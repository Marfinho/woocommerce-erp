<?php
/**
 * Umriss eines Bauteils in der Draufsicht als Raster, samt Einbuchtungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ThreeMf;

/**
 * Die konvexe Hülle (MeshOutline) füllt Einbuchtungen auf: Ein C-förmiger
 * Klemmring belegt in ihr eine ganze Halbscheibe, und kein anderes Teil
 * kann in seine Öffnung. Die Silhouette hier ist das echte Schattenbild:
 * alle Dreiecke des Bauteils, von oben gesehen, auf ein feines Raster
 * (0,5 mm) gebracht. Daraus rechnet RasterPacker seine gröberen Masken je
 * Drehwinkel.
 *
 * Das Bauteil wird so gelesen, wie PlateComposer es übernimmt: das erste
 * <object>, mit eigenem <mesh> oder als flach gerechnete <components>.
 * Stimmen Silhouette und eingesetzte Geometrie nicht überein, läge die
 * Anordnung daneben.
 *
 * Aufbau des Ergebnisses (Koordinaten in mm, Ursprung = Ecke des
 * Begrenzungsrechtecks der Eckpunkte):
 * - f:      Rasterweite in mm.
 * - w, h:   Spalten und Zeilen.
 * - runs:   je Zeile eine Liste [von, bis] belegter Zellen (inklusive).
 * - hull:   konvexe Hülle der Eckpunkte, derselbe Ursprung.
 * - width_mm, height_mm: Ausdehnung der Eckpunkte.
 */
class Silhouette {

	/**
	 * Rasterweite des feinen Umrisses in mm.
	 */
	const FINE_MM = 0.5;

	/**
	 * Silhouette einer 3MF-Projektdatei, mit Zwischenspeicher.
	 *
	 * Das Lesen großer Netze dauert Sekunden; der Umriss ändert sich nur,
	 * wenn die Datei ersetzt wird, deshalb hängt der Schlüssel an Größe und
	 * Änderungszeit.
	 *
	 * @param string $zip_path Absoluter Pfad der 3MF.
	 * @return array|null Silhouette oder null, wenn keine Dreiecke lesbar sind.
	 */
	public static function for_file( $zip_path ) {
		if ( ! is_file( $zip_path ) ) {
			return null;
		}

		$key = 'lw16_sil_' . md5( $zip_path . '|' . filesize( $zip_path ) . '|' . filemtime( $zip_path ) . '|' . self::FINE_MM );

		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $key );

			if ( is_array( $cached ) && isset( $cached['runs'], $cached['hull'] ) ) {
				return $cached;
			}
		}

		$sil = self::from_file( $zip_path );

		if ( $sil && function_exists( 'set_transient' ) ) {
			set_transient( $key, $sil, 30 * 86400 );
		}

		return $sil;
	}

	/**
	 * Silhouette einer 3MF-Datei berechnen (ohne Zwischenspeicher).
	 *
	 * @param string $zip_path Absoluter Pfad der 3MF.
	 * @return array|null
	 */
	public static function from_file( $zip_path ) {
		$mesh = self::mesh_of_file( $zip_path );

		if ( ! $mesh ) {
			return null;
		}

		return self::from_mesh( $mesh[0], $mesh[1] );
	}

	/**
	 * Silhouette aus Eckpunkten [[x,y,z]] und Dreiecken [[a,b,c]].
	 *
	 * @param array $vertices  Eckpunkte.
	 * @param array $triangles Dreiecke (Indizes).
	 * @return array|null
	 */
	public static function from_mesh( array $vertices, array $triangles ) {
		if ( ! $vertices || ! $triangles ) {
			return null;
		}

		$minx = INF;
		$miny = INF;
		$maxx = -INF;
		$maxy = -INF;

		foreach ( $vertices as $v ) {
			$minx = min( $minx, $v[0] );
			$miny = min( $miny, $v[1] );
			$maxx = max( $maxx, $v[0] );
			$maxy = max( $maxy, $v[1] );
		}

		$width  = $maxx - $minx;
		$height = $maxy - $miny;

		if ( $width <= 0 || $height <= 0 ) {
			return null;
		}

		$f     = self::FINE_MM;
		$cols  = (int) ceil( $width / $f ) + 1;
		$rows  = (int) ceil( $height / $f ) + 1;
		$diff  = array();
		$pts   = array();
		$count = count( $vertices );

		foreach ( $vertices as $v ) {
			$pts[] = array( $v[0] - $minx, $v[1] - $miny );
		}

		foreach ( $triangles as $t ) {
			if ( $t[0] >= $count || $t[1] >= $count || $t[2] >= $count || $t[0] < 0 || $t[1] < 0 || $t[2] < 0 ) {
				continue;
			}

			self::raster_triangle( $pts[ $t[0] ], $pts[ $t[1] ], $pts[ $t[2] ], $f, $rows, $cols, $diff );
		}

		$runs = self::runs_from_diff( $diff, $rows, $cols );

		return array(
			'f'         => $f,
			'w'         => $cols,
			'h'         => $rows,
			'runs'      => $runs,
			'hull'      => Polygon::convex_hull( $pts ),
			'width_mm'  => $width,
			'height_mm' => $height,
		);
	}

	/**
	 * Silhouette aus einem konvexen Polygon (Ersatz, wenn keine Dreiecke zur
	 * Verfügung stehen; füllt Einbuchtungen, wie die Hülle es tut).
	 *
	 * @param array $hull Konvexe Hülle [[x,y], ...].
	 * @return array|null
	 */
	public static function from_hull( array $hull ) {
		$hull = Polygon::convex_hull( $hull );

		if ( count( $hull ) < 3 ) {
			return null;
		}

		$bbox = Polygon::bounding_box( $hull );
		$hull = Polygon::translate( $hull, -$bbox['minx'], -$bbox['miny'] );
		$w    = $bbox['maxx'] - $bbox['minx'];
		$h    = $bbox['maxy'] - $bbox['miny'];

		if ( $w <= 0 || $h <= 0 ) {
			return null;
		}

		$n     = count( $hull );
		$mesh  = array();
		$tris  = array();

		foreach ( $hull as $p ) {
			$mesh[] = array( $p[0], $p[1], 0.0 );
		}

		// Fächer aus der ersten Ecke: für ein konvexes Polygon exakt.
		for ( $i = 1; $i < $n - 1; $i++ ) {
			$tris[] = array( 0, $i, $i + 1 );
		}

		return self::from_mesh( $mesh, $tris );
	}

	/**
	 * Umriss als SVG-Pfad (mm, Ursprung Ecke des Begrenzungsrechtecks, y nach
	 * oben). Senkrecht untereinander gleiche Zeilen sind zu einem Rechteck
	 * zusammengefasst, damit der Pfad klein bleibt.
	 *
	 * @param array $sil Silhouette.
	 * @return string
	 */
	public static function path_d( array $sil ) {
		$f     = $sil['f'];
		$d     = '';
		$open  = array();
		$rows  = $sil['runs'];
		$count = count( $rows );

		for ( $r = 0; $r <= $count; $r++ ) {
			$now  = $r < $count ? $rows[ $r ] : array();
			$next = array();

			foreach ( $now as $run ) {
				$key = $run[0] . ':' . $run[1];

				if ( isset( $open[ $key ] ) ) {
					$next[ $key ] = $open[ $key ];
					unset( $open[ $key ] );
				} else {
					$next[ $key ] = $r;
				}
			}

			// Was in dieser Zeile nicht mehr weiterläuft, wird als Rechteck ausgegeben.
			foreach ( $open as $key => $from ) {
				list( $a, $b ) = array_map( 'intval', explode( ':', $key ) );
				$d .= sprintf( 'M%s %sH%sV%sH%sZ', round( $a * $f, 2 ), round( $from * $f, 2 ), round( ( $b + 1 ) * $f, 2 ), round( $r * $f, 2 ), round( $a * $f, 2 ) );
			}

			$open = $next;
		}

		return $d;
	}

	/**
	 * Ein Dreieck (Draufsicht) auf das Raster bringen: Je Rasterzeile wird
	 * der Streifen [y, y+f] mit dem Dreieck geschnitten und die überdeckte
	 * x-Spanne als Differenz eingetragen. Konservativ: Auch nur angeschnittene
	 * Zellen zählen, und senkrechte Wände (in der Draufsicht eine Linie)
	 * liefern ihre Randzellen.
	 *
	 * @param array $a    Punkt [x,y].
	 * @param array $b    Punkt [x,y].
	 * @param array $c    Punkt [x,y].
	 * @param float $f    Rasterweite.
	 * @param int   $rows Zeilenzahl.
	 * @param int   $cols Spaltenzahl.
	 * @param array $diff Differenzfelder je Zeile (wird ergänzt).
	 */
	protected static function raster_triangle( array $a, array $b, array $c, $f, $rows, $cols, array &$diff ) {
		$ymin = min( $a[1], $b[1], $c[1] );
		$ymax = max( $a[1], $b[1], $c[1] );
		$r0   = max( 0, (int) floor( $ymin / $f ) );
		$r1   = min( $rows - 1, (int) floor( $ymax / $f ) );
		$edges = array( array( $a, $b ), array( $b, $c ), array( $c, $a ) );

		for ( $r = $r0; $r <= $r1; $r++ ) {
			$y0  = $r * $f;
			$y1  = $y0 + $f;
			$lo  = INF;
			$hi  = -INF;

			foreach ( $edges as $e ) {
				$p = $e[0];
				$q = $e[1];

				// Strecke auf den Streifen zuschneiden.
				$ty0 = 0.0;
				$ty1 = 1.0;
				$dy  = $q[1] - $p[1];

				if ( abs( $dy ) < 1e-12 ) {
					if ( $p[1] < $y0 || $p[1] > $y1 ) {
						continue;
					}
				} else {
					$ta = ( $y0 - $p[1] ) / $dy;
					$tb = ( $y1 - $p[1] ) / $dy;
					if ( $ta > $tb ) {
						$tmp = $ta;
						$ta  = $tb;
						$tb  = $tmp;
					}
					$ty0 = max( $ty0, $ta );
					$ty1 = min( $ty1, $tb );
					if ( $ty0 > $ty1 ) {
						continue;
					}
				}

				$dx = $q[0] - $p[0];
				$xa = $p[0] + $dx * $ty0;
				$xb = $p[0] + $dx * $ty1;
				$lo = min( $lo, $xa, $xb );
				$hi = max( $hi, $xa, $xb );
			}

			if ( $lo > $hi ) {
				// Streifen schneidet nur die Fläche, keine Kante (Dreieck deckt den ganzen Streifen).
				// Dann liegen die Schnittpunkte der Kanten trotzdem auf dem Streifenrand – nur bei
				// extrem schmalen Streifen möglich; sicherheitshalber die Ausdehnung des Dreiecks nehmen.
				$lo = min( $a[0], $b[0], $c[0] );
				$hi = max( $a[0], $b[0], $c[0] );
			}

			$ca = max( 0, (int) floor( $lo / $f ) );
			$cb = min( $cols - 1, (int) floor( $hi / $f ) );

			if ( $cb < $ca ) {
				continue;
			}

			if ( ! isset( $diff[ $r ] ) ) {
				$diff[ $r ] = array();
			}

			$diff[ $r ][ $ca ] = ( isset( $diff[ $r ][ $ca ] ) ? $diff[ $r ][ $ca ] : 0 ) + 1;
			$diff[ $r ][ $cb + 1 ] = ( isset( $diff[ $r ][ $cb + 1 ] ) ? $diff[ $r ][ $cb + 1 ] : 0 ) - 1;
		}
	}

	/**
	 * Differenzfelder in belegte Spannen je Zeile umrechnen.
	 *
	 * @param array $diff Differenzen je Zeile (Spalte => Änderung).
	 * @param int   $rows Zeilenzahl.
	 * @param int   $cols Spaltenzahl.
	 * @return array Zeile => Liste [von, bis].
	 */
	protected static function runs_from_diff( array $diff, $rows, $cols ) {
		$runs = array();

		for ( $r = 0; $r < $rows; $r++ ) {
			$row = array();

			if ( isset( $diff[ $r ] ) ) {
				ksort( $diff[ $r ] );
				$sum   = 0;
				$start = -1;

				foreach ( $diff[ $r ] as $col => $delta ) {
					$before = $sum;
					$sum   += $delta;

					if ( $before <= 0 && $sum > 0 ) {
						$start = $col;
					} elseif ( $before > 0 && $sum <= 0 && $start >= 0 ) {
						$row[] = array( $start, min( $cols - 1, $col - 1 ) );
						$start = -1;
					}
				}
			}

			$runs[] = $row;
		}

		return $runs;
	}

	/**
	 * Eckpunkte und Dreiecke des ersten Objekts einer 3MF – so, wie
	 * PlateComposer es übernimmt (eigenes Mesh, sonst die Komponenten eine
	 * Ebene tief mit ihrer Transformation).
	 *
	 * @param string $zip_path Pfad der 3MF.
	 * @return array|null [vertices, triangles] oder null.
	 */
	protected static function mesh_of_file( $zip_path ) {
		if ( ! class_exists( '\ZipArchive' ) || ! is_file( $zip_path ) ) {
			return null;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return null;
		}

		$xml = $zip->getFromName( ThreeMf::MODEL_PATH );
		$doc = is_string( $xml ) ? ThreeMf::document( $xml ) : null;

		if ( ! $doc ) {
			$zip->close();
			return null;
		}

		$object = $doc->getElementsByTagName( 'object' )->item( 0 );

		if ( ! $object ) {
			$zip->close();
			return null;
		}

		$result = null;
		$mesh   = self::child( $object, 'mesh' );

		if ( $mesh ) {
			$result = self::mesh_arrays( $mesh );
		} else {
			$components = self::child( $object, 'components' );
			$vertices   = array();
			$triangles  = array();

			if ( $components ) {
				foreach ( $components->childNodes as $component ) {
					if ( ! ( $component instanceof \DOMElement ) || 'component' !== $component->localName ) {
						continue;
					}

					$object_id = $component->getAttribute( 'objectid' );
					if ( '' === $object_id ) {
						continue;
					}

					$path    = $component->getAttributeNS( MeshOutline::PRODUCTION_NS, 'path' );
					$sub_doc = $doc;

					if ( '' !== $path ) {
						$sub_xml = $zip->getFromName( ltrim( $path, '/' ) );
						$sub_doc = is_string( $sub_xml ) ? ThreeMf::document( $sub_xml ) : null;
					}

					if ( ! $sub_doc ) {
						continue;
					}

					$referenced = null;
					foreach ( $sub_doc->getElementsByTagName( 'object' ) as $candidate ) {
						if ( $candidate->getAttribute( 'id' ) === (string) $object_id ) {
							$referenced = $candidate;
							break;
						}
					}

					if ( ! $referenced || self::child( $referenced, 'components' ) ) {
						continue;
					}

					$sub_mesh = self::child( $referenced, 'mesh' );
					if ( ! $sub_mesh ) {
						continue;
					}

					list( $sub_vertices, $sub_triangles ) = self::mesh_arrays( $sub_mesh );
					$sub_vertices = MeshOutline::apply_transform( $sub_vertices, $component->getAttribute( 'transform' ) );
					$offset       = count( $vertices );

					foreach ( $sub_vertices as $v ) {
						$vertices[] = $v;
					}
					foreach ( $sub_triangles as $t ) {
						$triangles[] = array( $t[0] + $offset, $t[1] + $offset, $t[2] + $offset );
					}
				}
			}

			if ( $vertices && $triangles ) {
				$result = array( $vertices, $triangles );
			}
		}

		$zip->close();

		return $result && $result[0] && $result[1] ? $result : null;
	}

	/**
	 * Erstes Kindelement mit diesem lokalen Namen.
	 *
	 * @param \DOMElement $parent Eltern.
	 * @param string      $name   Name.
	 * @return \DOMElement|null
	 */
	protected static function child( \DOMElement $parent, $name ) {
		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof \DOMElement && $name === $child->localName ) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * Eckpunkt- und Dreiecksliste eines <mesh>.
	 *
	 * @param \DOMElement $mesh Mesh.
	 * @return array [vertices, triangles]
	 */
	protected static function mesh_arrays( \DOMElement $mesh ) {
		$vertices  = array();
		$triangles = array();
		$v_el      = self::child( $mesh, 'vertices' );
		$t_el      = self::child( $mesh, 'triangles' );

		if ( $v_el ) {
			foreach ( $v_el->childNodes as $vertex ) {
				if ( $vertex instanceof \DOMElement && 'vertex' === $vertex->localName ) {
					$vertices[] = array( (float) $vertex->getAttribute( 'x' ), (float) $vertex->getAttribute( 'y' ), (float) $vertex->getAttribute( 'z' ) );
				}
			}
		}

		if ( $t_el ) {
			foreach ( $t_el->childNodes as $triangle ) {
				if ( $triangle instanceof \DOMElement && 'triangle' === $triangle->localName ) {
					$triangles[] = array( (int) $triangle->getAttribute( 'v1' ), (int) $triangle->getAttribute( 'v2' ), (int) $triangle->getAttribute( 'v3' ) );
				}
			}
		}

		return array( $vertices, $triangles );
	}
}
