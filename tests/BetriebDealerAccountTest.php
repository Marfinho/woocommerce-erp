<?php
/**
 * Händlerkonto: wer verknüpft werden darf, wo der Reiter steht, was der
 * Händler als Status liest.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\DealerAccount;

/**
 * Reine Logik des Händlerkontos.
 */
class BetriebDealerAccountTest extends TestCase {

	/**
	 * Ein normales Kundenkonto darf Händlerkonto werden.
	 */
	public function test_kundenkonto_ist_verknuepfbar() {
		$this->assertTrue( DealerAccount::linkable_caps( array( 'read' => true ) ) );
		$this->assertTrue( DealerAccount::linkable_caps( array() ) );
	}

	/**
	 * Mitarbeiter- und Verwaltungskonten nie.
	 */
	public function test_mitarbeiterkonto_ist_nicht_verknuepfbar() {
		foreach ( array( 'edit_posts', 'manage_woocommerce', 'manage_options', 'edit_shop_orders' ) as $cap ) {
			$this->assertFalse( DealerAccount::linkable_caps( array( 'read' => true, $cap => true ) ), $cap );
		}
	}

	/**
	 * Ein ausdrücklich entzogenes Recht zählt nicht.
	 */
	public function test_entzogenes_recht_zaehlt_nicht() {
		$this->assertTrue( DealerAccount::linkable_caps( array( 'read' => true, 'edit_posts' => false ) ) );
	}

	/**
	 * Der Reiter steht direkt nach „Bestellungen“.
	 */
	public function test_reiter_nach_bestellungen() {
		$items = array(
			'dashboard'       => 'Übersicht',
			'orders'          => 'Bestellungen',
			'edit-address'    => 'Adressen',
			'customer-logout' => 'Abmelden',
		);

		$out = DealerAccount::insert_menu_item( $items, 'nachbestellen', 'Nachbestellen' );

		$this->assertSame( array( 'dashboard', 'orders', 'nachbestellen', 'edit-address', 'customer-logout' ), array_keys( $out ) );
	}

	/**
	 * Ohne „Bestellungen“ vor „Abmelden“, ohne beides ans Ende.
	 */
	public function test_reiter_ohne_bestellungen() {
		$out = DealerAccount::insert_menu_item( array( 'dashboard' => 'Übersicht', 'customer-logout' => 'Abmelden' ), 'nachbestellen', 'Nachbestellen' );
		$this->assertSame( array( 'dashboard', 'nachbestellen', 'customer-logout' ), array_keys( $out ) );

		$out = DealerAccount::insert_menu_item( array( 'dashboard' => 'Übersicht' ), 'nachbestellen', 'Nachbestellen' );
		$this->assertSame( array( 'dashboard', 'nachbestellen' ), array_keys( $out ) );
	}

	/**
	 * Status aus Sicht des Händlers.
	 */
	public function test_status_fuer_den_haendler() {
		$this->assertSame( 'Angefragt, wird geprüft', DealerAccount::status_label( 'entwurf', 'offen' ) );
		$this->assertSame( 'Bestätigt', DealerAccount::status_label( 'bestaetigt', 'offen' ) );
		$this->assertSame( 'Bestätigt, Rechnung gestellt', DealerAccount::status_label( 'bestaetigt', 'berechnet' ) );
		$this->assertSame( 'Bezahlt', DealerAccount::status_label( 'bestaetigt', 'bezahlt' ) );
		$this->assertSame( 'Storniert', DealerAccount::status_label( 'storniert', 'offen' ) );
		$this->assertSame( 'Storniert', DealerAccount::status_label( 'bestaetigt', 'storniert' ) );
	}
}
