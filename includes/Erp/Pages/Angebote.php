<?php
/**
 * ERP-Seite „Angebote“: Angebot schreiben, als PDF verschicken, in Auftrag umwandeln.
 *
 * Ablauf: Entwurf → versendet → angenommen (wird zum Auftrag) oder abgelehnt.
 * Ein versendetes Angebot, dessen Gültigkeit abgelaufen ist, zeigt die Seite
 * als „abgelaufen“. Der Auftrag entsteht über denselben Weg wie eine manuelle
 * Bestellung (`OrderOpsRepository::create_manual`): mit Kontakt, Positionen,
 * Zahlungsziel aus der Kondition des Kontakts und Lagerabgang.
 *
 * Preise sind Bruttopreise wie überall im Betrieb (Artikelpreis bzw.
 * Händlerpreis). Bei Regelbesteuerung weist das PDF die enthaltene
 * Umsatzsteuer aus (19 %), als Kleinunternehmer den Hinweis nach § 19 UStG.
 *
 * Reine Regeln sind getestet (tests/ErpAngeboteTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Pdf;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Angebote.
 */
class Angebote {

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
			'entwurf'    => __( 'Entwurf', 'lindenweg16' ),
			'versendet'  => __( 'versendet', 'lindenweg16' ),
			'angenommen' => __( 'angenommen', 'lindenweg16' ),
			'abgelehnt'  => __( 'abgelehnt', 'lindenweg16' ),
			'abgelaufen' => __( 'abgelaufen', 'lindenweg16' ),
		);
	}

	/**
	 * Angezeigter Zustand: „abgelaufen“ ergibt sich aus dem Datum.
	 *
	 * @param string $status      Gespeicherter Zustand.
	 * @param string $valid_until Gültig bis.
	 * @param string $today       Heute.
	 * @return string
	 */
	public static function shown_status( $status, $valid_until, $today ) {
		if ( in_array( $status, array( 'entwurf', 'versendet' ), true ) && '' !== (string) $valid_until && (string) $valid_until < $today ) {
			return 'abgelaufen';
		}

		return (string) $status;
	}

	/**
	 * Erlaubter Wechsel?
	 *
	 * @param string $from Von.
	 * @param string $to   Nach.
	 * @return bool
	 */
	public static function can_switch( $from, $to ) {
		$allowed = array(
			'entwurf'   => array( 'versendet', 'abgelehnt' ),
			'versendet' => array( 'angenommen', 'abgelehnt', 'entwurf' ),
			'abgelehnt' => array( 'entwurf' ),
		);

		return isset( $allowed[ $from ] ) && in_array( $to, $allowed[ $from ], true );
	}

	/**
	 * Positionen und Summen rechnen.
	 *
	 * @param array $items        Positionen {qty, unit_price_gross, discount_pct}.
	 * @param float $discount_pct Rabatt auf alles in Prozent.
	 * @param float $shipping     Versand brutto.
	 * @param bool  $ku           Kleinunternehmer (keine USt ausweisen).
	 * @return array {lines: float[], subtotal, discount, shipping, total, vat}
	 */
	public static function totals( array $items, $discount_pct, $shipping, $ku ) {
		$lines    = array();
		$subtotal = 0.0;

		foreach ( $items as $i => $item ) {
			$line        = (float) $item['qty'] * (float) $item['unit_price_gross'] * ( 1 - max( 0, min( 100, (float) $item['discount_pct'] ) ) / 100 );
			$lines[ $i ] = round( $line, 2 );
			$subtotal   += $lines[ $i ];
		}

		$discount = round( $subtotal * max( 0, min( 100, (float) $discount_pct ) ) / 100, 2 );
		$total    = round( $subtotal - $discount + max( 0, (float) $shipping ), 2 );

		return array(
			'lines'    => $lines,
			'subtotal' => round( $subtotal, 2 ),
			'discount' => $discount,
			'shipping' => round( max( 0, (float) $shipping ), 2 ),
			'total'    => $total,
			'vat'      => $ku ? 0.0 : round( $total - $total / 1.19, 2 ),
		);
	}

	/**
	 * Eingaben eines Angebots prüfen und bereinigen.
	 *
	 * @param array  $in    Eingaben.
	 * @param string $today Heute.
	 * @return array {data, items, errors}
	 */
	public static function validate( array $in, $today ) {
		$errors = array();
		$items  = array();

		$recipient = trim( (string) ( isset( $in['recipient'] ) ? $in['recipient'] : '' ) );
		$date      = Kit::date( isset( $in['quote_date'] ) ? $in['quote_date'] : '' );
		$date      = '' !== $date ? $date : $today;
		$until     = Kit::date( isset( $in['valid_until'] ) ? $in['valid_until'] : '' );
		$until     = '' !== $until ? $until : Kit::add_days( $date, 30 );

		if ( '' === $recipient ) {
			$errors[] = __( 'Bitte einen Empfänger eintragen oder einen Kontakt wählen.', 'lindenweg16' );
		}
		if ( $until < $date ) {
			$errors[] = __( '„Gültig bis“ liegt vor dem Angebotsdatum.', 'lindenweg16' );
		}

		foreach ( (array) ( isset( $in['items'] ) ? $in['items'] : array() ) as $n => $raw ) {
			$raw   = (array) $raw;
			$title = trim( (string) ( isset( $raw['title'] ) ? $raw['title'] : '' ) );
			$qty   = Kit::amount( isset( $raw['qty'] ) ? $raw['qty'] : '' );
			$price = Kit::amount( isset( $raw['unit_price_gross'] ) ? $raw['unit_price_gross'] : '' );
			$disc  = Kit::amount( isset( $raw['discount_pct'] ) ? $raw['discount_pct'] : 0 );
			$art   = isset( $raw['article_id'] ) ? (int) $raw['article_id'] : 0;

			if ( '' === $title && ! $art && null === $qty && null === $price ) {
				continue; // Leere Zeile.
			}

			$pos = $n + 1;
			if ( '' === $title ) {
				/* translators: %d: Position. */
				$errors[] = sprintf( __( 'Position %d braucht eine Bezeichnung.', 'lindenweg16' ), $pos );
			}
			if ( null === $qty || $qty <= 0 || $qty > 100000 ) {
				/* translators: %d: Position. */
				$errors[] = sprintf( __( 'Position %d: Menge muss größer als 0 sein.', 'lindenweg16' ), $pos );
			}
			if ( null === $price || $price < 0 ) {
				/* translators: %d: Position. */
				$errors[] = sprintf( __( 'Position %d: Preis fehlt oder ist negativ.', 'lindenweg16' ), $pos );
			}
			if ( null === $disc || $disc < 0 || $disc > 100 ) {
				/* translators: %d: Position. */
				$errors[] = sprintf( __( 'Position %d: Rabatt zwischen 0 und 100 %%.', 'lindenweg16' ), $pos );
			}

			$items[] = array(
				'article_id'       => $art ? $art : null,
				'color'            => isset( $raw['color'] ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $raw['color'] ) ) : '',
				'title'            => Kit::cut( $title, 250 ),
				'description'      => trim( (string) ( isset( $raw['description'] ) ? $raw['description'] : '' ) ),
				'qty'              => null === $qty ? 0 : round( $qty, 3 ),
				'unit_price_gross' => null === $price ? 0 : round( $price, 4 ),
				'discount_pct'     => null === $disc ? 0 : round( $disc, 3 ),
			);
		}

		if ( ! $items ) {
			$errors[] = __( 'Das Angebot braucht mindestens eine Position.', 'lindenweg16' );
		}

		$disc = Kit::amount( isset( $in['discount_pct'] ) ? $in['discount_pct'] : 0 );
		$ship = Kit::amount( isset( $in['shipping_gross'] ) ? $in['shipping_gross'] : 0 );

		if ( null === $disc || $disc < 0 || $disc > 100 ) {
			$errors[] = __( 'Gesamtrabatt zwischen 0 und 100 %.', 'lindenweg16' );
		}
		if ( null === $ship || $ship < 0 ) {
			$errors[] = __( 'Versandkosten dürfen nicht negativ sein.', 'lindenweg16' );
		}

		return array(
			'data'   => array(
				'contact_id'        => isset( $in['contact_id'] ) && (int) $in['contact_id'] ? (int) $in['contact_id'] : null,
				'recipient'         => Kit::cut( $recipient, 250 ),
				'recipient_email'   => trim( (string) ( isset( $in['recipient_email'] ) ? $in['recipient_email'] : '' ) ),
				'recipient_address' => trim( (string) ( isset( $in['recipient_address'] ) ? $in['recipient_address'] : '' ) ),
				'quote_date'        => $date,
				'valid_until'       => $until,
				'intro'             => trim( (string) ( isset( $in['intro'] ) ? $in['intro'] : '' ) ),
				'outro'             => trim( (string) ( isset( $in['outro'] ) ? $in['outro'] : '' ) ),
				'discount_pct'      => null === $disc ? 0 : round( $disc, 3 ),
				'shipping_gross'    => null === $ship ? 0 : round( $ship, 2 ),
				'note'              => trim( (string) ( isset( $in['note'] ) ? $in['note'] : '' ) ),
			),
			'items'  => $items,
			'errors' => $errors,
		);
	}

	/**
	 * Mailtext zum Angebot.
	 *
	 * @param array  $quote   Angebot.
	 * @param string $company Firma.
	 * @return array {subject, body}
	 */
	public static function mail_text( array $quote, $company ) {
		$person = trim( (string) $quote['recipient'] );

		return array(
			/* translators: 1: Nummer, 2: Firma. */
			'subject' => sprintf( __( 'Ihr Angebot %1$s von %2$s', 'lindenweg16' ), $quote['number'], $company ),
			'body'    => sprintf(
				/* translators: 1: Anrede-Name, 2: Nummer, 3: Gültig bis, 4: Firma. */
				__( "Guten Tag %1\$s,\n\nvielen Dank für Ihre Anfrage. Im Anhang finden Sie unser Angebot %2\$s. Es gilt bis zum %3\$s.\n\nFür Rückfragen sind wir gern da. Zur Bestellung genügt eine kurze Antwort auf diese Mail.\n\nViele Grüße\n%4\$s", 'lindenweg16' ),
				'' !== $person ? $person : __( 'zusammen', 'lindenweg16' ),
				$quote['number'],
				Kit::dmy( $quote['valid_until'] ),
				$company
			),
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
		$needs = $api->needs( array( 'verkauf.angebote' ) );

		register_rest_route( $ns, '/erp/angebote', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'index' ), 'permission_callback' => $needs ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => $needs ),
		) );
		register_rest_route( $ns, '/erp/angebote/optionen', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'options' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/angebote/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'show' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/angebote/(?P<id>\d+)/pdf', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pdf' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/angebote/(?P<id>\d+)/mail', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'mail_draft' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/angebote/(?P<id>\d+)/senden', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/angebote/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_status' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/angebote/(?P<id>\d+)/auftrag', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'to_order' ), 'permission_callback' => $api->needs( array( 'verkauf.angebote', 'verkauf.orders' ) ) ) );
		register_rest_route( $ns, '/erp/angebote/(?P<id>\d+)/kopie', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'copy' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Angebot laden (mit Positionen).
	 *
	 * @param int $id Kennung.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$q = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Kit::table( 'quotes' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( ! $q ) {
			return null;
		}

		$q['items'] = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Kit::table( 'quote_items' ) . ' WHERE quote_id = %d ORDER BY position ASC, id ASC', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $q;
	}

	/**
	 * Angebot für die Oberfläche.
	 *
	 * @param array $q Angebot.
	 * @return array
	 */
	protected static function out( array $q ) {
		$today  = current_time( 'Y-m-d' );
		$ku     = Kit::company()['kleinunternehmer'];
		$items  = isset( $q['items'] ) ? $q['items'] : array();
		$totals = self::totals( $items, $q['discount_pct'], $q['shipping_gross'], $ku );
		$shown  = self::shown_status( $q['status'], $q['valid_until'], $today );
		$labels = self::status_labels();

		foreach ( $items as $i => $item ) {
			$items[ $i ]['line_total'] = $totals['lines'][ $i ];
		}

		return array(
			'id'                => (int) $q['id'],
			'number'            => (string) $q['number'],
			'contact_id'        => (int) $q['contact_id'],
			'recipient'         => (string) $q['recipient'],
			'recipient_email'   => (string) $q['recipient_email'],
			'recipient_address' => (string) $q['recipient_address'],
			'status'            => (string) $q['status'],
			'shown'             => $shown,
			'shown_label'       => isset( $labels[ $shown ] ) ? $labels[ $shown ] : $shown,
			'quote_date'        => (string) $q['quote_date'],
			'valid_until'       => (string) $q['valid_until'],
			'intro'             => (string) $q['intro'],
			'outro'             => (string) $q['outro'],
			'discount_pct'      => (float) $q['discount_pct'],
			'shipping_gross'    => (float) $q['shipping_gross'],
			'total'             => $totals['total'],
			'totals'            => $totals,
			'order_op_id'       => (int) $q['order_op_id'],
			'sent_at'           => (string) $q['sent_at'],
			'note'              => (string) $q['note'],
			'items'             => $items,
			'editable'          => in_array( $q['status'], array( 'entwurf', 'versendet' ), true ),
		);
	}

	/**
	 * Liste.
	 *
	 * @param WP_REST_Request $request Anfrage (status, q).
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ) {
		global $wpdb;

		$t      = Kit::table( 'quotes' );
		$q      = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$today  = current_time( 'Y-m-d' );
		$rows   = '' === $q
			? $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id DESC LIMIT 300", ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE number LIKE %s OR recipient LIKE %s ORDER BY id DESC LIMIT 300", '%' . $wpdb->esc_like( $q ) . '%', '%' . $wpdb->esc_like( $q ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$out    = array();
		$counts = array();
		$open   = 0.0;

		foreach ( (array) $rows as $row ) {
			$shown            = self::shown_status( $row['status'], $row['valid_until'], $today );
			$counts[ $shown ] = isset( $counts[ $shown ] ) ? $counts[ $shown ] + 1 : 1;
			if ( 'versendet' === $shown ) {
				$open += (float) $row['total_gross'];
			}
			if ( '' !== $status && 'alle' !== $status && $shown !== $status ) {
				continue;
			}
			$labels = self::status_labels();
			$out[]  = array(
				'id'          => (int) $row['id'],
				'number'      => (string) $row['number'],
				'recipient'   => (string) $row['recipient'],
				'quote_date'  => (string) $row['quote_date'],
				'valid_until' => (string) $row['valid_until'],
				'total'       => (float) $row['total_gross'],
				'shown'       => $shown,
				'shown_label' => $labels[ $shown ],
				'order_op_id' => (int) $row['order_op_id'],
			);
		}

		return new WP_REST_Response( array( 'rows' => $out, 'counts' => $counts, 'open_value' => round( $open, 2 ) ) );
	}

	/**
	 * Auswahl für das Formular: Kontakte, Artikel, Farben, Vorgabetexte.
	 *
	 * @return WP_REST_Response
	 */
	public static function options() {
		$contacts = array();
		$today    = current_time( 'Y-m-d' );

		foreach ( \Lindenweg16\Modules\Betrieb\ContactRepository::all() as $c ) {
			$profile  = \Lindenweg16\Modules\Betrieb\ContactRepository::profile( (int) $c['id'] );
			$discount = 0.0;
			foreach ( \Lindenweg16\Modules\Betrieb\ContactRepository::terms( (int) $c['id'] ) as $term ) {
				if ( $term['valid_from'] <= $today && ( empty( $term['valid_until'] ) || $term['valid_until'] >= $today ) ) {
					$discount = (float) $term['discount_pct'];
					break;
				}
			}
			$address    = $profile && $profile['billing_address'] ? (string) $profile['billing_address'] : trim( (string) $c['street'] . "\n" . trim( $c['zip'] . ' ' . $c['city'] ) );
			$contacts[] = array(
				'id'       => (int) $c['id'],
				'company'  => (string) $c['company'],
				'person'   => (string) $c['contact_person'],
				'email'    => $profile && is_email( (string) $profile['billing_email'] ) ? (string) $profile['billing_email'] : (string) $c['email'],
				'address'  => $address,
				'discount' => $discount,
				'type'     => (string) $c['type'],
			);
		}

		$articles = array();
		foreach ( \Lindenweg16\Modules\Betrieb\ArticleRepository::all_by_id() as $a ) {
			if ( in_array( (string) $a['status'], array( 'ausgelaufen', 'gesperrt' ), true ) || empty( $a['sellable'] ) ) {
				continue;
			}
			$articles[] = array(
				'id'     => (int) $a['id'],
				'sku'    => (string) $a['sku'],
				'name'   => (string) $a['name'],
				'price'  => null === $a['price_gross'] ? 0.0 : (float) $a['price_gross'],
				'dealer' => null === $a['dealer_price'] ? null : (float) $a['dealer_price'],
			);
		}

		$c = Kit::company();

		return new WP_REST_Response(
			array(
				'contacts' => $contacts,
				'articles' => $articles,
				'colors'   => \Lindenweg16\Modules\Betrieb\ColorRegistry::all(),
				'intro'    => __( 'vielen Dank für Ihre Anfrage. Gern bieten wir Ihnen an:', 'lindenweg16' ),
				'outro'    => __( 'Lieferzeit nach Auftrag etwa zwei Wochen. Wir freuen uns auf Ihre Bestellung.', 'lindenweg16' ),
				'ku'       => $c['kleinunternehmer'],
				'today'    => current_time( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Ein Angebot.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function show( WP_REST_Request $request ) {
		$q = self::find( (int) $request['id'] );

		return $q ? new WP_REST_Response( self::out( $q ) ) : Kit::fail( __( 'Dieses Angebot gibt es nicht (mehr).', 'lindenweg16' ), 404 );
	}

	/**
	 * Anlegen oder ändern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save( WP_REST_Request $request ) {
		global $wpdb;

		$in    = Kit::input( $request );
		$id    = isset( $in['id'] ) ? (int) $in['id'] : 0;
		$check = self::validate( $in, current_time( 'Y-m-d' ) );

		if ( $check['errors'] ) {
			return Kit::fail( implode( ' ', $check['errors'] ) );
		}
		if ( '' !== $check['data']['recipient_email'] && ! is_email( $check['data']['recipient_email'] ) ) {
			return Kit::fail( __( 'Die E-Mail-Adresse stimmt nicht.', 'lindenweg16' ) );
		}

		$before = $id ? self::find( $id ) : null;
		if ( $id && ! $before ) {
			return Kit::fail( __( 'Dieses Angebot gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( $before && ! in_array( $before['status'], array( 'entwurf', 'versendet' ), true ) ) {
			return Kit::fail( __( 'Ein angenommenes oder abgelehntes Angebot lässt sich nicht mehr ändern. Lege eine Kopie an.', 'lindenweg16' ), 409 );
		}

		$data                 = $check['data'];
		$data['total_gross']  = self::totals( $check['items'], $data['discount_pct'], $data['shipping_gross'], Kit::company()['kleinunternehmer'] )['total'];
		$data['updated_at']   = current_time( 'mysql', true );
		$t                    = Kit::table( 'quotes' );

		if ( $before ) {
			$wpdb->update( $t, $data, array( 'id' => $id ) );
			$wpdb->delete( Kit::table( 'quote_items' ), array( 'quote_id' => $id ) );
		} else {
			$data['number']     = Kit::number_from( $t, 'number', 'AN' );
			$data['status']     = 'entwurf';
			$data['created_at'] = current_time( 'mysql', true );
			$data['created_by'] = get_current_user_id();
			if ( ! $wpdb->insert( $t, $data ) ) {
				return Kit::fail( __( 'Das Angebot ließ sich nicht speichern. Ist das Plugin-Update vollständig gelaufen (Datenbank)?', 'lindenweg16' ), 500 );
			}
			$id = (int) $wpdb->insert_id;
		}

		foreach ( $check['items'] as $n => $item ) {
			$item['quote_id'] = $id;
			$item['position'] = $n + 1;
			$wpdb->insert( Kit::table( 'quote_items' ), $item );
		}

		AuditLog::record( $before ? 'erp_angebot_geaendert' : 'erp_angebot_angelegt', 'quote', $id, array( 'summe' => $data['total_gross'], 'positionen' => count( $check['items'] ) ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Angebot gespeichert.', 'lindenweg16' ), 'quote' => self::out( self::find( $id ) ) ), $before ? 200 : 201 );
	}

	/**
	 * PDF bauen.
	 *
	 * @param array $q Angebot (mit Positionen).
	 * @return string
	 */
	public static function build_pdf( array $q ) {
		$c      = Kit::company();
		$totals = self::totals( $q['items'], $q['discount_pct'], $q['shipping_gross'], $c['kleinunternehmer'] );
		$pdf    = new Pdf();
		$widths = array( 30, 255, 50, 75, 85 );
		$aligns = array( 'l', 'l', 'r', 'r', 'r' );

		Kit::letter_head(
			$pdf,
			array_merge( array( $q['recipient'] ), preg_split( '/\r\n|\r|\n/', (string) $q['recipient_address'] ) ),
			__( 'Angebot', 'lindenweg16' ),
			array(
				__( 'Angebotsnummer', 'lindenweg16' ) => $q['number'],
				__( 'Datum', 'lindenweg16' )          => Kit::dmy( $q['quote_date'] ),
				__( 'Gültig bis', 'lindenweg16' )     => Kit::dmy( $q['valid_until'] ),
			)
		);

		if ( '' !== trim( (string) $q['intro'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $q['intro'] ) as $line ) {
				$pdf->text( $line, 10 );
			}
			$pdf->space( 8 );
		}

		$pdf->hr();
		$pdf->table_cols( array( __( 'Pos.', 'lindenweg16' ), __( 'Bezeichnung', 'lindenweg16' ), __( 'Menge', 'lindenweg16' ), __( 'Einzelpreis', 'lindenweg16' ), __( 'Gesamt', 'lindenweg16' ) ), $widths, $aligns, 9, true );
		$pdf->hr( 0.3 );

		foreach ( $q['items'] as $i => $item ) {
			$title = (string) $item['title'];
			if ( (float) $item['discount_pct'] > 0 ) {
				$title .= ' (−' . str_replace( '.', ',', (string) ( 0 + (float) $item['discount_pct'] ) ) . ' %)';
			}
			$pdf->table_cols( array( (string) ( $i + 1 ), Kit::cut( $title, 58 ), str_replace( '.', ',', (string) ( 0 + (float) $item['qty'] ) ), Kit::eur( $item['unit_price_gross'] ), Kit::eur( $totals['lines'][ $i ] ) ), $widths, $aligns, 9 );
			if ( '' !== trim( (string) $item['description'] ) ) {
				$pdf->text( Kit::cut( (string) $item['description'], 110 ), 8, false, 30 );
			}
		}

		$pdf->hr( 0.3 );
		$pdf->space( 4 );
		$pdf->row( __( 'Zwischensumme', 'lindenweg16' ), Kit::eur( $totals['subtotal'] ), 10 );
		if ( $totals['discount'] > 0 ) {
			/* translators: %s: Prozent. */
			$pdf->row( sprintf( __( 'Rabatt %s %%', 'lindenweg16' ), str_replace( '.', ',', (string) ( 0 + (float) $q['discount_pct'] ) ) ), '−' . Kit::eur( $totals['discount'] ), 10 );
		}
		if ( $totals['shipping'] > 0 ) {
			$pdf->row( __( 'Versand', 'lindenweg16' ), Kit::eur( $totals['shipping'] ), 10 );
		}
		$pdf->row( __( 'Gesamtbetrag', 'lindenweg16' ), Kit::eur( $totals['total'] ), 12, true );
		if ( ! $c['kleinunternehmer'] ) {
			$pdf->row( __( 'darin enthaltene Umsatzsteuer 19 %', 'lindenweg16' ), Kit::eur( $totals['vat'] ), 9 );
		}

		if ( '' !== trim( (string) $q['outro'] ) ) {
			$pdf->space( 12 );
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $q['outro'] ) as $line ) {
				$pdf->text( $line, 10 );
			}
		}

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
		$q = self::find( (int) $request['id'] );

		if ( ! $q ) {
			return Kit::fail( __( 'Dieses Angebot gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		return Kit::file_response( 'Angebot-' . $q['number'] . '.pdf', 'application/pdf', self::build_pdf( $q ) );
	}

	/**
	 * Mailentwurf.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function mail_draft( WP_REST_Request $request ) {
		$q = self::find( (int) $request['id'] );

		if ( ! $q ) {
			return Kit::fail( __( 'Dieses Angebot gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$text = self::mail_text( $q, Kit::company()['name'] );

		return new WP_REST_Response( array( 'to' => (string) $q['recipient_email'], 'subject' => $text['subject'], 'body' => $text['body'] ) );
	}

	/**
	 * Per Mail mit PDF verschicken.
	 *
	 * @param WP_REST_Request $request Anfrage {to, subject, body}.
	 * @return WP_REST_Response
	 */
	public static function send( WP_REST_Request $request ) {
		global $wpdb;

		$q  = self::find( (int) $request['id'] );
		$in = Kit::input( $request );

		if ( ! $q ) {
			return Kit::fail( __( 'Dieses Angebot gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( ! in_array( $q['status'], array( 'entwurf', 'versendet' ), true ) ) {
			return Kit::fail( __( 'Dieses Angebot ist schon entschieden.', 'lindenweg16' ), 409 );
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
		if ( ! Kit::mail( $to, $subject, $body, 'Angebot-' . $q['number'] . '.pdf', self::build_pdf( $q ) ) ) {
			return Kit::fail( __( 'Die Mail konnte nicht verschickt werden. Bitte die Mail-Einstellungen der Website prüfen.', 'lindenweg16' ), 502 );
		}

		$wpdb->update( Kit::table( 'quotes' ), array( 'status' => 'versendet', 'sent_at' => current_time( 'mysql', true ), 'recipient_email' => $to ), array( 'id' => (int) $q['id'] ) );

		if ( $q['contact_id'] ) {
			\Lindenweg16\Modules\Betrieb\ContactRepository::add_activity(
				array(
					'contact_id'    => (int) $q['contact_id'],
					'activity_type' => 'mail',
					/* translators: 1: Nummer, 2: Betrag. */
					'summary'       => sprintf( __( 'Angebot %1$s (%2$s) per Mail geschickt.', 'lindenweg16' ), $q['number'], Kit::eur( $q['total_gross'] ) ),
					'next_step'     => __( 'Beim Kunden nachfassen', 'lindenweg16' ),
					'follow_up_on'  => Kit::add_days( current_time( 'Y-m-d' ), 7 ),
				)
			);
		}
		AuditLog::record( 'erp_angebot_versendet', 'quote', (int) $q['id'], array( 'an' => $to ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Angebot verschickt. In einer Woche steht die Wiedervorlage in der Kundenakte.', 'lindenweg16' ) ) );
	}

	/**
	 * Zustand ändern.
	 *
	 * @param WP_REST_Request $request Anfrage {status}.
	 * @return WP_REST_Response
	 */
	public static function set_status( WP_REST_Request $request ) {
		global $wpdb;

		$q  = self::find( (int) $request['id'] );
		$in = Kit::input( $request );
		$to = sanitize_key( isset( $in['status'] ) ? (string) $in['status'] : '' );

		if ( ! $q ) {
			return Kit::fail( __( 'Dieses Angebot gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( 'angenommen' === $to ) {
			return Kit::fail( __( 'Angenommen wird ein Angebot über „In Auftrag umwandeln“.', 'lindenweg16' ) );
		}
		if ( ! self::can_switch( $q['status'], $to ) ) {
			return Kit::fail( __( 'Dieser Wechsel geht nicht.', 'lindenweg16' ), 409 );
		}

		$wpdb->update( Kit::table( 'quotes' ), array( 'status' => $to, 'decided_at' => 'abgelehnt' === $to ? current_time( 'mysql', true ) : null ), array( 'id' => (int) $q['id'] ) );
		AuditLog::record( 'erp_angebot_status', 'quote', (int) $q['id'], array( 'von' => $q['status'], 'nach' => $to ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Angenommenes Angebot in einen Auftrag umwandeln.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function to_order( WP_REST_Request $request ) {
		global $wpdb;

		$q = self::find( (int) $request['id'] );

		if ( ! $q ) {
			return Kit::fail( __( 'Dieses Angebot gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( (int) $q['order_op_id'] ) {
			return Kit::fail( __( 'Aus diesem Angebot ist schon ein Auftrag entstanden.', 'lindenweg16' ), 409 );
		}
		if ( ! in_array( $q['status'], array( 'entwurf', 'versendet' ), true ) ) {
			return Kit::fail( __( 'Nur offene Angebote lassen sich umwandeln.', 'lindenweg16' ), 409 );
		}
		if ( ! $q['contact_id'] ) {
			return Kit::fail( __( 'Ein Auftrag braucht einen Kontakt. Bitte im Angebot einen Kontakt wählen (Verkauf → Kontakte).', 'lindenweg16' ) );
		}

		$lock = 'lw16_erp_angebot_' . (int) $q['id'];
		if ( get_transient( $lock ) ) {
			return Kit::fail( __( 'Wird gerade umgewandelt – bitte kurz warten.', 'lindenweg16' ), 409 );
		}
		set_transient( $lock, 1, 30 );

		$ku     = Kit::company()['kleinunternehmer'];
		$totals = self::totals( $q['items'], $q['discount_pct'], $q['shipping_gross'], $ku );
		$items  = array();
		$free   = 0;
		$factor = 1 - (float) $q['discount_pct'] / 100;

		foreach ( $q['items'] as $i => $item ) {
			if ( ! (int) $item['article_id'] ) {
				++$free;
				continue;
			}
			$items[] = array(
				'article_id'       => (int) $item['article_id'],
				'color'            => '' !== (string) $item['color'] ? (string) $item['color'] : \Lindenweg16\Modules\Betrieb\ColorRegistry::default_color(),
				'qty'              => (float) $item['qty'],
				'unit_price_gross' => (float) $item['qty'] > 0 ? round( $totals['lines'][ $i ] * $factor / (float) $item['qty'], 4 ) : 0,
				'discountable'     => 0,
			);
		}

		$terms_days = 0;
		$today      = current_time( 'Y-m-d' );
		foreach ( \Lindenweg16\Modules\Betrieb\ContactRepository::terms( (int) $q['contact_id'] ) as $term ) {
			if ( $term['valid_from'] <= $today && ( empty( $term['valid_until'] ) || $term['valid_until'] >= $today ) ) {
				$terms_days = (int) $term['payment_terms_days'];
				break;
			}
		}

		$order_id = \Lindenweg16\Modules\Betrieb\OrderOpsRepository::create_manual(
			array(
				'contact_id'         => (int) $q['contact_id'],
				'manual_order_date'  => $today,
				'manual_order_value' => $totals['total'],
				'payment_terms_days' => $terms_days,
				/* translators: %s: Nummer. */
				'note'               => sprintf( __( 'Aus Angebot %s', 'lindenweg16' ), $q['number'] ),
			),
			$items
		);

		delete_transient( $lock );

		if ( ! $order_id ) {
			return Kit::fail( __( 'Der Auftrag ließ sich nicht anlegen.', 'lindenweg16' ), 500 );
		}

		$wpdb->update( Kit::table( 'quotes' ), array( 'status' => 'angenommen', 'order_op_id' => $order_id, 'decided_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $q['id'] ) );
		AuditLog::record( 'erp_angebot_auftrag', 'quote', (int) $q['id'], array( 'auftrag' => $order_id ) );

		$msg = __( 'Auftrag angelegt, Ware ist ausgebucht.', 'lindenweg16' );
		if ( $free ) {
			/* translators: %d: Anzahl. */
			$msg .= ' ' . sprintf( _n( '%d Position ohne Artikel steht nur im Auftragswert, nicht als Lagerposition.', '%d Positionen ohne Artikel stehen nur im Auftragswert, nicht als Lagerpositionen.', $free, 'lindenweg16' ), $free );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => $msg, 'order_op_id' => $order_id ), 201 );
	}

	/**
	 * Kopie als neuer Entwurf.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function copy( WP_REST_Request $request ) {
		$q = self::find( (int) $request['id'] );

		if ( ! $q ) {
			return Kit::fail( __( 'Dieses Angebot gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$new = new WP_REST_Request( 'POST' );
		$new->set_body_params(
			array_merge(
				array_intersect_key( $q, array_flip( array( 'contact_id', 'recipient', 'recipient_email', 'recipient_address', 'intro', 'outro', 'discount_pct', 'shipping_gross', 'note' ) ) ),
				array(
					'quote_date'  => current_time( 'Y-m-d' ),
					'valid_until' => '',
					'items'       => $q['items'],
				)
			)
		);

		return self::save( $new );
	}

	/**
	 * Angebote eines Kontakts (für die Kundenakte).
	 *
	 * @param int $contact_id Kontakt.
	 * @return array
	 */
	public static function for_contact( $contact_id ) {
		global $wpdb;

		$today  = current_time( 'Y-m-d' );
		$labels = self::status_labels();
		$out    = array();

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, number, quote_date, valid_until, status, total_gross, order_op_id FROM ' . Kit::table( 'quotes' ) . ' WHERE contact_id = %d ORDER BY id DESC LIMIT 20', (int) $contact_id ), ARRAY_A ) as $q ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$shown = self::shown_status( $q['status'], $q['valid_until'], $today );
			$out[] = array(
				'id'     => (int) $q['id'],
				'number' => (string) $q['number'],
				'date'   => (string) $q['quote_date'],
				'total'  => (float) $q['total_gross'],
				'shown'  => $shown,
				'label'  => $labels[ $shown ],
				'order'  => (int) $q['order_op_id'],
			);
		}

		return $out;
	}
}
