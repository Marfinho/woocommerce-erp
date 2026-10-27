<?php
/**
 * Lagerbewegungen schreiben und auswerten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Db;
use Lindenweg16\Core\Log;

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
	 * Option: Die mitlaufende Summe ist aufgebaut und wird fortgeschrieben.
	 */
	const BALANCE_READY = 'lw16_stock_balance_ready';

	/**
	 * Tabellenname der mitlaufenden Summe.
	 *
	 * @return string
	 */
	public static function balance_table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_stock_balance';
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

		// Bewegung und Summe gehören zusammen: Fällt eines aus, gilt keins.
		$id = (int) Db::transaction(
			function () use ( $wpdb, $row ) {
				if ( false === $wpdb->insert( self::table(), $row ) ) {
					Log::error( 'lager', 'Bewegung konnte nicht geschrieben werden: ' . $wpdb->last_error, array( 'article_id' => $row['article_id'], 'qty' => $row['qty'] ) );

					return 0;
				}

				$new_id = (int) $wpdb->insert_id;
				self::add_to_balance( $row );

				return $new_id;
			}
		);

		if ( ! $id ) {
			return 0;
		}

		self::watch_negative( $row );

		BetriebAudit::record( 'create', 'stock_movements', $id, null, $row, isset( $data['void_reason'] ) ? $data['void_reason'] : '' );

		// Der Shop zieht nach (siehe WooStockSync).
		do_action( 'lw16_betrieb_stock_booked', (int) $row['article_id'], $row['color'] );

		self::maybe_announce_replenished( (int) $row['article_id'], (float) $row['qty'] );

		return $id;
	}

	/**
	 * Wird durch einen Abgang das Lager negativ? Dann Aufmerksamkeit.
	 *
	 * Verhindert wird die Buchung nicht - die Ware ist ja schon weg -, aber
	 * es soll auffallen, solange man noch nachsehen kann, was fehlt.
	 *
	 * @param array $row Gebuchte Bewegung.
	 */
	protected static function watch_negative( array $row ) {
		global $wpdb;

		if ( (float) $row['qty'] >= 0 || 'lager' !== $row['location'] ) {
			return;
		}

		$stand = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT qty FROM ' . self::balance_table() . ' WHERE article_id = %d AND color = %s AND location = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $row['article_id'],
				(string) $row['color'],
				'lager'
			)
		);

		if ( $stand < -0.0005 ) {
			Log::attention( 'lager', 'Bestand unter null: Artikel #' . (int) $row['article_id'] . ' (' . $row['color'] . ') steht bei ' . $stand, array( 'article_id' => (int) $row['article_id'] ) );
		}
	}

	/**
	 * Eine Bewegung in die mitlaufende Summe einrechnen.
	 *
	 * Ist die Summe noch nicht aufgebaut (erster Aufruf nach dem Update),
	 * wird sie jetzt aus allen Bewegungen gebaut - die neue steht bereits drin.
	 *
	 * @param array $row Bewegung (article_id, color, location, qty).
	 */
	protected static function add_to_balance( array $row ) {
		global $wpdb;

		if ( ! get_option( self::BALANCE_READY ) ) {
			self::rebuild_balances();

			return;
		}

		$table = self::balance_table();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (article_id, color, location, qty) VALUES (%d, %s, %s, %f) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $row['article_id'],
				(string) $row['color'],
				(string) $row['location'],
				(float) $row['qty']
			)
		);
	}

	/**
	 * Die mitlaufende Summe aus den Bewegungen neu aufbauen.
	 *
	 * Auch das Mittel, wenn die Systemprüfung eine Abweichung meldet.
	 *
	 * @return int Anzahl Zeilen.
	 */
	public static function rebuild_balances() {
		global $wpdb;

		$balance = self::balance_table();
		$moves   = self::table();

		$wpdb->query( "DELETE FROM {$balance}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "INSERT INTO {$balance} (article_id, color, location, qty) SELECT article_id, COALESCE(color, ''), location, SUM(qty) FROM {$moves} GROUP BY article_id, COALESCE(color, ''), location" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_option( self::BALANCE_READY, 1, false );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$balance}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Bestände als Verschachtelung: Artikel, Farbe, Lagerort.
	 *
	 * Dasselbe wie StockLedger::balances() über alle Bewegungen, nur aus der
	 * mitlaufenden Summe gelesen statt aus dem ganzen Konto errechnet.
	 *
	 * @param int $article_id Optional auf einen Artikel begrenzen.
	 * @return array article_id => color => location => Menge.
	 */
	public static function balances( $article_id = 0 ) {
		global $wpdb;

		if ( ! get_option( self::BALANCE_READY ) ) {
			self::rebuild_balances();
		}

		$table = self::balance_table();

		$rows = $article_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT article_id, color, location, qty FROM {$table} WHERE article_id = %d", (int) $article_id ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_results( "SELECT article_id, color, location, qty FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return StockLedger::from_rows( (array) $rows );
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

		$balances = self::balances( $article_id );
		$after    = 0.0;

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
	 * Wirksame Bewegungen zu einer Herkunft: Originale ohne Gegenbuchung.
	 *
	 * Anders als by_ref() fehlen hier auch die Bewegungen, die schon
	 * storniert wurden. Das ist die Grundlage dafür, eine Bestellung nicht
	 * doppelt zu buchen und nach einem Storno trotzdem neu buchen zu können.
	 *
	 * @param string $ref_type Herkunftsart.
	 * @param int    $ref_id   Herkunft.
	 * @return array
	 */
	public static function active_by_ref( $ref_type, $ref_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.* FROM {$table} m LEFT JOIN {$table} r ON r.reverses_id = m.id WHERE m.ref_type = %s AND m.ref_id = %d AND m.reverses_id IS NULL AND r.id IS NULL ORDER BY m.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
