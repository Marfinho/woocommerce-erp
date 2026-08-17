<?php
/**
 * GoBD-konforme Hash-Verkettung für steuerrelevante Tabellen.
 *
 * Jeder Datensatz enthält einen SHA-256-Hash aus seinem Inhalt und dem Hash
 * des Vorgänger-Datensatzes. Nachträgliche Manipulation bricht die Kette
 * sichtbar – vergleichbar mit einer Blockchain.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Hash-Verkettung: Berechnung, Einfügung und Prüfung.
 */
class HashChain {

	/**
	 * Felder je Tabelle, die in die Hash-Berechnung einfließen.
	 * WICHTIG: Felder, die sich nach der Erstellung ändern dürfen
	 * (z. B. storniert), gehören NICHT in diese Liste.
	 */
	const HASH_FIELDS = array(
		'belege'             => array( 'belegnummer', 'datei_pfad', 'betrag', 'datum', 'haendler', 'eur_kategorie', 'quelle', 'erstellt_am', 'erstellt_von' ),
		'rechnungen'         => array( 'order_id', 'rechnungsnummer', 'jahr', 'laufnummer', 'datei_pfad', 'betrag', 'erstellt_am' ),
		'buchungen'          => array( 'buchungsnummer', 'buchungsdatum', 'erfasst_am', 'typ', 'betrag', 'kategorie', 'beschreibung', 'referenz_typ', 'referenz_id', 'erstellt_von' ),
		'audit_log'          => array( 'user_id', 'aktion', 'objekt_typ', 'objekt_id', 'details', 'erstellt_am' ),
		'erechnung_eingang'  => array( 'datei_pfad', 'format', 'absender_name', 'absender_steuer_id', 'rechnungsnummer_ext', 'betrag', 'datum', 'erstellt_am' ),
		'kassenbuch'         => array( 'kassenbuchnummer', 'datum', 'beschreibung', 'einnahme', 'ausgabe', 'saldo', 'beleg_id', 'erstellt_am', 'erstellt_von' ),
	);

	/**
	 * Hash für einen Datensatz berechnen.
	 *
	 * @param string $table_key     Kurzname der Tabelle (z. B. 'belege').
	 * @param array  $data          Datensatz als assoziatives Array.
	 * @param string $previous_hash Hash des Vorgänger-Datensatzes (leer = erster Eintrag).
	 * @return string SHA-256-Hash.
	 */
	public static function compute( $table_key, array $data, $previous_hash = '' ) {
		$fields = isset( self::HASH_FIELDS[ $table_key ] ) ? self::HASH_FIELDS[ $table_key ] : array();
		$parts  = array( $table_key );
		foreach ( $fields as $field ) {
			$parts[] = array_key_exists( $field, $data ) ? (string) $data[ $field ] : '';
		}
		$parts[] = $previous_hash ? $previous_hash : 'GENESIS';
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Hash und Vorgänger-Hash für ein INSERT vorbereiten.
	 *
	 * @param string $table_key Kurzname (z. B. 'belege').
	 * @param array  $data      Datensatz (ohne hash/vorheriger_hash).
	 * @return array Array mit 'hash' und 'vorheriger_hash'.
	 */
	public static function prepare_insert( $table_key, array $data ) {
		global $wpdb;
		$table_full = $wpdb->prefix . 'lw16_' . $table_key;
		$prev       = self::get_last_hash( $table_full );
		$hash       = self::compute( $table_key, $data, $prev );
		return array(
			'hash'             => $hash,
			'vorheriger_hash'  => $prev,
		);
	}

	/**
	 * Letzten Hash einer Tabelle holen.
	 *
	 * @param string $table_full Vollständiger Tabellenname inkl. Präfix.
	 * @return string Hash oder leerer String.
	 */
	public static function get_last_hash( $table_full ) {
		global $wpdb;
		$col_check = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'hash'",
			DB_NAME,
			$table_full
		) );
		if ( ! $col_check ) {
			return '';
		}
		return (string) $wpdb->get_var( "SELECT hash FROM {$table_full} WHERE hash != '' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore
	}

	/**
	 * Kurzname aus vollem Tabellennamen extrahieren.
	 *
	 * @param string $table_full Voller Name (z. B. wp_lw16_belege).
	 * @return string Kurzname (z. B. belege).
	 */
	public static function table_key( $table_full ) {
		if ( preg_match( '/lw16_(.+)$/', $table_full, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Hash-Kette einer ganzen Tabelle verifizieren.
	 *
	 * @param string $table_full Vollständiger Tabellenname.
	 * @return array Fehler-Array (leer = alles ok). Jeder Eintrag: ['id' => int, 'error' => string].
	 */
	public static function verify( $table_full ) {
		global $wpdb;
		$key = self::table_key( $table_full );
		if ( ! $key || ! isset( self::HASH_FIELDS[ $key ] ) ) {
			return array( array( 'id' => 0, 'error' => 'Unbekannte Tabelle: ' . $key ) );
		}

		$rows          = $wpdb->get_results( "SELECT * FROM {$table_full} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore
		$errors        = array();
		$previous_hash = '';

		foreach ( $rows as $row ) {
			$stored_hash = isset( $row['hash'] ) ? $row['hash'] : '';
			$stored_prev = isset( $row['vorheriger_hash'] ) ? $row['vorheriger_hash'] : '';

			// Datensätze ohne Hash (vor Migration) überspringen.
			if ( '' === $stored_hash ) {
				continue;
			}

			// Vorgänger-Hash prüfen.
			if ( $stored_prev !== $previous_hash ) {
				$errors[] = array(
					'id'    => (int) $row['id'],
					'error' => sprintf(
						'Vorgänger-Hash Bruch (erwartet: %s, gespeichert: %s)',
						$previous_hash ? substr( $previous_hash, 0, 12 ) . '…' : 'GENESIS',
						$stored_prev ? substr( $stored_prev, 0, 12 ) . '…' : '(leer)'
					),
				);
			}

			// Hash neu berechnen und vergleichen.
			$computed = self::compute( $key, $row, $previous_hash );
			if ( $stored_hash !== $computed ) {
				$errors[] = array(
					'id'    => (int) $row['id'],
					'error' => 'Hash ungültig – mögliche Manipulation',
				);
			}

			$previous_hash = $stored_hash;
		}

		return $errors;
	}

	/**
	 * Lücken in einer Nummernfolge erkennen (z. B. Belegnummern).
	 *
	 * @param string $table_full  Tabellenname.
	 * @param string $column      Spaltenname der Laufnummer.
	 * @param int    $jahr        Kalenderjahr.
	 * @param string $jahr_column Spalte für das Jahr.
	 * @return int[] Fehlende Laufnummern.
	 */
	public static function find_gaps( $table_full, $column, $jahr, $jahr_column = 'jahr' ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT {$column} FROM {$table_full} WHERE {$jahr_column} = %d ORDER BY {$column} ASC", // phpcs:ignore
			$jahr
		) );
		$gaps = array();
		$expected = 1;
		foreach ( $rows as $nr ) {
			$nr = (int) $nr;
			while ( $expected < $nr ) {
				$gaps[] = $expected;
				$expected++;
			}
			$expected = $nr + 1;
		}
		return $gaps;
	}
}
