<?php
/**
 * Buchungsjournal: GoBD-konformes, append-only Kassenbuch.
 *
 * Jede Einnahme (WooCommerce-Bestellung) und Ausgabe (festgeschriebener Beleg)
 * erzeugt einen Buchungssatz. Stornierungen laufen über Gegenbuchungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\HashChain;
use Lindenweg16\Core\AuditLog;

/**
 * Verwaltet das Buchungsjournal (lw16_buchungen).
 */
class BuchungRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_buchungen';
	}

	/**
	 * Nächste Buchungsnummer für ein Kalenderjahr.
	 *
	 * @param int $jahr Kalenderjahr.
	 * @return array [ 'laufnummer' => int, 'buchungsnummer' => string ]
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
			'buchungsnummer' => \Lindenweg16\Core\Calc::buchungsnummer( $jahr, $lnr ),
		);
	}

	/**
	 * Buchung anlegen (Insert-only, keine Updates).
	 *
	 * @param array $data Buchungsdaten.
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$jahr = isset( $data['jahr'] ) ? (int) $data['jahr'] : (int) gmdate( 'Y' );
		if ( ! isset( $data['buchungsnummer'] ) || ! isset( $data['laufnummer'] ) ) {
			$next                   = self::next_nummer( $jahr );
			$data['laufnummer']     = $next['laufnummer'];
			$data['buchungsnummer'] = $next['buchungsnummer'];
		}

		$defaults = array(
			'buchungsnummer' => '',
			'jahr'           => $jahr,
			'laufnummer'     => 0,
			'buchungsdatum'  => gmdate( 'Y-m-d' ),
			'erfasst_am'     => current_time( 'mysql' ),
			'typ'            => 'ausgabe',
			'betrag'         => 0,
			'kategorie'      => '',
			'beschreibung'   => '',
			'referenz_typ'   => '',
			'referenz_id'    => null,
			'storniert'      => 0,
			'storno_von_id'  => null,
			'erstellt_von'   => get_current_user_id(),
		);
		$data = wp_parse_args( $data, $defaults );

		// Hash-Verkettung.
		$hashes             = HashChain::prepare_insert( 'buchungen', $data );
		$data['hash']             = $hashes['hash'];
		$data['vorheriger_hash']  = $hashes['vorheriger_hash'];

		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Buchung aus festgeschriebenem Beleg erzeugen.
	 *
	 * @param object $beleg Beleg-Datensatz.
	 * @return int Buchungs-ID.
	 */
	public static function create_from_beleg( $beleg ) {
		return self::create( array(
			'buchungsdatum' => $beleg->datum,
			'typ'           => 'ausgabe',
			'betrag'        => abs( (float) $beleg->betrag ),
			'kategorie'     => (string) $beleg->eur_kategorie,
			'beschreibung'  => trim( $beleg->haendler . ' – ' . $beleg->notiz, ' –' ),
			'referenz_typ'  => 'beleg',
			'referenz_id'   => (int) $beleg->id,
		) );
	}

	/**
	 * Buchung aus erstellter Rechnung (Einnahme) erzeugen.
	 *
	 * @param object    $rechnung Rechnungs-Datensatz.
	 * @param \WC_Order $order    WooCommerce-Bestellung.
	 * @return int Buchungs-ID.
	 */
	public static function create_from_rechnung( $rechnung, $order ) {
		$datum = $order->get_date_created()
			? $order->get_date_created()->format( 'Y-m-d' )
			: gmdate( 'Y-m-d' );

		return self::create( array(
			'buchungsdatum' => $datum,
			'typ'           => 'einnahme',
			'betrag'        => abs( (float) $rechnung->betrag ),
			'kategorie'     => 'Einnahmen',
			'beschreibung'  => sprintf( 'Rechnung %s / Bestellung #%s', $rechnung->rechnungsnummer, $order->get_order_number() ),
			'referenz_typ'  => 'rechnung',
			'referenz_id'   => (int) $rechnung->id,
		) );
	}

	/**
	 * Zahlungsgebühr einer Bestellung als Ausgabe buchen.
	 *
	 * Bewusst mit fester Referenz auf die Bestellung: Ein zweiter Aufruf für
	 * dieselbe Bestellung findet die vorhandene Buchung und legt keine neue
	 * an. Der Gebührenabruf läuft bei jedem Statuswechsel – ohne diese Sperre
	 * stünde die Gebühr mehrfach in der EÜR.
	 *
	 * @param \WC_Order $order  Bestellung.
	 * @param float     $amount Gebührenbetrag.
	 * @param string    $source Fundstelle, für die Nachvollziehbarkeit.
	 * @return int Buchungs-ID, 0 wenn bereits gebucht.
	 */
	public static function create_from_order_fee( $order, $amount, $source = '' ) {
		$amount = round( abs( (float) $amount ), 2 );

		if ( $amount <= 0 ) {
			return 0;
		}

		$existing = self::get_by_referenz( 'gebuehr', (int) $order->get_id() );

		if ( $existing ) {
			return 0;
		}

		$datum = $order->get_date_created()
			? $order->get_date_created()->format( 'Y-m-d' )
			: gmdate( 'Y-m-d' );

		$beschreibung = sprintf(
			/* translators: %s: Bestellnummer. */
			__( 'Zahlungsgebühr Bestellung #%s', 'lindenweg16' ),
			$order->get_order_number()
		);

		if ( $source ) {
			$beschreibung .= ' – ' . $source;
		}

		return self::create(
			array(
				'buchungsdatum' => $datum,
				'typ'           => 'ausgabe',
				'betrag'        => $amount,
				'kategorie'     => __( 'Nebenkosten des Geldverkehrs', 'lindenweg16' ),
				'beschreibung'  => $beschreibung,
				'referenz_typ'  => 'gebuehr',
				'referenz_id'   => (int) $order->get_id(),
			)
		);
	}

	/**
	 * Storno-Buchung erzeugen (Gegenbuchung).
	 *
	 * @param int    $original_id Ursprüngliche Buchungs-ID.
	 * @param string $grund       Stornierungsgrund.
	 * @return int|false Neue Buchungs-ID oder false bei Fehler.
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

		// Original als storniert markieren (kein Hash-Bruch, da storniert nicht im Hash).
		$wpdb->update( self::table(), array( 'storniert' => 1 ), array( 'id' => (int) $original_id ) );

		// Gegenbuchung.
		$id = self::create( array(
			'buchungsdatum' => gmdate( 'Y-m-d' ),
			'typ'           => 'storno',
			'betrag'        => abs( (float) $original->betrag ),
			'kategorie'     => $original->kategorie,
			'beschreibung'  => sprintf( 'Storno von %s: %s', $original->buchungsnummer, $grund ),
			'referenz_typ'  => 'storno',
			'referenz_id'   => (int) $original_id,
			'storno_von_id' => (int) $original_id,
		) );

		AuditLog::record( 'buchung_storniert', 'buchung', $original_id, array(
			'grund'                 => $grund,
			'storno_buchungsnummer' => self::get( $id )->buchungsnummer ?? '',
		) );

		return $id;
	}

	/**
	 * Einzelne Buchung holen.
	 *
	 * @param int $id Buchungs-ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/**
	 * Buchung zu einem Referenz-Objekt finden.
	 *
	 * @param string $typ Referenz-Typ.
	 * @param int    $id  Referenz-ID.
	 * @return object|null
	 */
	public static function get_by_referenz( $typ, $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE referenz_typ = %s AND referenz_id = %d AND storniert = 0 LIMIT 1", // phpcs:ignore
			$typ,
			$id
		) );
	}

	/**
	 * Paginierte Buchungsliste.
	 *
	 * @param array $args from, to, typ, per_page, page.
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
			$where   .= ' AND buchungsdatum >= %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where   .= ' AND buchungsdatum <= %s';
			$params[] = $args['to'];
		}
		if ( ! empty( $args['typ'] ) ) {
			$where   .= ' AND typ = %s';
			$params[] = $args['typ'];
		}

		$sql      = "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * Gesamtanzahl.
	 *
	 * @param array $args from, to, typ.
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;
		$t      = self::table();
		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['from'] ) ) {
			$where   .= ' AND buchungsdatum >= %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where   .= ' AND buchungsdatum <= %s';
			$params[] = $args['to'];
		}
		if ( ! empty( $args['typ'] ) ) {
			$where   .= ' AND typ = %s';
			$params[] = $args['typ'];
		}
		$sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
	}

	/**
	 * Alle Buchungen für den Export (ungepaginiert, nach Datum sortiert).
	 *
	 * @param string $from Startdatum.
	 * @param string $to   Enddatum.
	 * @return array
	 */
	public static function export( $from, $to ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE buchungsdatum >= %s AND buchungsdatum <= %s ORDER BY id ASC", // phpcs:ignore
			$from,
			$to
		), ARRAY_A );
	}
}
