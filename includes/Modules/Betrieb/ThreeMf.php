<?php
/**
 * Lesen von 3MF-Dateien.
 *
 * Ein 3MF ist ein ZIP mit einer XML-Datei darin (3D/3dmodel.model). Diese
 * Klasse fasst nur lesend zu: Sie sagt, ob ein Bauteil darin liegt, und
 * liest bei einer geslicten Druckdatei die Zahlen des Slicers aus.
 *
 * Vervielfältigt wird hier nichts mehr. Ein früherer Versuch, mehrere Teile
 * serverseitig auf einer Platte anzuordnen, rechnete mit achsparallelen
 * Hüllquadern – der sieht nicht, dass sich zwei T-förmige Teile ineinander
 * legen lassen, wenn eines gedreht wird, und verschenkte damit die halbe
 * Platte. Richtiges Verschachteln braucht die Bauteilkontur, und das kann
 * der Slicer besser. Das Anordnen bleibt deshalb dort.
 *
 * Die Klasse ist frei von Datenbank und Dateisystem, damit sie prüfbar ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Reine 3MF-Logik: Geometrie messen, Platte füllen.
 */
class ThreeMf {

	/**
	 * Pfad des Modells im ZIP.
	 */
	const MODEL_PATH = '3D/3dmodel.model';

	/**
	 * Datei, an der ein Bambu-/Orca-Projekt erkennbar ist.
	 */
	const PROJECT_MARKER = 'Metadata/model_settings.config';

	/**
	 * Beilage einer geslicten Druckdatei mit den Zahlen des Slicers.
	 */
	const SLICE_INFO = 'Metadata/slice_info.config';

	/**
	 * XML einlesen. Liefert null, wenn es kein 3MF-Modell ist.
	 *
	 * @param string $xml Inhalt von 3D/3dmodel.model.
	 * @return \DOMDocument|null
	 */
	public static function document( $xml ) {
		if ( '' === trim( (string) $xml ) ) {
			return null;
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		$ok   = $doc->loadXML( (string) $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok || ! $doc->documentElement || 'model' !== $doc->documentElement->localName ) {
			return null;
		}

		return $doc;
	}

	/**
	 * Die Positionen im <build>. Sind keine da, liegt in der Datei kein
	 * Bauteil – so erkennt man eine geslicte Druckdatei.
	 *
	 * @param \DOMDocument $doc Dokument.
	 * @return array DOMElement-Liste.
	 */
	public static function items( \DOMDocument $doc ) {
		$out = array();
		foreach ( $doc->getElementsByTagName( 'item' ) as $item ) {
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Die Zahlen des Slicers aus slice_info.config lesen.
	 *
	 * Eine geslicte Druckdatei weiss bereits, was sie kostet: Druckdauer,
	 * Materialverbrauch, Düse, Drucker und wie viele Teile auf der Platte
	 * liegen. Diese Zahlen abzutippen ist Arbeit, die sich sparen lässt –
	 * und Abtippen erzeugt Zahlendreher.
	 *
	 * Gerechnet wird nur, was dasteht. Fehlt eine Angabe, bleibt sie null;
	 * die Kalkulation soll auf gemessenen Werten stehen, nicht auf
	 * geschätzten.
	 *
	 * @param string $xml Inhalt von Metadata/slice_info.config.
	 * @return array|null {seconds, weight_g, parts, material, nozzle_mm, printer, objects}
	 */
	public static function slice_info( $xml ) {
		if ( '' === trim( (string) $xml ) ) {
			return null;
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		$ok   = $doc->loadXML( (string) $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok || ! $doc->documentElement ) {
			return null;
		}

		$meta = array();
		foreach ( $doc->getElementsByTagName( 'metadata' ) as $node ) {
			$key = $node->getAttribute( 'key' );
			if ( '' !== $key && ! isset( $meta[ $key ] ) ) {
				$meta[ $key ] = $node->getAttribute( 'value' );
			}
		}

		$objects = array();
		foreach ( $doc->getElementsByTagName( 'object' ) as $node ) {
			$objects[] = $node->getAttribute( 'name' );
		}

		// Der Materialverbrauch steht je Filament; mehrfarbige Platten
		// summieren sich.
		$weight   = null;
		$material = '';
		foreach ( $doc->getElementsByTagName( 'filament' ) as $node ) {
			$used = $node->getAttribute( 'used_g' );

			if ( is_numeric( $used ) ) {
				$weight = (float) $weight + (float) $used;
			}
			if ( '' === $material ) {
				$material = strtoupper( $node->getAttribute( 'type' ) );
			}
		}

		if ( null === $weight && isset( $meta['weight'] ) && is_numeric( $meta['weight'] ) ) {
			$weight = (float) $meta['weight'];
		}

		$nozzle = isset( $meta['nozzle_diameters'] ) ? strtok( (string) $meta['nozzle_diameters'], ' ' ) : '';

		return array(
			'seconds'   => isset( $meta['prediction'] ) && is_numeric( $meta['prediction'] ) ? (int) $meta['prediction'] : null,
			'weight_g'  => $weight,
			'parts'     => count( $objects ),
			'material'  => $material,
			'nozzle_mm' => is_numeric( $nozzle ) ? (float) $nozzle : null,
			'printer'   => isset( $meta['printer_model_id'] ) ? (string) $meta['printer_model_id'] : '',
			'objects'   => $objects,
		);
	}

	/**
	 * Aus den Slicer-Zahlen die Felder einer Revision ableiten.
	 *
	 * Wichtig ist die Umrechnung: Der Slicer nennt Dauer und Verbrauch für
	 * die **ganze Platte**, die Revision führt das Gewicht **je Teil**.
	 * Geteilt wird durch die Zahl der Objekte auf der Platte – liegt dort
	 * nur eins, ist beides dasselbe.
	 *
	 * @param array $info Ergebnis von slice_info().
	 * @return array {print_time_plate_h, weight_g, parts_per_plate, material, nozzle_mm}
	 */
	public static function revision_values( array $info ) {
		$parts = max( 1, (int) $info['parts'] );

		return array(
			'print_time_plate_h' => null === $info['seconds'] ? null : round( $info['seconds'] / 3600, 3 ),
			'weight_g'           => null === $info['weight_g'] ? null : round( $info['weight_g'] / $parts, 3 ),
			'parts_per_plate'    => $parts,
			'material'           => $info['material'],
			'nozzle_mm'          => $info['nozzle_mm'],
		);
	}
}
