<?php
/**
 * Datenzugriff für Druckaufträge und Ist-Zeit-Log.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Production;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Inventory\Repository;
use Lindenweg16\Core\Time;

/**
 * Kapselt Druckaufträge (Status geplant→verpackt) und das Produktions-Log
 * für die Ist-vs-Plan-Auswertung sowie die vorausschauenden Mengenvorschläge.
 */
class ProductionRepository {

	/**
	 * Tabellenname.
	 *
	 * @param string $name druckauftrag|produktion_log.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'lw16_' . $name;
	}

	/* --------------------------------------------------------------------
	 * Druckaufträge
	 * ------------------------------------------------------------------ */

	/**
	 * Druckauftrag anlegen.
	 *
	 * @param int    $produkt_id Modul-ID.
	 * @param float  $menge      Menge.
	 * @param string $referenz   Bestellreferenz.
	 * @return int
	 */
	public static function create_job( $produkt_id, $menge, $referenz = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'druckauftrag' ),
			array(
				'produkt_id'    => (int) $produkt_id,
				'menge'         => (float) $menge,
				'status'        => 'geplant',
				'order_referenz' => $referenz,
				'erstellt_am'   => Time::now(),
			),
			array( '%d', '%f', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Druckauftrag holen.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get_job( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'druckauftrag' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/**
	 * Offene Druckaufträge (nicht verpackt).
	 *
	 * @return array
	 */
	public static function open_jobs() {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM " . self::table( 'druckauftrag' ) . " WHERE status <> 'verpackt' ORDER BY erstellt_am ASC" ); // phpcs:ignore
	}

	/**
	 * Status eines Druckauftrags setzen.
	 *
	 * @param int    $id     ID.
	 * @param string $status geplant|in_produktion|fertig|verpackt.
	 */
	public static function set_job_status( $id, $status ) {
		global $wpdb;
		$data = array( 'status' => $status );
		if ( 'fertig' === $status ) {
			$data['abgeschlossen_am'] = Time::now();
		}
		$wpdb->update( self::table( 'druckauftrag' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Offene (noch nicht verpackte) Mengen je Produkt.
	 *
	 * Wird von der Druck-Queue gegengerechnet, damit fuer dieselbe Bestellung
	 * nicht bei jedem Seitenaufruf erneut die volle Menge vorgeschlagen wird.
	 *
	 * @return array produkt_id => Menge.
	 */
	public static function open_job_quantities() {
		global $wpdb;
		$t    = self::table( 'druckauftrag' );
		$rows = $wpdb->get_results( "SELECT produkt_id, SUM(menge) AS menge FROM {$t} WHERE status <> 'verpackt' GROUP BY produkt_id" ); // phpcs:ignore

		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->produkt_id ] = (float) $r->menge;
		}
		return $out;
	}

	/* --------------------------------------------------------------------
	 * Ist-Zeit-Log
	 * ------------------------------------------------------------------ */

	/**
	 * Produktions-Log-Eintrag anlegen.
	 *
	 * @param array $data Log-Daten.
	 * @return int
	 */
	public static function add_log( array $data ) {
		global $wpdb;
		$data['erstellt_am'] = Time::now();
		$wpdb->insert( self::table( 'produktion_log' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Durchschnittliche Ist-Werte der letzten X Fertigungen je Modul.
	 *
	 * @param int $produkt_id Modul-ID.
	 * @param int $limit      Anzahl letzter Einträge.
	 * @return array|null {ist_druckzeit_h, ist_arbeitszeit_min, anzahl}
	 */
	public static function avg_ist( $produkt_id, $limit = 8 ) {
		global $wpdb;
		$t    = self::table( 'produktion_log' );
		// Nur Eintraege mit erfassten Ist-Zeiten. Wurde ein Druckauftrag ohne
		// Zeiterfassung abgeschlossen, steht dort NULL - frueher eine 0, die
		// den Durchschnitt nach unten zog.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ist_druckzeit_h, ist_arbeitszeit_min FROM {$t}
				 WHERE produkt_id = %d
				   AND ( ist_druckzeit_h IS NOT NULL OR ist_arbeitszeit_min IS NOT NULL )
				 ORDER BY id DESC LIMIT %d", // phpcs:ignore
				$produkt_id,
				$limit
			)
		);
		if ( empty( $rows ) ) {
			return null;
		}

		$dz = 0.0; $az = 0.0; $n_dz = 0; $n_az = 0;
		foreach ( $rows as $r ) {
			if ( null !== $r->ist_druckzeit_h ) {
				$dz += (float) $r->ist_druckzeit_h;
				$n_dz++;
			}
			if ( null !== $r->ist_arbeitszeit_min ) {
				$az += (float) $r->ist_arbeitszeit_min;
				$n_az++;
			}
		}

		return array(
			'ist_druckzeit_h'     => $n_dz > 0 ? $dz / $n_dz : null,
			'ist_arbeitszeit_min' => $n_az > 0 ? $az / $n_az : null,
			'anzahl'              => count( $rows ),
		);
	}

	/* --------------------------------------------------------------------
	 * Vorausschauende Mengenvorschläge
	 * ------------------------------------------------------------------ */

	/**
	 * Gleitender Wochendurchschnitt der verkauften Menge je Modul.
	 * Ausgeschlossene Wochen (Ausreißer) werden übersprungen.
	 *
	 * @param int   $produkt_id       Modul-ID.
	 * @param int   $wochen           Betrachtungszeitraum in Wochen.
	 * @param array $excluded_weeks   Auszuschließende Wochen (YYYY-WW).
	 * @return float Durchschnitt pro Woche.
	 */
	public static function weekly_average( $produkt_id, $wochen = 6, array $excluded_weeks = array() ) {
		global $wpdb;
		$t      = Repository::table( 'lagerbewegung' );
		$wochen = max( 1, (int) $wochen );
		$grenze = Time::ago( $wochen * WEEK_IN_SECONDS );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT menge, erstellt_am FROM {$t} WHERE produkt_id = %d AND typ = 'verkauf' AND erstellt_am >= %s", // phpcs:ignore
				$produkt_id,
				$grenze
			)
		);

		// Welche Kalenderwochen liegen ueberhaupt im Betrachtungsfenster?
		$fenster = array();
		for ( $i = 0; $i < $wochen; $i++ ) {
			$fenster[ Time::week_key( Time::ago( $i * WEEK_IN_SECONDS ) ) ] = true;
		}

		$per_week = array();
		foreach ( $rows as $r ) {
			$wk = Time::week_key( $r->erstellt_am );
			if ( '' === $wk || in_array( $wk, $excluded_weeks, true ) ) {
				continue;
			}
			$per_week[ $wk ] = ( $per_week[ $wk ] ?? 0 ) + abs( (float) $r->menge );
		}

		// Nur Ausschlusswochen abziehen, die auch im Fenster liegen. Vorher
		// verkleinerten laengst vergangene Eintraege den Divisor und blaehten
		// den Durchschnitt auf.
		$ausgeschlossen_im_fenster = 0;
		foreach ( $excluded_weeks as $wk ) {
			if ( isset( $fenster[ $wk ] ) ) {
				$ausgeschlossen_im_fenster++;
			}
		}

		$effektive_wochen = max( 1, $wochen - $ausgeschlossen_im_fenster );
		return array_sum( $per_week ) / $effektive_wochen;
	}
}
