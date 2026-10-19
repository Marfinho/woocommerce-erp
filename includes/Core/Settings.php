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
	 * Standardwerte für alle Einstellungen.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
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
			// Marketing.
			'km_pauschale'              => 0.30,
			'marketing_nachlauf_tage'   => 30,
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
			// DHL Versand (Geschäftskundenversand, Labels). Sandbox und Live
			// haben getrennte Zugangsdaten – die DHL-Sandbox weist ein
			// echtes Geschäftskundenportal-Login zurück (invalid_grant) und
			// umgekehrt. Benutzer/Passwort der Sandbox sind ein von DHL
			// öffentlich dokumentierter, fester Testzugang (kein Geheimnis),
			// deshalb hier als Vorbelegung – API-Key und API-Secret sind je
			// Anwendung individuell und müssen selbst eingetragen werden.
			'dhl_versand_sandbox_api_key'    => '',
			'dhl_versand_sandbox_api_secret' => '',
			'dhl_versand_sandbox_user'       => 'user-valid',
			'dhl_versand_sandbox_password'   => 'SandboxPasswort2023!',
			'dhl_versand_live_api_key'       => '',
			'dhl_versand_live_api_secret'    => '',
			'dhl_versand_live_user'          => '',
			'dhl_versand_live_password'      => '',
			'dhl_versand_ekp'           => '',
			'dhl_versand_teilnahme'     => '01',
			'dhl_versand_teilnahme_01'  => '',
			'dhl_versand_teilnahme_62'  => '',
			'dhl_versand_teilnahme_53'  => '',
			'dhl_versand_teilnahme_54'  => '',
			'dhl_versand_teilnahme_66'  => '',
			'dhl_versand_profil'        => 'STANDARD_GRUPPENPROFIL',
			'dhl_versand_printformat'   => '910-300-610',
			'dhl_versand_sandbox'       => 1,
			'dhl_versand_warenpost'     => 0,
			'dhl_versand_vorabmeldung'  => 1,
			'dhl_versand_verpackung_g'  => 150,
			'dhl_versand_land'          => 'DE',
			// DHL Druck-Warteschlange (Raspberry-Pi-Client).
			'dhl_print_automatisch'     => 0,
			'dhl_print_standarddrucker' => 'eg_dhl',
			'dhl_print_aufbewahrung_tage' => 30,
			// Mailbox (IMAP/SMTP-Postfächer der Mitarbeiter).
			'mail_sync_aktiv'           => 1,
			'mail_lookback_tage'        => 90,
			'mail_max_anhang_mb'        => 20,
			// Bambu-Lab-Anbindung (Bridge auf dem Raspberry Pi).
			'bambu_aufbewahrung_tage'   => 90,
		);
	}

	/**
	 * Defaults beim Aktivieren setzen, ohne bestehende Werte zu überschreiben.
	 */
	public static function maybe_set_defaults() {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = wp_parse_args( $current, self::defaults() );
		update_option( self::OPTION, $merged );
	}

	/**
	 * Einzelnen Einstellungswert holen.
	 *
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = get_option( self::OPTION, array() );
		if ( is_array( $all ) && array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		$defaults = self::defaults();
		if ( array_key_exists( $key, $defaults ) ) {
			return $defaults[ $key ];
		}
		return $default;
	}

	/**
	 * Alle Einstellungen als Array.
	 *
	 * @return array
	 */
	public static function all() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * Einstellungen speichern (mit Merge auf Defaults).
	 *
	 * @param array $values Neue Werte.
	 */
	public static function update( array $values ) {
		$merged = wp_parse_args( $values, self::all() );
		update_option( self::OPTION, $merged );
	}
}
