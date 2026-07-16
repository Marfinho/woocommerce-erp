<?php
/**
 * Kernlogik der Lagerbewegungen: Verbuchung, Kaskadierung, Ampel.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Inventory;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Core\Settings;
use Lindenweg16\Core\MailTemplate;

/**
 * Verbucht Lagerbewegungen und aktualisiert den Bestand konsistent.
 */
class StockManager {

	/**
	 * Eine Lagerbewegung verbuchen und den Bestand fortschreiben.
	 * Positive Menge = Zugang, negative Menge = Abgang.
	 *
	 * @param int    $produkt_id Produkt-ID.
	 * @param float  $menge      Signierte Menge in Basis-Einheit.
	 * @param string $typ        wareneingang|produktion|verkauf|korrektur.
	 * @param array  $args       Optional: referenz, beleg_id, notiz.
	 * @return int Bewegungs-ID.
	 */
	public static function record_movement( $produkt_id, $menge, $typ, array $args = array() ) {
		global $wpdb;

		$produkt_id  = (int) $produkt_id;
		$menge       = (float) $menge;
		$lagerort_id = isset( $args['lagerort_id'] ) ? (int) $args['lagerort_id'] : null;

		$bestand_vorher = Repository::get_stock( $produkt_id );

		$wpdb->insert(
			Repository::table( 'lagerbewegung' ),
			array(
				'produkt_id'   => $produkt_id,
				'lagerort_id'  => $lagerort_id,
				'menge'        => $menge,
				'typ'          => $typ,
				'referenz'     => isset( $args['referenz'] ) ? $args['referenz'] : null,
				'beleg_id'     => isset( $args['beleg_id'] ) ? (int) $args['beleg_id'] : null,
				'notiz'        => isset( $args['notiz'] ) ? $args['notiz'] : null,
				'erstellt_von' => get_current_user_id(),
				'erstellt_am'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%f', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
		$bewegung_id = (int) $wpdb->insert_id;

		$neuer_bestand = $bestand_vorher + $menge;
		Repository::set_stock( $produkt_id, $neuer_bestand );

		if ( $lagerort_id ) {
			$ort_bestand = Repository::get_stock_at_location( $produkt_id, $lagerort_id );
			Repository::set_stock_at_location( $produkt_id, $lagerort_id, $ort_bestand + $menge );
		}

		// Mindestbestand-Prüfung bei Abgang.
		if ( $menge < 0 ) {
			self::maybe_notify_low_stock( $produkt_id, $neuer_bestand );
		}

		// Warteliste („wieder verfügbar") bei Zugang über 0 auslösen.
		if ( $menge > 0 && $bestand_vorher <= 0 && $neuer_bestand > 0 ) {
			do_action( 'lw16_stock_replenished', $produkt_id, $neuer_bestand );
		}

		do_action( 'lw16_movement_recorded', $produkt_id, $menge, $typ, $bewegung_id );

		return $bewegung_id;
	}

	/**
	 * Verkauf eines Produkts kaskadierend abbuchen.
	 * Set -> Module (Stückliste) -> Rohmaterial (Rezeptur).
	 *
	 * @param int    $produkt_id Verkauftes Produkt (set oder modul).
	 * @param float  $menge      Verkaufte Menge.
	 * @param string $referenz   Bestellreferenz.
	 */
	public static function process_sale( $produkt_id, $menge, $referenz ) {
		$produkt = Repository::get_product( $produkt_id );
		if ( ! $produkt ) {
			return;
		}

		if ( 'set' === $produkt->typ ) {
			// Set löst sich in seine Module auf.
			foreach ( Repository::get_stueckliste( $produkt_id ) as $pos ) {
				self::process_sale( (int) $pos->modul_id, $menge * (float) $pos->menge, $referenz );
			}
			return;
		}

		if ( 'modul' === $produkt->typ ) {
			// Modul-Bestand abbuchen ...
			self::record_movement(
				$produkt_id,
				-1 * abs( $menge ),
				'verkauf',
				array( 'referenz' => $referenz )
			);
			// ... und den Rohmaterial-Verbrauch laut Rezeptur.
			foreach ( Repository::get_rezeptur( $produkt_id ) as $rez ) {
				self::record_movement(
					(int) $rez->rohmaterial_id,
					-1 * abs( $menge ) * (float) $rez->menge,
					'verkauf',
					array( 'referenz' => $referenz )
				);
			}
			return;
		}

		// Rohmaterial direkt (falls direkt verkauft würde).
		self::record_movement( $produkt_id, -1 * abs( $menge ), 'verkauf', array( 'referenz' => $referenz ) );
	}

	/**
	 * Ampel-Status eines Produkts anhand des Mindestbestands.
	 *
	 * @param object $produkt Produktobjekt.
	 * @return string gruen|gelb|rot.
	 */
	public static function ampel( $produkt ) {
		$bestand = Repository::get_stock( $produkt->id );
		$min     = (float) $produkt->mindestbestand;

		if ( $min <= 0 ) {
			return 'gruen';
		}
		if ( $bestand <= 0 ) {
			return 'rot';
		}
		if ( $bestand < $min ) {
			return 'rot';
		}
		if ( $bestand < $min * 1.5 ) {
			return 'gelb';
		}
		return 'gruen';
	}

	/**
	 * Bei Unterschreitung des Mindestbestands die Manager benachrichtigen.
	 * Doppelbenachrichtigung per Transient-Dämpfer vermeiden.
	 *
	 * @param int   $produkt_id    Produkt-ID.
	 * @param float $neuer_bestand Aktueller Bestand.
	 */
	protected static function maybe_notify_low_stock( $produkt_id, $neuer_bestand ) {
		$produkt = Repository::get_product( $produkt_id );
		if ( ! $produkt || (float) $produkt->mindestbestand <= 0 ) {
			return;
		}
		if ( $neuer_bestand >= (float) $produkt->mindestbestand ) {
			return;
		}

		$lock_key = 'lw16_lowstock_' . $produkt_id;
		if ( get_transient( $lock_key ) ) {
			return; // In den letzten 24h schon gemeldet.
		}
		set_transient( $lock_key, 1, DAY_IN_SECONDS );

		$emails = Roles::get_manager_emails();
		if ( empty( $emails ) ) {
			return;
		}

		MailTemplate::send( $emails, 'mindestbestand', array(
			'{produktname}'    => $produkt->name,
			'{bestand}'        => rtrim( rtrim( number_format( (float) $neuer_bestand, 2, ',', '.' ), '0' ), ',' ),
			'{mindestbestand}' => rtrim( rtrim( number_format( (float) $produkt->mindestbestand, 2, ',', '.' ), '0' ), ',' ),
			'{einheit}'        => $produkt->basis_einheit,
		) );
	}
}
