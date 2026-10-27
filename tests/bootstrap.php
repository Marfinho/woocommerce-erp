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
require $lw16_root . '/includes/Core/Lock.php';
require $lw16_root . '/includes/Core/Log.php';
require $lw16_root . '/includes/Core/EventLog.php';
require $lw16_root . '/includes/Core/Throttle.php';
require $lw16_root . '/includes/Core/HashChain.php';
require $lw16_root . '/includes/Core/Settings.php';
require $lw16_root . '/includes/Core/ResetTool.php';
require $lw16_root . '/includes/Core/Privacy.php';
require $lw16_root . '/includes/Core/Updater.php';
require $lw16_root . '/includes/Core/Rollback.php';
require $lw16_root . '/includes/Modules/Accounting/PdfText.php';
require $lw16_root . '/includes/Modules/Accounting/BelegOcr.php';
require $lw16_root . '/includes/Modules/Accounting/FeeExtractor.php';
require $lw16_root . '/includes/Modules/Accounting/Kontenrahmen.php';
require $lw16_root . '/includes/Modules/Marketing/Kampagnenrechnung.php';
require $lw16_root . '/includes/Modules/Marketing/KampagnenRepository.php';
require $lw16_root . '/includes/Modules/Events/RsvpRechnung.php';
require $lw16_root . '/includes/Modules/Events/EventFields.php';
require $lw16_root . '/includes/Modules/Events/EventsWidget.php';
require $lw16_root . '/includes/Modules/Events/EventsBlock.php';
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
	'ShopStock',
	'ForecastModel',
	'ForecastKpi',
	'Promise',
	'DayPlan',
	'ForecastService',
	'CommissionBatch',
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
	'RepeatRate',
	'Stopwatch',
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
	'TimeRepository',
	'TimeCategoryGuess',
	'ModelFile',
	'KpiMeter',
	'AccessProfiles',
	'LaborCost',
	'UserActivity',
	'MaintenanceChecklist',
	'LabelSheet',
	'Purchase',
	'OrderSource',
	'BetriebModule',
	'Consistency',
	'ReorderToken',
	'ReorderPage',
	'ShippingLabel',
	'PriceSync',
) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/Betrieb/' . $lw16_class . '.php';
}

require $lw16_root . '/includes/Modules/Betrieb/Admin/ShippingPage.php';

// DHL-Versand: Produktwahl, Adressen, Auftrag und Antwort sind reine Logik.
foreach ( array( 'DhlProduct', 'DhlAddress', 'DhlRequest', 'DhlResponse', 'DhlAuth', 'DhlSandbox', 'DhlClient', 'DhlLabelRepository' ) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/Betrieb/Dhl/' . $lw16_class . '.php';
}

// Druck-Warteschlange: Claim-/Lock-Logik, Token-Hashing und das
// Testlabel-PDF sind ebenfalls ohne Datenbank prüfbar.
foreach ( array( 'PrintJobRepository', 'PrinterRepository', 'TestLabelPdf' ) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/Betrieb/PrintQueue/' . $lw16_class . '.php';
}

// Bambu-Lab-Anbindung: dasselbe Muster wie die Druck-Warteschlange - die
// Claim-/Lock-Logik, das Token-Hashing, die Bettmasse und die Entscheidung,
// welche Datei mit einem Job vergehen darf, sind ohne Datenbank prüfbar.
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}
foreach ( array( 'BambuJobRepository', 'BambuPrinterRepository', 'BambuService', 'BedSizeLookup' ) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/Betrieb/Bambu/' . $lw16_class . '.php';
}

// Plattenanordnung/Nesting und die Slicing-Warteschlange: reine Geometrie
// (Polygon, Sat, MeshOutline, BinPacker), die Transformationsmatrix-
// Berechnung von PlateComposer sowie dieselbe Claim-/Lock-/Token-Logik wie
// bei den übrigen Warteschlangen sind ohne Datenbank prüfbar.
foreach ( array( 'Polygon', 'Sat', 'MeshOutline', 'BinPacker', 'Silhouette', 'RasterPacker', 'PlateComposer', 'PlateService', 'SlicingJobRepository', 'SlicingWorkerAuth' ) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/Betrieb/Nesting/' . $lw16_class . '.php';
}

// Seiteneffektfreie Klassen des Moduls Konfigurator.
foreach ( array(
	'Layout',
	'ConfigCodec',
	'ArLink',
	'ArModelStore',
	'ColorHex',
	'ModelPlacement',
	'GlbMeasure',
	'WebModel',
) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/Configurator/' . $lw16_class . '.php';
}

// Seiteneffektfreie Klassen des Moduls AR-Ansicht. Bereinigen, Zählen,
// Dateiprüfung und Skript-Tag sind ohne Datenbank prüfbar.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Vereinfachte Bereinigung: Tags entfernen, Whitespace kürzen.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function sanitize_text_field( $text ) {
		return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $text ) ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Tags entfernen (vereinfacht).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON wie WordPress.
	 *
	 * @param mixed $data Daten.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Berechtigung aus $GLOBALS['lw16_test_caps'] (Liste erlaubter Rechte).
	 *
	 * @param string $cap Recht.
	 * @return bool
	 */
	function current_user_can( $cap ) {
		return in_array( $cap, isset( $GLOBALS['lw16_test_caps'] ) ? (array) $GLOBALS['lw16_test_caps'] : array(), true );
	}
}

foreach ( array( 'Options', 'Stats', 'UploadTypes', 'Viewer' ) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/ArView/' . $lw16_class . '.php';
}

// Seiteneffektfreie Klassen der Mailbox.
foreach ( array( 'MailCrypto', 'MimeParser', 'ImapClient', 'SmtpMailer', 'HtmlSanitizer', 'MailboxAccess', 'ComposeService', 'SyncService' ) as $lw16_class ) {
	require $lw16_root . '/includes/Modules/Mailbox/' . $lw16_class . '.php';
}

// ERP-Oberfläche: Rechtekatalog und Navigation sind reine Zuordnungen.
require $lw16_root . '/includes/Core/Roles.php';
require $lw16_root . '/includes/Erp/Permissions.php';
require $lw16_root . '/includes/Erp/Navigation.php';
require $lw16_root . '/includes/Erp/ActionMap.php';
require $lw16_root . '/includes/Erp/Subdomain.php';
require $lw16_root . '/includes/Erp/RunFlow.php';
require $lw16_root . '/includes/Erp/Forecast.php';
require $lw16_root . '/includes/Erp/Material.php';
require $lw16_root . '/includes/Erp/Procurement.php';
require $lw16_root . '/includes/Erp/Variance.php';
require $lw16_root . '/includes/Erp/Tasks.php';
require $lw16_root . '/includes/Erp/Assembly.php';
require $lw16_root . '/includes/Core/Pdf.php';
require $lw16_root . '/includes/Erp/Payroll.php';
require $lw16_root . '/includes/Erp/Payslips.php';
require $lw16_root . '/includes/Erp/Traffic.php';
require $lw16_root . '/includes/Erp/PayslipPdf.php';
require $lw16_root . '/includes/Erp/Catalog.php';
require $lw16_root . '/includes/Erp/OrderContact.php';
require $lw16_root . '/includes/Erp/ProductFile.php';
require $lw16_root . '/includes/Modules/Betrieb/ContactRepository.php';
require $lw16_root . '/includes/Erp/Pages/Verkauf.php';
require $lw16_root . '/includes/Erp/Pages/Verwaltung.php';
require $lw16_root . '/includes/Erp/Pages/Zahlen.php';
require $lw16_root . '/includes/Erp/Pages/BuchBuchungen.php';
require $lw16_root . '/includes/Modules/Mailbox/MailRepository.php';
require $lw16_root . '/includes/Modules/Mailbox/MailboxView.php';
require $lw16_root . '/includes/Erp/Pages/Postfach.php';
require $lw16_root . '/includes/Erp/Pages/Support.php';
require $lw16_root . '/includes/Erp/Pages/Hilfe.php';
require $lw16_root . '/includes/Erp/Pages/ArAnsicht.php';
require $lw16_root . '/includes/Erp/Pages/Produktion.php';
require $lw16_root . '/includes/Erp/Pages/BambuDruck.php';
require $lw16_root . '/includes/Erp/Pages/Lager.php';
require $lw16_root . '/includes/Erp/Pages/Konfigurator.php';
require $lw16_root . '/includes/Erp/Pages/Marketing.php';
require $lw16_root . '/includes/Erp/Pages/BuchMarketing.php';
require $lw16_root . '/includes/Modules/Betrieb/Admin/ImportPage.php';
require $lw16_root . '/includes/Erp/Pages/Import.php';
require $lw16_root . '/includes/Erp/Pages/Konten.php';
require $lw16_root . '/includes/Erp/Pages/BuchBelege.php';
require $lw16_root . '/includes/Erp/Pages/BuchAbschluss.php';
require $lw16_root . '/includes/Erp/Push.php';
if ( ! defined( 'LW16_CAPABILITY' ) ) {
	define( 'LW16_CAPABILITY', 'manage_lw16' );
}
if ( ! defined( 'LW16_MENU_SLUG' ) ) {
	define( 'LW16_MENU_SLUG', 'lindenweg16' );
}
require $lw16_root . '/includes/Admin/MenuGroups.php';
require $lw16_root . '/includes/Admin/Menu.php';
