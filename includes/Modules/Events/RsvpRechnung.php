<?php
/**
 * Reine Regeln der Anmeldung: Plätze, Warteliste, Nachrücken.
 *
 * Ohne WordPress und ohne Datenbank, damit die Kapazitätsrechnung einzeln
 * prüfbar bleibt – sie entscheidet, wer mitfahren darf.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Rechnet Plätze und Wartelistenplätze aus.
 */
class RsvpRechnung {

	/**
	 * Status einer bestätigten Anmeldung mit Platz.
	 */
	const ZUGESAGT = 'zugesagt';

	/**
	 * Status einer bestätigten Anmeldung ohne Platz.
	 */
	const WARTELISTE = 'warteliste';

	/**
	 * Angemeldet, aber noch nicht über den Link bestätigt.
	 */
	const OFFEN = 'offen';

	/**
	 * Zurückgezogen oder abgesagt.
	 */
	const ABGESAGT = 'abgesagt';

	/**
	 * Ist die Teilnehmerzahl überhaupt begrenzt?
	 *
	 * @param int $max Maximale Teilnehmerzahl (0 oder kleiner = unbegrenzt).
	 * @return bool
	 */
	public static function ist_begrenzt( $max ) {
		return (int) $max > 0;
	}

	/**
	 * Freie Plätze.
	 *
	 * @param int $max      Maximale Teilnehmerzahl, 0 = unbegrenzt.
	 * @param int $zugesagt Bereits vergebene Plätze.
	 * @return int|null null bei unbegrenzter Teilnehmerzahl.
	 */
	public static function freie_plaetze( $max, $zugesagt ) {
		if ( ! self::ist_begrenzt( $max ) ) {
			return null;
		}
		return max( 0, (int) $max - max( 0, (int) $zugesagt ) );
	}

	/**
	 * Ist das Event ausgebucht?
	 *
	 * @param int $max      Maximale Teilnehmerzahl.
	 * @param int $zugesagt Vergebene Plätze.
	 * @return bool
	 */
	public static function ist_ausgebucht( $max, $zugesagt ) {
		$frei = self::freie_plaetze( $max, $zugesagt );
		return null !== $frei && $frei <= 0;
	}

	/**
	 * Status, den eine neue Anmeldung nach ihrer Bestätigung bekommt.
	 *
	 * Entschieden wird erst bei der Bestätigung, nicht beim Absenden: Sonst
	 * blockierte eine nie bestätigte Anmeldung einen Platz.
	 *
	 * @param int $max      Maximale Teilnehmerzahl.
	 * @param int $zugesagt Vergebene Plätze.
	 * @return string ZUGESAGT oder WARTELISTE.
	 */
	public static function status_nach_bestaetigung( $max, $zugesagt ) {
		return self::ist_ausgebucht( $max, $zugesagt ) ? self::WARTELISTE : self::ZUGESAGT;
	}

	/**
	 * Zustand des Anmeldeformulars auf der Eventseite.
	 *
	 * @param bool $rsvp_aktiv   Anmeldung eingeschaltet.
	 * @param bool $event_vorbei Beginn liegt in der Vergangenheit.
	 * @param int  $max          Maximale Teilnehmerzahl.
	 * @param int  $zugesagt     Vergebene Plätze.
	 * @return string aus|vorbei|warteliste|offen
	 */
	public static function formular_status( $rsvp_aktiv, $event_vorbei, $max, $zugesagt ) {
		if ( ! $rsvp_aktiv ) {
			return 'aus';
		}
		if ( $event_vorbei ) {
			return 'vorbei';
		}
		if ( self::ist_ausgebucht( $max, $zugesagt ) ) {
			return 'warteliste';
		}
		return 'offen';
	}

	/**
	 * Wie viele von der Warteliste dürfen nachrücken?
	 *
	 * @param int $max      Maximale Teilnehmerzahl.
	 * @param int $zugesagt Vergebene Plätze.
	 * @param int $wartend  Länge der Warteliste.
	 * @return int
	 */
	public static function nachruecker_anzahl( $max, $zugesagt, $wartend ) {
		$wartend = max( 0, (int) $wartend );

		if ( ! self::ist_begrenzt( $max ) ) {
			// Ohne Grenze gehört niemand auf die Warteliste; alle rücken nach.
			return $wartend;
		}

		return min( $wartend, (int) self::freie_plaetze( $max, $zugesagt ) );
	}

	/**
	 * Anzeigetext zur Belegung.
	 *
	 * @param int $max      Maximale Teilnehmerzahl.
	 * @param int $zugesagt Vergebene Plätze.
	 * @return string
	 */
	public static function belegung_text( $max, $zugesagt ) {
		$zugesagt = max( 0, (int) $zugesagt );

		if ( ! self::ist_begrenzt( $max ) ) {
			/* translators: %d: Anzahl der Zusagen. */
			return sprintf( _n( '%d Anmeldung', '%d Anmeldungen', $zugesagt, 'lindenweg16' ), $zugesagt );
		}

		return sprintf(
			/* translators: 1: Zusagen, 2: Plätze insgesamt. */
			__( '%1$d von %2$d Plätzen belegt', 'lindenweg16' ),
			$zugesagt,
			(int) $max
		);
	}
}
