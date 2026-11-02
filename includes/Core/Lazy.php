<?php
/**
 * Klassen erst laden, wenn sie gebraucht werden.
 *
 * Das Plugin ist groß geworden (über 150.000 Zeilen). Früher wurde bei jedem
 * Aufruf jede Seitenklasse und jede REST-Klasse geladen, nur um ihre Hooks
 * anzumelden – auch wenn die Seite gar nicht offen war. Das kostet auf
 * kleinem Webspace spürbar Speicher und Zeit.
 *
 * Lazy meldet Hooks mit einem schlanken Rückruf an, der die Klasse erst
 * lädt, wenn der Hook wirklich feuert, und entscheidet, ob eine Admin-Seite
 * im aktuellen Aufruf überhaupt gebraucht wird.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Hilfen fürs verzögerte Laden.
 */
class Lazy {

	/**
	 * Bereits erzeugte Objekte je Klasse.
	 *
	 * @var array<string, object>
	 */
	private static $instances = array();

	/**
	 * Ein Objekt der Klasse, pro Aufruf nur einmal erzeugt.
	 *
	 * @param string $class Klassenname.
	 * @return object
	 */
	public static function instance( $class ) {
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new $class();
		}
		return self::$instances[ $class ];
	}

	/**
	 * Rückruf, der die Klasse erst beim Aufruf lädt und die Methode aufruft.
	 *
	 * @param string $class  Klassenname.
	 * @param string $method Methode.
	 * @return \Closure
	 */
	public static function method( $class, $method ) {
		return static function ( ...$args ) use ( $class, $method ) {
			return self::instance( $class )->$method( ...$args );
		};
	}

	/**
	 * REST-Routen einer Klasse erst bei rest_api_init anmelden – nur dann
	 * wird die Klasse geladen, also nur bei echten API-Aufrufen.
	 *
	 * @param string $class  Klassenname.
	 * @param string $method Methode, die die Routen anmeldet.
	 */
	public static function rest( $class, $method = 'register_routes' ) {
		add_action( 'rest_api_init', self::method( $class, $method ) );
	}

	/**
	 * Name der aufgerufenen Datei im Adminbereich (admin.php, admin-post.php …).
	 *
	 * @return string
	 */
	public static function pagenow() {
		if ( ! empty( $GLOBALS['pagenow'] ) ) {
			return (string) $GLOBALS['pagenow'];
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return basename( $script );
	}

	/**
	 * Formularversand (admin-post.php) oder AJAX: Hier kann jede Seite ihre
	 * Handler brauchen, deshalb wird alles angemeldet.
	 *
	 * @return bool
	 */
	public static function is_handler_request() {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}
		return in_array( self::pagenow(), array( 'admin-post.php', 'admin-ajax.php' ), true );
	}

	/**
	 * Slug der geöffneten Admin-Seite (admin.php?page=…).
	 *
	 * @return string
	 */
	public static function current_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Wird eine Admin-Seite in diesem Aufruf gebraucht?
	 *
	 * Ja bei Formularversand/AJAX und wenn eine ihrer Seiten offen ist.
	 *
	 * @param string[] $slugs Seiten-Slugs, die die Klasse darstellt. Leer = nur Handler.
	 * @return bool
	 */
	public static function page_needed( array $slugs ) {
		if ( self::is_handler_request() ) {
			return true;
		}
		return '' !== self::current_page() && in_array( self::current_page(), $slugs, true );
	}
}
