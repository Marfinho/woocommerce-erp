<?php
/**
 * Ehrlicher Stundenlohn: Was bleibt pro gearbeiteter Stunde – je Produkt,
 * Kanal und Markt?
 *
 * Deckungsbeitrag = Erlös − Material/Maschine/Komponenten (Herstellkosten
 * ohne Arbeit) − direkte Kosten (Marktstand, Kampagne). Geteilt durch die
 * Stunden, die dafür gearbeitet wurden:
 * - Handarbeit je Produkt aus der Nachbearbeitungszeit der Kalkulation,
 *   hochgerechnet auf die tatsächlich erfassten Produktionsstunden;
 * - Bestellabwicklung (Packen, Versand) je Bestellung;
 * - Vertriebszeit für Händler und Vereine;
 * - Standzeit auf dem Markt (Kasse auf bis Kasse zu).
 * Alles Übrige (Verwaltung, Marketing, Konstruktion, Wartung) ist
 * Gemeinzeit und geht nur in den Gesamtwert ein.
 *
 * Reine Rechnung ohne Datenbank, getestet (tests/ErpHourlyRateTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Stundenlohn-Rechnung.
 */
class HourlyRate {

	/**
	 * Kanäle mit Versand (die Versandzeit wird auf sie verteilt).
	 */
	const SHIPPING_CHANNELS = array( 'Shop', 'Etsy', 'Fachhandel', 'Verein', 'Sonstiges' );

	/**
	 * Kanäle, auf die Vertriebszeit fällt.
	 */
	const SALES_CHANNELS = array( 'Fachhandel', 'Verein' );

	/**
	 * Rechnen.
	 *
	 * @param array $lines   Verkaufte Positionen {order, article_id, label, channel, market, qty, revenue, material, hand_min}.
	 * @param array $orders  Bestellungen {key, channel, market, handling_min}.
	 * @param array $markets Märkte {name, hours, costs}.
	 * @param array $time    Erfasste Stunden {direct, versand, vertrieb, overhead}.
	 * @param array $opts    {default_handling_min}.
	 * @return array {products[], channels[], markets[], totals}
	 */
	public static function compute( array $lines, array $orders, array $markets, array $time, array $opts = array() ) {
		$default_handling = isset( $opts['default_handling_min'] ) ? (float) $opts['default_handling_min'] : 5.0;

		// 1. Handarbeit: geplante Minuten auf die erfassten Produktionsstunden hochrechnen.
		$planned_h = 0.0;
		foreach ( $lines as $l ) {
			$planned_h += (float) $l['qty'] * (float) $l['hand_min'] / 60;
		}
		$direct = (float) $time['direct'];
		$factor = $planned_h > 0 && $direct > 0 ? $direct / $planned_h : 1.0;

		// 2. Abwicklung je Bestellung: erfasst oder Vorgabe, auf die Versandzeit hochgerechnet.
		$order_h  = array();
		$ship_sum = 0.0;
		foreach ( $orders as $o ) {
			if ( ! in_array( (string) $o['channel'], self::SHIPPING_CHANNELS, true ) ) {
				continue;
			}
			$m                            = null !== $o['handling_min'] ? (float) $o['handling_min'] : $default_handling;
			$order_h[ (string) $o['key'] ] = $m / 60;
			$ship_sum                    += $m / 60;
		}
		$ship_factor = $ship_sum > 0 && (float) $time['versand'] > $ship_sum ? (float) $time['versand'] / $ship_sum : 1.0;
		foreach ( $order_h as $k => $h ) {
			$order_h[ $k ] = $h * $ship_factor;
		}

		// 3. Vertriebszeit gleichmäßig auf Händler- und Vereinsbestellungen.
		$sales_orders = array_values( array_filter( $orders, function ( $o ) {
			return in_array( (string) $o['channel'], self::SALES_CHANNELS, true );
		} ) );
		$sales_each   = $sales_orders ? (float) $time['vertrieb'] / count( $sales_orders ) : 0.0;
		foreach ( $sales_orders as $o ) {
			$order_h[ (string) $o['key'] ] = ( isset( $order_h[ (string) $o['key'] ] ) ? $order_h[ (string) $o['key'] ] : 0.0 ) + $sales_each;
		}

		// 4. Bestellzeit anteilig nach Erlös auf die Positionen der Bestellung.
		$order_rev = array();
		foreach ( $lines as $l ) {
			$order_rev[ (string) $l['order'] ] = ( isset( $order_rev[ (string) $l['order'] ] ) ? $order_rev[ (string) $l['order'] ] : 0.0 ) + (float) $l['revenue'];
		}

		$market_h = array();
		$market_c = array();
		foreach ( $markets as $m ) {
			$market_h[ (string) $m['name'] ] = ( isset( $market_h[ (string) $m['name'] ] ) ? $market_h[ (string) $m['name'] ] : 0.0 ) + (float) $m['hours'];
			$market_c[ (string) $m['name'] ] = ( isset( $market_c[ (string) $m['name'] ] ) ? $market_c[ (string) $m['name'] ] : 0.0 ) + (float) $m['costs'];
		}

		$groups = array( 'products' => array(), 'channels' => array(), 'markets' => array() );
		$add    = function ( $kind, $key, $label, $rev, $mat, $cost, $hours, $qty ) use ( &$groups ) {
			if ( ! isset( $groups[ $kind ][ $key ] ) ) {
				$groups[ $kind ][ $key ] = array( 'key' => $key, 'label' => $label, 'revenue' => 0.0, 'material' => 0.0, 'costs' => 0.0, 'hours' => 0.0, 'qty' => 0.0 );
			}
			$groups[ $kind ][ $key ]['revenue']  += $rev;
			$groups[ $kind ][ $key ]['material'] += $mat;
			$groups[ $kind ][ $key ]['costs']    += $cost;
			$groups[ $kind ][ $key ]['hours']    += $hours;
			$groups[ $kind ][ $key ]['qty']      += $qty;
		};

		foreach ( $lines as $l ) {
			$okey  = (string) $l['order'];
			$share = isset( $order_rev[ $okey ] ) && $order_rev[ $okey ] > 0 ? (float) $l['revenue'] / $order_rev[ $okey ] : 0.0;
			$hours = (float) $l['qty'] * (float) $l['hand_min'] / 60 * $factor + ( isset( $order_h[ $okey ] ) ? $order_h[ $okey ] * $share : 0.0 );
			$add( 'products', 'a' . (int) $l['article_id'], (string) $l['label'], (float) $l['revenue'], (float) $l['material'], 0.0, $hours, (float) $l['qty'] );
			$add( 'channels', (string) $l['channel'], (string) $l['channel'], (float) $l['revenue'], (float) $l['material'], 0.0, $hours, (float) $l['qty'] );
			if ( '' !== (string) $l['market'] ) {
				$add( 'markets', (string) $l['market'], (string) $l['market'], (float) $l['revenue'], (float) $l['material'], 0.0, $hours, (float) $l['qty'] );
			}
		}

		// Standzeit und Standkosten zählen zum Markt und zum Kanal „vor Ort“, auf die Produkte anteilig nach Erlös.
		foreach ( $market_h as $name => $h ) {
			$add( 'markets', $name, $name, 0.0, 0.0, $market_c[ $name ], $h, 0.0 );
			$add( 'channels', 'vor Ort', 'vor Ort', 0.0, 0.0, $market_c[ $name ], $h, 0.0 );
		}

		foreach ( $groups as $kind => $list ) {
			foreach ( $list as $key => $g ) {
				$db                            = $g['revenue'] - $g['material'] - $g['costs'];
				$groups[ $kind ][ $key ]['db']   = round( $db, 2 );
				$groups[ $kind ][ $key ]['rate'] = $g['hours'] > 0.01 ? round( $db / $g['hours'], 2 ) : null;
				foreach ( array( 'revenue', 'material', 'costs', 'hours' ) as $f ) {
					$groups[ $kind ][ $key ][ $f ] = round( $groups[ $kind ][ $key ][ $f ], 2 );
				}
			}
			$list = array_values( $groups[ $kind ] );
			usort(
				$list,
				function ( $a, $b ) {
					return ( null === $b['rate'] ? -1e9 : $b['rate'] ) <=> ( null === $a['rate'] ? -1e9 : $a['rate'] );
				}
			);
			$groups[ $kind ] = $list;
		}

		$rev = 0.0;
		$db  = 0.0;
		$hrs = 0.0;
		foreach ( $groups['channels'] as $c ) {
			$rev += $c['revenue'];
			$db  += $c['db'];
			$hrs += $c['hours'];
		}
		$all_hours = $hrs + (float) $time['overhead'];

		$groups['totals'] = array(
			'revenue'        => round( $rev, 2 ),
			'db'             => round( $db, 2 ),
			'direct_hours'   => round( $hrs, 2 ),
			'overhead_hours' => round( (float) $time['overhead'], 2 ),
			'all_hours'      => round( $all_hours, 2 ),
			'rate_direct'    => $hrs > 0.01 ? round( $db / $hrs, 2 ) : null,
			'rate_all'       => $all_hours > 0.01 ? round( $db / $all_hours, 2 ) : null,
			'scaled'         => round( $factor, 2 ),
		);

		return $groups;
	}

	/**
	 * Erfasste Stunden nach Art (aus hours_by_category).
	 *
	 * @param array $rows {category, hours}.
	 * @return array {direct, versand, vertrieb, overhead}
	 */
	public static function time_buckets( array $rows ) {
		$out = array( 'direct' => 0.0, 'versand' => 0.0, 'vertrieb' => 0.0, 'overhead' => 0.0 );

		foreach ( $rows as $r ) {
			$h = (float) $r['hours'];
			switch ( (string) $r['category'] ) {
				case 'produktion':
				case 'ruestzeit':
				case 'druckzeit':
					$out['direct'] += $h;
					break;
				case 'versand':
					$out['versand'] += $h;
					break;
				case 'vertrieb':
					$out['vertrieb'] += $h;
					break;
				default:
					$out['overhead'] += $h;
			}
		}

		return $out;
	}

	/**
	 * Ein Satz, der das Ergebnis einordnet.
	 *
	 * @param array $result compute().
	 * @return string[]
	 */
	public static function insights( array $result ) {
		$out = array();
		$p   = array_values( array_filter( $result['products'], function ( $x ) {
			return null !== $x['rate'] && $x['hours'] >= 1;
		} ) );

		if ( count( $p ) >= 2 ) {
			$best  = $p[0];
			$worst = $p[ count( $p ) - 1 ];
			/* translators: 1: Produkt, 2: Euro je Stunde, 3: Produkt, 4: Euro je Stunde. */
			$out[] = sprintf( __( 'Am meisten bleibt bei %1$s (%2$s je Stunde), am wenigsten bei %3$s (%4$s je Stunde).', 'lindenweg16' ), $best['label'], Kit::eur( $best['rate'] ), $worst['label'], Kit::eur( $worst['rate'] ) );
			if ( $worst['rate'] < 15 ) {
				/* translators: %s: Produkt. */
				$out[] = sprintf( __( '%s bringt weniger als 15 € je Stunde – Preis prüfen oder Handarbeit verringern.', 'lindenweg16' ), $worst['label'] );
			}
		}

		$c = array_values( array_filter( $result['channels'], function ( $x ) {
			return null !== $x['rate'];
		} ) );
		if ( count( $c ) >= 2 ) {
			/* translators: 1: Kanal, 2: Euro, 3: Kanal, 4: Euro. */
			$out[] = sprintf( __( 'Bester Kanal: %1$s (%2$s je Stunde), schwächster: %3$s (%4$s).', 'lindenweg16' ), $c[0]['label'], Kit::eur( $c[0]['rate'] ), $c[ count( $c ) - 1 ]['label'], Kit::eur( $c[ count( $c ) - 1 ]['rate'] ) );
		}

		$t = $result['totals'];
		if ( null !== $t['rate_all'] && $t['overhead_hours'] > $t['direct_hours'] * 0.5 ) {
			/* translators: %d: Prozent. */
			$out[] = sprintf( __( 'Gut %d %% deiner Zeit geht in Verwaltung, Marketing und Co. – das drückt den Stundenlohn am stärksten.', 'lindenweg16' ), (int) round( $t['overhead_hours'] / max( 0.01, $t['all_hours'] ) * 100 ) );
		}

		return $out;
	}
}
