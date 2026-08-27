<?php
/**
 * Farbsystem.
 *
 * Entscheidung: Die Farbe ist ein globales WooCommerce-Produktattribut
 * (Standard-Taxonomie `pa_farbe`), das als Variantenattribut verwendet wird.
 * Gründe:
 *
 * 1. Die Farbe muss ohnehin im Shop sichtbar sein. Eine zweite, modul-eigene
 *    Farbliste würde von der des Shops abdriften – und zwar genau dann, wenn
 *    eine Farbe hinzukommt.
 * 2. Als globales Attribut ist „anthrazit" ein Begriff für alle Produkte,
 *    nicht je Produkt neu getippt. Tippfehler erzeugen keine zweite Farbe.
 * 3. Variantenattribute landen an der Bestellposition. Beim Lagerabgang ist
 *    die Farbe damit auslesbar, ohne raten zu müssen.
 * 4. Es funktioniert schon mit einer einzigen Farbe: Solange es keine
 *    Varianten gibt, greift die eingestellte Standardfarbe. Eine zweite Farbe
 *    ist später ein neuer Begriff, keine Umstellung.
 *
 * Gespeichert wird intern immer der **Slug** (z. B. `anthrazit`), nie der
 * Anzeigename. Der Anzeigename kann sich ändern, ohne dass alte Buchungen
 * ihre Zuordnung verlieren.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Liest die Farben aus WooCommerce und ordnet Bestellpositionen eine zu.
 */
class ColorRegistry {

	/**
	 * Slug für Positionen, deren Farbe sich nicht bestimmen ließ.
	 *
	 * Solche Bewegungen werden gebucht und gemeldet, nie verworfen: Ein
	 * fehlender Bestand fällt später auf, eine verschwundene Buchung nicht.
	 */
	const UNRESOLVED = 'ungeklaert';

	/**
	 * Taxonomie des Farbattributs.
	 *
	 * @return string
	 */
	public static function taxonomy() {
		$slug = (string) Settings::get( 'betrieb_color_attribute', 'pa_farbe' );
		return '' === $slug ? 'pa_farbe' : $slug;
	}

	/**
	 * Standardfarbe, solange eine Position keine eigene trägt.
	 *
	 * @return string
	 */
	public static function default_color() {
		$slug = self::normalize( (string) Settings::get( 'betrieb_default_color', 'anthrazit' ) );
		return '' === $slug ? 'anthrazit' : $slug;
	}

	/**
	 * Alle bekannten Farben als Slug => Anzeigename.
	 *
	 * Quelle sind die Begriffe des Farbattributs. Fehlt das Attribut noch,
	 * liefert die Liste die Standardfarbe – das Modul bleibt benutzbar,
	 * bevor der Shop Varianten hat.
	 *
	 * @return array
	 */
	public static function all() {
		// Die Begriffe des Farbattributs holt sonst jeder Aufrufer neu.
		// Innerhalb eines Aufrufs sind sie dieselben, und die Liste wird
		// beim Aufbau des Katalogs je Teil gebraucht.
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$taxonomy = self::taxonomy();
		$colors   = array();

		if ( taxonomy_exists( $taxonomy ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$colors[ $term->slug ] = $term->name;
				}
			}
		}

		if ( ! $colors ) {
			$default            = self::default_color();
			$colors[ $default ] = ucfirst( str_replace( '-', ' ', $default ) );
		}

		$cache = $colors;

		return $cache;
	}

	/**
	 * Anzeigename einer Farbe.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function label( $slug ) {
		$slug = self::normalize( $slug );

		if ( self::UNRESOLVED === $slug ) {
			return __( 'ungeklärt', 'lindenweg16' );
		}

		$all = self::all();
		if ( isset( $all[ $slug ] ) ) {
			return $all[ $slug ];
		}

		// Farbe aus einer alten Buchung, die es im Shop nicht mehr gibt.
		return ucfirst( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * Rohwert auf einen Slug normalisieren.
	 *
	 * Akzeptiert Slug und Anzeigename gleichermaßen, damit ein manuell
	 * getippter Wert nicht neben dem Attributbegriff als zweite Farbe landet.
	 * Reine Logik, ohne WordPress-Abfragen.
	 *
	 * @param string $raw Rohwert.
	 * @return string
	 */
	public static function normalize( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		$map = array(
			'ä' => 'ae',
			'ö' => 'oe',
			'ü' => 'ue',
			'Ä' => 'ae',
			'Ö' => 'oe',
			'Ü' => 'ue',
			'ß' => 'ss',
		);
		$raw = strtr( $raw, $map );
		$raw = strtolower( $raw );
		$raw = preg_replace( '/[^a-z0-9]+/', '-', $raw );

		return trim( (string) $raw, '-' );
	}

	/**
	 * Aus mehreren Kandidaten die Farbe bestimmen – reine Logik.
	 *
	 * Der erste nicht leere Kandidat gewinnt. Bleibt keiner übrig, greift die
	 * Standardfarbe; fehlt auch die, wird die Position als ungeklärt gebucht.
	 *
	 * @param array  $candidates Rohwerte in absteigender Verlässlichkeit.
	 * @param string $default    Standardfarbe.
	 * @return array {color, resolved_from, is_default}
	 */
	public static function resolve( array $candidates, $default = '' ) {
		foreach ( $candidates as $source => $value ) {
			$slug = self::normalize( $value );
			if ( '' !== $slug ) {
				return array(
					'color'         => $slug,
					'resolved_from' => is_string( $source ) ? $source : 'kandidat',
					'is_default'    => false,
				);
			}
		}

		$fallback = self::normalize( $default );
		if ( '' !== $fallback ) {
			return array(
				'color'         => $fallback,
				'resolved_from' => 'standard',
				'is_default'    => true,
			);
		}

		return array(
			'color'         => self::UNRESOLVED,
			'resolved_from' => 'keine',
			'is_default'    => false,
		);
	}

	/**
	 * Farbe einer WooCommerce-Bestellposition bestimmen.
	 *
	 * Reihenfolge: Variantenattribut an der Position, dann das Attribut des
	 * Produkts (nur wenn es eindeutig ist), dann die Standardfarbe.
	 *
	 * @param \WC_Order_Item_Product $item Bestellposition.
	 * @return array Ergebnis von resolve().
	 */
	public static function resolve_order_item( $item ) {
		$taxonomy   = self::taxonomy();
		$candidates = array();

		// 1. Variantenattribut an der Bestellposition.
		if ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
			$candidates['variante'] = (string) $item->get_meta( $taxonomy );

			// Ältere Bestellungen speichern den Anzeigenamen als Meta.
			if ( '' === $candidates['variante'] ) {
				$candidates['variante'] = (string) $item->get_meta( wc_attribute_label( $taxonomy ) );
			}
		}

		// 2. Eindeutiges Produktattribut.
		if ( is_object( $item ) && method_exists( $item, 'get_product' ) ) {
			$product = $item->get_product();
			if ( $product ) {
				$terms = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'slugs' ) );
				if ( is_array( $terms ) && 1 === count( $terms ) ) {
					$candidates['produkt'] = (string) $terms[0];
				}
			}
		}

		return self::resolve( $candidates, self::default_color() );
	}

	/**
	 * Auswahlfeld-Optionen inklusive des Eintrags für ungeklärt.
	 *
	 * @param bool $with_unresolved Eintrag „ungeklärt" anhängen.
	 * @return array
	 */
	public static function options( $with_unresolved = false ) {
		$options = self::all();

		if ( $with_unresolved ) {
			$options[ self::UNRESOLVED ] = __( 'ungeklärt', 'lindenweg16' );
		}

		return $options;
	}
}
