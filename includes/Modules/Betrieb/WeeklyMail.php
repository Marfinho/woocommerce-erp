<?php
/**
 * Wöchentliche Zusammenfassung per E-Mail.
 *
 * Läuft über den Action Scheduler, den das Plugin durchgängig statt rohem
 * WP-Cron benutzt. Abschaltbar in den Einstellungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Core\Scheduler;
use Lindenweg16\Core\Settings;

/**
 * Baut und versendet die Wochenmail.
 */
class WeeklyMail {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( Scheduler::HOOK_BETRIEB_WEEKLY, array( $this, 'send' ) );
	}

	/**
	 * Ist der Versand aktiv?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Settings::get( 'betrieb_wochenmail', 1 );
	}

	/**
	 * Wochenmail versenden.
	 *
	 * @return bool
	 */
	public function send() {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$body = self::compose();

		if ( '' === $body ) {
			return false;
		}

		$sent = false;
		foreach ( Roles::get_manager_emails() as $email ) {
			$sent = wp_mail( $email, __( 'Betrieb: Wochenübersicht', 'lindenweg16' ), $body ) || $sent;
		}

		return $sent;
	}

	/**
	 * Text der Wochenmail zusammenstellen.
	 *
	 * @return string
	 */
	public static function compose() {
		$today  = gmdate( 'Y-m-d' );
		$iso    = IsoWeek::from_date( $today );
		$last   = IsoWeek::sequence( $iso['iso_year'], max( 1, $iso['iso_week'] - 1 ), 1 )[0];
		$bounds = IsoWeek::bounds( $last['iso_year'], $last['iso_week'] );

		$lines = array(
			sprintf(
				/* translators: 1: Kalenderwoche, 2: von, 3: bis. */
				__( 'Wochenübersicht %1$s (%2$s bis %3$s)', 'lindenweg16' ),
				IsoWeek::label( $last['iso_year'], $last['iso_week'] ),
				$bounds['von'],
				$bounds['bis']
			),
			'',
		);

		// Kennzahlen der Woche.
		$metrics     = Reporting::metrics( $bounds['von'], $bounds['bis'] );
		$definitions = Kpi::definitions();

		$lines[] = __( 'Kennzahlen', 'lindenweg16' );
		foreach ( $definitions as $key => $definition ) {
			$value = isset( $metrics[ $key ] ) ? $metrics[ $key ] : null;

			if ( null === $value ) {
				continue;
			}

			$evaluation = Kpi::evaluate( $key, $value );
			$marker     = 'rot' === $evaluation['level'] ? ' (!)' : '';

			$lines[] = sprintf( '  %s: %s%s', $definition['label'], Kpi::format( $key, $value ), $marker );
		}

		// Alarme.
		$alarms = Reporting::alarms( $bounds['von'], $bounds['bis'] );
		if ( $alarms ) {
			$lines[] = '';
			$lines[] = __( 'Alarme', 'lindenweg16' );
			foreach ( $alarms as $alarm ) {
				$lines[] = sprintf( '  %s: %s', $alarm['label'], $alarm['value'] );
			}
		}

		// Beschaffung.
		$procurement = ProcurementAlert::all();
		if ( $procurement ) {
			$lines[] = '';
			$lines[] = __( 'Nachbestellen', 'lindenweg16' );
			foreach ( $procurement as $alert ) {
				$lines[] = sprintf(
					/* translators: 1: Position, 2: Reichweite, 3: Schwelle. */
					__( '  %1$s: Reichweite %2$s Tage, Meldegrenze %3$d Tage', 'lindenweg16' ),
					$alert['label'],
					null === $alert['coverage_days'] ? '?' : number_format_i18n( $alert['coverage_days'], 0 ),
					(int) $alert['threshold_days']
				);
			}
		}

		// Wartung.
		$maintenance = array_filter(
			MaintenanceRepository::due(),
			static function ( $row ) {
				return ! empty( $row['reasons'] );
			}
		);
		if ( $maintenance ) {
			$lines[] = '';
			$lines[] = __( 'Wartung fällig', 'lindenweg16' );
			foreach ( $maintenance as $row ) {
				$lines[] = sprintf( '  %s: %s', $row['printer']['code'], implode( '; ', $row['reasons'] ) );
			}
		}

		// Wiedervorlagen.
		$follow_ups = ContactRepository::due_follow_ups();
		if ( $follow_ups ) {
			$lines[] = '';
			$lines[] = __( 'Fällige Wiedervorlagen', 'lindenweg16' );
			foreach ( $follow_ups as $contact ) {
				$lines[] = sprintf( '  %s (%s)', $contact['company'], $contact['follow_up_on'] );
			}
		}

		// Offene Posten.
		$overdue = array_filter( OrderOpsRepository::open_invoices(), array( OrderOpsRepository::class, 'is_overdue' ) );
		if ( $overdue ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %d: Anzahl. */
				__( 'Überfällige Posten: %d', 'lindenweg16' ),
				count( $overdue )
			);
		}

		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=lw16-betrieb' );

		return implode( "\n", $lines );
	}
}
