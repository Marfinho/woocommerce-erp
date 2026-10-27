<?php
/**
 * Prognose: Modell, Zielwahrscheinlichkeit, Lieferzusage, Tagesplan.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\DayPlan;
use Lindenweg16\Modules\Betrieb\ForecastKpi;
use Lindenweg16\Modules\Betrieb\ForecastModel;
use Lindenweg16\Modules\Betrieb\Promise;

/**
 * Eine Prognose, die sich selbst prüft, und die Bewertung gegen Ziele.
 */
class BetriebPrognoseTest extends TestCase {

	/**
	 * Lücken werden überbrückt, Ränder gekappt.
	 */
	public function test_luecken_werden_bereinigt() {
		$this->assertEquals( array( 1.0, 2.0, 3.0, 4.0 ), ForecastModel::clean( array( null, 1, null, null, 4, null ) ) );
	}

	/**
	 * Eine gerade Reihe wird fortgeschrieben, das Band wächst mit dem Horizont.
	 */
	public function test_trend_wird_fortgeschrieben_und_band_waechst() {
		$series = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$series[] = 100 + 5 * $i + ( ( $i % 3 ) - 1 ) * 3;
		}

		$f = ForecastModel::forecast( $series, 4 );

		$this->assertSame( 'holt', $f['method'] );
		$this->assertGreaterThan( 195, $f['points'][0]['mean'] );
		$this->assertGreaterThan( $f['points'][0]['mean'], $f['points'][3]['mean'], 'Der Trend setzt sich fort.' );

		$breite = static function ( $p ) {
			return $p['hi80'] - $p['lo80'];
		};
		$this->assertGreaterThan( $breite( $f['points'][0] ), $breite( $f['points'][3] ), 'Je weiter voraus, desto unsicherer.' );
		$this->assertGreaterThan( $f['points'][0]['hi80'] - $f['points'][0]['lo80'], $f['points'][0]['hi95'] - $f['points'][0]['lo95'], 'Das 95-%-Band ist breiter als das 80-%-Band.' );
		$this->assertSame( 'hoch', $f['reliability'] );
	}

	/**
	 * Saison wird nur mit zwei vollen Saisons gebildet.
	 */
	public function test_saison_nur_mit_beleg() {
		$base = array( 0.7, 0.7, 0.8, 0.9, 1, 1, 0.9, 0.9, 1, 1.1, 1.4, 1.7 );

		$lang = array();
		for ( $i = 0; $i < 36; $i++ ) {
			$lang[] = round( 1000 * ( 1 + 0.01 * $i ) * $base[ $i % 12 ] );
		}
		$f = ForecastModel::forecast( $lang, 12, 12 );
		$this->assertTrue( $f['seasonal'] );
		// Der Dezember (Schritt 11 ab Januar des vierten Jahres) liegt klar über dem Januar.
		$this->assertGreaterThan( 1.8 * $f['points'][0]['mean'], $f['points'][11]['mean'] );

		$kurz = ForecastModel::forecast( array_slice( $lang, 0, 18 ), 6, 12 );
		$this->assertFalse( $kurz['seasonal'], 'Unter zwei Saisons wird nicht geraten.' );
	}

	/**
	 * Sehr kurze Reihen bekommen ein breites Band und keine Gütebehauptung.
	 */
	public function test_kurze_reihe_ehrlich() {
		$f = ForecastModel::forecast( array( 10, 12, 11 ), 3 );

		$this->assertSame( 'mittelwert', $f['method'] );
		$this->assertSame( 'keine', $f['reliability'] );
		$this->assertGreaterThan( 2, $f['points'][0]['hi80'] - $f['points'][0]['lo80'] );
		$this->assertSame( 'keine', ForecastModel::forecast( array( 5 ), 2 )['method'] );
	}

	/**
	 * Mengen werden nie negativ.
	 */
	public function test_nie_unter_null() {
		$f = ForecastModel::forecast( array( 0, 0, 0, 0, 0, 0 ), 3 );

		$this->assertSame( 0.0, $f['points'][0]['mean'] );
		$this->assertSame( 0.0, $f['points'][0]['lo95'] );
	}

	/**
	 * Verteilungsfunktion an bekannten Stellen.
	 */
	public function test_normalverteilung() {
		$this->assertEqualsWithDelta( 0.5, ForecastModel::normal_cdf( 0 ), 0.0001 );
		$this->assertEqualsWithDelta( 0.975, ForecastModel::normal_cdf( 1.96 ), 0.0005 );
		$this->assertEqualsWithDelta( 0.1587, ForecastModel::normal_cdf( -1 ), 0.0005 );
	}

	/**
	 * Die Summe aus Ist und Rest: Erwartung addiert, Streuung mit Gleichlauf.
	 */
	public function test_summenprognose() {
		$points = array(
			array( 'mean' => 1000.0, 'sd' => 100.0 ),
			array( 'mean' => 1000.0, 'sd' => 100.0 ),
		);

		$unabhaengig = ForecastKpi::sum_projection( 5000, $points, 0.0 );
		$gleichlauf  = ForecastKpi::sum_projection( 5000, $points, 0.3 );

		$this->assertSame( 7000.0, $unabhaengig['mean'] );
		$this->assertEqualsWithDelta( 141.42, $unabhaengig['sd'], 0.01 );
		$this->assertGreaterThan( $unabhaengig['sd'], $gleichlauf['sd'], 'Gleichlauf macht das Band breiter.' );
		$this->assertGreaterThanOrEqual( 5000.0, $gleichlauf['lo95'], 'Was erreicht ist, geht nicht verloren.' );
	}

	/**
	 * Wahrscheinlichkeit, das Ziel zu erreichen.
	 */
	public function test_zielwahrscheinlichkeit() {
		$this->assertEqualsWithDelta( 0.5, ForecastKpi::prob_at_least( 100, 10, 100 ), 0.001 );
		$this->assertGreaterThan( 0.99, ForecastKpi::prob_at_least( 100, 10, 70 ) );
		$this->assertLessThan( 0.01, ForecastKpi::prob_at_least( 100, 10, 130 ) );
		$this->assertSame( 1.0, ForecastKpi::prob_at_least( 100, 0, 90 ) );
		$this->assertSame( 0.0, ForecastKpi::prob_at_least( 100, 0, 110 ) );
	}

	/**
	 * Ampel und Stufe aus den Wahrscheinlichkeiten.
	 */
	public function test_ampel() {
		$this->assertSame( 'gruen', ForecastKpi::status( 0.8, 0.02 ) );
		$this->assertSame( 'gelb', ForecastKpi::status( 0.4, 0.1 ) );
		$this->assertSame( 'rot', ForecastKpi::status( 0.1, 0.1 ) );
		$this->assertSame( 'rot', ForecastKpi::status( 0.9, 0.6 ), 'Eine wahrscheinlich verletzte Alarmgrenze ist rot, auch wenn das Ziel erreichbar scheint.' );
		$this->assertSame( 'neutral', ForecastKpi::status( null, null ) );
		$this->assertSame( 'critical', ForecastKpi::level_of( 'rot' ) );
		$this->assertSame( 'attention', ForecastKpi::level_of( 'gelb' ) );
		$this->assertSame( 'normal', ForecastKpi::level_of( 'gruen' ) );
	}

	/**
	 * Gemeldet wird nur, wenn sich die Stufe ändert.
	 */
	public function test_nur_aenderungen_werden_gemeldet() {
		$changes = ForecastKpi::changes(
			array( 'umsatz' => 'attention', 'bestellungen' => 'critical' ),
			array( 'umsatz' => 'attention', 'bestellungen' => 'normal', 'warenkorb' => 'critical' )
		);

		$this->assertCount( 2, $changes );
		$this->assertSame( 'recover', $changes[0]['direction'] );
		$this->assertSame( 'bestellungen', $changes[0]['key'] );
		$this->assertSame( 'raise', $changes[1]['direction'] );
		$this->assertSame( 'warenkorb', $changes[1]['key'] );
	}

	/**
	 * Was pro Woche noch nötig ist.
	 */
	public function test_erforderliche_rate() {
		$this->assertSame( 1000.0, ForecastKpi::required_per_period( 3000, 5000, 2 ) );
		$this->assertSame( 0.0, ForecastKpi::required_per_period( 6000, 5000, 2 ) );
		$this->assertNull( ForecastKpi::required_per_period( 3000, 5000, 0 ) );
	}

	/**
	 * Anteil einer Woche, der noch im Fenster liegt.
	 */
	public function test_anteil_im_fenster() {
		$day = 86400;
		$mon = strtotime( '2026-10-26' );
		$sun = $mon + 6 * $day;

		$this->assertSame( 1.0, ForecastKpi::overlap_fraction( $mon, $sun, $mon, $sun + 30 * $day ) );
		$this->assertEqualsWithDelta( 4 / 7, ForecastKpi::overlap_fraction( $mon, $sun, $mon + 3 * $day, $sun ), 0.0001 );
		$this->assertSame( 0.0, ForecastKpi::overlap_fraction( $mon, $sun, $sun + $day, $sun + 9 * $day ) );
	}

	/**
	 * Was im Lager liegt, geht sofort; sonst zählt die knappere Kapazität.
	 */
	public function test_lieferzusage() {
		$today = strtotime( '2026-10-27 UTC' );

		$sofort = Promise::earliest( 10, 5, 0.5, 0.2, 0, 0, 40, 14, $today );
		$this->assertTrue( $sofort['immediate'] );

		// 20 fehlen: 10 h Druck, 4 h Hand; Rückstand 30 h Druck. Drucker 40 h/Woche -> 1 Woche.
		$spaeter = Promise::earliest( 0, 20, 0.5, 0.2, 30, 0, 40, 14, $today );
		$this->assertFalse( $spaeter['immediate'] );
		$this->assertSame( 1.0, $spaeter['weeks'] );
		$this->assertSame( 'druckzeit', $spaeter['limiting'] );
		$this->assertGreaterThan( $spaeter['date_from'], $spaeter['date_to'], 'Spanne statt Punkt.' );

		// Handarbeit begrenzt.
		$hand = Promise::earliest( 0, 100, 0.1, 1.0, 0, 0, 40, 10, $today );
		$this->assertSame( 'arbeitszeit', $hand['limiting'] );

		// Keine Kapazität: unbekannt, nicht erfunden.
		$unbekannt = Promise::earliest( 0, 5, 1, 1, 0, 0, 0, 0, $today );
		$this->assertNull( $unbekannt['date_from'] );
	}

	/**
	 * Texte für die Produktseite.
	 */
	public function test_shop_text() {
		$this->assertSame( 'Auf Lager', Promise::shop_text( 10, null ) );
		$this->assertSame( 'Nur noch 2 auf Lager', Promise::shop_text( 2, null ) );
		$this->assertSame( 'Zurzeit nicht auf Lager', Promise::shop_text( 0, null ) );
		$this->assertStringContainsString( 'lieferbar in ca.', Promise::shop_text( 0, array( 'weeks' => 1.0, 'date_to' => '2026-11-10' ) ) );
	}

	/**
	 * Lange Platten in die Nacht, kurze in den Tag, nichts geht verloren.
	 */
	public function test_tagesplan() {
		$steps = array(
			array( 'article_id' => 1, 'action' => 'drucken', 'plates' => 2, 'machine_h' => 12.0, 'sku' => 'A', 'name' => 'Lang' ),
			array( 'article_id' => 2, 'action' => 'drucken', 'plates' => 3, 'machine_h' => 4.5, 'sku' => 'B', 'name' => 'Kurz' ),
			array( 'article_id' => 3, 'action' => 'montieren', 'plates' => 0, 'machine_h' => 0.0, 'sku' => 'C', 'name' => 'Montage' ),
		);

		$plates = DayPlan::plates( $steps );
		$this->assertCount( 5, $plates, 'Nur Gedrucktes belegt einen Drucker.' );

		$plan = DayPlan::build( $plates, 1 );

		$this->assertSame( 0, $plan['left'] );
		$first = $plan['days'][0]['printers'][0];
		$this->assertNotEmpty( $first['night'] );
		$this->assertGreaterThanOrEqual( DayPlan::LONG_H, $first['night'][0]['hours'] );

		$total = 0;
		foreach ( $plan['days'] as $day ) {
			$total += $day['plates'];
		}
		$this->assertSame( 5, $total );
	}

	/**
	 * Die Handarbeit des Tages begrenzt, wie viele Platten bestückt werden.
	 */
	public function test_tagesplan_handarbeit_begrenzt() {
		$plates = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$plates[] = array( 'article_id' => 1, 'sku' => 'A', 'name' => 'A', 'hours' => 1.0 );
		}

		$plan = DayPlan::build( $plates, 2, array( 'plate_min' => 30, 'operator_h' => 1.0 ) );

		$this->assertSame( 2, $plan['days'][0]['plates'], 'Eine Stunde Handarbeit reicht für zwei Plattenwechsel.' );
		$this->assertSame( 0, $plan['left'] );
		$this->assertGreaterThanOrEqual( 3, count( $plan['days'] ) );
	}

	/**
	 * Quantile der Normalverteilung - der Dienstgrad bestimmt den Sicherheitszuschlag.
	 */
	public function test_quantile() {
		$this->assertEqualsWithDelta( 0.0, ForecastModel::normal_quantile( 0.5 ), 0.0001 );
		$this->assertEqualsWithDelta( 1.2816, ForecastModel::normal_quantile( 0.9 ), 0.001 );
		$this->assertEqualsWithDelta( 1.6449, ForecastModel::normal_quantile( 0.95 ), 0.001 );
		$this->assertEqualsWithDelta( 2.3263, ForecastModel::normal_quantile( 0.99 ), 0.001 );
		$this->assertEqualsWithDelta( -1.2816, ForecastModel::normal_quantile( 0.1 ), 0.001 );
	}

	/**
	 * Ein Markt hebt nur die Woche an, die er berührt - anteilig nach Tagen.
	 */
	public function test_ereignisfaktor() {
		$day = 86400;
		$mon = strtotime( '2026-11-02 UTC' );
		$sun = $mon + 6 * $day;
		$events = array( array( 'von' => '2026-11-05', 'bis' => '2026-11-07', 'pct' => 70 ) );

		$this->assertEqualsWithDelta( 1.3, ForecastKpi::event_factor( $events, $mon, $sun ), 0.0001, '3 von 7 Tagen mit 70 % = 30 % Aufschlag.' );
		$this->assertSame( 1.0, ForecastKpi::event_factor( $events, $mon + 14 * $day, $sun + 14 * $day ) );
		$this->assertSame( 1.0, ForecastKpi::event_factor( array(), $mon, $sun ) );
	}

	/**
	 * Einstellungen: Grenzen, und „kritisch“ liegt immer unter „Aufmerksamkeit“.
	 */
	public function test_prognose_einstellungen() {
		$s = \Lindenweg16\Modules\Betrieb\ForecastService::sanitize_settings( array( 'p_attention' => 40, 'p_critical' => 60, 'service_level' => 120, 'lead_weeks' => -3 ) );

		$this->assertSame( 40, $s['p_attention'] );
		$this->assertLessThan( $s['p_attention'], $s['p_critical'] );
		$this->assertSame( 99, $s['service_level'] );
		$this->assertSame( 0.0, $s['lead_weeks'] );
		$this->assertSame( 0, $s['shop_text'] );
	}

	/**
	 * Vor dem ersten Verkauf liegt keine Nachfrage.
	 */
	public function test_fuehrende_nullen_sind_luecken() {
		$this->assertSame( array( null, null, 3.0, 0.0, 5.0 ), \Lindenweg16\Modules\Betrieb\ForecastService::trim_leading_zeros( array( 0.0, 0.0, 3.0, 0.0, 5.0 ) ) );
	}

	/**
	 * Bedarf über einen Zeitraum von Wochen, auch über halbe Wochen.
	 */
	public function test_bedarf_ueber_wochen() {
		$points = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$points[] = array( 'mean' => 10.0, 'sd' => 2.0 );
		}

		$d = \Lindenweg16\Modules\Betrieb\ForecastService::demand_over( $points, 2.5 );

		$this->assertEqualsWithDelta( 25.0, $d['mean'], 0.0001 );
		$this->assertGreaterThan( 2.0, $d['sd'] );
		$this->assertLessThan( 2.0 * 3, $d['sd'] );
	}

	/**
	 * Reichweite: erwartet, im schlechten und im guten Fall.
	 */
	public function test_reichweite_mit_band() {
		$points = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$points[] = array( 'mean' => 10.0, 'sd' => 3.0 );
		}

		$erwartet = \Lindenweg16\Modules\Betrieb\ForecastService::coverage_weeks( 25, $points, 0.0 );
		$schlecht = \Lindenweg16\Modules\Betrieb\ForecastService::coverage_weeks( 25, $points, ForecastModel::Z80 );
		$gut      = \Lindenweg16\Modules\Betrieb\ForecastService::coverage_weeks( 25, $points, -ForecastModel::Z80 );

		$this->assertEqualsWithDelta( 2.5, $erwartet, 0.01 );
		$this->assertLessThan( $erwartet, $schlecht, 'Bei hoher Nachfrage reicht der Bestand kürzer.' );
		$this->assertGreaterThan( $erwartet, $gut );
		$this->assertNull( \Lindenweg16\Modules\Betrieb\ForecastService::coverage_weeks( 5000, $points, 0.0 ), 'Über den Horizont hinaus: keine Zahl erfinden.' );
		$this->assertEqualsWithDelta( 0.0, \Lindenweg16\Modules\Betrieb\ForecastService::coverage_weeks( 0, $points, 0.0 ), 0.0001 );
	}
}
