<?php
/**
 * Kennzahlen des Druckjournals und Vorbelegung der Schnellerfassung.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\RestApi;
use Lindenweg16\Modules\Betrieb\BatchRepository;

/**
 * Berechnete Größen und die Vorbelegung, die die Erfassung auf fünf Eingaben
 * verkürzt.
 */
class BetriebPrintJobTest extends TestCase {

	/**
	 * Ausschuss und Quote ergeben sich aus geplant und gut.
	 */
	public function test_ausschuss_und_quote() {
		$metrics = PrintJobRepository::metrics(
			array( 'parts_planned' => 24, 'parts_ok' => 21, 'plate_changes' => 3 )
		);

		$this->assertEqualsWithDelta( 3.0, $metrics['parts_scrap'], 0.00001 );
		$this->assertEqualsWithDelta( 0.125, $metrics['scrap_rate'], 0.00001 );
		$this->assertEqualsWithDelta( 7.0, $metrics['parts_per_plate_change'], 0.00001 );
	}

	/**
	 * Ein fehlerfreier Job hat Quote 0.
	 */
	public function test_kein_ausschuss() {
		$metrics = PrintJobRepository::metrics(
			array( 'parts_planned' => 6, 'parts_ok' => 6, 'plate_changes' => 1 )
		);

		$this->assertEqualsWithDelta( 0.0, $metrics['scrap_rate'], 0.00001 );
		$this->assertEqualsWithDelta( 6.0, $metrics['parts_per_plate_change'], 0.00001 );
	}

	/**
	 * Ohne Plattenwechsel keine Division durch null.
	 */
	public function test_ohne_plattenwechsel() {
		$metrics = PrintJobRepository::metrics(
			array( 'parts_planned' => 6, 'parts_ok' => 6, 'plate_changes' => 0 )
		);

		$this->assertSame( 0.0, $metrics['parts_per_plate_change'] );
	}

	/**
	 * Ein vollständig fehlgeschlagener Job hat Quote 1.
	 */
	public function test_totalausfall() {
		$metrics = PrintJobRepository::metrics(
			array( 'parts_planned' => 8, 'parts_ok' => 0, 'plate_changes' => 1 )
		);

		$this->assertEqualsWithDelta( 1.0, $metrics['scrap_rate'], 0.00001 );
	}

	/**
	 * Die Vorbelegung füllt geplante Stückzahl und Druckzeit aus der aktiven
	 * Revision – das ist der Grund, warum am Drucker fünf Eingaben reichen.
	 */
	public function test_vorbelegung_aus_der_revision() {
		$revision = array( 'parts_per_plate' => 6, 'print_time_plate_h' => 9 );
		$input    = RestApi::apply_defaults( array( 'article_id' => 1, 'parts_ok' => 6 ), $revision );

		$this->assertSame( 6.0, $input['parts_planned'] );
		$this->assertSame( 9.0, $input['print_time_h'] );
		$this->assertSame( 1, $input['plate_changes'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $input['job_date'] );
	}

	/**
	 * Eigene Eingaben schlagen die Vorbelegung.
	 */
	public function test_eingabe_schlaegt_vorbelegung() {
		$revision = array( 'parts_per_plate' => 6, 'print_time_plate_h' => 9 );
		$input    = RestApi::apply_defaults(
			array( 'article_id' => 1, 'parts_planned' => 4, 'print_time_h' => 6.5, 'plate_changes' => 2 ),
			$revision
		);

		$this->assertSame( 4, $input['parts_planned'] );
		$this->assertSame( 6.5, $input['print_time_h'] );
		$this->assertSame( 2, $input['plate_changes'] );
	}

	/**
	 * Ohne Revision bleibt die Vorbelegung leer statt zu raten.
	 */
	public function test_ohne_revision_keine_erfundenen_werte() {
		$input = RestApi::apply_defaults( array( 'article_id' => 1 ), false );

		$this->assertArrayNotHasKey( 'parts_planned', $input );
		$this->assertArrayNotHasKey( 'print_time_h', $input );
	}

	/**
	 * Chargencode nach Schema JJMM-SKU-lfd, tippbar für den Slicer.
	 */
	public function test_chargencode_schema() {
		$this->assertSame( '2609-HH001-01', BatchRepository::build_code( '2026-09-05', 'HH-001', 1 ) );
		$this->assertSame( '2610-HH001-12', BatchRepository::build_code( '2026-10-31', 'HH-001', 12 ) );
	}

	/**
	 * Sonderzeichen der Artikelnummer fallen aus dem Code – er muss sich ins
	 * Bauteil prägen lassen.
	 */
	public function test_chargencode_ohne_sonderzeichen() {
		$this->assertSame( '2609-HHSTANGE-03', BatchRepository::build_code( '2026-09-05', 'HH-STANGE', 3 ) );
	}
}
