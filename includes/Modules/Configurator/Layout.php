<?php
/**
 * Die Regeln des Aufbaus – als reine Rechnung, ohne Datenbank.
 *
 * Der Haken besteht aus einem Basismodul und Modulen, die daran vertikal
 * frei verschiebbar sind. Frei heißt nicht beliebig: Zwei Module dürfen
 * sich nicht überlappen, keines darf über die Schiene hinausragen und
 * keines darf auf einem Sperrbereich sitzen – dort wird das Basismodul an
 * die Wand geschraubt, ein Modul davor käme nie an die Schraube.
 *
 * Diese Klasse ist die **verbindliche** Fassung dieser Regeln. Der Viewer
 * im Browser bildet sie ein zweites Mal ab, damit sich ein Modul beim
 * Ziehen sofort sperrig anfühlt; verlassen darf man sich darauf nicht. Was
 * in den Warenkorb geht, wird ausschließlich hier geprüft.
 *
 * Alle Maße sind Millimeter, gemessen von der Unterkante des Basismoduls.
 * Ein Modul belegt das halboffene Intervall [Position, Position + Höhe).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

/**
 * Prüft und beschreibt eine Konfiguration.
 */
class Layout {

	/**
	 * Mehr Module als das kann kein Haken tragen – und kein Formular
	 * sinnvoll darstellen. Die Grenze schützt vor aufgeblähten Links.
	 */
	const MAX_MODULES = 40;

	/**
	 * Größte bestellbare Stückzahl einer Konfiguration.
	 */
	const MAX_QTY = 99;

	/**
	 * Toleranz beim Vergleich zweier Millimeterangaben.
	 *
	 * Positionen kommen als Fließkommazahl aus dem Browser. Ohne Toleranz
	 * meldet ein Modul, das exakt an seinem Nachbarn anschließt, je nach
	 * Rundung eine Überlappung von einem tausendstel Millimeter.
	 */
	const EPSILON = 0.01;

	/**
	 * Eine Konfiguration prüfen.
	 *
	 * @param array $config  Konfiguration: base, color, qty, modules[[article,pos]].
	 * @param array $context Stammdaten: parts (article_id => Teil), blocks (part-Artikel-ID => Sperrbereiche).
	 * @return array {errors: string[], modules: array} Module aufsteigend sortiert.
	 */
	public static function validate( array $config, array $context ) {
		$parts  = isset( $context['parts'] ) ? (array) $context['parts'] : array();
		$blocks = isset( $context['blocks'] ) ? (array) $context['blocks'] : array();
		$errors = array();

		$base_id = isset( $config['base'] ) ? (int) $config['base'] : 0;
		$base    = isset( $parts[ $base_id ] ) ? $parts[ $base_id ] : null;

		if ( ! $base || 'basis' !== $base['role'] || empty( $base['is_active'] ) ) {
			return array(
				'errors'  => array( __( 'Bitte ein Basismodul wählen.', 'lindenweg16' ) ),
				'modules' => array(),
			);
		}

		$rail = (float) $base['height_mm'];
		if ( $rail <= 0 ) {
			return array(
				'errors'  => array( __( 'Für das Basismodul ist keine Höhe hinterlegt – ohne sie lässt sich nichts daran anordnen.', 'lindenweg16' ) ),
				'modules' => array(),
			);
		}

		$qty = isset( $config['qty'] ) ? (int) $config['qty'] : 1;
		if ( $qty < 1 || $qty > self::MAX_QTY ) {
			$errors[] = sprintf(
				/* translators: %d: größte Stückzahl. */
				__( 'Die Stückzahl muss zwischen 1 und %d liegen.', 'lindenweg16' ),
				self::MAX_QTY
			);
		}

		$raw = isset( $config['modules'] ) ? array_values( (array) $config['modules'] ) : array();

		if ( count( $raw ) > self::MAX_MODULES ) {
			return array(
				'errors'  => array(
					sprintf(
						/* translators: %d: größte Modulzahl. */
						__( 'Mehr als %d Module sind nicht vorgesehen.', 'lindenweg16' ),
						self::MAX_MODULES
					),
				),
				'modules' => array(),
			);
		}

		$max = (int) $base['max_modules'];
		if ( $max > 0 && count( $raw ) > $max ) {
			$errors[] = sprintf(
				/* translators: 1: gewählte Anzahl, 2: erlaubte Anzahl. */
				__( '%1$d Module gewählt, an dieses Basismodul passen höchstens %2$d.', 'lindenweg16' ),
				count( $raw ),
				$max
			);
		}

		$modules = array();

		foreach ( $raw as $index => $entry ) {
			$article = isset( $entry['article'] ) ? (int) $entry['article'] : 0;
			$part    = isset( $parts[ $article ] ) ? $parts[ $article ] : null;

			if ( ! $part || 'modul' !== $part['role'] || empty( $part['is_active'] ) ) {
				$errors[] = __( 'Ein gewähltes Modul gibt es nicht mehr.', 'lindenweg16' );
				continue;
			}

			$pos    = isset( $entry['pos'] ) ? round( (float) $entry['pos'], 2 ) : 0.0;
			$height = (float) $part['height_mm'];

			if ( $height <= 0 ) {
				$errors[] = sprintf(
					/* translators: %s: Bezeichnung des Moduls. */
					__( 'Für „%s" ist keine Höhe hinterlegt.', 'lindenweg16' ),
					$part['name']
				);
				continue;
			}

			if ( $pos < -self::EPSILON || $pos + $height > $rail + self::EPSILON ) {
				$errors[] = sprintf(
					/* translators: 1: Bezeichnung des Moduls, 2: Länge der Schiene. */
					__( '„%1$s" ragt über das Basismodul hinaus – zwischen 0 und %2$s mm ist Platz.', 'lindenweg16' ),
					$part['name'],
					self::format_mm( $rail )
				);
				continue;
			}

			$modules[] = array(
				'article' => $article,
				'pos'     => max( 0.0, $pos ),
				'height'  => $height,
				'name'    => (string) $part['name'],
				'index'   => (int) $index,
			);
		}

		usort(
			$modules,
			static function ( $a, $b ) {
				return $a['pos'] === $b['pos'] ? $a['index'] - $b['index'] : ( $a['pos'] < $b['pos'] ? -1 : 1 );
			}
		);

		$errors = array_merge(
			$errors,
			self::overlap_errors( $modules ),
			self::block_errors( $modules, isset( $blocks[ $base_id ] ) ? (array) $blocks[ $base_id ] : array() )
		);

		return array(
			'errors'  => array_values( array_unique( $errors ) ),
			'modules' => $modules,
		);
	}

	/**
	 * Überlappungen zwischen benachbarten Modulen melden.
	 *
	 * @param array $modules Nach Position sortierte Module.
	 * @return array
	 */
	protected static function overlap_errors( array $modules ) {
		$errors = array();

		for ( $i = 1; $i < count( $modules ); $i++ ) {
			$below = $modules[ $i - 1 ];
			$above = $modules[ $i ];

			if ( $above['pos'] < $below['pos'] + $below['height'] - self::EPSILON ) {
				$errors[] = sprintf(
					/* translators: 1: unteres Modul, 2: oberes Modul. */
					__( '„%1$s" und „%2$s" überschneiden sich – Module brauchen jedes seinen eigenen Platz.', 'lindenweg16' ),
					$below['name'],
					$above['name']
				);
			}
		}

		return $errors;
	}

	/**
	 * Module melden, die auf einem Sperrbereich sitzen.
	 *
	 * @param array $modules Module.
	 * @param array $blocks  Sperrbereiche des Basismoduls.
	 * @return array
	 */
	protected static function block_errors( array $modules, array $blocks ) {
		$errors = array();

		foreach ( $modules as $module ) {
			foreach ( $blocks as $block ) {
				if ( ! self::hits_block( $module['pos'], $module['height'], $block ) ) {
					continue;
				}

				$label = isset( $block['label'] ) && '' !== (string) $block['label']
					? (string) $block['label']
					: __( 'Wandbefestigung', 'lindenweg16' );

				$errors[] = sprintf(
					/* translators: 1: Bezeichnung des Moduls, 2: Bezeichnung des Sperrbereichs. */
					__( '„%1$s" steht auf dem Bereich „%2$s" – dort wird das Basismodul an die Wand geschraubt.', 'lindenweg16' ),
					$module['name'],
					$label
				);
			}
		}

		return $errors;
	}

	/**
	 * Berührt ein Modul einen Sperrbereich?
	 *
	 * @param float $pos    Position der Unterkante.
	 * @param float $height Höhe des Moduls.
	 * @param array $block  Sperrbereich mit from_mm und to_mm.
	 * @return bool
	 */
	public static function hits_block( $pos, $height, array $block ) {
		$from = (float) $block['from_mm'];
		$to   = (float) $block['to_mm'];

		if ( $to <= $from ) {
			return false;
		}

		return ( $pos < $to - self::EPSILON ) && ( $from < $pos + $height - self::EPSILON );
	}

	/**
	 * Erste freie Position für ein weiteres Modul.
	 *
	 * Gesucht wird von unten nach oben die erste Lücke, in die das Modul
	 * ganz hineinpasst – dieselbe Reihenfolge, in der ein Mensch den Haken
	 * bestücken würde.
	 *
	 * @param float $height  Höhe des neuen Moduls.
	 * @param float $rail    Länge des Basismoduls.
	 * @param array $modules Belegte Module (pos, height).
	 * @param array $blocks  Sperrbereiche.
	 * @return float|null Position oder null, wenn nichts mehr frei ist.
	 */
	public static function first_free_position( $height, $rail, array $modules, array $blocks ) {
		$height = (float) $height;

		if ( $height <= 0 || $height > $rail ) {
			return null;
		}

		// Kandidaten sind der Anfang der Schiene und die Oberkante jedes
		// belegten oder gesperrten Abschnitts. Dazwischen kann keine Lücke
		// beginnen, ohne dass darunter Platz verschenkt wird.
		$candidates = array( 0.0 );

		foreach ( $modules as $module ) {
			$candidates[] = (float) $module['pos'] + (float) $module['height'];
		}

		foreach ( $blocks as $block ) {
			$candidates[] = (float) $block['to_mm'];
		}

		sort( $candidates );

		foreach ( $candidates as $candidate ) {
			if ( $candidate < 0 || $candidate + $height > $rail + self::EPSILON ) {
				continue;
			}

			if ( self::is_free( $candidate, $height, $modules, $blocks ) ) {
				return round( $candidate, 2 );
			}
		}

		return null;
	}

	/**
	 * Ist ein Abschnitt frei?
	 *
	 * @param float $pos     Unterkante.
	 * @param float $height  Höhe.
	 * @param array $modules Belegte Module.
	 * @param array $blocks  Sperrbereiche.
	 * @return bool
	 */
	public static function is_free( $pos, $height, array $modules, array $blocks ) {
		foreach ( $modules as $module ) {
			$other_pos = (float) $module['pos'];
			$other_top = $other_pos + (float) $module['height'];

			if ( $pos < $other_top - self::EPSILON && $other_pos < $pos + $height - self::EPSILON ) {
				return false;
			}
		}

		foreach ( $blocks as $block ) {
			if ( self::hits_block( $pos, $height, $block ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Millimeterangabe ohne überflüssige Nullen.
	 *
	 * @param float $value Wert.
	 * @return string
	 */
	public static function format_mm( $value ) {
		$value = round( (float) $value, 1 );

		return rtrim( rtrim( number_format( $value, 1, ',', '' ), '0' ), ',' );
	}

	/**
	 * Die Konfiguration als Klartext, wie sie an der Bestellposition steht.
	 *
	 * @param array $modules Geprüfte Module.
	 * @return array Zeilen.
	 */
	public static function describe( array $modules ) {
		$lines = array();

		foreach ( $modules as $module ) {
			$lines[] = sprintf(
				/* translators: 1: Bezeichnung des Moduls, 2: Position in Millimetern. */
				__( '%1$s bei %2$s mm', 'lindenweg16' ),
				$module['name'],
				self::format_mm( $module['pos'] )
			);
		}

		return $lines;
	}
}
