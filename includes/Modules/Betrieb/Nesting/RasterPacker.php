<?php
/**
 * Anordnen von Bauteilen nach ihrem echten Umriss auf einem Raster.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

/**
 * Was der konvexe BinPacker nicht kann, kann dieses Raster:
 *
 * - Einbuchtungen nutzen: Ein C-förmiger Ring lässt andere Teile in seine
 *   Öffnung, eine Schale mit Aufhängebögen die kleinen Teile neben den Bögen.
 * - Beliebige Drehwinkel, nicht nur Vierteldrehungen.
 * - Sperrflächen und Randabstand ohne Sonderfälle.
 *
 * Verfahren: Bett und Teile liegen auf einem Raster (Zellweite etwa ein
 * Viertel des Sicherheitsabstands). Jede Zeile des Betts ist ein Bitfeld.
 * Für ein Teil und eine Drehung wird je Betthöhe in einem Zug berechnet,
 * an welchen x-Positionen es mit der Belegung kollidiert: Jede Spanne einer
 * Teilzeile verbreitert die Belegungszeile dazu um ihre Länge (Verschieben
 * und ODER-Verknüpfen, logarithmisch viele Schritte statt je Zelle einer).
 * Gesetzt wird an der tiefsten freien Stelle, links zuerst. Der
 * Sicherheitsabstand steckt in der Belegung: Jedes gesetzte Teil wird einmal
 * um den Abstand aufgeblasen.
 *
 * Um die Reihenfolge-Abhängigkeit des Verfahrens zu mildern, werden vier
 * feste Sortierungen und danach mit festem Startwert gewürfelte Varianten
 * gerechnet, solange das Zeitbudget reicht und mehr Platten nötig sind, als
 * die Fläche mindestens verlangt. Behalten wird die Anordnung mit den
 * wenigsten Platten.
 *
 * Ergebnisformat wie BinPacker::arrange(): {x, y} ist die Ecke des
 * Begrenzungsrechtecks des gedrehten Teils (die Maske liegt genau auf ihr).
 */
class RasterPacker {

	/**
	 * Bits je Wort des Bitfelds (PHP-Ganzzahlen haben 64, eins bleibt als Vorzeichen).
	 */
	const B = 60;

	/**
	 * Standard-Drehwinkel: Vierteldrehungen um die senkrechte Achse. Die
	 * Auflagefläche bleibt dabei dieselbe, das Teil liegt wie in der
	 * Projektdatei vorgegeben, nur anders ums Bett gedreht.
	 */
	const DEFAULT_ROTATIONS = array( 0, 90, 180, 270 );

	/**
	 * Feinere Drehschritte (45°) für Teile, die schräg besser nisten. Nicht
	 * Standard; über den Filter lw16_plate_rotations einschaltbar.
	 */
	const FINE_ROTATIONS = array( 0, 90, 180, 270, 45, 135, 225, 315 );

	/**
	 * Bauteile auf einem oder mehreren Betten anordnen.
	 *
	 * @param array $parts           Liste {key, sil} – sil = Silhouette (siehe Silhouette).
	 * @param float $bed_width_mm    Bettbreite.
	 * @param float $bed_height_mm   Betttiefe.
	 * @param float $margin_mm       Mindestabstand zwischen Teilen und zum Rand.
	 * @param array $rotations       Drehwinkel in Grad.
	 * @param array $exclusion_zones Sperrflächen (Polygone in Bettkoordinaten).
	 * @param float $budget_s        Rechenzeit für die Suche nach besseren Reihenfolgen.
	 * @return array {bins: [{placements: [{key,x,y,rotation_deg}]}], errors: [{key,message}]}
	 */
	public static function arrange( array $parts, $bed_width_mm, $bed_height_mm, $margin_mm = 5.0, array $rotations = self::DEFAULT_ROTATIONS, array $exclusion_zones = array(), $budget_s = 4.0 ) {
		$started = microtime( true );
		$margin  = max( 0.0, (float) $margin_mm );
		$bed_w   = (float) $bed_width_mm;
		$bed_h   = (float) $bed_height_mm;

		if ( ! $rotations ) {
			$rotations = array( 0 );
		}

		$cell  = $margin > 0 ? min( 2.0, max( 1.0, $margin / 4 ) ) : 1.0;
		$cols  = (int) floor( $bed_w / $cell + 1e-9 );
		$rows  = (int) floor( $bed_h / $cell + 1e-9 );
		$m     = (int) ceil( $margin / $cell - 1e-9 );
		$grid  = array(
			'cell'  => $cell,
			'cols'  => $cols,
			'rows'  => $rows,
			'm'     => $m,
			'words' => (int) ceil( $cols / self::B ),
			// Zulässiger Bereich für die linke untere Zelle der Maske.
			'x_lo'  => $m,
			'y_lo'  => $m,
			'x_hi'  => (int) floor( ( $bed_w - $margin ) / $cell + 1e-9 ),
			'y_hi'  => (int) floor( ( $bed_h - $margin ) / $cell + 1e-9 ),
		);

		$base_rows = self::zone_rows( $exclusion_zones, $grid, $margin );

		$masks  = array();
		$items  = array();
		$errors = array();

		foreach ( $parts as $part ) {
			$sil = isset( $part['sil'] ) && is_array( $part['sil'] ) ? $part['sil'] : null;

			if ( ! $sil || empty( $sil['hull'] ) || count( $sil['hull'] ) < 3 ) {
				continue; // Keine Fläche.
			}

			$sig = isset( $sil['sig'] ) ? $sil['sig'] : md5( json_encode( array( $sil['w'], $sil['h'], $sil['runs'] ) ) );

			if ( ! isset( $masks[ $sig ] ) ) {
				$masks[ $sig ] = self::masks_for( $sil, $rotations, $grid );
			}

			if ( ! $masks[ $sig ] ) {
				$errors[] = array(
					'key'     => (string) $part['key'],
					'message' => __( 'Das Bauteil ist grösser als das Druckbett (auch gedreht) – Anordnung nicht möglich.', 'lindenweg16' ),
				);
				continue;
			}

			$items[] = array(
				'key'  => (string) $part['key'],
				'sig'  => $sig,
				'area' => $masks[ $sig ][0]['cells'],
				'long' => max( $masks[ $sig ][0]['w'], $masks[ $sig ][0]['h'] ),
			);
		}

		if ( ! $items ) {
			return array( 'bins' => array(), 'errors' => $errors );
		}

		$usable    = max( 1, ( $grid['x_hi'] - $grid['x_lo'] ) * ( $grid['y_hi'] - $grid['y_lo'] ) );
		$sum_cells = 0;
		foreach ( $items as $item ) {
			$sum_cells += $item['area'];
		}
		$lower_bound = (int) ceil( $sum_cells / $usable );

		$orders = array(
			static function ( $a, $b ) {
				return $b['area'] <=> $a['area'];
			},
			static function ( $a, $b ) {
				return ( $b['long'] <=> $a['long'] ) ?: ( $b['area'] <=> $a['area'] );
			},
		);

		$best = null;
		$seed = 20240611;
		$run  = 0;

		while ( true ) {
			$sorted = $items;

			if ( $run < count( $orders ) ) {
				usort( $sorted, $orders[ $run ] );
			} else {
				// Gewürfelte Variante: Fläche mal Zufallsfaktor, Reihenfolge der Gleichen fest.
				$noise = 0.15 + 0.1 * ( $run % 5 );
				foreach ( $sorted as $i => $item ) {
					$seed             = ( $seed * 1103515245 + 12345 ) & 0x7fffffff;
					$sorted[ $i ]['k'] = $item['area'] * ( 1 + $noise * ( ( $seed / 0x7fffffff ) * 2 - 1 ) );
				}
				usort(
					$sorted,
					static function ( $a, $b ) {
						return ( $b['k'] <=> $a['k'] ) ?: strcmp( $a['key'], $b['key'] );
					}
				);
			}

			$result = self::pack( $sorted, $masks, $grid, $base_rows );

			if ( null === $best || self::better( $result, $best ) ) {
				$best = $result;
			}

			++$run;

			if ( count( $best['bins'] ) <= $lower_bound && ! $best['errors'] ) {
				break; // Weniger Platten geht nach der Fläche nicht.
			}

			if ( $run >= 2 && microtime( true ) - $started > $budget_s ) {
				break;
			}

			if ( $run >= 40 ) {
				break;
			}
		}

		$out_bins = array();
		foreach ( $best['bins'] as $bin ) {
			$placements = array();
			foreach ( $bin['placements'] as $p ) {
				$placements[] = array(
					'key'          => $p['key'],
					'x'            => round( $p['cx'] * $cell, 3 ),
					'y'            => round( $p['cy'] * $cell, 3 ),
					'rotation_deg' => $p['deg'],
				);
			}
			$out_bins[] = array( 'placements' => $placements );
		}

		return array( 'bins' => $out_bins, 'errors' => array_merge( $errors, $best['errors'] ) );
	}

	/* ====================================================================
	 * Packen
	 * ================================================================== */

	/**
	 * Teile in der gegebenen Reihenfolge auf Platten verteilen.
	 *
	 * @param array $sorted    Teile in Packreihenfolge.
	 * @param array $masks     Masken je Silhouette.
	 * @param array $grid      Rasterkennwerte.
	 * @param array $base_rows Vorbelegung (Sperrflächen).
	 * @return array {bins, errors}
	 */
	protected static function pack( array $sorted, array $masks, array $grid, array $base_rows ) {
		$bins   = array();
		$errors = array();

		foreach ( $sorted as $item ) {
			$placed = false;

			foreach ( $bins as $index => $bin ) {
				if ( isset( $bin['failed'][ $item['sig'] ] ) ) {
					continue;
				}

				$fit = self::try_place( $masks[ $item['sig'] ], $bin, $grid );

				if ( $fit ) {
					self::commit( $bins[ $index ], $item, $fit, $grid );
					$placed = true;
					break;
				}

				$bins[ $index ]['failed'][ $item['sig'] ] = true;
			}

			if ( $placed ) {
				continue;
			}

			$bin = array( 'occ' => $base_rows, 'placements' => array(), 'failed' => array(), 'cells' => 0 );
			$fit = self::try_place( $masks[ $item['sig'] ], $bin, $grid );

			if ( ! $fit ) {
				$errors[] = array(
					'key'     => $item['key'],
					'message' => __( 'Bauteil konnte auf keiner Platte platziert werden.', 'lindenweg16' ),
				);
				continue;
			}

			self::commit( $bin, $item, $fit, $grid );
			$bins[] = $bin;
		}

		return array( 'bins' => $bins, 'errors' => $errors );
	}

	/**
	 * Ist Lauf $a besser als $b? Weniger nicht platzierte Teile, dann weniger
	 * Platten, dann weniger Fläche auf der letzten (die vorderen sind voller).
	 *
	 * @param array $a Lauf.
	 * @param array $b Lauf.
	 * @return bool
	 */
	protected static function better( array $a, array $b ) {
		if ( count( $a['errors'] ) !== count( $b['errors'] ) ) {
			return count( $a['errors'] ) < count( $b['errors'] );
		}

		if ( count( $a['bins'] ) !== count( $b['bins'] ) ) {
			return count( $a['bins'] ) < count( $b['bins'] );
		}

		$last_a = end( $a['bins'] );
		$last_b = end( $b['bins'] );

		return ( $last_a ? $last_a['cells'] : 0 ) < ( $last_b ? $last_b['cells'] : 0 );
	}

	/**
	 * Gefundene Lage in die Platte übernehmen: Belegung aktualisieren.
	 *
	 * @param array $bin  Platte (wird geändert).
	 * @param array $item Teil.
	 * @param array $fit  {cx, cy, deg, mask}.
	 * @param array $grid Rasterkennwerte.
	 */
	protected static function commit( array &$bin, array $item, array $fit, array $grid ) {
		$mask = $fit['mask'];

		foreach ( $mask['dil'] as $i => $runs ) {
			$j = $fit['cy'] + $i - $grid['m'];

			if ( $j < 0 || $j >= $grid['rows'] ) {
				continue;
			}

			foreach ( $runs as $run ) {
				$lo = max( 0, $fit['cx'] + $run[0] );
				$hi = min( $grid['cols'] - 1, $fit['cx'] + $run[1] );

				if ( $hi >= $lo ) {
					self::set_range( $bin['occ'][ $j ], $lo, $hi );
				}
			}
		}

		$bin['placements'][] = array( 'key' => $item['key'], 'cx' => $fit['cx'], 'cy' => $fit['cy'], 'deg' => $fit['deg'] );
		$bin['cells']       += $mask['cells'];
		$bin['failed']       = array();
	}

	/**
	 * Beste Lage eines Teils auf einer Platte: niedrigste Oberkante, dann
	 * kleinstes x, dann die frühere Drehung.
	 *
	 * @param array $variants Masken des Teils je Drehwinkel.
	 * @param array $bin      Platte.
	 * @param array $grid     Rasterkennwerte.
	 * @return array|null {cx, cy, deg, mask}
	 */
	protected static function try_place( array $variants, array $bin, array $grid ) {
		$words = $grid['words'];
		$best  = null;
		$cache = array();
		$empty = array();

		foreach ( $bin['occ'] as $j => $row ) {
			$empty[ $j ] = ! self::any( $row );
		}

		foreach ( $variants as $mask ) {
			$x_hi = $grid['x_hi'] - $mask['w'];
			$y_hi = $grid['y_hi'] - $mask['h'];

			if ( $x_hi < $grid['x_lo'] || $y_hi < $grid['y_lo'] ) {
				continue;
			}

			for ( $y = $grid['y_lo']; $y <= $y_hi; $y++ ) {
				// Schlechter als der beste Treffer, egal wo es hier landet.
				if ( $best && $y + $mask['h'] > $best['top'] ) {
					break;
				}

				$forbid = array_fill( 0, $words, 0 );

				foreach ( $mask['rows'] as $i => $runs ) {
					$j = $y + $i;

					if ( $empty[ $j ] ) {
						continue;
					}

					foreach ( $runs as $run ) {
						$key = $j . ':' . $run[0] . ':' . $run[1];

						if ( ! isset( $cache[ $key ] ) ) {
							$cache[ $key ] = self::window_or( $bin['occ'][ $j ], $run[0], $run[1] - $run[0] + 1, $words );
						}

						$c = $cache[ $key ];
						for ( $w = 0; $w < $words; $w++ ) {
							$forbid[ $w ] |= $c[ $w ];
						}
					}
				}

				$x = self::first_zero( $forbid, $grid['x_lo'], $x_hi );

				if ( null === $x ) {
					continue;
				}

				$top = $y + $mask['h'];

				if ( ! $best || $top < $best['top'] || ( $top === $best['top'] && $x < $best['cx'] ) ) {
					$best = array( 'cx' => $x, 'cy' => $y, 'top' => $top, 'deg' => $mask['deg'], 'mask' => $mask );
				}

				break; // Erste freie Zeile dieser Drehung ist ihre tiefste.
			}
		}

		return $best;
	}

	/* ====================================================================
	 * Masken
	 * ================================================================== */

	/**
	 * Masken einer Silhouette für alle Drehwinkel (gleiche nur einmal).
	 *
	 * @param array $sil       Silhouette.
	 * @param array $rotations Winkel.
	 * @param array $grid      Rasterkennwerte.
	 * @return array Liste Masken, leer wenn keine aufs Bett passt.
	 */
	protected static function masks_for( array $sil, array $rotations, array $grid ) {
		$fine = array();
		foreach ( $sil['runs'] as $r => $runs ) {
			$line = str_repeat( '0', $sil['w'] );
			foreach ( $runs as $run ) {
				$line = substr_replace( $line, str_repeat( '1', $run[1] - $run[0] + 1 ), $run[0], $run[1] - $run[0] + 1 );
			}
			$fine[ $r ] = $line;
		}

		$out  = array();
		$seen = array();

		foreach ( $rotations as $deg ) {
			$mask = self::build_mask( $sil, $fine, $deg, $grid );

			if ( ! $mask ) {
				continue;
			}

			if ( $mask['w'] > $grid['x_hi'] - $grid['x_lo'] || $mask['h'] > $grid['y_hi'] - $grid['y_lo'] ) {
				continue;
			}

			$sig = md5( json_encode( $mask['rows'] ) );

			if ( isset( $seen[ $sig ] ) ) {
				continue;
			}

			$seen[ $sig ] = true;
			$out[]        = $mask;
		}

		return $out;
	}

	/**
	 * Maske einer Silhouette bei einem Drehwinkel.
	 *
	 * Das Raster liegt genau auf der Ecke des gedrehten Begrenzungsrechtecks
	 * der Hülle. Eine Zelle gilt als belegt, wenn einer von 3×3 Messpunkten in
	 * ihr auf dem feinen Umriss liegt (konservativ: lieber eine Zelle zu viel).
	 *
	 * @param array $sil  Silhouette.
	 * @param array $fine Feiner Umriss als Zeichenketten je Zeile.
	 * @param float $deg  Drehwinkel (Gegenuhrzeigersinn).
	 * @param array $grid Rasterkennwerte.
	 * @return array|null
	 */
	protected static function build_mask( array $sil, array $fine, $deg, array $grid ) {
		$rad = deg2rad( (float) $deg );
		$cos = cos( $rad );
		$sin = sin( $rad );
		$f   = $sil['f'];
		$c   = $grid['cell'];
		$m   = $grid['m'];

		$hull = Polygon::rotate( $sil['hull'], $deg );
		$bbox = Polygon::bounding_box( $hull );
		$w_mm = $bbox['maxx'] - $bbox['minx'];
		$h_mm = $bbox['maxy'] - $bbox['miny'];
		$cw   = max( 1, (int) ceil( $w_mm / $c - 1e-9 ) );
		$ch   = max( 1, (int) ceil( $h_mm / $c - 1e-9 ) );

		$offsets = array( 0.1, 0.5, 0.9 );
		$fw      = $sil['w'];
		$fh      = $sil['h'];
		$rows    = array();
		$cells   = 0;

		for ( $j = 0; $j < $ch; $j++ ) {
			$row   = array();
			$start = -1;

			for ( $i = 0; $i < $cw; $i++ ) {
				$hit = false;

				foreach ( $offsets as $oy ) {
					$qy = $bbox['miny'] + ( $j + $oy ) * $c;

					foreach ( $offsets as $ox ) {
						$qx = $bbox['minx'] + ( $i + $ox ) * $c;
						// Zurück in den ungedrehten Umriss.
						$px = $qx * $cos + $qy * $sin;
						$py = -$qx * $sin + $qy * $cos;
						$fa = (int) floor( $px / $f );
						$fb = (int) floor( $py / $f );

						if ( $fa >= 0 && $fb >= 0 && $fa < $fw && $fb < $fh && '1' === $fine[ $fb ][ $fa ] ) {
							$hit = true;
							break 2;
						}
					}
				}

				if ( $hit ) {
					++$cells;
					if ( $start < 0 ) {
						$start = $i;
					}
				} elseif ( $start >= 0 ) {
					$row[] = array( $start, $i - 1 );
					$start = -1;
				}
			}

			if ( $start >= 0 ) {
				$row[] = array( $start, $cw - 1 );
			}

			$rows[] = $row;
		}

		if ( ! $cells ) {
			return null;
		}

		return array(
			'deg'   => $deg,
			'w'     => $cw,
			'h'     => $ch,
			'rows'  => $rows,
			'cells' => $cells,
			'dil'   => self::dilate( $rows, $cw, $ch, $m ),
		);
	}

	/**
	 * Maske um den Sicherheitsabstand (Kreisscheibe, in Zellen) aufblasen.
	 *
	 * @param array $rows Spannen je Zeile.
	 * @param int   $cw   Breite.
	 * @param int   $ch   Höhe.
	 * @param int   $m    Radius in Zellen.
	 * @return array Zeile (von -m bis ch+m-1, Index 0 = Zeile -m) => Spannen, x ab Maskenursprung.
	 */
	protected static function dilate( array $rows, $cw, $ch, $m ) {
		$out = array();

		for ( $r = -$m; $r < $ch + $m; $r++ ) {
			$spans = array();

			for ( $dy = -$m; $dy <= $m; $dy++ ) {
				$src = $r + $dy;

				if ( $src < 0 || $src >= $ch || ! $rows[ $src ] ) {
					continue;
				}

				$dx = (int) floor( sqrt( max( 0, $m * $m - $dy * $dy ) ) + 1e-9 );

				foreach ( $rows[ $src ] as $run ) {
					$spans[] = array( $run[0] - $dx, $run[1] + $dx );
				}
			}

			$out[] = self::merge_spans( $spans );
		}

		return $out;
	}

	/**
	 * Überlappende Spannen zusammenfassen.
	 *
	 * @param array $spans Liste [von, bis].
	 * @return array
	 */
	protected static function merge_spans( array $spans ) {
		if ( ! $spans ) {
			return array();
		}

		usort(
			$spans,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);

		$out = array();
		$cur = $spans[0];

		for ( $i = 1, $n = count( $spans ); $i < $n; $i++ ) {
			if ( $spans[ $i ][0] <= $cur[1] + 1 ) {
				$cur[1] = max( $cur[1], $spans[ $i ][1] );
			} else {
				$out[] = $cur;
				$cur   = $spans[ $i ];
			}
		}

		$out[] = $cur;

		return $out;
	}

	/**
	 * Vorbelegung des Betts durch Sperrflächen, samt Sicherheitsabstand.
	 *
	 * @param array $zones  Polygone [[x,y], ...] in Bettkoordinaten.
	 * @param array $grid   Rasterkennwerte.
	 * @param float $margin Sicherheitsabstand in mm.
	 * @return array Zeilen mit Bitfeldern.
	 */
	protected static function zone_rows( array $zones, array $grid, $margin ) {
		$rows = array();
		for ( $j = 0; $j < $grid['rows']; $j++ ) {
			$rows[ $j ] = array_fill( 0, $grid['words'], 0 );
		}

		$c = $grid['cell'];

		foreach ( $zones as $zone ) {
			$hull = Polygon::convex_hull( is_array( $zone ) ? $zone : array() );

			if ( count( $hull ) < 3 ) {
				continue;
			}

			$box   = Polygon::bounding_box( $hull );
			$j0    = max( 0, (int) floor( $box['miny'] / $c ) - 1 );
			$j1    = min( $grid['rows'] - 1, (int) ceil( $box['maxy'] / $c ) + 1 );
			$i0    = max( 0, (int) floor( $box['minx'] / $c ) - 1 );
			$i1    = min( $grid['cols'] - 1, (int) ceil( $box['maxx'] / $c ) + 1 );
			$cells = array();

			for ( $j = $j0; $j <= $j1; $j++ ) {
				for ( $i = $i0; $i <= $i1; $i++ ) {
					if ( self::cell_touches( $hull, $i * $c, $j * $c, $c ) ) {
						$cells[ $j ][] = $i;
					}
				}
			}

			$m = $grid['m'];

			foreach ( $cells as $j => $list ) {
				$spans = array();
				foreach ( $list as $i ) {
					$spans[] = array( $i, $i );
				}
				$spans = self::merge_spans( $spans );

				for ( $dy = -$m; $dy <= $m; $dy++ ) {
					$jj = $j + $dy;

					if ( $jj < 0 || $jj >= $grid['rows'] ) {
						continue;
					}

					$dx = (int) floor( sqrt( max( 0, $m * $m - $dy * $dy ) ) + 1e-9 );

					foreach ( $spans as $span ) {
						self::set_range( $rows[ $jj ], max( 0, $span[0] - $dx ), min( $grid['cols'] - 1, $span[1] + $dx ) );
					}
				}
			}
		}

		return $rows;
	}

	/**
	 * Berührt ein konvexes Polygon die Zelle (x, y, c×c)?
	 *
	 * @param array $hull Konvexes Polygon, gegen den Uhrzeigersinn.
	 * @param float $x    Zelle links.
	 * @param float $y    Zelle unten.
	 * @param float $c    Zellweite.
	 * @return bool
	 */
	protected static function cell_touches( array $hull, $x, $y, $c ) {
		$cell = array( array( $x, $y ), array( $x + $c, $y ), array( $x + $c, $y + $c ), array( $x, $y + $c ) );

		return Sat::overlap( $hull, $cell );
	}

	/* ====================================================================
	 * Bitfelder (Wörter zu je B Bit, Bit 0 = kleinste Zelle)
	 * ================================================================== */

	/**
	 * Ist irgendein Bit gesetzt?
	 *
	 * @param array $words Bitfeld.
	 * @return bool
	 */
	protected static function any( array $words ) {
		foreach ( $words as $w ) {
			if ( $w ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Bitfeld um $k Stellen nach rechts schieben (Zelle x rückt auf x-k).
	 *
	 * @param array $v Bitfeld.
	 * @param int   $k Stellen.
	 * @param int   $n Wortzahl.
	 * @return array
	 */
	protected static function shr( array $v, $k, $n ) {
		$out = array_fill( 0, $n, 0 );
		$q   = intdiv( $k, self::B );
		$r   = $k % self::B;

		for ( $i = 0; $i < $n; $i++ ) {
			$j = $i + $q;

			if ( $j >= $n ) {
				break;
			}

			$word = $v[ $j ] >> $r;

			if ( $r > 0 && $j + 1 < $n ) {
				$word |= ( $v[ $j + 1 ] & ( ( 1 << $r ) - 1 ) ) << ( self::B - $r );
			}

			$out[ $i ] = $word;
		}

		return $out;
	}

	/**
	 * Kollisionspositionen einer Spanne: Bit x ist gesetzt, wenn eine der
	 * Zellen x+a … x+a+len-1 der Belegungszeile belegt ist.
	 *
	 * @param array $occ Belegungszeile.
	 * @param int   $a   Beginn der Spanne.
	 * @param int   $len Länge der Spanne.
	 * @param int   $n   Wortzahl.
	 * @return array
	 */
	protected static function window_or( array $occ, $a, $len, $n ) {
		$res     = $a > 0 ? self::shr( $occ, $a, $n ) : $occ;
		$covered = 1;

		while ( $covered < $len ) {
			$step    = min( $covered, $len - $covered );
			$shifted = self::shr( $res, $step, $n );

			for ( $w = 0; $w < $n; $w++ ) {
				$res[ $w ] |= $shifted[ $w ];
			}

			$covered += $step;
		}

		return $res;
	}

	/**
	 * Kleinste Position in [lo, hi], an der kein Bit gesetzt ist.
	 *
	 * @param array $forbid Gesperrte Positionen.
	 * @param int   $lo     Von.
	 * @param int   $hi     Bis (einschließlich).
	 * @return int|null
	 */
	protected static function first_zero( array $forbid, $lo, $hi ) {
		$mask_all = ( 1 << self::B ) - 1;
		$w_lo     = intdiv( $lo, self::B );
		$w_hi     = intdiv( $hi, self::B );

		for ( $w = $w_lo; $w <= $w_hi; $w++ ) {
			$free = ~$forbid[ $w ] & $mask_all;

			if ( $w === $w_lo ) {
				$free &= $mask_all << ( $lo % self::B ) & $mask_all;
			}

			if ( $w === $w_hi ) {
				$top = $hi % self::B;
				$free &= $top >= self::B - 1 ? $mask_all : ( ( 1 << ( $top + 1 ) ) - 1 );
			}

			if ( $free ) {
				$low = $free & -$free;

				return $w * self::B + (int) round( log( $low, 2 ) );
			}
		}

		return null;
	}

	/**
	 * Bits lo … hi (einschließlich) setzen.
	 *
	 * @param array $words Bitfeld (wird geändert).
	 * @param int   $lo    Von.
	 * @param int   $hi    Bis.
	 */
	protected static function set_range( array &$words, $lo, $hi ) {
		$w_lo = intdiv( $lo, self::B );
		$w_hi = intdiv( $hi, self::B );

		for ( $w = $w_lo; $w <= $w_hi; $w++ ) {
			$from = $w === $w_lo ? $lo % self::B : 0;
			$to   = $w === $w_hi ? $hi % self::B : self::B - 1;
			$len  = $to - $from + 1;

			$words[ $w ] |= ( ( 1 << $len ) - 1 ) << $from;
		}
	}
}
