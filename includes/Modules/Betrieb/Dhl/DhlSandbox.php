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
 * Eigene Zugangsdaten funktionieren dort nicht: Wer seine echten
 * einträgt und den Sandbox-Haken stehen lässt, bekommt eine Ablehnung,
 * die wie ein Tippfehler im Passwort aussieht. Deshalb setzt das Plugin
 * in der Sandbox von sich aus die Demo-Angaben ein.
 *
 * Nicht ersetzt werden API-Key und API-Secret: Die gehören der eigenen
 * Anwendung und gelten laut DHL üblicherweise für beide Umgebungen.
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
	 * Zugangsdaten der Umgebung – reine Logik.
	 *
	 * @param bool  $sandbox Läuft es gegen die Testumgebung?
	 * @param array $eigene  Eigene Angaben {user, password, ekp, teilnahme, profil}.
	 * @return array Dieselben Schlüssel, in der Sandbox überschrieben.
	 */
	public static function credentials( $sandbox, array $eigene ) {
		if ( ! $sandbox ) {
			return $eigene;
		}

		return array(
			'user'      => self::USER,
			'password'  => self::PASSWORD,
			'ekp'       => self::EKP,
			'teilnahme' => self::TEILNAHME,
			'profil'    => self::PROFIL,
		);
	}

	/**
	 * Welche Angaben muss der Betreiber selbst beisteuern?
	 *
	 * In der Sandbox nur die der eigenen Anwendung. Nach Benutzer,
	 * Kundennummer und Teilnahme dort zu fragen, hiesse nach etwas zu
	 * fragen, das es nicht zu holen gibt.
	 *
	 * @param bool $sandbox Läuft es gegen die Testumgebung?
	 * @return array Einstellungsschlüssel.
	 */
	public static function required_settings( $sandbox ) {
		$anwendung = array( 'dhl_versand_api_key', 'dhl_versand_api_secret' );

		if ( $sandbox ) {
			return $anwendung;
		}

		return array_merge(
			$anwendung,
			array( 'dhl_versand_user', 'dhl_versand_password', 'dhl_versand_ekp' )
		);
	}
}
