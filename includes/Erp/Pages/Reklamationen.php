<?php
/**
 * ERP-Seite „Reklamationen“: Reklamationen und Rücksendungen als Fall.
 *
 * Ablauf: Fall anlegen (zur Shop-Bestellung oder frei) → Ware ist da (zurück
 * ins Lager als „Retoure“ und/oder Ausschuss) → Lösung festlegen (Ersatz,
 * Gutschrift/Erstattung, Reparatur, abgelehnt) → erledigt. Jeder Schritt steht
 * im Verlauf des Falls. Hängt der Fall an einer Bestellung, bekommt deren
 * Auftrag die Kennzeichen „Reklamation“ bzw. „Retoure“ – damit stimmen die
 * Kennzahlen im Bericht.
 *
 * Ersatzlieferung bucht auf Wunsch den Abgang aus dem Lager (Art „Verkauf“,
 * Herkunft „Reklamation“). Das Geld zurück gibt weiter „Shop-Bestellung
 * erstatten“ – hier wird nur der Betrag festgehalten.
 *
 * Reine Regeln sind getestet (tests/ErpReklamationenTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\StockRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reklamationen und Retouren.
 */
class Reklamationen {

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Gründe.
	 *
	 * @return array
	 */
	public static function reasons() {
		return array(
			'defekt'      => __( 'Produktfehler', 'lindenweg16' ),
			'bruch'       => __( 'Transportschaden', 'lindenweg16' ),
			'falsch'      => __( 'Falscher Artikel oder Farbe', 'lindenweg16' ),
			'fehlt'       => __( 'Unvollständig', 'lindenweg16' ),
			'passt_nicht' => __( 'Gefällt nicht / Widerruf', 'lindenweg16' ),
			'sonstiges'   => __( 'Sonstiges', 'lindenweg16' ),
		);
	}

	/**
	 * Zustände.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'eingegangen' => __( 'neu', 'lindenweg16' ),
			'ware_da'     => __( 'Ware zurück', 'lindenweg16' ),
			'entschieden' => __( 'Lösung steht', 'lindenweg16' ),
			'erledigt'    => __( 'erledigt', 'lindenweg16' ),
		);
	}

	/**
	 * Lösungen.
	 *
	 * @return array
	 */
	public static function resolutions() {
		return array(
			'ersatz'     => __( 'Ersatz', 'lindenweg16' ),
			'gutschrift' => __( 'Gutschrift / Erstattung', 'lindenweg16' ),
			'reparatur'  => __( 'Reparatur', 'lindenweg16' ),
			'abgelehnt'  => __( 'abgelehnt', 'lindenweg16' ),
		);
	}

	/**
	 * Wareneingang prüfen.
	 *
	 * @param float $qty      Menge des Falls.
	 * @param float $done     Schon gebucht (zurück + Ausschuss).
	 * @param float $restock  Zurück ins Lager.
	 * @param float $scrap    Ausschuss.
	 * @return string Fehlertext oder leer.
	 */
	public static function receive_error( $qty, $done, $restock, $scrap ) {
		if ( $restock < 0 || $scrap < 0 ) {
			return __( 'Mengen dürfen nicht negativ sein.', 'lindenweg16' );
		}
		if ( $restock + $scrap <= 0 ) {
			return __( 'Bitte eintragen, wie viel zurück ins Lager geht oder Ausschuss ist.', 'lindenweg16' );
		}
		if ( $done + $restock + $scrap > (float) $qty + 0.0005 ) {
			return __( 'Das ist mehr als im Fall reklamiert wurde. Bitte erst die Menge im Fall erhöhen.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * Auswertung nach Grund und Artikel.
	 *
	 * @param array $cases    Fälle (reason, article_id, qty).
	 * @param array $articles article_id => {sku}.
	 * @return array {by_reason: [[key, label, count, qty]], top_articles: [[sku, count]]}
	 */
	public static function stats( array $cases, array $articles ) {
		$labels = self::reasons();
		$reason = array();
		$art    = array();

		foreach ( $cases as $c ) {
			$r = isset( $labels[ $c['reason'] ] ) ? $c['reason'] : 'sonstiges';
			if ( ! isset( $reason[ $r ] ) ) {
				$reason[ $r ] = array( $r, $labels[ $r ], 0, 0.0 );
			}
			++$reason[ $r ][2];
			$reason[ $r ][3] += (float) $c['qty'];

			$a = (int) $c['article_id'];
			if ( $a ) {
				$sku         = isset( $articles[ $a ] ) ? (string) $articles[ $a ]['sku'] : '#' . $a;
				$art[ $sku ] = ( isset( $art[ $sku ] ) ? $art[ $sku ] : 0 ) + 1;
			}
		}

		usort(
			$reason,
			function ( $x, $y ) {
				return $y[2] - $x[2];
			}
		);
		arsort( $art );

		$top = array();
		foreach ( array_slice( $art, 0, 5, true ) as $sku => $n ) {
			$top[] = array( (string) $sku, $n );
		}

		return array( 'by_reason' => array_values( $reason ), 'top_articles' => $top );
	}

	/**
	 * Verlaufseintrag anhängen.
	 *
	 * @param string $json Bisheriger Verlauf (JSON).
	 * @param string $text Eintrag.
	 * @param string $who  Name.
	 * @param int    $ts   Zeit.
	 * @return string
	 */
	public static function add_history( $json, $text, $who, $ts ) {
		$list   = json_decode( (string) $json, true );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array( 'ts' => (int) $ts, 'who' => (string) $who, 'text' => (string) $text );

		return (string) wp_json_encode( $list );
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
		$needs = $api->needs( array( 'verkauf.reklamationen' ) );

		register_rest_route( $ns, '/erp/reklamationen', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'index' ), 'permission_callback' => $needs ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $needs ),
		) );
		register_rest_route( $ns, '/erp/reklamationen/bestellung/(?P<order>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'order_lookup' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/reklamationen/(?P<id>\d+)/ware', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'receive' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/reklamationen/(?P<id>\d+)/loesung', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'resolve' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/reklamationen/(?P<id>\d+)/notiz', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'note' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/reklamationen/(?P<id>\d+)/erledigt', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'close' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Fall laden.
	 *
	 * @param int $id Kennung.
	 * @return array|null
	 */
	protected static function find( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Kit::table( 'rma_cases' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $row ? $row : null;
	}

	/**
	 * Name der angemeldeten Person.
	 *
	 * @return string
	 */
	protected static function me() {
		$u = wp_get_current_user();

		return $u && $u->ID ? (string) $u->display_name : '';
	}

	/**
	 * Feld ändern und Verlauf ergänzen.
	 *
	 * @param array  $case   Fall.
	 * @param array  $change Änderungen.
	 * @param string $text   Verlaufseintrag.
	 */
	protected static function update( array $case, array $change, $text ) {
		global $wpdb;

		$change['history']    = self::add_history( $case['history'], $text, self::me(), time() );
		$change['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( Kit::table( 'rma_cases' ), $change, array( 'id' => (int) $case['id'] ) );
		AuditLog::record( 'erp_reklamation', 'rma', (int) $case['id'], array( 'schritt' => $text ) );
	}

	/**
	 * Kennzeichen am Auftrag der Bestellung setzen.
	 *
	 * @param array  $case Fall.
	 * @param string $flag complaint oder returned.
	 */
	protected static function flag_order( array $case, $flag ) {
		global $wpdb;

		$op = null;
		if ( (int) $case['order_op_id'] ) {
			$op = \Lindenweg16\Modules\Betrieb\OrderOpsRepository::find( (int) $case['order_op_id'] );
		} elseif ( (int) $case['wc_order_id'] ) {
			$op = \Lindenweg16\Modules\Betrieb\OrderOpsRepository::find_by_order( (int) $case['wc_order_id'] );
		}

		if ( $op && ! (int) $op[ $flag ] ) {
			$wpdb->update( \Lindenweg16\Modules\Betrieb\OrderOpsRepository::table(), array( $flag => 1 ), array( 'id' => (int) $op['id'] ) );
			\Lindenweg16\Modules\Betrieb\BetriebAudit::record( 'update', 'order_ops', (int) $op['id'], array( $flag => 0 ), array( $flag => 1 ), sprintf( /* translators: %s: Nummer. */ __( 'Reklamation %s', 'lindenweg16' ), $case['number'] ) );
		}
	}

	/**
	 * Fall für die Oberfläche.
	 *
	 * @param array $c        Fall.
	 * @param array $articles Artikel.
	 * @return array
	 */
	protected static function out( array $c, array $articles ) {
		$reasons = self::reasons();
		$states  = self::status_labels();
		$res     = self::resolutions();
		$a       = (int) $c['article_id'] && isset( $articles[ (int) $c['article_id'] ] ) ? $articles[ (int) $c['article_id'] ] : null;
		$hist    = json_decode( (string) $c['history'], true );

		return array(
			'id'           => (int) $c['id'],
			'number'       => (string) $c['number'],
			'wc_order_id'  => (int) $c['wc_order_id'],
			'customer'     => (string) $c['customer'],
			'email'        => (string) $c['customer_email'],
			'article_id'   => (int) $c['article_id'],
			'article'      => $a ? $a['sku'] . ' · ' . $a['name'] : '',
			'color'        => (string) $c['color'],
			'color_label'  => '' !== (string) $c['color'] ? ColorRegistry::label( $c['color'] ) : '',
			'qty'          => (float) $c['qty'],
			'reason'       => (string) $c['reason'],
			'reason_label' => isset( $reasons[ $c['reason'] ] ) ? $reasons[ $c['reason'] ] : (string) $c['reason'],
			'description'  => (string) $c['description'],
			'status'       => (string) $c['status'],
			'status_label' => isset( $states[ $c['status'] ] ) ? $states[ $c['status'] ] : (string) $c['status'],
			'resolution'   => (string) $c['resolution'],
			'res_label'    => $c['resolution'] && isset( $res[ $c['resolution'] ] ) ? $res[ $c['resolution'] ] : '',
			'refund'       => null === $c['refund_amount'] ? null : (float) $c['refund_amount'],
			'restocked'    => (float) $c['restocked_qty'],
			'scrapped'     => (float) $c['scrapped_qty'],
			'history'      => is_array( $hist ) ? $hist : array(),
			'created'      => strtotime( $c['created_at'] . ' UTC' ),
			'age_days'     => max( 0, (int) floor( ( time() - strtotime( $c['created_at'] . ' UTC' ) ) / DAY_IN_SECONDS ) ),
		);
	}

	/**
	 * Liste mit Auswertung.
	 *
	 * @param WP_REST_Request $request Anfrage (q).
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ) {
		global $wpdb;

		$t        = Kit::table( 'rma_cases' );
		$rows     = (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY (status = 'erledigt') ASC, id DESC LIMIT 400", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$articles = ArticleRepository::all_by_id();
		$year     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT reason, article_id, qty FROM {$t} WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$options = array();
		foreach ( $articles as $a ) {
			$options[] = array( (int) $a['id'], $a['sku'] . ' · ' . $a['name'] );
		}

		return new WP_REST_Response(
			array(
				'rows'        => array_map( function ( $c ) use ( $articles ) { return self::out( $c, $articles ); }, $rows ),
				'stats'       => self::stats( $year, $articles ),
				'reasons'     => self::reasons(),
				'resolutions' => self::resolutions(),
				'articles'    => $options,
				'colors'      => ColorRegistry::all(),
				'can_book'    => Access::can( 'lager.buchen' ),
				'can_refund'  => Access::can( 'verkauf.shop_orders.erstatten' ),
			)
		);
	}

	/**
	 * Shop-Bestellung nachschlagen (Kunde, Positionen).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function order_lookup( WP_REST_Request $request ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $request['order'] ) : null;

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return Kit::fail( __( 'Diese Bestellnummer gibt es im Shop nicht.', 'lindenweg16' ), 404 );
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$sku     = $product ? (string) $product->get_sku() : '';
			$article = '' !== $sku ? ArticleRepository::find_by_sku( $sku ) : null;
			$color   = '';
			foreach ( (array) $item->get_meta_data() as $meta ) {
				$d = $meta->get_data();
				if ( false !== strpos( (string) $d['key'], 'farbe' ) ) {
					$color = ColorRegistry::normalize( (string) $d['value'] );
				}
			}
			$items[] = array(
				'name'       => (string) $item->get_name(),
				'qty'        => (float) $item->get_quantity(),
				'article_id' => $article ? (int) $article['id'] : 0,
				'color'      => $color,
			);
		}

		return new WP_REST_Response(
			array(
				'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . ( $order->get_billing_company() ? ' (' . $order->get_billing_company() . ')' : '' ) ),
				'email'    => (string) $order->get_billing_email(),
				'items'    => $items,
				'total'    => (float) $order->get_total(),
			)
		);
	}

	/**
	 * Fall anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function create( WP_REST_Request $request ) {
		global $wpdb;

		$in       = Kit::input( $request );
		$customer = Kit::cut( sanitize_text_field( isset( $in['customer'] ) ? (string) $in['customer'] : '' ), 250 );
		$email    = trim( (string) ( isset( $in['email'] ) ? $in['email'] : '' ) );
		$reason   = sanitize_key( isset( $in['reason'] ) ? (string) $in['reason'] : '' );
		$qty      = Kit::amount( isset( $in['qty'] ) ? $in['qty'] : 1 );
		$article  = isset( $in['article_id'] ) ? (int) $in['article_id'] : 0;
		$wc       = isset( $in['wc_order_id'] ) ? (int) $in['wc_order_id'] : 0;

		if ( '' === $customer ) {
			return Kit::fail( __( 'Bitte den Kunden eintragen (oder die Bestellnummer nachschlagen).', 'lindenweg16' ) );
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			return Kit::fail( __( 'Die E-Mail-Adresse stimmt nicht.', 'lindenweg16' ) );
		}
		if ( ! isset( self::reasons()[ $reason ] ) ) {
			return Kit::fail( __( 'Bitte den Grund wählen.', 'lindenweg16' ) );
		}
		if ( null === $qty || $qty <= 0 ) {
			return Kit::fail( __( 'Die Menge muss größer als 0 sein.', 'lindenweg16' ) );
		}
		if ( $article && ! ArticleRepository::find( $article ) ) {
			return Kit::fail( __( 'Diesen Artikel gibt es nicht.', 'lindenweg16' ) );
		}

		$t   = Kit::table( 'rma_cases' );
		$num = Kit::number_from( $t, 'number', 'RK' );
		$op  = $wc ? \Lindenweg16\Modules\Betrieb\OrderOpsRepository::find_by_order( $wc ) : null;

		$wpdb->insert(
			$t,
			array(
				'number'         => $num,
				'wc_order_id'    => $wc ? $wc : null,
				'order_op_id'    => $op ? (int) $op['id'] : null,
				'contact_id'     => $op && $op['contact_id'] ? (int) $op['contact_id'] : null,
				'customer'       => $customer,
				'customer_email' => '' !== $email ? $email : null,
				'article_id'     => $article ? $article : null,
				'color'          => ColorRegistry::normalize( isset( $in['color'] ) ? (string) $in['color'] : '' ),
				'qty'            => round( $qty, 3 ),
				'reason'         => $reason,
				'description'    => sanitize_textarea_field( isset( $in['description'] ) ? (string) $in['description'] : '' ),
				'status'         => 'eingegangen',
				'history'        => self::add_history( '', __( 'Fall angelegt', 'lindenweg16' ), self::me(), time() ),
				'created_at'     => current_time( 'mysql', true ),
				'created_by'     => get_current_user_id(),
			)
		);
		$id = (int) $wpdb->insert_id;

		if ( ! $id ) {
			return Kit::fail( __( 'Der Fall ließ sich nicht speichern. Ist das Plugin-Update vollständig gelaufen (Datenbank)?', 'lindenweg16' ), 500 );
		}

		$case = self::find( $id );
		self::flag_order( $case, 'complaint' );
		AuditLog::record( 'erp_reklamation_angelegt', 'rma', $id, array( 'nummer' => $num, 'grund' => $reason, 'bestellung' => $wc ) );

		/* translators: %s: Nummer. */
		return new WP_REST_Response( array( 'ok' => true, 'id' => $id, 'message' => sprintf( __( 'Fall %s angelegt.', 'lindenweg16' ), $num ) ), 201 );
	}

	/**
	 * Ware ist zurück: ins Lager und/oder Ausschuss.
	 *
	 * @param WP_REST_Request $request Anfrage {restock, scrap, note}.
	 * @return WP_REST_Response
	 */
	public static function receive( WP_REST_Request $request ) {
		$c  = self::find( (int) $request['id'] );
		$in = Kit::input( $request );

		if ( ! $c || 'erledigt' === $c['status'] ) {
			return Kit::fail( __( 'Dieser Fall ist nicht (mehr) offen.', 'lindenweg16' ), 409 );
		}

		$restock = Kit::amount( isset( $in['restock'] ) && '' !== (string) $in['restock'] ? $in['restock'] : 0 );
		$scrap   = Kit::amount( isset( $in['scrap'] ) && '' !== (string) $in['scrap'] ? $in['scrap'] : 0 );
		$restock = null === $restock ? -1 : $restock;
		$scrap   = null === $scrap ? -1 : $scrap;
		$error   = self::receive_error( (float) $c['qty'], (float) $c['restocked_qty'] + (float) $c['scrapped_qty'], $restock, $scrap );

		if ( '' !== $error ) {
			return Kit::fail( $error );
		}
		if ( $restock > 0 && ! (int) $c['article_id'] ) {
			return Kit::fail( __( 'Zum Einbuchen braucht der Fall einen Artikel.', 'lindenweg16' ) );
		}
		if ( $restock > 0 && ! Access::can( 'lager.buchen' ) ) {
			return Kit::fail( __( 'Zurück ins Lager buchen darf nur, wer Bestand buchen darf.', 'lindenweg16' ), 403 );
		}

		if ( $restock > 0 ) {
			$mid = StockRepository::book(
				array(
					'article_id'    => (int) $c['article_id'],
					'color'         => (string) $c['color'],
					'qty'           => $restock,
					'movement_type' => 'retoure',
					'location'      => 'lager',
					'ref_type'      => 'rma',
					'ref_id'        => (int) $c['id'],
					/* translators: %s: Nummer. */
					'note'          => sprintf( __( 'Rücksendung %s', 'lindenweg16' ), $c['number'] ),
				)
			);
			if ( ! $mid ) {
				return Kit::fail( __( 'Die Lagerbuchung hat nicht geklappt. Bitte erneut versuchen.', 'lindenweg16' ), 500 );
			}
		}

		$parts = array();
		if ( $restock > 0 ) {
			/* translators: %s: Menge. */
			$parts[] = sprintf( __( '%s zurück ins Lager', 'lindenweg16' ), str_replace( '.', ',', (string) ( 0 + $restock ) ) );
		}
		if ( $scrap > 0 ) {
			/* translators: %s: Menge. */
			$parts[] = sprintf( __( '%s Ausschuss', 'lindenweg16' ), str_replace( '.', ',', (string) ( 0 + $scrap ) ) );
		}
		$note = trim( sanitize_text_field( isset( $in['note'] ) ? (string) $in['note'] : '' ) );

		self::update(
			$c,
			array(
				'status'        => 'eingegangen' === $c['status'] ? 'ware_da' : $c['status'],
				'restocked_qty' => (float) $c['restocked_qty'] + $restock,
				'scrapped_qty'  => (float) $c['scrapped_qty'] + $scrap,
				'received_on'   => $c['received_on'] ? $c['received_on'] : current_time( 'Y-m-d' ),
			),
			__( 'Ware eingegangen: ', 'lindenweg16' ) . implode( ', ', $parts ) . ( '' !== $note ? ' – ' . $note : '' )
		);
		self::flag_order( $c, 'returned' );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Wareneingang gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Lösung festlegen.
	 *
	 * @param WP_REST_Request $request Anfrage {resolution, refund, book_replacement, note}.
	 * @return WP_REST_Response
	 */
	public static function resolve( WP_REST_Request $request ) {
		$c   = self::find( (int) $request['id'] );
		$in  = Kit::input( $request );
		$res = sanitize_key( isset( $in['resolution'] ) ? (string) $in['resolution'] : '' );

		if ( ! $c || 'erledigt' === $c['status'] ) {
			return Kit::fail( __( 'Dieser Fall ist nicht (mehr) offen.', 'lindenweg16' ), 409 );
		}
		if ( ! isset( self::resolutions()[ $res ] ) ) {
			return Kit::fail( __( 'Bitte eine Lösung wählen.', 'lindenweg16' ) );
		}

		$refund = null;
		if ( 'gutschrift' === $res ) {
			$refund = Kit::amount( isset( $in['refund'] ) ? $in['refund'] : '' );
			if ( null === $refund || $refund < 0 ) {
				return Kit::fail( __( 'Bitte den Betrag der Gutschrift eintragen.', 'lindenweg16' ) );
			}
		}

		$text = self::resolutions()[ $res ];
		if ( null !== $refund ) {
			$text .= ': ' . Kit::eur( $refund );
		}

		if ( 'ersatz' === $res && ! empty( $in['book_replacement'] ) ) {
			if ( ! (int) $c['article_id'] ) {
				return Kit::fail( __( 'Zum Ausbuchen des Ersatzes braucht der Fall einen Artikel.', 'lindenweg16' ) );
			}
			if ( ! Access::can( 'lager.buchen' ) ) {
				return Kit::fail( __( 'Ersatz ausbuchen darf nur, wer Bestand buchen darf.', 'lindenweg16' ), 403 );
			}
			StockRepository::book(
				array(
					'article_id'    => (int) $c['article_id'],
					'color'         => (string) $c['color'],
					'qty'           => -1 * abs( (float) $c['qty'] ),
					'movement_type' => 'verkauf',
					'location'      => 'lager',
					'ref_type'      => 'rma',
					'ref_id'        => (int) $c['id'],
					/* translators: %s: Nummer. */
					'note'          => sprintf( __( 'Ersatzlieferung %s', 'lindenweg16' ), $c['number'] ),
				)
			);
			$text .= ' – ' . __( 'Ersatz aus dem Lager ausgebucht', 'lindenweg16' );
		}

		$note = trim( sanitize_text_field( isset( $in['note'] ) ? (string) $in['note'] : '' ) );
		self::update( $c, array( 'status' => 'entschieden', 'resolution' => $res, 'refund_amount' => $refund ), $text . ( '' !== $note ? ' – ' . $note : '' ) );

		$msg = __( 'Lösung gespeichert.', 'lindenweg16' );
		if ( 'gutschrift' === $res && (int) $c['wc_order_id'] ) {
			$msg .= ' ' . __( 'Das Geld zurück gibst du in der Shop-Bestellung mit „Erstatten“.', 'lindenweg16' );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => $msg ) );
	}

	/**
	 * Notiz in den Verlauf.
	 *
	 * @param WP_REST_Request $request Anfrage {text}.
	 * @return WP_REST_Response
	 */
	public static function note( WP_REST_Request $request ) {
		$c    = self::find( (int) $request['id'] );
		$in   = Kit::input( $request );
		$text = trim( sanitize_textarea_field( isset( $in['text'] ) ? (string) $in['text'] : '' ) );

		if ( ! $c ) {
			return Kit::fail( __( 'Diesen Fall gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( '' === $text ) {
			return Kit::fail( __( 'Die Notiz ist leer.', 'lindenweg16' ) );
		}

		self::update( $c, array(), Kit::cut( $text, 1000 ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Notiz gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Fall abschließen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function close( WP_REST_Request $request ) {
		$c = self::find( (int) $request['id'] );

		if ( ! $c || 'erledigt' === $c['status'] ) {
			return Kit::fail( __( 'Dieser Fall ist nicht (mehr) offen.', 'lindenweg16' ), 409 );
		}
		if ( ! $c['resolution'] ) {
			return Kit::fail( __( 'Bitte zuerst die Lösung festlegen.', 'lindenweg16' ) );
		}

		self::update( $c, array( 'status' => 'erledigt', 'closed_at' => current_time( 'mysql', true ) ), __( 'Fall erledigt', 'lindenweg16' ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Fall erledigt.', 'lindenweg16' ) ) );
	}
}
