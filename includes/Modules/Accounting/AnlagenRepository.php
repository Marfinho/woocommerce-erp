<?php
/**
 * Anlagenbuchhaltung: Wirtschaftsgüter und Abschreibung (AfA).
 *
 * Verwaltet das Anlagenverzeichnis und berechnet lineare AfA.
 * Jährliche Abschreibungen werden als Belege mit Kategorie „AfA"
 * angelegt und automatisch festgeschrieben.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Calc;

/**
 * Verwaltet Wirtschaftsgüter und deren Abschreibungen (lw16_anlagen).
 */
class AnlagenRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_anlagen';
	}

	/**
	 * Nächste Anlagennummer für ein Kalenderjahr.
	 *
	 * @param int $jahr Kalenderjahr.
	 * @return array [ 'laufnummer' => int, 'anlagennummer' => string ]
	 */
	public static function next_nummer( $jahr ) {
		global $wpdb;
		$t   = self::table();
		$lnr = 1 + (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(laufnummer), 0) FROM {$t} WHERE jahr = %d", // phpcs:ignore
			$jahr
		) );
		return array(
			'laufnummer'     => $lnr,
			'anlagennummer'  => Calc::anlagennummer( $jahr, $lnr ),
		);
	}

	/**
	 * Neue Anlage erfassen.
	 *
	 * @param array $data Anlagendaten.
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$datum = isset( $data['anschaffungsdatum'] ) ? $data['anschaffungsdatum'] : gmdate( 'Y-m-d' );
		$jahr  = (int) substr( $datum, 0, 4 );
		if ( ! $jahr ) {
			$jahr = (int) gmdate( 'Y' );
		}

		if ( ! isset( $data['anlagennummer'] ) ) {
			$next                    = self::next_nummer( $jahr );
			$data['laufnummer']      = $next['laufnummer'];
			$data['anlagennummer']   = $next['anlagennummer'];
		}

		$defaults = array(
			'anlagennummer'       => '',
			'jahr'                => $jahr,
			'laufnummer'          => 0,
			'bezeichnung'         => '',
			'anschaffungsdatum'   => $datum,
			'anschaffungskosten'  => 0,
			'nutzungsdauer_jahre' => 1,
			'afa_methode'         => 'linear',
			'buchwert'            => 0,
			'beleg_id'            => null,
			'kategorie'           => '',
			'ausgeschieden'       => 0,
			'ausgeschieden_am'    => null,
			'erstellt_am'         => current_time( 'mysql' ),
			'erstellt_von'        => get_current_user_id(),
		);
		$data = wp_parse_args( $data, $defaults );

		// Buchwert = Anschaffungskosten bei Erstellung.
		if ( empty( $data['buchwert'] ) ) {
			$data['buchwert'] = $data['anschaffungskosten'];
		}

		$wpdb->insert( self::table(), $data );
		$id = (int) $wpdb->insert_id;

		AuditLog::record( 'anlage_erfasst', 'anlage', $id, array(
			'bezeichnung'        => $data['bezeichnung'],
			'anschaffungskosten' => $data['anschaffungskosten'],
			'nutzungsdauer'      => $data['nutzungsdauer_jahre'],
		) );

		return $id;
	}

	/**
	 * Einzelne Anlage holen.
	 *
	 * @param int $id Anlage-ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE id = %d", // phpcs:ignore
			$id
		) );
	}

	/**
	 * Alle Anlagen holen.
	 *
	 * @param bool $nur_aktive Nur nicht ausgeschiedene.
	 * @return array
	 */
	public static function get_all( $nur_aktive = true ) {
		global $wpdb;
		$t = self::table();
		if ( $nur_aktive ) {
			return $wpdb->get_results( "SELECT * FROM {$t} WHERE ausgeschieden = 0 ORDER BY anschaffungsdatum DESC" ); // phpcs:ignore
		}
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY ausgeschieden ASC, anschaffungsdatum DESC" ); // phpcs:ignore
	}

	/**
	 * Jährlichen AfA-Betrag berechnen (linear).
	 *
	 * @param object $anlage Anlage-Datensatz.
	 * @return float Jährlicher Abschreibungsbetrag.
	 */
	public static function calculate_afa( $anlage ) {
		$kosten  = (float) $anlage->anschaffungskosten;
		$dauer   = max( 1, (int) $anlage->nutzungsdauer_jahre );
		$jahres_afa = round( $kosten / $dauer, 2 );

		// Nicht mehr als den aktuellen Buchwert abschreiben.
		$buchwert = (float) $anlage->buchwert;
		return min( $jahres_afa, $buchwert );
	}

	/**
	 * AfA-Plan für eine Anlage berechnen.
	 *
	 * @param object $anlage Anlage-Datensatz.
	 * @return array Array von [ 'jahr' => int, 'afa' => float, 'buchwert' => float ].
	 */
	public static function get_afa_plan( $anlage ) {
		$kosten    = (float) $anlage->anschaffungskosten;
		$dauer     = max( 1, (int) $anlage->nutzungsdauer_jahre );
		$start     = (int) substr( $anlage->anschaffungsdatum, 0, 4 );
		$jahres_afa = round( $kosten / $dauer, 2 );
		$buchwert  = $kosten;
		$plan      = array();

		for ( $i = 0; $i < $dauer; $i++ ) {
			$afa = min( $jahres_afa, $buchwert );
			// Letztes Jahr: Rest abschreiben (Rundungsdifferenz).
			if ( $i === $dauer - 1 ) {
				$afa = $buchwert;
			}
			$buchwert -= $afa;
			$plan[] = array(
				'jahr'     => $start + $i,
				'afa'      => round( $afa, 2 ),
				'buchwert' => round( max( 0, $buchwert ), 2 ),
			);
		}

		return $plan;
	}

	/**
	 * AfA für ein Kalenderjahr buchen (alle aktiven Anlagen).
	 *
	 * Erzeugt für jede Anlage mit positivem Buchwert einen Beleg
	 * in der Kategorie „AfA" und schreibt ihn fest.
	 *
	 * @param int $jahr Kalenderjahr.
	 * @return int Anzahl gebuchter Abschreibungen.
	 */
	public static function book_yearly_afa( $jahr ) {
		global $wpdb;
		$anlagen = self::get_all( true );
		$count   = 0;

		foreach ( $anlagen as $anlage ) {
			$start_jahr = (int) substr( $anlage->anschaffungsdatum, 0, 4 );
			$end_jahr   = $start_jahr + (int) $anlage->nutzungsdauer_jahre - 1;

			// Nur abschreiben, wenn das Jahr im AfA-Zeitraum liegt.
			if ( $jahr < $start_jahr || $jahr > $end_jahr ) {
				continue;
			}
			if ( (float) $anlage->buchwert <= 0 ) {
				continue;
			}

			$afa = self::calculate_afa( $anlage );
			if ( $afa <= 0 ) {
				continue;
			}

			// Beleg anlegen.
			$beleg_id = BelegRepository::create( array(
				'betrag'        => $afa,
				'datum'         => $jahr . '-12-31',
				'haendler'      => $anlage->bezeichnung,
				'eur_kategorie' => 'AfA',
				'notiz'         => sprintf(
					/* translators: %1$s asset number, %2$d year */
					__( 'Lineare AfA %1$s, Geschäftsjahr %2$d', 'lindenweg16' ),
					$anlage->anlagennummer,
					$jahr
				),
				'quelle'        => 'afa',
			) );

			if ( $beleg_id ) {
				BelegRepository::festschreiben( $beleg_id );
			}

			// Buchwert reduzieren.
			$neuer_buchwert = max( 0, (float) $anlage->buchwert - $afa );
			$wpdb->update(
				self::table(),
				array( 'buchwert' => $neuer_buchwert ),
				array( 'id' => (int) $anlage->id )
			);

			AuditLog::record( 'afa_gebucht', 'anlage', (int) $anlage->id, array(
				'jahr'           => $jahr,
				'betrag'         => $afa,
				'neuer_buchwert' => $neuer_buchwert,
			) );

			$count++;
		}

		return $count;
	}

	/**
	 * Anlage als ausgeschieden markieren.
	 *
	 * @param int    $id    Anlage-ID.
	 * @param string $datum Datum des Ausscheidens (Y-m-d).
	 */
	public static function ausscheiden( $id, $datum = '' ) {
		global $wpdb;
		if ( ! $datum ) {
			$datum = gmdate( 'Y-m-d' );
		}
		$wpdb->update(
			self::table(),
			array(
				'ausgeschieden'    => 1,
				'ausgeschieden_am' => $datum,
			),
			array( 'id' => (int) $id )
		);
		AuditLog::record( 'anlage_ausgeschieden', 'anlage', $id, array( 'datum' => $datum ) );
	}

	/**
	 * Summe aller Buchwerte (Anlagevermögen).
	 *
	 * @return float
	 */
	public static function summe_buchwerte() {
		global $wpdb;
		$t = self::table();
		return (float) $wpdb->get_var( "SELECT COALESCE(SUM(buchwert), 0) FROM {$t} WHERE ausgeschieden = 0" ); // phpcs:ignore
	}

	/**
	 * Gesamtanzahl aktiver Anlagen.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE ausgeschieden = 0" ); // phpcs:ignore
	}
}
