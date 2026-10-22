<?php
/**
 * Stempeluhr als Sprechblase an der Kopfzeilenuhr.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

class ErpStempeluhrTest extends TestCase {

	protected function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_klick_auf_die_uhr_oeffnet_die_blase_statt_der_seite() {
		$js = $this->read( 'assets/erp/erp.js' );

		$this->assertStringContainsString( "UI.clockBubble(e.currentTarget)", $js );
		$this->assertStringContainsString( 'e.preventDefault()', $js );
		// Ohne Skript bleibt der Link zur Seite als Rückfall.
		$this->assertStringContainsString( "href: '#/seite/zeit', id: 'clock-pill'", $js );
	}

	public function test_blase_nutzt_die_vorhandenen_zeit_routen() {
		$js = $this->read( 'assets/erp/pages/stempeluhr.js' );

		foreach ( array( "'zeit'", "'zeit/start'", "'zeit/stopp'", "'zeit/verwerfen'", "'zeit/weiter'", "'zeit/pause'" ) as $route ) {
			$this->assertStringContainsString( $route, $js );
		}

		$this->assertStringContainsString( "href: '#/seite/zeit'", $js, 'Mehr führt zur Zeiterfassung.' );
	}

	public function test_uhr_ist_auch_am_handy_erreichbar() {
		$css = $this->read( 'assets/erp/erp.css' );

		$this->assertMatchesRegularExpression( '/\.clock-pill \{\s+display: inline-flex;/', $css );
		$this->assertStringNotContainsString( 'display: none; align-items: center; gap: 8px; height: 42px', $css );
	}

	public function test_stoppen_bucht_genau_einmal_ueber_die_bestehende_route() {
		$php = $this->read( 'includes/Erp/Pages/Verwaltung.php' );

		// Die Blase sendet an dieselbe Route; deren Prüfung gegen Doppelbuchung bleibt maßgeblich.
		$this->assertStringContainsString( 'Die Uhr wurde inzwischen gestoppt oder verworfen', $php );
	}
}
