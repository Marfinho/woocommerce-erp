<?php
/**
 * Haken anmelden, ohne die Klasse dahinter zu laden.
 *
 * Ein ERP im selben Plugin wie der Shop hat ein Mengenproblem: Die
 * Buchhaltung ist anderthalbtausend Zeilen lang, und davon braucht das
 * Frontend zwei Methoden – eine, wenn eine Bestellung abgeschlossen wird,
 * eine für den Hintergrundjob. Beides passiert selten. Trotzdem wurde die
 * Datei bei jedem Seitenaufruf eingelesen, weil `add_action` ein fertiges
 * Objekt braucht.
 *
 * Hier steht stattdessen ein Verschluss in der Hakentabelle. Er kennt nur
 * den Klassennamen als Zeichenkette – und die löst der Autoloader nicht
 * auf. Erst wenn der Haken wirklich auslöst, entsteht das Objekt.
 *
 * Nicht geeignet für Haken, die ohnehin bei jedem Aufruf auslösen: Dort
 * kostet der Umweg nur, ohne je etwas zu sparen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Verzögerte Anmeldung von Haken samt gemeinsamer Objektverwaltung.
 */
final class Lazy {

	/**
	 * Bereits gebaute Objekte, je Klasse eines.
	 *
	 * @var array<string,object>
	 */
	private static $instances = array();

	/**
	 * Das Objekt einer Klasse holen und dabei höchstens einmal bauen.
	 *
	 * @param string $class Vollständiger Klassenname.
	 * @return object
	 */
	public static function instance( $class ) {
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new $class();
		}

		return self::$instances[ $class ];
	}

	/**
	 * Eine Aktion anmelden, deren Klasse erst beim Auslösen geladen wird.
	 *
	 * @param string $hook     Name des Hakens.
	 * @param string $class    Klasse mit der Methode.
	 * @param string $method   Methodenname.
	 * @param int    $priority Reihenfolge.
	 * @param int    $accepted Wie viele Argumente die Methode nimmt.
	 */
	public static function action( $hook, $class, $method, $priority = 10, $accepted = 1 ) {
		add_action( $hook, self::callback( $class, $method ), $priority, $accepted );
	}

	/**
	 * Dasselbe für einen Filter. Der Rückgabewert wird durchgereicht.
	 *
	 * @param string $hook     Name des Hakens.
	 * @param string $class    Klasse mit der Methode.
	 * @param string $method   Methodenname.
	 * @param int    $priority Reihenfolge.
	 * @param int    $accepted Wie viele Argumente die Methode nimmt.
	 */
	public static function filter( $hook, $class, $method, $priority = 10, $accepted = 1 ) {
		add_filter( $hook, self::callback( $class, $method ), $priority, $accepted );
	}

	/**
	 * Der Verschluss, der die Klasse erst beim Auslösen auflöst.
	 *
	 * @param string $class  Klasse.
	 * @param string $method Methode.
	 * @return callable
	 */
	private static function callback( $class, $method ) {
		return static function ( ...$args ) use ( $class, $method ) {
			return self::instance( $class )->$method( ...$args );
		};
	}
}
