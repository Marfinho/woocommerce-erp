<?php
/**
 * Modul Affiliates: Influencer mit eigenem Rabattcode.
 *
 * - Rules.php     Prüfen und Rechnen (rein),
 * - Partners.php  Partner, Auszahlungen und der Gutschein im Shop,
 * - Sales.php     Bestellungen mit Affiliate-Code,
 * - Account.php   „Ambassador“ unter „Mein Konto“ (Zahlen zum eigenen Code, Auszahlung anfordern),
 * - AutoApply.php Link mit Code (`?aff=CODE`), der den Rabatt von selbst anwendet,
 * - Guard.php     Eigenkäufe mit dem eigenen Code verhindern,
 * - Booking.php   Auszahlungen als Beleg buchen (Werbekosten, eigene Kostenstelle),
 * - Seite im ERP: `Erp\Pages\Affiliates` (Verkauf → Marketing → Affiliates).
 *
 * Dieses Modul hält nur einen Haken: Sobald eine Bestellung mit Affiliate-Code
 * bezahlt ist, merkt sie sich den Provisionssatz von damals. Eine spätere Änderung
 * des Satzes wirkt so nur auf neue Bestellungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Affiliate;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;

/**
 * Hooks des Moduls.
 */
class AffiliateModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		( new Account() )->register();
		( new AutoApply() )->register();
		( new Guard() )->register();

		// Die Rolle „Ambassador“ gibt es auch auf Installationen, die vor ihr eingerichtet wurden.
		add_action( 'init', array( Roles::class, 'add_ambassador_role' ), 20 );
		add_action( 'delete_user', array( Partners::class, 'forget_user' ) );

		add_action( 'woocommerce_order_status_processing', array( $this, 'freeze_rate' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'freeze_rate' ), 20, 1 );
	}

	/**
	 * Provisionssatz an der Bestellung festhalten (nur beim ersten Mal).
	 *
	 * @param int $order_id Bestellung.
	 */
	public function freeze_rate( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || '' !== (string) $order->get_meta( Sales::META_RATE ) ) {
			return;
		}

		$used = array_map( 'strtolower', (array) $order->get_coupon_codes() );
		if ( ! $used ) {
			return;
		}

		foreach ( Partners::all() as $p ) {
			if ( in_array( strtolower( $p['code'] ), $used, true ) ) {
				$order->update_meta_data( Sales::META_RATE, $p['provision'] );
				$order->update_meta_data( Sales::META_PARTNER, $p['id'] );
				$order->save_meta_data();
				return;
			}
		}
	}
}
