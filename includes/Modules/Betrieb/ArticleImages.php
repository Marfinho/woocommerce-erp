<?php
/**
 * Fotos eines Artikels für den Shop.
 *
 * Im Shop gibt es den Schuhhalter einmal, im ERP je Schuhsystem einen
 * Artikel (siehe Variants). Die Fotos hängen deshalb am Artikel: Wählt der
 * Kunde „SPD-SL“, zeigt die Produktseite alle Fotos von M-SHR-SPDSL statt
 * der Fotos des Produkts.
 *
 * Ein Foto kann zusätzlich auf Merkmale eingeschränkt sein, etwa auf die
 * Farbe Schwarz oder – bei einem Set, das ein einziger Artikel ist – auf
 * das Holz Walnuss. Ohne Einschränkung gilt es für alle Varianten des
 * Artikels. Mehrere Ausprägungen desselben Merkmals sind erlaubt
 * („Weiß oder Schwarz“); verschiedene Merkmale müssen alle passen.
 *
 * Gespeichert werden die Fotos in der Mediathek, die Zuordnung in
 * `lw16_article_images`. Hochgeladen wird im ERP, nicht in wp-admin.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Fotos je Artikel, Zuordnung zu Shop-Varianten.
 */
class ArticleImages {

	/**
	 * Meta an einer Shop-Variante, deren Bild von hier gesetzt wurde.
	 */
	const META_SET = '_lw16_article_image';

	/**
	 * Tabelle.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_article_images';
	}

	/* --------------------------------------------------------------------
	 * Reine Regeln
	 * ------------------------------------------------------------------ */

	/**
	 * Einschränkung eines Fotos lesen.
	 *
	 * @param string $text `pa_farbe=schwarz&pa_farbe=weiss&pa_holz=walnuss`.
	 * @return array Merkmal => Liste erlaubter Ausprägungen (sortiert).
	 */
	public static function parse_tags( $text ) {
		$out = array();

		foreach ( preg_split( '/[&,;\r\n]+/', (string) $text ) as $chunk ) {
			if ( false === strpos( $chunk, '=' ) ) {
				continue;
			}

			list( $key, $value ) = explode( '=', $chunk, 2 );
			$key                 = Variants::key( $key );
			$value               = Variants::slug( $value );

			if ( '' === $key || '' === $value ) {
				continue;
			}

			$out[ $key ][ $value ] = true;
		}

		ksort( $out );
		foreach ( $out as $key => $values ) {
			$values = array_keys( $values );
			sort( $values );
			$out[ $key ] = $values;
		}

		return $out;
	}

	/**
	 * Einschränkung als Feldinhalt.
	 *
	 * @param array|string $tags Liste `merkmal=wert` oder Text.
	 * @return string
	 */
	public static function format_tags( $tags ) {
		$text = is_array( $tags ) ? implode( '&', array_map( 'strval', $tags ) ) : (string) $tags;
		$out  = array();

		foreach ( self::parse_tags( $text ) as $key => $values ) {
			foreach ( $values as $value ) {
				$out[] = $key . '=' . $value;
			}
		}

		return implode( '&', $out );
	}

	/**
	 * Gilt ein Foto für eine Auswahl?
	 *
	 * @param array $tags  Ergebnis von parse_tags().
	 * @param array $attrs Merkmale der Variante (vereinheitlicht).
	 * @return bool
	 */
	public static function fits( array $tags, array $attrs ) {
		foreach ( $tags as $key => $values ) {
			if ( ! isset( $attrs[ $key ] ) || ! in_array( (string) $attrs[ $key ], (array) $values, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Die Fotos einer Variante, in Reihenfolge.
	 *
	 * @param array $rows  Fotozeilen eines Artikels (attachment_id, tags, sort_order, id).
	 * @param array $attrs Merkmale der Variante.
	 * @return int[] Mediendateien.
	 */
	public static function select( array $rows, array $attrs ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				$order = (int) $a['sort_order'] - (int) $b['sort_order'];
				return 0 !== $order ? $order : (int) $a['id'] - (int) $b['id'];
			}
		);

		$out = array();

		foreach ( $rows as $row ) {
			if ( self::fits( self::parse_tags( isset( $row['tags'] ) ? (string) $row['tags'] : '' ), $attrs ) ) {
				$out[] = (int) $row['attachment_id'];
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Wählbare Merkmale für die Einschränkung – reine Logik.
	 *
	 * @param array $choices Liste {value: `merkmal=wert`, label: `Merkmal: Wert`}.
	 * @param array $fixed   Merkmale, die der Artikel schon festlegt (nicht anbieten).
	 * @return array Liste {key, label, values: [{value, label}]}.
	 */
	public static function group_choices( array $choices, array $fixed ) {
		$groups = array();

		foreach ( $choices as $choice ) {
			$value = isset( $choice['value'] ) ? (string) $choice['value'] : '';

			if ( false === strpos( $value, '=' ) ) {
				continue;
			}

			list( $key, $slug ) = explode( '=', $value, 2 );
			$key                = Variants::key( $key );

			if ( '' === $key || isset( $fixed[ $key ] ) ) {
				continue;
			}

			$label = isset( $choice['label'] ) ? (string) $choice['label'] : $value;
			$parts = explode( ': ', $label, 2 );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'key'    => $key,
					'label'  => 2 === count( $parts ) ? $parts[0] : $key,
					'values' => array(),
				);
			}

			$groups[ $key ]['values'][] = array(
				'value' => $key . '=' . Variants::slug( $slug ),
				'label' => 2 === count( $parts ) ? $parts[1] : $slug,
			);
		}

		return array_values( $groups );
	}

	/* --------------------------------------------------------------------
	 * Datenbank
	 * ------------------------------------------------------------------ */

	/**
	 * Fotos eines Artikels.
	 *
	 * @param int $article_id Artikel.
	 * @return array
	 */
	public static function for_article( $article_id ) {
		global $wpdb;
		$table = self::table();

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE article_id = %d ORDER BY sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $article_id
			),
			ARRAY_A
		);
	}

	/**
	 * Fotos mehrerer Artikel.
	 *
	 * @param int[] $article_ids Artikel.
	 * @return array article_id => Zeilen.
	 */
	public static function for_articles( array $article_ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $article_ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		$table = self::table();
		$rows  = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE article_id IN (" . implode( ',', $ids ) . ') ORDER BY sort_order ASC, id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();

		foreach ( $rows as $row ) {
			$out[ (int) $row['article_id'] ][] = $row;
		}

		return $out;
	}

	/**
	 * Ein Foto.
	 *
	 * @param int $id Zeile.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * Foto anhängen (hinten).
	 *
	 * @param int $article_id    Artikel.
	 * @param int $attachment_id Mediendatei.
	 * @return int
	 */
	public static function add( $article_id, $attachment_id ) {
		global $wpdb;
		$table = self::table();
		$max   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$table} WHERE article_id = %d", (int) $article_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->insert(
			$table,
			array(
				'article_id'    => (int) $article_id,
				'attachment_id' => (int) $attachment_id,
				'tags'          => '',
				'sort_order'    => $max + 1,
				'created_at'    => current_time( 'mysql', true ),
				'created_by'    => get_current_user_id(),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Einschränkung setzen.
	 *
	 * @param int          $id   Zeile.
	 * @param array|string $tags Liste `merkmal=wert`.
	 * @return bool
	 */
	public static function set_tags( $id, $tags ) {
		global $wpdb;

		return false !== $wpdb->update( self::table(), array( 'tags' => self::format_tags( $tags ) ), array( 'id' => (int) $id ) );
	}

	/**
	 * Foto eine Stelle nach vorn oder hinten.
	 *
	 * @param int    $id  Zeile.
	 * @param string $dir hoch|runter.
	 * @return bool
	 */
	public static function move( $id, $dir ) {
		global $wpdb;

		$row = self::find( $id );
		if ( ! $row ) {
			return false;
		}

		$rows  = self::for_article( (int) $row['article_id'] );
		$ids   = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$pos   = array_search( (int) $id, $ids, true );
		$other = 'hoch' === $dir ? $pos - 1 : $pos + 1;

		if ( false === $pos || $other < 0 || $other >= count( $ids ) ) {
			return false;
		}

		$tmp           = $ids[ $other ];
		$ids[ $other ] = $ids[ $pos ];
		$ids[ $pos ]   = $tmp;

		foreach ( $ids as $i => $row_id ) {
			$wpdb->update( self::table(), array( 'sort_order' => $i + 1 ), array( 'id' => (int) $row_id ) );
		}

		return true;
	}

	/**
	 * Foto entfernen. Die Mediendatei wird gelöscht, wenn sie sonst nirgends
	 * hängt – nicht bei einem anderen Artikel und nicht als Produktbild.
	 *
	 * @param int $id Zeile.
	 * @return bool
	 */
	public static function remove( $id ) {
		global $wpdb;

		$row = self::find( $id );
		if ( ! $row ) {
			return false;
		}

		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		self::sync_variation_images( (int) $row['article_id'] );

		$attachment = (int) $row['attachment_id'];
		$table      = self::table();
		$elsewhere  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d", $attachment ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$as_thumb   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d", $attachment ) );
		$in_gallery = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET(%d, meta_value)", $attachment ) );

		if ( ! $elsewhere && ! $as_thumb && ! $in_gallery && get_post_meta( $attachment, '_lw16_article_photo', true ) && function_exists( 'wp_delete_attachment' ) ) {
			wp_delete_attachment( $attachment, true );
		}

		return true;
	}

	/* --------------------------------------------------------------------
	 * Shop
	 * ------------------------------------------------------------------ */

	/**
	 * Wählbare Merkmale eines Artikels für die Einschränkung.
	 *
	 * @param array $article Artikel.
	 * @return array Siehe group_choices().
	 */
	public static function choices( array $article ) {
		$product_id = isset( $article['wc_product_id'] ) ? (int) $article['wc_product_id'] : 0;
		$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product ) {
			return array();
		}

		return self::group_choices(
			OrderLines::product_choices( $product, true ),
			Variants::parse( isset( $article['wc_variant'] ) ? (string) $article['wc_variant'] : '' )
		);
	}

	/**
	 * Das erste passende Foto als Bild der Shop-Varianten setzen.
	 *
	 * Warenkorb, Kasse und Bestellmail zeigen das Bild der Variante. Ein
	 * Bild, das jemand im Shop von Hand gesetzt hat, bleibt unberührt; nur
	 * ein von hier gesetztes wird geändert oder entfernt.
	 *
	 * @param int $article_id Artikel.
	 * @return int Anzahl geänderter Varianten.
	 */
	public static function sync_variation_images( $article_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$article = ArticleRepository::find( (int) $article_id );
		$product = $article && ! empty( $article['wc_product_id'] ) ? wc_get_product( (int) $article['wc_product_id'] ) : null;

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return 0;
		}

		$ctx     = OrderLines::context();
		$rows    = self::for_article( (int) $article_id );
		$changed = 0;

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( (int) $child_id );

			if ( ! $variation || OrderLines::article_for_product_object( $variation, $ctx ) !== (int) $article_id ) {
				continue;
			}

			$images  = self::select( $rows, OrderLines::variation_attributes( $variation ) );
			$current = (int) $variation->get_image_id();
			$ours    = (bool) $variation->get_meta( self::META_SET );

			if ( $images ) {
				if ( $current === (int) $images[0] && $ours ) {
					continue;
				}
				if ( $current && ! $ours ) {
					continue;
				}
				$variation->set_image_id( (int) $images[0] );
				$variation->update_meta_data( self::META_SET, 1 );
				$variation->save();
				$changed++;
			} elseif ( $ours ) {
				$variation->set_image_id( 0 );
				$variation->delete_meta_data( self::META_SET );
				$variation->save();
				$changed++;
			}
		}

		return $changed;
	}
}
