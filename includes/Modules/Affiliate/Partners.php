<?php
/**
 * Affiliates: Speicher der Partner und Auszahlungen, Abgleich mit dem WooCommerce-Gutschein.
 *
 * Jeder Partner hat genau einen Rabattcode. Der Code ist ein ganz normaler
 * WooCommerce-Gutschein (Prozent auf den Warenkorb), damit Warenkorb, Kasse und
 * Rechnung ihn ohne Zutun kennen. Die Verbindung steht im Gutschein
 * (`_lw16_affiliate_id`) und im Partner (`coupon_id`).
 *
 * Partner werden nie gelöscht, nur abgeschaltet: Der Code steht in Videos und
 * Beschreibungen, und die Auswertung vergangener Monate braucht den Partner.
 * Auszahlungen werden nur angehängt; ein Fehler wird mit einer Korrektur
 * (negativer Betrag mit Notiz) berichtigt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Affiliate;

defined( 'ABSPATH' ) || exit;

/**
 * Partner und Auszahlungen.
 */
class Partners {

	const OPTION_PARTNERS = 'lw16_affiliates';
	const OPTION_PAYOUTS  = 'lw16_affiliate_payouts';
	const META_COUPON     = '_lw16_affiliate_id';

	/**
	 * Alle Partner.
	 *
	 * @return array Id => Partner.
	 */
	public static function all() {
		$stored = get_option( self::OPTION_PARTNERS, array() );
		$out    = array();

		foreach ( is_array( $stored ) ? $stored : array() as $p ) {
			if ( ! is_array( $p ) || empty( $p['id'] ) || empty( $p['code'] ) ) {
				continue;
			}

			$out[ (int) $p['id'] ] = array_merge(
				array(
					'name'      => '',
					'plattform' => '',
					'email'     => '',
					'rabatt'    => 0.0,
					'provision' => 0.0,
					'aktiv'     => true,
					'notiz'     => '',
					'angelegt'  => '',
					'coupon_id' => 0,
				),
				$p,
				array( 'id' => (int) $p['id'] )
			);
		}

		return $out;
	}

	/**
	 * Einen Partner holen.
	 *
	 * @param int $id Kennung.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();

		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/**
	 * Gehört der Code (egal ob groß oder klein) schon einem Partner?
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function code_used( $code ) {
		foreach ( self::all() as $p ) {
			if ( 0 === strcasecmp( $p['code'], $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Neuen Partner anlegen und den Gutschein dazu erzeugen.
	 *
	 * @param array $in Eingaben, siehe Rules::clean_partner().
	 * @return array [ 'ok' => bool, 'errors' => Meldungen, 'id' => int ].
	 */
	public static function create( array $in ) {
		$clean  = Rules::clean_partner( $in, true );
		$errors = $clean['errors'];
		$row    = $clean['row'];

		if ( ! $errors ) {
			if ( self::code_used( $row['code'] ) || ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $row['code'] ) ) ) {
				$errors[] = __( 'Diesen Code gibt es schon – als Affiliate oder als anderen Gutschein im Shop. Bitte einen anderen wählen.', 'lindenweg16' );
			}
		}

		if ( $errors ) {
			return array(
				'ok'     => false,
				'errors' => $errors,
				'id'     => 0,
			);
		}

		$all      = self::all();
		$id       = $all ? max( array_keys( $all ) ) + 1 : 1;
		$row      = array_merge(
			$row,
			array(
				'id'        => $id,
				'angelegt'  => wp_date( 'Y-m-d' ),
				'coupon_id' => 0,
			)
		);
		$coupon   = self::sync_coupon( $row );
		$row['coupon_id'] = $coupon;

		if ( $coupon <= 0 ) {
			return array(
				'ok'     => false,
				'errors' => array( __( 'Der Gutschein im Shop konnte nicht angelegt werden. WooCommerce ist nicht erreichbar.', 'lindenweg16' ) ),
				'id'     => 0,
			);
		}

		$all[ $id ] = $row;
		update_option( self::OPTION_PARTNERS, array_values( $all ), false );

		return array(
			'ok'     => true,
			'errors' => array(),
			'id'     => $id,
		);
	}

	/**
	 * Einen bestehenden Partner ändern (der Code bleibt).
	 *
	 * @param int   $id Kennung.
	 * @param array $in Eingaben.
	 * @return array [ 'ok' => bool, 'errors' => Meldungen ].
	 */
	public static function update( $id, array $in ) {
		$all = self::all();

		if ( ! isset( $all[ (int) $id ] ) ) {
			return array(
				'ok'     => false,
				'errors' => array( __( 'Diesen Affiliate gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ) ),
			);
		}

		$clean = Rules::clean_partner( $in, false );
		if ( $clean['errors'] ) {
			return array(
				'ok'     => false,
				'errors' => $clean['errors'],
			);
		}

		$merged = array_merge( $all[ (int) $id ], $clean['row'] );
		$merged['coupon_id'] = self::sync_coupon( $merged );

		$all[ (int) $id ] = $merged;
		update_option( self::OPTION_PARTNERS, array_values( $all ), false );

		return array(
			'ok'     => true,
			'errors' => array(),
		);
	}

	/**
	 * Gutschein im Shop anlegen oder angleichen: Rabatt in Prozent und Status.
	 *
	 * @param array $p Partner.
	 * @return int Gutschein-Kennung oder 0.
	 */
	public static function sync_coupon( array $p ) {
		if ( ! class_exists( '\WC_Coupon' ) ) {
			return 0;
		}

		$existing = function_exists( 'wc_get_coupon_id_by_code' ) ? (int) wc_get_coupon_id_by_code( $p['code'] ) : 0;
		$coupon   = new \WC_Coupon( $existing );

		if ( ! $existing ) {
			$coupon->set_code( $p['code'] );
			$coupon->set_discount_type( 'percent' );
		}

		$coupon->set_amount( $p['rabatt'] );
		$coupon->set_status( $p['aktiv'] ? 'publish' : 'draft' );
		/* translators: 1: Name, 2: Kanal. */
		$coupon->set_description( sprintf( __( 'Affiliate: %1$s %2$s', 'lindenweg16' ), $p['name'], $p['plattform'] ? '(' . $p['plattform'] . ')' : '' ) );
		$coupon->update_meta_data( self::META_COUPON, (int) $p['id'] );

		return (int) $coupon->save();
	}

	/**
	 * Alle Auszahlungen, älteste zuerst.
	 *
	 * @return array
	 */
	public static function payouts() {
		$stored = get_option( self::OPTION_PAYOUTS, array() );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
	}

	/**
	 * Eine Auszahlung anhängen.
	 *
	 * @param int    $partner Kennung.
	 * @param float  $betrag  Betrag in Euro (negativ = Korrektur).
	 * @param string $datum   Datum Y-m-d.
	 * @param string $notiz   Notiz.
	 * @return array Die gespeicherte Zeile.
	 */
	public static function add_payout( $partner, $betrag, $datum, $notiz ) {
		$list   = self::payouts();
		$ids    = array_map(
			function ( $r ) {
				return (int) $r['id'];
			},
			$list
		);
		$row    = array(
			'id'      => $ids ? max( $ids ) + 1 : 1,
			'partner' => (int) $partner,
			'betrag'  => round( (float) $betrag, 2 ),
			'datum'   => $datum,
			'notiz'   => $notiz,
			'user'    => get_current_user_id(),
		);
		$list[] = $row;

		update_option( self::OPTION_PAYOUTS, $list, false );

		return $row;
	}
}
