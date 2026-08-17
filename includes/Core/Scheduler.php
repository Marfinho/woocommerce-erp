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

	/**
	 * Option, die den zuletzt eingeplanten Intervall-Satz festhält.
	 *
	 * as_has_scheduled_action() prüft nur, *ob* ein Job existiert – nicht mit
	 * welchem Intervall. Ohne diese Versionierung würde eine Änderung der
	 * Intervalle auf bestehenden Installationen nie greifen.
	 */
	const VERSION_OPTION = 'lw16_schedule_version';

	/**
	 * Bei Änderungen an $recurring erhöhen.
	 */
	const VERSION = '2';

	// Wiederkehrende Jobs (Hook-Name => Intervall in Sekunden).
	const HOOK_DAILY_LOYALTY   = 'lw16_daily_loyalty_check';
	const HOOK_DHL_TRACKING    = 'lw16_dhl_tracking_refresh';
	const HOOK_CART_REMINDERS  = 'lw16_cart_reminders';
	const HOOK_POSTPURCHASE    = 'lw16_postpurchase_mails';
	const HOOK_WEEKLY_DIGEST   = 'lw16_weekly_digest';
	const HOOK_WEEKLY_EXPORT   = 'lw16_weekly_export';
	const HOOK_DAILY_STOCK     = 'lw16_daily_stock_report';

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
			self::HOOK_GENERATE_INVOICE,
			self::HOOK_GENERATE_PACKING,
		);
	}

	/**
	 * Wiederkehrende Jobs sicherstellen. Wird bei jedem Boot aufgerufen –
	 * as_schedule_recurring_action ist idempotent bei gesetztem unique-Flag.
	 */
	public static function ensure_recurring() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$now = time();

		$recurring = array(
			self::HOOK_DAILY_LOYALTY  => DAY_IN_SECONDS,
			self::HOOK_DHL_TRACKING   => 3 * HOUR_IN_SECONDS,
			self::HOOK_CART_REMINDERS => HOUR_IN_SECONDS,
			self::HOOK_POSTPURCHASE   => DAY_IN_SECONDS,
			// Täglich prüfen, ob der konfigurierte Digest-Wochentag erreicht
			// ist. Mit einem Wochenintervall würde der Job dauerhaft am
			// Aktivierungs-Wochentag feuern und der Wochentag-Filter im
			// Handler ihn dann jedes Mal verwerfen.
			self::HOOK_WEEKLY_DIGEST  => DAY_IN_SECONDS,
			self::HOOK_WEEKLY_EXPORT  => WEEK_IN_SECONDS,
			self::HOOK_DAILY_STOCK    => DAY_IN_SECONDS,
		);

		// Intervalle geändert? Dann die alten Jobs verwerfen und neu planen.
		$stale = get_option( self::VERSION_OPTION ) !== self::VERSION;
		if ( $stale && function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array_keys( $recurring ) as $hook ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
		}

		foreach ( $recurring as $hook => $interval ) {
			if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_recurring_action( $now + MINUTE_IN_SECONDS, $interval, $hook, array(), self::GROUP );
			}
		}

		if ( $stale ) {
			update_option( self::VERSION_OPTION, self::VERSION );
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
