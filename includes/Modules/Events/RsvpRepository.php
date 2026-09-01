<?php
/**
 * Datenzugriff für Event-Anmeldungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Events;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;

/**
 * Verwaltet `lw16_event_rsvp` samt Bestätigungs- und Abmelde-Token.
 */
class RsvpRepository {

	/**
	 * Gültigkeit eines Bestätigungslinks in Tagen.
	 */
	const BESTAETIGUNG_TAGE = 7;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_event_rsvp';
	}

	/**
	 * Token hashen – im Klartext steht es nur in der E-Mail.
	 *
	 * @param string $token Klartext-Token.
	 * @return string
	 */
	public static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Anmeldung anlegen oder eine vorhandene erneuern.
	 *
	 * Dieselbe Adresse zweimal ergibt keine zweite Anmeldung: Wer den Link
	 * nicht mehr findet und noch einmal absendet, bekommt einen neuen – sonst
	 * stünde er zweimal auf der Liste. Eine bereits bestätigte Anmeldung
	 * bleibt unangetastet.
	 *
	 * @param int    $event_id Event.
	 * @param string $name     Name.
	 * @param string $email    E-Mail.
	 * @param string $notiz    Freitext.
	 * @return array {id, token, bereits_bestaetigt, status}
	 */
	public static function anmelden( $event_id, $name, $email, $notiz = '' ) {
		global $wpdb;

		$event_id = (int) $event_id;
		$email    = sanitize_email( $email );
		$token    = bin2hex( random_bytes( 32 ) );

		$bestehend = self::find_by_email( $event_id, $email );

		if ( $bestehend && in_array( $bestehend->status, array( RsvpRechnung::ZUGESAGT, RsvpRechnung::WARTELISTE ), true ) ) {
			return array(
				'id'                 => (int) $bestehend->id,
				'token'              => '',
				'bereits_bestaetigt' => true,
				'status'             => $bestehend->status,
			);
		}

		$daten = array(
			'event_id'      => $event_id,
			'user_id'       => get_current_user_id() ?: null,
			'name'          => sanitize_text_field( $name ),
			'email'         => $email,
			'notiz'         => sanitize_textarea_field( $notiz ),
			'status'        => RsvpRechnung::OFFEN,
			'token_hash'    => self::hash( $token ),
			'token_ablauf'  => gmdate( 'Y-m-d H:i:s', time() + self::BESTAETIGUNG_TAGE * DAY_IN_SECONDS ),
			'bestaetigt_am' => null,
			'erstellt_am'   => current_time( 'mysql' ),
		);

		if ( $bestehend ) {
			$wpdb->update( self::table(), $daten, array( 'id' => (int) $bestehend->id ) );
			$id = (int) $bestehend->id;
		} else {
			$wpdb->insert( self::table(), $daten );
			$id = (int) $wpdb->insert_id;
		}

		return array(
			'id'                 => $id,
			'token'              => $token,
			'bereits_bestaetigt' => false,
			'status'             => RsvpRechnung::OFFEN,
		);
	}

	/**
	 * Anmeldung einer Adresse zu einem Event finden.
	 *
	 * @param int    $event_id Event.
	 * @param string $email    E-Mail.
	 * @return object|null
	 */
	public static function find_by_email( $event_id, $email ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE event_id = %d AND email = %s", // phpcs:ignore
			(int) $event_id,
			sanitize_email( $email )
		) );
	}

	/**
	 * Anmeldung über das Klartext-Token finden.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	public static function find_by_token( $token ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE token_hash = %s", // phpcs:ignore
			self::hash( $token )
		) );
	}

	/**
	 * Anzahl der Anmeldungen eines Events je Status.
	 *
	 * @param int    $event_id Event.
	 * @param string $status   Status.
	 * @return int
	 */
	public static function count( $event_id, $status ) {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE event_id = %d AND status = %s", // phpcs:ignore
			(int) $event_id,
			$status
		) );
	}

	/**
	 * Anmeldungen eines Events.
	 *
	 * @param int    $event_id Event.
	 * @param string $status   Status, leer = alle.
	 * @return array
	 */
	public static function all( $event_id, $status = '' ) {
		global $wpdb;
		$t = self::table();

		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$t} WHERE event_id = %d AND status = %s ORDER BY bestaetigt_am ASC, id ASC", // phpcs:ignore
				(int) $event_id,
				$status
			) );
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE event_id = %d ORDER BY id ASC", // phpcs:ignore
			(int) $event_id
		) );
	}

	/**
	 * Anmeldung bestätigen.
	 *
	 * Ob sie einen Platz bekommt oder auf die Warteliste rutscht, entscheidet
	 * sich hier – nicht schon beim Absenden. Eine nie bestätigte Anmeldung
	 * blockiert damit keinen Platz.
	 *
	 * @param object $rsvp           Datensatz.
	 * @param int    $max_teilnehmer Maximale Teilnehmerzahl.
	 * @return string Neuer Status.
	 */
	public static function bestaetigen( $rsvp, $max_teilnehmer ) {
		global $wpdb;

		if ( in_array( $rsvp->status, array( RsvpRechnung::ZUGESAGT, RsvpRechnung::WARTELISTE ), true ) ) {
			return (string) $rsvp->status;
		}

		$zugesagt = self::count( (int) $rsvp->event_id, RsvpRechnung::ZUGESAGT );
		$status   = RsvpRechnung::status_nach_bestaetigung( $max_teilnehmer, $zugesagt );

		$wpdb->update(
			self::table(),
			array(
				'status'        => $status,
				'bestaetigt_am' => current_time( 'mysql' ),
				'token_ablauf'  => null,
			),
			array( 'id' => (int) $rsvp->id )
		);

		AuditLog::record( 'event_rsvp_bestaetigt', 'event', (int) $rsvp->event_id, array(
			'rsvp_id' => (int) $rsvp->id,
			'status'  => $status,
		) );

		return $status;
	}

	/**
	 * Anmeldung zurückziehen.
	 *
	 * @param object $rsvp Datensatz.
	 * @return bool
	 */
	public static function absagen( $rsvp ) {
		global $wpdb;

		if ( RsvpRechnung::ABGESAGT === $rsvp->status ) {
			return false;
		}

		$wpdb->update(
			self::table(),
			array( 'status' => RsvpRechnung::ABGESAGT, 'abgesagt_am' => current_time( 'mysql' ) ),
			array( 'id' => (int) $rsvp->id )
		);

		AuditLog::record( 'event_rsvp_abgesagt', 'event', (int) $rsvp->event_id, array( 'rsvp_id' => (int) $rsvp->id ) );

		return true;
	}

	/**
	 * Status einer Anmeldung von Hand setzen (Backend).
	 *
	 * @param int    $id     Anmeldung.
	 * @param string $status Neuer Status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;

		$erlaubt = array( RsvpRechnung::OFFEN, RsvpRechnung::ZUGESAGT, RsvpRechnung::WARTELISTE, RsvpRechnung::ABGESAGT );
		if ( ! in_array( $status, $erlaubt, true ) ) {
			return false;
		}

		$wpdb->update( self::table(), array( 'status' => $status ), array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * Freigewordene Plätze mit der Warteliste auffüllen.
	 *
	 * Läuft nach jeder Absage. Nachgerückt wird in der Reihenfolge der
	 * Bestätigung – wer zuerst zugesagt hat, ist zuerst dran.
	 *
	 * @param int $event_id       Event.
	 * @param int $max_teilnehmer Maximale Teilnehmerzahl.
	 * @return array Nachgerückte Datensätze.
	 */
	public static function nachruecken( $event_id, $max_teilnehmer ) {
		global $wpdb;

		$event_id  = (int) $event_id;
		$warteliste = self::all( $event_id, RsvpRechnung::WARTELISTE );
		if ( ! $warteliste ) {
			return array();
		}

		$zugesagt = self::count( $event_id, RsvpRechnung::ZUGESAGT );
		$anzahl   = RsvpRechnung::nachruecker_anzahl( $max_teilnehmer, $zugesagt, count( $warteliste ) );

		if ( $anzahl <= 0 ) {
			return array();
		}

		$nachgerueckt = array_slice( $warteliste, 0, $anzahl );

		foreach ( $nachgerueckt as $rsvp ) {
			$wpdb->update(
				self::table(),
				array( 'status' => RsvpRechnung::ZUGESAGT ),
				array( 'id' => (int) $rsvp->id )
			);
			AuditLog::record( 'event_rsvp_nachgerueckt', 'event', $event_id, array( 'rsvp_id' => (int) $rsvp->id ) );
		}

		return $nachgerueckt;
	}

	/**
	 * Unbestätigte Anmeldungen mit abgelaufenem Link aufräumen.
	 *
	 * @return int Anzahl gelöschter Zeilen.
	 */
	public static function purge_abgelaufene() {
		global $wpdb;
		$t = self::table();

		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$t} WHERE status = %s AND token_ablauf IS NOT NULL AND token_ablauf < %s", // phpcs:ignore
			RsvpRechnung::OFFEN,
			gmdate( 'Y-m-d H:i:s' )
		) );
	}
}
