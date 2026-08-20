<?php
/**
 * Admin-Menü „Lindenweg16" mit Untermenüs für alle Module.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert das Hauptmenü und die Untermenüseiten in wp-admin.
 */
class Menu {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Menüstruktur aufbauen.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Lindenweg16', 'lindenweg16' ),
			__( 'Lindenweg16', 'lindenweg16' ),
			LW16_CAPABILITY,
			LW16_MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-store',
			56
		);

		$pages = array(
			LW16_MENU_SLUG              => __( 'Dashboard', 'lindenweg16' ),
			'lw16-buchhaltung'         => __( 'Buchhaltung', 'lindenweg16' ),
			'lw16-loyalty'             => __( 'Loyalty', 'lindenweg16' ),
			'lw16-produktion'          => __( 'Produktion', 'lindenweg16' ),
			'lw16-preiskalkulation'    => __( 'Preiskalkulation', 'lindenweg16' ),
			'lw16-kasse'               => __( 'Kasse', 'lindenweg16' ),
			'lw16-support'             => __( 'Support', 'lindenweg16' ),
			'lw16-reporting'           => __( 'Reporting', 'lindenweg16' ),
			'lw16-einstellungen'       => __( 'Einstellungen', 'lindenweg16' ),
		);

		foreach ( $pages as $slug => $title ) {
			add_submenu_page(
				LW16_MENU_SLUG,
				$title,
				$title,
				LW16_CAPABILITY,
				$slug,
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
		wp_enqueue_style( 'lw16-admin', LW16_PLUGIN_URL . 'assets/css/admin.css', array(), LW16_VERSION );
		wp_enqueue_script( 'lw16-admin', LW16_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), LW16_VERSION, true );
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
