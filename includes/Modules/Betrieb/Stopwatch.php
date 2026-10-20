<?php
/**
 * Die Rechnung hinter der Stoppuhr.
 *
 * Eine laufende Uhr ist kein Zeiteintrag. Sie ist ein Zustand: „seit 9:12
 * läuft etwas". Erst das Stoppen macht daraus eine Buchung, und zwar genau
 * einmal – dieselbe Regel wie beim Wareneingang und beim Bestand.
 *
 * Was hier nicht passiert: heimlich runden, kappen oder korrigieren. Eine
 * Uhr, die seit vierzehn Stunden läuft, wurde vergessen; das System sagt
 * das und lässt den Menschen entscheiden, statt vierzehn Stunden zu buchen
 * oder stillschweigend acht daraus zu machen.
 *
 * Frei von Datenbank und WordPress-Umgebung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Laufzeit, Umrechnung und Plausibilität.
 */
class Stopwatch {

	/**
	 * Ab hier ist eine Laufzeit eine vergessene Uhr und keine Arbeitszeit.
	 *
	 * Zehn Stunden am Stück ohne einmal zu stoppen kommt vor, vierzehn
	 * praktisch nie – und wenn doch, ist die Rückfrage billiger als der
	 * falsche Eintrag.
	 */
	const IMPLAUSIBLE_SECONDS = 10 * HOUR_IN_SECONDS;

	/**
	 * Unter dieser Laufzeit lohnt sich kein Eintrag.
	 *
	 * Ein versehentlicher Doppelklick auf Start und Stopp soll keine
	 * Buchung über null Stunden hinterlassen.
	 */
	const MIN_SECONDS = 60;

	/**
	 * Zeitpunkt um Sekunden nach hinten schieben – für die Pause.
	 *
	 * Wer pausiert, verschiebt den Start um die Dauer der Pause. Dann zählt
	 * die Uhr nach dem Weitermachen genau die Zeit, die gearbeitet wurde.
	 *
	 * @param string $value   Zeitpunkt (Y-m-d H:i:s, UTC).
	 * @param int    $seconds Sekunden (nie negativ).
	 * @return string Der verschobene Zeitpunkt, bei ungültiger Eingabe unverändert.
	 */
	public static function shift( $value, $seconds ) {
		$ts = self::timestamp( $value );

		if ( null === $ts ) {
			return (string) $value;
		}

		return gmdate( 'Y-m-d H:i:s', $ts + max( 0, (int) $seconds ) );
	}

	/**
	 * Verstrichene Sekunden.
	 *
	 * @param string $started_at Startzeit (Y-m-d H:i:s, UTC).
	 * @param string $now        Jetzt (Y-m-d H:i:s, UTC).
	 * @return int Nie negativ – eine rückwärts laufende Uhr ist kein Guthaben.
	 */
	public static function elapsed( $started_at, $now ) {
		$start = self::timestamp( $started_at );
		$jetzt = self::timestamp( $now );

		if ( null === $start || null === $jetzt ) {
			return 0;
		}

		return max( 0, $jetzt - $start );
	}

	/**
	 * Sekunden als Stunden für die Buchung.
	 *
	 * Auf zwei Nachkommastellen, das sind 36 Sekunden. Feiner aufgelöst
	 * täuschte die Zahl eine Genauigkeit vor, die eine per Hand gestartete
	 * Uhr nicht hat.
	 *
	 * @param int $seconds Sekunden.
	 * @return float
	 */
	public static function hours( $seconds ) {
		return round( max( 0, (int) $seconds ) / 3600, 2 );
	}

	/**
	 * Laufzeit als Uhrzeit für die Anzeige.
	 *
	 * @param int $seconds Sekunden.
	 * @return string h:mm:ss
	 */
	public static function format( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		return sprintf(
			'%d:%02d:%02d',
			intdiv( $seconds, 3600 ),
			intdiv( $seconds % 3600, 60 ),
			$seconds % 60
		);
	}

	/**
	 * Kurzform für die Adminleiste – dort ist der Platz knapp.
	 *
	 * @param int $seconds Sekunden.
	 * @return string
	 */
	public static function short( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		return sprintf( '%d:%02d', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
	}

	/**
	 * Ist die Laufzeit lang genug für eine Buchung?
	 *
	 * @param int $seconds Sekunden.
	 * @return bool
	 */
	public static function is_bookable( $seconds ) {
		return (int) $seconds >= self::MIN_SECONDS;
	}

	/**
	 * Sieht die Laufzeit nach einer vergessenen Uhr aus?
	 *
	 * @param int $seconds Sekunden.
	 * @return bool
	 */
	public static function is_implausible( $seconds ) {
		return (int) $seconds >= self::IMPLAUSIBLE_SECONDS;
	}

	/**
	 * Der Tag, auf den die Zeit gebucht wird.
	 *
	 * Der Starttag, nicht der Stopptag: Wer um 23:40 anfängt und um 0:20
	 * aufhört, hat am Vortag gearbeitet. Andersherum stünde die Arbeit an
	 * einem Tag, an dem sie zu vierzig Minuten nach Mitternacht begann.
	 *
	 * @param string $started_at Startzeit (UTC).
	 * @param string $timezone   Zeitzone der Anlage.
	 * @return string Y-m-d
	 */
	public static function entry_date( $started_at, $timezone = 'UTC' ) {
		$start = self::timestamp( $started_at );

		if ( null === $start ) {
			return gmdate( 'Y-m-d' );
		}

		return gmdate( 'Y-m-d', $start + self::offset( $timezone, $start ) );
	}

	/**
	 * Versatz einer Zeitzone in Sekunden.
	 *
	 * @param string $timezone  Zeitzone.
	 * @param int    $timestamp Zeitpunkt.
	 * @return int
	 */
	protected static function offset( $timezone, $timestamp ) {
		try {
			$zone = new \DateTimeZone( (string) $timezone );
			$zeit = new \DateTime( '@' . (int) $timestamp );

			return $zone->getOffset( $zeit );
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * Eine Zeitangabe als Zeitstempel.
	 *
	 * @param string $value Zeitangabe.
	 * @return int|null
	 */
	protected static function timestamp( $value ) {
		$value = (string) $value;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return null;
		}

		$zeit = strtotime( $value . ' UTC' );

		return false === $zeit ? null : (int) $zeit;
	}
}
