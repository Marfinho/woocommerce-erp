<?php
/**
 * Die Testumgebung der DHL.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl;

defined( 'ABSPATH' ) || exit;

/**
 * Für die Sandbox legt man keinen Benutzer an.
 *
 * Das ist die Stelle, an der man eine Stunde sucht: Im
 * Geschäftskundenportal gibt es keinen Testzugang zum Anlegen, und im
 * Entwicklerportal auch nicht. Die DHL veröffentlicht stattdessen einen
 * festen Demo-Zugang, den alle benutzen – samt einer Testkundennummer,
 * auf die die Sandbox bucht.
 *
 * Benutzer und Passwort dieses Zugangs stehen als Vorgabe in den
 * Einstellungen und lassen sich dort ansehen. Was dort nicht steht und
 * auch nicht hingehört, ist die Kundennummer: Die Sandbox bucht auf eine
 * Testkundennummer der DHL. Schickte man die echte mit, entstünde eine
 * Abrechnungsnummer, die es in der Testumgebung nicht gibt – und die
 * Ablehnung sähe nach einem Fehler in der Adresse aus.
 *
 * Nicht ersetzt werden API-Key und API-Secret. Die gehören der eigenen
 * Anwendung und werden je Umgebung getrennt gepflegt.
 */
class DhlSandbox {

	/**
	 * Benutzer des Demo-Zugangs.
	 */
	const USER = 'user-valid';

	/**
	 * Passwort des Demo-Zugangs.
	 */
	const PASSWORD = 'SandboxPasswort2023!';

	/**
	 * Testkundennummer (EKP), auf die die Sandbox bucht.
	 */
	const EKP = '3333333333';

	/**
	 * Teilnahme des Demo-Zugangs.
	 */
	const TEILNAHME = '02';

	/**
	 * Abrechnungsprofil des Demo-Zugangs.
	 */
	const PROFIL = 'STANDARD_GRUPPENPROFIL';

	/**
	 * Auf wen gebucht wird – reine Logik.
	 *
	 * @param bool  $sandbox Läuft es gegen die Testumgebung?
	 * @param array $eigene  Eigene Angaben {ekp, teilnahme, profil}.
	 * @return array Dieselben Schlüssel, in der Sandbox überschrieben.
	 */
	public static function billing( $sandbox, array $eigene ) {
		if ( ! $sandbox ) {
			return $eigene;
		}

		return array(
			'ekp'       => self::EKP,
			'teilnahme' => self::TEILNAHME,
			'profil'    => self::PROFIL,
		);
	}

	/**
	 * Muss der Betreiber die Kundennummer selbst eintragen?
	 *
	 * In der Sandbox nicht – dort gehört sie der DHL. Danach zu fragen
	 * hiesse, nach etwas zu fragen, das es nicht zu holen gibt.
	 *
	 * @param bool $sandbox Läuft es gegen die Testumgebung?
	 * @return bool
	 */
	public static function needs_own_ekp( $sandbox ) {
		return ! $sandbox;
	}
}
