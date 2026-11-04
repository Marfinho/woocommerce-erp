<?php
/**
 * Affiliates in der ERP-Oberfläche: Recht, Navigation, Seite.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Navigation;
use Lindenweg16\Erp\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * Prüft, dass Recht, Menüeintrag, Script und Route zusammenpassen.
 */
class ErpAffiliatesTest extends TestCase {

	public function test_rechte_sind_im_katalog() {
		$catalog = Permissions::catalog();

		$this->assertArrayHasKey( 'verkauf.affiliates', $catalog );
		$this->assertSame( array( 'manage_woocommerce' ), $catalog['verkauf.affiliates']['caps'] );

		// Auszahlen ist ein eigenes Recht und setzt die Seite voraus.
		$this->assertArrayHasKey( 'verkauf.affiliates.auszahlen', $catalog );
		$this->assertSame( array( 'verkauf.affiliates' ), $catalog['verkauf.affiliates.auszahlen']['requires'] );
	}

	public function test_navigation_fuehrt_die_native_seite_unter_marketing() {
		$items = Navigation::items();

		$this->assertSame( 'native', $items['affiliates']['kind'] );
		$this->assertSame( 'verkauf.affiliates', $items['affiliates']['perm'] );
		$this->assertSame( array( 'verkauf', 'Marketing' ), Navigation::place( 'affiliates' ) );
	}

	public function test_rolle_ambassador_hat_nur_lesen() {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Core/Roles.php' );

		$this->assertStringContainsString( "AMBASSADOR_ROLE = 'lw16_ambassador'", $src );
		$this->assertMatchesRegularExpression( '/add_role\(\s*self::AMBASSADOR_ROLE,[^;]*array\( \'read\' => true \)/s', $src );
	}

	public function test_account_seite_ist_nur_fuer_verknuepfte_konten() {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Affiliate/Account.php' );

		// Menüpunkt und Seite hängen am Partner des angemeldeten Kontos, die Anforderung am Nonce.
		$this->assertStringContainsString( 'Partners::for_user( get_current_user_id() )', $src );
		$this->assertStringContainsString( "wp_verify_nonce( \$nonce, 'lw16_aff_request' )", $src );
	}

	public function test_modul_haengt_link_wache_und_buchung_ein() {
		$dir = dirname( __DIR__ ) . '/includes/Modules/Affiliate/';
		$mod = file_get_contents( $dir . 'AffiliateModule.php' );

		$this->assertStringContainsString( '( new AutoApply() )->register()', $mod );
		$this->assertStringContainsString( '( new Guard() )->register()', $mod );

		// Der Link-Parameter, die Wache am Gutschein und das Konto der Buchung.
		$this->assertStringContainsString( "PARAM  = 'aff'", file_get_contents( $dir . 'AutoApply.php' ) );
		$this->assertStringContainsString( "'woocommerce_coupon_is_valid'", file_get_contents( $dir . 'Guard.php' ) );
		$this->assertStringContainsString( 'Kontenrahmen::KONTO_WERBUNG', file_get_contents( $dir . 'Booking.php' ) );
		// Eigenkäufe zählen auch ohne Wache nicht für die Provision.
		$this->assertStringContainsString( 'Rules::is_self_purchase', file_get_contents( $dir . 'Sales.php' ) );
	}

	public function test_willkommensmail_hat_vorlage_und_alle_platzhalter_sind_dokumentiert() {
		require_once dirname( __DIR__ ) . '/includes/Core/MailTemplate.php';

		$templates = \Lindenweg16\Core\MailTemplate::templates();
		$this->assertArrayHasKey( 'ambassador_willkommen', $templates );

		$tpl = $templates['ambassador_willkommen'];
		$this->assertStringContainsString( 'DANKE', $tpl['text'] );

		// Jeder Platzhalter im Text steht in der Dokumentation, außer dem allgemeinen {firmenname}.
		preg_match_all( '/\{[a-z_]+\}/', $tpl['betreff'] . $tpl['text'], $m );
		foreach ( array_unique( $m[0] ) as $ph ) {
			if ( '{firmenname}' !== $ph ) {
				$this->assertStringContainsString( $ph, $tpl['platzhalter'], $ph . ' fehlt in der Platzhalter-Liste' );
			}
		}

		// Und die Klasse füllt jeden dokumentierten Platzhalter.
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Affiliate/Welcome.php' );
		preg_match_all( '/\{[a-z_]+\}/', $tpl['platzhalter'], $doc );
		foreach ( $doc[0] as $ph ) {
			$this->assertStringContainsString( "'" . $ph . "'", $src, $ph . ' wird in Welcome.php nicht gefüllt' );
		}
	}

	public function test_seite_ist_als_script_registriert() {
		$js = file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/affiliates.js' );

		$this->assertStringContainsString( "UI.pages['affiliates']", $js );
		$this->assertStringContainsString( "ui.api('affiliates", $js );
		$this->assertStringContainsString( "affiliates/auszahlung", $js );
		$this->assertStringContainsString( "affiliates/ablehnen", $js );
		$this->assertStringContainsString( "affiliates/storno", $js );
	}
}
