<?php
/**
 * Wochenrückblick als Geschichte: wenige Sätze statt Zahlenfriedhof.
 *
 * Bekommt die Fakten der Woche (und der Vorwoche) und macht daraus Sätze:
 * Umsatz und Trend, Bestseller, bester Kanal oder Markt, Stundenlohn,
 * Drucker und Ausschuss, Lager, Nachkauf, Geld – und genau einen Tipp für die
 * nächste Woche, ausgewählt nach dem, was am meisten bringt.
 *
 * Reine Rechnung ohne Datenbank, getestet (tests/ErpReviewTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Wochenrückblick.
 */
class Review {

	/**
	 * Veränderung in Prozent (null ohne Vergleichswert).
	 *
	 * @param float $now  Jetzt.
	 * @param float $prev Vorher.
	 * @return int|null
	 */
	public static function change( $now, $prev ) {
		return (float) $prev > 0 ? (int) round( ( (float) $now - (float) $prev ) / (float) $prev * 100 ) : null;
	}

	/**
	 * Sätze bauen.
	 *
	 * @param array $f Fakten: revenue, revenue_prev, orders, orders_prev, best (Liste {label, qty}),
	 *                 channel {label, rate}|null, market {label, db}|null, rate, rate_prev,
	 *                 printers (Liste {name, plates, scrap}), below_target, empty, reorder (Liste Namen),
	 *                 lost (Anzahl), cash_in4 (Betrag|null), buffer, worst_product {label, rate}|null.
	 * @return array {headline, lines[], tip}
	 */
	public static function compose( array $f ) {
		$lines = array();
		$ch    = self::change( $f['revenue'], $f['revenue_prev'] );

		/* translators: 1: Betrag, 2: Anzahl. */
		$head = sprintf( __( '%1$s Umsatz aus %2$d Verkäufen', 'lindenweg16' ), Kit::eur( $f['revenue'] ), (int) $f['orders'] );
		if ( null !== $ch ) {
			$head .= ' – ' . ( $ch >= 0 ? sprintf( /* translators: %d: Prozent. */ __( '%d %% mehr als letzte Woche', 'lindenweg16' ), $ch ) : sprintf( /* translators: %d: Prozent. */ __( '%d %% weniger als letzte Woche', 'lindenweg16' ), abs( $ch ) ) );
		}

		if ( ! empty( $f['best'] ) ) {
			$names = array();
			foreach ( array_slice( $f['best'], 0, 3 ) as $b ) {
				$names[] = $b['label'] . ' (' . (int) round( $b['qty'] ) . '×)';
			}
			/* translators: %s: Liste. */
			$lines[] = sprintf( __( 'Am meisten verkauft: %s.', 'lindenweg16' ), implode( ', ', $names ) );
		}

		if ( ! empty( $f['market'] ) ) {
			/* translators: 1: Markt, 2: Betrag. */
			$lines[] = sprintf( __( 'Markt der Woche: %1$s mit %2$s Deckungsbeitrag.', 'lindenweg16' ), $f['market']['label'], Kit::eur( $f['market']['db'] ) );
		} elseif ( ! empty( $f['channel'] ) ) {
			/* translators: 1: Kanal, 2: Betrag. */
			$lines[] = sprintf( __( 'Bester Kanal: %1$s mit %2$s je Arbeitsstunde.', 'lindenweg16' ), $f['channel']['label'], Kit::eur( $f['channel']['rate'] ) );
		}

		if ( null !== $f['rate'] ) {
			$rc   = null !== $f['rate_prev'] ? (float) $f['rate'] - (float) $f['rate_prev'] : null;
			/* translators: %s: Betrag. */
			$text = sprintf( __( 'Dein Stundenlohn diese Woche: %s', 'lindenweg16' ), Kit::eur( $f['rate'] ) );
			if ( null !== $rc && abs( $rc ) >= 1 ) {
				/* translators: %s: Betrag. */
				$text .= ' (' . ( $rc > 0 ? '+' : '−' ) . Kit::eur( abs( $rc ) ) . ')';
			}
			$lines[] = $text . '.';
		}

		$weak = null;
		$plates = 0;
		foreach ( (array) $f['printers'] as $p ) {
			$plates += (int) $p['plates'];
			if ( (int) $p['plates'] >= 3 ) {
				$q = (int) $p['scrap'] / (int) $p['plates'];
				if ( $q >= 0.15 && ( ! $weak || $q > $weak['q'] ) ) {
					$weak = array( 'name' => $p['name'], 'q' => $q, 'scrap' => (int) $p['scrap'], 'plates' => (int) $p['plates'] );
				}
			}
		}
		if ( $plates ) {
			/* translators: %d: Anzahl. */
			$text = sprintf( _n( '%d Platte gedruckt.', '%d Platten gedruckt.', $plates, 'lindenweg16' ), $plates );
			if ( $weak ) {
				/* translators: 1: Drucker, 2: Ausschuss, 3: Platten. */
				$text .= ' ' . sprintf( __( 'Sorgenkind: %1$s mit Ausschuss auf %2$d von %3$d Platten.', 'lindenweg16' ), $weak['name'], $weak['scrap'], $weak['plates'] );
			}
			$lines[] = $text;
		}

		if ( (int) $f['empty'] || (int) $f['below_target'] ) {
			/* translators: 1: leer, 2: unter Soll. */
			$lines[] = sprintf( __( 'Lager: %1$d Artikel leer, %2$d unter Soll.', 'lindenweg16' ), (int) $f['empty'], (int) $f['below_target'] );
		}

		if ( ! empty( $f['reorder'] ) ) {
			/* translators: %s: Namen. */
			$lines[] = sprintf( __( 'Bestellen bald wieder: %s.', 'lindenweg16' ), implode( ', ', array_slice( $f['reorder'], 0, 4 ) ) );
		}

		if ( null !== $f['cash_in4'] ) {
			/* translators: %s: Betrag. */
			$lines[] = sprintf( __( 'Kontostand in vier Wochen voraussichtlich %s.', 'lindenweg16' ), Kit::eur( $f['cash_in4'] ) );
		}

		// Genau ein Tipp – das Wichtigste zuerst.
		if ( null !== $f['cash_in4'] && $f['cash_in4'] < (float) $f['buffer'] ) {
			$tip = __( 'Das Geld wird knapp: offene Rechnungen anmahnen und größere Einkäufe schieben (Liquidität → Was wäre wenn).', 'lindenweg16' );
		} elseif ( $weak ) {
			/* translators: %s: Drucker. */
			$tip = sprintf( __( '%s wartest du am besten zuerst: Düse reinigen, Platte entfetten, Filament trocknen.', 'lindenweg16' ), $weak['name'] );
		} elseif ( (int) $f['empty'] > 0 ) {
			$tip = __( 'Leere Artikel zuerst in die Nachtschicht – ausverkauft verkauft nichts.', 'lindenweg16' );
		} elseif ( ! empty( $f['reorder'] ) ) {
			/* translators: %s: Name. */
			$tip = sprintf( __( 'Schreib %s an, bevor die Konkurrenz es tut (Verkauf → Nachkauf).', 'lindenweg16' ), $f['reorder'][0] );
		} elseif ( ! empty( $f['worst_product'] ) && null !== $f['worst_product']['rate'] && $f['worst_product']['rate'] < 15 ) {
			/* translators: 1: Produkt, 2: Betrag. */
			$tip = sprintf( __( '%1$s bringt nur %2$s je Stunde – Preis anheben oder Handarbeit verringern.', 'lindenweg16' ), $f['worst_product']['label'], Kit::eur( $f['worst_product']['rate'] ) );
		} elseif ( (int) $f['lost'] > 0 ) {
			/* translators: %d: Anzahl. */
			$tip = sprintf( _n( '%d Stammkunde ist seit Langem weg – eine persönliche Mail holt oft zurück.', '%d Stammkunden sind seit Langem weg – eine persönliche Mail holt oft zurück.', (int) $f['lost'], 'lindenweg16' ), (int) $f['lost'] );
		} else {
			$tip = __( 'Läuft. Nimm dir eine Stunde für ein neues Produkt oder Foto – die nächste Woche verkauft sich nicht von allein.', 'lindenweg16' );
		}

		return array( 'headline' => $head, 'lines' => $lines, 'tip' => $tip );
	}

	/**
	 * Mailtext.
	 *
	 * @param array  $r       compose().
	 * @param string $company Firma.
	 * @param string $label   Woche (z. B. „KW 45“).
	 * @param string $url     ERP-Adresse.
	 * @return array {subject, body}
	 */
	public static function mail( array $r, $company, $label, $url ) {
		$body = $r['headline'] . "\n\n" . implode( "\n", array_map( function ( $l ) { return '• ' . $l; }, $r['lines'] ) ) . "\n\n" . __( 'Tipp für nächste Woche:', 'lindenweg16' ) . ' ' . $r['tip'] . "\n\n" . $url;

		/* translators: 1: Firma, 2: Woche. */
		return array( 'subject' => sprintf( __( '%1$s · Deine Woche (%2$s)', 'lindenweg16' ), $company, $label ), 'body' => $body );
	}
}
