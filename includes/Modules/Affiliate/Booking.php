<?php
/**
 * Affiliates: Auszahlungen in der Buchhaltung buchen.
 *
 * Jede Auszahlung wird ein festgeschriebener Eigenbeleg auf dem Konto Werbekosten und der
 * Kostenstelle des Affiliates. Die Kostenstelle (Kampagne) legt das ERP beim ersten Buchen an
 * und trägt den Code als Gutscheincode ein: Unter Marketing stehen dann Provision und
 * Umsatz dieses Influencers nebeneinander.
 *
 * Eine falsche Auszahlung wird storniert (Gegenbeleg), nie gelöscht.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Affiliate;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Accounting\Kontenrahmen;
use Lindenweg16\Modules\Marketing\KampagnenRepository;

/**
 * Buchen und Stornieren.
 */
class Booking {

	/**
	 * Soll gebucht werden? Einstellung „Auszahlungen automatisch als Beleg buchen“.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) \Lindenweg16\Core\Settings::get( 'affiliate_auto_buchen', 1 );
	}

	/**
	 * Kostenstelle des Affiliates: bestehende nehmen oder beim ersten Mal anlegen.
	 *
	 * @param array $p Partner.
	 * @return int Kennung oder 0.
	 */
	protected static function kampagne( array $p ) {
		if ( ! empty( $p['kampagne_id'] ) && KampagnenRepository::get( (int) $p['kampagne_id'] ) ) {
			return (int) $p['kampagne_id'];
		}

		$id = (int) KampagnenRepository::create(
			array(
				/* translators: %s: Name des Affiliates. */
				'name'           => sprintf( __( 'Affiliate: %s', 'lindenweg16' ), $p['name'] ),
				'typ'            => 'sonstiges',
				'kanal'          => $p['plattform'],
				'start_datum'    => '' !== (string) $p['angelegt'] ? $p['angelegt'] : gmdate( 'Y-m-d' ),
				'gutschein_code' => $p['code'],
				'status'         => 'aktiv',
				'notiz'          => __( 'Vom ERP angelegt: Provisionen dieses Affiliates.', 'lindenweg16' ),
			)
		);

		if ( $id > 0 ) {
			Partners::set_kampagne( (int) $p['id'], $id );
		}

		return $id;
	}

	/**
	 * Eine Auszahlung als Beleg buchen.
	 *
	 * @param array  $p      Partner.
	 * @param float  $betrag Betrag in Euro (positiv).
	 * @param string $datum  Datum Y-m-d.
	 * @return int Beleg-Kennung oder 0, wenn nicht gebucht werden konnte.
	 */
	public static function book( array $p, $betrag, $datum ) {
		$beleg = (int) BelegRepository::create(
			array(
				'betrag'        => round( (float) $betrag, 2 ),
				'datum'         => $datum,
				/* translators: 1: Name, 2: Code. */
				'haendler'      => sprintf( __( 'Affiliate-Provision: %1$s (%2$s)', 'lindenweg16' ), $p['name'], $p['code'] ),
				'konto'         => Kontenrahmen::KONTO_WERBUNG,
				'eur_kategorie' => 'Werbekosten',
				'notiz'         => __( 'Provision laut Affiliate-Auswertung. Rechnung oder Gutschrift des Influencers zum Beleg nehmen.', 'lindenweg16' ),
				'quelle'        => 'affiliate',
			)
		);

		if ( $beleg <= 0 ) {
			return 0;
		}

		BelegRepository::festschreiben( $beleg );

		$kampagne = self::kampagne( $p );
		if ( $kampagne > 0 ) {
			KampagnenRepository::zuordnen( $kampagne, $beleg, 100 );
		}

		return $beleg;
	}

	/**
	 * Den Beleg einer stornierten Auszahlung zurücknehmen.
	 *
	 * @param array  $p      Partner.
	 * @param int    $beleg  Beleg-Kennung.
	 * @param string $grund  Begründung.
	 * @return int Kennung des Gegenbelegs oder 0.
	 */
	public static function cancel( array $p, $beleg, $grund ) {
		if ( $beleg <= 0 ) {
			return 0;
		}

		if ( ! empty( $p['kampagne_id'] ) ) {
			KampagnenRepository::loesen( (int) $p['kampagne_id'], (int) $beleg );
		}

		return (int) BelegRepository::stornieren( (int) $beleg, $grund );
	}
}
