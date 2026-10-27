<?php
/**
 * Zentraler Orchestrator: bootet alle Module.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Admin\Menu;
use Lindenweg16\Admin\MenuGroups;
use Lindenweg16\Admin\SettingsPage;
use Lindenweg16\Admin\ResetPage;
use Lindenweg16\Admin\Dashboard;
use Lindenweg16\Admin\GlobalSearch;
use Lindenweg16\Admin\HandbuchPage;
use Lindenweg16\Erp\Shell as ErpShell;
use Lindenweg16\Modules\Inventory\InventoryModule;
use Lindenweg16\Modules\Accounting\AccountingModule;
use Lindenweg16\Modules\Loyalty\LoyaltyModule;
use Lindenweg16\Modules\Production\ProductionModule;
use Lindenweg16\Modules\Retention\RetentionModule;
use Lindenweg16\Modules\Support\SupportModule;
use Lindenweg16\Modules\POS\POSModule;
use Lindenweg16\Modules\Marketing\MarketingModule;
use Lindenweg16\Modules\Events\EventsModule;
use Lindenweg16\Modules\Betrieb\BetriebModule;
use Lindenweg16\Modules\Configurator\ConfiguratorModule;
use Lindenweg16\Modules\ArView\ArViewModule;
use Lindenweg16\Modules\Mailbox\MailboxModule;
use Lindenweg16\Modules\Gutschein\GutscheinModule;
use Lindenweg16\Modules\Kurzlinks\KurzlinksModule;

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
		Scheduler::watch_failures();

		// Einmaliger Rewrite-Flush nach Aktivierung.
		add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );

		// Auskunft und Löschung nach DSGVO. Gilt im Frontend wie im Backend:
		// WordPress ruft die Rueckrufe aus dem Adminbereich auf, meldet sie
		// aber ueber Filter an, die frueh gesetzt sein muessen.
		( new Privacy() )->register();

		// Updates für Plugin und Theme aus GitHub über die WordPress-Update-Funktion.
		// Auch außerhalb des Adminbereichs: WordPress prüft per Cron.
		( new Updater() )->register();

		// Vollzugriff schließt die Modulrechte ein (siehe Roles::MODULE_CAPS).
		add_filter( 'user_has_cap', array( Roles::class, 'grant_module_caps' ) );

		// Eigene ERP-Oberfläche unter /erp (zweiter Zugang neben wp-admin).
		( new ErpShell() )->register();
		( new \Lindenweg16\Erp\Traffic() )->register();
		\Lindenweg16\Erp\Push::init();

		// Admin-Grundgerüst.
		if ( is_admin() ) {
			( new MenuGroups() )->register();
			( new Menu() )->register();
			( new SettingsPage() )->register();
			( new ResetPage() )->register();
			( new Dashboard() )->register();
			( new GlobalSearch() )->register();
			( new HandbuchPage() )->register();
		}

		// Module registrieren. Jedes Modul kapselt seine eigenen Hooks.
		$this->modules = array(
			'inventory'  => new InventoryModule(),
			'accounting' => new AccountingModule(),
			'loyalty'    => new LoyaltyModule(),
			'production' => new ProductionModule(),
			'retention'  => new RetentionModule(),
			'support'    => new SupportModule(),
			'pos'        => new POSModule(),
			'marketing'  => new MarketingModule(),
			'events'     => new EventsModule(),
			'betrieb'    => new BetriebModule(),
			'konfigurator' => new ConfiguratorModule(),
			'arview'     => new ArViewModule(),
			'mailbox'    => new MailboxModule(),
			'gutschein'  => new GutscheinModule(),
			'kurzlinks'  => new KurzlinksModule(),
		);

		foreach ( $this->modules as $key => $module ) {
			// Das alte Lager-Modul ist stillgelegt, sobald Betrieb alle seine
			// Produkte übernommen hat (Verwaltung → Farben & Lager).
			if ( 'inventory' === $key && \Lindenweg16\Modules\Betrieb\StockOwnership::legacy_disabled() ) {
				continue;
			}

			if ( method_exists( $module, 'register' ) ) {
				$module->register();
			}
		}

		do_action( 'lw16_booted', $this );
	}

	/**
	 * Einmaliger Rewrite-Flush nach Aktivierung, damit Endpoints funktionieren.
	 */
	public function maybe_flush_rewrite() {
		if ( get_option( 'lw16_flush_rewrite' ) ) {
			delete_option( 'lw16_flush_rewrite' );
			flush_rewrite_rules();
		}
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
