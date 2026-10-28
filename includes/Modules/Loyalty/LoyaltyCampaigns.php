<?php
/**
 * Punkte-Aktionen: zeitlich begrenzt mehr Punkte.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

/**
 * Speichert Aktionen (z. B. „doppelte Punkte bis Sonntag“, optional nur für
 * bestimmte Kategorien) in einer eigenen Option. Die Rechenregeln stehen in
 * LoyaltyRules.
 */
class LoyaltyCampaigns {

	const OPTION = 'lw16_loyalty_aktionen';

	/**
	 * Alle Aktionen, neueste zuerst.
	 *
	 * @return array Liste mit id, name, von, bis, faktor, kategorien.
	 */
	public static function all() {
		$list = get_option( self::OPTION, array() );
		$list = is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
		usort(
			$list,
			function ( $a, $b ) {
				return strcmp( (string) $b['von'], (string) $a['von'] );
			}
		);
		return $list;
	}

	/**
	 * Aktion anlegen oder ändern.
	 *
	 * @param array  $campaign Geprüfte Aktion (LoyaltyRules::sanitize_campaign).
	 * @param string $id       Vorhandene Kennung oder leer für neu.
	 * @return string Kennung.
	 */
	public static function save( array $campaign, $id = '' ) {
		$list = self::all();
		$id   = $id ? sanitize_key( $id ) : strtolower( wp_generate_password( 10, false, false ) );
		$list = array_values(
			array_filter(
				$list,
				function ( $c ) use ( $id ) {
					return ( $c['id'] ?? '' ) !== $id;
				}
			)
		);

		$campaign['id'] = $id;
		$list[]         = $campaign;
		update_option( self::OPTION, $list, false );
		return $id;
	}

	/**
	 * Aktion löschen.
	 *
	 * @param string $id Kennung.
	 * @return bool Gab es sie?
	 */
	public static function delete( $id ) {
		$list = self::all();
		$keep = array_values(
			array_filter(
				$list,
				function ( $c ) use ( $id ) {
					return ( $c['id'] ?? '' ) !== $id;
				}
			)
		);
		if ( count( $keep ) === count( $list ) ) {
			return false;
		}
		update_option( self::OPTION, $keep, false );
		return true;
	}

	/**
	 * Eine Aktion.
	 *
	 * @param string $id Kennung.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $c ) {
			if ( ( $c['id'] ?? '' ) === $id ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Aktionen, die an einem Tag laufen (Standard: heute, Ortszeit).
	 *
	 * @param string $ymd Tag als Y-m-d.
	 * @return array
	 */
	public static function active( $ymd = '' ) {
		return LoyaltyRules::active_on( self::all(), $ymd ? $ymd : current_time( 'Y-m-d' ) );
	}
}
