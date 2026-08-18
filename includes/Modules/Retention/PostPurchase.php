<?php
/**
 * Post-Purchase-E-Mail-Sequenz mit Bewertungsanfrage.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Retention;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Calc;
use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Time;
use Lindenweg16\Core\Scheduler;
use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Modules\Loyalty\LoyaltyRepository;

/**
 * Dankeschön- und Bewertungsanfrage-Mail über denselben täglichen Cron
 * wie die Loyalty-Reifelogik. Punkte-Bonus nur bei echter Bewertung.
 */
class PostPurchase {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Läuft im täglichen Loyalty-Cron mit.
		add_action( Scheduler::HOOK_DAILY_LOYALTY, array( $this, 'process' ) );
		// Punkte-Bonus, wenn eine echte Produktbewertung eingeht …
		add_action( 'comment_post', array( $this, 'maybe_award_review_bonus' ), 10, 2 );
		// … und nachträglich, sobald eine moderierte Bewertung freigegeben wird.
		add_action( 'transition_comment_status', array( $this, 'on_comment_status_change' ), 10, 3 );
	}

	/**
	 * Fällige Mails verschicken (idempotent per Order-Meta-Flags).
	 */
	public function process() {
		$danke_tage  = (float) Settings::get( 'postpurchase_danke_tage', 2 );
		$review_tage = (float) Settings::get( 'postpurchase_review_tage', 12 );

		// Dankeschön-Mail.
		$this->process_batch( '_lw16_thankyou_sent', $danke_tage, 'thankyou' );
		// Bewertungsanfrage.
		$this->process_batch( '_lw16_review_sent', $review_tage, 'review' );
	}

	/**
	 * Abgeschlossene Bestellungen im Fälligkeitsfenster abarbeiten.
	 *
	 * @param string $flag_meta Meta-Flag gegen Doppelversand.
	 * @param float  $tage      Verzögerung in Tagen.
	 * @param string $typ       thankyou|review.
	 */
	protected function process_batch( $flag_meta, $tage, $typ ) {
		$grenze = time() - $tage * DAY_IN_SECONDS;

		// Stichtag: Bestellungen von *vor* der Plugin-Installation dürfen die
		// Automatik nicht auslösen. Ohne diese Grenze würde der erste
		// Cron-Lauf die komplette Bestellhistorie anmailen.
		$start = get_option( Installer::POSTPURCHASE_START_OPTION );
		$start_ts = $start ? strtotime( $start ) : 0;
		if ( $start_ts && $start_ts > $grenze ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'status'       => array( 'wc-completed' ),
				'limit'        => 50,
				'date_created' => ( $start_ts ? $start_ts : 0 ) . '...' . $grenze,
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => $flag_meta,
						'compare' => 'NOT EXISTS',
					),
				),
				'return'       => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			if ( 'thankyou' === $typ ) {
				$this->send_thankyou( $order );
			} else {
				$this->send_review_request( $order );
			}
			$order->update_meta_data( $flag_meta, Time::now() );
			$order->save();
		}
	}

	/**
	 * Dankeschön-Mail.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	protected function send_thankyou( $order ) {
		$to = $order->get_billing_email();
		if ( ! is_email( $to ) ) {
			return;
		}
		MailTemplate::send( $to, 'danke', array(
			'{vorname}' => $order->get_billing_first_name(),
		) );
	}

	/**
	 * Bewertungsanfrage mit Punkte-Bonus-Hinweis.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	protected function send_review_request( $order ) {
		$to = $order->get_billing_email();
		if ( ! is_email( $to ) ) {
			return;
		}
		$bonus = (int) Settings::get( 'review_bonus_punkte', 20 );

		$links = array();
		foreach ( $order->get_items() as $item ) {
			$permalink = get_permalink( $item->get_product_id() );
			if ( $permalink ) {
				$links[] = $item->get_name() . ': ' . $permalink . '#reviews';
			}
		}

		MailTemplate::send( $to, 'review_anfrage', array(
			'{vorname}'       => $order->get_billing_first_name(),
			'{bonus_punkte}'  => (string) $bonus,
			'{produktlinks}'  => implode( "\n", $links ),
		) );
	}

	/**
	 * Punkte-Bonus vergeben, wenn eine echte Produktbewertung eingeht.
	 *
	 * @param int        $comment_id Kommentar-ID.
	 * @param int|string $approved   Freigabestatus (1 = freigegeben).
	 */
	public function maybe_award_review_bonus( $comment_id, $approved = 1 ) {
		$this->award_review_bonus( $comment_id, 1 === (int) $approved );
	}

	/**
	 * Bonus nachtragen, wenn eine Bewertung später freigegeben wird.
	 *
	 * @param string      $new_status Neuer Status.
	 * @param string      $old_status Alter Status.
	 * @param \WP_Comment $comment    Kommentar.
	 */
	public function on_comment_status_change( $new_status, $old_status, $comment ) {
		if ( 'approved' !== $new_status || ! $comment ) {
			return;
		}
		$this->award_review_bonus( (int) $comment->comment_ID, true );
	}

	/**
	 * Gemeinsame Prüf- und Vergabelogik für den Bewertungsbonus.
	 *
	 * Die Bedingungen stecken in der getesteten Calc::review_bonus_eligible().
	 * Vorher wurde der Freigabestatus komplett ignoriert – Bewertungen in
	 * Moderation oder im Spam lösten sofort Punkte aus – und ein Kaufnachweis
	 * wurde nie geprüft.
	 *
	 * @param int  $comment_id Kommentar-ID.
	 * @param bool $approved   Ob die Bewertung freigegeben ist.
	 */
	protected function award_review_bonus( $comment_id, $approved ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		$product_id = (int) $comment->comment_post_ID;
		$user_id    = (int) $comment->user_id;
		$min        = (int) Settings::get( 'review_min_zeichen', 15 );
		$flag       = 'lw16_review_bonus_' . $product_id;

		$has_bought = false;
		if ( $user_id && function_exists( 'wc_customer_bought_product' ) ) {
			$user       = get_userdata( $user_id );
			$has_bought = wc_customer_bought_product( $user ? $user->user_email : '', $user_id, $product_id );
		}

		$eligible = Calc::review_bonus_eligible(
			get_post_type( $product_id ),
			mb_strlen( trim( (string) $comment->comment_content ) ),
			$min,
			$user_id,
			(bool) get_user_meta( $user_id, $flag, true ),
			(bool) $approved,
			(bool) $has_bought
		);

		if ( ! $eligible ) {
			return;
		}

		$bonus = (int) Settings::get( 'review_bonus_punkte', 20 );
		if ( $bonus <= 0 ) {
			return;
		}

		LoyaltyRepository::adjust_points( $user_id, $bonus );
		LoyaltyRepository::add_ledger(
			array(
				'user_id' => $user_id,
				'order_id' => null,
				'points'  => $bonus,
				'type'    => 'review_bonus',
				'status'  => 'available',
				'notiz'   => __( 'Bonus für Produktbewertung', 'lindenweg16' ),
			)
		);
		update_user_meta( $user_id, $flag, 1 );
	}
}
