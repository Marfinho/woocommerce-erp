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
	 * @return string Nächstes Datum (Y-m-d).
	 */
	public static function calculate_next( $current_date, $intervall ) {
		$dt = new \DateTime( $current_date );
		switch ( $intervall ) {
			case 'woechentlich':
				$dt->modify( '+1 week' );
				break;
			case 'monatlich':
				$dt->modify( '+1 month' );
				break;
			case 'quartalsweise':
				$dt->modify( '+3 months' );
				break;
			case 'halbjaehrlich':
				$dt->modify( '+6 months' );
				break;
			case 'jaehrlich':
				$dt->modify( '+1 year' );
				break;
			default:
				$dt->modify( '+1 month' );
		}
		return $dt->format( 'Y-m-d' );
	}

	/**
	 * Alle fälligen Dauerbuchungen ausführen.
	 *
	 * Erzeugt für jede fällige Buchung einen Beleg, schreibt ihn fest
	 * (→ automatische Buchung im Journal) und berechnet das nächste Datum.
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

		$count = 0;
		foreach ( $due as $db ) {
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

			if ( $beleg_id ) {
				BelegRepository::festschreiben( $beleg_id );
			}

			// Nächstes Ausführungsdatum berechnen.
			$next = self::calculate_next( $db->naechste_ausfuehrung, $db->intervall );
			$update = array(
				'naechste_ausfuehrung' => $next,
				'letzte_ausfuehrung'   => $today,
			);

			// Automatisch deaktivieren, wenn Enddatum überschritten.
			if ( $db->ende_datum && $next > $db->ende_datum ) {
				$update['aktiv'] = 0;
			}

			$wpdb->update( $t, $update, array( 'id' => (int) $db->id ) );
			$count++;
		}

		if ( $count > 0 ) {
			AuditLog::record( 'dauerbuchungen_ausgefuehrt', 'system', 0, array(
				'anzahl' => $count,
				'datum'  => $today,
			) );
		}

		return $count;
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
