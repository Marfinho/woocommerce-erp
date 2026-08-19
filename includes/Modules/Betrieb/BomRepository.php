<?php
/**
 * Stücklistenzeilen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Stückliste inklusive Zyklenprüfung.
 */
class BomRepository {

	use SanitizesInput;

	/**
	 * Erlaubte Kindtypen.
	 */
	const CHILD_TYPES = array( 'artikel', 'komponente' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_bom';
	}

	/**
	 * Alle Zeilen, nach Elternartikel gruppiert – Kontext für die Auflösung.
	 *
	 * @return array
	 */
	public static function all_by_parent() {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY parent_article_id ASC, position ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row['parent_article_id'] ][] = $row;
		}
		return $grouped;
	}

	/**
	 * Zeilen eines Artikels.
	 *
	 * @param int $article_id Artikel.
	 * @return array
	 */
	public static function for_article( $article_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE parent_article_id = %d ORDER BY position ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $article_id
			),
			ARRAY_A
		);
	}

	/**
	 * Eine Zeile holen.
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
	 * Eingaben prüfen, inklusive Zyklenprüfung gegen den aktuellen Bestand.
	 *
	 * @param array    $input Eingaben.
	 * @param int|null $id    Eigene ID bei Bearbeitung.
	 * @return array {data, errors}
	 */
	public static function validate( array $input, $id = null ) {
		$errors = array();

		$parent = self::int_or( $input, 'parent_article_id', 0 );
		$type   = self::enum( $input, 'child_type', self::CHILD_TYPES, 'komponente' );
		$child  = self::int_or( $input, 'child_id', 0 );
		$qty    = self::decimal_or( $input, 'qty', 1 );

		if ( ! $parent || ! ArticleRepository::find( $parent ) ) {
			$errors[] = __( 'Der Elternartikel existiert nicht.', 'lindenweg16' );
		}

		if ( ! $child ) {
			$errors[] = __( 'Bitte eine Position auswählen.', 'lindenweg16' );
		} elseif ( 'artikel' === $type && ! ArticleRepository::find( $child ) ) {
			$errors[] = __( 'Die gewählte Unterbaugruppe existiert nicht.', 'lindenweg16' );
		} elseif ( 'komponente' === $type && ! ComponentRepository::find( $child ) ) {
			$errors[] = __( 'Die gewählte Komponente existiert nicht.', 'lindenweg16' );
		}

		if ( $qty <= 0 ) {
			$errors[] = __( 'Die Menge muss größer als 0 sein.', 'lindenweg16' );
		}

		// Zyklenprüfung: gegen den Bestand ohne die eigene Zeile, sonst
		// blockiert eine Zeile beim Bearbeiten sich selbst.
		if ( ! $errors && 'artikel' === $type ) {
			$ctx = array( 'bom' => self::all_by_parent_excluding( $id ) );
			if ( BomResolver::would_create_cycle( $parent, $child, $ctx ) ) {
				$errors[] = __( 'Diese Position würde einen Kreis in der Stückliste erzeugen – ein Artikel darf sich nicht selbst enthalten.', 'lindenweg16' );
			}
		}

		return array(
			'data'   => array(
				'parent_article_id' => $parent,
				'child_type'        => $type,
				'child_id'          => $child,
				'qty'               => $qty,
				'position'          => self::int_or( $input, 'position', 0 ),
				'note'              => self::text( $input, 'note' ),
			),
			'errors' => $errors,
		);
	}

	/**
	 * Alle Zeilen ohne eine bestimmte ID, nach Elternartikel gruppiert.
	 *
	 * @param int|null $exclude_id Auszulassende Zeile.
	 * @return array
	 */
	public static function all_by_parent_excluding( $exclude_id = null ) {
		$grouped = self::all_by_parent();

		if ( ! $exclude_id ) {
			return $grouped;
		}

		foreach ( $grouped as $parent => $rows ) {
			$grouped[ $parent ] = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $exclude_id ) {
						return (int) $row['id'] !== (int) $exclude_id;
					}
				)
			);
		}
		return $grouped;
	}

	/**
	 * Zeile anlegen.
	 *
	 * @param array $data Geprüfte Daten.
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'bom', $id, null, $data );
		return $id;
	}

	/**
	 * Zeile ändern.
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

		$data['updated_at'] = current_time( 'mysql', true );

		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ) ) {
			return false;
		}
		BetriebAudit::record( 'update', 'bom', (int) $id, $before, $data, $reason );
		return true;
	}

	/**
	 * Zeile entfernen.
	 *
	 * Stücklisten sind Stammdaten, keine Journaldaten – hier ist echtes
	 * Löschen zulässig. Der Vorgang wird protokolliert, und alte Bestellungen
	 * bleiben über die Artikelrevision nachvollziehbar.
	 *
	 * @param int    $id     ID.
	 * @param string $reason Begründung.
	 * @return bool
	 */
	public static function delete( $id, $reason = '' ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		if ( false === $wpdb->delete( self::table(), array( 'id' => (int) $id ) ) ) {
			return false;
		}
		BetriebAudit::record( 'void', 'bom', (int) $id, $before, null, $reason );
		return true;
	}
}
