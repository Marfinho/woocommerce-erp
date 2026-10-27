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
	 * Kennzeichen einer mitgelieferten Vorlage.
	 *
	 * In `model_file` steht dann nicht der Name einer hochgeladenen Datei,
	 * sondern `demo:haken`. Das braucht keine zweite Spalte und macht
	 * beim Lesen sofort klar, woher das Modell kommt.
	 */
	const DEMO_PREFIX = 'demo:';

	/**
	 * Kennung des Standardmodells für Basismodule.
	 *
	 * Die Datei ist der Halter der Holzstange. Die Stange selbst und die
	 * zweite Ausfertigung setzt die Ansicht zusammen – so passt dasselbe
	 * Modell zu jeder Länge. Ein Basismodul ohne eigenes Modell zeigt es.
	 */
	const STANDARD_KEY = 'holzstange';

	/**
	 * Die mitgelieferten Modelle.
	 *
	 * Das Standardmodell der Holzstange steht zuerst. Die Demo-Modelle
	 * zeigen, dass Maßstab, Anordnung und Sperrbereiche zusammen-
	 * passen – nicht den Haster Haken. Wer sie stehen lässt, verkauft ein
	 * Bild, das nicht zum Teil gehört; sie sind zum Einrichten da, nicht
	 * zum Betrieb.
	 *
	 * @return array Kennung => Beschriftung.
	 */
	public static function demo_models() {
		return array(
			self::STANDARD_KEY => __( 'Standard: Holzstange mit zwei Haltern', 'lindenweg16' ),
			'schiene' => __( 'Demo: Schiene mit zwei Schraubaugen (600 mm)', 'lindenweg16' ),
			'haken'   => __( 'Demo: Haken (80 mm)', 'lindenweg16' ),
			'ablage'  => __( 'Demo: Ablage (150 mm)', 'lindenweg16' ),
		);
	}

	/**
	 * Zeigt ein Verweis auf ein mitgeliefertes Modell?
	 *
	 * @param string $name Wert aus model_file.
	 * @return string Kennung des Demo-Modells oder leer.
	 */
	public static function demo_key( $name ) {
		$name = (string) $name;

		if ( 0 !== strpos( $name, self::DEMO_PREFIX ) ) {
			return '';
		}

		$key = substr( $name, strlen( self::DEMO_PREFIX ) );

		return array_key_exists( $key, self::demo_models() ) ? $key : '';
	}

	/**
	 * Pfad einer Modelldatei auf dem Server.
	 *
	 * @param string $name Dateiname ohne Pfad oder `demo:`-Verweis.
	 * @return string Leer, wenn es die Datei nicht gibt.
	 */
	public static function file_path( $name ) {
		$demo = self::demo_key( $name );

		if ( '' !== $demo ) {
			$path = dirname( __DIR__, 3 ) . '/assets/models/demo/' . $demo . '.glb';

			return is_readable( $path ) ? $path : '';
		}

		$name = basename( (string) $name );
		$path = trailingslashit( self::dir() ) . $name;

		return '' !== $name && is_readable( $path ) ? $path : '';
	}

	/**
	 * Die Maße eines abgelegten Modells.
	 *
	 * @param string     $name     Dateiname oder `demo:`-Verweis.
	 * @param array|null $rotation Drehung [x, y, z] in Grad vor dem Messen.
	 * @return array|null {width, height, depth} in Millimetern.
	 */
	public static function measure( $name, $rotation = null ) {
		// Das Standardmodell ist nur der Halter; die Maße der Stange gibt
		// ihre Länge vor, nicht die Datei.
		if ( self::demo_key( $name ) === self::STANDARD_KEY ) {
			return null;
		}

		$path = self::file_path( $name );

		return '' === $path ? null : GlbMeasure::measure_file( $path, $rotation );
	}

	/**
	 * Adresse einer abgelegten Datei.
	 *
	 * @param string $name Dateiname ohne Pfad oder `demo:`-Verweis.
	 * @return string Leer, wenn nichts hinterlegt ist.
	 */
	public static function url( $name ) {
		$demo = self::demo_key( $name );

		if ( '' !== $demo ) {
			return \Lindenweg16\Core\Assets::url( 'assets/models/demo/' . $demo . '.glb' );
		}

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
		// Ein Demo-Modell gehört zum Plugin und wird nie gelöscht – sonst
		// nimmt das Entfernen eines Teils die Vorlage für alle mit.
		if ( '' !== self::demo_key( $name ) ) {
			return;
		}

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
