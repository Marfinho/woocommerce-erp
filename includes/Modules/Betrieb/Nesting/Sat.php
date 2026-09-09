<?php
/**
 * Kollisionsprüfung konvexer Polygone (Separating-Axis-Theorem).
 *
 * Für zwei konvexe Polygone gilt: Sie überlappen sich genau dann nicht,
 * wenn es (mindestens) eine Achse gibt, auf die projiziert sich ihre
 * Wertebereiche nicht überschneiden – und als Kandidatenachsen genügen die
 * Aussennormalen aller Kanten beider Polygone. Das ist ein exaktes,
 * klassisches Verfahren, kein heuristischer Näherungstest.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

/**
 * Statischer Überlappungstest.
 */
class Sat {

	/**
	 * Überlappen sich zwei konvexe Polygone (oder berühren sie sich)?
	 *
	 * Berührung (Wertebereiche stossen exakt aneinander, ohne Lücke) zählt
	 * hier bewusst als Überlappung – für eine Druckplatte ist "es reicht
	 * gerade so" kein sicherer Zustand, ein Sicherheitsabstand soll
	 * ausdrücklich eine Lücke sein, keine Nulllücke.
	 *
	 * @param array $a Polygon A, mindestens 3 Punkte.
	 * @param array $b Polygon B, mindestens 3 Punkte.
	 * @return bool
	 */
	public static function overlap( array $a, array $b ) {
		if ( count( $a ) < 3 || count( $b ) < 3 ) {
			// Ohne Fläche keine sinnvolle Kollisionsprüfung - im Zweifel
			// (konservativ) als überlappend behandeln, statt eine
			// entartete Form fälschlich für frei zu halten.
			return true;
		}

		foreach ( self::axes( $a ) as $axis ) {
			if ( self::separated_on_axis( $a, $b, $axis ) ) {
				return false;
			}
		}

		foreach ( self::axes( $b ) as $axis ) {
			if ( self::separated_on_axis( $a, $b, $axis ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Kandidatenachsen eines Polygons: die (nicht normierten) Normalen
	 * aller Kanten. Normierung ist für den reinen Trenntest nicht nötig.
	 *
	 * @param array $poly Polygon.
	 * @return array Liste von [nx, ny].
	 */
	protected static function axes( array $poly ) {
		$n    = count( $poly );
		$axes = array();

		for ( $i = 0; $i < $n; $i++ ) {
			$a  = $poly[ $i ];
			$b  = $poly[ ( $i + 1 ) % $n ];
			$dx = $b[0] - $a[0];
			$dy = $b[1] - $a[1];

			if ( abs( $dx ) < Polygon::EPSILON && abs( $dy ) < Polygon::EPSILON ) {
				continue; // Entartete Kante.
			}

			$axes[] = array( -$dy, $dx );
		}

		return $axes;
	}

	/**
	 * Sind zwei Polygone auf der gegebenen Achse getrennt (Lücke > 0,
	 * inklusive exakter Berührung als NICHT getrennt)?
	 *
	 * @param array $a    Polygon A.
	 * @param array $b    Polygon B.
	 * @param array $axis Achse [nx, ny] (muss nicht normiert sein).
	 * @return bool
	 */
	protected static function separated_on_axis( array $a, array $b, array $axis ) {
		list( $amin, $amax ) = self::project( $a, $axis );
		list( $bmin, $bmax ) = self::project( $b, $axis );

		return $amax < $bmin - Polygon::EPSILON || $bmax < $amin - Polygon::EPSILON;
	}

	/**
	 * Ein Polygon auf eine Achse projizieren.
	 *
	 * @param array $poly Polygon.
	 * @param array $axis Achse [nx, ny].
	 * @return array [min, max].
	 */
	protected static function project( array $poly, array $axis ) {
		$min = null;
		$max = null;

		foreach ( $poly as $p ) {
			$dot = $p[0] * $axis[0] + $p[1] * $axis[1];
			if ( null === $min || $dot < $min ) {
				$min = $dot;
			}
			if ( null === $max || $dot > $max ) {
				$max = $dot;
			}
		}

		return array( $min, $max );
	}
}
