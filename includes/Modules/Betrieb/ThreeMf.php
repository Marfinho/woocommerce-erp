<?php
/**
 * Lesen und Vervielfältigen von 3MF-Modellen.
 *
 * Ein 3MF ist ein ZIP mit einer XML-Datei darin (3D/3dmodel.model). Diese
 * Klasse fasst ausschließlich diese XML an: sie liest die Bauteilgrösse aus
 * dem Netz und legt für eine Platte mehrere Instanzen desselben Objekts an.
 *
 * Bewusst wird das Netz selbst nie kopiert. 3MF trennt Geometrie (<object>)
 * von Platzierung (<item> im <build>). Zehn Teile sind deshalb zehn Zeilen
 * mit je einer anderen Verschiebung – die Datei bleibt so gross wie vorher
 * und die Geometrie bleibt bitgenau die freigegebene.
 *
 * Die Klasse ist frei von Datenbank und Dateisystem, damit sie prüfbar ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Reine 3MF-Logik: Geometrie messen, Platte füllen.
 */
class ThreeMf {

	/**
	 * Pfad des Modells im ZIP.
	 */
	const MODEL_PATH = '3D/3dmodel.model';

	/**
	 * Datei, an der ein Bambu-/Orca-Projekt erkennbar ist.
	 */
	const PROJECT_MARKER = 'Metadata/model_settings.config';

	/**
	 * Grenze gegen Endlosschleifen in verschachtelten Komponenten.
	 */
	const MAX_DEPTH = 8;

	/**
	 * Obergrenze für eine Platte – mehr ist kein Bedienfehler mehr,
	 * sondern ein Vertipper.
	 */
	const MAX_COPIES = 200;

	/**
	 * Einheitsmatrix als 3MF-Zwölfer.
	 *
	 * @return array
	 */
	public static function identity() {
		return array( 1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0 );
	}

	/**
	 * Transform-Attribut lesen. Fehlt es oder ist es unbrauchbar, gilt die
	 * Einheitsmatrix – genau so verlangt es die Spezifikation.
	 *
	 * @param string $raw Attributwert.
	 * @return array Zwölf Zahlen.
	 */
	public static function matrix( $raw ) {
		$parts = preg_split( '/\s+/', trim( (string) $raw ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $parts || 12 !== count( $parts ) ) {
			return self::identity();
		}

		$out = array();
		foreach ( $parts as $part ) {
			if ( ! is_numeric( $part ) ) {
				return self::identity();
			}
			$out[] = (float) $part;
		}
		return $out;
	}

	/**
	 * Matrix als Attributwert schreiben.
	 *
	 * @param array $m Zwölf Zahlen.
	 * @return string
	 */
	public static function matrix_string( array $m ) {
		$out = array();
		foreach ( $m as $value ) {
			$out[] = rtrim( rtrim( number_format( (float) $value, 6, '.', '' ), '0' ), '.' );
			if ( '' === end( $out ) || '-' === end( $out ) ) {
				$out[ count( $out ) - 1 ] = '0';
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * Zwei Matrizen verketten: erst $inner, dann $outer.
	 *
	 * @param array $outer Äussere Matrix.
	 * @param array $inner Innere Matrix.
	 * @return array
	 */
	public static function compose( array $outer, array $inner ) {
		$out = array();

		for ( $row = 0; $row < 3; $row++ ) {
			for ( $col = 0; $col < 3; $col++ ) {
				$sum = 0.0;
				for ( $k = 0; $k < 3; $k++ ) {
					$sum += $inner[ $row * 3 + $k ] * $outer[ $k * 3 + $col ];
				}
				$out[ $row * 3 + $col ] = $sum;
			}
		}

		for ( $col = 0; $col < 3; $col++ ) {
			$sum = 0.0;
			for ( $k = 0; $k < 3; $k++ ) {
				$sum += $inner[ 9 + $k ] * $outer[ $k * 3 + $col ];
			}
			$out[ 9 + $col ] = $sum + $outer[ 9 + $col ];
		}

		return $out;
	}

	/**
	 * Einen Punkt durch die Matrix schicken.
	 *
	 * @param array $m Matrix.
	 * @param float $x X.
	 * @param float $y Y.
	 * @param float $z Z.
	 * @return array
	 */
	public static function apply( array $m, $x, $y, $z ) {
		return array(
			$x * $m[0] + $y * $m[3] + $z * $m[6] + $m[9],
			$x * $m[1] + $y * $m[4] + $z * $m[7] + $m[10],
			$x * $m[2] + $y * $m[5] + $z * $m[8] + $m[11],
		);
	}

	/**
	 * Verschiebung auf eine Matrix legen.
	 *
	 * @param array $m  Matrix.
	 * @param float $dx X-Versatz.
	 * @param float $dy Y-Versatz.
	 * @return array
	 */
	public static function translate( array $m, $dx, $dy ) {
		$m[9]  = $m[9] + (float) $dx;
		$m[10] = $m[10] + (float) $dy;
		return $m;
	}

	/**
	 * XML einlesen. Liefert null, wenn es kein 3MF-Modell ist.
	 *
	 * @param string $xml Inhalt von 3D/3dmodel.model.
	 * @return \DOMDocument|null
	 */
	public static function document( $xml ) {
		if ( '' === trim( (string) $xml ) ) {
			return null;
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		$ok   = $doc->loadXML( (string) $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok || ! $doc->documentElement || 'model' !== $doc->documentElement->localName ) {
			return null;
		}

		return $doc;
	}

	/**
	 * Alle Objekte nach ihrer id.
	 *
	 * @param \DOMDocument $doc Dokument.
	 * @return array id => DOMElement.
	 */
	public static function objects( \DOMDocument $doc ) {
		$out = array();
		foreach ( $doc->getElementsByTagName( 'object' ) as $object ) {
			$id = $object->getAttribute( 'id' );
			if ( '' !== $id ) {
				$out[ $id ] = $object;
			}
		}
		return $out;
	}

	/**
	 * Die Positionen im <build>.
	 *
	 * @param \DOMDocument $doc Dokument.
	 * @return array DOMElement-Liste.
	 */
	public static function items( \DOMDocument $doc ) {
		$out = array();
		foreach ( $doc->getElementsByTagName( 'item' ) as $item ) {
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Hüllquader eines Objekts, bereits durch $matrix geschickt.
	 *
	 * @param array        $objects Objekte nach id.
	 * @param string       $id      Objekt-id.
	 * @param array        $matrix  Ausgangsmatrix.
	 * @param int          $depth   Rekursionstiefe.
	 * @param array|null   $box     Bisheriger Quader.
	 * @return array|null array(minx,miny,minz,maxx,maxy,maxz) oder null.
	 */
	public static function bbox( array $objects, $id, array $matrix, $depth = 0, $box = null ) {
		if ( $depth > self::MAX_DEPTH || ! isset( $objects[ $id ] ) ) {
			return $box;
		}

		$object = $objects[ $id ];

		foreach ( $object->getElementsByTagName( 'vertex' ) as $vertex ) {
			$point = self::apply(
				$matrix,
				(float) $vertex->getAttribute( 'x' ),
				(float) $vertex->getAttribute( 'y' ),
				(float) $vertex->getAttribute( 'z' )
			);

			if ( null === $box ) {
				$box = array( $point[0], $point[1], $point[2], $point[0], $point[1], $point[2] );
				continue;
			}

			for ( $axis = 0; $axis < 3; $axis++ ) {
				$box[ $axis ]     = min( $box[ $axis ], $point[ $axis ] );
				$box[ $axis + 3 ] = max( $box[ $axis + 3 ], $point[ $axis ] );
			}
		}

		foreach ( $object->getElementsByTagName( 'component' ) as $component ) {
			$child = $component->getAttribute( 'objectid' );
			if ( '' === $child || $child === $id ) {
				continue;
			}
			$box = self::bbox(
				$objects,
				$child,
				self::compose( $matrix, self::matrix( $component->getAttribute( 'transform' ) ) ),
				$depth + 1,
				$box
			);
		}

		return $box;
	}

	/**
	 * Raster für $count Teile berechnen.
	 *
	 * Gelegt wird spaltenweise von vorn links nach hinten rechts, das Ganze
	 * mittig auf der Platte. Passt es nicht, sagt die Antwort wie viele
	 * Teile passen – das ist die Zahl, die der Bediener wirklich braucht.
	 *
	 * @param int   $count   Gewünschte Stückzahl.
	 * @param float $width   Bauteilbreite in mm.
	 * @param float $depth   Bauteiltiefe in mm.
	 * @param float $gap     Abstand in mm.
	 * @param float $plate_w Plattenbreite in mm.
	 * @param float $plate_d Plattentiefe in mm.
	 * @return array {fits, cols, rows, max, offsets, error}
	 */
	public static function grid( $count, $width, $depth, $gap, $plate_w, $plate_d ) {
		$count   = max( 1, (int) $count );
		$width   = max( 0.001, (float) $width );
		$depth   = max( 0.001, (float) $depth );
		$gap     = max( 0.0, (float) $gap );
		$plate_w = max( 0.0, (float) $plate_w );
		$plate_d = max( 0.0, (float) $plate_d );

		$fail = array(
			'fits'    => false,
			'cols'    => 0,
			'rows'    => 0,
			'max'     => 0,
			'offsets' => array(),
			'error'   => '',
		);

		if ( $width > $plate_w || $depth > $plate_d ) {
			$fail['error'] = __( 'Das Bauteil ist grösser als die Platte.', 'lindenweg16' );
			return $fail;
		}

		// Wie viele Zellen passen je Achse? Der Abstand fällt nur zwischen
		// den Teilen an, nicht am Rand.
		$max_cols = (int) floor( ( $plate_w + $gap ) / ( $width + $gap ) );
		$max_rows = (int) floor( ( $plate_d + $gap ) / ( $depth + $gap ) );
		$max      = max( 0, $max_cols * $max_rows );

		if ( $count > $max ) {
			$fail['max']   = $max;
			$fail['error'] = sprintf(
				/* translators: %d: Stückzahl. */
				__( 'So viele Teile passen nicht auf die Platte. Platz ist für %d.', 'lindenweg16' ),
				$max
			);
			return $fail;
		}

		// Möglichst quadratisch anordnen – das lässt am meisten Luft zum Rand.
		$cols = min( $max_cols, max( 1, (int) ceil( sqrt( $count ) ) ) );
		$rows = (int) ceil( $count / $cols );
		if ( $rows > $max_rows ) {
			$cols = $max_cols;
			$rows = (int) ceil( $count / $cols );
		}

		$step_x = $width + $gap;
		$step_y = $depth + $gap;

		// Belegte Fläche mittig auf die Platte legen.
		$used_w = $cols * $width + ( $cols - 1 ) * $gap;
		$used_d = $rows * $depth + ( $rows - 1 ) * $gap;
		$left   = ( $plate_w - $used_w ) / 2;
		$front  = ( $plate_d - $used_d ) / 2;

		$offsets = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$col       = $i % $cols;
			$row       = (int) floor( $i / $cols );
			$offsets[] = array(
				$left + $col * $step_x,
				$front + $row * $step_y,
			);
		}

		return array(
			'fits'    => true,
			'cols'    => $cols,
			'rows'    => $rows,
			'max'     => $max,
			'offsets' => $offsets,
			'error'   => '',
		);
	}

	/**
	 * Aus einem Modell mit genau einer Position eine volle Platte machen.
	 *
	 * @param string $xml     Inhalt von 3D/3dmodel.model.
	 * @param int    $count   Gewünschte Stückzahl.
	 * @param array  $options gap, plate_w, plate_d.
	 * @return array {success, xml, placed, cols, rows, size, message}
	 */
	public static function fill_plate( $xml, $count, array $options = array() ) {
		$gap     = isset( $options['gap'] ) ? (float) $options['gap'] : 6.0;
		$plate_w = isset( $options['plate_w'] ) ? (float) $options['plate_w'] : 256.0;
		$plate_d = isset( $options['plate_d'] ) ? (float) $options['plate_d'] : 256.0;
		$count   = (int) $count;

		$fail = array(
			'success' => false,
			'xml'     => '',
			'placed'  => 0,
			'cols'    => 0,
			'rows'    => 0,
			'size'    => array( 0.0, 0.0, 0.0 ),
			'message' => '',
		);

		if ( $count < 1 || $count > self::MAX_COPIES ) {
			$fail['message'] = sprintf(
				/* translators: %d: Obergrenze. */
				__( 'Bitte eine Stückzahl zwischen 1 und %d angeben.', 'lindenweg16' ),
				self::MAX_COPIES
			);
			return $fail;
		}

		$doc = self::document( $xml );
		if ( ! $doc ) {
			$fail['message'] = __( 'Die Datei enthält kein lesbares 3MF-Modell.', 'lindenweg16' );
			return $fail;
		}

		$items = self::items( $doc );
		if ( ! $items ) {
			$fail['message'] = __( 'In der Datei ist kein Bauteil platziert.', 'lindenweg16' );
			return $fail;
		}
		if ( count( $items ) > 1 ) {
			$fail['message'] = __( 'In der Datei liegen schon mehrere Teile. Bitte eine Datei mit genau einem Körper hinterlegen.', 'lindenweg16' );
			return $fail;
		}

		$item    = $items[0];
		$objects = self::objects( $doc );
		$id      = $item->getAttribute( 'objectid' );
		$matrix  = self::matrix( $item->getAttribute( 'transform' ) );
		$box     = self::bbox( $objects, $id, $matrix );

		if ( ! $box ) {
			$fail['message'] = __( 'Das Bauteil hat keine messbare Geometrie.', 'lindenweg16' );
			return $fail;
		}

		$width = $box[3] - $box[0];
		$depth = $box[4] - $box[1];
		$high  = $box[5] - $box[2];

		$grid = self::grid( $count, $width, $depth, $gap, $plate_w, $plate_d );
		if ( ! $grid['fits'] ) {
			$fail['size']    = array( $width, $depth, $high );
			$fail['message'] = $grid['error'];
			return $fail;
		}

		$parent = $item->parentNode;

		foreach ( $grid['offsets'] as $index => $offset ) {
			$moved = self::translate( $matrix, $offset[0] - $box[0], $offset[1] - $box[1] );
			if ( 0 === $index ) {
				$item->setAttribute( 'transform', self::matrix_string( $moved ) );
				continue;
			}
			$copy = $item->cloneNode( true );
			$copy->setAttribute( 'transform', self::matrix_string( $moved ) );
			$parent->appendChild( $copy );
		}

		return array(
			'success' => true,
			'xml'     => $doc->saveXML(),
			'placed'  => count( $grid['offsets'] ),
			'cols'    => $grid['cols'],
			'rows'    => $grid['rows'],
			'size'    => array( $width, $depth, $high ),
			'message' => '',
		);
	}
}
