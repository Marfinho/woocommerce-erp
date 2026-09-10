<?php
/**
 * Druckbett-Innenmasse bekannter Bambu-Lab-Modelle.
 *
 * Reine Nachschlagetabelle, ohne Datenbank – dient als Rückfallwert, wenn
 * für einen Drucker kein eigenes Bettmass hinterlegt ist (siehe
 * BambuPrinterRepository::effective_bed_size()). Ein von der Bridge
 * gemeldetes oder von Hand eingetragenes Mass hat immer Vorrang, falls das
 * eigene Gerät (z. B. ein modifiziertes Bett) von der Werksangabe abweicht.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Bambu;

defined( 'ABSPATH' ) || exit;

/**
 * Bettmasse nach Modellbezeichnung.
 */
class BedSizeLookup {

	/**
	 * Bekannte Modelle => {width_mm, height_mm}.
	 *
	 * Die Schlüssel werden als Teilstring gegen die (von der Bridge
	 * gemeldete oder von Hand eingetragene) Modellbezeichnung geprüft
	 * (siehe match()) – "Bambu Lab P1S" trifft z. B. auf den Schlüssel
	 * "P1S". Längere, spezifischere Schlüssel stehen vor kürzeren, damit
	 * "A1 MINI" nicht fälschlich schon bei "A1" zutrifft.
	 *
	 * @return array
	 */
	public static function known_models() {
		return array(
			'A1 MINI' => array( 'width_mm' => 180, 'height_mm' => 180 ),
			'X1E'     => array( 'width_mm' => 256, 'height_mm' => 256 ),
			'X1C'     => array( 'width_mm' => 256, 'height_mm' => 256 ),
			'X1'      => array( 'width_mm' => 256, 'height_mm' => 256 ),
			'P1S'     => array( 'width_mm' => 256, 'height_mm' => 256 ),
			'P1P'     => array( 'width_mm' => 256, 'height_mm' => 256 ),
			'A1'      => array( 'width_mm' => 256, 'height_mm' => 256 ),
			'H2D'     => array( 'width_mm' => 350, 'height_mm' => 320 ),
		);
	}

	/**
	 * Bettmass zu einer (Teil-)Modellbezeichnung finden.
	 *
	 * @param string $printer_model Freitext, z. B. "Bambu Lab P1S" oder "P1S".
	 * @return array|null {width_mm, height_mm} oder null, wenn unbekannt.
	 */
	public static function match( $printer_model ) {
		$model = strtoupper( trim( (string) $printer_model ) );

		if ( '' === $model ) {
			return null;
		}

		foreach ( self::known_models() as $needle => $size ) {
			if ( false !== strpos( $model, $needle ) ) {
				return $size;
			}
		}

		return null;
	}
}
