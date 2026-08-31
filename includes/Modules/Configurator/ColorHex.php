<?php
/**
 * Vom Farbnamen zum Farbwert für die Ansicht.
 *
 * Das Farbsystem des Betriebs kennt Slugs („anthrazit"), keine
 * Bildschirmfarben – und das ist richtig so: Der Slug ist die
 * Bestellwahrheit, ein Hexwert wäre nur eine Behauptung darüber, wie das
 * Filament aussieht. Für die Vorschau braucht der Browser aber einen Wert.
 *
 * Deshalb hier eine kleine Zuordnung mit den üblichen Filamentfarben, ein
 * neutraler Rückfallwert und ein Filter, über den sich jede Farbe ohne
 * Codeänderung nachjustieren lässt. Trifft die Vorschau eine Nuance nicht,
 * ist das ein Anzeigefehler und kein falsch bestelltes Teil.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

/**
 * Liefert Bildschirmfarben zu Farb-Slugs.
 */
class ColorHex {

	/**
	 * Farbe, solange nichts Besseres bekannt ist: ein helles Grau, das
	 * weder nach Weiß noch nach einer bestimmten Farbe aussieht.
	 */
	const FALLBACK = '#d8d8d6';

	/**
	 * Bekannte Filamentfarben.
	 *
	 * @return array Slug => Hexwert.
	 */
	public static function map() {
		return array(
			'weiss'      => '#f2f2ef',
			'weiß'       => '#f2f2ef',
			'natur'      => '#e8e0cf',
			'schwarz'    => '#1c1c1c',
			'anthrazit'  => '#3a3d40',
			'grau'       => '#8b8f93',
			'silber'     => '#b7bcc0',
			'rot'        => '#b6262a',
			'orange'     => '#e2711d',
			'gelb'       => '#e8c22e',
			'gruen'      => '#3f7d3a',
			'grün'       => '#3f7d3a',
			'blau'       => '#2f5d8c',
			'hellblau'   => '#6ba3d0',
			'tuerkis'    => '#2f8c85',
			'türkis'     => '#2f8c85',
			'lila'       => '#6b4a8c',
			'rosa'       => '#d98aa8',
			'braun'      => '#6b4a33',
			'beige'      => '#d6c7a8',
			'gold'       => '#b79246',
			'kupfer'     => '#a4643c',
			'transparent' => '#e6ecef',
		);
	}

	/**
	 * Bildschirmfarbe zu einem Slug.
	 *
	 * @param string $slug Farb-Slug aus dem Farbattribut.
	 * @return string Hexwert mit führendem Doppelkreuz.
	 */
	public static function for_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		$map  = self::map();
		$hex  = isset( $map[ $slug ] ) ? $map[ $slug ] : self::FALLBACK;

		/**
		 * Bildschirmfarbe einer Filamentfarbe überschreiben.
		 *
		 * @param string $hex  Bisheriger Wert.
		 * @param string $slug Farb-Slug.
		 */
		$hex = apply_filters( 'lw16_konfigurator_farbwert', $hex, $slug );

		return self::normalize( $hex );
	}

	/**
	 * Hexwert prüfen und auf sechs Stellen bringen.
	 *
	 * @param string $hex Rohwert.
	 * @return string
	 */
	public static function normalize( $hex ) {
		$hex = strtolower( trim( (string) $hex ) );

		if ( preg_match( '/^#?([0-9a-f]{3})$/', $hex, $match ) ) {
			$short = $match[1];
			return '#' . $short[0] . $short[0] . $short[1] . $short[1] . $short[2] . $short[2];
		}

		if ( preg_match( '/^#?([0-9a-f]{6})$/', $hex, $match ) ) {
			return '#' . $match[1];
		}

		return self::FALLBACK;
	}
}
