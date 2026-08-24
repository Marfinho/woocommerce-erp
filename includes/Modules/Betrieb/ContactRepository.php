<?php
/**
 * Kontakte: Händler, Vereine, Firmen, Presse, Leads.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Kontakte, Aktivitäten, Profile und Konditionen.
 */
class ContactRepository {

	use SanitizesInput;

	const TYPES      = array( 'haendler', 'verein', 'firma', 'presse', 'kommune', 'lead' );
	const STATUS     = array( 'identifiziert', 'angesprochen', 'muster_abgegeben', 'interesse', 'kunde', 'abgelehnt', 'ruht' );
	const ACTIVITIES = array( 'besuch', 'anruf', 'mail', 'muster', 'bestellung' );

	/**
	 * Bezeichnungen der Typen.
	 *
	 * @return array
	 */
	public static function type_labels() {
		return array(
			'haendler' => __( 'Händler', 'lindenweg16' ),
			'verein'   => __( 'Verein', 'lindenweg16' ),
			'firma'    => __( 'Firma', 'lindenweg16' ),
			'presse'   => __( 'Presse', 'lindenweg16' ),
			'kommune'  => __( 'Kommune', 'lindenweg16' ),
			'lead'     => __( 'Lead', 'lindenweg16' ),
		);
	}

	/**
	 * Bezeichnungen der Status.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'identifiziert'    => __( 'identifiziert', 'lindenweg16' ),
			'angesprochen'     => __( 'angesprochen', 'lindenweg16' ),
			'muster_abgegeben' => __( 'Muster abgegeben', 'lindenweg16' ),
			'interesse'        => __( 'Interesse', 'lindenweg16' ),
			'kunde'            => __( 'Kunde', 'lindenweg16' ),
			'abgelehnt'        => __( 'abgelehnt', 'lindenweg16' ),
			'ruht'             => __( 'ruht', 'lindenweg16' ),
		);
	}

	/**
	 * Bezeichnungen der Aktivitätsarten.
	 *
	 * @return array
	 */
	public static function activity_labels() {
		return array(
			'besuch'     => __( 'Besuch', 'lindenweg16' ),
			'anruf'      => __( 'Anruf', 'lindenweg16' ),
			'mail'       => __( 'E-Mail', 'lindenweg16' ),
			'muster'     => __( 'Muster', 'lindenweg16' ),
			'bestellung' => __( 'Bestellung', 'lindenweg16' ),
		);
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_contacts';
	}

	/**
	 * Alle Kontakte.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY company ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Einen Kontakt holen.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Fällige Wiedervorlagen.
	 *
	 * Ein Händlerkontakt ohne Termin zum Nachfassen ist kein Kontakt.
	 *
	 * @return array
	 */
	public static function due_follow_ups() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE follow_up_on IS NOT NULL AND follow_up_on <= %s
				 AND status NOT IN ( 'abgelehnt', 'ruht' )
				 ORDER BY follow_up_on ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d' )
			),
			ARRAY_A
		);
	}

	/**
	 * Kontakt anlegen oder ändern.
	 *
	 * @param array $input Eingaben.
	 * @return array {id, errors}
	 */
	public static function save( array $input ) {
		global $wpdb;

		$errors  = array();
		$company = isset( $input['company'] ) ? sanitize_text_field( $input['company'] ) : '';

		if ( '' === $company ) {
			$errors[] = __( 'Ein Firmen- oder Personenname ist Pflicht.', 'lindenweg16' );
		}

		if ( $errors ) {
			return array(
				'id'     => 0,
				'errors' => $errors,
			);
		}

		$data = array(
			'type'           => self::enum( $input, 'type', self::TYPES, 'lead' ),
			'company'        => $company,
			'street'         => self::text( $input, 'street' ),
			'zip'            => self::text( $input, 'zip' ),
			'city'           => self::text( $input, 'city' ),
			'contact_person' => self::text( $input, 'contact_person' ),
			'phone'          => self::text( $input, 'phone' ),
			'email'          => isset( $input['email'] ) && is_email( $input['email'] ) ? sanitize_email( $input['email'] ) : null,
			'source'         => self::text( $input, 'source' ),
			'status'         => self::enum( $input, 'status', self::STATUS, 'identifiziert' ),
			'follow_up_on'   => self::date( $input, 'follow_up_on' ),
			'note'           => isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : null,
		);

		$id = self::int_or( $input, 'contact_id', 0 );

		if ( $id ) {
			$before             = self::find( $id );
			$data['updated_at'] = current_time( 'mysql', true );
			$wpdb->update( self::table(), $data, array( 'id' => $id ) );
			BetriebAudit::record( 'update', 'contacts', $id, $before, $data );

			return array(
				'id'     => $id,
				'errors' => array(),
			);
		}

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();
		$wpdb->insert( self::table(), $data );

		$new_id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'contacts', $new_id, null, $data );

		return array(
			'id'     => $new_id,
			'errors' => array(),
		);
	}

	/* ====================================================================
	 * Aktivitäten
	 * ================================================================== */

	/**
	 * Tabellenname der Aktivitäten.
	 *
	 * @return string
	 */
	public static function activities_table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_contact_activities';
	}

	/**
	 * Aktivitäten eines Kontakts.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array
	 */
	public static function activities( $contact_id ) {
		global $wpdb;
		$table = self::activities_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE contact_id = %d ORDER BY activity_date DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $contact_id
			),
			ARRAY_A
		);
	}

	/**
	 * Aktivität erfassen.
	 *
	 * Ohne Wiedervorlage wird abgelehnt: Ein Gespräch ohne nächsten Termin
	 * versandet, und genau das soll das Formular verhindern.
	 *
	 * @param array $input Eingaben.
	 * @return array {id, errors}
	 */
	public static function add_activity( array $input ) {
		global $wpdb;

		$errors     = array();
		$contact_id = self::int_or( $input, 'contact_id', 0 );
		$follow_up  = self::date( $input, 'follow_up_on' );

		if ( ! $contact_id || ! self::find( $contact_id ) ) {
			$errors[] = __( 'Der Kontakt existiert nicht.', 'lindenweg16' );
		}

		if ( ! $follow_up ) {
			$errors[] = __( 'Eine Aktivität ohne Wiedervorlage versandet. Bitte ein Datum zum Nachfassen setzen.', 'lindenweg16' );
		}

		if ( $errors ) {
			return array(
				'id'     => 0,
				'errors' => $errors,
			);
		}

		$data = array(
			'contact_id'    => $contact_id,
			'activity_date' => self::date_or_today( $input, 'activity_date' ),
			'activity_type' => self::enum( $input, 'activity_type', self::ACTIVITIES, 'anruf' ),
			'summary'       => isset( $input['summary'] ) ? sanitize_textarea_field( $input['summary'] ) : null,
			'next_step'     => self::text( $input, 'next_step' ),
			'follow_up_on'  => $follow_up,
			'created_at'    => current_time( 'mysql', true ),
			'created_by'    => get_current_user_id(),
		);

		$wpdb->insert( self::activities_table(), $data );
		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'contact_activities', $id, null, $data );

		// Die Wiedervorlage am Kontakt mitziehen – sie ist der Einstieg ins
		// Dashboard.
		$before = self::find( $contact_id );
		$wpdb->update(
			self::table(),
			array(
				'follow_up_on' => $follow_up,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $contact_id )
		);
		BetriebAudit::record( 'update', 'contacts', $contact_id, $before, array( 'follow_up_on' => $follow_up ) );

		return array(
			'id'     => $id,
			'errors' => array(),
		);
	}

	/* ====================================================================
	 * Profil und Konditionen
	 * ================================================================== */

	/**
	 * Profil eines Kontakts.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array|null
	 */
	public static function profile( $contact_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lw16_contact_profiles';
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE contact_id = %d", (int) $contact_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Profil speichern.
	 *
	 * @param int   $contact_id Kontakt.
	 * @param array $input      Eingaben.
	 * @return bool
	 */
	public static function save_profile( $contact_id, array $input ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'lw16_contact_profiles';
		$before = self::profile( $contact_id );

		$fields = array(
			'billing_company', 'billing_address', 'billing_email', 'vat_id',
			'shipping_address', 'delivery_notes', 'decision_maker', 'goods_receiver',
			'preferred_channel', 'best_time', 'preferred_colors', 'season_peak',
			'order_frequency', 'packaging_preference', 'shelf_situation', 'competitor_products',
		);

		$data = array( 'contact_id' => (int) $contact_id );

		foreach ( $fields as $field ) {
			$data[ $field ] = isset( $input[ $field ] ) ? sanitize_textarea_field( $input[ $field ] ) : null;
		}

		$data['preferred_articles']  = isset( $input['preferred_articles'] ) ? wp_json_encode( $input['preferred_articles'] ) : null;
		$data['excluded_articles']   = isset( $input['excluded_articles'] ) ? wp_json_encode( $input['excluded_articles'] ) : null;
		$data['next_expected_order'] = self::date( $input, 'next_expected_order' );
		$data['display_provided']    = self::flag( $input, 'display_provided' );
		$data['commission_allowed']  = self::flag( $input, 'commission_allowed' );
		$data['credit_limit']        = self::decimal( $input, 'credit_limit' );

		if ( $before ) {
			$data['updated_at'] = current_time( 'mysql', true );
			$wpdb->update( $table, $data, array( 'id' => (int) $before['id'] ) );
			BetriebAudit::record( 'update', 'contact_profiles', (int) $before['id'], $before, $data );
			return true;
		}

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();
		$wpdb->insert( $table, $data );
		BetriebAudit::record( 'create', 'contact_profiles', (int) $wpdb->insert_id, null, $data );

		return true;
	}

	/**
	 * Konditionsstufen eines Kontakts.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array
	 */
	public static function terms( $contact_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lw16_contact_terms';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE contact_id = %d ORDER BY valid_from DESC, min_order_value ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $contact_id
			),
			ARRAY_A
		);
	}

	/**
	 * Konditionsstufe festschreiben.
	 *
	 * Eine Konditionsänderung erzeugt eine neue Zeile; die alte wird über
	 * valid_until beendet, nie überschrieben.
	 *
	 * @param array $input Eingaben.
	 * @return int
	 */
	public static function add_term( array $input ) {
		global $wpdb;

		$table      = $wpdb->prefix . 'lw16_contact_terms';
		$contact_id = self::int_or( $input, 'contact_id', 0 );
		$valid_from = self::date_or_today( $input, 'valid_from' );

		if ( ! $contact_id ) {
			return 0;
		}

		// Bisher offene Stufen zum Vortag beenden.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET valid_until = DATE_SUB( %s, INTERVAL 1 DAY )
				 WHERE contact_id = %d AND valid_until IS NULL AND valid_from < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$valid_from,
				$contact_id,
				$valid_from
			)
		);

		$data = array(
			'contact_id'         => $contact_id,
			'min_order_value'    => self::decimal_or( $input, 'min_order_value', 0 ),
			'discount_pct'       => self::decimal_or( $input, 'discount_pct', 0 ),
			'payment_terms_days' => self::int_or( $input, 'payment_terms_days', 0 ),
			'exclusive_postcode' => self::text( $input, 'exclusive_postcode' ),
			'valid_from'         => $valid_from,
			'valid_until'        => self::date( $input, 'valid_until' ),
			'agreed_by'          => self::text( $input, 'agreed_by' ),
			'note'               => isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : null,
			'created_at'         => current_time( 'mysql', true ),
			'created_by'         => get_current_user_id(),
		);

		$wpdb->insert( $table, $data );
		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'contact_terms', $id, null, $data );

		return $id;
	}

	/**
	 * Umsatz eines Kontakts – aus den Bestellungen, nicht gepflegt.
	 *
	 * @param int $contact_id Kontakt.
	 * @return float
	 */
	public static function revenue( $contact_id ) {
		$sum = 0.0;

		foreach ( OrderOpsRepository::all( array( 'contact_id' => $contact_id, 'limit' => 1000 ) ) as $order ) {
			if ( 'bestaetigt' !== $order['order_status'] ) {
				continue;
			}
			$sum += OrderOpsRepository::order_value( $order );
		}

		return $sum;
	}

	/**
	 * Kommissionsbestand eines Kontakts mit Alter der ältesten Lieferung.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array
	 */
	public static function commission_stock( $contact_id ) {
		global $wpdb;

		$table = StockRepository::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT article_id, color, SUM( qty ) AS qty, MIN( moved_at ) AS oldest
				 FROM {$table}
				 WHERE contact_id = %d AND location = 'kommission'
				 GROUP BY article_id, color
				 HAVING SUM( qty ) > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $contact_id
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$oldest        = strtotime( (string) $row['oldest'] );
			$row['age_days'] = $oldest ? (int) floor( ( time() - $oldest ) / DAY_IN_SECONDS ) : 0;
		}

		return $rows ? $rows : array();
	}
}
