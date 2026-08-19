<?php
/**
 * Wer bucht den Lagerabgang einer Bestellung?
 *
 * Das Plugin hat zwei Lagermodelle: das ältere Inventory-Modul mit einem
 * materialisierten Bestand je Produkt und das Bewegungskonto des Moduls
 * Betrieb. Beide auf dieselbe Bestellung loszulassen, würde doppelt abbuchen.
 *
 * Entscheidung: **Betrieb ist führend für genau die Artikel, die es kennt.**
 * Ein WooCommerce-Produkt, das über `wc_product_id` auf einen Artikel in
 * `lw16_articles` zeigt, wird ausschließlich vom Bewegungskonto gebucht;
 * Inventory überspringt es. Alles andere – Rohmaterial, Altbestände, Produkte
 * ohne Betrieb-Artikel – bleibt unverändert bei Inventory.
 *
 * Begründung: Ein Bewegungskonto lässt sich nachrechnen und stornieren, eine
 * fortgeschriebene Zahl nicht. Für die GoBD-Nachvollziehbarkeit und die
 * Kommissionskette (raus, abgerechnet, retour) führt an Bewegungen kein Weg
 * vorbei. Die Umstellung erfolgt aber nicht schlagartig, sondern je Artikel:
 * Wer in `lw16_articles` steht, ist umgestellt.
 *
 * Die Übernahme ist bis zum Abschluss von Meilenstein 5 abgeschaltet, weil
 * Betrieb bis dahin gar nicht bucht – sonst entstünde eine Lücke, in der
 * niemand abbucht.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Entscheidet je Produkt, welches Modul den Lagerabgang bucht.
 */
class StockOwnership {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'lw16_inventory_skip_product', array( __CLASS__, 'filter_skip_product' ), 10, 2 );
	}

	/**
	 * Ist die Übernahme durch Betrieb aktiv?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return (bool) Settings::get( 'betrieb_lagerhoheit', 0 );
	}

	/**
	 * Kennt Betrieb ein WooCommerce-Produkt?
	 *
	 * @param int $wc_product_id Produkt-ID.
	 * @return int Artikel-ID oder 0.
	 */
	public static function article_for_product( $wc_product_id ) {
		global $wpdb;

		$wc_product_id = (int) $wc_product_id;
		if ( ! $wc_product_id ) {
			return 0;
		}

		$table = ArticleRepository::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE wc_product_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wc_product_id
			)
		);
	}

	/**
	 * Entscheidung als reine Logik – wer bucht?
	 *
	 * @param int  $article_id      Artikel in lw16_articles, 0 wenn unbekannt.
	 * @param bool $authority_active Ist die Übernahme eingeschaltet?
	 * @return string 'betrieb' oder 'inventory'.
	 */
	public static function decide( $article_id, $authority_active ) {
		if ( $authority_active && (int) $article_id > 0 ) {
			return 'betrieb';
		}
		return 'inventory';
	}

	/**
	 * Filter für das Inventory-Modul: Produkt beim Lagerabzug überspringen?
	 *
	 * @param bool $skip           Bisherige Entscheidung.
	 * @param int  $wc_product_id  Produkt-ID.
	 * @return bool
	 */
	public static function filter_skip_product( $skip, $wc_product_id ) {
		if ( $skip ) {
			return true;
		}

		$article_id = self::article_for_product( $wc_product_id );

		return 'betrieb' === self::decide( $article_id, self::is_active() );
	}
}
