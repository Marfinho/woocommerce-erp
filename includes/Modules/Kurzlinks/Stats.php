<?php
/**
 * Anonyme Tageszähler der Kurzlinks.
 *
 * Je Tag, Kurzlink und Quelle (`utm_source`) steht eine Zahl in der Tabelle,
 * sonst nichts: keine IP-Adresse, kein User-Agent, kein Cookie, kein Log pro
 * Aufruf. Der User-Agent wird nur geprüft, ob ein Bot dahintersteckt, und nie
 * gespeichert.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Kurzlinks;

defined( 'ABSPATH' ) || exit;

/**
 * Zählen und Auswerten.
 */
class Stats {

	/**
	 * Höchstens so viele verschiedene Quellen je Tag und Kurzlink. Jeder weitere
	 * Wert landet unter „sonstige“ – sonst könnte jemand die Tabelle mit
	 * ausgedachten Parametern aufblähen.
	 */
	const MAX_SOURCES = 30;

	/**
	 * Sammelwert für alles über der Grenze.
	 */
	const OTHER = 'sonstige';

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	protected static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lw16_kurzlinks_stats';
	}

	/**
	 * Gängige Bots, Link-Vorschauen und Skript-Clients (Kleinbuchstaben).
	 *
	 * @return array
	 */
	public static function bot_markers() {
		return array(
			'bot',
			'crawl',
			'spider',
			'slurp',
			'preview',
			'facebookexternalhit',
			'whatsapp',
			'telegram',
			'discord',
			'skype',
			'headless',
			'lighthouse',
			'pingdom',
			'uptime',
			'monitor',
			'curl/',
			'wget',
			'python',
			'java/',
			'go-http-client',
			'libwww',
			'okhttp',
			'axios',
			'node-fetch',
			'postman',
		);
	}

	/**
	 * Ist das ein Aufruf, der gezählt werden soll?
	 *
	 * Nicht gezählt werden HEAD- und andere Nicht-GET-Anfragen sowie offensichtliche
	 * Bots. Ohne User-Agent zählt der Aufruf ebenfalls nicht: Browser senden immer einen.
	 *
	 * @param string $method     HTTP-Methode.
	 * @param string $user_agent User-Agent (wird nur geprüft).
	 * @return bool
	 */
	public static function is_countable( $method, $user_agent ) {
		if ( 'GET' !== strtoupper( (string) $method ) ) {
			return false;
		}

		$ua = strtolower( trim( (string) $user_agent ) );

		if ( '' === $ua ) {
			return false;
		}

		foreach ( self::bot_markers() as $marker ) {
			if ( false !== strpos( $ua, $marker ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Quelle aus dem Query-String für die Zählung bereinigen.
	 *
	 * @param mixed $raw Wert von `utm_source`.
	 * @return string Kleinbuchstaben, Ziffern, `_`, `-`, `.`; höchstens 64 Zeichen; leer ohne Quelle.
	 */
	public static function clean_source( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$s = strtolower( trim( $raw ) );
		$s = preg_replace( '/\s+/', '-', $s );
		$s = preg_replace( '/[^a-z0-9_.\-]/', '', (string) $s );

		return substr( (string) $s, 0, 64 );
	}

	/**
	 * Einen Aufruf zählen.
	 *
	 * @param string $slug       Kurzlink.
	 * @param mixed  $utm_source Rohwert von `utm_source` (oder leer).
	 * @return bool Ob gezählt wurde.
	 */
	public static function record( $slug, $utm_source = '' ) {
		global $wpdb;

		$slug = (string) $slug;

		if ( '' === $slug || strlen( $slug ) > 64 ) {
			return false;
		}

		$source = self::clean_source( $utm_source );
		$datum  = current_time( 'Y-m-d' );

		if ( '' !== $source ) {
			// Grenze für verschiedene Quellen je Tag und Link.
			$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . self::table() . ' WHERE datum = %s AND slug = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$datum,
					$slug
				)
			);

			if ( $count >= self::MAX_SOURCES ) {
				$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						'SELECT 1 FROM ' . self::table() . ' WHERE datum = %s AND slug = %s AND utm_source = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$datum,
						$slug,
						$source
					)
				);

				if ( ! $exists ) {
					$source = self::OTHER;
				}
			}
		}

		$sql = $wpdb->prepare(
			'INSERT INTO ' . self::table() . ' (datum, slug, utm_source, anzahl) VALUES (%s, %s, %s, 1) ON DUPLICATE KEY UPDATE anzahl = anzahl + 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$datum,
			$slug,
			$source
		);

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Summen je Kurzlink und Quelle für 7 Tage, 30 Tage und gesamt.
	 *
	 * Die Zeiträume enthalten den heutigen Tag.
	 *
	 * @return array Zeilen mit slug, utm_source, d7, d30, gesamt.
	 */
	public static function totals() {
		global $wpdb;

		$heute = current_time( 'Y-m-d' );
		$seit7 = gmdate( 'Y-m-d', strtotime( $heute . ' -6 days' ) );
		$seit30 = gmdate( 'Y-m-d', strtotime( $heute . ' -29 days' ) );

		$sql = $wpdb->prepare(
			'SELECT slug, utm_source, SUM(anzahl) AS gesamt, SUM(CASE WHEN datum >= %s THEN anzahl ELSE 0 END) AS d30, SUM(CASE WHEN datum >= %s THEN anzahl ELSE 0 END) AS d7 FROM ' . self::table() . ' GROUP BY slug, utm_source ORDER BY slug ASC, gesamt DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$seit30,
			$seit7
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Zeilen je Link und Quelle zu Gruppen je Link mit Zwischensumme falten.
	 *
	 * @param array $rows Zeilen mit slug, utm_source, d7, d30, gesamt.
	 * @return array Slug => [ 'd7', 'd30', 'gesamt', 'quellen' => [ [ 'quelle', 'd7', 'd30', 'gesamt' ], … ] ].
	 */
	public static function group( array $rows ) {
		$out = array();

		foreach ( $rows as $row ) {
			$slug = (string) $row['slug'];

			if ( ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = array(
					'd7'      => 0,
					'd30'     => 0,
					'gesamt'  => 0,
					'quellen' => array(),
				);
			}

			$d7     = (int) $row['d7'];
			$d30    = (int) $row['d30'];
			$gesamt = (int) $row['gesamt'];

			$out[ $slug ]['d7']       += $d7;
			$out[ $slug ]['d30']      += $d30;
			$out[ $slug ]['gesamt']   += $gesamt;
			$out[ $slug ]['quellen'][] = array(
				'quelle' => (string) $row['utm_source'],
				'd7'     => $d7,
				'd30'    => $d30,
				'gesamt' => $gesamt,
			);
		}

		return $out;
	}
}
