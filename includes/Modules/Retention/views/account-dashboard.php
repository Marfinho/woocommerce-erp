<?php
/**
 * Mein Konto – Übersicht (ersetzt myaccount/dashboard.php von WooCommerce).
 *
 * @package Lindenweg16
 */

defined( 'ABSPATH' ) || exit;

\Lindenweg16\Modules\Retention\AccountOverview::render();

// Wie im Original: Erweiterungen hängen sich hier an.
do_action( 'woocommerce_account_dashboard' );
