<?php
/**
 * Mein Konto – Übersicht: Punkte, Bestellungen, Adressen auf einen Blick.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Retention;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Calc;
use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Loyalty\LoyaltyModule;
use Lindenweg16\Modules\Loyalty\LoyaltyRepository;

/**
 * Ersetzt WooCommerces Text-Dashboard („Von deinem Konto aus kannst du …“)
 * durch eine Übersicht. Die Prämienpunkte stehen oben: Sie sind der Grund,
 * wiederzukommen. Das Aussehen liefert das Theme (Abschnitt „Mein Konto –
 * Übersicht“ in theme.css).
 */
class AccountOverview {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'wc_get_template', array( $this, 'template' ), 10, 2 );
	}

	/**
	 * Das Dashboard-Template auf die eigene Übersicht umbiegen.
	 *
	 * @param string $template      Gefundener Pfad.
	 * @param string $template_name Template-Name.
	 * @return string
	 */
	public function template( $template, $template_name ) {
		if ( 'myaccount/dashboard.php' === $template_name ) {
			return __DIR__ . '/views/account-dashboard.php';
		}
		return $template;
	}

	/**
	 * Übersicht ausgeben.
	 */
	public static function render() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$customer = new \WC_Customer( $user_id );
		$first    = $customer->get_first_name() ? $customer->get_first_name() : $customer->get_display_name();
		$since    = $customer->get_date_created();

		echo '<div class="lw16-konto">';

		echo '<header class="lw16-konto__head">';
		/* translators: %s: Vorname */
		echo '<h2>' . esc_html( sprintf( __( 'Moin, %s.', 'lindenweg16' ), $first ) ) . '</h2>';
		if ( $since ) {
			/* translators: %s: Monat und Jahr */
			echo '<p>' . esc_html( sprintf( __( 'Dabei seit %s', 'lindenweg16' ), date_i18n( 'F Y', $since->getTimestamp() ) ) ) . '</p>';
		}
		echo '</header>';

		self::render_points( $user_id );
		self::render_stats( $user_id );
		self::render_last_order( $user_id );
		self::render_addresses( $customer );

		echo '</div>';
	}

	/**
	 * Prämienkarte: Punktestand, Gegenwert, Fortschritt, wie man sammelt.
	 *
	 * @param int $user_id Kunde.
	 */
	protected static function render_points( $user_id ) {
		$points  = LoyaltyRepository::get_points( $user_id );
		$pending = LoyaltyRepository::pending_points( $user_id );
		$step    = max( 1, (int) Settings::get( 'punkte_einloesewert', 100 ) );
		$euro    = Calc::points_to_euro( $points, $step );
		$rest    = $points % $step;
		$percent = (int) round( $rest / $step * 100 );

		echo '<section class="lw16-konto__punkte" aria-labelledby="lw16-konto-punkte">';
		echo '<div class="lw16-konto__punkte-stand">';
		echo '<p class="lw16-konto__eyebrow" id="lw16-konto-punkte">' . esc_html__( 'Deine Prämienpunkte', 'lindenweg16' ) . '</p>';
		echo '<p class="lw16-konto__zahl">' . esc_html( number_format_i18n( $points ) ) . '</p>';
		if ( $euro > 0 ) {
			/* translators: %s: Betrag */
			echo '<p class="lw16-konto__wert">' . esc_html( sprintf( __( 'Das sind %s Rabatt – einlösbar an der Kasse.', 'lindenweg16' ), self::money( $euro ) ) ) . '</p>';
		} else {
			echo '<p class="lw16-konto__wert">' . esc_html__( 'Mit jeder Bestellung sammelst du Punkte und sparst beim nächsten Einkauf.', 'lindenweg16' ) . '</p>';
		}

		echo '<div class="lw16-konto__bar" role="progressbar" aria-valuemin="0" aria-valuemax="' . (int) $step . '" aria-valuenow="' . (int) $rest . '" aria-label="' . esc_attr__( 'Punkte bis zum nächsten Euro Rabatt', 'lindenweg16' ) . '"><i style="width:' . (int) $percent . '%"></i></div>';
		/* translators: %s: Punkte */
		echo '<p class="lw16-konto__klein">' . esc_html( sprintf( __( 'Noch %s Punkte bis zum nächsten vollen Euro Rabatt', 'lindenweg16' ), number_format_i18n( $step - $rest ) ) ) . '</p>';

		if ( $pending['points'] > 0 ) {
			$when = $pending['next_at'] ? date_i18n( 'j. F', strtotime( get_date_from_gmt( $pending['next_at'] ) ) ) : '';
			echo '<p class="lw16-konto__unterwegs">';
			echo $when
				/* translators: 1: Punkte, 2: Datum */
				? esc_html( sprintf( __( '+%1$s Punkte sind unterwegs – die ersten werden am %2$s frei.', 'lindenweg16' ), number_format_i18n( $pending['points'] ), $when ) )
				/* translators: %s: Punkte */
				: esc_html( sprintf( __( '+%s Punkte sind unterwegs.', 'lindenweg16' ), number_format_i18n( $pending['points'] ) ) );
			echo '</p>';
		}
		echo '</div>';

		$per_euro = (float) Settings::get( 'punkte_pro_euro', 1 );
		$bonus    = (int) Settings::get( 'review_bonus_punkte', 20 );
		echo '<div class="lw16-konto__sammeln">';
		echo '<p class="lw16-konto__eyebrow">' . esc_html__( 'So sammelst du', 'lindenweg16' ) . '</p>';
		echo '<ul>';
		/* translators: %s: Punkte je Euro */
		echo '<li><b>' . esc_html( sprintf( _n( '%s Punkt', '%s Punkte', (int) ceil( $per_euro ), 'lindenweg16' ), number_format_i18n( $per_euro, floor( $per_euro ) === $per_euro ? 0 : 1 ) ) ) . '</b> ' . esc_html__( 'für jeden Euro Einkauf', 'lindenweg16' ) . '</li>';
		if ( $bonus > 0 ) {
			/* translators: %s: Bonuspunkte */
			echo '<li><b>' . esc_html( sprintf( __( '+%s Punkte', 'lindenweg16' ), number_format_i18n( $bonus ) ) ) . '</b> ' . esc_html__( 'für jede Produktbewertung', 'lindenweg16' ) . '</li>';
		}
		echo '<li><b>' . esc_html__( 'Guthaben', 'lindenweg16' ) . '</b> ' . esc_html__( 'wenn Freunde über deinen Link bestellen', 'lindenweg16' ) . '</li>';
		echo '</ul>';

		$link = home_url( '/?' . LoyaltyModule::COOKIE . '=' . rawurlencode( LoyaltyRepository::get_or_create_code( $user_id ) ) );
		echo '<div class="lw16-copy-row">';
		echo '<label class="screen-reader-text" for="lw16-ref-link">' . esc_html__( 'Dein Empfehlungslink', 'lindenweg16' ) . '</label>';
		echo '<input type="text" readonly value="' . esc_attr( $link ) . '" id="lw16-ref-link" class="lw16-ref-input">';
		echo '<button type="button" class="button lw-soft" id="lw16-copy-btn" data-target="lw16-ref-link">' . esc_html__( 'Link kopieren', 'lindenweg16' ) . '</button>';
		echo '</div>';
		echo '<a class="lw16-konto__mehr" href="' . esc_url( wc_get_account_endpoint_url( 'praemien' ) ) . '">' . esc_html__( 'Punkteverlauf und Empfehlungen', 'lindenweg16' ) . '</a>';
		echo '</div>';

		echo '</section>';
	}

	/**
	 * Kennzahlen: Bestellungen, offene Bestellungen, Guthaben, Wunschliste.
	 *
	 * @param int $user_id Kunde.
	 */
	protected static function render_stats( $user_id ) {
		$open = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-pending', 'wc-on-hold', 'wc-processing' ),
				'limit'       => -1,
				'return'      => 'ids',
			)
		);

		$tiles = array(
			array( __( 'Bestellungen', 'lindenweg16' ), number_format_i18n( wc_get_customer_order_count( $user_id ) ), wc_get_account_endpoint_url( 'orders' ) ),
			array( __( 'In Arbeit', 'lindenweg16' ), number_format_i18n( count( $open ) ), wc_get_account_endpoint_url( 'orders' ) ),
			array( __( 'Guthaben', 'lindenweg16' ), self::money( LoyaltyRepository::get_credit( $user_id ) ), wc_get_account_endpoint_url( 'praemien' ) ),
			array( __( 'Wunschliste', 'lindenweg16' ), number_format_i18n( Wishlist::count_for( $user_id ) ), wc_get_account_endpoint_url( Wishlist::ENDPOINT ) ),
		);

		echo '<div class="lw16-konto__stats">';
		foreach ( $tiles as $t ) {
			echo '<a class="lw16-konto__stat" href="' . esc_url( $t[2] ) . '"><span>' . esc_html( $t[0] ) . '</span><b>' . esc_html( $t[1] ) . '</b></a>';
		}
		echo '</div>';
	}

	/**
	 * Letzte Bestellung mit Status, oder der Weg in den Shop.
	 *
	 * @param int $user_id Kunde.
	 */
	protected static function render_last_order( $user_id ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$order = $orders ? $orders[0] : null;

		echo '<section class="lw16-konto__karte">';
		echo '<div class="lw16-konto__karte-kopf"><h3>' . esc_html__( 'Letzte Bestellung', 'lindenweg16' ) . '</h3>';
		if ( $order ) {
			echo '<a href="' . esc_url( wc_get_account_endpoint_url( 'orders' ) ) . '">' . esc_html__( 'Alle Bestellungen', 'lindenweg16' ) . '</a>';
		}
		echo '</div>';

		if ( ! $order ) {
			echo '<div class="lw16-konto__leer"><p>' . esc_html__( 'Noch keine Bestellung – deine erste bringt schon Punkte.', 'lindenweg16' ) . '</p>';
			echo '<a class="button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Zum Shop', 'lindenweg16' ) . '</a></div>';
			echo '</section>';
			return;
		}

		$items = array_values( $order->get_items() );
		$line  = $items ? $items[0]->get_name() : '';
		if ( count( $items ) > 1 ) {
			/* translators: %d: weitere Positionen */
			$line .= ' ' . sprintf( _n( '+ %d weiterer Artikel', '+ %d weitere Artikel', count( $items ) - 1, 'lindenweg16' ), count( $items ) - 1 );
		}
		$date = $order->get_date_created();

		echo '<a class="lw16-konto__order" href="' . esc_url( $order->get_view_order_url() ) . '">';
		/* translators: %s: Bestellnummer */
		echo '<span class="lw16-konto__order-main"><b>' . esc_html( sprintf( __( 'Bestellung #%s', 'lindenweg16' ), $order->get_order_number() ) ) . '</b>';
		echo '<small>' . esc_html( trim( ( $date ? date_i18n( 'j. F Y', $date->getTimestamp() ) . ' · ' : '' ) . $line ) ) . '</small></span>';
		echo '<span class="lw16-konto__status is-' . esc_attr( $order->get_status() ) . '">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span>';
		echo '<span class="lw16-konto__betrag">' . wp_kses_post( $order->get_formatted_order_total() ) . '</span>';
		echo '</a>';
		echo '</section>';
	}

	/**
	 * Rechnungs- und Lieferadresse.
	 *
	 * @param \WC_Customer $customer Kunde.
	 */
	protected static function render_addresses( $customer ) {
		$blocks = array(
			'billing'  => array( __( 'Rechnungsadresse', 'lindenweg16' ), $customer->get_billing() ),
			'shipping' => array( __( 'Lieferadresse', 'lindenweg16' ), $customer->get_shipping() ),
		);
		if ( wc_ship_to_billing_address_only() || ! wc_shipping_enabled() ) {
			unset( $blocks['shipping'] );
		}

		echo '<div class="lw16-konto__adressen">';
		foreach ( $blocks as $type => $b ) {
			$formatted = WC()->countries->get_formatted_address( $b[1] );
			echo '<section class="lw16-konto__karte">';
			echo '<div class="lw16-konto__karte-kopf"><h3>' . esc_html( $b[0] ) . '</h3>';
			echo '<a class="lw16-konto__pill" href="' . esc_url( wc_get_endpoint_url( 'edit-address', $type ) ) . '">' . esc_html( $formatted ? __( 'Bearbeiten', 'lindenweg16' ) : __( 'Hinzufügen', 'lindenweg16' ) ) . '</a></div>';
			echo $formatted
				? '<address>' . wp_kses_post( $formatted ) . '</address>'
				: '<p class="lw16-konto__klein">' . esc_html__( 'Noch nicht hinterlegt.', 'lindenweg16' ) . '</p>';
			echo '</section>';
		}
		echo '</div>';
	}

	/**
	 * Betrag ohne HTML (für Fließtext und Kacheln).
	 *
	 * @param float $amount Betrag.
	 * @return string
	 */
	protected static function money( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
}
