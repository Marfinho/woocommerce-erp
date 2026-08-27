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
use Lindenweg16\Admin\ResetPage;
use Lindenweg16\Admin\Dashboard;
use Lindenweg16\Admin\GlobalSearch;
use Lindenweg16\Modules\Inventory\InventoryHooks;
use Lindenweg16\Modules\Accounting\AccountingHooks;
use Lindenweg16\Modules\Loyalty\LoyaltyModule;
use Lindenweg16\Modules\Pricing\PricingModule;
use Lindenweg16\Modules\Production\ProductionHooks;
use Lindenweg16\Modules\Retention\RetentionModule;
use Lindenweg16\Modules\Support\SupportModule;
use Lindenweg16\Modules\POS\POSModule;
use Lindenweg16\Modules\Betrieb\BetriebModule;
use Lindenweg16\Modules\Configurator\ConfiguratorModule;

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

		// Einmaliger Rewrite-Flush nach Aktivierung.
		add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );

		// Auskunft und Löschung nach DSGVO. Gilt im Frontend wie im Backend:
		// WordPress ruft die Rueckrufe aus dem Adminbereich auf, meldet sie
		// aber ueber Filter an, die frueh gesetzt sein muessen.
		( new Privacy() )->register();

		// Admin-Grundgerüst.
		if ( is_admin() ) {
			( new Menu() )->register();
			( new SettingsPage() )->register();
			( new ResetPage() )->register();
			( new Dashboard() )->register();
			( new GlobalSearch() )->register();
		}

		// Module registrieren. Jedes Modul kapselt seine eigenen Hooks.
		foreach ( $this->module_classes() as $key => $class ) {
			$this->modules[ $key ] = new $class();

			if ( method_exists( $this->modules[ $key ], 'register' ) ) {
				$this->modules[ $key ]->register();
			}
		}

		do_action( 'lw16_booted', $this );
	}

	/**
	 * Welche Module dieser Aufruf überhaupt braucht.
	 *
	 * Kasse und Preiskalkulation sind reine Backend-Werkzeuge: Sie hängen
	 * ausschließlich an `lw16_render_page_*`, `admin_post_*` und
	 * `wp_ajax_*`. Alle drei laufen im Adminkontext – auch admin-post.php
	 * und admin-ajax.php setzen `is_admin()`. Ein Besucher auf einer
	 * Produktseite hat also nichts davon, zahlt aber für das Laden und
	 * Auswerten der Klassen. Deshalb bleiben sie dort weg.
	 *
	 * Alles andere wird auch im Frontend gebraucht: Bestellungen wechseln
	 * ihren Status außerhalb des Backends, und der Action Scheduler
	 * arbeitet seine Warteschlange in gewöhnlichen Seitenaufrufen ab.
	 *
	 * Lager, Buchhaltung und Produktion sind dabei die dicksten Brocken –
	 * zusammen gut dreitausendsiebenhundert Zeilen, von denen das Frontend
	 * eine Handvoll Methoden braucht. Für sie steht hier eine schlanke
	 * Hooks-Klasse, die die eigentliche Modulklasse erst lädt, wenn einer
	 * ihrer Haken tatsächlich auslöst.
	 *
	 * @return array<string,string> Schlüssel => Klassenname.
	 */
	protected function module_classes() {
		$module_classes = array(
			'inventory'    => InventoryHooks::class,
			'accounting'   => AccountingHooks::class,
			'loyalty'      => LoyaltyModule::class,
			'production'   => ProductionHooks::class,
			'retention'    => RetentionModule::class,
			'support'      => SupportModule::class,
			'betrieb'      => BetriebModule::class,
			'konfigurator' => ConfiguratorModule::class,
		);

		if ( is_admin() ) {
			$module_classes['pricing'] = PricingModule::class;
			$module_classes['pos']     = POSModule::class;
		}

		return $module_classes;
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
	 * Im Frontend sind reine Backend-Module nicht gebootet; für sie kommt
	 * dort `null` zurück.
	 *
	 * @param string $key Modul-Schlüssel.
	 * @return object|null
	 */
	public function module( $key ) {
		return isset( $this->modules[ $key ] ) ? $this->modules[ $key ] : null;
	}
}
