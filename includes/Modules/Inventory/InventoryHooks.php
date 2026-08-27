<?php
/**
 * Die Haken des Lagermoduls – ohne das Modul zu laden.
 *
 * `InventoryModule` ist über siebzehnhundert Zeilen lang, und das meiste
 * davon sind Bildschirmmasken und Formularempfänger. Im Frontend wird
 * davon genau nichts gebraucht: Dort zählen zwei seltene Ereignisse, eine
 * abgeschlossene Bestellung und der tägliche Bestandsbericht. Beide melden
 * sich hier über {@see Lazy} an, das die Klasse erst lädt, wenn sie
 * wirklich dran ist.
 *
 * Im Backend wird sie wie gehabt gebaut und meldet ihre Masken selbst an.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Inventory;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Lazy;
use Lindenweg16\Core\Scheduler;

/**
 * Meldet die Haken des Moduls an.
 */
class InventoryHooks {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Echtzeit-Lagerabzug bei abgeschlossener Bestellung. Der Status
		// wechselt im Kundenkonto und per Webhook, also auch ohne Backend.
		Lazy::action( 'woocommerce_order_status_completed', InventoryModule::class, 'deduct_stock_for_order', 20, 1 );

		// Täglicher Bestandsreport über den Action Scheduler.
		Lazy::action( Scheduler::HOOK_DAILY_STOCK, InventoryModule::class, 'send_daily_stock_report' );

		if ( is_admin() ) {
			Lazy::instance( InventoryModule::class )->register_admin();
		}
	}
}
