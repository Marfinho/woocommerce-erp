<?php
/**
 * Wer ist gerade auf der Website? – ohne Cookie, ohne Einwilligung.
 *
 * Jede geöffnete Seite meldet sich alle 25 Sekunden mit einer Zufallskennung, die
 * nur im Arbeitsspeicher des Tabs lebt (eine JavaScript-Variable). Nichts wird
 * im Browser gespeichert oder ausgelesen – kein Cookie, kein localStorage, keine
 * Fingerabdrücke – und auf dem Server werden weder IP-Adresse noch User-Agent
 * abgelegt. Die Kennung wechselt mit jedem Seitenaufruf; sie verbindet also nie
 * zwei Seiten derselben Person.
 *
 * Gespeichert wird je geöffneter Seite eine Zeile: Kennung, Pfad ohne Abfrage,
 * Seitentitel, Bereich, Herkunft (nur der Host der Verweisseite), Gerät (Handy
 * oder Rechner) und zwei Zeitpunkte. Die Zeile verschwindet, wenn der Tab
 * schließt, spätestens nach 30 Minuten. Warenkorb, Kasse und Bestellbestätigung
 * werden nur als Bereich geführt, nie mit ihrem Pfad.
 *
 * Gezählt werden damit offene Seiten, nicht Menschen: Wer zwei Tabs offen hat,
 * zählt doppelt; ein Tab im Hintergrund zählt nach 90 Sekunden nicht mehr mit.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Anwesenheit speichern und auswerten.
 */
class Presence {

	/**
	 * Eine Seite gilt als „jetzt offen“, wenn sie sich in so vielen Sekunden gemeldet hat.
	 */
	const ACTIVE = 90;

	/**
	 * Zeilen werden nach so vielen Sekunden gelöscht.
	 */
	const KEEP = 1800;

	/**
	 * Mehr gleichzeitige Zeilen nimmt die Tabelle nicht an (Schutz vor Flut).
	 */
	const MAX_ROWS = 2000;

	/**
	 * Zufallskennung eines Tabs prüfen – reine Regel.
	 *
	 * @param mixed $token Eingabe.
	 * @return string Kennung oder leer.
	 */
	public static function clean_token( $token ) {
		return is_string( $token ) && preg_match( '/^[a-z0-9]{10,20}$/', $token ) ? $token : '';
	}

	/**
	 * Pfad für die Anzeige bereinigen – reine Regel.
	 *
	 * Ohne Abfrage und Anker (die tragen Schlüssel und Suchbegriffe), höchstens
	 * 190 Zeichen. Warenkorb und Kasse bleiben ohne Pfad: Dort stehen Bestellnummern.
	 *
	 * @param mixed $path Pfad, wie ihn der Browser meldet.
	 * @return string Pfad mit führendem „/“.
	 */
	public static function clean_path( $path ) {
		$path = is_string( $path ) ? $path : '';
		$path = (string) preg_replace( '/[?#].*$/s', '', $path );
		$path = '/' . trim( rawurldecode( $path ), '/' );
		$path = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $path );

		if ( function_exists( 'mb_substr' ) ) {
			$path = mb_substr( $path, 0, 190 );
		} else {
			$path = substr( $path, 0, 190 );
		}

		$section = Traffic::classify( $path );

		if ( 'warenkorb' === $section || 'kasse' === $section ) {
			return '/' . $section;
		}

		return $path;
	}

	/**
	 * Seitentitel bereinigen – reine Regel.
	 *
	 * @param mixed  $title   Titel laut Browser.
	 * @param string $section Bereich; Warenkorb und Kasse tragen keinen Titel.
	 * @return string
	 */
	public static function clean_title( $title, $section ) {
		if ( ! is_string( $title ) || 'warenkorb' === $section || 'kasse' === $section ) {
			return '';
		}

		$title = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $title ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 100 ) : substr( $title, 0, 100 );
	}

	/**
	 * Herkunft: nur der Host der Verweisseite – reine Regel.
	 *
	 * @param mixed  $referrer Verweisseite laut Browser.
	 * @param string $own_host Host der eigenen Website.
	 * @return string Host ohne „www.“; leer bei direktem Aufruf oder interner Verweisseite.
	 */
	public static function clean_referrer( $referrer, $own_host ) {
		if ( ! is_string( $referrer ) || '' === $referrer ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		$host = (string) preg_replace( '/^www\./', '', $host );
		$own  = (string) preg_replace( '/^www\./', '', strtolower( (string) $own_host ) );

		if ( '' === $host || $host === $own || ! preg_match( '/^[a-z0-9.-]{3,64}$/', $host ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * Gerät aus der Fensterbreite – reine Regel.
	 *
	 * @param mixed $width Breite in Pixeln.
	 * @return string „m“ (Handy), „d“ (Rechner) oder leer.
	 */
	public static function device( $width ) {
		if ( ! is_numeric( $width ) || $width < 1 || $width > 20000 ) {
			return '';
		}

		return $width < 768 ? 'm' : 'd';
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	protected static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lw16_presence';
	}

	/**
	 * Meldung einer offenen Seite festhalten.
	 *
	 * @param string $token  Kennung des Tabs.
	 * @param array  $data   {path, title, ref, dev} – schon bereinigt.
	 * @param int    $now    Zeitpunkt.
	 * @return bool True, wenn die Seite neu hinzukam (und nicht nur wieder meldete).
	 */
	public static function touch( $token, array $data, $now ) {
		global $wpdb;

		$table   = self::table();
		$section = Traffic::classify( $data['path'] );

		// Flutschutz: viele offene Seiten gleichzeitig sind nicht plausibel.
		$known = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $known && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) >= self::MAX_ROWS ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return false;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (token, first_ts, seen_ts, path, title, section, ref, dev) VALUES (%s, %d, %d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE seen_ts = VALUES(seen_ts)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token,
				$now,
				$now,
				$data['path'],
				$data['title'],
				$section,
				$data['ref'],
				$data['dev']
			)
		);

		self::purge( $now, 1 === wp_rand( 1, 40 ) );

		return ! $known;
	}

	/**
	 * Eine Seite ist zu: Zeile sofort entfernen.
	 *
	 * @param string $token Kennung.
	 */
	public static function leave( $token ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'token' => $token ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Alte Zeilen löschen.
	 *
	 * @param int  $now   Zeitpunkt.
	 * @param bool $force Jetzt wirklich löschen (sonst nur gelegentlich).
	 */
	public static function purge( $now, $force ) {
		global $wpdb;

		if ( ! $force ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE seen_ts < %d', (int) $now - self::KEEP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Stand für das ERP.
	 *
	 * @param int|null $now Zeitpunkt (für Tests).
	 * @return array Siehe summarize().
	 */
	public static function snapshot( $now = null ) {
		global $wpdb;

		$now = null === $now ? time() : (int) $now;

		self::purge( $now, true );

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT first_ts, seen_ts, path, title, section, ref, dev FROM ' . self::table() . ' WHERE seen_ts >= %d', $now - self::ACTIVE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return self::summarize( is_array( $rows ) ? $rows : array(), $now );
	}

	/**
	 * Zeilen zur Anzeige verdichten – reine Rechnung.
	 *
	 * @param array $rows Zeilen {first_ts, seen_ts, path, title, section, ref, dev}.
	 * @param int   $now  Zeitpunkt.
	 * @return array {online, sections, pages, refs, devices, visitors}; visitors: die am längsten Bleibenden zuerst, ohne Kennung.
	 */
	public static function summarize( array $rows, $now ) {
		$out = array(
			'online'   => 0,
			'sections' => array_fill_keys( Traffic::SECTIONS, 0 ),
			'pages'    => array(),
			'refs'     => array(),
			'devices'  => array(
				'm' => 0,
				'd' => 0,
			),
			'visitors' => array(),
		);

		$pages = array();
		$refs  = array();

		foreach ( $rows as $row ) {
			if ( (int) $row['seen_ts'] < $now - self::ACTIVE ) {
				continue;
			}

			++$out['online'];

			if ( isset( $out['sections'][ $row['section'] ] ) ) {
				++$out['sections'][ $row['section'] ];
			}

			if ( isset( $out['devices'][ $row['dev'] ] ) ) {
				++$out['devices'][ $row['dev'] ];
			}

			$path = (string) $row['path'];

			if ( ! isset( $pages[ $path ] ) ) {
				$pages[ $path ] = array(
					'path'    => $path,
					'title'   => (string) $row['title'],
					'section' => (string) $row['section'],
					'count'   => 0,
				);
			}

			++$pages[ $path ]['count'];

			if ( '' !== (string) $row['ref'] ) {
				$refs[ $row['ref'] ] = ( isset( $refs[ $row['ref'] ] ) ? $refs[ $row['ref'] ] : 0 ) + 1;
			}

			$out['visitors'][] = array(
				'path'    => $path,
				'title'   => (string) $row['title'],
				'section' => (string) $row['section'],
				'ref'     => (string) $row['ref'],
				'dev'     => (string) $row['dev'],
				'since'   => max( 0, (int) $now - (int) $row['first_ts'] ),
			);
		}

		usort(
			$pages,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'] ?: strcmp( $a['path'], $b['path'] );
			}
		);

		arsort( $refs );

		foreach ( $refs as $host => $count ) {
			$out['refs'][] = array(
				'host'  => (string) $host,
				'count' => $count,
			);
		}

		usort(
			$out['visitors'],
			static function ( $a, $b ) {
				return $b['since'] <=> $a['since'];
			}
		);

		$out['pages']    = array_slice( $pages, 0, 20 );
		$out['refs']     = array_slice( $out['refs'], 0, 10 );
		$out['visitors'] = array_slice( $out['visitors'], 0, 50 );

		return $out;
	}
}
