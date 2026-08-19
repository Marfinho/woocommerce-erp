<?php
/**
 * Rekursive Stücklistenauflösung, Zyklenerkennung, Tiefenbegrenzung.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\BomResolver;

/**
 * Prüft die Auflösung mehrstufiger Stücklisten.
 */
class BetriebBomResolverTest extends TestCase {

	/**
	 * Stange mit zwei gedruckten Unterbaugruppen und Kleinteilen.
	 *
	 * @return array
	 */
	protected function ctx() {
		return array(
			'bom' => array(
				100 => array(
					array( 'child_type' => 'artikel', 'child_id' => 101, 'qty' => 2 ),
					array( 'child_type' => 'artikel', 'child_id' => 102, 'qty' => 2 ),
					array( 'child_type' => 'komponente', 'child_id' => 20, 'qty' => 4 ),
				),
				101 => array(
					array( 'child_type' => 'komponente', 'child_id' => 21, 'qty' => 1 ),
				),
			),
		);
	}

	/**
	 * Einstufige Auflösung liefert alle direkten Zeilen.
	 */
	public function test_direkte_zeilen() {
		$result = BomResolver::resolve( 100, $this->ctx() );

		$this->assertEmpty( $result['errors'] );
		$this->assertCount( 4, $result['lines'], '3 direkte Zeilen + 1 aus der Unterbaugruppe.' );
	}

	/**
	 * Mengen multiplizieren sich über die Ebenen: 2 Abstandshalter mit je
	 * einem Gewindeeinsatz ergeben 2 Gewindeeinsätze.
	 */
	public function test_mengen_multiplizieren_sich() {
		$result = BomResolver::resolve( 100, $this->ctx() );

		$gefunden = null;
		foreach ( $result['lines'] as $line ) {
			if ( 'komponente' === $line['child_type'] && 21 === $line['child_id'] ) {
				$gefunden = $line;
			}
		}

		$this->assertNotNull( $gefunden );
		$this->assertEqualsWithDelta( 2.0, $gefunden['qty'], 0.00001 );
		$this->assertEqualsWithDelta( 1.0, $gefunden['qty_each'], 0.00001 );
		$this->assertSame( 1, $gefunden['depth'] );
	}

	/**
	 * Die Wurzelmenge schlägt auf alle Ebenen durch.
	 */
	public function test_wurzelmenge_schlaegt_durch() {
		$result = BomResolver::resolve( 100, $this->ctx(), 3.0 );

		foreach ( $result['lines'] as $line ) {
			if ( 'komponente' === $line['child_type'] && 20 === $line['child_id'] ) {
				$this->assertEqualsWithDelta( 12.0, $line['qty'], 0.00001 );
			}
			if ( 'komponente' === $line['child_type'] && 21 === $line['child_id'] ) {
				$this->assertEqualsWithDelta( 6.0, $line['qty'], 0.00001 );
			}
		}
	}

	/**
	 * Ein Artikel, der sich selbst enthält, wird erkannt und nicht endlos
	 * verfolgt.
	 */
	public function test_direkter_zyklus_wird_erkannt() {
		$ctx = array(
			'bom' => array(
				1 => array( array( 'child_type' => 'artikel', 'child_id' => 1, 'qty' => 1 ) ),
			),
		);

		$result = BomResolver::resolve( 1, $ctx );

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 'cycle', $result['errors'][0]['type'] );
	}

	/**
	 * Auch ein Zyklus über mehrere Ebenen bricht die Auflösung ab.
	 */
	public function test_indirekter_zyklus_wird_erkannt() {
		$ctx = array(
			'bom' => array(
				1 => array( array( 'child_type' => 'artikel', 'child_id' => 2, 'qty' => 1 ) ),
				2 => array( array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ) ),
				3 => array( array( 'child_type' => 'artikel', 'child_id' => 1, 'qty' => 1 ) ),
			),
		);

		$result = BomResolver::resolve( 1, $ctx );

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 'cycle', $result['errors'][0]['type'] );
	}

	/**
	 * Eine Komponente darf mehrfach vorkommen – das ist kein Zyklus.
	 */
	public function test_mehrfache_komponente_ist_kein_zyklus() {
		$ctx = array(
			'bom' => array(
				1 => array(
					array( 'child_type' => 'komponente', 'child_id' => 9, 'qty' => 2 ),
					array( 'child_type' => 'artikel', 'child_id' => 2, 'qty' => 1 ),
				),
				2 => array(
					array( 'child_type' => 'komponente', 'child_id' => 9, 'qty' => 3 ),
				),
			),
		);

		$result = BomResolver::resolve( 1, $ctx );

		$this->assertEmpty( $result['errors'] );
		$this->assertCount( 3, $result['lines'] );
	}

	/**
	 * Ab der sechsten Ebene wird abgebrochen, nicht stillschweigend
	 * abgeschnitten.
	 */
	public function test_tiefenbegrenzung_greift() {
		$ctx = array( 'bom' => array() );
		for ( $i = 1; $i <= 9; $i++ ) {
			$ctx['bom'][ $i ] = array( array( 'child_type' => 'artikel', 'child_id' => $i + 1, 'qty' => 1 ) );
		}

		$result = BomResolver::resolve( 1, $ctx );

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 'depth', $result['errors'][0]['type'] );
		$this->assertCount( 5, $result['lines'], 'Genau fünf Ebenen werden aufgelöst.' );
	}

	/**
	 * Eine Stückliste mit genau fünf Ebenen ist noch zulässig.
	 */
	public function test_fuenf_ebenen_sind_zulaessig() {
		$ctx = array( 'bom' => array() );
		for ( $i = 1; $i <= 5; $i++ ) {
			$ctx['bom'][ $i ] = array( array( 'child_type' => 'artikel', 'child_id' => $i + 1, 'qty' => 1 ) );
		}

		$result = BomResolver::resolve( 1, $ctx );

		$this->assertEmpty( $result['errors'] );
		$this->assertCount( 5, $result['lines'] );
	}

	/**
	 * Ein Artikel darf sich nicht selbst als Kind bekommen.
	 */
	public function test_selbstbezug_wird_beim_speichern_verhindert() {
		$this->assertTrue( BomResolver::would_create_cycle( 1, 1, array( 'bom' => array() ) ) );
	}

	/**
	 * Ein Kind, das den Elternartikel bereits enthält, darf nicht eingehängt
	 * werden – sonst entsteht der Zyklus erst beim nächsten Aufruf.
	 */
	public function test_zyklus_beim_einhaengen_wird_verhindert() {
		$ctx = array(
			'bom' => array(
				2 => array( array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ) ),
				3 => array( array( 'child_type' => 'artikel', 'child_id' => 1, 'qty' => 1 ) ),
			),
		);

		$this->assertTrue( BomResolver::would_create_cycle( 1, 2, $ctx ) );
	}

	/**
	 * Ein unverfängliches Einhängen bleibt erlaubt.
	 */
	public function test_unverfaengliches_einhaengen_ist_erlaubt() {
		$ctx = array(
			'bom' => array(
				2 => array( array( 'child_type' => 'komponente', 'child_id' => 9, 'qty' => 1 ) ),
			),
		);

		$this->assertFalse( BomResolver::would_create_cycle( 1, 2, $ctx ) );
	}

	/**
	 * Alle beteiligten Artikel-IDs für die Chargen-Rückverfolgung.
	 */
	public function test_beteiligte_artikel_ids() {
		$ids = BomResolver::article_ids( 100, $this->ctx() );

		sort( $ids );
		$this->assertSame( array( 100, 101, 102 ), $ids );
	}
}
