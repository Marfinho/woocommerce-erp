<?php
/**
 * Rechteprofile des Moduls Betrieb.
 *
 * Der Zuschnitt folgt der Frage, was jemand im Betrieb tut, nicht der
 * Tabellenstruktur:
 *
 * - Wer an der Maschine steht, muss erfassen und protokollieren können –
 *   Zeit, Scans, Picklisten, Druckjournal, Aufträge starten. Er muss dafür
 *   Stammdaten sehen, aber nicht ändern, und er hat mit Preisen, Kosten und
 *   Löhnen nichts zu tun.
 * - Wer den Betrieb führt, ändert Stammdaten und sieht die Zahlen.
 * - Wer die Anlage verantwortet, ändert Grundeinstellungen und vergibt
 *   Rechte und Löhne.
 *
 * Deshalb sind Sehen und Ändern getrennte Rechte und Geld ein eigenes: Ein
 * Aushilfsvertrag ist kein Grund, jemandem den Zugang zu verwehren, aber
 * auch keiner, ihm die Kalkulation zu zeigen.
 *
 * Die Klasse ist frei von Datenbank und WordPress, damit die Zuordnung
 * prüfbar ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Rechte und Profile.
 */
class AccessProfiles {

	/**
	 * Erfassen und protokollieren: buchen, scannen, Zeit schreiben,
	 * Aufträge starten und fertigmelden.
	 */
	const CAP_CAPTURE = 'lw16_capture';

	/**
	 * Stammdaten ändern: Artikel, Revisionen, Stücklisten, Drucker,
	 * Kontakte, Sollbestände.
	 */
	const CAP_PRODUCTION = 'lw16_manage_production';

	/**
	 * Auswertungen sehen.
	 */
	const CAP_REPORTS = 'lw16_view_reports';

	/**
	 * Geld sehen: Preise, Kosten, Kalkulation, Löhne.
	 */
	const CAP_COSTS = 'lw16_view_costs';

	/**
	 * Grundeinstellungen der Anlage ändern.
	 */
	const CAP_SETTINGS = 'lw16_manage_settings';

	/**
	 * Rechte und Löhne vergeben.
	 */
	const CAP_USERS = 'lw16_manage_users';

	/**
	 * Alle Rechte in der Reihenfolge, in der sie aufeinander aufbauen.
	 *
	 * @return array Recht => Beschriftung.
	 */
	public static function capabilities() {
		return array(
			self::CAP_CAPTURE    => __( 'Erfassen und protokollieren', 'lindenweg16' ),
			self::CAP_PRODUCTION => __( 'Stammdaten ändern', 'lindenweg16' ),
			self::CAP_REPORTS    => __( 'Auswertungen sehen', 'lindenweg16' ),
			self::CAP_COSTS      => __( 'Preise, Kosten und Löhne sehen', 'lindenweg16' ),
			self::CAP_SETTINGS   => __( 'Grundeinstellungen ändern', 'lindenweg16' ),
			self::CAP_USERS      => __( 'Rechte und Löhne vergeben', 'lindenweg16' ),
		);
	}

	/**
	 * Was ein Recht praktisch bedeutet – für die Rechtevergabe, damit
	 * niemand nach dem Namen raten muss.
	 *
	 * @return array Recht => Erklärung.
	 */
	public static function explanations() {
		return array(
			self::CAP_CAPTURE    => __( 'Zeit erfassen, scannen, picken, Druckaufträge buchen, Aufträge starten und fertigmelden. Sehen darf er dabei alles, was er zum Arbeiten braucht – ändern nichts davon.', 'lindenweg16' ),
			self::CAP_PRODUCTION => __( 'Artikel, Revisionen, Stücklisten, Drucker, Material, Kontakte und Sollbestände anlegen und ändern.', 'lindenweg16' ),
			self::CAP_REPORTS    => __( 'Dashboard-Kennzahlen und die Berichte öffnen.', 'lindenweg16' ),
			self::CAP_COSTS      => __( 'Kalkulation, Herstellkosten, Verkaufspreise und Stundenlöhne. Ohne dieses Recht bleiben diese Spalten leer statt vorhanden.', 'lindenweg16' ),
			self::CAP_SETTINGS   => __( 'Kalkulationsgrundlagen, Ziele, Import und Verfahrensdokumentation.', 'lindenweg16' ),
			self::CAP_USERS      => __( 'Profile zuweisen, Stundenlöhne pflegen, das Protokoll aller Nutzer einsehen.', 'lindenweg16' ),
		);
	}

	/**
	 * Die Profile mit ihren Rechten.
	 *
	 * @return array
	 */
	public static function profiles() {
		return array(
			'kein'      => array(
				'label' => __( 'Kein Zugriff', 'lindenweg16' ),
				'hint'  => __( 'Sieht das Modul Betrieb nicht.', 'lindenweg16' ),
				'caps'  => array(),
			),
			'werkstatt' => array(
				'label' => __( 'Werkstatt (Aushilfe, Minijob)', 'lindenweg16' ),
				'hint'  => __( 'Erfasst und protokolliert. Ändert keine Stammdaten, sieht keine Preise und keine Kosten.', 'lindenweg16' ),
				'caps'  => array( self::CAP_CAPTURE ),
			),
			'fertigung' => array(
				'label' => __( 'Fertigung', 'lindenweg16' ),
				'hint'  => __( 'Wie Werkstatt, darf zusätzlich Stammdaten pflegen und Auswertungen sehen – aber kein Geld.', 'lindenweg16' ),
				'caps'  => array( self::CAP_CAPTURE, self::CAP_PRODUCTION, self::CAP_REPORTS ),
			),
			'leitung'   => array(
				'label' => __( 'Betriebsleitung', 'lindenweg16' ),
				'hint'  => __( 'Alles Fachliche samt Kalkulation und Kosten. Keine Grundeinstellungen, keine Rechtevergabe.', 'lindenweg16' ),
				'caps'  => array( self::CAP_CAPTURE, self::CAP_PRODUCTION, self::CAP_REPORTS, self::CAP_COSTS ),
			),
			'admin'     => array(
				'label' => __( 'Vollzugriff', 'lindenweg16' ),
				'hint'  => __( 'Alles, einschliesslich Grundeinstellungen, Rechten und Löhnen.', 'lindenweg16' ),
				'caps'  => array( self::CAP_CAPTURE, self::CAP_PRODUCTION, self::CAP_REPORTS, self::CAP_COSTS, self::CAP_SETTINGS, self::CAP_USERS ),
			),
		);
	}

	/**
	 * Gibt es dieses Profil?
	 *
	 * @param string $profile Kennung.
	 * @return bool
	 */
	public static function exists( $profile ) {
		return array_key_exists( (string) $profile, self::profiles() );
	}

	/**
	 * Die Rechte eines Profils.
	 *
	 * @param string $profile Kennung.
	 * @return array
	 */
	public static function caps( $profile ) {
		$profiles = self::profiles();

		return isset( $profiles[ $profile ] ) ? $profiles[ $profile ]['caps'] : array();
	}

	/**
	 * Beschriftung eines Profils.
	 *
	 * @param string $profile Kennung.
	 * @return string
	 */
	public static function label( $profile ) {
		$profiles = self::profiles();

		return isset( $profiles[ $profile ] ) ? $profiles[ $profile ]['label'] : (string) $profile;
	}

	/**
	 * Welches Profil passt zu diesen Rechten?
	 *
	 * Gebraucht für die Anzeige bestehender Nutzer, deren Rechte nicht über
	 * ein Profil gesetzt wurden. Passt keines genau, heisst es auch so –
	 * ein ungefähr passendes Profil anzuzeigen wäre eine Falschaussage.
	 *
	 * @param array $caps Vorhandene Rechte.
	 * @return string Profilkennung oder leerer String.
	 */
	public static function match( array $caps ) {
		$have = array_values( array_unique( array_filter( $caps ) ) );
		sort( $have );

		foreach ( self::profiles() as $key => $profile ) {
			$want = $profile['caps'];
			sort( $want );

			if ( $want === $have ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Darf jemand mit diesen Rechten das?
	 *
	 * @param array  $caps Vorhandene Rechte.
	 * @param string $cap  Gefragtes Recht.
	 * @return bool
	 */
	public static function allows( array $caps, $cap ) {
		return in_array( (string) $cap, $caps, true );
	}
}
