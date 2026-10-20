<?php
/**
 * Etiketten gehen als je ein Druckauftrag in die Warteschlange.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

class ErpLabelJobsTest extends TestCase {

	/**
	 * @return array Quelltext der Stellen, die Etiketten einreihen.
	 */
	public function sources() {
		return array(
			'Workflow' => array( dirname( __DIR__ ) . '/includes/Erp/Workflow.php' ),
			'Exporter' => array( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Exporter.php' ),
		);
	}

	/**
	 * @dataProvider sources
	 */
	public function test_jedes_etikett_wird_ein_eigener_druckauftrag( $file ) {
		$src = file_get_contents( $file );

		$this->assertMatchesRegularExpression( '/foreach \( \$labels as \$label \) \{\s+\$pdf\s+= LabelPdf::build_roll\( array\( \$label \)/', $src );
	}
}
