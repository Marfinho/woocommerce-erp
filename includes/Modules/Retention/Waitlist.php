<?php
/**
 * „Wieder verfügbar"-Warteliste.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Retention;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Core\Throttle;
use Lindenweg16\Modules\Inventory\Repository;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\StockLedger;
use Lindenweg16\Modules\Betrieb\StockOwnership;
use Lindenweg16\Modules\Betrieb\StockRepository;

/**
 * Formular auf ausverkauften Produktseiten und automatische Benachrichtigung,
 * sobald der Bestand wieder über 0 steigt (jede Anmeldung nur einmal).
 *
 * Das Formular ist offen: Jeder kann jede Adresse eintragen. Benachrichtigt
 * wird deshalb nur, wer die Anmeldung über den Link in einer Bestätigungsmail
 * bestätigt hat (Double-Opt-in). Angemeldete Kunden mit ihrer eigenen
 * Kontoadresse gelten sofort als bestätigt.
 */
class Waitlist {

	/**
	 * Parameter des Bestätigungslinks.
	 */
	const PARAM = 'lw16_wl';

	/**
	 * Anmeldungen je Adresse (IP) und Minute.
	 */
	const MAX_PRO_MINUTE = 5;

	/**
	 * So viele unbestätigte Anmeldungen darf eine E-Mail-Adresse zugleich haben.
	 * Jede löst eine Mail aus – mehr wäre ein Weg, fremde Postfächer zu füllen.
	 */
	const MAX_OFFEN = 3;

	/**
	 * Unbestätigte Anmeldungen verfallen nach so vielen Tagen.
	 */
	const VERFALL_TAGE = 7;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Formular unter dem ausverkauften Produkt.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_form' ), 35 );
		add_action( 'admin_post_lw16_waitlist_signup', array( $this, 'handle_signup' ) );
		add_action( 'admin_post_nopriv_lw16_waitlist_signup', array( $this, 'handle_signup' ) );
		add_action( 'template_redirect', array( $this, 'bestaetigen' ), 5 );

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
	 * Signatur des Bestätigungslinks – reine Regel.
	 *
	 * Bindet Anmeldung und Adresse aneinander: Der Link einer Anmeldung
	 * bestätigt keine andere.
	 *
	 * @param int    $id    Anmeldung.
	 * @param string $email Adresse der Anmeldung.
	 * @param string $key   Geheimer Schlüssel.
	 * @return string
	 */
	public static function signatur( $id, $email, $key ) {
		return substr( hash_hmac( 'sha256', (int) $id . '|' . strtolower( trim( (string) $email ) ), (string) $key ), 0, 24 );
	}

	/**
	 * Wert aus dem Link zerlegen – reine Regel.
	 *
	 * @param mixed $wert `<Anmeldung>.<Signatur>`.
	 * @return array|null {id, sig} oder null bei falscher Form.
	 */
	public static function zerlegen( $wert ) {
		if ( ! is_string( $wert ) || ! preg_match( '/^([1-9][0-9]{0,18})\.([a-f0-9]{24})$/', $wert, $m ) ) {
			return null;
		}

		return array(
			'id'  => (int) $m[1],
			'sig' => $m[2],
		);
	}

	/**
	 * Braucht die Anmeldung eine Bestätigungsmail? Reine Regel.
	 *
	 * Nicht, wenn ein angemeldeter Kunde seine eigene Kontoadresse einträgt.
	 *
	 * @param string $email       Eingetragene Adresse.
	 * @param string $konto_email Adresse des angemeldeten Kontos, leer ohne Anmeldung.
	 * @return bool
	 */
	public static function braucht_bestaetigung( $email, $konto_email ) {
		$email       = strtolower( trim( (string) $email ) );
		$konto_email = strtolower( trim( (string) $konto_email ) );

		return '' === $konto_email || $email !== $konto_email;
	}

	/**
	 * Rückmeldung über dem Formular – reine Regel.
	 *
	 * @param mixed $status Wert aus der Adresse (`?lw16_waitlist=`).
	 * @return string Leer bei unbekanntem Wert.
	 */
	public static function meldung( $status ) {
		$texte = array(
			'ok'         => __( 'Du stehst auf der Liste. Wir schreiben dir, sobald die Ware wieder da ist.', 'lindenweg16' ),
			'mail'       => __( 'Fast geschafft: Wir haben dir eine E-Mail geschickt. Bitte bestätige dort deine Anmeldung.', 'lindenweg16' ),
			'bestaetigt' => __( 'Danke, deine Anmeldung ist bestätigt. Wir schreiben dir, sobald die Ware wieder da ist.', 'lindenweg16' ),
			'ungueltig'  => __( 'Der Bestätigungslink ist ungültig oder abgelaufen. Bitte trag dich noch einmal ein.', 'lindenweg16' ),
			'zu_oft'     => __( 'Zu viele Anmeldungen in kurzer Zeit. Bitte versuch es in ein paar Minuten noch einmal.', 'lindenweg16' ),
		);

		return is_string( $status ) && isset( $texte[ $status ] ) ? $texte[ $status ] : '';
	}

	/**
	 * Geheimer Schlüssel der Signatur.
	 *
	 * @return string
	 */
	protected static function schluessel() {
		return wp_salt( 'auth' ) . 'lw16_warteliste';
	}

	/**
	 * Bestätigungslink einer Anmeldung.
	 *
	 * @param int    $id    Anmeldung.
	 * @param string $email Adresse.
	 * @return string
	 */
	protected static function link( $id, $email ) {
		return add_query_arg( self::PARAM, (int) $id . '.' . self::signatur( $id, $email, self::schluessel() ), home_url( '/' ) );
	}

	/**
	 * Name und Shop-Adresse der Ware einer Anmeldung.
	 *
	 * @param int $produkt_id Produkt der alten Lagerverwaltung.
	 * @param int $article_id Betriebsartikel.
	 * @return array {name, link}
	 */
	protected static function ziel( $produkt_id, $article_id ) {
		$wc_id = 0;
		$name  = '';

		if ( $article_id ) {
			$article = ArticleRepository::find( (int) $article_id );
			if ( $article ) {
				$wc_id = (int) $article['wc_product_id'];
				$name  = (string) $article['name'];
			}
		}

		if ( ! $wc_id && $produkt_id ) {
			$produkt = Repository::get_product( (int) $produkt_id );
			if ( $produkt ) {
				$wc_id = (int) $produkt->woo_product_id;
				$name  = (string) $produkt->name;
			}
		}

		$wc = $wc_id ? wc_get_product( $wc_id ) : null;

		return array(
			'name' => $wc ? $wc->get_name() : $name,
			'link' => $wc ? get_permalink( $wc_id ) : home_url( '/' ),
		);
	}

	/**
	 * Formular nur anzeigen, wenn das verknüpfte Modul ausverkauft ist.
	 */
	public function render_form() {
		global $product;
		if ( ! $product ) {
			return;
		}
		// Wer ohne Bestand bestellen kann, braucht keine Warteliste: Die Seite
		// zeigt dann „Wird für dich gefertigt“ mit Lieferzeit und den Warenkorb.
		if ( \Lindenweg16\Modules\Betrieb\ForecastHooks::made_to_order( $product ) ) {
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

		// phpcs:ignore WordPress.Security.NonceVerification -- Nur Auswahl eines festen Textes.
		$hinweis = self::meldung( isset( $_GET['lw16_waitlist'] ) ? sanitize_key( wp_unslash( $_GET['lw16_waitlist'] ) ) : '' );
		if ( '' !== $hinweis ) {
			echo '<p class="lw16-waitlist__hinweis" role="status"><strong>' . esc_html( $hinweis ) . '</strong></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_waitlist_signup">';
		echo '<input type="hidden" name="produkt_id" value="' . ( $produkt ? (int) $produkt->id : 0 ) . '">';
		echo '<input type="hidden" name="article_id" value="' . (int) $article_id . '">';
		echo '<input type="hidden" name="redirect" value="' . esc_url( get_permalink( $product->get_id() ) ) . '">';
		wp_nonce_field( 'lw16_waitlist' );
		echo '<input type="email" name="email" required placeholder="' . esc_attr__( 'Deine E-Mail', 'lindenweg16' ) . '" value="' . esc_attr( $email ) . '"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Benachrichtige mich, wenn wieder verfügbar', 'lindenweg16' ) . '</button>';
		echo '<p class="lw16-privacy"><small>' . esc_html__( 'Deine E-Mail-Adresse wird ausschließlich für diese eine Benachrichtigung verwendet, keine sonstige Nutzung ohne separates Opt-in. Du bekommst zuerst eine E-Mail, in der du die Anmeldung bestätigst.', 'lindenweg16' ) . '</small></p>';
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
		$status     = 'ok';

		// Offenes Formular: je Adresse (IP) begrenzen, bevor etwas gespeichert oder verschickt wird.
		if ( ! Throttle::hit( 'warteliste', self::MAX_PRO_MINUTE ) ) {
			wp_safe_redirect( add_query_arg( 'lw16_waitlist', 'zu_oft', $redirect ) );
			exit;
		}

		if ( ( $produkt_id || $article_id ) && is_email( $email ) ) {
			$table = self::table();

			// Nie bestätigte Anmeldungen wegräumen.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE bestaetigt = 0 AND erstellt_am < %s", gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' UTC' ) - self::VERFALL_TAGE * DAY_IN_SECONDS ) ) ); // phpcs:ignore

			// Doppelte offene Anmeldung vermeiden – gleiche Ware, gleiche
			// Adresse, egal über welche der beiden Kennungen sie kam.
			$exists = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, bestaetigt FROM {$table} WHERE email = %s AND benachrichtigt = 0 AND ( ( produkt_id > 0 AND produkt_id = %d ) OR ( article_id IS NOT NULL AND article_id = %d ) )", // phpcs:ignore
					$email,
					$produkt_id,
					$article_id
				)
			);

			$konto = is_user_logged_in() ? (string) wp_get_current_user()->user_email : '';
			$offen = self::braucht_bestaetigung( $email, $konto );

			if ( $exists ) {
				// Keine zweite Mail für dieselbe Anmeldung – sonst ließe sich ein
				// fremdes Postfach durch wiederholtes Absenden füllen.
				$status = (int) $exists->bestaetigt ? 'ok' : 'mail';
			} elseif ( $offen && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND bestaetigt = 0", $email ) ) >= self::MAX_OFFEN ) { // phpcs:ignore
				// Gleiche Antwort wie sonst: Sie verrät nicht, ob die Adresse schon Anmeldungen hat.
				$status = 'mail';
			} else {
				$wpdb->insert(
					$table,
					array(
						'produkt_id'     => $produkt_id,
						'article_id'     => $article_id ? $article_id : null,
						'email'          => $email,
						'user_id'        => get_current_user_id() ?: null,
						'benachrichtigt' => 0,
						'bestaetigt'     => $offen ? 0 : 1,
						'erstellt_am'    => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%d', '%d', '%d', '%s' )
				);

				$id = (int) $wpdb->insert_id;

				if ( $id && $offen ) {
					$ziel = self::ziel( $produkt_id, $article_id );
					MailTemplate::send(
						$email,
						'warteliste_bestaetigen',
						array(
							'{produktname}'      => $ziel['name'],
							'{bestaetigungslink}' => self::link( $id, $email ),
						)
					);
					$status = 'mail';
				} elseif ( $id ) {
					// Nachfragesignal fürs ERP erst, wenn die Anmeldung gilt.
					do_action( 'lw16_waitlist_signup', $produkt_id, $article_id );
				}
			}
		}

		wp_safe_redirect( add_query_arg( 'lw16_waitlist', $status, $redirect ) );
		exit;
	}

	/**
	 * Klick auf den Link der Bestätigungsmail.
	 */
	public function bestaetigen() {
		// phpcs:ignore WordPress.Security.NonceVerification -- Der Link trägt eine Signatur statt eines Nonce.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification
		$teile = self::zerlegen( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) );
		$row   = $teile ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $teile['id'] ) ) : null; // phpcs:ignore

		if ( ! $row || ! hash_equals( self::signatur( $row->id, $row->email, self::schluessel() ), $teile['sig'] ) ) {
			wp_safe_redirect( add_query_arg( 'lw16_waitlist', 'ungueltig', home_url( '/' ) ) );
			exit;
		}

		if ( ! (int) $row->bestaetigt ) {
			$wpdb->update( self::table(), array( 'bestaetigt' => 1 ), array( 'id' => (int) $row->id ), array( '%d' ), array( '%d' ) );
			do_action( 'lw16_waitlist_signup', (int) $row->produkt_id, (int) $row->article_id );
		}

		$ziel = self::ziel( (int) $row->produkt_id, (int) $row->article_id );

		wp_safe_redirect( add_query_arg( 'lw16_waitlist', 'bestaetigt', $ziel['link'] ) );
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
				'SELECT * FROM ' . self::table() . ' WHERE benachrichtigt = 0 AND bestaetigt = 1 AND ( article_id = %d OR ( %d > 0 AND produkt_id = %d ) )', // phpcs:ignore
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
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE produkt_id = %d AND benachrichtigt = 0 AND bestaetigt = 1', $produkt_id ) ); // phpcs:ignore
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
