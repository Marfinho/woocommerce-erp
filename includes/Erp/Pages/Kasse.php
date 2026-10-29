<?php
/**
 * ERP-Seite „Kasse“: Kassatag starten, verkaufen, stornieren, abschließen.
 *
 * Gleiche Fachlogik wie früher die wp-admin-Seite (`POSModule::book_sale()` und
 * Co.): Lagerabgang über die Weiche, Beleg und Kassenbucheintrag beim
 * Abschluss. Recht: `verkauf.kasse` (WordPress-Recht `lw16_mod_kasse`).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Marketing\KampagnenRepository;
use Lindenweg16\Modules\POS\POSModule;
use Lindenweg16\Modules\POS\POSRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Kasse in der neuen Oberfläche.
 */
class Kasse {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$needs = $api->needs( array( 'verkauf.kasse' ) );
		$ns    = RestApi::NAMESPACE_V1;

		register_rest_route(
			$ns,
			'/erp/kasse',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get' ),
				'permission_callback' => $needs,
			)
		);

		register_rest_route(
			$ns,
			'/erp/kasse/tag',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'detail' ),
				'permission_callback' => $needs,
			)
		);

		foreach ( array( 'start', 'verkauf', 'storno', 'abschluss', 'produkt', 'produkt-aendern', 'bon' ) as $action ) {
			register_rest_route(
				$ns,
				'/erp/kasse/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, str_replace( '-', '_', $action ) ),
					'permission_callback' => $needs,
				)
			);
		}
	}

	/**
	 * Eine Position für die Oberfläche.
	 *
	 * @param object $pos Zeile.
	 * @return array
	 */
	public static function position( $pos ) {
		return array(
			'id'        => (int) $pos->id,
			'artikel'   => (string) $pos->artikel_name,
			'menge'     => (int) $pos->menge,
			'preis'     => (float) $pos->einzelpreis,
			'betrag'    => (float) $pos->betrag,
			'storniert' => (bool) $pos->storniert,
			'zeit'      => gmdate( 'H:i', strtotime( (string) $pos->erstellt_am ) ),
		);
	}

	/**
	 * Ein Kassatag mit Positionen und Differenz.
	 *
	 * @param object $tag Zeile.
	 * @return array
	 */
	public static function tag( $tag ) {
		$positionen = POSRepository::get_positionen( $tag->id );
		$umsatz     = POSModule::sum_positions( $positionen );
		$anfang     = (float) $tag->kassenbestand_anfang;
		$ende       = (float) $tag->kassenbestand_ende;

		return array(
			'id'          => (int) $tag->id,
			'markt'       => (string) $tag->markt_name,
			'datum'       => (string) $tag->datum,
			'status'      => (string) $tag->status,
			'anfang'      => $anfang,
			'ende'        => $ende,
			'umsatz'      => round( $umsatz, 2 ),
			'differenz'   => 'abgeschlossen' === $tag->status ? round( $ende - $anfang - $umsatz, 2 ) : null,
			'notiz'       => (string) ( $tag->notiz ?? '' ),
			'beleg_id'    => (int) ( $tag->beleg_id ?? 0 ),
			'positionen'  => array_map( array( __CLASS__, 'position' ), $positionen ),
		);
	}

	/**
	 * Stand der Kasse: offener Tag, Kacheln, letzte Tage.
	 *
	 * @return WP_REST_Response
	 */
	public static function get() {
		$offen = POSRepository::get_offener_tag();

		$tage = array();
		foreach ( POSRepository::get_tage( 'abgeschlossen', 20 ) as $t ) {
			$umsatz = (float) $t->umsatz_gesamt;
			$tage[] = array(
				'id'        => (int) $t->id,
				'markt'     => (string) $t->markt_name,
				'datum'     => (string) $t->datum,
				'umsatz'    => $umsatz,
				'anfang'    => (float) $t->kassenbestand_anfang,
				'ende'      => (float) $t->kassenbestand_ende,
				'differenz' => round( (float) $t->kassenbestand_ende - (float) $t->kassenbestand_anfang - $umsatz, 2 ),
			);
		}

		$kampagnen = array();
		foreach ( KampagnenRepository::options() as $id => $label ) {
			$kampagnen[] = array( 'id' => (int) $id, 'label' => $label );
		}

		// Auch ohne offenen Tag: Kassenprodukte lassen sich vorab pflegen.
		$optionen = array();
		foreach ( POSModule::sale_options() as $o ) {
			$o['kuerzel'] = POSModule::initials( $o['name'] );
			$o['suche']   = POSModule::search_key( $o['label'] );
			$optionen[]   = $o;
		}

		return new WP_REST_Response(
			array(
				'tag'       => $offen ? self::tag( $offen ) : null,
				'optionen'  => $optionen,
				'tage'      => $tage,
				'kampagnen' => $kampagnen,
				'heute'     => gmdate( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Ein abgeschlossener Tag im Detail.
	 *
	 * @param WP_REST_Request $request Anfrage (id).
	 * @return WP_REST_Response
	 */
	public static function detail( WP_REST_Request $request ) {
		$tag = POSRepository::get_tag( (int) $request->get_param( 'id' ) );

		if ( ! $tag ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Kassatag nicht gefunden.', 'lindenweg16' ) ), 404 );
		}

		return new WP_REST_Response( self::tag( $tag ) );
	}

	/**
	 * Antwort aus dem Ergebnis der Fachlogik.
	 *
	 * @param array $result ok, message.
	 * @return WP_REST_Response
	 */
	protected static function answer( array $result ) {
		return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/**
	 * Kassatag starten.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function start( WP_REST_Request $request ) {
		return self::answer( POSModule::start_tag( (array) $request->get_json_params() ) );
	}

	/**
	 * Verkauf buchen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function verkauf( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();

		return self::answer( POSModule::book_sale( (int) ( $body['tag_id'] ?? 0 ), $body ) );
	}

	/**
	 * Ganzen Bon buchen.
	 *
	 * @param WP_REST_Request $request Anfrage (tag_id, zeilen).
	 * @return WP_REST_Response
	 */
	public static function bon( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();

		return self::answer( POSModule::book_bon( (int) ( $body['tag_id'] ?? 0 ), (array) ( $body['zeilen'] ?? array() ) ) );
	}

	/**
	 * Verkauf stornieren.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function storno( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();

		return self::answer( POSModule::storno_position( (int) ( $body['id'] ?? 0 ) ) );
	}

	/**
	 * Produkt nur für die Kasse anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage (name, preis, bestand, foto).
	 * @return WP_REST_Response
	 */
	public static function produkt( WP_REST_Request $request ) {
		return self::answer( POSModule::create_product( (array) $request->get_json_params() ) );
	}

	/**
	 * Kassenprodukt ändern oder ausblenden.
	 *
	 * @param WP_REST_Request $request Anfrage (id, name, preis, foto, ausblenden).
	 * @return WP_REST_Response
	 */
	public static function produkt_aendern( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();

		return self::answer( POSModule::update_product( (int) ( $body['id'] ?? 0 ), $body ) );
	}

	/**
	 * Tag abschließen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function abschluss( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();

		return self::answer( POSModule::close_tag( (int) ( $body['tag_id'] ?? 0 ), $body ) );
	}
}
