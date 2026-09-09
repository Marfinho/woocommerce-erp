<?php
/**
 * Slicing-Profile (Drucker-/Filament-/Prozess-JSON aus OrcaSlicer) je
 * Material × Druckermodell.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;

/**
 * Verwaltet die drei Profildateien, die der Slicing-Worker für einen
 * automatischen Slice-Lauf braucht. Ohne ein passendes Profil kann eine
 * Platte nicht automatisch geslict werden (siehe SlicingService).
 */
class SlicingProfileRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_slicing_profiles';
	}

	/**
	 * Unterverzeichnis der Profildateien innerhalb des geschützten
	 * Dokumentenverzeichnisses.
	 *
	 * @return string
	 */
	public static function dir() {
		$dir = trailingslashit( Installer::docs_dir() ) . 'slicing-profiles';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * Alle Profile.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY material ASC, printer_model ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Einzelnes Profil.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Passendes Profil für Material + Druckermodell.
	 *
	 * @param string $material      z. B. PETG.
	 * @param string $printer_model z. B. "Bambu Lab P1S" oder "P1S".
	 * @return object|null
	 */
	public static function find_for( $material, $printer_model ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE material = %s AND printer_model = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				strtoupper( (string) $material ),
				(string) $printer_model
			)
		);
	}

	/**
	 * Eine Profildatei (printer/filament/process) unter geschütztem,
	 * eindeutigem Namen ablegen.
	 *
	 * @param array  $file Ein Element aus $_FILES.
	 * @param string $kind printer|filament|process, nur für den Dateinamen.
	 * @return string Abgelegter Dateiname (ohne Pfad), oder leer bei Fehler.
	 */
	public static function store_file( array $file, $kind ) {
		if ( empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return '';
		}

		if ( 'json' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
			return '';
		}

		// Ein gültiges OrcaSlicer-/Bambu-Studio-Profil ist JSON - ein
		// Syntaxfehler hier würde erst beim Slice-Lauf auf dem Worker
		// auffallen, Stunden oder Tage später. Besser sofort ablehnen.
		$content = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		if ( false === $content || null === json_decode( (string) $content ) ) {
			return '';
		}

		$filename = sprintf( '%s-%d-%s.json', sanitize_key( $kind ), time(), wp_generate_password( 6, false, false ) );
		$target   = trailingslashit( self::dir() ) . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
			return '';
		}

		return $filename;
	}

	/**
	 * Geprüfter absoluter Pfad einer abgelegten Profildatei.
	 *
	 * @param string $filename Dateiname.
	 * @return string Absoluter Pfad oder leer.
	 */
	public static function safe_path( $filename ) {
		if ( '' === (string) $filename ) {
			return '';
		}

		$base = realpath( self::dir() );
		$real = realpath( trailingslashit( self::dir() ) . basename( (string) $filename ) );

		if ( ! $base || ! $real || 0 !== strpos( $real, $base ) || ! is_file( $real ) ) {
			return '';
		}

		return $real;
	}

	/**
	 * Profil anlegen oder (bei gleichem Material+Modell) ersetzen.
	 *
	 * @param array $data {material, printer_model, printer_profile_path,
	 *                      filament_profile_path, process_profile_path}
	 *                      – die drei *_path-Felder sind bereits abgelegte
	 *                      Dateinamen (siehe store_file()).
	 * @return int Neue oder bestehende ID, 0 bei Fehler.
	 */
	public static function upsert( array $data ) {
		global $wpdb;

		$material      = strtoupper( sanitize_text_field( (string) $data['material'] ) );
		$printer_model = sanitize_text_field( (string) $data['printer_model'] );

		if ( '' === $material || '' === $printer_model ) {
			return 0;
		}
		if ( empty( $data['printer_profile_path'] ) || empty( $data['filament_profile_path'] ) || empty( $data['process_profile_path'] ) ) {
			return 0;
		}

		$existing = self::find_for( $material, $printer_model );
		$row      = array(
			'material'               => $material,
			'printer_model'          => $printer_model,
			'printer_profile_path'   => (string) $data['printer_profile_path'],
			'filament_profile_path'  => (string) $data['filament_profile_path'],
			'process_profile_path'   => (string) $data['process_profile_path'],
		);

		if ( $existing ) {
			$row['updated_at'] = current_time( 'mysql', true );
			$wpdb->update( self::table(), $row, array( 'id' => (int) $existing->id ) );
			return (int) $existing->id;
		}

		$row['created_at'] = current_time( 'mysql', true );
		$wpdb->insert( self::table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Profil löschen.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}
}
