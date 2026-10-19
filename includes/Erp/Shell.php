<?php
/**
 * Die ERP-Oberfläche unter /erp.
 *
 * Eine eigene, schlanke Seite ohne Theme und ohne wp-admin-Gerüst. Sie
 * lädt eine kleine Browser-Anwendung (assets/erp/), die Navigation,
 * Kennzahlen und Rechteverwaltung über die REST-Schnittstelle bezieht
 * und die bestehenden wp-admin-Seiten eingebettet anzeigt.
 *
 * Angemeldet wird mit dem normalen WordPress-Konto. Die Adresse steht an
 * einer Stelle (url()); ein späterer Umzug auf eine Subdomain ändert nur
 * diese Methode und den Cookie-Bereich von WordPress.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;

/**
 * Route, Seitenrahmen und Einbettung.
 */
class Shell {

	/**
	 * Abfragevariable der Route.
	 */
	const QUERY_VAR = 'lw16_erp';

	/**
	 * Merker, dass die Rewrite-Regel einmal gespeichert wurde.
	 */
	const REWRITE_OPTION = 'lw16_erp_rewrite';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_link' ), 90 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'gate_plugin_pages' ) );
			add_action( 'admin_head', array( $this, 'embed_styles' ) );
		}

		( new RestApi() )->register();
	}

	/**
	 * Adresse der Oberfläche.
	 *
	 * @return string
	 */
	public static function url() {
		/**
		 * Adresse der ERP-Oberfläche – z. B. für eine Subdomain.
		 *
		 * @param string $url Vorgabe: /erp/ unter der Shop-Adresse.
		 */
		return apply_filters( 'lw16_erp_url', home_url( '/erp/' ) );
	}

	/**
	 * Rewrite-Regel anlegen.
	 */
	public function add_rewrite() {
		add_rewrite_rule( '^erp(?:/.*)?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Regel nach dem ersten Laden dieser Fassung bekanntmachen.
	 */
	public function maybe_flush_rewrite() {
		if ( '1' !== get_option( self::REWRITE_OPTION ) ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, '1' );
		}
	}

	/**
	 * Abfragevariable freigeben.
	 *
	 * @param array $vars Bekannte Variablen.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Link „ERP“ in der Adminleiste für alle mit Zugang.
	 *
	 * @param \WP_Admin_Bar $bar Adminleiste.
	 */
	public function admin_bar_link( $bar ) {
		if ( ! Access::has_any() ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'lw16-erp',
				'title' => esc_html__( 'ERP', 'lindenweg16' ),
				'href'  => self::url(),
			)
		);
	}

	/**
	 * Seite ausliefern, wenn /erp aufgerufen wurde.
	 */
	public function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Nie zwischenspeichern: Die Seite zeigt je Person etwas anderes.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'lw16 erp' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}

		if ( ! Access::has_any() ) {
			status_header( 403 );
			$this->render_denied();
			exit;
		}

		$this->render_app();
		exit;
	}

	/**
	 * Rahmen der Anwendung.
	 */
	protected function render_app() {
		$config = array(
			'rest'     => esc_url_raw( rest_url( 'lw16/v1/erp/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'erpUrl'   => self::url(),
			'adminUrl' => admin_url( '/' ),
			'shopUrl'  => home_url( '/' ),
			'siteName' => get_bloginfo( 'name' ),
			'logout'   => wp_logout_url( self::url() ),
			'version'  => LW16_VERSION,
		);

		$css = 'assets/erp/erp.css';
		$js  = 'assets/erp/erp.js';
		$ico = get_site_icon_url( 64 );

		echo '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '"><head>';
		echo '<meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
		echo '<meta name="robots" content="noindex, nofollow">';
		echo '<meta name="color-scheme" content="light dark">';
		echo '<meta name="theme-color" content="#2b323a">';
		echo '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">';
		echo '<title>' . esc_html( 'ERP · ' . $config['siteName'] ) . '</title>';
		if ( $ico ) {
			echo '<link rel="icon" href="' . esc_url( $ico ) . '">';
		}
		echo '<link rel="stylesheet" href="' . esc_url( LW16_PLUGIN_URL . $css . '?ver=' . Assets::version( $css ) ) . '">';
		echo '<script>window.LW16ERP=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';</script>';
		echo '</head><body>';
		echo '<div id="app" class="erp-boot">' . esc_html__( 'ERP wird geladen …', 'lindenweg16' ) . '</div>';
		echo '<noscript><p class="erp-noscript">' . esc_html__( 'Das ERP braucht JavaScript.', 'lindenweg16' ) . '</p></noscript>';
		echo '<script src="' . esc_url( LW16_PLUGIN_URL . $js . '?ver=' . Assets::version( $js ) ) . '" defer></script>';
		echo '</body></html>';
	}

	/**
	 * Seite für angemeldete Personen ohne ERP-Recht.
	 */
	protected function render_denied() {
		echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<meta name="robots" content="noindex, nofollow"><title>' . esc_html__( 'Kein Zugriff', 'lindenweg16' ) . '</title></head>';
		echo '<body style="font:16px/1.5 system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem">';
		echo '<h1>' . esc_html__( 'Kein Zugriff', 'lindenweg16' ) . '</h1>';
		echo '<p>' . esc_html__( 'Für dein Konto sind keine ERP-Rechte eingerichtet. Bitte wende dich an die Betriebsleitung.', 'lindenweg16' ) . '</p>';
		echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Zum Shop', 'lindenweg16' ) . '</a> · <a href="' . esc_url( wp_logout_url( self::url() ) ) . '">' . esc_html__( 'Abmelden', 'lindenweg16' ) . '</a></p>';
		echo '</body></html>';
	}

	/**
	 * Läuft diese Anfrage in einem Rahmen der ERP-Oberfläche?
	 *
	 * Browser melden bei jeder Navigation im Rahmen „Sec-Fetch-Dest:
	 * iframe“ – auch nach dem Klick auf einen Link oder dem Absenden eines
	 * Formulars. Der Parameter dient Browsern, die das nicht senden.
	 * Sicherheit hängt daran nicht: Es werden nur Bedienelemente
	 * ausgeblendet.
	 *
	 * @return bool
	 */
	public static function is_embedded() {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_embed'] ) ) {
			return true;
		}

		return isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && 'iframe' === $_SERVER['HTTP_SEC_FETCH_DEST'];
	}

	/**
	 * Im Rahmen das wp-admin-Gerüst ausblenden.
	 */
	public function embed_styles() {
		if ( ! self::is_embedded() ) {
			return;
		}

		echo '<style id="lw16-embed">'
			. '#adminmenumain,#wpadminbar,#wpfooter,#screen-meta,#screen-meta-links{display:none!important}'
			. 'html.wp-toolbar{padding-top:0!important}'
			. '#wpcontent,#wpfooter{margin-left:0!important}'
			. '#wpbody-content{padding-bottom:24px}'
			. 'body.wp-admin{background:#f4f2ee}'
			. '</style>';
	}

	/**
	 * Plugin-Seiten nur öffnen, wenn das feine Recht dazu da ist.
	 *
	 * Das alte Menü prüft nur die sechs groben Rechte. Ohne diese Sperre
	 * käme jemand, der „Material“ nicht sehen soll, aber „Artikel“ darf,
	 * über die Adresse trotzdem hinein. Vollzugriff ist ausgenommen.
	 */
	public function gate_plugin_pages() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$item = Navigation::find_by_page( $page );

		if ( ! $item || ! is_user_logged_in() || Access::is_full() ) {
			return;
		}

		if ( ! Access::can( $item['perm'] ) ) {
			wp_die( esc_html__( 'Dafür fehlt dir das Recht in der ERP-Oberfläche.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}
	}
}
