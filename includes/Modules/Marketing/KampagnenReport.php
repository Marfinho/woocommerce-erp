<?php
/**
 * Auswertung einer Kostenstelle: Kosten gegen zugeordneten Umsatz.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Marketing;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Stellt die drei belastbaren Umsatzquellen einer Kampagne zusammen.
 *
 * Bewusst keine darüber hinausgehende Schätzung: Was sich nicht über die
 * Marktkasse, einen Gutscheincode oder die Kampagnenkennung einer Bestellung
 * nachweisen lässt, bleibt unzugeordnet. Eine Zahl mit Nachkommastellen wäre
 * hier nur Bauchgefühl in schöner Schrift.
 */
class KampagnenReport {

	/**
	 * Meta-Schlüssel, unter dem WooCommerce die Kampagnenkennung ablegt.
	 */
	const META_UTM_CAMPAIGN = '_wc_order_attribution_utm_campaign';

	/**
	 * Meta-Schlüssel der Herkunftsart (utm, referral, organic …).
	 */
	const META_SOURCE_TYPE = '_wc_order_attribution_source_type';

	/**
	 * Auswertungszeitraum einer Kostenstelle.
	 *
	 * Der Umsatz darf über das Kampagnenende hinaus zählen: Wer am Marktstand
	 * einen Flyer mitnimmt, bestellt selten am selben Tag. Die Kosten dagegen
	 * hängen am Beleg, nicht am Zeitraum.
	 *
	 * @param object $kampagne Kostenstelle.
	 * @return array {von, bis}
	 */
	public static function zeitraum( $kampagne ) {
		$von = $kampagne->start_datum ? $kampagne->start_datum : gmdate( 'Y-m-d' );
		if ( ! empty( $kampagne->event_datum ) && $kampagne->event_datum < $von ) {
			$von = $kampagne->event_datum;
		}

		$bis = $kampagne->ende_datum ? $kampagne->ende_datum : $kampagne->event_datum;
		if ( ! $bis ) {
			$bis = gmdate( 'Y-m-d' );
		}

		$nachlauf = (int) Settings::get( 'marketing_nachlauf_tage', 30 );
		$bis      = gmdate( 'Y-m-d', strtotime( $bis . ' +' . max( 0, $nachlauf ) . ' days' ) );

		// Nie in die Zukunft auswerten – sonst wirkt die Kampagne unfertig.
		$heute = gmdate( 'Y-m-d' );
		if ( $bis > $heute ) {
			$bis = $heute;
		}

		// Bei einer erst geplanten Kampagne liegt der Beginn hinter dem
		// gekappten Ende. Ein rückwärts laufender Zeitraum wäre kein leeres
		// Ergebnis, sondern eine sinnlose Abfrage.
		if ( $bis < $von ) {
			$bis = $von;
		}

		return array( 'von' => $von, 'bis' => $bis );
	}

	/**
	 * Umsatz der Marktkasse, der an dieser Kostenstelle hängt.
	 *
	 * @param int $kampagne_id Kostenstelle.
	 * @return array {summe, tage}
	 */
	public static function markt_umsatz( $kampagne_id ) {
		global $wpdb;
		$t = $wpdb->prefix . 'lw16_kasse_tage';

		$tage = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, markt_name, datum, umsatz_gesamt FROM {$t}
			 WHERE kampagne_id = %d AND status = 'abgeschlossen'
			 ORDER BY datum", // phpcs:ignore
			(int) $kampagne_id
		) );

		$summe = 0.0;
		foreach ( $tage as $tag ) {
			$summe += (float) $tag->umsatz_gesamt;
		}

		return array( 'summe' => round( $summe, 2 ), 'tage' => $tage );
	}

	/**
	 * Shop-Bestellungen, die dieser Kostenstelle zuzurechnen sind.
	 *
	 * Zwei Nachweise zählen: die Kampagnenkennung, die WooCommerce selbst an
	 * die Bestellung schreibt, und ein Gutscheincode, den es nur bei dieser
	 * Kampagne gab. Eine Bestellung, auf die beides zutrifft, zählt einmal.
	 *
	 * @param object $kampagne Kostenstelle.
	 * @return array {summe, anzahl, per_utm, per_gutschein}
	 */
	public static function shop_umsatz( $kampagne ) {
		$utm      = strtolower( trim( (string) $kampagne->utm_campaign ) );
		$gutschein = strtolower( trim( (string) $kampagne->gutschein_code ) );

		$leer = array( 'summe' => 0.0, 'anzahl' => 0, 'per_utm' => 0, 'per_gutschein' => 0 );

		if ( '' === $utm && '' === $gutschein ) {
			return $leer;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $leer;
		}

		$zeitraum = self::zeitraum( $kampagne );

		$orders = wc_get_orders(
			array(
				'status'       => array( 'wc-completed', 'wc-processing' ),
				'date_created' => $zeitraum['von'] . '...' . $zeitraum['bis'],
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		$summe         = 0.0;
		$anzahl        = 0;
		$per_utm       = 0;
		$per_gutschein = 0;

		foreach ( $orders as $order ) {
			$treffer_utm = false;
			$treffer_gut = false;

			if ( '' !== $utm ) {
				$order_utm = strtolower( trim( (string) $order->get_meta( self::META_UTM_CAMPAIGN ) ) );
				$treffer_utm = ( '' !== $order_utm && $order_utm === $utm );
			}

			if ( '' !== $gutschein ) {
				foreach ( $order->get_coupon_codes() as $code ) {
					if ( strtolower( trim( (string) $code ) ) === $gutschein ) {
						$treffer_gut = true;
						break;
					}
				}
			}

			if ( ! $treffer_utm && ! $treffer_gut ) {
				continue;
			}

			$summe += (float) $order->get_total() - (float) $order->get_total_refunded();
			$anzahl++;
			if ( $treffer_utm ) {
				$per_utm++;
			}
			if ( $treffer_gut ) {
				$per_gutschein++;
			}
		}

		return array(
			'summe'         => round( $summe, 2 ),
			'anzahl'        => $anzahl,
			'per_utm'       => $per_utm,
			'per_gutschein' => $per_gutschein,
		);
	}

	/**
	 * Vollständige Auswertung einer Kostenstelle.
	 *
	 * @param object $kampagne Kostenstelle.
	 * @return array
	 */
	public static function report( $kampagne ) {
		$kosten = KampagnenRepository::kosten( (int) $kampagne->id );
		$markt  = self::markt_umsatz( (int) $kampagne->id );
		$shop   = self::shop_umsatz( $kampagne );

		$umsatz = round( $markt['summe'] + $shop['summe'], 2 );

		return array(
			'kampagne'      => $kampagne,
			'zeitraum'      => self::zeitraum( $kampagne ),
			'kosten'        => $kosten['summe'],
			'kosten_konten' => $kosten['nach_konto'],
			'markt'         => $markt,
			'shop'          => $shop,
			'umsatz'        => $umsatz,
			'budget'        => Kampagnenrechnung::budget_status( $kampagne->budget_geplant, $kosten['summe'] ),
			'roas'          => Kampagnenrechnung::roas( $umsatz, $kosten['summe'] ),
			'cpo'           => Kampagnenrechnung::kosten_pro_bestellung( $kosten['summe'], $shop['anzahl'] ),
			'ergebnis'      => Kampagnenrechnung::ergebnis( $umsatz, $kosten['summe'] ),
		);
	}
}
