<?php
/**
 * "Druckfarm": eigenständige Übersichtsseite mit den Live-Status-Karten
 * aller Bambu-Lab-Drucker – für die Werkstatt-Anzeige, ohne den Umweg
 * über "Bambu-Lab-Drucker" samt Verwaltungsformularen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;

/**
 * Zeigt exakt dieselben Karten wie BambuPrintersPage (Reiter "Drucker"),
 * nur ohne Formulare zum Anlegen/Bearbeiten von Druckern – reine Anzeige.
 */
class DruckfarmPage {

	const SLUG = 'lw16-betrieb-druckfarm';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_druckfarm', array( $this, 'render' ) );
	}

	/**
	 * Zugriff prüfen - dasselbe Recht wie der Menüpunkt (siehe
	 * BetriebModule::pages()) und wie BambuPrintersPage selbst.
	 *
	 * @param string $cap Benötigte Capability.
	 */
	protected function require_cap( $cap = 'lw16_capture' ) {
		if ( ! Roles::current_user_can_betrieb( $cap ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		echo '<h1>' . esc_html__( 'Druckfarm', 'lindenweg16' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Live-Status aller Bambu-Lab-Drucker – aktualisiert sich automatisch. Zum Verwalten (Drucker anlegen, Zugangsdaten ändern, Aufträge einreihen) siehe „Bambu-Lab-Drucker".', 'lindenweg16' ) . '</p>';

		// Dieselbe Karten-Liste wie im Standard-Reiter von BambuPrintersPage
		// (render_printer_cards()) - eine Instanz reicht, sie hat keinen
		// eigenen Zustand.
		( new BambuPrintersPage() )->render_printer_cards();
	}
}
