<?php
/**
 * Modul Kurzlinks: feste Adressen für Printmaterial.
 *
 * QR-Codes auf Paketbeilage, Flyer, Sticker und Stand tragen Adressen wie
 * `lindenweg16.de/montage`, die sich nach dem Druck nicht mehr ändern. Das Modul
 * leitet sie auf ein im Admin einstellbares Ziel weiter und zählt die Aufrufe
 * anonym je Tag und Quelle:
 *
 * - Links.php        Speicher und Prüfregeln (Slug, Ziel),
 * - Redirect.php     Rewrite-Regel und 302-Weiterleitung,
 * - Stats.php        Tageszähler ohne IP, User-Agent und Cookie,
 * - OrderOrigin.php  Bestellungen nach Herkunft (WooCommerce Order Attribution),
 * - Admin/KurzlinksPage.php  Seite Verkauf → Kurzlinks.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Kurzlinks;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Kurzlinks\Admin\KurzlinksPage;

/**
 * Startet die Teile des Moduls.
 */
class KurzlinksModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		( new Redirect() )->register();

		if ( is_admin() ) {
			$page = new KurzlinksPage();
			$page->register();
			add_action( 'lw16_dashboard_widgets', array( $page, 'render_widget' ) );
		}
	}
}
