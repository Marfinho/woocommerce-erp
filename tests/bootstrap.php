<?php
/**
 * Test-Bootstrap: lädt die reinen Rechenregeln ohne WordPress-Umgebung.
 *
 * Die getesteten Klassen enthalten `defined('ABSPATH') || exit;` als
 * Direktaufruf-Schutz. Für die Unit-Tests definieren wir ABSPATH und laden
 * die seiteneffektfreie Calc-Klasse direkt – bewusst ohne die volle
 * WordPress-Testsuite, da nur reine Logik geprüft wird.
 *
 * @package Lindenweg16
 */

define( 'ABSPATH', __DIR__ . '/' );

/*
 * Minimale Stubs der WordPress-Funktionen, die in den ansonsten reinen
 * Rechenklassen nur für übersetzbare Meldungen vorkommen. Damit bleiben die
 * Fachklassen idiomatisch (alle Strings übersetzbar) und trotzdem ohne
 * WordPress testbar.
 */
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

require dirname( __DIR__ ) . '/includes/Core/Calc.php';
require dirname( __DIR__ ) . '/includes/Core/HashChain.php';
require dirname( __DIR__ ) . '/includes/Modules/Betrieb/CalcSettings.php';
require dirname( __DIR__ ) . '/includes/Modules/Betrieb/MachineRate.php';
require dirname( __DIR__ ) . '/includes/Modules/Betrieb/BomResolver.php';
require dirname( __DIR__ ) . '/includes/Modules/Betrieb/CostCalculator.php';
require dirname( __DIR__ ) . '/includes/Modules/Betrieb/BundleCalculator.php';
require dirname( __DIR__ ) . '/includes/Modules/Betrieb/ReleaseGate.php';
require dirname( __DIR__ ) . '/includes/Modules/Betrieb/SanitizesInput.php';
require dirname( __DIR__ ) . '/includes/Modules/Betrieb/SourcingRepository.php';
require dirname( __DIR__ ) . '/includes/Modules/Betrieb/ColorRegistry.php';
require dirname( __DIR__ ) . '/includes/Modules/Betrieb/StockOwnership.php';
