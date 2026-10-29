<?php
/**
 * Shop-Bestellung im ERP: Rechte, Routen und die Rechnung der Erstattung.
 *
 * Die Erstattung gibt Geld heraus. Hier steht fest, dass sie nur mit eigenem
 * Recht geht, dass der Server den Betrag selbst rechnet und dass nie mehr
 * erstattet wird, als noch offen ist.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\ShopBestellung;
use Lindenweg16\Erp\Permissions;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	/**
	 * Nachkommastellen für die Tests.
	 *
	 * @return int
	 */
	function wc_get_price_decimals() {
		return 2;
	}
}

/**
 * Tests für ShopBestellung.
 */
class ErpShopBestellungTest extends TestCase {

	/**
	 * Positionszeile als Attrappe.
	 *
	 * @param float $total Netto.
	 * @param array $taxes Steuer je Satz.
	 * @param int   $qty   Stück.
	 * @return object
	 */
	protected function item( $total, array $taxes, $qty ) {
		return new class( $total, $taxes, $qty ) {
			public function __construct( public $total, public $taxes, public $qty ) {}
			public function get_name() {
				return 'Haken';
			}
			public function get_quantity() {
				return $this->qty;
			}
			public function get_total() {
				return $this->total;
			}
			public function get_taxes() {
				return array( 'total' => $this->taxes );
			}
		};
	}

	/**
	 * Bestellung als Attrappe.
	 *
	 * @param object $item         Position (ID 7).
	 * @param float  $total        Bestellsumme.
	 * @param float  $refunded     Schon erstattet (brutto).
	 * @param int    $qty_refunded Schon erstattete Stück.
	 * @param float  $net_refunded Schon erstattet netto.
	 * @param float  $tax_refunded Schon erstattet Steuer.
	 * @return object
	 */
	protected function order( $item, $total, $refunded = 0.0, $qty_refunded = 0, $net_refunded = 0.0, $tax_refunded = 0.0 ) {
		return new class( $item, $total, $refunded, $qty_refunded, $net_refunded, $tax_refunded ) {
			public function __construct( public $item, public $total, public $refunded, public $qty, public $net, public $tax ) {}
			public function get_item( $id ) {
				return 7 === (int) $id ? $this->item : null;
			}
			public function get_total() {
				return $this->total;
			}
			public function get_total_refunded() {
				return $this->refunded;
			}
			public function get_qty_refunded_for_item( $id ) {
				return -$this->qty;
			}
			public function get_total_refunded_for_item( $id, $type = 'line_item' ) {
				return $this->net;
			}
			public function get_tax_refunded_for_item( $id, $rate, $type = 'line_item' ) {
				return $this->tax;
			}
		};
	}

	public function test_zwei_neue_rechte_mit_voraussetzung() {
		$catalog = Permissions::catalog();

		foreach ( array( 'verkauf.shop_orders.status', 'verkauf.shop_orders.erstatten' ) as $key ) {
			$this->assertArrayHasKey( $key, $catalog );
			$this->assertSame( array( 'verkauf.shop_orders' ), $catalog[ $key ]['requires'] );
			$this->assertSame( Permissions::SCOPE_CAP, $catalog[ $key ]['scope'] );
		}
	}

	public function test_erstatten_ist_in_keiner_vorlage() {
		foreach ( Permissions::presets() as $name => $preset ) {
			if ( 'vollzugriff' === $name ) {
				continue;
			}
			$this->assertNotContains( 'verkauf.shop_orders.erstatten', $preset['keys'], "Die Vorlage $name gibt Geld heraus." );
		}
	}

	public function test_routen_haengen_an_den_richtigen_rechten() {
		$php = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/ShopBestellung.php' );

		$this->assertMatchesRegularExpression( "#/erp/shop-bestellung/erstattung.*?'verkauf.shop_orders.erstatten'#s", $php );
		$this->assertMatchesRegularExpression( "#/erp/shop-bestellung/status.*?'verkauf.shop_orders.status'#s", $php );
		$this->assertMatchesRegularExpression( "#/erp/shop-bestellung/notiz.*?'verkauf.shop_orders.status'#s", $php );
		$this->assertStringContainsString( "'refunded' !== \$slug", $php, '„Erstattet“ darf man nicht von Hand setzen.' );
		$this->assertStringContainsString( 'Lock::acquire', $php, 'Doppelklick darf nicht zweimal erstatten.' );
	}

	public function test_oberflaeche_ruft_die_routen() {
		$js = file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/verkauf.js' );

		$this->assertStringContainsString( "UI.routes['shop']", $js );
		$this->assertStringContainsString( 'shop-bestellung/erstattung', $js );
		$this->assertStringContainsString( 'shop-bestellung/status', $js );
		$this->assertStringContainsString( 'shop-bestellung/notiz', $js );
	}

	public function test_teilerstattung_rechnet_netto_und_steuer() {
		$order = $this->order( $this->item( 30.0, array( 1 => 5.70 ), 3 ), 35.70 );
		$plan  = ShopBestellung::plan_refund( $order, array( array( 'id' => 7, 'qty' => 1 ) ), 0 );

		$this->assertTrue( $plan['ok'] );
		$this->assertSame( 11.90, $plan['amount'] );
		$this->assertSame( 10.0, $plan['line_items'][7]['refund_total'] );
		$this->assertSame( 1.90, $plan['line_items'][7]['refund_tax'][1] );
	}

	public function test_der_rest_bekommt_den_restbetrag_ohne_rundungsfehler() {
		// Ein Stück schon erstattet (10,00 + 1,90): die letzten zwei nehmen den Rest.
		$order = $this->order( $this->item( 30.0, array( 1 => 5.70 ), 3 ), 35.70, 11.90, 1, 10.0, 1.90 );
		$plan  = ShopBestellung::plan_refund( $order, array( array( 'id' => 7, 'qty' => 2 ) ), 0 );

		$this->assertTrue( $plan['ok'] );
		$this->assertSame( 23.80, $plan['amount'] );
		$this->assertSame( 20.0, $plan['line_items'][7]['refund_total'] );
	}

	public function test_mehr_stueck_als_offen_wird_abgelehnt() {
		$order = $this->order( $this->item( 30.0, array( 1 => 5.70 ), 3 ), 35.70, 11.90, 1, 10.0, 1.90 );

		$this->assertFalse( ShopBestellung::plan_refund( $order, array( array( 'id' => 7, 'qty' => 3 ) ), 0 )['ok'] );
	}

	public function test_nie_mehr_als_erstattbar() {
		$order = $this->order( $this->item( 30.0, array( 1 => 5.70 ), 3 ), 35.70, 30.0 );
		$plan  = ShopBestellung::plan_refund( $order, array(), 10.00 );

		$this->assertFalse( $plan['ok'] );
		$this->assertStringContainsString( '5,70', $plan['message'] );
	}

	public function test_leere_erstattung_und_fremde_position() {
		$order = $this->order( $this->item( 30.0, array(), 3 ), 30.0 );

		$this->assertFalse( ShopBestellung::plan_refund( $order, array(), 0 )['ok'] );
		$this->assertFalse( ShopBestellung::plan_refund( $order, array( array( 'id' => 99, 'qty' => 1 ) ), 0 )['ok'], 'Eine fremde Position darf nicht erstattet werden.' );
	}

	public function test_freier_betrag_allein() {
		$order = $this->order( $this->item( 30.0, array(), 3 ), 30.0 );
		$plan  = ShopBestellung::plan_refund( $order, array(), 5.5 );

		$this->assertTrue( $plan['ok'] );
		$this->assertSame( 5.5, $plan['amount'] );
		$this->assertSame( array(), $plan['line_items'] );
	}
}
