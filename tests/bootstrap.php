<?php
/**
 * Test-Bootstrap: lädt die reinen Rechenregeln ohne WordPress-Umgebung.
 *
 * Die getesteten Klassen enthalten `defined('ABSPATH') || exit;` als
 * Direktaufruf-Schutz. Für die Unit-Tests definieren wir ABSPATH und laden
 * die seiteneffektfreien Klassen direkt – bewusst ohne die volle
 * WordPress-Testsuite, da nur reine Logik geprüft wird.
 *
 * Die wenigen WordPress-Funktionen, die in den Rechenklassen vorkommen,
 * betreffen ausschließlich übersetzbare Meldungen und Randwerte. Sie werden
 * hier minimal nachgebildet, damit die Fachklassen idiomatisch bleiben.
 *
 * @package Lindenweg16
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

if ( ! function_exists( '__' ) ) {
	/**
	 * Gibt den Text unverändert zurück.
	 *
	 * @param string $text   Text.
	 * @param string $domain Textdomain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Wählt Singular oder Plural.
	 *
	 * @param string $single Einzahl.
	 * @param string $plural Mehrzahl.
	 * @param int    $number Anzahl.
	 * @param string $domain Textdomain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Zahl formatieren.
	 *
	 * @param float $number   Zahl.
	 * @param int   $decimals Nachkommastellen.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals, ',', '.' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Liefert im Test immer den Vorgabewert – die Rechenklassen greifen nur
	 * für Randwerte wie die Standardfarbe auf Optionen zu.
	 *
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Vorgabe.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Platzhalter für Blog-Angaben.
	 *
	 * @param string $show Feld.
	 * @return string
	 */
	function get_bloginfo( $show = '' ) {
		return '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Basis-URL im Test.
	 *
	 * @param string $path Pfad.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://lindenweg16.test' . $path;
	}
}

$lw16_root = dirname( __DIR__ );

// Kernbausteine.
require $lw16_root . '/includes/Core/Calc.php';
require $lw16_root . '/includes/Core/HashChain.php';
require $lw16_root . '/includes/Core/Settings.php';

// Seiteneffektfreie Klassen des Moduls Betrieb.
foreach ( array(
	'SanitizesInput',
	'CalcSettings',
	'MachineRate',
	'ColorRegistry',
	'StockOwnership',
	'IsoWeek',
	'StockLedger',
	'BomResolver',
	'CostCalculator',
	'BundleCalculator',
	'ReleaseGate',
	'SourcingRepository',
	'PrintJobRepository',
	'BatchRepository',
	'RestApi',
	'ProcurementAlert',
	'DiscountResolver',
	'CapacityPlanner',
	'Kpi',
	'ReorderToken',
) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/Betrieb/' . $lw16_class . '.php';
}
