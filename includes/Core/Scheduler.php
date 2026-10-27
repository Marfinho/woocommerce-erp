<?php
/**
 * Zentrale Anbindung an den Action Scheduler (bringt WooCommerce mit).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert und plant alle wiederkehrenden Hintergrundjobs des Plugins.
 * Nutzt den Action Scheduler statt rohem WP-Cron.
 */
class Scheduler {

	const GROUP = 'lindenweg16';

	// Merker "wiederkehrende Jobs zuletzt geprueft", siehe ensure_recurring().
	const TRANSIENT_CHECKED = 'lw16_recurring_checked';

	// Wiederkehrende Jobs (Hook-Name => Intervall in Sekunden).
	const HOOK_DAILY_LOYALTY   = 'lw16_daily_loyalty_check';
	const HOOK_DHL_TRACKING    = 'lw16_dhl_tracking_refresh';
	const HOOK_CART_REMINDERS  = 'lw16_cart_reminders';
	const HOOK_POSTPURCHASE    = 'lw16_postpurchase_mails';
	const HOOK_WEEKLY_DIGEST   = 'lw16_weekly_digest';
	const HOOK_WEEKLY_EXPORT   = 'lw16_weekly_export';
	const HOOK_DAILY_STOCK     = 'lw16_daily_stock_report';

	// Zahlungsgebühren nachtragen: Der Dienstleister liefert sie oft erst
	// Stunden oder Tage nach dem Kauf per Webhook nach.
	const HOOK_DAILY_FEES      = 'lw16_daily_fee_sync';

	// Dauerbuchungen täglich prüfen und ausführen.
	const HOOK_RECURRING_BOOKINGS = 'lw16_recurring_bookings';

	// Modul Betrieb.
	const HOOK_BETRIEB_WEEKLY = 'lw16_betrieb_weekly_mail';

	// Events: unbestätigte Anmeldungen mit abgelaufenem Link verfallen lassen.
	const HOOK_DAILY_EVENTS = 'lw16_daily_events_cleanup';

	// DHL Druck-Warteschlange: liegengebliebene Claims freigeben (Pi-Absturz)
	// und alte Job-Einträge nach Aufbewahrungsfrist aufräumen.
	const HOOK_DHL_PRINT_MAINTENANCE = 'lw16_dhl_print_maintenance';

	// Bambu-Lab-Anbindung: liegengebliebene Claims freigeben (Bridge auf
	// dem Raspberry Pi abgestürzt oder Netz weg) und alte Job-Einträge
	// aufräumen.
	const HOOK_BAMBU_MAINTENANCE = 'lw16_bambu_maintenance';

	// Headless-Slicing-Warteschlange: dieselbe Wartung wie oben, für die
	// Slicing-Aufträge statt die Druckaufträge.
	const HOOK_SLICING_MAINTENANCE = 'lw16_slicing_maintenance';

	// Mailbox: Postfächer per IMAP abgleichen (alle fünf Minuten) und
	// Befehle an den Mailserver (gelesen, verschieben, löschen) im Hintergrund.
	const HOOK_MAIL_SYNC      = 'lw16_mail_sync';
	const HOOK_MAIL_SERVER_OP = 'lw16_mail_server_op';

	// AR-Ansicht aus dem Konfigurator: abgelegte Konfigurationen nach sieben Tagen löschen.
	const HOOK_AR_MODEL_CLEANUP = 'lw16_ar_modell_aufraeumen';

	/**
	 * Bestand aus dem ERP in die Shop-Produkte übertragen (Sicherheitsnetz).
	 */
	const HOOK_SHOP_STOCK = 'lw16_shop_bestand_abgleichen';

	// Asynchrone Einzeljobs.
	const HOOK_GENERATE_INVOICE = 'lw16_generate_invoice';
	const HOOK_GENERATE_PACKING = 'lw16_generate_packing_slip';

	/**
	 * Alle vom Plugin genutzten Hook-Namen (für Cleanup beim Deaktivieren).
	 *
	 * @return string[]
	 */
	public static function all_hooks() {
		return array(
			self::HOOK_DAILY_LOYALTY,
			self::HOOK_DHL_TRACKING,
			self::HOOK_CART_REMINDERS,
			self::HOOK_POSTPURCHASE,
			self::HOOK_WEEKLY_DIGEST,
			self::HOOK_WEEKLY_EXPORT,
			self::HOOK_DAILY_STOCK,
			self::HOOK_DAILY_FEES,
			self::HOOK_RECURRING_BOOKINGS,
			self::HOOK_GENERATE_INVOICE,
			self::HOOK_GENERATE_PACKING,
			self::HOOK_BETRIEB_WEEKLY,
			self::HOOK_DAILY_EVENTS,
			self::HOOK_DHL_PRINT_MAINTENANCE,
			self::HOOK_BAMBU_MAINTENANCE,
			self::HOOK_SLICING_MAINTENANCE,
			self::HOOK_MAIL_SYNC,
			self::HOOK_MAIL_SERVER_OP,
			self::HOOK_AR_MODEL_CLEANUP,
			self::HOOK_SHOP_STOCK,
		);
	}

	/**
	 * Fehlschläge unserer Hintergrundjobs ins Systemprotokoll schreiben.
	 *
	 * Action Scheduler meldet sie sonst nur in seiner eigenen Liste, die
	 * kaum jemand ansieht.
	 */
	public static function watch_failures() {
		add_action( 'action_scheduler_failed_execution', array( __CLASS__, 'log_failure' ), 10, 2 );
		add_action( 'action_scheduler_failed_action', array( __CLASS__, 'log_timeout' ), 10, 1 );
	}

	/**
	 * Ein Job hat eine Ausnahme geworfen.
	 *
	 * @param int        $action_id Auftrag.
	 * @param \Exception $exception Fehler.
	 */
	public static function log_failure( $action_id, $exception ) {
		$hook = self::hook_of( $action_id );

		if ( self::is_ours( $hook ) ) {
			Log::error( 'job', $hook . ': ' . ( is_object( $exception ) ? $exception->getMessage() : '' ), array( 'action_id' => (int) $action_id ) );
		}
	}

	/**
	 * Ein Job hat das Zeitlimit überschritten.
	 *
	 * @param int $action_id Auftrag.
	 */
	public static function log_timeout( $action_id ) {
		$hook = self::hook_of( $action_id );

		if ( self::is_ours( $hook ) ) {
			Log::error( 'job', $hook . ': Zeitlimit überschritten', array( 'action_id' => (int) $action_id ) );
		}
	}

	/**
	 * Gehört ein Hook zu diesem Plugin? Reine Regel.
	 *
	 * @param string $hook Hook-Name.
	 * @return bool
	 */
	public static function is_ours( $hook ) {
		return 0 === strpos( (string) $hook, 'lw16_' );
	}

	/**
	 * Hook-Name eines Auftrags.
	 *
	 * @param int $action_id Auftrag.
	 * @return string
	 */
	protected static function hook_of( $action_id ) {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return '';
		}

		$action = \ActionScheduler::store()->fetch_action( (string) $action_id );

		return $action ? (string) $action->get_hook() : '';
	}

	/**
	 * Wiederkehrende Jobs sicherstellen. Wird bei jedem Boot aufgerufen –
	 * as_schedule_recurring_action ist idempotent bei gesetztem unique-Flag.
	 */
	public static function ensure_recurring() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		// Nicht bei jedem Seitenaufruf: as_has_scheduled_action() ist je
		// Hook eine eigene Datenbankabfrage - 14 Abfragen auf jeder Seite,
		// im Shop wie im Backend, nur um festzustellen, dass alles schon
		// eingeplant ist. Einmal je Stunde reicht; Aktivierung und
		// Deaktivierung loeschen den Merker, damit die Jobs danach sofort
		// wieder eingeplant werden (siehe Installer).
		if ( get_transient( self::TRANSIENT_CHECKED ) ) {
			return;
		}
		set_transient( self::TRANSIENT_CHECKED, 1, HOUR_IN_SECONDS );

		$now = time();

		$recurring = array(
			self::HOOK_DAILY_LOYALTY  => DAY_IN_SECONDS,
			self::HOOK_DHL_TRACKING   => 3 * HOUR_IN_SECONDS,
			self::HOOK_CART_REMINDERS => HOUR_IN_SECONDS,
			self::HOOK_POSTPURCHASE   => DAY_IN_SECONDS,
			self::HOOK_WEEKLY_DIGEST  => WEEK_IN_SECONDS,
			self::HOOK_WEEKLY_EXPORT  => WEEK_IN_SECONDS,
			self::HOOK_DAILY_STOCK       => DAY_IN_SECONDS,
			self::HOOK_DAILY_FEES        => DAY_IN_SECONDS,
			self::HOOK_RECURRING_BOOKINGS => DAY_IN_SECONDS,
			self::HOOK_BETRIEB_WEEKLY     => WEEK_IN_SECONDS,
			self::HOOK_DAILY_EVENTS       => DAY_IN_SECONDS,
			self::HOOK_DHL_PRINT_MAINTENANCE => 5 * MINUTE_IN_SECONDS,
			self::HOOK_BAMBU_MAINTENANCE     => 5 * MINUTE_IN_SECONDS,
			self::HOOK_SLICING_MAINTENANCE   => 5 * MINUTE_IN_SECONDS,
			self::HOOK_MAIL_SYNC             => 5 * MINUTE_IN_SECONDS,
			self::HOOK_AR_MODEL_CLEANUP      => DAY_IN_SECONDS,
			self::HOOK_SHOP_STOCK            => HOUR_IN_SECONDS,
		);

		foreach ( $recurring as $hook => $interval ) {
			if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_recurring_action( $now + MINUTE_IN_SECONDS, $interval, $hook, array(), self::GROUP );
			}
		}
	}

	/**
	 * Einen einmaligen asynchronen Job einreihen (z. B. PDF-Erzeugung).
	 *
	 * @param string $hook Hook-Name.
	 * @param array  $args Argumente.
	 */
	public static function enqueue_async( $hook, array $args = array() ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
		} else {
			// Fallback: direkt ausführen, falls Action Scheduler fehlt.
			do_action( $hook, ...array_values( $args ) );
		}
	}
}
