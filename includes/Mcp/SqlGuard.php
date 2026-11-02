<?php
/**
 * Prüft Datenbankabfragen des MCP-Zugangs: nur lesen, nur eine Anweisung.
 *
 * Reine Regel (tests/McpSqlGuardTest.php). Zusätzlich läuft jede Abfrage in
 * einer Nur-Lese-Transaktion (siehe Tools::db_query) – die Prüfung hier ist
 * die erste, nicht die einzige Sperre.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Nur-Lese-Prüfung für SQL.
 */
class SqlGuard {

	/**
	 * Erlaubte erste Schlüsselwörter.
	 */
	const START = array( 'SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' );

	/**
	 * Wörter, die in einer Leseabfrage nichts verloren haben.
	 */
	const FORBIDDEN = array(
		'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'RENAME',
		'GRANT', 'REVOKE', 'CALL', 'HANDLER', 'LOAD', 'LOCK', 'UNLOCK', 'SET', 'DO', 'PREPARE',
		'EXECUTE', 'DEALLOCATE', 'OPTIMIZE', 'REPAIR', 'ANALYZE', 'INTO', 'OUTFILE', 'DUMPFILE',
		'SLEEP', 'BENCHMARK', 'LOAD_FILE', 'GET_LOCK', 'RELEASE_LOCK', 'SHUTDOWN', 'KILL',
		'FLUSH', 'RESET', 'PURGE', 'INSTALL', 'UNINSTALL', 'COMMIT', 'ROLLBACK', 'SAVEPOINT',
	);

	/**
	 * Bezeichner, die Passwörter oder Zugangsschlüssel enthalten.
	 */
	const SECRETS = array( 'user_pass', 'user_activation_key', 'session_tokens', 'lw16_mcp_tokens' );

	/**
	 * Abfrage prüfen.
	 *
	 * @param string $sql Abfrage.
	 * @return string Leerer Text = in Ordnung, sonst der Grund.
	 */
	public static function check( $sql ) {
		$sql = trim( (string) $sql );

		if ( '' === $sql ) {
			return 'Leere Abfrage.';
		}

		if ( strlen( $sql ) > 20000 ) {
			return 'Abfrage zu lang (höchstens 20.000 Zeichen).';
		}

		$bare = self::strip( $sql );

		if ( null === $bare ) {
			return 'Nicht geschlossene Anführungszeichen oder Kommentare.';
		}

		$bare = rtrim( trim( $bare ), "; \t\n\r" );

		if ( false !== strpos( $bare, ';' ) ) {
			return 'Nur eine Anweisung pro Abfrage (kein Semikolon in der Mitte).';
		}

		$words = self::words( $bare );

		if ( ! $words || ! in_array( $words[0], self::START, true ) ) {
			return 'Nur Leseabfragen erlaubt: ' . implode( ', ', self::START ) . '.';
		}

		// SHOW … (auch SHOW CREATE TABLE) liest nur. Die Textfunktionen
		// REPLACE() und INSERT() sind keine Schreibbefehle.
		$scan = preg_replace( '/\b(REPLACE|INSERT)\s*\(/i', 'fn(', $bare );
		$bad  = 'SHOW' === $words[0] ? array() : array_values( array_intersect( array_unique( self::words( $scan ) ), self::FORBIDDEN ) );
		if ( $bad ) {
			return 'Nicht erlaubt in einer Leseabfrage: ' . implode( ', ', $bad ) . '.';
		}

		if ( preg_match( '/\bFOR\s+(UPDATE|SHARE)\b/i', $bare ) || preg_match( '/\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $bare ) ) {
			return 'Sperrende Leseabfragen sind nicht erlaubt.';
		}

		// Geheimnisse auch in Zeichenketten suchen (z. B. meta_key = 'session_tokens').
		foreach ( self::SECRETS as $secret ) {
			if ( false !== stripos( $sql, $secret ) ) {
				return 'Passwörter und Zugangsschlüssel sind über diesen Zugang gesperrt (' . $secret . ').';
			}
		}

		if ( preg_match( '/@@|\@[a-z_]/i', $bare ) ) {
			return 'Variablen sind nicht erlaubt.';
		}

		return '';
	}

	/**
	 * Braucht die Abfrage ein angehängtes LIMIT?
	 *
	 * @param string $sql Abfrage (bereits geprüft).
	 * @return bool
	 */
	public static function needs_limit( $sql ) {
		$bare  = (string) self::strip( (string) $sql );
		$words = self::words( $bare );

		return $words && in_array( $words[0], array( 'SELECT', 'WITH' ), true ) && ! in_array( 'LIMIT', $words, true );
	}

	/**
	 * LIMIT anhängen.
	 *
	 * @param string $sql   Abfrage.
	 * @param int    $limit Zeilen.
	 * @return string
	 */
	public static function with_limit( $sql, $limit ) {
		$sql = rtrim( trim( (string) $sql ), "; \t\n\r" );

		return self::needs_limit( $sql ) ? $sql . ' LIMIT ' . max( 1, (int) $limit ) : $sql;
	}

	/**
	 * Zeichenketten, Bezeichner in Backticks und Kommentare entfernen.
	 *
	 * @param string $sql Abfrage.
	 * @return string|null Null bei nicht geschlossenem Text.
	 */
	public static function strip( $sql ) {
		$out = '';
		$len = strlen( $sql );
		$i   = 0;

		while ( $i < $len ) {
			$c    = $sql[ $i ];
			$next = $i + 1 < $len ? $sql[ $i + 1 ] : '';

			if ( "'" === $c || '"' === $c ) {
				$i++;
				$closed = false;
				while ( $i < $len ) {
					if ( '\\' === $sql[ $i ] ) {
						$i += 2;
						continue;
					}
					if ( $sql[ $i ] === $c ) {
						if ( $i + 1 < $len && $sql[ $i + 1 ] === $c ) {
							$i += 2;
							continue;
						}
						$closed = true;
						$i++;
						break;
					}
					$i++;
				}
				if ( ! $closed ) {
					return null;
				}
				$out .= " '' ";
				continue;
			}

			if ( '`' === $c ) {
				$end = strpos( $sql, '`', $i + 1 );
				if ( false === $end ) {
					return null;
				}
				$out .= ' q ';
				$i    = $end + 1;
				continue;
			}

			if ( '#' === $c || ( '-' === $c && '-' === $next ) ) {
				$end = strpos( $sql, "\n", $i );
				$i   = false === $end ? $len : $end + 1;
				$out .= ' ';
				continue;
			}

			if ( '/' === $c && '*' === $next ) {
				// MySQL führt /*! … */ aus – solche Kommentare gar nicht zulassen.
				if ( $i + 2 < $len && ( '!' === $sql[ $i + 2 ] || '+' === $sql[ $i + 2 ] ) ) {
					return null;
				}
				$end = strpos( $sql, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$out .= ' ';
				$i    = $end + 2;
				continue;
			}

			$out .= $c;
			$i++;
		}

		return $out;
	}

	/**
	 * Wörter in Großbuchstaben.
	 *
	 * @param string $bare Abfrage ohne Text und Kommentare.
	 * @return string[]
	 */
	protected static function words( $bare ) {
		preg_match_all( '/[A-Za-z_][A-Za-z0-9_$]*/', (string) $bare, $m );

		return array_map( 'strtoupper', $m[0] );
	}
}
