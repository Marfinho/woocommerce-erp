<?php
/**
 * Chargenbuch – GPSR-Rückverfolgbarkeit.
 *
 * Der Chargencode wird serverseitig nach dem Schema JJMM-SKU-lfd vergeben.
 * Er wird ins Bauteil eingeprägt und ist damit die Klammer zwischen einem
 * ausgelieferten Teil, seinem Druckprofil, seiner Filamentcharge und den
 * Zugversuchen, die es freigegeben haben.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Vergabe und Auswertung der Chargen.
 */
class BatchRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_batches';
	}

	/**
	 * Chargencode bilden – reine Logik.
	 *
	 * Schema JJMM-SKU-lfd, wobei die Artikelnummer auf Buchstaben und Ziffern
	 * reduziert wird: Der Code muss sich in den Slicer tippen und ins Bauteil
	 * prägen lassen.
	 *
	 * @param string $produced_on Produktionsdatum (Y-m-d).
	 * @param string $sku         Artikelnummer.
	 * @param int    $sequence    Laufende Nummer im Monat.
	 * @return string
	 */
	public static function build_code( $produced_on, $sku, $sequence ) {
		$compact = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $sku ) );

		return sprintf(
			'%s-%s-%02d',
			gmdate( 'ym', strtotime( (string) $produced_on . ' 12:00:00 UTC' ) ),
			$compact,
			(int) $sequence
		);
	}

	/**
	 * Nächsten freien Chargencode für Artikel und Monat ermitteln.
	 *
	 * @param string $produced_on Produktionsdatum.
	 * @param string $sku         Artikelnummer.
	 * @return string
	 */
	public static function next_code( $produced_on, $sku ) {
		global $wpdb;

		$table  = self::table();
		$prefix = self::build_code( $produced_on, $sku, 1 );
		$prefix = substr( $prefix, 0, strrpos( $prefix, '-' ) + 1 );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE batch_code LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return self::build_code( $produced_on, $sku, $count + 1 );
	}

	/**
	 * Charge zu einem Druckjob anlegen.
	 *
	 * @param int   $job_id Druckjob.
	 * @param array $job    Jobdaten.
	 * @return int Charge oder 0.
	 */
	public static function create_for_job( $job_id, array $job ) {
		global $wpdb;

		$article = ArticleRepository::find( (int) $job['article_id'] );
		if ( ! $article ) {
			return 0;
		}

		$data = array(
			'batch_code'          => self::next_code( $job['job_date'], $article['sku'] ),
			'produced_on'         => $job['job_date'],
			'article_id'          => (int) $job['article_id'],
			'article_revision_id' => ! empty( $job['article_revision_id'] ) ? (int) $job['article_revision_id'] : null,
			'filament_lot_id'     => ! empty( $job['filament_lot_id'] ) ? (int) $job['filament_lot_id'] : null,
			'printer_id'          => (int) $job['printer_id'],
			'qty'                 => (float) $job['parts_ok'],
			'inspected'           => 0,
			'created_at'          => current_time( 'mysql', true ),
			'created_by'          => get_current_user_id(),
		);

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'batches', $id, null, $data );

		return $id;
	}

	/**
	 * Alle Chargen.
	 *
	 * @param array $args Filter.
	 * @return array
	 */
	public static function all( array $args = array() ) {
		global $wpdb;

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'article_id', 'filament_lot_id', 'printer_id' ) as $field ) {
			if ( ! empty( $args[ $field ] ) ) {
				$where[]  = "{$field} = %d";
				$params[] = (int) $args[ $field ];
			}
		}

		if ( empty( $args['include_voided'] ) ) {
			$where[] = 'voided_at IS NULL';
		}

		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY produced_on DESC, id DESC LIMIT %d';
		$params[] = isset( $args['limit'] ) ? (int) $args['limit'] : 200;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Eine Charge holen.
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
	 * Charge über ihren Code holen.
	 *
	 * @param string $code Chargencode.
	 * @return array|null
	 */
	public static function find_by_code( $code ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE batch_code = %s", (string) $code ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Charge stornieren – ohne sie zu löschen.
	 *
	 * Wird der zugehörige Druckjob storniert, wurde die Charge nie gefertigt.
	 * Sie bleibt stehen, damit die Nummernfolge lückenlos bleibt, ist aber
	 * gekennzeichnet: Eine stornierte Charge darf in der Rückverfolgung nicht
	 * als ausgeliefert erscheinen.
	 *
	 * @param int    $id     Charge.
	 * @param string $reason Begründung.
	 * @return bool
	 */
	public static function void( $id, $reason ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before || $before['voided_at'] ) {
			return false;
		}

		$data = array(
			'voided_at'   => current_time( 'mysql', true ),
			'void_reason' => sanitize_text_field( $reason ),
			'updated_at'  => current_time( 'mysql', true ),
		);

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'void', 'batches', (int) $id, $before, $data, $reason );

		return true;
	}

	/**
	 * Prüfung einer Charge festhalten.
	 *
	 * @param int    $id          Charge.
	 * @param bool   $inspected   Geprüft.
	 * @param string $disposition Verbleib.
	 * @param string $note        Bemerkung.
	 * @return bool
	 */
	public static function set_inspection( $id, $inspected, $disposition = '', $note = '' ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		$data = array(
			'inspected'   => $inspected ? 1 : 0,
			'disposition' => $disposition ? sanitize_text_field( $disposition ) : null,
			'note'        => $note ? sanitize_textarea_field( $note ) : null,
			'updated_at'  => current_time( 'mysql', true ),
		);

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'update', 'batches', (int) $id, $before, $data );

		return true;
	}
}
