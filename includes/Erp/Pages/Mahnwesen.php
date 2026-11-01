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

		$fee   = $level > 1 ? max( 0, (float) $ctx['fee'] ) : 0.0;
		$total = round( $sum + $fee, 2 );
		$hello = '' !== trim( (string) $ctx['person'] ) ? sprintf( /* translators: %s: Name. */ __( 'Guten Tag %s,', 'lindenweg16' ), trim( (string) $ctx['person'] ) ) : __( 'Guten Tag,', 'lindenweg16' );
		$pay   = '' !== (string) $ctx['iban'] ? sprintf( /* translators: %s: IBAN. */ __( 'Bitte überweisen Sie auf IBAN %s.', 'lindenweg16' ), $ctx['iban'] ) : '';
		$fee_t = $fee > 0 ? sprintf( /* translators: %s: Gebühr. */ __( 'Für diese Mahnung berechnen wir eine Mahngebühr von %s.', 'lindenweg16' ), Kit::eur( $fee ) ) . "\n" : '';
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
		$needs = $api->needs( array( 'finanzen.mahnwesen' ) );

		register_rest_route( $ns, '/erp/mahnwesen', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/mahnwesen/einstellungen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/mahnwesen/(?P<id>\d+)/entwurf', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'draft' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/mahnwesen/(?P<id>\d+)/pdf', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pdf' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/mahnwesen/(?P<id>\d+)/senden', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/mahnwesen/(?P<id>\d+)/bezahlt', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'paid' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/mahnwesen/(?P<id>\d+)/rechnungsdatum', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invoice_date' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Letzte verschickte Stufe je Auftrag.
	 *
	 * @return array order_op_id => {level, sent, new_due, count}
	 */
	protected static function last_notices() {
		global $wpdb;

		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT order_op_id, level, sent_at, new_due FROM ' . Kit::table( 'dunning_notices' ) . ' ORDER BY sent_at ASC, id ASC', ARRAY_A ) as $n ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$id         = (int) $n['order_op_id'];
			$count      = isset( $out[ $id ] ) ? $out[ $id ]['count'] + 1 : 1;
			$out[ $id ] = array(
				'level'   => max( (int) $n['level'], isset( $out[ $id ] ) ? $out[ $id ]['level'] : 0 ),
				'sent'    => substr( (string) $n['sent_at'], 0, 10 ),
				'new_due' => (string) $n['new_due'],
				'count'   => $count,
			);
		}

		return $out;
	}

	/**
	 * Ein offener Posten für Anzeige und Text.
	 *
	 * @param array  $o       Auftrag.
	 * @param array  $notices Letzte Stufen.
	 * @param array  $s       Einstellungen.
	 * @param string $today   Heute.
	 * @return array
	 */
	protected static function item( array $o, array $notices, array $s, $today ) {
		$contact = $o['contact_id'] ? ContactRepository::find( (int) $o['contact_id'] ) : null;
		$due     = $o['invoice_date'] ? Kit::add_days( $o['invoice_date'], (int) $o['payment_terms_days'] ) : '';
		$overdue = '' !== $due ? Kit::days_between( $due, $today ) : 0;
		$last    = isset( $notices[ (int) $o['id'] ] ) ? $notices[ (int) $o['id'] ] : array( 'level' => 0, 'sent' => '', 'new_due' => '', 'count' => 0 );
		$next    = '' !== $due ? self::next_level( $overdue, $last['level'], $last['sent'], $last['new_due'], $s, $today ) : array( 'level' => 0, 'due' => false, 'wait' => 0 );
		$labels  = self::level_labels();

		return array(
			'id'           => (int) $o['id'],
			'number'       => (int) $o['wc_order_id'] ? '#' . (int) $o['wc_order_id'] : 'A-' . (int) $o['id'],
			'contact_id'   => (int) $o['contact_id'],
			'company'      => $contact ? (string) $contact['company'] : __( 'ohne Kontakt', 'lindenweg16' ),
			'person'       => $contact ? (string) $contact['contact_person'] : '',
			'email'        => self::email_of( $contact ),
			'value'        => round( OrderOpsRepository::order_value( $o ), 2 ),
			'order_date'   => substr( (string) OrderOpsRepository::order_date( $o ), 0, 10 ),
			'invoice_date' => (string) $o['invoice_date'],
			'terms'        => (int) $o['payment_terms_days'],
			'due'          => $due,
			'overdue'      => $overdue,
			'bucket'       => '' === $due ? 'ohne_rechnung' : self::bucket( $overdue ),
			'last_level'   => (int) $last['level'],
			'last_label'   => $last['level'] ? $labels[ $last['level'] ] : '',
			'last_sent'    => $last['sent'],
			'next_level'   => $next['level'],
			'next_label'   => $next['level'] ? $labels[ $next['level'] ] : '',
			'next_due'     => $next['due'],
			'next_wait'    => $next['wait'],
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
	 * Übersicht.
	 *
	 * @return WP_REST_Response
	 */
	public static function overview() {
		$today   = current_time( 'Y-m-d' );
		$s       = Kit::settings( 'mahn' );
		$notices = self::last_notices();
		$rows    = array();
		$sums    = array( 'offen' => 0.0, 'ueberfaellig' => 0.0, 'nicht_faellig' => 0.0, '1_30' => 0.0, '31_60' => 0.0, 'ueber_60' => 0.0, 'ohne_rechnung' => 0.0 );

		foreach ( OrderOpsRepository::open_invoices() as $o ) {
			$item                      = self::item( $o, $notices, $s, $today );
			$rows[]                    = $item;
			$sums['offen']            += $item['value'];
			$sums[ $item['bucket'] ]  += $item['value'];
			if ( $item['overdue'] > 0 && '' !== $item['due'] ) {
				$sums['ueberfaellig'] += $item['value'];
			}
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $b['overdue'] - $a['overdue'];
			}
		);

		$waiting = 0;
		if ( function_exists( 'wc_get_orders' ) ) {
			$waiting = count( (array) wc_get_orders( array( 'status' => array( 'wc-on-hold' ), 'limit' => 200, 'return' => 'ids', 'date_created' => '<' . ( time() - 7 * DAY_IN_SECONDS ) ) ) );
		}

		$history = array();
		global $wpdb;
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Kit::table( 'dunning_notices' ) . ' ORDER BY id DESC LIMIT 30', ARRAY_A ) as $n ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$labels    = self::level_labels();
			$history[] = array(
				'order_op_id' => (int) $n['order_op_id'],
				'level'       => isset( $labels[ (int) $n['level'] ] ) ? $labels[ (int) $n['level'] ] : (string) $n['level'],
				'amount'      => (float) $n['amount'],
				'fee'         => (float) $n['fee'],
				'sent_at'     => substr( (string) $n['sent_at'], 0, 10 ),
				'sent_to'     => (string) $n['sent_to'],
			);
		}

		foreach ( $sums as $key => $value ) {
			$sums[ $key ] = round( $value, 2 );
		}

		return new WP_REST_Response(
			array(
				'rows'         => $rows,
				'sums'         => $sums,
				'settings'     => $s,
				'shop_waiting' => $waiting,
				'history'      => $history,
			)
		);
	}

	/**
	 * Einstellungen speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $request ) {
		$in = Kit::input( $request );

		foreach ( array( 'tage_erinnerung', 'tage_stufe2', 'tage_stufe3', 'frist_tage' ) as $key ) {
			if ( isset( $in[ $key ] ) && ( ! is_numeric( $in[ $key ] ) || (int) $in[ $key ] < 0 || (int) $in[ $key ] > 120 ) ) {
				return Kit::fail( __( 'Tage bitte als ganze Zahl zwischen 0 und 120.', 'lindenweg16' ) );
			}
		}

		$saved = Kit::save_settings( 'mahn', $in );
		AuditLog::record( 'erp_mahn_einstellungen', 'settings', 0, $saved );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Gespeichert.', 'lindenweg16' ), 'settings' => $saved ) );
	}

	/**
	 * Auftrag als offenen Posten laden.
	 *
	 * @param int $id Auftrag.
	 * @return array|WP_REST_Response
	 */
	protected static function load( $id ) {
		$o = OrderOpsRepository::find( (int) $id );

		if ( ! $o ) {
			return Kit::fail( __( 'Diesen Auftrag gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( ! in_array( $o['invoice_status'], array( 'offen', 'berechnet' ), true ) ) {
			return Kit::fail( __( 'Diese Rechnung ist nicht mehr offen.', 'lindenweg16' ), 409 );
		}

		return self::item( $o, self::last_notices(), Kit::settings( 'mahn' ), current_time( 'Y-m-d' ) );
	}

	/**
	 * Text für eine Stufe.
	 *
	 * @param array $item  Posten.
	 * @param int   $level Stufe.
	 * @param float $fee   Gebühr (null = Vorgabe).
	 * @return array {subject, body, total, fee, new_due}
	 */
	protected static function compose( array $item, $level, $fee = null ) {
		$s       = Kit::settings( 'mahn' );
		$c       = Kit::company();
		$fee     = null === $fee ? ( 2 === $level ? (float) $s['gebuehr_stufe2'] : ( 3 === $level ? (float) $s['gebuehr_stufe3'] : 0.0 ) ) : (float) $fee;
		$new_due = Kit::add_days( current_time( 'Y-m-d' ), (int) $s['frist_tage'] );
		$text    = self::text(
			$level,
			array(
				'company' => $item['company'],
				'person'  => $item['person'],
				'shop'    => $c['name'],
				'iban'    => $c['iban'],
				'items'   => array( array( 'number' => $item['number'], 'date' => $item['invoice_date'], 'value' => $item['value'], 'due' => $item['due'] ) ),
				'fee'     => $fee,
				'new_due' => $new_due,
			)
		);

		return array_merge( $text, array( 'fee' => $level > 1 ? $fee : 0.0, 'new_due' => $new_due ) );
	}

	/**
	 * Gewählte Stufe aus der Anfrage (Vorgabe: die nächste).
	 *
	 * @param WP_REST_Request|array $src  Anfrage oder Eingaben.
	 * @param array                 $item Posten.
	 * @return int
	 */
	protected static function level_of( $src, array $item ) {
		$raw = is_array( $src ) ? ( isset( $src['level'] ) ? $src['level'] : 0 ) : $src->get_param( 'level' );
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
		$item = self::load( (int) $request['id'] );
		if ( $item instanceof WP_REST_Response ) {
			return $item;
		}

		$level = self::level_of( $request, $item );
		$text  = self::compose( $item, $level );

		return new WP_REST_Response( array_merge( $text, array( 'to' => $item['email'], 'level' => $level, 'item' => $item ) ) );
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
		$c      = $item['contact_id'] ? ContactRepository::find( $item['contact_id'] ) : null;
		$addr   = $c ? array( (string) $c['company'], (string) $c['contact_person'], (string) $c['street'], trim( $c['zip'] . ' ' . $c['city'] ) ) : array( $item['company'] );

		Kit::letter_head(
			$pdf,
			$addr,
			$labels[ $level ],
			array(
				__( 'Datum', 'lindenweg16' )         => Kit::dmy( current_time( 'Y-m-d' ) ),
				__( 'Rechnung/Auftrag', 'lindenweg16' ) => $item['number'],
				__( 'Zahlbar bis', 'lindenweg16' )   => Kit::dmy( $text['new_due'] ),
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
		$item = self::load( (int) $request['id'] );
		if ( $item instanceof WP_REST_Response ) {
			return $item;
		}

		$level = self::level_of( $request, $item );

		return Kit::file_response( sanitize_title( self::level_labels()[ $level ] ) . '-' . $item['number'] . '.pdf', 'application/pdf', self::build_pdf( $item, $level, self::compose( $item, $level ) ) );
	}

	/**
	 * Verschicken und festhalten.
	 *
	 * @param WP_REST_Request $request Anfrage {level, to, subject, body, fee}.
	 * @return WP_REST_Response
	 */
	public static function send( WP_REST_Request $request ) {
		global $wpdb;

		$item = self::load( (int) $request['id'] );
		if ( $item instanceof WP_REST_Response ) {
			return $item;
		}

		$in      = Kit::input( $request );
		$level   = self::level_of( $in, $item );
		$to      = trim( (string) ( isset( $in['to'] ) ? $in['to'] : '' ) );
		$subject = sanitize_text_field( (string) ( isset( $in['subject'] ) ? $in['subject'] : '' ) );
		$body    = sanitize_textarea_field( (string) ( isset( $in['body'] ) ? $in['body'] : '' ) );
		$fee     = Kit::amount( isset( $in['fee'] ) ? $in['fee'] : '' );

		if ( '' === $item['due'] || $item['overdue'] <= 0 ) {
			return Kit::fail( __( 'Diese Rechnung ist noch nicht fällig.', 'lindenweg16' ), 409 );
		}
		if ( $level > $item['last_level'] + 1 ) {
			return Kit::fail( __( 'Bitte die Stufen der Reihe nach verschicken (erst Erinnerung, dann Mahnung).', 'lindenweg16' ), 409 );
		}
		if ( ! is_email( $to ) ) {
			return Kit::fail( __( 'Die E-Mail-Adresse stimmt nicht. Bitte in der Kundenakte nachtragen.', 'lindenweg16' ) );
		}
		if ( '' === $subject || '' === trim( $body ) ) {
			return Kit::fail( __( 'Betreff und Text dürfen nicht leer sein.', 'lindenweg16' ) );
		}

		$lock = 'lw16_erp_mahnung_' . $item['id'];
		if ( get_transient( $lock ) ) {
			return Kit::fail( __( 'Das Schreiben ist gerade eben schon rausgegangen.', 'lindenweg16' ), 409 );
		}

		$text         = self::compose( $item, $level, null === $fee ? null : $fee );
		$text['body'] = $body;
		$labels       = self::level_labels();

		if ( ! Kit::mail( $to, $subject, $body, sanitize_title( $labels[ $level ] ) . '-' . $item['number'] . '.pdf', self::build_pdf( $item, $level, $text ) ) ) {
			return Kit::fail( __( 'Die Mail konnte nicht verschickt werden. Bitte die Mail-Einstellungen der Website prüfen.', 'lindenweg16' ), 502 );
		}
		set_transient( $lock, 1, 60 );

		$wpdb->insert(
			Kit::table( 'dunning_notices' ),
			array(
				'order_op_id' => $item['id'],
				'contact_id'  => $item['contact_id'] ? $item['contact_id'] : null,
				'level'       => $level,
				'amount'      => $item['value'],
				'fee'         => $text['fee'],
				'new_due'     => $text['new_due'],
				'sent_to'     => $to,
				'subject'     => $subject,
				'body'        => $body,
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
					'summary'       => sprintf( __( '%1$s zu %2$s (%3$s) verschickt.', 'lindenweg16' ), $labels[ $level ], $item['number'], Kit::eur( $item['value'] ) ),
					'next_step'     => __( 'Zahlungseingang prüfen', 'lindenweg16' ),
					'follow_up_on'  => Kit::add_days( $text['new_due'], 1 ),
				)
			);
		}
		AuditLog::record( 'erp_mahnung', 'order_ops', $item['id'], array( 'stufe' => $level, 'betrag' => $item['value'], 'gebuehr' => $text['fee'], 'an' => $to ) );

		/* translators: %s: Stufe. */
		return new WP_REST_Response( array( 'ok' => true, 'message' => sprintf( __( '%s verschickt.', 'lindenweg16' ), $labels[ $level ] ) ), 201 );
	}

	/**
	 * Als bezahlt markieren.
	 *
	 * @param WP_REST_Request $request Anfrage {date}.
	 * @return WP_REST_Response
	 */
	public static function paid( WP_REST_Request $request ) {
		global $wpdb;

		$o    = OrderOpsRepository::find( (int) $request['id'] );
		$in   = Kit::input( $request );
		$date = Kit::date( isset( $in['date'] ) ? $in['date'] : '' );
		$date = '' !== $date ? $date : current_time( 'Y-m-d' );

		if ( ! $o ) {
			return Kit::fail( __( 'Diesen Auftrag gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$change = array( 'invoice_status' => 'bezahlt', 'paid_date' => $date, 'updated_at' => current_time( 'mysql', true ) );
		$wpdb->update( OrderOpsRepository::table(), $change, array( 'id' => (int) $o['id'] ) );
		\Lindenweg16\Modules\Betrieb\BetriebAudit::record( 'update', 'order_ops', (int) $o['id'], $o, $change, __( 'Als bezahlt markiert (Offene Posten)', 'lindenweg16' ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Als bezahlt markiert.', 'lindenweg16' ) ) );
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
}
