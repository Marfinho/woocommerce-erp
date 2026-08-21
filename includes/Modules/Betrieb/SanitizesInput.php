<?php
/**
 * Gemeinsame Eingabe-Normalisierung der Betrieb-Repositories.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Wandelt Formulareingaben in Datenbankwerte.
 */
trait SanitizesInput {

	/**
	 * Standardlagerplatz aus einem Formular übernehmen.
	 *
	 * Der Platz ist eine Vorbelegung, keine Bestandsangabe: Er sagt, wo
	 * ein Teil üblicherweise liegt, damit Buchungsmasken und Picklisten
	 * einen sinnvollen Vorschlag machen. Der tatsächliche Bestand je
	 * Platz kommt weiterhin aus den Bewegungen.
	 *
	 * Ein unbekannter oder stillgelegter Platz wird zu NULL – lieber
	 * kein Vorschlag als einer, der ins Leere zeigt.
	 *
	 * @param array  $input Eingaben.
	 * @param string $key   Feldname.
	 * @return int|null
	 */
	protected static function bin_or_null( array $input, $key = 'default_bin_id' ) {
		$id = isset( $input[ $key ] ) ? (int) $input[ $key ] : 0;

		if ( $id <= 0 ) {
			return null;
		}

		$bin = BinRepository::find( $id );

		if ( ! $bin || empty( $bin['is_active'] ) ) {
			return null;
		}

		return $id;
	}

	/**
	 * Textfeld normalisieren. Leere Eingabe wird NULL.
	 *
	 * @param array  $input Eingaben.
	 * @param string $key   Feld.
	 * @return string|null
	 */
	protected static function text( array $input, $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			return null;
		}
		$value = sanitize_text_field( $input[ $key ] );
		return '' === $value ? null : $value;
	}

	/**
	 * Zahl normalisieren, deutsche Kommaschreibweise zulassen.
	 *
	 * Leere Eingabe bleibt NULL: „nicht gepflegt" und „ist null" sind
	 * verschiedene Aussagen, und die Kalkulation muss sie unterscheiden können.
	 *
	 * @param array  $input Eingaben.
	 * @param string $key   Feld.
	 * @return float|null
	 */
	protected static function decimal( array $input, $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			return null;
		}
		$raw = trim( str_replace( ',', '.', (string) $input[ $key ] ) );
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		return (float) $raw;
	}

	/**
	 * Zahl mit Vorgabewert, wenn nichts eingegeben wurde.
	 *
	 * @param array  $input   Eingaben.
	 * @param string $key     Feld.
	 * @param float  $default Vorgabe.
	 * @return float
	 */
	protected static function decimal_or( array $input, $key, $default = 0.0 ) {
		$value = self::decimal( $input, $key );
		return null === $value ? (float) $default : $value;
	}

	/**
	 * Ganzzahl mit Vorgabewert.
	 *
	 * @param array  $input   Eingaben.
	 * @param string $key     Feld.
	 * @param int    $default Vorgabe.
	 * @return int
	 */
	protected static function int_or( array $input, $key, $default = 0 ) {
		$value = self::decimal( $input, $key );
		return null === $value ? (int) $default : (int) round( $value );
	}

	/**
	 * Datum auf Y-m-d normalisieren.
	 *
	 * @param array  $input Eingaben.
	 * @param string $key   Feld.
	 * @return string|null
	 */
	protected static function date( array $input, $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			return null;
		}
		$raw = trim( (string) $input[ $key ] );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : null;
	}

	/**
	 * Datum mit Vorgabe heute.
	 *
	 * @param array  $input Eingaben.
	 * @param string $key   Feld.
	 * @return string
	 */
	protected static function date_or_today( array $input, $key ) {
		$value = self::date( $input, $key );
		return null === $value ? gmdate( 'Y-m-d' ) : $value;
	}

	/**
	 * Wert gegen eine Liste erlaubter Werte prüfen.
	 *
	 * @param array  $input   Eingaben.
	 * @param string $key     Feld.
	 * @param array  $allowed Erlaubte Werte.
	 * @param string $default Vorgabe.
	 * @return string
	 */
	protected static function enum( array $input, $key, array $allowed, $default ) {
		$value = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Kontrollkästchen auswerten.
	 *
	 * @param array  $input Eingaben.
	 * @param string $key   Feld.
	 * @return int
	 */
	protected static function flag( array $input, $key ) {
		return empty( $input[ $key ] ) ? 0 : 1;
	}
}
