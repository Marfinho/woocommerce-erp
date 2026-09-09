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
use Lindenweg16\Modules\Betrieb\Dhl\DhlClient;
use Lindenweg16\Modules\Betrieb\Dhl\DhlLabelRepository;
use Lindenweg16\Modules\Betrieb\Dhl\DhlProduct;
use Lindenweg16\Modules\Betrieb\Dhl\DhlShipping;
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

	const NONCE = 'lw16_betrieb_versand';

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
		add_action( 'admin_post_lw16_betrieb_dhl_label', array( $this, 'handle_label' ) );
		add_action( 'admin_post_lw16_betrieb_dhl_void', array( $this, 'handle_void' ) );
		add_action( 'admin_post_lw16_betrieb_dhl_pdf', array( $this, 'handle_pdf' ) );
		add_action( 'admin_post_lw16_betrieb_dhl_test', array( $this, 'handle_test' ) );
	}

	/**
	 * Label erzeugen.
	 */
	public function handle_label() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;

		$ergebnis = DhlShipping::create_label(
			$order_id,
			array(
				'product'  => isset( $_POST['product'] ) ? sanitize_text_field( wp_unslash( $_POST['product'] ) ) : '',
				'weight_g' => isset( $_POST['weight_g'] ) ? (float) wp_unslash( $_POST['weight_g'] ) : 0,
				'bulky'    => ! empty( $_POST['bulky'] ),
			)
		);

		$this->back( $ergebnis['message'], $ergebnis['ok'] ? 'success' : 'error' );
	}

	/**
	 * Nur die Anmeldung prüfen.
	 */
	public function handle_test() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$ergebnis = DhlClient::test();

		$this->back( $ergebnis['message'], $ergebnis['ok'] ? 'success' : 'error' );
	}

	/**
	 * Label stornieren.
	 */
	public function handle_void() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$ergebnis = DhlShipping::void_label( isset( $_POST['label_id'] ) ? (int) $_POST['label_id'] : 0 );

		$this->back( $ergebnis['message'], $ergebnis['ok'] ? 'success' : 'error' );
	}

	/**
	 * Labeldatei ausliefern.
	 *
	 * Die Datei liegt im geschützten Verzeichnis; ohne diesen Umweg wäre
	 * sie entweder öffentlich abrufbar oder gar nicht.
	 */
	public function handle_pdf() {
		$this->require_cap();

		$id = isset( $_GET['label'] ) ? (int) $_GET['label'] : 0;
		check_admin_referer( 'lw16_betrieb_dhl_pdf_' . $id );

		$label = DhlLabelRepository::find( $id );
		$pfad  = $label ? DhlLabelRepository::path( $label->filename ) : '';

		if ( ! $label || ! is_file( $pfad ) ) {
			wp_die( esc_html__( 'Labeldatei nicht gefunden.', 'lindenweg16' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $label->filename . '"' );
		header( 'Content-Length: ' . filesize( $pfad ) );
		readfile( $pfad ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_capture' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Zurück zur Versandliste mit Meldung.
	 *
	 * @param string $msg Meldung.
	 * @param string $typ success oder error.
	 */
	protected function back( $msg, $typ ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::SLUG,
					'lw16_msg' => rawurlencode( $msg ),
					'lw16_typ' => $typ,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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
		$this->require_cap();

		echo '<h1>' . esc_html__( 'Versand', 'lindenweg16' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_msg'] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				// phpcs:ignore WordPress.Security.NonceVerification
				'error' === ( isset( $_GET['lw16_typ'] ) ? sanitize_key( wp_unslash( $_GET['lw16_typ'] ) ) : '' ) ? 'error' : 'success',
				// phpcs:ignore WordPress.Security.NonceVerification
				esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) )
			);
		}

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
		// Der eigene Zugang schlägt jedes fremde Plugin: Nur er erzeugt das
		// Label hier in der Liste, ohne dass jemand die Bestellung öffnet.
		if ( DhlClient::configured() ) {
			if ( DhlClient::sandbox() ) {
				printf(
					'<div class="notice notice-warning inline"><p>%s</p>%s</div>',
					esc_html__( 'DHL-Sandbox aktiv: Angemeldet wird mit dem Demo-Zugang der DHL, gebucht auf ihre Testkundennummer. Die Labels sehen echt aus, sind aber keine gültige Frankierung. Zum Scharfschalten den Haken unter Lindenweg16 → Einstellungen entfernen – dann greifen die eigenen Angaben aus dem Geschäftskundenportal.', 'lindenweg16' ),
					$this->test_form() // phpcs:ignore WordPress.Security.EscapeOutput -- in test_form() escaped.
				);

				return;
			}

			printf(
				'<p>%s</p>',
				$this->test_form() // phpcs:ignore WordPress.Security.EscapeOutput -- in test_form() escaped.
			);

			return;
		}

		$fehlt = DhlClient::missing_credentials();

		if ( count( $fehlt ) < 4 ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p><p><a class="button" href="%s">%s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %s: Liste fehlender Angaben. */
						__( 'Der DHL-Zugang ist angefangen, aber unvollständig – es fehlt: %s.', 'lindenweg16' ),
						implode( ', ', $fehlt )
					)
				),
				esc_url( admin_url( 'admin.php?page=lw16-betrieb-einstellungen&view=plugin' ) ),
				esc_html__( 'Zu den Einstellungen', 'lindenweg16' )
			);

			return;
		}

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
			esc_html__( 'Noch kein DHL-Zugang hinterlegt und kein Versandplugin erkannt. Trage die Zugangsdaten aus dem Geschäftskundenportal unter Lindenweg16 → Einstellungen ein, dann entsteht das Label hier in der Liste. So lange trägst du die Sendungsnummer von Hand ein; die Verfolgung läuft damit genauso.', 'lindenweg16' )
		);
	}

	/**
	 * Knopf „Zugang prüfen".
	 *
	 * Kostet kein Label. Wenn eins nicht entsteht, ist das die erste
	 * Frage: Liegt es am Zugang oder an der Sendung?
	 *
	 * @return string HTML.
	 */
	protected function test_form() {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-inline-form">'
			. '<input type="hidden" name="action" value="lw16_betrieb_dhl_test">'
			. wp_nonce_field( self::NONCE, '_wpnonce', true, false )
			. '<button type="submit" class="button button-small">' . esc_html__( 'DHL-Zugang prüfen', 'lindenweg16' ) . '</button>'
			. '</form>';
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

		echo '<td>' . $this->label_cell( $order ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- in label_cell() escaped.

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
	 * @param \WC_Order $order Bestellung.
	 * @return string HTML.
	 */
	protected function label_cell( $order ) {
		$order_id = $order->get_id();
		$label    = DhlLabelRepository::for_order( $order_id );

		if ( $label ) {
			return $this->label_done_cell( $label );
		}

		if ( DhlClient::configured() ) {
			return $this->label_form_cell( $order );
		}

		$aktiv = ShippingLabel::active();

		if ( ! $aktiv ) {
			return '<small>' . esc_html__( 'kein DHL-Zugang', 'lindenweg16' ) . '</small>';
		}

		return sprintf(
			'<a class="button button-small" href="%s">%s</a>',
			esc_url( $this->order_url( $order_id ) ),
			esc_html__( 'Label erstellen', 'lindenweg16' )
		);
	}

	/**
	 * Ein vorhandenes Label: öffnen oder stornieren.
	 *
	 * @param object $label Datensatz.
	 * @return string HTML.
	 */
	protected function label_done_cell( $label ) {
		$html = sprintf(
			'<a class="button button-small" href="%s" target="_blank">%s</a><br /><small>%s · %s g</small>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=lw16_betrieb_dhl_pdf&label=' . (int) $label->id ),
					'lw16_betrieb_dhl_pdf_' . (int) $label->id
				)
			),
			esc_html__( 'Label öffnen', 'lindenweg16' ),
			esc_html( DhlProduct::label( $label->product ) ),
			esc_html( number_format_i18n( (int) $label->weight_g ) )
		);

		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-inline-form">';
		$html .= '<input type="hidden" name="action" value="lw16_betrieb_dhl_void">';
		$html .= sprintf( '<input type="hidden" name="label_id" value="%d">', (int) $label->id );
		$html .= wp_nonce_field( self::NONCE, '_wpnonce', true, false );
		$html .= sprintf(
			'<button type="submit" class="button-link" style="color:#dc3232" onclick="return confirm(\'%s\')">%s</button>',
			esc_attr__( 'Label stornieren? Die Frankierung wird dann nicht berechnet.', 'lindenweg16' ),
			esc_html__( 'stornieren', 'lindenweg16' )
		);
		$html .= '</form>';

		return $html;
	}

	/**
	 * Noch kein Label: Produkt und Gewicht bestätigen, dann erzeugen.
	 *
	 * Vorbelegt ist beides – aus Zielland und Artikelstamm. Änderbar ist
	 * es trotzdem: Wer sperrige Ware packt, weiß mehr als das Gewicht
	 * verrät, und ein zu leicht frankiertes Paket kostet eine Nachzahlung.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return string HTML.
	 */
	protected function label_form_cell( $order ) {
		$order_id = $order->get_id();

		$land    = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
		$gewicht = DhlShipping::weight_g( $order );
		$produkt = DhlProduct::for_destination( $land, $gewicht, 1 === (int) Settings::get( 'dhl_versand_warenpost', 0 ) );

		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-inline-form">';
		$html .= '<input type="hidden" name="action" value="lw16_betrieb_dhl_label">';
		$html .= sprintf( '<input type="hidden" name="order_id" value="%d">', (int) $order_id );
		$html .= wp_nonce_field( self::NONCE, '_wpnonce', true, false );

		$html .= '<select name="product" style="max-width:160px">';
		foreach ( DhlProduct::all() as $key => $bezeichnung ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $key, $produkt, false ),
				esc_html( $bezeichnung )
			);
		}
		$html .= '</select> ';

		$html .= sprintf(
			'<input type="number" name="weight_g" value="%d" min="1" step="1" style="width:80px" title="%s"> g ',
			(int) round( $gewicht ),
			esc_attr__( 'Gewicht in Gramm, inklusive Verpackungszuschlag', 'lindenweg16' )
		);

		$html .= sprintf(
			'<label style="font-size:11px"><input type="checkbox" name="bulky" value="1"> %s</label> ',
			esc_html__( 'Sperrgut', 'lindenweg16' )
		);

		$html .= sprintf(
			'<button type="submit" class="button button-small button-primary">%s</button>',
			esc_html__( 'Label erzeugen', 'lindenweg16' )
		);
		$html .= '</form>';

		return $html;
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
