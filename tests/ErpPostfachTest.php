<?php
/**
 * Regeln der nativen Mailbox: Stufen je Aktion, Ordnerliste, Fremdbilder, Antwortvorlage.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Postfach;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Postfach.
 */
class ErpPostfachTest extends TestCase {

	public function test_reading_actions_need_read_and_changing_ones_need_send() {
		foreach ( array( 'read', 'unread', 'star', 'unstar', 'assign' ) as $do ) {
			$this->assertSame( 'read', Postfach::action_level( $do ), $do );
		}
		foreach ( array( 'trash', 'spam', 'notspam', 'move' ) as $do ) {
			$this->assertSame( 'send', Postfach::action_level( $do ), $do );
		}
	}

	public function test_unknown_actions_are_refused() {
		$this->assertSame( '', Postfach::action_level( 'delete' ) );
		$this->assertSame( '', Postfach::action_level( '' ) );
		$this->assertSame( '', Postfach::action_level( 'READ' ) );
	}

	public function test_filter_falls_back_to_all() {
		$this->assertSame( 'unread', Postfach::clean_filter( 'unread' ) );
		$this->assertSame( 'all', Postfach::clean_filter( 'x; DROP' ) );
	}

	public function test_folders_are_ordered_and_drafts_are_local() {
		$folders = array(
			'Trash'           => array( 'name' => 'Trash', 'role' => 'trash' ),
			'INBOX'           => array( 'name' => 'INBOX', 'role' => 'inbox' ),
			'Sent'            => array( 'name' => 'Sent', 'role' => 'sent' ),
			'Drafts'          => array( 'name' => 'Drafts', 'role' => 'drafts' ),
			'Kunden.Anfragen' => array( 'name' => 'Kunden.Anfragen', 'role' => 'other' ),
		);
		$counts  = array(
			'INBOX'    => array( 'total' => 10, 'unread' => 3 ),
			'Sent'     => array( 'total' => 5, 'unread' => 2 ),
			'__drafts' => array( 'total' => 1, 'unread' => 0 ),
		);

		$rows  = Postfach::folder_rows( $folders, $counts );
		$names = array_column( $rows, 'name' );

		$this->assertSame( array( 'INBOX', '__drafts', 'Sent', 'Kunden.Anfragen', 'Trash' ), $names );
		$this->assertSame( 'Posteingang', $rows[0]['label'] );
		$this->assertSame( 3, $rows[0]['unread'] );
		$this->assertSame( 'Anfragen', $rows[3]['label'] );
		// Im Gesendet-Ordner bedeutet „ungelesen“ nichts.
		$this->assertSame( 0, $rows[2]['unread'] );
		$this->assertSame( 1, $rows[1]['total'] );
	}

	public function test_remote_images_are_detected() {
		$this->assertTrue( Postfach::has_remote_content( '<p><img src="https://track.example/p.gif"></p>' ) );
		$this->assertTrue( Postfach::has_remote_content( '<div style="background:url(http://x.example/a.png)"></div>' ) );
		$this->assertFalse( Postfach::has_remote_content( '<p>Hallo <img src="data:image/png;base64,AAAA"></p>' ) );
		$this->assertFalse( Postfach::has_remote_content( '<p>Nur Text</p>' ) );
	}

	public function test_dates_come_out_as_unix_time() {
		$this->assertSame( 0, Postfach::to_ts( '' ) );
		$this->assertSame( 0, Postfach::to_ts( 'kein Datum' ) );
		$this->assertSame( 1700000000, Postfach::to_ts( '2023-11-14 22:13:20' ) );
	}

	public function test_reply_goes_to_the_sender_and_quotes_the_text() {
		$ref  = array(
			'id'         => 7,
			'from_name'  => 'Käthe',
			'from_email' => 'kaethe@example.de',
			'to_json'    => wp_json_encode_test( array( array( 'name' => '', 'email' => 'info@lindenweg16.de' ) ) ),
			'cc_json'    => '[]',
			'subject'    => 'Frage zur Bestellung',
			'body_text'  => "Hallo\nwann kommt das Paket?",
		);
		$vals = Postfach::reply_values( $ref, 'reply', 'info@lindenweg16.de', '01.02.2025 10:00' );

		$this->assertSame( '"Käthe" <kaethe@example.de>', $vals['to'] );
		$this->assertSame( 'Re: Frage zur Bestellung', $vals['subject'] );
		$this->assertSame( 7, $vals['reply_to_id'] );
		$this->assertStringContainsString( '> wann kommt das Paket?', $vals['body'] );
	}

	public function test_reply_all_leaves_out_the_own_address() {
		$ref  = array(
			'id'         => 8,
			'from_name'  => 'Anna',
			'from_email' => 'anna@example.de',
			'to_json'    => wp_json_encode_test( array( array( 'name' => '', 'email' => 'info@lindenweg16.de' ), array( 'name' => '', 'email' => 'bob@example.de' ) ) ),
			'cc_json'    => '[]',
			'subject'    => 'Re: Hallo',
			'body_text'  => 'x',
		);
		$vals = Postfach::reply_values( $ref, 'replyall', 'info@lindenweg16.de', 'heute' );

		$this->assertSame( 'bob@example.de', $vals['cc'] );
		$this->assertSame( 'Re: Hallo', $vals['subject'] );
	}

	public function test_forward_has_no_recipient_and_keeps_the_original() {
		$ref  = array(
			'id'         => 9,
			'from_name'  => '',
			'from_email' => 'a@example.de',
			'to_json'    => '[]',
			'cc_json'    => '[]',
			'subject'    => 'Rechnung',
			'body_text'  => 'Anbei.',
		);
		$vals = Postfach::reply_values( $ref, 'forward', 'info@lindenweg16.de', 'heute' );

		$this->assertSame( '', $vals['to'] );
		$this->assertSame( 0, $vals['reply_to_id'] );
		$this->assertSame( 'Fwd: Rechnung', $vals['subject'] );
		$this->assertStringContainsString( 'Anbei.', $vals['body'] );
	}

	public function test_clip_counts_characters_not_bytes() {
		$this->assertSame( 'äöü', Postfach::clip( 'äöüxyz', 3 ) );
		$this->assertSame( 'ab', Postfach::clip( 'ab', 5 ) );
	}
}

/**
 * JSON für Testdaten.
 *
 * @param array $data Daten.
 * @return string
 */
function wp_json_encode_test( array $data ) { // phpcs:ignore
	return json_encode( $data );
}
