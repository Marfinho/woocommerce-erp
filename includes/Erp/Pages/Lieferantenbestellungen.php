<?php
/**
 * ERP-Seite „Bestellungen an Lieferanten“: Bestellschein (PDF), Mail, Bestätigung, Lieferstand.
 *
 * Die einzelnen Einkäufe entstehen wie bisher im Einkauf (Tabelle purchases,
 * je Teil eine Zeile). Hier werden die offenen Einkäufe eines Lieferanten zu
 * einem Bestellschein mit Nummer (BE-JJJJ-0001) gebündelt, als PDF per Mail
 * geschickt und die Bestätigung festgehalten. Bestätigt der Lieferant einen
 * Liefertermin, bekommen die Einkäufe diesen als erwarteten Tag – das verbessert
 * Bedarf und Liquiditätsvorschau. Der Lieferstand ergibt sich aus den
 * Wareneingängen im Einkauf: teilgeliefert, geliefert.
 *
 * Reine Regeln sind getestet (tests/ErpLieferantenbestellungenTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Pdf;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\Procurement;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\Purchase;
use Lindenweg16\Modules\Betrieb\PurchaseRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Bestellungen an Lieferanten.
 */
class Lieferantenbestellungen {

	/**
	 * Option mit den Mailadressen der Lieferanten (Name => Adresse).
	 */
	const EMAILS = 'lw16_erp_supplier_emails';

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Zustände.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'entwurf'       => __( 'Entwurf', 'lindenweg16' ),
			'versendet'     => __( 'bestellt', 'lindenweg16' ),
			'bestaetigt'    => __( 'bestätigt', 'lindenweg16' ),
			'teilgeliefert' => __( 'teilweise geliefert', 'lindenweg16' ),
			'geliefert'     => __( 'geliefert', 'lindenweg16' ),
			'storniert'     => __( 'storniert', 'lindenweg16' ),
		);
	}

	/**
	 * Angezeigter Zustand aus eigenem Status und Lieferstand der Einkäufe.
	 *
	 * @param string $status    Gespeicherter Status.
	 * @param array  $purchases Einkäufe (status).
	 * @return string
	 */
	public static function shown_status( $status, array $purchases ) {
		if ( ! $purchases ) {
			return (string) $status;
		}

		$states = array_count_values( array_map( function ( $p ) { return (string) $p['status']; }, $purchases ) );
		$total  = count( $purchases );
		$done   = isset( $states['geliefert'] ) ? $states['geliefert'] : 0;
		$void   = isset( $states['storniert'] ) ? $states['storniert'] : 0;

		if ( $void === $total ) {
			return 'storniert';
		}
		if ( $done + $void === $total ) {
			return 'geliefert';
		}
		if ( $done > 0 ) {
			return 'teilgeliefert';
		}

		return (string) $status;
	}

	/**
	 * Offene Einkäufe ohne Bestellschein nach Lieferant.
	 *
	 * @param array $purchases Offene Einkäufe.
	 * @param array $linked    Kennungen mit Bestellschein (id => true).
	 * @return array Lieferant => Einkäufe
	 */
	public static function group_open( array $purchases, array $linked ) {
		$out   = array();
		$names = array();

		foreach ( $purchases as $p ) {
			if ( isset( $linked[ (int) $p['id'] ] ) || ! Purchase::is_open( $p ) ) {
				continue;
			}
			$name = trim( (string) $p['supplier'] );
			$name = '' !== $name ? $name : __( 'ohne Lieferant', 'lindenweg16' );
			$key  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );

			// Gleicher Lieferant, anders geschrieben („Würth“, „würth“): ein Bestellschein.
			if ( ! isset( $names[ $key ] ) ) {
				$names[ $key ] = $name;
			}
			$out[ $names[ $key ] ][] = $p;
		}

		ksort( $out, SORT_NATURAL | SORT_FLAG_CASE );

		return $out;
	}

	/**
	 * Summen eines Bestellscheins (netto).
	 *
	 * @param array $purchases Einkäufe.
	 * @return array {goods, shipping, total}
	 */
	public static function totals( array $purchases ) {
		$goods = 0.0;
		$ship  = 0.0;

		foreach ( $purchases as $p ) {
			if ( 'storniert' === $p['status'] ) {
				continue;
			}
			$goods += (float) $p['qty'] * (float) $p['unit_price_net'];
			$ship  += (float) $p['shipping_net'];
		}

		return array( 'goods' => round( $goods, 2 ), 'shipping' => round( $ship, 2 ), 'total' => round( $goods + $ship, 2 ) );
	}

	/**
	 * Mailtext.
	 *
	 * @param array  $order   Bestellschein.
	 * @param string $company Firma.
	 * @return array {subject, body}
	 */
	public static function mail_text( array $order, $company ) {
		$wanted = '' !== (string) $order['wanted_by'] ? sprintf( /* translators: %s: Datum. */ __( 'Wir benötigen die Ware bis %s.', 'lindenweg16' ), Kit::dmy( $order['wanted_by'] ) ) . ' ' : '';

		return array(
			/* translators: 1: Nummer, 2: Firma. */
			'subject' => sprintf( __( 'Bestellung %1$s – %2$s', 'lindenweg16' ), $order['number'], $company ),
			/* translators: 1: Nummer, 2: Termin, 3: Firma. */
			'body'    => sprintf( __( "Guten Tag,\n\nim Anhang finden Sie unsere Bestellung %1\$s. %2\$sBitte bestätigen Sie uns kurz Lieferumfang und Liefertermin.\n\nVielen Dank und viele Grüße\n%3\$s", 'lindenweg16' ), $order['number'], $wanted, $company ),
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
		$needs = $api->needs( array( 'lager.bestellungen' ) );

		register_rest_route( $ns, '/erp/lieferantenbestellungen', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'index' ), 'permission_callback' => $needs ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $needs ),
		) );
		register_rest_route( $ns, '/erp/lieferantenbestellungen/(?P<id>\d+)/pdf', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pdf' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/lieferantenbestellungen/(?P<id>\d+)/mail', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'mail_draft' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/lieferantenbestellungen/(?P<id>\d+)/senden', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/lieferantenbestellungen/(?P<id>\d+)/bestaetigt', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'confirm' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/lieferantenbestellungen/(?P<id>\d+)/aufloesen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'dissolve' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Gespeicherte Mailadresse eines Lieferanten.
	 *
	 * @param string $supplier Name.
	 * @return string
	 */
	protected static function email_for( $supplier ) {
		$all = get_option( self::EMAILS, array() );
		$key = strtolower( trim( (string) $supplier ) );

		return is_array( $all ) && isset( $all[ $key ] ) ? (string) $all[ $key ] : '';
	}

	/**
	 * Mailadresse eines Lieferanten merken.
	 *
	 * @param string $supplier Name.
	 * @param string $email    Adresse.
	 */
	protected static function remember_email( $supplier, $email ) {
		if ( ! is_email( $email ) ) {
			return;
		}
		$all = get_option( self::EMAILS, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ strtolower( trim( (string) $supplier ) ) ] = $email;
		update_option( self::EMAILS, $all, false );
	}

	/**
	 * Bestellschein mit Einkäufen laden.
	 *
	 * @param int $id Kennung.
	 * @return array|null
	 */
	protected static function find( $id ) {
		global $wpdb;

		$o = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Kit::table( 'supplier_orders' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( ! $o ) {
			return null;
		}

		$o['purchases'] = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT purchase_id FROM ' . Kit::table( 'supplier_order_items' ) . ' WHERE supplier_order_id = %d ORDER BY id ASC', (int) $id ) ) as $pid ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$p = PurchaseRepository::find( (int) $pid );
			if ( $p ) {
				$o['purchases'][] = $p;
			}
		}

		return $o;
	}

	/**
	 * Einkauf als Zeile.
	 *
	 * @param array $p Einkauf.
	 * @return array
	 */
	protected static function line( array $p ) {
		$labels = Purchase::state_labels();

		return array(
			'id'       => (int) $p['id'],
			'label'    => Procurement::label_of( $p ),
			'qty'      => (float) $p['qty'],
			'unit'     => Procurement::unit( (string) $p['item_type'] ),
			'price'    => (float) $p['unit_price_net'],
			'shipping' => (float) $p['shipping_net'],
			'total'    => round( Purchase::total( $p ), 2 ),
			'status'   => (string) $p['status'],
			'state'    => isset( $labels[ $p['status'] ] ) ? $labels[ $p['status'] ] : (string) $p['status'],
			'ordered'  => (string) $p['ordered_on'],
			'expected' => (string) $p['expected_on'],
			'received' => null === $p['received_qty'] ? null : (float) $p['received_qty'],
		);
	}

	/**
	 * Übersicht: Bestellscheine und offene Einkäufe ohne Bestellschein.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		global $wpdb;

		$linked = array();
		foreach ( (array) $wpdb->get_col( 'SELECT purchase_id FROM ' . Kit::table( 'supplier_order_items' ) ) as $pid ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$linked[ (int) $pid ] = true;
		}

		$groups = array();
		foreach ( self::group_open( PurchaseRepository::open(), $linked ) as $supplier => $list ) {
			$groups[] = array(
				'supplier' => $supplier,
				'email'    => self::email_for( $supplier ),
				'lines'    => array_map( array( __CLASS__, 'line' ), $list ),
				'total'    => self::totals( $list )['total'],
			);
		}

		$orders = array();
		$labels = self::status_labels();
		foreach ( (array) $wpdb->get_col( 'SELECT id FROM ' . Kit::table( 'supplier_orders' ) . ' ORDER BY id DESC LIMIT 100' ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$o        = self::find( (int) $id );
			$shown    = self::shown_status( $o['status'], $o['purchases'] );
			$orders[] = array(
				'id'             => (int) $o['id'],
				'number'         => (string) $o['number'],
				'supplier'       => (string) $o['supplier'],
				'email'          => (string) $o['supplier_email'],
				'status'         => (string) $o['status'],
				'shown'          => $shown,
				'shown_label'    => $labels[ $shown ],
				'order_date'     => (string) $o['order_date'],
				'wanted_by'      => (string) $o['wanted_by'],
				'confirmed_date' => (string) $o['confirmed_date'],
				'supplier_ref'   => (string) $o['supplier_ref'],
				'note'           => (string) $o['note'],
				'lines'          => array_map( array( __CLASS__, 'line' ), $o['purchases'] ),
				'totals'         => self::totals( $o['purchases'] ),
			);
		}

		return new WP_REST_Response( array( 'groups' => $groups, 'orders' => $orders ) );
	}

	/**
	 * Bestellschein anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage {supplier, email, purchase_ids[], wanted_by, note}.
	 * @return WP_REST_Response
	 */
	public static function create( WP_REST_Request $request ) {
		global $wpdb;

		$in       = Kit::input( $request );
		$supplier = Kit::cut( sanitize_text_field( isset( $in['supplier'] ) ? (string) $in['supplier'] : '' ), 250 );
		$email    = trim( (string) ( isset( $in['email'] ) ? $in['email'] : '' ) );
		$ids      = array_values( array_unique( array_filter( array_map( 'intval', (array) ( isset( $in['purchase_ids'] ) ? $in['purchase_ids'] : array() ) ) ) ) );
		$wanted   = Kit::date( isset( $in['wanted_by'] ) ? $in['wanted_by'] : '' );

		if ( '' === $supplier ) {
			return Kit::fail( __( 'Bitte den Lieferanten angeben.', 'lindenweg16' ) );
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			return Kit::fail( __( 'Die E-Mail-Adresse stimmt nicht.', 'lindenweg16' ) );
		}
		if ( ! $ids ) {
			return Kit::fail( __( 'Bitte mindestens eine Position wählen.', 'lindenweg16' ) );
		}

		$items_t = Kit::table( 'supplier_order_items' );
		foreach ( $ids as $pid ) {
			$p = PurchaseRepository::find( $pid );
			if ( ! $p || ! Purchase::is_open( $p ) ) {
				return Kit::fail( __( 'Eine Position ist nicht mehr offen. Bitte die Seite neu laden.', 'lindenweg16' ), 409 );
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$items_t} WHERE purchase_id = %d", $pid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				return Kit::fail( __( 'Eine Position steht schon auf einem Bestellschein.', 'lindenweg16' ), 409 );
			}
		}

		$t = Kit::table( 'supplier_orders' );
		$wpdb->insert(
			$t,
			array(
				'number'         => Kit::number_from( $t, 'number', 'BE' ),
				'supplier'       => $supplier,
				'supplier_email' => '' !== $email ? $email : null,
				'status'         => 'entwurf',
				'order_date'     => current_time( 'Y-m-d' ),
				'wanted_by'      => '' !== $wanted ? $wanted : null,
				'note'           => sanitize_textarea_field( isset( $in['note'] ) ? (string) $in['note'] : '' ),
				'created_at'     => current_time( 'mysql', true ),
				'created_by'     => get_current_user_id(),
			)
		);
		$id = (int) $wpdb->insert_id;

		if ( ! $id ) {
			return Kit::fail( __( 'Der Bestellschein ließ sich nicht anlegen. Ist das Plugin-Update vollständig gelaufen (Datenbank)?', 'lindenweg16' ), 500 );
		}

		foreach ( $ids as $pid ) {
			$wpdb->insert( $items_t, array( 'supplier_order_id' => $id, 'purchase_id' => $pid ) );
		}
		self::remember_email( $supplier, $email );
		AuditLog::record( 'erp_bestellschein_angelegt', 'supplier_order', $id, array( 'lieferant' => $supplier, 'positionen' => count( $ids ) ) );

		return new WP_REST_Response( array( 'ok' => true, 'id' => $id, 'message' => __( 'Bestellschein angelegt.', 'lindenweg16' ) ), 201 );
	}

	/**
	 * PDF bauen.
	 *
	 * @param array $o Bestellschein.
	 * @return string
	 */
	public static function build_pdf( array $o ) {
		$c      = Kit::company();
		$pdf    = new Pdf();
		$widths = array( 25, 245, 70, 70, 85 );
		$aligns = array( 'l', 'l', 'r', 'r', 'r' );
		$sum    = self::totals( $o['purchases'] );

		Kit::letter_head(
			$pdf,
			array( $o['supplier'] ),
			__( 'Bestellung', 'lindenweg16' ),
			array(
				__( 'Bestellnummer', 'lindenweg16' ) => $o['number'],
				__( 'Datum', 'lindenweg16' )         => Kit::dmy( $o['order_date'] ),
				__( 'Gewünscht bis', 'lindenweg16' ) => Kit::dmy( $o['wanted_by'] ),
				__( 'Lieferadresse', 'lindenweg16' ) => implode( ', ', array_filter( array( $c['name'], $c['strasse'], $c['ort'] ) ) ),
			)
		);

		$pdf->text( __( 'Wir bestellen zu den vereinbarten Konditionen:', 'lindenweg16' ), 10 );
		$pdf->space( 6 );
		$pdf->hr();
		$pdf->table_cols( array( __( 'Pos.', 'lindenweg16' ), __( 'Bezeichnung', 'lindenweg16' ), __( 'Menge', 'lindenweg16' ), __( 'Preis netto', 'lindenweg16' ), __( 'Summe netto', 'lindenweg16' ) ), $widths, $aligns, 9, true );
		$pdf->hr( 0.3 );

		$n = 0;
		foreach ( $o['purchases'] as $p ) {
			if ( 'storniert' === $p['status'] ) {
				continue;
			}
			++$n;
			$pdf->table_cols(
				array(
					(string) $n,
					Kit::cut( Procurement::label_of( $p ), 55 ),
					str_replace( '.', ',', (string) ( 0 + (float) $p['qty'] ) ) . ' ' . Procurement::unit( (string) $p['item_type'] ),
					(float) $p['unit_price_net'] > 0 ? Kit::eur( $p['unit_price_net'] ) : '–',
					(float) $p['unit_price_net'] > 0 ? Kit::eur( (float) $p['qty'] * (float) $p['unit_price_net'] ) : '–',
				),
				$widths,
				$aligns,
				9
			);
		}

		$pdf->hr( 0.3 );
		$pdf->space( 4 );
		if ( $sum['shipping'] > 0 ) {
			$pdf->row( __( 'Versand netto', 'lindenweg16' ), Kit::eur( $sum['shipping'] ), 10 );
		}
		if ( $sum['total'] > 0 ) {
			$pdf->row( __( 'Gesamt netto', 'lindenweg16' ), Kit::eur( $sum['total'] ), 11, true );
		}
		if ( '' !== trim( (string) $o['note'] ) ) {
			$pdf->space( 10 );
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $o['note'] ) as $line ) {
				$pdf->text( $line, 10 );
			}
		}
		$pdf->space( 10 );
		$pdf->text( __( 'Bitte geben Sie bei Lieferung und Rechnung unsere Bestellnummer an.', 'lindenweg16' ), 9 );

		Kit::letter_foot( $pdf );

		return $pdf->output();
	}

	/**
	 * PDF herunterladen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function pdf( WP_REST_Request $request ) {
		$o = self::find( (int) $request['id'] );

		return $o ? Kit::file_response( 'Bestellung-' . $o['number'] . '.pdf', 'application/pdf', self::build_pdf( $o ) ) : Kit::fail( __( 'Diesen Bestellschein gibt es nicht (mehr).', 'lindenweg16' ), 404 );
	}

	/**
	 * Mailentwurf.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function mail_draft( WP_REST_Request $request ) {
		$o = self::find( (int) $request['id'] );

		if ( ! $o ) {
			return Kit::fail( __( 'Diesen Bestellschein gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$text = self::mail_text( $o, Kit::company()['name'] );

		return new WP_REST_Response( array( 'to' => $o['supplier_email'] ? (string) $o['supplier_email'] : self::email_for( $o['supplier'] ), 'subject' => $text['subject'], 'body' => $text['body'] ) );
	}

	/**
	 * Per Mail schicken.
	 *
	 * @param WP_REST_Request $request Anfrage {to, subject, body}.
	 * @return WP_REST_Response
	 */
	public static function send( WP_REST_Request $request ) {
		global $wpdb;

		$o  = self::find( (int) $request['id'] );
		$in = Kit::input( $request );

		if ( ! $o ) {
			return Kit::fail( __( 'Diesen Bestellschein gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$to      = trim( (string) ( isset( $in['to'] ) ? $in['to'] : '' ) );
		$subject = sanitize_text_field( (string) ( isset( $in['subject'] ) ? $in['subject'] : '' ) );
		$body    = sanitize_textarea_field( (string) ( isset( $in['body'] ) ? $in['body'] : '' ) );

		if ( ! is_email( $to ) ) {
			return Kit::fail( __( 'Die E-Mail-Adresse stimmt nicht.', 'lindenweg16' ) );
		}
		if ( '' === $subject || '' === trim( $body ) ) {
			return Kit::fail( __( 'Betreff und Text dürfen nicht leer sein.', 'lindenweg16' ) );
		}
		if ( ! Kit::mail( $to, $subject, $body, 'Bestellung-' . $o['number'] . '.pdf', self::build_pdf( $o ) ) ) {
			return Kit::fail( __( 'Die Mail konnte nicht verschickt werden. Bitte die Mail-Einstellungen der Website prüfen.', 'lindenweg16' ), 502 );
		}

		$wpdb->update( Kit::table( 'supplier_orders' ), array( 'status' => 'entwurf' === $o['status'] ? 'versendet' : $o['status'], 'supplier_email' => $to, 'sent_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $o['id'] ) );

		// Die Bestellnummer auch am Einkauf, damit Lieferschein und Rechnung zuordenbar sind.
		foreach ( $o['purchases'] as $p ) {
			if ( '' === (string) $p['order_ref'] ) {
				$wpdb->update( PurchaseRepository::table(), array( 'order_ref' => $o['number'] ), array( 'id' => (int) $p['id'] ) );
			}
		}

		self::remember_email( $o['supplier'], $to );
		AuditLog::record( 'erp_bestellschein_versendet', 'supplier_order', (int) $o['id'], array( 'an' => $to ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Bestellung verschickt.', 'lindenweg16' ) ) );
	}

	/**
	 * Bestätigung des Lieferanten festhalten.
	 *
	 * @param WP_REST_Request $request Anfrage {date, supplier_ref}.
	 * @return WP_REST_Response
	 */
	public static function confirm( WP_REST_Request $request ) {
		global $wpdb;

		$o    = self::find( (int) $request['id'] );
		$in   = Kit::input( $request );
		$date = Kit::date( isset( $in['date'] ) ? $in['date'] : '' );
		$ref  = Kit::cut( sanitize_text_field( isset( $in['supplier_ref'] ) ? (string) $in['supplier_ref'] : '' ), 100 );

		if ( ! $o ) {
			return Kit::fail( __( 'Diesen Bestellschein gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$wpdb->update(
			Kit::table( 'supplier_orders' ),
			array(
				'status'         => 'bestaetigt',
				'confirmed_at'   => current_time( 'mysql', true ),
				'confirmed_date' => '' !== $date ? $date : null,
				'supplier_ref'   => '' !== $ref ? $ref : null,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $o['id'] )
		);

		$moved = 0;
		if ( '' !== $date ) {
			foreach ( $o['purchases'] as $p ) {
				if ( Purchase::is_open( $p ) && (string) $p['expected_on'] !== $date ) {
					$wpdb->update( PurchaseRepository::table(), array( 'expected_on' => $date, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $p['id'] ) );
					++$moved;
				}
			}
		}

		AuditLog::record( 'erp_bestellschein_bestaetigt', 'supplier_order', (int) $o['id'], array( 'liefertermin' => $date, 'referenz' => $ref ) );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => $moved
					/* translators: %d: Anzahl. */
					? sprintf( __( 'Bestätigung gespeichert. %d Positionen haben den neuen Liefertermin.', 'lindenweg16' ), $moved )
					: __( 'Bestätigung gespeichert.', 'lindenweg16' ),
			)
		);
	}

	/**
	 * Entwurf auflösen: Die Einkäufe werden wieder frei, der Bestellschein bleibt als storniert stehen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function dissolve( WP_REST_Request $request ) {
		global $wpdb;

		$o = self::find( (int) $request['id'] );

		if ( ! $o ) {
			return Kit::fail( __( 'Diesen Bestellschein gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( 'entwurf' !== $o['status'] ) {
			return Kit::fail( __( 'Ein verschickter Bestellschein bleibt stehen. Einzelne Einkäufe storniert man im Einkauf.', 'lindenweg16' ), 409 );
		}

		$wpdb->delete( Kit::table( 'supplier_order_items' ), array( 'supplier_order_id' => (int) $o['id'] ) );
		$wpdb->update( Kit::table( 'supplier_orders' ), array( 'status' => 'storniert', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $o['id'] ) );
		AuditLog::record( 'erp_bestellschein_aufgeloest', 'supplier_order', (int) $o['id'] );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Entwurf aufgelöst. Die Positionen sind wieder frei.', 'lindenweg16' ) ) );
	}
}
