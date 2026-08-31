<?php
/**
 * Die Konfiguration als kurze Zeichenkette.
 *
 * Ein Kunde soll seinen Haken verschicken können („schau mal, so hätte ich
 * ihn gern"), und der Warenkorb soll nachvollziehbar festhalten, was
 * bestellt wurde. Beides braucht dieselbe Darstellung: kurz genug für eine
 * Adresszeile, lesbar genug, um sie im Zweifel von Hand zu prüfen.
 *
 * Aufbau: `v1.<Basis>.<Farbe>.<Artikel>@<Position>,<Artikel>@<Position>`
 * – Artikelnummern sind die IDs aus dem Artikelstamm, Positionen
 * Millimeter von der Unterkante des Basismoduls, Dezimaltrenner ist der
 * Punkt. Ohne Module endet die Kette nach der Farbe.
 *
 * Entschlüsselt wird streng: Was nicht dem Muster entspricht, ist kein
 * „ungefähr gemeinter" Haken, sondern ein Fehler. Ob die enthaltenen
 * Artikel existieren und der Aufbau zulässig ist, entscheidet danach
 * Layout – der Code prüft nur seine eigene Form.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

/**
 * Wandelt zwischen Konfiguration und Zeichenkette.
 */
class ConfigCodec {

	/**
	 * Kennung der aktuellen Fassung.
	 */
	const VERSION = 'v1';

	/**
	 * Name des Parameters im geteilten Link.
	 */
	const QUERY_VAR = 'haken';

	/**
	 * Konfiguration zu einer Zeichenkette.
	 *
	 * @param array $config base, color, modules[[article,pos]].
	 * @return string Leer, wenn kein Basismodul gewählt ist.
	 */
	public static function encode( array $config ) {
		$base = isset( $config['base'] ) ? (int) $config['base'] : 0;

		if ( $base <= 0 ) {
			return '';
		}

		$color = self::normalize_color( isset( $config['color'] ) ? $config['color'] : '' );
		$parts = array();

		foreach ( (array) ( isset( $config['modules'] ) ? $config['modules'] : array() ) as $module ) {
			$article = isset( $module['article'] ) ? (int) $module['article'] : 0;

			if ( $article <= 0 ) {
				continue;
			}

			$parts[] = $article . '@' . self::number( isset( $module['pos'] ) ? $module['pos'] : 0 );
		}

		$code = self::VERSION . '.' . $base . '.' . $color;

		return $parts ? $code . '.' . implode( ',', $parts ) : $code;
	}

	/**
	 * Zeichenkette zurück in eine Konfiguration.
	 *
	 * @param string $code Code.
	 * @return array|null Konfiguration oder null, wenn die Form nicht stimmt.
	 */
	public static function decode( $code ) {
		$code = trim( (string) $code );

		if ( '' === $code || strlen( $code ) > 2048 ) {
			return null;
		}

		$fields = explode( '.', $code, 4 );

		if ( count( $fields ) < 3 || self::VERSION !== $fields[0] ) {
			return null;
		}

		if ( ! preg_match( '/^[1-9][0-9]{0,17}$/', $fields[1] ) ) {
			return null;
		}

		$color = self::normalize_color( $fields[2] );

		// Streng vergleichen statt still zu bereinigen: Ein Code, aus dem
		// erst durch Wegwerfen von Zeichen ein gültiger wird, war keiner.
		if ( $fields[2] !== $color ) {
			return null;
		}

		$modules = array();
		$list    = isset( $fields[3] ) ? trim( $fields[3] ) : '';

		if ( '' !== $list ) {
			foreach ( explode( ',', $list ) as $entry ) {
				if ( ! preg_match( '/^([1-9][0-9]{0,17})@(0|[1-9][0-9]{0,5})(\.[0-9]{1,2})?$/', $entry, $match ) ) {
					return null;
				}

				$modules[] = array(
					'article' => (int) $match[1],
					'pos'     => (float) ( $match[2] . ( isset( $match[3] ) ? $match[3] : '' ) ),
				);
			}
		}

		if ( count( $modules ) > Layout::MAX_MODULES ) {
			return null;
		}

		return array(
			'base'    => (int) $fields[1],
			'color'   => $color,
			'modules' => $modules,
		);
	}

	/**
	 * Millimeterangabe für den Code: Punkt als Trenner, höchstens zwei
	 * Nachkommastellen, keine Nullen am Ende.
	 *
	 * @param float $value Wert.
	 * @return string
	 */
	protected static function number( $value ) {
		$value = round( max( 0.0, (float) $value ), 2 );
		$text  = number_format( $value, 2, '.', '' );

		return false === strpos( $text, '.' ) ? $text : rtrim( rtrim( $text, '0' ), '.' );
	}

	/**
	 * Farbkennung auf das erlaubte Zeichenvorrat einschränken.
	 *
	 * Gespeichert wird der Slug des Farbattributs. Alles, was dort nicht
	 * vorkommen kann, fliegt raus – der Code darf keine Trennzeichen
	 * transportieren, sonst zerfällt er beim Lesen an der falschen Stelle.
	 *
	 * @param string $color Rohwert.
	 * @return string
	 */
	protected static function normalize_color( $color ) {
		$color = strtolower( trim( (string) $color ) );
		$color = preg_replace( '/[^a-z0-9_-]/', '', $color );

		return substr( (string) $color, 0, 64 );
	}
}
