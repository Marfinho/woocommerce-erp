<?php
/**
 * Die fünf Hauptmenüs der Seitenleiste, gemeinsam für alle Module.
 *
 * Vorher hatte praktisch jedes Modul sein eigenes Hauptmenü oder hängte
 * sich als Untermenü an "Betrieb" – 21 Unterpunkte allein dort, dazu ein
 * zweites, unabhängiges Menü "Lindenweg16" mit nochmal zehn. Diese Klasse
 * legt stattdessen fünf fachliche Hauptmenüs an (Verkauf, Fertigung,
 * Lager, Finanzen, Verwaltung); jedes Modul hängt seine Unterseiten mit
 * add_submenu_page() an eines dieser fünf Elternmenüs, statt ein eigenes
 * zu eröffnen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert nur die fünf Eltern-Menüpunkte selbst – die eigentlichen
 * Unterseiten bleiben Sache der jeweiligen Module.
 */
class MenuGroups {

	const VERKAUF     = 'lw16-verkauf';
	const FERTIGUNG    = 'lw16-fertigung';
	const LAGER        = 'lw16-lager-menu';
	const FINANZEN     = 'lw16-finanzen';
	const VERWALTUNG   = 'lw16-verwaltung';

	/**
	 * Hooks registrieren.
	 *
	 * Priorität 5: deutlich vor den Modulen (Standard-Priorität 10), damit
	 * die fünf Elternmenüs existieren, bevor irgendein Modul versucht,
	 * eine Unterseite daran zu hängen.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menus' ), 5 );
	}

	/**
	 * Die fünf Hauptmenüs anlegen.
	 *
	 * Jedes bekommt einen eigenen, harmlosen Platzhalter als Callback für
	 * den Klick auf den Menüpunkt selbst (nicht auf eine Unterseite) –
	 * WordPress verlangt einen, auch wenn niemand ihn absichtlich
	 * aufruft. "Verkauf" ist die Ausnahme: es nutzt bewusst denselben
	 * Slug wie das bisherige "Betrieb"-Dashboard, das damit unverändert
	 * die erste/Standardseite bleibt.
	 */
	public function add_menus() {
		add_menu_page(
			__( 'Verkauf', 'lindenweg16' ),
			__( 'Verkauf', 'lindenweg16' ),
			'lw16_capture',
			self::VERKAUF,
			array( $this, 'render_placeholder' ),
			'dashicons-store',
			56
		);

		add_menu_page(
			__( 'Fertigung', 'lindenweg16' ),
			__( 'Fertigung', 'lindenweg16' ),
			'lw16_capture',
			self::FERTIGUNG,
			array( $this, 'render_placeholder' ),
			'dashicons-hammer',
			57
		);

		add_menu_page(
			__( 'Lager', 'lindenweg16' ),
			__( 'Lager', 'lindenweg16' ),
			'lw16_capture',
			self::LAGER,
			array( $this, 'render_placeholder' ),
			'dashicons-archive',
			58
		);

		add_menu_page(
			__( 'Finanzen', 'lindenweg16' ),
			__( 'Finanzen', 'lindenweg16' ),
			'lw16_capture',
			self::FINANZEN,
			array( $this, 'render_placeholder' ),
			'dashicons-chart-area',
			59
		);

		add_menu_page(
			__( 'Verwaltung', 'lindenweg16' ),
			__( 'Verwaltung', 'lindenweg16' ),
			'lw16_capture',
			self::VERWALTUNG,
			array( $this, 'render_placeholder' ),
			'dashicons-admin-generic',
			60
		);
	}

	/**
	 * Wird nur erreicht, wenn jemand exakt auf den Hauptmenüpunkt klickt
	 * statt auf eine der echten Unterseiten – leitet auf die erste
	 * Unterseite des jeweiligen Menüs weiter, statt eine leere Seite zu
	 * zeigen.
	 */
	public function render_placeholder() {
		global $submenu;

		// phpcs:ignore WordPress.Security.NonceVerification
		$parent = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( isset( $submenu[ $parent ] ) && is_array( $submenu[ $parent ] ) ) {
			foreach ( $submenu[ $parent ] as $eintrag ) {
				$ziel = isset( $eintrag[2] ) ? $eintrag[2] : '';

				// WordPress hängt an add_menu_page() automatisch einen
				// ersten Untermenüpunkt mit demselben Titel UND Slug wie
				// der Elternmenüpunkt selbst an (hier sichtbar als
				// doppeltes "Fertigung"/"Lager"/... in der Seitenleiste) -
				// außer man überschreibt ihn explizit, wie "Verkauf" es
				// bewusst tut (siehe Kommentar oben bei add_menus()). Ohne
				// diesen Filter würde genau dieser Auto-Eintrag als erstes
				// gefunden und die Umleitung ginge zurück auf denselben
				// Elternmenüpunkt - eine Endlosschleife (ERR_TOO_MANY_
				// REDIRECTS im Browser).
				if ( '' !== $ziel && $ziel !== $parent ) {
					wp_safe_redirect( admin_url( 'admin.php?page=' . $ziel ) );
					exit;
				}
			}
		}

		echo '<div class="wrap"><p>' . esc_html__( 'Bitte einen Punkt in der Seitenleiste wählen.', 'lindenweg16' ) . '</p></div>';
	}
}
