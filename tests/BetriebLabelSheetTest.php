<?php
/**
 * Prüft die Geometrie des Etikettenbogens.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\LabelSheet;
use PHPUnit\Framework\TestCase;

/**
 * Bogen-Tests.
 */
class BetriebLabelSheetTest extends TestCase {

	/**
	 * Jede Aufteilung nennt Spalten, Zeilen und ein Sollmass.
	 */
	public function test_jede_aufteilung_ist_vollstaendig() {
		foreach ( LabelSheet::layouts() as $key => $layout ) {
			foreach ( array( 'label', 'hint', 'cols', 'rows', 'label_w', 'label_h' ) as $feld ) {
				$this->assertArrayHasKey( $feld, $layout, $key . ' fehlt ' . $feld );
			}

			$this->assertGreaterThan( 0, $layout['cols'] );
			$this->assertGreaterThan( 0, $layout['rows'] );
		}
	}

	/**
	 * Kein Bogen läuft über die Seite hinaus. Ein Etikett, das über den
	 * Rand ragt, wird vom Drucker abgeschnitten.
	 */
	public function test_keine_aufteilung_laeuft_ueber_die_seite() {
		foreach ( LabelSheet::layouts() as $key => $layout ) {
			$this->assertLessThanOrEqual(
				LabelSheet::PAGE_WIDTH,
				$layout['cols'] * $layout['label_w'],
				$key . ' ist breiter als die Seite.'
			);
			$this->assertLessThanOrEqual(
				LabelSheet::PAGE_HEIGHT,
				$layout['rows'] * $layout['label_h'],
				$key . ' ist höher als die Seite.'
			);
		}
	}

	/**
	 * Der Klebebogen hält sein Sollmass. Würde stattdessen die Seite durch
	 * acht geteilt, liefe der Bogen nach unten aus der Stanzung.
	 */
	public function test_der_klebebogen_haelt_sein_sollmass() {
		$layout = LabelSheet::layout( 'bogen' );
		$cells  = LabelSheet::cells( $layout );

		$this->assertCount( 24, $cells );
		$this->assertEqualsWithDelta( 70.0, $cells[0]['w'] / LabelSheet::MM, 0.01 );
		$this->assertEqualsWithDelta( 37.0, $cells[0]['h'] / LabelSheet::MM, 0.01 );

		// Der Abstand zwischen zwei Zeilen ist genau die Etikettenhöhe.
		$this->assertEqualsWithDelta(
			$cells[0]['h'],
			$cells[3]['y'] - $cells[0]['y'],
			0.0001
		);
	}

	/**
	 * Die Felder stehen zeilenweise von oben links und liegen mittig auf
	 * der Seite.
	 */
	public function test_die_felder_liegen_mittig_und_der_reihe_nach() {
		$layout = LabelSheet::layout( 'bogen' );
		$cells  = LabelSheet::cells( $layout );

		$this->assertSame( $cells[0]['y'], $cells[1]['y'], 'Erste Zeile ist nicht auf einer Höhe.' );
		$this->assertGreaterThan( $cells[0]['x'], $cells[1]['x'] );
		$this->assertGreaterThan( $cells[0]['y'], $cells[3]['y'] );

		$rand_links  = $cells[0]['x'];
		$rand_rechts = LabelSheet::PAGE_WIDTH - ( $cells[2]['x'] + $cells[2]['w'] );
		$this->assertEqualsWithDelta( $rand_links, $rand_rechts, 0.001 );

		$rand_oben  = $cells[0]['y'];
		$rand_unten = LabelSheet::PAGE_HEIGHT - ( $cells[23]['y'] + $cells[23]['h'] );
		$this->assertEqualsWithDelta( $rand_oben, $rand_unten, 0.001 );
	}

	/**
	 * Mehr Etiketten als Plätze ergeben mehrere Seiten, und keines geht
	 * verloren.
	 */
	public function test_die_seiten_teilen_ohne_verlust() {
		$layout = LabelSheet::layout( 'bogen' );
		$labels = range( 1, 30 );
		$pages  = LabelSheet::paginate( $labels, $layout );

		$this->assertCount( 2, $pages );
		$this->assertCount( 24, $pages[0] );
		$this->assertCount( 6, $pages[1] );
		$this->assertSame( $labels, array_merge( $pages[0], $pages[1] ) );
	}

	/**
	 * Ohne Etiketten gibt es keine leere Seite.
	 */
	public function test_ohne_etiketten_keine_seite() {
		$this->assertSame( array(), LabelSheet::paginate( array(), LabelSheet::layout( 'bogen' ) ) );
	}

	/**
	 * Nebeneinanderliegende Module werden zu einem Rechteck.
	 */
	public function test_module_werden_zusammengefasst() {
		$runs = LabelSheet::runs(
			array(
				array( 1, 1, 1, 0, 1 ),
				array( 0, 0, 0, 0, 0 ),
				array( 0, 1, 1, 0, 0 ),
			)
		);

		$this->assertSame(
			array(
				array( 'row' => 0, 'col' => 0, 'length' => 3 ),
				array( 'row' => 0, 'col' => 4, 'length' => 1 ),
				array( 'row' => 2, 'col' => 1, 'length' => 2 ),
			),
			$runs
		);
	}

	/**
	 * Aus den Läufen lässt sich das Raster wieder herstellen – nur dann
	 * zeigt das PDF denselben Code, den der Encoder erzeugt hat.
	 */
	public function test_aus_den_laeufen_wird_das_raster_wieder() {
		$matrix = array(
			array( 1, 0, 1, 1, 0, 1 ),
			array( 1, 1, 0, 0, 1, 1 ),
			array( 0, 0, 0, 0, 0, 0 ),
			array( 1, 1, 1, 1, 1, 1 ),
		);

		$zurueck = array_fill( 0, 4, array_fill( 0, 6, 0 ) );

		foreach ( LabelSheet::runs( $matrix ) as $run ) {
			for ( $i = 0; $i < $run['length']; $i++ ) {
				$zurueck[ $run['row'] ][ $run['col'] + $i ] = 1;
			}
		}

		$this->assertSame( $matrix, $zurueck );
	}

	/**
	 * Der QR-Code bleibt in seinem Etikett – auch auf dem kleinsten.
	 */
	public function test_der_code_bleibt_im_etikett() {
		foreach ( array_keys( LabelSheet::layouts() ) as $key ) {
			$layout = LabelSheet::layout( $key );
			$cell   = LabelSheet::cells( $layout )[0];

			// Version 1 bis 4 deckt alles ab, was hier je codiert wird.
			foreach ( array( 21, 25, 29, 33 ) as $module ) {
				$box = LabelSheet::qr_box( $cell, $module );

				$this->assertGreaterThanOrEqual( $cell['x'], $box['x'], $key );
				$this->assertGreaterThanOrEqual( $cell['y'], $box['y'], $key );
				$this->assertLessThanOrEqual( $cell['x'] + $cell['w'], $box['x'] + $box['size'], $key );
				$this->assertLessThanOrEqual( $cell['y'] + $cell['h'], $box['y'] + $box['size'], $key );
			}
		}
	}

	/**
	 * Der Code nimmt höchstens die halbe Breite – sonst bliebe für den
	 * Klartext nichts, und ohne Klartext ist ein Etikett bei leerem Handy
	 * wertlos.
	 */
	public function test_der_code_laesst_platz_fuer_den_klartext() {
		foreach ( array_keys( LabelSheet::layouts() ) as $key ) {
			$layout = LabelSheet::layout( $key );
			$cell   = LabelSheet::cells( $layout )[0];
			$box    = LabelSheet::qr_box( $cell, 25 );

			$this->assertLessThanOrEqual( $cell['w'] / 2, $box['size'], $key );
		}
	}

	/**
	 * Auf dem kleinsten Etikett bleibt ein Modul gross genug, dass eine
	 * Handykamera es noch auflöst.
	 */
	public function test_die_module_bleiben_druckbar() {
		foreach ( array_keys( LabelSheet::layouts() ) as $key ) {
			$layout = LabelSheet::layout( $key );
			$cell   = LabelSheet::cells( $layout )[0];
			$box    = LabelSheet::qr_box( $cell, 29 );

			$this->assertGreaterThan(
				0.5,
				$box['module'] / LabelSheet::MM,
				$key . ': Module unter einem halben Millimeter sind nicht mehr zuverlässig scannbar.'
			);
		}
	}

	/**
	 * Auch auf der Rolle bleiben die Module gross genug. Das ist der Grund,
	 * warum es kein 17-mm-Format gibt.
	 */
	public function test_die_module_bleiben_auch_auf_der_rolle_druckbar() {
		foreach ( array_keys( LabelSheet::rolls() ) as $key ) {
			$cell = LabelSheet::roll_cell( LabelSheet::roll( $key ) );
			$box  = LabelSheet::qr_box( $cell, 29, LabelSheet::padding( $cell['h'] ) );

			$this->assertGreaterThan(
				0.5,
				$box['module'] / LabelSheet::MM,
				$key . ': Module unter einem halben Millimeter sind nicht mehr zuverlässig scannbar.'
			);
		}
	}

	/**
	 * Ein Rollenetikett ist die ganze Seite – ohne Rand, sonst skaliert der
	 * Treiber.
	 */
	public function test_das_rollenetikett_ist_die_ganze_seite() {
		foreach ( LabelSheet::rolls() as $key => $roll ) {
			$cell = LabelSheet::roll_cell( $roll );

			$this->assertSame( 0.0, $cell['x'], $key );
			$this->assertSame( 0.0, $cell['y'], $key );
			$this->assertEqualsWithDelta( $roll['w'], $cell['w'] / LabelSheet::MM, 0.001, $key );
			$this->assertEqualsWithDelta( $roll['h'], $cell['h'] / LabelSheet::MM, 0.001, $key );
		}
	}

	/**
	 * Jedes Rollenformat ist genau so breit wie die Rolle im Gerät.
	 *
	 * Das war der Fehler der ersten Fassung: Brother nennt seine Rollen
	 * „Breite × Länge", und aus DK-11201 (29 mm breit, 90 mm lang) wurde
	 * versehentlich ein 90 mm breites Etikett. Auf einer 62-mm-Rolle
	 * druckt so etwas nicht.
	 */
	public function test_jedes_rollenformat_ist_62_mm_breit() {
		foreach ( LabelSheet::rolls() as $key => $roll ) {
			$this->assertSame(
				LabelSheet::ROLL_WIDTH_MM,
				$roll['w'],
				$key . ' ist nicht so breit wie die Rolle im Gerät.'
			);
		}
	}

	/**
	 * Der Inhalt bleibt innerhalb der bedruckbaren Breite. Der Druckkopf
	 * ist schmaler als die Rolle – ganz am Rand käme nichts heraus.
	 */
	public function test_der_inhalt_bleibt_im_druckbaren_bereich() {
		$unbedruckbar = ( LabelSheet::ROLL_WIDTH_MM - LabelSheet::PRINTABLE_WIDTH_MM ) / 2;

		foreach ( LabelSheet::rolls() as $key => $roll ) {
			$cell    = LabelSheet::roll_cell( $roll );
			$padding = LabelSheet::padding( $cell['h'] );

			$this->assertGreaterThan(
				$unbedruckbar,
				$padding / LabelSheet::MM,
				$key . ': Der Rand ist schmaler als der Bereich, den der Druckkopf nicht erreicht.'
			);
		}
	}

	/**
	 * Unbekanntes Rollenformat fällt auf das gängigste zurück.
	 */
	public function test_unbekannte_rolle_faellt_zurueck() {
		$this->assertFalse( LabelSheet::has_roll( 'ausgedacht' ) );
		$this->assertSame( LabelSheet::roll( 'dk11209' ), LabelSheet::roll( 'ausgedacht' ) );
	}

	/**
	 * Bogen und Rolle sind zwei verschiedene Dinge und dürfen sich in den
	 * Kennungen nicht überschneiden – sonst entscheidet der Zufall, welches
	 * Format der Export nimmt.
	 */
	public function test_bogen_und_rolle_teilen_keine_kennung() {
		$this->assertSame(
			array(),
			array_intersect( array_keys( LabelSheet::layouts() ), array_keys( LabelSheet::rolls() ) )
		);
	}

	/**
	 * Zu langer Text wird sichtbar gekürzt, kurzer bleibt unangetastet.
	 */
	public function test_langer_text_wird_sichtbar_gekuerzt() {
		$this->assertSame( 'kurz', LabelSheet::shorten( 'kurz', 10 ) );
		$this->assertSame( 'Basisplat…', LabelSheet::shorten( 'Basisplatte schwarz', 10 ) );
	}

	/**
	 * Eine unbekannte Aufteilung fällt auf den Klebebogen zurück statt zu
	 * scheitern.
	 */
	public function test_unbekannte_aufteilung_faellt_zurueck() {
		$this->assertFalse( LabelSheet::has_layout( 'ausgedacht' ) );
		$this->assertSame(
			LabelSheet::layout( 'bogen' ),
			LabelSheet::layout( 'ausgedacht' )
		);
	}
}
