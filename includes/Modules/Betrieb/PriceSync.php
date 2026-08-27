<?php
/**
 * Preisübergabe an das Shop-Produkt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;

/**
 * Der Preis eines Artikels steht im Betrieb – und muss im Shop stehen.
 *
 * Bis hierher gab es dafür eine eigene Seite, auf der man den Preis noch
 * einmal von Hand „übernahm". Zwei Wahrheiten für dieselbe Zahl: Wer den
 * Preis am Artikel änderte und den zweiten Schritt vergaß, verkaufte
 * weiter zum alten. Jetzt zieht der Shop nach, sobald der Preis am
 * Artikel gespeichert wird.
 *
 * Geschrieben wird ausschliesslich der Normalpreis. Ein laufendes Angebot
 * in WooCommerce bleibt unberührt – sonst wäre eine Preisaktion mit einem
 * Klick weg, ohne dass es jemand merkt.
 */
class PriceSync {

	/**
	 * Ergebnis der letzten Übertragung, für die Rückmeldung im Formular.
	 *
	 * @var array|null
	 */
	protected static $last = null;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_article_saved', array( $this, 'on_article_saved' ), 10, 3 );
	}

	/**
	 * Nach dem Speichern eines Artikels den Shop nachziehen.
	 *
	 * @param int        $article_id Artikel.
	 * @param array      $after      Gespeicherte Felder.
	 * @param array|null $before     Stand davor; null beim Anlegen.
	 */
	public function on_article_saved( $article_id, array $after, $before = null ) {
		if ( ! self::needs_push( is_array( $before ) ? $before : null, $after ) ) {
			return;
		}

		self::$last = self::push( (int) $article_id );
	}

	/**
	 * Ergebnis der letzten Übertragung abholen (und vergessen).
	 *
	 * @return array|null
	 */
	public static function take_last() {
		$last       = self::$last;
		self::$last = null;

		return $last;
	}

	/**
	 * Muss überhaupt etwas übertragen werden? Reine Logik.
	 *
	 * Ohne diese Prüfung schriebe jedes Speichern eines Artikels das
	 * Shop-Produkt neu – auch wenn nur eine Notiz geändert wurde. Das
	 * wäre eine Produktänderung pro Tippfehler, samt Eintrag in jedem
	 * Protokoll, das an WooCommerce hängt.
	 *
	 * @param array|null $before Stand davor; null beim Anlegen.
	 * @param array      $after  Gespeicherte Felder.
	 * @return bool
	 */
	public static function needs_push( $before, array $after ) {
		if ( ! array_key_exists( 'price_gross', $after ) && ! array_key_exists( 'wc_product_id', $after ) ) {
			return false;
		}

		// Eine Teiländerung schickt nur die Felder mit, die sie ändert -
		// die Preisübernahme aus der Kalkulation etwa nur den Preis. Die
		// Verknüpfung steht dann allein im Stand davor; ohne diesen
		// Rückgriff bliebe genau der Preis liegen, der gerade gesetzt
		// wurde.
		$wc_id = array_key_exists( 'wc_product_id', $after )
			? (int) $after['wc_product_id']
			: (int) ( is_array( $before ) && isset( $before['wc_product_id'] ) ? $before['wc_product_id'] : 0 );

		if ( ! $wc_id ) {
			return false;
		}

		if ( null === $before ) {
			return true;
		}

		// Erst jetzt verknüpft: Das Produkt kennt den Preis des Artikels
		// noch gar nicht.
		if ( array_key_exists( 'wc_product_id', $after )
			&& (int) ( isset( $before['wc_product_id'] ) ? $before['wc_product_id'] : 0 ) !== $wc_id ) {
			return true;
		}

		if ( ! array_key_exists( 'price_gross', $after ) ) {
			return false;
		}

		return self::differs(
			isset( $before['price_gross'] ) ? $before['price_gross'] : null,
			$after['price_gross']
		);
	}

	/**
	 * Unterscheiden sich zwei Preise? Reine Logik.
	 *
	 * Verglichen wird auf den Cent. Ein Vergleich der Zeichenketten
	 * meldete „12.90" und „12.9000" als Unterschied und schriebe das
	 * Produkt bei jedem Speichern neu.
	 *
	 * @param mixed $a Erster Preis.
	 * @param mixed $b Zweiter Preis.
	 * @return bool
	 */
	public static function differs( $a, $b ) {
		$leer_a = null === $a || '' === $a;
		$leer_b = null === $b || '' === $b;

		if ( $leer_a || $leer_b ) {
			return $leer_a !== $leer_b;
		}

		return abs( round( (float) $a, 2 ) - round( (float) $b, 2 ) ) >= 0.005;
	}

	/**
	 * Preis eines Artikels ins Shop-Produkt schreiben.
	 *
	 * @param int $article_id Artikel.
	 * @return array {status, message}
	 */
	public static function push( $article_id ) {
		$article = ArticleRepository::find( (int) $article_id );

		if ( ! $article ) {
			return self::result( 'unbekannt', __( 'Artikel nicht gefunden.', 'lindenweg16' ) );
		}

		$wc_id = isset( $article['wc_product_id'] ) ? (int) $article['wc_product_id'] : 0;

		if ( ! $wc_id ) {
			return self::result( 'ohne_shop', __( 'Kein Shop-Produkt verknüpft – der Preis bleibt im Betrieb.', 'lindenweg16' ) );
		}

		$preis = isset( $article['price_gross'] ) ? $article['price_gross'] : null;

		if ( null === $preis || (float) $preis <= 0 ) {
			return self::result( 'ohne_preis', __( 'Kein Bruttopreis am Artikel – nichts zu übertragen.', 'lindenweg16' ) );
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $wc_id ) : null;

		if ( ! $product ) {
			return self::result( 'fehlt', __( 'Das verknüpfte Shop-Produkt gibt es nicht mehr.', 'lindenweg16' ) );
		}

		if ( ! self::differs( $product->get_regular_price(), $preis ) ) {
			return self::result( 'gleich', __( 'Der Shop steht bereits auf diesem Preis.', 'lindenweg16' ) );
		}

		$product->set_regular_price( (string) round( (float) $preis, 2 ) );
		$product->save();

		self::store_margin( (int) $article['id'], $wc_id, (float) $preis );

		AuditLog::record( 'preis_an_shop', 'article', (int) $article['id'], array( 'preis' => (float) $preis, 'wc_product_id' => $wc_id ) );

		return self::result(
			'uebertragen',
			sprintf(
				/* translators: 1: Artikelnummer, 2: Preis. */
				__( '%1$s: %2$s € in den Shop übertragen.', 'lindenweg16' ),
				$article['sku'],
				number_format_i18n( (float) $preis, 2 )
			)
		);
	}

	/**
	 * Alle verkäuflichen Artikel in den Shop schieben.
	 *
	 * Für den Fall, dass sich nicht ein Preis geändert hat, sondern eine
	 * Annahme darunter – dann stimmen auf einen Schlag viele nicht mehr.
	 *
	 * @return array Anzahl je Ergebnis.
	 */
	public static function push_all() {
		$zaehler = array();

		foreach ( ArticleRepository::selectable( true ) as $article ) {
			$status = self::push( (int) $article['id'] )['status'];

			$zaehler[ $status ] = isset( $zaehler[ $status ] ) ? $zaehler[ $status ] + 1 : 1;
		}

		return $zaehler;
	}

	/**
	 * Erreichte Marge am Produkt hinterlegen.
	 *
	 * Das Empfehlungsprogramm leitet die Prämie daraus ab. Stünde dort
	 * die Marge eines alten Preises, zahlte es an der neuen Kalkulation
	 * vorbei.
	 *
	 * @param int   $article_id Artikel.
	 * @param int   $wc_id      Shop-Produkt.
	 * @param float $preis      Bruttopreis.
	 */
	protected static function store_margin( $article_id, $wc_id, $preis ) {
		$settings = CalculationContext::settings();
		$cost     = CostCalculator::manufacturing_cost( $article_id, CalculationContext::build(), $settings );
		$ergebnis = CostCalculator::contribution_from_cost( $cost, $preis, $settings );

		update_post_meta( $wc_id, '_lw16_margin_percent', round( $ergebnis['db2_pct'] * 100, 2 ) );
	}

	/**
	 * Ergebnis bauen.
	 *
	 * @param string $status  Kennung.
	 * @param string $message Meldung.
	 * @return array
	 */
	protected static function result( $status, $message ) {
		return array(
			'status'  => $status,
			'message' => $message,
		);
	}
}
