<?php
/**
 * AR-Ansicht: Skripte, Markup und Dateien müssen zusammenpassen.
 *
 * Das Skript greift Elemente über feste Datenattribute ab. Fehlt eines im
 * PHP, bleibt der Banner unsichtbar oder die Seite ohne Funktion – und das
 * fällt erst am Telefon auf. Zusätzlich hält der Test die Datenschutz-
 * Zusagen fest: keine Cookies, kein CDN, kein jQuery.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Liest JS, CSS und PHP als Text.
 */
class ArViewAssetsTest extends TestCase {

	protected function read( $relative ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $relative );
	}

	public function test_banner_attribute_im_markup() {
		$js   = $this->read( 'assets/js/ar-hint.js' );
		$html = $this->read( 'includes/Modules/ArView/Banner.php' );

		preg_match_all( '/\[(data-lw16-ar-[a-z-]+)\]/', $js, $m );
		$this->assertGreaterThanOrEqual( 4, count( array_unique( $m[1] ) ) );

		foreach ( array_unique( $m[1] ) as $attr ) {
			$this->assertStringContainsString( $attr, $html, "Banner-Markup fehlt: $attr" );
		}

		preg_match_all( "/getAttribute\( '(data-[a-z-]+)' \)/", $js, $m );
		foreach ( array_unique( $m[1] ) as $attr ) {
			$this->assertStringContainsString( $attr . '=', $html, "Datenattribut fehlt im Markup: $attr" );
		}
	}

	public function test_viewer_attribute_im_markup() {
		$js   = $this->read( 'assets/js/ar-viewer.js' );
		$html = $this->read( 'includes/Modules/ArView/Viewer.php' );

		preg_match_all( '/\[(data-lw16-arv[a-z-]*)\]/', $js, $m );
		$this->assertGreaterThanOrEqual( 3, count( array_unique( $m[1] ) ) );

		foreach ( array_unique( $m[1] ) as $attr ) {
			$this->assertStringContainsString( $attr, $html, "Viewer-Markup fehlt: $attr" );
		}

		preg_match_all( "/getAttribute\( '(data-[a-z-]+)' \)/", $js, $m );
		foreach ( array_unique( $m[1] ) as $attr ) {
			$this->assertStringContainsString( $attr . '=', $html, "Datenattribut fehlt im Markup: $attr" );
		}
	}

	public function test_pflichtattribute_des_viewers() {
		$html = $this->read( 'includes/Modules/ArView/Viewer.php' );

		foreach ( array( 'ar-modes="webxr scene-viewer quick-look"', 'ar-placement="wall"', 'ar-scale="fixed"', 'camera-controls', 'slot="ar-button"' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html );
		}
	}

	public function test_storage_ist_abgesichert() {
		$js = $this->read( 'assets/js/ar-hint.js' );

		// Der Zugriff auf Storage läuft ausschließlich über die try/catch-Helfer.
		$this->assertSame( 0, preg_match( '/(?<!window\[ store \]\.)\b(localStorage|sessionStorage)\s*\./', $js ), 'Direkter Storage-Zugriff.' );
		$this->assertStringContainsString( "'l16_ar_hint_dismissed'", $js );
		$this->assertGreaterThanOrEqual( 3, substr_count( $js, 'try {' ) );
	}

	public function test_keine_cookies_kein_cdn_kein_jquery() {
		foreach ( array( 'assets/js/ar-hint.js', 'assets/js/ar-viewer.js', 'assets/js/ar-admin.js', 'assets/css/ar-view.css' ) as $file ) {
			// Kommentare dürfen davon sprechen; der Code darf es nicht tun.
			$src = preg_replace( '#/\*.*?\*/#s', '', $this->read( $file ) );
			$this->assertStringNotContainsString( 'document.cookie', $src, $file );
			$this->assertDoesNotMatchRegularExpression( '#https?://#', $src, "$file nennt eine externe Adresse." );
			$this->assertDoesNotMatchRegularExpression( '/jQuery|\$\(/', $src, "$file nutzt jQuery." );
		}

		foreach ( glob( dirname( __DIR__ ) . '/includes/Modules/ArView/*.php' ) as $php ) {
			$this->assertStringNotContainsString( 'setcookie', file_get_contents( $php ), basename( $php ) );
			$this->assertStringNotContainsString( 'REMOTE_ADDR', file_get_contents( $php ), basename( $php ) );
		}
	}

	public function test_lokale_dateien_vorhanden() {
		foreach ( array(
			'assets/vendor/model-viewer/model-viewer.min.js',
			'assets/vendor/model-viewer/LICENSE',
			'assets/vendor/model-viewer/HERKUNFT.md',
			'assets/vendor/nunito/nunito-latin-400-normal.woff2',
			'assets/vendor/nunito/nunito-latin-700-normal.woff2',
			'assets/vendor/nunito/LICENSE',
		) as $file ) {
			$this->assertFileExists( dirname( __DIR__ ) . '/' . $file );
		}
	}

	public function test_viewer_laedt_die_bibliothek_lokal_und_sperrt_fremde_dekoder() {
		$js = $this->read( 'assets/js/ar-viewer.js' );

		$this->assertStringContainsString( "from '../vendor/model-viewer/model-viewer.min.js'", $js );
		foreach ( array( 'dracoDecoderLocation', 'ktx2TranscoderLocation', 'lottieLoaderLocation' ) as $prop ) {
			$this->assertStringContainsString( $prop, $js );
		}
	}

	public function test_banner_texte_nur_escaped() {
		$php = $this->read( 'includes/Modules/ArView/Banner.php' );

		$this->assertStringContainsString( 'esc_html( $o[\'title\'] )', $php );
		$this->assertStringContainsString( 'esc_html( $o[\'text\'] )', $php );
		$this->assertStringContainsString( 'esc_url( $context[\'url\'] )', $php );
	}

	public function test_banner_ist_standardmaessig_versteckt_und_gut_tippbar() {
		$php = $this->read( 'includes/Modules/ArView/Banner.php' );
		$css = $this->read( 'assets/css/ar-view.css' );

		$this->assertMatchesRegularExpression( '/data-lw16-ar-hint hidden/', $php );
		$this->assertMatchesRegularExpression( '/min-height:\s*44px/', $css );
		$this->assertStringContainsString( '#3a424c', strtolower( $css ) );
		$this->assertStringContainsString( '"Nunito"', $css );
	}
}
