<?php
/**
 * Bestellung und Geschäftskunde zusammenbringen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\OrderContact;
use PHPUnit\Framework\TestCase;

class ErpOrderContactTest extends TestCase {

	public function test_kundenart_folgt_der_kontaktart() {
		$this->assertSame( 'B2B', OrderContact::customer_type( 'haendler' ) );
		$this->assertSame( 'B2B', OrderContact::customer_type( 'firma' ) );
		$this->assertSame( 'Verein', OrderContact::customer_type( 'verein' ) );
		$this->assertSame( 'Presse', OrderContact::customer_type( 'presse' ) );
	}

	public function test_vergleichstext_ignoriert_gross_umlaute_und_zeichen() {
		$this->assertSame( OrderContact::fold( 'Müller-Bräu' ), OrderContact::fold( 'MUELLER BRAEU' ) );
		$this->assertSame( 'mueller gmbh', OrderContact::fold( '  Müller  GmbH ' ) );
	}

	public function test_gleiche_email_vor_gleicher_firma() {
		$contacts = array(
			array( 'id' => 1, 'company' => 'Café Linde', 'email' => 'x@y.de' ),
			array( 'id' => 2, 'company' => 'Anders AG', 'email' => 'Info@Linde.de' ),
		);

		$s = OrderContact::suggest( $contacts, 'info@linde.de', 'Café Linde' );

		$this->assertSame( 2, $s[0]['id'] );
		$this->assertSame( 'email', $s[0]['why'] );
		$this->assertSame( 1, $s[1]['id'] );
		$this->assertSame( 'firma', $s[1]['why'] );
	}

	public function test_ohne_treffer_kein_vorschlag_und_leere_angaben_passen_auf_nichts() {
		$contacts = array( array( 'id' => 1, 'company' => '', 'email' => '' ) );

		$this->assertSame( array(), OrderContact::suggest( $contacts, '', '' ) );
		$this->assertSame( array(), OrderContact::suggest( $contacts, 'a@b.de', 'Firma' ) );
	}

	public function test_zuordnung_braucht_beide_rechte() {
		$rest = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/RestApi.php' );

		$this->assertMatchesRegularExpression( "#versand/\\(\\?P<id>\\\\d\\+\\)/kontakt.*?'verkauf\\.versand', 'verkauf\\.kontakte'#s", $rest );
	}
}
