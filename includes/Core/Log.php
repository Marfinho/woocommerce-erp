<?php
/**
 * Systemprotokoll.
 *
 * Fehler in Hintergrundjobs, Buchungen und Schnittstellen gingen bisher
 * stillschweigend unter: Wer nicht zufällig zusah, erfuhr nie, dass etwas
 * nicht lief. Dieses Protokoll sammelt sie an einer Stelle - im Logger von
 * WooCommerce (Quelle `lindenweg16`) und in einer kurzen Liste, die das ERP
 * unter „Systemmeldungen" anzeigt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Zentrale Fehlermeldungen.
 */
class Log {

	/**
	 * Option mit den letzten Einträgen.
	 */
	const OPTION = 'lw16_system_log';

	/**
	 * Höchstzahl gemerkter Einträge.
	 */
	const MAX = 200;

	/**
	 * Fehler melden.
	 *
	 * @param string $area    Bereich, etwa „lager" oder „dhl".
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben (einfache Werte).
	 */
	public static function error( $area, $message, array $context = array() ) {
		self::add( 'error', $area, $message, $context );
	}

	/**
	 * Warnung melden.
	 *
	 * @param string $area    Bereich.
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben.
	 */
	public static function warning( $area, $message, array $context = array() ) {
		self::add( 'warning', $area, $message, $context );
	}

	/**
	 * Eintrag bauen - reine Logik.
	 *
	 * @param string $level   error oder warning.
	 * @param string $area    Bereich.
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben.
	 * @param int    $time    Zeitpunkt.
	 * @return array
	 */
	public static function entry( $level, $area, $message, array $context, $time ) {
		$clean = array();

		foreach ( $context as $key => $value ) {
			$clean[ (string) $key ] = is_scalar( $value ) || null === $value ? mb_substr( (string) $value, 0, 200 ) : '[' . gettype( $value ) . ']';
		}

		return array(
			'time'    => (int) $time,
			'level'   => 'warning' === $level ? 'warning' : 'error',
			'area'    => mb_substr( (string) $area, 0, 40 ),
			'message' => mb_substr( (string) $message, 0, 500 ),
			'context' => $clean,
		);
	}

	/**
	 * Neuen Eintrag vorn anfügen und auf die Höchstzahl kürzen - reine Logik.
	 *
	 * @param array $entries Bisherige Einträge, neueste zuerst.
	 * @param array $entry   Neuer Eintrag.
	 * @param int   $max     Höchstzahl.
	 * @return array
	 */
	public static function push( array $entries, array $entry, $max = self::MAX ) {
		array_unshift( $entries, $entry );

		return array_slice( $entries, 0, max( 1, (int) $max ) );
	}

	/**
	 * Eintrag schreiben.
	 *
	 * @param string $level   Stufe.
	 * @param string $area    Bereich.
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben.
	 */
	protected static function add( $level, $area, $message, array $context ) {
		$entry = self::entry( $level, $area, $message, $context, time() );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log(
				'warning' === $entry['level'] ? 'warning' : 'error',
				'[' . $entry['area'] . '] ' . $entry['message'] . ( $entry['context'] ? ' ' . wp_json_encode( $entry['context'] ) : '' ),
				array( 'source' => 'lindenweg16' )
			);
		}

		$entries = get_option( self::OPTION, array() );
		update_option( self::OPTION, self::push( is_array( $entries ) ? $entries : array(), $entry ), false );
	}

	/**
	 * Die letzten Einträge, neueste zuerst.
	 *
	 * @param int $limit Anzahl.
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? array_slice( $entries, 0, max( 1, (int) $limit ) ) : array();
	}

	/**
	 * Protokoll leeren.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
