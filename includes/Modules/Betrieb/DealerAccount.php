<?php
/**
 * Händlerkonto: Nachbestellen unter „Mein Konto“.
 *
 * Ein Händler (B2B-Kontakt) bekommt ein normales Kundenkonto im Shop. Ist es
 * mit seinem Kontakt verknüpft, erscheint unter „Mein Konto“ der Reiter
 * „Nachbestellen“: das Bestellformular mit seinen Konditionen und darunter
 * seine bisherigen Anfragen und Aufträge.
 *
 * Grundsätze:
 * - Die Verknüpfung steht am Benutzer (Benutzer-Meta), nie im Formular. Eine
 *   Anfrage gehört immer zum Kontakt des angemeldeten Kontos.
 * - Mitarbeiterkonten (wer Beiträge bearbeiten oder den Shop verwalten darf)
 *   werden nie mit einem Händler verknüpft.
 * - Wie beim Link entsteht nur ein Bestellentwurf; erst die Bestätigung im
 *   ERP macht daraus einen Auftrag.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\MailTemplate;

/**
 * Verknüpfung, Einladung und Konto-Reiter.
 */
class DealerAccount {

	/**
	 * Benutzer-Meta mit der Kontakt-ID.
	 */
	const META = 'lw16_haendler_kontakt';

	/**
	 * Endpoint unter „Mein Konto“.
	 */
	const ENDPOINT = 'nachbestellen';

	/**
	 * Nonce des Formulars (dasselbe Formular wie beim Link).
	 */
	const NONCE = ReorderPage::NONCE;

	/**
	 * Name des Absende-Felds.
	 */
	const FIELD = 'lw16_haendler_submit';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_submit' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Endpoint registrieren; auf bestehenden Installationen einmal die
	 * Adressregeln auffrischen.
	 *
	 * Nur unter Seiten (EP_PAGES), nicht an der Wurzel: /nachbestellen/<token>/
	 * ist der Nachbestell-Link und bleibt es.
	 */
	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );

		if ( '1' !== get_option( 'lw16_haendler_endpoint' ) ) {
			update_option( 'lw16_haendler_endpoint', '1', false );
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
	 * Adresse des Reiters.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_account_endpoint_url( self::ENDPOINT );
		}

		return home_url( '/' );
	}

	/* ====================================================================
	 * Verknüpfung
	 * ================================================================== */

	/**
	 * Kontakt eines Benutzers.
	 *
	 * @param int $user_id Benutzer.
	 * @return array|null Kontakt oder null.
	 */
	public static function contact_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return null;
		}

		$contact_id = (int) get_user_meta( $user_id, self::META, true );
		if ( ! $contact_id ) {
			return null;
		}

		$contact = ContactRepository::find( $contact_id );

		return $contact ? $contact : null;
	}

	/**
	 * Benutzer eines Kontakts.
	 *
	 * @param int $contact_id Kontakt.
	 * @return \WP_User|null
	 */
	public static function user_for_contact( $contact_id ) {
		$contact_id = (int) $contact_id;
		if ( ! $contact_id ) {
			return null;
		}

		$users = get_users(
			array(
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (string) $contact_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 1,
			)
		);

		return $users ? $users[0] : null;
	}

	/**
	 * Darf ein Konto mit diesem Rechte-Satz einem Händler gehören?
	 *
	 * Reine Logik. Mitarbeiterkonten nie: Wer im Händlerkonto angemeldet ist,
	 * soll nicht nebenbei Shop oder Website verwalten können – und ein
	 * Mitarbeiter soll nicht versehentlich als Händler bestellen.
	 *
	 * @param array $caps Rechte als Name => bool.
	 * @return bool
	 */
	public static function linkable_caps( array $caps ) {
		foreach ( array( 'edit_posts', 'manage_woocommerce', 'manage_options', 'edit_shop_orders' ) as $cap ) {
			if ( ! empty( $caps[ $cap ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Zustand für das ERP.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array state (none|linked), email, last_active (Y-m-d oder '').
	 */
	public static function state( $contact_id ) {
		$user = self::user_for_contact( $contact_id );

		if ( ! $user ) {
			return array(
				'state'       => 'none',
				'email'       => '',
				'last_active' => '',
			);
		}

		$active = (int) get_user_meta( $user->ID, 'wc_last_active', true );

		return array(
			'state'       => 'linked',
			'email'       => (string) $user->user_email,
			'last_active' => $active ? gmdate( 'Y-m-d', $active ) : '',
		);
	}

	/**
	 * Konto anlegen oder ein vorhandenes Kundenkonto verknüpfen, dann einladen.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array ok, message.
	 */
	public static function create_or_link( $contact_id ) {
		$contact = ContactRepository::find( (int) $contact_id );

		if ( ! $contact ) {
			return self::result( false, __( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ) );
		}

		if ( self::user_for_contact( (int) $contact['id'] ) ) {
			return self::result( false, __( 'Der Kontakt hat schon ein Kundenkonto.', 'lindenweg16' ) );
		}

		$email = isset( $contact['email'] ) ? (string) $contact['email'] : '';
		if ( ! is_email( $email ) ) {
			return self::result( false, __( 'Für ein Kundenkonto braucht der Kontakt eine E-Mail-Adresse. Bitte zuerst oben eintragen.', 'lindenweg16' ) );
		}

		$user = get_user_by( 'email', $email );

		if ( $user ) {
			if ( ! self::linkable_caps( (array) $user->allcaps ) ) {
				return self::result( false, __( 'Zu dieser E-Mail-Adresse gibt es ein Mitarbeiterkonto. Händlerkonten brauchen eine eigene Adresse.', 'lindenweg16' ) );
			}

			$other = (int) get_user_meta( $user->ID, self::META, true );
			if ( $other && $other !== (int) $contact['id'] && ContactRepository::find( $other ) ) {
				return self::result( false, __( 'Dieses Kundenkonto gehört schon zu einem anderen Kontakt.', 'lindenweg16' ) );
			}

			update_user_meta( $user->ID, self::META, (int) $contact['id'] );
			self::activity( $contact, __( 'Vorhandenes Kundenkonto als Händlerkonto freigeschaltet.', 'lindenweg16' ) );

			$sent = self::send_invite( $contact, $user, false );

			return self::result(
				true,
				$sent
					? __( 'Das vorhandene Kundenkonto ist jetzt freigeschaltet. Der Händler hat eine Mail mit dem Weg zum Nachbestellen bekommen.', 'lindenweg16' )
					: __( 'Das vorhandene Kundenkonto ist jetzt freigeschaltet. Die Mail ging nicht raus – bitte die Mail-Einstellungen prüfen.', 'lindenweg16' )
			);
		}

		$user_id = self::insert_user( $contact );

		if ( is_wp_error( $user_id ) ) {
			return self::result( false, $user_id->get_error_message() );
		}

		update_user_meta( $user_id, self::META, (int) $contact['id'] );
		self::activity( $contact, __( 'Händlerkonto angelegt und Einladung geschickt.', 'lindenweg16' ) );

		$sent = self::send_invite( $contact, get_user_by( 'id', $user_id ), true );

		return self::result(
			true,
			$sent
				? __( 'Konto angelegt. Der Händler hat eine Mail bekommen, mit der er sein Passwort festlegt.', 'lindenweg16' )
				: __( 'Konto angelegt, aber die Mail ging nicht raus – bitte die Mail-Einstellungen prüfen und die Einladung erneut senden.', 'lindenweg16' )
		);
	}

	/**
	 * Einladung erneut senden.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array ok, message.
	 */
	public static function reinvite( $contact_id ) {
		$contact = ContactRepository::find( (int) $contact_id );
		$user    = $contact ? self::user_for_contact( (int) $contact['id'] ) : null;

		if ( ! $user ) {
			return self::result( false, __( 'Der Kontakt hat noch kein Kundenkonto.', 'lindenweg16' ) );
		}

		if ( ! self::send_invite( $contact, $user, true ) ) {
			return self::result( false, __( 'Die Mail konnte nicht verschickt werden. Bitte die Mail-Einstellungen der Website prüfen.', 'lindenweg16' ) );
		}

		return self::result( true, __( 'Einladung erneut geschickt. Der Link zum Passwort gilt 24 Stunden.', 'lindenweg16' ) );
	}

	/**
	 * Verknüpfung lösen. Das Kundenkonto bleibt bestehen, nur der Reiter
	 * „Nachbestellen“ verschwindet.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array ok, message.
	 */
	public static function unlink( $contact_id ) {
		$contact = ContactRepository::find( (int) $contact_id );
		$user    = $contact ? self::user_for_contact( (int) $contact['id'] ) : null;

		if ( ! $user ) {
			return self::result( false, __( 'Es gab keine Verknüpfung.', 'lindenweg16' ) );
		}

		delete_user_meta( $user->ID, self::META );
		self::activity( $contact, __( 'Händlerkonto getrennt; das Kundenkonto besteht weiter.', 'lindenweg16' ) );

		return self::result( true, __( 'Verknüpfung gelöst. Das Kundenkonto bleibt bestehen, nachbestellen kann der Händler darüber nicht mehr.', 'lindenweg16' ) );
	}

	/**
	 * Neues Kundenkonto anlegen, Rechnungsdaten aus dem Kontakt.
	 *
	 * @param array $contact Kontakt.
	 * @return int|\WP_Error
	 */
	protected static function insert_user( array $contact ) {
		$email = (string) $contact['email'];
		$login = function_exists( 'wc_create_new_customer_username' )
			? wc_create_new_customer_username( $email )
			: sanitize_user( current( explode( '@', $email ) ), true );

		$person = trim( (string) $contact['contact_person'] );
		$parts  = '' !== $person ? preg_split( '/\s+/', $person, 2 ) : array( '', '' );
		$first  = isset( $parts[0] ) ? $parts[0] : '';
		$last   = isset( $parts[1] ) ? $parts[1] : '';

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'role'         => get_role( 'customer' ) ? 'customer' : get_option( 'default_role', 'subscriber' ),
				'display_name' => (string) $contact['company'],
				'first_name'   => $first,
				'last_name'    => $last,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$billing = array(
			'billing_company'   => (string) $contact['company'],
			'billing_first_name' => $first,
			'billing_last_name' => $last,
			'billing_address_1' => (string) $contact['street'],
			'billing_postcode'  => (string) $contact['zip'],
			'billing_city'      => (string) $contact['city'],
			'billing_phone'     => (string) $contact['phone'],
			'billing_email'     => $email,
		);

		foreach ( $billing as $key => $value ) {
			if ( '' !== $value ) {
				update_user_meta( $user_id, $key, $value );
			}
		}

		return (int) $user_id;
	}

	/**
	 * Einladung schicken.
	 *
	 * Neues Konto: mit Link zum Passwort-Festlegen. Vorhandenes Konto: nur
	 * der Weg zum Nachbestellen, das Passwort hat der Händler schon.
	 *
	 * @param array    $contact  Kontakt.
	 * @param \WP_User $user     Benutzer.
	 * @param bool     $password Link zum Passwort-Festlegen mitschicken.
	 * @return bool
	 */
	protected static function send_invite( array $contact, $user, $password ) {
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$link = '';

		if ( $password ) {
			$key = get_password_reset_key( $user );
			if ( is_wp_error( $key ) ) {
				return false;
			}
			$link = self::password_url( $user, $key );
		}

		return (bool) MailTemplate::send(
			$user->user_email,
			$password ? 'haendler_zugang' : 'haendler_freigeschaltet',
			array(
				'{firma}'             => (string) $contact['company'],
				'{benutzername}'      => (string) $user->user_email,
				'{passwort_link}'     => $link,
				'{nachbestellen_url}' => self::endpoint_url(),
			)
		);
	}

	/**
	 * Link zum Festlegen des Passworts.
	 *
	 * Über die Passwort-Seite von WooCommerce, damit der Händler im Design
	 * des Shops bleibt; ohne WooCommerce die von WordPress.
	 *
	 * @param \WP_User $user Benutzer.
	 * @param string   $key  Schlüssel.
	 * @return string
	 */
	protected static function password_url( $user, $key ) {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return add_query_arg(
				array(
					'action' => 'newaccount',
					'key'    => $key,
					'id'     => $user->ID,
				),
				wc_get_account_endpoint_url( 'lost-password' )
			);
		}

		return network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
	}

	/**
	 * Vermerk in der Kontakthistorie.
	 *
	 * @param array  $contact Kontakt.
	 * @param string $text    Text.
	 */
	protected static function activity( array $contact, $text ) {
		ContactRepository::add_activity(
			array(
				'contact_id'    => (int) $contact['id'],
				'activity_type' => 'mail',
				'summary'       => $text,
			)
		);
	}

	/**
	 * Ergebnis-Array.
	 *
	 * @param bool   $ok      Erfolg.
	 * @param string $message Meldung.
	 * @return array
	 */
	protected static function result( $ok, $message ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => (string) $message,
		);
	}

	/* ====================================================================
	 * Reiter unter „Mein Konto“
	 * ================================================================== */

	/**
	 * Menüpunkt nach „Bestellungen“ einfügen – reine Logik.
	 *
	 * @param array  $items Menüpunkte.
	 * @param string $key   Schlüssel.
	 * @param string $label Beschriftung.
	 * @return array
	 */
	public static function insert_menu_item( array $items, $key, $label ) {
		$new = array();

		foreach ( $items as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'orders' === $k ) {
				$new[ $key ] = $label;
			}
		}

		if ( isset( $new[ $key ] ) ) {
			return $new;
		}

		// Ohne „Bestellungen“: vor „Abmelden“, sonst ans Ende.
		$out = array();
		foreach ( $new as $k => $v ) {
			if ( 'customer-logout' === $k ) {
				$out[ $key ] = $label;
			}
			$out[ $k ] = $v;
		}
		if ( ! isset( $out[ $key ] ) ) {
			$out[ $key ] = $label;
		}

		return $out;
	}

	/**
	 * Menüpunkt nur für Händler.
	 *
	 * @param array $items Menüpunkte.
	 * @return array
	 */
	public function menu_item( $items ) {
		if ( ! self::contact_for_user( get_current_user_id() ) ) {
			return $items;
		}

		return self::insert_menu_item( (array) $items, self::ENDPOINT, __( 'Nachbestellen', 'lindenweg16' ) );
	}

	/**
	 * Seitentitel.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Nachbestellen', 'lindenweg16' );
	}

	/**
	 * Ist gerade der Reiter „Nachbestellen“ offen?
	 *
	 * @return bool
	 */
	protected static function is_endpoint() {
		global $wp_query;

		return function_exists( 'is_account_page' ) && is_account_page()
			&& $wp_query instanceof \WP_Query && isset( $wp_query->query_vars[ self::ENDPOINT ] );
	}

	/**
	 * Stil und Konditionsanzeige laden.
	 */
	public function enqueue() {
		if ( ! self::is_endpoint() ) {
			return;
		}

		$contact = self::contact_for_user( get_current_user_id() );

		ReorderPage::enqueue_assets( $contact ? ReorderPage::staffel_config( $contact, ReorderPage::catalog() ) : array() );
	}

	/**
	 * Anfrage aus dem Konto entgegennehmen.
	 *
	 * Nach dem Absenden zurück auf den Reiter (Post/Redirect/Get), damit ein
	 * Neuladen keine zweite Anfrage erzeugt.
	 */
	public function handle_submit() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Prüfung folgt.
		if ( empty( $_POST[ self::FIELD ] ) || ! is_user_logged_in() ) {
			return;
		}

		$back = self::endpoint_url();

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			self::notice( __( 'Die Seite war zu lange offen. Bitte die Mengen noch einmal eintragen und absenden.', 'lindenweg16' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		$contact = self::contact_for_user( get_current_user_id() );

		if ( ! $contact ) {
			wp_safe_redirect( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) );
			exit;
		}

		$request = ReorderPage::submit_request( $contact, wp_unslash( $_POST ) );

		if ( ! $request['ok'] ) {
			self::notice( $request['error'], 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		self::notice(
			sprintf(
				/* translators: %d: Nummer der Anfrage. */
				__( 'Danke, Ihre Anfrage Nr. %d ist eingegangen. Wir prüfen Verfügbarkeit und Konditionen und bestätigen Ihnen den Auftrag; erst dann stehen Preise, Mengen und Liefertermin fest.', 'lindenweg16' ),
				$request['order_id']
			),
			'success'
		);

		wp_safe_redirect( $back . '#lw16-anfragen' );
		exit;
	}

	/**
	 * Hinweis über WooCommerce anzeigen.
	 *
	 * @param string $text Text.
	 * @param string $type success|error|notice.
	 */
	protected static function notice( $text, $type ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $text, $type );
		}
	}

	/**
	 * Inhalt des Reiters.
	 */
	public function render() {
		$contact = self::contact_for_user( get_current_user_id() );

		if ( ! $contact ) {
			printf( '<p>%s</p>', esc_html__( 'Dieser Bereich ist für unsere Händler. Sie sind Händler und sehen hier nichts? Dann melden Sie sich bitte bei uns, wir schalten Ihr Konto frei.', 'lindenweg16' ) );
			return;
		}

		echo '<div class="lw16-haendler">';

		printf(
			'<p class="lw16-haendler__intro">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: Firma. */
					__( 'Hier bestellen Sie für %s nach. Ihre Konditionen sind schon eingerechnet; die Anfrage ist unverbindlich, wir bestätigen Ihnen den Auftrag.', 'lindenweg16' ),
					$contact['company']
				)
			)
		);

		echo ReorderPage::form_html( $contact, '', self::FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapt in form_html().

		echo self::requests_html( (int) $contact['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapt in requests_html().

		echo '</div>';
	}

	/**
	 * Status einer Anfrage, wie der Händler ihn liest – reine Logik.
	 *
	 * @param string $order_status   entwurf|bestaetigt|storniert.
	 * @param string $invoice_status offen|berechnet|bezahlt|storniert.
	 * @return string
	 */
	public static function status_label( $order_status, $invoice_status ) {
		if ( 'storniert' === $order_status || 'storniert' === $invoice_status ) {
			return __( 'Storniert', 'lindenweg16' );
		}

		if ( 'entwurf' === $order_status ) {
			return __( 'Angefragt, wird geprüft', 'lindenweg16' );
		}

		if ( 'bezahlt' === $invoice_status ) {
			return __( 'Bezahlt', 'lindenweg16' );
		}

		if ( 'berechnet' === $invoice_status ) {
			return __( 'Bestätigt, Rechnung gestellt', 'lindenweg16' );
		}

		return __( 'Bestätigt', 'lindenweg16' );
	}

	/**
	 * Bisherige Anfragen und Aufträge (escapt).
	 *
	 * Nur Datum, Nummer, Positionen, Status und Wert – keine Kosten,
	 * Margen oder internen Notizen.
	 *
	 * @param int $contact_id Kontakt.
	 * @return string
	 */
	protected static function requests_html( $contact_id ) {
		$orders = OrderOpsRepository::all(
			array(
				'contact_id' => $contact_id,
				'limit'      => 20,
			)
		);

		ob_start();

		echo '<section class="lw16-haendler__anfragen" id="lw16-anfragen">';
		printf( '<h2>%s</h2>', esc_html__( 'Ihre Anfragen und Aufträge', 'lindenweg16' ) );

		if ( ! $orders ) {
			printf( '<p class="lw16-reorder__hint">%s</p>', esc_html__( 'Noch keine. Ihre erste Anfrage erscheint hier, sobald Sie sie abgeschickt haben.', 'lindenweg16' ) );
			echo '</section>';
			return (string) ob_get_clean();
		}

		$articles = ArticleRepository::all_by_id();

		printf(
			'<table class="shop_table shop_table_responsive woocommerce-orders-table lw16-haendler__tabelle"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th class="lw16-haendler__wert">%s</th></tr></thead><tbody>',
			esc_html__( 'Nr.', 'lindenweg16' ),
			esc_html__( 'Datum', 'lindenweg16' ),
			esc_html__( 'Positionen', 'lindenweg16' ),
			esc_html__( 'Status', 'lindenweg16' ),
			esc_html__( 'Wert', 'lindenweg16' )
		);

		foreach ( $orders as $order ) {
			$wc     = (int) $order['wc_order_id'];
			$number = $wc
				/* translators: %d: Bestellnummer im Shop. */
				? sprintf( __( 'Shop #%d', 'lindenweg16' ), $wc )
				: (string) (int) $order['id'];

			$lines = array();
			foreach ( OrderOpsRepository::items( (int) $order['id'] ) as $item ) {
				$aid     = (int) $item['article_id'];
				$name    = isset( $articles[ $aid ] ) ? (string) $articles[ $aid ]['sku'] : '';
				$lines[] = '<span class="lw16-haendler__pos">' . esc_html( number_format_i18n( (float) $item['qty'], 0 ) . ' × ' . $name . ReorderPage::color_suffix( $item ) ) . '</span>';
			}

			$date  = OrderOpsRepository::order_date( $order );
			$value = OrderOpsRepository::order_value( $order );
			$draft = 'entwurf' === $order['order_status'];

			printf(
				'<tr class="woocommerce-orders-table__row%6$s"><td data-title="%7$s">%1$s</td><td data-title="%8$s">%2$s</td><td data-title="%9$s">%3$s</td><td data-title="%10$s"><span class="lw16-haendler__status">%4$s</span></td><td class="lw16-haendler__wert" data-title="%11$s">%5$s</td></tr>',
				esc_html( $number ),
				esc_html( $date ? date_i18n( 'd.m.Y', strtotime( $date ) ) : '' ),
				$lines ? '<span class="lw16-haendler__posliste">' . implode( '', $lines ) . '</span>' : '–', // Einzeln escapt.
				esc_html( self::status_label( (string) $order['order_status'], (string) $order['invoice_status'] ) ),
				'<span>' . esc_html( number_format_i18n( $value, 2 ) . ' €' ) . '</span>' . ( $draft ? '<small>' . esc_html__( 'vorläufig', 'lindenweg16' ) . '</small>' : '' ),
				$draft ? ' is-draft' : '',
				esc_attr__( 'Nr.', 'lindenweg16' ),
				esc_attr__( 'Datum', 'lindenweg16' ),
				esc_attr__( 'Positionen', 'lindenweg16' ),
				esc_attr__( 'Status', 'lindenweg16' ),
				esc_attr__( 'Wert', 'lindenweg16' )
			);
		}

		echo '</tbody></table></section>';

		return (string) ob_get_clean();
	}
}
