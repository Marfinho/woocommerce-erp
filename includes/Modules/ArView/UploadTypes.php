<?php
/**
 * Upload-Typen für 3D-Modelle: GLB und USDZ, nur für Administratoren.
 *
 * WordPress kennt beide Endungen nicht. Zwei Dinge sind hier bewusst
 * streng:
 *
 * - **Nur wer die Website verwalten darf** (`manage_options`), kann sie
 *   hochladen. Beide Formate sind Container mit beliebigem Inhalt; sie
 *   gehören nicht in die Hände jeder Rolle, die Bilder hochladen darf.
 * - **Der Inhalt muss zur Endung passen.** Eine GLB beginnt mit der Kennung
 *   `glTF`, eine USDZ ist ein ZIP-Archiv (`PK`). Eine umbenannte Datei
 *   wird abgewiesen, statt in der Mediathek zu landen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\ArView;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert die Typen und prüft Hochgeladenes.
 */
class UploadTypes {

	const MIME_GLB  = 'model/gltf-binary';
	const MIME_USDZ = 'model/vnd.usdz+zip';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'upload_mimes', array( $this, 'add_mimes' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'check_filetype' ), 10, 5 );
	}

	/**
	 * Endung => MIME-Typ der erlaubten Modelldateien.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'glb'  => self::MIME_GLB,
			'usdz' => self::MIME_USDZ,
		);
	}

	/**
	 * Darf der aktuelle Benutzer Modelldateien hochladen?
	 *
	 * @return bool
	 */
	protected function allowed() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Typen für Administratoren freischalten.
	 *
	 * @param array $mimes Erlaubte Typen.
	 * @return array
	 */
	public function add_mimes( $mimes ) {
		if ( ! $this->allowed() ) {
			return $mimes;
		}

		return array_merge( (array) $mimes, self::types() );
	}

	/**
	 * Endung aus einem Dateinamen, kleingeschrieben.
	 *
	 * @param string $filename Dateiname.
	 * @return string
	 */
	public static function extension( $filename ) {
		return strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
	}

	/**
	 * Passt der Anfang der Datei zur Endung?
	 *
	 * @param string $ext  Endung (glb oder usdz).
	 * @param string $file Pfad der Datei.
	 * @return bool
	 */
	public static function content_matches( $ext, $file ) {
		if ( ! is_readable( $file ) || ! is_file( $file ) ) {
			return false;
		}

		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return false;
		}

		$head = fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( 'glb' === $ext ) {
			return 'glTF' === $head;
		}

		if ( 'usdz' === $ext ) {
			return "PK\x03\x04" === $head;
		}

		return false;
	}

	/**
	 * Dateityp und Inhalt prüfen.
	 *
	 * WordPress erkennt den Inhalt per finfo selbst und verwirft Dateien,
	 * deren erkannter Typ nicht zur Endung passt – bei einer USDZ ist das
	 * `application/zip`. Hier wird das Urteil für die beiden Endungen
	 * ersetzt: erlaubt, wenn der Inhalt zur Endung passt, sonst verweigert.
	 *
	 * @param array  $data     Bisheriges Ergebnis (ext, type, proper_filename).
	 * @param string $file     Pfad der Datei.
	 * @param string $filename Dateiname.
	 * @return array
	 */
	public function check_filetype( $data, $file, $filename ) {
		$ext = self::extension( $filename );

		if ( ! array_key_exists( $ext, self::types() ) ) {
			return $data;
		}

		if ( ! $this->allowed() || ! self::content_matches( $ext, (string) $file ) ) {
			return array(
				'ext'             => false,
				'type'            => false,
				'proper_filename' => false,
			);
		}

		return array(
			'ext'             => $ext,
			'type'            => self::types()[ $ext ],
			'proper_filename' => false,
		);
	}
}
