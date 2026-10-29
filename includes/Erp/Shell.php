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

use Lindenweg16\Core\Settings;

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
		( new Subdomain() )->register();
		LiveViewer::register();

		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_link' ), 90 );
		add_filter( 'show_admin_bar', array( $this, 'show_admin_bar' ) );
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 20, 3 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'lock_wpadmin' ), 1 );
			add_action( 'admin_init', array( $this, 'gate_plugin_pages' ) );
			add_action( 'admin_menu', array( $this, 'maybe_hide_menus' ), 9999 );
			add_action( 'admin_init', array( $this, 'gate_actions' ), 2 );
			add_action( 'admin_init', array( $this, 'dashboard_to_erp' ), 3 );
			add_action( 'admin_init', array( $this, 'classic_to_erp' ), 4 );
			add_action( 'admin_head', array( $this, 'embed_styles' ) );
			add_action( 'admin_post_lw16_erp_run_file', array( $this, 'serve_run_file' ) );
			add_action( 'admin_post_lw16_erp_payslip_pdf', array( PayslipPdf::class, 'serve_pdf' ) );
			add_action( 'admin_post_lw16_erp_payslip_csv', array( PayslipPdf::class, 'serve_csv' ) );
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
		 * @param string $url Vorgabe: /erp/ unter der Shop-Adresse, mit
		 *                    LW16_ERP_HOST die Subdomain.
		 */
		$url = Subdomain::host() ? 'https://' . Subdomain::host() . '/' : home_url( '/erp/' );

		return apply_filters( 'lw16_erp_url', $url );
	}

	/**
	 * Rewrite-Regel anlegen.
	 */
	public function add_rewrite() {
		add_rewrite_rule( '^erp(?:/.*)?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );

		// Auf der Subdomain liegen Manifest und Service Worker an der Wurzel.
		if ( Subdomain::host() ) {
			add_rewrite_rule( '^(?:manifest\.webmanifest|sw\.js)$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}
	}

	/**
	 * Regel nach dem ersten Laden dieser Fassung bekanntmachen.
	 */
	public function maybe_flush_rewrite() {
		// Der Merker enthält die Subdomain: Wer sie später einstellt oder
		// ändert, bekommt die Regeln neu geschrieben.
		$marker = '1:' . Subdomain::host();

		if ( get_option( self::REWRITE_OPTION ) !== $marker ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, $marker );
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
	 * Adminleiste im Frontend nur für Team: Normale Kunden-Konten sehen sie nicht.
	 *
	 * @param bool $show Ob die Leiste angezeigt wird.
	 * @return bool
	 */
	public function show_admin_bar( $show ) {
		if ( ! $show ) {
			return false;
		}

		// Ist das wp-admin gesperrt, brauchen Mitarbeiter die Leiste mit ihren
		// wp-admin-Links nicht; sie arbeiten im ERP.
		if ( Workflow::settings()['lock_wpadmin'] && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return Access::has_any() || current_user_can( 'edit_posts' );
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
		$path    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$on_host = Subdomain::is_request( isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$route   = (bool) get_query_var( self::QUERY_VAR );
		$root    = '/' === $path || '/index.php' === $path || (bool) preg_match( '#^/p/\d+/?$#', $path );

		// Auf der Subdomain gibt es nur die Oberfläche, nicht den Shop.
		if ( $on_host && ! $route && ! $root ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		if ( ! $route && ! ( $on_host && $root ) ) {
			return;
		}

		$special = self::special_request();

		// Manifest und Service Worker an der Wurzel gibt es nur auf der Subdomain.
		if ( $special && Subdomain::host() && ! $on_host && 0 !== strpos( $path, '/erp/' ) ) {
			return;
		}

		if ( 'sw' === $special ) {
			$this->render_sw();
			exit;
		}

		// Das App-Manifest enthält nichts Persönliches und wird vom Browser
		// ohne Anmeldung abgerufen – es muss vor der Anmeldeprüfung kommen.
		if ( 'manifest' === $special ) {
			$this->render_manifest();
			exit;
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
			// Zurück auf genau diese Seite: Der QR-Code an der Platte führt
			// auf /p/<nummer>, und wer sich dafür anmelden muss, soll danach
			// dort landen – nicht auf der Startseite.
			wp_safe_redirect( wp_login_url( self::current_url() ) );
			exit;
		}

		if ( ! LiveViewer::can_open() ) {
			status_header( 403 );
			$this->render_denied();
			exit;
		}

		$this->render_app();
		exit;
	}

	/**
	 * Adresse der aktuellen Anfrage.
	 *
	 * @return string
	 */
	protected static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Nur Adressen der eigenen Website; alles andere fällt auf die Startseite zurück.
		$wanted = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
		$home   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$this_h = (string) wp_parse_url( $wanted, PHP_URL_HOST );

		if ( $this_h === $home || ( Subdomain::host() && Subdomain::is_request( $this_h ) ) ) {
			return $wanted;
		}

		return self::url();
	}

	/**
	 * Was verlangt der Browser außer der Oberfläche selbst?
	 *
	 * @return string manifest, sw oder leer.
	 */
	protected static function special_request() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( 'manifest.webmanifest' === basename( $path ) ) {
			return 'manifest';
		}

		return 'sw.js' === basename( $path ) ? 'sw' : '';
	}

	/**
	 * Service Worker ausliefern.
	 *
	 * Er hält die Oberfläche ohne Verbindung bereit (assets/erp/sw.js).
	 * Ohne Anmeldung abrufbar, weil der Browser ihn so anfordert; er
	 * enthält nichts Persönliches.
	 */
	protected function render_sw() {
		$source = (string) file_get_contents( LW16_PLUGIN_DIR . 'assets/erp/sw.js' );
		$scope  = (string) wp_parse_url( self::url(), PHP_URL_PATH );

		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . ( '' !== $scope ? $scope : '/' ) );
		header( 'Cache-Control: no-cache' );

		$source = str_replace( '__VERSION__', LW16_VERSION . '-' . Assets::version( 'assets/erp/sw.js' ), $source );
		echo str_replace( '__PUSH_URL__', esc_url_raw( rest_url( 'lw16/v1/erp/push/abruf' ) ), $source ); // phpcs:ignore WordPress.Security.EscapeOutput -- eigenes Skript, nur Versionsnummer eingesetzt.
	}

	/**
	 * Manifest, damit sich die Oberfläche auf Handy und Tablet wie eine App
	 * auf den Startbildschirm legen lässt. Symbole kommen aus dem
	 * Website-Symbol von WordPress; ohne eines bietet der Browser das
	 * Installieren meist nicht an.
	 */
	protected function render_manifest() {
		$icons = array();

		foreach ( array( 192, 512 ) as $size ) {
			$url = get_site_icon_url( $size );
			if ( $url ) {
				$icons[] = array(
					'src'   => $url,
					'sizes' => $size . 'x' . $size,
					'type'  => 'image/png',
				);
			}
		}

		$path = (string) wp_parse_url( self::url(), PHP_URL_PATH );

		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		echo wp_json_encode(
			array(
				'name'             => 'ERP · ' . get_bloginfo( 'name' ),
				'short_name'       => 'ERP',
				'start_url'        => self::url(),
				'scope'            => '' !== $path ? $path : '/',
				'display'          => 'standalone',
				'background_color' => '#f4f2ee',
				'theme_color'      => '#2b323a',
				'lang'             => 'de',
				'icons'            => $icons,
			)
		);
	}

	/**
	 * Adresse einer Plugin-Datei – auf der Subdomain mit deren Adresse, damit
	 * Skript und Seite dieselbe Herkunft haben (nötig für den Service Worker).
	 *
	 * @param string $relative Pfad ab Plugin-Wurzel.
	 * @return string
	 */
	protected function asset_url( $relative ) {
		$url = LW16_PLUGIN_URL . ltrim( $relative, '/' );

		if ( Subdomain::is_request( isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return Subdomain::swap_host( $url, Subdomain::host() );
		}

		return $url;
	}

	/**
	 * Rahmen der Anwendung.
	 */
	protected function render_app() {
		$config = array(
			'rest'     => esc_url_raw( rest_url( 'lw16/v1/erp/' ) ),
			'api'      => esc_url_raw( rest_url( 'lw16/v1/' ) ),
			'jsqr'     => $this->asset_url( 'assets/vendor/jsqr/jsQR.js' ),
			'sw'       => self::url() . 'sw.js',
			'swScope'  => (string) wp_parse_url( self::url(), PHP_URL_PATH ),
			'erpPath'  => (string) wp_parse_url( self::url(), PHP_URL_PATH ),
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
		echo '<link rel="manifest" href="' . esc_url( self::url() . 'manifest.webmanifest' ) . '">';
		echo '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">';
		echo '<title>' . esc_html( 'ERP · ' . $config['siteName'] ) . '</title>';
		if ( $ico ) {
			echo '<link rel="icon" href="' . esc_url( $ico ) . '">';
		}
		echo '<link rel="stylesheet" href="' . esc_url( $this->asset_url( $css ) . '?ver=' . Assets::version( $css ) ) . '">';
		foreach ( (array) glob( LW16_PLUGIN_DIR . 'assets/erp/pages/*.css' ) as $page ) {
			$rel = 'assets/erp/pages/' . basename( $page );
			echo '<link rel="stylesheet" href="' . esc_url( $this->asset_url( $rel ) . '?ver=' . Assets::version( $rel ) ) . '">';
		}
		echo '<script>window.LW16ERP=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';</script>';
		echo '</head><body>';
		echo '<div id="app" class="erp-boot">' . esc_html__( 'ERP wird geladen …', 'lindenweg16' ) . '</div>';
		echo '<noscript><p class="erp-noscript">' . esc_html__( 'Das ERP braucht JavaScript.', 'lindenweg16' ) . '</p></noscript>';
		// Seiten in eigenen Dateien (assets/erp/pages/*.js) laden vor dem Gerüst;
		// sie melden sich nur an und laufen erst, wenn die Seite gebraucht wird.
		foreach ( (array) glob( LW16_PLUGIN_DIR . 'assets/erp/pages/*.js' ) as $page ) {
			$rel = 'assets/erp/pages/' . basename( $page );
			echo '<script src="' . esc_url( $this->asset_url( $rel ) . '?ver=' . Assets::version( $rel ) ) . '" defer></script>';
		}
		echo '<script src="' . esc_url( $this->asset_url( $js ) . '?ver=' . Assets::version( $js ) ) . '" defer></script>';
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
	 * ERP-Menüs aus der wp-admin-Seitenleiste nehmen (Einstellung).
	 *
	 * Gedacht für den Tag, an dem das ERP die Arbeit übernommen hat: Das
	 * wp-admin zeigt dann nur noch die Website. Die Seiten bleiben unter
	 * ihrer Adresse erreichbar – die eingebetteten Ansichten im ERP laden
	 * sie weiterhin –, es verschwindet nur der Menüeintrag.
	 */
	public function maybe_hide_menus() {
		if ( ! Workflow::settings()['hide_wpadmin'] ) {
			return;
		}

		foreach ( array(
			\Lindenweg16\Admin\MenuGroups::VERKAUF,
			\Lindenweg16\Admin\MenuGroups::FERTIGUNG,
			\Lindenweg16\Admin\MenuGroups::LAGER,
			\Lindenweg16\Admin\MenuGroups::FINANZEN,
			\Lindenweg16\Admin\MenuGroups::VERWALTUNG,
			\Lindenweg16\Modules\Mailbox\MailboxModule::PAGE,
		) as $slug ) {
			remove_menu_page( $slug );
		}
	}

	/**
	 * Projekt-3MF einer Platte herunterladen.
	 *
	 * Aus dem Workflow und von der mobilen Seite; die Datei liegt im
	 * geschützten Verzeichnis, deshalb der Umweg über diese Anfrage.
	 */
	public function serve_run_file() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$run_id = isset( $_GET['run'] ) ? (int) $_GET['run'] : 0;

		check_admin_referer( 'lw16_erp_run_file_' . $run_id );

		if ( ! \Lindenweg16\Core\Roles::current_user_can_betrieb( 'lw16_capture' ) || ! Access::can( 'fertigung.workflow' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}

		$run   = RunRepository::find( $run_id );
		$plate = $run && $run['plate_job_id'] ? \Lindenweg16\Modules\Betrieb\Nesting\PlateJobRepository::find( (int) $run['plate_job_id'] ) : null;

		if ( ! $plate || ! is_file( $plate->file_path ) ) {
			wp_die( esc_html__( 'Datei nicht gefunden.', 'lindenweg16' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: model/3mf' );
		header( 'Content-Disposition: attachment; filename="platte-' . $run_id . '.3mf"' );
		header( 'Content-Length: ' . filesize( $plate->file_path ) );
		readfile( $plate->file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Soll diese Person nach der Anmeldung im ERP landen? – reine Regel.
	 *
	 * Gilt nur, wenn die WordPress-Verwaltung ausgeblendet ist (Einstellung im
	 * Workflow): Wer das ERP nutzen darf und keine Website verwaltet, braucht
	 * das wp-admin-Dashboard nicht. Wer die Website verwaltet (manage_options),
	 * behält seinen gewohnten Einstieg.
	 *
	 * @param bool $hide       wp-admin-Menüs sind ausgeblendet.
	 * @param bool $has_erp    Die Person darf das ERP öffnen.
	 * @param bool $can_manage Die Person verwaltet die Website.
	 * @return bool
	 */
	public static function wants_erp_landing( $hide, $has_erp, $can_manage ) {
		return (bool) $hide && (bool) $has_erp && ! $can_manage;
	}

	/**
	 * Nach der Anmeldung ins ERP statt ins wp-admin-Dashboard.
	 *
	 * @param string $to   Bisheriges Ziel.
	 * @param string $from Angeforderte Adresse.
	 * @param mixed  $user Angemeldete Person (oder Fehler).
	 * @return string
	 */
	public function login_redirect( $to, $from, $user ) {
		if ( ! ( $user instanceof \WP_User ) ) {
			return $to;
		}

		// Eine ausdrücklich angeforderte Adresse (z. B. ein Link aus einer Mail) bleibt.
		if ( ! empty( $from ) && false === strpos( (string) $to, 'wp-admin' ) ) {
			return $to;
		}

		if ( self::wants_erp_landing( (bool) ( Workflow::settings()['hide_wpadmin'] || Workflow::settings()['lock_wpadmin'] ), Access::has_any( $user->ID ), user_can( $user, 'manage_options' ) ) ) {
			return self::url();
		}

		return $to;
	}

	/**
	 * Seiten im wp-admin, die auch bei gesperrtem wp-admin offen bleiben.
	 *
	 * Formular- und Ajax-Eingänge (das ERP und der Shop nutzen sie), der
	 * Datei-Upload und das eigene Profil (Passwort ändern).
	 *
	 * @return string[]
	 */
	public static function lock_exempt_pages() {
		return array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php', 'profile.php' );
	}

	/**
	 * Wird dieser wp-admin-Aufruf gesperrt? – reine Regel.
	 *
	 * @param bool   $locked      Die Sperre ist eingeschaltet.
	 * @param bool   $can_manage  Die Person verwaltet die Website (Webmaster).
	 * @param string $pagenow     Aufgerufene Datei.
	 * @param bool   $embedded    Aufruf aus einem Rahmen des ERP.
	 * @param bool   $embed_right Die Person darf eine eingebettete Seite (Shop-Produkte oder -Bestellungen) öffnen.
	 * @return bool
	 */
	public static function wpadmin_blocked( $locked, $can_manage, $pagenow, $embedded, $embed_right ) {
		if ( ! $locked || $can_manage ) {
			return false;
		}

		if ( in_array( $pagenow, self::lock_exempt_pages(), true ) ) {
			return false;
		}

		// Die zwei Seiten, die das ERP noch einbettet, öffnen sich nur im Rahmen und nur mit dem ERP-Recht.
		return ! ( $embedded && $embed_right );
	}

	/**
	 * wp-admin für alle außer dem Webmaster sperren (Einstellung).
	 *
	 * Wer das ERP nutzen darf, landet dort; alle anderen auf der Startseite.
	 * Ein Formular-Aufruf wird abgewiesen statt umgeleitet.
	 */
	public function lock_wpadmin() {
		global $pagenow;

		if ( ! is_user_logged_in() || ! Workflow::settings()['lock_wpadmin'] ) {
			return;
		}

		$blocked = self::wpadmin_blocked(
			true,
			current_user_can( 'manage_options' ),
			(string) $pagenow,
			self::is_embedded(),
			Access::can( 'verkauf.shop_products' ) || Access::can( 'verkauf.shop_orders' )
		);

		if ( ! $blocked ) {
			return;
		}

		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Das wp-admin ist gesperrt. Bitte im ERP arbeiten.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}

		wp_safe_redirect( Access::has_any() ? self::url() : home_url( '/' ) );
		exit;
	}

	/**
	 * Das wp-admin-Dashboard leitet ins ERP, wenn es für diese Person nichts zu tun gibt.
	 */
	public function dashboard_to_erp() {
		global $pagenow;

		if ( 'index.php' !== $pagenow || wp_doing_ajax() || isset( $_GET['lw16_embed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && 'iframe' === $_SERVER['HTTP_SEC_FETCH_DEST'] ) {
			return;
		}

		if ( self::wants_erp_landing( (bool) Workflow::settings()['hide_wpadmin'], Access::has_any(), current_user_can( 'manage_options' ) ) ) {
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	/**
	 * Klassische Betriebsseite im wp-admin: ins ERP leiten, wenn es dort eine gibt.
	 *
	 * Wer das ERP für alles nutzt, soll nicht versehentlich in der alten
	 * Oberfläche landen (Lesezeichen, Links aus Mails). Ausgenommen sind
	 * der Rahmen im ERP („klassische Ansicht"), Ajax und alles außer einem
	 * einfachen Seitenaufruf - dort darf nie etwas umgeleitet werden.
	 */
	public function classic_to_erp() {
		global $pagenow;

		if ( ! Settings::get( 'erp_klassisch_umleiten', 0 ) || 'admin.php' !== $pagenow || wp_doing_ajax() || self::is_embedded() ) {
			return;
		}

		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_GET['page'] ) || isset( $_GET['lw16_classic'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$item = Navigation::find_by_page( sanitize_key( wp_unslash( $_GET['page'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! self::classic_redirects( $item, $item ? Access::can( $item['perm'] ) : false ) ) {
			return;
		}

		wp_safe_redirect( self::url() . '#/seite/' . $item['id'] );
		exit;
	}

	/**
	 * Wird für diesen Eintrag umgeleitet? Reine Regel.
	 *
	 * Nur Seiten, die das ERP selbst anbietet (native, mit klassischem
	 * Vorgänger), und nur, wer sie dort auch öffnen darf - sonst stünde
	 * die Person vor einer leeren Seite.
	 *
	 * @param array|null $item    Eintrag aus Navigation::find_by_page().
	 * @param bool       $allowed Darf die Person die Seite im ERP öffnen?
	 * @return bool
	 */
	public static function classic_redirects( $item, $allowed ) {
		return is_array( $item ) && 'native' === $item['kind'] && '' !== $item['alt'] && (bool) $allowed;
	}

	/**
	 * Adresse mit Prüfzahl für einen Download.
	 *
	 * Anders als wp_nonce_url() bleibt das „&“ ein „&“: wp_nonce_url() gibt
	 * HTML zurück („&#038;“), und in ein href gesetzt zerlegt das die Adresse –
	 * die Prüfzahl und die Nummer kamen nie an („Der Link ist abgelaufen“).
	 *
	 * @param string $url    Adresse.
	 * @param string $action Name der Prüfzahl.
	 * @return string
	 */
	public static function nonce_url( $url, $action ) {
		return add_query_arg( '_wpnonce', wp_create_nonce( $action ), $url );
	}

	/**
	 * Formular- und Ajax-Vorgänge nur ausführen, wenn die feinen Rechte
	 * dafür da sind (siehe ActionMap). Gilt für admin-post.php und
	 * admin-ajax.php; Vollzugriff ist ausgenommen.
	 */
	public function gate_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$perms  = ActionMap::perms_for( $action );

		if ( ! $perms || ! is_user_logged_in() || Access::is_full() ) {
			return;
		}

		foreach ( $perms as $perm ) {
			if ( ! Access::can( $perm ) ) {
				$message = __( 'Dafür fehlt dir das Recht in der ERP-Oberfläche.', 'lindenweg16' );

				if ( wp_doing_ajax() ) {
					wp_send_json_error( array( 'message' => $message ), 403 );
				}

				wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
			}
		}
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
