<?php
/**
 * Wer darf welches Postfach sehen und benutzen?
 *
 * Die Regeln stehen als reine Funktion hier, ohne Datenbank und WordPress,
 * damit sie prüfbar sind – ein Fehler an dieser Stelle hieße, dass jemand
 * fremde Mails liest.
 *
 * Stufen (aufsteigend):
 *  - none:  kein Zugriff
 *  - read:  lesen, markieren, notieren, zuweisen
 *  - send:  zusätzlich schreiben, antworten, löschen
 *  - admin: zusätzlich Postfach einrichten (Verwalter sehen alle Postfächer)
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * Zugriffsstufen.
 */
class MailboxAccess {

	/**
	 * Recht, das Mailbox-Menü zu sehen. Wird automatisch vergeben, sobald
	 * jemand Besitzer oder Mitnutzer eines Postfachs ist.
	 */
	const CAP_USE = 'lw16_mail_use';

	/**
	 * Recht, Postfächer einzurichten und alle zu sehen.
	 */
	const CAP_MANAGE = 'lw16_mail_manage';

	/**
	 * Stufen in aufsteigender Reihenfolge.
	 *
	 * @var string[]
	 */
	const LEVELS = array( 'none', 'read', 'send', 'admin' );

	/**
	 * Zugriffsstufe eines Nutzers auf ein Postfach.
	 *
	 * @param array $account    Postfach mit owner_user_id.
	 * @param array $shares     Mitnutzer: user_id => can_send (bool).
	 * @param int   $user_id    Nutzer.
	 * @param bool  $is_manager Ob der Nutzer Postfächer verwalten darf.
	 * @return string
	 */
	public static function level( array $account, array $shares, $user_id, $is_manager ) {
		$user_id = (int) $user_id;

		if ( $is_manager ) {
			return 'admin';
		}

		if ( $user_id <= 0 ) {
			return 'none';
		}

		if ( isset( $account['owner_user_id'] ) && (int) $account['owner_user_id'] === $user_id ) {
			return 'send';
		}

		if ( isset( $shares[ $user_id ] ) ) {
			return $shares[ $user_id ] ? 'send' : 'read';
		}

		return 'none';
	}

	/**
	 * Reicht die Stufe?
	 *
	 * @param string $level  Vorhandene Stufe.
	 * @param string $needed Benötigte Stufe.
	 * @return bool
	 */
	public static function allows( $level, $needed ) {
		$have = array_search( $level, self::LEVELS, true );
		$need = array_search( $needed, self::LEVELS, true );

		if ( false === $have || false === $need ) {
			return false;
		}

		return $have >= $need && $need > 0;
	}

	/**
	 * Darf der aktuelle Nutzer Postfächer verwalten?
	 *
	 * @return bool
	 */
	public static function is_manager() {
		return current_user_can( LW16_CAPABILITY ) || current_user_can( self::CAP_MANAGE );
	}

	/**
	 * Darf der aktuelle Nutzer die Mailbox überhaupt öffnen?
	 *
	 * @return bool
	 */
	public static function can_open() {
		return current_user_can( self::CAP_USE ) || self::is_manager();
	}

	/**
	 * Das Menü-Recht je nach Nutzer: Verwalter haben es ohnehin.
	 *
	 * @return string
	 */
	public static function menu_cap() {
		return self::is_manager() ? ( current_user_can( LW16_CAPABILITY ) ? LW16_CAPABILITY : self::CAP_MANAGE ) : self::CAP_USE;
	}
}
