<?php
/**
 * Admin-Menü „Lindenweg16" mit Untermenüs für alle Module.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;

/**
 * Registriert die (vormals eigenständigen) "Lindenweg16"-Unterseiten als
 * Untermenüs der fünf gemeinsamen Hauptmenüs aus MenuGroups, statt ein
 * eigenes Hauptmenü zu eröffnen.
 */
class Menu {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Priorität 10 (Standard): MenuGroups (Priorität 5) hat die
		// Elternmenüs zu diesem Zeitpunkt schon angelegt.
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Recht je Seite. Vollzugriff (manage_lw16) schließt alle ein, siehe
	 * Roles::grant_module_caps().
	 *
	 * @return array Seitenkennung => Recht.
	 */
	public static function page_caps() {
		return array(
			LW16_MENU_SLUG       => 'lw16_mod_finanzen',
			'lw16-buchhaltung'   => 'lw16_mod_finanzen',
			'lw16-reporting'     => 'lw16_mod_reporting',
			'lw16-loyalty'       => 'lw16_mod_loyalty',
			'lw16-kasse'         => 'lw16_mod_kasse',
			'lw16-marketing'     => 'lw16_mod_marketing',
			'lw16-produktion'    => 'lw16_mod_produktion',
			'lw16-support'       => 'lw16_mod_support',
			'lw16-anleitungen'   => 'lw16_mod_anleitungen',
		);
	}

	/**
	 * Menüstruktur aufbauen.
	 *
	 * Jeder Eintrag: Slug => [Titel, Elternmenü aus MenuGroups].
	 */
	public function add_menu() {
		// Eigener Callback (render_dashboard, nicht render_page) - feuert
		// lw16_render_dashboard statt des generischen lw16_render_page_*,
		// genau wie zuvor als Klick auf das (jetzt entfallene) eigene
		// Hauptmenü.
		add_submenu_page(
			MenuGroups::FINANZEN,
			__( 'Dashboard: Finanzen', 'lindenweg16' ),
			__( 'Dashboard: Finanzen', 'lindenweg16' ),
			'lw16_mod_finanzen',
			LW16_MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		$pages = array(
			'lw16-buchhaltung'   => array( __( 'Buchhaltung', 'lindenweg16' ), MenuGroups::FINANZEN ),
			'lw16-reporting'     => array( __( 'Reporting', 'lindenweg16' ), MenuGroups::FINANZEN ),
			'lw16-loyalty'       => array( __( 'Loyalty', 'lindenweg16' ), MenuGroups::VERKAUF ),
			'lw16-kasse'         => array( __( 'Kasse', 'lindenweg16' ), MenuGroups::VERKAUF ),
			'lw16-marketing'     => array( __( 'Marketing', 'lindenweg16' ), MenuGroups::VERKAUF ),
			'lw16-produktion'    => array( __( 'Produktion', 'lindenweg16' ), MenuGroups::FERTIGUNG ),
			'lw16-support'       => array( __( 'Support', 'lindenweg16' ), MenuGroups::VERWALTUNG ),
			'lw16-anleitungen'   => array( __( 'Anleitungen', 'lindenweg16' ), MenuGroups::VERWALTUNG ),
			// "Einstellungen" hat keinen eigenen Eintrag mehr - ist jetzt
			// der Reiter "Plugin" auf der gemeinsamen Einstellungen-Seite
			// unter Betrieb (Lindenweg16\Admin\SettingsPage::render_content()
			// wird von dort direkt aufgerufen).
		);

		foreach ( $pages as $slug => $config ) {
			add_submenu_page(
				$config[1],
				$config[0],
				$config[0],
				self::page_caps()[ $slug ],
				$slug,
				array( $this, 'render_page' )
			);
		}

		// Dashboard/Einstellungen laufen über eigene register()-Aufrufe
		// (Dashboard-Klasse, SettingsPage) - hier nur die schlichten
		// Modul-Seiten ohne eigene Klasse.

		// Das Zurücksetzen vor dem Livegang loescht Bewegungsdaten
		// unwiderruflich. Es erscheint deshalb nur fuer Benutzer, die die
		// Website ohnehin verwalten duerfen - einen Menuepunkt anzuzeigen,
		// den man nicht benutzen darf, waere nur verwirrend.
		if ( current_user_can( 'manage_options' ) ) {
			add_submenu_page(
				MenuGroups::VERWALTUNG,
				__( 'Livegang', 'lindenweg16' ),
				__( 'Livegang', 'lindenweg16' ),
				'manage_options',
				'lw16-livegang',
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * CSS/JS nur auf Plugin-Seiten laden.
	 *
	 * @param string $hook Aktueller Admin-Hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'lw16' ) && false === strpos( (string) $hook, LW16_MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'lw16-admin', LW16_PLUGIN_URL . 'assets/css/admin.css', array(), Assets::version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'lw16-admin', LW16_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), Assets::version( 'assets/js/admin.js' ), true );
		wp_localize_script(
			'lw16-admin',
			'LW16Admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lw16_admin' ),
			)
		);
	}

	/**
	 * Dashboard-Seite (Übersicht + Steuer-Rücklage).
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'lw16_mod_finanzen' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		do_action( 'lw16_render_dashboard' );
	}

	/**
	 * Untermenüseiten an das jeweilige Modul delegieren.
	 */
	public function render_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$caps = self::page_caps();

		// Das Zurücksetzen vor dem Livegang bleibt bei den Seitenverwaltern.
		$needed = isset( $caps[ $page ] ) ? $caps[ $page ] : ( 'lw16-livegang' === $page ? 'manage_options' : LW16_CAPABILITY );

		if ( ! current_user_can( $needed ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		do_action( 'lw16_render_page_' . str_replace( '-', '_', $page ) );
	}
}
