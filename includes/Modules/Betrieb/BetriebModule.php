<?php
/**
 * Modul Betrieb: Erfassung der Fertigungs-, Lager- und Vertriebsdaten.
 *
 * Eigenes Hauptmenü, weil der Bereich fachlich neben der Buchhaltung steht
 * und im Alltag der häufigste Einstieg ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\Admin\ArticlesPage;
use Lindenweg16\Modules\Betrieb\Admin\DashboardPage;
use Lindenweg16\Modules\Betrieb\Admin\DemandPage;
use Lindenweg16\Modules\Betrieb\Admin\ImportPage;
use Lindenweg16\Modules\Betrieb\Admin\MaterialPage;
use Lindenweg16\Modules\Betrieb\Admin\OrdersPage;
use Lindenweg16\Modules\Betrieb\Admin\SimplePages;
use Lindenweg16\Modules\Betrieb\Admin\BinsPage;
use Lindenweg16\Modules\Betrieb\Admin\StockPage;
use Lindenweg16\Modules\Betrieb\Admin\PrintJobsPage;
use Lindenweg16\Modules\Betrieb\Admin\PrintersPage;
use Lindenweg16\Modules\Betrieb\Admin\SettingsPage;
use Lindenweg16\Modules\Betrieb\Admin\ShippingPage;
use Lindenweg16\Modules\Betrieb\Admin\TimerBar;
use Lindenweg16\Modules\Betrieb\Admin\UsersPage;

/**
 * Registriert Menü, Seiten und Formular-Handler des Moduls.
 */
class BetriebModule {

	const MENU_SLUG = 'lw16-betrieb';

	/**
	 * Untermenüs: Slug => [Titel, benötigte Capability].
	 *
	 * @return array
	 */
	public static function pages() {
		return array(
			self::MENU_SLUG            => array( __( 'Dashboard', 'lindenweg16' ), 'lw16_capture' ),
			'lw16-betrieb-bedarf'       => array( __( 'Bedarf', 'lindenweg16' ), 'lw16_manage_production' ),
			'lw16-betrieb-druckjournal' => array( __( 'Druckjournal', 'lindenweg16' ), 'lw16_capture' ),
			'lw16-betrieb-artikel'      => array( __( 'Artikel & Revisionen', 'lindenweg16' ), 'lw16_manage_production' ),
			'lw16-betrieb-drucker'      => array( __( 'Drucker & Wartung', 'lindenweg16' ), 'lw16_manage_production' ),
			'lw16-betrieb-konfigurator' => array( __( 'Konfigurator', 'lindenweg16' ), 'lw16_manage_production' ),
			'lw16-betrieb-kapazitaet'   => array( __( 'Kapazität', 'lindenweg16' ), 'lw16_manage_production' ),
			'lw16-betrieb-material'     => array( __( 'Material', 'lindenweg16' ), 'lw16_manage_production' ),
			'lw16-betrieb-lager'        => array( __( 'Lager', 'lindenweg16' ), 'lw16_capture' ),
			'lw16-betrieb-lagerplaetze' => array( __( 'Lagerplätze, Fertigung & Picklisten', 'lindenweg16' ), 'lw16_capture' ),
			'lw16-betrieb-bestellungen' => array( __( 'Bestellungen', 'lindenweg16' ), 'lw16_manage_production' ),
			'lw16-betrieb-versand'      => array( __( 'Versand', 'lindenweg16' ), 'lw16_capture' ),
			'lw16-betrieb-qualitaet'    => array( __( 'Qualität', 'lindenweg16' ), 'lw16_capture' ),
			'lw16-betrieb-kontakte'     => array( __( 'Kontakte / B2B', 'lindenweg16' ), 'lw16_manage_production' ),
			'lw16-betrieb-zeit'         => array( __( 'Zeiterfassung', 'lindenweg16' ), 'lw16_capture' ),
			'lw16-betrieb-berichte'     => array( __( 'Berichte', 'lindenweg16' ), 'lw16_view_reports' ),
			'lw16-betrieb-import'       => array( __( 'Import & Doku', 'lindenweg16' ), 'lw16_manage_settings' ),
			'lw16-betrieb-benutzer'     => array( __( 'Benutzer & Rechte', 'lindenweg16' ), 'lw16_manage_users' ),
			'lw16-betrieb-einstellungen' => array( __( 'Einstellungen', 'lindenweg16' ), 'lw16_manage_settings' ),
		);
	}

	/**
	 * Gibt es diese Seite im Modul?
	 *
	 * Reine Prüfung ohne Datenbank. Sie entscheidet, ob ein aus einem
	 * Formular mitgeschicktes Rücksprungziel übernommen werden darf –
	 * ungeprüft wäre das eine offene Weiterleitung.
	 *
	 * @param string $slug Seitenkennung.
	 * @return bool
	 */
	public static function is_page( $slug ) {
		return '' !== (string) $slug && array_key_exists( (string) $slug, self::pages() );
	}

	/**
	 * In welchem Meilenstein die jeweilige Seite Inhalt bekommt.
	 * Dient nur der ehrlichen Beschriftung noch leerer Seiten.
	 *
	 * @return array
	 */
	protected static function milestones() {
		return array();
	}

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Gilt auch im Frontend: Der Lagerabzug läuft beim Statuswechsel
		// einer Bestellung, nicht im Backend.
		( new StockOwnership() )->register();
		( new RestApi() )->register();
		( new MobileCapture() )->register();
		( new ReorderPage() )->register();
		( new WooIntegration() )->register();
		( new PriceSync() )->register();
		( new OrderMirror() )->register();
		( new WeeklyMail() )->register();
		( new Exporter() )->register();

		// Die Adminleiste steht auch im Frontend, wenn jemand angemeldet
		// ist – die Uhr soll dort genauso laufen.
		( new TimerBar() )->register();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			( new SettingsPage() )->register();
			( new PrintersPage() )->register();
			( new ArticlesPage() )->register();
			( new PrintJobsPage() )->register();
			( new StockPage() )->register();
			( new BinsPage() )->register();
			( new DemandPage() )->register();
			( new MaterialPage() )->register();
			( new OrdersPage() )->register();
			( new ShippingPage() )->register();
			( new SimplePages() )->register();
			( new ImportPage() )->register();
			( new UsersPage() )->register();
		}
	}

	/**
	 * Hauptmenü „Betrieb" mit Unterseiten aufbauen.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Betrieb', 'lindenweg16' ),
			__( 'Betrieb', 'lindenweg16' ),
			'lw16_capture',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-hammer',
			57
		);

		foreach ( self::pages() as $slug => $config ) {
			add_submenu_page(
				self::MENU_SLUG,
				$config[0],
				$config[0],
				$config[1],
				$slug,
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Assets nur auf den Betrieb-Seiten laden.
	 *
	 * @param string $hook Aktueller Admin-Hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'lw16-admin', LW16_PLUGIN_URL . 'assets/css/admin.css', array(), Assets::version( 'assets/css/admin.css' ) );
		wp_enqueue_style( 'lw16-betrieb', LW16_PLUGIN_URL . 'assets/css/betrieb.css', array( 'lw16-admin' ), Assets::version( 'assets/css/betrieb.css' ) );
	}

	/**
	 * Seitenrouting. Jede Seite prüft ihre eigene Capability.
	 */
	public function render_page() {
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::MENU_SLUG; // phpcs:ignore WordPress.Security.NonceVerification
		$pages  = self::pages();
		$config = isset( $pages[ $page ] ) ? $pages[ $page ] : null;

		if ( ! $config || ! Roles::current_user_can_betrieb( $config[1] ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		/**
		 * Module hängen sich hier mit ihrer Seitenausgabe ein.
		 * Gibt kein Listener aus, greift der Platzhalter unten.
		 */
		ob_start();
		do_action( 'lw16_betrieb_render_' . str_replace( '-', '_', $page ) );
		$output = ob_get_clean();

		if ( '' !== trim( (string) $output ) ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Die Listener escapen selbst.
			return;
		}

		if ( self::MENU_SLUG === $page ) {
			DashboardPage::render();
			return;
		}

		$this->render_placeholder( $config[0], $page );
	}

	/**
	 * Dashboard, solange die Kennzahlen noch nicht implementiert sind
	 * (Meilenstein 10). Zeigt den Installationsstand, damit die Seite nicht
	 * leer wirkt und ein Fehler beim Anlegen der Tabellen sofort auffällt.
	 */
	protected function render_dashboard_stub() {
		global $wpdb;

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Betrieb', 'lindenweg16' ) . '</h1>';
		echo '<p>' . esc_html__( 'Das Fundament steht: Tabellen, Rechte, Einstellungen und Protokoll. Die Kennzahlen folgen in Meilenstein 10.', 'lindenweg16' ) . '</p>';

		$missing = array();
		foreach ( self::table_names() as $table ) {
			$full = $wpdb->prefix . 'lw16_' . $table;
			if ( $full !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) ) {
				$missing[] = $table;
			}
		}

		if ( $missing ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: Liste fehlender Tabellen. */
				esc_html__( 'Fehlende Tabellen: %s. Plugin einmal deaktivieren und wieder aktivieren.', 'lindenweg16' ),
				esc_html( implode( ', ', $missing ) )
			);
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-success inline"><p>' . esc_html(
				sprintf(
					/* translators: %d: Anzahl Tabellen. */
					__( 'Alle %d Tabellen des Moduls sind angelegt.', 'lindenweg16' ),
					count( self::table_names() )
				)
			) . '</p></div>';
		}

		$settings = CalcSettings::for_date();
		echo '<h2>' . esc_html__( 'Aktuelle Kalkulationsannahmen', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		foreach ( CalcSettings::labels() as $key => $label ) {
			printf(
				'<tr><td>%s</td><td style="text-align:right">%s</td></tr>',
				esc_html( $label ),
				esc_html( number_format_i18n( isset( $settings[ $key ] ) ? $settings[ $key ] : 0, 4 ) )
			);
		}
		echo '</tbody></table>';

		$this->render_machine_rate();

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=lw16-betrieb-einstellungen' ) ),
			esc_html__( 'Annahmen bearbeiten', 'lindenweg16' )
		);
		echo '</div>';
	}

	/**
	 * Maschinenstundensatz mit seiner Herkunft ausweisen.
	 *
	 * Der Satz ist eine Rechengröße, kein gepflegter Wert – deshalb wird
	 * offengelegt, aus welchen Geräten und Annahmen er entsteht.
	 */
	protected function render_machine_rate() {
		$breakdown = MachineRate::current_breakdown();

		echo '<h2>' . esc_html__( 'Maschinenstundensatz (berechnet)', 'lindenweg16' ) . '</h2>';

		if ( 0 === $breakdown['printer_count'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Noch keine Geräte erfasst. Ohne Anschaffungskosten fehlt die Abschreibung – der Satz ist damit zu niedrig. Geräte werden in Meilenstein 3 erfasst.', 'lindenweg16' ) . '</p></div>';
		}

		printf(
			'<p><strong>%s €/h</strong> %s</p>',
			esc_html( number_format_i18n( $breakdown['rate'], 4 ) ),
			esc_html(
				sprintf(
					/* translators: %d: Anzahl berücksichtigter Geräte. */
					_n( 'Mittelwert über %d produzierendes Gerät.', 'Mittelwert über %d produzierende Geräte.', (int) $breakdown['printer_count'], 'lindenweg16' ),
					(int) $breakdown['printer_count']
				)
			)
		);

		if ( $breakdown['per_printer'] ) {
			echo '<table class="widefat striped" style="max-width:640px"><thead><tr>';
			echo '<th>' . esc_html__( 'Gerät', 'lindenweg16' ) . '</th>';
			echo '<th style="text-align:right">' . esc_html__( 'Satz €/h', 'lindenweg16' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $breakdown['per_printer'] as $code => $rate ) {
				printf(
					'<tr><td>%s</td><td style="text-align:right">%s</td></tr>',
					esc_html( $code ),
					esc_html( number_format_i18n( $rate, 4 ) )
				);
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Platzhalter für Seiten, die erst in einem späteren Meilenstein
	 * Inhalt bekommen. Bewusst benannt statt leer.
	 *
	 * @param string $title Seitentitel.
	 * @param string $page  Slug.
	 */
	protected function render_placeholder( $title, $page ) {
		$milestones = self::milestones();
		$milestone  = isset( $milestones[ $page ] ) ? $milestones[ $page ] : 0;

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html( $title ) . '</h1>';
		echo '<div class="notice notice-info inline"><p>';
		if ( $milestone ) {
			printf(
				/* translators: %d: Nummer des Meilensteins. */
				esc_html__( 'Diese Seite wird in Meilenstein %d umgesetzt. Die zugehörigen Tabellen sind bereits angelegt.', 'lindenweg16' ),
				(int) $milestone
			);
		} else {
			esc_html_e( 'Diese Seite wird in einem späteren Meilenstein umgesetzt.', 'lindenweg16' );
		}
		echo '</p></div></div>';
	}

	/**
	 * Kurznamen aller Tabellen des Moduls (ohne Präfix lw16_).
	 *
	 * @return string[]
	 */
	public static function table_names() {
		$names = array();
		foreach ( Schema::tables( 'LW16PREFIX', '' ) as $sql ) {
			if ( preg_match( '/CREATE TABLE LW16PREFIX(\w+)/', $sql, $m ) ) {
				$names[] = $m[1];
			}
		}
		return $names;
	}
}
