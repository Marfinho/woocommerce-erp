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
 * Die Übernahme ist aktiv, seit Betrieb die Abgänge selbst bucht
 * (WooIntegration). Sie lässt sich in den Einstellungen abschalten, falls das
 * Lager-Modul die Hoheit zurückbekommen soll – dann bucht wieder ausschließlich
 * dieses.
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
	 * Produkt-ID => Artikel-ID fuer die Dauer eines Aufrufs.
	 *
	 * @var array<int,int>
	 */
	protected static $zuordnung = array();

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'lw16_inventory_skip_product', array( __CLASS__, 'filter_skip_product' ), 10, 2 );
		add_action( 'lw16_katalog_veraltet', array( __CLASS__, 'forget_map' ) );
	}

	/**
	 * Ist die Übernahme durch Betrieb aktiv?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return (bool) Settings::get( 'betrieb_lagerhoheit', 1 );
	}

	/**
	 * Kennt Betrieb ein WooCommerce-Produkt?
	 *
	 * @param int $wc_product_id Produkt-ID.
	 * @return int Artikel-ID oder 0.
	 */
	public static function article_for_product( $wc_product_id ) {
		$wc_product_id = (int) $wc_product_id;
		if ( ! $wc_product_id ) {
			return 0;
		}

		// Dieselbe Frage faellt in einem Aufruf oft mehrfach an: einmal je
		// Bestellposition, einmal je Produkt in der Nachbestellliste, einmal
		// je Zeile der Bedarfsplanung. Die Zuordnung aendert sich dabei
		// nicht – sie wird gemerkt, statt sie jedes Mal neu zu erfragen.
		if ( isset( self::$zuordnung[ $wc_product_id ] ) ) {
			return self::$zuordnung[ $wc_product_id ];
		}

		global $wpdb;

		$table = ArticleRepository::table();

		self::$zuordnung[ $wc_product_id ] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE wc_product_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wc_product_id
			)
		);

		return self::$zuordnung[ $wc_product_id ];
	}

	/**
	 * Die Zuordnung für viele Produkte in einer Abfrage.
	 *
	 * Bewusst ohne Rücksicht auf den Status des Artikels – gefragt ist,
	 * ob es überhaupt eine Verknüpfung gibt, nicht ob der Artikel noch
	 * angeboten wird. Ein stillgelegter Artikel ist verknüpft.
	 *
	 * @param int[] $wc_product_ids Produkt-IDs.
	 * @return array<int,int> Produkt-ID => Artikel-ID, nur für Treffer.
	 */
	public static function articles_for_products( array $wc_product_ids ) {
		$wc_product_ids = array_values( array_unique( array_filter( array_map( 'intval', $wc_product_ids ) ) ) );

		if ( ! $wc_product_ids ) {
			return array();
		}

		global $wpdb;

		$table       = ArticleRepository::table();
		$platzhalter = implode( ',', array_fill( 0, count( $wc_product_ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, wc_product_id FROM {$table} WHERE wc_product_id IN ({$platzhalter})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Platzhalter oben erzeugt, Werte gebunden.
				$wc_product_ids
			),
			ARRAY_A
		);

		$treffer = array();

		foreach ( (array) $rows as $row ) {
			$product_id = (int) $row['wc_product_id'];

			// Zeigen zwei Artikel auf dasselbe Produkt, gewinnt der erste –
			// wie beim LIMIT 1 der Einzelabfrage.
			if ( ! isset( $treffer[ $product_id ] ) ) {
				$treffer[ $product_id ] = (int) $row['id'];
			}
		}

		// Auch die Fehlanzeigen merken, damit eine spätere Einzelfrage
		// nach demselben Produkt nicht doch noch zur Datenbank geht.
		foreach ( $wc_product_ids as $product_id ) {
			self::$zuordnung[ $product_id ] = isset( $treffer[ $product_id ] ) ? $treffer[ $product_id ] : 0;
		}

		return $treffer;
	}

	/**
	 * Die gemerkte Zuordnung verwerfen.
	 *
	 * Noetig, sobald ein Artikel angelegt, geaendert oder geloescht wird –
	 * dann kann sich die Verknuepfung zum Shop-Produkt verschoben haben.
	 */
	public static function forget_map() {
		self::$zuordnung = array();
	}

	/**
	 * Kennt Betrieb ein Produkt der abgelösten Lagerverwaltung?
	 *
	 * Die Übernahme setzt legacy_produkt_id, sodass ein altes Produkt und
	 * sein Betriebsartikel dieselbe Sache bleiben. Ohne diese Brücke würden
	 * Kasse und Warteliste weiter in den eingefrorenen Altbestand buchen
	 * beziehungsweise ihn abfragen.
	 *
	 * @param int $legacy_id Produkt-ID der alten Lagerverwaltung.
	 * @return int Artikel-ID oder 0.
	 */
	public static function article_for_legacy_product( $legacy_id ) {
		global $wpdb;

		$legacy_id = (int) $legacy_id;
		if ( ! $legacy_id ) {
			return 0;
		}

		$table = ArticleRepository::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE legacy_produkt_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$legacy_id
			)
		);
	}

	/**
	 * Eine Bewegung an die zuständige Lagerverwaltung geben.
	 *
	 * Gemeinsame Weiche für alle Stellen außerhalb des Shops – heute Kasse,
	 * morgen was auch immer. Ohne sie trifft jede Stelle ihre eigene
	 * Entscheidung, und irgendwann trifft eine die falsche.
	 *
	 * @param array  $target     Ziel: article_id und/oder legacy_produkt_id.
	 * @param float  $qty        Menge, negativ für Abgang.
	 * @param string $type       Bewegungsart.
	 * @param array  $args       Zusatz: color, note, ref_type, ref_id.
	 * @return string 'betrieb' oder 'inventory' – wer gebucht hat.
	 */
	public static function book( array $target, $qty, $type, array $args = array() ) {
		$article_id = isset( $target['article_id'] ) ? (int) $target['article_id'] : 0;
		$legacy_id  = isset( $target['legacy_produkt_id'] ) ? (int) $target['legacy_produkt_id'] : 0;

		if ( ! $article_id && $legacy_id ) {
			$article_id = self::article_for_legacy_product( $legacy_id );
		}

		if ( 'betrieb' !== self::decide( $article_id, self::is_active() ) ) {
			return 'inventory';
		}

		StockRepository::book(
			array_merge(
				array(
					'article_id'    => $article_id,
					'qty'           => (float) $qty,
					'movement_type' => (string) $type,
					'location'      => 'lager',
				),
				$args
			)
		);

		return 'betrieb';
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
