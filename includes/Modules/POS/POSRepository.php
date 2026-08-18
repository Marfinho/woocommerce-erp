<?php
/**
 * Repository: Datenzugriff für die Marktkasse (POS).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\POS;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Time;

class POSRepository {

	/* ------------------------------------------------------------------
	 * Kassatage
	 * ---------------------------------------------------------------- */

	public static function create_tag( array $data ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'lw16_kasse_tage',
			array(
				'markt_name'           => $data['markt_name'] ?? '',
				'datum'                => $data['datum'] ?? gmdate( 'Y-m-d' ),
				'kassenbestand_anfang' => $data['kassenbestand_anfang'] ?? 0,
				'status'               => 'offen',
				'erstellt_von'         => get_current_user_id(),
				'erstellt_am'          => Time::now(),
			),
			array( '%s', '%s', '%f', '%s', '%d', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function get_tag( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}lw16_kasse_tage WHERE id = %d",
				$id
			)
		);
	}

	public static function get_offener_tag() {
		global $wpdb;
		return $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}lw16_kasse_tage WHERE status = 'offen' ORDER BY id DESC LIMIT 1"
		);
	}

	public static function get_tage( $status = null, $limit = 50 ) {
		global $wpdb;
		$t = $wpdb->prefix . 'lw16_kasse_tage';

		if ( $status ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$t} WHERE status = %s ORDER BY datum DESC, id DESC LIMIT %d",
					$status,
					$limit
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} ORDER BY datum DESC, id DESC LIMIT %d",
				$limit
			)
		);
	}

	public static function update_tag( $id, array $data ) {
		global $wpdb;
		$formats = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'kassenbestand_ende', 'umsatz_gesamt', 'kassenbestand_anfang' ), true ) ) {
				$formats[] = '%f';
			} elseif ( in_array( $key, array( 'beleg_id', 'erstellt_von' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}
		return $wpdb->update(
			$wpdb->prefix . 'lw16_kasse_tage',
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}

	/* ------------------------------------------------------------------
	 * Kassapositionen
	 * ---------------------------------------------------------------- */

	public static function create_position( array $data ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'lw16_kasse_positionen',
			array(
				'tag_id'       => $data['tag_id'],
				'produkt_id'   => $data['produkt_id'] ?? null,
				'artikel_name' => $data['artikel_name'] ?? '',
				'menge'        => $data['menge'] ?? 1,
				'einzelpreis'  => $data['einzelpreis'] ?? 0,
				'betrag'       => $data['betrag'] ?? 0,
				'storniert'    => 0,
				'erstellt_am'  => Time::now(),
			),
			array( '%d', '%d', '%s', '%d', '%f', '%f', '%d', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function get_position( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}lw16_kasse_positionen WHERE id = %d",
				$id
			)
		);
	}

	public static function get_positionen( $tag_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}lw16_kasse_positionen WHERE tag_id = %d ORDER BY erstellt_am ASC",
				$tag_id
			)
		);
	}

	public static function update_position( $id, array $data ) {
		global $wpdb;
		$formats = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'einzelpreis', 'betrag' ), true ) ) {
				$formats[] = '%f';
			} elseif ( in_array( $key, array( 'tag_id', 'produkt_id', 'menge', 'storniert' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}
		return $wpdb->update(
			$wpdb->prefix . 'lw16_kasse_positionen',
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}
}
