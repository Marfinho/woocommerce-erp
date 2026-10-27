<?php
/**
 * Wie ein Modell an der Stange sitzt.
 *
 * Eine .glb kommt aus dem CAD mit ihrem eigenen Koordinatensystem: Der
 * Halter der Holzstange etwa liegt, wie er auf dem Druckbett stand,
 * 128 mm neben dem Ursprung. Ohne Angabe, was oben ist und wo das Teil an
 * die Stange gehört, taucht so ein Modul irgendwo im Raum auf – oder gar
 * nicht im Bild. Pro Teil gilt deshalb eine Lage:
 *
 * - Ausrichtung: `auto` (Voreinstellung) stellt das Teil auf die
 *   Unterkante, mittig auf die Stange, Rückseite an die Wand. `asis` lässt
 *   die Lage der Konstruktion unverändert.
 * - Drehung um X, Y und Z in Grad (Reihenfolge X, Y, Z wie in three.js),
 *   damit „oben" im Modell oben ist.
 * - Verschiebung in Millimetern: X nach rechts, Y nach oben, Z von der
 *   Wand weg. Die Stangenachse liegt bei X 0, Z 45.
 *
 * Dieselbe Rechnung steht im Browser in assets/js/konfigurator-lage.js;
 * die Vorschau im ERP und die Ansicht im Shop benutzen diese Datei.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

/**
 * Prüfen und Aufbereiten der Lage.
 */
class ModelPlacement {

	/**
	 * Abstand der Stangenachse von der Wand in Millimetern.
	 */
	const ROD_DEPTH = 45;

	/**
	 * Durchmesser der Holzstange in Millimetern.
	 */
	const ROD_DIAMETER = 20;

	/**
	 * Erlaubte Ausrichtungen.
	 */
	const ALIGN = array( 'auto', 'asis' );

	/**
	 * Lage aus Formulareingaben.
	 *
	 * @param array $input Rohe Eingaben (model_align, model_rot_x … model_off_z).
	 * @return array Spalten für die Tabelle.
	 */
	public static function from_input( array $input ) {
		$align = isset( $input['model_align'] ) && in_array( $input['model_align'], self::ALIGN, true ) ? $input['model_align'] : 'auto';

		return array(
			'model_align' => $align,
			'model_rot_x' => self::number( $input, 'model_rot_x', 360 ),
			'model_rot_y' => self::number( $input, 'model_rot_y', 360 ),
			'model_rot_z' => self::number( $input, 'model_rot_z', 360 ),
			'model_off_x' => self::number( $input, 'model_off_x', 5000 ),
			'model_off_y' => self::number( $input, 'model_off_y', 5000 ),
			'model_off_z' => self::number( $input, 'model_off_z', 5000 ),
		);
	}

	/**
	 * Lage für den Browser.
	 *
	 * @param array $row Zeile aus der Tabelle.
	 * @return array {align, rot: [x,y,z], off: [x,y,z]}
	 */
	public static function for_browser( array $row ) {
		$align = isset( $row['model_align'] ) && in_array( $row['model_align'], self::ALIGN, true ) ? $row['model_align'] : 'auto';

		return array(
			'align' => $align,
			'rot'   => array(
				isset( $row['model_rot_x'] ) ? (float) $row['model_rot_x'] : 0.0,
				isset( $row['model_rot_y'] ) ? (float) $row['model_rot_y'] : 0.0,
				isset( $row['model_rot_z'] ) ? (float) $row['model_rot_z'] : 0.0,
			),
			'off'   => array(
				isset( $row['model_off_x'] ) ? (float) $row['model_off_x'] : 0.0,
				isset( $row['model_off_y'] ) ? (float) $row['model_off_y'] : 0.0,
				isset( $row['model_off_z'] ) ? (float) $row['model_off_z'] : 0.0,
			),
		);
	}

	/**
	 * Die Drehung als Matrix (spaltenweise), Reihenfolge X, Y, Z wie three.js.
	 *
	 * @param array $deg Winkel [x, y, z] in Grad.
	 * @return array 16 Werte.
	 */
	public static function rotation_matrix( array $deg ) {
		list( $ax, $ay, $az ) = array_map(
			static function ( $d ) {
				return deg2rad( (float) $d );
			},
			array_values( $deg + array( 0, 0, 0 ) )
		);

		$a = cos( $ax );
		$b = sin( $ax );
		$c = cos( $ay );
		$d = sin( $ay );
		$e = cos( $az );
		$f = sin( $az );

		// Wie THREE.Matrix4.makeRotationFromEuler für die Ordnung XYZ.
		$ae = $a * $e;
		$af = $a * $f;
		$be = $b * $e;
		$bf = $b * $f;

		return array(
			$c * $e,
			$af + $be * $d,
			$bf - $ae * $d,
			0.0,
			-$c * $f,
			$ae - $bf * $d,
			$be + $af * $d,
			0.0,
			$d,
			-$b * $c,
			$a * $c,
			0.0,
			0.0,
			0.0,
			0.0,
			1.0,
		);
	}

	/**
	 * Zahl aus dem Formular, mit Komma und in Grenzen.
	 *
	 * @param array  $input Eingaben.
	 * @param string $key   Feld.
	 * @param float  $limit Betragsgrenze.
	 * @return float
	 */
	protected static function number( array $input, $key, $limit ) {
		if ( ! isset( $input[ $key ] ) ) {
			return 0.0;
		}

		$value = str_replace( ',', '.', trim( (string) $input[ $key ] ) );

		return (float) max( -$limit, min( $limit, round( (float) $value, 2 ) ) );
	}
}
