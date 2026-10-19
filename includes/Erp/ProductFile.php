<?php
/**
 * Produktakte: reine Regeln der nativen Reiter (Stammdaten, Revisionen,
 * Stückliste, Kalkulation, Beschaffung).
 *
 * Ohne Datenbank und ohne WordPress-Aufrufe, damit sich alles testen lässt.
 * Die REST-Routen stehen in Pages/Produkt.php und rufen nur auf.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\BomResolver;

/**
 * Regeln für die Reiter der Produktakte.
 */
class ProductFile {

	/**
	 * Felder des Artikelformulars.
	 */
	const ARTICLE_FIELDS = array( 'sku', 'name', 'status', 'sourcing_type', 'sellable', 'discountable', 'default_bin_id', 'wc_product_id', 'price_gross', 'dealer_price', 'weight_g', 'postprocessing_min', 'load_rating_kg', 'barcode', 'notes' );

	/**
	 * Felder des Revisionsformulars.
	 */
	const REVISION_FIELDS = array( 'version', 'material', 'color', 'weight_g', 'parts_per_plate', 'print_time_plate_h', 'nozzle_mm', 'layer_mm', 'perimeters', 'infill_pct', 'infill_pattern', 'nozzle_temp', 'bed_temp', 'cooling_pct', 'supports', 'orientation_note' );

	/**
	 * Felder der Stücklistenzeile.
	 */
	const BOM_FIELDS = array( 'child', 'qty', 'position', 'note' );

	/**
	 * Felder der Beschaffung.
	 */
	const SOURCING_FIELDS = array( 'valid_from', 'supplier_name', 'supplier_contact', 'purchase_price_net', 'min_order_qty', 'lead_time_days', 'own_processing_min', 'unit_note' );

	/**
	 * Nur die erlaubten Felder aus den Anfrageparametern, als Text (Zahlen aus
	 * JSON werden zu Text, damit die Prüfungen der Repositories sie wie
	 * Formularwerte lesen). Wahrheitswerte bleiben erhalten.
	 *
	 * @param array    $params Anfrageparameter.
	 * @param string[] $keys   Erlaubte Felder.
	 * @return array
	 */
	public static function pick( array $params, array $keys ) {
		$out = array();

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $params ) ) {
				continue;
			}

			$value = $params[ $key ];

			if ( is_bool( $value ) ) {
				$out[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$out[ $key ] = (string) $value;
			} elseif ( null === $value ) {
				$out[ $key ] = '';
			}
		}

		return $out;
	}

	/**
	 * Bestehende Werte unter die Eingabe legen.
	 *
	 * Die Prüfung der Repositories macht aus einem fehlenden Feld „leer“: ein
	 * Formular, das z. B. den Barcode nicht kennt, würde ihn sonst beim
	 * Speichern löschen. Hier gilt: was nicht mitgeschickt wird, bleibt.
	 *
	 * @param array    $existing Datensatz.
	 * @param array    $input    Eingabe (nur gesendete Felder).
	 * @param string[] $keys     Felder, die der Datensatz mitbringt.
	 * @return array
	 */
	public static function overlay( array $existing, array $input, array $keys ) {
		$out = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = $input[ $key ];
			} elseif ( array_key_exists( $key, $existing ) && null !== $existing[ $key ] ) {
				$out[ $key ] = $existing[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Auswahl „typ:id“ der Stückliste in Typ und ID zerlegen.
	 *
	 * @param string $choice Auswahl.
	 * @return array {child_type, child_id}; leer, wenn nicht lesbar.
	 */
	public static function split_child( $choice ) {
		$choice = (string) $choice;

		if ( false === strpos( $choice, ':' ) ) {
			return array();
		}

		list( $type, $id ) = explode( ':', $choice, 2 );

		return array(
			'child_type' => $type,
			'child_id'   => $id,
		);
	}

	/**
	 * Artikel, deren Stückliste tiefer als erlaubt ist.
	 *
	 * @param array $bom      Elternartikel => Zeilen.
	 * @param int   $max_depth Erlaubte Tiefe.
	 * @return int[]
	 */
	public static function too_deep( array $bom, $max_depth ) {
		$bad = array();

		foreach ( array_keys( $bom ) as $article ) {
			$result = BomResolver::resolve( (int) $article, array( 'bom' => $bom ), 1.0, (int) $max_depth );

			foreach ( $result['errors'] as $error ) {
				if ( 'depth' === $error['type'] ) {
					$bad[] = (int) $article;
					break;
				}
			}
		}

		return $bad;
	}

	/**
	 * Würde die neue Zeile eine Stückliste tiefer machen als erlaubt?
	 *
	 * Gezählt wird nur, was durch die neue Zeile hinzukommt: eine schon
	 * vorher zu tiefe Stückliste sperrt nicht jede weitere Eingabe.
	 *
	 * @param int    $parent    Elternartikel.
	 * @param string $type      artikel oder komponente.
	 * @param int    $child     Kind.
	 * @param array  $bom       Elternartikel => Zeilen.
	 * @param int    $max_depth Erlaubte Tiefe.
	 * @return bool
	 */
	public static function adds_depth_error( $parent, $type, $child, array $bom, $max_depth ) {
		if ( 'artikel' !== $type ) {
			return false;
		}

		$before = self::too_deep( $bom, $max_depth );

		$bom[ (int) $parent ][] = array(
			'child_type' => 'artikel',
			'child_id'   => (int) $child,
			'qty'        => 1,
		);

		$after = self::too_deep( $bom, $max_depth );

		return (bool) array_diff( $after, $before );
	}

	/**
	 * Welche leeren Felder einer Revision füllt eine geslicte Druckdatei?
	 *
	 * Übernommen wird nur, was noch leer ist – eine gemessene Angabe wird nie
	 * überschrieben, nur weil zufällig eine Datei hochgeladen wurde.
	 *
	 * @param array $revision Revision (print_time_plate_h, weight_g).
	 * @param array $values   Ergebnis von ThreeMf::revision_values().
	 * @return array Felder, die zu setzen sind.
	 */
	public static function fill_empty( array $revision, array $values ) {
		$data = array();

		if ( (float) $revision['print_time_plate_h'] <= 0 && null !== $values['print_time_plate_h'] ) {
			$data['print_time_plate_h'] = $values['print_time_plate_h'];
		}

		if ( (float) $revision['weight_g'] <= 0 && null !== $values['weight_g'] ) {
			$data['weight_g']        = $values['weight_g'];
			$data['parts_per_plate'] = $values['parts_per_plate'];
		}

		return $data;
	}

	/**
	 * Rückmeldung nach dem Hochladen einer 3MF.
	 *
	 * @param string $name    Dateiname.
	 * @param string $kind    modell oder druckdatei.
	 * @param int    $objects Anzahl Objekte (Modell: Positionen im Aufbau).
	 * @return string
	 */
	public static function upload_message( $name, $kind, $objects ) {
		$objects = max( 0, (int) $objects );

		if ( 'modell' === $kind ) {
			return sprintf(
				/* translators: 1: Dateiname, 2: Anzahl Objekte. */
				_n( 'Projekt-3MF gespeichert: %1$s, %2$d Objekt.', 'Projekt-3MF gespeichert: %1$s, %2$d Objekte.', $objects, 'lindenweg16' ),
				$name,
				$objects
			);
		}

		return sprintf(
			/* translators: %s: Dateiname. */
			__( 'Geslicte Druckdatei gespeichert: %s. Das ist keine Projekt-3MF – das Produkt gilt weiter als „ohne 3MF“. Bitte zusätzlich die ungeslicte Projektdatei (aus Bambu Studio „Projekt speichern“) hochladen.', 'lindenweg16' ),
			$name
		);
	}

	/**
	 * Sprechender Text zu einem PHP-Uploadfehler.
	 *
	 * @param int $code UPLOAD_ERR_*.
	 * @param int $max  Erlaubte Größe in Byte.
	 * @return string Leer, wenn kein Fehler.
	 */
	public static function upload_error( $code, $max ) {
		$limit = self::bytes_text( $max );

		switch ( (int) $code ) {
			case 0:
				return '';
			case 1:
			case 2:
				return sprintf(
					/* translators: %s: Größe. */
					__( 'Die Datei ist zu groß: der Server nimmt höchstens %s an. Das Limit steht in PHP (upload_max_filesize) – bitte beim Hoster erhöhen lassen oder die Datei im Slicer verkleinern.', 'lindenweg16' ),
					$limit
				);
			case 3:
				return __( 'Die Datei kam nur teilweise an. Bitte noch einmal hochladen (bei schwachem Netz am besten per WLAN).', 'lindenweg16' );
			case 4:
				return __( 'Es wurde keine Datei ausgewählt.', 'lindenweg16' );
			case 6:
			case 7:
				return __( 'Der Server kann die Datei nicht zwischenspeichern (temporäres Verzeichnis fehlt oder ist nicht beschreibbar). Bitte den Hoster informieren.', 'lindenweg16' );
			default:
				return __( 'Der Upload ist fehlgeschlagen. Bitte noch einmal versuchen.', 'lindenweg16' );
		}
	}

	/**
	 * Größe als Text.
	 *
	 * @param int $bytes Byte.
	 * @return string
	 */
	public static function bytes_text( $bytes ) {
		$bytes = (float) $bytes;

		if ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 1, ',', '.' ) . ' MB';
		}

		return number_format( $bytes / 1024, 0, ',', '.' ) . ' KB';
	}

	/**
	 * Zielmarge aus der Eingabe: 0 bis 95 Prozent, Standard 45.
	 *
	 * @param mixed $raw Eingabe.
	 * @return float Prozent.
	 */
	public static function margin_percent( $raw ) {
		if ( null === $raw || '' === trim( (string) $raw ) ) {
			return 45.0;
		}

		$value = str_replace( ',', '.', trim( (string) $raw ) );

		if ( ! is_numeric( $value ) ) {
			return 45.0;
		}

		return max( 0.0, min( 95.0, (float) $value ) );
	}

	/**
	 * Stichtag als Y-m-d; ungültig oder leer = heute.
	 *
	 * @param mixed  $raw   Eingabe.
	 * @param string $today Heute als Y-m-d.
	 * @return string
	 */
	public static function stichtag( $raw, $today ) {
		$raw = trim( (string) $raw );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $today;
		}

		return $raw;
	}

	/**
	 * Preis aus der Übernahme: größer 0, auf Cent gerundet.
	 *
	 * @param float|null $price Vorschlag.
	 * @return float|null Null, wenn nichts zu übernehmen ist.
	 */
	public static function adoptable_price( $price ) {
		if ( null === $price ) {
			return null;
		}

		$price = round( (float) $price, 2 );

		return $price > 0 ? $price : null;
	}

	/**
	 * Dateiname für den Download einer 3MF.
	 *
	 * @param string $sku     Artikelnummer.
	 * @param string $version Version.
	 * @return string Ohne Pfadzeichen, mit .3mf.
	 */
	public static function download_name( $sku, $version ) {
		$name = trim( (string) $sku ) . '-' . trim( (string) $version );
		$name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name );
		$name = trim( (string) preg_replace( '/-{2,}/', '-', (string) $name ), '-.' );

		return ( '' === $name ? 'artikel' : $name ) . '.3mf';
	}

	/**
	 * Passt eine Revision, Stücklistenzeile o. Ä. zum Artikel der Adresse?
	 *
	 * Die Adresse nennt Artikel und Zeile getrennt; ohne diese Prüfung ließe
	 * sich über Artikel A eine Zeile von Artikel B ändern.
	 *
	 * @param array|null $row       Zeile.
	 * @param string     $key       Schlüssel des Artikels in der Zeile.
	 * @param int        $article   Artikel aus der Adresse.
	 * @return bool
	 */
	public static function belongs_to( $row, $key, $article ) {
		return is_array( $row ) && isset( $row[ $key ] ) && (int) $row[ $key ] === (int) $article;
	}

	/**
	 * Kostenzeilen für die Anzeige: Anteil an den Herstellkosten.
	 *
	 * @param float $cost  Kosten der Zeile.
	 * @param float $total Herstellkosten.
	 * @return float Prozent, eine Nachkommastelle.
	 */
	public static function share( $cost, $total ) {
		return (float) $total > 0 ? round( (float) $cost / (float) $total * 100, 1 ) : 0.0;
	}
}
