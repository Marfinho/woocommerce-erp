<?php
/**
 * Herstellkosten und Deckungsbeitrag, geprüft gegen die Datenerfassungsmappe.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\CostCalculator;

/**
 * Kalkulation gedruckter Artikel, Zukaufartikel und Baugruppen.
 */
class BetriebCostCalculatorTest extends TestCase {

	/**
	 * Annahmen der Mappe. machine_rate_per_h kommt in der Anwendung aus
	 * MachineRate; hier wird der Wert der Mappe gesetzt, damit die
	 * Kalibrierung vergleichbar bleibt.
	 *
	 * @return array
	 */
	protected function settings() {
		return array(
			'filament_price_per_kg' => 22.0,
			'machine_rate_per_h'    => 0.20,
			'labor_rate_per_h'      => 25.0,
			'payment_fee_pct'       => 0.0249,
			'payment_fee_fixed'     => 0.35,
			'packaging_cost'        => 0.75,
			'scrap_surcharge_pct'   => 0.05,
			'vat_rate'              => 0.0,
		);
	}

	/**
	 * HH-001 aus Blatt 01 der Mappe: 55 g, 9 h Platte, 6 Teile, 7 min
	 * Nachbearbeitung, VK 19,90 €.
	 *
	 * @param array $bom Optionale Stückliste.
	 * @return array
	 */
	protected function ctx_hh001( array $bom = array() ) {
		return array(
			'articles'   => array(
				1 => array(
					'id'                 => 1,
					'sku'                => 'HH-001',
					'name'               => 'Haster Haken Classic',
					'sourcing_type'      => 'druck',
					'price_gross'        => 19.90,
					'dealer_price'       => 9.95,
					'postprocessing_min' => 7,
					'load_rating_kg'     => 25,
				),
			),
			'revisions'  => array(
				1 => array(
					'article_id'         => 1,
					'version'            => 'v1.0',
					'weight_g'           => 55,
					'parts_per_plate'    => 6,
					'print_time_plate_h' => 9,
				),
			),
			'components' => array(),
			'bom'        => $bom,
			'sourcing'   => array(),
		);
	}

	/**
	 * Stückpreis kommt aus der Gebindegröße, nicht aus einem gepflegten Feld.
	 */
	public function test_stueckpreis_aus_gebindegroesse() {
		$this->assertEqualsWithDelta(
			0.043,
			CostCalculator::unit_price( array( 'pack_price_net' => 4.30, 'pack_qty' => 100 ) ),
			0.00001
		);
	}

	/**
	 * Gebindegröße 0 darf nicht durch null teilen.
	 */
	public function test_stueckpreis_ohne_gebindegroesse() {
		$this->assertSame( 0.0, CostCalculator::unit_price( array( 'pack_price_net' => 4.30, 'pack_qty' => 0 ) ) );
	}

	/**
	 * Druckzeit je Teil ergibt sich aus der Platte.
	 */
	public function test_druckzeit_je_teil() {
		$this->assertEqualsWithDelta(
			1.5,
			CostCalculator::print_time_per_part( array( 'print_time_plate_h' => 9, 'parts_per_plate' => 6 ) ),
			0.00001
		);
	}

	/**
	 * Material und Maschine reproduzieren die Mappe exakt.
	 */
	public function test_material_und_maschine_wie_in_der_mappe() {
		$cost = CostCalculator::manufacturing_cost( 1, $this->ctx_hh001(), $this->settings() );

		$this->assertEqualsWithDelta( 1.21, $cost['eigenmaterial'], 0.00001 );
		$this->assertEqualsWithDelta( 0.30, $cost['eigenmaschine'], 0.00001 );
		$this->assertEqualsWithDelta( 1.51, $cost['herstellkosten'], 0.00001 );
	}

	/**
	 * Kalibrierung gegen Blatt 02 der Mappe.
	 *
	 * Die Mappe rechnet mit einer Pauschale „Montagematerial" von 0,55 € und
	 * legt den Ausschusszuschlag nur auf Material und Maschine. Diese
	 * Umsetzung ersetzt die Pauschale durch die Stückliste und legt den
	 * Zuschlag auf die vollen Herstellkosten – ein Ausschussteil vernichtet
	 * die eingesetzten Kleinteile mit, nicht nur das Filament.
	 *
	 * Daraus folgt eine bewusste Abweichung von drei Cent:
	 *   Mappe:   DB I 16,17 €   DB II 13,25 €
	 *   hier:    DB I 16,14 €   DB II 13,22 €
	 */
	public function test_kalibrierung_hh001_gegen_die_mappe() {
		$ctx = $this->ctx_hh001(
			array(
				// Kleinteile im Wert der bisherigen Pauschale von 0,55 €.
				1 => array(
					array( 'child_type' => 'komponente', 'child_id' => 10, 'qty' => 1 ),
					array( 'child_type' => 'komponente', 'child_id' => 11, 'qty' => 1 ),
				),
			)
		);
		$ctx['components'] = array(
			10 => array( 'id' => 10, 'code' => 'GEW-M4', 'name' => 'Gewindeeinsatz M4', 'pack_price_net' => 18.00, 'pack_qty' => 50 ),
			11 => array( 'id' => 11, 'code' => 'SCHR-M4-20', 'name' => 'Klemmschraube M4x20', 'pack_price_net' => 19.00, 'pack_qty' => 100 ),
		);

		$cost = CostCalculator::manufacturing_cost( 1, $ctx, $this->settings() );

		// 18/50 = 0,36 + 19/100 = 0,19 → 0,55 wie die Pauschale der Mappe.
		$this->assertEqualsWithDelta( 0.55, $cost['komponenten'], 0.00001 );
		$this->assertEqualsWithDelta( 2.06, $cost['herstellkosten'], 0.00001 );

		$db = CostCalculator::contribution( 1, $ctx, $this->settings() );

		$this->assertEqualsWithDelta( 19.90, $db['netto_erloes'], 0.00001, 'Kleinunternehmer: brutto = netto.' );
		$this->assertEqualsWithDelta( 0.103, $db['ausschuss'], 0.00001, 'Zuschlag auf die vollen Herstellkosten.' );
		$this->assertEqualsWithDelta( 0.84551, $db['zahlung'], 0.00001 );
		$this->assertEqualsWithDelta( 16.1415, $db['db1'], 0.0001 );
		$this->assertEqualsWithDelta( 2.91667, $db['handling'], 0.0001 );
		$this->assertEqualsWithDelta( 13.2248, $db['db2'], 0.0001 );
	}

	/**
	 * Die Formel der Mappe – Pauschale statt Stückliste, Zuschlag nur auf
	 * Material und Maschine – trifft deren Werte auf den Cent. Der Test hält
	 * fest, dass die Abweichung oben eine Entscheidung ist und kein Fehler.
	 */
	public function test_formel_der_mappe_trifft_deren_werte() {
		$material  = 55 / 1000 * 22.0;
		$maschine  = 1.5 * 0.20;
		$ausschuss = ( $material + $maschine ) * 0.05;
		$montage   = 0.55;
		$verpackung = 0.75;
		$zahlung   = 19.90 * 0.0249 + 0.35;

		$db1 = 19.90 - ( $material + $maschine + $ausschuss + $montage + $verpackung + $zahlung );
		$db2 = $db1 - 7 / 60 * 25.0;

		$this->assertEqualsWithDelta( 16.17, $db1, 0.005 );
		$this->assertEqualsWithDelta( 13.25, $db2, 0.005 );
	}

	/**
	 * Ein Zukaufartikel hat keine Druckzeit und kein Filamentgewicht. Die
	 * Kosten kommen aus der Beschaffung – sonst rechnet die Kalkulation für
	 * die Holzstange über das Filamentgewicht und liefert Unsinn.
	 */
	public function test_zukaufartikel_rechnet_ueber_die_beschaffung() {
		$ctx = array(
			'articles'   => array(
				2 => array(
					'id'                 => 2,
					'sku'                => 'HH-100',
					'name'               => 'Holzstange 100 cm',
					'sourcing_type'      => 'zukauf',
					'price_gross'        => 24.90,
					'postprocessing_min' => 0,
				),
			),
			'revisions'  => array(),
			'components' => array(),
			'bom'        => array(),
			'sourcing'   => array(
				2 => array(
					'article_id'         => 2,
					'purchase_price_net' => 6.40,
					'own_processing_min' => 4,
					'lead_time_days'     => 10,
				),
			),
		);

		$cost = CostCalculator::manufacturing_cost( 2, $ctx, $this->settings() );

		$this->assertEqualsWithDelta( 6.40, $cost['eigenmaterial'], 0.00001 );
		$this->assertSame( 0.0, $cost['eigenmaschine'], 'Kein Druck, keine Maschinenzeit.' );
		$this->assertSame( 0.0, $cost['print_time_h'] );
		$this->assertEqualsWithDelta( 4.0, $cost['postprocessing_min'], 0.00001, 'Eigene Nacharbeit zählt als Handling.' );
		$this->assertEmpty( $cost['errors'] );
	}

	/**
	 * Fehlen die Beschaffungsdaten, wird das benannt statt mit 0 gerechnet.
	 */
	public function test_zukaufartikel_ohne_beschaffungsdaten_meldet_das() {
		$ctx = array(
			'articles'   => array( 2 => array( 'id' => 2, 'sku' => 'HH-100', 'sourcing_type' => 'zukauf' ) ),
			'revisions'  => array(),
			'components' => array(),
			'bom'        => array(),
			'sourcing'   => array(),
		);

		$cost = CostCalculator::manufacturing_cost( 2, $ctx, $this->settings() );

		$this->assertNotEmpty( $cost['errors'] );
		$this->assertSame( 'missing_sourcing', $cost['errors'][0]['type'] );
	}

	/**
	 * Hybridartikel addieren beide Wege: Zukaufteil plus gedruckte Anbauteile.
	 */
	public function test_hybridartikel_addiert_beide_wege() {
		$ctx = array(
			'articles'   => array(
				3 => array( 'id' => 3, 'sku' => 'HH-200', 'sourcing_type' => 'hybrid', 'postprocessing_min' => 2 ),
			),
			'revisions'  => array(
				3 => array( 'article_id' => 3, 'weight_g' => 20, 'parts_per_plate' => 4, 'print_time_plate_h' => 2 ),
			),
			'components' => array(),
			'bom'        => array(),
			'sourcing'   => array(
				3 => array( 'article_id' => 3, 'purchase_price_net' => 3.00, 'own_processing_min' => 5 ),
			),
		);

		$cost = CostCalculator::manufacturing_cost( 3, $ctx, $this->settings() );

		// Material: 20 g = 0,44 € Filament + 3,00 € Zukauf.
		$this->assertEqualsWithDelta( 3.44, $cost['eigenmaterial'], 0.00001 );
		// Maschine: 2 h / 4 Teile = 0,5 h × 0,20.
		$this->assertEqualsWithDelta( 0.10, $cost['eigenmaschine'], 0.00001 );
		// Nachbearbeitung: 2 am Artikel + 5 aus der Beschaffung.
		$this->assertEqualsWithDelta( 7.0, $cost['postprocessing_min'], 0.00001 );
	}

	/**
	 * Die Stange als Baugruppe: Zukaufteil plus gedruckte Unterbaugruppen
	 * plus Kleinteile. Die Kosten lösen sich rekursiv auf.
	 */
	public function test_baugruppe_loest_sich_rekursiv_auf() {
		$ctx = $this->ctx_stange();

		$cost = CostCalculator::manufacturing_cost( 100, $ctx, $this->settings() );

		// Holzstange 6,40 € als eigenes Material des Wurzelartikels.
		$this->assertEqualsWithDelta( 6.40, $cost['eigenmaterial'], 0.00001 );

		// Unterbaugruppen: 2 Abstandshalter + 2 Wandanker.
		// Abstandshalter: 12 g = 0,264 € + 0,25 h × 0,20 = 0,05 → 0,314.
		// Wandanker:      18 g = 0,396 € + 0,40 h × 0,20 = 0,08 → 0,476.
		$this->assertEqualsWithDelta( 2 * 0.314 + 2 * 0.476, $cost['unterbaugruppen'], 0.00001 );

		// Kleinteile: 4 Holzschrauben à 0,03 + 4 Dübel à 0,08 + 4 M4x80 à 0,12 + 1 Bit à 0,45.
		$this->assertEqualsWithDelta( 4 * 0.03 + 4 * 0.08 + 4 * 0.12 + 0.45, $cost['komponenten'], 0.00001 );

		// Druckzeit summiert sich über die Unterbaugruppen.
		$this->assertEqualsWithDelta( 2 * 0.25 + 2 * 0.40, $cost['print_time_h'], 0.00001 );

		$this->assertEmpty( $cost['errors'] );
	}

	/**
	 * Bei einer Baugruppe treiben die Kleinteile die Kosten, nicht das
	 * Filament. Genau dafür gibt es die aufgeklappte Positionsliste.
	 */
	public function test_positionsliste_weist_jede_zeile_aus() {
		$cost = CostCalculator::manufacturing_cost( 100, $this->ctx_stange(), $this->settings() );

		$this->assertCount( 6, $cost['positionen'], '2 Unterbaugruppen + 4 Komponentenzeilen.' );

		$komponenten = array_sum(
			array_map(
				static function ( $p ) {
					return 'komponente' === $p['type'] ? $p['cost'] : 0;
				},
				$cost['positionen']
			)
		);
		$this->assertEqualsWithDelta( 1.37, $komponenten, 0.00001 );
		$this->assertGreaterThan( $cost['eigenmaschine'], $komponenten, 'Kleinteile kosten mehr als die Maschinenzeit.' );
	}

	/**
	 * Händlerpreis als Basis liefert einen anderen Deckungsbeitrag.
	 */
	public function test_haendlerpreis_als_basis() {
		$uvp      = CostCalculator::contribution( 1, $this->ctx_hh001(), $this->settings(), 'uvp' );
		$haendler = CostCalculator::contribution( 1, $this->ctx_hh001(), $this->settings(), 'haendler' );

		$this->assertEqualsWithDelta( 19.90, $uvp['price_gross'], 0.00001 );
		$this->assertEqualsWithDelta( 9.95, $haendler['price_gross'], 0.00001 );
		$this->assertLessThan( $uvp['db2'], $haendler['db2'] );
	}

	/**
	 * Mit Regelbesteuerung sinkt der Nettoerlös entsprechend.
	 */
	public function test_umsatzsteuer_mindert_den_nettoerloes() {
		$settings             = $this->settings();
		$settings['vat_rate'] = 0.19;

		$db = CostCalculator::contribution( 1, $this->ctx_hh001(), $settings );

		$this->assertEqualsWithDelta( 16.7227, $db['netto_erloes'], 0.0001 );
	}

	/**
	 * Fehlt die Revision eines gedruckten Artikels, wird das gemeldet.
	 */
	public function test_fehlende_revision_wird_gemeldet() {
		$ctx              = $this->ctx_hh001();
		$ctx['revisions'] = array();

		$cost = CostCalculator::manufacturing_cost( 1, $ctx, $this->settings() );

		$this->assertNotEmpty( $cost['errors'] );
		$this->assertSame( 'missing_revision', $cost['errors'][0]['type'] );
	}

	/**
	 * Die Kalkulation hält dieselbe Tiefengrenze ein wie die Auflösung:
	 * fünf Kindebenen sind zulässig, die sechste bricht ab.
	 */
	public function test_tiefengrenze_stimmt_mit_der_aufloesung_ueberein() {
		$tief = $this->ctx_kette( 5 );
		$this->assertEmpty(
			CostCalculator::manufacturing_cost( 1, $tief, $this->settings() )['errors'],
			'Fünf Ebenen müssen durchlaufen.'
		);

		$zu_tief = $this->ctx_kette( 6 );
		$errors  = CostCalculator::manufacturing_cost( 1, $zu_tief, $this->settings() )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'depth', $errors[0]['type'] );
	}

	/**
	 * Kette aus ineinander verschachtelten Artikeln bauen.
	 *
	 * @param int $levels Anzahl der Kindebenen.
	 * @return array
	 */
	protected function ctx_kette( $levels ) {
		$ctx = array(
			'articles'   => array(),
			'revisions'  => array(),
			'components' => array(),
			'bom'        => array(),
			'sourcing'   => array(),
		);

		for ( $i = 1; $i <= $levels + 1; $i++ ) {
			$ctx['articles'][ $i ] = array(
				'id'                 => $i,
				'sku'                => 'A-' . $i,
				'sourcing_type'      => 'druck',
				'postprocessing_min' => 0,
			);
			$ctx['revisions'][ $i ] = array(
				'article_id'         => $i,
				'weight_g'           => 10,
				'parts_per_plate'    => 1,
				'print_time_plate_h' => 1,
			);
			if ( $i <= $levels ) {
				$ctx['bom'][ $i ] = array(
					array( 'child_type' => 'artikel', 'child_id' => $i + 1, 'qty' => 1 ),
				);
			}
		}

		return $ctx;
	}

	/**
	 * Kontext der Stange als Verkaufsprodukt.
	 *
	 * @return array
	 */
	protected function ctx_stange() {
		return array(
			'articles'   => array(
				100 => array( 'id' => 100, 'sku' => 'HH-STANGE', 'name' => 'Stange 100 cm', 'sourcing_type' => 'zukauf', 'price_gross' => 34.90, 'postprocessing_min' => 6 ),
				101 => array( 'id' => 101, 'sku' => 'HH-ABST', 'name' => 'Abstandshalter', 'sourcing_type' => 'druck', 'sellable' => 0, 'postprocessing_min' => 0 ),
				102 => array( 'id' => 102, 'sku' => 'HH-ANKR', 'name' => 'Wandanker', 'sourcing_type' => 'druck', 'sellable' => 0, 'postprocessing_min' => 0 ),
			),
			'revisions'  => array(
				101 => array( 'article_id' => 101, 'weight_g' => 12, 'parts_per_plate' => 8, 'print_time_plate_h' => 2 ),
				102 => array( 'article_id' => 102, 'weight_g' => 18, 'parts_per_plate' => 10, 'print_time_plate_h' => 4 ),
			),
			'components' => array(
				20 => array( 'id' => 20, 'code' => 'SCHR-3x10', 'name' => 'Holzschraube 3x10', 'pack_price_net' => 3.00, 'pack_qty' => 100 ),
				21 => array( 'id' => 21, 'code' => 'DUE-DUO', 'name' => 'Fischer DuoPower', 'pack_price_net' => 8.00, 'pack_qty' => 100 ),
				22 => array( 'id' => 22, 'code' => 'SCHR-M4-80', 'name' => 'Schraube M4x80', 'pack_price_net' => 12.00, 'pack_qty' => 100 ),
				23 => array( 'id' => 23, 'code' => 'BIT-TX', 'name' => 'Torx-Bit', 'pack_price_net' => 4.50, 'pack_qty' => 10 ),
			),
			'bom'        => array(
				100 => array(
					array( 'child_type' => 'artikel', 'child_id' => 101, 'qty' => 2, 'position' => 1 ),
					array( 'child_type' => 'artikel', 'child_id' => 102, 'qty' => 2, 'position' => 2 ),
					array( 'child_type' => 'komponente', 'child_id' => 20, 'qty' => 4, 'position' => 3 ),
					array( 'child_type' => 'komponente', 'child_id' => 21, 'qty' => 4, 'position' => 4 ),
					array( 'child_type' => 'komponente', 'child_id' => 22, 'qty' => 4, 'position' => 5 ),
					array( 'child_type' => 'komponente', 'child_id' => 23, 'qty' => 1, 'position' => 6 ),
				),
			),
			'sourcing'   => array(
				100 => array( 'article_id' => 100, 'purchase_price_net' => 6.40, 'own_processing_min' => 4 ),
			),
		);
	}
}
