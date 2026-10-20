<?php
/**
 * Konturgenaues Anordnen mehrerer Bauteile auf einem oder mehreren
 * Druckbetten (Platten).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

/**
 * Bottom-Left-Fill-Platzierung auf Basis der tatsächlichen Bauteilkontur
 * (konvexe Hülle), nicht des Begrenzungsrechtecks.
 *
 * Anspruch und Grenzen ehrlich benannt: Dies ist keine vollständige
 * No-Fit-Polygon-Optimierung, wie sie ein dedizierter Nesting-Algorithmus
 * (SVGNest/Deepnest) leisten würde – dafür bräuchte es Randflächen-
 * Booleans, die sich in dieser Grössenordnung nicht seriös in einer
 * Sitzung bauen und "penibel" testen lassen. Was hier steht, ist trotzdem
 * echtes, konturbasiertes Nesting: Die Platzierung nutzt die tatsächliche
 * (konvexe) Form jedes Bauteils, nicht sein Begrenzungsrechteck, und die
 * Kollisionsfreiheit ist über SAT exakt geprüft, nicht geschätzt. Zwei
 * runde Teile rücken dadurch näher zusammen, als es ihre Rechtecke
 * erlauben würden – nur eben nicht so dicht, wie ein echter
 * No-Fit-Polygon-Nester sie ineinander verzahnen würde.
 *
 * Determinismus ist Absicht: Dieselbe Eingabe liefert immer dieselbe
 * Anordnung – wichtig, damit ein Ergebnis nachvollziehbar bleibt und sich
 * ohne Zufallskomponente testen lässt.
 */
class BinPacker {

	/**
	 * Erlaubte Rotationsschritte, wenn der Aufrufer keine eigenen angibt.
	 */
	const DEFAULT_ROTATIONS = array( 0, 90, 180, 270 );

	/**
	 * Winziger zusätzlicher Versatz (mm) beim Erzeugen von Kandidatenpunkten
	 * entlang bereits belegter (um margin_mm aufgeblasener) Flächen.
	 *
	 * Sat::overlap() zählt eine exakte Berührung absichtlich als Kollision
	 * (siehe dort) – ein Kandidat, der exakt auf dem aufgeblasenen Rand
	 * eines Nachbarn liegt, würde also an genau der Stelle scheitern, die
	 * eigentlich den Sicherheitsabstand schon erfüllt. NUDGE_MM schiebt
	 * den Kandidaten einen Hauch darüber hinaus – geometrisch bedeutungslos
	 * (ein Hundertstel Millimeter, weit unter jeder Druckertoleranz), aber
	 * genug, damit "genau margin_mm entfernt" als frei erkannt wird, statt
	 * jede Berührung des aufgeblasenen Randes selbst wieder auszuschliessen.
	 */
	const NUDGE_MM = 0.01;

	/**
	 * Bauteile auf einem oder mehreren Druckbetten anordnen.
	 *
	 * @param array $parts     Liste {key, hull} – hull = konvexe Hülle
	 *                          [[x,y], ...] in mm. Jede gewünschte Kopie
	 *                          ist ein eigener Eintrag mit eigenem key
	 *                          (der Aufrufer vervielfältigt vorher).
	 * @param float $bed_width_mm  Druckbett-Breite.
	 * @param float $bed_height_mm Druckbett-Tiefe.
	 * @param float $margin_mm     Mindestabstand zwischen zwei Bauteilen
	 *                              und zum Bettrand.
	 * @param array $rotations     Erlaubte Rotationswinkel in Grad.
	 * @param array $exclusion_zones Feste Sperrflächen in absoluten
	 *                                Bett-Koordinaten (z. B. das
	 *                                "bed_exclude_area" des Druckerprofils
	 *                                - Bambu-Drucker sperren typischerweise
	 *                                eine kleine Bettecke). Liste von
	 *                                Polygonen [[[x,y], ...], ...], gilt
	 *                                für jede Platte gleich (drucker-,
	 *                                nicht plattenspezifisch).
	 * @return array {bins: [{placements: [{key,x,y,rotation_deg}]}, ...], errors: [{key,message}]}
	 */
	public static function arrange( array $parts, $bed_width_mm, $bed_height_mm, $margin_mm = 5.0, array $rotations = self::DEFAULT_ROTATIONS, array $exclusion_zones = array() ) {
		$bed_width_mm  = (float) $bed_width_mm;
		$bed_height_mm = (float) $bed_height_mm;
		$margin_mm     = max( 0.0, (float) $margin_mm );

		if ( ! $rotations ) {
			$rotations = array( 0 );
		}

		// Wie ein bereits platziertes Bauteil behandelt (siehe
		// candidate_points()/try_place()) - dieselbe Kollisionslogik
		// sorgt dann automatisch dafür, dass kein Bauteil dort landet UND
		// dass Kandidatenpunkte an den Rändern der Sperrfläche entstehen,
		// genau wie neben einem echten Nachbarn.
		$inflated_exclusions = array();
		foreach ( $exclusion_zones as $zone ) {
			$hull = Polygon::convex_hull( is_array( $zone ) ? $zone : array() );
			if ( count( $hull ) < 3 ) {
				continue;
			}
			$inflated_exclusions[] = $margin_mm > 0 ? Polygon::offset( $hull, $margin_mm ) : $hull;
		}

		// Grösste zuerst: ein klassischer, gut belegter Kniff bei
		// First-Fit-artigem Packen – kleine Teile füllen hinterher eher
		// die Lücken zwischen bereits platzierten grossen.
		$normalized = array();
		foreach ( $parts as $part ) {
			$hull = Polygon::convex_hull( $part['hull'] );
			if ( count( $hull ) < 3 ) {
				continue; // Keine Fläche - siehe errors unten.
			}
			$bbox              = Polygon::bounding_box( $hull );
			$normalized[]       = array(
				'key'    => (string) $part['key'],
				// Auf (0,0) normiert: place() setzt ohnehin die Bounding-
				// Box-Ecke an den Zielpunkt, ein fester Bezug erleichtert
				// aber das Nachvollziehen der Zwischenwerte.
				'hull'   => Polygon::translate( $hull, -$bbox['minx'], -$bbox['miny'] ),
				'width'  => $bbox['maxx'] - $bbox['minx'],
				'height' => $bbox['maxy'] - $bbox['miny'],
				'area'   => Polygon::area( $hull ),
				// Kopien eines Artikels haben dieselbe Hülle: Passt eine nicht\n				// mehr auf eine Platte, passt keine weitere (siehe pack()).
				'sig'    => md5( json_encode( $hull ) ),
			);
		}

		// Mehrere feste Reihenfolgen probieren und die beste Anordnung
		// behalten: Welche Reihenfolge gut packt, hängt von den Formen ab
		// (lange Teile zuerst, grosse Fläche zuerst, hohe Teile zuerst).
		// Alle Läufe sind deterministisch, das Ergebnis ist es damit auch.
		$orders = array(
			static function ( $a, $b ) {
				return $b['area'] <=> $a['area'];
			},
			static function ( $a, $b ) {
				return max( $b['width'], $b['height'] ) <=> max( $a['width'], $a['height'] ) ?: $b['area'] <=> $a['area'];
			},
			static function ( $a, $b ) {
				return $b['height'] <=> $a['height'] ?: $b['area'] <=> $a['area'];
			},
			static function ( $a, $b ) {
				return $b['width'] <=> $a['width'] ?: $b['area'] <=> $a['area'];
			},
		);

		$best = null;

		foreach ( $orders as $order ) {
			$sorted = $normalized;
			usort( $sorted, $order );

			$run = self::pack( $sorted, $bed_width_mm, $bed_height_mm, $margin_mm, $rotations, $inflated_exclusions );

			if ( null === $best || self::better( $run, $best ) ) {
				$best = $run;
			}
		}

		$bins   = $best['bins'];
		$errors = $best['errors'];

		$out_bins = array();
		foreach ( $bins as $bin ) {
			$out_bins[] = array( 'placements' => $bin['placements'] );
		}

		return array( 'bins' => $out_bins, 'errors' => $errors );
	}

	/**
	 * Teile in der gegebenen Reihenfolge auf Platten verteilen.
	 *
	 * @param array $sorted               Normierte Teile, in Packreihenfolge.
	 * @param float $bed_width_mm         Bettbreite.
	 * @param float $bed_height_mm        Betttiefe.
	 * @param float $margin_mm            Sicherheitsabstand.
	 * @param array $rotations            Erlaubte Rotationswinkel.
	 * @param array $inflated_exclusions  Aufgeblasene Sperrflächen.
	 * @return array {bins, errors}
	 */
	protected static function pack( array $sorted, $bed_width_mm, $bed_height_mm, $margin_mm, array $rotations, array $inflated_exclusions ) {
		$bins   = array();
		$errors = array();

		foreach ( $sorted as $part ) {
			if ( ! self::fits_any_rotation( $part['hull'], $bed_width_mm, $bed_height_mm, $margin_mm, $rotations ) ) {
				$errors[] = array(
					'key'     => $part['key'],
					'message' => __( 'Das Bauteil ist grösser als das Druckbett (auch gedreht) – Anordnung nicht möglich.', 'lindenweg16' ),
				);
				continue;
			}

			$placed = false;

			foreach ( $bins as &$bin ) {
				if ( isset( $bin['failed'][ $part['sig'] ] ) ) {
					continue;
				}

				$fit = self::try_place( $part, $bin, $bed_width_mm, $bed_height_mm, $margin_mm, $rotations );
				if ( $fit ) {
					$bin['placements'][] = $fit['placement'];
					$bin['occupied'][]   = $fit['inflated_hull'];
					$bin['failed']       = array();
					$placed              = true;
					break;
				}

				$bin['failed'][ $part['sig'] ] = true;
			}
			unset( $bin );

			if ( ! $placed ) {
				$bin = array( 'placements' => array(), 'occupied' => $inflated_exclusions, 'failed' => array() );

				$fit = self::try_place( $part, $bin, $bed_width_mm, $bed_height_mm, $margin_mm, $rotations );

				if ( ! $fit ) {
					// Kann bei einem leeren Bett nur passieren, wenn schon
					// fits_any_rotation() oben falsch läge - als Schutz vor
					// einer Endlosschleife trotzdem klar gemeldet statt
					// stillschweigend übersprungen.
					$errors[] = array(
						'key'     => $part['key'],
						'message' => __( 'Bauteil konnte auf keiner Platte platziert werden.', 'lindenweg16' ),
					);
					continue;
				}

				$bin['placements'][] = $fit['placement'];
				$bin['occupied'][]   = $fit['inflated_hull'];
				$bins[]               = $bin;
			}
		}

		return array( 'bins' => $bins, 'errors' => $errors );
	}

	/**
	 * Ist Lauf $a besser als $b? Weniger nicht platzierte Teile, dann
	 * weniger Platten, dann weniger belegte Höhe auf der letzten Platte
	 * (sie lässt sich dann mit Folgeaufträgen am ehesten auffüllen).
	 *
	 * @param array $a Lauf {bins, errors}.
	 * @param array $b Lauf {bins, errors}.
	 * @return bool
	 */
	protected static function better( array $a, array $b ) {
		if ( count( $a['errors'] ) !== count( $b['errors'] ) ) {
			return count( $a['errors'] ) < count( $b['errors'] );
		}

		if ( count( $a['bins'] ) !== count( $b['bins'] ) ) {
			return count( $a['bins'] ) < count( $b['bins'] );
		}

		return self::last_fill( $a ) < self::last_fill( $b );
	}

	/**
	 * Anzahl Teile auf der letzten Platte (weniger = die vorderen sind voller).
	 *
	 * @param array $run Lauf {bins, errors}.
	 * @return int
	 */
	protected static function last_fill( array $run ) {
		$last = end( $run['bins'] );

		return $last ? count( $last['placements'] ) : 0;
	}

	/**
	 * Passt das Bauteil bei irgendeiner der erlaubten Rotationen überhaupt
	 * auf ein (leeres) Bett dieser Grösse?
	 *
	 * @param array $hull_at_origin Hülle, auf (0,0) normiert.
	 * @param float $bed_width_mm   Bettbreite.
	 * @param float $bed_height_mm  Betttiefe.
	 * @param float $margin_mm      Randabstand.
	 * @param array $rotations      Rotationswinkel.
	 * @return bool
	 */
	protected static function fits_any_rotation( array $hull_at_origin, $bed_width_mm, $bed_height_mm, $margin_mm, array $rotations ) {
		foreach ( $rotations as $deg ) {
			$rotated = Polygon::rotate( $hull_at_origin, $deg );
			$bbox    = Polygon::bounding_box( $rotated );
			$w       = $bbox['maxx'] - $bbox['minx'];
			$h       = $bbox['maxy'] - $bbox['miny'];

			if ( $w + 2 * $margin_mm <= $bed_width_mm + Polygon::EPSILON && $h + 2 * $margin_mm <= $bed_height_mm + Polygon::EPSILON ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Besten Platz für ein Bauteil in einem Bin suchen.
	 *
	 * Alle Kandidatenpunkte und Rotationen werden bewertet, nicht der erste
	 * freie Treffer genommen: Gewählt wird die Lage mit der niedrigsten
	 * Oberkante, dann der geringsten Breite nach rechts. Das hält die Teile
	 * zusammen und lässt rechts oben eine zusammenhängende freie Fläche.
	 *
	 * @param array $part          {key, hull (auf 0,0 normiert), width, height}.
	 * @param array $bin           {placements, occupied} - occupied sind
	 *                              bereits aufgeblasene Hüllen der bisher
	 *                              platzierten Teile.
	 * @param float $bed_width_mm  Bettbreite.
	 * @param float $bed_height_mm Betttiefe.
	 * @param float $margin_mm     Sicherheitsabstand.
	 * @param array $rotations     Erlaubte Rotationswinkel.
	 * @return array|null {placement, inflated_hull}
	 */
	protected static function try_place( array $part, array $bin, $bed_width_mm, $bed_height_mm, $margin_mm, array $rotations ) {
		$boxes = array();
		foreach ( $bin['occupied'] as $inflated ) {
			$boxes[] = Polygon::bounding_box( $inflated );
		}

		// Ausdehnung je Rotation einmal rechnen: place() setzt die Ecke des
		// Begrenzungsrechtecks auf den Kandidatenpunkt, die Box dort ist also
		// Punkt + (Breite, Höhe) – die Hülle selbst wird erst gebraucht, wenn
		// die Box alle Prüfungen bestanden hat.
		$sizes = array();
		foreach ( $rotations as $deg ) {
			$bb            = Polygon::bounding_box( Polygon::rotate( $part['hull'], $deg ) );
			$sizes[ $deg ] = array( $bb['maxx'] - $bb['minx'], $bb['maxy'] - $bb['miny'] );
		}

		$min_height = min( array_column( $sizes, 1 ) );
		$best       = null;

		// Die Punkte sind nach y sortiert: Liegt schon die Unterkante so hoch,
		// dass auch das niedrigste Teil über dem besten Treffer endet, ist
		// kein späterer Punkt mehr besser.
		foreach ( self::candidate_points( $bin, $margin_mm ) as $point ) {
			if ( $best && $point[1] + $min_height > $best['top'] + Polygon::EPSILON ) {
				break;
			}

			foreach ( $rotations as $deg ) {
				$minx = $point[0];
				$miny = $point[1];
				$maxx = $minx + $sizes[ $deg ][0];
				$maxy = $miny + $sizes[ $deg ][1];

				// Rand: sowohl die untere/linke als auch die obere/rechte
				// Kante prüfen (Kandidaten können rechnerisch unter
				// margin_mm liegen, wenn der Nachbar nahe am Rand sitzt).
				if ( $minx < $margin_mm - Polygon::EPSILON || $miny < $margin_mm - Polygon::EPSILON ) {
					continue;
				}
				if ( $maxx > $bed_width_mm - $margin_mm + Polygon::EPSILON || $maxy > $bed_height_mm - $margin_mm + Polygon::EPSILON ) {
					continue;
				}

				// Schlechter als der bisher beste Treffer? Dann gar nicht erst prüfen.
				if ( $best && ( $maxy > $best['top'] + Polygon::EPSILON || ( abs( $maxy - $best['top'] ) <= Polygon::EPSILON && $maxx >= $best['right'] - Polygon::EPSILON ) ) ) {
					continue;
				}

				$placed_hull = null;
				$collides    = false;

				foreach ( $bin['occupied'] as $index => $inflated ) {
					$box = $boxes[ $index ];

					// Begrenzungsrechtecke ohne Überlappung: kann nicht kollidieren.
					if ( $maxx <= $box['minx'] || $minx >= $box['maxx'] || $maxy <= $box['miny'] || $miny >= $box['maxy'] ) {
						continue;
					}

					if ( null === $placed_hull ) {
						$placed_hull = Polygon::place( $part['hull'], $deg, $point[0], $point[1] );
					}

					if ( Sat::overlap( $placed_hull, $inflated ) ) {
						$collides = true;
						break;
					}
				}

				if ( $collides ) {
					continue;
				}

				if ( null === $placed_hull ) {
					$placed_hull = Polygon::place( $part['hull'], $deg, $point[0], $point[1] );
				}

				$best = array(
					'top'           => $maxy,
					'right'         => $maxx,
					'placement'     => array(
						'key'          => $part['key'],
						'x'            => round( $point[0], 3 ),
						'y'            => round( $point[1], 3 ),
						'rotation_deg' => $deg,
					),
					'inflated_hull' => $margin_mm > 0 ? Polygon::offset( $placed_hull, $margin_mm ) : $placed_hull,
				);
			}
		}

		return $best ? array( 'placement' => $best['placement'], 'inflated_hull' => $best['inflated_hull'] ) : null;
	}

	/**
	 * Kandidaten-Ankerpunkte (linke-untere Ecke der Bounding Box) für die
	 * nächste Platzierung: Alle Kombinationen aus den x-Kanten und den
	 * y-Kanten der belegten Flächen (plus Bettecke). Das deckt auch Nischen
	 * ab, die nur an den Kanten zweier verschiedener Teile entstehen (etwa
	 * rechts neben dem einen und über dem anderen), und die der alte
	 * Kandidatensatz nie versucht hat.
	 *
	 * @param array $bin       {placements, occupied}.
	 * @param float $margin_mm Randabstand.
	 * @return array Liste [x, y], sortiert nach y, dann x.
	 */
	protected static function candidate_points( array $bin, $margin_mm ) {
		$xs = array( round( $margin_mm, 3 ) => $margin_mm );
		$ys = array( round( $margin_mm, 3 ) => $margin_mm );

		foreach ( $bin['occupied'] as $hull ) {
			$bbox = Polygon::bounding_box( $hull );

			foreach ( array( $bbox['maxx'] + self::NUDGE_MM, $bbox['minx'] ) as $x ) {
				$xs[ (string) round( $x, 3 ) ] = $x;
			}
			foreach ( array( $bbox['maxy'] + self::NUDGE_MM, $bbox['miny'] ) as $y ) {
				$ys[ (string) round( $y, 3 ) ] = $y;
			}
		}

		$xs = array_values( $xs );
		$ys = array_values( $ys );
		sort( $xs );
		sort( $ys );

		$points = array();
		foreach ( $ys as $y ) {
			foreach ( $xs as $x ) {
				$points[] = array( $x, $y );
			}
		}

		return $points;
	}
}
