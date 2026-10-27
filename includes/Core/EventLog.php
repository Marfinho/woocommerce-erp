<?php
/**
 * Sammelt die Ereignisse des ERP im Ereignisprotokoll.
 *
 * Statt an hundert Stellen im Code einzeln zu protokollieren, hört diese
 * Klasse auf Ereignisse, die es ohnehin gibt: das Änderungsprotokoll (jede
 * geschriebene Änderung), WooCommerce (Bestellungen, Lagerwarnungen),
 * WordPress (Anmeldungen, Mailfehler, Updates) und PHP selbst (Abbrüche im
 * Plugin). Was darüber hinaus wichtig ist, ruft `Log::normal()`,
 * `Log::attention()` oder `Log::critical()` direkt auf.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Hängt das Ereignisprotokoll an die vorhandenen Ereignisse.
 */
class EventLog {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_audit_written', array( $this, 'on_audit' ), 10, 1 );
		add_action( 'lw16_settings_updated', array( $this, 'on_settings' ), 10, 1 );
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 1 );
		add_action( 'wp_mail_failed', array( $this, 'on_mail_failed' ), 10, 1 );
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status' ), 20, 3 );
		add_action( 'woocommerce_low_stock', array( $this, 'on_low_stock' ), 20, 1 );
		add_action( 'woocommerce_no_stock', array( $this, 'on_no_stock' ), 20, 1 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 20, 2 );
		add_action( 'lw16_betrieb_stock_replenished', array( $this, 'on_replenished' ), 20, 2 );

		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
	}

	/**
	 * Stufe und Bereich einer Änderung im Änderungsprotokoll - reine Regel.
	 *
	 * Eine Stornierung ist nichts Gewöhnliches, aber auch kein Fehler:
	 * Aufmerksamkeit. Alles andere ist der normale Lauf.
	 *
	 * @param array $data Eintrag des Änderungsprotokolls (aktion, objekt_typ, objekt_id).
	 * @return array {level, area, message}
	 */
	public static function classify_audit( array $data ) {
		$aktion = isset( $data['aktion'] ) ? (string) $data['aktion'] : '';
		$typ    = isset( $data['objekt_typ'] ) ? (string) $data['objekt_typ'] : '';
		$id     = ! empty( $data['objekt_id'] ) ? ' #' . (int) $data['objekt_id'] : '';

		$level = ( false !== strpos( $aktion, 'void' ) || false !== strpos( $aktion, 'storno' ) || false !== strpos( $aktion, 'delete' ) || false !== strpos( $aktion, 'loesch' ) )
			? Log::ATTENTION
			: Log::NORMAL;

		return array(
			'level'   => $level,
			'area'    => self::area_of( $typ ),
			'message' => trim( $aktion . ': ' . $typ . $id ),
		);
	}

	/**
	 * Bereich zu einem Objekttyp - reine Regel.
	 *
	 * @param string $type Objekttyp (meist ein Tabellenname).
	 * @return string
	 */
	public static function area_of( $type ) {
		$type = (string) $type;

		foreach ( array(
			'lager'       => array( 'stock', 'bin', 'component', 'batch', 'picklist' ),
			'bestellung'  => array( 'order', 'bestell' ),
			'produktion'  => array( 'print', 'work_order', 'filament', 'bambu', 'printer' ),
			'artikel'     => array( 'article', 'bom', 'bundle', 'revision', 'sourcing' ),
			'buchhaltung' => array( 'beleg', 'buchung', 'rechnung', 'kasse', 'konto', 'kampagne' ),
			'versand'     => array( 'dhl', 'shipping', 'label' ),
			'personal'    => array( 'time', 'payslip', 'wage', 'user' ),
		) as $area => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $type, $needle ) ) {
					return $area;
				}
			}
		}

		return '' !== $type ? 'daten' : 'system';
	}

	/**
	 * Eine Änderung wurde im Änderungsprotokoll festgehalten.
	 *
	 * @param array $data Eintrag.
	 */
	public function on_audit( $data ) {
		$c = self::classify_audit( (array) $data );

		if ( Log::ATTENTION === $c['level'] ) {
			Log::attention( $c['area'], $c['message'] );
		} else {
			Log::normal( $c['area'], $c['message'] );
		}
	}

	/**
	 * Einstellungen geändert - nur die Namen der Felder.
	 *
	 * @param array $keys Geänderte Felder.
	 */
	public function on_settings( $keys ) {
		Log::normal( 'einstellungen', 'Einstellungen gespeichert: ' . implode( ', ', array_slice( array_map( 'strval', (array) $keys ), 0, 12 ) ) );
	}

	/**
	 * Anmeldung.
	 *
	 * @param string   $login Benutzername.
	 * @param \WP_User $user  Person.
	 */
	public function on_login( $login, $user ) {
		// Nur Mitarbeitende: Kundenanmeldungen im Shop gehören nicht in ein Betriebsbuch.
		if ( self::is_staff( $user ) ) {
			Log::normal( 'konto', 'Angemeldet: ' . $login, array( 'user' => (int) $user->ID ) );
		}
	}

	/**
	 * Gehört die Person zum Betrieb (Verwaltung oder ERP-Rechte)?
	 *
	 * @param mixed $user Person.
	 * @return bool
	 */
	protected static function is_staff( $user ) {
		return $user instanceof \WP_User && ( user_can( $user, 'manage_options' ) || \Lindenweg16\Erp\Access::has_any( $user->ID ) );
	}

	/**
	 * Fehlgeschlagene Anmeldung; viele in kurzer Zeit sind kritisch.
	 *
	 * @param string $login Benutzername.
	 */
	public function on_login_failed( $login ) {
		$key   = 'lw16_loginfail_' . substr( md5( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '' ), 0, 12 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 15 * MINUTE_IN_SECONDS );

		// Den Namen nur nennen, wenn es ein Konto des Betriebs ist - sonst
		// stünden Tippfehler und Kundennamen im Protokoll.
		$name = self::is_staff( get_user_by( 'login', (string) $login ) ) ? (string) $login : 'unbekanntes oder Kundenkonto';

		if ( self::is_brute_force( $count ) ) {
			Log::critical( 'konto', 'Wiederholt fehlgeschlagene Anmeldung (' . $count . ' in 15 Minuten): ' . $name );
		} else {
			Log::attention( 'konto', 'Anmeldung fehlgeschlagen: ' . $name );
		}
	}

	/**
	 * Sind es so viele Fehlversuche, dass es nach einem Angriff aussieht? Reine Regel.
	 *
	 * @param int $count Fehlversuche in 15 Minuten.
	 * @return bool
	 */
	public static function is_brute_force( $count ) {
		return (int) $count >= 5;
	}

	/**
	 * Eine E-Mail ließ sich nicht senden.
	 *
	 * @param \WP_Error $error Fehler.
	 */
	public function on_mail_failed( $error ) {
		Log::attention( 'mail', 'E-Mail konnte nicht gesendet werden: ' . ( is_wp_error( $error ) ? $error->get_error_message() : '' ) );
	}

	/**
	 * Neue Bestellung.
	 *
	 * @param int $order_id Bestellung.
	 */
	public function on_new_order( $order_id ) {
		Log::normal( 'bestellung', 'Neue Bestellung #' . (int) $order_id, array( 'order_id' => (int) $order_id ) );
	}

	/**
	 * Statuswechsel einer Bestellung.
	 *
	 * @param int    $order_id Bestellung.
	 * @param string $from     Alter Status.
	 * @param string $to       Neuer Status.
	 */
	public function on_order_status( $order_id, $from, $to ) {
		$message = 'Bestellung #' . (int) $order_id . ': ' . $from . ' → ' . $to;
		$context = array( 'order_id' => (int) $order_id );

		if ( self::status_needs_attention( $to ) ) {
			Log::attention( 'bestellung', $message, $context );
		} else {
			Log::normal( 'bestellung', $message, $context );
		}
	}

	/**
	 * Verdient ein neuer Bestellstatus Aufmerksamkeit? Reine Regel.
	 *
	 * @param string $status Status ohne Präfix.
	 * @return bool
	 */
	public static function status_needs_attention( $status ) {
		return in_array( (string) $status, array( 'cancelled', 'refunded', 'failed' ), true );
	}

	/**
	 * Niedriger Bestand laut WooCommerce.
	 *
	 * @param \WC_Product $product Produkt.
	 */
	public function on_low_stock( $product ) {
		Log::attention( 'lager', 'Niedriger Bestand: ' . ( is_object( $product ) ? $product->get_name() : '' ) );
	}

	/**
	 * Ausverkauft laut WooCommerce.
	 *
	 * @param \WC_Product $product Produkt.
	 */
	public function on_no_stock( $product ) {
		Log::attention( 'lager', 'Ausverkauft: ' . ( is_object( $product ) ? $product->get_name() : '' ) );
	}

	/**
	 * Ein Artikel ist wieder lieferbar.
	 *
	 * @param int   $article_id Artikel.
	 * @param float $stock      Neuer Bestand.
	 */
	public function on_replenished( $article_id, $stock = 0 ) {
		Log::normal( 'lager', 'Wieder lieferbar: Artikel #' . (int) $article_id . ' (Bestand ' . (float) $stock . ')' );
	}

	/**
	 * Ein Update ist durchgelaufen.
	 *
	 * @param mixed $upgrader Upgrader.
	 * @param array $extra    Angaben zum Update.
	 */
	public function on_upgrade( $upgrader, $extra ) {
		$plugins = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );

		if ( defined( 'LW16_PLUGIN_BASENAME' ) && in_array( LW16_PLUGIN_BASENAME, $plugins, true ) ) {
			Log::normal( 'update', 'Plugin aktualisiert (jetzt Version ' . LW16_VERSION . ')' );
		}
	}

	/**
	 * Beim Beenden der Anfrage: Ist PHP im Plugin abgebrochen?
	 */
	public static function on_shutdown() {
		$error = error_get_last();

		if ( ! $error || ! self::is_plugin_fatal( $error, defined( 'LW16_PLUGIN_DIR' ) ? LW16_PLUGIN_DIR : '' ) ) {
			return;
		}

		Log::critical( 'php', $error['message'], array( 'datei' => basename( (string) $error['file'] ), 'zeile' => (int) $error['line'] ) );
	}

	/**
	 * Ist das ein Abbruch in unserem Code? Reine Regel.
	 *
	 * @param array  $error      Ergebnis von error_get_last().
	 * @param string $plugin_dir Ordner des Plugins.
	 * @return bool
	 */
	public static function is_plugin_fatal( array $error, $plugin_dir ) {
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

		return in_array( (int) $error['type'], $fatal, true )
			&& '' !== (string) $plugin_dir
			&& 0 === strpos( str_replace( '\\', '/', (string) $error['file'] ), str_replace( '\\', '/', (string) $plugin_dir ) );
	}
}
