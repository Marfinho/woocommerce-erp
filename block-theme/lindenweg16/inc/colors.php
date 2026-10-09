<?php
/**
 * Farben unter Design → Lindenweg16 anpassen.
 *
 * Die 19 Farbrollen stehen in theme.json. Hier gespeicherte Farben ersetzen
 * diese Werte – überall, wo das Theme die Rollen nutzt: Website, Editor und
 * E-Mails. Leere Felder behalten den Wert aus theme.json.
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Die Farbrollen aus theme.json (Reihenfolge, Name, Standardwert).
 *
 * @return array<string, array{name:string,color:string}>
 */
function lindenweg16_default_palette(): array {
	static $palette = null;

	if ( null !== $palette ) {
		return $palette;
	}

	$palette = array();
	$json    = wp_json_file_decode( get_theme_file_path( 'theme.json' ), array( 'associative' => true ) );

	foreach ( (array) ( $json['settings']['color']['palette'] ?? array() ) as $entry ) {
		if ( isset( $entry['slug'], $entry['color'] ) ) {
			$palette[ (string) $entry['slug'] ] = array(
				'name'  => (string) ( $entry['name'] ?? $entry['slug'] ),
				'color' => strtolower( (string) $entry['color'] ),
			);
		}
	}

	return $palette;
}

/**
 * Unter Design → Lindenweg16 geänderte Farben (nur gültige, abweichende).
 *
 * @return array<string, string> Rolle => Hex-Farbe.
 */
function lindenweg16_custom_colors(): array {
	$saved    = get_theme_mod( 'lindenweg16_colors', array() );
	$defaults = lindenweg16_default_palette();
	$colors   = array();

	foreach ( (array) $saved as $slug => $color ) {
		$color = sanitize_hex_color( (string) $color );

		if ( $color && isset( $defaults[ $slug ] ) && strtolower( $color ) !== $defaults[ $slug ]['color'] ) {
			$colors[ (string) $slug ] = strtolower( $color );
		}
	}

	return $colors;
}

/**
 * Aktuell gültige Farbe einer Rolle (eigene Farbe oder theme.json).
 *
 * @param string $slug Rolle.
 */
function lindenweg16_color( string $slug ): string {
	$custom   = lindenweg16_custom_colors();
	$defaults = lindenweg16_default_palette();

	return $custom[ $slug ] ?? ( $defaults[ $slug ]['color'] ?? '#000000' );
}

/**
 * Eigene Farben in die Theme-Palette schreiben.
 *
 * @param WP_Theme_JSON_Data $theme_json Theme-Daten.
 * @return WP_Theme_JSON_Data
 */
function lindenweg16_apply_custom_colors( $theme_json ) {
	$custom = lindenweg16_custom_colors();

	if ( ! $custom || ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
		return $theme_json;
	}

	$palette = array();
	foreach ( lindenweg16_default_palette() as $slug => $entry ) {
		$palette[] = array(
			'slug'  => $slug,
			'name'  => $entry['name'],
			'color' => $custom[ $slug ] ?? $entry['color'],
		);
	}

	return $theme_json->update_with(
		array(
			'version'  => 3,
			'settings' => array( 'color' => array( 'palette' => $palette ) ),
		)
	);
}
add_filter( 'wp_theme_json_data_theme', 'lindenweg16_apply_custom_colors' );

/**
 * Kontrastverhältnis zweier Hex-Farben nach WCAG.
 *
 * @param string $a Farbe 1.
 * @param string $b Farbe 2.
 */
function lindenweg16_contrast( string $a, string $b ): float {
	$luminance = static function ( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$rgb = array_map(
			static function ( string $part ): float {
				$c = hexdec( $part ) / 255;
				return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			},
			str_split( substr( $hex . '000000', 0, 6 ), 2 )
		);
		return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
	};

	$l1 = $luminance( $a );
	$l2 = $luminance( $b );

	return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
}

/**
 * Farbpaare, die gut lesbar sein müssen (Schrift auf Fläche).
 *
 * @return array<int, array{0:string,1:string,2:float}> Schrift, Fläche, Mindestkontrast.
 */
function lindenweg16_contrast_pairs(): array {
	return array(
		array( 'text', 'background', 4.5 ),
		array( 'text', 'surface', 4.5 ),
		array( 'text-muted', 'surface', 4.5 ),
		array( 'text-muted', 'background', 4.5 ),
		array( 'on-primary', 'primary', 4.5 ),
		array( 'on-accent', 'accent-1', 4.5 ),
		array( 'on-accent', 'accent-3', 4.5 ),
		array( 'on-accent', 'accent-4', 4.5 ),
		array( 'on-ink', 'ink', 4.5 ),
		array( 'sale', 'surface', 4.5 ),
		array( 'on-primary', 'sale', 4.5 ),
		array( 'error', 'surface', 4.5 ),
		array( 'success', 'surface', 4.5 ),
		array( 'accent-2', 'surface', 3.0 ),
	);
}
