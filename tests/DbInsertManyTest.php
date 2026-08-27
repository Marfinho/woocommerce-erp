<?php
/**
 * Tests für den Stapel-Einfüger (Lindenweg16\Core\Db).
 *
 * Geprüft wird der erzeugte Befehl, nicht die Datenbank: Spaltenliste,
 * Bindung der Werte, NULL als Schlüsselwort statt als leere Zeichenkette,
 * die Aufteilung in Stapel und das Verhalten bei leerer Eingabe.
 *
 * Die Attrappe bildet `prepare()` so nach, wie WordPress es tut – Werte
 * werden maskiert eingesetzt. Damit lässt sich zeigen, dass ein Wert Wert
 * bleibt und nie zu Befehl wird.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\Db;

/**
 * Minimale $wpdb-Attrappe.
 */
class LW16_FakeWpdb {

	/** @var string[] Abgesetzte Befehle. */
	public $sql = array();

	/**
	 * Platzhalter binden wie WordPress.
	 *
	 * @param string $query  Befehl.
	 * @param mixed  ...$args Werte.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return preg_replace_callback(
			'/%[sdf]/',
			static function ( $match ) use ( &$i, $args ) {
				$value = isset( $args[ $i ] ) ? $args[ $i ] : null;
				$i++;

				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}
				if ( '%f' === $match[0] ) {
					// Wie wpdb::prepare: sprachunabhängig über %F.
					return sprintf( '%F', (float) $value );
				}

				return "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	/**
	 * Befehl merken, Anzahl der Wertetupel zurückgeben.
	 *
	 * @param string $query Befehl.
	 * @return int
	 */
	public function query( $query ) {
		$this->sql[] = $query;

		return substr_count( $query, '), (' ) + 1;
	}
}

/**
 * @covers \Lindenweg16\Core\Db
 */
class DbInsertManyTest extends TestCase {

	/** @var LW16_FakeWpdb */
	protected $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new LW16_FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	public function test_erzeugt_einen_befehl_fuer_mehrere_zeilen() {
		$eingefuegt = Db::insert_many(
			'wp_items',
			array(
				array( 'picklist_id' => 7, 'article_id' => 3, 'qty' => 2.5 ),
				array( 'picklist_id' => 7, 'article_id' => 4, 'qty' => 1.0 ),
			)
		);

		$this->assertCount( 1, $this->wpdb->sql, 'Zwei Zeilen gehören in einen Befehl.' );
		$this->assertSame( 2, $eingefuegt );
		$this->assertStringContainsString(
			'INSERT INTO wp_items (`picklist_id`, `article_id`, `qty`) VALUES',
			$this->wpdb->sql[0]
		);
	}

	public function test_null_bleibt_null_und_wird_nicht_zur_leeren_zeichenkette() {
		Db::insert_many( 'wp_items', array( array( 'bin_id' => null, 'article_id' => 3 ) ) );

		$this->assertStringContainsString( '(NULL, ', $this->wpdb->sql[0] );
		$this->assertStringNotContainsString( "('', ", $this->wpdb->sql[0] );
	}

	public function test_werte_werden_gebunden_und_bleiben_werte() {
		Db::insert_many( 'wp_items', array( array( 'note' => "1'); DROP TABLE wp_items; --" ) ) );

		// Das Anführungszeichen ist maskiert, der Befehl endet nicht nach
		// dem eingeschleusten Semikolon.
		$this->assertStringContainsString( "\\'", $this->wpdb->sql[0] );
		$this->assertSame( 1, substr_count( $this->wpdb->sql[0], 'INSERT INTO' ) );
	}

	public function test_grosse_mengen_werden_in_stapel_geteilt() {
		$rows = array();
		for ( $i = 0; $i < 450; $i++ ) {
			$rows[] = array( 'a' => $i, 'b' => 'x' );
		}

		$eingefuegt = Db::insert_many( 'wp_items', $rows );

		$this->assertCount( 3, $this->wpdb->sql, '450 Zeilen bei Stapelgröße 200 sind drei Befehle.' );
		$this->assertSame( 450, $eingefuegt );
	}

	public function test_kommazahlen_bleiben_sprachunabhaengig() {
		// Vor PHP 8 richtete sich die Umwandlung einer Kommazahl in eine
		// Zeichenkette nach der Sprachumgebung. Wäre der Wert als `%s`
		// gebunden, stünde auf einem deutschen Server "2,5" im Befehl – und
		// in der Spalte danach eine 2.
		$alt = setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German' );

		Db::insert_many( 'wp_items', array( array( 'menge' => 2.5 ) ) );

		if ( false !== $alt ) {
			setlocale( LC_NUMERIC, $alt );
		}

		$this->assertStringContainsString( '2.5', $this->wpdb->sql[0] );
		$this->assertStringNotContainsString( '2,5', $this->wpdb->sql[0] );
	}

	public function test_ganze_zahlen_werden_ohne_anfuehrungszeichen_gebunden() {
		Db::insert_many( 'wp_items', array( array( 'article_id' => 42 ) ) );

		$this->assertStringContainsString( '(42)', $this->wpdb->sql[0] );
	}

	public function test_leere_eingabe_fasst_die_datenbank_nicht_an() {
		$this->assertSame( 0, Db::insert_many( 'wp_items', array() ) );
		$this->assertSame( 0, Db::insert_many( 'wp_items', array( 'kein_array' ) ) );
		$this->assertSame( array(), $this->wpdb->sql );
	}
}
