<?php
/**
 * Systemprüfung: Widersprüche, die beim Speichern noch keine waren.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\Consistency;
use PHPUnit\Framework\TestCase;

/**
 * Jede Regel einzeln – und der Nachweis, dass sie schweigt, wenn alles
 * stimmt. Eine Prüfung, die immer etwas findet, liest bald niemand mehr.
 */
class BetriebConsistencyTest extends TestCase {

	/**
	 * Ein Artikel.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	protected function artikel( array $over = array() ) {
		return array_merge(
			array(
				'id'             => 1,
				'sku'            => 'HH-001',
				'name'           => 'Basisplatte',
				'status'         => 'aktiv',
				'sourcing_type'  => 'druck',
				'sellable'       => 1,
				'wc_product_id'  => 10,
				'default_bin_id' => null,
			),
			$over
		);
	}

	/**
	 * Ein vollständig stimmiger Zustand ergibt keine Befunde.
	 */
	public function test_stimmiger_zustand_schweigt() {
		$befunde = Consistency::check(
			array(
				'articles'   => array( 1 => $this->artikel() ),
				'revisions'  => array( 1 => true ),
				'stock'      => array( 1 => 5 ),
				'wc_missing' => array(),
			)
		);

		$this->assertSame( array(), $befunde );
	}

	/**
	 * Montageartikel ohne Stückliste lässt sich nicht fertigen.
	 */
	public function test_montage_ohne_stueckliste() {
		$befunde = Consistency::check_makeable(
			array( 1 => $this->artikel( array( 'sourcing_type' => 'montage' ) ) ),
			array()
		);

		$this->assertCount( 1, $befunde );
		$this->assertSame( 'fehler', $befunde[0]['level'] );
		$this->assertStringContainsString( 'Stückliste', $befunde[0]['text'] );
	}

	/**
	 * Mit Stückliste ist alles in Ordnung.
	 */
	public function test_montage_mit_stueckliste_ist_still() {
		$befunde = Consistency::check_makeable(
			array( 1 => $this->artikel( array( 'sourcing_type' => 'montage' ) ) ),
			array( 1 => 3 )
		);

		$this->assertSame( array(), $befunde );
	}

	/**
	 * Ein stillgelegter Artikel wird nicht mehr bemängelt – er soll ja
	 * nicht mehr gefertigt werden.
	 */
	public function test_stillgelegter_artikel_wird_nicht_bemaengelt() {
		$befunde = Consistency::check_makeable(
			array( 1 => $this->artikel( array( 'sourcing_type' => 'montage', 'status' => 'ausgelaufen' ) ) ),
			array()
		);

		$this->assertSame( array(), $befunde );
	}

	/**
	 * Gedruckter Artikel ohne freigegebene Revision.
	 */
	public function test_druck_ohne_revision() {
		$befunde = Consistency::check_printable( array( 1 => $this->artikel() ), array() );

		$this->assertCount( 1, $befunde );
		$this->assertSame( 'fehler', $befunde[0]['level'] );
	}

	/**
	 * Ein Zukaufartikel braucht keine Revision.
	 */
	public function test_zukauf_braucht_keine_revision() {
		$befunde = Consistency::check_printable(
			array( 1 => $this->artikel( array( 'sourcing_type' => 'zukauf' ) ) ),
			array()
		);

		$this->assertSame( array(), $befunde );
	}

	/**
	 * Verkäuflich ohne Shop-Verknüpfung: beim Verkauf kein Lagerabgang.
	 */
	public function test_verkaeuflich_ohne_verknuepfung() {
		$befunde = Consistency::check_shop_links(
			array( 1 => $this->artikel( array( 'wc_product_id' => 0 ) ) ),
			array()
		);

		$this->assertCount( 1, $befunde );
		$this->assertStringContainsString( 'Lagerabgang', $befunde[0]['fix'] );
	}

	/**
	 * Zwei Artikel auf demselben Shop-Produkt buchen doppelt ab.
	 */
	public function test_doppelte_verknuepfung() {
		$befunde = Consistency::check_shop_links(
			array(
				1 => $this->artikel(),
				2 => $this->artikel( array( 'id' => 2, 'sku' => 'HH-002' ) ),
			),
			array()
		);

		$this->assertCount( 1, $befunde );
		$this->assertStringContainsString( 'HH-001', $befunde[0]['text'] );
		$this->assertStringContainsString( 'HH-002', $befunde[0]['text'] );
	}

	/**
	 * Eine Verknüpfung ins Leere.
	 */
	public function test_verknuepfung_ins_leere() {
		$befunde = Consistency::check_shop_links(
			array( 1 => $this->artikel() ),
			array( 1 => 10 )
		);

		$this->assertCount( 1, $befunde );
		$this->assertStringContainsString( '10', $befunde[0]['text'] );
	}

	/**
	 * Negativer Bestand heißt: Es fehlt ein Zugang.
	 */
	public function test_negativer_bestand() {
		$befunde = Consistency::check_stock( array( 1 => $this->artikel() ), array( 1 => -3 ) );

		$this->assertCount( 1, $befunde );
		$this->assertSame( 'fehler', $befunde[0]['level'] );
		$this->assertStringContainsString( 'fehlende Bewegung', $befunde[0]['fix'] );
	}

	/**
	 * Null ist kein negativer Bestand.
	 */
	public function test_null_ist_kein_minus() {
		$this->assertSame( array(), Consistency::check_stock( array(), array( 1 => 0 ) ) );
	}

	/**
	 * Sollbestand für einen stillgelegten Artikel ist tote Vorgabe.
	 */
	public function test_sollbestand_fuer_stillgelegten_artikel() {
		$befunde = Consistency::check_targets(
			array( 1 => $this->artikel( array( 'status' => 'ausgelaufen' ) ) ),
			array( array( 'article_id' => 1 ) )
		);

		$this->assertCount( 1, $befunde );
		$this->assertSame( 'warnung', $befunde[0]['level'] );
	}

	/**
	 * Standardplatz auf einem stillgelegten Fach.
	 */
	public function test_standardplatz_stillgelegt() {
		$befunde = Consistency::check_bins(
			array( 1 => $this->artikel( array( 'default_bin_id' => 7 ) ) ),
			array( 7 => false )
		);

		$this->assertCount( 1, $befunde );
		$this->assertSame( 'warnung', $befunde[0]['level'] );
	}

	/**
	 * Ein aktiver Platz ist kein Befund.
	 */
	public function test_aktiver_standardplatz_ist_still() {
		$this->assertSame(
			array(),
			Consistency::check_bins(
				array( 1 => $this->artikel( array( 'default_bin_id' => 7 ) ) ),
				array( 7 => true )
			)
		);
	}

	/**
	 * Ein offener Druckauftrag, dessen Artikel inzwischen montiert wird.
	 */
	public function test_auftrag_passt_nicht_mehr() {
		$befunde = Consistency::check_open_orders(
			array( 1 => $this->artikel( array( 'sourcing_type' => 'montage' ) ) ),
			array( array( 'article_id' => 1, 'kind' => 'druck', 'code' => 'DA260821-01' ) )
		);

		$this->assertCount( 1, $befunde );
		$this->assertStringContainsString( 'DA260821-01', $befunde[0]['text'] );
	}

	/**
	 * Fehler stehen vor Warnungen – was blockiert, gehört nach oben.
	 */
	public function test_fehler_stehen_oben() {
		$befunde = Consistency::check(
			array(
				'articles' => array(
					1 => $this->artikel( array( 'default_bin_id' => 7 ) ),
					2 => $this->artikel( array( 'id' => 2, 'sku' => 'HH-002', 'wc_product_id' => 0 ) ),
				),
				'revisions' => array( 1 => true, 2 => true ),
				'bins'      => array( 7 => false ),
			)
		);

		$this->assertGreaterThanOrEqual( 2, count( $befunde ) );
		$this->assertSame( 'fehler', $befunde[0]['level'] );
		$this->assertSame( 'warnung', end( $befunde )['level'] );
	}

	/**
	 * Solange Produkte an der alten Lagerverwaltung hängen, sagt die
	 * Prüfung das – sie ist das Maß dafür, wie weit der Umzug ist.
	 */
	public function test_alte_lagerverwaltung_wird_gemeldet() {
		$befunde = Consistency::check_legacy_stock(
			array(
				array( 'id' => 10, 'name' => 'Wandhaken klein' ),
				array( 'id' => 11, 'name' => 'Wandhaken groß' ),
			)
		);

		$this->assertCount( 1, $befunde );
		$this->assertStringContainsString( '2 Shop-Produkte', $befunde[0]['text'] );
		$this->assertStringContainsString( 'Wandhaken klein', $befunde[0]['text'] );
	}

	/**
	 * Ist die Liste leer, bucht nur noch eine Verwaltung – kein Befund.
	 */
	public function test_ohne_altlasten_kein_befund() {
		$this->assertSame( array(), Consistency::check_legacy_stock( array() ) );
	}

	/**
	 * Viele Produkte werden zusammengefasst, nicht alle aufgezählt.
	 */
	public function test_viele_produkte_werden_zusammengefasst() {
		$produkte = array();

		for ( $i = 1; $i <= 9; $i++ ) {
			$produkte[] = array( 'id' => $i, 'name' => 'Produkt ' . $i );
		}

		$befunde = Consistency::check_legacy_stock( $produkte );

		$this->assertStringContainsString( '9 Shop-Produkte', $befunde[0]['text'] );
		$this->assertStringContainsString( 'weitere', $befunde[0]['text'] );
		$this->assertStringNotContainsString( 'Produkt 9', $befunde[0]['text'] );
	}

	/**
	 * Ein Produkt ohne Namen wird über seine Nummer benannt, nicht mit
	 * einer Leerstelle.
	 */
	public function test_produkt_ohne_namen() {
		$befunde = Consistency::check_legacy_stock( array( array( 'id' => 42, 'name' => '' ) ) );

		$this->assertStringContainsString( '#42', $befunde[0]['text'] );
	}

	/**
	 * Die Zusammenfassung zählt nach Schwere.
	 */
	public function test_zusammenfassung_zaehlt() {
		$summe = Consistency::summarize(
			array(
				array( 'level' => 'fehler' ),
				array( 'level' => 'fehler' ),
				array( 'level' => 'warnung' ),
			)
		);

		$this->assertSame( 2, $summe['fehler'] );
		$this->assertSame( 1, $summe['warnung'] );
	}
}
