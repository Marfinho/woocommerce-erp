<?php
/**
 * Verbindung der Mailbox mit dem Shop: Kundendaten zur Mail und der
 * Mailverlauf an der Bestellung.
 *
 * Zugriffsgrenze: Es erscheinen nur Mails aus Postfächern, die der
 * angemeldete Nutzer selbst sehen darf – an der Bestellung sieht ein
 * Mitarbeiter also nicht den Schriftverkehr fremder Postfächer.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * Meta-Box „Mails" an der Bestellung und Kundenhinweise im Lesefenster.
 */
class OrderMailsBox {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
	}

	/**
	 * Meta-Box an die Bestellung hängen (klassisch und HPOS).
	 */
	public function add_box() {
		if ( ! MailboxAccess::can_open() ) {
			return;
		}

		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		add_meta_box( 'lw16-order-mails', __( 'Mails', 'lindenweg16' ), array( $this, 'render_box' ), array_unique( $screens ), 'side', 'default' );
	}

	/**
	 * Inhalt der Meta-Box.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Bestellung.
	 */
	public function render_box( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$email    = strtolower( (string) $order->get_billing_email() );
		$accounts = MailRepository::accounts_for_user( get_current_user_id(), MailboxAccess::is_manager() );
		$ids      = array_map( 'intval', array_column( $accounts, 'id' ) );

		if ( '' === $email ) {
			echo '<p>' . esc_html__( 'Keine E-Mail-Adresse an der Bestellung.', 'lindenweg16' ) . '</p>';
			return;
		}

		$messages = MailRepository::messages_for_email( $email, $ids, 10 );

		if ( $messages ) {
			echo '<ul>';
			foreach ( $messages as $m ) {
				$out = $email !== strtolower( (string) $m['from_email'] );
				echo '<li><a href="' . esc_url( MailboxModule::url( array( 'account' => (int) $m['account_id'], 'folder' => $m['folder'], 'msg' => (int) $m['id'] ) ) ) . '">'
					. esc_html( ( $out ? '→ ' : '← ' ) . ( '' !== $m['subject'] ? $m['subject'] : __( '(ohne Betreff)', 'lindenweg16' ) ) ) . '</a>'
					. '<br><span class="description">' . esc_html( MailboxView::fmt_date( $m['sent_at'], true ) ) . '</span></li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'Noch kein Mailverkehr mit dieser Adresse.', 'lindenweg16' ) . '</p>';
		}

		foreach ( $accounts as $a ) {
			if ( MailboxAccess::allows( $a['level'], 'send' ) && ! empty( $a['active'] ) ) {
				$url = MailboxModule::url(
					array(
						'account' => (int) $a['id'],
						'compose' => 'new',
						'to'      => $email,
						/* translators: %s: Bestellnummer */
						'subject' => sprintf( __( 'Deine Bestellung #%s', 'lindenweg16' ), $order->get_order_number() ),
					)
				);
				echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Mail schreiben', 'lindenweg16' ) . '</a></p>';
				break;
			}
		}
	}

	/**
	 * Was der Shop zu einer Mail-Adresse weiß: Kundenkonto, letzte Bestellungen
	 * und in der Betreffzeile genannte Bestellnummern.
	 *
	 * @param string $email   Adresse des Absenders.
	 * @param string $subject Betreff.
	 * @return array Liste aus {text, url}.
	 */
	public static function customer_info( $email, $subject ) {
		if ( ! function_exists( 'wc_get_orders' ) || '' === trim( (string) $email ) ) {
			return array();
		}

		// Bestelldaten sieht nur, wer ohnehin Bestellungen bearbeiten darf.
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( LW16_CAPABILITY ) ) {
			return array();
		}

		$lines = array();
		$seen  = array();

		$user = get_user_by( 'email', $email );
		if ( $user && current_user_can( 'edit_users' ) ) {
			$lines[] = array(
				'text' => sprintf( /* translators: %s: Name */ __( 'Kundenkonto: %s', 'lindenweg16' ), $user->display_name ),
				'url'  => get_edit_user_link( $user->ID ),
			);
		}

		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 5,
				'orderby'       => 'date',
				'order'         => 'DESC',
			)
		);

		// Im Betreff genannte Bestellnummern ("#1234") zuerst.
		if ( preg_match_all( '/#(\d{2,9})/', (string) $subject, $m ) ) {
			foreach ( array_slice( array_unique( $m[1] ), 0, 3 ) as $number ) {
				$order = wc_get_order( (int) $number );
				if ( $order ) {
					array_unshift( $orders, $order );
				}
			}
		}

		foreach ( $orders as $order ) {
			if ( isset( $seen[ $order->get_id() ] ) ) {
				continue;
			}
			$seen[ $order->get_id() ] = true;

			$lines[] = array(
				'text' => sprintf(
					/* translators: 1: Nummer, 2: Datum, 3: Summe, 4: Status */
					__( 'Bestellung #%1$s · %2$s · %3$s · %4$s', 'lindenweg16' ),
					$order->get_order_number(),
					$order->get_date_created() ? wc_format_datetime( $order->get_date_created(), 'd.m.Y' ) : '',
					html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
					wc_get_order_status_name( $order->get_status() )
				),
				'url'  => $order->get_edit_order_url(),
			);
		}

		return $lines;
	}
}
