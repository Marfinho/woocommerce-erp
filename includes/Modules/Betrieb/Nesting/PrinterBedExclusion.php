<?php
/**
 * Feste Sperrfläche(n) auf dem Druckbett aus einem OrcaSlicer-/Bambu-
 * Studio-Druckerprofil ("bed_exclude_area").
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

/**
 * Liest ausschliesslich das "bed_exclude_area"-Feld - keine
 * Profil-Vererbung ("inherits"), das löst nur der Slicing-Worker gegen
 * die dort installierte OrcaSlicer-Version auf (siehe dessen
 * prepare_profile_bundle()). Fehlt das Feld im hochgeladenen Profil
 * (z. B. weil es nur eine Differenzdatei ohne diesen Schlüssel ist),
 * bleibt es bei "keine bekannte Sperrfläche" - kein Rückschritt
 * gegenüber dem bisherigen Stand ganz ohne Sperrflächen-Kenntnis.
 */
class PrinterBedExclusion {

	/**
	 * Sperrflächen aus einer Druckerprofil-JSON-Datei lesen.
	 *
	 * Bambu-Studio-Format: eine flache Liste von "XxY"-Punkten
	 * (z. B. "18x0"), die ein einzelnes Polygon bilden - typischerweise
	 * eine kleine Rechteck-Ecke des Betts.
	 *
	 * @param string $path Absoluter Pfad der Druckerprofil-JSON.
	 * @return array Liste von Polygonen [[[x,y], ...], ...] in mm, leer
	 *                wenn keine (gültige) Sperrfläche gefunden wurde.
	 */
	public static function from_profile_json( $path ) {
		if ( '' === (string) $path || ! is_file( $path ) ) {
			return array();
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		if ( ! is_string( $raw ) ) {
			return array();
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['bed_exclude_area'] ) || ! is_array( $data['bed_exclude_area'] ) ) {
			return array();
		}

		$points = array();
		foreach ( $data['bed_exclude_area'] as $entry ) {
			if ( ! is_string( $entry ) || false === strpos( $entry, 'x' ) ) {
				continue;
			}
			list( $x, $y ) = array_map( 'trim', explode( 'x', $entry, 2 ) );
			if ( ! is_numeric( $x ) || ! is_numeric( $y ) ) {
				continue;
			}
			$points[] = array( (float) $x, (float) $y );
		}

		return count( $points ) >= 3 ? array( $points ) : array();
	}
}
