<?php
/**
 * Auskunft und Löschung personenbezogener Daten.
 *
 * WordPress bringt für Art. 15 und Art. 17 DSGVO einen fertigen Mechanismus
 * mit: Unter Werkzeuge → Daten exportieren beziehungsweise Daten löschen
 * fragt es alle angemeldeten Stellen nach den Daten einer E-Mail-Adresse.
 *
 * Das Plugin speichert personenbezogene Daten an sechs Stellen. Solange es
 * sich dort nicht anmeldet, findet WordPress davon nichts – die Auskunft an
 * den Kunden wäre unvollständig, ohne dass es jemandem auffällt. Genau das
 * behebt diese Datei.
 *
 * Zwei Dinge werden bewusst **nicht** gelöscht:
 *
 * - Buchhaltungsdaten und Bestellungen. Sie unterliegen der
 *   Aufbewahrungspflicht; sie zu löschen wäre nicht nur unnötig, sondern
 *   unzulässig. WooCommerce hat dafür eigene Anonymisierungsregeln.
 * - Der Punktestand, solange er ein offenes Guthaben darstellt. Er wird
 *   stattdessen anonymisiert gemeldet, damit die Löschung nicht stillschweigend
 *   Geld vernichtet. Was gelöscht wird, ist die Zuordnung zur Person.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Meldet die personenbezogenen Datenbestände bei WordPress an.
 */
class Privacy {

	/**
	 * Wie viele Datensätze je Durchgang bearbeitet werden.
	 *
	 * WordPress ruft die Rückrufe seitenweise auf, damit auch große Bestände
	 * ohne Zeitüberlauf durchlaufen.
	 */
	const PER_PAGE = 100;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
	}

	/**
	 * Die Datenbestände mit ihren Feldern.
	 *
	 * Reine Beschreibung ohne Datenbankzugriff – so lässt sich prüfen, dass
	 * kein Bestand vergessen wurde und keiner ohne Beschriftung dasteht.
	 *
	 * @return array
	 */
	public static function sources() {
		return array(
			'wishlist'      => array(
				'label'  => __( 'Wunschliste', 'lindenweg16' ),
				'table'  => 'lw16_wishlist',
				'by'     => 'user',
				'fields' => array(
					'woo_product_id'  => __( 'Produkt', 'lindenweg16' ),
					'hinzugefuegt_am' => __( 'Hinzugefügt am', 'lindenweg16' ),
				),
			),
			'warteliste'    => array(
				'label'  => __( 'Warteliste', 'lindenweg16' ),
				'table'  => 'lw16_warteliste',
				'by'     => 'email',
				'fields' => array(
					'email'          => __( 'E-Mail', 'lindenweg16' ),
					'benachrichtigt' => __( 'Benachrichtigt', 'lindenweg16' ),
					'erstellt_am'    => __( 'Eingetragen am', 'lindenweg16' ),
				),
			),
			'cart_tracking' => array(
				'label'  => __( 'Liegengebliebener Warenkorb', 'lindenweg16' ),
				'table'  => 'lw16_cart_tracking',
				'by'     => 'email',
				'fields' => array(
					'email'         => __( 'E-Mail', 'lindenweg16' ),
					'cart_snapshot' => __( 'Inhalt', 'lindenweg16' ),
					'erstellt_am'   => __( 'Erfasst am', 'lindenweg16' ),
					'erinnert_am'   => __( 'Erinnert am', 'lindenweg16' ),
				),
			),
			'tickets'       => array(
				'label'  => __( 'Supportanfragen', 'lindenweg16' ),
				'table'  => 'lw16_tickets',
				'by'     => 'user',
				'fields' => array(
					'betreff'     => __( 'Betreff', 'lindenweg16' ),
					'nachricht'   => __( 'Nachricht', 'lindenweg16' ),
					'status'      => __( 'Status', 'lindenweg16' ),
					'erstellt_am' => __( 'Gesendet am', 'lindenweg16' ),
				),
			),
			'points_ledger' => array(
				'label'  => __( 'Punktekonto', 'lindenweg16' ),
				'table'  => 'lw16_points_ledger',
				'by'     => 'user',
				'fields' => array(
					'points'      => __( 'Punkte', 'lindenweg16' ),
					'type'        => __( 'Art', 'lindenweg16' ),
					'status'      => __( 'Status', 'lindenweg16' ),
					'notiz'       => __( 'Notiz', 'lindenweg16' ),
					'created_at'  => __( 'Gebucht am', 'lindenweg16' ),
				),
			),
			'event_rsvp'    => array(
				'label'  => __( 'Event-Anmeldungen', 'lindenweg16' ),
				'table'  => 'lw16_event_rsvp',
				'by'     => 'email',
				'fields' => array(
					'name'          => __( 'Name', 'lindenweg16' ),
					'email'         => __( 'E-Mail', 'lindenweg16' ),
					'status'        => __( 'Status', 'lindenweg16' ),
					'notiz'         => __( 'Anmerkung', 'lindenweg16' ),
					'bestaetigt_am' => __( 'Bestätigt am', 'lindenweg16' ),
					'erstellt_am'   => __( 'Angemeldet am', 'lindenweg16' ),
				),
			),
			'referrals'     => array(
				'label'  => __( 'Empfehlungen', 'lindenweg16' ),
				'table'  => 'lw16_referrals',
				'by'     => 'referrer',
				'fields' => array(
					'status'        => __( 'Status', 'lindenweg16' ),
					'reward_amount' => __( 'Prämie', 'lindenweg16' ),
					'created_at'    => __( 'Erfasst am', 'lindenweg16' ),
				),
			),
		);
	}

	/**
	 * Spalte, über die ein Bestand einer Person zugeordnet ist.
	 *
	 * Reine Logik.
	 *
	 * @param string $by Zuordnungsart.
	 * @return string Spaltenname.
	 */
	public static function column_for( $by ) {
		$map = array(
			'user'     => 'user_id',
			'email'    => 'email',
			'referrer' => 'referrer_user_id',
		);

		return isset( $map[ $by ] ) ? $map[ $by ] : 'user_id';
	}

	/* ====================================================================
	 * Auskunft
	 * ================================================================== */

	/**
	 * Exporter anmelden.
	 *
	 * @param array $exporters Bisherige Exporter.
	 * @return array
	 */
	public function register_exporters( $exporters ) {
		foreach ( self::sources() as $key => $source ) {
			$exporters[ 'lindenweg16-' . $key ] = array(
				'exporter_friendly_name' => $source['label'],
				'callback'               => function ( $email, $page = 1 ) use ( $key ) {
					return $this->export( $key, $email, (int) $page );
				},
			);
		}

		return $exporters;
	}

	/**
	 * Einen Bestand ausgeben.
	 *
	 * @param string $key   Bestand.
	 * @param string $email E-Mail der betroffenen Person.
	 * @param int    $page  Seite, beginnend bei 1.
	 * @return array
	 */
	public function export( $key, $email, $page ) {
		$sources = self::sources();
		$source  = isset( $sources[ $key ] ) ? $sources[ $key ] : null;
		$rows    = $source ? $this->fetch( $source, $email, $page ) : array();
		$items   = array();

		foreach ( $rows as $row ) {
			$data = array();

			foreach ( $source['fields'] as $field => $label ) {
				if ( ! isset( $row[ $field ] ) ) {
					continue;
				}

				$data[] = array(
					'name'  => $label,
					'value' => (string) $row[ $field ],
				);
			}

			$items[] = array(
				'group_id'    => 'lindenweg16-' . $key,
				'group_label' => $source['label'],
				'item_id'     => 'lindenweg16-' . $key . '-' . (int) $row['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/* ====================================================================
	 * Löschung
	 * ================================================================== */

	/**
	 * Eraser anmelden.
	 *
	 * @param array $erasers Bisherige Eraser.
	 * @return array
	 */
	public function register_erasers( $erasers ) {
		foreach ( self::sources() as $key => $source ) {
			$erasers[ 'lindenweg16-' . $key ] = array(
				'eraser_friendly_name' => $source['label'],
				'callback'             => function ( $email, $page = 1 ) use ( $key ) {
					return $this->erase( $key, $email, (int) $page );
				},
			);
		}

		return $erasers;
	}

	/**
	 * Einen Bestand löschen beziehungsweise anonymisieren.
	 *
	 * @param string $key   Bestand.
	 * @param string $email E-Mail der betroffenen Person.
	 * @param int    $page  Seite.
	 * @return array
	 */
	public function erase( $key, $email, $page ) {
		global $wpdb;

		$sources = self::sources();
		$source  = isset( $sources[ $key ] ) ? $sources[ $key ] : null;
		$rows    = $source ? $this->fetch( $source, $email, $page ) : array();

		$removed  = 0;
		$retained = 0;
		$messages = array();

		foreach ( $rows as $row ) {
			$table = $wpdb->prefix . $source['table'];

			// Offene Punkte sind Guthaben. Gelöscht wird die Zuordnung zur
			// Person, nicht der Saldo – sonst vernichtet eine Löschanfrage
			// stillschweigend Geld, und das lässt sich nicht rückgängig machen.
			if ( 'points_ledger' === $key && 'available' === $row['status'] && (int) $row['points'] > 0 ) {
				$wpdb->update( $table, array( 'user_id' => 0, 'notiz' => null ), array( 'id' => (int) $row['id'] ) );
				$retained++;
				continue;
			}

			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ) );
			$removed++;
		}

		if ( $retained ) {
			$messages[] = sprintf(
				/* translators: %d: Anzahl. */
				__( '%d Punktebuchungen mit offenem Guthaben wurden anonymisiert statt gelöscht, damit kein Guthaben verlorengeht.', 'lindenweg16' ),
				$retained
			);
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $rows ) < self::PER_PAGE,
		);
	}

	/* ====================================================================
	 * Zugriff
	 * ================================================================== */

	/**
	 * Datensätze einer Person aus einem Bestand holen.
	 *
	 * @param array  $source Bestandsbeschreibung.
	 * @param string $email  E-Mail.
	 * @param int    $page   Seite.
	 * @return array
	 */
	protected function fetch( array $source, $email, $page ) {
		global $wpdb;

		$column = self::column_for( $source['by'] );
		$table  = $wpdb->prefix . $source['table'];
		$offset = max( 0, ( (int) $page - 1 ) * self::PER_PAGE );

		if ( 'email' === $source['by'] ) {
			$value = $email;
		} else {
			$user = get_user_by( 'email', $email );

			if ( ! $user ) {
				return array();
			}

			$value = $user->ID;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$column} = %s ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$value,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}
}
