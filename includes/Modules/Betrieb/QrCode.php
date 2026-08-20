<?php
/**
 * QR-Code-Erzeugung ohne externe Abhängigkeit.
 *
 * Byte-Modus, Versionen 1 bis 12, alle vier Fehlerkorrekturstufen. Das reicht
 * für Adressen und Codes bis rund 300 Zeichen; darüber hinaus wird bewusst
 * abgebrochen statt geraten.
 *
 * Zur Verlässlichkeit: Der erzeugte Modulraster ist gegen eine
 * Referenzimplementierung geprüft (tests/BetriebQrCodeTest.php vergleicht mit
 * fest hinterlegten Rasterprüfsummen, die aus einer geprüften Bibliothek
 * stammen). Ein QR-Code, den der Scanner nicht liest, ist schlimmer als
 * keiner – deshalb der Aufwand.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Kodiert Text als QR-Modulraster und gibt ihn als SVG aus.
 */
class QrCode {

	/**
	 * Höchste unterstützte Version.
	 */
	const MAX_VERSION = 12;

	// EC-Blockaufbau je Version und Fehlerkorrekturstufe: Blöcke, Gesamt, Daten.
	const EC_BLOCKS = array(
		1  => array( 'L' => array( array( 1, 26, 19 ) ), 'M' => array( array( 1, 26, 16 ) ), 'Q' => array( array( 1, 26, 13 ) ), 'H' => array( array( 1, 26, 9 ) ) ),
		2  => array( 'L' => array( array( 1, 44, 34 ) ), 'M' => array( array( 1, 44, 28 ) ), 'Q' => array( array( 1, 44, 22 ) ), 'H' => array( array( 1, 44, 16 ) ) ),
		3  => array( 'L' => array( array( 1, 70, 55 ) ), 'M' => array( array( 1, 70, 44 ) ), 'Q' => array( array( 2, 35, 17 ) ), 'H' => array( array( 2, 35, 13 ) ) ),
		4  => array( 'L' => array( array( 1, 100, 80 ) ), 'M' => array( array( 2, 50, 32 ) ), 'Q' => array( array( 2, 50, 24 ) ), 'H' => array( array( 4, 25, 9 ) ) ),
		5  => array( 'L' => array( array( 1, 134, 108 ) ), 'M' => array( array( 2, 67, 43 ) ), 'Q' => array( array( 2, 33, 15 ), array( 2, 34, 16 ) ), 'H' => array( array( 2, 33, 11 ), array( 2, 34, 12 ) ) ),
		6  => array( 'L' => array( array( 2, 86, 68 ) ), 'M' => array( array( 4, 43, 27 ) ), 'Q' => array( array( 4, 43, 19 ) ), 'H' => array( array( 4, 43, 15 ) ) ),
		7  => array( 'L' => array( array( 2, 98, 78 ) ), 'M' => array( array( 4, 49, 31 ) ), 'Q' => array( array( 2, 32, 14 ), array( 4, 33, 15 ) ), 'H' => array( array( 4, 39, 13 ), array( 1, 40, 14 ) ) ),
		8  => array( 'L' => array( array( 2, 121, 97 ) ), 'M' => array( array( 2, 60, 38 ), array( 2, 61, 39 ) ), 'Q' => array( array( 4, 40, 18 ), array( 2, 41, 19 ) ), 'H' => array( array( 4, 40, 14 ), array( 2, 41, 15 ) ) ),
		9  => array( 'L' => array( array( 2, 146, 116 ) ), 'M' => array( array( 3, 58, 36 ), array( 2, 59, 37 ) ), 'Q' => array( array( 4, 36, 16 ), array( 4, 37, 17 ) ), 'H' => array( array( 4, 36, 12 ), array( 4, 37, 13 ) ) ),
		10 => array( 'L' => array( array( 2, 86, 68 ), array( 2, 87, 69 ) ), 'M' => array( array( 4, 69, 43 ), array( 1, 70, 44 ) ), 'Q' => array( array( 6, 43, 19 ), array( 2, 44, 20 ) ), 'H' => array( array( 6, 43, 15 ), array( 2, 44, 16 ) ) ),
		11 => array( 'L' => array( array( 4, 101, 81 ) ), 'M' => array( array( 1, 80, 50 ), array( 4, 81, 51 ) ), 'Q' => array( array( 4, 50, 22 ), array( 4, 51, 23 ) ), 'H' => array( array( 3, 36, 12 ), array( 8, 37, 13 ) ) ),
		12 => array( 'L' => array( array( 2, 116, 92 ), array( 2, 117, 93 ) ), 'M' => array( array( 6, 58, 36 ), array( 2, 59, 37 ) ), 'Q' => array( array( 4, 46, 20 ), array( 6, 47, 21 ) ), 'H' => array( array( 7, 42, 14 ), array( 4, 43, 15 ) ) ),
	);

	// Mittelpunkte der Ausrichtungsmuster je Version.
	const ALIGNMENT = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
		11 => array( 6, 30, 54 ),
		12 => array( 6, 32, 58 ),
	);

	// Versionsinformation (BCH 18,6), erst ab Version 7 eingebettet.
	const VERSION_INFO = array(
		7  => 31892,
		8  => 34236,
		9  => 39577,
		10 => 42195,
		11 => 48118,
		12 => 51042,
	);

	// Bitmuster der Fehlerkorrekturstufe im Formatfeld.
	const EC_BITS = array( 'L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2 );

	/**
	 * Logarithmentafel von GF(256).
	 *
	 * @var array|null
	 */
	protected static $log = null;

	/**
	 * Exponententafel von GF(256).
	 *
	 * @var array|null
	 */
	protected static $exp = null;

	/* ====================================================================
	 * Öffentliche Schnittstelle
	 * ================================================================== */

	/**
	 * Modulraster erzeugen.
	 *
	 * @param string   $text  Inhalt.
	 * @param string   $level Fehlerkorrektur L, M, Q oder H.
	 * @param int|null $mask  Maske erzwingen; normalerweise null, dann wird
	 *                        die mit der geringsten Strafpunktzahl gewaehlt.
	 * @return array Zeilen mit je einem Array aus 0 und 1.
	 * @throws \InvalidArgumentException Wenn der Inhalt nicht passt.
	 */
	public static function matrix( $text, $level = 'M', $mask = null ) {
		$text  = (string) $text;
		$level = strtoupper( (string) $level );

		if ( ! isset( self::EC_BITS[ $level ] ) ) {
			throw new \InvalidArgumentException( 'Unbekannte Fehlerkorrekturstufe.' );
		}

		$version   = self::pick_version( strlen( $text ), $level );
		$codewords = self::final_message( $text, $version, $level );

		$size     = 17 + 4 * $version;
		$reserved = self::reserved_map( $version, $size );
		$matrix   = self::base_matrix( $version, $size );

		self::place_data( $matrix, $reserved, $codewords, $size );

		$mask = null === $mask
			? self::best_mask( $matrix, $reserved, $level, $size )
			: (int) $mask;
		self::apply_mask( $matrix, $reserved, $mask, $size );
		self::place_format( $matrix, $level, $mask, $size );

		if ( $version >= 7 ) {
			self::place_version( $matrix, $version, $size );
		}

		return $matrix;
	}

	/**
	 * QR-Code als SVG.
	 *
	 * Bewusst ohne eingebettete Schrift und ohne externe Referenz: Das SVG
	 * lässt sich in eine Seite einbetten, ausdrucken und als Datei ablegen.
	 *
	 * @param string $text   Inhalt.
	 * @param int    $px     Kantenlänge in Pixeln.
	 * @param string $level  Fehlerkorrektur.
	 * @param int    $quiet  Ruhezone in Modulen (Norm: 4).
	 * @return string
	 */
	public static function svg( $text, $px = 160, $level = 'M', $quiet = 4 ) {
		$matrix = self::matrix( $text, $level );
		$size   = count( $matrix );
		$total  = $size + 2 * (int) $quiet;

		$path = '';
		foreach ( $matrix as $y => $row ) {
			foreach ( $row as $x => $on ) {
				if ( $on ) {
					$path .= sprintf( 'M%d %dh1v1h-1z', $x + (int) $quiet, $y + (int) $quiet );
				}
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %2$d %2$d" shape-rendering="crispEdges" role="img" aria-label="QR-Code">'
			. '<rect width="%2$d" height="%2$d" fill="#fff"/><path d="%3$s" fill="#000"/></svg>',
			(int) $px,
			$total,
			$path
		);
	}

	/**
	 * Wie viele Bytes passen in eine Version bei dieser Stufe?
	 *
	 * @param int    $version Version.
	 * @param string $level   Stufe.
	 * @return int
	 */
	public static function capacity( $version, $level ) {
		$data_words = 0;
		foreach ( self::EC_BLOCKS[ $version ][ $level ] as $group ) {
			$data_words += $group[0] * $group[2];
		}

		// Modusanzeige 4 Bit plus Längenfeld (8 Bit bis Version 9, sonst 16).
		$header_bits = 4 + ( $version < 10 ? 8 : 16 );

		return (int) floor( ( $data_words * 8 - $header_bits ) / 8 );
	}

	/* ====================================================================
	 * Datenstrom
	 * ================================================================== */

	/**
	 * Kleinste Version, in die der Inhalt passt.
	 *
	 * @param int    $length Länge in Bytes.
	 * @param string $level  Stufe.
	 * @return int
	 * @throws \InvalidArgumentException Wenn der Inhalt zu lang ist.
	 */
	protected static function pick_version( $length, $level ) {
		for ( $v = 1; $v <= self::MAX_VERSION; $v++ ) {
			if ( $length <= self::capacity( $v, $level ) ) {
				return $v;
			}
		}

		throw new \InvalidArgumentException( 'Inhalt zu lang für einen QR-Code bis Version ' . self::MAX_VERSION . '.' );
	}

	/**
	 * Bitstrom aufbauen, in Blöcke teilen, Fehlerkorrektur rechnen und
	 * verschachteln.
	 *
	 * @param string $text    Inhalt.
	 * @param int    $version Version.
	 * @param string $level   Stufe.
	 * @return array Codewörter.
	 */
	protected static function final_message( $text, $version, $level ) {
		$groups     = self::EC_BLOCKS[ $version ][ $level ];
		$data_words = 0;
		foreach ( $groups as $group ) {
			$data_words += $group[0] * $group[2];
		}

		$bits = '0100'; // Byte-Modus.
		$bits .= str_pad( decbin( strlen( $text ) ), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT );

		for ( $i = 0, $n = strlen( $text ); $i < $n; $i++ ) {
			$bits .= str_pad( decbin( ord( $text[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}

		// Abschlusszeichen, höchstens vier Nullbits.
		$capacity_bits = $data_words * 8;
		$bits         .= str_repeat( '0', min( 4, $capacity_bits - strlen( $bits ) ) );

		// Auf volle Bytes auffüllen.
		if ( strlen( $bits ) % 8 ) {
			$bits .= str_repeat( '0', 8 - strlen( $bits ) % 8 );
		}

		$data = array();
		foreach ( str_split( $bits, 8 ) as $byte ) {
			$data[] = bindec( $byte );
		}

		// Füllbytes nach Norm: abwechselnd 236 und 17.
		$pad = array( 236, 17 );
		for ( $i = 0; count( $data ) < $data_words; $i++ ) {
			$data[] = $pad[ $i % 2 ];
		}

		// In Blöcke teilen und je Block die Fehlerkorrektur rechnen.
		$data_blocks  = array();
		$error_blocks = array();
		$offset       = 0;

		foreach ( $groups as $group ) {
			list( $count, $total, $per_block ) = $group;
			$ec_words                          = $total - $per_block;

			for ( $b = 0; $b < $count; $b++ ) {
				$block          = array_slice( $data, $offset, $per_block );
				$offset        += $per_block;
				$data_blocks[]  = $block;
				$error_blocks[] = self::reed_solomon( $block, $ec_words );
			}
		}

		return array_merge(
			self::interleave( $data_blocks ),
			self::interleave( $error_blocks )
		);
	}

	/**
	 * Blöcke spaltenweise verschachteln.
	 *
	 * @param array $blocks Blöcke.
	 * @return array
	 */
	protected static function interleave( array $blocks ) {
		$out = array();
		$max = 0;
		foreach ( $blocks as $block ) {
			$max = max( $max, count( $block ) );
		}

		for ( $i = 0; $i < $max; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block[ $i ] ) ) {
					$out[] = $block[ $i ];
				}
			}
		}

		return $out;
	}

	/* ====================================================================
	 * Galois-Körper und Reed-Solomon
	 * ================================================================== */

	/**
	 * Tafeln von GF(256) mit dem Generatorpolynom 0x11D aufbauen.
	 */
	protected static function init_tables() {
		if ( null !== self::$log ) {
			return;
		}

		self::$log = array_fill( 0, 256, 0 );
		self::$exp = array_fill( 0, 512, 0 );

		$x = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			self::$exp[ $i ]   = $x;
			self::$log[ $x ]   = $i;
			$x                <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11D;
			}
		}
		for ( $i = 255; $i < 512; $i++ ) {
			self::$exp[ $i ] = self::$exp[ $i - 255 ];
		}
	}

	/**
	 * Generatorpolynom für n Fehlerkorrekturwörter.
	 *
	 * @param int $n Anzahl.
	 * @return array Exponenten der Koeffizienten.
	 */
	protected static function generator( $n ) {
		self::init_tables();

		$poly = array( 1 );
		for ( $i = 0; $i < $n; $i++ ) {
			$next = array_fill( 0, count( $poly ) + 1, 0 );
			foreach ( $poly as $k => $coef ) {
				$next[ $k ]     ^= $coef;
				$next[ $k + 1 ] ^= self::mul( $coef, self::$exp[ $i ] );
			}
			$poly = $next;
		}

		return $poly;
	}

	/**
	 * Multiplikation in GF(256).
	 *
	 * @param int $a Faktor.
	 * @param int $b Faktor.
	 * @return int
	 */
	protected static function mul( $a, $b ) {
		if ( 0 === $a || 0 === $b ) {
			return 0;
		}
		return self::$exp[ self::$log[ $a ] + self::$log[ $b ] ];
	}

	/**
	 * Fehlerkorrekturwörter eines Blocks.
	 *
	 * @param array $block    Datenwörter.
	 * @param int   $ec_words Anzahl Korrekturwörter.
	 * @return array
	 */
	protected static function reed_solomon( array $block, $ec_words ) {
		self::init_tables();

		$gen  = self::generator( $ec_words );
		$rest = array_merge( $block, array_fill( 0, $ec_words, 0 ) );

		for ( $i = 0, $n = count( $block ); $i < $n; $i++ ) {
			$coef = $rest[ $i ];
			if ( 0 === $coef ) {
				continue;
			}
			foreach ( $gen as $k => $g ) {
				$rest[ $i + $k ] ^= self::mul( $g, $coef );
			}
		}

		return array_slice( $rest, count( $block ) );
	}

	/* ====================================================================
	 * Raster
	 * ================================================================== */

	/**
	 * Karte der belegten Felder: 1 heißt Funktionsmuster oder reserviert.
	 *
	 * @param int $version Version.
	 * @param int $size    Kantenlänge.
	 * @return array
	 */
	protected static function reserved_map( $version, $size ) {
		$map = array_fill( 0, $size, array_fill( 0, $size, 0 ) );

		// Suchmuster samt Trennlinie in den drei Ecken.
		foreach ( array( array( 0, 0 ), array( $size - 7, 0 ), array( 0, $size - 7 ) ) as $corner ) {
			for ( $y = -1; $y <= 7; $y++ ) {
				for ( $x = -1; $x <= 7; $x++ ) {
					$py = $corner[1] + $y;
					$px = $corner[0] + $x;
					if ( $py >= 0 && $py < $size && $px >= 0 && $px < $size ) {
						$map[ $py ][ $px ] = 1;
					}
				}
			}
		}

		// Taktmuster.
		for ( $i = 0; $i < $size; $i++ ) {
			$map[6][ $i ] = 1;
			$map[ $i ][6] = 1;
		}

		// Ausrichtungsmuster.
		foreach ( self::alignment_centers( $version, $size ) as $center ) {
			for ( $y = -2; $y <= 2; $y++ ) {
				for ( $x = -2; $x <= 2; $x++ ) {
					$map[ $center[1] + $y ][ $center[0] + $x ] = 1;
				}
			}
		}

		// Formatfelder: links oben je neun Module, an den beiden anderen Ecken
		// je acht. Ein Modul zu viel zu reservieren verschiebt den gesamten
		// Datenstrom – deshalb sind die Grenzen hier verschieden.
		for ( $i = 0; $i <= 8; $i++ ) {
			$map[8][ $i ] = 1;
			$map[ $i ][8] = 1;
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$map[8][ $size - 1 - $i ] = 1;
			$map[ $size - 1 - $i ][8] = 1;
		}

		// Versionsfelder ab Version 7.
		if ( $version >= 7 ) {
			for ( $i = 0; $i < 6; $i++ ) {
				for ( $j = 0; $j < 3; $j++ ) {
					$map[ $i ][ $size - 11 + $j ] = 1;
					$map[ $size - 11 + $j ][ $i ] = 1;
				}
			}
		}

		return $map;
	}

	/**
	 * Mittelpunkte der Ausrichtungsmuster ohne die drei Ecken.
	 *
	 * @param int $version Version.
	 * @param int $size    Kantenlänge.
	 * @return array
	 */
	protected static function alignment_centers( $version, $size ) {
		$positions = self::ALIGNMENT[ $version ];
		$centers   = array();

		foreach ( $positions as $y ) {
			foreach ( $positions as $x ) {
				$in_corner = ( 6 === $x && 6 === $y )
					|| ( 6 === $x && $y === $size - 7 )
					|| ( $x === $size - 7 && 6 === $y );
				if ( ! $in_corner ) {
					$centers[] = array( $x, $y );
				}
			}
		}

		return $centers;
	}

	/**
	 * Funktionsmuster ins Raster zeichnen.
	 *
	 * @param int $version Version.
	 * @param int $size    Kantenlänge.
	 * @return array
	 */
	protected static function base_matrix( $version, $size ) {
		$m = array_fill( 0, $size, array_fill( 0, $size, 0 ) );

		foreach ( array( array( 0, 0 ), array( $size - 7, 0 ), array( 0, $size - 7 ) ) as $corner ) {
			for ( $y = 0; $y < 7; $y++ ) {
				for ( $x = 0; $x < 7; $x++ ) {
					$ring = ( 0 === $y || 6 === $y || 0 === $x || 6 === $x );
					$core = ( $y >= 2 && $y <= 4 && $x >= 2 && $x <= 4 );
					$m[ $corner[1] + $y ][ $corner[0] + $x ] = ( $ring || $core ) ? 1 : 0;
				}
			}
		}

		for ( $i = 8; $i < $size - 8; $i++ ) {
			$bit          = ( 0 === $i % 2 ) ? 1 : 0;
			$m[6][ $i ]   = $bit;
			$m[ $i ][6]   = $bit;
		}

		foreach ( self::alignment_centers( $version, $size ) as $center ) {
			for ( $y = -2; $y <= 2; $y++ ) {
				for ( $x = -2; $x <= 2; $x++ ) {
					$edge = ( 2 === abs( $x ) || 2 === abs( $y ) );
					$dot  = ( 0 === $x && 0 === $y );
					$m[ $center[1] + $y ][ $center[0] + $x ] = ( $edge || $dot ) ? 1 : 0;
				}
			}
		}

		// Immer dunkles Modul.
		$m[ $size - 8 ][8] = 1;

		return $m;
	}

	/**
	 * Codewörter im Zickzack von rechts unten nach oben einfüllen.
	 *
	 * @param array $matrix     Raster.
	 * @param array $reserved   Belegungskarte.
	 * @param array $codewords  Codewörter.
	 * @param int   $size       Kantenlänge.
	 */
	protected static function place_data( array &$matrix, array $reserved, array $codewords, $size ) {
		$bits = '';
		foreach ( $codewords as $word ) {
			$bits .= str_pad( decbin( $word ), 8, '0', STR_PAD_LEFT );
		}

		$index = 0;
		$up    = true;

		for ( $right = $size - 1; $right > 0; $right -= 2 ) {
			// Die Spalte des senkrechten Taktmusters wird übersprungen.
			if ( 6 === $right ) {
				$right = 5;
			}

			for ( $step = 0; $step < $size; $step++ ) {
				$y = $up ? $size - 1 - $step : $step;

				foreach ( array( $right, $right - 1 ) as $x ) {
					if ( $reserved[ $y ][ $x ] ) {
						continue;
					}
					$matrix[ $y ][ $x ] = isset( $bits[ $index ] ) ? (int) $bits[ $index ] : 0;
					$index++;
				}
			}

			$up = ! $up;
		}
	}

	/**
	 * Maskenbedingung.
	 *
	 * @param int $mask Maske 0 bis 7.
	 * @param int $x    Spalte.
	 * @param int $y    Zeile.
	 * @return bool
	 */
	protected static function mask_bit( $mask, $x, $y ) {
		switch ( $mask ) {
			case 0:
				return 0 === ( $y + $x ) % 2;
			case 1:
				return 0 === $y % 2;
			case 2:
				return 0 === $x % 3;
			case 3:
				return 0 === ( $y + $x ) % 3;
			case 4:
				return 0 === ( intdiv( $y, 2 ) + intdiv( $x, 3 ) ) % 2;
			case 5:
				return 0 === ( $y * $x ) % 2 + ( $y * $x ) % 3;
			case 6:
				return 0 === ( ( $y * $x ) % 2 + ( $y * $x ) % 3 ) % 2;
			default:
				return 0 === ( ( $y + $x ) % 2 + ( $y * $x ) % 3 ) % 2;
		}
	}

	/**
	 * Maske auf die Datenfelder anwenden.
	 *
	 * @param array $matrix   Raster.
	 * @param array $reserved Belegungskarte.
	 * @param int   $mask     Maske.
	 * @param int   $size     Kantenlänge.
	 */
	protected static function apply_mask( array &$matrix, array $reserved, $mask, $size ) {
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( ! $reserved[ $y ][ $x ] && self::mask_bit( $mask, $x, $y ) ) {
					$matrix[ $y ][ $x ] ^= 1;
				}
			}
		}
	}

	/**
	 * Maske mit der geringsten Strafpunktzahl wählen.
	 *
	 * @param array  $matrix   Raster.
	 * @param array  $reserved Belegungskarte.
	 * @param string $level    Stufe.
	 * @param int    $size     Kantenlänge.
	 * @return int
	 */
	protected static function best_mask( array $matrix, array $reserved, $level, $size ) {
		$best  = 0;
		$score = PHP_INT_MAX;

		for ( $mask = 0; $mask < 8; $mask++ ) {
			$candidate = $matrix;
			self::blank_format( $candidate, $size );
			self::apply_mask( $candidate, $reserved, $mask, $size );

			$penalty = self::penalty( $candidate, $size );
			if ( $penalty < $score ) {
				$score = $penalty;
				$best  = $mask;
			}
		}

		return $best;
	}

	/**
	 * Format- und Versionsfelder für die Maskenbewertung auf hell setzen.
	 *
	 * Die Norm laesst offen, mit welchem Inhalt der Formatbereich bewertet
	 * wird. Gaengige Umsetzungen bewerten ihn leer – und weil davon abhaengt,
	 * welche Maske gewinnt, wird hier dieselbe Festlegung getroffen. Auf die
	 * Lesbarkeit hat sie keinen Einfluss; jede der acht Masken ergibt einen
	 * gueltigen Code.
	 *
	 * @param array $matrix Raster.
	 * @param int   $size   Kantenlaenge.
	 */
	protected static function blank_format( array &$matrix, $size ) {
		for ( $i = 0; $i <= 8; $i++ ) {
			// Index 6 gehoert zum Taktmuster, nicht zum Formatfeld.
			if ( 6 === $i ) {
				continue;
			}
			$matrix[8][ $i ] = 0;
			$matrix[ $i ][8] = 0;
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$matrix[8][ $size - 1 - $i ] = 0;
			$matrix[ $size - 1 - $i ][8] = 0;
		}
	}

	/**
	 * Strafpunkte nach den vier Regeln der Norm.
	 *
	 * @param array $m    Raster.
	 * @param int   $size Kantenlänge.
	 * @return int
	 */
	protected static function penalty( array $m, $size ) {
		$score = 0;

		// Regel 1: Ketten gleicher Module ab Länge fünf.
		for ( $i = 0; $i < $size; $i++ ) {
			foreach ( array( 'row', 'col' ) as $dir ) {
				$run  = 1;
				$prev = 'row' === $dir ? $m[ $i ][0] : $m[0][ $i ];
				for ( $j = 1; $j < $size; $j++ ) {
					$value = 'row' === $dir ? $m[ $i ][ $j ] : $m[ $j ][ $i ];
					if ( $value === $prev ) {
						$run++;
						continue;
					}
					if ( $run >= 5 ) {
						$score += 3 + ( $run - 5 );
					}
					$prev = $value;
					$run  = 1;
				}
				if ( $run >= 5 ) {
					$score += 3 + ( $run - 5 );
				}
			}
		}

		// Regel 2: gleichfarbige Blöcke aus zwei mal zwei Modulen.
		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$v = $m[ $y ][ $x ];
				if ( $v === $m[ $y ][ $x + 1 ] && $v === $m[ $y + 1 ][ $x ] && $v === $m[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}

		// Regel 3: Muster, die einem Suchmuster ähneln.
		$needles = array(
			array( 1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0 ),
			array( 0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1 ),
		);
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				foreach ( $needles as $needle ) {
					if ( $x + 11 <= $size ) {
						$hit = true;
						for ( $k = 0; $k < 11; $k++ ) {
							if ( $m[ $y ][ $x + $k ] !== $needle[ $k ] ) {
								$hit = false;
								break;
							}
						}
						if ( $hit ) {
							$score += 40;
						}
					}
					if ( $y + 11 <= $size ) {
						$hit = true;
						for ( $k = 0; $k < 11; $k++ ) {
							if ( $m[ $y + $k ][ $x ] !== $needle[ $k ] ) {
								$hit = false;
								break;
							}
						}
						if ( $hit ) {
							$score += 40;
						}
					}
				}
			}
		}

		// Regel 4: Abweichung vom hälftigen Anteil dunkler Module.
		$dark = 0;
		foreach ( $m as $row ) {
			$dark += array_sum( $row );
		}
		$percent = $dark * 100 / ( $size * $size );
		$score  += 10 * (int) floor( abs( $percent - 50 ) / 5 );

		return $score;
	}

	/**
	 * Formatfeld berechnen und an beiden Stellen eintragen.
	 *
	 * @param array  $matrix Raster.
	 * @param string $level  Stufe.
	 * @param int    $mask   Maske.
	 * @param int    $size   Kantenlänge.
	 */
	protected static function place_format( array &$matrix, $level, $mask, $size ) {
		$data = ( self::EC_BITS[ $level ] << 3 ) | $mask;
		$bch  = $data << 10;

		for ( $i = 4; $i >= 0; $i-- ) {
			if ( $bch & ( 1 << ( $i + 10 ) ) ) {
				$bch ^= 0x537 << $i;
			}
		}

		$format = ( ( $data << 10 ) | $bch ) ^ 0x5412;

		for ( $i = 0; $i < 15; $i++ ) {
			$bit = ( $format >> $i ) & 1;

			// Erste Kopie rund um das linke obere Suchmuster.
			if ( $i < 6 ) {
				$matrix[ $i ][8] = $bit;
			} elseif ( 6 === $i ) {
				$matrix[7][8] = $bit;
			} elseif ( 7 === $i ) {
				$matrix[8][8] = $bit;
			} elseif ( 8 === $i ) {
				// Spalte 6 ist Taktmuster; Bit 8 rutscht deshalb auf Spalte 7.
				$matrix[8][7] = $bit;
			} else {
				$matrix[8][ 14 - $i ] = $bit;
			}

			// Zweite Kopie, geteilt auf die beiden anderen Ecken.
			if ( $i < 8 ) {
				$matrix[8][ $size - 1 - $i ] = $bit;
			} else {
				$matrix[ $size - 15 + $i ][8] = $bit;
			}
		}
	}

	/**
	 * Versionsfeld ab Version 7 eintragen.
	 *
	 * @param array $matrix  Raster.
	 * @param int   $version Version.
	 * @param int   $size    Kantenlänge.
	 */
	protected static function place_version( array &$matrix, $version, $size ) {
		$info = self::VERSION_INFO[ $version ];

		for ( $i = 0; $i < 18; $i++ ) {
			$bit = ( $info >> $i ) & 1;
			$row = intdiv( $i, 3 );
			$col = $i % 3;

			$matrix[ $row ][ $size - 11 + $col ] = $bit;
			$matrix[ $size - 11 + $col ][ $row ] = $bit;
		}
	}
}
