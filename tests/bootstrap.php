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

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Platzhalter fuer die WordPress-Escapefunktion.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Platzhalter fuer die WordPress-Escapefunktion.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Platzhalter: uebersetzt nicht, escapt aber wie im Betrieb.
	 *
	 * @param string $text   Text.
	 * @param string $domain Textdomain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Gibt den Wert unverändert zurück – ohne WordPress hängt kein Filter.
	 *
	 * @param string $hook  Filtername.
	 * @param mixed  $value Wert.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) { // phpcs:ignore
		return $value;
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
		if ( isset( $GLOBALS['lw16_test_options'][ $key ] ) ) {
			return $GLOBALS['lw16_test_options'][ $key ];
		}
		return $default;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Platzhalter fuer die WordPress-Variante von parse_url.
	 *
	 * @param string $url       URL.
	 * @param int    $component Bestandteil.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Vereinfachter Nachbau: haengt Parameter an eine URL an.
	 *
	 * @param array|string $args Parameter oder einzelner Schluessel.
	 * @param string       $url  Basis-URL.
	 * @return string
	 */
	function add_query_arg( $args, $url = '' ) {
		if ( ! is_array( $args ) ) {
			$args = array( $args => func_get_arg( 1 ) );
			$url  = func_num_args() > 2 ? func_get_arg( 2 ) : '';
		}

		$query = http_build_query( $args );

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
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

// Konstanten, die das Plugin sonst in seiner Hauptdatei setzt.
if ( ! defined( 'LW16_PLUGIN_DIR' ) ) {
	define( 'LW16_PLUGIN_DIR', $lw16_root . '/' );
}
if ( ! defined( 'LW16_PLUGIN_URL' ) ) {
	define( 'LW16_PLUGIN_URL', 'https://lindenweg16.test/wp-content/plugins/lindenweg16/' );
}
if ( ! defined( 'LW16_VERSION' ) ) {
	define( 'LW16_VERSION', '0.10.0' );
}

// Kernbausteine.
require $lw16_root . '/includes/Core/Assets.php';
require $lw16_root . '/includes/Core/Calc.php';
require $lw16_root . '/includes/Core/HashChain.php';
require $lw16_root . '/includes/Core/Settings.php';
require $lw16_root . '/includes/Core/ResetTool.php';
require $lw16_root . '/includes/Core/Privacy.php';
require $lw16_root . '/includes/Modules/Accounting/PdfText.php';
require $lw16_root . '/includes/Modules/Accounting/BelegOcr.php';
require $lw16_root . '/includes/Modules/Accounting/FeeExtractor.php';
require $lw16_root . '/includes/Modules/Accounting/Kontenrahmen.php';
require $lw16_root . '/includes/Modules/Marketing/Kampagnenrechnung.php';
require $lw16_root . '/includes/Modules/Production/DhlTracking.php';
require $lw16_root . '/includes/Modules/POS/POSModule.php';

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
	'ComponentConsumption',
	'CostCalculator',
	'BundleCalculator',
	'SourcingRepository',
	'PrintJobRepository',
	'BatchRepository',
	'RestApi',
	'ProcurementAlert',
	'DiscountResolver',
	'CapacityPlanner',
	'Kpi',
	'Period',
	'KpiGoals',
	'TrendChart',
	'DeviceLabel',
	'QrCode',
	'ScanResolver',
	'BinRepository',
	'PicklistRepository',
	'WorkOrderRepository',
	'PriceTierRepository',
	'DemandPlanner',
	'ProductionPlan',
	'DeliveryNote',
	'ThreeMf',
	'AnalyseExport',
	'ModelFile',
	'KpiMeter',
	'AccessProfiles',
	'LaborCost',
	'UserActivity',
	'MaintenanceChecklist',
	'LabelSheet',
	'Purchase',
	'BetriebModule',
	'Consistency',
	'ReorderToken',
	'ReorderPage',
) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/Betrieb/' . $lw16_class . '.php';
}

// Seiteneffektfreie Klassen des Moduls Konfigurator.
foreach ( array(
	'Layout',
	'ConfigCodec',
	'ColorHex',
	'WebModel',
) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/Configurator/' . $lw16_class . '.php';
}
