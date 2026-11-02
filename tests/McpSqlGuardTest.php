<?php
/**
 * Nur-Lese-Prüfung für die Datenbankabfragen des MCP-Zugangs.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Mcp\SqlGuard;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/Mcp/SqlGuard.php';

/**
 * Tests für Mcp\SqlGuard.
 */
class McpSqlGuardTest extends TestCase {

	/**
	 * @dataProvider erlaubt
	 */
	public function test_leseabfragen_sind_erlaubt( $sql ) {
		$this->assertSame( '', SqlGuard::check( $sql ), $sql );
	}

	public function erlaubt() {
		return array(
			array( 'SELECT * FROM wp_lw16_belege WHERE status = \'offen\'' ),
			array( 'select count(*) from wp_posts;' ),
			array( "SELECT REPLACE(name, 'a', 'b') FROM wp_lw16_artikel" ),
			array( 'WITH x AS (SELECT 1 AS a) SELECT a FROM x' ),
			array( 'SHOW TABLES' ),
			array( 'SHOW CREATE TABLE wp_lw16_belege' ),
			array( 'DESCRIBE wp_lw16_belege' ),
			array( 'EXPLAIN SELECT 1' ),
			array( "SELECT * FROM t WHERE note = 'bitte DELETE FROM t; ausführen'" ),
			array( 'SELECT date_updated, updated_at FROM t ORDER BY id DESC' ),
			array( "SELECT 'it''s' AS x -- Kommentar mit DROP\n FROM dual" ),
		);
	}

	/**
	 * @dataProvider verboten
	 */
	public function test_schreibendes_wird_abgelehnt( $sql ) {
		$this->assertNotSame( '', SqlGuard::check( $sql ), $sql );
	}

	public function verboten() {
		return array(
			array( '' ),
			array( 'DELETE FROM wp_posts' ),
			array( 'UPDATE wp_options SET option_value = 1' ),
			array( 'SELECT 1; DROP TABLE wp_posts' ),
			array( 'WITH x AS (SELECT 1) DELETE FROM wp_posts' ),
			array( 'SELECT * INTO OUTFILE \'/tmp/x\' FROM wp_posts' ),
			array( 'SELECT * FROM wp_posts FOR UPDATE' ),
			array( 'SELECT SLEEP(100)' ),
			array( 'SELECT BENCHMARK(100000000, MD5(1))' ),
			array( 'SELECT LOAD_FILE(\'/etc/passwd\')' ),
			array( 'SELECT user_pass FROM wp_users' ),
			array( "SELECT meta_value FROM wp_usermeta WHERE meta_key = 'session_tokens'" ),
			array( "SELECT option_value FROM wp_options WHERE option_name = 'lw16_mcp_tokens'" ),
			array( 'SELECT /*! 1; DROP TABLE x */ 1' ),
			array( "SELECT 'offen" ),
			array( 'SELECT @@version' ),
			array( 'SET @a = 1' ),
			array( 'LOCK TABLES wp_posts READ' ),
			array( 'CALL foo()' ),
		);
	}

	public function test_limit_wird_angehaengt() {
		$this->assertSame( 'SELECT * FROM t LIMIT 201', SqlGuard::with_limit( 'SELECT * FROM t;', 201 ) );
		$this->assertSame( 'SELECT * FROM t LIMIT 5', SqlGuard::with_limit( 'SELECT * FROM t LIMIT 5', 201 ) );
		$this->assertSame( 'SHOW TABLES', SqlGuard::with_limit( 'SHOW TABLES', 201 ) );
		// „limit“ in einem Text zählt nicht.
		$this->assertSame( "SELECT * FROM t WHERE x = 'limit' LIMIT 10", SqlGuard::with_limit( "SELECT * FROM t WHERE x = 'limit'", 10 ) );
	}
}
