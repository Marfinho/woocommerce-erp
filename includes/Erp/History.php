<?php
/**
 * Verlauf der Seitenaufrufe: an welchen Tagen und zu welchen Zeiten ist viel los?
 *
 * Liest den anonymen Aufruf-Zähler (`Traffic`, Tabelle `lw16_traffic`): je Stunde
 * eine Zahl. Daraus werden Tagesverlauf, Wochentage, Tageszeiten und eine
 * Wochentag-mal-Stunde-Übersicht. Es gibt weder Personen noch Kennungen; gezählt
 * werden Seitenaufrufe, keine Menschen. Der Zähler speichert in UTC, die Auswertung
 * rechnet auf die Zeit der Website um (Sommerzeit eingeschlossen).
 *
 * Mittelwerte zählen nur abgeschlossene Tage ab dem ersten Tag mit Daten; der
 * heutige Tag erscheint im Verlauf, fließt aber nicht in Durchschnitte ein.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Auswertung des Verlaufs.
 */
class History {

	/**
	 * Zeiträume der Anzeige in Tagen.
	 */
	const RANGES = array( 7, 30, 90, 365 );

	/**
	 * Zeitraum auf einen bekannten Wert begrenzen – reine Regel.
	 *
	 * @param mixed $days Eingabe.
	 * @return int
	 */
	public static function clean_days( $days ) {
		$days = is_numeric( $days ) ? (int) $days : 30;

		return in_array( $days, self::RANGES, true ) ? $days : 30;
	}

	/**
	 * Zeitzone der Website.
	 *
	 * @return \DateTimeZone
	 */
	protected static function zone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	}

	/**
	 * Auswertung für das ERP.
	 *
	 * @param mixed $days Zeitraum.
	 * @return array Siehe summarize().
	 */
	public static function snapshot( $days ) {
		global $wpdb;

		$days  = self::clean_days( $days );
		$table = $wpdb->prefix . 'lw16_traffic';
		$since = gmdate( 'Y-m-d', time() - ( $days + 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT day, hour, SUM(views) AS views FROM {$table} WHERE day >= %s GROUP BY day, hour", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::summarize( is_array( $rows ) ? $rows : array(), time(), $days, self::zone() );
	}

	/**
	 * Zeilen zu Anzeigewerten verdichten – reine Rechnung.
	 *
	 * @param array         $rows Zeilen {day (UTC), hour (UTC), views}.
	 * @param int           $now  Zeitpunkt.
	 * @param int           $days Zeitraum.
	 * @param \DateTimeZone $tz   Zeitzone der Anzeige.
	 * @return array {days, total, today, avg_day, complete_days, first, daily, weekdays, hours, heat, best_day, best_weekday, best_hour}
	 */
	public static function summarize( array $rows, $now, $days, \DateTimeZone $tz ) {
		$now   = (int) $now;
		$days  = (int) $days;
		$utc   = new \DateTimeZone( 'UTC' );
		$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$start = ( new \DateTimeImmutable( $today, $tz ) )->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );

		$daily = array();
		for ( $d = new \DateTimeImmutable( $start, $tz ); $d->format( 'Y-m-d' ) <= $today; $d = $d->modify( '+1 day' ) ) {
			$daily[ $d->format( 'Y-m-d' ) ] = 0;
		}

		$cell  = array();
		$total = 0;
		$first = '';

		foreach ( $rows as $r ) {
			$views = (int) $r['views'];
			$utc_t = new \DateTimeImmutable( $r['day'] . ' 00:00:00', $utc );
			$local = $utc_t->modify( '+' . (int) $r['hour'] . ' hours' )->setTimezone( $tz );
			$date  = $local->format( 'Y-m-d' );

			if ( ! isset( $daily[ $date ] ) || $views <= 0 ) {
				continue;
			}

			$daily[ $date ] += $views;
			$total          += $views;
			$first           = '' === $first || $date < $first ? $date : $first;

			// Durchschnitte nur aus abgeschlossenen Tagen.
			if ( $date < $today ) {
				$w                      = (int) $local->format( 'N' );
				$h                      = (int) $local->format( 'G' );
				$cell[ $w ][ $h ]       = ( isset( $cell[ $w ][ $h ] ) ? $cell[ $w ][ $h ] : 0 ) + $views;
			}
		}

		// Abgeschlossene Tage ab dem ersten Tag mit Daten; so verzerren Tage vor dem Start nichts.
		$wd_days  = array_fill( 1, 7, 0 );
		$complete = 0;
		$done_sum = 0;

		foreach ( $daily as $date => $views ) {
			if ( $date >= $today || '' === $first || $date < $first ) {
				continue;
			}

			++$complete;
			$done_sum += $views;
			++$wd_days[ (int) ( new \DateTimeImmutable( $date, $tz ) )->format( 'N' ) ];
		}

		$heat     = array();
		$weekdays = array();
		$hours    = array_fill( 0, 24, 0.0 );

		for ( $w = 1; $w <= 7; $w++ ) {
			$sum = 0;
			$row = array();

			for ( $h = 0; $h < 24; $h++ ) {
				$v    = isset( $cell[ $w ][ $h ] ) ? $cell[ $w ][ $h ] : 0;
				$sum += $v;
				$row[] = $wd_days[ $w ] ? round( $v / $wd_days[ $w ], 1 ) : 0.0;

				if ( $complete ) {
					$hours[ $h ] += $v / $complete;
				}
			}

			$heat[]     = $row;
			$weekdays[] = array(
				'w'    => $w,
				'avg'  => $wd_days[ $w ] ? round( $sum / $wd_days[ $w ], 1 ) : 0.0,
				'days' => $wd_days[ $w ],
			);
		}

		$hour_list = array();
		foreach ( $hours as $h => $avg ) {
			$hour_list[] = array(
				'h'   => $h,
				'avg' => round( $avg, 1 ),
			);
		}

		$best_day = null;
		foreach ( $daily as $date => $views ) {
			if ( $views > 0 && ( null === $best_day || $views > $best_day['views'] ) ) {
				$best_day = array(
					'date'  => $date,
					'views' => $views,
				);
			}
		}

		$best_wd = null;
		foreach ( $weekdays as $wd ) {
			if ( $wd['avg'] > 0 && ( null === $best_wd || $wd['avg'] > $best_wd['avg'] ) ) {
				$best_wd = $wd;
			}
		}

		$best_h = null;
		foreach ( $hour_list as $hh ) {
			if ( $hh['avg'] > 0 && ( null === $best_h || $hh['avg'] > $best_h['avg'] ) ) {
				$best_h = $hh;
			}
		}

		$list = array();
		foreach ( $daily as $date => $views ) {
			$list[] = array(
				'date'  => $date,
				'views' => $views,
			);
		}

		return array(
			'days'          => $days,
			'total'         => $total,
			'today'         => $daily[ $today ],
			'avg_day'       => $complete ? round( $done_sum / $complete, 1 ) : 0.0,
			'complete_days' => $complete,
			'first'         => $first,
			'daily'         => $list,
			'weekdays'      => $weekdays,
			'hours'         => $hour_list,
			'heat'          => $heat,
			'best_day'      => $best_day,
			'best_weekday'  => $best_wd,
			'best_hour'     => $best_h,
		);
	}
}
