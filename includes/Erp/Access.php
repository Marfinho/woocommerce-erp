<?php
/**
 * Wer darf im ERP was – pro Person.
 *
 * Die feinen Rechte stehen als Liste am Nutzer (Nutzermerker), nicht an
 * einer WordPress-Rolle: Wer Redakteur des Shops und zugleich Aushilfe am
 * Packtisch ist, braucht dafür keine eigene Rolle. Beim Speichern werden
 * außerdem die alten Betrieb-Rechte nachgeführt, die die bestehenden
 * wp-admin-Seiten prüfen (siehe Permissions).
 *
 * Vollzugriff (manage_lw16, also auch Administratoren) hat immer alles und
 * lässt sich über diese Klasse nicht beschneiden – so kann niemand die
 * Anlage aussperren.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Rechteprüfung und Speichern.
 */
class Access {

	/**
	 * Nutzermerker mit der Liste der feinen Rechte.
	 */
	const META = 'lw16_erp_perms';

	/**
	 * Hat die Person Vollzugriff?
	 *
	 * @param int $user_id Nutzer; 0 = angemeldeter.
	 * @return bool
	 */
	public static function is_full( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		return $user_id > 0 && user_can( $user_id, LW16_CAPABILITY );
	}

	/**
	 * Die feinen Rechte einer Person.
	 *
	 * Gespeichert gilt vor abgeleitet: Wer in der neuen Oberfläche
	 * eingerichtet wurde, hat genau diese Liste. Wer noch nie angefasst
	 * wurde, bekommt, was seine alten Rechte hergeben.
	 *
	 * @param int $user_id Nutzer; 0 = angemeldeter.
	 * @return string[]
	 */
	public static function keys( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( $user_id < 1 ) {
			return array();
		}

		if ( self::is_full( $user_id ) ) {
			return Permissions::grantable_keys();
		}

		$stored = get_user_meta( $user_id, self::META, true );

		if ( is_array( $stored ) ) {
			return Permissions::normalize( $stored );
		}

		return Permissions::derive_from_caps( self::legacy_caps( $user_id ) );
	}

	/**
	 * Welche der alten Rechte (Betrieb, Module, Mailbox) die Person hat.
	 *
	 * @param int $user_id Nutzer.
	 * @return string[]
	 */
	public static function legacy_caps( $user_id ) {
		$caps = array();

		foreach ( Permissions::all_caps() as $cap ) {
			if ( user_can( (int) $user_id, $cap ) ) {
				$caps[] = $cap;
			}
		}

		return $caps;
	}

	/**
	 * Hat die Person dieses Recht?
	 *
	 * Rechte mit Geltungsbereich „full“ hat nur, wer Vollzugriff hat.
	 *
	 * @param string $key     Schlüssel.
	 * @param int    $user_id Nutzer; 0 = angemeldeter.
	 * @return bool
	 */
	public static function can( $key, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! Permissions::exists( $key ) || $user_id < 1 ) {
			return false;
		}

		if ( self::is_full( $user_id ) ) {
			return true;
		}

		return in_array( $key, self::keys( $user_id ), true );
	}

	/**
	 * Darf die Person das ERP überhaupt öffnen?
	 *
	 * @param int $user_id Nutzer; 0 = angemeldeter.
	 * @return bool
	 */
	public static function has_any( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		return $user_id > 0 && ( self::is_full( $user_id ) || array() !== self::keys( $user_id ) );
	}

	/**
	 * Feine Rechte speichern und die alten Betrieb-Rechte angleichen.
	 *
	 * Vollzugriff-Nutzer werden nicht angefasst.
	 *
	 * @param int   $user_id Nutzer.
	 * @param array $keys    Gewählte Rechte.
	 * @return string[]|false Gespeicherte Liste oder false.
	 */
	public static function save( $user_id, array $keys ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user || self::is_full( $user->ID ) ) {
			return false;
		}

		$keys = Permissions::normalize( $keys );
		$caps = Permissions::caps_for( $keys );

		foreach ( Permissions::all_caps() as $cap ) {
			if ( in_array( $cap, $caps, true ) ) {
				$user->add_cap( $cap );
			} else {
				$user->remove_cap( $cap );
			}
		}

		update_user_meta( $user->ID, self::META, $keys );

		return $keys;
	}

	/**
	 * Die Navigation der angemeldeten Person.
	 *
	 * @return array Bereich => [Einträge].
	 */
	public static function navigation() {
		return Navigation::visible(
			function ( $perm ) {
				return self::can( $perm );
			}
		);
	}

	/**
	 * Die Menüführung dieser Person: Plätze mit Reitern und Seiten.
	 *
	 * @return array Platz => [ [label, items[]], … ]
	 */
	public static function sections() {
		return Navigation::sections_visible(
			function ( $perm ) {
				return self::can( $perm );
			}
		);
	}
}
