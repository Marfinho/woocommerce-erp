<?php
/**
 * Prüft die Rechnung hinter dem Verlaufsgraphen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\TrendChart;
use PHPUnit\Framework\TestCase;

/**
 * Verlaufs-Tests.
 */
class BetriebTrendChartTest extends TestCase {

	/**
	 * Punkte bauen.
	 *
	 * @param array $werte Werte, null für Lücken.
	 * @return array
	 */
	protected function punkte( array $werte ) {
		$out = array();

		foreach ( $werte as $i => $wert ) {
			$out[] = array(
				'label'  => 'P' . $i,
				'value'  => $wert,
				'target' => null,
				'alarm'  => null,
				'text'   => 'P' . $i,
			);
		}

		return $out;
	}

	/**
	 * Die Achse endet auf einer runden Zahl.
	 */
	public function test_die_achse_endet_rund() {
		$this->assertSame( 1.0, TrendChart::nice_max( 0.9 ) );
		$this->assertSame( 2.0, TrendChart::nice_max( 1.4 ) );
		$this->assertSame( 2.5, TrendChart::nice_max( 2.3 ) );
		$this->assertSame( 5.0, TrendChart::nice_max( 4.1 ) );
		$this->assertSame( 10.0, TrendChart::nice_max( 6.0 ) );
		$this->assertSame( 5000.0, TrendChart::nice_max( 4200.0 ) );
		$this->assertSame( 0.0, TrendChart::nice_max( 0 ) );
	}

	/**
	 * Die Skala berücksichtigt auch Ziel und Alarm.
	 *
	 * Sonst läuft eine Ziellinie über dem Höchstwert oben aus dem Bild und
	 * der Leser sieht nicht, wie weit er davon entfernt ist.
	 */
	public function test_die_skala_umfasst_ziel_und_alarm() {
		$punkte = array(
			array( 'value' => 100.0, 'target' => 900.0, 'alarm' => null ),
		);

		$skala = TrendChart::scale( $punkte );

		$this->assertGreaterThanOrEqual( 900.0, $skala['max'] );
		$this->assertCount( 5, $skala['ticks'] );
		$this->assertSame( 0.0, $skala['ticks'][0], 'Die Skala beginnt bei null.' );
	}

	/**
	 * Ohne Werte gibt es keine Skala.
	 */
	public function test_ohne_werte_keine_skala() {
		$skala = TrendChart::scale( $this->punkte( array( null, null ) ) );

		$this->assertSame( 0.0, $skala['max'] );
		$this->assertSame( array(), $skala['ticks'] );
	}

	/**
	 * Punkte verteilen sich über die Zeichenfläche, der einzelne steht mittig.
	 */
	public function test_punkte_verteilen_sich_ueber_die_flaeche() {
		$x = TrendChart::positions( 3, 100.0 );

		$this->assertCount( 3, $x );
		$this->assertEqualsWithDelta( TrendChart::PAD_L, $x[0], 0.001 );
		$this->assertEqualsWithDelta( TrendChart::WIDTH - TrendChart::PAD_R, $x[2], 0.001 );
		$this->assertEqualsWithDelta( ( $x[0] + $x[2] ) / 2, $x[1], 0.001 );

		$einer = TrendChart::positions( 1, 100.0 );
		$this->assertCount( 1, $einer );
		$this->assertGreaterThan( TrendChart::PAD_L, $einer[0], 'Ein einzelner Punkt klebt nicht an der Achse.' );

		$this->assertSame( array(), TrendChart::positions( 0, 100.0 ) );
	}

	/**
	 * Null liegt unten, der Höchstwert oben.
	 */
	public function test_null_liegt_unten() {
		$unten = TrendChart::y( 0.0, 100.0 );
		$oben  = TrendChart::y( 100.0, 100.0 );

		$this->assertEqualsWithDelta( TrendChart::HEIGHT - TrendChart::PAD_B, $unten, 0.001 );
		$this->assertEqualsWithDelta( TrendChart::PAD_T, $oben, 0.001 );
		$this->assertGreaterThan( $oben, $unten, 'Im Bild wächst y nach unten.' );

		$this->assertNull( TrendChart::y( null, 100.0 ) );
		$this->assertNull( TrendChart::y( 5.0, 0.0 ) );
	}

	/**
	 * Eine Lücke unterbricht die Linie.
	 *
	 * Der entscheidende Test: Ohne den zweiten Anfangspunkt zöge der
	 * Zeichner eine Gerade über den fehlenden Monat und behauptete einen
	 * Verlauf, den niemand gemessen hat.
	 */
	public function test_eine_luecke_unterbricht_die_linie() {
		$x    = TrendChart::positions( 5, 100.0 );
		$pfad = TrendChart::path( array( 10.0, 20.0, null, 40.0, 50.0 ), $x, 100.0 );

		$this->assertSame( 2, substr_count( $pfad, 'M' ), 'Zwei getrennte Züge.' );
		$this->assertSame( 2, substr_count( $pfad, 'L' ) );
	}

	/**
	 * Eine durchgehende Reihe ist ein einziger Zug.
	 */
	public function test_durchgehende_reihe_ist_ein_zug() {
		$x    = TrendChart::positions( 4, 100.0 );
		$pfad = TrendChart::path( array( 10.0, 20.0, 30.0, 40.0 ), $x, 100.0 );

		$this->assertSame( 1, substr_count( $pfad, 'M' ) );
		$this->assertSame( 3, substr_count( $pfad, 'L' ) );
	}

	/**
	 * Ein einzelner Wert ergibt keine Linie.
	 */
	public function test_ein_einzelner_wert_ergibt_keine_linie() {
		$x = TrendChart::positions( 3, 100.0 );

		$this->assertSame( '', TrendChart::path( array( null, 20.0, null ), $x, 100.0 ) );
		$this->assertSame( '', TrendChart::path( array( null, null, null ), $x, 100.0 ) );
	}

	/**
	 * Koordinaten benutzen immer den Punkt als Dezimaltrennzeichen.
	 *
	 * Ein Komma je nach Spracheinstellung ergäbe kaputte Pfaddaten – und
	 * zwar erst auf einer deutschen Installation, nicht im Test.
	 */
	public function test_koordinaten_haben_keinen_dezimalkomma() {
		$x    = TrendChart::positions( 7, 100.0 );
		$pfad = TrendChart::path( array( 10.5, 20.25, 33.3, 41.7, 52.9, 61.1, 70.4 ), $x, 100.0 );

		$this->assertStringNotContainsString( ',', $pfad );
		$this->assertMatchesRegularExpression( '/^M [\d.]+ [\d.]+( L [\d.]+ [\d.]+)+$/', $pfad );
	}

	/**
	 * Die Zeitachse beschriftet höchstens sechs Punkte.
	 *
	 * Die Zahl steht nicht aus Geschmack da: Bei acht Marken stossen „KW
	 * 21/2026" und „KW 23/2026" im gerenderten Bild aneinander.
	 */
	public function test_die_zeitachse_bleibt_lesbar() {
		$this->assertSame( 1, TrendChart::label_stride( 6 ) );
		$this->assertSame( 3, TrendChart::label_stride( 13 ) );
		$this->assertSame( 7, TrendChart::label_stride( 40 ) );

		foreach ( range( 1, TrendChart::WIDTH ) as $anzahl ) {
			$stride = TrendChart::label_stride( $anzahl );
			$anzahl_beschriftet = (int) floor( ( $anzahl - 1 ) / $stride ) + 1;

			$this->assertLessThanOrEqual( 6, $anzahl_beschriftet, 'Bei ' . $anzahl . ' Punkten.' );
		}
	}

	/**
	 * Ohne Werte kommt ein Satz statt eines leeren Rahmens.
	 */
	public function test_ohne_werte_kommt_ein_satz() {
		$leer = TrendChart::svg( array( 'points' => $this->punkte( array( null, null ) ) ) );

		$this->assertStringNotContainsString( '<svg', $leer );
		$this->assertStringContainsString( 'keine Werte', $leer );
	}

	/**
	 * Das erzeugte SVG ist wohlgeformt und trägt eine Beschriftung.
	 */
	public function test_das_svg_ist_wohlgeformt() {
		$svg = TrendChart::svg(
			array(
				'points' => array(
					array( 'label' => 'Jan.', 'value' => 10.0, 'target' => 20.0, 'alarm' => 5.0, 'text' => 'Jan.: 10' ),
					array( 'label' => 'Feb.', 'value' => null, 'target' => 20.0, 'alarm' => 5.0, 'text' => 'Feb.: —' ),
					array( 'label' => 'März', 'value' => 25.0, 'target' => 20.0, 'alarm' => 5.0, 'text' => 'März: 25' ),
				),
				'title'  => 'Umsatz',
				'id'     => 'test-verlauf',
			)
		);

		$this->assertNotFalse( simplexml_load_string( $svg ), 'Das SVG ist nicht wohlgeformt.' );
		$this->assertStringContainsString( 'role="img"', $svg );
		$this->assertStringContainsString( 'aria-labelledby="test-verlauf-titel"', $svg );
		$this->assertStringContainsString( '<title id="test-verlauf-titel">Umsatz</title>', $svg );

		// Ziel und Alarm sind gestrichelt – sie sind Marken, keine Messreihe.
		$this->assertSame( 2, substr_count( $svg, 'stroke-dasharray' ) );

		// Jeder Punkt trägt seine Kurzinfo.
		$this->assertStringContainsString( 'Jan.: 10', $svg );
		$this->assertStringContainsString( 'März: 25', $svg );
	}

	/**
	 * Die Farben trennen Messreihe und Zustand.
	 */
	public function test_reihe_und_zustand_haben_getrennte_farben() {
		$farben = TrendChart::colors();

		foreach ( array( 'linie', 'ziel', 'alarm', 'raster', 'achse', 'grund' ) as $rolle ) {
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $farben[ $rolle ], $rolle );
		}

		$this->assertNotSame( $farben['linie'], $farben['ziel'] );
		$this->assertNotSame( $farben['linie'], $farben['alarm'] );
		$this->assertNotSame( $farben['ziel'], $farben['alarm'] );
	}

	/**
	 * Markup und Stilvorlage benutzen dieselben Klassennamen.
	 */
	public function test_markup_und_stilvorlage_passen_zusammen() {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/betrieb.css' );

		foreach ( array(
			'lw16-verlauf__bild',
			'lw16-verlauf__leer',
			'lw16-verlauf__legende',
			'lw16-verlauf__schluessel',
			'lw16-verlauf__strich',
			'lw16-verlauf__strich--gestrichelt',
		) as $klasse ) {
			$this->assertStringContainsString( '.' . $klasse, $css, $klasse . ' fehlt in der Stilvorlage.' );
		}
	}
}
