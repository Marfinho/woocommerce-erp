<?php
/**
 * Datenzugriff für Kostenstellen (Kampagnen) und ihre Belegzuordnungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Marketing;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Calc;

/**
 * Verwaltet `lw16_kampagnen` und die Zuordnungstabelle `lw16_kampagne_beleg`.
 *
 * Die Zuordnung liegt bewusst in einer eigenen Tabelle statt in einer Spalte
 * des Belegs: Belege sind nach der Festschreibung unveränderlich, die
 * Kostenstellenzuordnung ist reine Auswertung und muss auch danach noch
 * korrigierbar bleiben – und ein Beleg kann sich auf mehrere Kostenstellen
 * aufteilen.
 */
class KampagnenRepository {

	/**
	 * Zulässige Kampagnentypen.
	 */
	const TYPEN = array( 'event', 'online_ads', 'print', 'sonstiges' );

	/**
	 * Zulässige Statuswerte.
	 */
	const STATUS = array( 'geplant', 'aktiv', 'abgeschlossen' );

	/**
	 * Tabelle der Kostenstellen.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_kampagnen';
	}

	/**
	 * Tabelle der Belegzuordnungen.
	 *
	 * @return string
	 */
	public static function link_table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_kampagne_beleg';
	}

	/**
	 * Nächste freie Kostenstellennummer eines Jahres.
	 *
	 * @param int $jahr Kalenderjahr.
	 * @return string
	 */
	public static function next_kostenstelle( $jahr ) {
		global $wpdb;
		$t      = self::table();
		$praefix = sprintf( 'K-%d-', (int) $jahr );
		$like   = $wpdb->esc_like( $praefix ) . '%';
		$hoechste = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(CAST(SUBSTRING(kostenstelle, %d) AS UNSIGNED)), 0)
			 FROM {$t} WHERE kostenstelle LIKE %s", // phpcs:ignore
			strlen( $praefix ) + 1,
			$like
		) );
		return Calc::kostenstelle( $jahr, $hoechste + 1 );
	}

	/**
	 * Kostenstelle anlegen.
	 *
	 * @param array $data Felder.
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$jahr = ! empty( $data['start_datum'] ) ? (int) substr( $data['start_datum'], 0, 4 ) : (int) gmdate( 'Y' );
		if ( ! $jahr ) {
			$jahr = (int) gmdate( 'Y' );
		}

		$defaults = array(
			'kostenstelle'   => self::next_kostenstelle( $jahr ),
			'name'           => '',
			'typ'            => 'event',
			'kanal'          => '',
			'start_datum'    => gmdate( 'Y-m-d' ),
			'ende_datum'     => null,
			'event_datum'    => null,
			'budget_geplant' => 0,
			'utm_campaign'   => '',
			'gutschein_code' => '',
			'gefahrene_km'   => 0,
			'km_beleg_id'    => null,
			'status'         => 'geplant',
			'notiz'          => '',
			'erstellt_am'    => current_time( 'mysql' ),
			'erstellt_von'   => get_current_user_id(),
		);
		$data = wp_parse_args( $data, $defaults );

		$data['typ']    = in_array( $data['typ'], self::TYPEN, true ) ? $data['typ'] : 'sonstiges';
		$data['status'] = in_array( $data['status'], self::STATUS, true ) ? $data['status'] : 'geplant';

		$wpdb->insert( self::table(), $data );
		$id = (int) $wpdb->insert_id;

		AuditLog::record( 'kampagne_erstellt', 'kampagne', $id, array(
			'kostenstelle' => $data['kostenstelle'],
			'name'         => $data['name'],
			'budget'       => $data['budget_geplant'],
		) );

		return $id;
	}

	/**
	 * Kostenstelle aktualisieren.
	 *
	 * @param int   $id   ID.
	 * @param array $data Zu ändernde Felder.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		// Die Nummer ist der Identifikator und wird nie überschrieben.
		unset( $data['kostenstelle'], $data['erstellt_am'], $data['erstellt_von'] );

		if ( isset( $data['typ'] ) && ! in_array( $data['typ'], self::TYPEN, true ) ) {
			unset( $data['typ'] );
		}
		if ( isset( $data['status'] ) && ! in_array( $data['status'], self::STATUS, true ) ) {
			unset( $data['status'] );
		}
		if ( empty( $data ) ) {
			return false;
		}

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		AuditLog::record( 'kampagne_geaendert', 'kampagne', (int) $id, array( 'felder' => array_keys( $data ) ) );
		return true;
	}

	/**
	 * Einzelne Kostenstelle holen.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Kostenstellen auflisten.
	 *
	 * @param array $args status, typ, limit.
	 * @return array
	 */
	public static function all( array $args = array() ) {
		global $wpdb;
		$t = self::table();

		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['typ'] ) ) {
			$where   .= ' AND typ = %s';
			$params[] = $args['typ'];
		}

		$limit    = isset( $args['limit'] ) ? (int) $args['limit'] : 200;
		$params[] = $limit;

		$sql = "SELECT * FROM {$t} WHERE {$where} ORDER BY COALESCE(event_datum, start_datum) DESC, id DESC LIMIT %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * Kostenstellen für Auswahlfelder – abgeschlossene ans Ende.
	 *
	 * @return array id => Beschriftung.
	 */
	public static function options() {
		$out = array();
		foreach ( self::all( array( 'limit' => 500 ) ) as $k ) {
			if ( 'abgeschlossen' === $k->status ) {
				continue;
			}
			$out[ (int) $k->id ] = $k->kostenstelle . ' – ' . $k->name;
		}
		return $out;
	}

	/* --------------------------------------------------------------------
	 * Belegzuordnung
	 * ------------------------------------------------------------------ */

	/**
	 * Bereits vergebener Anteil eines Belegs über alle Kostenstellen.
	 *
	 * @param int $beleg_id     Beleg-ID.
	 * @param int $ausser_id    Zuordnungs-ID, die nicht mitzählt (beim Ändern).
	 * @return float Prozent.
	 */
	public static function vergebener_anteil( $beleg_id, $ausser_id = 0 ) {
		global $wpdb;
		$t = self::link_table();
		return (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(anteil_prozent), 0) FROM {$t} WHERE beleg_id = %d AND id != %d", // phpcs:ignore
			(int) $beleg_id,
			(int) $ausser_id
		) );
	}

	/**
	 * Vergebene Anteile für mehrere Belege auf einmal.
	 *
	 * Das Auswahlfeld im Zuordnungsformular zeigt je Beleg den freien Rest.
	 * Einzeln abgefragt wären das hundert Abfragen für eine Seite.
	 *
	 * @param array $beleg_ids Beleg-IDs.
	 * @return array Beleg-ID => vergebener Anteil in Prozent.
	 */
	public static function vergebene_anteile( array $beleg_ids ) {
		global $wpdb;

		$beleg_ids = array_values( array_unique( array_map( 'intval', $beleg_ids ) ) );
		if ( ! $beleg_ids ) {
			return array();
		}

		$t            = self::link_table();
		$platzhalter  = implode( ',', array_fill( 0, count( $beleg_ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare(
			"SELECT beleg_id, SUM(anteil_prozent) AS vergeben FROM {$t}
			 WHERE beleg_id IN ({$platzhalter}) GROUP BY beleg_id", // phpcs:ignore
			$beleg_ids
		) );

		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->beleg_id ] = (float) $r->vergeben;
		}
		return $out;
	}

	/**
	 * Beleg einer Kostenstelle zuordnen.
	 *
	 * Der Anteil wird auf den noch freien Rest begrenzt, statt ihn
	 * abzulehnen – wer 100 % eintippt, obwohl schon 40 % vergeben sind,
	 * meint fast immer „den Rest".
	 *
	 * @param int   $kampagne_id Kostenstelle.
	 * @param int   $beleg_id    Beleg.
	 * @param float $prozent     Gewünschter Anteil.
	 * @return float Tatsächlich zugeordneter Anteil, 0 wenn nichts frei war.
	 */
	public static function zuordnen( $kampagne_id, $beleg_id, $prozent = 100 ) {
		global $wpdb;

		$kampagne_id = (int) $kampagne_id;
		$beleg_id    = (int) $beleg_id;

		$bestehend = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::link_table() . ' WHERE kampagne_id = %d AND beleg_id = %d', // phpcs:ignore
			$kampagne_id,
			$beleg_id
		) );

		$vergeben = self::vergebener_anteil( $beleg_id, $bestehend ? (int) $bestehend->id : 0 );
		$anteil   = Kampagnenrechnung::zulaessiger_anteil( $vergeben, $prozent );

		if ( $anteil <= 0 ) {
			return 0.0;
		}

		if ( $bestehend ) {
			$wpdb->update(
				self::link_table(),
				array( 'anteil_prozent' => $anteil ),
				array( 'id' => (int) $bestehend->id )
			);
		} else {
			$wpdb->insert(
				self::link_table(),
				array(
					'kampagne_id'    => $kampagne_id,
					'beleg_id'       => $beleg_id,
					'anteil_prozent' => $anteil,
					'erstellt_am'    => current_time( 'mysql' ),
					'erstellt_von'   => get_current_user_id(),
				)
			);
		}

		AuditLog::record( 'kampagne_beleg_zugeordnet', 'kampagne', $kampagne_id, array(
			'beleg_id' => $beleg_id,
			'anteil'   => $anteil,
		) );

		return $anteil;
	}

	/**
	 * Zuordnung wieder lösen.
	 *
	 * @param int $kampagne_id Kostenstelle.
	 * @param int $beleg_id    Beleg.
	 * @return bool
	 */
	public static function loesen( $kampagne_id, $beleg_id ) {
		global $wpdb;
		$rows = $wpdb->delete(
			self::link_table(),
			array(
				'kampagne_id' => (int) $kampagne_id,
				'beleg_id'    => (int) $beleg_id,
			),
			array( '%d', '%d' )
		);
		if ( $rows ) {
			AuditLog::record( 'kampagne_beleg_geloest', 'kampagne', (int) $kampagne_id, array( 'beleg_id' => (int) $beleg_id ) );
		}
		return (bool) $rows;
	}

	/**
	 * Zugeordnete Belege einer Kostenstelle mit Anteil und Teilbetrag.
	 *
	 * @param int $kampagne_id Kostenstelle.
	 * @return array
	 */
	public static function belege( $kampagne_id ) {
		global $wpdb;
		$link   = self::link_table();
		$belege = $wpdb->prefix . 'lw16_belege';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.*, l.anteil_prozent
			 FROM {$link} l
			 INNER JOIN {$belege} b ON b.id = l.beleg_id
			 WHERE l.kampagne_id = %d AND b.storniert = 0 AND b.quelle != 'storno'
			 ORDER BY b.datum DESC, b.id DESC", // phpcs:ignore
			(int) $kampagne_id
		) );

		foreach ( $rows as $r ) {
			$r->anteil_betrag = Kampagnenrechnung::anteil_betrag( $r->betrag, $r->anteil_prozent );
		}
		return $rows;
	}

	/**
	 * Zugeordnete Kosten einer Kostenstelle.
	 *
	 * Zählt nur Aufwandskonten: Der Marktumsatz landet als Einnahmebeleg an
	 * derselben Kostenstelle und darf die Kosten nicht mindern.
	 *
	 * @param int $kampagne_id Kostenstelle.
	 * @return array {summe, nach_konto}
	 */
	public static function kosten( $kampagne_id ) {
		$summe = 0.0;
		$konten = array();

		foreach ( self::belege( $kampagne_id ) as $b ) {
			$konto = (string) $b->konto;
			if ( '' === $konto ) {
				$konto = \Lindenweg16\Modules\Accounting\Kontenrahmen::konto_fuer_kategorie( $b->eur_kategorie );
			}
			$eintrag = \Lindenweg16\Modules\Accounting\Kontenrahmen::get( $konto );
			if ( $eintrag && 'einnahme' === $eintrag['typ'] ) {
				continue;
			}

			$betrag = (float) $b->anteil_betrag;
			$summe += $betrag;

			$schluessel = $konto ? $konto : 'ohne';
			if ( ! isset( $konten[ $schluessel ] ) ) {
				$konten[ $schluessel ] = 0.0;
			}
			$konten[ $schluessel ] += $betrag;
		}

		ksort( $konten );

		return array(
			'summe'      => round( $summe, 2 ),
			'nach_konto' => $konten,
		);
	}

	/**
	 * Kostenstellen, denen ein Beleg zugeordnet ist.
	 *
	 * @param int $beleg_id Beleg-ID.
	 * @return array
	 */
	public static function fuer_beleg( $beleg_id ) {
		global $wpdb;
		$link = self::link_table();
		$t    = self::table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT k.*, l.anteil_prozent FROM {$link} l
			 INNER JOIN {$t} k ON k.id = l.kampagne_id
			 WHERE l.beleg_id = %d ORDER BY k.id", // phpcs:ignore
			(int) $beleg_id
		) );
	}
}
