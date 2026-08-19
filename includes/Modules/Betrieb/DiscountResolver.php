<?php
/**
 * Auflösung der Rabattstaffel.
 *
 * Zu Kontakt und Bestelldatum werden die am Stichtag gültigen Sonder-
 * konditionen geladen; existiert keine, greift die Standardstaffel. Aus den
 * Stufen gewinnt die höchste, deren Mindestbestellwert erreicht wird.
 *
 * Der Bestellwert wird dabei **zum UVP** gerechnet, vor Rabatt – sonst rutscht
 * eine Bestellung durch den Rabatt unter die eigene Schwelle und verliert ihn
 * wieder.
 *
 * Nicht rabattfähige Positionen (personalisiert, Sonderfarbe) zählen weder in
 * die Schwelle noch bekommen sie Rabatt: Sonst rechnet die Kalkulation den
 * margenstärksten Teil des Sortiments klein.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Ermittelt Rabattsatz und Stufe.
 */
class DiscountResolver {

	/**
	 * Rabatt aus Stufen und Bestellwert bestimmen – reine Logik.
	 *
	 * @param array $tiers      Stufen mit min_order_value und discount_pct.
	 * @param float $base_value Rabattfähiger Bestellwert zum UVP.
	 * @return array {tier, discount_pct, tier_id, next}
	 */
	public static function resolve( array $tiers, $base_value ) {
		$base_value = (float) $base_value;
		$best       = null;
		$next       = null;

		// Aufsteigend nach Schwelle sortieren, damit die höchste erreichte
		// Stufe gewinnt und die nächste bekannt ist.
		usort(
			$tiers,
			static function ( $a, $b ) {
				return (float) $a['min_order_value'] <=> (float) $b['min_order_value'];
			}
		);

		foreach ( $tiers as $tier ) {
			$threshold = (float) $tier['min_order_value'];

			if ( $base_value >= $threshold ) {
				// Bei gleicher Schwelle gewinnt der höhere Rabatt.
				if ( null === $best || (float) $tier['discount_pct'] >= (float) $best['discount_pct'] ) {
					$best = $tier;
				}
				continue;
			}

			if ( null === $next ) {
				$next = $tier;
			}
		}

		return array(
			'tier'         => $best,
			'tier_id'      => $best && isset( $best['id'] ) ? (int) $best['id'] : null,
			'discount_pct' => $best ? (float) $best['discount_pct'] : 0.0,
			'next'         => $next,
			'missing'      => $next ? max( 0.0, (float) $next['min_order_value'] - $base_value ) : 0.0,
		);
	}

	/**
	 * Rabattfähigen Bestellwert zum UVP bilden – reine Logik.
	 *
	 * @param array $items Positionen mit qty, unit_price_gross, discountable.
	 * @return array {discountable, fixed, total}
	 */
	public static function base_value( array $items ) {
		$discountable = 0.0;
		$fixed        = 0.0;

		foreach ( $items as $item ) {
			$item  = (array) $item;
			$value = (float) $item['qty'] * (float) $item['unit_price_gross'];

			if ( empty( $item['discountable'] ) ) {
				$fixed += $value;
				continue;
			}

			$discountable += $value;
		}

		return array(
			'discountable' => $discountable,
			'fixed'        => $fixed,
			'total'        => $discountable + $fixed,
		);
	}

	/**
	 * Bestellwert nach Rabatt – reine Logik.
	 *
	 * @param array $items        Positionen.
	 * @param float $discount_pct Rabattsatz in Prozent.
	 * @return float
	 */
	public static function net_value( array $items, $discount_pct ) {
		$base = self::base_value( $items );

		return $base['discountable'] * ( 1 - (float) $discount_pct / 100 ) + $base['fixed'];
	}

	/**
	 * Rabatt für einen Kontakt zum Bestelldatum ermitteln.
	 *
	 * @param int    $contact_id Kontakt.
	 * @param array  $items      Positionen.
	 * @param string $date       Bestelldatum.
	 * @return array
	 */
	public static function for_contact( $contact_id, array $items, $date = '' ) {
		if ( '' === $date ) {
			$date = gmdate( 'Y-m-d' );
		}

		$base  = self::base_value( $items );
		$tiers = self::tiers_for_contact( $contact_id, $date );

		$result = self::resolve( $tiers['tiers'], $base['discountable'] );

		$result['source']                     = $tiers['source'];
		$result['base_value']                 = $base['discountable'];
		$result['fixed_value']                = $base['fixed'];
		$result['order_value_gross']          = $base['total'];
		$result['order_value_net_of_discount'] = self::net_value( $items, $result['discount_pct'] );
		$result['saving']                      = $base['total'] - $result['order_value_net_of_discount'];
		$result['payment_terms_days']          = $tiers['payment_terms_days'];

		// Was spart der Händler, wenn er die nächste Stufe erreicht?
		if ( $result['next'] ) {
			$next_value                     = $base['discountable'] + $result['missing'];
			$result['next_saving']          = $next_value * (float) $result['next']['discount_pct'] / 100
				- $next_value * $result['discount_pct'] / 100;
		} else {
			$result['next_saving'] = 0.0;
		}

		return $result;
	}

	/**
	 * Gültige Stufen: Sonderkonditionen des Kontakts, sonst Standardstaffel.
	 *
	 * @param int    $contact_id Kontakt.
	 * @param string $date       Stichtag.
	 * @return array {tiers, source, payment_terms_days}
	 */
	public static function tiers_for_contact( $contact_id, $date ) {
		global $wpdb;

		$contact_id = (int) $contact_id;

		if ( $contact_id ) {
			$terms = $wpdb->prefix . 'lw16_contact_terms';
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$terms}
					 WHERE contact_id = %d AND valid_from <= %s
					 AND ( valid_until IS NULL OR valid_until >= %s )
					 ORDER BY min_order_value ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$contact_id,
					$date,
					$date
				),
				ARRAY_A
			);

			if ( $rows ) {
				return array(
					'tiers'              => $rows,
					'source'             => 'kontakt',
					'payment_terms_days' => (int) $rows[0]['payment_terms_days'],
				);
			}
		}

		$table = $wpdb->prefix . 'lw16_price_tiers';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE valid_from <= %s AND ( valid_until IS NULL OR valid_until >= %s )
				 ORDER BY min_order_value ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date,
				$date
			),
			ARRAY_A
		);

		return array(
			'tiers'              => $rows ? $rows : array(),
			'source'             => 'standard',
			'payment_terms_days' => 0,
		);
	}
}
