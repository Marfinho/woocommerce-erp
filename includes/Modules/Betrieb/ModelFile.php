<?php
/**
 * Ablage der 3MF-Dateien einer Artikelrevision.
 *
 * Die Datei gehört zur Revision, nicht zum Artikel: eine freigegebene
 * Revision ist genau eine Geometrie mit genau einem Druckprofil. Wer die
 * aktive Revision herunterlädt, hat damit immer den Stand, nach dem auch
 * kalkuliert und gedruckt wird.
 *
 * Die Dateien liegen im geschützten Upload-Verzeichnis und werden nur über
 * einen angemeldeten Download ausgeliefert – Konstruktionsdaten sind das
 * Kapital des Betriebs und gehören nicht in ein öffentliches Verzeichnis.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;

/**
 * Speichern, Ausliefern und Vervielfältigen von 3MF-Dateien.
 */
class ModelFile {

	/**
	 * Grösse, ab der eine Datei kein Bauteil mehr ist.
	 */
	const MAX_BYTES = 67108864;

	/**
	 * Absoluter Pfad zu einer abgelegten Datei.
	 *
	 * @param string $name Dateiname ohne Pfad.
	 * @return string
	 */
	public static function path( $name ) {
		return trailingslashit( Installer::models_dir() ) . basename( (string) $name );
	}

	/**
	 * Liegt die Datei wirklich im Modellverzeichnis und ist lesbar?
	 *
	 * @param string $name Dateiname.
	 * @return string Geprüfter absoluter Pfad oder leerer String.
	 */
	public static function safe_path( $name ) {
		if ( '' === (string) $name ) {
			return '';
		}

		$base = realpath( Installer::models_dir() );
		$real = realpath( self::path( $name ) );

		if ( ! $base || ! $real || 0 !== strpos( $real, $base ) || ! is_file( $real ) ) {
			return '';
		}

		return $real;
	}

	/**
	 * Modell-XML aus einem 3MF holen.
	 *
	 * @param string $path Absoluter Pfad zur 3MF-Datei.
	 * @return string Leerer String, wenn die Datei nicht taugt.
	 */
	public static function read_model( $path ) {
		if ( ! class_exists( '\ZipArchive' ) || ! is_file( $path ) ) {
			return '';
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return '';
		}

		$xml = $zip->getFromName( ThreeMf::MODEL_PATH );
		$zip->close();

		return is_string( $xml ) ? $xml : '';
	}

	/**
	 * Ist das eine Projektdatei aus Bambu Studio oder Orca Slicer?
	 *
	 * Solche Dateien führen ihre Plattenbelegung in einer eigenen Beilage.
	 * Das Modell selbst bleibt gültig, die zusätzlichen Positionen sieht der
	 * Slicer aber erst nach dem Anordnen – darauf wird hingewiesen.
	 *
	 * @param string $path Absoluter Pfad.
	 * @return bool
	 */
	public static function is_project( $path ) {
		if ( ! class_exists( '\ZipArchive' ) || ! is_file( $path ) ) {
			return false;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return false;
		}

		$found = false !== $zip->locateName( ThreeMf::PROJECT_MARKER );
		$zip->close();

		return $found;
	}

	/**
	 * Prüfen, ob eine Datei ein brauchbares 3MF ist.
	 *
	 * @param string $path Absoluter Pfad.
	 * @return array {ok, message}
	 */
	public static function inspect( $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Auf diesem Server fehlt die ZIP-Unterstützung von PHP. Ohne sie lassen sich 3MF-Dateien nicht lesen.', 'lindenweg16' ),
			);
		}

		$xml = self::read_model( $path );
		if ( '' === $xml ) {
			return array(
				'ok'      => false,
				'message' => __( 'Das ist keine 3MF-Datei – 3D/3dmodel.model fehlt darin.', 'lindenweg16' ),
			);
		}

		if ( ! ThreeMf::document( $xml ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Das Modell in der Datei lässt sich nicht lesen.', 'lindenweg16' ),
			);
		}

		return array( 'ok' => true, 'message' => '' );
	}

	/**
	 * Hochgeladene Datei ablegen.
	 *
	 * @param array $file Eintrag aus $_FILES.
	 * @return array {success, name, size, sha1, message}
	 */
	public static function store( $file ) {
		$fail = array(
			'success' => false,
			'name'    => '',
			'size'    => 0,
			'sha1'    => '',
			'message' => '',
		);

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$fail['message'] = __( 'Es wurde keine Datei hochgeladen.', 'lindenweg16' );
			return $fail;
		}

		if ( (int) $file['size'] > self::MAX_BYTES ) {
			$fail['message'] = __( 'Die Datei ist zu gross.', 'lindenweg16' );
			return $fail;
		}

		$ext = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( '3mf' !== $ext ) {
			$fail['message'] = __( 'Bitte eine .3mf-Datei wählen.', 'lindenweg16' );
			return $fail;
		}

		$check = self::inspect( $file['tmp_name'] );
		if ( ! $check['ok'] ) {
			$fail['message'] = $check['message'];
			return $fail;
		}

		$dir = Installer::models_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$name   = 'modell-' . gmdate( 'Ymd' ) . '-' . wp_generate_password( 12, false ) . '.3mf';
		$target = trailingslashit( $dir ) . $name;

		if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore
			$fail['message'] = __( 'Die Datei liess sich nicht ablegen.', 'lindenweg16' );
			return $fail;
		}

		return array(
			'success' => true,
			'name'    => $name,
			'size'    => (int) filesize( $target ),
			'sha1'    => (string) sha1_file( $target ),
			'message' => '',
		);
	}

	/**
	 * Abgelegte Datei entfernen.
	 *
	 * @param string $name Dateiname.
	 * @return bool
	 */
	public static function delete( $name ) {
		$path = self::safe_path( $name );
		if ( '' === $path ) {
			return false;
		}
		return @unlink( $path ); // phpcs:ignore
	}

	/**
	 * Kopie der Datei anlegen, in der das Bauteil mehrfach auf der Platte
	 * liegt. Das Original bleibt unangetastet.
	 *
	 * @param string $source  Absoluter Pfad zur Vorlage.
	 * @param string $target  Absoluter Pfad zur neuen Datei.
	 * @param int    $count   Stückzahl.
	 * @param array  $options gap, plate_w, plate_d.
	 * @return array {success, placed, cols, rows, size, message}
	 */
	public static function fill_plate( $source, $target, $count, array $options = array() ) {
		$fail = array(
			'success' => false,
			'placed'  => 0,
			'cols'    => 0,
			'rows'    => 0,
			'size'    => array( 0.0, 0.0, 0.0 ),
			'message' => '',
		);

		$xml = self::read_model( $source );
		if ( '' === $xml ) {
			$fail['message'] = __( 'Die hinterlegte Datei lässt sich nicht lesen.', 'lindenweg16' );
			return $fail;
		}

		$result = ThreeMf::fill_plate( $xml, $count, $options );
		if ( ! $result['success'] ) {
			$fail['message'] = $result['message'];
			$fail['size']    = $result['size'];
			return $fail;
		}

		if ( ! @copy( $source, $target ) ) { // phpcs:ignore
			$fail['message'] = __( 'Die Kopie liess sich nicht anlegen.', 'lindenweg16' );
			return $fail;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $target ) ) {
			@unlink( $target ); // phpcs:ignore
			$fail['message'] = __( 'Die Kopie liess sich nicht schreiben.', 'lindenweg16' );
			return $fail;
		}

		$written = $zip->addFromString( ThreeMf::MODEL_PATH, $result['xml'] );
		$zip->close();

		if ( ! $written ) {
			@unlink( $target ); // phpcs:ignore
			$fail['message'] = __( 'Das Modell liess sich nicht in die Kopie schreiben.', 'lindenweg16' );
			return $fail;
		}

		return array(
			'success' => true,
			'placed'  => $result['placed'],
			'cols'    => $result['cols'],
			'rows'    => $result['rows'],
			'size'    => $result['size'],
			'message' => '',
		);
	}
}
