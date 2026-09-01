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
 *
 * Gezählt werden durchgehend **Plätze**, nicht Anmeldungen: Eine Anmeldung
 * mit zwei Begleitpersonen belegt drei. Wer hier Zeilen zählte, hätte ein
 * überbuchtes Event.
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
	 * Höchstzahl an Begleitpersonen je Anmeldung.
	 *
	 * Eine Obergrenze braucht es, weil das Formular öffentlich ist – ohne sie
	 * trüge eine einzige Anmeldung beliebig viele Namen ein.
	 */
	const MAX_BEGLEITER = 10;

	/**
	 * Plätze, die eine Anmeldung belegt: sie selbst plus Begleitpersonen.
	 *
	 * @param int $begleiter Anzahl der Begleitpersonen.
	 * @return int
	 */
	public static function plaetze_fuer( $begleiter ) {
		return 1 + min( self::MAX_BEGLEITER, max( 0, (int) $begleiter ) );
	}

	/**
	 * Namensliste der Begleitpersonen säubern.
	 *
	 * Leere Zeilen fallen heraus – das Formular fügt sie auf Klick hinzu, und
	 * eine ungenutzte bleibt oft stehen. Ein Platz ohne Namen wird dagegen
	 * nicht vergeben: Bei einem Rennen oder einer Ausfahrt muss auf der
	 * Startliste stehen, wer mitfährt.
	 *
	 * @param array $roh Eingegebene Namen.
	 * @return array Bereinigte Namen, höchstens MAX_BEGLEITER.
	 */
	public static function begleiter_liste( array $roh ) {
		$namen = array();

		foreach ( $roh as $name ) {
			if ( ! is_scalar( $name ) ) {
				continue;
			}
			$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
			if ( '' === $name ) {
				continue;
			}
			$namen[] = $name;
			if ( count( $namen ) >= self::MAX_BEGLEITER ) {
				break;
			}
		}

		return $namen;
	}

	/**
	 * Passt eine Anmeldung dieser Größe noch hinein?
	 *
	 * @param int $max    Maximale Teilnehmerzahl, 0 = unbegrenzt.
	 * @param int $belegt Belegte Plätze.
	 * @param int $bedarf Benötigte Plätze.
	 * @return bool
	 */
	public static function passt( $max, $belegt, $bedarf = 1 ) {
		$frei = self::freie_plaetze( $max, $belegt );
		if ( null === $frei ) {
			return true;
		}
		return $frei >= max( 1, (int) $bedarf );
	}

	/**
	 * Status, den eine neue Anmeldung nach ihrer Bestätigung bekommt.
	 *
	 * Entschieden wird erst bei der Bestätigung, nicht beim Absenden: Sonst
	 * blockierte eine nie bestätigte Anmeldung einen Platz.
	 *
	 * @param int $max      Maximale Teilnehmerzahl.
	 * @param int $zugesagt Vergebene Plätze.
	 * @param int $bedarf   Benötigte Plätze (mit Begleitung).
	 * @return string ZUGESAGT oder WARTELISTE.
	 */
	public static function status_nach_bestaetigung( $max, $zugesagt, $bedarf = 1 ) {
		return self::passt( $max, $zugesagt, $bedarf ) ? self::ZUGESAGT : self::WARTELISTE;
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
	 * Wie viele Wartende vom Anfang der Liste rücken nach?
	 *
	 * Streng der Reihe nach: Passt der Nächste nicht mehr hinein, ist
	 * Schluss – auch wenn hinter ihm ein Einzelner läge, der noch passte.
	 * Sonst überholte eine Einzelperson eine wartende Familie, und zwar
	 * jedes Mal wieder. Wer das im Einzelfall anders will, setzt den Status
	 * im Backend von Hand.
	 *
	 * @param array $bedarfe Benötigte Plätze der Wartenden, in ihrer Reihenfolge.
	 * @param int   $max     Maximale Teilnehmerzahl, 0 = unbegrenzt.
	 * @param int   $belegt  Belegte Plätze.
	 * @return int Anzahl der Wartenden vom Anfang der Liste.
	 */
	public static function nachruecker_anzahl( array $bedarfe, $max, $belegt ) {
		if ( ! self::ist_begrenzt( $max ) ) {
			// Ohne Grenze gehört niemand auf die Warteliste; alle rücken nach.
			return count( $bedarfe );
		}

		$frei   = (int) self::freie_plaetze( $max, $belegt );
		$anzahl = 0;

		foreach ( $bedarfe as $bedarf ) {
			$bedarf = max( 1, (int) $bedarf );
			if ( $bedarf > $frei ) {
				break;
			}
			$frei -= $bedarf;
			$anzahl++;
		}

		return $anzahl;
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
			/* translators: %d: Anzahl der Teilnehmenden. */
			return sprintf( _n( '%d Teilnehmer', '%d Teilnehmer', $zugesagt, 'lindenweg16' ), $zugesagt );
		}

		return sprintf(
			/* translators: 1: Zusagen, 2: Plätze insgesamt. */
			__( '%1$d von %2$d Plätzen belegt', 'lindenweg16' ),
			$zugesagt,
			(int) $max
		);
	}
}
