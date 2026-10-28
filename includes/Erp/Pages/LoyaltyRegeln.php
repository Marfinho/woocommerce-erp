<?php
/**
 * ERP: Regeln und Aktionen des Prämienprogramms.
 *
 * Lesen mit `verkauf.loyalty`, Ändern mit `verkauf.loyalty.regeln`.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Settings;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Loyalty\LoyaltyCampaigns;
use Lindenweg16\Modules\Loyalty\LoyaltyPresale;
use Lindenweg16\Modules\Loyalty\LoyaltyRules;
use Lindenweg16\Modules\Loyalty\LoyaltyTiers;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Punkte je Euro, Bonuspunkte je Auslöser und zeitlich begrenzte Aktionen.
 */
class LoyaltyRegeln {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns   = RestApi::NAMESPACE_V1;
		$read = $api->needs( array( 'verkauf.loyalty' ) );
		$edit = function () {
			return Access::can( 'verkauf.loyalty' ) && Access::can( 'verkauf.loyalty.regeln' );
		};

		register_rest_route( $ns, '/erp/loyalty/regeln', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'read' ),
				'permission_callback' => $read,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_rules' ),
				'permission_callback' => $edit,
			),
		) );
		register_rest_route( $ns, '/erp/loyalty/aktionen', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_campaign' ),
			'permission_callback' => $edit,
		) );
		register_rest_route( $ns, '/erp/loyalty/aktionen/(?P<id>[a-z0-9]+)/loeschen', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'delete_campaign' ),
			'permission_callback' => $edit,
		) );
		register_rest_route( $ns, '/erp/loyalty/presale', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_presale' ),
			'permission_callback' => $edit,
		) );
		register_rest_route( $ns, '/erp/loyalty/presale/(?P<id>[a-z0-9]+)/loeschen', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'delete_presale' ),
			'permission_callback' => $edit,
		) );
		register_rest_route( $ns, '/erp/loyalty/presale/produkte', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'search_products' ),
			'permission_callback' => $read,
		) );
	}

	/**
	 * Regeln, Aktionen und Kategorien.
	 *
	 * @return WP_REST_Response
	 */
	public static function read() {
		$fields = array();
		foreach ( LoyaltyRules::fields() as $key => $def ) {
			$def['key']   = $key;
			$def['value'] = Settings::get( $key, $def['suggest'] );
			$fields[]     = $def;
		}

		$groups = array();
		foreach ( LoyaltyRules::groups() as $id => $title ) {
			$groups[] = array( 'id' => $id, 'title' => $title );
		}

		$today     = current_time( 'Y-m-d' );
		$campaigns = array();
		foreach ( LoyaltyCampaigns::all() as $c ) {
			$c['state']  = $c['bis'] < $today ? 'vorbei' : ( $c['von'] > $today ? 'geplant' : 'laeuft' );
			$campaigns[] = $c;
		}

		$presales = array();
		foreach ( LoyaltyPresale::all() as $p ) {
			$p['state']    = $p['frei_ab'] <= $today ? 'vorbei' : ( $p['von'] > $today ? 'geplant' : 'laeuft' );
			$p['produkte_namen'] = array();
			foreach ( (array) $p['produkte'] as $pid ) {
				$p['produkte_namen'][ (int) $pid ] = html_entity_decode( get_the_title( (int) $pid ), ENT_QUOTES, 'UTF-8' );
			}
			$presales[]    = $p;
		}

		return new WP_REST_Response(
			array(
				'presales'   => $presales,
				'merkmale'   => self::merkmale(),
				'groups'     => $groups,
				'fields'     => $fields,
				'campaigns'  => $campaigns,
				'categories' => self::categories(),
				'tiers'      => LoyaltyTiers::all(),
				'today'      => $today,
				'can_edit'   => Access::can( 'verkauf.loyalty.regeln' ),
			)
		);
	}

	/**
	 * Regeln speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save_rules( WP_REST_Request $request ) {
		$in     = (array) $request->get_json_params();
		$result = LoyaltyRules::sanitize( array_filter( $in, 'is_scalar' ) );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		$tiers = null;
		if ( isset( $in['stufen'] ) && is_array( $in['stufen'] ) ) {
			$check = LoyaltyRules::sanitize_tiers(
				array_map(
					function ( $row ) {
						return is_array( $row ) ? array(
							'name'   => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
							'ab'     => $row['ab'] ?? 0,
							'faktor' => $row['faktor'] ?? 1,
						) : null;
					},
					$in['stufen']
				)
			);
			if ( ! $check['tiers'] ) {
				return self::fail( $check['error'] );
			}
			$tiers = $check['tiers'];
		}
		if ( ! $result['values'] && null === $tiers ) {
			return self::fail( __( 'Es kam nichts zum Speichern an.', 'lindenweg16' ) );
		}

		$values = $result['values'];
		// Verfall frisch eingeschaltet: Die Frist beginnt für alle heute,
		// niemand verliert über Nacht Punkte aus der Zeit davor.
		if ( ! empty( $values['punkte_verfall_monate'] ) && ! (int) Settings::get( 'punkte_verfall_monate', 0 ) ) {
			$values['punkte_verfall_start'] = time();
		}

		$before = array();
		foreach ( array_keys( $values ) as $key ) {
			$before[ $key ] = Settings::get( $key );
		}
		if ( $values ) {
			Settings::update( $values );
		}
		if ( null !== $tiers ) {
			$before['stufen'] = LoyaltyTiers::all();
			$values['stufen'] = $tiers;
			LoyaltyTiers::save( $tiers );
		}
		AuditLog::record( 'loyalty_regeln', 'settings', 0, array( 'vorher' => $before, 'nachher' => $values ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Regeln gespeichert. Sie gelten ab sofort.', 'lindenweg16' ) ) );
	}

	/**
	 * Aktion anlegen oder ändern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save_campaign( WP_REST_Request $request ) {
		$in    = (array) $request->get_json_params();
		$id    = isset( $in['id'] ) ? sanitize_key( (string) $in['id'] ) : '';
		$known = wp_list_pluck( self::categories(), 'id' );

		if ( $id && ! LoyaltyCampaigns::get( $id ) ) {
			return self::fail( __( 'Diese Aktion gibt es nicht mehr. Lade die Seite neu.', 'lindenweg16' ), 404 );
		}

		$check = LoyaltyRules::sanitize_campaign(
			array(
				'name'       => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
				'von'        => (string) ( $in['von'] ?? '' ),
				'bis'        => (string) ( $in['bis'] ?? '' ),
				'faktor'     => $in['faktor'] ?? 0,
				'kategorien' => isset( $in['kategorien'] ) && is_array( $in['kategorien'] ) ? $in['kategorien'] : array(),
			),
			array_map( 'intval', $known )
		);
		if ( ! $check['campaign'] ) {
			return self::fail( $check['error'] );
		}

		$new_id = LoyaltyCampaigns::save( $check['campaign'], $id );
		AuditLog::record( $id ? 'loyalty_aktion_geaendert' : 'loyalty_aktion_angelegt', 'loyalty_aktion', 0, array_merge( array( 'id' => $new_id ), $check['campaign'] ) );

		return new WP_REST_Response( array( 'ok' => true, 'id' => $new_id, 'message' => $id ? __( 'Aktion geändert.', 'lindenweg16' ) : __( 'Aktion angelegt.', 'lindenweg16' ) ) );
	}

	/**
	 * Aktion löschen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function delete_campaign( WP_REST_Request $request ) {
		$id = sanitize_key( (string) $request['id'] );
		$c  = LoyaltyCampaigns::get( $id );
		if ( ! $c || ! LoyaltyCampaigns::delete( $id ) ) {
			return self::fail( __( 'Diese Aktion gibt es nicht mehr.', 'lindenweg16' ), 404 );
		}
		AuditLog::record( 'loyalty_aktion_geloescht', 'loyalty_aktion', 0, $c );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Aktion gelöscht. Schon vergebene Punkte bleiben.', 'lindenweg16' ) ) );
	}

	/**
	 * Pre-Sale anlegen oder ändern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save_presale( WP_REST_Request $request ) {
		$in = (array) $request->get_json_params();
		$id = isset( $in['id'] ) ? sanitize_key( (string) $in['id'] ) : '';

		if ( $id && ! LoyaltyPresale::get( $id ) ) {
			return self::fail( __( 'Diesen Pre-Sale gibt es nicht mehr. Lade die Seite neu.', 'lindenweg16' ), 404 );
		}

		$cats  = array_map( 'intval', wp_list_pluck( self::categories(), 'id' ) );
		$check = LoyaltyRules::sanitize_presale(
			array(
				'name'       => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
				'von'        => (string) ( $in['von'] ?? '' ),
				'frei_ab'    => (string) ( $in['frei_ab'] ?? '' ),
				'stufe'      => $in['stufe'] ?? 0,
				'produkte'   => isset( $in['produkte'] ) && is_array( $in['produkte'] ) ? $in['produkte'] : array(),
				'kategorien' => isset( $in['kategorien'] ) && is_array( $in['kategorien'] ) ? array_values( array_intersect( array_map( 'intval', $in['kategorien'] ), $cats ) ) : array(),
				'merkmale'   => isset( $in['merkmale'] ) && is_array( $in['merkmale'] ) ? $in['merkmale'] : array(),
			),
			count( LoyaltyTiers::all() ) - 1
		);
		if ( ! $check['presale'] ) {
			return self::fail( $check['error'] );
		}

		$new_id = LoyaltyPresale::save( $check['presale'], $id );
		AuditLog::record( $id ? 'loyalty_presale_geaendert' : 'loyalty_presale_angelegt', 'loyalty_presale', 0, array_merge( array( 'id' => $new_id ), $check['presale'] ) );

		return new WP_REST_Response( array( 'ok' => true, 'id' => $new_id, 'message' => $id ? __( 'Pre-Sale geändert.', 'lindenweg16' ) : __( 'Pre-Sale angelegt.', 'lindenweg16' ) ) );
	}

	/**
	 * Pre-Sale löschen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function delete_presale( WP_REST_Request $request ) {
		$id = sanitize_key( (string) $request['id'] );
		$p  = LoyaltyPresale::get( $id );
		if ( ! $p || ! LoyaltyPresale::delete( $id ) ) {
			return self::fail( __( 'Diesen Pre-Sale gibt es nicht mehr.', 'lindenweg16' ), 404 );
		}
		AuditLog::record( 'loyalty_presale_geloescht', 'loyalty_presale', 0, $p );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Pre-Sale gelöscht. Die Produkte sind sofort für alle kaufbar.', 'lindenweg16' ) ) );
	}

	/**
	 * Produktsuche für die Auswahl einzelner Produkte.
	 *
	 * @param WP_REST_Request $request Anfrage (q).
	 * @return WP_REST_Response
	 */
	public static function search_products( WP_REST_Request $request ) {
		$q = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		if ( strlen( $q ) < 2 ) {
			return new WP_REST_Response( array() );
		}
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'private' ), 's' => $q, 'posts_per_page' => 12, 'fields' => 'ids' ) );
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = array( 'id' => (int) $id, 'name' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) );
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * Merkmale (Attribute wie Farbe und Schlagwörter) mit ihren Werten für die Auswahl.
	 *
	 * @return array Liste mit taxonomy, label, terms (slug, name).
	 */
	protected static function merkmale() {
		$out = array();
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
			if ( 'product_cat' === $tax->name || ! ( 'product_tag' === $tax->name || 0 === strpos( $tax->name, 'pa_' ) ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$list = array();
			foreach ( $terms as $t ) {
				$list[] = array( 'slug' => $t->slug, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) );
			}
			$out[] = array( 'taxonomy' => $tax->name, 'label' => $tax->label, 'terms' => $list );
		}
		return $out;
	}

	/**
	 * Produktkategorien für die Auswahl.
	 *
	 * @return array Liste mit id, name.
	 */
	protected static function categories() {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array( 'id' => (int) $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) );
		}
		return $out;
	}

	/**
	 * Fehlerantwort mit verständlichem Text.
	 *
	 * @param string $message Meldung.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}
}
