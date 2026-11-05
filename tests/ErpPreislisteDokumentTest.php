<?php
/**
 * Händlerpreisliste als Dokument: Stufen, Ersparnis, Stand des Händlers, HTML.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\PriceListDocument;
use PHPUnit\Framework\TestCase;

class ErpPreislisteDokumentTest extends TestCase {

	private function tiers() {
		return array(
			array( 'name' => 'Kommission', 'min' => 500, 'pct' => 30 ),
			array( 'name' => 'Standard', 'min' => 1000, 'pct' => 35 ),
			array( 'name' => 'Vorzug', 'min' => 1500, 'pct' => 40 ),
			array( 'name' => 'Exklusiv', 'min' => 3000, 'pct' => 55 ),
		);
	}

	private function data( array $over = array() ) {
		return array_merge(
			array(
				'date'    => '2026-11-05',
				'vat'     => 0,
				'shop'    => 'Lindenweg16',
				'sender'  => array( 'name' => 'Lindenweg16', 'strasse' => 'Lindenweg 16', 'ort' => '31559 Haste', 'email' => 'info@lindenweg16.de', 'telefon' => '' ),
				'contact' => array( 'company' => 'Zweirad <Müller> & Söhne', 'person' => 'Anna Schmidt', 'address' => 'Hauptstr. 1, 31515 Wunstorf', 'vat_id' => '' ),
				'tiers'   => $this->tiers(),
				'days'    => 14,
				'source'  => 'standard',
				'rows'    => array( array( 'sku' => 'M-BHR', 'name' => 'Brillenhalter', 'uvp' => 18.0, 'dealer_net' => 18.0 ) ),
				'orders'  => array(),
			),
			$over
		);
	}

	public function test_stufen_zeigen_ersparnis_und_mehrertrag() {
		$steps = PriceListDocument::tier_steps( $this->tiers() );

		$this->assertSame( 150.0, $steps[0]['discount'] );
		$this->assertSame( 350.0, $steps[0]['pay'] );
		// Die erste Stufe gegen „kein Rabatt“: der ganze Rabatt ist der Mehrertrag.
		$this->assertSame( 150.0, $steps[0]['extra'] );
		// 1.000 € zu 35 % statt 30 %: 50 € mehr gespart.
		$this->assertSame( 50.0, $steps[1]['extra'] );
		// 3.000 € zu 55 % statt 40 %: 450 € mehr.
		$this->assertSame( 450.0, $steps[3]['extra'] );
		$this->assertSame( 1350.0, $steps[3]['pay'] );
	}

	public function test_stufen_werden_sortiert_und_bekommen_einen_namen() {
		$steps = PriceListDocument::tier_steps(
			array(
				array( 'min' => 800, 'pct' => 50 ),
				array( 'min' => 200, 'pct' => 40 ),
			)
		);

		$this->assertSame( array( 200.0, 800.0 ), array_column( $steps, 'min' ) );
		$this->assertSame( 'Stufe 1', $steps[0]['name'] );
	}

	public function test_nach_der_letzten_bestellung_fehlt_der_betrag_zur_naechsten_stufe() {
		$s = PriceListDocument::standing( $this->tiers(), 900.0 );

		$this->assertSame( 'Kommission', $s['current']['name'] );
		$this->assertSame( 'Standard', $s['next']['name'] );
		$this->assertSame( 100.0, $s['missing'] );
		// Bei 1.000 € bringt Standard 350 € statt 300 € Rabatt.
		$this->assertSame( 50.0, $s['gain'] );
	}

	public function test_unter_der_ersten_stufe_zaehlt_der_ganze_rabatt_als_gewinn() {
		$s = PriceListDocument::standing( $this->tiers(), 300.0 );

		$this->assertNull( $s['current'] );
		$this->assertSame( 'Kommission', $s['next']['name'] );
		$this->assertSame( 200.0, $s['missing'] );
		$this->assertSame( 150.0, $s['gain'] );
	}

	public function test_in_der_hoechsten_stufe_gibt_es_keine_naechste() {
		$s = PriceListDocument::standing( $this->tiers(), 3500.0 );

		$this->assertSame( 'Exklusiv', $s['current']['name'] );
		$this->assertNull( $s['next'] );
		$this->assertSame( 0.0, $s['gain'] );
	}

	public function test_ohne_bestellung_beginnt_alles_bei_der_ersten_stufe() {
		$s = PriceListDocument::standing( $this->tiers(), null );

		$this->assertNull( $s['current'] );
		$this->assertSame( 'Kommission', $s['next']['name'] );
		$this->assertSame( 500.0, $s['missing'] );
	}

	public function test_bezugswert_rechnet_den_rabatt_der_letzten_bestellung_zurueck() {
		$orders = array(
			array( 'value' => 700.0, 'pct' => 30.0 ),
			array( 'value' => 5000.0, 'pct' => 0.0 ),
		);

		// Nur die neueste (erste) zählt: 700 € nach 30 % Rabatt = 1.000 € vor Rabatt.
		$this->assertSame( 1000.0, PriceListDocument::reference_value( $orders ) );
		$this->assertSame( 200.0, PriceListDocument::reference_value( array( array( 'value' => 200.0, 'pct' => 0.0 ) ) ) );
		$this->assertNull( PriceListDocument::reference_value( array() ) );
	}

	public function test_stufenname_nur_bei_eindeutigem_satz() {
		$steps = PriceListDocument::tier_steps( $this->tiers() );

		$this->assertSame( 'Vorzug', PriceListDocument::tier_label( 40.0, $steps ) );
		$this->assertSame( '', PriceListDocument::tier_label( 12.5, $steps ) );
		$this->assertSame( '', PriceListDocument::tier_label( 0.0, $steps ) );
	}

	public function test_formate() {
		$this->assertSame( '1.350 €', PriceListDocument::eur( 1350 ) );
		$this->assertSame( '35,20 €', PriceListDocument::eur( 35.2 ) );
		$this->assertSame( '30 %', PriceListDocument::pct( 30 ) );
		$this->assertSame( '12,5 %', PriceListDocument::pct( 12.5 ) );
		$this->assertSame( '05.11.2026', PriceListDocument::dmy( '2026-11-05' ) );
	}

	public function test_anrede_nur_bei_einer_person_mit_namen() {
		$this->assertSame( 'Guten Tag Anna Schmidt,', PriceListDocument::greeting( 'Anna Schmidt' ) );
		$this->assertSame( 'Guten Tag,', PriceListDocument::greeting( '' ) );
		$this->assertSame( 'Guten Tag,', PriceListDocument::greeting( 'René Niepold, Kevin Niepold' ) );
		$this->assertSame( 'Guten Tag,', PriceListDocument::greeting( 'Max und Moritz' ) );
	}

	public function test_dokument_fuer_neukunden() {
		$html = PriceListDocument::render( $this->data() );

		$this->assertStringContainsString( 'Guten Tag Anna Schmidt,', $html );
		$this->assertStringNotContainsString( 'Ihr Stand bei uns', $html );
		$this->assertStringContainsString( 'Schon ab 500 € Bestellwert', $html );
		$this->assertStringContainsString( 'Zahlungsziel: 14 Tage netto.', $html );
		$this->assertStringContainsString( 'Kleinunternehmer nach § 19 UStG', $html );
		// Eingebetteter Text wird maskiert.
		$this->assertStringContainsString( 'Zweirad &lt;Müller&gt; &amp; Söhne', $html );
		$this->assertStringNotContainsString( '<Müller>', $html );
	}

	public function test_dokument_fuer_bestandskunden_zeigt_historie_stufe_und_naechsten_schritt() {
		$html = PriceListDocument::render(
			$this->data(
				array(
					'orders' => array(
						array( 'number' => '#1042', 'date' => '2026-11-02', 'value' => 690.0, 'pct' => 30.0 ),
						array( 'number' => 'A-7', 'date' => '2026-10-10', 'value' => 310.0, 'pct' => 0.0 ),
					),
				)
			)
		);

		$this->assertStringContainsString( 'Ihr Stand bei uns', $html );
		$this->assertStringContainsString( 'vielen Dank für Ihre bisherigen 2 Bestellungen', $html );
		$this->assertStringContainsString( '#1042', $html );
		$this->assertStringContainsString( '02.11.2026', $html );
		$this->assertStringContainsString( 'Ihre letzte Stufe', $html );
		$this->assertStringContainsString( 'Stufe „Kommission“', $html );
		// 690 € nach 30 % = rund 985,71 € vor Rabatt; bis 1.000 € fehlen 14,29 €.
		$this->assertStringContainsString( 'Nur 14,29 € mehr Bestellwert', $html );
	}

	public function test_dokument_in_hoechster_stufe() {
		$html = PriceListDocument::render(
			$this->data( array( 'orders' => array( array( 'number' => '#9', 'date' => '2026-11-03', 'value' => 1350.0, 'pct' => 55.0 ) ) ) )
		);

		$this->assertStringContainsString( 'Sie sind in unserer höchsten Stufe', $html );
	}

	public function test_mit_umsatzsteuer_stehen_brutto_und_netto_in_den_spaltenkoepfen() {
		$html = PriceListDocument::render( $this->data( array( 'vat' => 0.19 ) ) );

		$this->assertStringContainsString( '<small>brutto</small>', $html );
		$this->assertStringContainsString( 'zuzüglich 19 % Umsatzsteuer', $html );
	}

	public function test_dokument_steht_allein_mit_schriften_im_html() {
		$html = PriceListDocument::render( $this->data(), array( 'inter' => 'QUJD' ) );

		$this->assertStringContainsString( 'data:font/woff2;base64,QUJD', $html );
		$this->assertStringContainsString( '#151515', $html );
		$this->assertStringContainsString( '#EC1443', $html );
		$this->assertStringContainsString( '@page{size:A4;margin:0}', $html );
	}

	public function test_auslieferung_prueft_nonce_und_recht_und_steht_in_der_aktionskarte() {
		$map = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/ActionMap.php' );
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/PriceListDocument.php' );
		$sh  = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Shell.php' );

		$this->assertStringContainsString( "'lw16_erp_pricelist'          => array( 'verkauf.kontakte' )", $map );
		$this->assertStringContainsString( "check_admin_referer( self::ACTION . '_' . \$id )", $src );
		$this->assertStringContainsString( "Access::can( 'verkauf.kontakte' )", $src );
		$this->assertStringContainsString( "add_action( 'admin_post_lw16_erp_pricelist'", $sh );
	}
}
