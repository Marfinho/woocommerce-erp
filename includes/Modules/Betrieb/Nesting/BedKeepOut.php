<?php
/**
 * Sperrzonen auf dem Druckbett, die der Drucker vor jedem Druck selbst
 * benutzt (Düsen-Ladelinie, Kalibrierlinien).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

/**
 * Reine Logik, ohne Datenbank.
 *
 * Der Start-G-Code der Bambu-Lab-X1-Serie (Bambu Studio, machine_start_gcode
 * von „Bambu Lab X1 Carbon 0.4 nozzle“) zeichnet vor jedem Druck am vorderen
 * Bettrand:
 *
 * - die Düsen-Ladelinie („nozzle load line“): X 18–240, Y 1–11,
 * - die Kalibrierlinien für Flussrate/Lidar („extrinsic para cali paint“,
 *   Testlinie): X 35–225, Y 4–14,
 * - die Laser-/Kamerakalibrierung rechts vorn: um X 221–240, Y 1–11.
 *
 * Die P1-Serie zeichnet dieselbe Ladelinie (ohne Lidar-Kalibrierung). Ein
 * Bauteil in diesem Streifen wird auf die Linie gedruckt. Dazu kommt die
 * offizielle Sperrecke vorn links (bed_exclude_area 0–18 × 0–28 mm), über
 * der die Düse zum Abstreifen steht.
 *
 * OrcaSlicer kennt aus dem Druckerprofil nur die Sperrecke – den Streifen
 * nicht. Deshalb legt die Anordnung hier wie das an den Slicing-Worker
 * ausgelieferte Profil (siehe SlicingRestApi::get_job_profile()) beides fest.
 */
class BedKeepOut {

	/**
	 * Tiefe des vorderen Streifens (mm): Kalibrierlinien bis Y 14 plus
	 * Linienbreite.
	 */
	const FRONT_STRIP_MM = 15;

	/**
	 * Offizielle Sperrecke vorn links (Bambu Studio, bed_exclude_area).
	 */
	const CORNER_WIDTH_MM  = 18;
	const CORNER_HEIGHT_MM = 28;

	/**
	 * Zeichnet dieses Modell die Linien am vorderen Rand? Erkannt werden die
	 * X1- und P1-Serie, als Kurzname („X1C“, „P1S“) wie als Profilname („Bambu
	 * Lab X1 Carbon“). Die A1-Serie nicht: Ihren Start-G-Code habe ich nicht
	 * geprüft.
	 *
	 * @param string $model Druckermodell oder Profilname.
	 * @return bool
	 */
	public static function applies_to( $model ) {
		$model = strtoupper( (string) $model );

		return (bool) preg_match( '/(^|[^A-Z0-9])(X1|P1)([CEPS]|\s|$|[^A-Z0-9])/', $model ) || false !== strpos( $model, 'X1 CARBON' );
	}

	/**
	 * Tiefe des vorderen Streifens; über den Filter lw16_bed_front_strip_mm
	 * anpassbar (0 schaltet ihn ab).
	 *
	 * @return float
	 */
	public static function front_strip_mm() {
		$mm = function_exists( 'apply_filters' ) ? apply_filters( 'lw16_bed_front_strip_mm', self::FRONT_STRIP_MM ) : self::FRONT_STRIP_MM;

		return max( 0.0, (float) $mm );
	}

	/**
	 * Sperrzonen als einzelne, konvexe Rechtecke (Bettkoordinaten in mm,
	 * Ursprung vorn links) – so, wie die Anordnung (RasterPacker/BinPacker)
	 * sie braucht.
	 *
	 * @param string $model        Druckermodell.
	 * @param float  $bed_width_mm Bettbreite.
	 * @return array Liste von Polygonen.
	 */
	public static function zones( $model, $bed_width_mm ) {
		if ( ! self::applies_to( $model ) ) {
			return array();
		}

		$w     = (float) $bed_width_mm;
		$strip = self::front_strip_mm();
		$zones = array( self::rect( 0, 0, self::CORNER_WIDTH_MM, self::CORNER_HEIGHT_MM ) );

		if ( $strip > 0 && $w > 0 ) {
			$zones[] = self::rect( 0, 0, $w, $strip );
		}

		return $zones;
	}

	/**
	 * Dieselben Zonen als ein einziges Polygon im Format von bed_exclude_area
	 * („XxY“-Punkte) – OrcaSlicer liest dort genau ein Polygon. Ecke und
	 * Streifen ergeben zusammen ein L.
	 *
	 * @param string $model        Druckermodell.
	 * @param float  $bed_width_mm Bettbreite.
	 * @return string[] Leer, wenn das Modell keine Sperrzonen hat.
	 */
	public static function exclude_area( $model, $bed_width_mm ) {
		if ( ! self::applies_to( $model ) ) {
			return array();
		}

		$w     = (float) $bed_width_mm;
		$strip = min( self::front_strip_mm(), (float) self::CORNER_HEIGHT_MM );
		$cw    = self::CORNER_WIDTH_MM;
		$ch    = self::CORNER_HEIGHT_MM;

		if ( $strip <= 0 || $w <= $cw ) {
			$points = array( array( 0, 0 ), array( $cw, 0 ), array( $cw, $ch ), array( 0, $ch ) );
		} else {
			$points = array( array( 0, 0 ), array( $w, 0 ), array( $w, $strip ), array( $cw, $strip ), array( $cw, $ch ), array( 0, $ch ) );
		}

		return array_map(
			static function ( $p ) {
				return self::num( $p[0] ) . 'x' . self::num( $p[1] );
			},
			$points
		);
	}

	/**
	 * Druckerprofil (JSON-Text) um die Sperrzonen ergänzen, bevor es an den
	 * Slicing-Worker geht. Eine im Profil schon eingetragene Sperrfläche wird
	 * nur ersetzt, wenn sie ganz in unseren Zonen liegt (die übliche Ecke
	 * 0–18 × 0–28) – eine eigene, abweichende Fläche bleibt unangetastet.
	 *
	 * @param string $json         Profilinhalt.
	 * @param string $model        Druckermodell (leer: aus dem Profil).
	 * @param float  $bed_width_mm Bettbreite (0: aus printable_area oder 256).
	 * @return string Unveränderter Text, wenn nichts zu tun ist.
	 */
	public static function apply_to_profile( $json, $model = '', $bed_width_mm = 0 ) {
		// Als Objekt dekodieren: Ein leeres Objekt {} im Profil bliebe sonst
		// nicht erhalten (würde zu []), und OrcaSlicer könnte daran scheitern.
		$data = json_decode( (string) $json );

		if ( ! is_object( $data ) ) {
			return (string) $json;
		}

		$field = static function ( $key ) use ( $data ) {
			return isset( $data->$key ) && is_scalar( $data->$key ) ? (string) $data->$key : '';
		};
		$probe = trim( (string) $model . ' ' . $field( 'printer_model' ) . ' ' . $field( 'inherits' ) . ' ' . $field( 'name' ) );

		if ( ! self::applies_to( $probe ) ) {
			return (string) $json;
		}

		$area  = isset( $data->printable_area ) && is_array( $data->printable_area ) ? $data->printable_area : array();
		$width = (float) $bed_width_mm > 0 ? (float) $bed_width_mm : self::width_from_points( $area );
		$zones = self::zones( $probe, $width );

		if ( ! empty( $data->bed_exclude_area ) && is_array( $data->bed_exclude_area ) ) {
			foreach ( $data->bed_exclude_area as $entry ) {
				$point = self::parse_point( $entry );
				if ( $point && ! self::inside_any( $point, $zones ) ) {
					return (string) $json;
				}
			}
		}

		$data->bed_exclude_area = self::exclude_area( $probe, $width );

		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Bettbreite aus printable_area (größtes X), sonst 256 mm (X1/P1).
	 *
	 * @param array $points printable_area-Einträge.
	 * @return float
	 */
	protected static function width_from_points( array $points ) {
		$max = 0.0;

		foreach ( $points as $entry ) {
			$point = self::parse_point( $entry );
			if ( $point ) {
				$max = max( $max, $point[0] );
			}
		}

		return $max > 0 ? $max : 256.0;
	}

	/**
	 * „18x28“ → [18.0, 28.0].
	 *
	 * @param mixed $entry Eintrag.
	 * @return array|null
	 */
	protected static function parse_point( $entry ) {
		if ( ! is_string( $entry ) || false === strpos( $entry, 'x' ) ) {
			return null;
		}

		list( $x, $y ) = array_map( 'trim', explode( 'x', $entry, 2 ) );

		return is_numeric( $x ) && is_numeric( $y ) ? array( (float) $x, (float) $y ) : null;
	}

	/**
	 * Liegt der Punkt in (oder auf dem Rand) einer der Rechteck-Zonen?
	 *
	 * @param array $point Punkt.
	 * @param array $zones Rechtecke.
	 * @return bool
	 */
	protected static function inside_any( array $point, array $zones ) {
		foreach ( $zones as $zone ) {
			$xs = array_column( $zone, 0 );
			$ys = array_column( $zone, 1 );
			if ( $point[0] >= min( $xs ) - 0.01 && $point[0] <= max( $xs ) + 0.01 && $point[1] >= min( $ys ) - 0.01 && $point[1] <= max( $ys ) + 0.01 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rechteck, gegen den Uhrzeigersinn.
	 *
	 * @param float $x0 Links.
	 * @param float $y0 Vorn.
	 * @param float $x1 Rechts.
	 * @param float $y1 Hinten.
	 * @return array
	 */
	protected static function rect( $x0, $y0, $x1, $y1 ) {
		return array( array( (float) $x0, (float) $y0 ), array( (float) $x1, (float) $y0 ), array( (float) $x1, (float) $y1 ), array( (float) $x0, (float) $y1 ) );
	}

	/**
	 * Zahl ohne überflüssige Nachkommastellen („15“, „12.5“).
	 *
	 * @param float $value Wert.
	 * @return string
	 */
	protected static function num( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
	}
}
