<?php
/**
 * Modul 6: Kundenbindung (Sammelmodul).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert Warenkorbabbrecher, Wunschliste, Post-Purchase-Sequenz und
 * die „Wieder verfügbar"-Warteliste und liefert den Meistgewünscht-Report.
 */
class RetentionModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		( new Waitlist() )->register();
		( new Wishlist() )->register();
		( new CartTracking() )->register();
		( new PostPurchase() )->register();

		add_action( 'lw16_dashboard_widgets', array( $this, 'render_most_wished_widget' ) );
	}

	/**
	 * Dashboard-Widget: meistgewünschte Produkte als Nachfragesignal.
	 */
	public function render_most_wished_widget() {
		$data = Wishlist::most_wished( 10 );
		if ( empty( $data ) ) {
			return;
		}
		echo '<div class="lw16-card"><h2>' . esc_html__( 'Meistgewünschte Produkte', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Produkt', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Merkungen', 'lindenweg16' ) . '</th></tr></thead><tbody>';
		foreach ( $data as $pid => $anzahl ) {
			$wc = wc_get_product( $pid );
			echo '<tr><td>' . esc_html( $wc ? $wc->get_name() : '#' . $pid ) . '</td><td>' . (int) $anzahl . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="lw16-hint">' . esc_html__( 'Ergänzt die Bestellhistorie als Nachfragesignal für die Mengenvorschläge.', 'lindenweg16' ) . '</p></div>';
	}
}
