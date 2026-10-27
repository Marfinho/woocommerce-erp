<?php
/**
 * Die Teile des Konfigurators: welcher Artikel wie im Raum erscheint.
 *
 * Gepflegt wird hier nur die Geometrie und die Rolle. Bezeichnung, Preis
 * und Verfügbarkeit bleiben da, wo sie hingehören – im Artikelstamm und im
 * Shop. Ein Teil ohne gültigen Artikel ist deshalb kein halb gepflegter
 * Datensatz, sondern gar keiner: Der Konfigurator blendet es aus.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\BetriebAudit;

/**
 * Lesen und Schreiben der Konfigurator-Teile.
 */
class PartRepository {

	/**
	 * Erlaubte Rollen.
	 */
	const ROLES = array( 'basis', 'modul' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_config_parts';
	}

	/**
	 * Beschriftung der Rollen.
	 *
	 * @return array
	 */
	public static function role_labels() {
		return array(
			'basis' => __( 'Basismodul (die Schiene)', 'lindenweg16' ),
			'modul' => __( 'Modul (hängt an der Schiene)', 'lindenweg16' ),
		);
	}

	/**
	 * Alle Teile, nach Rolle und Sortierung.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY role ASC, sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Ein Teil.
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
	 * Das Teil zu einem Artikel.
	 *
	 * @param int $article_id Artikel.
	 * @return array|null
	 */
	public static function find_by_article( $article_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE article_id = %d", (int) $article_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Stammdaten für die Prüfung einer Konfiguration.
	 *
	 * Liefert genau die Form, die Layout erwartet: Teile nach Artikel-ID,
	 * Sperrbereiche nach Artikel-ID des Basismoduls.
	 *
	 * @param bool $only_active Nur aktive Teile aktiver Artikel.
	 * @return array {parts, blocks}
	 */
	public static function context( $only_active = true ) {
		$articles = ArticleRepository::all_by_id();
		$blocks   = BlockRepository::all_by_part();
		$parts    = array();
		$zones    = array();

		foreach ( self::all() as $row ) {
			$article_id = (int) $row['article_id'];
			$article    = isset( $articles[ $article_id ] ) ? $articles[ $article_id ] : null;

			// Ohne Artikel gibt es weder Bezeichnung noch Preis. So ein Teil
			// darf im Shop nicht auftauchen, auch nicht als leere Zeile.
			if ( ! $article ) {
				continue;
			}

			$active = (int) $row['is_active'] && ArticleRepository::is_active( $article );

			if ( $only_active && ! $active ) {
				continue;
			}

			$parts[ $article_id ] = array(
				'part_id'       => (int) $row['id'],
				'article_id'    => $article_id,
				'role'          => (string) $row['role'],
				'name'          => (string) $article['name'],
				'sku'           => (string) $article['sku'],
				'teaser'        => (string) $row['teaser'],
				'model_file'    => (string) $row['model_file'],
				'height_mm'     => (float) $row['height_mm'],
				'width_mm'      => (float) $row['width_mm'],
				'depth_mm'      => (float) $row['depth_mm'],
				'max_modules'   => (int) $row['max_modules'],
				'sort_order'    => (int) $row['sort_order'],
				'is_active'     => $active ? 1 : 0,
				'wc_product_id' => (int) $article['wc_product_id'],
			);

			$zones[ $article_id ] = isset( $blocks[ (int) $row['id'] ] ) ? $blocks[ (int) $row['id'] ] : array();
		}

		return array(
			'parts'  => $parts,
			'blocks' => $zones,
		);
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array      $input    Rohe Eingaben.
	 * @param int|null   $id       Bei Bearbeitung die eigene ID.
	 * @param array|null $measured Maße aus dem Modell {width, height, depth}. Sie
	 *                             füllen leere Felder; mit `maße_aus_modell` in den
	 *                             Eingaben ersetzen sie auch eingetragene Werte.
	 * @return array {data, errors}
	 */
	public static function validate( array $input, $id = null, $measured = null ) {
		$errors = array();

		$article_id = isset( $input['article_id'] ) ? (int) $input['article_id'] : 0;
		$article    = $article_id ? ArticleRepository::find( $article_id ) : null;

		if ( ! $article ) {
			$errors[] = __( 'Bitte einen Artikel aus dem Artikelstamm wählen.', 'lindenweg16' );
		} else {
			$existing = self::find_by_article( $article_id );
			if ( $existing && (int) $existing['id'] !== (int) $id ) {
				$errors[] = sprintf(
					/* translators: %s: Bezeichnung des Artikels. */
					__( 'Für „%s" ist bereits ein Teil angelegt.', 'lindenweg16' ),
					$article['name']
				);
			}
		}

		$role = isset( $input['role'] ) && in_array( $input['role'], self::ROLES, true ) ? $input['role'] : 'modul';

		$data = array(
			'article_id'  => $article_id,
			'role'        => $role,
			'height_mm'   => self::measure( $input, 'height_mm' ),
			'width_mm'    => self::measure( $input, 'width_mm' ),
			'depth_mm'    => self::measure( $input, 'depth_mm' ),
			'max_modules' => isset( $input['max_modules'] ) ? max( 0, min( Layout::MAX_MODULES, (int) $input['max_modules'] ) ) : 0,
			'sort_order'  => isset( $input['sort_order'] ) ? max( 0, min( 9999, (int) $input['sort_order'] ) ) : 0,
			'is_active'   => empty( $input['is_active'] ) ? 0 : 1,
			'teaser'      => isset( $input['teaser'] ) ? sanitize_text_field( $input['teaser'] ) : null,
		);

		// Das Modell kennt seine Maße. Eingetragen werden müssen sie nur,
		// wenn es kein Modell gibt oder die Datei keine hergibt.
		if ( is_array( $measured ) ) {
			$override = ! empty( $input['measure_from_model'] );
			$keys    = array(
				'height_mm' => 'height',
				'width_mm'  => 'width',
				'depth_mm'  => 'depth',
			);

			foreach ( $keys as $column => $axis ) {
				if ( isset( $measured[ $axis ] ) && (float) $measured[ $axis ] > 0 && ( $override || $data[ $column ] <= 0 ) ) {
					$data[ $column ] = round( (float) $measured[ $axis ], 2 );
				}
			}
		}

		// Die Höhe ist die einzige Angabe, ohne die nichts geht: Sie
		// entscheidet, wie viel Platz ein Modul belegt und wie lang die
		// Schiene ist. Breite und Tiefe dienen nur der Ersatzdarstellung,
		// solange kein Modell hinterlegt ist.
		if ( $data['height_mm'] <= 0 ) {
			$errors[] = 'basis' === $role
				? __( 'Das Basismodul braucht seine Länge in Millimetern – sie ist der Weg, auf dem die Module sitzen. Mit einem Modell (.glb) liest der Konfigurator sie selbst aus.', 'lindenweg16' )
				: __( 'Das Modul braucht seine Bauhöhe in Millimetern. Mit einem Modell (.glb) liest der Konfigurator sie selbst aus.', 'lindenweg16' );
		}

		if ( 'modul' === $role && $data['max_modules'] > 0 ) {
			$errors[] = __( 'Eine Modulzahl wird am Basismodul gepflegt, nicht am Modul selbst.', 'lindenweg16' );
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	/**
	 * Maßangabe aus dem Formular.
	 *
	 * @param array  $input Eingaben.
	 * @param string $key   Feld.
	 * @return float
	 */
	protected static function measure( array $input, $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			return 0.0;
		}

		$value = str_replace( ',', '.', (string) $input[ $key ] );

		return max( 0.0, min( 99999.99, round( (float) $value, 2 ) ) );
	}

	/**
	 * Teil anlegen.
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
		BetriebAudit::record( 'create', 'config_parts', $id, null, $data );

		return $id;
	}

	/**
	 * Teil ändern.
	 *
	 * @param int   $id   ID.
	 * @param array $data Geprüfte Daten.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql', true );

		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ) ) {
			return false;
		}

		BetriebAudit::record( 'update', 'config_parts', (int) $id, $before, $data );

		return true;
	}

	/**
	 * Nur die Modelldatei setzen.
	 *
	 * @param int    $id   ID.
	 * @param string $file Dateiname oder leer zum Entfernen.
	 * @return bool
	 */
	public static function set_model( $id, $file ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		$data = array(
			'model_file' => '' === (string) $file ? null : (string) $file,
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ) ) {
			return false;
		}

		BetriebAudit::record( 'update', 'config_parts', (int) $id, $before, $data );

		return true;
	}

	/**
	 * Teil löschen – samt seiner Sperrbereiche und der Modelldatei.
	 *
	 * Ein Teil ist Darstellung, keine Buchung: Es darf verschwinden. Der
	 * Artikel dahinter bleibt selbstverständlich unberührt.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		BlockRepository::delete_for_part( (int) $id );
		WebModel::delete( (string) $before['model_file'] );

		if ( false === $wpdb->delete( self::table(), array( 'id' => (int) $id ) ) ) {
			return false;
		}

		BetriebAudit::record( 'delete', 'config_parts', (int) $id, $before, null );

		return true;
	}
}
