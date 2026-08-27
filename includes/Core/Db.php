<?php
/**
 * Kleine Helfer für Schreibvorgänge, die sonst zeilenweise laufen.
 *
 * Eine Pickliste über zwanzig Bestellungen hat schnell hundert Positionen.
 * Einzeln eingefügt sind das hundert Rundreisen zur Datenbank – und jede
 * kostet Netzwerkzeit, Transaktionsaufwand und einen Indexdurchlauf, ganz
 * unabhängig davon, wie klein die Zeile ist. Als ein Befehl mit hundert
 * Wertetupeln ist es eine Rundreise.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Schreibhilfen auf $wpdb.
 */
final class Db {

	/**
	 * Wie viele Zeilen höchstens in einen Befehl gepackt werden.
	 *
	 * Begrenzt, damit weder das Paketlimit des Servers noch der Speicher
	 * beim Zusammenbauen der Zeichenkette zum Thema wird.
	 */
	const STAPEL = 200;

	/**
	 * Mehrere gleich aufgebaute Zeilen in einem Rutsch einfügen.
	 *
	 * Alle Zeilen müssen dieselben Spalten in derselben Reihenfolge haben –
	 * die Spalten werden aus der ersten Zeile genommen. `null` bleibt
	 * `NULL`, alles andere wird gebunden.
	 *
	 * @param string $table Vollständiger Tabellenname.
	 * @param array  $rows  Liste assoziativer Zeilen.
	 * @return int Anzahl eingefügter Zeilen.
	 */
	public static function insert_many( $table, array $rows ) {
		global $wpdb;

		$rows = array_values( array_filter( $rows, 'is_array' ) );

		if ( ! $rows ) {
			return 0;
		}

		$columns = array_keys( $rows[0] );

		if ( ! $columns ) {
			return 0;
		}

		$spalten   = '`' . implode( '`, `', array_map( static function ( $c ) {
			return str_replace( '`', '', (string) $c );
		}, $columns ) ) . '`';
		$eingefugt = 0;

		foreach ( array_chunk( $rows, self::STAPEL ) as $stapel ) {
			$tupel  = array();
			$werte  = array();

			foreach ( $stapel as $row ) {
				$stellen = array();

				foreach ( $columns as $column ) {
					$value = array_key_exists( $column, $row ) ? $row[ $column ] : null;

					// NULL muss wörtlich dastehen – gebunden würde daraus die
					// Zeichenkette "" und damit eine 0 in einer Zahlenspalte.
					if ( null === $value ) {
						$stellen[] = 'NULL';
						continue;
					}

					// Typgerecht binden, und zwar nicht aus Ordnungsliebe:
					// Eine Kommazahl als `%s` zu binden ginge über die
					// Umwandlung in eine Zeichenkette, und die richtete sich
					// vor PHP 8 nach der eingestellten Sprachumgebung. Auf
					// einem deutschen Server wäre aus 2.5 eine "2,5"
					// geworden – und daraus in der Datenbank eine 2.
					// `wpdb::prepare` behandelt `%f` ausdrücklich
					// sprachunabhängig.
					if ( is_bool( $value ) ) {
						$stellen[] = '%d';
						$werte[]   = (int) $value;
						continue;
					}

					if ( is_int( $value ) ) {
						$stellen[] = '%d';
						$werte[]   = $value;
						continue;
					}

					if ( is_float( $value ) ) {
						$stellen[] = '%f';
						$werte[]   = $value;
						continue;
					}

					$stellen[] = '%s';
					$werte[]   = $value;
				}

				$tupel[] = '(' . implode( ', ', $stellen ) . ')';
			}

			$sql = "INSERT INTO {$table} ({$spalten}) VALUES " . implode( ', ', $tupel );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Spalten stammen aus dem Aufrufer-Code, alle Werte sind gebunden.
			$ergebnis = $werte ? $wpdb->query( $wpdb->prepare( $sql, $werte ) ) : $wpdb->query( $sql );

			if ( false !== $ergebnis ) {
				$eingefugt += (int) $ergebnis;
			}
		}

		return $eingefugt;
	}
}
