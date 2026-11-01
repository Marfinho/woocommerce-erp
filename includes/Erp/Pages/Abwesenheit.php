<?php
/**
 * ERP-Seite „Urlaub & Abwesenheit“: beantragen, genehmigen, Resturlaub, Team-Kalender.
 *
 * - Urlaub wird beantragt und von jemandem mit „Urlaub genehmigen“ entschieden.
 * - Krankheit ist eine Meldung, keine Bitte: sie gilt sofort.
 * - Arbeitstage zählen Montag bis Freitag ohne gesetzliche Feiertage
 *   (bundesweit plus die des eingestellten Bundeslandes).
 * - Urlaubsanspruch je Person und Jahr (Vorgabe in den Einstellungen,
 *   abweichend je Person im Nutzermerker `lw16_urlaubstage`).
 * - Datenschutz: Wer nicht genehmigen darf, sieht bei anderen nur „abwesend“,
 *   nie den Grund – Krankheit ist ein Gesundheitsdatum.
 *
 * Reine Regeln sind getestet (tests/ErpAbwesenheitTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Urlaub und Abwesenheit.
 */
class Abwesenheit {

	/**
	 * Nutzermerker mit dem Urlaubsanspruch (Tage je Jahr).
	 */
	const META_DAYS = 'lw16_urlaubstage';

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Arten.
	 *
	 * @return array
	 */
	public static function kinds() {
		return array(
			'urlaub'      => __( 'Urlaub', 'lindenweg16' ),
			'krank'       => __( 'Krank', 'lindenweg16' ),
			'sonderurlaub' => __( 'Sonderurlaub', 'lindenweg16' ),
			'fortbildung' => __( 'Fortbildung', 'lindenweg16' ),
			'sonstiges'   => __( 'Sonstiges', 'lindenweg16' ),
		);
	}

	/**
	 * Bundesländer.
	 *
	 * @return array
	 */
	public static function states() {
		return array(
			''   => __( 'nur bundesweite Feiertage', 'lindenweg16' ),
			'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg', 'HB' => 'Bremen', 'HH' => 'Hamburg',
			'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern', 'NI' => 'Niedersachsen', 'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz',
			'SL' => 'Saarland', 'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt', 'SH' => 'Schleswig-Holstein', 'TH' => 'Thüringen',
		);
	}

	/**
	 * Ostersonntag (gregorianisch).
	 *
	 * @param int $year Jahr.
	 * @return string JJJJ-MM-TT
	 */
	public static function easter( $year ) {
		$a = $year % 19;
		$b = intdiv( $year, 100 );
		$c = $year % 100;
		$d = intdiv( $b, 4 );
		$e = $b % 4;
		$f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 );
		$k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Gesetzliche Feiertage eines Jahres.
	 *
	 * @param int    $year Jahr.
	 * @param string $land Kürzel des Bundeslandes (leer = nur bundesweit).
	 * @return array Datum => Name
	 */
	public static function holidays( $year, $land = '' ) {
		$e   = self::easter( (int) $year );
		$add = function ( $days ) use ( $e ) {
			return Kit::add_days( $e, $days );
		};
		$y   = (int) $year;
		$out = array(
			"$y-01-01" => 'Neujahr',
			$add( -2 ) => 'Karfreitag',
			$add( 1 )  => 'Ostermontag',
			"$y-05-01" => 'Tag der Arbeit',
			$add( 39 ) => 'Christi Himmelfahrt',
			$add( 50 ) => 'Pfingstmontag',
			"$y-10-03" => 'Tag der Deutschen Einheit',
			"$y-12-25" => '1. Weihnachtstag',
			"$y-12-26" => '2. Weihnachtstag',
		);

		$in = function ( array $lands ) use ( $land ) {
			return in_array( $land, $lands, true );
		};

		if ( $in( array( 'BW', 'BY', 'ST' ) ) ) {
			$out[ "$y-01-06" ] = 'Heilige Drei Könige';
		}
		if ( $in( array( 'BE', 'MV' ) ) ) {
			$out[ "$y-03-08" ] = 'Internationaler Frauentag';
		}
		if ( $in( array( 'BW', 'BY', 'HE', 'NW', 'RP', 'SL' ) ) ) {
			$out[ $add( 60 ) ] = 'Fronleichnam';
		}
		if ( $in( array( 'SL' ) ) ) {
			$out[ "$y-08-15" ] = 'Mariä Himmelfahrt';
		}
		if ( $in( array( 'TH' ) ) ) {
			$out[ "$y-09-20" ] = 'Weltkindertag';
		}
		if ( $in( array( 'BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'ST', 'SH', 'TH' ) ) ) {
			$out[ "$y-10-31" ] = 'Reformationstag';
		}
		if ( $in( array( 'BW', 'BY', 'NW', 'RP', 'SL' ) ) ) {
			$out[ "$y-11-01" ] = 'Allerheiligen';
		}
		if ( $in( array( 'SN' ) ) ) {
			// Mittwoch vor dem 23. November.
			$ts                         = strtotime( "$y-11-22 12:00:00 UTC" );
			$back                       = ( (int) gmdate( 'N', $ts ) + 4 ) % 7;
			$out[ gmdate( 'Y-m-d', $ts - $back * 86400 ) ] = 'Buß- und Bettag';
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Arbeitstage in einem Zeitraum.
	 *
	 * @param string $from     JJJJ-MM-TT.
	 * @param string $to       JJJJ-MM-TT.
	 * @param string $land     Bundesland.
	 * @param bool   $half_day Halber Tag (nur bei einem einzelnen Tag).
	 * @return float
	 */
	public static function workdays( $from, $to, $land = '', $half_day = false ) {
		if ( '' === $from || '' === $to || $to < $from ) {
			return 0.0;
		}

		$days = 0;
		$hol  = array();
		$ts   = strtotime( $from . ' 12:00:00 UTC' );
		$end  = strtotime( $to . ' 12:00:00 UTC' );

		for ( $i = 0; $ts <= $end && $i < 800; $i++, $ts += 86400 ) {
			$date = gmdate( 'Y-m-d', $ts );
			$year = (int) substr( $date, 0, 4 );
			if ( ! isset( $hol[ $year ] ) ) {
				$hol[ $year ] = self::holidays( $year, $land );
			}
			if ( (int) gmdate( 'N', $ts ) <= 5 && ! isset( $hol[ $year ][ $date ] ) ) {
				++$days;
			}
		}

		if ( $half_day && $from === $to && 1 === $days ) {
			return 0.5;
		}

		return (float) $days;
	}

	/**
	 * Überschneiden sich zwei Zeiträume?
	 *
	 * @param string $a_from Von A.
	 * @param string $a_to   Bis A.
	 * @param string $b_from Von B.
	 * @param string $b_to   Bis B.
	 * @return bool
	 */
	public static function overlaps( $a_from, $a_to, $b_from, $b_to ) {
		return $a_from <= $b_to && $b_from <= $a_to;
	}

	/**
	 * Urlaubskonto eines Jahres.
	 *
	 * @param float $entitlement Anspruch.
	 * @param array $absences    Abwesenheiten der Person (kind, status, days, date_from).
	 * @param int    $year        Jahr.
	 * @param string $today       Heute (genommen = begonnen bis heute).
	 * @return array {anspruch, genommen, geplant, beantragt, rest}
	 */
	public static function balance( $entitlement, array $absences, $year, $today ) {
		$taken   = 0.0;
		$planned = 0.0;
		$pending = 0.0;

		foreach ( $absences as $a ) {
			if ( 'urlaub' !== $a['kind'] || (int) substr( (string) $a['date_from'], 0, 4 ) !== (int) $year ) {
				continue;
			}
			if ( 'genehmigt' === $a['status'] ) {
				if ( (string) $a['date_from'] <= $today ) {
					$taken += (float) $a['days'];
				} else {
					$planned += (float) $a['days'];
				}
			} elseif ( 'beantragt' === $a['status'] ) {
				$pending += (float) $a['days'];
			}
		}

		return array(
			'anspruch'  => (float) $entitlement,
			'genommen'  => $taken,
			'geplant'   => $planned,
			'beantragt' => $pending,
			'rest'      => (float) $entitlement - $taken - $planned - $pending,
		);
	}

	/**
	 * Urlaubskonto mit Übertrag aus dem Vorjahr.
	 *
	 * Der Rest des Vorjahres wird zuerst verbraucht – von Urlaub, der bis zum
	 * Verfallstag beginnt. Was danach übrig ist, verfällt am Tag danach.
	 *
	 * @param float  $entitlement Anspruch dieses Jahres.
	 * @param float  $carry       Rest aus dem Vorjahr (≥ 0).
	 * @param array  $absences    Abwesenheiten dieses Jahres.
	 * @param int    $year        Jahr.
	 * @param string $today       Heute.
	 * @param string $expiry      Verfallstag MM-TT (leer = verfällt nicht).
	 * @return array balance() plus {uebertrag, verfallen}
	 */
	public static function balance_with_carry( $entitlement, $carry, array $absences, $year, $today, $expiry ) {
		$carry = max( 0.0, (float) $carry );
		$base  = self::balance( $entitlement, $absences, $year, $today );
		$lost  = 0.0;

		if ( $carry > 0 && preg_match( '/^\d{2}-\d{2}$/', (string) $expiry ) ) {
			$until = $year . '-' . $expiry;
			$early = 0.0;
			foreach ( $absences as $a ) {
				if ( 'urlaub' === $a['kind'] && in_array( $a['status'], array( 'genehmigt', 'beantragt' ), true ) && (int) substr( (string) $a['date_from'], 0, 4 ) === (int) $year && (string) $a['date_from'] <= $until ) {
					$early += (float) $a['days'];
				}
			}
			if ( $today > $until ) {
				$lost = max( 0.0, $carry - $early );
			}
		}

		$base['uebertrag'] = $carry;
		$base['verfallen'] = $lost;
		$base['rest']      = $base['rest'] + $carry - $lost;

		return $base;
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns      = RestApi::NAMESPACE_V1;
		$needs   = $api->needs( array( 'verwaltung.abwesenheit' ) );
		$approve = $api->needs( array( 'verwaltung.abwesenheit', 'verwaltung.abwesenheit.genehmigen' ) );

		register_rest_route( $ns, '/erp/abwesenheit', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $needs ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $needs ),
		) );
		register_rest_route( $ns, '/erp/abwesenheit/(?P<id>\d+)/entscheiden', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'decide' ), 'permission_callback' => $approve ) );
		register_rest_route( $ns, '/erp/abwesenheit/(?P<id>\d+)/zurueckziehen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'withdraw' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/abwesenheit/anspruch', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_entitlement' ), 'permission_callback' => $approve ) );
	}

	/**
	 * Darf genehmigen?
	 *
	 * @return bool
	 */
	protected static function can_approve() {
		return Access::can( 'verwaltung.abwesenheit.genehmigen' );
	}

	/**
	 * Anspruch einer Person.
	 *
	 * @param int $user_id Person.
	 * @return float
	 */
	protected static function entitlement( $user_id ) {
		$own = get_user_meta( (int) $user_id, self::META_DAYS, true );

		return '' !== $own && null !== $own ? (float) $own : (float) Kit::settings( 'urlaub' )['tage_standard'];
	}

	/**
	 * Konto einer Person für das laufende Jahr mit Übertrag.
	 *
	 * @param int    $user_id Person.
	 * @param int    $year    Jahr.
	 * @param string $today   Heute.
	 * @return array
	 */
	protected static function account_of( $user_id, $year, $today ) {
		$s    = Kit::settings( 'urlaub' );
		$prev = self::rows( "user_id = %d AND date_from >= %s AND date_from <= %s", array( (int) $user_id, ( $year - 1 ) . '-01-01', ( $year - 1 ) . '-12-31' ) );
		$cur  = self::rows( "user_id = %d AND date_from >= %s AND date_from <= %s", array( (int) $user_id, $year . '-01-01', $year . '-12-31' ) );
		$rest = (int) $s['uebertrag'] ? self::balance( self::entitlement( $user_id ), $prev, $year - 1, ( $year - 1 ) . '-12-31' )['rest'] : 0.0;

		return self::balance_with_carry( self::entitlement( $user_id ), $rest, $cur, $year, $today, (int) $s['uebertrag'] ? (string) $s['verfall'] : '' );
	}

	/**
	 * Personen, die im ERP arbeiten.
	 *
	 * @return array id => Name
	 */
	protected static function people() {
		$out = array();

		foreach ( get_users( array( 'capability__in' => array( 'lw16_capture', 'lw16_manage_production', 'lw16_manage_users' ), 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
			$out[ (int) $u->ID ] = (string) $u->display_name;
		}

		$me = get_current_user_id();
		if ( $me && ! isset( $out[ $me ] ) ) {
			$out[ $me ] = (string) wp_get_current_user()->display_name;
		}

		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );

		return $out;
	}

	/**
	 * Abwesenheiten laden.
	 *
	 * @param string $where Bedingung.
	 * @param array  $args  Werte.
	 * @return array
	 */
	protected static function rows( $where, array $args ) {
		global $wpdb;

		$t   = Kit::table( 'absences' );
		$sql = "SELECT * FROM {$t} WHERE {$where} ORDER BY date_from ASC, id ASC";

		return (array) ( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Für die Oberfläche – ohne Grund, wenn die Person ihn nicht sehen darf.
	 *
	 * @param array $a       Abwesenheit.
	 * @param array $people  Namen.
	 * @param bool  $full    Grund zeigen.
	 * @return array
	 */
	protected static function out( array $a, array $people, $full ) {
		$kinds = self::kinds();
		$mine  = (int) $a['user_id'] === get_current_user_id();
		$show  = $full || $mine;

		return array(
			'id'         => (int) $a['id'],
			'user_id'    => (int) $a['user_id'],
			'name'       => isset( $people[ (int) $a['user_id'] ] ) ? $people[ (int) $a['user_id'] ] : '#' . (int) $a['user_id'],
			'kind'       => $show ? (string) $a['kind'] : 'abwesend',
			'kind_label' => $show ? ( isset( $kinds[ $a['kind'] ] ) ? $kinds[ $a['kind'] ] : (string) $a['kind'] ) : __( 'abwesend', 'lindenweg16' ),
			'from'       => (string) $a['date_from'],
			'to'         => (string) $a['date_to'],
			'half_day'   => (bool) $a['half_day'],
			'days'       => (float) $a['days'],
			'status'     => (string) $a['status'],
			'note'       => $show ? (string) $a['note'] : '',
			'decision'   => $show ? (string) $a['decision_note'] : '',
			'mine'       => $mine,
		);
	}

	/**
	 * Übersicht: eigenes Konto, Kalender des Monats, offene Anträge.
	 *
	 * @param WP_REST_Request $request Anfrage (monat JJJJ-MM).
	 * @return WP_REST_Response
	 */
	public static function overview( WP_REST_Request $request ) {
		$today   = current_time( 'Y-m-d' );
		$month   = preg_match( '/^\d{4}-\d{2}$/', (string) $request->get_param( 'monat' ) ) ? (string) $request->get_param( 'monat' ) : substr( $today, 0, 7 );
		$m_from  = $month . '-01';
		$m_to    = gmdate( 'Y-m-t', strtotime( $m_from . ' 12:00:00 UTC' ) );
		$year    = (int) substr( $today, 0, 4 );
		$me      = get_current_user_id();
		$full    = self::can_approve();
		$people  = self::people();
		$s       = Kit::settings( 'urlaub' );

		$mine   = self::rows( 'user_id = %d AND date_to >= %s', array( $me, ( $year - 1 ) . '-01-01' ) );
		$month_rows = self::rows( "date_from <= %s AND date_to >= %s AND status IN ('beantragt', 'genehmigt')", array( $m_to, $m_from ) );

		$out = array(
			'me'        => $me,
			'month'     => $month,
			'holidays'  => self::holidays( (int) substr( $month, 0, 4 ), (string) $s['bundesland'] ),
			'balance'   => self::account_of( $me, $year, $today ),
			'mine'      => array_map( function ( $a ) use ( $people, $full ) { return self::out( $a, $people, $full ); }, array_reverse( $mine ) ),
			'calendar'  => array_map( function ( $a ) use ( $people, $full ) { return self::out( $a, $people, $full ); }, $month_rows ),
			'kinds'     => self::kinds(),
			'can_approve' => $full,
			'settings'  => $s,
			'states'    => self::states(),
		);

		if ( $full ) {
			$out['pending'] = array_map( function ( $a ) use ( $people ) { return self::out( $a, $people, true ); }, self::rows( "status = 'beantragt'", array() ) );
			$out['people']  = array();
			$all_year       = self::rows( 'date_from >= %s AND date_from <= %s', array( $year . '-01-01', $year . '-12-31' ) );
			foreach ( $people as $id => $name ) {
				$own             = array_filter( $all_year, function ( $a ) use ( $id ) { return (int) $a['user_id'] === (int) $id; } );
				$sick            = array_sum( array_map( function ( $a ) { return 'krank' === $a['kind'] && 'genehmigt' === $a['status'] ? (float) $a['days'] : 0; }, $own ) );
				$out['people'][] = array_merge( array( 'id' => $id, 'name' => $name, 'krank' => $sick ), self::account_of( $id, $year, $today ) );
			}
		}

		return new WP_REST_Response( $out );
	}

	/**
	 * Antrag oder Meldung anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage {kind, from, to, half_day, note, user_id}.
	 * @return WP_REST_Response
	 */
	public static function create( WP_REST_Request $request ) {
		global $wpdb;

		$in     = Kit::input( $request );
		$kind   = sanitize_key( isset( $in['kind'] ) ? (string) $in['kind'] : '' );
		$from   = Kit::date( isset( $in['from'] ) ? $in['from'] : '' );
		$to     = Kit::date( isset( $in['to'] ) ? $in['to'] : '' );
		$to     = '' !== $to ? $to : $from;
		$half   = ! empty( $in['half_day'] ) && $from === $to;
		$me     = get_current_user_id();
		$user   = isset( $in['user_id'] ) && (int) $in['user_id'] ? (int) $in['user_id'] : $me;
		$full   = self::can_approve();

		if ( $user !== $me && ! $full ) {
			return Kit::fail( __( 'Für andere eintragen darf nur, wer Urlaub genehmigen darf.', 'lindenweg16' ), 403 );
		}
		if ( ! isset( self::kinds()[ $kind ] ) ) {
			return Kit::fail( __( 'Bitte die Art wählen.', 'lindenweg16' ) );
		}
		if ( '' === $from || $to < $from ) {
			return Kit::fail( __( 'Bitte einen gültigen Zeitraum wählen (bis nicht vor von).', 'lindenweg16' ) );
		}
		if ( Kit::days_between( $from, $to ) > 366 ) {
			return Kit::fail( __( 'Der Zeitraum ist länger als ein Jahr.', 'lindenweg16' ) );
		}

		$days = self::workdays( $from, $to, (string) Kit::settings( 'urlaub' )['bundesland'], $half );
		if ( $days <= 0 ) {
			return Kit::fail( __( 'In diesem Zeitraum liegt kein Arbeitstag (Wochenende oder Feiertag).', 'lindenweg16' ) );
		}

		foreach ( self::rows( "user_id = %d AND status IN ('beantragt', 'genehmigt')", array( $user ) ) as $a ) {
			if ( self::overlaps( $from, $to, $a['date_from'], $a['date_to'] ) ) {
				/* translators: 1: von, 2: bis. */
				return Kit::fail( sprintf( __( 'Für %1$s bis %2$s ist schon etwas eingetragen. Bitte das zuerst zurückziehen.', 'lindenweg16' ), Kit::dmy( $a['date_from'] ), Kit::dmy( $a['date_to'] ) ), 409 );
			}
		}

		$status = 'urlaub' === $kind && ! ( $full && $user !== $me ) ? 'beantragt' : 'genehmigt';

		$wpdb->insert(
			Kit::table( 'absences' ),
			array(
				'user_id'    => $user,
				'kind'       => $kind,
				'date_from'  => $from,
				'date_to'    => $to,
				'half_day'   => $half ? 1 : 0,
				'days'       => $days,
				'status'     => $status,
				'note'       => Kit::cut( sanitize_textarea_field( isset( $in['note'] ) ? (string) $in['note'] : '' ), 500 ),
				'decided_by' => 'genehmigt' === $status ? $me : null,
				'decided_at' => 'genehmigt' === $status ? current_time( 'mysql', true ) : null,
				'created_at' => current_time( 'mysql', true ),
				'created_by' => $me,
			)
		);
		$id = (int) $wpdb->insert_id;

		if ( ! $id ) {
			return Kit::fail( __( 'Ließ sich nicht speichern. Ist das Plugin-Update vollständig gelaufen (Datenbank)?', 'lindenweg16' ), 500 );
		}

		if ( 'beantragt' === $status ) {
			self::notify_approvers( $user, $from, $to, $days );
		}

		// Ohne Grund im Protokoll – Krankheit gehört nicht in ein allgemeines Protokoll.
		AuditLog::record( 'erp_abwesenheit_angelegt', 'absence', $id, array( 'person' => $user, 'tage' => $days, 'status' => $status ) );

		$msg = 'beantragt' === $status
			/* translators: %s: Tage. */
			? sprintf( __( 'Urlaub beantragt (%s Arbeitstage). Du siehst hier, sobald entschieden ist.', 'lindenweg16' ), str_replace( '.', ',', (string) $days ) )
			/* translators: %s: Tage. */
			: sprintf( __( 'Eingetragen (%s Arbeitstage).', 'lindenweg16' ), str_replace( '.', ',', (string) $days ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => $msg ), 201 );
	}

	/**
	 * Wer genehmigen darf, bekommt eine Mail.
	 *
	 * @param int    $user Antragsteller.
	 * @param string $from Von.
	 * @param string $to   Bis.
	 * @param float  $days Tage.
	 */
	protected static function notify_approvers( $user, $from, $to, $days ) {
		$who  = get_userdata( (int) $user );
		$name = $who ? $who->display_name : '#' . (int) $user;
		$sent = array();

		foreach ( get_users( array( 'fields' => array( 'ID', 'user_email' ) ) ) as $u ) {
			if ( (int) $u->ID === (int) $user || ! is_email( $u->user_email ) || isset( $sent[ $u->user_email ] ) ) {
				continue;
			}
			if ( ! Access::can( 'verwaltung.abwesenheit.genehmigen', (int) $u->ID ) ) {
				continue;
			}
			/* translators: 1: Name, 2: von, 3: bis, 4: Tage. */
			wp_mail( $u->user_email, __( 'Neuer Urlaubsantrag', 'lindenweg16' ), sprintf( __( "%1\$s beantragt Urlaub vom %2\$s bis %3\$s (%4\$s Arbeitstage).\n\nGenehmigen im ERP unter Urlaub & Abwesenheit → Team.", 'lindenweg16' ), $name, Kit::dmy( $from ), Kit::dmy( $to ), str_replace( '.', ',', (string) $days ) ) );
			$sent[ $u->user_email ] = true;
		}
	}

	/**
	 * Antrag entscheiden.
	 *
	 * @param WP_REST_Request $request Anfrage {decision, note}.
	 * @return WP_REST_Response
	 */
	public static function decide( WP_REST_Request $request ) {
		global $wpdb;

		$in       = Kit::input( $request );
		$decision = sanitize_key( isset( $in['decision'] ) ? (string) $in['decision'] : '' );
		$rows     = self::rows( 'id = %d', array( (int) $request['id'] ) );
		$a        = $rows ? $rows[0] : null;

		if ( ! $a ) {
			return Kit::fail( __( 'Diesen Eintrag gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( 'beantragt' !== $a['status'] ) {
			return Kit::fail( __( 'Darüber ist schon entschieden.', 'lindenweg16' ), 409 );
		}
		if ( ! in_array( $decision, array( 'genehmigt', 'abgelehnt' ), true ) ) {
			return Kit::fail( __( 'Bitte genehmigen oder ablehnen.', 'lindenweg16' ) );
		}

		$note = Kit::cut( sanitize_text_field( isset( $in['note'] ) ? (string) $in['note'] : '' ), 250 );
		$wpdb->update( Kit::table( 'absences' ), array( 'status' => $decision, 'decided_by' => get_current_user_id(), 'decided_at' => current_time( 'mysql', true ), 'decision_note' => $note ), array( 'id' => (int) $a['id'] ) );
		AuditLog::record( 'erp_urlaub_entschieden', 'absence', (int) $a['id'], array( 'person' => (int) $a['user_id'], 'entscheidung' => $decision ) );

		$user = get_userdata( (int) $a['user_id'] );
		if ( $user && is_email( $user->user_email ) ) {
			$text = 'genehmigt' === $decision
				/* translators: 1: von, 2: bis. */
				? sprintf( __( 'Dein Urlaub vom %1$s bis %2$s ist genehmigt.', 'lindenweg16' ), Kit::dmy( $a['date_from'] ), Kit::dmy( $a['date_to'] ) )
				/* translators: 1: von, 2: bis. */
				: sprintf( __( 'Dein Urlaubsantrag vom %1$s bis %2$s ist leider abgelehnt.', 'lindenweg16' ), Kit::dmy( $a['date_from'] ), Kit::dmy( $a['date_to'] ) );
			wp_mail( $user->user_email, __( 'Urlaubsantrag', 'lindenweg16' ), $text . ( '' !== $note ? "\n\n" . $note : '' ) );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => 'genehmigt' === $decision ? __( 'Genehmigt.', 'lindenweg16' ) : __( 'Abgelehnt.', 'lindenweg16' ) ) );
	}

	/**
	 * Eigenen Eintrag zurückziehen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function withdraw( WP_REST_Request $request ) {
		global $wpdb;

		$rows = self::rows( 'id = %d', array( (int) $request['id'] ) );
		$a    = $rows ? $rows[0] : null;

		if ( ! $a ) {
			return Kit::fail( __( 'Diesen Eintrag gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( (int) $a['user_id'] !== get_current_user_id() && ! self::can_approve() ) {
			return Kit::fail( __( 'Das ist nicht dein Eintrag.', 'lindenweg16' ), 403 );
		}
		if ( ! in_array( $a['status'], array( 'beantragt', 'genehmigt' ), true ) ) {
			return Kit::fail( __( 'Dieser Eintrag ist schon erledigt.', 'lindenweg16' ), 409 );
		}
		if ( 'genehmigt' === $a['status'] && 'urlaub' === $a['kind'] && $a['date_from'] <= current_time( 'Y-m-d' ) && ! self::can_approve() ) {
			return Kit::fail( __( 'Begonnener Urlaub lässt sich nur von der Leitung ändern.', 'lindenweg16' ), 409 );
		}

		$wpdb->update( Kit::table( 'absences' ), array( 'status' => 'zurueckgezogen' ), array( 'id' => (int) $a['id'] ) );
		AuditLog::record( 'erp_abwesenheit_zurueckgezogen', 'absence', (int) $a['id'], array( 'person' => (int) $a['user_id'] ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Zurückgezogen.', 'lindenweg16' ) ) );
	}

	/**
	 * Anspruch einer Person oder Vorgaben setzen.
	 *
	 * @param WP_REST_Request $request Anfrage {user_id, days} oder {tage_standard, bundesland}.
	 * @return WP_REST_Response
	 */
	public static function set_entitlement( WP_REST_Request $request ) {
		$in = Kit::input( $request );

		if ( isset( $in['bundesland'] ) || isset( $in['tage_standard'] ) ) {
			if ( isset( $in['bundesland'] ) && ! isset( self::states()[ (string) $in['bundesland'] ] ) ) {
				return Kit::fail( __( 'Unbekanntes Bundesland.', 'lindenweg16' ) );
			}
			if ( isset( $in['verfall'] ) && ! preg_match( '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', (string) $in['verfall'] ) ) {
				return Kit::fail( __( 'Verfallstag bitte als MM-TT, z. B. 03-31.', 'lindenweg16' ) );
			}
			if ( array_key_exists( 'uebertrag', $in ) ) {
				$in['uebertrag'] = empty( $in['uebertrag'] ) ? 0 : 1;
			}
			$saved = Kit::save_settings( 'urlaub', $in );
			AuditLog::record( 'erp_urlaub_einstellungen', 'settings', 0, $saved );
			return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Gespeichert.', 'lindenweg16' ) ) );
		}

		$user = isset( $in['user_id'] ) ? (int) $in['user_id'] : 0;
		$days = Kit::amount( isset( $in['days'] ) ? $in['days'] : '' );

		if ( ! $user || ! get_userdata( $user ) ) {
			return Kit::fail( __( 'Diese Person gibt es nicht.', 'lindenweg16' ), 404 );
		}
		if ( null === $days || $days < 0 || $days > 60 ) {
			return Kit::fail( __( 'Bitte 0 bis 60 Tage eintragen.', 'lindenweg16' ) );
		}

		update_user_meta( $user, self::META_DAYS, round( $days * 2 ) / 2 );
		AuditLog::record( 'erp_urlaubsanspruch', 'user', $user, array( 'tage' => $days ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Urlaubsanspruch gespeichert.', 'lindenweg16' ) ) );
	}
}
