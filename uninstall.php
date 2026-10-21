<?php
/**
 * Deinstallation.
 *
 * Standardmäßig bleibt alles stehen: Belege, Rechnungen, Buchungen und die
 * Protokollkette unterliegen der Aufbewahrungspflicht (§ 147 AO, GoBD), und
 * ein versehentlich gelöschtes Plugin darf sie nicht mitnehmen. Wer wirklich
 * alles entfernen will, setzt vor dem Löschen in `wp-config.php`
 *
 *     define( 'LW16_REMOVE_ALL_DATA', true );
 *
 * Dann werden alle Tabellen `lw16_*`, die Einstellungen, die Rollen und die
 * Nutzermerker gelöscht. Hochgeladene Dateien (Belege, Modelle, Mailanhänge)
 * bleiben auch dann liegen und müssen von Hand entfernt werden.
 *
 * @package Lindenweg16
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'LW16_REMOVE_ALL_DATA' ) || true !== LW16_REMOVE_ALL_DATA ) {
	return;
}

global $wpdb;

// Hintergrundjobs.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( (array) $wpdb->get_col( "SELECT DISTINCT hook FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE 'lw16\_%'" ) as $hook ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		as_unschedule_all_actions( $hook );
	}
}

// Tabellen.
$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'lw16_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( (array) $tables as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}

// Einstellungen und Merker.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'lw16_' ) . '%', $wpdb->esc_like( 'lindenweg16_' ) . '%', $wpdb->esc_like( '_transient_lw16_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'lw16_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Rollen.
remove_role( 'lw16_manager' );
remove_role( 'lw16_betrieb' );

foreach ( array( 'administrator' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'manage_lw16' );
	}
}
