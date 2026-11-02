<?php
/**
 * Wiederkehrende Buchungen (Dauerbuchungen): Miete, Versicherung, Abos.
 *
 * Automatisiert regelmäßige Ausgaben/Einnahmen – erzeugt zu jedem Fälligkeitsdatum
 * einen Beleg, schreibt ihn fest und bucht ihn im Journal.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;

/**
 * Verwaltet wiederkehrende Buchungsvorlagen (lw16_dauerbuchungen).
 */
class DauerbuchungRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_dauerbuchungen';
	}

	/**
	 * Neue Dauerbuchung anlegen.
	 *
	 * @param array $data Daten.
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$defaults = array(
			'bezeichnung'          => '',
			'betrag'               => 0,
			'kategorie'            => '',
			'typ'                  => 'ausgabe',
			'intervall'            => 'monatlich',
			'start_datum'          => gmdate( 'Y-m-d' ),
			'ende_datum'           => null,
			'naechste_ausfuehrung' => '',
			'letzte_ausfuehrung'   => null,
			'aktiv'                => 1,
			'auf_rechnung'         => 0,
			'erstellt_am'          => current_time( 'mysql' ),
			'erstellt_von'         => get_current_user_id(),
		);
		$data = wp_parse_args( $data, $defaults );

		if ( empty( $data['naechste_ausfuehrung'] ) ) {
			$data['naechste_ausfuehrung'] = $data['start_datum'];
		}

		$wpdb->insert( self::table(), $data );
		$id = (int) $wpdb->insert_id;

		AuditLog::record( 'dauerbuchung_erstellt', 'dauerbuchung', $id, array(
			'bezeichnung' => $data['bezeichnung'],
			'betrag'      => $data['betrag'],
			'intervall'   => $data['intervall'],
			'auf_rechnung' => (int) $data['auf_rechnung'],
		) );

		return $id;
	}

	/**
	 * Einzelne Dauerbuchung holen.
	 *
	 * @param int $id ID.
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
	 * Alle Dauerbuchungen holen.
	 *
	 * @param bool $nur_aktive Nur aktive.
	 * @return array
	 */
	public static function get_all( $nur_aktive = true ) {
		global $wpdb;
		$t = self::table();
		if ( $nur_aktive ) {
			return $wpdb->get_results( "SELECT * FROM {$t} WHERE aktiv = 1 ORDER BY naechste_ausfuehrung ASC" ); // phpcs:ignore
		}
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY aktiv DESC, naechste_ausfuehrung ASC" ); // phpcs:ignore
	}

	/**
	 * Dauerbuchung deaktivieren (kein DELETE).
	 *
	 * @param int $id ID.
	 */
	public static function deactivate( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'aktiv' => 0 ), array( 'id' => (int) $id ) );
		AuditLog::record( 'dauerbuchung_deaktiviert', 'dauerbuchung', $id );
	}

	/**
	 * Nächstes Ausführungsdatum berechnen.
	 *
	 * @param string $current_date Aktuelles Datum (Y-m-d).
	 * @param string $intervall    Intervall-Typ.
	 * @param int    $anchor_day   Tag im Monat aus dem Startdatum (0 = Tag von $current_date);
	 *                             am Monatsende wird begrenzt (31.01. → 28.02. → 31.03.).
	 * @return string Nächstes Datum (Y-m-d).
	 */
	public static function calculate_next( $current_date, $intervall, $anchor_day = 0 ) {
		return \Lindenweg16\Erp\Kit::next_interval_date( $current_date, $intervall, $anchor_day );
	}

	/**
	 * Alle fälligen Dauerbuchungen ausführen.
	 *
	 * Erzeugt für jede fällige Buchung einen Beleg, schreibt ihn fest
	 * (→ automatische Buchung im Journal) und berechnet das nächste Datum.
	 * Steht eine Dauerbuchung auf „auf Rechnung warten“, entsteht statt des
	 * Belegs ein wartender Termin (siehe ausfuehren()).
	 *
	 * @return int Anzahl ausgeführter Buchungen.
	 */
	public static function execute_due() {
		global $wpdb;
		$t     = self::table();
		$today = gmdate( 'Y-m-d' );

		$due = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE aktiv = 1 AND naechste_ausfuehrung <= %s AND (ende_datum IS NULL OR ende_datum >= %s)", // phpcs:ignore
			$today,
			$today
		) );

		$count  = 0;
		$wartet = 0;
		foreach ( $due as $db ) {
			$r = self::ausfuehren( $db, $today );
			if ( 'fehler' === $r['art'] ) {
				continue;
			}
			if ( 'wartet' === $r['art'] ) {
				++$wartet;
			}
			$count++;
		}

		if ( $count > 0 ) {
			AuditLog::record( 'dauerbuchungen_ausgefuehrt', 'system', 0, array(
				'anzahl'      => $count,
				'datum'       => $today,
				'auf_rechnung' => $wartet,
			) );
		}

		return $count;
	}

	/**
	 * Einen fälligen Termin einer Dauerbuchung ausführen.
	 *
	 * Normal: Beleg zum heutigen Datum, sofort festgeschrieben (Journal).
	 * Mit „auf Rechnung warten“: kein Beleg, sondern ein wartender Termin –
	 * der Beleg entsteht erst mit der hochgeladenen Rechnung (Datei, echter
	 * Betrag und Rechnungsdatum gehören in die Hash-Kette). Danach in beiden
	 * Fällen das nächste Datum, bei überschrittenem Ende abschalten.
	 *
	 * @param object $db    Zeile aus lw16_dauerbuchungen.
	 * @param string $today Heute (Y-m-d).
	 * @return array {art: beleg|wartet|fehler, id: Beleg- bzw. Termin-ID}
	 */
	public static function ausfuehren( $db, $today ) {
		global $wpdb;

		if ( ! empty( $db->auf_rechnung ) ) {
			$id = self::termin_anlegen( $db, (string) $db->naechste_ausfuehrung );
			if ( ! $id ) {
				return array( 'art' => 'fehler', 'id' => 0 );
			}
			$r = array( 'art' => 'wartet', 'id' => $id );
		} else {
			$beleg_id = BelegRepository::create( array(
				'betrag'        => abs( (float) $db->betrag ),
				'datum'         => $today,
				'haendler'      => $db->bezeichnung,
				'eur_kategorie' => $db->kategorie,
				'notiz'         => sprintf(
					/* translators: %d: Dauerbuchung ID */
					__( 'Automatisch aus Dauerbuchung #%d', 'lindenweg16' ),
					$db->id
				),
				'quelle'        => 'dauerbuchung',
			) );

			if ( ! $beleg_id ) {
				return array( 'art' => 'fehler', 'id' => 0 );
			}

			BelegRepository::festschreiben( $beleg_id );
			$r = array( 'art' => 'beleg', 'id' => (int) $beleg_id );
		}

		// Nächstes Ausführungsdatum berechnen.
		$next   = self::calculate_next( $db->naechste_ausfuehrung, $db->intervall, (int) substr( (string) $db->start_datum, 8, 2 ) );
		$update = array(
			'naechste_ausfuehrung' => $next,
			'letzte_ausfuehrung'   => $today,
		);

		// Automatisch deaktivieren, wenn Enddatum überschritten.
		if ( $db->ende_datum && $next > $db->ende_datum ) {
			$update['aktiv'] = 0;
		}

		$wpdb->update( self::table(), $update, array( 'id' => (int) $db->id ) );

		return $r;
	}

	/* ====================================================================
	 * Termine, die auf ihre Rechnung warten (lw16_dauerbuchung_offen)
	 * ================================================================== */

	/**
	 * Tabelle der wartenden Termine.
	 *
	 * @return string
	 */
	public static function offen_table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_dauerbuchung_offen';
	}

	/**
	 * Wartenden Termin anlegen (je Dauerbuchung und Fälligkeit nur einmal).
	 *
	 * @param object $db         Dauerbuchung.
	 * @param string $faellig_am Fälligkeit (Y-m-d).
	 * @return int Termin-ID (auch die eines schon vorhandenen).
	 */
	public static function termin_anlegen( $db, $faellig_am ) {
		global $wpdb;
		$t = self::offen_table();

		$vorhanden = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE dauerbuchung_id = %d AND faellig_am = %s", // phpcs:ignore
			(int) $db->id,
			$faellig_am
		) );
		if ( $vorhanden ) {
			return $vorhanden;
		}

		$wpdb->insert( $t, array(
			'dauerbuchung_id' => (int) $db->id,
			'faellig_am'      => $faellig_am,
			'bezeichnung'     => (string) $db->bezeichnung,
			'betrag'          => abs( (float) $db->betrag ),
			'kategorie'       => (string) $db->kategorie,
			'status'          => 'wartet',
			'erstellt_am'     => current_time( 'mysql' ),
		) );
		$id = (int) $wpdb->insert_id;

		AuditLog::record( 'dauerbuchung_wartet', 'dauerbuchung', (int) $db->id, array(
			'termin'      => $id,
			'faellig_am'  => $faellig_am,
			'bezeichnung' => (string) $db->bezeichnung,
			'betrag'      => abs( (float) $db->betrag ),
		) );

		return $id;
	}

	/**
	 * Ein Termin.
	 *
	 * @param int $id Termin-ID.
	 * @return object|null
	 */
	public static function termin( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::offen_table() . " WHERE id = %d", (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Alle Termine, die noch auf ihre Rechnung warten (älteste zuerst).
	 *
	 * @return array
	 */
	public static function wartende() {
		global $wpdb;
		return (array) $wpdb->get_results( "SELECT * FROM " . self::offen_table() . " WHERE status = 'wartet' ORDER BY faellig_am ASC, id ASC" ); // phpcs:ignore
	}

	/**
	 * Anzahl wartender Termine.
	 *
	 * @return int
	 */
	public static function count_wartend() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::offen_table() . " WHERE status = 'wartet'" ); // phpcs:ignore
	}

	/**
	 * Termin als erledigt markieren: Die Rechnung ist als Beleg erfasst.
	 *
	 * Nur aus „wartet“ heraus – zwei gleichzeitige Uploads erledigen ihn nicht doppelt.
	 *
	 * @param int $id       Termin-ID.
	 * @param int $beleg_id Beleg.
	 * @return bool
	 */
	public static function termin_erfasst( $id, $beleg_id ) {
		global $wpdb;
		$n = $wpdb->update(
			self::offen_table(),
			array(
				'status'       => 'erfasst',
				'beleg_id'     => (int) $beleg_id,
				'erledigt_am'  => current_time( 'mysql' ),
				'erledigt_von' => get_current_user_id(),
			),
			array( 'id' => (int) $id, 'status' => 'wartet' )
		);
		if ( $n ) {
			AuditLog::record( 'dauerbuchung_rechnung_erfasst', 'dauerbuchung_offen', (int) $id, array( 'beleg' => (int) $beleg_id ) );
		}
		return (bool) $n;
	}

	/**
	 * Termin verwerfen: In diesem Zeitraum kam keine Rechnung (z. B. Abo pausiert).
	 *
	 * @param int    $id    Termin-ID.
	 * @param string $grund Begründung.
	 * @return bool
	 */
	public static function termin_verwerfen( $id, $grund ) {
		global $wpdb;
		$n = $wpdb->update(
			self::offen_table(),
			array(
				'status'       => 'verworfen',
				'grund'        => mb_substr( (string) $grund, 0, 255 ),
				'erledigt_am'  => current_time( 'mysql' ),
				'erledigt_von' => get_current_user_id(),
			),
			array( 'id' => (int) $id, 'status' => 'wartet' )
		);
		if ( $n ) {
			AuditLog::record( 'dauerbuchung_termin_verworfen', 'dauerbuchung_offen', (int) $id, array( 'grund' => (string) $grund ) );
		}
		return (bool) $n;
	}

	/**
	 * Fällige Dauerbuchungen zählen.
	 *
	 * @return int
	 */
	public static function count_due() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE aktiv = 1 AND naechste_ausfuehrung <= %s", // phpcs:ignore
			gmdate( 'Y-m-d' )
		) );
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
	 * Intervall-Optionen.
	 *
	 * @return array
	 */
	public static function intervall_optionen() {
		return array(
			'woechentlich'   => __( 'Wöchentlich', 'lindenweg16' ),
			'monatlich'      => __( 'Monatlich', 'lindenweg16' ),
			'quartalsweise'  => __( 'Quartalsweise', 'lindenweg16' ),
			'halbjaehrlich'  => __( 'Halbjährlich', 'lindenweg16' ),
			'jaehrlich'      => __( 'Jährlich', 'lindenweg16' ),
		);
	}
}
