<?php
/**
 * Die laufende Uhr eines Nutzers.
 *
 * Gespeichert wird sie am Nutzer, nicht in einer Tabelle: Eine laufende Uhr
 * ist ein Zustand, kein Vorgang. Sie hinterlässt keine Spur im Protokoll und
 * keine Zeile in der Auswertung – das tut erst der Zeiteintrag, den das
 * Stoppen erzeugt.
 *
 * Jeder Nutzer hat höchstens eine laufende Uhr. Zwei gleichzeitig wären eine
 * Zeit, die zweimal gezählt wird.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Starten, beobachten und beenden der Stoppuhr.
 */
class TimerState {

	/**
	 * Schlüssel am Nutzer.
	 */
	const META_KEY = 'lw16_stoppuhr';

	/**
	 * Die laufende Uhr eines Nutzers.
	 *
	 * @param int $user_id Nutzer, 0 für den angemeldeten.
	 * @return array|null {started_at, seen, last_seen_at, work_order_id}
	 */
	public static function get( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return null;
		}

		$state = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $state ) || empty( $state['started_at'] ) ) {
			return null;
		}

		return array(
			'started_at'    => (string) $state['started_at'],
			'last_seen_at'  => isset( $state['last_seen_at'] ) ? (string) $state['last_seen_at'] : (string) $state['started_at'],
			'seen'          => isset( $state['seen'] ) && is_array( $state['seen'] ) ? $state['seen'] : array(),
			'work_order_id' => isset( $state['work_order_id'] ) ? (int) $state['work_order_id'] : 0,
			'paused_at'     => isset( $state['paused_at'] ) ? (string) $state['paused_at'] : '',
		);
	}

	/**
	 * Uhr anhalten. Eine angehaltene oder fehlende Uhr bleibt, wie sie ist.
	 *
	 * @return bool Ob angehalten wurde.
	 */
	public static function pause() {
		$user_id = get_current_user_id();
		$raw     = $user_id ? get_user_meta( $user_id, self::META_KEY, true ) : null;
		$state   = self::get( $user_id );

		if ( ! $state || '' !== $state['paused_at'] || ! is_array( $raw ) ) {
			return false;
		}

		$raw['paused_at'] = current_time( 'mysql', true );
		update_user_meta( $user_id, self::META_KEY, $raw );

		return true;
	}

	/**
	 * Nach der Pause weitermachen: Der Start rückt um die Pause nach hinten.
	 *
	 * @return bool Ob weitergemacht wurde.
	 */
	public static function resume() {
		$user_id = get_current_user_id();
		$raw     = $user_id ? get_user_meta( $user_id, self::META_KEY, true ) : null;
		$state   = self::get( $user_id );

		if ( ! $state || '' === $state['paused_at'] || ! is_array( $raw ) ) {
			return false;
		}

		$pause = Stopwatch::elapsed( $state['paused_at'], current_time( 'mysql', true ) );

		$raw['started_at']   = Stopwatch::shift( $state['started_at'], $pause );
		$raw['last_seen_at'] = current_time( 'mysql', true );
		unset( $raw['paused_at'] );
		update_user_meta( $user_id, self::META_KEY, $raw );

		return true;
	}

	/**
	 * Läuft bei diesem Nutzer eine Uhr?
	 *
	 * @param int $user_id Nutzer.
	 * @return bool
	 */
	public static function is_running( $user_id = 0 ) {
		return null !== self::get( $user_id );
	}

	/**
	 * Uhr starten.
	 *
	 * Eine bereits laufende Uhr wird nicht überschrieben – der zweite Klick
	 * auf Start ist ein Versehen, und ein zurückgesetzter Start verlöre die
	 * bisher gelaufene Zeit lautlos.
	 *
	 * @param int $work_order_id Zugehöriger Fertigungsauftrag.
	 * @return bool Ob gestartet wurde.
	 */
	public static function start( $work_order_id = 0 ) {
		$user_id = get_current_user_id();

		if ( ! $user_id || self::is_running( $user_id ) ) {
			return false;
		}

		$jetzt = current_time( 'mysql', true );

		update_user_meta(
			$user_id,
			self::META_KEY,
			array(
				'started_at'    => $jetzt,
				'last_seen_at'  => $jetzt,
				'seen'          => array(),
				'work_order_id' => (int) $work_order_id,
			)
		);

		return true;
	}

	/**
	 * Festhalten, wo der Nutzer gerade ist.
	 *
	 * Die Zeit seit der letzten Beobachtung wird der zuletzt gesehenen
	 * Seite zugeschlagen, nicht der aktuellen: Die Minuten sind dort
	 * vergangen, wo er war, nicht dort, wo er gerade ankommt.
	 *
	 * @param string $page Seitenkennung der aufgerufenen Seite.
	 */
	public static function touch( $page ) {
		$user_id = get_current_user_id();
		$state   = self::get( $user_id );

		// Während der Pause gehört keine Zeit einer Seite.
		if ( ! $state || '' !== $state['paused_at'] ) {
			return;
		}

		$jetzt   = current_time( 'mysql', true );
		$abstand = Stopwatch::elapsed( $state['last_seen_at'], $jetzt );

		$state['seen'] = TimeCategoryGuess::accumulate(
			$state['seen'],
			isset( $state['last_page'] ) ? $state['last_page'] : $page,
			$abstand
		);

		// Nichts vergangen und dieselbe Seite: Ein Schreibvorgang je
		// Seitenaufruf ist genug, zwei sind Last ohne Inhalt.
		$gleich = isset( $state['last_page'] ) && (string) $state['last_page'] === (string) $page;

		if ( $abstand <= 0 && $gleich ) {
			return;
		}

		$state['last_seen_at'] = $jetzt;
		$state['last_page']    = (string) $page;

		update_user_meta( $user_id, self::META_KEY, $state );
	}

	/**
	 * Uhr wegwerfen, ohne zu buchen.
	 *
	 * @param int $user_id Nutzer.
	 */
	public static function discard( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( $user_id ) {
			delete_user_meta( $user_id, self::META_KEY );
		}
	}

	/**
	 * Was beim Stoppen im Formular stehen soll.
	 *
	 * Gebucht wird hier nichts. Diese Klasse kennt den Zustand, das
	 * Buchen bleibt beim Zeiteintrag – sonst gäbe es zwei Stellen, an denen
	 * Arbeitszeit entsteht.
	 *
	 * @param int $user_id Nutzer.
	 * @return array|null
	 */
	public static function stop_form( $user_id = 0 ) {
		$state = self::get( $user_id );

		if ( ! $state ) {
			return null;
		}

		// Die Zeit bis jetzt gehört noch der zuletzt gesehenen Seite, sonst
		// fehlt die letzte Sitzung im Vorschlag.
		// Angehalten zählt die Zeit nur bis zum Anhalten.
		$bis = '' !== $state['paused_at'] ? $state['paused_at'] : current_time( 'mysql', true );

		$seen = TimeCategoryGuess::accumulate(
			$state['seen'],
			isset( $state['last_page'] ) ? $state['last_page'] : '',
			Stopwatch::elapsed( $state['last_seen_at'], $bis )
		);

		$sekunden = Stopwatch::elapsed( $state['started_at'], $bis );
		$vorschlag = TimeCategoryGuess::suggest( $seen );

		return array(
			'started_at'    => $state['started_at'],
			'seconds'       => $sekunden,
			'hours'         => Stopwatch::hours( $sekunden ),
			'entry_date'    => Stopwatch::entry_date( $state['started_at'], wp_timezone_string() ),
			'work_order_id' => $state['work_order_id'],
			'suggestion'    => $vorschlag,
			'explanation'   => TimeCategoryGuess::explain( $seen ),
			'bookable'      => Stopwatch::is_bookable( $sekunden ),
			'implausible'   => Stopwatch::is_implausible( $sekunden ),
			'paused'        => '' !== $state['paused_at'],
		);
	}
}
