<?php
/**
 * Maße eines glTF-Binärmodells (.glb).
 *
 * Die Höhe, Breite und Tiefe eines Moduls stehen schon in seinem Modell.
 * Wer sie zusätzlich von Hand einträgt, pflegt dieselbe Zahl an zwei
 * Stellen. Gelesen wird deshalb die umschließende Box, und zwar aus den
 * `min`/`max`-Angaben der Positions-Accessoren – die verlangt die glTF-
 * Spezifikation für jedes Positionsattribut, die Geometrie selbst muss
 * dafür nicht gelesen werden. Knoten-Transformationen (Verschieben,
 * Drehen, Skalieren) werden angewendet.
 *
 * Achsen nach glTF: Y zeigt nach oben (Höhe bzw. Länge der Schiene), X ist
 * die Breite, Z die Tiefe von der Wand weg.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

/**
 * Liest die Abmessungen eines Modells.
 */
class GlbMeasure {

	/**
	 * Ab dieser Ausdehnung gilt eine Datei als in Millimetern gespeichert.
	 *
	 * glTF rechnet in Metern, viele CAD-Exporte schreiben aber Millimeter
	 * ohne Umrechnung hinein. Ein Modul, das größer als zwanzig Meter
	 * wäre, gibt es nicht – dann sind die Zahlen Millimeter.
	 */
	const MM_THRESHOLD = 20.0;

	/**
	 * Die Maße einer Datei.
	 *
	 * @param string     $path     Pfad zur .glb.
	 * @param array|null $rotation Drehung [x, y, z] in Grad, die vor dem Messen gilt
	 *                             (ein liegend konstruiertes Teil wird aufgestellt).
	 * @return array|null {width, height, depth} in Millimetern, auf 0,1 gerundet;
	 *                    null, wenn die Datei nicht lesbar ist oder keine Maße hergibt.
	 */
	public static function measure_file( $path, $rotation = null ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return null;
		}

		$head = fread( $handle, 20 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$json = '';

		if ( is_string( $head ) && 20 === strlen( $head ) ) {
			$info = unpack( 'a4magic/Vversion/Vlength/Vchunk/Vtype', $head );

			// Das JSON steht im ersten Block. Mehr als ein paar Megabyte
			// sind kein Schaumodell, daher die Obergrenze.
			if ( 'glTF' === $info['magic'] && 0x4E4F534A === $info['type'] && $info['chunk'] > 0 && $info['chunk'] < 8388608 ) {
				$json = (string) fread( $handle, $info['chunk'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return self::measure_json( $json, $rotation );
	}

	/**
	 * Die Maße aus dem JSON-Teil eines glTF.
	 *
	 * @param string     $json     Text.
	 * @param array|null $rotation Drehung [x, y, z] in Grad.
	 * @return array|null
	 */
	public static function measure_json( $json, $rotation = null ) {
		$gltf = json_decode( (string) $json, true );

		if ( ! is_array( $gltf ) || empty( $gltf['nodes'] ) || empty( $gltf['meshes'] ) || empty( $gltf['accessors'] ) ) {
			return null;
		}

		$roots = array();
		$scene = isset( $gltf['scenes'][ isset( $gltf['scene'] ) ? (int) $gltf['scene'] : 0 ]['nodes'] ) ? $gltf['scenes'][ isset( $gltf['scene'] ) ? (int) $gltf['scene'] : 0 ]['nodes'] : null;

		if ( is_array( $scene ) ) {
			$roots = $scene;
		} else {
			// Ohne Szene gelten alle Knoten, die kein anderer als Kind hat.
			$children = array();
			foreach ( $gltf['nodes'] as $node ) {
				foreach ( isset( $node['children'] ) ? (array) $node['children'] : array() as $child ) {
					$children[ (int) $child ] = true;
				}
			}
			foreach ( array_keys( $gltf['nodes'] ) as $index ) {
				if ( ! isset( $children[ $index ] ) ) {
					$roots[] = $index;
				}
			}
		}

		$box = array(
			'min' => array( INF, INF, INF ),
			'max' => array( -INF, -INF, -INF ),
		);

		$start = is_array( $rotation ) && array_filter( $rotation ) ? ModelPlacement::rotation_matrix( $rotation ) : self::identity();

		foreach ( $roots as $root ) {
			self::walk( $gltf, (int) $root, $start, $box, 0 );
		}

		if ( INF === $box['min'][0] ) {
			return null;
		}

		$extent = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$extent[ $i ] = $box['max'][ $i ] - $box['min'][ $i ];
		}

		$scale = max( $extent ) > self::MM_THRESHOLD ? 1.0 : 1000.0;

		return array(
			'width'  => round( $extent[0] * $scale, 1 ),
			'height' => round( $extent[1] * $scale, 1 ),
			'depth'  => round( $extent[2] * $scale, 1 ),
		);
	}

	/**
	 * Einen Knoten samt Kindern ablaufen und die Box erweitern.
	 *
	 * @param array $gltf   Dokument.
	 * @param int   $index  Knoten.
	 * @param array $parent Matrix des Elternknotens (spaltenweise, 16 Werte).
	 * @param array $box    Umschließende Box (wird erweitert).
	 * @param int   $depth  Schachtelungstiefe als Schutz vor Schleifen.
	 */
	protected static function walk( array $gltf, $index, array $parent, array &$box, $depth ) {
		if ( $depth > 32 || ! isset( $gltf['nodes'][ $index ] ) ) {
			return;
		}

		$node  = $gltf['nodes'][ $index ];
		$world = self::multiply( $parent, self::local( $node ) );

		if ( isset( $node['mesh'], $gltf['meshes'][ (int) $node['mesh'] ] ) ) {
			foreach ( (array) ( isset( $gltf['meshes'][ (int) $node['mesh'] ]['primitives'] ) ? $gltf['meshes'][ (int) $node['mesh'] ]['primitives'] : array() ) as $primitive ) {
				$accessor = isset( $primitive['attributes']['POSITION'] ) ? (int) $primitive['attributes']['POSITION'] : -1;
				$min      = isset( $gltf['accessors'][ $accessor ]['min'] ) ? $gltf['accessors'][ $accessor ]['min'] : null;
				$max      = isset( $gltf['accessors'][ $accessor ]['max'] ) ? $gltf['accessors'][ $accessor ]['max'] : null;

				if ( ! is_array( $min ) || ! is_array( $max ) || count( $min ) < 3 || count( $max ) < 3 ) {
					continue;
				}

				for ( $corner = 0; $corner < 8; $corner++ ) {
					$point = array(
						( $corner & 1 ) ? (float) $max[0] : (float) $min[0],
						( $corner & 2 ) ? (float) $max[1] : (float) $min[1],
						( $corner & 4 ) ? (float) $max[2] : (float) $min[2],
					);
					$out   = self::apply( $world, $point );

					for ( $i = 0; $i < 3; $i++ ) {
						$box['min'][ $i ] = min( $box['min'][ $i ], $out[ $i ] );
						$box['max'][ $i ] = max( $box['max'][ $i ], $out[ $i ] );
					}
				}
			}
		}

		foreach ( isset( $node['children'] ) ? (array) $node['children'] : array() as $child ) {
			self::walk( $gltf, (int) $child, $world, $box, $depth + 1 );
		}
	}

	/**
	 * Einheitsmatrix.
	 *
	 * @return array
	 */
	protected static function identity() {
		return array( 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0 );
	}

	/**
	 * Die eigene Matrix eines Knotens: `matrix` oder Verschiebung, Drehung, Skalierung.
	 *
	 * @param array $node Knoten.
	 * @return array
	 */
	protected static function local( array $node ) {
		if ( isset( $node['matrix'] ) && is_array( $node['matrix'] ) && 16 === count( $node['matrix'] ) ) {
			return array_map( 'floatval', array_values( $node['matrix'] ) );
		}

		$t = isset( $node['translation'] ) && 3 === count( (array) $node['translation'] ) ? array_map( 'floatval', array_values( $node['translation'] ) ) : array( 0.0, 0.0, 0.0 );
		$r = isset( $node['rotation'] ) && 4 === count( (array) $node['rotation'] ) ? array_map( 'floatval', array_values( $node['rotation'] ) ) : array( 0.0, 0.0, 0.0, 1.0 );
		$s = isset( $node['scale'] ) && 3 === count( (array) $node['scale'] ) ? array_map( 'floatval', array_values( $node['scale'] ) ) : array( 1.0, 1.0, 1.0 );

		list( $x, $y, $z, $w ) = $r;

		$length = sqrt( $x * $x + $y * $y + $z * $z + $w * $w );
		if ( $length > 0 ) {
			$x /= $length;
			$y /= $length;
			$z /= $length;
			$w /= $length;
		}

		// Spaltenweise: erste drei Werte sind die X-Achse usw.
		return array(
			( 1 - 2 * ( $y * $y + $z * $z ) ) * $s[0],
			( 2 * ( $x * $y + $z * $w ) ) * $s[0],
			( 2 * ( $x * $z - $y * $w ) ) * $s[0],
			0.0,
			( 2 * ( $x * $y - $z * $w ) ) * $s[1],
			( 1 - 2 * ( $x * $x + $z * $z ) ) * $s[1],
			( 2 * ( $y * $z + $x * $w ) ) * $s[1],
			0.0,
			( 2 * ( $x * $z + $y * $w ) ) * $s[2],
			( 2 * ( $y * $z - $x * $w ) ) * $s[2],
			( 1 - 2 * ( $x * $x + $y * $y ) ) * $s[2],
			0.0,
			$t[0],
			$t[1],
			$t[2],
			1.0,
		);
	}

	/**
	 * Zwei spaltenweise gespeicherte 4×4-Matrizen multiplizieren (a · b).
	 *
	 * @param array $a Links.
	 * @param array $b Rechts.
	 * @return array
	 */
	protected static function multiply( array $a, array $b ) {
		$out = array();

		for ( $col = 0; $col < 4; $col++ ) {
			for ( $row = 0; $row < 4; $row++ ) {
				$sum = 0.0;
				for ( $k = 0; $k < 4; $k++ ) {
					$sum += $a[ $k * 4 + $row ] * $b[ $col * 4 + $k ];
				}
				$out[ $col * 4 + $row ] = $sum;
			}
		}

		return $out;
	}

	/**
	 * Einen Punkt mit einer Matrix umrechnen.
	 *
	 * @param array $m Matrix.
	 * @param array $p Punkt (x, y, z).
	 * @return array
	 */
	protected static function apply( array $m, array $p ) {
		return array(
			$m[0] * $p[0] + $m[4] * $p[1] + $m[8] * $p[2] + $m[12],
			$m[1] * $p[0] + $m[5] * $p[1] + $m[9] * $p[2] + $m[13],
			$m[2] * $p[0] + $m[6] * $p[1] + $m[10] * $p[2] + $m[14],
		);
	}
}
