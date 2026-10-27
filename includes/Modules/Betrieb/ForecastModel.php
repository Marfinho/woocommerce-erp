<?php
/**
 * Prognosemodell für Zeitreihen: Erwartung und Unsicherheitsbänder.
 *
 * Kleine Betriebe haben kurze, unruhige Verkaufsreihen. Ein Verfahren, das
 * Genauigkeit vortäuscht, schadet mehr als keins. Deshalb gilt hier:
 *
 * - **Gedämpfter Trend.** Ein Aufwärtstrend setzt sich nicht unendlich fort;
 *   er flacht mit jedem Schritt ab (Dämpfung 0,9). Der Level wird exponentiell
 *   geglättet (Holt), neuere Wochen zählen mehr als alte.
 * - **Saison nur mit Beleg.** Saisonfaktoren werden erst gebildet, wenn
 *   mindestens zwei volle Saisons vorliegen und die Reihe keine Nullen
 *   dominieren. Vorher wäre „Weihnachten“ ein Rateergebnis.
 * - **Bänder wachsen mit dem Horizont.** Die Streuung stammt aus den
 *   Einschrittfehlern der Anpassung und wird für jeden weiteren Schritt
 *   größer (Varianzformel des gedämpften Holt-Modells). Ausgegeben werden
 *   ein 80-%- und ein 95-%-Band.
 * - **Gütemaß statt Behauptung.** Jede Prognose trägt ihren eigenen Rückblick:
 *   Wie weit lagen Einschrittprognosen in der jüngsten Vergangenheit
 *   daneben (wMAPE), und wie oft lag der Istwert im 80-%-Band?
 *
 * Reine Mathematik ohne Datenbank und WordPress.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Prognose einer Zeitreihe.
 */
class ForecastModel {

	/**
	 * Dämpfung des Trends je Schritt.
	 */
	const PHI = 0.9;

	/**
	 * Quantile der Normalverteilung für die Bänder.
	 */
	const Z80 = 1.2816;
	const Z95 = 1.96;

	/**
	 * Fehlende Werte (null) durch Nachbarn ersetzen und Randlücken kappen.
	 *
	 * Eine Lücke mitten in der Reihe wird linear überbrückt, eine am Anfang
	 * abgeschnitten (dort gab es noch nichts zu messen), eine am Ende
	 * ebenso (der laufende, unvollständige Zeitraum gehört nicht hierher).
	 *
	 * @param array $series Werte, null für „nicht gemessen“.
	 * @return float[]
	 */
	public static function clean( array $series ) {
		$values = array_values( $series );

		while ( $values && null === $values[0] ) {
			array_shift( $values );
		}
		while ( $values && null === $values[ count( $values ) - 1 ] ) {
			array_pop( $values );
		}

		$n = count( $values );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( null !== $values[ $i ] ) {
				continue;
			}

			$j = $i;
			while ( null === $values[ $j ] ) {
				$j++;
			}

			$a = (float) $values[ $i - 1 ];
			$b = (float) $values[ $j ];

			for ( $k = $i; $k < $j; $k++ ) {
				$values[ $k ] = $a + ( $b - $a ) * ( $k - $i + 1 ) / ( $j - $i + 1 );
			}
		}

		return array_map( 'floatval', $values );
	}

	/**
	 * Saisonfaktoren (multiplikativ) oder null, wenn die Reihe sie nicht trägt.
	 *
	 * @param float[] $y      Reihe.
	 * @param int     $season Länge der Saison (12 Monate, 52 Wochen), 0 = keine.
	 * @return float[]|null Faktoren je Position in der Saison.
	 */
	public static function seasonal_indices( array $y, $season ) {
		$season = (int) $season;
		$n      = count( $y );

		if ( $season < 2 || $n < 2 * $season ) {
			return null;
		}

		$zeros = 0;
		foreach ( $y as $v ) {
			if ( $v <= 0 ) {
				$zeros++;
			}
		}
		if ( $zeros > 0.1 * $n ) {
			return null;
		}

		// Gleitender Durchschnitt über genau eine Saison (zentriert).
		$ratios = array_fill( 0, $season, array() );
		$half   = intdiv( $season, 2 );

		for ( $t = $half; $t < $n - $half; $t++ ) {
			$sum = 0.0;
			$cnt = 0;

			for ( $k = -$half; $k <= $half; $k++ ) {
				// Bei gerader Saison zählen die Randpunkte nur halb.
				$w = ( 0 === $season % 2 && abs( $k ) === $half ) ? 0.5 : 1.0;
				if ( 0 !== $season % 2 && abs( $k ) === $half && $season % 2 ) {
					$w = 1.0;
				}
				$sum += $w * $y[ $t + $k ];
				$cnt += $w;
			}

			$ma = $cnt > 0 ? $sum / $cnt : 0.0;
			if ( $ma > 0 ) {
				$ratios[ $t % $season ][] = $y[ $t ] / $ma;
			}
		}

		$idx = array();
		foreach ( $ratios as $list ) {
			if ( ! $list ) {
				return null;
			}
			$idx[] = array_sum( $list ) / count( $list );
		}

		$mean = array_sum( $idx ) / $season;
		if ( $mean <= 0 ) {
			return null;
		}

		foreach ( $idx as $i => $v ) {
			$idx[ $i ] = max( 0.3, min( 3.0, $v / $mean ) );
		}

		return $idx;
	}

	/**
	 * Gedämpftes Holt-Modell anpassen.
	 *
	 * @param float[] $y Reihe (mindestens vier Werte).
	 * @return array {level, trend, alpha, beta, sigma, fitted}
	 */
	public static function fit_holt( array $y ) {
		$n    = count( $y );
		$best = null;
		$phi  = self::PHI;

		foreach ( array( 0.1, 0.3, 0.5, 0.7, 0.9 ) as $alpha ) {
			foreach ( array( 0.05, 0.1, 0.2, 0.3 ) as $beta ) {
				$level = $y[0];
				$trend = $n >= 3 ? ( $y[2] - $y[0] ) / 2 : 0.0;
				$sse   = 0.0;
				$m     = 0;
				$fit   = array( $y[0] );

				for ( $t = 1; $t < $n; $t++ ) {
					$pred = $level + $phi * $trend;
					$err  = $y[ $t ] - $pred;
					$sse += $err * $err;
					$m++;
					$fit[] = $pred;

					$new_level = $pred + $alpha * $err;
					$trend     = $phi * $trend + $beta * $alpha * $err;
					$level     = $new_level;
				}

				if ( null === $best || $sse < $best['sse'] ) {
					$best = array(
						'sse'    => $sse,
						'm'      => $m,
						'level'  => $level,
						'trend'  => $trend,
						'alpha'  => $alpha,
						'beta'   => $beta,
						'fitted' => $fit,
					);
				}
			}
		}

		$dof   = max( 1, $best['m'] - 2 );
		$sigma = sqrt( $best['sse'] / $dof );

		return array(
			'level'  => $best['level'],
			'trend'  => $best['trend'],
			'alpha'  => $best['alpha'],
			'beta'   => $best['beta'],
			'sigma'  => $sigma,
			'fitted' => $best['fitted'],
		);
	}

	/**
	 * Prognose mit Bändern.
	 *
	 * @param array $series      Reihe, null für Lücken.
	 * @param int   $horizon     Anzahl Schritte voraus.
	 * @param int   $season      Länge der Saison, 0 = keine.
	 * @param bool  $non_negative Werte unter null abschneiden (Mengen, Umsatz).
	 * @return array {method, n, points[], sigma, accuracy, coverage80, reliability, seasonal}
	 */
	public static function forecast( array $series, $horizon, $season = 0, $non_negative = true ) {
		$y       = self::clean( $series );
		$n       = count( $y );
		$horizon = max( 1, (int) $horizon );

		if ( $n < 2 ) {
			return array(
				'method'      => 'keine',
				'n'           => $n,
				'points'      => array(),
				'sigma'       => 0.0,
				'accuracy'    => null,
				'coverage80'  => null,
				'reliability' => 'keine',
				'seasonal'    => false,
			);
		}

		$core = self::core( $y, $horizon, (int) $season, (bool) $non_negative );

		// Rückblick: Einschrittprognosen aus den letzten Ursprüngen.
		$errors   = 0.0;
		$actuals  = 0.0;
		$hits     = 0;
		$tests    = 0;
		$first    = max( 4, $n - 8 );

		for ( $k = $first; $k < $n; $k++ ) {
			$sub = self::core( array_slice( $y, 0, $k ), 1, (int) $season, (bool) $non_negative );

			if ( empty( $sub['points'] ) ) {
				continue;
			}

			$p       = $sub['points'][0];
			$errors += abs( $y[ $k ] - $p['mean'] );
			$actuals += abs( $y[ $k ] );
			$tests++;

			if ( $y[ $k ] >= $p['lo80'] && $y[ $k ] <= $p['hi80'] ) {
				$hits++;
			}
		}

		$accuracy = ( $tests >= 3 && $actuals > 0 ) ? $errors / $actuals * 100 : null;

		$core['accuracy']    = $accuracy;
		$core['coverage80']  = $tests >= 3 ? $hits / $tests * 100 : null;
		$core['reliability'] = self::reliability( $accuracy, $n );

		return $core;
	}

	/**
	 * Gütestufe aus dem Rückblick - reine Regel.
	 *
	 * @param float|null $accuracy wMAPE in Prozent.
	 * @param int        $n        Anzahl Werte.
	 * @return string hoch, mittel, niedrig oder keine
	 */
	public static function reliability( $accuracy, $n ) {
		if ( null === $accuracy || $n < 8 ) {
			return 'keine';
		}

		if ( $accuracy <= 15 ) {
			return 'hoch';
		}

		return $accuracy <= 30 ? 'mittel' : 'niedrig';
	}

	/**
	 * Kern: anpassen und vorausrechnen, ohne Rückblick.
	 *
	 * @param float[] $y            Bereinigte Reihe.
	 * @param int     $horizon      Schritte.
	 * @param int     $season       Saisonlänge.
	 * @param bool    $non_negative Nicht unter null.
	 * @return array
	 */
	protected static function core( array $y, $horizon, $season, $non_negative ) {
		$n   = count( $y );
		$idx = self::seasonal_indices( $y, $season );

		$work = $y;
		if ( $idx ) {
			foreach ( $y as $t => $v ) {
				$work[ $t ] = $v / $idx[ $t % $season ];
			}
		}

		if ( $n < 4 ) {
			// Zu kurz für einen Trend: Mittel der letzten Werte, breites Band.
			$tail  = array_slice( $work, -3 );
			$mean  = array_sum( $tail ) / count( $tail );
			$sd    = self::sd( $work );
			$sigma = max( $sd, 0.25 * abs( $mean ) );
			$model = array( 'level' => $mean, 'trend' => 0.0, 'alpha' => 0.3, 'beta' => 0.0, 'sigma' => $sigma );
			$method = 'mittelwert';
		} else {
			$model  = self::fit_holt( $work );
			$model['sigma'] = max( $model['sigma'], 0.05 * abs( $model['level'] ) );
			$method = $idx ? 'holt-saison' : 'holt';
		}

		$points = array();
		$phi    = self::PHI;
		$damp   = 0.0;

		for ( $h = 1; $h <= $horizon; $h++ ) {
			$damp += pow( $phi, $h );
			$mean  = $model['level'] + $model['trend'] * $damp;

			// Varianz wächst mit dem Horizont (gedämpftes Holt, additive Fehler).
			$var = 1.0;
			for ( $j = 1; $j < $h; $j++ ) {
				$sum_phi = $phi * ( pow( $phi, $j ) - 1 ) / ( $phi - 1 );
				$c       = $model['alpha'] * ( 1 + $model['beta'] * $sum_phi );
				$var    += $c * $c;
			}
			$sd = $model['sigma'] * sqrt( $var );

			$factor = $idx ? $idx[ ( $n + $h - 1 ) % $season ] : 1.0;
			$mean  *= $factor;
			$sd    *= $factor;

			$points[] = self::point( $h, $mean, $sd, $non_negative );
		}

		return array(
			'method'      => $method,
			'n'           => $n,
			'points'      => $points,
			'sigma'       => (float) $model['sigma'],
			'accuracy'    => null,
			'coverage80'  => null,
			'reliability' => 'keine',
			'seasonal'    => (bool) $idx,
		);
	}

	/**
	 * Ein Prognosepunkt mit Bändern.
	 *
	 * @param int   $h            Schritt.
	 * @param float $mean         Erwartung.
	 * @param float $sd           Streuung.
	 * @param bool  $non_negative Nicht unter null.
	 * @return array
	 */
	public static function point( $h, $mean, $sd, $non_negative ) {
		$floor = $non_negative ? 0.0 : -INF;

		return array(
			'h'    => (int) $h,
			'mean' => max( $floor, $mean ),
			'sd'   => (float) $sd,
			'lo80' => max( $floor, $mean - self::Z80 * $sd ),
			'hi80' => max( $floor, $mean + self::Z80 * $sd ),
			'lo95' => max( $floor, $mean - self::Z95 * $sd ),
			'hi95' => max( $floor, $mean + self::Z95 * $sd ),
		);
	}

	/**
	 * Stichprobenstandardabweichung.
	 *
	 * @param float[] $v Werte.
	 * @return float
	 */
	public static function sd( array $v ) {
		$n = count( $v );
		if ( $n < 2 ) {
			return 0.0;
		}

		$mean = array_sum( $v ) / $n;
		$sum  = 0.0;
		foreach ( $v as $x ) {
			$sum += ( $x - $mean ) * ( $x - $mean );
		}

		return sqrt( $sum / ( $n - 1 ) );
	}

	/**
	 * Verteilungsfunktion der Standardnormalverteilung.
	 *
	 * Näherung nach Abramowitz und Stegun (7.1.26), Fehler unter 1,5e-7.
	 *
	 * @param float $x Wert.
	 * @return float
	 */
	public static function normal_cdf( $x ) {
		$sign = $x < 0 ? -1 : 1;
		$z    = abs( $x ) / M_SQRT2;
		$t    = 1.0 / ( 1.0 + 0.3275911 * $z );
		$y    = 1.0 - ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( -$z * $z );

		return 0.5 * ( 1.0 + $sign * $y );
	}

	/**
	 * Umkehrung der Verteilungsfunktion (Quantil der Standardnormalverteilung).
	 *
	 * Näherung nach Acklam, Fehler unter 1,2e-9 im Bereich 0,02 bis 0,98 und
	 * für die Zwecke hier überall ausreichend.
	 *
	 * @param float $p Wahrscheinlichkeit zwischen 0 und 1 (ausschließlich).
	 * @return float
	 */
	public static function normal_quantile( $p ) {
		$p = min( 0.999999, max( 0.000001, (float) $p ) );

		$a = array( -39.69683028665376, 220.9460984245205, -275.9285104469687, 138.3577518672690, -30.66479806614716, 2.506628277459239 );
		$b = array( -54.47609879822406, 161.5858368580409, -155.6989798598866, 66.80131188771972, -13.28068155288572 );
		$c = array( -0.007784894002430293, -0.3223964580411365, -2.400758277161838, -2.549732539343734, 4.374664141464968, 2.938163982698783 );
		$d = array( 0.007784695709041462, 0.3224671290700398, 2.445134137142996, 3.754408661907416 );

		if ( $p < 0.02425 ) {
			$q = sqrt( -2 * log( $p ) );

			return ( ( ( ( ( $c[0] * $q + $c[1] ) * $q + $c[2] ) * $q + $c[3] ) * $q + $c[4] ) * $q + $c[5] ) / ( ( ( ( $d[0] * $q + $d[1] ) * $q + $d[2] ) * $q + $d[3] ) * $q + 1 );
		}

		if ( $p > 1 - 0.02425 ) {
			$q = sqrt( -2 * log( 1 - $p ) );

			return -( ( ( ( ( $c[0] * $q + $c[1] ) * $q + $c[2] ) * $q + $c[3] ) * $q + $c[4] ) * $q + $c[5] ) / ( ( ( ( $d[0] * $q + $d[1] ) * $q + $d[2] ) * $q + $d[3] ) * $q + 1 );
		}

		$q = $p - 0.5;
		$r = $q * $q;

		return ( ( ( ( ( $a[0] * $r + $a[1] ) * $r + $a[2] ) * $r + $a[3] ) * $r + $a[4] ) * $r + $a[5] ) * $q / ( ( ( ( ( $b[0] * $r + $b[1] ) * $r + $b[2] ) * $r + $b[3] ) * $r + $b[4] ) * $r + 1 );
	}
}
