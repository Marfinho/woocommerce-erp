<?php
/**
 * CSV-Exporte gegen Formel-Einschleusung (Pentest 27.11.2026).
 *
 * Tabellenprogramme führen eine Zelle als Formel aus, wenn sie mit
 * `=` `+` `-` `@` Tab oder CR beginnt. Ein Händlername aus einer
 * importierten E-Rechnung ist voll vom Absender bestimmt. Die
 * Buchhaltungs- und Kennzahlen-Exporte schützten davor nicht; andere
 * Exporte (Lohn, Marketing, Events) taten es schon. `Core\Csv::guard`
 * vereinheitlicht die Regel.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\Csv;

class CsvFormelTest extends TestCase {

	/**
	 * Formeln bekommen ein führendes Hochkomma.
	 *
	 * @dataProvider formeln
	 * @param string $eingabe Zellwert.
	 */
	public function test_formeln_werden_entschaerft( $eingabe ) {
		$out = Csv::guard( $eingabe );
		$this->assertSame( "'", $out[0], "Formel nicht entschärft: $eingabe" );
		$this->assertSame( $eingabe, substr( $out, 1 ), 'Der Inhalt bleibt sonst gleich.' );
	}

	/**
	 * Gefährliche Zellanfänge.
	 *
	 * @return array
	 */
	public function formeln() {
		return array(
			array( '=1+1' ),
			array( '=HYPERLINK("http://evil.example")' ),
			array( '+1+1' ),
			array( '@SUM(A1)' ),
			array( '-cmd|calc' ),
			array( "=cmd|'/C calc'!A1" ),
			array( "\tTab" ),
			array( "\rCR" ),
		);
	}

	/**
	 * Echte Zahlen – auch negative Beträge – bleiben unverändert.
	 *
	 * @dataProvider zahlen
	 * @param string $eingabe Wert.
	 */
	public function test_zahlen_bleiben_unveraendert( $eingabe ) {
		$this->assertSame( $eingabe, Csv::guard( $eingabe ) );
	}

	/**
	 * Werte, die keine Formel sind.
	 *
	 * @return array
	 */
	public function zahlen() {
		return array(
			array( '-5' ),
			array( '-5,00' ),
			array( '-1234.56' ),
			array( '-5,00 €' ),
			array( '+3,50' ),
			array( '+1' ),
			array( '19,90' ),
			array( 'Normaltext' ),
			array( 'Müller GmbH' ),
			array( '' ),
		);
	}

	/**
	 * Der Buchhaltungsexport (ExportGenerator::build_csv) schützt jedes Feld.
	 */
	public function test_buchhaltungsexport_schuetzt_haendlername() {
		$method = new ReflectionMethod( 'Lindenweg16\\Modules\\Accounting\\ExportGenerator', 'build_csv' );
		$method->setAccessible( true );

		$csv = $method->invoke(
			null,
			array( 'Haendler', 'Notiz' ),
			array( array( 'haendler' => '=HYPERLINK("http://evil")', 'notiz' => '@SUM(1)' ) ),
			array( 'haendler', 'notiz' )
		);

		$this->assertStringContainsString( '"\'=HYPERLINK', $csv, 'Händlername nicht entschärft.' );
		$this->assertStringContainsString( '"\'@SUM', $csv, 'Notiz nicht entschärft.' );
		$this->assertStringNotContainsString( '"=HYPERLINK', $csv );
	}

	/**
	 * Kein CSV-Builder im Plugin schreibt Daten ungeschützt.
	 *
	 * Wächter: Wer künftig einen Export hinzufügt, muss den Schutz
	 * mitnehmen (eigene csv_safe/csv_cell/csv_line oder Core\Csv::guard).
	 */
	public function test_jeder_csv_builder_kennt_den_schutz() {
		$root  = dirname( __DIR__ ) . '/includes';
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		$sinks = array( 'fputcsv', 'build_csv', 'datev_line' );
		$fehlt = array();

		foreach ( $it as $file ) {
			if ( '.php' !== substr( (string) $file, -4 ) ) {
				continue;
			}
			$code = (string) file_get_contents( (string) $file );

			foreach ( $sinks as $sink ) {
				if ( false === strpos( $code, $sink . '(' ) && false === strpos( $code, 'function ' . $sink ) ) {
					continue;
				}
				// In der Datei muss ein Formel-Schutz sichtbar sein.
				if ( ! preg_match( '/Csv::guard|Csv::class|csv_safe|csv_cell|csv_line|strpos\( "=\+/', $code ) ) {
					$fehlt[] = substr( (string) $file, strlen( $root ) + 1 ) . " ($sink)";
				}
			}
		}

		$this->assertSame( array(), array_unique( $fehlt ), 'CSV-Builder ohne Formel-Schutz.' );
	}
}
