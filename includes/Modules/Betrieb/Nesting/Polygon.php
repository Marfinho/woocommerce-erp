<?php
/**
 * Reine 2D-Polygon-Geometrie für die Plattenanordnung.
 *
 * Ein Polygon ist hier immer ein Array von Punkten [x, y] (float, in mm),
 * im Gegenuhrzeigersinn (CCW) geordnet – das setzen convex_hull() und
 * offset() voraus bzw. stellen es her. Alles hier ist reine Rechnung ohne
 * Datenbank und ohne WordPress, deshalb ohne WordPress-Testsuite prüfbar
 * (siehe tests/NestingTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

/**
 * Statische Hilfsfunktionen auf Punktlisten.
 */
class Polygon {

	/**
	 * Toleranz für Fliesskomma-Vergleiche (mm).
	 */
	const EPSILON = 1.0e-6;

	/**
	 * Konvexe Hülle einer Punktmenge (Andrew's-Monotone-Chain-Verfahren).
	 *
	 * Liefert die Hülle im Gegenuhrzeigersinn, ohne doppelten Schlusspunkt
	 * und ohne Punkte, die exakt auf einer Kante zwischen zwei anderen
	 * Hüllpunkten liegen (kollinear). Weniger als drei unterschiedliche
	 * Punkte ergeben keine Fläche – dann werden die (deduplizierten)
	 * Punkte unverändert zurückgegeben, statt eine leere Hülle zu liefern:
	 * Aufrufer sollen mit einer Linie/einem Punkt genauso umgehen können
	 * wie mit einer Fläche, nur eben ohne Ausdehnung.
	 *
	 * @param array $points Liste [x, y].
	 * @return array Hülle im Gegenuhrzeigersinn.
	 */
	public static function convex_hull( array $points ) {
		$pts = self::unique_points( $points );

		if ( count( $pts ) < 3 ) {
			return $pts;
		}

		usort(
			$pts,
			static function ( $a, $b ) {
				if ( abs( $a[0] - $b[0] ) > self::EPSILON ) {
					return $a[0] <=> $b[0];
				}
				return $a[1] <=> $b[1];
			}
		);

		$n     = count( $pts );
		$lower = array();
		for ( $i = 0; $i < $n; $i++ ) {
			while ( count( $lower ) >= 2 && self::cross( $lower[ count( $lower ) - 2 ], $lower[ count( $lower ) - 1 ], $pts[ $i ] ) <= self::EPSILON ) {
				array_pop( $lower );
			}
			$lower[] = $pts[ $i ];
		}

		$upper = array();
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			while ( count( $upper ) >= 2 && self::cross( $upper[ count( $upper ) - 2 ], $upper[ count( $upper ) - 1 ], $pts[ $i ] ) <= self::EPSILON ) {
				array_pop( $upper );
			}
			$upper[] = $pts[ $i ];
		}

		array_pop( $lower );
		array_pop( $upper );

		return array_merge( $lower, $upper );
	}

	/**
	 * Punkte ohne exakte Duplikate (Toleranz EPSILON).
	 *
	 * @param array $points Punkte.
	 * @return array
	 */
	protected static function unique_points( array $points ) {
		// Über ein Raster der Weite EPSILON statt jeden Punkt mit jedem zu
		// vergleichen: Netze mit hunderttausend Eckpunkten brauchten sonst
		// Minuten (quadratischer Aufwand).
		$seen = array();
		$out  = array();

		foreach ( $points as $p ) {
			$key = round( $p[0] / self::EPSILON ) . ':' . round( $p[1] / self::EPSILON );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$out[]        = array( (float) $p[0], (float) $p[1] );
		}

		return $out;
	}

	/**
	 * Kreuzprodukt (O→A) × (O→B) – Vorzeichen sagt, ob B links (>0), rechts
	 * (<0) oder auf der Linie O-A (=0) liegt.
	 *
	 * @param array $o Ursprung.
	 * @param array $a Punkt A.
	 * @param array $b Punkt B.
	 * @return float
	 */
	public static function cross( array $o, array $a, array $b ) {
		return ( $a[0] - $o[0] ) * ( $b[1] - $o[1] ) - ( $a[1] - $o[1] ) * ( $b[0] - $o[0] );
	}

	/**
	 * Fläche eines (einfachen) Polygons – Gauss'sche Trapezformel, Betrag.
	 *
	 * @param array $poly Punkte.
	 * @return float
	 */
	public static function area( array $poly ) {
		$n = count( $poly );
		if ( $n < 3 ) {
			return 0.0;
		}
		$sum = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$j    = ( $i + 1 ) % $n;
			$sum += $poly[ $i ][0] * $poly[ $j ][1] - $poly[ $j ][0] * $poly[ $i ][1];
		}
		return abs( $sum ) / 2.0;
	}

	/**
	 * Begrenzungsrechteck.
	 *
	 * @param array $poly Punkte.
	 * @return array {minx, miny, maxx, maxy} – (0,0,0,0) bei leerem Polygon.
	 */
	public static function bounding_box( array $poly ) {
		if ( ! $poly ) {
			return array(
				'minx' => 0.0,
				'miny' => 0.0,
				'maxx' => 0.0,
				'maxy' => 0.0,
			);
		}

		$xs = array_column( $poly, 0 );
		$ys = array_column( $poly, 1 );

		return array(
			'minx' => min( $xs ),
			'miny' => min( $ys ),
			'maxx' => max( $xs ),
			'maxy' => max( $ys ),
		);
	}

	/**
	 * Polygon um (dx, dy) verschieben.
	 *
	 * @param array $poly Punkte.
	 * @param float $dx   Verschiebung X.
	 * @param float $dy   Verschiebung Y.
	 * @return array
	 */
	public static function translate( array $poly, $dx, $dy ) {
		$dx = (float) $dx;
		$dy = (float) $dy;
		return array_map(
			static function ( $p ) use ( $dx, $dy ) {
				return array( $p[0] + $dx, $p[1] + $dy );
			},
			$poly
		);
	}

	/**
	 * Polygon um den Ursprung (0,0) drehen.
	 *
	 * @param array $poly    Punkte.
	 * @param float $degrees Winkel, Gegenuhrzeigersinn.
	 * @return array
	 */
	public static function rotate( array $poly, $degrees ) {
		$rad = deg2rad( (float) $degrees );
		$cos = cos( $rad );
		$sin = sin( $rad );

		return array_map(
			static function ( $p ) use ( $cos, $sin ) {
				return array(
					$p[0] * $cos - $p[1] * $sin,
					$p[0] * $sin + $p[1] * $cos,
				);
			},
			$poly
		);
	}

	/**
	 * Polygon drehen und so verschieben, dass die Ecke des
	 * Begrenzungsrechtecks nach der Drehung exakt bei (anchor_x, anchor_y)
	 * liegt. Genau die Operation, die BinPacker beim Ausprobieren einer
	 * Platzierung braucht: erst drehen (ändert die Ausdehnung), dann an
	 * den Kandidatenpunkt schieben.
	 *
	 * @param array $poly     Punkte, bereits so normiert, dass irgendein
	 *                         fester Bezug existiert (die Methode selbst
	 *                         braucht das nicht - sie verschiebt ohnehin
	 *                         auf Basis der Bounding Box nach der Drehung).
	 * @param float $degrees  Winkel, Gegenuhrzeigersinn.
	 * @param float $anchor_x Ziel-X der linken unteren Ecke.
	 * @param float $anchor_y Ziel-Y der linken unteren Ecke.
	 * @return array
	 */
	public static function place( array $poly, $degrees, $anchor_x, $anchor_y ) {
		$rotated = self::rotate( $poly, $degrees );
		$bbox    = self::bounding_box( $rotated );

		return self::translate( $rotated, (float) $anchor_x - $bbox['minx'], (float) $anchor_y - $bbox['miny'] );
	}

	/**
	 * Konvexes Polygon um eine feste Distanz nach aussen versetzen
	 * ("aufblasen"), durch Verschieben jeder Kante entlang ihrer
	 * Aussennormalen und Neuberechnen der Eckpunkte als Schnitt
	 * benachbarter versetzter Kanten.
	 *
	 * Nur für konvexe, im Gegenuhrzeigersinn geordnete Polygone korrekt
	 * (bei uns immer der Fall - jede Hülle kommt aus convex_hull()). Dient
	 * als Sicherheitsabstand zwischen zwei Bauteilen: Statt bei jeder
	 * Kollisionsprüfung einen Mindestabstand zu rechnen, wird ein bereits
	 * platziertes Teil einmalig um den Sicherheitsabstand aufgeblasen und
	 * danach ganz normal auf (echte, nicht aufgeblasene) Überlappung
	 * geprüft (siehe Sat::overlap()).
	 *
	 * Anmerkung zur Genauigkeit: An einer konvexen Ecke ist der so
	 * entstehende Rand ein scharfer Knick, kein Kreisbogen wie bei einer
	 * echten Minkowski-Summe mit einer Scheibe. In dem schmalen Keil genau
	 * an der Ecke kann der garantierte Abstand deshalb minimal unter
	 * $distance liegen – für die Druckbett-Anordnung unschädlich, da der
	 * Sicherheitsabstand ohnehin grosszügig gewählt wird und keine
	 * Millimeterpräzision an der Düse ersetzt.
	 *
	 * @param array $poly     Konvexes Polygon, CCW geordnet.
	 * @param float $distance Versatz nach aussen (mm), muss >= 0 sein.
	 * @return array
	 */
	public static function offset( array $poly, $distance ) {
		$distance = (float) $distance;
		$n        = count( $poly );

		if ( $n < 3 || $distance <= 0 ) {
			return $poly;
		}

		// Für jede Kante die versetzte Linie als {point, direction}.
		$lines = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$a = $poly[ $i ];
			$b = $poly[ ( $i + 1 ) % $n ];

			$dx = $b[0] - $a[0];
			$dy = $b[1] - $a[1];
			$len = sqrt( $dx * $dx + $dy * $dy );

			if ( $len < self::EPSILON ) {
				continue; // Entartete Kante (Duplikatpunkt) überspringen.
			}

			// Aussennormale einer CCW-Kante: (dy, -dx) normiert.
			$nx = $dy / $len;
			$ny = -$dx / $len;

			$lines[] = array(
				'px' => $a[0] + $nx * $distance,
				'py' => $a[1] + $ny * $distance,
				'dx' => $dx,
				'dy' => $dy,
			);
		}

		$m = count( $lines );
		if ( $m < 3 ) {
			// Zu viele entartete Kanten für ein sinnvolles Ergebnis - im
			// Zweifel lieber die Ausgangsform unverändert zurückgeben, als
			// mit einer Division durch (nahe) Null eine Fantasieform zu
			// erzeugen.
			return $poly;
		}

		$out = array();
		for ( $i = 0; $i < $m; $i++ ) {
			$prev = $lines[ ( $i - 1 + $m ) % $m ];
			$cur  = $lines[ $i ];

			$point = self::line_intersection(
				$prev['px'],
				$prev['py'],
				$prev['dx'],
				$prev['dy'],
				$cur['px'],
				$cur['py'],
				$cur['dx'],
				$cur['dy']
			);

			if ( null === $point ) {
				// Parallele Kanten (z. B. ein Rechteck) - der Schnittpunkt
				// existiert rechnerisch nicht, die versetzte Ecke liegt aber
				// exakt am Beginn der aktuellen Linie.
				$point = array( $cur['px'], $cur['py'] );
			}

			$out[] = $point;
		}

		return $out;
	}

	/**
	 * Schnittpunkt zweier Geraden (Punkt + Richtung).
	 *
	 * @param float $px1 Punkt 1 X.
	 * @param float $py1 Punkt 1 Y.
	 * @param float $dx1 Richtung 1 X.
	 * @param float $dy1 Richtung 1 Y.
	 * @param float $px2 Punkt 2 X.
	 * @param float $py2 Punkt 2 Y.
	 * @param float $dx2 Richtung 2 X.
	 * @param float $dy2 Richtung 2 Y.
	 * @return array|null [x, y] oder null bei (fast) parallelen Geraden.
	 */
	public static function line_intersection( $px1, $py1, $dx1, $dy1, $px2, $py2, $dx2, $dy2 ) {
		$denom = $dx1 * $dy2 - $dy1 * $dx2;

		if ( abs( $denom ) < self::EPSILON ) {
			return null;
		}

		$t = ( ( $px2 - $px1 ) * $dy2 - ( $py2 - $py1 ) * $dx2 ) / $denom;

		return array( $px1 + $t * $dx1, $py1 + $t * $dy1 );
	}
}
