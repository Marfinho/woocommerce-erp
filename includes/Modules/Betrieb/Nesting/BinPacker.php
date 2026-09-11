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
			);
		}

		usort(
			$normalized,
			static function ( $a, $b ) {
				return $b['area'] <=> $a['area'];
			}
		);

		$bins   = array();
		$errors = array();

		foreach ( $normalized as $part ) {
			if ( ! self::fits_any_rotation( $part['hull'], $bed_width_mm, $bed_height_mm, $margin_mm, $rotations ) ) {
				$errors[] = array(
					'key'     => $part['key'],
					'message' => __( 'Das Bauteil ist grösser als das Druckbett (auch gedreht) – Anordnung nicht möglich.', 'lindenweg16' ),
				);
				continue;
			}

			$placed = false;

			foreach ( $bins as &$bin ) {
				$fit = self::try_place( $part, $bin, $bed_width_mm, $bed_height_mm, $margin_mm, $rotations );
				if ( $fit ) {
					$bin['placements'][] = $fit['placement'];
					$bin['occupied'][]   = $fit['inflated_hull'];
					$placed              = true;
					break;
				}
			}
			unset( $bin );

			if ( ! $placed ) {
				$bin = array( 'placements' => array(), 'occupied' => $inflated_exclusions );

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

		$out_bins = array();
		foreach ( $bins as $bin ) {
			$out_bins[] = array( 'placements' => $bin['placements'] );
		}

		return array( 'bins' => $out_bins, 'errors' => $errors );
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
	 * Versucht, ein Bauteil in einem bestehenden (ggf. leeren) Bin zu
	 * platzieren. Liefert bei Erfolg Platzierung + die um den
	 * Sicherheitsabstand aufgeblasene Hülle (für künftige
	 * Kollisionsprüfungen), sonst null.
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
		$candidates = self::candidate_points( $bin, $margin_mm );

		foreach ( $candidates as $point ) {
			foreach ( $rotations as $deg ) {
				$placed_hull = Polygon::place( $part['hull'], $deg, $point[0], $point[1] );
				$bbox        = Polygon::bounding_box( $placed_hull );

				// Sowohl die untere/linke als auch die obere/rechte Kante
				// prüfen: Kandidatenpunkte, die vom aufgeblasenen Nachbarn
				// abgeleitet sind (siehe candidate_points()), können
				// rechnerisch unterhalb von margin_mm liegen, wenn der
				// Nachbar selbst nahe am Bettrand sitzt - ohne diese Prüfung
				// würde ein Bauteil dann den Randabstand unterschreiten,
				// statt (korrekt) diesen Kandidaten zu verwerfen.
				if ( $bbox['minx'] < $margin_mm - Polygon::EPSILON || $bbox['miny'] < $margin_mm - Polygon::EPSILON ) {
					continue;
				}

				if ( $bbox['maxx'] > $bed_width_mm - $margin_mm + Polygon::EPSILON ) {
					continue;
				}
				if ( $bbox['maxy'] > $bed_height_mm - $margin_mm + Polygon::EPSILON ) {
					continue;
				}

				$collides = false;
				foreach ( $bin['occupied'] as $inflated ) {
					if ( Sat::overlap( $placed_hull, $inflated ) ) {
						$collides = true;
						break;
					}
				}

				if ( $collides ) {
					continue;
				}

				return array(
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

		return null;
	}

	/**
	 * Kandidaten-Ankerpunkte (linke-untere Ecke der Bounding Box) für die
	 * nächste Platzierung: die Bett-Ecke plus, für jedes bereits platzierte
	 * Teil, die Punkte rechts und oberhalb seiner Bounding Box – das
	 * klassische Bottom-Left-Fill-Kandidatenset. Sortiert nach (y, x)
	 * aufsteigend, damit die unterste, dann linkeste freie Stelle zuerst
	 * versucht wird.
	 *
	 * @param array $bin       {placements, occupied}.
	 * @param float $margin_mm Randabstand.
	 * @return array Liste [x, y], sortiert.
	 */
	protected static function candidate_points( array $bin, $margin_mm ) {
		$points = array( array( $margin_mm, $margin_mm ) );

		foreach ( $bin['occupied'] as $hull ) {
			$bbox     = Polygon::bounding_box( $hull );
			$points[] = array( $bbox['maxx'] + self::NUDGE_MM, $margin_mm );
			$points[] = array( $margin_mm, $bbox['maxy'] + self::NUDGE_MM );
			$points[] = array( $bbox['maxx'] + self::NUDGE_MM, $bbox['miny'] );
			$points[] = array( $bbox['minx'], $bbox['maxy'] + self::NUDGE_MM );
		}

		usort(
			$points,
			static function ( $a, $b ) {
				if ( abs( $a[1] - $b[1] ) > Polygon::EPSILON ) {
					return $a[1] <=> $b[1];
				}
				return $a[0] <=> $b[0];
			}
		);

		return $points;
	}
}
