<?php
/**
 * Artikelrevisionen: versioniertes Druckprofil.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Revisionen.
 */
class RevisionRepository {

	use SanitizesInput;

	/**
	 * Erlaubte Materialien.
	 */
	const MATERIALS = array( 'PETG', 'ASA', 'PLA', 'PETG-CF' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_article_revisions';
	}

	/**
	 * Alle Revisionen eines Artikels, neueste zuerst.
	 *
	 * @param int $article_id Artikel.
	 * @return array
	 */
	public static function for_article( $article_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE article_id = %d ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $article_id
			),
			ARRAY_A
		);
	}

	/**
	 * Eine Revision holen.
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
	 * Aktive Revisionen aller Artikel – Kontext für die Kalkulation.
	 *
	 * @return array article_id => Revision.
	 */
	public static function active_by_article() {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_article = array();
		foreach ( (array) $rows as $row ) {
			$by_article[ (int) $row['article_id'] ] = $row;
		}
		return $by_article;
	}

	/**
	 * Aktive Revision eines Artikels.
	 *
	 * @param int $article_id Artikel.
	 * @return array|null
	 */
	public static function active_for_article( $article_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE article_id = %d AND is_active = 1 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $article_id
			),
			ARRAY_A
		);
	}

	/**
	 * Maßgebliche Revision eines Artikels für die Erfassung.
	 *
	 * Bevorzugt die aktive; solange keine aktiv gesetzt ist, die zuletzt
	 * angelegte. Ohne diesen Rückfall stünde am Anfang jeder Druckjob ohne
	 * Revision da – und damit ohne Bauteilgewicht, ohne Filamentverbrauch und
	 * ohne verwertbare Charge.
	 *
	 * @param int $article_id Artikel.
	 * @return array|null
	 */
	public static function current_for_article( $article_id ) {
		$active = self::active_for_article( $article_id );

		if ( $active ) {
			return $active;
		}

		$revisions = self::for_article( $article_id );

		return $revisions ? $revisions[0] : null;
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array    $input Eingaben.
	 * @param int|null $id    Eigene ID bei Bearbeitung.
	 * @return array {data, errors}
	 */
	public static function validate( array $input, $id = null ) {
		global $wpdb;

		$errors     = array();
		$article_id = self::int_or( $input, 'article_id', 0 );
		$article    = $article_id ? ArticleRepository::find( $article_id ) : null;

		if ( ! $article ) {
			$errors[] = __( 'Der Artikel existiert nicht.', 'lindenweg16' );
		} elseif ( in_array( $article['sourcing_type'], array( 'zukauf', 'montage' ), true ) ) {
			$errors[] = __( 'Nur gedruckte Artikel haben ein Druckprofil. Ein Halbzeug entsteht durch Montage, ein Zukaufartikel wird bestellt.', 'lindenweg16' );
		}

		$version = isset( $input['version'] ) ? sanitize_text_field( $input['version'] ) : '';
		if ( '' === $version ) {
			$errors[] = __( 'Eine Versionsbezeichnung ist Pflicht (z. B. v1.0).', 'lindenweg16' );
		} elseif ( $article_id ) {
			$table    = self::table();
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE article_id = %d AND version = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$article_id,
					$version
				),
				ARRAY_A
			);
			if ( $existing && (int) $existing['id'] !== (int) $id ) {
				$errors[] = sprintf(
					/* translators: %s: Version. */
					__( 'Für diesen Artikel gibt es die Version %s bereits.', 'lindenweg16' ),
					$version
				);
			}
		}

		$parts = self::int_or( $input, 'parts_per_plate', 1 );
		if ( $parts < 1 ) {
			$errors[] = __( 'Teile je Platte muss mindestens 1 sein – daraus wird die Druckzeit je Teil gerechnet.', 'lindenweg16' );
		}

		$weight = self::decimal_or( $input, 'weight_g', 0 );
		if ( $weight <= 0 ) {
			$errors[] = __( 'Ein Bauteilgewicht größer als 0 ist Pflicht – daraus kommen die Materialkosten.', 'lindenweg16' );
		}

		$material = isset( $input['material'] ) ? strtoupper( sanitize_text_field( $input['material'] ) ) : 'PETG';
		if ( ! in_array( $material, self::MATERIALS, true ) ) {
			$material = 'PETG';
		}

		return array(
			'data'   => array(
				'article_id'           => $article_id,
				'version'              => $version,
				'material'             => $material,
				'nozzle_mm'            => self::decimal( $input, 'nozzle_mm' ),
				'layer_mm'             => self::decimal( $input, 'layer_mm' ),
				'perimeters'           => self::int_or( $input, 'perimeters', 0 ),
				'infill_pct'           => self::decimal( $input, 'infill_pct' ),
				'infill_pattern'       => self::text( $input, 'infill_pattern' ),
				'nozzle_temp'          => self::int_or( $input, 'nozzle_temp', 0 ),
				'bed_temp'             => self::int_or( $input, 'bed_temp', 0 ),
				'cooling_pct'          => self::int_or( $input, 'cooling_pct', 0 ),
				'supports'             => self::flag( $input, 'supports' ),
				'orientation_note'     => isset( $input['orientation_note'] ) ? sanitize_textarea_field( $input['orientation_note'] ) : null,
				'orientation_image_id' => self::int_or( $input, 'orientation_image_id', 0 ) ? self::int_or( $input, 'orientation_image_id', 0 ) : null,
				'parts_per_plate'      => $parts,
				'print_time_plate_h'   => self::decimal_or( $input, 'print_time_plate_h', 0 ),
				'weight_g'             => $weight,
			),
			'errors' => $errors,
		);
	}

	/**
	 * Revision anlegen. Neue Revisionen sind zunächst inaktiv und werden
	 * bewusst aktiv gesetzt.
	 *
	 * @param array $data Geprüfte Daten.
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$data['is_active']  = 0;
		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'article_revisions', $id, null, $data );
		return $id;
	}

	/**
	 * Revision ändern.
	 *
	 * @param int    $id     ID.
	 * @param array  $data   Geprüfte Daten.
	 * @param string $reason Begründung.
	 * @return bool
	 */
	public static function update( $id, array $data, $reason = '' ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		unset( $data['is_active'] );
		$data['updated_at'] = current_time( 'mysql', true );

		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ) ) {
			return false;
		}
		BetriebAudit::record( 'update', 'article_revisions', (int) $id, $before, $data, $reason );
		return true;
	}

	/**
	 * Revision aktiv setzen.
	 *
	 * @param int $id Revision.
	 * @return array {success, reasons}
	 */
	public static function activate( $id ) {
		global $wpdb;

		$revision = self::find( $id );
		if ( ! $revision ) {
			return array(
				'success' => false,
				'reasons' => array( __( 'Revision nicht gefunden.', 'lindenweg16' ) ),
			);
		}

		$table      = self::table();
		$article_id = (int) $revision['article_id'];
		$now        = current_time( 'mysql', true );

		// Bisher aktive Revision ablösen und auf die neue verweisen.
		$previous = self::active_for_article( $article_id );
		if ( $previous && (int) $previous['id'] !== (int) $id ) {
			$wpdb->update(
				$table,
				array(
					'is_active'     => 0,
					'superseded_by' => (int) $id,
					'updated_at'    => $now,
				),
				array( 'id' => (int) $previous['id'] )
			);
			BetriebAudit::record( 'update', 'article_revisions', (int) $previous['id'], $previous, array( 'is_active' => 0, 'superseded_by' => (int) $id ), __( 'Durch neue Revision abgelöst.', 'lindenweg16' ) );
		}

		$data = array(
			'is_active'   => 1,
			'released_at' => $now,
			'released_by' => get_current_user_id(),
			'updated_at'  => $now,
		);

		$wpdb->update( $table, $data, array( 'id' => (int) $id ) );
		BetriebAudit::record(
			'update',
			'article_revisions',
			(int) $id,
			$revision,
			$data,
			__( 'Revision aktiv gesetzt.', 'lindenweg16' )
		);

		return array(
			'success' => true,
			'reasons' => array(),
		);
	}
}
