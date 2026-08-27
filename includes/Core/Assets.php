<?php
/**
 * Versionsstempel für Stil- und Skriptdateien.
 *
 * Browser merken sich eine Datei an ihrer Adresse. Hängt dort immer
 * dieselbe Plugin-Version, bekommt ein Besucher eine korrigierte Datei
 * nie zu sehen – er hat sie ja schon. Genau das ist passiert: Mehrere
 * Korrekturen an der mobilen Erfassung lagen auf dem Server, im Telefon
 * lief weiter die alte Fassung.
 *
 * Deshalb stempelt hier die Änderungszeit der Datei selbst. Sie ändert
 * sich mit jeder Auslieferung von allein, ohne dass jemand daran denken
 * muss.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Liefert den Versionsstempel einer mitgelieferten Datei.
 */
class Assets {

	/**
	 * Versionsstempel für eine Datei im Plugin.
	 *
	 * @param string $relative Pfad ab Plugin-Wurzel, z. B. assets/css/betrieb.css.
	 * @return string
	 */
	public static function version( $relative ) {
		$relative = ltrim( (string) $relative, '/' );

		// Zwei Zugriffe auf das Dateisystem je Datei, und dieselbe Datei
		// wird auf einer Seite mehrfach angemeldet – etwa frontend.css aus
		// drei verschiedenen Modulen. Innerhalb eines Aufrufs aendert sich
		// die Zeit nicht, also wird sie gemerkt.
		static $stamps = array();

		if ( isset( $stamps[ $relative ] ) ) {
			return $stamps[ $relative ];
		}

		$absolute = LW16_PLUGIN_DIR . $relative;

		// Fehlt die Datei oder lässt sich ihre Zeit nicht lesen, bleibt es
		// bei der Plugin-Version. Lieber ein grober Stempel als gar keiner.
		// `filemtime` meldet bei fehlender Datei selbst false, eine eigene
		// Pruefung davor waere ein zweiter Gang zur Platte.
		$time = @filemtime( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fehlende Datei ist ein erwarteter Fall, der Rueckfallwert steht darunter.

		$stamps[ $relative ] = $time ? (string) $time : LW16_VERSION;

		return $stamps[ $relative ];
	}

	/**
	 * Vollständige URL einer Datei samt Versionsstempel.
	 *
	 * Für Stellen, die ein <link> oder <script> von Hand schreiben.
	 *
	 * @param string $relative Pfad ab Plugin-Wurzel.
	 * @return string
	 */
	public static function url( $relative ) {
		$relative = ltrim( (string) $relative, '/' );

		return LW16_PLUGIN_URL . $relative . '?v=' . self::version( $relative );
	}
}
