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

	// Asynchrone Einzeljobs.
	const HOOK_GENERATE_INVOICE = 'lw16_generate_invoice';
	const HOOK_GENERATE_PACKING = 'lw16_generate_packing_slip';

	/**
	 * Merker, dass der Plan bereits geprueft wurde.
	 *
	 * Der Wert enthaelt die Signatur des Plans. Aendert sich ein Intervall
	 * oder kommt ein Job dazu, passt die Signatur nicht mehr und die
	 * Pruefung laeuft beim naechsten Aufruf erneut.
	 */
	const CHECK_OPTION = 'lw16_scheduler_checked';

	/**
	 * Wie lange der Merker gilt. Sicherheitsnetz, falls jemand die Jobs
	 * ausserhalb des Plugins loescht – dann fehlen sie hoechstens einen Tag.
	 */
	const CHECK_TTL = DAY_IN_SECONDS;

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
		);
	}

	/**
	 * Der Plan: Hook-Name => Intervall in Sekunden.
	 *
	 * @return array<string,int>
	 */
	protected static function recurring_plan() {
		return array(
			self::HOOK_DAILY_LOYALTY      => DAY_IN_SECONDS,
			self::HOOK_DHL_TRACKING       => 3 * HOUR_IN_SECONDS,
			self::HOOK_CART_REMINDERS     => HOUR_IN_SECONDS,
			self::HOOK_POSTPURCHASE       => DAY_IN_SECONDS,
			self::HOOK_WEEKLY_DIGEST      => WEEK_IN_SECONDS,
			self::HOOK_WEEKLY_EXPORT      => WEEK_IN_SECONDS,
			self::HOOK_DAILY_STOCK        => DAY_IN_SECONDS,
			self::HOOK_DAILY_FEES         => DAY_IN_SECONDS,
			self::HOOK_RECURRING_BOOKINGS => DAY_IN_SECONDS,
			self::HOOK_BETRIEB_WEEKLY     => WEEK_IN_SECONDS,
		);
	}

	/**
	 * Wiederkehrende Jobs sicherstellen.
	 *
	 * Frueher lief das bei jedem einzelnen Seitenaufruf: zehn Hooks, zehn
	 * ungepufferte Abfragen gegen die Tabelle des Action Schedulers – auch
	 * fuer den Besucher, der nur eine Produktseite ansieht. Die Jobs aendern
	 * sich aber hoechstens bei einer neuen Fassung des Plugins.
	 *
	 * Deshalb merkt sich das Plugin die Signatur des Plans in einer Option.
	 * Stimmt sie, ist nichts zu tun und der Aufruf kostet nichts, weil
	 * WordPress die Optionen ohnehin gebuendelt laedt. Nur wenn die
	 * Signatur fehlt, veraltet ist oder der Merker abgelaufen ist, laufen
	 * die Pruefabfragen – und zwar nicht im Seitenaufbau eines Besuchers,
	 * sondern im Adminbereich, in Cron-Laeufen und auf der Kommandozeile.
	 */
	public static function ensure_recurring() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$plan      = self::recurring_plan();
		$signature = md5( LW16_VERSION . '|' . wp_json_encode( $plan ) );
		$checked   = get_option( self::CHECK_OPTION );

		if ( is_array( $checked )
			&& isset( $checked['signature'], $checked['time'] )
			&& $checked['signature'] === $signature
			&& ( time() - (int) $checked['time'] ) < self::CHECK_TTL
		) {
			return;
		}

		// Ein Besucher soll nie fuer die Wartung bezahlen. Kommt der Aufruf
		// aus dem Frontend, bleibt der Merker ungesetzt und der naechste
		// Admin- oder Cron-Lauf erledigt es.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$now = time();

		foreach ( $plan as $hook => $interval ) {
			if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_recurring_action( $now + MINUTE_IN_SECONDS, $interval, $hook, array(), self::GROUP );
			}
		}

		// Ausdruecklich mitgeladen: Der Merker wird bei jedem Aufruf gelesen.
		// Läge er ausserhalb der gebuendelten Optionen, kostete das genau
		// die Abfrage, die hier eingespart werden soll.
		update_option(
			self::CHECK_OPTION,
			array(
				'signature' => $signature,
				'time'      => $now,
			),
			true
		);
	}

	/**
	 * Merker verwerfen, damit der Plan beim naechsten Lauf neu geprueft wird.
	 */
	public static function forget_check() {
		delete_option( self::CHECK_OPTION );
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
