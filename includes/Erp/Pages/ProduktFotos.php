<?php
/**
 * Produktakte, Reiter „Fotos“: Fotos für den Shop im ERP hochladen.
 *
 * Die Fotos hängen am Artikel (siehe Modules\Betrieb\ArticleImages). Die
 * Produktseite im Shop zeigt beim Wählen einer Variante alle Fotos des
 * Artikels dahinter.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ArticleImages;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen erp/produkte/<id>/fotos.
 */
class ProduktFotos {

	/**
	 * Höchstzahl Dateien je Hochladen.
	 */
	const MAX_FILES = 20;

	/**
	 * Höchstgröße je Foto (Byte).
	 */
	const MAX_BYTES = 15728640;

	/**
	 * Erlaubte Bildtypen.
	 */
	const TYPES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api REST-Zugang (Rechteprüfung).
	 */
	public static function register( RestApi $api ) {
		$edit = $api->needs( array( 'fertigung.artikel', 'fertigung.stammdaten.edit' ) );
		$base = '/erp/produkte/(?P<id>\d+)/fotos';

		$route = static function ( $path, $methods, $callback ) use ( $edit ) {
			register_rest_route(
				RestApi::NAMESPACE_V1,
				$path,
				array(
					'methods'             => $methods,
					'callback'            => $callback,
					'permission_callback' => $edit,
				)
			);
		};

		$route( $base, 'GET', array( __CLASS__, 'list_photos' ) );
		$route( $base, 'POST', array( __CLASS__, 'upload' ) );
		$route( $base . '/(?P<fid>\d+)', 'POST', array( __CLASS__, 'update' ) );
		$route( $base . '/(?P<fid>\d+)/entfernen', 'POST', array( __CLASS__, 'remove' ) );
		$route( '/erp/produkte/(?P<id>\d+)/shoptext', 'POST', array( __CLASS__, 'save_texts' ) );
	}

	/**
	 * Kurztext und Beschreibung der Shop-Variante speichern.
	 *
	 * Leer heißt: Der Shop zeigt den Text des Produkts.
	 *
	 * @param WP_REST_Request $request Anfrage: short, description.
	 * @return WP_REST_Response
	 */
	public static function save_texts( WP_REST_Request $request ) {
		global $wpdb;

		$article = self::article( $request );

		if ( ! $article ) {
			return self::fail( __( 'Dieses Produkt gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$params = (array) $request->get_json_params();
		$data   = array(
			'shop_short'       => trim( wp_kses_post( (string) ( isset( $params['short'] ) ? $params['short'] : '' ) ) ),
			'shop_description' => trim( wp_kses_post( (string) ( isset( $params['description'] ) ? $params['description'] : '' ) ) ),
		);

		$before = array(
			'shop_short'       => isset( $article['shop_short'] ) ? (string) $article['shop_short'] : '',
			'shop_description' => isset( $article['shop_description'] ) ? (string) $article['shop_description'] : '',
		);

		if ( false === $wpdb->update( ArticleRepository::table(), array_merge( $data, array( 'updated_at' => current_time( 'mysql', true ) ) ), array( 'id' => (int) $article['id'] ) ) ) {
			return self::fail( __( 'Die Texte ließen sich nicht speichern. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		\Lindenweg16\Modules\Betrieb\BetriebAudit::record( 'update', 'articles', (int) $article['id'], $before, $data, __( 'Shop-Texte der Variante', 'lindenweg16' ) );

		return new WP_REST_Response( self::payload( ArticleRepository::find( (int) $article['id'] ), __( 'Texte gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Fehlerantwort.
	 *
	 * @param string $message Text.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Artikel der Anfrage.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array|null
	 */
	protected static function article( WP_REST_Request $request ) {
		return ArticleRepository::find( (int) $request['id'] );
	}

	/**
	 * Foto der Anfrage, nur wenn es zum Artikel gehört.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array|null
	 */
	protected static function photo( WP_REST_Request $request ) {
		$row = ArticleImages::find( (int) $request['fid'] );

		return $row && (int) $row['article_id'] === (int) $request['id'] ? $row : null;
	}

	/**
	 * Fotos und wählbare Merkmale.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function list_photos( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::fail( __( 'Dieses Produkt gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		return new WP_REST_Response( self::payload( $article ) );
	}

	/**
	 * Antwort mit allen Fotos.
	 *
	 * @param array  $article Artikel.
	 * @param string $message Meldung.
	 * @return array
	 */
	protected static function payload( array $article, $message = '' ) {
		$photos = array();

		foreach ( ArticleImages::for_article( (int) $article['id'] ) as $row ) {
			$id       = (int) $row['attachment_id'];
			$photos[] = array(
				'id'    => (int) $row['id'],
				'thumb' => (string) wp_get_attachment_image_url( $id, 'medium' ),
				'full'  => (string) wp_get_attachment_image_url( $id, 'full' ),
				'name'  => (string) get_the_title( $id ),
				'tags'  => '' === (string) $row['tags'] ? array() : explode( '&', (string) $row['tags'] ),
			);
		}

		return array(
			'ok'      => true,
			'message' => $message,
			'shop'    => ! empty( $article['wc_product_id'] ),
			'variant' => isset( $article['wc_variant'] ) ? (string) $article['wc_variant'] : '',
			'choices' => ArticleImages::choices( $article ),
			'photos'  => $photos,
			'texts'   => array(
				'short'       => isset( $article['shop_short'] ) ? (string) $article['shop_short'] : '',
				'description' => isset( $article['shop_description'] ) ? (string) $article['shop_description'] : '',
			),
			'modules' => self::modules( $article ),
		);
	}

	/**
	 * Module eines Sets, deren Hauptbild die Galerie des Sets ergänzt.
	 *
	 * @param array $article Artikel.
	 * @return string[] Bezeichnungen.
	 */
	protected static function modules( array $article ) {
		$out      = array();
		$articles = ArticleRepository::all_by_id();

		foreach ( \Lindenweg16\Modules\Betrieb\BomRepository::for_article( (int) $article['id'] ) as $row ) {
			$row   = (array) $row;
			$child = (int) $row['child_id'];
			if ( 'artikel' === $row['child_type'] && ! empty( $articles[ $child ]['wc_product_id'] ) ) {
				$out[] = $articles[ $child ]['sku'] . ' – ' . $articles[ $child ]['name'];
			}
		}

		return $out;
	}

	/**
	 * Fotos hochladen.
	 *
	 * @param WP_REST_Request $request Anfrage mit Dateien in `foto[]`.
	 * @return WP_REST_Response
	 */
	public static function upload( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::fail( __( 'Dieses Produkt gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$params = $request->get_file_params();
		$files  = self::normalize( isset( $params['foto'] ) ? $params['foto'] : null );

		if ( ! $files ) {
			return self::fail( __( 'Es kam kein Foto an. Entweder wurde keins gewählt, oder die Dateien sind zusammen größer, als der Server annimmt.', 'lindenweg16' ) );
		}

		if ( count( $files ) > self::MAX_FILES ) {
			return self::fail( sprintf( /* translators: %d: Höchstzahl. */ __( 'Bitte höchstens %d Fotos auf einmal hochladen.', 'lindenweg16' ), self::MAX_FILES ) );
		}

		foreach ( $files as $file ) {
			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				return self::fail( sprintf( /* translators: %s: Dateiname. */ __( '„%s“ kam nicht vollständig an. Bitte noch einmal versuchen.', 'lindenweg16' ), $file['name'] ) );
			}
			if ( (int) $file['size'] > self::MAX_BYTES ) {
				return self::fail( sprintf( /* translators: %s: Dateiname. */ __( '„%s“ ist größer als 15 MB. Bitte verkleinern.', 'lindenweg16' ), $file['name'] ) );
			}
			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
			if ( empty( $check['type'] ) || ! in_array( $check['type'], self::TYPES, true ) ) {
				return self::fail( sprintf( /* translators: %s: Dateiname. */ __( '„%s“ ist kein JPG-, PNG- oder WebP-Foto.', 'lindenweg16' ), $file['name'] ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$parent = (int) $article['wc_product_id'];
		$added  = 0;
		$errors = array();

		foreach ( $files as $file ) {
			$id = media_handle_sideload( $file, $parent, (string) $article['name'] );

			if ( is_wp_error( $id ) ) {
				$errors[] = $file['name'] . ': ' . $id->get_error_message();
				continue;
			}

			update_post_meta( (int) $id, '_lw16_article_photo', (int) $article['id'] );
			update_post_meta( (int) $id, '_wp_attachment_image_alt', (string) $article['name'] );
			ArticleImages::add( (int) $article['id'], (int) $id );
			$added++;
		}

		ArticleImages::sync_variation_images( (int) $article['id'] );

		$message = sprintf( /* translators: %d: Anzahl. */ _n( '%d Foto hochgeladen.', '%d Fotos hochgeladen.', $added, 'lindenweg16' ), $added );
		if ( $errors ) {
			$message .= ' ' . __( 'Nicht übernommen:', 'lindenweg16' ) . ' ' . implode( '; ', $errors );
		}

		return new WP_REST_Response( self::payload( ArticleRepository::find( (int) $article['id'] ), $message ), $added ? 201 : 422 );
	}

	/**
	 * Einschränkung ändern oder verschieben.
	 *
	 * @param WP_REST_Request $request Anfrage: tags (Liste) und/oder move (hoch|runter).
	 * @return WP_REST_Response
	 */
	public static function update( WP_REST_Request $request ) {
		$article = self::article( $request );
		$photo   = self::photo( $request );

		if ( ! $article || ! $photo ) {
			return self::fail( __( 'Dieses Foto gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$params  = (array) $request->get_json_params();
		$message = '';

		if ( array_key_exists( 'tags', $params ) ) {
			$allowed = array();
			foreach ( ArticleImages::choices( $article ) as $group ) {
				foreach ( $group['values'] as $value ) {
					$allowed[ $value['value'] ] = true;
				}
			}
			$tags = array_values( array_filter( array_map( 'strval', (array) $params['tags'] ), static function ( $tag ) use ( $allowed ) {
				return isset( $allowed[ ArticleImages::format_tags( $tag ) ] );
			} ) );
			ArticleImages::set_tags( (int) $photo['id'], $tags );
			$message = $tags ? __( 'Zuordnung gespeichert.', 'lindenweg16' ) : __( 'Gilt jetzt für alle Varianten.', 'lindenweg16' );
		}

		if ( ! empty( $params['move'] ) ) {
			ArticleImages::move( (int) $photo['id'], 'hoch' === $params['move'] ? 'hoch' : 'runter' );
			$message = __( 'Reihenfolge geändert.', 'lindenweg16' );
		}

		ArticleImages::sync_variation_images( (int) $article['id'] );

		return new WP_REST_Response( self::payload( $article, $message ) );
	}

	/**
	 * Foto entfernen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function remove( WP_REST_Request $request ) {
		$article = self::article( $request );
		$photo   = self::photo( $request );

		if ( ! $article || ! $photo ) {
			return self::fail( __( 'Dieses Foto gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		ArticleImages::remove( (int) $photo['id'] );

		return new WP_REST_Response( self::payload( $article, __( 'Foto entfernt.', 'lindenweg16' ) ) );
	}

	/**
	 * Hochgeladene Dateien als Liste.
	 *
	 * @param mixed $files Eintrag aus $_FILES (einzeln oder mehrfach).
	 * @return array
	 */
	public static function normalize( $files ) {
		$out = array();

		if ( ! is_array( $files ) || ! isset( $files['name'] ) ) {
			return $out;
		}

		$names = (array) $files['name'];

		foreach ( $names as $i => $name ) {
			$error = (int) ( is_array( $files['error'] ) ? ( isset( $files['error'][ $i ] ) ? $files['error'][ $i ] : UPLOAD_ERR_NO_FILE ) : $files['error'] );

			if ( UPLOAD_ERR_NO_FILE === $error || '' === (string) $name ) {
				continue;
			}

			$pick  = static function ( $field ) use ( $files, $i ) {
				return is_array( $files[ $field ] ) ? ( isset( $files[ $field ][ $i ] ) ? $files[ $field ][ $i ] : '' ) : $files[ $field ];
			};
			$out[] = array(
				'name'     => (string) $name,
				'type'     => (string) $pick( 'type' ),
				'tmp_name' => (string) $pick( 'tmp_name' ),
				'error'    => $error,
				'size'     => (int) $pick( 'size' ),
			);
		}

		return $out;
	}
}
