<?php
/**
 * Wege auf der Website: von welcher Seite zu welcher – als anonyme Tageszähler.
 *
 * Beim Öffnen einer Seite liefert der Browser die Seite davor mit (Verweisseite).
 * Daraus wird je Tag und Seitenpaar eine Zahl hochgezählt: „von /shop nach
 * /produkt/haken: 14“. Es gibt keine Kennung und keinen Cookie, und es steht
 * nirgends, welche Aufrufe zusammengehören – ein Weg über drei oder mehr Seiten
 * lässt sich deshalb nicht nachverfolgen und nicht einer Person zuordnen.
 *
 * Als „von“ steht der Pfad der Vorgängerseite, `@host` für eine fremde Seite
 * (Suchmaschine, soziales Netz) oder leer für Direktaufruf, Lesezeichen und
 * Neuladen. Warenkorb und Kasse erscheinen nur als Bereich. Die Anzeige fasst
 * Paare mit weniger als MIN_SHOW Fällen zusammen; Zähler werden nach KEEP_DAYS gelöscht.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Übergänge zählen und auswerten.
 */
class Flow {

	/**
	 * Aufbewahrung in Tagen.
	 */
	const KEEP_DAYS = 90;

	/**
	 * Paare mit weniger Fällen werden in der Anzeige zusammengefasst.
	 */
	const MIN_SHOW = 3;

	/**
	 * Höchstens so viele verschiedene Paare je Tag (Schutz vor ausgedachten Pfaden).
	 */
	const MAX_PAIRS_PER_DAY = 5000;

	/**
	 * Zeiträume der Anzeige in Tagen.
	 */
	const RANGES = array( 7, 30, 90 );

	/**
	 * Zeitraum auf einen bekannten Wert begrenzen – reine Regel.
	 *
	 * @param mixed $days Eingabe.
	 * @return int
	 */
	public static function clean_days( $days ) {
		$days = is_numeric( $days ) ? (int) $days : 30;

		return in_array( $days, self::RANGES, true ) ? $days : 30;
	}

	/**
	 * Pfad für den Zähler kürzen – reine Regel.
	 *
	 * @param string $path Schon bereinigter Pfad.
	 * @return string Höchstens 100 Zeichen.
	 */
	protected static function short( $path ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $path, 0, 100 ) : substr( (string) $path, 0, 100 );
	}

	/**
	 * Woher kam der Aufruf? – reine Regel.
	 *
	 * @param mixed  $referrer Verweisseite laut Browser.
	 * @param string $own_host Host der eigenen Website.
	 * @return string Pfad der eigenen Vorgängerseite, `@host` für eine fremde Seite, leer für direkt.
	 */
	public static function source( $referrer, $own_host ) {
		if ( ! is_string( $referrer ) || '' === $referrer ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		$host = (string) preg_replace( '/^www\./', '', $host );
		$own  = (string) preg_replace( '/^www\./', '', strtolower( (string) $own_host ) );

		if ( '' === $host ) {
			return '';
		}

		if ( $host === $own ) {
			$path = (string) wp_parse_url( $referrer, PHP_URL_PATH );

			return self::short( Presence::clean_path( $path ) );
		}

		return preg_match( '/^[a-z0-9.-]{3,64}$/', $host ) ? '@' . $host : '';
	}

	/**
	 * Einen Übergang zählen.
	 *
	 * @param string $from Siehe source().
	 * @param string $to   Bereinigter Pfad der geöffneten Seite.
	 * @param int    $now  Zeitpunkt.
	 */
	public static function record( $from, $to, $now ) {
		global $wpdb;

		$to = self::short( $to );

		// Neuladen derselben Seite ist kein Weg.
		if ( '' === $to || $from === $to ) {
			return;
		}

		$table = $wpdb->prefix . 'lw16_flow';
		$day   = gmdate( 'Y-m-d', $now );
		$pair  = md5( $from . "\n" . $to );

		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE day = %s AND pair = %s", $day, $pair ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $exists && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE day = %s", $day ) ) >= self::MAX_PAIRS_PER_DAY ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (day, pair, from_path, to_path, views) VALUES (%s, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$day,
				$pair,
				$from,
				$to
			)
		);

		if ( 1 === wp_rand( 1, 200 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", gmdate( 'Y-m-d', $now - self::KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Auswertung für das ERP.
	 *
	 * @param int    $days Zeitraum.
	 * @param string $page Optional: eine Seite, deren Vorher und Nachher gezeigt wird.
	 * @param int    $now  Zeitpunkt (für Tests).
	 * @return array Siehe summarize().
	 */
	public static function snapshot( $days, $page = '', $now = null ) {
		global $wpdb;

		$now   = null === $now ? time() : (int) $now;
		$days  = self::clean_days( $days );
		$table = $wpdb->prefix . 'lw16_flow';
		$since = gmdate( 'Y-m-d', $now - ( $days - 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT from_path, to_path, SUM(views) AS views FROM {$table} WHERE day >= %s GROUP BY from_path, to_path", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out         = self::summarize( is_array( $rows ) ? $rows : array(), $page );
		$out['days'] = $days;

		return $out;
	}

	/**
	 * Paare zu Anzeigewerten verdichten – reine Rechnung.
	 *
	 * @param array  $rows Zeilen {from_path, to_path, views}.
	 * @param string $page Optional: eine Seite für Vorher und Nachher.
	 * @return array {transitions, entries, sources, pages, cart, page, before, after, hidden}
	 */
	public static function summarize( array $rows, $page = '' ) {
		$trans   = array();
		$entries = array();
		$sources = array();
		$into    = array();
		$before  = array();
		$after   = array();
		$hidden  = 0;
		$cart_in = 0;
		$cart_on = 0;

		foreach ( $rows as $r ) {
			$from  = (string) $r['from_path'];
			$to    = (string) $r['to_path'];
			$views = (int) $r['views'];
			$intern = '' !== $from && '@' !== $from[0];

			$into[ $to ] = ( isset( $into[ $to ] ) ? $into[ $to ] : 0 ) + $views;

			if ( '/warenkorb' === $to ) {
				$cart_in += $views;
			}

			if ( '/warenkorb' === $from && '/kasse' === $to ) {
				$cart_on += $views;
			}

			if ( ! $intern ) {
				$entries[ $to ] = ( isset( $entries[ $to ] ) ? $entries[ $to ] : 0 ) + $views;

				if ( '' !== $from ) {
					$sources[ $from ] = ( isset( $sources[ $from ] ) ? $sources[ $from ] : 0 ) + $views;
				}
			}

			if ( '' !== $page && $to === $page ) {
				$before[ $from ] = $views;
			}

			if ( '' !== $page && $from === $page ) {
				$after[ $to ] = $views;
			}

			if ( ! $intern ) {
				continue;
			}

			if ( $views < self::MIN_SHOW ) {
				$hidden += $views;
				continue;
			}

			$trans[] = array(
				'from'  => $from,
				'to'    => $to,
				'count' => $views,
			);
		}

		usort(
			$trans,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'] ?: strcmp( $a['from'] . $a['to'], $b['from'] . $b['to'] );
			}
		);

		arsort( $into );

		$pages = array();
		foreach ( array_slice( $into, 0, 40, true ) as $path => $count ) {
			$pages[] = array(
				'path'  => (string) $path,
				'count' => $count,
			);
		}

		return array(
			'transitions' => array_slice( $trans, 0, 30 ),
			'entries'     => self::top( $entries, 15, self::MIN_SHOW ),
			'sources'     => self::top( $sources, 10, self::MIN_SHOW ),
			'pages'       => $pages,
			'cart'        => array(
				'in'      => $cart_in,
				'to_kasse' => $cart_on,
			),
			'page'        => $page,
			'before'      => '' === $page ? array() : self::top( $before, 10, self::MIN_SHOW ),
			'after'       => '' === $page ? array() : self::top( $after, 10, self::MIN_SHOW ),
			'hidden'      => $hidden,
		);
	}

	/**
	 * Größte Einträge einer Zählung.
	 *
	 * @param array $counts Pfad => Anzahl.
	 * @param int   $limit  Höchstens so viele.
	 * @param int   $min    Mindestzahl.
	 * @return array Liste {path, count}.
	 */
	protected static function top( array $counts, $limit, $min = 1 ) {
		arsort( $counts );
		$out = array();

		foreach ( $counts as $path => $count ) {
			if ( $count < $min || count( $out ) >= $limit ) {
				break;
			}

			$out[] = array(
				'path'  => '' === (string) $path ? '' : (string) $path,
				'count' => (int) $count,
			);
		}

		return $out;
	}
}
