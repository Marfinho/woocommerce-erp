<?php
/**
 * Die Haken der Buchhaltung – ohne die Buchhaltung zu laden.
 *
 * `AccountingModule` ist rund fünfzehnhundert Zeilen lang. Das Frontend
 * braucht davon die Statuswechsel einer Bestellung, zwei Hintergrundjobs
 * und einen Link in der Bestellübersicht des Kundenkontos. Alles vier
 * sind seltene Ereignisse, und für sie die ganze Buchhaltung einzulesen,
 * war reine Verschwendung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Lazy;
use Lindenweg16\Core\Scheduler;

/**
 * Meldet die Haken des Moduls an.
 */
class AccountingHooks {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		$modul = AccountingModule::class;

		// Automatische Rechnungserstellung: bei Abschluss asynchron einreihen.
		Lazy::action( 'woocommerce_order_status_completed', $modul, 'enqueue_invoice', 30, 1 );
		Lazy::action( Scheduler::HOOK_GENERATE_INVOICE, $modul, 'generate_invoice', 10, 1 );

		// Zahlungsgebühr übernehmen. Bewusst an mehreren Statuswechseln: Die
		// Gebühr schreibt der Zahlungsdienstleister mal beim Bezahlen, mal
		// erst Tage später in eine Notiz. Die Buchung selbst ist gegen
		// Doppelung gesichert, also schadet ein Aufruf zu viel nicht.
		Lazy::action( 'woocommerce_order_status_processing', $modul, 'capture_order_fee', 40, 1 );
		Lazy::action( 'woocommerce_order_status_completed', $modul, 'capture_order_fee', 40, 1 );
		Lazy::action( 'woocommerce_payment_complete', $modul, 'capture_order_fee', 40, 1 );
		Lazy::action( 'woocommerce_order_note_added', $modul, 'capture_fee_from_note', 10, 2 );

		// Nachlauf: WooPayments trägt die Gebühr per Webhook nach, oft
		// Stunden nach dem Kauf und ohne dass ein Status wechselt oder eine
		// Notiz entsteht. Ohne diesen täglichen Nachlauf bliebe sie liegen.
		Lazy::action( Scheduler::HOOK_DAILY_FEES, $modul, 'sync_recent_fees' );

		// Dauerbuchungen automatisch ausführen.
		Lazy::action( Scheduler::HOOK_RECURRING_BOOKINGS, $modul, 'run_recurring_bookings' );

		// Rechnung im Kundenkonto verlinken.
		Lazy::filter( 'woocommerce_my_account_my_orders_actions', $modul, 'account_order_invoice_action', 10, 2 );

		if ( is_admin() ) {
			Lazy::instance( $modul )->register_admin();
		}
	}
}
