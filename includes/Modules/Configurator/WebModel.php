<?php
/**
 * Ablage der Modelle für die Ansicht im Browser.
 *
 * Hier liegen **bewusst nicht** die Konstruktionsdaten. Die 3MF-Dateien
 * der Revisionen sind das Kapital des Betriebs und bleiben im geschützten
 * Verzeichnis (siehe ModelFile). Was der Konfigurator lädt, ist eine
 * abgespeckte Schauversion: reduziertes Netz, keine Innengeometrie, keine
 * Gewinde, keine Toleranzen. Sie muss öffentlich abrufbar sein – ein
 * Browser lädt sie direkt –, und genau deshalb darf sie nichts enthalten,
 * was ein Nachbau brauchen würde.
 *
 * Format ist glTF, binär (.glb): ein Modell in einer Datei, Material und
 * Geometrie zusammen, vom Browser ohne Umweg ladbar.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

/**
 * Speichern, Ausliefern und Löschen der Web-Modelle.
 */
class WebModel {

	/**
	 * Verzeichnisname unterhalb des Upload-Ordners.
	 */
	const DIR = 'lw16-konfigurator';

	/**
	 * Größe, ab der eine Datei kein Schaumodell mehr ist.
	 *
	 * Ein reduziertes Modul liegt bei wenigen hundert Kilobyte. Alles
	 * jenseits von acht Megabyte ist entweder unreduziert exportiert oder
	 * gar kein Schaumodell – beides gehört nicht auf eine Produktseite.
	 */
	const MAX_BYTES = 8388608;

	/**
	 * Absoluter Pfad des Verzeichnisses.
	 *
	 * @return string
	 */
	public static function dir() {
		$upload = wp_upload_dir();

		return trailingslashit( $upload['basedir'] ) . self::DIR;
	}

	/**
	 * Adresse einer abgelegten Datei.
	 *
	 * @param string $name Dateiname ohne Pfad.
	 * @return string Leer, wenn nichts hinterlegt ist.
	 */
	public static function url( $name ) {
		$name = basename( (string) $name );

		if ( '' === $name || ! is_readable( trailingslashit( self::dir() ) . $name ) ) {
			return '';
		}

		$upload = wp_upload_dir();

		// Der Zeitstempel der Datei hängt am Link, damit ein ausgetauschtes
		// Modell im Browser nicht hinter der alten Fassung verschwindet.
		$stamp = filemtime( trailingslashit( self::dir() ) . $name );

		return trailingslashit( $upload['baseurl'] ) . self::DIR . '/' . rawurlencode( $name ) . ( $stamp ? '?v=' . $stamp : '' );
	}

	/**
	 * Eine hochgeladene Datei übernehmen.
	 *
	 * @param array  $file   Eintrag aus $_FILES.
	 * @param string $prefix Namensvorschlag, üblicherweise die Artikelnummer.
	 * @return array {name, errors}
	 */
	public static function store( array $file, $prefix ) {
		if ( ! isset( $file['tmp_name'] ) || '' === $file['tmp_name'] || ! empty( $file['error'] ) ) {
			return array(
				'name'   => '',
				'errors' => array( __( 'Es kam keine Datei an.', 'lindenweg16' ) ),
			);
		}

		$original  = isset( $file['name'] ) ? (string) $file['name'] : '';
		$extension = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );

		if ( 'glb' !== $extension ) {
			return array(
				'name'   => '',
				'errors' => array( __( 'Der Konfigurator lädt ausschließlich .glb – ein glTF-Modell in einer einzigen Datei.', 'lindenweg16' ) ),
			);
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return array(
				'name'   => '',
				'errors' => array(
					sprintf(
						/* translators: %s: größte erlaubte Dateigröße. */
						__( 'Die Datei ist leer oder größer als %s. Für die Produktseite gehört das Modell reduziert exportiert.', 'lindenweg16' ),
						size_format( self::MAX_BYTES )
					),
				),
			);
		}

		// glTF-Binärdateien beginnen mit der Kennung "glTF". Ohne diese
		// Prüfung landet jede umbenannte Datei im öffentlichen Ordner.
		$handle = fopen( $file['tmp_name'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$magic  = $handle ? fread( $handle, 4 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		if ( 'glTF' !== $magic ) {
			return array(
				'name'   => '',
				'errors' => array( __( 'Die Datei ist kein glTF-Binärmodell.', 'lindenweg16' ) ),
			);
		}

		$dir = self::dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$name = sanitize_file_name( sanitize_title( $prefix ) . '-' . wp_generate_password( 6, false, false ) . '.glb' );
		$path = trailingslashit( $dir ) . $name;

		if ( ! @move_uploaded_file( $file['tmp_name'], $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return array(
				'name'   => '',
				'errors' => array( __( 'Die Datei ließ sich nicht ablegen. Rechte des Upload-Verzeichnisses prüfen.', 'lindenweg16' ) ),
			);
		}

		return array(
			'name'   => $name,
			'errors' => array(),
		);
	}

	/**
	 * Eine abgelegte Datei entfernen.
	 *
	 * @param string $name Dateiname ohne Pfad.
	 */
	public static function delete( $name ) {
		$name = basename( (string) $name );

		if ( '' === $name ) {
			return;
		}

		$path = trailingslashit( self::dir() ) . $name;

		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
