<?php
/**
 * Weiterleitung: `/{slug}` führt per 302 auf das hinterlegte Ziel.
 *
 * Eine einzige Rewrite-Regel für alle aktiven Slugs. Weitergeleitet wird nur auf
 * Ziele, die im Admin hinterlegt sind – nie auf eine URL aus einem Parameter.
 * Inaktive und unbekannte Slugs liefern das normale 404 von WordPress.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Kurzlinks;

defined( 'ABSPATH' ) || exit;

/**
 * Regel, Weiterleitung und Aufbau der Ziel-URL.
 */
class Redirect {

	const QUERY_VAR  = 'lw16_kurzlink';
	const RULES_HASH = 'lw16_kurzlinks_rules';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		// Vor redirect_canonical (Priorität 10): sonst hängt WordPress erst einen Slash an (301).
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );

		// Legt später jemand eine Seite mit demselben Pfad an, bekommt sie einen anderen Slug.
		add_filter( 'wp_unique_post_slug_is_bad_flat_slug', array( $this, 'slug_is_taken' ), 10, 2 );
		add_filter( 'wp_unique_post_slug_is_bad_hierarchical_slug', array( $this, 'slug_is_taken' ), 10, 2 );
	}

	/**
	 * Regel anlegen und – nur wenn sich die Slugs geändert haben – einen Flush vormerken.
	 *
	 * Der Flush selbst läuft einmalig in `Plugin::maybe_flush_rewrite()` (init, Priorität 99),
	 * nicht bei jedem Aufruf.
	 */
	public function add_rewrite() {
		self::add_rules();

		$hash = md5( implode( '|', array_keys( Links::active() ) ) );

		if ( get_option( self::RULES_HASH ) !== $hash ) {
			update_option( self::RULES_HASH, $hash, true );
			update_option( 'lw16_flush_rewrite', 1 );
		}
	}

	/**
	 * Die Regel für alle aktiven Slugs.
	 */
	public static function add_rules() {
		$slugs = array_keys( Links::active() );

		if ( ! $slugs ) {
			return;
		}

		$alt = implode( '|', array_map( 'preg_quote', $slugs ) );

		add_rewrite_rule( '^(' . $alt . ')/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Query-Variable zulassen.
	 *
	 * @param array $vars Bekannte Variablen.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Aktive Kurzlinks sind für neue Seiten und Beiträge tabu.
	 *
	 * @param bool   $bad  Bisheriges Ergebnis.
	 * @param string $slug Vorgeschlagener Slug.
	 * @return bool
	 */
	public function slug_is_taken( $bad, $slug ) {
		if ( $bad ) {
			return $bad;
		}

		return isset( Links::active()[ $slug ] );
	}

	/**
	 * Weiterleiten – oder 404.
	 */
	public function maybe_redirect() {
		$slug = (string) get_query_var( self::QUERY_VAR );

		if ( '' === $slug ) {
			return;
		}

		$link = Links::find( strtolower( $slug ) );

		if ( ! $link || ! $link['aktiv'] ) {
			global $wp_query;

			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		$ziel    = self::target_url( $link );
		$query   = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$intern  = Links::is_internal( $ziel, $home );
		$url     = self::build_url( $ziel, $query, $intern );

		// Der User-Agent wird nur auf Bots geprüft und nicht gespeichert.
		if ( Stats::is_countable( $method, $agent ) ) {
			$utm = '';
			if ( isset( $_GET['utm_source'] ) && is_string( $_GET['utm_source'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$utm = wp_unslash( $_GET['utm_source'] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			}
			Stats::record( $link['slug'], $utm );
		}

		// 302, nie zwischenspeichern: Das Ziel soll sich später ändern lassen.
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		// Das Ziel stammt aus den Einstellungen, nicht aus der Anfrage – deshalb darf es
		// auch auf fremde Hosts führen (wp_safe_redirect würde das verbieten).
		wp_redirect( $url, 302, 'Lindenweg16 Kurzlinks' ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Ziel eines Links: gewählte Seite, freie URL oder Startseite.
	 *
	 * @param array $link Eintrag.
	 * @return string
	 */
	public static function target_url( array $link ) {
		if ( $link['seite_id'] > 0 && 'publish' === get_post_status( $link['seite_id'] ) ) {
			$permalink = get_permalink( $link['seite_id'] );
			if ( $permalink ) {
				return $permalink;
			}
		}

		if ( '' !== $link['url'] ) {
			// Pfad ohne Host („/montage-video“) hängt an die eigene Adresse.
			if ( 0 === strpos( $link['url'], '/' ) ) {
				return home_url( $link['url'] );
			}

			return $link['url'];
		}

		return home_url( '/' );
	}

	/**
	 * Ziel-URL mit durchgereichtem Query-String.
	 *
	 * Bei Zielen auf der eigenen Seite werden die Parameter der Anfrage angehängt
	 * (z. B. `utm_source`, damit WooCommerce die Bestellherkunft erfasst); Parameter,
	 * die das Ziel schon selbst trägt, gewinnen. Bei fremden Zielen bleibt die URL, wie sie ist.
	 *
	 * @param string $target       Ziel.
	 * @param string $query_string Query-String der Anfrage (ohne „?“).
	 * @param bool   $internal     Ob das Ziel auf der eigenen Seite liegt.
	 * @return string
	 */
	public static function build_url( $target, $query_string, $internal ) {
		$query_string = ltrim( (string) $query_string, '?' );

		if ( ! $internal || '' === $query_string || strlen( $query_string ) > 2000 ) {
			return $target;
		}

		$fragment = '';
		$hash_pos = strpos( $target, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $target, $hash_pos );
			$target   = substr( $target, 0, $hash_pos );
		}

		$own   = array();
		$q_pos = strpos( $target, '?' );
		if ( false !== $q_pos ) {
			parse_str( substr( $target, $q_pos + 1 ), $own );
			$target = substr( $target, 0, $q_pos );
		}

		$incoming = array();
		parse_str( $query_string, $incoming );

		$merged = $own + $incoming;

		if ( ! $merged ) {
			return $target . $fragment;
		}

		return $target . '?' . http_build_query( $merged, '', '&', PHP_QUERY_RFC3986 ) . $fragment;
	}
}
