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
			LW16_CAPABILITY,
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
				LW16_CAPABILITY,
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
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		do_action( 'lw16_render_dashboard' );
	}

	/**
	 * Untermenüseiten an das jeweilige Modul delegieren.
	 */
	public function render_page() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		do_action( 'lw16_render_page_' . str_replace( '-', '_', $page ) );
	}
}
