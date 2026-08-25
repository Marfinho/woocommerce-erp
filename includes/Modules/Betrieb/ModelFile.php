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
 * Speichern und Ausliefern von 3MF-Dateien.
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
	 * Was für eine Datei ist das?
	 *
	 * Bambu Studio kennt zwei sehr verschiedene 3MF: die Projektdatei mit
	 * der Geometrie und die geslicte Druckdatei (.gcode.3mf), die man an
	 * den Drucker schickt. Letztere enthält fertigen G-Code, aber **kein
	 * Modell mehr** – ihr 3dmodel.model ist leer. Beide sind nützlich, aber
	 * für Verschiedenes, und beide heissen .3mf.
	 *
	 * @param string $path Absoluter Pfad.
	 * @return string modell, druckdatei oder leerer String.
	 */
	public static function kind( $path ) {
		if ( ! class_exists( '\ZipArchive' ) || ! is_file( $path ) ) {
			return '';
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return '';
		}

		$gcode = false;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( 0 === strpos( $name, 'Metadata/' ) && '.gcode' === substr( $name, -6 ) ) {
				$gcode = true;
				break;
			}
		}

		$xml = $zip->getFromName( ThreeMf::MODEL_PATH );
		$zip->close();

		$doc   = is_string( $xml ) ? ThreeMf::document( $xml ) : null;
		$items = $doc ? ThreeMf::items( $doc ) : array();

		if ( $items ) {
			return 'modell';
		}

		return $gcode ? 'druckdatei' : '';
	}

	/**
	 * Beschriftung einer Dateiart.
	 *
	 * @param string $kind Art.
	 * @return string
	 */
	public static function kind_label( $kind ) {
		$labels = array(
			'modell'     => __( 'Modell', 'lindenweg16' ),
			'druckdatei' => __( 'Druckdatei (geslict)', 'lindenweg16' ),
		);

		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : __( 'unbekannt', 'lindenweg16' );
	}

	/**
	 * Die Zahlen des Slicers aus einer Druckdatei.
	 *
	 * @param string $path Absoluter Pfad.
	 * @return array|null
	 */
	public static function slice_info( $path ) {
		if ( ! class_exists( '\ZipArchive' ) || ! is_file( $path ) ) {
			return null;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return null;
		}

		$xml = $zip->getFromName( ThreeMf::SLICE_INFO );
		$zip->close();

		return is_string( $xml ) ? ThreeMf::slice_info( $xml ) : null;
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
				'kind'    => '',
				'message' => __( 'Das ist keine 3MF-Datei – 3D/3dmodel.model fehlt darin.', 'lindenweg16' ),
			);
		}

		if ( ! ThreeMf::document( $xml ) ) {
			return array(
				'ok'      => false,
				'kind'    => '',
				'message' => __( 'Das Modell in der Datei lässt sich nicht lesen.', 'lindenweg16' ),
			);
		}

		$kind = self::kind( $path );

		if ( '' === $kind ) {
			return array(
				'ok'      => false,
				'kind'    => '',
				'message' => __( 'In dieser Datei ist kein Bauteil und kein G-Code – sie ist leer.', 'lindenweg16' ),
			);
		}

		return array( 'ok' => true, 'kind' => $kind, 'message' => '' );
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
			'kind'    => '',
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
			'kind'    => $check['kind'],
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
}
