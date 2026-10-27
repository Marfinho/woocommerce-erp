<?php
/**
 * Kurzzeitige Ablage der Konfigurationen für die AR-Ansicht.
 *
 * Auf Android lädt Googles Scene Viewer die Datei selbst von einer
 * Internetadresse. Die Konfiguration, die jemand gerade zusammengestellt
 * hat, liegt aber nur im Browser. Damit die AR-Ansicht auf möglichst vielen
 * Geräten klappt – auch dort, wo der Browser kein WebXR kann –, legt der
 * Konfigurator die .glb kurz auf dem Server ab.
 *
 * Das ist eine öffentliche Schnittstelle, die Dateien annimmt, deshalb ist
 * sie eng gefasst:
 *
 * - Der Name ist der Hash des Inhalts. Dieselbe Konfiguration liegt nur
 *   einmal da, und niemand kann sich einen Namen aussuchen.
 * - Es werden nur glTF-Binärdateien mit eingebetteten Daten angenommen,
 *   höchstens {@see ArModelStore::MAX_BYTES} groß.
 * - Je Besucher und Stunde gibt es eine Obergrenze, insgesamt eine
 *   Höchstzahl von Dateien; die ältesten verschwinden zuerst.
 * - Was älter als sieben Tage ist, wird täglich gelöscht.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

/**
 * Ablage unterhalb des Upload-Ordners.
 */
class ArModelStore {

	/**
	 * Verzeichnis unterhalb des Upload-Ordners.
	 */
	const DIR = 'lw16-ar';

	/**
	 * Größte angenommene Datei. Eine Konfiguration liegt bei ein bis zwei
	 * Megabyte; was darüber hinausgeht, ist keine.
	 */
	const MAX_BYTES = 6291456;

	/**
	 * Höchstzahl abgelegter Dateien.
	 */
	const MAX_FILES = 300;

	/**
	 * Aufbewahrung in Sekunden.
	 */
	const KEEP = 604800;

	/**
	 * Ablagen je Besucher und Stunde.
	 */
	const PER_HOUR = 12;

	/**
	 * Prüfen, ob die Daten eine brauchbare glTF-Binärdatei sind.
	 *
	 * @param string $binary Rohdaten.
	 * @return string Fehlertext, leer wenn in Ordnung.
	 */
	public static function check( $binary ) {
		$binary = (string) $binary;
		$length = strlen( $binary );

		if ( $length < 40 ) {
			return __( 'Die Datei ist leer oder zu kurz.', 'lindenweg16' );
		}

		if ( $length > self::MAX_BYTES ) {
			return __( 'Die Datei ist zu groß.', 'lindenweg16' );
		}

		$head = unpack( 'a4magic/Vversion/Vlength/Vchunk/Vtype', substr( $binary, 0, 20 ) );

		if ( 'glTF' !== $head['magic'] || 2 !== (int) $head['version'] || (int) $head['length'] !== $length ) {
			return __( 'Die Datei ist kein glTF-Binärmodell.', 'lindenweg16' );
		}

		if ( 0x4E4F534A !== (int) $head['type'] || (int) $head['chunk'] <= 0 || 20 + (int) $head['chunk'] > $length ) {
			return __( 'Die Datei hat keinen lesbaren Kopf.', 'lindenweg16' );
		}

		$json = json_decode( substr( $binary, 20, (int) $head['chunk'] ), true );

		if ( ! is_array( $json ) || empty( $json['meshes'] ) || empty( $json['nodes'] ) ) {
			return __( 'Die Datei enthält kein Modell.', 'lindenweg16' );
		}

		// Alles muss in der Datei stehen. Ein Verweis auf eine Adresse im
		// Netz wäre ein Weg, über die Seite des Shops fremde Inhalte
		// auszuliefern.
		foreach ( array( 'buffers', 'images' ) as $key ) {
			foreach ( isset( $json[ $key ] ) ? (array) $json[ $key ] : array() as $entry ) {
				if ( isset( $entry['uri'] ) && 0 !== strpos( (string) $entry['uri'], 'data:' ) ) {
					return __( 'Die Datei verweist auf fremde Inhalte.', 'lindenweg16' );
				}
			}
		}

		return '';
	}

	/**
	 * Dateiname zu einem Inhalt.
	 *
	 * @param string $binary Rohdaten.
	 * @return string
	 */
	public static function file_name( $binary ) {
		return substr( hash( 'sha256', (string) $binary ), 0, 40 ) . '.glb';
	}

	/**
	 * Verzeichnis auf dem Server.
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
	 * @param string $name Dateiname.
	 * @return string
	 */
	public static function url( $name ) {
		$upload = wp_upload_dir();

		return trailingslashit( $upload['baseurl'] ) . self::DIR . '/' . rawurlencode( $name );
	}

	/**
	 * Eine Konfiguration ablegen.
	 *
	 * @param string $binary Rohdaten.
	 * @return array {url, error}
	 */
	public static function store( $binary ) {
		$error = self::check( $binary );

		if ( '' !== $error ) {
			return array(
				'url'   => '',
				'error' => $error,
			);
		}

		$dir = self::dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Kein Verzeichnislisting, auch wenn der Server es erlaubt.
			file_put_contents( trailingslashit( $dir ) . 'index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$name = self::file_name( $binary );
		$path = trailingslashit( $dir ) . $name;

		if ( ! file_exists( $path ) ) {
			self::trim( $dir );

			if ( false === file_put_contents( $path, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return array(
					'url'   => '',
					'error' => __( 'Die Datei ließ sich nicht ablegen.', 'lindenweg16' ),
				);
			}
		} else {
			// Dieselbe Konfiguration noch einmal: Die Aufbewahrungsfrist beginnt neu.
			@touch( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		return array(
			'url'   => self::url( $name ),
			'error' => '',
		);
	}

	/**
	 * Darf dieser Besucher noch ablegen? Zählt mit.
	 *
	 * @return bool
	 */
	public static function allow() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'lw16_arm_' . substr( wp_hash( $ip ), 0, 20 );
		$n   = (int) get_transient( $key );

		if ( $n >= self::PER_HOUR ) {
			return false;
		}

		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Die ältesten Dateien entfernen, wenn zu viele da sind.
	 *
	 * @param string $dir Verzeichnis.
	 */
	protected static function trim( $dir ) {
		$files = glob( trailingslashit( $dir ) . '*.glb' );

		if ( ! is_array( $files ) || count( $files ) < self::MAX_FILES ) {
			return;
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $a ) - filemtime( $b );
			}
		);

		foreach ( array_slice( $files, 0, count( $files ) - self::MAX_FILES + 20 ) as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Alte Dateien löschen (täglicher Job).
	 *
	 * @return int Zahl der gelöschten Dateien.
	 */
	public static function cleanup() {
		$files = glob( trailingslashit( self::dir() ) . '*.glb' );
		$gone  = 0;

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( filemtime( $file ) < time() - self::KEEP ) {
				wp_delete_file( $file );
				++$gone;
			}
		}

		return $gone;
	}
}
