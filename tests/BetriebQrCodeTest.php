<?php
/**
 * QR-Encoder.
 *
 * Die Prüfsummen unten stammen aus einer etablierten Fremdimplementierung
 * (Python-Bibliothek "qrcode"). Beim Bau wurde jede Kombination aus Version 1
 * bis 12, allen vier Fehlerkorrekturstufen und allen acht Masken modulgenau
 * gegen sie abgeglichen. Dieser Test hält davon so viel fest, wie sich ohne
 * die Fremdbibliothek prüfen lässt: Wenn hier etwas rot wird, weicht der
 * Encoder vom Referenzverhalten ab – und dann liest der Scanner womöglich
 * nichts mehr.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\QrCode;

/**
 * Prüft Raster, Kapazitäten und Grenzen.
 */
class BetriebQrCodeTest extends TestCase {

	/**
	 * Raster als Zeichenkette, wie sie in die Prüfsumme eingeht.
	 *
	 * @param array $matrix Raster.
	 * @return string
	 */
	protected function flatten( array $matrix ) {
		$lines = array();
		foreach ( $matrix as $row ) {
			$lines[] = implode( '', $row );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Jeder Testfall muss modulgenau der Referenz entsprechen.
	 */
	public function test_raster_entspricht_der_referenz() {
		$cases = array(
			array( 'A', 'L', 21, 'f195dbbdf146dae0ddeba600cfb966e631eeab6cbf78410286bda1914ca89458' ),
			array( 'A', 'M', 21, '177b3a1897a152aee17ab1ee4b667913c6bb873e6ba357debfc5ba243dabff0e' ),
			array( 'A', 'Q', 21, '05e65ce3a3fc956052468fca69f5b6ba64efe49f8629123ae76390b1ef7ad919' ),
			array( 'A', 'H', 21, '92c6cb5733679259e26ef45082f9932a784f2c39836069e17622bf87e2802e2c' ),
			array( 'HALLO', 'M', 21, '54d89093e81136dbbbd6c779024979fbd4dfbfa51fbc0be3653415f37a2079e2' ),
			array( 'LW16-BIN-A01', 'Q', 25, 'b865145d1143995d6854f12374fb66704535b39ef7295f8b6d8c242283fc1e99' ),
			array( '2608-HH-001', 'H', 25, '199b8bbf3c2c914f3504093442238bb2319fae21cab22d6c59ca28c0ec96c709' ),
			array( '0123456789', 'L', 21, 'c625d2699efba1caeb0178a7f6d1a4898b42590a584bcff03e42ecc0f2cdf61c' ),
			array( 'https://lindenweg16.de/betrieb-erfassen/?p=P1', 'M', 33, '4a6868e29f110b2283ba62263ad08bc3f416ee23a441c2fe4a59aec096b8249f' ),
			array( 'https://lindenweg16.de/nachbestellen/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/', 'M', 41, '235db9edabdf5453b83149c09e16c16c236800bb5bc9729c98bd681cbd7725c9' ),
			array( 'https://lindenweg16.de/nachbestellen/ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/', 'L', 37, 'b3c951eb79dc554f910b87a6899407993ebcd82b6412585e0cb60e2eb0488790' ),
			array( 'Gruesse aus Osnabrueck', 'M', 25, 'bcf671f342468b13ca7fcf8c313f2953e60d90c361302d6866e8e1eff307cae1' ),
			array( 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', 'Q', 53, '98c01e8738578b8569e60e4a23999fb870e10bd7416a9ccb0b8c0587e2a24ef0' ),
			array( 'YYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYY', 'L', 53, '53f38b2901d7cdc691af8bd0e5366f128eff5f3c9fef25cc348ac84d3869666c' ),
			array( 'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ', 'H', 45, '8356ffb9201651296a29a66b69a80b221f85e1a4b25df18d12e2693ea8db91c3' ),
		);

		foreach ( $cases as $case ) {
			list( $text, $level, $size, $digest ) = $case;

			$matrix = QrCode::matrix( $text, $level );

			$this->assertCount( $size, $matrix, 'Falsche Version für: ' . substr( $text, 0, 30 ) );
			$this->assertSame(
				$digest,
				hash( 'sha256', $this->flatten( $matrix ) ),
				'Raster weicht ab bei: ' . substr( $text, 0, 30 ) . ' / ' . $level
			);
		}
	}

	/**
	 * Das Raster ist quadratisch und enthält nur 0 und 1.
	 */
	public function test_raster_ist_quadratisch_und_binaer() {
		$matrix = QrCode::matrix( 'LW16', 'M' );
		$size   = count( $matrix );

		foreach ( $matrix as $row ) {
			$this->assertCount( $size, $row );
			foreach ( $row as $value ) {
				$this->assertContains( $value, array( 0, 1 ) );
			}
		}
	}

	/**
	 * Die drei Suchmuster stehen an ihren Ecken – daran findet der Scanner
	 * den Code überhaupt erst.
	 */
	public function test_suchmuster_stehen_in_den_ecken() {
		$matrix = QrCode::matrix( 'LW16', 'M' );
		$size   = count( $matrix );

		foreach ( array( array( 0, 0 ), array( $size - 7, 0 ), array( 0, $size - 7 ) ) as $corner ) {
			list( $ox, $oy ) = $corner;
			$this->assertSame( 1, $matrix[ $oy ][ $ox ] );
			$this->assertSame( 1, $matrix[ $oy + 3 ][ $ox + 3 ] );
			$this->assertSame( 0, $matrix[ $oy + 1 ][ $ox + 1 ] );
		}
	}

	/**
	 * Das feste dunkle Modul ist immer gesetzt.
	 */
	public function test_dunkles_modul_ist_gesetzt() {
		foreach ( array( 'A', 'LW16-BIN-A01', str_repeat( 'X', 120 ) ) as $text ) {
			$matrix = QrCode::matrix( $text, 'M' );
			$size   = count( $matrix );
			$this->assertSame( 1, $matrix[ $size - 8 ][8], 'Dunkles Modul fehlt.' );
		}
	}

	/**
	 * Die Kapazität wächst mit der Version und sinkt mit der Stufe.
	 */
	public function test_kapazitaet_ist_monoton() {
		for ( $v = 1; $v < QrCode::MAX_VERSION; $v++ ) {
			$this->assertGreaterThan(
				QrCode::capacity( $v, 'M' ),
				QrCode::capacity( $v + 1, 'M' ),
				'Version ' . ( $v + 1 ) . ' fasst nicht mehr als ' . $v
			);
		}

		foreach ( range( 1, QrCode::MAX_VERSION ) as $v ) {
			$this->assertGreaterThan( QrCode::capacity( $v, 'M' ), QrCode::capacity( $v, 'L' ) );
			$this->assertGreaterThan( QrCode::capacity( $v, 'Q' ), QrCode::capacity( $v, 'M' ) );
			$this->assertGreaterThan( QrCode::capacity( $v, 'H' ), QrCode::capacity( $v, 'Q' ) );
		}
	}

	/**
	 * Genau an der Kapazitätsgrenze wird noch die kleinere Version gewählt,
	 * ein Zeichen darüber die nächste.
	 */
	public function test_versionsgrenze_stimmt_auf_das_zeichen() {
		$limit = QrCode::capacity( 1, 'M' );

		$this->assertCount( 21, QrCode::matrix( str_repeat( 'A', $limit ), 'M' ) );
		$this->assertCount( 25, QrCode::matrix( str_repeat( 'A', $limit + 1 ), 'M' ) );
	}

	/**
	 * Zu langer Inhalt wird abgewiesen statt stillschweigend abgeschnitten.
	 */
	public function test_zu_langer_inhalt_wird_abgewiesen() {
		$this->expectException( InvalidArgumentException::class );
		QrCode::matrix( str_repeat( 'A', 2000 ), 'H' );
	}

	/**
	 * Unbekannte Fehlerkorrekturstufe wird abgewiesen.
	 */
	public function test_unbekannte_stufe_wird_abgewiesen() {
		$this->expectException( InvalidArgumentException::class );
		QrCode::matrix( 'LW16', 'X' );
	}

	/**
	 * Das SVG ist in sich geschlossen und ohne externe Verweise.
	 */
	public function test_svg_ist_eigenstaendig() {
		$svg = QrCode::svg( 'https://lindenweg16.de/betrieb-erfassen/', 200, 'M' );

		$this->assertStringStartsWith( '<svg', $svg );
		$this->assertStringEndsWith( '</svg>', $svg );
		$this->assertStringContainsString( 'width="200"', $svg );
		$this->assertStringNotContainsString( 'http://', str_replace( 'http://www.w3.org/2000/svg', '', $svg ) );
		$this->assertStringNotContainsString( '<script', $svg );

		// Ruhezone: vier Module ringsum, also Version 1 (21) plus 8.
		$this->assertStringContainsString( 'viewBox="0 0 ' . ( count( QrCode::matrix( 'https://lindenweg16.de/betrieb-erfassen/', 'M' ) ) + 8 ), $svg );
	}
}
