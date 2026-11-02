<?php
/**
 * Hintergrundaufgaben der ERP-Erweiterungen.
 *
 * - stündlich: Vivid-Umsätze und -Salden abrufen (wenn verbunden),
 * - täglich: Überblick per Mail (Digest::run).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Scheduler;

/**
 * Anmeldung der Hintergrundaufgaben.
 */
class Jobs {

	/**
	 * Hooks anmelden.
	 */
	public static function init() {
		add_action( Scheduler::HOOK_ERP_BANK_SYNC, array( __CLASS__, 'bank_sync' ) );
		add_action( Scheduler::HOOK_ERP_DAILY, array( __CLASS__, 'daily' ) );
	}

	/**
	 * Tagesjob: Basiszinssatz aktuell halten (wöchentlich), dann der Überblick.
	 */
	public static function daily() {
		Pages\Mahnwesen::refresh_base_rate();
		Digest::run();
	}

	/**
	 * Vivid abrufen.
	 */
	public static function bank_sync() {
		if ( Vivid::connected() ) {
			Pages\Bank::vivid_sync();
		}
		// Läuft stündlich: passender Moment für den Nachtschicht-Push.
		Pages\Nachtschicht::maybe_push();
	}
}
