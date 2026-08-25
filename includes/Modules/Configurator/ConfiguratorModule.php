<?php
/**
 * Modul Konfigurator: der Haster Haken zum Zusammenstellen.
 *
 * Das Modul setzt auf dem Artikelstamm des Moduls Betrieb auf und hängt
 * seine Pflegeseite dort ins Menü – die Teile des Konfigurators sind
 * Fertigungsdaten und gehören neben Artikel und Stückliste, nicht in eine
 * eigene Ecke des Backends.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Configurator\Admin\ConfiguratorPage;

/**
 * Startet Frontend, Warenkorb-Anbindung und Pflegeseite.
 */
class ConfiguratorModule {

	/**
	 * Seitenkennung im Menü „Betrieb".
	 */
	const PAGE_SLUG = 'lw16-betrieb-konfigurator';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		( new Frontend() )->register();
		( new CartIntegration() )->register();

		if ( is_admin() ) {
			( new ConfiguratorPage() )->register();
		}
	}
}
