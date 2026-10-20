<?php
/**
 * Storno einer Bestellung und Deinstallation.
 *
 * Beides läuft erst mit WordPress und WooCommerce wirklich; hier wird
 * gesichert, dass die Verdrahtung nicht still verloren geht. Das Verhalten
 * selbst wurde in einer echten Installation durchgespielt.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Verdrahtung von Storno, Einstellungen und Deinstallation.
 */
class StornoUndDeinstallationTest extends TestCase {

	/**
	 * Quelltext lesen.
	 *
	 * @param string $path Pfad ab Plugin-Wurzel.
	 * @return string
	 */
	protected function src( $path ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $path );
	}

	/**
	 * Stornierte und erstattete Bestellungen nehmen den Lagerabgang zurück.
	 */
	public function test_lager_wird_bei_storno_und_erstattung_zurueckgebucht() {
		$src = $this->src( 'includes/Modules/Betrieb/WooIntegration.php' );

		$this->assertStringContainsString( "'woocommerce_order_status_cancelled', array( \$this, 'reverse_stock' )", $src );
		$this->assertStringContainsString( "'woocommerce_order_status_refunded', array( \$this, 'reverse_stock' )", $src );
		$this->assertStringContainsString( 'StockRepository::void(', $src );
		$this->assertStringContainsString( 'delete_meta_data( self::META_BOOKED )', $src, 'Nach dem Storno muss eine erneut abgeschlossene Bestellung wieder buchen.' );
	}

	/**
	 * Die Rückbuchung läuft unter derselben Sperre wie der Abgang.
	 */
	public function test_rueckbuchung_nutzt_dieselbe_sperre_wie_der_abgang() {
		$src = $this->src( 'includes/Modules/Betrieb/WooIntegration.php' );

		$this->assertSame( 2, substr_count( $src, "Lock::acquire( 'wc_stock_' . (int) \$order_id, 10 )" ) );
	}

	/**
	 * Rechnungen werden bei Storno und Erstattung storniert, nicht gelöscht.
	 */
	public function test_rechnung_wird_bei_storno_storniert() {
		$src = $this->src( 'includes/Modules/Accounting/AccountingModule.php' );

		$this->assertStringContainsString( "'woocommerce_order_status_cancelled', array( \$this, 'storno_invoice_for_order' )", $src );
		$this->assertStringContainsString( "'woocommerce_order_status_refunded', array( \$this, 'storno_invoice_for_order' )", $src );
		$this->assertStringContainsString( 'InvoiceGenerator::stornieren(', $src );
		$this->assertStringNotContainsString( 'DELETE FROM', substr( $src, (int) strpos( $src, 'function storno_invoice_for_order' ), 900 ) );
	}

	/**
	 * Beide Automatiken lassen sich abschalten und sind standardmäßig an.
	 */
	public function test_automatiken_sind_einstellbar() {
		$settings = $this->src( 'includes/Core/Settings.php' );
		$page     = $this->src( 'includes/Admin/SettingsPage.php' );

		foreach ( array( 'rechnung_auto_storno', 'betrieb_storno_lager' ) as $key ) {
			$this->assertMatchesRegularExpression( "/'" . $key . "'\s*=> 1,/", $settings );
			$this->assertStringContainsString( "'" . $key . "' ", $page );
		}
	}

	/**
	 * Die Deinstallation löscht ohne ausdrückliche Freigabe nichts.
	 */
	public function test_deinstallation_loescht_nur_mit_freigabe() {
		$src = $this->src( 'uninstall.php' );

		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $src );
		$this->assertStringContainsString( 'LW16_REMOVE_ALL_DATA', $src );
		$this->assertLessThan(
			(int) strpos( $src, 'DROP TABLE' ),
			(int) strpos( $src, 'LW16_REMOVE_ALL_DATA' ),
			'Die Freigabeprüfung muss vor dem ersten Löschen stehen.'
		);
	}

	/**
	 * Das Sprachverzeichnis aus dem Plugin-Kopf existiert.
	 */
	public function test_sprachverzeichnis_existiert() {
		$this->assertDirectoryExists( dirname( __DIR__ ) . '/languages' );
	}
}
