<?php
/**
 * Zentrale Plugin-Einstellungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Kapselt alle Plugin-Einstellungen in einer Option und liefert Defaults.
 */
class Settings {

	const OPTION = 'lw16_settings';

	/**
	 * Zusammengefuehrte Werte fuer die Dauer eines Aufrufs.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Standardwerte für alle Einstellungen.
	 *
	 * @return array
	 */
	public static function defaults() {
		// Die Vorgaben ziehen vier Werte aus den Seiteninformationen. Sie
		// aendern sich innerhalb eines Aufrufs nicht, und `get()` wird an
		// vielen Stellen gebraucht – ohne diesen Merker liefe der Aufbau
		// pro Aufruf erneut.
		static $defaults = null;

		if ( null !== $defaults ) {
			return $defaults;
		}

		$defaults = array(
			// Firmendaten für Rechnungen / EÜR.
			'firma_name'                => get_bloginfo( 'name' ),
			'firma_strasse'             => '',
			'firma_plz_ort'             => '',
			'firma_steuernummer'        => '',
			'firma_email'               => get_bloginfo( 'admin_email' ),
			'firma_telefon'             => '',
			'firma_iban'                => '',
			'firma_bic'                 => '',
			// Buchhaltung.
			'kleinunternehmer'          => 1,
			'steuersatz_ruecklage'      => 25,
			'rechnungsnummer_modus'     => 'jahr', // jahr | durchlaufend.
			// Loyalty.
			'referral_cookie_tage'      => 30,
			'referral_prozent'          => 5,
			'referral_margin_fallback'  => 40,
			'referral_wartezeit_tage'   => 14,
			'referral_review_schwelle'  => 25, // EUR Reward, ab dem Review greift.
			'referral_schnellkauf_sek'  => 60,
			'punkte_pro_euro'           => 1,
			'punkte_einloesewert'       => 100, // Punkte für 1 EUR.
			'punkte_wartezeit_tage'     => 14,
			// Kundenbindung.
			'cart_erinnerung_1_stunden' => 3,
			'cart_erinnerung_2_stunden' => 48,
			'postpurchase_danke_tage'   => 2,
			'postpurchase_review_tage'  => 12,
			'review_bonus_punkte'       => 20,
			'review_min_zeichen'        => 15,
			// Betrieb.
			'betrieb_color_attribute'   => 'pa_farbe',
			'betrieb_default_color'     => 'anthrazit',
			'betrieb_lagerhoheit'       => 1,
			'betrieb_wochenmail'        => 1,
			'betrieb_reorder_layout'    => 'theme',
			'gebuehren_automatisch'     => 1,
			// Reporting.
			'digest_wochentag'          => 1, // Montag.
			'export_email'              => get_bloginfo( 'admin_email' ),
			// Corporate Identity.
			'ci_logo_id'                => 0,
			'ci_farbe'                  => '#8B6914',
			// E-Mail.
			'email_absender_name'       => get_bloginfo( 'name' ),
			'email_footer'              => '',
			// DHL Tracking.
			'dhl_tracking_api_key'      => '',
			'dhl_tracking_sandbox'      => 1,
		);

		return $defaults;
	}

	/**
	 * Defaults beim Aktivieren setzen, ohne bestehende Werte zu überschreiben.
	 */
	public static function maybe_set_defaults() {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = $current + self::defaults();
		update_option( self::OPTION, $merged );
		self::$cache = $merged;
	}

	/**
	 * Einzelnen Einstellungswert holen.
	 *
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Alle Einstellungen als Array.
	 *
	 * @return array
	 */
	public static function all() {
		// Zusammengefuehrt wird einmal je Aufruf. `get_option` selbst ist
		// zwar gepuffert, `wp_parse_args` gegen ueber vierzig Vorgaben aber
		// nicht umsonst – und `get()` faellt an manchen Stellen dutzendfach
		// an, etwa im Warenkorb.
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Gespeicherte Werte gewinnen, fehlende kommen aus den Vorgaben.
		// `wp_parse_args` macht bei zwei Feldern nichts anderes, geht dafür
		// aber über eine Fallunterscheidung und `array_merge`.
		self::$cache = $stored + self::defaults();

		return self::$cache;
	}

	/**
	 * Den Merker verwerfen. Noetig, sobald jemand die Werte schreibt.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Einstellungen speichern (mit Merge auf Defaults).
	 *
	 * @param array $values Neue Werte.
	 */
	public static function update( array $values ) {
		$merged = $values + self::all();
		update_option( self::OPTION, $merged );
		self::$cache = $merged;
	}
}
