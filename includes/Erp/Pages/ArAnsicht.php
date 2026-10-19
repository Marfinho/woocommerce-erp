<?php
/**
 * ERP-Seite „AR-Ansicht“: Einstellungen und Auswertung.
 *
 * Gleiches Recht (`lw16_mod_arview` über verwaltung.arview), gleiche
 * Bereinigung (`Options::sanitize`) und gleiche Zähler (`Stats`) wie die
 * alte Seite.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\ArView\Options;
use Lindenweg16\Modules\ArView\Stats;
use Lindenweg16\Modules\ArView\Viewer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * AR-Ansicht in der neuen Oberfläche.
 */
class ArAnsicht {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$needs = $api->needs( array( 'verwaltung.arview' ) );

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/arview',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get' ),
					'permission_callback' => $needs,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save' ),
					'permission_callback' => $needs,
				),
			)
		);
	}

	/**
	 * Zeiträume der Auswertung: Tage => Beschriftung (0 = alles).
	 *
	 * @return array
	 */
	public static function ranges() {
		return array(
			30 => __( 'Letzte 30 Tage', 'lindenweg16' ),
			90 => __( 'Letzte 90 Tage', 'lindenweg16' ),
			0  => __( 'Gesamter Zeitraum', 'lindenweg16' ),
		);
	}

	/**
	 * Gewählten Zeitraum auf die bekannten begrenzen (Vorgabe 30 Tage).
	 *
	 * @param mixed $days Eingabe.
	 * @return int
	 */
	public static function clean_days( $days ) {
		$days = is_numeric( $days ) ? (int) $days : 30;

		return array_key_exists( $days, self::ranges() ) ? $days : 30;
	}

	/**
	 * Einstellungen aus der Eingabe: ein nicht gesendeter Schalter heißt „aus“.
	 *
	 * @param array $input Rohwerte (ent-slasht).
	 * @return array Bereinigt.
	 */
	public static function settings_from_input( array $input ) {
		if ( ! array_key_exists( 'enabled', $input ) ) {
			$input['enabled'] = 0;
		}

		return Options::sanitize( $input );
	}

	/**
	 * Einstellungen, Hinweise und Zahlen.
	 *
	 * @param WP_REST_Request $request Anfrage (tage).
	 * @return WP_REST_Response
	 */
	public static function get( WP_REST_Request $request ) {
		$o    = Options::get();
		$days = self::clean_days( $request->get_param( 'tage' ) );

		$pages = array();
		foreach ( (array) get_pages( array( 'post_status' => 'publish' ) ) as $p ) {
			$pages[] = array(
				'id'    => (int) $p->ID,
				'title' => (string) $p->post_title,
			);
		}

		$warning = '';
		if ( $o['enabled'] ) {
			if ( '' === Options::viewer_url() ) {
				$warning = __( 'Es ist noch keine (veröffentlichte) Ziel-Seite gewählt. Solange das so bleibt, erscheint der Hinweis auf keiner Produktseite.', 'lindenweg16' );
			} else {
				$page = get_post( (int) $o['page_id'] );
				if ( $page && ! has_shortcode( (string) $page->post_content, Viewer::SHORTCODE ) ) {
					/* translators: %s: Shortcode. */
					$warning = sprintf( __( 'Die gewählte Seite enthält den Shortcode %s nicht. Bitte füge ihn dort ein, sonst landen Besucher auf einer leeren Seite.', 'lindenweg16' ), '[' . Viewer::SHORTCODE . ']' );
				}
			}
		}

		$totals = Stats::totals( $days );
		uasort(
			$totals,
			static function ( $a, $b ) {
				return $b[ Stats::EVENT_SHOWN ] <=> $a[ Stats::EVENT_SHOWN ];
			}
		);

		$sum  = array_fill_keys( array_keys( Stats::events() ), 0 );
		$rows = array();
		foreach ( $totals as $product_id => $row ) {
			foreach ( $row as $event => $count ) {
				$sum[ $event ] += $count;
			}
			$title  = get_the_title( $product_id );
			$rows[] = array(
				'title'  => '' !== $title ? $title : sprintf( /* translators: %d: Produkt-ID. */ __( 'Produkt #%d (gelöscht)', 'lindenweg16' ), $product_id ),
				'counts' => array_values( $row ),
				'rate'   => Stats::rate( $row[ Stats::EVENT_YES ], $row[ Stats::EVENT_SHOWN ] ),
			);
		}

		return new WP_REST_Response(
			array(
				'settings' => $o,
				'limits'   => array(
					'days_min'  => Options::DAYS_MIN,
					'days_max'  => Options::DAYS_MAX,
					'delay_min' => Options::DELAY_MIN,
					'delay_max' => Options::DELAY_MAX,
				),
				'pages'    => $pages,
				'warning'  => $warning,
				'shortcode' => '[' . Viewer::SHORTCODE . ']',
				'events'   => array_values( Stats::events() ),
				'days'     => $days,
				'ranges'   => self::ranges(),
				'rows'     => $rows,
				'sum'      => array_values( $sum ),
				'sum_rate' => Stats::rate( $sum[ Stats::EVENT_YES ], $sum[ Stats::EVENT_SHOWN ] ),
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
		$input = $request->get_param( 'settings' );

		if ( ! is_array( $input ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Es kamen keine Einstellungen an. Bitte die Seite neu laden und noch einmal speichern.', 'lindenweg16' ) ), 422 );
		}

		$clean = self::settings_from_input( $input );
		update_option( Options::OPTION, $clean );

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'message'  => __( 'Einstellungen gespeichert.', 'lindenweg16' ),
				'settings' => $clean,
			)
		);
	}
}
