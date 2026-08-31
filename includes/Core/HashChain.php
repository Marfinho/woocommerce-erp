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
	 * Einen verketteten Datensatz anlegen.
	 *
	 * Lesen des Vorgängerhashs und Einfügen gehören zusammen. Liefen sie
	 * getrennt, könnten zwei gleichzeitige Schreiber denselben Vorgänger
	 * lesen und beide auf ihn zeigen: Die Kette gabelt sich, und die
	 * Prüfung meldet ab dieser Stelle „mögliche Manipulation" – für jeden
	 * folgenden Satz, weil sie mit dem neu berechneten Hash weiterrechnet.
	 * Ein selbst verursachter Fehlalarm ist bei einem GoBD-Protokoll teuer:
	 * Er sieht genauso aus wie der echte Fall.
	 *
	 * Deshalb hält ein benannter Sperrschlüssel der Datenbank die beiden
	 * Schritte zusammen. Bekommen wir ihn nicht, wird trotzdem geschrieben –
	 * ein fehlender Protokolleintrag wäre schlimmer als ein prüfbarer
	 * Bruch.
	 *
	 * @param string $table_key Kurzname (z. B. 'belege').
	 * @param array  $data      Datensatz ohne hash/vorheriger_hash.
	 * @param array  $extra     Weitere Spalten, die nicht in den Hash eingehen.
	 * @return int Eingefügte ID, 0 bei Fehlschlag.
	 */
	public static function insert( $table_key, array $data, array $extra = array() ) {
		global $wpdb;

		$table_full = $wpdb->prefix . 'lw16_' . $table_key;
		$gesperrt   = self::lock( $table_key );

		try {
			$prev = self::get_last_hash( $table_full );

			$data['hash']            = self::compute( $table_key, $data, $prev );
			$data['vorheriger_hash'] = $prev;

			$ok = false !== $wpdb->insert( $table_full, array_merge( $data, $extra ) );

			return $ok ? (int) $wpdb->insert_id : 0;
		} finally {
			if ( $gesperrt ) {
				self::unlock( $table_key );
			}
		}
	}

	/**
	 * Sperre für das Anhängen an eine Kette.
	 *
	 * @param string $table_key Kurzname.
	 * @return bool Ob die Sperre gehalten wird.
	 */
	protected static function lock( $table_key ) {
		global $wpdb;

		// Der Name enthält die Datenbank, damit sich zwei Anlagen auf
		// demselben Server nicht gegenseitig ausbremsen.
		$name = substr( 'lw16_chain_' . DB_NAME . '_' . $table_key, 0, 64 );

		return '1' === (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 )
		);
	}

	/**
	 * Sperre wieder freigeben.
	 *
	 * @param string $table_key Kurzname.
	 */
	protected static function unlock( $table_key ) {
		global $wpdb;

		$name = substr( 'lw16_chain_' . DB_NAME . '_' . $table_key, 0, 64 );

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
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

		$rows = $wpdb->get_results( "SELECT * FROM {$table_full} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore

		return self::check_rows( $key, (array) $rows );
	}

	/**
	 * Eine geladene Kette prüfen – reine Rechnung.
	 *
	 * Zwei getrennte Aussagen je Datensatz, und das ist der Punkt:
	 *
	 * - **Kettenglied**: Zeigt der Satz auf den Hash seines Vorgängers?
	 *   Bricht das, wurde davor etwas eingefügt oder entfernt.
	 * - **Inhalt**: Passt der gespeicherte Hash zum Inhalt des Satzes und
	 *   zu dem Vorgänger, auf den er selbst zeigt? Bricht das, wurde dieser
	 *   Satz verändert.
	 *
	 * Früher lief die Inhaltsprüfung gegen den *neu berechneten* Vorgänger.
	 * Ein einziger veränderter Satz machte damit jeden folgenden ungültig:
	 * Die Meldung lautete auf zehntausend Zeilen „mögliche Manipulation",
	 * und die eine Zeile, um die es ging, ging darin unter. Die
	 * Beweiskraft bleibt dieselbe – wer einen Satz ändert und seinen Hash
	 * nachzieht, bricht das Kettenglied des nächsten –, nur der Befund ist
	 * jetzt lesbar.
	 *
	 * @param string $key  Kurzname der Tabelle.
	 * @param array  $rows Datensätze, nach id aufsteigend.
	 * @return array Liste aus {id, error}.
	 */
	public static function check_rows( $key, array $rows ) {
		$errors        = array();
		$previous_hash = '';

		foreach ( $rows as $row ) {
			$row         = (array) $row;
			$stored_hash = isset( $row['hash'] ) ? (string) $row['hash'] : '';
			$stored_prev = isset( $row['vorheriger_hash'] ) ? (string) $row['vorheriger_hash'] : '';

			// Datensätze ohne Hash (vor Migration) überspringen.
			if ( '' === $stored_hash ) {
				continue;
			}

			if ( $stored_prev !== $previous_hash ) {
				$errors[] = array(
					'id'    => isset( $row['id'] ) ? (int) $row['id'] : 0,
					'error' => sprintf(
						'Vorgänger-Hash Bruch (erwartet: %s, gespeichert: %s)',
						$previous_hash ? substr( $previous_hash, 0, 12 ) . '…' : 'GENESIS',
						$stored_prev ? substr( $stored_prev, 0, 12 ) . '…' : '(leer)'
					),
				);
			}

			if ( $stored_hash !== self::compute( $key, $row, $stored_prev ) ) {
				$errors[] = array(
					'id'    => isset( $row['id'] ) ? (int) $row['id'] : 0,
					'error' => 'Hash ungültig – Inhalt verändert',
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
