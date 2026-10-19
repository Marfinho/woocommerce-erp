<?php
/**
 * Tests für die reinen Hilfsfunktionen des GitHub-Updaters.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Core\Updater;
use PHPUnit\Framework\TestCase;

class UpdaterTest extends TestCase {

	public function test_version_aus_plugin_header() {
		$php = "<?php\n/**\n * Plugin Name:       Lindenweg16 ERP\n * Version:           0.16.0\n * Requires PHP:      7.4\n */";
		$this->assertSame( '0.16.0', Updater::parse_version( $php ) );
	}

	public function test_version_aus_style_css() {
		$css = "/*\nTheme Name:        Lindenweg16\nVersion:           1.1.1\nRequires PHP:      7.4\n*/";
		$this->assertSame( '1.1.1', Updater::parse_version( $css ) );
	}

	public function test_requires_php_wird_nicht_als_version_gelesen() {
		$this->assertSame( '', Updater::parse_version( " * Requires PHP: 7.4\n * WC tested up to: 9.9" ) );
	}

	public function test_leerer_inhalt_ohne_version() {
		$this->assertSame( '', Updater::parse_version( '' ) );
		$this->assertSame( '', Updater::parse_version( '404: Not Found' ) );
	}

	public function test_nur_hoehere_version_ist_update() {
		$this->assertTrue( Updater::is_newer( '0.15.1', '0.16.0' ) );
		$this->assertTrue( Updater::is_newer( '0.9.0', '0.10.0' ) );
		$this->assertFalse( Updater::is_newer( '0.16.0', '0.16.0' ) );
		$this->assertFalse( Updater::is_newer( '0.16.0', '0.15.1' ) );
		$this->assertFalse( Updater::is_newer( '0.16.0', '' ) );
	}

	public function test_ordner_wird_auf_installierten_namen_umbenannt() {
		$this->assertSame(
			'/tmp/upgrade/abc/woocommerce-erp-main/',
			Updater::target_path( '/tmp/upgrade/abc/Marfinho-woocommerce-erp-1a2b3c/', 'woocommerce-erp-main' )
		);
	}

	public function test_richtiger_ordner_bleibt() {
		$this->assertSame( '', Updater::target_path( '/tmp/upgrade/abc/woocommerce-erp-main/', 'woocommerce-erp-main' ) );
		$this->assertSame( '', Updater::target_path( '/tmp/upgrade/abc/lindenweg16', 'lindenweg16' ) );
		$this->assertSame( '', Updater::target_path( '/tmp/upgrade/abc/x/', '' ) );
	}

	public function test_paketadressen_zeigen_auf_github() {
		$this->assertSame( 'https://github.com/Marfinho/woocommerce-erp/archive/refs/heads/main.zip', Updater::plugin_package() );
		$this->assertSame( 'https://raw.githubusercontent.com/Marfinho/woocommerce-erp/main/block-theme/dist/lindenweg16.zip', Updater::theme_package() );
	}

	public function test_changelog_als_html() {
		$md   = "# Changelog\n\nText.\n\n## [0.16.0] – 2026-10-19\n\n### Neu\n\n- **Updates** aus `main`\n  zweite Zeile\n- Punkt zwei\n\n## [0.15.1] – 2026-10-19\n\nEin Absatz,\nder umbricht.\n\nNeuer Absatz.\n\n- alt\n\n## [0.15.0]\n\n- älter\n\n## [0.14.0]\n\n- ganz alt\n";
		$html = Updater::changelog_html( $md, 3 );

		$this->assertStringContainsString( '<h3>[0.16.0] – 2026-10-19</h3>', $html );
		$this->assertStringContainsString( '<h4>Neu</h4>', $html );
		$this->assertStringContainsString( '<li><strong>Updates</strong> aus <code>main</code> zweite Zeile', $html );
		$this->assertStringContainsString( '<p>Ein Absatz, der umbricht.</p>', $html );
		$this->assertStringContainsString( '<p>Neuer Absatz.</p>', $html );
		$this->assertStringContainsString( '[0.15.0]', $html );
		$this->assertStringNotContainsString( '0.14.0', $html );
		$this->assertStringNotContainsString( 'Text.', $html );
	}
}
