<?php
/**
 * ERP-Seite „Liquidität“: Vorschau auf den Kontostand der nächsten 12 Wochen.
 *
 * Start ist der letzte Kontostand aus dem Bankabgleich (CAMT-Auszug) oder,
 * wenn es den nicht gibt oder der von Hand eingetragene neuer ist, dieser.
 * Dazu kommen je Woche:
 * - Eingänge: offene Rechnungen zum Fälligkeitstag (überfällige in Woche 1),
 *   Dauerbuchungen vom Typ Einnahme, wahlweise der übliche Shop-Umsatz
 *   (Durchschnitt der letzten Wochen).
 * - Ausgänge: Dauerbuchungen (Miete, Versicherung …), Löhne (Durchschnitt der
 *   letzten drei Monate, am Monatsende), offene Einkäufe zum erwarteten
 *   Liefertag.
 *
 * Es ist eine Schätzung – die Seite sagt bei jedem Posten, woher er kommt.
 * Reine Regeln sind getestet (tests/ErpLiquiditaetTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Liquiditätsvorschau.
 */
class Liquiditaet {

	/**
	 * Wochen in der Vorschau.
	 */
	const WEEKS = 12;

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Wochen ab dem Montag der Woche von $start.
	 *
	 * @param string $start JJJJ-MM-TT.
	 * @param int    $n     Anzahl.
	 * @return array Liste {from, to, label}
	 */
	public static function weeks( $start, $n ) {
		$ts     = strtotime( $start . ' 12:00:00 UTC' );
		$monday = $ts - ( ( (int) gmdate( 'N', $ts ) - 1 ) * 86400 );
		$out    = array();

		for ( $i = 0; $i < $n; $i++ ) {
			$from  = gmdate( 'Y-m-d', $monday + $i * 7 * 86400 );
			$to    = gmdate( 'Y-m-d', $monday + ( $i * 7 + 6 ) * 86400 );
			$out[] = array( 'from' => $from, 'to' => $to, 'label' => 'KW ' . (int) gmdate( 'W', $monday + $i * 7 * 86400 ) );
		}

		return $out;
	}

	/**
	 * Nächstes Datum einer Dauerbuchung (wie DauerbuchungRepository::calculate_next).
	 *
	 * @param string $date      JJJJ-MM-TT.
	 * @param string $intervall Intervall.
	 * @return string
	 */
	public static function next_date( $date, $intervall ) {
		$map = array(
			'woechentlich'  => '+1 week',
			'monatlich'     => '+1 month',
			'quartalsweise' => '+3 months',
			'halbjaehrlich' => '+6 months',
			'jaehrlich'     => '+1 year',
		);
		$dt  = new \DateTime( $date . ' 12:00:00', new \DateTimeZone( 'UTC' ) );
		$dt->modify( isset( $map[ $intervall ] ) ? $map[ $intervall ] : '+1 month' );

		return $dt->format( 'Y-m-d' );
	}

	/**
	 * Termine einer Dauerbuchung bis zu einem Datum.
	 *
	 * @param string $next      Nächste Ausführung.
	 * @param string $intervall Intervall.
	 * @param string $until     Bis (einschließlich).
	 * @param string $end       Ende der Dauerbuchung (leer = offen).
	 * @return string[]
	 */
	public static function occurrences( $next, $intervall, $until, $end = '' ) {
		$out  = array();
		$date = (string) $next;
		$stop = '' !== (string) $end && (string) $end < $until ? (string) $end : $until;

		for ( $i = 0; $i < 60 && '' !== $date && $date <= $stop; $i++ ) {
			$out[] = $date;
			$date  = self::next_date( $date, $intervall );
		}

		return $out;
	}

	/**
	 * Vorschau rechnen.
	 *
	 * Posten vor der ersten Woche (überfällig) fallen in Woche 1.
	 *
	 * @param float $start  Startsaldo.
	 * @param array $weeks  Wochen (weeks()).
	 * @param array $events Posten {date, amount (+ Eingang, − Ausgang), label, kind}.
	 * @return array Liste je Woche {from, to, label, in, out, balance, items}
	 */
	public static function project( $start, array $weeks, array $events ) {
		$rows    = array();
		$balance = (float) $start;

		foreach ( $weeks as $i => $w ) {
			$in    = 0.0;
			$out   = 0.0;
			$items = array();

			foreach ( $events as $e ) {
				$d     = (string) $e['date'];
				$match = ( $d >= $w['from'] && $d <= $w['to'] ) || ( 0 === $i && $d < $w['from'] );
				if ( ! $match ) {
					continue;
				}
				if ( (float) $e['amount'] >= 0 ) {
					$in += (float) $e['amount'];
				} else {
					$out += (float) $e['amount'];
				}
				$items[] = $e;
			}

			$balance += $in + $out;
			$rows[]   = array(
				'from'    => $w['from'],
				'to'      => $w['to'],
				'label'   => $w['label'],
				'in'      => round( $in, 2 ),
				'out'     => round( $out, 2 ),
				'balance' => round( $balance, 2 ),
				'items'   => $items,
			);
		}

		return $rows;
	}

	/**
	 * Erste Woche unter dem Puffer.
	 *
	 * @param array $rows   project().
	 * @param float $buffer Puffer.
	 * @return int Index oder -1.
	 */
	public static function first_below( array $rows, $buffer ) {
		foreach ( $rows as $i => $r ) {
			if ( $r['balance'] < (float) $buffer ) {
				return $i;
			}
		}

		return -1;
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns    = RestApi::NAMESPACE_V1;
		$needs = $api->needs( array( 'finanzen.liquiditaet' ) );

		register_rest_route( $ns, '/erp/liquiditaet', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/liquiditaet/einstellungen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Alle Posten der Vorschau.
	 *
	 * @param string $today Heute.
	 * @param string $until Ende.
	 * @param array  $s     Einstellungen.
	 * @return array {events, shop_week, payroll_month}
	 */
	protected static function events( $today, $until, array $s ) {
		$events = array();

		// Offene Rechnungen.
		foreach ( \Lindenweg16\Modules\Betrieb\OrderOpsRepository::open_invoices() as $o ) {
			$due     = $o['invoice_date'] ? Kit::add_days( $o['invoice_date'], (int) $o['payment_terms_days'] ) : Kit::add_days( $today, 14 );
			$contact = $o['contact_id'] ? \Lindenweg16\Modules\Betrieb\ContactRepository::find( (int) $o['contact_id'] ) : null;
			$events[] = array(
				'date'   => $due,
				'amount' => round( \Lindenweg16\Modules\Betrieb\OrderOpsRepository::order_value( $o ), 2 ),
				/* translators: 1: Nummer, 2: Firma. */
				'label'  => sprintf( __( 'Rechnung %1$s · %2$s', 'lindenweg16' ), (int) $o['wc_order_id'] ? '#' . (int) $o['wc_order_id'] : 'A-' . (int) $o['id'], $contact ? $contact['company'] : '' ),
				'kind'   => $due < $today ? 'ueberfaellig' : 'rechnung',
			);
		}

		// Dauerbuchungen.
		foreach ( \Lindenweg16\Modules\Accounting\DauerbuchungRepository::get_all( true ) as $d ) {
			$d = (array) $d;
			foreach ( self::occurrences( (string) $d['naechste_ausfuehrung'], (string) $d['intervall'], $until, (string) $d['ende_datum'] ) as $date ) {
				$events[] = array(
					'date'   => $date,
					'amount' => ( 'einnahme' === $d['typ'] ? 1 : -1 ) * abs( (float) $d['betrag'] ),
					'label'  => (string) $d['bezeichnung'],
					'kind'   => 'dauer',
				);
			}
		}

		// Löhne: Durchschnitt der letzten drei Monate, je Monatsende.
		$from_3  = gmdate( 'Y-m-01', strtotime( $today . ' -3 months' ) );
		$to_3    = gmdate( 'Y-m-t', strtotime( $today . ' -1 month' ) );
		$payroll = 0.0;
		foreach ( \Lindenweg16\Erp\Payslips::between( $from_3, $to_3 ) as $slip ) {
			if ( \Lindenweg16\Erp\Payslips::VOID !== $slip['status'] ) {
				$payroll += (float) $slip['total'];
			}
		}
		$payroll = round( $payroll / 3, 2 );
		if ( $payroll > 0 ) {
			$month = gmdate( 'Y-m-t', strtotime( $today . ' 12:00:00 UTC' ) );
			for ( $i = 0; $i < 4 && $month <= $until; $i++ ) {
				$events[] = array( 'date' => $month, 'amount' => -$payroll, 'label' => __( 'Löhne (Schnitt der letzten 3 Monate)', 'lindenweg16' ), 'kind' => 'lohn' );
				$month    = gmdate( 'Y-m-t', strtotime( gmdate( 'Y-m-01', strtotime( $month . ' 12:00:00 UTC' ) ) . ' +1 month' ) );
			}
		}

		// Offene Einkäufe.
		foreach ( \Lindenweg16\Modules\Betrieb\PurchaseRepository::open() as $p ) {
			$total = \Lindenweg16\Modules\Betrieb\Purchase::total( $p );
			if ( $total <= 0 ) {
				continue;
			}
			$events[] = array(
				'date'   => $p['expected_on'] ? (string) $p['expected_on'] : Kit::add_days( (string) $p['ordered_on'], 14 ),
				'amount' => -round( $total, 2 ),
				/* translators: %s: Lieferant. */
				'label'  => sprintf( __( 'Einkauf bei %s (netto, bei Lieferung)', 'lindenweg16' ), $p['supplier'] ? $p['supplier'] : __( 'Lieferant', 'lindenweg16' ) ),
				'kind'   => 'einkauf',
			);
		}

		// Üblicher Shop-Umsatz.
		$shop_week = 0.0;
		if ( (int) $s['shop_einrechnen'] && function_exists( 'wc_get_orders' ) ) {
			$weeks     = max( 1, min( 52, (int) $s['shop_wochen'] ) );
			$shop_week = round( \Lindenweg16\Modules\Accounting\EuerCalculator::einnahmen( gmdate( 'Y-m-d', strtotime( $today . ' -' . $weeks . ' weeks' ) ), Kit::add_days( $today, -1 ) ) / $weeks, 2 );
			if ( $shop_week > 0 ) {
				foreach ( self::weeks( $today, self::WEEKS ) as $w ) {
					$events[] = array( 'date' => $w['to'], 'amount' => $shop_week, 'label' => __( 'Shop-Umsatz (Schnitt)', 'lindenweg16' ), 'kind' => 'shop' );
				}
			}
		}

		return array( 'events' => $events, 'shop_week' => $shop_week, 'payroll_month' => $payroll );
	}

	/**
	 * Vorschau.
	 *
	 * @return WP_REST_Response
	 */
	public static function overview() {
		$today = current_time( 'Y-m-d' );
		$s     = Kit::settings( 'liqui' );
		$weeks = self::weeks( $today, self::WEEKS );
		$until = $weeks[ count( $weeks ) - 1 ]['to'];
		$bank  = Bank::latest_balance();

		$start = array( 'amount' => (float) $s['saldo'], 'date' => (string) $s['saldo_datum'], 'source' => 'manuell' );
		if ( $bank && ( '' === $start['date'] || $bank['date'] >= $start['date'] ) ) {
			$start = array( 'amount' => $bank['amount'], 'date' => $bank['date'], 'source' => 'bank' );
		}

		$ev   = self::events( $today, $until, $s );
		$rows = self::project( $start['amount'], $weeks, $ev['events'] );

		return new WP_REST_Response(
			array(
				'start'         => $start,
				'rows'          => $rows,
				'settings'      => $s,
				'buffer'        => (float) $s['puffer'],
				'first_below'   => self::first_below( $rows, (float) $s['puffer'] ),
				'shop_week'     => $ev['shop_week'],
				'payroll_month' => $ev['payroll_month'],
				'min'           => $rows ? min( array_column( $rows, 'balance' ) ) : $start['amount'],
			)
		);
	}

	/**
	 * Einstellungen speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save( WP_REST_Request $request ) {
		$in = Kit::input( $request );

		if ( isset( $in['saldo'] ) && '' !== (string) $in['saldo'] && null === Kit::amount( $in['saldo'] ) ) {
			return Kit::fail( __( 'Der Kontostand ist keine Zahl.', 'lindenweg16' ) );
		}
		if ( isset( $in['saldo_datum'] ) && '' !== (string) $in['saldo_datum'] ) {
			$in['saldo_datum'] = Kit::date( $in['saldo_datum'] );
			if ( '' === $in['saldo_datum'] ) {
				return Kit::fail( __( 'Das Datum des Kontostands stimmt nicht.', 'lindenweg16' ) );
			}
		}
		$in['shop_einrechnen'] = empty( $in['shop_einrechnen'] ) ? 0 : 1;

		$saved = Kit::save_settings( 'liqui', $in );
		AuditLog::record( 'erp_liquiditaet_einstellungen', 'settings', 0, $saved );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Gespeichert.', 'lindenweg16' ) ) );
	}
}
