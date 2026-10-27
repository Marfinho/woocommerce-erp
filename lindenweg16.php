<?php
/**
 * Plugin Name:       Lindenweg16 ERP
 * Plugin URI:        https://lindenweg16.de
 * Description:       Gesamtplugin für lindenweg16.de: Buchhaltung, Lagerverwaltung, Loyalty, Produktions- und Bestellplanung, Preiskalkulation, Kundenbindung, Support & Reporting – vollständig in WooCommerce integriert.
 * Version:           0.39.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Lindenweg16
 * Text Domain:       lindenweg16
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.9
 *
 * @package Lindenweg16
 */

defined( 'ABSPATH' ) || exit;

define( 'LW16_VERSION', '0.39.2' );
define( 'LW16_PLUGIN_FILE', __FILE__ );
define( 'LW16_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LW16_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LW16_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Capability, Rolle und Menü-Slug zentral definieren.
define( 'LW16_CAPABILITY', 'manage_lw16' );
define( 'LW16_ROLE', 'lw16_manager' );
define( 'LW16_MENU_SLUG', 'lindenweg16' );

/**
 * Einfacher PSR-4-ähnlicher Autoloader für den Namespace Lindenweg16\.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix   = 'Lindenweg16\\';
		$base_dir = LW16_PLUGIN_DIR . 'includes/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * HPOS-Kompatibilität deklarieren.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Aktivierung: WooCommerce-Abhängigkeit prüfen, Tabellen anlegen, Rolle registrieren.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'Lindenweg16 ERP benötigt ein aktives WooCommerce. Bitte zuerst WooCommerce installieren und aktivieren.', 'lindenweg16' ),
				esc_html__( 'Plugin-Abhängigkeit fehlt', 'lindenweg16' ),
				array( 'back_link' => true )
			);
		}

		require_once LW16_PLUGIN_DIR . 'includes/Core/Installer.php';
		\Lindenweg16\Core\Installer::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		require_once LW16_PLUGIN_DIR . 'includes/Core/Installer.php';
		\Lindenweg16\Core\Installer::deactivate();
	}
);

/**
 * Plugin booten, sobald alle Plugins geladen sind (damit WooCommerce verfügbar ist).
 */
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'lindenweg16', false, dirname( LW16_PLUGIN_BASENAME ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Lindenweg16 ERP ist aktiv, aber WooCommerce wurde nicht gefunden. Das Plugin bleibt inaktiv, bis WooCommerce verfügbar ist.', 'lindenweg16' );
					echo '</p></div>';
				}
			);
			return;
		}

		\Lindenweg16\Core\Plugin::instance()->boot();
	}
);
