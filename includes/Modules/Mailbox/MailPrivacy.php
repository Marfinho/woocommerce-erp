<?php
/**
 * Auskunft und Löschanfragen für Mails (DSGVO).
 *
 * Die Mailbox hält Kopien des Schriftverkehrs mit Kunden. Fragt eine Person
 * nach ihren Daten (Werkzeuge → Daten exportieren), gehören diese Mails dazu.
 *
 * Gelöscht werden sie bewusst nicht: E-Mails mit Geschäftsinhalt sind
 * Handels- und Geschäftsbriefe und unterliegen der Aufbewahrungspflicht
 * (§ 257 HGB, § 147 AO) – wie die Buchhaltungsdaten in `Core\Privacy`. Die
 * Löschanfrage meldet das ausdrücklich zurück, damit niemand glaubt, die
 * Daten seien weg.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * Exporter und Eraser für die Mailbox.
 */
class MailPrivacy {

	const PER_PAGE = 50;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Exporter anmelden.
	 *
	 * @param array $exporters Bisherige.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['lindenweg16-mailbox'] = array(
			'exporter_friendly_name' => __( 'Mailbox (E-Mail-Schriftverkehr)', 'lindenweg16' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Eraser anmelden.
	 *
	 * @param array $erasers Bisherige.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['lindenweg16-mailbox'] = array(
			'eraser_friendly_name' => __( 'Mailbox (E-Mail-Schriftverkehr)', 'lindenweg16' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Mails, in denen die Adresse als Absender oder Empfänger vorkommt.
	 *
	 * @param string $email Adresse.
	 * @param int    $page  Seite ab 1.
	 * @return array
	 */
	protected function fetch( $email, $page ) {
		global $wpdb;

		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return array();
		}

		$t    = MailRepository::table( 'messages' );
		$like = '%' . $wpdb->esc_like( $email ) . '%';

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE folder <> %s AND (from_email = %s OR to_json LIKE %s OR cc_json LIKE %s) ORDER BY id ASC LIMIT %d OFFSET %d",
				MailRepository::DRAFTS,
				$email,
				$like,
				$like,
				self::PER_PAGE,
				max( 0, ( (int) $page - 1 ) * self::PER_PAGE )
			),
			ARRAY_A
		);
	}

	/**
	 * Auskunft.
	 *
	 * @param string $email Adresse der betroffenen Person.
	 * @param int    $page  Seite.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$rows  = $this->fetch( $email, (int) $page );
		$items = array();

		foreach ( $rows as $row ) {
			$account = MailRepository::get_account( (int) $row['account_id'] );

			$items[] = array(
				'group_id'    => 'lindenweg16-mailbox',
				'group_label' => __( 'E-Mail-Schriftverkehr', 'lindenweg16' ),
				'item_id'     => 'lindenweg16-mail-' . (int) $row['id'],
				'data'        => array(
					array( 'name' => __( 'Postfach', 'lindenweg16' ), 'value' => $account ? $account['email'] : '' ),
					array( 'name' => __( 'Von', 'lindenweg16' ), 'value' => trim( $row['from_name'] . ' <' . $row['from_email'] . '>' ) ),
					array( 'name' => __( 'An', 'lindenweg16' ), 'value' => MailboxView::addr_text( $row['to_json'] ) ),
					array( 'name' => __( 'Cc', 'lindenweg16' ), 'value' => MailboxView::addr_text( $row['cc_json'] ) ),
					array( 'name' => __( 'Betreff', 'lindenweg16' ), 'value' => $row['subject'] ),
					array( 'name' => __( 'Datum (UTC)', 'lindenweg16' ), 'value' => (string) $row['sent_at'] ),
					array( 'name' => __( 'Text', 'lindenweg16' ), 'value' => (string) $row['body_text'] ),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Löschanfrage: meldet die Aufbewahrungspflicht zurück, löscht nichts.
	 *
	 * @param string $email Adresse.
	 * @param int    $page  Seite.
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		$rows = $this->fetch( $email, (int) $page );

		return array(
			'items_removed'  => 0,
			'items_retained' => count( $rows ),
			'messages'       => $rows ? array(
				__( 'E-Mails sind Geschäftsbriefe und unterliegen der gesetzlichen Aufbewahrungspflicht (§ 257 HGB, § 147 AO). Sie wurden nicht gelöscht; nach Ablauf der Frist können sie im Mailprogramm und in der Mailbox entfernt werden.', 'lindenweg16' ),
			) : array(),
			'done'           => count( $rows ) < self::PER_PAGE,
		);
	}
}
