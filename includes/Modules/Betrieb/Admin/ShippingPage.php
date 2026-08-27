<?php
/**
 * Versand: Bestellungen packen, Lieferschein drucken, Sendung verfolgen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Betrieb\ShippingLabel;
use Lindenweg16\Modules\Production\DhlTracking;

/**
 * Eine Liste der Bestellungen, die raus müssen, mit allem daneben, was
 * dafür nötig ist.
 *
 * Die Aktionen standen bisher unter Lindenweg16 → Produktion, während die
 * Ware, die Picklisten und die Fertigungsaufträge im Betrieb liegen.
 * Wer gepackt hat, musste das Menü wechseln, um denselben Karton zu
 * frankieren.
 */
class ShippingPage {

	const SLUG = 'lw16-betrieb-versand';

	/**
	 * Welche Bestellungen die Ansicht zeigt.
	 */
	const FILTER = array(
		'offen'     => array( 'wc-processing', 'wc-on-hold' ),
		'versendet' => array( 'wc-completed' ),
	);

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_versand', array( $this, 'render' ) );
	}

	/**
	 * Bestellstatus zum gewählten Filter.
	 *
	 * Reine Logik: Ein unbekannter Filter aus der Adresszeile darf nicht
	 * in eine leere Abfrage laufen, sonst sieht die Seite aus, als gäbe es
	 * nichts zu versenden.
	 *
	 * @param string $filter Wert aus der Adresszeile.
	 * @return array Liste der WooCommerce-Status.
	 */
	public static function statuses_for( $filter ) {
		$filter = (string) $filter;

		if ( 'alle' === $filter ) {
			return array_merge( self::FILTER['offen'], self::FILTER['versendet'] );
		}

		return isset( self::FILTER[ $filter ] ) ? self::FILTER[ $filter ] : self::FILTER['offen'];
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_capture' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		echo '<h1>' . esc_html__( 'Versand', 'lindenweg16' ) . '</h1>';

		if ( ! function_exists( 'wc_get_orders' ) ) {
			echo '<p>' . esc_html__( 'WooCommerce ist nicht aktiv – ohne Shop gibt es keine Bestellungen zu versenden.', 'lindenweg16' ) . '</p>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'offen';
		$base   = admin_url( 'admin.php?page=' . self::SLUG );

		$this->render_filter( $filter, $base );
		$this->render_label_hint();

		$orders = wc_get_orders(
			array(
				'status'  => self::statuses_for( $filter ),
				'limit'   => 100,
				'orderby' => 'date',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		if ( ! $orders ) {
			echo '<p>' . esc_html__( 'Keine Bestellung in dieser Ansicht.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<div class="lw16-scrollx"><table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Bestellung', 'lindenweg16' ),
			__( 'Empfänger', 'lindenweg16' ),
			__( 'Inhalt', 'lindenweg16' ),
			__( 'Lieferschein', 'lindenweg16' ),
			__( 'Sendungsnummer', 'lindenweg16' ),
			__( 'Label', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $orders as $order ) {
			$this->render_row( $order );
		}

		echo '</tbody></table></div>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Gezeigt werden die letzten 100 Bestellungen des gewählten Filters, älteste zuerst – die, die am längsten warten, stehen oben.', 'lindenweg16' )
		);
	}

	/**
	 * Umschalter zwischen offenen und versendeten Bestellungen.
	 *
	 * @param string $filter Aktueller Filter.
	 * @param string $base   Grund-URL der Seite.
	 */
	protected function render_filter( $filter, $base ) {
		$filter = isset( self::FILTER[ $filter ] ) || 'alle' === $filter ? $filter : 'offen';

		$ansichten = array(
			'offen'     => __( 'Zu versenden', 'lindenweg16' ),
			'versendet' => __( 'Versendet', 'lindenweg16' ),
			'alle'      => __( 'Alle', 'lindenweg16' ),
		);

		echo '<nav class="lw16-tabs">';
		foreach ( $ansichten as $key => $titel ) {
			printf(
				'<a href="%s" class="%s">%s</a> ',
				esc_url( add_query_arg( 'filter', $key, $base ) ),
				$filter === $key ? 'active' : '',
				esc_html( $titel )
			);
		}
		echo '</nav>';
	}

	/**
	 * Sagen, wer hier das Label erzeugt – und wer nicht.
	 *
	 * Ohne diesen Hinweis sucht man auf der Seite nach einem Knopf, den
	 * es nicht geben kann: Dieses Plugin verfolgt Sendungen, es
	 * frankiert sie nicht.
	 */
	protected function render_label_hint() {
		$aktiv = ShippingLabel::active();

		if ( $aktiv ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: Name des Versandplugins. */
						__( 'Die Labels erzeugt %s. Der Knopf in der Spalte „Label" führt zur Bestellung, wo das Plugin sein Frankierfenster einblendet; die Sendungsnummer holt sich die Verfolgung von dort automatisch ab.', 'lindenweg16' ),
						ShippingLabel::label( $aktiv )
					)
				)
			);

			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'Kein Versandplugin erkannt. Dieses Plugin verfolgt Sendungen, es frankiert sie nicht – ein DHL-Label braucht den Geschäftskundenzugang der DHL. Trage die Sendungsnummer so lange von Hand ein; die Verfolgung läuft damit genauso.', 'lindenweg16' )
		);
	}

	/**
	 * Eine Bestellung als Zeile.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	protected function render_row( $order ) {
		$order_id = $order->get_id();
		$datum    = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd.m.Y' ) : '';

		echo '<tr>';

		printf(
			'<td><a href="%s"><strong>#%s</strong></a><br /><small>%s · %s</small></td>',
			esc_url( $this->order_url( $order_id ) ),
			esc_html( $order->get_order_number() ),
			esc_html( $datum ),
			esc_html( wc_get_order_status_name( $order->get_status() ) )
		);

		$land = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
		printf(
			'<td>%s<br /><small>%s</small></td>',
			esc_html( $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name() ),
			esc_html( trim( $order->get_shipping_postcode() . ' ' . $order->get_shipping_city() . ' ' . $land ) )
		);

		echo '<td>' . esc_html( $this->content_summary( $order ) ) . '</td>';

		// Lieferschein: immer frisch erzeugt, damit eine späte
		// Adressänderung nicht auf dem alten PDF stehen bleibt.
		echo '<td><form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-inline-form" target="_blank">';
		echo '<input type="hidden" name="action" value="lw16_packliste">';
		printf( '<input type="hidden" name="order_id" value="%d">', (int) $order_id );
		printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( 'lw16_packliste_' . $order_id ) ) );
		printf( '<input type="text" name="notiz" placeholder="%s" class="small-text" style="width:110px"> ', esc_attr__( 'Notiz…', 'lindenweg16' ) );
		printf( '<button type="submit" class="button button-small">%s</button>', esc_html__( 'Drucken', 'lindenweg16' ) );
		echo '</form></td>';

		echo '<td>' . $this->tracking_cell( $order ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- in tracking_cell() escaped.

		echo '<td>' . $this->label_cell( $order_id ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- in label_cell() escaped.

		echo '</tr>';
	}

	/**
	 * Kurzfassung des Bestellinhalts.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return string
	 */
	protected function content_summary( $order ) {
		$teile = array();

		foreach ( $order->get_items() as $item ) {
			$teile[] = $item->get_quantity() . '× ' . $item->get_name();
		}

		if ( ! $teile ) {
			return '—';
		}

		// Mehr als drei Positionen sprengen die Zeile; wer sie braucht,
		// klickt auf die Bestellung.
		if ( count( $teile ) > 3 ) {
			$rest  = count( $teile ) - 3;
			$teile = array_slice( $teile, 0, 3 );
			/* translators: %d: Anzahl weiterer Positionen. */
			$teile[] = sprintf( __( '… und %d weitere', 'lindenweg16' ), $rest );
		}

		return implode( ', ', $teile );
	}

	/**
	 * Sendungsnummer eintragen und Stand anzeigen.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return string HTML.
	 */
	protected function tracking_cell( $order ) {
		global $wpdb;

		$order_id = $order->get_id();
		$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DhlTracking::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore
		$nummer   = $row ? $row->tracking_nummer : DhlTracking::extract_tracking_number( $order );

		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-inline-form">';
		$html .= '<input type="hidden" name="action" value="lw16_save_tracking">';
		$html .= sprintf( '<input type="hidden" name="order_id" value="%d">', (int) $order_id );
		$html .= '<input type="hidden" name="zurueck" value="versand">';
		$html .= wp_nonce_field( 'lw16_save_tracking_' . $order_id, '_wpnonce', true, false );
		$html .= sprintf(
			'<input type="text" name="tracking_nummer" value="%s" placeholder="%s" style="width:170px"> ',
			esc_attr( (string) $nummer ),
			esc_attr__( 'Sendungsnummer', 'lindenweg16' )
		);
		$html .= sprintf( '<button type="submit" class="button button-small">%s</button>', esc_html__( 'Speichern', 'lindenweg16' ) );
		$html .= '</form>';

		if ( $row && $row->aktueller_status ) {
			$html .= sprintf( '<div><small>%s</small></div>', esc_html( $row->aktueller_status ) );
		}

		if ( $nummer && Settings::get( 'dhl_tracking_api_key' ) ) {
			$html .= sprintf(
				'<div><a href="%s">%s</a></div>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lw16_refresh_tracking&order_id=' . $order_id . '&zurueck=versand' ), 'lw16_refresh_tracking_' . $order_id ) ),
				esc_html__( 'Stand jetzt abrufen', 'lindenweg16' )
			);
		}

		return $html;
	}

	/**
	 * Label-Spalte.
	 *
	 * @param int $order_id Bestellung.
	 * @return string HTML.
	 */
	protected function label_cell( $order_id ) {
		$aktiv = ShippingLabel::active();

		if ( ! $aktiv ) {
			return '<small>' . esc_html__( 'kein Versandplugin', 'lindenweg16' ) . '</small>';
		}

		return sprintf(
			'<a class="button button-small" href="%s">%s</a>',
			esc_url( $this->order_url( $order_id ) ),
			esc_html__( 'Label erstellen', 'lindenweg16' )
		);
	}

	/**
	 * Adresse der Bestellung – mit und ohne HPOS.
	 *
	 * @param int $order_id Bestellung.
	 * @return string
	 */
	protected function order_url( $order_id ) {
		if ( function_exists( 'wc_get_container' ) ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $order_id );
		}

		return admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
	}
}
