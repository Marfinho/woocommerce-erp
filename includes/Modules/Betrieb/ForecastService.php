<?php
/**
 * Prognose und Planung: Daten einsammeln, Modell anwenden, Auslöser prüfen.
 *
 * Hier liegt alles, was Datenbank und WooCommerce braucht. Die Rechnung
 * selbst steht in ForecastModel (Zeitreihe), ForecastKpi (Ziele und
 * Auslöser), Promise (Lieferzusage) und DayPlan (Tagesplan) - rein und
 * geprüft.
 *
 * Drei Sichten:
 *
 * 1. **Kennzahlen mit Ziel.** Umsatz und Bestellungen als Wochenreihe gegen
 *    das Quartalsziel (Wahrscheinlichkeit, Band, Pfad), Quoten und Ø-Warenkorb
 *    als Monatsreihe gegen Ziel und Alarmgrenze.
 * 2. **Artikelbedarf.** Wochenverkäufe je Artikel und Farbe, Prognose mit
 *    Band, Reichweite, Sicherheitsbestand und empfohlene Fertigungsmenge.
 * 3. **Planung.** Lieferzusage, Tagesplan und die Anbindung an den Shop.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Log;

/**
 * Datenseite der Prognose.
 */
class ForecastService {

	const OPTION   = 'lw16_prognose';
	const EVENTS   = 'lw16_prognose_ereignisse';
	const STATE    = 'lw16_prognose_stufen';
	const LAST_RUN = 'lw16_prognose_lauf';
	const CACHE    = 'lw16_pg_';
	const VERSION  = 'lw16_prognose_version';

	/**
	 * Summenkennzahlen - als Wochenreihe gegen das Quartalsziel.
	 */
	const SUM_KEYS = array( 'umsatz', 'bestellungen' );

	/**
	 * Verhältniskennzahlen - als Monatsreihe gegen Ziel und Alarmgrenze.
	 */
	const RATIO_KEYS = array( 'warenkorb', 'b2b_anteil', 'reklamationsquote', 'ausschussquote', 'auslastung', 'wiederkaufsrate' );

	/* ====================================================================
	 * Einstellungen und Ereignisse
	 * ================================================================== */

	/**
	 * Vorgaben.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'p_attention'   => 50,
			'p_critical'    => 15,
			'service_level' => 90,
			'lead_weeks'    => 1.0,
			'cover_weeks'   => 2.0,
			'shop_text'     => 0,
			'use_model'     => 1,
		);
	}

	/**
	 * Eingaben bereinigen - reine Logik.
	 *
	 * @param array $in Eingabe.
	 * @return array
	 */
	public static function sanitize_settings( array $in ) {
		$d   = self::defaults();
		$out = array(
			'p_attention'   => min( 95, max( 5, (int) ( isset( $in['p_attention'] ) ? $in['p_attention'] : $d['p_attention'] ) ) ),
			'p_critical'    => min( 90, max( 1, (int) ( isset( $in['p_critical'] ) ? $in['p_critical'] : $d['p_critical'] ) ) ),
			'service_level' => min( 99, max( 50, (int) ( isset( $in['service_level'] ) ? $in['service_level'] : $d['service_level'] ) ) ),
			'lead_weeks'    => min( 12.0, max( 0.0, (float) ( isset( $in['lead_weeks'] ) ? $in['lead_weeks'] : $d['lead_weeks'] ) ) ),
			'cover_weeks'   => min( 12.0, max( 0.5, (float) ( isset( $in['cover_weeks'] ) ? $in['cover_weeks'] : $d['cover_weeks'] ) ) ),
			'shop_text'     => ! empty( $in['shop_text'] ) ? 1 : 0,
			'use_model'     => ! empty( $in['use_model'] ) ? 1 : 0,
		);

		// „Kritisch“ liegt immer unter „Aufmerksamkeit“, sonst käme es nie dazu.
		if ( $out['p_critical'] >= $out['p_attention'] ) {
			$out['p_critical'] = max( 1, $out['p_attention'] - 5 );
		}

		return $out;
	}

	/**
	 * Aktuelle Einstellungen.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );

		return self::sanitize_settings( array_merge( self::defaults(), is_array( $saved ) ? $saved : array() ) );
	}

	/**
	 * Einstellungen speichern.
	 *
	 * @param array $in Eingabe.
	 */
	public static function save_settings( array $in ) {
		update_option( self::OPTION, self::sanitize_settings( $in ), false );
		self::flush();
	}

	/**
	 * Schwellen für die Ampel als Anteile.
	 *
	 * @return array
	 */
	public static function thresholds() {
		$s = self::settings();

		return array(
			'attention' => $s['p_attention'] / 100,
			'critical'  => $s['p_critical'] / 100,
		);
	}

	/**
	 * Geplante Ereignisse (Märkte, Aktionen) bereinigen - reine Logik.
	 *
	 * @param array $rows Eingabe.
	 * @return array
	 */
	public static function sanitize_events( array $rows ) {
		$out = array();

		foreach ( $rows as $row ) {
			$von = isset( $row['von'] ) ? (string) $row['von'] : '';
			$bis = isset( $row['bis'] ) ? (string) $row['bis'] : '';

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $von ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $bis ) || $bis < $von ) {
				continue;
			}

			$out[] = array(
				'name' => mb_substr( trim( wp_strip_all_tags( isset( $row['name'] ) ? (string) $row['name'] : '' ) ), 0, 80 ),
				'von'  => $von,
				'bis'  => $bis,
				'pct'  => max( -90.0, min( 500.0, (float) ( isset( $row['pct'] ) ? $row['pct'] : 0 ) ) ),
			);

			if ( count( $out ) >= 50 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Geplante Ereignisse.
	 *
	 * @return array
	 */
	public static function events() {
		$events = get_option( self::EVENTS, array() );

		return is_array( $events ) ? self::sanitize_events( $events ) : array();
	}

	/**
	 * Ereignisse speichern.
	 *
	 * @param array $rows Eingabe.
	 */
	public static function save_events( array $rows ) {
		update_option( self::EVENTS, self::sanitize_events( $rows ), false );
		self::flush();
	}

	/**
	 * Was Märkte bisher gebracht haben - als Anhaltspunkt für den Aufschlag.
	 *
	 * @return array {days, revenue, per_day}
	 */
	public static function market_hint() {
		global $wpdb;

		$table = $wpdb->prefix . 'lw16_kasse_tage';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS days, COALESCE(SUM(umsatz_gesamt),0) AS revenue FROM {$table} WHERE status = 'abgeschlossen' AND datum >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d', time() - 365 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);

		$days = $row ? (int) $row['days'] : 0;
		$rev  = $row ? (float) $row['revenue'] : 0.0;

		return array(
			'days'    => $days,
			'revenue' => $rev,
			'per_day' => $days > 0 ? $rev / $days : null,
		);
	}

	/**
	 * Zwischenspeicher leeren.
	 */
	public static function flush() {
		// Neue Versionsnummer: Alle alten Zwischenspeicher-Schlüssel laufen ins Leere
		// und verfallen von selbst - auch mit externem Objekt-Cache.
		update_option( self::VERSION, (string) microtime( true ), false );
	}

	/**
	 * Schlüssel im Zwischenspeicher, passend zur aktuellen Version.
	 *
	 * @param string $suffix Name.
	 * @return string
	 */
	protected static function cache_key( $suffix ) {
		return self::CACHE . substr( md5( (string) get_option( self::VERSION, '1' ) ), 0, 8 ) . '_' . $suffix;
	}

	/* ====================================================================
	 * Kennzahlen mit Ziel
	 * ================================================================== */

	/**
	 * Heutiges Datum.
	 *
	 * @return string Y-m-d
	 */
	protected static function today() {
		return function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/**
	 * Verlauf der Kennzahlen je Zeitraum (zwischengespeichert).
	 *
	 * @param string $granularity woche oder monat.
	 * @param int    $count       Anzahl Zeiträume.
	 * @return array {buckets, metrics}
	 */
	protected static function history( $granularity, $count ) {
		$key    = self::cache_key( 'h_' . $granularity . '_' . (int) $count . '_' . self::today() );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$buckets = Period::series( $granularity, self::today(), $count );
		$data    = array(
			'buckets' => $buckets,
			'metrics' => Reporting::series( $buckets ),
		);

		set_transient( $key, $data, 30 * MINUTE_IN_SECONDS );

		return $data;
	}

	/**
	 * Werte einer Kennzahl ohne den laufenden Zeitraum.
	 *
	 * Vor dem ersten Umsatz gab es nichts zu messen: führende Nullen
	 * zählen als „noch kein Betrieb“ und werden abgeschnitten.
	 *
	 * @param array  $hist    Verlauf.
	 * @param string $key     Kennzahl.
	 * @param bool   $zeros_are_gaps Nullen am Anfang als Lücke.
	 * @return array {values, buckets}
	 */
	protected static function series_of( array $hist, $key, $zeros_are_gaps ) {
		$buckets = array_slice( $hist['buckets'], 0, -1 );
		$values  = array();

		foreach ( $buckets as $bucket ) {
			$v        = isset( $hist['metrics'][ $bucket['key'] ][ $key ] ) ? $hist['metrics'][ $bucket['key'] ][ $key ] : null;
			$values[] = null === $v ? null : (float) $v;
		}

		if ( $zeros_are_gaps ) {
			foreach ( $values as $i => $v ) {
				if ( null !== $v && $v > 0 ) {
					break;
				}
				$values[ $i ] = null;
			}
		}

		return array(
			'values'  => $values,
			'buckets' => $buckets,
		);
	}

	/**
	 * Eine Summenkennzahl gegen das Quartalsziel.
	 *
	 * @param string $key Kennzahl.
	 * @return array|null
	 */
	protected static function sum_outlook( $key ) {
		$defs  = Kpi::definitions();
		$today = self::today();
		$hist  = self::history( 'woche', 40 );
		$ser   = self::series_of( $hist, $key, true );

		$horizon = 14;
		$fc      = ForecastModel::forecast( $ser['values'], $horizon, 0, true );

		if ( empty( $fc['points'] ) ) {
			return null;
		}

		$goals   = KpiGoalRepository::for_metric( $key );
		$quarter = Period::bucket( 'quartal', $today );
		$limits  = Reporting::limits( $key, $quarter, $goals );
		$events  = self::events();
		$th      = self::thresholds();

		// Die laufende Woche und die folgenden: Erwartung mal Ereignisfaktor.
		$current = Period::bucket( 'woche', $today );
		$cur_ts  = strtotime( $current['von'] . ' UTC' );
		$tom_ts  = strtotime( $today . ' UTC' ) + DAY_IN_SECONDS;
		$end_ts  = strtotime( $quarter['bis'] . ' UTC' );

		$weeks  = array();
		$rest   = array();
		$remain = 0.0;

		foreach ( $fc['points'] as $i => $p ) {
			$from   = $cur_ts + $i * 7 * DAY_IN_SECONDS;
			$to     = $from + 6 * DAY_IN_SECONDS;
			$factor = ForecastKpi::event_factor( $events, $from, $to );
			$mean   = $p['mean'] * $factor;
			$sd     = $p['sd'] * $factor;

			$frac = ForecastKpi::overlap_fraction( $from, $to, $tom_ts, $end_ts );
			if ( $frac > 0 ) {
				$rest[]  = array( 'mean' => $mean * $frac, 'sd' => $sd * $frac );
				$remain += $frac;
			}

			$week = Period::bucket( 'woche', gmdate( 'Y-m-d', $from ) );
			$lim  = Reporting::limits( $key, $week, $goals );

			$weeks[] = array(
				'label'  => $week['label'],
				'mean'   => $mean,
				'lo80'   => max( 0.0, $p['lo80'] * $factor ),
				'hi80'   => $p['hi80'] * $factor,
				'lo95'   => max( 0.0, $p['lo95'] * $factor ),
				'hi95'   => $p['hi95'] * $factor,
				'target' => $lim['target'],
			);
		}

		$actual_all = Reporting::metrics( $quarter['von'], $today );
		$actual     = isset( $actual_all[ $key ] ) && null !== $actual_all[ $key ] ? (float) $actual_all[ $key ] : 0.0;
		$proj       = ForecastKpi::sum_projection( $actual, $rest );

		$p_goal  = null !== $limits['target'] ? ForecastKpi::prob_at_least( $proj['mean'], $proj['sd'], (float) $limits['target'] ) : null;
		$p_alarm = null !== $limits['alarm'] ? ForecastKpi::prob_at_most( $proj['mean'], $proj['sd'], (float) $limits['alarm'] ) : null;
		$status  = ForecastKpi::status( $p_goal, $p_alarm, $th );

		// Verlauf: letzte 20 Wochen mit abgeleitetem Wochenziel.
		$history = array();
		foreach ( array_slice( $ser['buckets'], -20 ) as $bucket ) {
			$lim       = Reporting::limits( $key, $bucket, $goals );
			$history[] = array(
				'label'  => $bucket['label'],
				'value'  => isset( $hist['metrics'][ $bucket['key'] ][ $key ] ) ? (float) $hist['metrics'][ $bucket['key'] ][ $key ] : null,
				'target' => $lim['target'],
			);
		}

		return array(
			'key'         => $key,
			'label'       => $defs[ $key ]['label'],
			'unit'        => $defs[ $key ]['unit'],
			'type'        => 'sum',
			'history'     => $history,
			'forecast'    => array_slice( $weeks, 0, 12 ),
			'cumulative'  => self::cumulative( $key, $quarter, $today, $fc['points'], $events, $limits, $goals, $actual ),
			'quarter'     => array(
				'label'      => $quarter['label'],
				'von'        => $quarter['von'],
				'bis'        => $quarter['bis'],
				'target'     => $limits['target'],
				'alarm'      => $limits['alarm'],
				'actual'     => $actual,
				'projection' => $proj,
				'required'   => null !== $limits['target'] ? ForecastKpi::required_per_period( $actual, (float) $limits['target'], $remain ) : null,
				'weeks_left' => round( $remain, 1 ),
			),
			'p_goal'      => $p_goal,
			'p_alarm'     => $p_alarm,
			'status'      => $status,
			'reliability' => $fc['reliability'],
			'accuracy'    => $fc['accuracy'],
			'method'      => $fc['method'],
			'n'           => $fc['n'],
		);
	}

	/**
	 * Kumulierter Quartalspfad: Ist bis heute, danach Erwartung mit Band, gegen den Zielpfad.
	 *
	 * @param string $key     Kennzahl.
	 * @param array  $quarter Quartal.
	 * @param string $today   Heute.
	 * @param array  $points  Prognosepunkte ab der laufenden Woche.
	 * @param array  $events  Ereignisse.
	 * @param array  $limits  Ziel und Alarm des Quartals.
	 * @param array  $goals   Quartalsziele.
	 * @param float  $actual  Ist bis heute.
	 * @return array
	 */
	protected static function cumulative( $key, array $quarter, $today, array $points, array $events, array $limits, array $goals, $actual ) {
		$q_from = strtotime( $quarter['von'] . ' UTC' );
		$q_to   = strtotime( $quarter['bis'] . ' UTC' );
		$t_ts   = strtotime( $today . ' UTC' );
		$cur_ts = strtotime( Period::bucket( 'woche', $today )['von'] . ' UTC' );
		$days   = max( 1, (int) round( ( $q_to - $q_from ) / DAY_IN_SECONDS ) + 1 );
		$out    = array();
		$cum    = 0.0;
		$rest   = array();

		for ( $ws = strtotime( Period::bucket( 'woche', $quarter['von'] )['von'] . ' UTC' ); $ws <= $q_to; $ws += 7 * DAY_IN_SECONDS ) {
			$we    = $ws + 6 * DAY_IN_SECONDS;
			$from  = max( $ws, $q_from );
			$to    = min( $we, $q_to );
			$label = Period::bucket( 'woche', gmdate( 'Y-m-d', $ws ) )['label'];

			$target_cum = null !== $limits['target'] ? (float) $limits['target'] * ( (int) round( ( $to - $q_from ) / DAY_IN_SECONDS ) + 1 ) / $days : null;

			if ( $to <= $t_ts ) {
				$m   = Reporting::metrics( gmdate( 'Y-m-d', $from ), gmdate( 'Y-m-d', $to ) );
				$cum += isset( $m[ $key ] ) ? (float) $m[ $key ] : 0.0;

				$out[] = array(
					'label'  => $label,
					'actual' => $cum,
					'target' => $target_cum,
				);
				continue;
			}

			$i = (int) round( ( $ws - $cur_ts ) / ( 7 * DAY_IN_SECONDS ) );
			if ( ! isset( $points[ $i ] ) ) {
				break;
			}

			$factor = ForecastKpi::event_factor( $events, $ws, $we );
			$frac   = ForecastKpi::overlap_fraction( $ws, $we, max( $t_ts + DAY_IN_SECONDS, $q_from ), $q_to );
			$rest[] = array( 'mean' => $points[ $i ]['mean'] * $factor * $frac, 'sd' => $points[ $i ]['sd'] * $factor * $frac );
			$proj   = ForecastKpi::sum_projection( $actual, $rest );

			$out[] = array(
				'label'  => $label,
				'mean'   => $proj['mean'],
				'lo80'   => $proj['lo80'],
				'hi80'   => $proj['hi80'],
				'lo95'   => $proj['lo95'],
				'hi95'   => $proj['hi95'],
				'target' => $target_cum,
			);
		}

		return $out;
	}

	/**
	 * Eine Verhältniskennzahl: nächste Monate gegen Ziel und Alarmgrenze.
	 *
	 * @param string $key Kennzahl.
	 * @return array|null
	 */
	protected static function ratio_outlook( $key ) {
		$defs = Kpi::definitions();
		$def  = $defs[ $key ];
		$hist = self::history( 'monat', 24 );
		$ser  = self::series_of( $hist, $key, false );

		$valid = array_filter(
			$ser['values'],
			static function ( $v ) {
				return null !== $v;
			}
		);

		if ( count( $valid ) < 5 ) {
			return null;
		}

		$fc = ForecastModel::forecast( $ser['values'], 3, 12, true );
		if ( empty( $fc['points'] ) ) {
			return null;
		}

		$goals   = KpiGoalRepository::for_metric( $key );
		$th      = self::thresholds();
		$current = Period::bucket( 'monat', self::today() );
		$higher  = Kpi::HIGHER_IS_BETTER === $def['direction'];
		$alarm_high = ! empty( $def['alarm_high'] );
		$forecast = array();
		$worst    = 'neutral';
		$first    = null;

		foreach ( $fc['points'] as $i => $p ) {
			$bucket = Period::bucket( 'monat', gmdate( 'Y-m-d', strtotime( $current['von'] . ' UTC' ) + $i * 32 * DAY_IN_SECONDS ) );
			$lim    = Reporting::limits( $key, $bucket, $goals );

			$p_goal  = null;
			$p_alarm = null;

			if ( null !== $lim['target'] ) {
				$p_goal = ( $higher || $alarm_high ) ? ForecastKpi::prob_at_least( $p['mean'], $p['sd'], (float) $lim['target'] ) : ForecastKpi::prob_at_most( $p['mean'], $p['sd'], (float) $lim['target'] );
			}
			if ( null !== $lim['alarm'] ) {
				$p_alarm = ( $higher && ! $alarm_high ) ? ForecastKpi::prob_at_most( $p['mean'], $p['sd'], (float) $lim['alarm'] ) : ForecastKpi::prob_at_least( $p['mean'], $p['sd'], (float) $lim['alarm'] );
			}

			$status = ForecastKpi::status( $p_goal, $p_alarm, $th );

			if ( $i < 2 ) {
				$rank = array( 'neutral' => 0, 'gruen' => 1, 'gelb' => 2, 'rot' => 3 );
				if ( $rank[ $status ] > $rank[ $worst ] ) {
					$worst = $status;
				}
				if ( null === $first ) {
					$first = array( 'p_goal' => $p_goal, 'p_alarm' => $p_alarm );
				}
			}

			$forecast[] = array(
				'label'  => $bucket['label'],
				'mean'   => $p['mean'],
				'lo80'   => $p['lo80'],
				'hi80'   => $p['hi80'],
				'lo95'   => $p['lo95'],
				'hi95'   => $p['hi95'],
				'target' => $lim['target'],
				'alarm'  => $lim['alarm'],
				'p_goal' => $p_goal,
				'p_alarm' => $p_alarm,
				'status' => $status,
			);
		}

		$history = array();
		foreach ( array_slice( $ser['buckets'], -12 ) as $bucket ) {
			$lim       = Reporting::limits( $key, $bucket, $goals );
			$history[] = array(
				'label'  => $bucket['label'],
				'value'  => isset( $hist['metrics'][ $bucket['key'] ][ $key ] ) && null !== $hist['metrics'][ $bucket['key'] ][ $key ] ? (float) $hist['metrics'][ $bucket['key'] ][ $key ] : null,
				'target' => $lim['target'],
				'alarm'  => $lim['alarm'],
			);
		}

		return array(
			'key'         => $key,
			'label'       => $def['label'],
			'unit'        => $def['unit'],
			'type'        => 'ratio',
			'history'     => $history,
			'forecast'    => $forecast,
			'p_goal'      => $first ? $first['p_goal'] : null,
			'p_alarm'     => $first ? $first['p_alarm'] : null,
			'status'      => 'neutral' === $worst ? 'neutral' : $worst,
			'reliability' => $fc['reliability'],
			'accuracy'    => $fc['accuracy'],
			'method'      => $fc['method'],
			'n'           => $fc['n'],
		);
	}

	/**
	 * Alle Kennzahlen mit Prognose (zwischengespeichert).
	 *
	 * @param bool $fresh Zwischenspeicher übergehen.
	 * @return array
	 */
	public static function kpi_overview( $fresh = false ) {
		$key = self::cache_key( 'kpi_' . self::today() );

		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$rows = array();

		foreach ( self::SUM_KEYS as $k ) {
			$o = self::sum_outlook( $k );
			if ( $o ) {
				$rows[] = $o;
			}
		}

		foreach ( self::RATIO_KEYS as $k ) {
			$o = self::ratio_outlook( $k );
			if ( $o ) {
				$rows[] = $o;
			}
		}

		set_transient( $key, $rows, 30 * MINUTE_IN_SECONDS );

		return $rows;
	}

	/* ====================================================================
	 * Artikelbedarf
	 * ================================================================== */

	/**
	 * Wochenverkäufe je Artikel und Farbe der letzten Wochen.
	 *
	 * @param int $weeks Anzahl abgeschlossener Wochen.
	 * @return array article_id => color => Liste je Woche (alt nach neu).
	 */
	public static function weekly_units( $weeks = 26 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$key    = self::cache_key( 'units_' . (int) $weeks . '_' . self::today() );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$cur_start = strtotime( Period::bucket( 'woche', self::today() )['von'] . ' UTC' );
		$start     = $cur_start - (int) $weeks * 7 * DAY_IN_SECONDS;
		$map       = StockOwnership::article_map();
		$out       = array();
		$page      = 1;

		do {
			$orders = wc_get_orders(
				array(
					'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
					'limit'        => 300,
					'paged'        => $page,
					'date_created' => $start . '...' . ( $cur_start - 1 ),
					'return'       => 'objects',
				)
			);

			foreach ( (array) $orders as $order ) {
				$ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
				$i  = (int) floor( ( $ts - $start ) / ( 7 * DAY_IN_SECONDS ) );

				if ( $i < 0 || $i >= $weeks ) {
					continue;
				}

				foreach ( $order->get_items() as $item ) {
					$article_id = isset( $map[ (int) $item->get_product_id() ] ) ? (int) $map[ (int) $item->get_product_id() ] : 0;

					if ( ! $article_id ) {
						continue;
					}

					$color = ColorRegistry::resolve_order_item( $item )['color'];
					$qty   = (float) $item->get_quantity();

					foreach ( array( $color, '*' ) as $c ) {
						if ( ! isset( $out[ $article_id ][ $c ] ) ) {
							$out[ $article_id ][ $c ] = array_fill( 0, (int) $weeks, 0.0 );
						}
						$out[ $article_id ][ $c ][ $i ] += $qty;
					}
				}
			}

			$page++;
		} while ( count( (array) $orders ) >= 300 && $page <= 20 );

		set_transient( $key, $out, HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Vor dem ersten Verkauf liegt keine Nachfrage, nur ein Artikel, den es noch nicht gab.
	 *
	 * @param float[] $series Wochenwerte.
	 * @return array Mit null vor dem ersten Verkauf.
	 */
	public static function trim_leading_zeros( array $series ) {
		foreach ( $series as $i => $v ) {
			if ( $v > 0 ) {
				break;
			}
			$series[ $i ] = null;
		}

		return $series;
	}

	/**
	 * Bedarf über die ersten N Wochen als Erwartung und Streuung.
	 *
	 * @param array $points  Prognosepunkte (mean, sd).
	 * @param float $weeks   Wochen (auch gebrochen).
	 * @return array {mean, sd}
	 */
	public static function demand_over( array $points, $weeks ) {
		$use  = array();
		$left = (float) $weeks;

		foreach ( $points as $p ) {
			if ( $left <= 0 ) {
				break;
			}
			$f      = min( 1.0, $left );
			$use[]  = array( 'mean' => $p['mean'] * $f, 'sd' => $p['sd'] * $f );
			$left  -= 1.0;
		}

		$proj = ForecastKpi::sum_projection( 0.0, $use );

		return array( 'mean' => $proj['mean'], 'sd' => $proj['sd'] );
	}

	/**
	 * Wie viele Wochen reicht ein Bestand?
	 *
	 * @param float $stock  Bestand.
	 * @param array $points Prognosepunkte.
	 * @param float $z      Faktor für die Abweichung (0 = Erwartung, positiv = pessimistisch, negativ = optimistisch).
	 * @return float|null Null, wenn er über den Horizont hinaus reicht.
	 */
	public static function coverage_weeks( $stock, array $points, $z = 0.0 ) {
		$cum_mean = 0.0;
		$rest     = array();

		foreach ( $points as $i => $p ) {
			$rest[] = $p;
			$proj   = ForecastKpi::sum_projection( 0.0, $rest );
			$need   = max( 0.0, $proj['mean'] + $z * $proj['sd'] );

			if ( $need >= $stock ) {
				$prev = 0.0;
				if ( $i > 0 ) {
					$pp   = ForecastKpi::sum_projection( 0.0, array_slice( $rest, 0, -1 ) );
					$prev = max( 0.0, $pp['mean'] + $z * $pp['sd'] );
				}

				$span = $need - $prev;

				return $i + ( $span > 0 ? ( $stock - $prev ) / $span : 1.0 );
			}

			$cum_mean = $proj['mean'];
		}

		unset( $cum_mean );

		return null;
	}

	/**
	 * Auswertung einer Reihe (Artikel oder Farbe).
	 *
	 * @param array $series    Wochenwerte.
	 * @param float $available Verfügbar (Lager minus Reservierung).
	 * @param float $in_work   Schon in Fertigung.
	 * @param array $set       Einstellungen.
	 * @param array $events    Ereignisse.
	 * @return array|null
	 */
	protected static function evaluate_series( array $series, $available, $in_work, array $set, array $events ) {
		$vals = self::trim_leading_zeros( $series );
		$fc   = ForecastModel::forecast( $vals, 12, 0, true );

		if ( empty( $fc['points'] ) ) {
			return null;
		}

		$cur_ts = strtotime( Period::bucket( 'woche', self::today() )['von'] . ' UTC' );
		$points = array();

		foreach ( $fc['points'] as $i => $p ) {
			$from     = $cur_ts + $i * 7 * DAY_IN_SECONDS;
			$factor   = ForecastKpi::event_factor( $events, $from, $from + 6 * DAY_IN_SECONDS );
			$points[] = array( 'mean' => $p['mean'] * $factor, 'sd' => $p['sd'] * $factor );
		}

		$z         = ForecastModel::normal_quantile( $set['service_level'] / 100 );
		$lead      = self::demand_over( $points, $set['lead_weeks'] );
		$total     = self::demand_over( $points, $set['lead_weeks'] + $set['cover_weeks'] );
		$target    = $total['mean'] + $z * $total['sd'];
		$recommend = (int) max( 0, ceil( $target - $available - $in_work - 0.0001 ) );
		$safety    = (int) ceil( $z * $lead['sd'] );

		$level = 'normal';
		if ( $available < $lead['mean'] - 0.0001 ) {
			$level = 'critical';
		} elseif ( $available < $lead['mean'] + $z * $lead['sd'] - 0.0001 ) {
			$level = 'attention';
		}

		return array(
			'per_week'    => round( $points[0]['mean'], 2 ),
			'per_week_lo' => round( max( 0.0, $fc['points'][0]['lo80'] ), 2 ),
			'per_week_hi' => round( $fc['points'][0]['hi80'], 2 ),
			'available'   => (int) floor( $available + 0.0005 ),
			'in_work'     => (float) $in_work,
			'reach'       => self::coverage_weeks( $available, $points, 0.0 ),
			'reach_worst' => self::coverage_weeks( $available, $points, ForecastModel::Z80 ),
			'reach_best'  => self::coverage_weeks( $available, $points, -ForecastModel::Z80 ),
			'safety'      => $safety,
			'recommended' => $recommend,
			'demand_lead' => round( $lead['mean'], 1 ),
			'demand_total' => round( $total['mean'], 1 ),
			'level'       => $level,
			'reliability' => $fc['reliability'],
			'accuracy'    => null === $fc['accuracy'] ? null : round( $fc['accuracy'], 1 ),
			'n'           => $fc['n'],
			'forecast'    => array_map(
				static function ( $p, $pt ) {
					return array(
						'mean' => round( $pt['mean'], 2 ),
						'lo80' => round( $p['lo80'], 2 ),
						'hi80' => round( $p['hi80'], 2 ),
					);
				},
				array_slice( $fc['points'], 0, 8 ),
				array_slice( $points, 0, 8 )
			),
		);
	}

	/**
	 * Bedarfsprognose je Artikel und Farbe (zwischengespeichert).
	 *
	 * @param bool $fresh Zwischenspeicher übergehen.
	 * @return array
	 */
	public static function article_outlook( $fresh = false ) {
		$key = self::cache_key( 'art_' . self::today() );

		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$set      = self::settings();
		$events   = self::events();
		$units    = self::weekly_units( 26 );
		$articles = ArticleRepository::all_by_id();
		$balances = StockRepository::balances();
		$lines    = WooIntegration::open_order_lines();
		$in_work  = DemandPlanner::in_work();
		$rows     = array();

		foreach ( $units as $article_id => $colors ) {
			if ( ! isset( $articles[ $article_id ] ) || ! ArticleRepository::is_active( $articles[ $article_id ] ) ) {
				continue;
			}

			$stock    = ShopStock::stock_by_color( isset( $balances[ $article_id ] ) ? $balances[ $article_id ] : array() );
			$reserved = ShopStock::reserved_by_color( $lines, $article_id );
			$total    = self::evaluate_series( $colors['*'], ShopStock::simple_quantity( $stock, $reserved ), isset( $in_work[ $article_id ] ) ? $in_work[ $article_id ] : 0.0, $set, $events );

			if ( ! $total ) {
				continue;
			}

			$by_color = array();
			$avail    = ShopStock::available( $stock, $reserved );

			foreach ( $colors as $color => $series ) {
				if ( '*' === $color ) {
					continue;
				}

				$ev = self::evaluate_series( $series, isset( $avail[ $color ] ) ? $avail[ $color ] : 0, 0.0, $set, $events );
				if ( $ev ) {
					$by_color[ $color ] = array_merge( array( 'color' => $color, 'label' => ColorRegistry::label( $color ) ), $ev );
				}
			}

			$rows[] = array_merge(
				array(
					'article_id' => (int) $article_id,
					'sku'        => (string) $articles[ $article_id ]['sku'],
					'name'       => (string) $articles[ $article_id ]['name'],
					'colors'     => array_values( $by_color ),
				),
				$total
			);
		}

		$rank = array( 'critical' => 0, 'attention' => 1, 'normal' => 2 );
		usort(
			$rows,
			static function ( $a, $b ) use ( $rank ) {
				if ( $rank[ $a['level'] ] !== $rank[ $b['level'] ] ) {
					return $rank[ $a['level'] ] <=> $rank[ $b['level'] ];
				}
				$ra = null === $a['reach'] ? 99 : $a['reach'];
				$rb = null === $b['reach'] ? 99 : $b['reach'];

				return $ra <=> $rb;
			}
		);

		set_transient( $key, $rows, HOUR_IN_SECONDS );

		return $rows;
	}

	/**
	 * Artikel, für die der Verlauf für das Modell reicht (mindestens acht Wochen seit dem ersten Verkauf).
	 *
	 * @return array article_id => true
	 */
	public static function modelled_articles() {
		$out = array();

		foreach ( self::weekly_units( 26 ) as $article_id => $colors ) {
			$vals = array_filter(
				self::trim_leading_zeros( $colors['*'] ),
				static function ( $v ) {
					return null !== $v;
				}
			);

			if ( count( $vals ) >= 8 ) {
				$out[ (int) $article_id ] = true;
			}
		}

		return $out;
	}

	/**
	 * Fertigungsbedarf aus dem Modell, im Format der bisherigen Hochrechnung.
	 *
	 * Statt „Wochenmittel mal Reichweite“ gilt: Bedarf über Vorlauf und
	 * Reichweite auf dem gewählten Dienstgrad, minus offene Bestellungen.
	 *
	 * @param array $open_orders article_id => Menge in offenen Bestellungen.
	 * @return array Zeilen wie Forecast::demands().
	 */
	public static function model_demands( array $open_orders ) {
		$set    = self::settings();
		$events = self::events();
		$z      = ForecastModel::normal_quantile( $set['service_level'] / 100 );
		$out    = array();

		foreach ( self::weekly_units( 26 ) as $article_id => $colors ) {
			$vals = self::trim_leading_zeros( $colors['*'] );
			$fc   = ForecastModel::forecast( $vals, 12, 0, true );

			// Zu wenig Verlauf: Die bisherige einfache Hochrechnung bleibt zuständig.
			if ( empty( $fc['points'] ) || $fc['n'] < 8 ) {
				continue;
			}

			$cur_ts = strtotime( Period::bucket( 'woche', self::today() )['von'] . ' UTC' );
			$points = array();
			foreach ( $fc['points'] as $i => $p ) {
				$factor   = ForecastKpi::event_factor( $events, $cur_ts + $i * 7 * DAY_IN_SECONDS, $cur_ts + ( $i * 7 + 6 ) * DAY_IN_SECONDS );
				$points[] = array( 'mean' => $p['mean'] * $factor, 'sd' => $p['sd'] * $factor );
			}

			$d    = self::demand_over( $points, $set['cover_weeks'] );
			$want = $d['mean'] + $z * $d['sd'];
			$open = isset( $open_orders[ $article_id ] ) ? (float) $open_orders[ $article_id ] : 0.0;
			$need = (int) ceil( $want - $open - 0.0001 );

			if ( $need <= 0 ) {
				continue;
			}

			$out[] = array(
				'article_id' => (int) $article_id,
				'qty'        => (float) $need,
				'source'     => 'hochrechnung',
				'label'      => sprintf(
					/* translators: 1: Mittel je Woche, 2: Güte. */
					__( 'Prognose Ø %1$s je Woche (Güte: %2$s)', 'lindenweg16' ),
					round( $points[0]['mean'], 1 ),
					$fc['reliability']
				),
				'per_week'   => round( $points[0]['mean'], 2 ),
			);
		}

		return $out;
	}

	/* ====================================================================
	 * Planung: Plan, Kapazität, Zusage, Tagesplan
	 * ================================================================== */

	/**
	 * Kapazität je Woche.
	 *
	 * @return array {machine_h, operator_h, printers, plate_min}
	 */
	public static function capacity() {
		$settings = CalculationContext::settings();
		$printers = max( 1, count( PrinterRepository::all() ) );

		return array(
			'machine_h'  => max( 0.0, (float) $settings['net_hours_per_printer'] ) * $printers,
			'operator_h' => max( 0.0, (float) $settings['operator_hours_per_week'] ),
			'printers'   => $printers,
			'plate_min'  => (float) $settings['plate_change_minutes'],
		);
	}

	/**
	 * Schritte des Produktionsplans aus dem heutigen Bedarf.
	 *
	 * @return array Geordnete Schritte mit Aufwand.
	 */
	public static function plan_steps() {
		$cap      = self::capacity();
		$articles = ArticleRepository::all_by_id();
		$plan     = DemandPlanner::plan( DemandPlanner::collect( true ), DemandPlanner::context() );
		$steps    = array();

		foreach ( DemandPlanner::actionable( $plan['articles'] ) as $row ) {
			$id       = (int) $row['article_id'];
			$revision = RevisionRepository::active_for_article( $id );

			$effort = ProductionPlan::effort(
				$row,
				array(
					'print_time_h'       => $revision ? CostCalculator::print_time_per_part( $revision ) : 0,
					'parts_per_plate'    => $revision ? (int) $revision['parts_per_plate'] : 1,
					'postprocessing_min' => isset( $articles[ $id ]['postprocessing_min'] ) ? (float) $articles[ $id ]['postprocessing_min'] : 0,
				),
				$cap['plate_min']
			);

			$steps[] = array_merge(
				array(
					'article_id' => $id,
					'sku'        => (string) $row['sku'],
					'name'       => (string) $row['name'],
					'action'     => (string) $row['action'],
					'depth'      => (int) $row['depth'],
					'qty'        => (float) $row['net'],
				),
				$effort
			);
		}

		return ProductionPlan::order( $steps );
	}

	/**
	 * Tagesplan: heutiger Bedarf auf Drucker, Tag und Nacht verteilt.
	 *
	 * @return array
	 */
	public static function day_plan() {
		$cap   = self::capacity();
		$steps = self::plan_steps();
		$plan  = DayPlan::build(
			DayPlan::plates( $steps ),
			$cap['printers'],
			array(
				'plate_min'  => $cap['plate_min'],
				'operator_h' => $cap['operator_h'] / 5,
				'long_h'     => (float) \Lindenweg16\Erp\Workflow::settings()['long_h'],
			)
		);

		$totals = ProductionPlan::totals( ProductionPlan::schedule( $steps, $cap['machine_h'], $cap['operator_h'] ) );

		return array(
			'plan'     => $plan,
			'totals'   => $totals['total'],
			'weeks'    => count( $totals['weeks'] ),
			'capacity' => $cap,
			'hands'    => array_values(
				array_filter(
					$steps,
					static function ( $s ) {
						return 'drucken' !== $s['action'];
					}
				)
			),
		);
	}

	/**
	 * Lieferzusage für einen Artikel.
	 *
	 * @param int    $article_id Artikel.
	 * @param float  $qty        Menge.
	 * @param string $color      Farbe, leer = alle zusammen.
	 * @return array
	 */
	public static function promise( $article_id, $qty, $color = '' ) {
		$article = ArticleRepository::find( (int) $article_id );

		if ( ! $article ) {
			return array( 'error' => __( 'Artikel nicht gefunden.', 'lindenweg16' ) );
		}

		$balances = StockRepository::balances( (int) $article_id );
		$stock    = ShopStock::stock_by_color( isset( $balances[ (int) $article_id ] ) ? $balances[ (int) $article_id ] : array() );
		$reserved = ShopStock::reserved_by_color( WooIntegration::open_order_lines(), (int) $article_id );

		if ( '' !== $color ) {
			$avail = ShopStock::available( $stock, $reserved );
			$have  = isset( $avail[ $color ] ) ? (float) $avail[ $color ] : 0.0;
		} else {
			$have = (float) ShopStock::simple_quantity( $stock, $reserved );
		}

		$result = array(
			'article_id' => (int) $article_id,
			'name'       => (string) $article['name'],
			'qty'        => (float) $qty,
			'available'  => (int) $have,
		);

		if ( 'zukauf' === ( isset( $article['sourcing_type'] ) ? $article['sourcing_type'] : '' ) && $have < $qty ) {
			return array_merge( $result, array( 'immediate' => false, 'unknown' => true, 'hint' => __( 'Zukaufartikel: Die Lieferzeit bestimmt der Lieferant.', 'lindenweg16' ) ) );
		}

		$cap      = self::capacity();
		$revision = RevisionRepository::active_for_article( (int) $article_id );
		$per_part = $revision ? CostCalculator::print_time_per_part( $revision ) : 0.0;
		$per_plate = $revision ? max( 1, (int) $revision['parts_per_plate'] ) : 1;
		$post_min = isset( $article['postprocessing_min'] ) ? (float) $article['postprocessing_min'] : 0.0;
		$hands    = ( $post_min + $cap['plate_min'] / $per_plate ) / 60;

		$backlog = ProductionPlan::totals( self::plan_steps() )['total'];

		return array_merge(
			$result,
			Promise::earliest( $have, $qty, $per_part, $hands, $backlog['machine_h'], $backlog['operator_h'], $cap['machine_h'], $cap['operator_h'], time() )
		);
	}

	/**
	 * Verfügbarkeit und Lieferzeit aller Artikel für den Shop (kurz zwischengespeichert).
	 *
	 * @return array article_id => {color => verfügbar, days}
	 */
	public static function shop_map() {
		$key    = self::cache_key( 'shop' );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$balances = StockRepository::balances();
		$lines    = WooIntegration::open_order_lines();
		$cap      = self::capacity();
		$backlog  = null;
		$out      = array();

		foreach ( ArticleRepository::all_by_id() as $id => $article ) {
			$stock    = ShopStock::stock_by_color( isset( $balances[ $id ] ) ? $balances[ $id ] : array() );
			$reserved = ShopStock::reserved_by_color( $lines, $id );
			$all      = ShopStock::simple_quantity( $stock, $reserved );
			$weeks    = null;

			// Der Plan wird nur gerechnet, wenn irgendein Artikel knapp ist -
			// sonst braucht die Produktseite keine Lieferzeit.
			if ( $all <= 3 && 'zukauf' !== $article['sourcing_type'] ) {
				if ( null === $backlog ) {
					$backlog = ProductionPlan::totals( self::plan_steps() )['total'];
				}

				$revision = RevisionRepository::active_for_article( $id );
				$per_part = $revision ? CostCalculator::print_time_per_part( $revision ) : 0.0;
				$hands    = ( (float) $article['postprocessing_min'] + $cap['plate_min'] / ( $revision ? max( 1, (int) $revision['parts_per_plate'] ) : 1 ) ) / 60;
				$est      = Promise::earliest( 0, 1, $per_part, $hands, $backlog['machine_h'], $backlog['operator_h'], $cap['machine_h'], $cap['operator_h'], time() );
				$weeks    = $est['weeks'];
			}

			$out[ $id ] = array(
				'all'    => $all,
				'colors' => ShopStock::available( $stock, $reserved ),
				'weeks'  => $weeks,
			);
		}

		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );

		return $out;
	}

	/* ====================================================================
	 * Auslöser
	 * ================================================================== */

	/**
	 * Auslöser prüfen und Änderungen ins Ereignisprotokoll schreiben.
	 *
	 * Gemeldet wird nur, wenn sich die Stufe einer Kennzahl oder eines
	 * Artikels gegenüber dem letzten Lauf ändert.
	 *
	 * @return array {raised, recovered, levels}
	 */
	public static function run_triggers() {
		$levels = array();
		$text   = array();

		foreach ( self::kpi_overview( true ) as $kpi ) {
			$k            = 'kpi:' . $kpi['key'];
			$levels[ $k ] = ForecastKpi::level_of( $kpi['status'] );
			$text[ $k ]   = ForecastKpi::message( $kpi['label'], $kpi );
		}

		foreach ( self::article_outlook( true ) as $row ) {
			$k            = 'artikel:' . $row['article_id'];
			$levels[ $k ] = $row['level'];
			$text[ $k ]   = sprintf(
				/* translators: 1: Artikel, 2: Menge, 3: Bedarf. */
				__( '%1$s: Bestand %2$d reicht voraussichtlich nicht bis zum Nachschub (erwarteter Bedarf im Vorlauf %3$s).', 'lindenweg16' ),
				$row['name'],
				$row['available'],
				$row['demand_lead']
			);
		}

		$prev = get_option( self::STATE, array() );
		$prev = is_array( $prev ) ? $prev : array();
		$res  = array( 'raised' => 0, 'recovered' => 0 );

		foreach ( ForecastKpi::changes( $prev, $levels ) as $c ) {
			if ( 'raise' === $c['direction'] ) {
				$res['raised']++;
				if ( 'critical' === $c['to'] ) {
					Log::critical( 'prognose', $text[ $c['key'] ] );
				} else {
					Log::attention( 'prognose', $text[ $c['key'] ] );
				}
			} else {
				$res['recovered']++;
				Log::normal( 'prognose', sprintf( /* translators: %s: Meldung. */ __( 'Entspannt: %s', 'lindenweg16' ), $text[ $c['key'] ] ) );
			}
		}

		update_option( self::STATE, $levels, false );
		update_option( self::LAST_RUN, time(), false );

		$res['levels'] = $levels;

		return $res;
	}
}
