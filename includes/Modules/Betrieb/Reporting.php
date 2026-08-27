<?php
/**
 * Reporting-Dienst: alle Kennzahlen für einen Zeitraum.
 *
 * Die Beschaffung der Werte liegt hier, ihre Bewertung in Kpi. So bleibt die
 * Bewertungslogik ohne Datenbank prüfbar.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Berechnet die Steuerungskennzahlen.
 */
class Reporting {

	/**
	 * Wie viele Bestellungen gleichzeitig im Speicher stehen dürfen.
	 */
	const STAPEL = 500;

	/**
	 * Alle Kennzahlen eines Zeitraums.
	 *
	 * @param string $von Von (Y-m-d).
	 * @param string $bis Bis (Y-m-d).
	 * @return array metric_key => Wert oder null.
	 */
	public static function metrics( $von, $bis ) {
		$orders = self::orders_in_period( $von, $bis );

		$umsatz       = 0.0;
		$umsatz_b2b   = 0.0;
		$handling     = array();
		$complaints   = 0;
		$count        = 0;

		foreach ( $orders as $order ) {
			$value   = OrderOpsRepository::order_value( $order );
			$umsatz += $value;
			$count++;

			if ( 'B2B' === $order['customer_type'] ) {
				$umsatz_b2b += $value;
			}
			if ( null !== $order['handling_minutes'] ) {
				$handling[] = (float) $order['handling_minutes'];
			}
			if ( $order['complaint'] ) {
				$complaints++;
			}
		}

		$production = PrintJobRepository::aggregate( $von, $bis );
		$settings   = CalcSettings::for_date( $bis );
		$printers   = count(
			array_filter(
				PrinterRepository::all(),
				static function ( $printer ) {
					return in_array( $printer['status'], MachineRate::PRODUCTIVE_STATUS, true );
				}
			)
		);

		$wochen        = max( 1, ( strtotime( $bis ) - strtotime( $von ) ) / ( 7 * DAY_IN_SECONDS ) );
		$kapazitaet    = $printers * (float) $settings['net_hours_per_printer'] * $wochen;
		$bedienzeit_h  = CapacityPlanner::operator_hours( $production['plate_changes'], (float) $settings['plate_change_minutes'] );
		$arbeitsstunden = self::work_hours( $von, $bis );

		return array(
			'umsatz'                  => $umsatz,
			'bestellungen'            => (float) $count,
			'warenkorb'               => $count > 0 ? $umsatz / $count : null,
			'handlingzeit'            => $handling ? array_sum( $handling ) / count( $handling ) : null,
			'b2b_anteil'              => $umsatz > 0 ? $umsatz_b2b / $umsatz * 100 : null,
			'reklamationsquote'       => $count > 0 ? $complaints / $count * 100 : null,
			'ausschussquote'          => $production['parts_planned'] > 0 ? $production['scrap_rate'] * 100 : null,
			'teile_je_plattenwechsel' => $production['plate_changes'] > 0 ? $production['parts_per_plate_change'] : null,
			'auslastung'              => $kapazitaet > 0 ? $production['print_time_h'] / $kapazitaet * 100 : null,
			'bedienzeit_anteil'       => $arbeitsstunden > 0 ? $bedienzeit_h / $arbeitsstunden * 100 : null,
			'wochenstunden'           => $wochen > 0 ? $arbeitsstunden / $wochen : null,
			'wiederkaufsrate'         => self::repeat_rate(),
		);
	}

	/**
	 * Bestellungen eines Zeitraums.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	public static function orders_in_period( $von, $bis ) {
		$result = array();

		foreach ( OrderOpsRepository::all( array( 'limit' => 5000 ) ) as $order ) {
			if ( 'bestaetigt' !== $order['order_status'] ) {
				continue;
			}

			$date = OrderOpsRepository::order_date( $order );

			if ( $date && $date >= $von && $date <= $bis ) {
				$result[] = $order;
			}
		}

		return $result;
	}

	/**
	 * Erfasste Arbeitsstunden eines Zeitraums.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return float
	 */
	public static function work_hours( $von, $bis ) {
		global $wpdb;

		$table = TimeRepository::table();

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( hours ), 0 ) FROM {$table} WHERE entry_date BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$von,
				$bis
			)
		);
	}

	/**
	 * Wiederkaufsrate der letzten zwölf Monate.
	 *
	 * Ein Kunde, der einmal kauft, ist ein Zufall; einer, der wiederkommt,
	 * ist ein Kanal.
	 *
	 * @return float|null
	 */
	public static function repeat_rate() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$per_customer = array();

		// Ein Jahr Bestellungen auf einmal als vollständige Objekte zu
		// laden, wächst sich mit dem Shop zum Speicherproblem aus –
		// gebraucht werden je Bestellung genau zwei Angaben. Also
		// stapelweise, gezählt wird wie zuvor.
		$seite = 1;

		do {
			$orders = wc_get_orders(
				array(
					'limit'        => self::STAPEL,
					'paged'        => $seite,
					'status'       => array( 'completed', 'processing' ),
					'date_created' => '>' . ( time() - 365 * DAY_IN_SECONDS ),
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'return'       => 'objects',
				)
			);

			foreach ( (array) $orders as $order ) {
				$key = $order->get_customer_id() ? 'u' . $order->get_customer_id() : 'e' . strtolower( $order->get_billing_email() );

				if ( '' === trim( str_replace( array( 'u', 'e' ), '', $key ) ) ) {
					continue;
				}

				$per_customer[ $key ] = isset( $per_customer[ $key ] ) ? $per_customer[ $key ] + 1 : 1;
			}

			$seite++;
		} while ( count( (array) $orders ) === self::STAPEL );

		if ( ! $per_customer ) {
			return null;
		}

		$repeat = count(
			array_filter(
				$per_customer,
				static function ( $count ) {
					return $count >= 2;
				}
			)
		);

		return $repeat / count( $per_customer ) * 100;
	}

	/**
	 * Alarmierende Kennzahlen eines Zeitraums.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	public static function alarms( $von, $bis ) {
		$alarms = array();

		foreach ( self::metrics( $von, $bis ) as $key => $value ) {
			$evaluation = Kpi::evaluate( $key, $value );

			if ( 'rot' === $evaluation['level'] ) {
				$definitions = Kpi::definitions();
				$alarms[]    = array(
					'key'   => $key,
					'label' => isset( $definitions[ $key ] ) ? $definitions[ $key ]['label'] : $key,
					'value' => Kpi::format( $key, $value ),
				);
			}
		}

		return $alarms;
	}

	/**
	 * Monatsabschluss: automatische Kennzahlen einfrieren.
	 *
	 * @param int $year  Jahr.
	 * @param int $month Monat.
	 * @return int Anzahl eingefrorener Werte.
	 */
	public static function freeze_month( $year, $month ) {
		global $wpdb;

		$year  = (int) $year;
		$month = (int) $month;
		$von   = sprintf( '%04d-%02d-01', $year, $month );
		$bis   = gmdate( 'Y-m-t', strtotime( $von ) );

		$table = $wpdb->prefix . 'lw16_kpi_snapshots';
		$count = 0;

		// Der Bestand des Monats kommt in einer Abfrage, nicht in einer je
		// Kennzahl. Es sind rund zwei Dutzend – und der Abschluss läuft im
		// Hintergrundjob, wo jede eingesparte Rundreise zur Datenbank zählt.
		$vorhanden = array();

		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE period_year = %d AND period_month = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$year,
				$month
			),
			ARRAY_A
		) as $row ) {
			$vorhanden[ (string) $row['metric_key'] ] = $row;
		}

		foreach ( self::metrics( $von, $bis ) as $key => $value ) {
			if ( null === $value ) {
				continue;
			}

			$existing = isset( $vorhanden[ $key ] ) ? $vorhanden[ $key ] : null;

			// Bereits eingefrorene Werte bleiben stehen – das ist der Zweck
			// des Abschlusses.
			if ( $existing && $existing['frozen_at'] ) {
				continue;
			}

			$data = array(
				'period_year'   => $year,
				'period_month'  => $month,
				'metric_key'    => $key,
				'value_decimal' => (float) $value,
				'is_manual'     => 0,
				'frozen_at'     => current_time( 'mysql', true ),
			);

			if ( $existing ) {
				$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
			} else {
				$data['created_at'] = current_time( 'mysql', true );
				$data['created_by'] = get_current_user_id();
				$wpdb->insert( $table, $data );
			}

			$count++;
		}

		BetriebAudit::record(
			'create',
			'kpi_snapshots',
			0,
			null,
			array( 'year' => $year, 'month' => $month, 'count' => $count ),
			__( 'Monatsabschluss', 'lindenweg16' )
		);

		return $count;
	}

	/**
	 * Eingefrorene Werte eines Monats.
	 *
	 * @param int $year  Jahr.
	 * @param int $month Monat.
	 * @return array
	 */
	public static function snapshot( $year, $month ) {
		global $wpdb;

		$table = $wpdb->prefix . 'lw16_kpi_snapshots';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE period_year = %d AND period_month = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $year,
				(int) $month
			),
			ARRAY_A
		);

		$values = array();
		foreach ( (array) $rows as $row ) {
			$values[ $row['metric_key'] ] = (float) $row['value_decimal'];
		}

		return $values;
	}

	/**
	 * B2B-Auswertung je Händler.
	 *
	 * @return array
	 */
	public static function b2b() {
		$result = array();

		foreach ( ContactRepository::all() as $contact ) {
			$orders = OrderOpsRepository::all( array( 'contact_id' => (int) $contact['id'], 'limit' => 1000 ) );
			$orders = array_values(
				array_filter(
					$orders,
					static function ( $order ) {
						return 'bestaetigt' === $order['order_status'];
					}
				)
			);

			if ( ! $orders ) {
				continue;
			}

			$revenue = 0.0;
			$dates   = array();

			foreach ( $orders as $order ) {
				$revenue += OrderOpsRepository::order_value( $order );
				$date     = OrderOpsRepository::order_date( $order );
				if ( $date ) {
					$dates[] = $date;
				}
			}

			sort( $dates );

			$first_contact = $contact['created_at'] ? substr( $contact['created_at'], 0, 10 ) : null;
			$first_order   = $dates ? $dates[0] : null;

			$result[] = array(
				'contact'        => $contact,
				'orders'         => count( $orders ),
				'revenue'        => $revenue,
				'reorder'        => count( $orders ) >= 2,
				'days_to_first'  => ( $first_contact && $first_order )
					? (int) round( ( strtotime( $first_order ) - strtotime( $first_contact ) ) / DAY_IN_SECONDS )
					: null,
			);
		}

		usort(
			$result,
			static function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);

		return $result;
	}

	/**
	 * Nachbestellquote: Anteil der Händler mit mehr als einer Bestellung.
	 *
	 * Die entscheidende Zahl im B2B – ein Händler, der einmal bestellt, ist
	 * ein Zufall; einer, der nachbestellt, ist ein Kanal.
	 *
	 * @return float|null
	 */
	public static function reorder_rate() {
		$rows = self::b2b();

		if ( ! $rows ) {
			return null;
		}

		$repeat = count(
			array_filter(
				$rows,
				static function ( $row ) {
					return $row['reorder'];
				}
			)
		);

		return $repeat / count( $rows ) * 100;
	}
}
