<?php
/**
 * ERP-Seite „Offene Posten“: offene Rechnungen mit Fälligkeit und Mahnstufen.
 *
 * Offene Posten sind die Aufträge mit Rechnung „offen“ oder „berechnet“
 * (`OrderOpsRepository::open_invoices`) – dieselben, die in der Kundenakte und
 * in den Aufgaben als überfällig erscheinen. Fällig = Rechnungsdatum plus
 * Zahlungsziel.
 *
 * Stufen: 1 Zahlungserinnerung (freundlich, ohne Gebühr), 2 erste Mahnung,
 * 3 letzte Mahnung – jeweils mit neuer Frist und optionaler Mahngebühr. Wann
 * die nächste Stufe dran ist, steht in den Einstellungen (Tage nach der
 * vorigen). Jede verschickte Stufe steht mit Text in `dunning_notices`, in der
 * Kundenakte und im Änderungsprotokoll. Die Mahngebühr wird nur ausgewiesen,
 * nicht gebucht – gebucht wird, was tatsächlich gezahlt wird.
 *
 * Reine Regeln sind getestet (tests/ErpMahnwesenTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Pdf;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ContactRepository;
use Lindenweg16\Modules\Betrieb\OrderOpsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Offene Posten und Mahnungen.
 */
class Mahnwesen {

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Bezeichnung der Stufen.
	 *
	 * @return array
	 */
	public static function level_labels() {
		return array(
			1 => __( 'Zahlungserinnerung', 'lindenweg16' ),
			2 => __( '1. Mahnung', 'lindenweg16' ),
			3 => __( 'Letzte Mahnung', 'lindenweg16' ),
		);
	}

	/**
	 * Altersklasse eines offenen Postens.
	 *
	 * @param int $overdue Tage über Fälligkeit (negativ = noch nicht fällig).
	 * @return string nicht_faellig, 1_30, 31_60, ueber_60
	 */
	public static function bucket( $overdue ) {
		if ( $overdue <= 0 ) {
			return 'nicht_faellig';
		}

		return $overdue <= 30 ? '1_30' : ( $overdue <= 60 ? '31_60' : 'ueber_60' );
	}

	/**
	 * Welche Stufe ist dran?
	 *
	 * @param int    $overdue    Tage über Fälligkeit.
	 * @param int    $last_level Zuletzt verschickte Stufe (0 = keine).
	 * @param string $last_sent  Datum der letzten Stufe.
	 * @param string $last_due   Neue Frist der letzten Stufe.
	 * @param array  $s          Einstellungen (tage_erinnerung, tage_stufe2, tage_stufe3).
	 * @param string $today      Heute.
	 * @return array {level: 0..3, due: bool, wait: int} wait = Tage bis die Stufe dran ist.
	 */
	public static function next_level( $overdue, $last_level, $last_sent, $last_due, array $s, $today ) {
		$last_level = (int) $last_level;

		if ( $overdue <= 0 || $last_level >= 3 ) {
			return array( 'level' => 0, 'due' => false, 'wait' => 0 );
		}

		if ( 0 === $last_level ) {
			$due = (int) $overdue > (int) $s['tage_erinnerung'];
			return array( 'level' => 1, 'due' => $due, 'wait' => $due ? 0 : (int) $s['tage_erinnerung'] - (int) $overdue + 1 );
		}

		$gap   = 1 === $last_level ? (int) $s['tage_stufe2'] : (int) $s['tage_stufe3'];
		$ready = '' !== (string) $last_due ? max( Kit::add_days( $last_sent, $gap ), Kit::add_days( $last_due, 1 ) ) : Kit::add_days( $last_sent, $gap );
		$wait  = max( 0, Kit::days_between( $today, $ready ) );

		return array( 'level' => $last_level + 1, 'due' => 0 === $wait, 'wait' => $wait );
	}

	/**
	 * Text einer Erinnerung oder Mahnung.
	 *
	 * @param int    $level   Stufe 1–3.
	 * @param array  $ctx     {company, person, shop, iban, items: [{number, date, value, due}], fee, new_due, today}.
	 * @return array {subject, body, total}
	 */
	public static function text( $level, array $ctx ) {
		$lines = array();
		$sum   = 0.0;

		foreach ( $ctx['items'] as $item ) {
			$sum    += (float) $item['value'];
			/* translators: 1: Nummer, 2: Datum, 3: Betrag, 4: fällig seit. */
			$lines[] = sprintf( __( '– %1$s vom %2$s über %3$s, fällig seit %4$s', 'lindenweg16' ), $item['number'], Kit::dmy( $item['date'] ), Kit::eur( $item['value'] ), Kit::dmy( $item['due'] ) );
		}

		$fee      = $level > 1 ? max( 0, (float) $ctx['fee'] ) : 0.0;
		$interest = $level > 1 && isset( $ctx['interest'] ) ? max( 0, (float) $ctx['interest'] ) : 0.0;
		$flat     = $level > 1 && isset( $ctx['flat'] ) ? max( 0, (float) $ctx['flat'] ) : 0.0;
		$earlier  = isset( $ctx['earlier'] ) ? max( 0, (float) $ctx['earlier'] ) : 0.0;
		$total    = round( $sum + $fee + $interest + $flat + $earlier, 2 );
		$hello = '' !== trim( (string) $ctx['person'] ) ? sprintf( /* translators: %s: Name. */ __( 'Guten Tag %s,', 'lindenweg16' ), trim( (string) $ctx['person'] ) ) : __( 'Guten Tag,', 'lindenweg16' );
		$pay   = '' !== (string) $ctx['iban'] ? sprintf( /* translators: %s: IBAN. */ __( 'Bitte überweisen Sie auf IBAN %s.', 'lindenweg16' ), $ctx['iban'] ) : '';
		$fee_t = $fee > 0 ? sprintf( /* translators: %s: Gebühr. */ __( 'Für diese Mahnung berechnen wir eine Mahngebühr von %s.', 'lindenweg16' ), Kit::eur( $fee ) ) . "\n" : '';
		if ( $earlier > 0 ) {
			/* translators: %s: Betrag. */
			$fee_t .= sprintf( __( 'Aus früheren Mahnungen offen: %s.', 'lindenweg16' ), Kit::eur( $earlier ) ) . "\n";
		}
		if ( $interest > 0 ) {
			/* translators: 1: Betrag, 2: Zinssatz, 3: Tage. */
			$fee_t .= sprintf( __( 'Verzugszinsen nach § 288 BGB (%2$s %% p. a., %3$d Tage): %1$s.', 'lindenweg16' ), Kit::eur( $interest ), str_replace( '.', ',', (string) ( isset( $ctx['rate'] ) ? $ctx['rate'] : '' ) ), isset( $ctx['days'] ) ? (int) $ctx['days'] : 0 ) . "\n";
		}
		if ( $flat > 0 ) {
			/* translators: %s: Betrag. */
			$fee_t .= sprintf( __( 'Verzugspauschale nach § 288 Abs. 5 BGB: %s.', 'lindenweg16' ), Kit::eur( $flat ) ) . "\n";
		}
		$list  = implode( "\n", $lines );
		$due   = Kit::dmy( $ctx['new_due'] );

		switch ( (int) $level ) {
			case 3:
				/* translators: %s: Firma. */
				$subject = sprintf( __( 'Letzte Mahnung – %s', 'lindenweg16' ), $ctx['shop'] );
				/* translators: 1: Anrede, 2: Liste, 3: Gebührensatz, 4: Summe, 5: Frist, 6: Zahlhinweis, 7: Shop. */
				$body = sprintf( __( "%1\$s\n\ntrotz Erinnerung und Mahnung ist folgende Rechnung weiter offen:\n\n%2\$s\n\n%3\$sOffen insgesamt: %4\$s\n\nBitte begleichen Sie den Betrag bis spätestens %5\$s. Danach müssen wir die Forderung leider ohne weitere Ankündigung abgeben. %6\$s\n\nSollte sich die Zahlung überschnitten haben, betrachten Sie dieses Schreiben bitte als gegenstandslos.\n\nMit freundlichen Grüßen\n%7\$s", 'lindenweg16' ), $hello, $list, $fee_t, Kit::eur( $total ), $due, $pay, $ctx['shop'] );
				break;
			case 2:
				/* translators: %s: Firma. */
				$subject = sprintf( __( '1. Mahnung – %s', 'lindenweg16' ), $ctx['shop'] );
				/* translators: 1: Anrede, 2: Liste, 3: Gebührensatz, 4: Summe, 5: Frist, 6: Zahlhinweis, 7: Shop. */
				$body = sprintf( __( "%1\$s\n\nauf unsere Zahlungserinnerung haben wir leider noch keinen Zahlungseingang gesehen. Offen ist:\n\n%2\$s\n\n%3\$sOffen insgesamt: %4\$s\n\nBitte überweisen Sie den Betrag bis %5\$s. %6\$s\n\nSollte sich die Zahlung überschnitten haben, betrachten Sie dieses Schreiben bitte als gegenstandslos.\n\nMit freundlichen Grüßen\n%7\$s", 'lindenweg16' ), $hello, $list, $fee_t, Kit::eur( $total ), $due, $pay, $ctx['shop'] );
				break;
			default:
				/* translators: %s: Firma. */
				$subject = sprintf( __( 'Zahlungserinnerung – %s', 'lindenweg16' ), $ctx['shop'] );
				/* translators: 1: Anrede, 2: Liste, 3: Summe, 4: Frist, 5: Zahlhinweis, 6: Shop. */
				$body = sprintf( __( "%1\$s\n\nim Alltag geht so etwas leicht unter: Bei uns ist noch folgende Rechnung offen:\n\n%2\$s\n\nOffen insgesamt: %3\$s\n\nWir freuen uns über den Ausgleich bis %4\$s. %5\$s\n\nFalls Sie schon überwiesen haben: vielen Dank, dann hat sich diese Nachricht erledigt.\n\nViele Grüße\n%6\$s", 'lindenweg16' ), $hello, $list, Kit::eur( $total ), $due, $pay, $ctx['shop'] );
		}

		return array(
			'subject' => $subject,
			'body'    => preg_replace( "/ +\n/", "\n", $body ),
			'total'   => $total,
		);
	}

	/**
	 * Verzugszinsen (§ 288 BGB): Basiszinssatz plus 9 Prozentpunkte unter
	 * Unternehmern, plus 5 gegenüber Verbrauchern.
	 *
	 * @param float $value    Forderung.
	 * @param float $base     Basiszinssatz in Prozent (kann negativ sein).
	 * @param int   $days     Tage im Verzug.
	 * @param bool  $business Unternehmer?
	 * @return array {rate, amount}
	 */
	public static function interest( $value, $base, $days, $business ) {
		$rate = round( (float) $base + ( $business ? 9 : 5 ), 2 );

		return array(
			'rate'   => $rate,
			'amount' => $days > 0 && $rate > 0 ? round( (float) $value * $rate / 100 * (int) $days / 365, 2 ) : 0.0,
		);
	}

	/**
	 * Deckt eine Zahlung Rechnung und Nebenforderungen?
	 *
	 * @param float $paid   Gezahlt.
	 * @param float $base   Rechnungsbetrag.
	 * @param float $extras Gebühren und Zinsen.
	 * @return bool
	 */
	public static function covers_extras( $paid, $base, $extras ) {
		return $extras > 0 && (float) $paid + 0.005 >= (float) $base + (float) $extras;
	}

	/**
	 * Ist ein Posten gesperrt?
	 *
	 * @param array|null $block Sperre {until, reason} oder null.
	 * @param string     $today Heute.
	 * @return bool
	 */
	public static function is_blocked( $block, $today ) {
		return is_array( $block ) && ( '' === (string) $block['until'] || (string) $block['until'] >= $today );
	}

	/**
	 * Zeitreihe der Bundesbank: Basiszinssatz gemäß § 247 BGB (SU0115).
	 */
	const BASE_RATE_URL = 'https://api.statistiken.bundesbank.de/rest/data/BBIN1/M.DE.BBK.BBKBAS2.EUR.ME?lastNObservations=1';

	/**
	 * Basiszinssatz aus der CSV-Antwort der Bundesbank lesen.
	 *
	 * @param string $csv Antwort (Semikolon, Kopfzeile mit TIME_PERIOD und OBS_VALUE).
	 * @return array|null {rate, period}
	 */
	public static function parse_base_rate( $csv ) {
		$lines = preg_split( '/\r\n|\r|\n/', preg_replace( '/^\xEF\xBB\xBF/', '', (string) $csv ) );
		$head  = null;
		$best  = null;

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = str_getcsv( $line, ';', '"', '' );
			if ( null === $head ) {
				$head = array_flip( $cells );
				if ( ! isset( $head['TIME_PERIOD'], $head['OBS_VALUE'] ) ) {
					return null;
				}
				continue;
			}
			$period = isset( $cells[ $head['TIME_PERIOD'] ] ) ? (string) $cells[ $head['TIME_PERIOD'] ] : '';
			$value  = isset( $cells[ $head['OBS_VALUE'] ] ) ? str_replace( ',', '.', (string) $cells[ $head['OBS_VALUE'] ] ) : '';
			if ( preg_match( '/^\d{4}-\d{2}$/', $period ) && is_numeric( $value ) && ( ! $best || $period > $best['period'] ) ) {
				$best = array( 'rate' => round( (float) $value, 2 ), 'period' => $period );
			}
		}

		return $best;
	}

	/**
	 * Basiszinssatz bei der Bundesbank abrufen und speichern (wenn „automatisch“ an ist).
	 *
	 * @param bool $force Auch wenn der letzte Abruf jünger als eine Woche ist.
	 * @return array {ok, message, rate}
	 */
	public static function refresh_base_rate( $force = false ) {
		$s = Kit::settings( 'mahn' );

		if ( ! (int) $s['basiszins_auto'] ) {
			return array( 'ok' => false, 'message' => __( 'Der Basiszinssatz wird von Hand gepflegt.', 'lindenweg16' ), 'rate' => (float) $s['basiszins'] );
		}
		if ( ! $force && '' !== (string) $s['basiszins_abruf'] && Kit::days_between( $s['basiszins_abruf'], current_time( 'Y-m-d' ) ) < 7 ) {
			return array( 'ok' => true, 'message' => '', 'rate' => (float) $s['basiszins'] );
		}

		$res = wp_remote_get( self::BASE_RATE_URL, array( 'timeout' => 20, 'headers' => array( 'Accept' => 'application/vnd.sdmx.data+csv;version=1.0.0' ) ) );
		$got = is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ? null : self::parse_base_rate( wp_remote_retrieve_body( $res ) );

		if ( ! $got ) {
			\Lindenweg16\Core\Log::error( 'mahnwesen', 'Basiszinssatz der Bundesbank nicht abrufbar' );
			return array( 'ok' => false, 'message' => __( 'Die Bundesbank war nicht erreichbar. Der bisherige Wert bleibt.', 'lindenweg16' ), 'rate' => (float) $s['basiszins'] );
		}

		Kit::save_settings( 'mahn', array( 'basiszins' => $got['rate'], 'basiszins_stand' => $got['period'], 'basiszins_abruf' => current_time( 'Y-m-d' ) ) );

		/* translators: 1: Satz, 2: Monat. */
		return array( 'ok' => true, 'message' => sprintf( __( 'Basiszinssatz laut Bundesbank: %1$s %% (Stand %2$s).', 'lindenweg16' ), str_replace( '.', ',', (string) $got['rate'] ), $got['period'] ), 'rate' => $got['rate'] );
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Routen anmelden. Ein Posten ist ein Auftrag (art=auftrag) oder eine
	 * Shop-Bestellung, die auf die Überweisung wartet (art=shop).
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns    = RestApi::NAMESPACE_V1;
		$needs = $api->needs( array( 'finanzen.mahnwesen' ) );
		$item  = '/erp/mahnwesen/(?P<art>auftrag|shop)/(?P<id>\d+)';

		register_rest_route( $ns, '/erp/mahnwesen', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/mahnwesen/einstellungen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/mahnwesen/sammeln', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send_all' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/mahnwesen/basiszins', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'base_rate_now' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, $item . '/entwurf', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'draft' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, $item . '/pdf', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pdf' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, $item . '/senden', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, $item . '/bezahlt', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'paid' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, $item . '/sperre', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'block' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/mahnwesen/auftrag/(?P<id>\d+)/rechnungsdatum', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invoice_date' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Verschickte Schreiben je Posten.
	 *
	 * @return array Schlüssel (auftrag-ID / shop-ID) => {level, sent, new_due, count, extras_open}
	 */
	protected static function notices() {
		global $wpdb;

		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Kit::table( 'dunning_notices' ) . ' ORDER BY sent_at ASC, id ASC', ARRAY_A ) as $n ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$key = (int) $n['wc_order_id'] && ! (int) $n['order_op_id'] ? 'shop-' . (int) $n['wc_order_id'] : 'auftrag-' . (int) $n['order_op_id'];
			$cur = isset( $out[ $key ] ) ? $out[ $key ] : array( 'level' => 0, 'sent' => '', 'new_due' => '', 'count' => 0, 'extras_open' => 0.0 );

			$out[ $key ] = array(
				'level'       => max( (int) $n['level'], $cur['level'] ),
				'sent'        => substr( (string) $n['sent_at'], 0, 10 ),
				'new_due'     => (string) $n['new_due'],
				'count'       => $cur['count'] + 1,
				'extras_open' => $cur['extras_open'] + ( (int) $n['fee_booked'] ? 0 : (float) $n['fee'] + (float) $n['interest'] ),
			);
		}

		return $out;
	}

	/**
	 * Gemeinsame Felder eines Postens.
	 *
	 * @param array  $base    Grunddaten {kind, id, number, …}.
	 * @param array  $notices Schreiben.
	 * @param array  $s       Einstellungen.
	 * @param string $today   Heute.
	 * @return array
	 */
	protected static function finish( array $base, array $notices, array $s, $today ) {
		$key     = $base['kind'] . '-' . $base['id'];
		$due     = $base['due'];
		$overdue = '' !== $due ? Kit::days_between( $due, $today ) : 0;
		$last    = isset( $notices[ $key ] ) ? $notices[ $key ] : array( 'level' => 0, 'sent' => '', 'new_due' => '', 'count' => 0, 'extras_open' => 0.0 );
		$next    = '' !== $due ? self::next_level( $overdue, $last['level'], $last['sent'], $last['new_due'], $s, $today ) : array( 'level' => 0, 'due' => false, 'wait' => 0 );
		$labels  = self::level_labels();
		$blocks  = self::blocks();
		$block   = isset( $blocks[ $key ] ) && self::is_blocked( $blocks[ $key ], $today ) ? $blocks[ $key ] : null;

		if ( $block ) {
			$next['due'] = false;
		}

		return array_merge(
			$base,
			array(
				'key'         => $key,
				'overdue'     => $overdue,
				'bucket'      => '' === $due ? 'ohne_rechnung' : self::bucket( $overdue ),
				'extras_open' => round( $last['extras_open'], 2 ),
				'last_level'  => (int) $last['level'],
				'last_label'  => $last['level'] ? $labels[ $last['level'] ] : '',
				'last_sent'   => $last['sent'],
				'next_level'  => $next['level'],
				'next_label'  => $next['level'] ? $labels[ $next['level'] ] : '',
				'next_due'    => $next['due'],
				'next_wait'   => $next['wait'],
				'block'       => $block,
			)
		);
	}

	/**
	 * Mahnsperren.
	 *
	 * @return array Schlüssel => {until, reason, by, at}
	 */
	protected static function blocks() {
		$b = get_option( 'lw16_erp_mahnsperre', array() );

		return is_array( $b ) ? $b : array();
	}

	/**
	 * Mahnsperre setzen oder aufheben.
	 *
	 * @param WP_REST_Request $request Anfrage {until, reason, remove}.
	 * @return WP_REST_Response
	 */
	public static function block( WP_REST_Request $request ) {
		$key    = (string) $request['art'] . '-' . (int) $request['id'];
		$in     = Kit::input( $request );
		$blocks = self::blocks();

		if ( ! empty( $in['remove'] ) ) {
			unset( $blocks[ $key ] );
			update_option( 'lw16_erp_mahnsperre', $blocks, false );
			AuditLog::record( 'erp_mahnsperre_aufgehoben', 'shop' === $request['art'] ? 'wc_order' : 'order_ops', (int) $request['id'] );
			return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Mahnsperre aufgehoben.', 'lindenweg16' ) ) );
		}

		$until  = Kit::date( isset( $in['until'] ) ? $in['until'] : '' );
		$reason = Kit::cut( sanitize_text_field( isset( $in['reason'] ) ? (string) $in['reason'] : '' ), 200 );

		if ( '' === $reason ) {
			return Kit::fail( __( 'Bitte einen Grund angeben, z. B. „Ratenzahlung vereinbart“ oder „Reklamation offen“.', 'lindenweg16' ) );
		}
		if ( '' !== $until && $until < current_time( 'Y-m-d' ) ) {
			return Kit::fail( __( 'Das Datum liegt in der Vergangenheit.', 'lindenweg16' ) );
		}

		$blocks[ $key ] = array( 'until' => $until, 'reason' => $reason, 'by' => get_current_user_id(), 'at' => current_time( 'Y-m-d' ) );
		update_option( 'lw16_erp_mahnsperre', $blocks, false );
		AuditLog::record( 'erp_mahnsperre', 'shop' === $request['art'] ? 'wc_order' : 'order_ops', (int) $request['id'], array( 'bis' => $until, 'grund' => $reason ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Mahnsperre gesetzt.', 'lindenweg16' ) ) );
	}

	/**
	 * Auftrag als Posten.
	 *
	 * @param array $o Auftrag.
	 * @return array
	 */
	protected static function from_order( array $o ) {
		$contact = $o['contact_id'] ? ContactRepository::find( (int) $o['contact_id'] ) : null;

		return array(
			'kind'         => 'auftrag',
			'id'           => (int) $o['id'],
			'number'       => (int) $o['wc_order_id'] ? '#' . (int) $o['wc_order_id'] : 'A-' . (int) $o['id'],
			'contact_id'   => (int) $o['contact_id'],
			'company'      => $contact ? (string) $contact['company'] : __( 'ohne Kontakt', 'lindenweg16' ),
			'person'       => $contact ? (string) $contact['contact_person'] : '',
			'email'        => self::email_of( $contact ),
			'address'      => $contact ? array( (string) $contact['company'], (string) $contact['contact_person'], (string) $contact['street'], trim( $contact['zip'] . ' ' . $contact['city'] ) ) : array(),
			'business'     => 'B2C' !== (string) $o['customer_type'],
			'value'        => round( OrderOpsRepository::order_value( $o ), 2 ),
			'order_date'   => substr( (string) OrderOpsRepository::order_date( $o ), 0, 10 ),
			'invoice_date' => (string) $o['invoice_date'],
			'terms'        => (int) $o['payment_terms_days'],
			'due'          => $o['invoice_date'] ? Kit::add_days( $o['invoice_date'], (int) $o['payment_terms_days'] ) : '',
		);
	}

	/**
	 * Shop-Bestellung, die auf die Überweisung wartet, als Posten.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @param array     $s     Einstellungen.
	 * @return array
	 */
	protected static function from_shop( $order, array $s ) {
		$date = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : current_time( 'Y-m-d' );
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$co   = trim( (string) $order->get_billing_company() );

		return array(
			'kind'         => 'shop',
			'id'           => (int) $order->get_id(),
			'number'       => '#' . $order->get_order_number(),
			'contact_id'   => 0,
			'company'      => '' !== $co ? $co : $name,
			'person'       => $name,
			'email'        => (string) $order->get_billing_email(),
			'address'      => array( $co, $name, (string) $order->get_billing_address_1(), trim( $order->get_billing_postcode() . ' ' . $order->get_billing_city() ) ),
			'business'     => '' !== $co,
			'value'        => round( (float) $order->get_total(), 2 ),
			'order_date'   => $date,
			'invoice_date' => $date,
			'terms'        => (int) $s['shop_tage'],
			'due'          => Kit::add_days( $date, (int) $s['shop_tage'] ),
			'payment'      => (string) $order->get_payment_method_title(),
		);
	}

	/**
	 * Wartende Shop-Bestellungen.
	 *
	 * @return array
	 */
	protected static function shop_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		return (array) wc_get_orders(
			array(
				'status' => array( 'wc-on-hold', 'wc-pending' ),
				'limit'  => 300,
				'return' => 'objects',
			)
		);
	}

	/**
	 * Rechnungsadresse eines Kontakts.
	 *
	 * @param array|null $contact Kontakt.
	 * @return string
	 */
	protected static function email_of( $contact ) {
		if ( ! $contact ) {
			return '';
		}

		$profile = ContactRepository::profile( (int) $contact['id'] );

		return $profile && is_email( (string) $profile['billing_email'] ) ? (string) $profile['billing_email'] : (string) $contact['email'];
	}

	/**
	 * Alle offenen Posten.
	 *
	 * @return array
	 */
	public static function all_items() {
		$today   = current_time( 'Y-m-d' );
		$s       = Kit::settings( 'mahn' );
		$notices = self::notices();
		$rows    = array();

		foreach ( OrderOpsRepository::open_invoices() as $o ) {
			$rows[] = self::finish( self::from_order( $o ), $notices, $s, $today );
		}

		if ( (int) $s['shop_mahnen'] ) {
			foreach ( self::shop_orders() as $order ) {
				// Gehört die Bestellung zu einem Auftrag mit offener Rechnung, steht sie dort schon.
				$op = OrderOpsRepository::find_by_order( (int) $order->get_id() );
				if ( $op && in_array( $op['invoice_status'], array( 'offen', 'berechnet' ), true ) && $op['invoice_date'] ) {
					continue;
				}
				$rows[] = self::finish( self::from_shop( $order, $s ), $notices, $s, $today );
			}
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $b['overdue'] - $a['overdue'];
			}
		);

		return $rows;
	}

	/**
	 * Übersicht.
	 *
	 * @return WP_REST_Response
	 */
	public static function overview() {
		global $wpdb;

		$rows = self::all_items();
		$sums = array( 'offen' => 0.0, 'ueberfaellig' => 0.0, 'nicht_faellig' => 0.0, '1_30' => 0.0, '31_60' => 0.0, 'ueber_60' => 0.0, 'ohne_rechnung' => 0.0, 'nebenforderungen' => 0.0 );
		$due  = 0;

		foreach ( $rows as $item ) {
			$sums['offen']             += $item['value'];
			$sums[ $item['bucket'] ]   += $item['value'];
			$sums['nebenforderungen']  += $item['extras_open'];
			if ( '' !== $item['due'] && $item['overdue'] > 0 ) {
				$sums['ueberfaellig'] += $item['value'];
			}
			if ( $item['next_level'] && $item['next_due'] && is_email( $item['email'] ) ) {
				++$due;
			}
		}

		$history = array();
		$labels  = self::level_labels();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Kit::table( 'dunning_notices' ) . ' ORDER BY id DESC LIMIT 50', ARRAY_A ) as $n ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$history[] = array(
				'number'   => (int) $n['order_op_id'] ? 'A-' . (int) $n['order_op_id'] : '#' . (int) $n['wc_order_id'],
				'level'    => isset( $labels[ (int) $n['level'] ] ) ? $labels[ (int) $n['level'] ] : (string) $n['level'],
				'amount'   => (float) $n['amount'],
				'fee'      => (float) $n['fee'] + (float) $n['interest'],
				'booked'   => (bool) $n['fee_booked'],
				'sent_at'  => substr( (string) $n['sent_at'], 0, 10 ),
				'sent_to'  => (string) $n['sent_to'],
			);
		}

		foreach ( $sums as $key => $value ) {
			$sums[ $key ] = round( $value, 2 );
		}

		return new WP_REST_Response(
			array(
				'rows'     => $rows,
				'sums'     => $sums,
				'settings' => Kit::settings( 'mahn' ),
				'due_now'  => $due,
				'history'  => $history,
			)
		);
	}

	/**
	 * Basiszinssatz jetzt abrufen.
	 *
	 * @return WP_REST_Response
	 */
	public static function base_rate_now() {
		$r = self::refresh_base_rate( true );

		return $r['ok'] ? new WP_REST_Response( array( 'ok' => true, 'message' => $r['message'] ) ) : Kit::fail( $r['message'], 502 );
	}

	/**
	 * Einstellungen speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $request ) {
		$in = Kit::input( $request );

		foreach ( array( 'tage_erinnerung', 'tage_stufe2', 'tage_stufe3', 'frist_tage', 'shop_tage' ) as $key ) {
			if ( isset( $in[ $key ] ) && ( ! is_numeric( $in[ $key ] ) || (int) $in[ $key ] < 0 || (int) $in[ $key ] > 120 ) ) {
				return Kit::fail( __( 'Tage bitte als ganze Zahl zwischen 0 und 120.', 'lindenweg16' ) );
			}
		}
		if ( isset( $in['basiszins'] ) && '' !== (string) $in['basiszins'] ) {
			$b = Kit::amount( $in['basiszins'] );
			if ( null === $b || $b < -10 || $b > 20 ) {
				return Kit::fail( __( 'Der Basiszinssatz ist keine plausible Zahl (Prozent, z. B. 1,27).', 'lindenweg16' ) );
			}
		}
		foreach ( array( 'shop_mahnen', 'zinsen', 'pauschale', 'basiszins_auto' ) as $flag ) {
			if ( array_key_exists( $flag, $in ) ) {
				$in[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
			}
		}
		if ( isset( $in['konto'] ) && '' !== (string) $in['konto'] && ! preg_match( '/^\d{4,8}$/', (string) $in['konto'] ) ) {
			return Kit::fail( __( 'Das Konto für Mahngebühren besteht aus 4 bis 8 Ziffern (oder leer lassen).', 'lindenweg16' ) );
		}

		$saved = Kit::save_settings( 'mahn', $in );
		AuditLog::record( 'erp_mahn_einstellungen', 'settings', 0, $saved );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Gespeichert.', 'lindenweg16' ), 'settings' => $saved ) );
	}

	/**
	 * Posten laden.
	 *
	 * @param string $kind auftrag oder shop.
	 * @param int    $id   Kennung.
	 * @return array|WP_REST_Response
	 */
	protected static function load( $kind, $id ) {
		$s = Kit::settings( 'mahn' );

		if ( 'shop' === $kind ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $id ) : null;
			if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
				return Kit::fail( __( 'Diese Shop-Bestellung gibt es nicht (mehr).', 'lindenweg16' ), 404 );
			}
			if ( ! in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
				return Kit::fail( __( 'Diese Bestellung wartet nicht mehr auf eine Zahlung.', 'lindenweg16' ), 409 );
			}
			return self::finish( self::from_shop( $order, $s ), self::notices(), $s, current_time( 'Y-m-d' ) );
		}

		$o = OrderOpsRepository::find( (int) $id );
		if ( ! $o ) {
			return Kit::fail( __( 'Diesen Auftrag gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( ! in_array( $o['invoice_status'], array( 'offen', 'berechnet' ), true ) ) {
			return Kit::fail( __( 'Diese Rechnung ist nicht mehr offen.', 'lindenweg16' ), 409 );
		}

		return self::finish( self::from_order( $o ), self::notices(), $s, current_time( 'Y-m-d' ) );
	}

	/**
	 * Text für eine Stufe.
	 *
	 * @param array      $item  Posten.
	 * @param int        $level Stufe.
	 * @param float|null $fee   Gebühr (null = Vorgabe).
	 * @return array {subject, body, total, fee, interest, flat, new_due}
	 */
	protected static function compose( array $item, $level, $fee = null ) {
		$s        = Kit::settings( 'mahn' );
		$c        = Kit::company();
		$fee      = null === $fee ? ( 2 === $level ? (float) $s['gebuehr_stufe2'] : ( 3 === $level ? (float) $s['gebuehr_stufe3'] : 0.0 ) ) : (float) $fee;
		$new_due  = Kit::add_days( current_time( 'Y-m-d' ), (int) $s['frist_tage'] );
		$int      = array( 'rate' => 0, 'amount' => 0.0 );
		$flat     = 0.0;

		// Zinsen und Pauschale ab der letzten Mahnung; die Pauschale nur unter Unternehmern und nur einmal.
		if ( 3 === $level && (int) $s['zinsen'] ) {
			$int = self::interest( $item['value'], (float) $s['basiszins'], $item['overdue'], $item['business'] );
		}
		if ( 3 === $level && (int) $s['pauschale'] && $item['business'] ) {
			$flat = 40.0;
		}

		$text = self::text(
			$level,
			array(
				'company'  => $item['company'],
				'person'   => $item['person'],
				'shop'     => $c['name'],
				'iban'     => $c['iban'],
				'items'    => array( array( 'number' => $item['number'], 'date' => $item['invoice_date'], 'value' => $item['value'], 'due' => $item['due'] ) ),
				'fee'      => $fee,
				'interest' => $int['amount'],
				'rate'     => $int['rate'],
				'days'     => $item['overdue'],
				'flat'     => $flat,
				'earlier'  => $item['extras_open'],
				'new_due'  => $new_due,
			)
		);

		return array_merge(
			$text,
			array(
				'fee'      => $level > 1 ? $fee : 0.0,
				'interest' => round( $int['amount'] + $flat, 2 ),
				'new_due'  => $new_due,
			)
		);
	}

	/**
	 * Gewählte Stufe (Vorgabe: die nächste).
	 *
	 * @param mixed $raw  Eingabe.
	 * @param array $item Posten.
	 * @return int
	 */
	protected static function level_of( $raw, array $item ) {
		$lvl = (int) $raw;

		return $lvl >= 1 && $lvl <= 3 ? $lvl : max( 1, (int) $item['next_level'] );
	}

	/**
	 * Entwurf.
	 *
	 * @param WP_REST_Request $request Anfrage (level).
	 * @return WP_REST_Response
	 */
	public static function draft( WP_REST_Request $request ) {
		$item = self::load( (string) $request['art'], (int) $request['id'] );
		if ( $item instanceof WP_REST_Response ) {
			return $item;
		}

		$level = self::level_of( $request->get_param( 'level' ), $item );

		return new WP_REST_Response( array_merge( self::compose( $item, $level ), array( 'to' => $item['email'], 'level' => $level, 'item' => $item ) ) );
	}

	/**
	 * Schreiben als PDF bauen.
	 *
	 * @param array $item  Posten.
	 * @param int   $level Stufe.
	 * @param array $text  Text.
	 * @return string
	 */
	protected static function build_pdf( array $item, $level, array $text ) {
		$pdf    = new Pdf();
		$labels = self::level_labels();

		Kit::letter_head(
			$pdf,
			$item['address'] ? $item['address'] : array( $item['company'] ),
			$labels[ $level ],
			array(
				__( 'Datum', 'lindenweg16' )            => Kit::dmy( current_time( 'Y-m-d' ) ),
				__( 'Rechnung/Bestellung', 'lindenweg16' ) => $item['number'],
				__( 'Offen insgesamt', 'lindenweg16' )  => Kit::eur( $text['total'] ),
				__( 'Zahlbar bis', 'lindenweg16' )      => Kit::dmy( $text['new_due'] ),
			)
		);

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text['body'] ) as $line ) {
			$pdf->text( $line, 10 );
		}

		Kit::letter_foot( $pdf );

		return $pdf->output();
	}

	/**
	 * PDF.
	 *
	 * @param WP_REST_Request $request Anfrage (level).
	 * @return WP_REST_Response
	 */
	public static function pdf( WP_REST_Request $request ) {
		$item = self::load( (string) $request['art'], (int) $request['id'] );
		if ( $item instanceof WP_REST_Response ) {
			return $item;
		}

		$level = self::level_of( $request->get_param( 'level' ), $item );

		return Kit::file_response( sanitize_title( self::level_labels()[ $level ] ) . '-' . ltrim( $item['number'], '#' ) . '.pdf', 'application/pdf', self::build_pdf( $item, $level, self::compose( $item, $level ) ) );
	}

	/**
	 * Ein Schreiben verschicken und festhalten.
	 *
	 * @param array      $item    Posten.
	 * @param int        $level   Stufe.
	 * @param string     $to      Empfänger.
	 * @param string     $subject Betreff.
	 * @param string     $body    Text (leer = Vorgabe).
	 * @param float|null $fee     Gebühr (null = Vorgabe).
	 * @return array {ok, message, status}
	 */
	protected static function deliver( array $item, $level, $to, $subject, $body, $fee = null ) {
		global $wpdb;

		if ( '' === $item['due'] || $item['overdue'] <= 0 ) {
			return array( 'ok' => false, 'message' => __( 'Diese Rechnung ist noch nicht fällig.', 'lindenweg16' ), 'status' => 409 );
		}
		if ( $level > $item['last_level'] + 1 ) {
			return array( 'ok' => false, 'message' => __( 'Bitte die Stufen der Reihe nach verschicken (erst Erinnerung, dann Mahnung).', 'lindenweg16' ), 'status' => 409 );
		}
		if ( ! empty( $item['block'] ) ) {
			/* translators: %s: Grund. */
			return array( 'ok' => false, 'message' => sprintf( __( 'Für diesen Posten gilt eine Mahnsperre (%s). Erst aufheben.', 'lindenweg16' ), $item['block']['reason'] ), 'status' => 409 );
		}
		if ( ! is_email( $to ) ) {
			return array( 'ok' => false, 'message' => __( 'Die E-Mail-Adresse stimmt nicht. Bitte in der Kundenakte bzw. Bestellung nachtragen.', 'lindenweg16' ), 'status' => 422 );
		}

		$lock = 'lw16_erp_mahnung_' . $item['key'];
		if ( get_transient( $lock ) ) {
			return array( 'ok' => false, 'message' => __( 'Das Schreiben ist gerade eben schon rausgegangen.', 'lindenweg16' ), 'status' => 409 );
		}

		$text = self::compose( $item, $level, $fee );
		if ( '' !== trim( (string) $body ) ) {
			$text['body'] = $body;
		}
		$subject = '' !== trim( (string) $subject ) ? $subject : $text['subject'];
		$labels  = self::level_labels();

		if ( ! Kit::mail( $to, $subject, $text['body'], sanitize_title( $labels[ $level ] ) . '-' . ltrim( $item['number'], '#' ) . '.pdf', self::build_pdf( $item, $level, $text ) ) ) {
			return array( 'ok' => false, 'message' => __( 'Die Mail konnte nicht verschickt werden. Bitte die Mail-Einstellungen der Website prüfen.', 'lindenweg16' ), 'status' => 502 );
		}
		set_transient( $lock, 1, 60 );

		$wpdb->insert(
			Kit::table( 'dunning_notices' ),
			array(
				'order_op_id' => 'auftrag' === $item['kind'] ? $item['id'] : 0,
				'wc_order_id' => 'shop' === $item['kind'] ? $item['id'] : null,
				'contact_id'  => $item['contact_id'] ? $item['contact_id'] : null,
				'level'       => $level,
				'amount'      => $item['value'],
				'fee'         => $text['fee'],
				'interest'    => $text['interest'],
				'new_due'     => $text['new_due'],
				'sent_to'     => $to,
				'subject'     => $subject,
				'body'        => $text['body'],
				'sent_at'     => current_time( 'mysql', true ),
				'sent_by'     => get_current_user_id(),
			)
		);

		if ( $item['contact_id'] ) {
			ContactRepository::add_activity(
				array(
					'contact_id'    => $item['contact_id'],
					'activity_type' => 'mail',
					/* translators: 1: Stufe, 2: Nummer, 3: Betrag. */
					'summary'       => sprintf( __( '%1$s zu %2$s (%3$s) verschickt.', 'lindenweg16' ), $labels[ $level ], $item['number'], Kit::eur( $text['total'] ) ),
					'next_step'     => __( 'Zahlungseingang prüfen', 'lindenweg16' ),
					'follow_up_on'  => Kit::add_days( $text['new_due'], 1 ),
				)
			);
		}
		if ( 'shop' === $item['kind'] && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $item['id'] );
			if ( $order ) {
				/* translators: 1: Stufe, 2: Adresse. */
				$order->add_order_note( sprintf( __( '%1$s per Mail an %2$s geschickt (ERP).', 'lindenweg16' ), $labels[ $level ], $to ) );
			}
		}
		AuditLog::record( 'erp_mahnung', 'shop' === $item['kind'] ? 'wc_order' : 'order_ops', $item['id'], array( 'stufe' => $level, 'betrag' => $item['value'], 'gebuehr' => $text['fee'], 'zinsen' => $text['interest'], 'an' => $to ) );

		/* translators: %s: Stufe. */
		return array( 'ok' => true, 'message' => sprintf( __( '%s verschickt.', 'lindenweg16' ), $labels[ $level ] ), 'status' => 201 );
	}

	/**
	 * Verschicken.
	 *
	 * @param WP_REST_Request $request Anfrage {level, to, subject, body, fee}.
	 * @return WP_REST_Response
	 */
	public static function send( WP_REST_Request $request ) {
		$item = self::load( (string) $request['art'], (int) $request['id'] );
		if ( $item instanceof WP_REST_Response ) {
			return $item;
		}

		$in      = Kit::input( $request );
		$subject = sanitize_text_field( (string) ( isset( $in['subject'] ) ? $in['subject'] : '' ) );
		$body    = sanitize_textarea_field( (string) ( isset( $in['body'] ) ? $in['body'] : '' ) );

		if ( '' === $subject || '' === trim( $body ) ) {
			return Kit::fail( __( 'Betreff und Text dürfen nicht leer sein.', 'lindenweg16' ) );
		}

		$fee = isset( $in['fee'] ) && '' !== (string) $in['fee'] ? Kit::amount( $in['fee'] ) : null;
		$res = self::deliver( $item, self::level_of( isset( $in['level'] ) ? $in['level'] : 0, $item ), trim( (string) ( isset( $in['to'] ) ? $in['to'] : '' ) ), $subject, $body, null === $fee ? null : max( 0, $fee ) );

		return $res['ok'] ? new WP_REST_Response( array( 'ok' => true, 'message' => $res['message'] ), 201 ) : Kit::fail( $res['message'], $res['status'] );
	}

	/**
	 * Alle fälligen Stufen mit den Vorgabetexten verschicken.
	 *
	 * @return WP_REST_Response
	 */
	public static function send_all() {
		$sent    = 0;
		$skipped = array();

		foreach ( self::all_items() as $item ) {
			if ( ! $item['next_level'] || ! $item['next_due'] ) {
				continue;
			}
			if ( ! is_email( $item['email'] ) ) {
				$skipped[] = $item['number'] . ' (' . __( 'keine Mailadresse', 'lindenweg16' ) . ')';
				continue;
			}
			$res = self::deliver( $item, (int) $item['next_level'], $item['email'], '', '' );
			if ( $res['ok'] ) {
				++$sent;
			} else {
				$skipped[] = $item['number'] . ' (' . $res['message'] . ')';
			}
		}

		/* translators: %d: Anzahl. */
		$msg = sprintf( __( '%d Schreiben verschickt.', 'lindenweg16' ), $sent );
		if ( $skipped ) {
			$msg .= ' ' . __( 'Nicht verschickt:', 'lindenweg16' ) . ' ' . implode( ', ', array_slice( $skipped, 0, 10 ) );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => $msg, 'sent' => $sent, 'skipped' => count( $skipped ) ) );
	}

	/**
	 * Mahngebühren und Zinsen buchen, wenn die Zahlung sie deckt (aus dem Bankabgleich).
	 *
	 * @param string $kind   auftrag oder shop.
	 * @param int    $id     Kennung.
	 * @param float  $paid   Gezahlter Betrag.
	 * @param float  $base   Rechnungsbetrag.
	 * @param string $date   Zahldatum.
	 * @return float Gebuchter Betrag (0 = nichts).
	 */
	public static function book_fee_if_paid( $kind, $id, $paid, $base, $date ) {
		global $wpdb;

		$t    = Kit::table( 'dunning_notices' );
		$col  = 'shop' === $kind ? 'wc_order_id' : 'order_op_id';
		$open = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(fee + interest), 0) FROM {$t} WHERE {$col} = %d AND fee_booked = 0", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( ! self::covers_extras( $paid, $base, $open ) ) {
			return 0.0;
		}

		return self::book_fee( $kind, $id, round( $open, 2 ), $date );
	}

	/**
	 * Nebenforderung als Einnahme ins Journal.
	 *
	 * @param string $kind   auftrag oder shop.
	 * @param int    $id     Kennung.
	 * @param float  $amount Betrag.
	 * @param string $date   Datum.
	 * @return float
	 */
	protected static function book_fee( $kind, $id, $amount, $date ) {
		global $wpdb;

		if ( $amount <= 0 ) {
			return 0.0;
		}

		$s     = Kit::settings( 'mahn' );
		$konto = '' !== (string) $s['konto'] ? (string) $s['konto'] . ' ' : '';
		$col   = 'shop' === $kind ? 'wc_order_id' : 'order_op_id';

		\Lindenweg16\Modules\Accounting\BuchungRepository::create(
			array(
				'buchungsdatum' => $date,
				'jahr'          => (int) substr( $date, 0, 4 ),
				'typ'           => 'einnahme',
				'betrag'        => $amount,
				'kategorie'     => $konto . __( 'Mahngebühren und Verzugszinsen', 'lindenweg16' ),
				/* translators: %s: Nummer. */
				'beschreibung'  => sprintf( __( 'Mahngebühr/Zinsen zu %s', 'lindenweg16' ), ( 'shop' === $kind ? '#' : 'A-' ) . (int) $id ),
				'referenz_typ'  => 'mahnung',
				'referenz_id'   => (int) $id,
			)
		);
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Kit::table( 'dunning_notices' ) . " SET fee_booked = 1 WHERE {$col} = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		AuditLog::record( 'erp_mahngebuehr_gebucht', 'shop' === $kind ? 'wc_order' : 'order_ops', (int) $id, array( 'betrag' => $amount ) );

		return $amount;
	}

	/**
	 * Als bezahlt markieren (wahlweise mit Mahngebühr).
	 *
	 * @param WP_REST_Request $request Anfrage {date, fee_paid}.
	 * @return WP_REST_Response
	 */
	public static function paid( WP_REST_Request $request ) {
		global $wpdb;

		$kind = (string) $request['art'];
		$id   = (int) $request['id'];
		$in   = Kit::input( $request );
		$date = Kit::date( isset( $in['date'] ) ? $in['date'] : '' );
		$date = '' !== $date ? $date : current_time( 'Y-m-d' );
		$msg  = __( 'Als bezahlt markiert.', 'lindenweg16' );

		if ( 'shop' === $kind ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
			if ( ! $order ) {
				return Kit::fail( __( 'Diese Shop-Bestellung gibt es nicht (mehr).', 'lindenweg16' ), 404 );
			}
			if ( in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
				/* translators: %s: Datum. */
				$order->add_order_note( sprintf( __( 'Zahlungseingang am %s (Offene Posten im ERP).', 'lindenweg16' ), Kit::dmy( $date ) ) );
				$order->payment_complete();
			}
		} else {
			$o = OrderOpsRepository::find( $id );
			if ( ! $o ) {
				return Kit::fail( __( 'Diesen Auftrag gibt es nicht (mehr).', 'lindenweg16' ), 404 );
			}
			$change = array( 'invoice_status' => 'bezahlt', 'paid_date' => $date, 'updated_at' => current_time( 'mysql', true ) );
			$wpdb->update( OrderOpsRepository::table(), $change, array( 'id' => $id ) );
			\Lindenweg16\Modules\Betrieb\BetriebAudit::record( 'update', 'order_ops', $id, $o, $change, __( 'Als bezahlt markiert (Offene Posten)', 'lindenweg16' ) );
		}

		$blocks = self::blocks();
		if ( isset( $blocks[ $kind . '-' . $id ] ) ) {
			unset( $blocks[ $kind . '-' . $id ] );
			update_option( 'lw16_erp_mahnsperre', $blocks, false );
		}

		if ( ! empty( $in['fee_paid'] ) ) {
			$col  = 'shop' === $kind ? 'wc_order_id' : 'order_op_id';
			$open = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(fee + interest), 0) FROM ' . Kit::table( 'dunning_notices' ) . " WHERE {$col} = %d AND fee_booked = 0", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$fee  = self::book_fee( $kind, $id, round( $open, 2 ), $date );
			if ( $fee > 0 ) {
				/* translators: %s: Betrag. */
				$msg .= ' ' . sprintf( __( 'Mahngebühr/Zinsen %s als Einnahme gebucht.', 'lindenweg16' ), Kit::eur( $fee ) );
			}
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => $msg ) );
	}

	/**
	 * Rechnungsdatum nachtragen (ab dann läuft das Zahlungsziel).
	 *
	 * @param WP_REST_Request $request Anfrage {date, terms}.
	 * @return WP_REST_Response
	 */
	public static function invoice_date( WP_REST_Request $request ) {
		global $wpdb;

		$o     = OrderOpsRepository::find( (int) $request['id'] );
		$in    = Kit::input( $request );
		$date  = Kit::date( isset( $in['date'] ) ? $in['date'] : '' );
		$terms = isset( $in['terms'] ) && is_numeric( $in['terms'] ) ? max( 0, min( 365, (int) $in['terms'] ) ) : null;

		if ( ! $o ) {
			return Kit::fail( __( 'Diesen Auftrag gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( '' === $date ) {
			return Kit::fail( __( 'Bitte ein Rechnungsdatum wählen.', 'lindenweg16' ) );
		}

		$change = array( 'invoice_date' => $date, 'invoice_status' => 'berechnet', 'updated_at' => current_time( 'mysql', true ) );
		if ( null !== $terms ) {
			$change['payment_terms_days'] = $terms;
		}
		$wpdb->update( OrderOpsRepository::table(), $change, array( 'id' => (int) $o['id'] ) );
		\Lindenweg16\Modules\Betrieb\BetriebAudit::record( 'update', 'order_ops', (int) $o['id'], $o, $change, __( 'Rechnungsdatum nachgetragen (Offene Posten)', 'lindenweg16' ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Rechnungsdatum gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Erinnerungen und Mahnungen an einen Kontakt (für die Kundenakte).
	 *
	 * @param int $contact_id Kontakt.
	 * @return array
	 */
	public static function for_contact( $contact_id ) {
		global $wpdb;

		$labels = self::level_labels();
		$out    = array();

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT order_op_id, level, amount, fee, interest, sent_at, new_due FROM ' . Kit::table( 'dunning_notices' ) . ' WHERE contact_id = %d ORDER BY id DESC LIMIT 20', (int) $contact_id ), ARRAY_A ) as $n ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[] = array(
				'number' => 'A-' . (int) $n['order_op_id'],
				'label'  => isset( $labels[ (int) $n['level'] ] ) ? $labels[ (int) $n['level'] ] : '',
				'amount' => (float) $n['amount'],
				'extra'  => round( (float) $n['fee'] + (float) $n['interest'], 2 ),
				'date'   => substr( (string) $n['sent_at'], 0, 10 ),
				'due'    => (string) $n['new_due'],
			);
		}

		return $out;
	}
}
