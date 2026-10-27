<?php
/**
 * Sicherung der Plugin-Dateien vor einem Update - und der Weg zurück.
 *
 * Die Updates kommen direkt aus GitHub. Ein fehlerhaftes Update ließe sich
 * bisher nur beheben, indem jemand die alte Fassung von Hand wieder
 * einspielt. Vor jedem Update legt dieses Werkzeug deshalb ein ZIP der
 * installierten Fassung ab, die letzten zwei bleiben liegen, und im ERP gibt
 * es einen Knopf „Auf diese Fassung zurück".
 *
 * Gesichert werden nur die Dateien des Plugins. Die Daten (Datenbank) bleiben
 * unberührt; Datenbank-Änderungen späterer Versionen sind rein ergänzend.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-Dateien sichern und zurückspielen.
 */
class Rollback {

	/**
	 * Wie viele Sicherungen bleiben liegen.
	 */
	const KEEP = 2;

	/**
	 * Ordner der Sicherungen.
	 *
	 * @return string
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );

		return trailingslashit( $uploads['basedir'] ) . 'lw16-backup';
	}

	/**
	 * Hook: vor dem Update des Plugins eine Sicherung anlegen.
	 *
	 * Gibt $response unverändert zurück - eine fehlgeschlagene Sicherung
	 * darf das Update nicht verhindern, sie wird aber gemeldet.
	 *
	 * @param mixed $response   Bisheriges Ergebnis.
	 * @param array $hook_extra Angaben zum Update.
	 * @return mixed
	 */
	public static function before_update( $response, $hook_extra = array() ) {
		if ( is_array( $hook_extra ) && ( $hook_extra['plugin'] ?? '' ) === LW16_PLUGIN_BASENAME ) {
			if ( ! self::create() ) {
				Log::warning( 'update', 'Vor dem Update konnte keine Sicherung der Plugin-Dateien angelegt werden.' );
			}
		}

		return $response;
	}

	/**
	 * Sicherung der installierten Fassung anlegen.
	 *
	 * @return bool
	 */
	public static function create() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return false;
		}

		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Kein Abruf von außen: Der Ordner enthält zwar nur Programmdateien, braucht aber keiner zu sehen.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.php', "<?php\n// Stille.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$file = $dir . '/' . self::file_name( LW16_VERSION, time() );
		$zip  = new \ZipArchive();

		if ( true !== $zip->open( $file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$root = rtrim( LW16_PLUGIN_DIR, '/\\' );
		$base = basename( $root );
		$iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iter as $path => $info ) {
			if ( $info->isFile() && ! self::skip( substr( $path, strlen( $root ) + 1 ) ) ) {
				$zip->addFile( $path, $base . '/' . str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) ) );
			}
		}

		$ok = $zip->close();

		if ( $ok ) {
			self::prune();
		}

		return $ok;
	}

	/**
	 * Gehört eine Datei nicht in die Sicherung? Reine Regel.
	 *
	 * @param string $relative Pfad relativ zum Plugin-Ordner.
	 * @return bool
	 */
	public static function skip( $relative ) {
		$relative = str_replace( '\\', '/', (string) $relative );

		return 0 === strpos( $relative, 'vendor/' ) || 0 === strpos( $relative, '.git/' ) || 0 === strpos( $relative, 'node_modules/' );
	}

	/**
	 * Dateiname einer Sicherung.
	 *
	 * @param string $version Version.
	 * @param int    $time    Zeitpunkt.
	 * @return string
	 */
	public static function file_name( $version, $time ) {
		return 'lindenweg16-' . preg_replace( '/[^0-9A-Za-z.\-]/', '', (string) $version ) . '-' . gmdate( 'Ymd-His', (int) $time ) . '.zip';
	}

	/**
	 * Ist das ein gültiger Name einer unserer Sicherungen? Reine Regel.
	 *
	 * Verhindert, dass über den Dateinamen aus dem Ordner ausgebrochen wird.
	 *
	 * @param string $name Dateiname.
	 * @return bool
	 */
	public static function is_valid_name( $name ) {
		return 1 === preg_match( '/^lindenweg16-[0-9A-Za-z.\-]+-\d{8}-\d{6}\.zip$/', (string) $name );
	}

	/**
	 * Vorhandene Sicherungen, neueste zuerst.
	 *
	 * @return array Zeilen: file, version, time, size.
	 */
	public static function all() {
		$out = array();

		foreach ( (array) glob( self::dir() . '/lindenweg16-*.zip' ) as $path ) {
			$name = basename( $path );

			if ( ! self::is_valid_name( $name ) ) {
				continue;
			}

			$out[] = array(
				'file'    => $name,
				'version' => preg_match( '/^lindenweg16-(.+)-\d{8}-\d{6}\.zip$/', $name, $m ) ? $m[1] : '',
				'time'    => (int) filemtime( $path ),
				'size'    => (int) filesize( $path ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);

		return $out;
	}

	/**
	 * Alte Sicherungen entfernen.
	 */
	public static function prune() {
		foreach ( array_slice( self::all(), self::KEEP ) as $old ) {
			wp_delete_file( self::dir() . '/' . $old['file'] );
		}
	}

	/**
	 * Eine Sicherung zurückspielen.
	 *
	 * @param string $name Dateiname aus all().
	 * @return true|string True oder die Fehlermeldung.
	 */
	public static function restore( $name ) {
		if ( ! self::is_valid_name( $name ) ) {
			return __( 'Ungültige Sicherung.', 'lindenweg16' );
		}

		$path = self::dir() . '/' . $name;
		if ( ! is_file( $path ) ) {
			return __( 'Die Sicherung gibt es nicht mehr.', 'lindenweg16' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! WP_Filesystem() ) {
			return __( 'Auf das Dateisystem kann nicht geschrieben werden.', 'lindenweg16' );
		}

		$result = unzip_file( $path, WP_PLUGIN_DIR );

		if ( is_wp_error( $result ) ) {
			Log::error( 'update', 'Zurücksetzen fehlgeschlagen: ' . $result->get_error_message() );

			return $result->get_error_message();
		}

		Log::attention( 'update', 'Plugin auf gesicherte Fassung ' . $name . ' zurückgesetzt.' );

		return true;
	}
}
