<?php
/**
 * „Wieder verfügbar"-Warteliste.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Retention;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Modules\Inventory\Repository;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\StockLedger;
use Lindenweg16\Modules\Betrieb\StockOwnership;
use Lindenweg16\Modules\Betrieb\StockRepository;

/**
 * Formular auf ausverkauften Produktseiten und automatische Benachrichtigung,
 * sobald der Bestand wieder über 0 steigt (jede Anmeldung nur einmal).
 */
class Waitlist {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Formular unter dem ausverkauften Produkt.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_form' ), 35 );
		add_action( 'admin_post_lw16_waitlist_signup', array( $this, 'handle_signup' ) );
		add_action( 'admin_post_nopriv_lw16_waitlist_signup', array( $this, 'handle_signup' ) );

		// Auslöser: Lagerzugang über 0. Zwei Quellen, weil zwei
		// Lagerverwaltungen buchen können – seit der Zusammenlegung fast
		// immer die zweite.
		add_action( 'lw16_stock_replenished', array( $this, 'notify' ), 10, 2 );
		add_action( 'lw16_betrieb_stock_replenished', array( $this, 'notify_article' ), 10, 2 );
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_warteliste';
	}

	/**
	 * Formular nur anzeigen, wenn das verknüpfte Modul ausverkauft ist.
	 */
	public function render_form() {
		global $product;
		if ( ! $product ) {
			return;
		}
		// Zuständigkeit klären: Kennt Betrieb das Produkt, gilt sein
		// Bewegungskonto. Der Bestand der abgelösten Verwaltung ist seit
		// der Übernahme eine eingefrorene Zahl und taugt nicht mehr als
		// Grundlage für „ausverkauft".
		$article_id = StockOwnership::article_for_product( $product->get_id() );
		$produkt    = Repository::get_by_woo_id( $product->get_id() );

		if ( $article_id ) {
			if ( self::article_stock( $article_id ) > 0 ) {
				return;
			}
		} else {
			if ( ! $produkt ) {
				return;
			}
			if ( Repository::get_stock( $produkt->id ) > 0 ) {
				return;
			}
		}

		$email = '';
		if ( is_user_logged_in() ) {
			$u     = wp_get_current_user();
			$email = $u->user_email;
		}

		echo '<div class="lw16-waitlist">';
		echo '<h4>' . esc_html__( 'Zurzeit nicht auf Lager', 'lindenweg16' ) . '</h4>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_waitlist_signup">';
		echo '<input type="hidden" name="produkt_id" value="' . ( $produkt ? (int) $produkt->id : 0 ) . '">';
		echo '<input type="hidden" name="article_id" value="' . (int) $article_id . '">';
		echo '<input type="hidden" name="redirect" value="' . esc_url( get_permalink( $product->get_id() ) ) . '">';
		wp_nonce_field( 'lw16_waitlist' );
		echo '<input type="email" name="email" required placeholder="' . esc_attr__( 'Deine E-Mail', 'lindenweg16' ) . '" value="' . esc_attr( $email ) . '"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Benachrichtige mich, wenn wieder verfügbar', 'lindenweg16' ) . '</button>';
		echo '<p class="lw16-privacy"><small>' . esc_html__( 'Deine E-Mail-Adresse wird ausschließlich für diese eine Benachrichtigung verwendet, keine sonstige Nutzung ohne separates Opt-in.', 'lindenweg16' ) . '</small></p>';
		echo '</form></div>';
	}

	/**
	 * Anmeldung speichern.
	 */
	public function handle_signup() {
		check_admin_referer( 'lw16_waitlist' );
		global $wpdb;

		$produkt_id = (int) ( $_POST['produkt_id'] ?? 0 );
		$article_id = (int) ( $_POST['article_id'] ?? 0 );
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$redirect   = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : home_url();

		if ( ( $produkt_id || $article_id ) && is_email( $email ) ) {
			// Doppelte offene Anmeldung vermeiden – gleiche Ware, gleiche
			// Adresse, egal über welche der beiden Kennungen sie kam.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::table() . ' WHERE email = %s AND benachrichtigt = 0 AND ( ( produkt_id > 0 AND produkt_id = %d ) OR ( article_id IS NOT NULL AND article_id = %d ) )', // phpcs:ignore
					$email,
					$produkt_id,
					$article_id
				)
			);

			if ( ! $exists ) {
				$wpdb->insert(
					self::table(),
					array(
						'produkt_id'     => $produkt_id,
						'article_id'     => $article_id ? $article_id : null,
						'email'          => $email,
						'user_id'        => get_current_user_id() ?: null,
						'benachrichtigt' => 0,
						'erstellt_am'    => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%d', '%d', '%s' )
				);
			}
		}

		wp_safe_redirect( add_query_arg( 'lw16_waitlist', 'ok', $redirect ) );
		exit;
	}

	/**
	 * Bestand eines Betriebsartikels am Lagerort „Lager".
	 *
	 * @param int $article_id Artikel.
	 * @return float
	 */
	protected static function article_stock( $article_id ) {
		$balances = StockRepository::balances( (int) $article_id );
		$sum      = 0.0;

		foreach ( isset( $balances[ (int) $article_id ] ) ? $balances[ (int) $article_id ] : array() as $locations ) {
			$sum += isset( $locations['lager'] ) ? (float) $locations['lager'] : 0.0;
		}

		return $sum;
	}

	/**
	 * Anmeldungen zu einem Betriebsartikel benachrichtigen.
	 *
	 * Berücksichtigt auch Anmeldungen, die noch unter der alten Produkt-ID
	 * eingetragen wurden – die Übernahme hat beide Kennungen verbunden.
	 *
	 * @param int   $article_id Artikel.
	 * @param float $bestand    Neuer Bestand.
	 */
	public function notify_article( $article_id, $bestand ) {
		global $wpdb;

		$article = ArticleRepository::find( (int) $article_id );

		if ( ! $article || empty( $article['wc_product_id'] ) ) {
			return;
		}

		$legacy = ! empty( $article['legacy_produkt_id'] ) ? (int) $article['legacy_produkt_id'] : 0;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE benachrichtigt = 0 AND ( article_id = %d OR ( %d > 0 AND produkt_id = %d ) )', // phpcs:ignore
				(int) $article_id,
				$legacy,
				$legacy
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		$wc   = wc_get_product( (int) $article['wc_product_id'] );
		$name = $wc ? $wc->get_name() : $article['name'];
		$link = $wc ? get_permalink( (int) $article['wc_product_id'] ) : home_url();

		foreach ( $rows as $row ) {
			MailTemplate::send(
				$row->email,
				'wieder_verfuegbar',
				array(
					'{produktname}' => $name,
					'{produktlink}' => $link,
				)
			);
			$wpdb->update( self::table(), array( 'benachrichtigt' => 1 ), array( 'id' => $row->id ) );
		}
	}

	/**
	 * Alle offenen Anmeldungen für ein Produkt benachrichtigen.
	 *
	 * @param int   $produkt_id    Produkt-ID.
	 * @param float $neuer_bestand Bestand.
	 */
	public function notify( $produkt_id, $neuer_bestand ) {
		global $wpdb;
		$produkt = Repository::get_product( $produkt_id );
		if ( ! $produkt || empty( $produkt->woo_product_id ) ) {
			return;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE produkt_id = %d AND benachrichtigt = 0', $produkt_id ) ); // phpcs:ignore
		if ( empty( $rows ) ) {
			return;
		}

		$wc   = wc_get_product( $produkt->woo_product_id );
		$name = $wc ? $wc->get_name() : $produkt->name;
		$link = $wc ? get_permalink( $produkt->woo_product_id ) : home_url();

		foreach ( $rows as $row ) {
			MailTemplate::send( $row->email, 'wieder_verfuegbar', array(
				'{produktname}' => $name,
				'{produktlink}' => $link,
			) );
			$wpdb->update( self::table(), array( 'benachrichtigt' => 1 ), array( 'id' => $row->id ) );
		}
	}
}
