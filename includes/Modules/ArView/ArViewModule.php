<?php
/**
 * Modul AR-Ansicht: „An deiner Wand ansehen".
 *
 * Mobile Besucher sehen den Haster Haken per AR in Originalgröße an ihrer
 * eigenen Wand. Das Modul besteht aus:
 *
 * - einer Metabox „3D-Modell (AR)" am WooCommerce-Produkt (ProductMeta),
 * - den Upload-Typen GLB/USDZ für Administratoren (UploadTypes),
 * - einem Hinweis-Banner auf Produktseiten (Banner),
 * - der AR-Seite per Shortcode (Viewer),
 * - anonymen Tageszählern samt REST-Endpunkt (Stats, RestApi),
 * - der Seite Verwaltung → AR-Ansicht (Admin/ArViewPage).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\ArView;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\ArView\Admin\ArViewPage;

/**
 * Startet die Teile des Moduls.
 */
class ArViewModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		( new UploadTypes() )->register();
		( new RestApi() )->register();
		( new Banner() )->register();
		( new Viewer() )->register();

		if ( is_admin() ) {
			( new ProductMeta() )->register();
			( new ArViewPage() )->register();
		}
	}
}
