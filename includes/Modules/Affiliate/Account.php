<?php
/**
 * Affiliates: der Bereich „Ambassador“ unter „Mein Konto“ im Shop.
 *
 * Wer mit einem Affiliate verknüpft ist (Rolle Ambassador), sieht dort nur die eigenen
 * Zahlen zu seinem Code und kann eine Auszahlung anfordern. Andere Kunden sehen weder
 * den Menüpunkt noch den Inhalt. Es erscheinen nie Kundendaten, nur Anzahl und Warenwert.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Affiliate;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Settings;

/**
 * Mein-Konto-Seite für Ambassadors.
 */
class Account {

	const ENDPOINT = 'ambassador';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_request' ) );
	}

	/**
	 * Endpoint registrieren; auf bestehenden Installationen einmal die Adressregeln auffrischen.
	 */
	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		if ( '1' !== get_option( 'lw16_affiliate_endpoint' ) ) {
			update_option( 'lw16_affiliate_endpoint', '1', false );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Query-Var eintragen.
	 *
	 * @param array $vars Query-Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Der Partner des angemeldeten Kontos.
	 *
	 * @return array|null
	 */
	protected function partner() {
		return Partners::for_user( get_current_user_id() );
	}

	/**
	 * Menüpunkt nur für Ambassadors, vor „Abmelden“.
	 *
	 * @param array $items Menüpunkte.
	 * @return array
	 */
	public function menu_item( $items ) {
		if ( ! $this->partner() ) {
			return $items;
		}

		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Ambassador', 'lindenweg16' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Ambassador', 'lindenweg16' );
		}

		return $new;
	}

	/**
	 * Seitentitel.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Ambassador', 'lindenweg16' );
	}

	/**
	 * Wartezeit in Tagen.
	 *
	 * @return int
	 */
	protected function wait_days() {
		return max( 0, (int) Settings::get( 'affiliate_wartezeit_tage', 30 ) );
	}

	/**
	 * Mindestbetrag einer Anforderung.
	 *
	 * @return float
	 */
	protected function min_amount() {
		return (float) Settings::get( 'affiliate_min_auszahlung', 20 );
	}

	/**
	 * Kennzahlen eines Partners.
	 *
	 * @param array $p     Partner.
	 * @param int   $days  Zeitraum.
	 * @param bool  $fresh Ohne Zwischenspeicher rechnen.
	 * @return array
	 */
	protected function figures( array $p, $days, $fresh = false ) {
		$key   = 'lw16_aff_sales_' . (int) $p['id'];
		$sales = $fresh ? false : get_transient( $key );

		if ( ! is_array( $sales ) ) {
			$sales = Sales::collect( array( $p['id'] => $p ), $p['angelegt'] );
			set_transient( $key, $sales, 5 * MINUTE_IN_SECONDS );
		}

		$all = Rules::summarize( array( $p['id'] => $p ), $sales, Partners::payouts(), time(), $this->wait_days(), $days );

		return $all[ $p['id'] ];
	}

	/**
	 * Auszahlung anfordern (POST auf der Ambassador-Seite).
	 */
	public function handle_request() {
		$this->handle_split();

		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['lw16_aff_request'] ) || ! is_user_logged_in() ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$p     = $this->partner();
		$back  = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::ENDPOINT ) : home_url( '/' );
		$uid   = get_current_user_id();

		if ( ! $p || ! wp_verify_nonce( $nonce, 'lw16_aff_request' ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		$ziel  = isset( $_POST['ziel'] ) ? sanitize_text_field( wp_unslash( $_POST['ziel'] ) ) : '';
		$f     = $this->figures( $p, 0, true );
		$check = Rules::check_request( $f['guthaben'], $this->min_amount(), null !== Partners::open_request( $p['id'] ), $ziel );

		if ( ! $p['aktiv'] ) {
			$check['errors'][] = __( 'Dein Code ist im Moment abgeschaltet. Bitte melde dich bei uns.', 'lindenweg16' );
		}

		if ( $check['errors'] ) {
			set_transient( 'lw16_aff_msg_' . $uid, array( 'error', implode( ' ', $check['errors'] ) ), 5 * MINUTE_IN_SECONDS );
		} else {
			Partners::add_request( $p['id'], $check['betrag'], $check['ziel'] );
			AuditLog::record( 'affiliate_anforderung', 'affiliate', $p['id'], array( 'betrag' => $check['betrag'] ) );
			$this->notify_shop( $p, $check['betrag'], $check['ziel'] );
			set_transient( 'lw16_aff_msg_' . $uid, array( 'ok', __( 'Danke! Deine Auszahlung ist angefordert. Wir überweisen und melden uns, falls etwas fehlt.', 'lindenweg16' ) ), 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Aufteilung ändern (POST auf der Ambassador-Seite): Rabatt für die Follower, der Rest ist die Provision.
	 */
	protected function handle_split() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['lw16_aff_split'] ) || ! is_user_logged_in() ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$p     = $this->partner();
		$back  = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::ENDPOINT ) : home_url( '/' );
		$uid   = get_current_user_id();

		if ( ! $p || ! wp_verify_nonce( $nonce, 'lw16_aff_split' ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		if ( empty( $p['teilen'] ) ) {
			set_transient( 'lw16_aff_msg_' . $uid, array( 'error', __( 'Die Aufteilung ist bei dir festgelegt. Bitte melde dich bei uns, wenn du sie ändern möchtest.', 'lindenweg16' ) ), 5 * MINUTE_IN_SECONDS );
		} else {
			$res = Partners::set_split( $p['id'], isset( $_POST['rabatt'] ) ? sanitize_text_field( wp_unslash( $_POST['rabatt'] ) ) : '' );

			if ( $res['ok'] ) {
				AuditLog::record( 'affiliate_aufteilung', 'affiliate', $p['id'], array( 'rabatt_vorher' => $res['vorher'], 'rabatt' => $res['rabatt'], 'provision' => $res['provision'] ) );
				/* translators: 1: Rabatt in Prozent, 2: Provision in Prozent. */
				$text = sprintf( __( 'Gespeichert: %1$s %% Rabatt für deine Follower, %2$s %% für dich. Es gilt ab sofort für neue Bestellungen.', 'lindenweg16' ), $this->percent( $res['rabatt'] ), $this->percent( $res['provision'] ) );
				set_transient( 'lw16_aff_msg_' . $uid, array( 'ok', $text ), 5 * MINUTE_IN_SECONDS );
			} else {
				set_transient( 'lw16_aff_msg_' . $uid, array( 'error', implode( ' ', $res['errors'] ) ), 5 * MINUTE_IN_SECONDS );
			}
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Dem Shop eine Mail schicken, dass eine Auszahlung wartet.
	 *
	 * @param array  $p      Partner.
	 * @param float  $betrag Betrag.
	 * @param string $ziel   Zahlungsangabe.
	 */
	protected function notify_shop( array $p, $betrag, $ziel ) {
		$to = (string) Settings::get( 'firma_email', get_bloginfo( 'admin_email' ) );

		if ( ! is_email( $to ) ) {
			return;
		}

		$body = sprintf(
			/* translators: 1: Name, 2: Code, 3: Betrag, 4: Zahlungsangabe, 5: Adresse im ERP. */
			__( "%1\$s (Code %2\$s) hat eine Auszahlung von %3\$s € angefordert.\n\nZahlungsangabe: %4\$s\n\nIm ERP unter Verkauf → Marketing → Affiliates festhalten, nachdem du überwiesen hast:\n%5\$s", 'lindenweg16' ),
			$p['name'],
			$p['code'],
			number_format( $betrag, 2, ',', '.' ),
			$ziel,
			\Lindenweg16\Erp\Shell::url() . '#/affiliates'
		);

		wp_mail( $to, sprintf( /* translators: 1: Name, 2: Betrag. */ __( 'Auszahlung angefordert: %1$s, %2$s €', 'lindenweg16' ), $p['name'], number_format( $betrag, 2, ',', '.' ) ), $body );
	}

	/**
	 * Euro-Betrag für die Anzeige.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	protected function eur( $n ) {
		return number_format( (float) $n, 2, ',', '.' ) . ' €';
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$p = $this->partner();

		if ( ! $p ) {
			echo '<p>' . esc_html__( 'Diese Seite ist für Ambassadors gedacht.', 'lindenweg16' ) . '</p>';
			return;
		}

		$ranges = Rules::ranges();
		$days   = Rules::clean_days( isset( $_GET['tage'] ) ? sanitize_text_field( wp_unslash( $_GET['tage'] ) ) : null ); // phpcs:ignore WordPress.Security.NonceVerification
		$f      = $this->figures( $p, $days );
		$all    = $this->figures( $p, 0 );
		$open   = Partners::open_request( $p['id'] );
		$url    = wc_get_account_endpoint_url( self::ENDPOINT );
		$min    = $this->min_amount();

		echo '<div class="lw16-ambassador">';

		$msg = get_transient( 'lw16_aff_msg_' . get_current_user_id() );
		if ( is_array( $msg ) ) {
			delete_transient( 'lw16_aff_msg_' . get_current_user_id() );
			echo '<div class="woocommerce-' . ( 'ok' === $msg[0] ? 'message' : 'error' ) . '" role="alert">' . esc_html( $msg[1] ) . '</div>';
		}

		echo '<h3>' . esc_html__( 'Dein Code', 'lindenweg16' ) . '</h3>';
		echo '<p><strong style="font-size:1.6em;letter-spacing:.05em">' . esc_html( $p['code'] ) . '</strong></p>';
		if ( ! $p['aktiv'] ) {
			echo '<p><em>' . esc_html__( 'Dein Code ist im Moment abgeschaltet.', 'lindenweg16' ) . '</em></p>';
		} else {
			/* translators: 1: Rabatt in Prozent, 2: Provision in Prozent, 3: Gesamtanteil. */
			echo '<p>' . esc_html( sprintf( __( 'Wer ihn im Warenkorb eingibt, spart %1$s %%. Du bekommst %2$s %% Provision auf den Warenwert. Zusammen sind das %3$s %%.', 'lindenweg16' ), $this->percent( $p['rabatt'] ), $this->percent( $p['provision'] ), $this->percent( $p['gesamt'] ) ) ) . '</p>';

			if ( ! empty( $p['teilen'] ) && $p['gesamt'] > 0 ) {
				echo '<form method="post" action="' . esc_url( $url ) . '">';
				wp_nonce_field( 'lw16_aff_split' );
				echo '<input type="hidden" name="lw16_aff_split" value="1">';
				echo '<p><label for="lw16-aff-rabatt"><strong>' . esc_html__( 'Aufteilung bestimmst du:', 'lindenweg16' ) . '</strong> ' . esc_html__( 'Wie viel Prozent sparen deine Follower?', 'lindenweg16' ) . '</label><br>';
				echo '<input type="number" id="lw16-aff-rabatt" name="rabatt" min="0" max="' . esc_attr( $p['gesamt'] ) . '" step="0.5" value="' . esc_attr( $p['rabatt'] ) . '" data-gesamt="' . esc_attr( $p['gesamt'] ) . '" style="width:6em" oninput="document.getElementById(\'lw16-aff-rest\').textContent=Math.max(0,this.dataset.gesamt-this.value).toFixed(1).replace(\'.\',\',\')"> %';
				echo ' &nbsp; ' . esc_html__( 'Für dich bleiben:', 'lindenweg16' ) . ' <strong><span id="lw16-aff-rest">' . esc_html( number_format( (float) $p['provision'], 1, ',', '' ) ) . '</span> %</strong></p>';
				echo '<p><button type="submit" class="button">' . esc_html__( 'Aufteilung speichern', 'lindenweg16' ) . '</button> <small>' . esc_html__( 'Gilt für neue Bestellungen; bezahlte Bestellungen behalten ihren Satz.', 'lindenweg16' ) . '</small></p>';
				echo '</form>';
			}
		}

		if ( $p['aktiv'] ) {
			echo '<p><label for="lw16-aff-link">' . esc_html__( 'Dein Link: Wer ihn öffnet, bekommt den Rabatt automatisch in den Warenkorb.', 'lindenweg16' ) . '</label><br>';
			echo '<input type="text" id="lw16-aff-link" readonly value="' . esc_attr( AutoApply::link( $p['code'] ) ) . '" onclick="this.select()" style="width:100%;max-width:28em"></p>';
		}

		echo '<h3>' . esc_html__( 'Dein Kontostand', 'lindenweg16' ) . '</h3>';
		echo '<table class="woocommerce-table shop_table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Auszahlbar', 'lindenweg16' ) . '</th><td><strong>' . esc_html( $this->eur( $all['guthaben'] ) ) . '</strong></td></tr>';
		/* translators: %d: Tage. */
		echo '<tr><th>' . esc_html( sprintf( __( 'In Wartezeit (%d Tage nach der Bestellung)', 'lindenweg16' ), $this->wait_days() ) ) . '</th><td>' . esc_html( $this->eur( $all['wartend'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Bisher ausgezahlt', 'lindenweg16' ) . '</th><td>' . esc_html( $this->eur( $all['ausgezahlt'] ) ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Auszahlung anfordern', 'lindenweg16' ) . '</h3>';
		$last = null;
		foreach ( Partners::requests() as $r ) {
			if ( (int) $r['partner'] === (int) $p['id'] ) {
				$last = $r;
			}
		}
		if ( $last && 'abgelehnt' === $last['status'] ) {
			/* translators: 1: Datum, 2: Begründung. */
			echo '<p><em>' . esc_html( sprintf( __( 'Deine Anforderung vom %1$s wurde nicht ausgezahlt. %2$s', 'lindenweg16' ), wp_date( 'd.m.Y', $last['ts'] ), $last['notiz'] ) ) . '</em></p>';
		}
		if ( $open ) {
			/* translators: 1: Betrag, 2: Datum. */
			echo '<p>' . esc_html( sprintf( __( 'Du hast am %2$s %1$s angefordert. Wir kümmern uns darum.', 'lindenweg16' ), $this->eur( $open['betrag'] ), wp_date( 'd.m.Y', $open['ts'] ) ) ) . '</p>';
		} elseif ( $p['aktiv'] && $all['guthaben'] >= max( 0.01, $min ) ) {
			echo '<form method="post" action="' . esc_url( $url ) . '">';
			wp_nonce_field( 'lw16_aff_request' );
			echo '<input type="hidden" name="lw16_aff_request" value="1">';
			/* translators: %s: Betrag. */
			echo '<p>' . esc_html( sprintf( __( 'Angefordert wird dein ganzes auszahlbares Guthaben: %s.', 'lindenweg16' ), $this->eur( $all['guthaben'] ) ) ) . '</p>';
			echo '<p><label for="lw16-aff-ziel">' . esc_html__( 'Wohin soll das Geld? (z. B. PayPal-Adresse oder IBAN)', 'lindenweg16' ) . '</label><br>';
			echo '<input type="text" id="lw16-aff-ziel" name="ziel" maxlength="190" required style="width:100%;max-width:28em"></p>';
			echo '<p><button type="submit" class="button">' . esc_html__( 'Auszahlung anfordern', 'lindenweg16' ) . '</button></p>';
			echo '</form>';
		} else {
			/* translators: %s: Mindestbetrag. */
			echo '<p>' . esc_html( sprintf( __( 'Eine Auszahlung ist ab %s möglich.', 'lindenweg16' ), $this->eur( $min ) ) ) . '</p>';
		}

		echo '<h3>' . esc_html__( 'Deine Zahlen', 'lindenweg16' ) . '</h3><p>';
		foreach ( $ranges as $k => $label ) {
			$link = add_query_arg( 'tage', $k, $url );
			echo $k === $days ? '<strong>' . esc_html( $label ) . '</strong> ' : '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a> ';
			echo '&nbsp;';
		}
		echo '</p>';
		echo '<table class="woocommerce-table shop_table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Bestellungen mit deinem Code', 'lindenweg16' ) . '</th><td>' . (int) $f['anzahl'] . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Warenwert (netto, nach Rabatt und Erstattungen)', 'lindenweg16' ) . '</th><td>' . esc_html( $this->eur( $f['basis'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Deine Provision', 'lindenweg16' ) . '</th><td><strong>' . esc_html( $this->eur( $f['provision'] ) ) . '</strong></td></tr>';
		echo '</tbody></table>';

		$mine = array_values(
			array_filter(
				array_reverse( Partners::payouts() ),
				function ( $r ) use ( $p ) {
					return (int) $r['partner'] === (int) $p['id'] && empty( $r['storniert'] );
				}
			)
		);
		echo '<h3>' . esc_html__( 'Bisherige Auszahlungen', 'lindenweg16' ) . '</h3>';
		if ( ! $mine ) {
			echo '<p>' . esc_html__( 'Noch keine Auszahlung.', 'lindenweg16' ) . '</p>';
		} else {
			echo '<table class="woocommerce-table shop_table"><thead><tr><th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Betrag', 'lindenweg16' ) . '</th></tr></thead><tbody>';
			foreach ( $mine as $r ) {
				echo '<tr><td>' . esc_html( wp_date( 'd.m.Y', strtotime( $r['datum'] ) ) ) . '</td><td>' . esc_html( $this->eur( $r['betrag'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * Prozentzahl ohne überflüssige Nullen.
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	protected function percent( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, ',', '' ), '0' ), ',' );
	}
}
