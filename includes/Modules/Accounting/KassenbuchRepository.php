<?php
/**
 * Kassenbuch: GoBD-konformes Kassenbuch für Bargeschäfte.
 *
 * Erfasst alle Bareinnahmen und -ausgaben mit laufendem Saldo.
 * Jeder Eintrag ist per SHA-256-Hash-Kette unveränderlich verkettet.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\HashChain;
use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Calc;

/**
 * Verwaltet das Kassenbuch (lw16_kassenbuch).
 */
class KassenbuchRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_kassenbuch';
	}

	/**
	 * Nächste Kassenbuchnummer für ein Kalenderjahr.
	 *
	 * @param int $jahr Kalenderjahr.
	 * @return array [ 'laufnummer' => int, 'kassenbuchnummer' => string ]
	 */
	public static function next_nummer( $jahr ) {
		global $wpdb;
		$t   = self::table();
		$lnr = 1 + (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(laufnummer), 0) FROM {$t} WHERE jahr = %d", // phpcs:ignore
			$jahr
		) );
		return array(
			'laufnummer'       => $lnr,
			'kassenbuchnummer' => Calc::kassenbuchnummer( $jahr, $lnr ),
		);
	}

	/**
	 * Aktuellen Saldo holen (letzter Eintrag).
	 *
	 * @return float
	 */
	public static function aktueller_saldo() {
		global $wpdb;
		$t = self::table();
		$saldo = $wpdb->get_var( "SELECT saldo FROM {$t} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore
		return null !== $saldo ? (float) $saldo : 0.0;
	}

	/**
	 * Neuen Kassenbuch-Eintrag anlegen (Insert-only, mit Hash-Kette).
	 *
	 * @param array $data Eintragsdaten.
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$jahr = (int) gmdate( 'Y' );
		if ( ! isset( $data['kassenbuchnummer'] ) ) {
			$next                       = self::next_nummer( $jahr );
			$data['laufnummer']         = $next['laufnummer'];
			$data['kassenbuchnummer']   = $next['kassenbuchnummer'];
		}

		$einnahme = isset( $data['einnahme'] ) ? abs( (float) $data['einnahme'] ) : 0;
		$ausgabe  = isset( $data['ausgabe'] ) ? abs( (float) $data['ausgabe'] ) : 0;
		$saldo    = self::aktueller_saldo() + $einnahme - $ausgabe;

		$defaults = array(
			'kassenbuchnummer' => '',
			'jahr'             => $jahr,
			'laufnummer'       => 0,
			'datum'            => gmdate( 'Y-m-d' ),
			'beschreibung'     => '',
			'einnahme'         => $einnahme,
			'ausgabe'          => $ausgabe,
			'saldo'            => round( $saldo, 2 ),
			'beleg_id'         => null,
			'storniert'        => 0,
			'storno_von_id'    => null,
			'erstellt_am'      => current_time( 'mysql' ),
			'erstellt_von'     => get_current_user_id(),
		);
		$data = wp_parse_args( $data, $defaults );

		// Saldo immer neu berechnen.
		$data['saldo'] = round( $saldo, 2 );

		// Hash-Verkettung.
		return HashChain::insert( 'kassenbuch', $data );
	}

	/**
	 * Storno-Eintrag erzeugen (Gegenbuchung).
	 *
	 * @param int    $original_id Ursprüngliche ID.
	 * @param string $grund       Stornierungsgrund.
	 * @return int|false Neue ID oder false.
	 */
	public static function create_storno( $original_id, $grund = '' ) {
		global $wpdb;
		$original = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE id = %d", // phpcs:ignore
			$original_id
		) );
		if ( ! $original || (int) $original->storniert === 1 ) {
			return false;
		}

		// Original als storniert markieren.
		$wpdb->update( self::table(), array( 'storniert' => 1 ), array( 'id' => (int) $original_id ) );

		// Gegenbuchung: Einnahme und Ausgabe tauschen.
		$id = self::create( array(
			'datum'         => gmdate( 'Y-m-d' ),
			'beschreibung'  => sprintf( 'Storno von %s: %s', $original->kassenbuchnummer, $grund ),
			'einnahme'      => (float) $original->ausgabe,  // Ausgabe → Einnahme.
			'ausgabe'       => (float) $original->einnahme, // Einnahme → Ausgabe.
			'storno_von_id' => (int) $original_id,
		) );

		AuditLog::record( 'kassenbuch_storniert', 'kassenbuch', $original_id, array(
			'grund' => $grund,
		) );

		return $id;
	}

	/**
	 * Paginierte Einträge.
	 *
	 * @param array $args from, to, per_page, page.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$t        = self::table();
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['from'] ) ) {
			$where   .= ' AND datum >= %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where   .= ' AND datum <= %s';
			$params[] = $args['to'];
		}

		$sql      = "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * Gesamtanzahl.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() ); // phpcs:ignore
	}

	/**
	 * Tagesabschluss: Saldo-Kontrolle für ein Datum.
	 *
	 * @param string $datum Datum (Y-m-d).
	 * @return array Zusammenfassung.
	 */
	public static function tagesabschluss( $datum ) {
		global $wpdb;
		$t = self::table();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) AS anzahl,
				COALESCE(SUM(einnahme), 0) AS einnahmen,
				COALESCE(SUM(ausgabe), 0) AS ausgaben
			FROM {$t} WHERE datum = %s AND storniert = 0", // phpcs:ignore
			$datum
		) );

		$letzter_saldo = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT saldo FROM {$t} WHERE datum <= %s ORDER BY id DESC LIMIT 1", // phpcs:ignore
			$datum
		) );

		return array(
			'datum'     => $datum,
			'anzahl'    => (int) $row->anzahl,
			'einnahmen' => (float) $row->einnahmen,
			'ausgaben'  => (float) $row->ausgaben,
			'saldo'     => $letzter_saldo,
		);
	}

	/**
	 * Alle Einträge für den Export.
	 *
	 * @param string $from Startdatum.
	 * @param string $to   Enddatum.
	 * @return array
	 */
	public static function export( $from, $to ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE datum >= %s AND datum <= %s ORDER BY id ASC", // phpcs:ignore
			$from,
			$to
		), ARRAY_A );
	}
}
