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
	 * Capabilities des Moduls Betrieb.
	 *
	 * Ergänzen die bestehende Sammel-Capability manage_lw16, ersetzen sie
	 * aber nicht: Wer manage_lw16 hat, behält den Zugriff auf alles Bisherige.
	 */
	const BETRIEB_CAPS = array(
		'lw16_manage_production',
		'lw16_view_reports',
		'lw16_manage_settings',
	);

	/**
	 * Rolle für das Modul Betrieb (voller Zugriff, keine WordPress-Adminrechte).
	 */
	const BETRIEB_ROLE = 'lw16_betrieb';

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

		self::add_betrieb_role();
	}

	/**
	 * Rolle lw16_betrieb anlegen und die Betrieb-Capabilities verteilen.
	 *
	 * Empfänger: der neue Rollenname, die bestehende Rolle lw16_manager
	 * (damit vorhandene Nutzer nicht plötzlich ausgesperrt sind) und der
	 * Administrator. Idempotent – läuft bei jedem Schema-Upgrade mit.
	 */
	public static function add_betrieb_role() {
		add_role(
			self::BETRIEB_ROLE,
			__( 'Lindenweg16 Betrieb', 'lindenweg16' ),
			array( 'read' => true )
		);

		foreach ( array( self::BETRIEB_ROLE, LW16_ROLE, 'administrator' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::BETRIEB_CAPS as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
			// Die Betrieb-Rolle braucht zusätzlich Zugriff auf die
			// gemeinsamen Plugin-Seiten (Menü, Medien für Skizzen).
			if ( self::BETRIEB_ROLE === $role_name && ! $role->has_cap( 'upload_files' ) ) {
				$role->add_cap( 'upload_files' );
			}
		}
	}

	/**
	 * Prüft eine Betrieb-Capability. Wer manage_lw16 besitzt, kommt ebenfalls
	 * durch – sonst würde ein bestehender Manager beim Upgrade ausgesperrt.
	 *
	 * @param string $cap Capability aus BETRIEB_CAPS.
	 * @return bool
	 */
	public static function current_user_can_betrieb( $cap ) {
		return current_user_can( $cap ) || current_user_can( LW16_CAPABILITY );
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
