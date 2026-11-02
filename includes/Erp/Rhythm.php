<?php
/**
 * Nachkauf-Gefühl: Bestellrhythmus eines Kunden erkennen.
 *
 * Aus den Abständen der bisherigen Bestellungen (Median, robust gegen
 * Ausreißer) und deren Streuung ergibt sich, wann die nächste Bestellung zu
 * erwarten ist und wie sicher das ist. Daraus: „bald fällig“, „überfällig“
 * (Kunde droht wegzubleiben), „noch Zeit“.
 *
 * Reine Rechnung ohne Datenbank, getestet (tests/ErpRhythmTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Bestellrhythmus.
 */
class Rhythm {

	/**
	 * Median einer Zahlenliste.
	 *
	 * @param float[] $values Werte.
	 * @return float|null
	 */
	public static function median( array $values ) {
		$values = array_values( $values );
		$n      = count( $values );

		if ( ! $n ) {
			return null;
		}

		sort( $values );
		$mid = intdiv( $n, 2 );

		return $n % 2 ? (float) $values[ $mid ] : ( (float) $values[ $mid - 1 ] + (float) $values[ $mid ] ) / 2;
	}

	/**
	 * Rhythmus aus Bestelldaten.
	 *
	 * @param string[] $dates Bestelldaten JJJJ-MM-TT (beliebige Reihenfolge, gleiche Tage zählen einmal).
	 * @param string   $today Heute.
	 * @return array|null {orders, last, interval, next, days_to, spread, confidence 0..1, state} – null bei weniger als 2 Bestelltagen.
	 */
	public static function analyse( array $dates, $today ) {
		$dates = array_values( array_unique( array_filter( array_map( function ( $d ) {
			return substr( (string) $d, 0, 10 );
		}, $dates ) ) ) );
		sort( $dates );

		if ( count( $dates ) < 2 ) {
			return null;
		}

		$gaps = array();
		for ( $i = 1, $n = count( $dates ); $i < $n; $i++ ) {
			$gaps[] = Kit::days_between( $dates[ $i - 1 ], $dates[ $i ] );
		}

		$median = self::median( $gaps );
		// Streuung als mittlere Abweichung: Eine einzelne lange Pause soll die Sicherheit senken.
		$dev    = array_sum( array_map( function ( $g ) use ( $median ) {
			return abs( $g - $median );
		}, $gaps ) ) / count( $gaps );
		$last   = $dates[ count( $dates ) - 1 ];
		$next   = Kit::add_days( $last, (int) round( $median ) );
		$to     = Kit::days_between( $today, $next );

		// Sicherheit: mehr Bestellungen und gleichmäßigere Abstände = sicherer.
		$regular    = $median > 0 ? max( 0.0, 1 - $dev / $median ) : 0.0;
		$amount     = min( 1.0, ( count( $gaps ) ) / 4 );
		$confidence = round( $regular * 0.6 + $amount * 0.4, 2 );

		// Überfällig ab anderthalb Abständen, verloren nach drei.
		$since = Kit::days_between( $last, $today );
		if ( $median > 0 && $since > 3 * $median ) {
			$state = 'verloren';
		} elseif ( $median > 0 && $since > 1.5 * $median ) {
			$state = 'ueberfaellig';
		} elseif ( $to <= 14 ) {
			$state = 'bald';
		} else {
			$state = 'zeit';
		}

		return array(
			'orders'     => count( $dates ),
			'last'       => $last,
			'interval'   => (int) round( $median ),
			'next'       => $next,
			'days_to'    => $to,
			'spread'     => (int) round( $dev ),
			'confidence' => $confidence,
			'state'      => $state,
		);
	}

	/**
	 * Lieblingsartikel aus den bisherigen Positionen.
	 *
	 * @param array $items Positionen {article_id, label, qty}.
	 * @param int   $max   Wie viele.
	 * @return array Liste {article_id, label, qty, times}
	 */
	public static function favourites( array $items, $max = 3 ) {
		$out = array();

		foreach ( $items as $it ) {
			$k = (int) $it['article_id'] ? 'a' . (int) $it['article_id'] : 'n' . md5( (string) $it['label'] );
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array( 'article_id' => (int) $it['article_id'], 'label' => (string) $it['label'], 'qty' => 0.0, 'times' => 0 );
			}
			$out[ $k ]['qty']   += (float) $it['qty'];
			$out[ $k ]['times'] += 1;
		}

		usort(
			$out,
			function ( $a, $b ) {
				return ( $b['times'] <=> $a['times'] ) ?: ( $b['qty'] <=> $a['qty'] );
			}
		);

		return array_slice( $out, 0, $max );
	}

	/**
	 * Mailtext zum Nachbestellen.
	 *
	 * @param array  $c   {name, person, favourites[], b2b, link}.
	 * @param string $shop Firma.
	 * @return array {subject, body}
	 */
	public static function mail_text( array $c, $shop ) {
		$hello = '' !== trim( (string) $c['person'] ) ? sprintf( /* translators: %s: Name. */ __( 'Hallo %s,', 'lindenweg16' ), trim( (string) $c['person'] ) ) : __( 'Hallo,', 'lindenweg16' );
		$favs  = array();
		foreach ( (array) $c['favourites'] as $f ) {
			$favs[] = '– ' . $f['label'] . ( ! empty( $c['b2b'] ) ? ' (' . sprintf( /* translators: %s: Menge. */ __( 'zuletzt %s Stück', 'lindenweg16' ), str_replace( '.', ',', (string) round( $f['qty'] / max( 1, $f['times'] ) ) ) ) . ')' : '' );
		}
		$list = $favs ? "\n\n" . implode( "\n", $favs ) : '';

		if ( ! empty( $c['b2b'] ) ) {
			/* translators: 1: Anrede, 2: Liste, 3: Link-Satz, 4: Firma. */
			$body = sprintf( __( "%1\$s\n\nbei Ihnen dürfte es bald Zeit für Nachschub sein. Zuletzt hatten Sie bei uns:%2\$s\n\n%3\$sSoll ich etwas zurücklegen oder gleich wieder drucken? Eine kurze Antwort genügt.\n\nViele Grüße\n%4\$s", 'lindenweg16' ), $hello, $list, '' !== (string) $c['link'] ? sprintf( /* translators: %s: Link. */ __( "Nachbestellen geht direkt hier: %s\n\n", 'lindenweg16' ), $c['link'] ) : '', $shop );
		} else {
			/* translators: 1: Anrede, 2: Liste, 3: Link-Satz, 4: Firma. */
			$body = sprintf( __( "%1\$s\n\nschön, dass Sie schon mehrfach bei uns bestellt haben! Falls wieder etwas fehlt – Ihre Favoriten:%2\$s\n\n%3\$sViele Grüße\n%4\$s", 'lindenweg16' ), $hello, $list, '' !== (string) $c['link'] ? sprintf( /* translators: %s: Link. */ __( "Zum Shop: %s\n\n", 'lindenweg16' ), $c['link'] ) : '', $shop );
		}

		return array(
			/* translators: %s: Firma. */
			'subject' => ! empty( $c['b2b'] ) ? sprintf( __( 'Zeit für Nachschub? – %s', 'lindenweg16' ), $shop ) : sprintf( __( 'Wieder etwas für Sie dabei? – %s', 'lindenweg16' ), $shop ),
			'body'    => $body,
		);
	}
}
