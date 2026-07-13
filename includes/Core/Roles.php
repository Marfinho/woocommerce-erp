<?php
/**
 * Rolle und Capability lw16_manager.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Verwaltet die eigene Capability manage_lw16 und die Rolle lw16_manager.
 */
class Roles {

	/**
	 * Rolle anlegen und Capability an Administratoren + Rolle vergeben.
	 */
	public static function add_role() {
		add_role(
			LW16_ROLE,
			__( 'Lindenweg16 Manager', 'lindenweg16' ),
			array(
				'read'          => true,
				LW16_CAPABILITY => true,
			)
		);

		// Bestehende Rolle nachträglich mit Capability versorgen (bei Upgrade).
		$role = get_role( LW16_ROLE );
		if ( $role && ! $role->has_cap( LW16_CAPABILITY ) ) {
			$role->add_cap( LW16_CAPABILITY );
		}

		// Administratoren behalten immer vollen Zugriff.
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( LW16_CAPABILITY ) ) {
			$admin->add_cap( LW16_CAPABILITY );
		}
	}

	/**
	 * Prüft, ob der aktuelle Nutzer Zugriff auf das Plugin hat.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( LW16_CAPABILITY );
	}

	/**
	 * Alle Nutzer-IDs mit manage_lw16-Capability (für Benachrichtigungen).
	 *
	 * @return int[]
	 */
	public static function get_manager_user_ids() {
		$ids   = array();
		$users = get_users( array( 'fields' => array( 'ID' ) ) );
		foreach ( $users as $user ) {
			if ( user_can( $user->ID, LW16_CAPABILITY ) ) {
				$ids[] = (int) $user->ID;
			}
		}
		return $ids;
	}

	/**
	 * E-Mail-Adressen aller Manager (für Digest/Benachrichtigungen).
	 *
	 * @return string[]
	 */
	public static function get_manager_emails() {
		$emails = array();
		foreach ( self::get_manager_user_ids() as $id ) {
			$user = get_userdata( $id );
			if ( $user && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}
		return array_unique( $emails );
	}
}
