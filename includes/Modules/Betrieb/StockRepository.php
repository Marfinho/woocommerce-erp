<?php
/**
 * Lagerbewegungen schreiben und auswerten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Zugriff auf das Bewegungskonto.
 */
class StockRepository {

	use SanitizesInput;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_stock_movements';
	}

	/**
	 * Tabellenname der Sollbestände.
	 *
	 * @return string
	 */
	public static function targets_table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_stock_targets';
	}

	/**
	 * Eine Bewegung buchen.
	 *
	 * @param array $data Bewegungsdaten.
	 * @return int Neue ID oder 0.
	 */
	public static function book( array $data ) {
		global $wpdb;

		$row = array(
			'article_id'    => isset( $data['article_id'] ) ? (int) $data['article_id'] : 0,
			'color'         => isset( $data['color'] ) ? ColorRegistry::normalize( $data['color'] ) : '',
			'qty'           => isset( $data['qty'] ) ? (float) $data['qty'] : 0.0,
			'movement_type' => isset( $data['movement_type'] ) ? sanitize_key( $data['movement_type'] ) : 'korrektur',
			'location'      => isset( $data['location'] ) && in_array( $data['location'], StockLedger::LOCATIONS, true ) ? $data['location'] : 'lager',
			'bin_id'        => ! empty( $data['bin_id'] ) ? (int) $data['bin_id'] : null,
			'contact_id'    => ! empty( $data['contact_id'] ) ? (int) $data['contact_id'] : null,
			'ref_type'      => isset( $data['ref_type'] ) ? sanitize_key( $data['ref_type'] ) : null,
			'ref_id'        => ! empty( $data['ref_id'] ) ? (int) $data['ref_id'] : null,
			'moved_at'      => isset( $data['moved_at'] ) ? $data['moved_at'] : current_time( 'mysql', true ),
			'note'          => isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : null,
			'reverses_id'   => ! empty( $data['reverses_id'] ) ? (int) $data['reverses_id'] : null,
			'void_reason'   => isset( $data['void_reason'] ) ? sanitize_text_field( $data['void_reason'] ) : null,
			'created_at'    => current_time( 'mysql', true ),
			'created_by'    => get_current_user_id(),
		);

		if ( ! $row['article_id'] ) {
			return 0;
		}

		if ( '' === $row['color'] ) {
			$row['color'] = ColorRegistry::default_color();
		}

		if ( false === $wpdb->insert( self::table(), $row ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'stock_movements', $id, null, $row, isset( $data['void_reason'] ) ? $data['void_reason'] : '' );

		self::maybe_announce_replenished( (int) $row['article_id'], (float) $row['qty'] );

		return $id;
	}

	/**
	 * Meldung, wenn ein Artikel wieder lieferbar wird.
	 *
	 * Die Warteliste hing bisher an einem Ereignis der abgelösten
	 * Lagerverwaltung. Seit das Modul Betrieb bucht, wurde es nie mehr
	 * ausgelöst – die „Ist wieder da"-Mail ging also nicht mehr raus.
	 *
	 * Geprüft wird nur bei Zugängen, und nur der Übergang von leer auf
	 * vorhanden. Ohne diese Bedingung käme bei jedem Wareneingang eine Mail.
	 *
	 * @param int   $article_id Artikel.
	 * @param float $qty        Gebuchte Menge.
	 */
	protected static function maybe_announce_replenished( $article_id, $qty ) {
		if ( $article_id <= 0 || $qty <= 0 ) {
			return;
		}

		$movements = self::all( $article_id );
		$balances  = StockLedger::balances( $movements );
		$after     = 0.0;

		foreach ( isset( $balances[ $article_id ] ) ? $balances[ $article_id ] : array() as $locations ) {
			$after += isset( $locations['lager'] ) ? (float) $locations['lager'] : 0.0;
		}

		$before = $after - $qty;

		if ( $before > 0.0005 || $after <= 0.0005 ) {
			return;
		}

		do_action( 'lw16_betrieb_stock_replenished', $article_id, $after );
	}

	/**
	 * Mehrere Bewegungen als Einheit buchen (Kommissionspaare).
	 *
	 * @param array $movements Bewegungen.
	 * @param array $common    Gemeinsame Felder.
	 * @return int[] Neue IDs.
	 */
	public static function book_many( array $movements, array $common = array() ) {
		$ids = array();
		foreach ( $movements as $movement ) {
			$ids[] = self::book( array_merge( $common, $movement ) );
		}
		return $ids;
	}

	/**
	 * Eine Bewegung stornieren – durch Gegenbuchung, nie durch Löschen.
	 *
	 * @param int    $id     Ursprüngliche Bewegung.
	 * @param string $reason Begründung (Pflicht).
	 * @return array {success, message}
	 */
	public static function void( $id, $reason ) {
		global $wpdb;

		$reason = trim( (string) $reason );
		if ( '' === $reason ) {
			return array(
				'success' => false,
				'message' => __( 'Eine Stornobuchung braucht eine Begründung.', 'lindenweg16' ),
			);
		}

		$table    = self::table();
		$original = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! $original ) {
			return array(
				'success' => false,
				'message' => __( 'Bewegung nicht gefunden.', 'lindenweg16' ),
			);
		}

		$already = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE reverses_id = %d", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $already ) {
			return array(
				'success' => false,
				'message' => __( 'Diese Bewegung wurde bereits storniert.', 'lindenweg16' ),
			);
		}

		self::book( StockLedger::reversal( $original, $reason ) );

		return array(
			'success' => true,
			'message' => __( 'Storno gebucht.', 'lindenweg16' ),
		);
	}

	/**
	 * Bewegungen zu einer Herkunft, Stornos ausgenommen.
	 *
	 * Gegenbuchungen tragen dieselbe Herkunft wie das Original – wer sie
	 * mitnimmt, storniert den Storno.
	 *
	 * @param string $ref_type Herkunftsart.
	 * @param int    $ref_id   Herkunft.
	 * @return array
	 */
	public static function by_ref( $ref_type, $ref_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ref_type = %s AND ref_id = %d AND reverses_id IS NULL ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $ref_type,
				(int) $ref_id
			),
			ARRAY_A
		);
	}

	/**
	 * Alle Bewegungen (für die Bestandsermittlung).
	 *
	 * @param int $article_id Optional auf einen Artikel begrenzen.
	 * @return array
	 */
	public static function all( $article_id = 0 ) {
		global $wpdb;

		$table = self::table();

		if ( $article_id ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE article_id = %d ORDER BY id ASC", (int) $article_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Letzte Bewegungen für die Journalansicht.
	 *
	 * @param int $limit Anzahl.
	 * @return array
	 */
	public static function recent( $limit = 100 ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Verbrauch eines Artikels in den letzten Wochen (für die Reichweite).
	 *
	 * @param int $article_id Artikel.
	 * @param int $weeks      Zeitraum in Wochen.
	 * @return float Positiver Verbrauchswert.
	 */
	public static function consumption( $article_id, $weeks = 4 ) {
		global $wpdb;

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $weeks * 7 * DAY_IN_SECONDS );

		$sum = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( qty ), 0 ) FROM {$table}
				 WHERE article_id = %d AND moved_at >= %s AND qty < 0
				 AND movement_type IN ( 'verkauf', 'kommission_abgerechnet', 'muster' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $article_id,
				$since
			)
		);

		return abs( $sum );
	}

	/**
	 * Bewegungen ohne geklärte Farbe – gehören ins Dashboard.
	 *
	 * @return array
	 */
	public static function unresolved() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE color = %s ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ColorRegistry::UNRESOLVED
			),
			ARRAY_A
		);
	}

	/**
	 * Sollbestände.
	 *
	 * @return array
	 */
	public static function targets() {
		global $wpdb;
		$table = self::targets_table();
		return $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Sollbestand entfernen.
	 *
	 * Kein Buchungssatz, sondern eine Planungsvorgabe – die darf weg. Die
	 * Bewegungen des Artikels bleiben davon unberührt.
	 *
	 * @param int    $article_id Artikel.
	 * @param string $color      Farbe.
	 * @return bool
	 */
	public static function remove_target( $article_id, $color ) {
		global $wpdb;

		$table = self::targets_table();
		$color = ColorRegistry::normalize( $color );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE article_id = %d AND color = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $article_id,
				$color
			),
			ARRAY_A
		);

		if ( ! $existing ) {
			return false;
		}

		$wpdb->delete( $table, array( 'id' => (int) $existing['id'] ) );
		BetriebAudit::record( 'delete', 'stock_targets', (int) $existing['id'], $existing, null );

		return true;
	}

	/**
	 * Sollbestand setzen.
	 *
	 * @param int    $article_id Artikel.
	 * @param string $color      Farbe.
	 * @param float  $qty        Sollmenge.
	 * @return bool
	 */
	public static function set_target( $article_id, $color, $qty ) {
		global $wpdb;

		$table = self::targets_table();
		$color = ColorRegistry::normalize( $color );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE article_id = %d AND color = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $article_id,
				$color
			),
			ARRAY_A
		);

		$data = array(
			'article_id' => (int) $article_id,
			'color'      => $color,
			'target_qty' => (float) $qty,
		);

		if ( $existing ) {
			$data['updated_at'] = current_time( 'mysql', true );
			$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
			BetriebAudit::record( 'update', 'stock_targets', (int) $existing['id'], $existing, $data );
			return true;
		}

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();
		$wpdb->insert( $table, $data );
		BetriebAudit::record( 'create', 'stock_targets', (int) $wpdb->insert_id, null, $data );

		return true;
	}
}
