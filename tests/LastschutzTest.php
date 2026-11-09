<?php
/**
 * Lastschutz vor einem Ansturm: Besucherzählung drosselbar, keine Altlast-Jobs,
 * Footer und Vertrauens-Chips passend zu Steuer und Versand.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Erp\Traffic;

class LastschutzTest extends TestCase {

	protected function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_meldeumfang_folgt_den_schaltern() {
		$this->assertSame( 'live', Traffic::mode( 1, 1 ) );
		$this->assertSame( 'aufruf', Traffic::mode( 1, 0 ) );
		$this->assertSame( 'aus', Traffic::mode( 0, 1 ), 'Ohne Zählung gibt es auch keinen Takt.' );
		$this->assertSame( 'aus', Traffic::mode( 0, 0 ) );
	}

	public function test_takt_nur_im_live_modus() {
		$src  = $this->read( 'includes/Erp/Traffic.php' );
		$beac = substr( $src, strpos( $src, 'public function beacon()' ) );

		$this->assertMatchesRegularExpression( "/'aus' === \\\$mode\s*\)\s*\{\s*return;/", $beac, 'Abgeschaltet gibt es kein Skript.' );
		$live = strpos( $beac, "'live' === \$mode" );
		$else = strpos( $beac, ": '' )", (int) $live );
		$tick = strpos( $beac, 'setInterval' );

		$this->assertNotFalse( $live );
		$this->assertNotFalse( $else );
		$this->assertTrue( $tick > $live && $tick < $else, 'Der 25-Sekunden-Takt hängt am Live-Modus.' );
	}

	public function test_meldestelle_verwirft_frueh() {
		$src     = $this->read( 'includes/Erp/Traffic.php' );
		$collect = substr( $src, strpos( $src, 'public function collect(' ) );
		$throttle = strpos( $collect, 'Throttle::hit' );

		$this->assertNotFalse( $throttle );
		$this->assertLessThan( $throttle, strpos( $collect, "'aus' === \$mode" ), 'Abgeschaltet: antworten, bevor der Drossel-Zähler schreibt.' );
		$this->assertLessThan( $throttle, strpos( $collect, "'aufruf' === \$mode" ), 'Ohne Takt: Wiedermeldungen verwerfen, bevor der Drossel-Zähler schreibt.' );
	}

	public function test_schalter_stehen_in_den_einstellungen() {
		$settings = $this->read( 'includes/Core/Settings.php' );
		$page     = $this->read( 'includes/Admin/SettingsPage.php' );

		foreach ( array( 'besucher_zaehlen', 'besucher_live_takt' ) as $key ) {
			$this->assertMatchesRegularExpression( "/'" . $key . "'\s*=>\s*1,/", $settings, $key . ' ist standardmäßig an.' );
			$this->assertMatchesRegularExpression( "/'" . $key . "'\s*=>\s*array\(.*'checkbox' \)/", $page, $key . ' ist als Häkchen einstellbar.' );
		}
	}

	public function test_nachkauf_job_wird_nicht_mehr_eingeplant() {
		$src       = $this->read( 'includes/Core/Scheduler.php' );
		$recurring = substr( $src, strpos( $src, '$recurring = array(' ) );
		$recurring = substr( $recurring, 0, strpos( $recurring, ');' ) );

		$this->assertStringNotContainsString( 'HOOK_POSTPURCHASE', $recurring, 'Niemand hört den Job ab; eingeplant scheitert er stündlich.' );
		$this->assertStringContainsString( 'as_unschedule_all_actions( self::HOOK_POSTPURCHASE', $src, 'Schon eingeplante Läufe werden entfernt.' );
	}

	public function test_footer_ohne_mwst_bei_kleinunternehmer() {
		$footer = $this->read( 'block-theme/lindenweg16/patterns/footer.php' );
		$fn     = $this->read( 'block-theme/lindenweg16/functions.php' );

		$this->assertStringNotContainsString( 'Alle Preise inkl. MwSt.', $footer, 'Der Text kommt aus lindenweg16_footer_price_note().' );
		$this->assertStringContainsString( 'lindenweg16_footer_price_note()', $footer );
		$this->assertStringContainsString( "'woocommerce_gzd_small_enterprise'", $fn );
		$this->assertStringContainsString( 'keine MwSt. gem. § 19 UStG', $fn );
	}

	public function test_vertrauens_chips_versprechen_nichts_falsches() {
		$bar     = $this->read( 'block-theme/lindenweg16/patterns/trust-bar.php' );
		$product = $this->read( 'block-theme/lindenweg16/patterns/hidden-product-trust.php' );
		$fn      = $this->read( 'block-theme/lindenweg16/functions.php' );

		$this->assertStringNotContainsString( 'ab 50', $bar, 'Die Schwelle kommt aus der Versandart.' );
		$this->assertStringContainsString( 'lindenweg16_free_shipping_threshold()', $bar );
		$this->assertStringNotContainsString( '30 Tage Rückgabe', $bar . $product, 'Die AGB kennen nur das gesetzliche Widerrufsrecht.' );
		$this->assertStringNotContainsString( 'SEPA', substr( $fn, strpos( $fn, 'function lindenweg16_default_payment_methods' ), 400 ), 'Die Kasse bietet keine Lastschrift an.' );
	}
	public function test_kasse_zeigt_die_checkboxen_von_germanized() {
		$tpl = $this->read( 'block-theme/lindenweg16/templates/page-checkout.html' );
		$inc = $this->read( 'block-theme/lindenweg16/inc/woocommerce.php' );

		$this->assertStringContainsString( '<!-- wp:woocommerce-germanized/checkout-checkboxes -->', $tpl, 'Nur dieser Block zeigt AGB und Widerrufsbelehrung aus Germanized.' );
		$this->assertStringNotContainsString( 'wp:woocommerce/checkout-terms-block', $tpl, 'Sonst stünden zwei Zustimmungen untereinander.' );
		$this->assertStringContainsString( "add_filter( 'render_block_woocommerce/checkout', 'lindenweg16_checkout_terms_fallback'", $inc, 'Ohne Germanized gilt wieder die AGB-Checkbox von WooCommerce.' );
	}
}
