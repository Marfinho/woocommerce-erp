<?php
/**
 * Kurzlinks: Slug-Prüfung, Weiterleitungsziel, Query-Handling, Zähler und Herkunft.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Kurzlinks\Links;
use Lindenweg16\Modules\Kurzlinks\OrderOrigin;
use Lindenweg16\Modules\Kurzlinks\Redirect;
use Lindenweg16\Modules\Kurzlinks\Schema;
use Lindenweg16\Modules\Kurzlinks\Stats;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Vereinfachter Ersatz: Kleinbuchstaben, Bindestriche.
	 *
	 * @param string $title Text.
	 * @return string
	 */
	function sanitize_title( $title ) {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Vereinfachter Ersatz: nur erlaubte Schemata, Pfade bleiben.
	 *
	 * @param string $url       URL.
	 * @param array  $protocols Erlaubte Schemata.
	 * @return string
	 */
	function esc_url_raw( $url, $protocols = null ) {
		$scheme = parse_url( $url, PHP_URL_SCHEME );
		if ( is_string( $scheme ) && ! in_array( $scheme, (array) $protocols, true ) ) {
			return '';
		}
		return $url;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Nichtnegative Ganzzahl.
	 *
	 * @param mixed $n Wert.
	 * @return int
	 */
	function absint( $n ) {
		return abs( (int) $n );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Fester Tag, damit der Zähler im Test reproduzierbar ist.
	 *
	 * @param string $type Format.
	 * @return string
	 */
	function current_time( $type ) { // phpcs:ignore
		return isset( $GLOBALS['lw16_test_today'] ) ? $GLOBALS['lw16_test_today'] : '2026-10-23';
	}
}

/**
 * Minimale Datenbank im Speicher: versteht genau die Anweisungen von Stats.
 */
class KurzlinksFakeWpdb {

	public $prefix = 'wp_';
	public $rows   = array();
	public $log    = array();

	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$sql
		);
	}

	public function get_var( $sql ) {
		$this->log[] = $sql;
		preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m );
		$a = array_map( 'stripslashes', $m[1] );

		if ( 0 === strpos( $sql, 'SELECT COUNT(*)' ) ) {
			return count( array_filter( $this->rows, function ( $k ) use ( $a ) {
				return 0 === strpos( $k, $a[0] . '|' . $a[1] . '|' );
			}, ARRAY_FILTER_USE_KEY ) );
		}

		return isset( $this->rows[ $a[0] . '|' . $a[1] . '|' . $a[2] ] ) ? 1 : null;
	}

	public function query( $sql ) {
		$this->log[] = $sql;
		preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m );
		$a   = array_map( 'stripslashes', $m[1] );
		$key = $a[0] . '|' . $a[1] . '|' . $a[2];

		$this->rows[ $key ] = ( isset( $this->rows[ $key ] ) ? $this->rows[ $key ] : 0 ) + 1;

		return 1;
	}
}

/**
 * Tests des Moduls Kurzlinks.
 */
class KurzlinksTest extends TestCase {

	/**
	 * Standard-Reservierte aus dem Plugin.
	 *
	 * @var array
	 */
	protected $reserved;

	protected function setUp(): void {
		$this->reserved = Links::reserved_slugs();
		$GLOBALS['lw16_test_today'] = '2026-10-23';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['lw16_test_today'] );
	}

	/* ---------------- Slug-Kollision ---------------- */

	public function test_freier_slug_ist_in_ordnung() {
		$this->assertSame( '', Links::slug_error( 'flyer', 'flyer', array( 'montage' ), $this->reserved, false ) );
	}

	public function test_slug_kollidiert_mit_bestehender_seite() {
		$this->assertNotSame( '', Links::slug_error( 'kontakt', 'kontakt', array(), $this->reserved, true ) );
	}

	public function test_slug_kollidiert_mit_wordpress_reservierten() {
		foreach ( array( 'wp-admin', 'feed', 'category', 'shop', 'cart', 'checkout', 'my-account', 'erp', 'wp-json' ) as $slug ) {
			$this->assertNotSame( '', Links::slug_error( $slug, $slug, array(), $this->reserved, false ), $slug );
		}
	}

	public function test_slug_doppelt_leer_und_ungueltig() {
		$this->assertNotSame( '', Links::slug_error( 'montage', 'montage', array( 'montage' ), $this->reserved, false ) );
		$this->assertNotSame( '', Links::slug_error( '', '', array(), $this->reserved, false ) );
		$this->assertNotSame( '', Links::slug_error( 'ü', 'u%cc%88', array(), $this->reserved, false ) );
		$this->assertNotSame( '', Links::slug_error( 'x', str_repeat( 'a', 65 ), array(), $this->reserved, false ) );
	}

	/**
	 * process(): bei einer Kollision wird nichts gespeichert.
	 */
	public function test_process_lehnt_kollision_ab_und_speichert_nichts() {
		$current = array( 'montage' => $this->link( 'montage' ) );
		$rows    = array(
			array( 'orig' => 'montage', 'beschreibung' => 'neu beschrieben', 'aktiv' => '1' ),
			array( 'slug' => 'Kontakt', 'beschreibung' => 'x', 'aktiv' => '1' ),
		);

		$r = Links::process( $rows, $current, $this->reserved, function ( $slug ) {
			return 'kontakt' === $slug;
		}, 'lindenweg16.de' );

		$this->assertArrayHasKey( 1, $r['errors'] );
		$this->assertArrayNotHasKey( 0, $r['errors'] );
	}

	public function test_process_legt_neuen_link_an_und_aendert_bestehende() {
		$current = array( 'montage' => $this->link( 'montage' ) );
		$rows    = array(
			array( 'orig' => 'montage', 'beschreibung' => 'Beilage links', 'url' => 'https://www.google.com/maps', 'aktiv' => '1' ),
			array( 'slug' => 'Flyer', 'beschreibung' => 'Flyer', 'url' => '', 'seite_id' => '12', 'aktiv' => '1' ),
			array( 'slug' => '', 'beschreibung' => '' ),
		);

		$r = Links::process( $rows, $current, $this->reserved, function () {
			return false;
		}, 'lindenweg16.de' );

		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array( 'montage', 'flyer' ), array_keys( $r['links'] ) );
		$this->assertSame( 'https://www.google.com/maps', $r['links']['montage']['url'] );
		$this->assertSame( 12, $r['links']['flyer']['seite_id'] );
		$this->assertTrue( $r['links']['flyer']['aktiv'] );
	}

	public function test_process_deaktivieren_und_loeschen() {
		$current = array( 'montage' => $this->link( 'montage' ), 'bewerten' => $this->link( 'bewerten' ) );
		$rows    = array(
			array( 'orig' => 'montage', 'aktiv' => '' ),
			array( 'orig' => 'bewerten', 'loeschen' => '1', 'aktiv' => '1' ),
		);

		$r = Links::process( $rows, $current, $this->reserved, function () {
			return true; // Auch wenn inzwischen Inhalt unter dem Pfad liegt: Deaktivieren/Löschen muss gehen.
		}, 'lindenweg16.de' );

		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array( 'montage' ), array_keys( $r['links'] ) );
		$this->assertFalse( $r['links']['montage']['aktiv'] );
	}

	public function test_slug_bestehender_links_ist_nicht_umbenennbar() {
		$current = array( 'montage' => $this->link( 'montage' ) );
		$rows    = array( array( 'orig' => 'montage', 'slug' => 'anders', 'aktiv' => '1' ) );

		$r = Links::process( $rows, $current, $this->reserved, function () {
			return false;
		}, 'lindenweg16.de' );

		$this->assertSame( array( 'montage' ), array_keys( $r['links'] ) );
	}

	/* ---------------- Ziel-URL ---------------- */

	public function test_ziel_url_prueft_schema_und_schleife() {
		$slugs = array( 'montage', 'bewerten' );

		$this->assertSame( '', Links::url_error( 'https://g.page/r/abc', 'https://g.page/r/abc', 'lindenweg16.de', $slugs ) );
		$this->assertSame( '', Links::url_error( '/montage-video', '/montage-video', 'lindenweg16.de', $slugs ) );
		$this->assertNotSame( '', Links::url_error( '', 'javascript:alert(1)', 'lindenweg16.de', $slugs ) );
		$this->assertNotSame( '', Links::url_error( '//evil.example', '//evil.example', 'lindenweg16.de', $slugs ) );
		$this->assertNotSame( '', Links::url_error( 'https://lindenweg16.de/montage/', 'https://lindenweg16.de/montage/', 'lindenweg16.de', $slugs ) );
		$this->assertNotSame( '', Links::url_error( 'https://www.lindenweg16.de/bewerten', 'x', 'lindenweg16.de', $slugs ) );
	}

	/* ---------------- Query-Handling ---------------- */

	public function test_interne_ziele_bekommen_den_query_string() {
		$this->assertSame(
			'https://lindenweg16.de/montageanleitung/?utm_source=beilage',
			Redirect::build_url( 'https://lindenweg16.de/montageanleitung/', 'utm_source=beilage', true )
		);
	}

	public function test_eigene_parameter_des_ziels_gewinnen_und_fragment_bleibt() {
		$this->assertSame(
			'https://lindenweg16.de/seite/?a=1&utm_source=beilage&utm_medium=qr#video',
			Redirect::build_url( 'https://lindenweg16.de/seite/?a=1#video', 'utm_source=beilage&a=2&utm_medium=qr', true )
		);
	}

	public function test_externe_ziele_bekommen_keine_parameter() {
		$this->assertSame(
			'https://g.page/r/abc',
			Redirect::build_url( 'https://g.page/r/abc', 'utm_source=beilage', false )
		);
	}

	public function test_ohne_query_bleibt_das_ziel_unveraendert() {
		$this->assertSame( 'https://lindenweg16.de/x/', Redirect::build_url( 'https://lindenweg16.de/x/', '', true ) );
	}

	public function test_query_wird_kodiert_und_kann_keine_header_einschleusen() {
		$url = Redirect::build_url( 'https://lindenweg16.de/x/', "utm_source=a%0d%0aLocation:evil&b=<script>", true );

		$this->assertStringNotContainsString( "\n", $url );
		$this->assertStringNotContainsString( '<', $url );
	}

	public function test_intern_oder_extern() {
		$this->assertTrue( Links::is_internal( 'https://lindenweg16.de/a', 'lindenweg16.de' ) );
		$this->assertTrue( Links::is_internal( 'https://www.lindenweg16.de/a', 'lindenweg16.de' ) );
		$this->assertTrue( Links::is_internal( '/pfad', 'lindenweg16.de' ) );
		$this->assertFalse( Links::is_internal( 'https://lindenweg16.de.evil.example/a', 'lindenweg16.de' ) );
		$this->assertFalse( Links::is_internal( 'https://g.page/r/abc', 'lindenweg16.de' ) );
	}

	public function test_startseite_als_platzhalter() {
		$this->assertSame( 'https://lindenweg16.test/', Redirect::target_url( $this->link( 'montage' ) ) );
	}

	/* ---------------- Zähler ---------------- */

	public function test_bots_und_head_werden_nicht_gezaehlt() {
		$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

		$this->assertTrue( Stats::is_countable( 'GET', $iphone ) );
		$this->assertFalse( Stats::is_countable( 'HEAD', $iphone ) );
		$this->assertFalse( Stats::is_countable( 'GET', '' ) );
		$this->assertFalse( Stats::is_countable( 'GET', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ) );
		$this->assertFalse( Stats::is_countable( 'GET', 'WhatsApp/2.23.20 A' ) );
		$this->assertFalse( Stats::is_countable( 'GET', 'curl/8.4.0' ) );
		$this->assertFalse( Stats::is_countable( 'GET', 'facebookexternalhit/1.1' ) );
	}

	public function test_quelle_wird_bereinigt() {
		$this->assertSame( 'beilage', Stats::clean_source( ' Beilage ' ) );
		$this->assertSame( 'paket-beilage', Stats::clean_source( 'Paket Beilage' ) );
		$this->assertSame( 'ab-drop', Stats::clean_source( "a<b>'; DROP" ) );
		$this->assertSame( '', Stats::clean_source( array( 'x' ) ) );
		$this->assertSame( 64, strlen( Stats::clean_source( str_repeat( 'a', 200 ) ) ) );
	}

	public function test_zaehler_steigt_pro_aufruf() {
		$GLOBALS['wpdb'] = new KurzlinksFakeWpdb();

		Stats::record( 'montage', 'beilage' );
		Stats::record( 'montage', 'beilage' );
		Stats::record( 'montage', '' );
		Stats::record( 'bewerten', 'Beilage' );

		$this->assertSame(
			array(
				'2026-10-23|montage|beilage'  => 2,
				'2026-10-23|montage|'         => 1,
				'2026-10-23|bewerten|beilage' => 1,
			),
			$GLOBALS['wpdb']->rows
		);
	}

	public function test_zaehler_begrenzt_verschiedene_quellen() {
		$GLOBALS['wpdb'] = new KurzlinksFakeWpdb();

		for ( $i = 0; $i < Stats::MAX_SOURCES + 5; $i++ ) {
			Stats::record( 'montage', 'x' . $i );
		}
		Stats::record( 'montage', 'x0' ); // Bekannte Quelle zählt weiter.

		$this->assertCount( Stats::MAX_SOURCES + 1, $GLOBALS['wpdb']->rows );
		$this->assertSame( 5, $GLOBALS['wpdb']->rows['2026-10-23|montage|' . Stats::OTHER] );
		$this->assertSame( 2, $GLOBALS['wpdb']->rows['2026-10-23|montage|x0'] );
	}

	/**
	 * Datenschutz: Die Tabelle hat genau vier Spalten, nichts Personenbezogenes.
	 */
	public function test_tabelle_speichert_keine_ip_und_keinen_user_agent() {
		$sql = implode( ' ', Schema::tables( 'wp_lw16_', '' ) );

		preg_match_all( '/^\s*(\w+)\s+(?:DATE|VARCHAR|INT|BIGINT)/mi', $sql, $m );
		$this->assertSame( array( 'datum', 'slug', 'utm_source', 'anzahl' ), $m[1] );
		$this->assertDoesNotMatchRegularExpression( '/\b(ip|user_agent|ua|cookie)\b/i', $sql );

		// Und der Code, der schreibt, fasst weder Adresse noch User-Agent an.
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Kurzlinks/Stats.php' );
		$this->assertStringNotContainsString( 'REMOTE_ADDR', $src );
		$this->assertStringNotContainsString( 'HTTP_USER_AGENT', $src );
		$this->assertStringNotContainsString( 'setcookie', file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Kurzlinks/Redirect.php' ) );
	}

	public function test_gruppieren_je_link() {
		$g = Stats::group(
			array(
				array( 'slug' => 'montage', 'utm_source' => 'beilage', 'd7' => '3', 'd30' => '5', 'gesamt' => '9' ),
				array( 'slug' => 'montage', 'utm_source' => '', 'd7' => '1', 'd30' => '1', 'gesamt' => '2' ),
				array( 'slug' => 'bewerten', 'utm_source' => 'beilage', 'd7' => '0', 'd30' => '2', 'gesamt' => '2' ),
			)
		);

		$this->assertSame( array( 'd7' => 4, 'd30' => 6, 'gesamt' => 11 ), array_diff_key( $g['montage'], array( 'quellen' => 1 ) ) );
		$this->assertCount( 2, $g['montage']['quellen'] );
		$this->assertSame( 2, $g['bewerten']['gesamt'] );
	}

	/* ---------------- Bestellungen nach Herkunft ---------------- */

	public function test_herkunft_fasst_zusammen_und_zeigt_unbekannt_zuletzt() {
		$out = OrderOrigin::aggregate(
			array(
				array( 'source' => 'beilage', 'total' => 100.0 ),
				array( 'source' => ' Beilage ', 'total' => 50.5 ),
				array( 'source' => '', 'total' => 500.0 ),
				array( 'source' => false, 'total' => 20.0 ),
				array( 'source' => 'flyer', 'total' => 80.0 ),
				array( 'source' => '(direct)', 'total' => 10.0 ),
			)
		);

		$this->assertSame( array( 'beilage', 'flyer', 'direkt', 'unbekannt' ), array_keys( $out ) );
		$this->assertSame( 2, $out['beilage']['anzahl'] );
		$this->assertEqualsWithDelta( 150.5, $out['beilage']['umsatz'], 0.001 );
		$this->assertSame( 2, $out['unbekannt']['anzahl'] );
		$this->assertEqualsWithDelta( 520.0, $out['unbekannt']['umsatz'], 0.001 );
	}

	/**
	 * Hilfsfunktion: ein gespeicherter Link.
	 *
	 * @param string $slug Slug.
	 * @return array
	 */
	protected function link( $slug ) {
		return array(
			'slug'         => $slug,
			'beschreibung' => '',
			'seite_id'     => 0,
			'url'          => '',
			'aktiv'        => true,
		);
	}
}
