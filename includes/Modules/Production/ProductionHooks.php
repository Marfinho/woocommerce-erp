<?php
/**
 * Die Haken der Produktion.
 *
 * Packliste und Druckaufträge hängen an seltenen Ereignissen und werden
 * deshalb verzögert angemeldet. Die Sendungsverfolgung nicht: Sie greift
 * in jeden Aufruf des Kundenkontos ein und muss dafür bereitstehen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Production;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Lazy;
use Lindenweg16\Core\Scheduler;

/**
 * Meldet die Haken des Moduls an.
 */
class ProductionHooks {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Packliste automatisch bei Status processing (asynchron).
		Lazy::action( 'woocommerce_order_status_processing', ProductionModule::class, 'enqueue_packing_slip', 20, 1 );
		Lazy::action( Scheduler::HOOK_GENERATE_PACKING, ProductionModule::class, 'generate_packing_slip', 10, 1 );

		// DHL-Sendungsverfolgung: zeigt dem Kunden seinen Stand im Konto und
		// meldet sich dafür bei jedem Aufruf an.
		( new DhlTracking() )->register();

		if ( is_admin() ) {
			Lazy::instance( ProductionModule::class )->register_admin();
		}
	}
}
