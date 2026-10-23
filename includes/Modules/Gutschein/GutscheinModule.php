<?php
/**
 * Modul Gutschein: Vorlagenwahl und Versand für das Gutschein-Produkt.
 *
 * Ein Produkt wird im Produktformular als „Gutschein mit Vorlagenwahl“
 * markiert. Der Kunde wählt dann auf der Produktseite zwischen drei
 * gestalteten Vorlagen oder der schlichten Mail, trägt Von, An und Text
 * ein und sieht die Karte dabei live. Nach der Zahlung entsteht ein
 * WooCommerce-Gutscheincode in Höhe des bezahlten Betrags, und der
 * Beschenkte bekommt ihn per Mail.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Gutschein;

defined( 'ABSPATH' ) || exit;

/**
 * Startet Produktfeld, Warenkorb-Anbindung und Versand.
 */
class GutscheinModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		( new Produkt() )->register();
		( new Versand() )->register();
	}
}
