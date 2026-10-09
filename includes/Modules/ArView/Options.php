<?php
/**
 * Einstellungen der AR-Ansicht.
 *
 * Eigene Option statt `lw16_settings`: Das Modul bringt seine Vorgaben
 * selbst mit und braucht die zentrale Einstellungsseite nicht. Wer das
 * Modul entfernt, räumt genau eine Option ab.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\ArView;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen, Vorbelegen und Bereinigen der Einstellungen.
 */
class Options {

	const OPTION = 'lw16_arview';

	/**
	 * Grenzen für die Zahlenfelder.
	 */
	const DAYS_MIN  = 1;
	const DAYS_MAX  = 365;
	const DELAY_MIN = 0;
	const DELAY_MAX = 60;

	/**
	 * Vorgaben.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'   => 1,
			'page_id'   => 0,
			'title'     => __( 'Passt der Haster Haken an deine Wand?', 'lindenweg16' ),
			'text'      => __( 'Sieh ihn dir in Originalgröße mit deiner Handykamera an – ohne App.', 'lindenweg16' ),
			'btn_yes'   => __( 'Ja, zeig mal', 'lindenweg16' ),
			'btn_no'    => __( 'Nein, danke', 'lindenweg16' ),
			'lock_days' => 7,
			'delay'     => 3,
		);
	}

	/**
	 * Alle Einstellungen, Lücken mit Vorgaben gefüllt.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Ein einzelner Wert.
	 *
	 * @param string $key Schlüssel.
	 * @return mixed
	 */
	public static function value( $key ) {
		$all = self::get();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Ist die Funktion eingeschaltet?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) self::value( 'enabled' );
	}

	/**
	 * Eingaben bereinigen und auf die Grenzen zurechtstutzen.
	 *
	 * Leere Texte fallen auf die Vorgabe zurück: Ein Banner ohne Titel oder
	 * mit einem Knopf ohne Beschriftung wäre ein Fehler, den niemand
	 * absichtlich einstellt.
	 *
	 * @param array $input Rohwerte (bereits ent-slasht).
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$out      = array();

		$out['enabled'] = ! empty( $input['enabled'] ) ? 1 : 0;

		// Fehlt der Schlüssel ganz (noch nie gespeichert), gilt die Vorgabe.
		if ( ! array_key_exists( 'enabled', $input ) ) {
			$out['enabled'] = (int) $defaults['enabled'];
		}

		$out['page_id'] = isset( $input['page_id'] ) ? max( 0, (int) $input['page_id'] ) : 0;

		foreach ( array( 'title', 'text', 'btn_yes', 'btn_no' ) as $key ) {
			$value       = isset( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : '';
			$out[ $key ] = '' === $value ? $defaults[ $key ] : $value;
		}

		$out['lock_days'] = self::clamp( isset( $input['lock_days'] ) ? $input['lock_days'] : $defaults['lock_days'], self::DAYS_MIN, self::DAYS_MAX, $defaults['lock_days'] );
		$out['delay']     = self::clamp( isset( $input['delay'] ) ? $input['delay'] : $defaults['delay'], self::DELAY_MIN, self::DELAY_MAX, $defaults['delay'] );

		return $out;
	}

	/**
	 * Ganzzahl in Grenzen; Unsinn ergibt die Vorgabe.
	 *
	 * @param mixed $value   Eingabe.
	 * @param int   $min     Untergrenze.
	 * @param int   $max     Obergrenze.
	 * @param int   $default Vorgabe bei nicht numerischer Eingabe.
	 * @return int
	 */
	public static function clamp( $value, $min, $max, $default ) {
		if ( ! is_numeric( $value ) ) {
			return (int) $default;
		}

		return (int) max( $min, min( $max, (int) round( (float) $value ) ) );
	}

	/**
	 * Adresse der AR-Seite mit Produkt-Parameter.
	 *
	 * @param int $product_id Produkt, 0 für die Seite ohne Auswahl.
	 * @return string Leer, wenn keine veröffentlichte Zielseite gewählt ist.
	 */
	public static function viewer_url( $product_id = 0 ) {
		$page_id = (int) self::value( 'page_id' );

		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}

		$url = get_permalink( $page_id );

		if ( ! $url ) {
			return '';
		}

		return $product_id > 0 ? add_query_arg( 'produkt', (int) $product_id, $url ) : $url;
	}
}
