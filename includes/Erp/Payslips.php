<?php
/**
 * Lohnabrechnungen: Speichern, Festschreiben, Bezahlt, Storno.
 *
 * Eine Abrechnung durchläuft vier Zustände:
 * - Entwurf: frei änderbar, rechnet sich bei jedem Speichern aus den Zeiten neu.
 * - Festgeschrieben: bekommt ihre Nummer und eine Prüfsumme, bleibt unverändert.
 * - Bezahlt: dazu das Zahlungsdatum.
 * - Storniert: bleibt stehen, mit Grund. Eine Korrektur ist ein neuer Entwurf.
 *
 * Die Nummern laufen je Jahr lückenlos und werden erst beim Festschreiben
 * vergeben, damit ein verworfener Entwurf keine Lücke hinterlässt.
 *
 * Zeiten, die nach dem Festschreiben für den Zeitraum nachgetragen werden,
 * ändern die Abrechnung nicht; die Liste zeigt die Abweichung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Modules\Betrieb\TimeRepository;
use Lindenweg16\Modules\Betrieb\WageRepository;

/**
 * Zugriff auf die Tabelle der Abrechnungen.
 */
class Payslips {

	/**
	 * Zustände.
	 */
	const DRAFT = 'entwurf';
	const FINAL = 'festgeschrieben';
	const PAID  = 'bezahlt';
	const VOID  = 'storniert';

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lw16_payslips';
	}

	/**
	 * Bezeichnungen der Zustände.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			self::DRAFT => __( 'Entwurf', 'lindenweg16' ),
			self::FINAL => __( 'Festgeschrieben', 'lindenweg16' ),
			self::PAID  => __( 'Bezahlt', 'lindenweg16' ),
			self::VOID  => __( 'Storniert', 'lindenweg16' ),
		);
	}

	/**
	 * Eine Abrechnung.
	 *
	 * @param int $id Nummer in der Tabelle.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Abrechnungen, die einen Monat berühren.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	public static function between( $von, $bis ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE period_von <= %s AND period_bis >= %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bis,
				$von
			),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/**
	 * JSON-Felder auspacken und Zahlen als Zahlen liefern.
	 *
	 * @param array $row Zeile.
	 * @return array
	 */
	protected static function decode( array $row ) {
		$row['id']                = (int) $row['id'];
		$row['user_id']           = (int) $row['user_id'];
		$row['hours']             = (float) $row['hours'];
		$row['gross']             = (float) $row['gross'];
		$row['adjustments_total'] = (float) $row['adjustments_total'];
		$row['total']             = (float) $row['total'];
		$row['lines']             = self::json( $row['lines_json'] );
		$row['days']              = self::json( $row['days_json'] );
		$row['adjustments']       = self::json( $row['adjustments_json'] );

		return $row;
	}

	/**
	 * JSON-Liste lesen.
	 *
	 * @param mixed $text JSON.
	 * @return array
	 */
	protected static function json( $text ) {
		$data = json_decode( (string) $text, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Die noch nicht stornierte Abrechnung, die den Zeitraum berührt.
	 *
	 * @param int    $user_id    Person.
	 * @param string $von        Von.
	 * @param string $bis        Bis.
	 * @param int    $except_id  Diese Abrechnung nicht mitzählen.
	 * @return array|null
	 */
	public static function overlapping( $user_id, $von, $bis, $except_id = 0 ) {
		foreach ( self::between( $von, $bis ) as $slip ) {
			if ( (int) $slip['user_id'] === (int) $user_id && self::VOID !== $slip['status'] && (int) $slip['id'] !== (int) $except_id && Payroll::overlaps( $von, $bis, $slip['period_von'], $slip['period_bis'] ) ) {
				return $slip;
			}
		}

		return null;
	}

	/**
	 * Rechnung einer Person aus den aktuellen Zeiten.
	 *
	 * @param int    $user_id Person.
	 * @param string $von     Von.
	 * @param string $bis     Bis.
	 * @return array Ergebnis von Payroll::build().
	 */
	public static function compute( $user_id, $von, $bis ) {
		return Payroll::build( TimeRepository::between( $von, $bis ), WageRepository::all(), $user_id, $von, $bis );
	}

	/**
	 * Entwurf anlegen.
	 *
	 * @param int    $user_id Person.
	 * @param string $month   Monat JJJJ-MM.
	 * @param mixed  $adjust  Zuschläge und Abzüge.
	 * @param string $note    Bemerkung.
	 * @return array {ok, message, id}
	 */
	public static function create( $user_id, $month, $adjust, $note ) {
		global $wpdb;

		$bounds = Payroll::month_bounds( $month );
		$user   = get_userdata( (int) $user_id );

		if ( ! $bounds ) {
			return self::result( false, __( 'Der Monat ist ungültig. Bitte im Format Jahr-Monat wählen, zum Beispiel 2026-10.', 'lindenweg16' ) );
		}

		if ( ! $user ) {
			return self::result( false, __( 'Diese Person gibt es nicht mehr.', 'lindenweg16' ) );
		}

		$existing = self::overlapping( $user_id, $bounds['von'], $bounds['bis'] );

		if ( $existing ) {
			return self::result(
				false,
				sprintf(
					/* translators: 1: Name, 2: Monat, 3: Nummer oder Entwurf. */
					__( 'Für %1$s gibt es für %2$s schon eine Abrechnung (%3$s). Bearbeite sie oder storniere sie zuerst.', 'lindenweg16' ),
					$user->display_name,
					$bounds['label'],
					$existing['number'] ? $existing['number'] : __( 'Entwurf', 'lindenweg16' )
				)
			);
		}

		$calc = self::compute( $user_id, $bounds['von'], $bounds['bis'] );
		$adj  = Payroll::adjustments( $adjust );

		if ( $adj['error'] ) {
			return self::result( false, $adj['error'] );
		}

		if ( $calc['hours'] <= 0 && ! $adj['rows'] ) {
			return self::result( false, sprintf( /* translators: 1: Name, 2: Monat. */ __( 'Für %1$s sind im %2$s keine Stunden erfasst. Es gibt nichts abzurechnen.', 'lindenweg16' ), $user->display_name, $bounds['label'] ) );
		}

		$ok = $wpdb->insert(
			self::table(),
			self::values( $calc, $adj, $note ) + array(
				'user_id'       => (int) $user_id,
				'employee_name' => $user->display_name,
				'period_von'    => $bounds['von'],
				'period_bis'    => $bounds['bis'],
				'status'        => self::DRAFT,
				'created_at'    => current_time( 'mysql', true ),
				'created_by'    => get_current_user_id(),
			)
		);

		if ( ! $ok ) {
			return self::result( false, __( 'Die Abrechnung ließ sich nicht speichern. Bitte noch einmal versuchen.', 'lindenweg16' ) );
		}

		$id = (int) $wpdb->insert_id;
		AuditLog::record( 'erp_lohnabrechnung_entwurf', 'payslip', $id, array( 'person' => (int) $user_id, 'monat' => $month ) );

		return self::result( true, sprintf( /* translators: 1: Name, 2: Monat. */ __( 'Entwurf für %1$s, %2$s, angelegt.', 'lindenweg16' ), $user->display_name, $bounds['label'] ), $id );
	}

	/**
	 * Spaltenwerte aus Rechnung, Zuschlägen und Bemerkung.
	 *
	 * @param array  $calc Ergebnis von Payroll::build().
	 * @param array  $adj  Ergebnis von Payroll::adjustments().
	 * @param string $note Bemerkung.
	 * @return array
	 */
	protected static function values( array $calc, array $adj, $note ) {
		return array(
			'hours'             => $calc['hours'],
			'gross'             => $calc['gross'],
			'adjustments_total' => $adj['total'],
			'total'             => round( $calc['gross'] + $adj['total'], 2 ),
			'lines_json'        => wp_json_encode( $calc['lines'] ),
			'days_json'         => wp_json_encode( $calc['days'] ),
			'adjustments_json'  => wp_json_encode( $adj['rows'] ),
			'note'              => mb_substr( sanitize_text_field( (string) $note ), 0, 255 ),
		);
	}

	/**
	 * Entwurf ändern: Zuschläge, Abzüge, Bemerkung; Stunden werden neu gerechnet.
	 *
	 * @param int    $id     Abrechnung.
	 * @param mixed  $adjust Zuschläge und Abzüge.
	 * @param string $note   Bemerkung.
	 * @return array {ok, message, id}
	 */
	public static function update( $id, $adjust, $note ) {
		global $wpdb;

		$slip = self::find( $id );

		if ( ! $slip ) {
			return self::result( false, __( 'Diese Abrechnung gibt es nicht (mehr).', 'lindenweg16' ) );
		}

		if ( self::DRAFT !== $slip['status'] ) {
			return self::result( false, __( 'Nur ein Entwurf lässt sich ändern. Eine festgeschriebene Abrechnung bleibt, wie sie ist. Storniere sie und lege eine neue an.', 'lindenweg16' ), (int) $id );
		}

		$adj = Payroll::adjustments( $adjust );

		if ( $adj['error'] ) {
			return self::result( false, $adj['error'], (int) $id );
		}

		$calc = self::compute( $slip['user_id'], $slip['period_von'], $slip['period_bis'] );

		$wpdb->update( self::table(), self::values( $calc, $adj, $note ), array( 'id' => (int) $id ) );
		AuditLog::record( 'erp_lohnabrechnung_geaendert', 'payslip', (int) $id, array( 'person' => $slip['user_id'] ) );

		return self::result( true, __( 'Entwurf gespeichert, die Stunden sind neu aus den Zeiten gerechnet.', 'lindenweg16' ), (int) $id );
	}

	/**
	 * Entwurf festschreiben: neu rechnen, Nummer und Prüfsumme vergeben.
	 *
	 * @param int  $id    Abrechnung.
	 * @param bool $force Trotz überschrittener Obergrenze festschreiben.
	 * @return array {ok, message, id, needs_confirm}
	 */
	public static function finalize( $id, $force = false ) {
		global $wpdb;

		$slip = self::find( $id );

		if ( ! $slip || self::DRAFT !== $slip['status'] ) {
			return self::result( false, __( 'Nur ein Entwurf lässt sich festschreiben.', 'lindenweg16' ), (int) $id );
		}

		$calc = self::compute( $slip['user_id'], $slip['period_von'], $slip['period_bis'] );

		if ( $calc['unpriced_hours'] > 0 ) {
			return self::result(
				false,
				sprintf(
					/* translators: 1: Name, 2: Stunden. */
					__( 'Für %1$s fehlt der Stundenlohn für %2$s Stunden. Trag ihn unter Löhne mit passendem Stichtag ein, dann lässt sich die Abrechnung festschreiben.', 'lindenweg16' ),
					$slip['employee_name'],
					Payroll::hours_text( $calc['unpriced_hours'] )
				),
				(int) $id
			);
		}

		$adj    = Payroll::adjustments( $slip['adjustments'] );
		$values = self::values( $calc, $adj, (string) $slip['note'] );

		$over = self::over( self::check( $slip['user_id'], $slip['period_von'], $values['total'], $calc['hours'], (int) $id ) );

		if ( $over && ! $force ) {
			$result                  = self::result( false, sprintf( /* translators: 1: Name, 2: Aufzählung. */ __( 'Für %1$s ist eine Obergrenze überschritten: %2$s.', 'lindenweg16' ), $slip['employee_name'], implode( '; ', $over ) ), (int) $id );
			$result['needs_confirm'] = true;

			return $result;
		}

		$full   = array(
			'user_id'     => $slip['user_id'],
			'von'         => $slip['period_von'],
			'bis'         => $slip['period_bis'],
			'lines'       => $calc['lines'],
			'days'        => $calc['days'],
			'adjustments' => $adj['rows'],
			'gross'       => $calc['gross'],
			'total'       => $values['total'],
		);

		$year   = (int) substr( $slip['period_von'], 0, 4 );
		$number = Payroll::number_text( $year, self::next_sequence( $year ) );

		$wpdb->update(
			self::table(),
			$values + array(
				'number'       => $number,
				'status'       => self::FINAL,
				'checksum'     => Payroll::checksum( $full ),
				'finalized_at' => current_time( 'mysql', true ),
				'finalized_by' => get_current_user_id(),
			),
			array( 'id' => (int) $id )
		);

		AuditLog::record( 'erp_lohnabrechnung_festgeschrieben', 'payslip', (int) $id, array( 'nummer' => $number, 'person' => $slip['user_id'], 'grenze_ueberschritten' => $over ? $over : null ) );

		return self::result( true, sprintf( /* translators: %s: Nummer. */ __( 'Abrechnung %s festgeschrieben.', 'lindenweg16' ), $number ), (int) $id );
	}

	/**
	 * Nächste laufende Nummer im Jahr.
	 *
	 * @param int $year Jahr.
	 * @return int
	 */
	protected static function next_sequence( $year ) {
		global $wpdb;
		$table = self::table();
		$like  = $wpdb->esc_like( sprintf( 'LA-%04d-', $year ) ) . '%';
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(CAST(SUBSTRING(number, 9) AS UNSIGNED)) FROM {$table} WHERE number LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $max + 1;
	}

	/**
	 * Als bezahlt markieren.
	 *
	 * @param int    $id   Abrechnung.
	 * @param string $date Zahlungsdatum Y-m-d (leer = heute).
	 * @return array {ok, message, id}
	 */
	public static function mark_paid( $id, $date ) {
		global $wpdb;

		$slip = self::find( $id );

		if ( ! $slip || self::FINAL !== $slip['status'] ) {
			return self::result( false, __( 'Nur eine festgeschriebene Abrechnung lässt sich als bezahlt markieren.', 'lindenweg16' ), (int) $id );
		}

		$date = '' === trim( (string) $date ) ? gmdate( 'Y-m-d' ) : (string) $date;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return self::result( false, __( 'Das Datum ist ungültig. Bitte im Format Jahr-Monat-Tag eintragen, zum Beispiel 2026-11-05.', 'lindenweg16' ), (int) $id );
		}

		$wpdb->update( self::table(), array( 'status' => self::PAID, 'paid_on' => $date ), array( 'id' => (int) $id ) );
		AuditLog::record( 'erp_lohnabrechnung_bezahlt', 'payslip', (int) $id, array( 'nummer' => $slip['number'], 'am' => $date ) );

		return self::result( true, sprintf( /* translators: 1: Nummer, 2: Datum. */ __( '%1$s als bezahlt am %2$s markiert.', 'lindenweg16' ), $slip['number'], Payroll::dmy( $date ) ), (int) $id );
	}

	/**
	 * Stornieren: bleibt stehen, mit Grund.
	 *
	 * @param int    $id     Abrechnung.
	 * @param string $reason Grund.
	 * @return array {ok, message, id}
	 */
	public static function void( $id, $reason ) {
		global $wpdb;

		$slip   = self::find( $id );
		$reason = trim( sanitize_text_field( (string) $reason ) );

		if ( ! $slip || ! in_array( $slip['status'], array( self::FINAL, self::PAID ), true ) ) {
			return self::result( false, __( 'Nur eine festgeschriebene oder bezahlte Abrechnung lässt sich stornieren. Einen Entwurf löschst du.', 'lindenweg16' ), (int) $id );
		}

		if ( '' === $reason ) {
			return self::result( false, __( 'Bitte einen Grund für die Stornierung eintragen. Er bleibt an der Abrechnung stehen.', 'lindenweg16' ), (int) $id );
		}

		$wpdb->update(
			self::table(),
			array(
				'status'      => self::VOID,
				'voided_at'   => current_time( 'mysql', true ),
				'voided_by'   => get_current_user_id(),
				'void_reason' => mb_substr( $reason, 0, 255 ),
			),
			array( 'id' => (int) $id )
		);
		AuditLog::record( 'erp_lohnabrechnung_storniert', 'payslip', (int) $id, array( 'nummer' => $slip['number'], 'grund' => $reason ) );

		return self::result( true, sprintf( /* translators: %s: Nummer. */ __( '%s storniert.', 'lindenweg16' ), $slip['number'] ), (int) $id );
	}

	/**
	 * Entwurf löschen.
	 *
	 * @param int $id Abrechnung.
	 * @return array {ok, message, id}
	 */
	public static function delete_draft( $id ) {
		global $wpdb;

		$slip = self::find( $id );

		if ( ! $slip || self::DRAFT !== $slip['status'] ) {
			return self::result( false, __( 'Nur ein Entwurf lässt sich löschen.', 'lindenweg16' ), (int) $id );
		}

		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		AuditLog::record( 'erp_lohnabrechnung_entwurf_geloescht', 'payslip', (int) $id, array( 'person' => $slip['user_id'] ) );

		return self::result( true, __( 'Entwurf gelöscht.', 'lindenweg16' ), (int) $id );
	}

	/**
	 * Abweichung der heutigen Zeiten gegen eine festgeschriebene Abrechnung.
	 *
	 * @param array $slip Abrechnung.
	 * @return float Stunden mehr (plus) oder weniger (minus) als abgerechnet.
	 */
	public static function drift( array $slip ) {
		if ( ! in_array( $slip['status'], array( self::FINAL, self::PAID ), true ) ) {
			return 0.0;
		}

		$calc = self::compute( $slip['user_id'], $slip['period_von'], $slip['period_bis'] );

		return round( $calc['hours'] - (float) $slip['hours'], 2 );
	}

	/**
	 * Obergrenzen einer Person.
	 *
	 * @param int $user_id Person.
	 * @return array {gross_month, hours_month, gross_year}; null = keine Grenze.
	 */
	public static function limits( $user_id ) {
		return Payroll::limits_clean( get_user_meta( (int) $user_id, 'lw16_pay_limits', true ) )['limits'];
	}

	/**
	 * Obergrenzen einer Person festlegen.
	 *
	 * @param int   $user_id Person.
	 * @param mixed $raw     Eingabe.
	 * @return array {ok, message, id}
	 */
	public static function set_limits( $user_id, $raw ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return self::result( false, __( 'Diese Person gibt es nicht mehr.', 'lindenweg16' ) );
		}

		$clean = Payroll::limits_clean( $raw );

		if ( $clean['error'] ) {
			return self::result( false, $clean['error'] );
		}

		update_user_meta( (int) $user_id, 'lw16_pay_limits', $clean['limits'] );
		AuditLog::record( 'erp_lohn_grenzen', 'user', (int) $user_id, $clean['limits'] );

		return self::result( true, sprintf( /* translators: %s: Name. */ __( 'Obergrenzen für %s gespeichert.', 'lindenweg16' ), $user->display_name ), (int) $user_id );
	}

	/**
	 * Prüfung einer Person und eines Monats gegen ihre Grenzen.
	 *
	 * Das Jahr zählt alle noch gültigen Abrechnungen des Kalenderjahres und
	 * diese als Letzte dazu; eine schon gezählte Abrechnung wird nicht doppelt genommen.
	 *
	 * @param int    $user_id   Person.
	 * @param string $von       Beginn des Monats.
	 * @param float  $gross     Verdienst im Monat (brutto, mit Zuschlägen).
	 * @param float  $hours     Stunden im Monat.
	 * @param int    $except_id Abrechnung, die nicht zusätzlich zählt.
	 * @return array Zeilen aus Payroll::checks().
	 */
	public static function check( $user_id, $von, $gross, $hours, $except_id = 0 ) {
		$limits = self::limits( $user_id );

		if ( null === $limits['gross_year'] && null === $limits['gross_month'] && null === $limits['hours_month'] ) {
			return array();
		}

		$sum = null !== $limits['gross_year'] ? self::year_before( $user_id, $von, $except_id ) : 0.0;

		return Payroll::checks( $limits, $gross, $hours, $sum + $gross );
	}

	/**
	 * Verdienst des Kalenderjahres aus den übrigen gültigen Abrechnungen.
	 *
	 * Der Monat dieser Abrechnung zählt nicht mit, er wird dazugerechnet.
	 *
	 * @param int    $user_id   Person.
	 * @param string $von       Beginn des Monats.
	 * @param int    $except_id Abrechnung, die nicht zählt.
	 * @return float
	 */
	public static function year_before( $user_id, $von, $except_id = 0 ) {
		$year = substr( $von, 0, 4 );
		$sum  = 0.0;

		foreach ( self::between( $year . '-01-01', $year . '-12-31' ) as $slip ) {
			if ( $slip['user_id'] === (int) $user_id && self::VOID !== $slip['status'] && (int) $slip['id'] !== (int) $except_id && substr( $slip['period_von'], 0, 7 ) !== substr( $von, 0, 7 ) ) {
				$sum += $slip['total'];
			}
		}

		return round( $sum, 2 );
	}

	/**
	 * Überschrittene Grenzen als Sätze.
	 *
	 * @param array $checks Zeilen aus check().
	 * @return array
	 */
	public static function over( array $checks ) {
		$out = array();

		foreach ( $checks as $row ) {
			if ( 'ueber' === $row['state'] ) {
				$out[] = Payroll::over_text( $row );
			}
		}

		return $out;
	}

	/**
	 * Ergebnis einer Aktion.
	 *
	 * @param bool   $ok      Erfolg.
	 * @param string $message Meldung.
	 * @param int    $id      Abrechnung.
	 * @return array
	 */
	protected static function result( $ok, $message, $id = 0 ) {
		return array( 'ok' => (bool) $ok, 'message' => (string) $message, 'id' => (int) $id );
	}
}
