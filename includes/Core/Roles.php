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
		'lw16_capture',
		'lw16_manage_production',
		'lw16_view_reports',
		'lw16_view_costs',
		'lw16_manage_settings',
		'lw16_manage_users',
	);

	/**
	 * Rechte der übrigen Module (Buchhaltung, Kasse, Support …).
	 *
	 * Früher prüfte jedes dieser Module das Sammelrecht manage_lw16 – wer
	 * Buchhaltung sehen sollte, bekam damit alles. Jetzt hat jedes Modul ein
	 * eigenes Recht, das sich pro Person in der ERP-Oberfläche vergeben
	 * lässt. Wer manage_lw16 hat, bekommt sie alle automatisch (siehe
	 * grant_module_caps), es muss also nichts migriert werden.
	 */
	const MODULE_CAPS = array(
		'lw16_mod_finanzen',
		'lw16_mod_reporting',
		'lw16_mod_loyalty',
		'lw16_mod_kasse',
		'lw16_mod_marketing',
		'lw16_mod_produktion',
		'lw16_mod_support',
		'lw16_mod_anleitungen',
		'lw16_mod_arview',
	);

	/**
	 * Rolle für das Modul Betrieb (voller Zugriff, keine WordPress-Adminrechte).
	 */
	const BETRIEB_ROLE = 'lw16_betrieb';

	/**
	 * Nutzermerker für das zuletzt gesetzte Profil.
	 */
	const PROFILE_META = 'lw16_betrieb_profil';

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
	 * Vollzugriff schließt alle Modulrechte ein.
	 *
	 * Filter für user_has_cap. Liegt zur Laufzeit an, nicht in der
	 * Datenbank: Neue Modulrechte brauchen keine Migration, und ein
	 * Administrator kann sich nicht durch fehlende Einträge aussperren.
	 *
	 * @param array $allcaps Alle Rechte des Nutzers.
	 * @return array
	 */
	public static function grant_module_caps( $allcaps ) {
		if ( ! empty( $allcaps[ LW16_CAPABILITY ] ) ) {
			foreach ( self::MODULE_CAPS as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
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

	/**
	 * Rechteprofil eines Nutzers setzen.
	 *
	 * Die Rechte hängen am Nutzer, nicht an einer WordPress-Rolle: So kann
	 * jemand Redakteur des Shops und zugleich Aushilfe in der Werkstatt
	 * sein, ohne dass eine Rolle die andere überschreibt.
	 *
	 * Administratoren behalten über manage_lw16 ohnehin vollen Zugriff –
	 * dieser Weg kann also niemanden aussperren, der die Anlage betreut.
	 *
	 * @param int    $user_id Nutzer.
	 * @param string $profile Profilkennung aus AccessProfiles.
	 * @return bool
	 */
	public static function set_betrieb_profile( $user_id, $profile ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user || ! \Lindenweg16\Modules\Betrieb\AccessProfiles::exists( $profile ) ) {
			return false;
		}

		$wanted = \Lindenweg16\Modules\Betrieb\AccessProfiles::caps( $profile );

		foreach ( self::BETRIEB_CAPS as $cap ) {
			if ( in_array( $cap, $wanted, true ) ) {
				$user->add_cap( $cap );
			} else {
				$user->remove_cap( $cap );
			}
		}

		update_user_meta( (int) $user_id, self::PROFILE_META, (string) $profile );

		return true;
	}

	/**
	 * Die Betrieb-Rechte eines Nutzers.
	 *
	 * @param int $user_id Nutzer.
	 * @return array
	 */
	public static function betrieb_caps( $user_id ) {
		$caps = array();

		foreach ( self::BETRIEB_CAPS as $cap ) {
			if ( user_can( (int) $user_id, $cap ) ) {
				$caps[] = $cap;
			}
		}

		return $caps;
	}

	/**
	 * Das Profil eines Nutzers, aus seinen tatsächlichen Rechten
	 * hergeleitet. Der gespeicherte Merker zählt nur, wenn er noch zu den
	 * Rechten passt – sonst hätte jemand über die WordPress-Rollen etwas
	 * geändert und die Anzeige würde lügen.
	 *
	 * @param int $user_id Nutzer.
	 * @return string Profilkennung oder leerer String.
	 */
	public static function betrieb_profile( $user_id ) {
		// Wer die Sammel-Capability hat, hat faktisch Vollzugriff.
		if ( user_can( (int) $user_id, LW16_CAPABILITY ) ) {
			return 'admin';
		}

		return \Lindenweg16\Modules\Betrieb\AccessProfiles::match( self::betrieb_caps( $user_id ) );
	}

	/**
	 * Alle Nutzer, die im Modul Betrieb etwas dürfen.
	 *
	 * @return \WP_User[]
	 */
	public static function betrieb_users() {
		$out = array();

		foreach ( get_users() as $user ) {
			if ( user_can( $user->ID, LW16_CAPABILITY ) || self::betrieb_caps( $user->ID ) ) {
				$out[] = $user;
			}
		}

		return $out;
	}
}
