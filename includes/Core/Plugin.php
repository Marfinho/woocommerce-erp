<?php
/**
 * Zentraler Orchestrator: bootet alle Module.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Admin\Menu;
use Lindenweg16\Admin\SettingsPage;
use Lindenweg16\Modules\Inventory\InventoryModule;
use Lindenweg16\Modules\Accounting\AccountingModule;
use Lindenweg16\Modules\Loyalty\LoyaltyModule;
use Lindenweg16\Modules\Pricing\PricingModule;
use Lindenweg16\Modules\Production\ProductionModule;
use Lindenweg16\Modules\Retention\RetentionModule;
use Lindenweg16\Modules\Support\SupportModule;

/**
 * Singleton, das den Lebenszyklus des Plugins verwaltet.
 */
class Plugin {

	/**
	 * Singleton-Instanz.
	 *
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Registrierte Modul-Objekte.
	 *
	 * @var array
	 */
	protected $modules = array();

	/**
	 * Instanz holen.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Plugin starten.
	 */
	public function boot() {
		// Schema-Upgrades on-the-fly (falls Version sich geändert hat).
		Installer::maybe_upgrade();

		// Hintergrundjobs sicherstellen.
		add_action( 'init', array( Scheduler::class, 'ensure_recurring' ) );

		// Admin-Grundgerüst.
		if ( is_admin() ) {
			( new Menu() )->register();
			( new SettingsPage() )->register();
		}

		// Module registrieren. Jedes Modul kapselt seine eigenen Hooks.
		$this->modules = array(
			'inventory'  => new InventoryModule(),
			'accounting' => new AccountingModule(),
			'loyalty'    => new LoyaltyModule(),
			'pricing'    => new PricingModule(),
			'production' => new ProductionModule(),
			'retention'  => new RetentionModule(),
			'support'    => new SupportModule(),
		);

		foreach ( $this->modules as $module ) {
			if ( method_exists( $module, 'register' ) ) {
				$module->register();
			}
		}

		do_action( 'lw16_booted', $this );
	}

	/**
	 * Ein Modul-Objekt holen.
	 *
	 * @param string $key Modul-Schlüssel.
	 * @return object|null
	 */
	public function module( $key ) {
		return isset( $this->modules[ $key ] ) ? $this->modules[ $key ] : null;
	}
}
