<?php
/**
 * Sollbestände werden unter dem Spaltennamen gelesen, unter dem sie liegen.
 *
 * `StockRepository::targets()` liefert Zeilen der Tabelle mit der Spalte
 * `target_qty`. Zwei Leser fragten nach `qty`, bekamen nichts und rechneten
 * mit 0: Es entstand nie ein Bedarf aus dem Sollbestand, und die Warnzahl im
 * Dashboard blieb bei 0. Die Rechenkerne waren geprüft, die Datenbankzeile
 * davor nicht – dieser Test liest die Aufrufer.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Leser von Sollbeständen.
 */
class SollbestandSpalteTest extends TestCase {

	/**
	 * Jeder Aufrufer von targets() liest `target_qty`, nie `qty`.
	 */
	public function test_leser_von_sollbestaenden_nutzen_die_spalte_target_qty() {
		$root  = dirname( __DIR__ ) . '/includes';
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		$found = 0;

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$src = (string) file_get_contents( $file->getPathname() );

			if ( false === strpos( $src, 'StockRepository::targets()' ) ) {
				continue;
			}

			++$found;
			$after = substr( $src, (int) strpos( $src, 'StockRepository::targets()' ) );
			$block = substr( $after, 0, 900 );

			$this->assertStringNotContainsString( "\$target['qty']", $block, $file->getPathname() . ' liest die falsche Spalte.' );
			$this->assertStringNotContainsString( "\$row['qty']", $block, $file->getPathname() . ' liest die falsche Spalte.' );
		}

		$this->assertGreaterThanOrEqual( 5, $found, 'Es sollten mehrere Aufrufer gefunden werden.' );
	}
}
