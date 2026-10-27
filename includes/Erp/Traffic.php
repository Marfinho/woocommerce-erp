<?php
/**
 * Anonymer Seitenaufruf-Zähler fürs Live-Dashboard.
 *
 * Gezählt wird, wie oft Seiten der Website angesehen werden, in welchem
 * Bereich (Start, Shop, Warenkorb, Kasse, Inhalt) und in welchem
 * Zehn-Minuten-Fenster. Es wird nichts über Personen gespeichert: keine
 * Adresse, kein Cookie, keine Kennung, kein Seitenpfad. Das ist deshalb
 * eine Zählung von Aufrufen, keine von Besuchern: Wer dreimal lädt, zählt dreimal.
 *
 * Ein Skript am Seitenende meldet den Aufruf. Der Zähler läuft damit auch auf
 * Seiten, die ein Seiten-Cache ausliefert, ohne dass PHP sie noch erzeugt.
 * Nicht gezählt werden angemeldete Personen (ihre Seiten tragen das Skript
 * nicht), Bots, Vorschauen und Browser mit „Do Not Track“.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

use Lindenweg16\Core\Throttle;

defined( 'ABSPATH' ) || exit;

/**
 * Zählen und Auswerten.
 */
class Traffic {

	/**
	 * Bereiche der Website.
	 */
	const SECTIONS = array( 'start', 'shop', 'warenkorb', 'kasse', 'inhalt' );

	/**
	 * Aufbewahrung in Tagen.
	 */
	const KEEP_DAYS = 400;

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wp_footer', array( $this, 'beacon' ), 99 );
	}

	/**
	 * Route der Meldung.
	 */
	public function routes() {
		register_rest_route(
			'lw16/v1',
			'/pv',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'collect' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Skript am Seitenende, nur für anonyme Besucher.
	 */
	public function beacon() {
		if ( is_admin() || is_user_logged_in() || is_preview() || is_customize_preview() || is_feed() || is_404() ) {
			return;
		}

		// Nur Pfad und Abfrage: Die Meldung geht an dieselbe Adresse, von der die Seite kam.
		// Mit www-Adresse und ohne www wäre sie sonst ein fremder Ursprung und würde abgewiesen.
		$parts = wp_parse_url( rest_url( 'lw16/v1/pv' ) );
		$url   = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		// sendBeacon als einfacher Text: keine Vorabfrage des Browsers, kein Cookie;
		// „Do Not Track“ wird respektiert.
		echo '<script>(function(){try{if(navigator.doNotTrack==="1"||window.doNotTrack==="1"){return;}var b=new Blob([JSON.stringify({p:location.pathname})],{type:"text/plain"});if(navigator.sendBeacon){navigator.sendBeacon(' . wp_json_encode( $url ) . ',b);}}catch(e){}})();</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Eine Meldung entgegennehmen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response
	 */
	public function collect( \WP_REST_Request $request ) {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Offener Endpunkt: pro Adresse höchstens 120 Meldungen je Minute (das
		// reicht für jeden Menschen), und nur vom eigenen Ursprung.
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$fremd  = '' !== $origin && wp_parse_url( $origin, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $fremd || ! Throttle::hit( 'pv', 120 ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		if ( ! self::is_bot( $ua ) ) {
			$body = json_decode( (string) $request->get_body(), true );
			$path = is_array( $body ) && isset( $body['p'] ) && is_string( $body['p'] ) ? $body['p'] : '';

			self::record( self::classify( $path ) );
		}

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Bereich einer Seite – reine Regel.
	 *
	 * @param string $path Pfad, wie ihn der Browser meldet.
	 * @return string Einer aus SECTIONS.
	 */
	public static function classify( $path ) {
		$path = strtolower( rawurldecode( substr( (string) $path, 0, 200 ) ) );
		$path = '/' . trim( $path, '/' );

		if ( '/' === $path ) {
			return 'start';
		}

		if ( preg_match( '#^/(warenkorb|cart)(/|$)#', $path ) ) {
			return 'warenkorb';
		}

		if ( preg_match( '#^/(kasse|checkout|bestellung-erhalten|order-received)(/|$)#', $path ) ) {
			return 'kasse';
		}

		if ( preg_match( '#^/(shop|produkt|product|produkte|produkt-kategorie|product-category|kategorie)(/|$)#', $path ) ) {
			return 'shop';
		}

		return 'inhalt';
	}

	/**
	 * Sieht die Kennung des Browsers nach einem Roboter aus – reine Regel.
	 *
	 * @param string $ua Kennung.
	 * @return bool
	 */
	public static function is_bot( $ua ) {
		$ua = trim( (string) $ua );

		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|preview|monitor|uptime|curl|wget|python|java\/|headless|lighthouse|facebookexternalhit|embedly|scan/i', $ua );
	}

	/**
	 * Zähler um eins erhöhen.
	 *
	 * @param string $section Bereich.
	 */
	public static function record( $section ) {
		global $wpdb;

		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			return;
		}

		$table = $wpdb->prefix . 'lw16_traffic';
		$now   = time();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (day, hour, slot, section, views) VALUES (%s, %d, %d, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d', $now ),
				(int) gmdate( 'G', $now ),
				(int) floor( (int) gmdate( 'i', $now ) / 10 ),
				$section
			)
		);

		// Gelegentlich Altes wegräumen, statt einen eigenen Auftrag zu brauchen.
		if ( 1 === wp_rand( 1, 500 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", gmdate( 'Y-m-d', $now - self::KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Auswertung für das Dashboard.
	 *
	 * @param int|null $now Zeitpunkt (für Tests).
	 * @return array {today, yesterday, last30, sections, series}
	 */
	public static function snapshot( $now = null ) {
		global $wpdb;

		$now   = null === $now ? time() : (int) $now;
		$table = $wpdb->prefix . 'lw16_traffic';
		$since = gmdate( 'Y-m-d', $now - 2 * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT day, hour, slot, section, views FROM {$table} WHERE day >= %s", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::summarize( is_array( $rows ) ? $rows : array(), $now );
	}

	/**
	 * Zeilen zu Kennzahlen verdichten – reine Rechnung.
	 *
	 * @param array $rows Zeilen {day, hour, slot, section, views}.
	 * @param int   $now  Zeitpunkt.
	 * @return array {today, yesterday, last30, sections, series, last_ts}; series: 24 Stunden, älteste zuerst, je {ts, views}; last_ts: Beginn des Fensters mit dem letzten Aufruf (0 = keiner in den letzten zwei Tagen).
	 */
	public static function summarize( array $rows, $now ) {
		$today     = gmdate( 'Y-m-d', $now );
		$yesterday = gmdate( 'Y-m-d', $now - DAY_IN_SECONDS );
		$slot_now  = (int) floor( $now / 600 );
		$hour_now  = (int) floor( $now / 3600 );
		$out       = array(
			'today'     => 0,
			'yesterday' => 0,
			'last30'    => 0,
			'sections'  => array_fill_keys( self::SECTIONS, 0 ),
			'series'    => array(),
			'last_ts'   => 0,
		);

		$by_hour = array();

		foreach ( $rows as $row ) {
			$views = (int) $row['views'];
			$ts    = strtotime( $row['day'] . ' UTC' ) + (int) $row['hour'] * 3600 + (int) $row['slot'] * 600;

			if ( $row['day'] === $today ) {
				$out['today'] += $views;

				if ( isset( $out['sections'][ $row['section'] ] ) ) {
					$out['sections'][ $row['section'] ] += $views;
				}
			} elseif ( $row['day'] === $yesterday ) {
				$out['yesterday'] += $views;
			}

			// Die letzten drei Fenster, das laufende eingerechnet.
			if ( (int) floor( $ts / 600 ) > $slot_now - 3 && (int) floor( $ts / 600 ) <= $slot_now ) {
				$out['last30'] += $views;
			}

			if ( $views > 0 ) {
				$out['last_ts'] = max( $out['last_ts'], $ts );
			}

			$h             = (int) floor( $ts / 3600 );
			$by_hour[ $h ] = ( isset( $by_hour[ $h ] ) ? $by_hour[ $h ] : 0 ) + $views;
		}

		for ( $i = 23; $i >= 0; $i-- ) {
			$h                 = $hour_now - $i;
			$out['series'][]   = array( 'ts' => $h * 3600, 'views' => isset( $by_hour[ $h ] ) ? $by_hour[ $h ] : 0 );
		}

		return $out;
	}
}
