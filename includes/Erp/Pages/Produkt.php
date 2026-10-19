<?php
/**
 * Produktakte: REST-Routen der nativen Reiter.
 *
 * Stammdaten, 3MF und Revisionen, Stückliste, Kalkulation und Beschaffung
 * laufen hier über dieselben Repositories und Prüfungen wie die alte Seite
 * (Modules/Betrieb/Admin/ArticlesPage.php). Die Repositories schreiben auch
 * das Protokoll (BetriebAudit); hier kommt nichts daran vorbei.
 *
 * Rechte: fertigung.artikel zum Ansehen, fertigung.stammdaten.edit zum
 * Ändern, finanzen.kosten für alles mit Geldwerten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\ProductFile;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuFilamentCatalogRepository;
use Lindenweg16\Modules\Betrieb\BinRepository;
use Lindenweg16\Modules\Betrieb\BomRepository;
use Lindenweg16\Modules\Betrieb\BomResolver;
use Lindenweg16\Modules\Betrieb\CalculationContext;
use Lindenweg16\Modules\Betrieb\ComponentRepository;
use Lindenweg16\Modules\Betrieb\CostCalculator;
use Lindenweg16\Modules\Betrieb\ModelFile;
use Lindenweg16\Modules\Betrieb\Nesting\SlicingProfileRepository;
use Lindenweg16\Modules\Betrieb\PriceSync;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\SourcingRepository;
use Lindenweg16\Modules\Betrieb\ThreeMf;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen erp/produkte/<id>/… und erp/produkt-neu.
 */
class Produkt {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api REST-Zugang (Rechteprüfung).
	 */
	public static function register( RestApi $api ) {
		$view  = $api->needs( array( 'fertigung.artikel' ) );
		$edit  = $api->needs( array( 'fertigung.artikel', 'fertigung.stammdaten.edit' ) );
		$money = $api->needs( array( 'fertigung.artikel', 'finanzen.kosten' ) );
		$price = $api->needs( array( 'fertigung.artikel', 'fertigung.stammdaten.edit', 'finanzen.kosten' ) );
		$base  = '/erp/produkte/(?P<id>\d+)';
		$rev   = $base . '/revisionen/(?P<rid>\d+)';

		$route = static function ( $path, $methods, $callback, $permission ) {
			register_rest_route(
				RestApi::NAMESPACE_V1,
				$path,
				array(
					'methods'             => $methods,
					'callback'            => $callback,
					'permission_callback' => $permission,
				)
			);
		};

		// Stammdaten.
		$route( '/erp/produkt-neu', 'GET', array( __CLASS__, 'article_blank' ), $edit );
		$route( '/erp/produkt-neu', 'POST', array( __CLASS__, 'article_create' ), $edit );
		$route( $base . '/stamm', 'GET', array( __CLASS__, 'article_get' ), $edit );
		$route( $base . '/stamm', 'POST', array( __CLASS__, 'article_save' ), $edit );
		$route( $base . '/stamm/status', 'POST', array( __CLASS__, 'article_state' ), $edit );

		// 3MF und Revisionen.
		$route( $base . '/revisionen', 'GET', array( __CLASS__, 'revisions_get' ), $view );
		$route( $base . '/revisionen', 'POST', array( __CLASS__, 'revision_create' ), $edit );
		$route( $rev, 'POST', array( __CLASS__, 'revision_update' ), $edit );
		$route( $rev . '/aktivieren', 'POST', array( __CLASS__, 'revision_activate' ), $edit );
		$route( $rev . '/datei', 'GET', array( __CLASS__, 'model_download' ), $view );
		$route( $rev . '/datei', 'POST', array( __CLASS__, 'model_upload' ), $edit );
		$route( $rev . '/datei/entfernen', 'POST', array( __CLASS__, 'model_remove' ), $edit );

		// Stückliste.
		$route( $base . '/stueckliste', 'GET', array( __CLASS__, 'bom_get' ), $view );
		$route( $base . '/stueckliste', 'POST', array( __CLASS__, 'bom_add' ), $edit );
		$route( $base . '/stueckliste/(?P<bid>\d+)/entfernen', 'POST', array( __CLASS__, 'bom_remove' ), $edit );

		// Kalkulation (nur mit Recht auf Kosten).
		$route( $base . '/kalkulation', 'GET', array( __CLASS__, 'calc_get' ), $money );
		$route( $base . '/kalkulation/preis', 'POST', array( __CLASS__, 'calc_adopt' ), $price );
		$route( '/erp/preise/shop', 'POST', array( __CLASS__, 'prices_push_all' ), $price );

		// Beschaffung.
		$route( $base . '/beschaffung', 'GET', array( __CLASS__, 'sourcing_get' ), $view );
		$route( $base . '/beschaffung', 'POST', array( __CLASS__, 'sourcing_save' ), $edit );
	}

	/* ====================================================================
	 * Hilfen
	 * ================================================================== */

	/**
	 * Antwort mit Fehlertext.
	 *
	 * @param string $message Text, deutsch und verständlich.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Erfolgsantwort.
	 *
	 * @param string $message Text.
	 * @param array  $extra   Weitere Felder.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function done( $message, array $extra = array(), $status = 200 ) {
		return new WP_REST_Response( array_merge( array( 'ok' => true, 'message' => $message ), $extra ), $status );
	}

	/**
	 * Artikel der Adresse.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array|null
	 */
	protected static function article( WP_REST_Request $request ) {
		return ArticleRepository::find( (int) $request['id'] );
	}

	/**
	 * Meldung bei unbekanntem Artikel.
	 *
	 * @return WP_REST_Response
	 */
	protected static function no_article() {
		return self::fail( __( 'Dieses Produkt gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
	}

	/**
	 * Eingaben (JSON oder Formular) auf die erlaubten Felder.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @param string[]        $keys    Felder.
	 * @return array
	 */
	protected static function input( WP_REST_Request $request, array $keys ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		return ProductFile::pick( (array) $params, $keys );
	}

	/**
	 * Begründung der Änderung für das Protokoll.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return string
	 */
	protected static function reason( WP_REST_Request $request ) {
		return sanitize_text_field( (string) $request->get_param( 'reason' ) );
	}

	/**
	 * Hinweis zum Shop-Preis, falls beim Speichern einer übertragen wurde.
	 *
	 * @param string $msg Grundmeldung.
	 * @return string
	 */
	protected static function with_price_note( $msg ) {
		$result = PriceSync::take_last();

		if ( ! $result || 'gleich' === $result['status'] || 'ohne_shop' === $result['status'] ) {
			return $msg;
		}

		return $msg . ' ' . $result['message'];
	}

	/**
	 * Zahl für die Oberfläche (Text, damit Kommastellen nicht verloren gehen).
	 *
	 * @param mixed $value Wert.
	 * @return string
	 */
	protected static function plain( $value ) {
		return null === $value ? '' : (string) $value;
	}

	/* ====================================================================
	 * Stammdaten
	 * ================================================================== */

	/**
	 * Auswahllisten des Stammdatenformulars.
	 *
	 * @return array
	 */
	protected static function article_options() {
		$bins = array();

		foreach ( BinRepository::all( true ) as $bin ) {
			$bins[] = array( 'id' => (int) $bin['id'], 'label' => trim( $bin['code'] . ' ' . (string) $bin['name'] ) );
		}

		$labels = function ( array $map ) {
			$out = array();
			foreach ( $map as $key => $label ) {
				$out[] = array( 'value' => (string) $key, 'label' => (string) $label );
			}
			return $out;
		};

		return array(
			'status'   => $labels( ArticleRepository::status_labels() ),
			'sourcing' => $labels( ArticleRepository::sourcing_labels() ),
			'bins'     => $bins,
			'shop'     => self::shop_products(),
		);
	}

	/**
	 * Shop-Produkte zum Verknüpfen; leer, wenn das Sortiment zu groß ist.
	 *
	 * @return array
	 */
	protected static function shop_products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$limit    = 400;
		$products = wc_get_products(
			array(
				'status'  => array( 'publish', 'private', 'draft' ),
				'limit'   => $limit + 1,
				'orderby' => 'title',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		if ( count( (array) $products ) > $limit ) {
			return array();
		}

		$rows = array();

		foreach ( (array) $products as $product ) {
			$rows[] = array( 'id' => (int) $product->get_id(), 'label' => trim( $product->get_name() . ' ' . ( $product->get_sku() ? '(' . $product->get_sku() . ')' : '' ) ) );
		}

		return $rows;
	}

	/**
	 * Artikel für das Formular.
	 *
	 * @param array $a Datensatz.
	 * @return array
	 */
	protected static function article_form( array $a ) {
		return array(
			'id'                 => (int) $a['id'],
			'sku'                => (string) $a['sku'],
			'name'               => (string) $a['name'],
			'status'             => (string) $a['status'],
			'sourcing_type'      => (string) $a['sourcing_type'],
			'sellable'           => (bool) $a['sellable'],
			'discountable'       => (bool) $a['discountable'],
			'default_bin_id'     => (int) $a['default_bin_id'],
			'wc_product_id'      => (int) $a['wc_product_id'],
			'price_gross'        => self::plain( $a['price_gross'] ),
			'dealer_price'       => self::plain( $a['dealer_price'] ),
			'weight_g'           => self::plain( $a['weight_g'] ),
			'postprocessing_min' => self::plain( $a['postprocessing_min'] ),
			'load_rating_kg'     => self::plain( $a['load_rating_kg'] ),
			'barcode'            => self::plain( $a['barcode'] ),
			'notes'              => self::plain( $a['notes'] ),
		);
	}

	/**
	 * Leeres Formular für ein neues Produkt.
	 *
	 * @return WP_REST_Response
	 */
	public static function article_blank() {
		return new WP_REST_Response(
			array(
				'article'  => array(
					'id'                 => 0,
					'sku'                => '',
					'name'               => '',
					'status'             => 'entwicklung',
					'sourcing_type'      => '',
					'sellable'           => true,
					'discountable'       => true,
					'default_bin_id'     => 0,
					'wc_product_id'      => 0,
					'price_gross'        => '',
					'dealer_price'       => '',
					'weight_g'           => '',
					'postprocessing_min' => '',
					'load_rating_kg'     => '',
					'barcode'            => '',
					'notes'              => '',
				),
				'options'  => self::article_options(),
				'blockers' => array(),
				'active'   => true,
			)
		);
	}

	/**
	 * Stammdaten eines Artikels.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function article_get( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		return new WP_REST_Response(
			array(
				'article'  => self::article_form( $article ),
				'options'  => self::article_options(),
				'blockers' => ArticleRepository::delete_blockers( (int) $article['id'] ),
				'active'   => ArticleRepository::is_active( $article ),
				'money'    => Access::can( 'finanzen.kosten' ),
			)
		);
	}

	/**
	 * Neues Produkt anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function article_create( WP_REST_Request $request ) {
		$input  = self::input( $request, ProductFile::ARTICLE_FIELDS );
		$result = ArticleRepository::validate( $input, null );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		$new = ArticleRepository::insert( $result['data'] );

		if ( ! $new ) {
			return self::fail( __( 'Das Produkt konnte nicht angelegt werden. Bitte die Eingaben prüfen und noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done( self::with_price_note( __( 'Produkt angelegt.', 'lindenweg16' ) ), array( 'id' => $new, 'sourcing_type' => $result['data']['sourcing_type'] ), 201 );
	}

	/**
	 * Stammdaten speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function article_save( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$id    = (int) $article['id'];
		$input = ProductFile::overlay( $article, self::input( $request, ProductFile::ARTICLE_FIELDS ), ProductFile::ARTICLE_FIELDS );

		// Die Prüfung liest „leer“ als „nicht gesetzt“; gespeicherte Werte kommen als Zahlen.
		$result = ArticleRepository::validate( $input, $id );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		if ( ! ArticleRepository::update( $id, $result['data'], self::reason( $request ) ) ) {
			return self::fail( __( 'Die Änderung konnte nicht gespeichert werden. Bitte die Seite neu laden und noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done( self::with_price_note( __( 'Artikel gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Stilllegen, wieder aufnehmen oder löschen.
	 *
	 * @param WP_REST_Request $request Anfrage; was = stilllegen, aufnehmen, loeschen.
	 * @return WP_REST_Response
	 */
	public static function article_state( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$id  = (int) $article['id'];
		$was = sanitize_key( (string) $request->get_param( 'was' ) );

		if ( 'loeschen' === $was ) {
			$result = ArticleRepository::delete( $id );

			return $result['success'] ? self::done( $result['message'], array( 'deleted' => true ) ) : self::fail( $result['message'], 409 );
		}

		if ( ! in_array( $was, array( 'stilllegen', 'aufnehmen' ), true ) ) {
			return self::fail( __( 'Diese Aktion kenne ich nicht. Erlaubt sind Stilllegen, Wieder aufnehmen und Löschen.', 'lindenweg16' ) );
		}

		// Stilllegen heißt ausgelaufen, nicht gesperrt: Gesperrt ist ein
		// Qualitätsurteil, ausgelaufen eine Sortimentsentscheidung.
		$neu = 'stilllegen' === $was ? 'ausgelaufen' : 'aktiv';

		if ( ! ArticleRepository::set_status( $id, $neu ) ) {
			return self::fail( __( 'Der Status konnte nicht geändert werden.', 'lindenweg16' ), 500 );
		}

		return self::done(
			sprintf(
				/* translators: 1: Artikelnummer, 2: neuer Status. */
				__( '%1$s ist jetzt %2$s.', 'lindenweg16' ),
				$article['sku'],
				ArticleRepository::status_labels()[ $neu ]
			)
		);
	}

	/* ====================================================================
	 * 3MF und Revisionen
	 * ================================================================== */

	/**
	 * Farbauswahl: nur Farben mit hinterlegtem Slicing-Profil.
	 *
	 * @return array Liste aus value und label.
	 */
	protected static function color_options() {
		$options = array();

		foreach ( SlicingProfileRepository::existing_material_colors() as $combo ) {
			if ( '' === $combo->color ) {
				$options[] = array( 'value' => '', 'label' => __( '(farblos – das eine Profil dieses Materials)', 'lindenweg16' ) );
				continue;
			}

			$filament  = BambuFilamentCatalogRepository::find( $combo->color );
			$options[] = array(
				'value' => (string) $combo->color,
				'label' => sprintf( '%s – %s (%s)', $combo->material, $filament && $filament->label ? $filament->label : $combo->color, $combo->color ),
			);
		}

		return $options;
	}

	/**
	 * Anzeigename einer Revisionsfarbe.
	 *
	 * @param string $color Gespeicherte Filament-ID.
	 * @return string
	 */
	protected static function color_label( $color ) {
		if ( '' === (string) $color ) {
			return __( '(farblos)', 'lindenweg16' );
		}

		$filament = BambuFilamentCatalogRepository::find( $color );

		return $filament ? sprintf( '%s (%s)', $filament->label, $color ) : (string) $color;
	}

	/**
	 * Revision für die Oberfläche.
	 *
	 * @param array $r Datensatz.
	 * @return array
	 */
	protected static function revision_row( array $r ) {
		$has = ! empty( $r['model_file'] );

		return array(
			'id'               => (int) $r['id'],
			'version'          => (string) $r['version'],
			'active'           => (bool) $r['is_active'],
			'material'         => (string) $r['material'],
			'color'            => (string) $r['color'],
			'color_label'      => self::color_label( (string) $r['color'] ),
			'weight_g'         => self::plain( $r['weight_g'] ),
			'parts_per_plate'  => self::plain( $r['parts_per_plate'] ),
			'print_time_plate_h' => self::plain( $r['print_time_plate_h'] ),
			'per_part_h'       => round( CostCalculator::print_time_per_part( $r ), 3 ),
			'nozzle_mm'        => self::plain( $r['nozzle_mm'] ),
			'layer_mm'         => self::plain( $r['layer_mm'] ),
			'perimeters'       => self::plain( $r['perimeters'] ),
			'infill_pct'       => self::plain( $r['infill_pct'] ),
			'infill_pattern'   => self::plain( $r['infill_pattern'] ),
			'nozzle_temp'      => self::plain( $r['nozzle_temp'] ),
			'bed_temp'         => self::plain( $r['bed_temp'] ),
			'cooling_pct'      => self::plain( $r['cooling_pct'] ),
			'supports'         => (bool) $r['supports'],
			'orientation_note' => self::plain( $r['orientation_note'] ),
			'model'            => $has ? array(
				'name'       => (string) $r['model_name'],
				'kind'       => (string) $r['model_kind'],
				'kind_label' => ModelFile::kind_label( (string) $r['model_kind'] ),
				'bytes'      => (int) $r['model_bytes'],
				'size'       => ProductFile::bytes_text( (int) $r['model_bytes'] ),
				'at'         => ! empty( $r['model_uploaded_at'] ) ? (int) strtotime( $r['model_uploaded_at'] . ' UTC' ) : 0,
			) : null,
		);
	}

	/**
	 * Revisionen eines Artikels.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function revisions_get( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$printed = ! in_array( $article['sourcing_type'], array( 'zukauf', 'montage' ), true );
		$rows    = array();

		if ( $printed ) {
			foreach ( RevisionRepository::for_article( (int) $article['id'] ) as $row ) {
				$rows[] = self::revision_row( $row );
			}
		}

		return new WP_REST_Response(
			array(
				'article'    => array( 'id' => (int) $article['id'], 'sku' => (string) $article['sku'], 'name' => (string) $article['name'], 'sourcing_type' => (string) $article['sourcing_type'] ),
				'printed'    => $printed,
				'revisions'  => $rows,
				'materials'  => RevisionRepository::MATERIALS,
				'colors'     => $printed ? self::color_options() : array(),
				'max_bytes'  => min( ModelFile::MAX_BYTES, (int) wp_max_upload_size() ),
				'limit_note' => (int) wp_max_upload_size() < ModelFile::MAX_BYTES,
			)
		);
	}

	/**
	 * Revision der Adresse, nur wenn sie zum Artikel gehört.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array|null
	 */
	protected static function revision( WP_REST_Request $request ) {
		$row = RevisionRepository::find( (int) $request['rid'] );

		return ProductFile::belongs_to( $row, 'article_id', (int) $request['id'] ) ? $row : null;
	}

	/**
	 * Meldung bei unbekannter Revision.
	 *
	 * @return WP_REST_Response
	 */
	protected static function no_revision() {
		return self::fail( __( 'Diese Revision gibt es nicht (mehr) an diesem Produkt. Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
	}

	/**
	 * Revision anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function revision_create( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$input               = self::input( $request, ProductFile::REVISION_FIELDS );
		$input['article_id'] = (int) $article['id'];
		$result              = RevisionRepository::validate( $input, null );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		$new = RevisionRepository::insert( $result['data'] );

		if ( ! $new ) {
			return self::fail( __( 'Die Revision konnte nicht angelegt werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done( __( 'Revision angelegt. Sie ist noch nicht aktiv – setze sie in der Liste aktiv.', 'lindenweg16' ), array( 'revision' => $new ), 201 );
	}

	/**
	 * Revision ändern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function revision_update( WP_REST_Request $request ) {
		$revision = self::revision( $request );

		if ( ! $revision ) {
			return self::no_revision();
		}

		$id                  = (int) $revision['id'];
		$input               = ProductFile::overlay( $revision, self::input( $request, ProductFile::REVISION_FIELDS ), array_merge( ProductFile::REVISION_FIELDS, array( 'orientation_image_id' ) ) );
		$input['article_id'] = (int) $revision['article_id'];
		$result              = RevisionRepository::validate( $input, $id );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		if ( ! RevisionRepository::update( $id, $result['data'], self::reason( $request ) ) ) {
			return self::fail( __( 'Die Revision konnte nicht gespeichert werden. Bitte die Seite neu laden und noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done( __( 'Revision gespeichert.', 'lindenweg16' ) );
	}

	/**
	 * Revision aktiv setzen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function revision_activate( WP_REST_Request $request ) {
		$revision = self::revision( $request );

		if ( ! $revision ) {
			return self::no_revision();
		}

		$result = RevisionRepository::activate( (int) $revision['id'] );

		if ( ! $result['success'] ) {
			return self::fail( implode( ' ', $result['reasons'] ) );
		}

		$msg = __( 'Revision aktiv gesetzt.', 'lindenweg16' );

		if ( empty( $revision['model_file'] ) || 'modell' !== $revision['model_kind'] ) {
			$msg .= ' ' . __( 'Achtung: Für diese Revision ist noch keine Projekt-3MF hinterlegt – ohne sie lässt sich das Produkt nicht in einen Plan aufnehmen.', 'lindenweg16' );
		}

		return self::done( $msg );
	}

	/**
	 * Zahl der Objekte in einer abgelegten Projektdatei.
	 *
	 * @param string $path Absoluter Pfad.
	 * @return int
	 */
	protected static function object_count( $path ) {
		$xml = ModelFile::read_model( $path );
		$doc = '' === $xml ? null : ThreeMf::document( $xml );

		return $doc ? count( ThreeMf::items( $doc ) ) : 0;
	}

	/**
	 * Projekt-3MF hochladen (multipart, Feld „model“).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function model_upload( WP_REST_Request $request ) {
		$revision = self::revision( $request );

		if ( ! $revision ) {
			return self::no_revision();
		}

		$files = $request->get_file_params();
		$file  = isset( $files['model'] ) ? $files['model'] : null;
		$max   = min( ModelFile::MAX_BYTES, (int) wp_max_upload_size() );

		// Übersteigt die Anfrage post_max_size, kommt gar kein Feld an.
		if ( ! $file || ( empty( $file['name'] ) && empty( $file['error'] ) ) ) {
			return self::fail( __( 'Es kam keine Datei an. Entweder wurde keine gewählt, oder sie ist größer, als der Server annimmt. Bitte eine .3mf-Datei wählen.', 'lindenweg16' ) );
		}

		$error = ProductFile::upload_error( isset( $file['error'] ) ? (int) $file['error'] : 0, $max );

		if ( '' !== $error ) {
			return self::fail( $error );
		}

		$id     = (int) $revision['id'];
		$stored = ModelFile::store( $file );

		if ( ! $stored['success'] ) {
			return self::fail( $stored['message'] );
		}

		$old = RevisionRepository::clear_model( $id );
		if ( '' !== $old ) {
			ModelFile::delete( $old );
		}

		$original = sanitize_file_name( (string) $file['name'] );

		if ( ! RevisionRepository::set_model( $id, $stored['name'], $original, $stored['sha1'], $stored['size'], $stored['kind'] ) ) {
			ModelFile::delete( $stored['name'] );

			return self::fail( __( 'Die Datei wurde geprüft, ließ sich aber nicht an der Revision speichern. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		$path    = ModelFile::path( $stored['name'] );
		$message = ProductFile::upload_message( $original, $stored['kind'], 'modell' === $stored['kind'] ? self::object_count( $path ) : 0 );

		if ( 'druckdatei' === $stored['kind'] ) {
			$message .= self::adopt_slice_values( $id, $path );
		}

		return self::done( $message, array( 'kind' => $stored['kind'] ) );
	}

	/**
	 * Aus einer geslicten Druckdatei die Zahlen des Slicers übernehmen.
	 *
	 * @param int    $id   Revision.
	 * @param string $path Absoluter Pfad.
	 * @return string Hinweistext, mit führendem Leerzeichen.
	 */
	protected static function adopt_slice_values( $id, $path ) {
		$info = ModelFile::slice_info( $path );

		if ( ! $info ) {
			return ' ' . __( 'Die Datei enthält keine lesbaren Slicer-Angaben.', 'lindenweg16' );
		}

		$values   = ThreeMf::revision_values( $info );
		$revision = RevisionRepository::find( $id );
		$data     = $revision ? ProductFile::fill_empty( $revision, $values ) : array();

		if ( $data ) {
			RevisionRepository::update( $id, $data, __( 'Aus der geslicten Druckdatei übernommen.', 'lindenweg16' ) );
		}

		return ' ' . sprintf(
			/* translators: 1: Druckzeit, 2: Gewicht, 3: Teile, 4: übernommen oder nicht. */
			__( 'Erkannt: %1$s h Druckzeit, %2$s g Material, %3$d Teile auf der Platte. %4$s', 'lindenweg16' ),
			null === $values['print_time_plate_h'] ? '?' : number_format_i18n( $values['print_time_plate_h'], 3 ),
			null === $info['weight_g'] ? '?' : number_format_i18n( $info['weight_g'], 2 ),
			(int) $values['parts_per_plate'],
			$data
				? __( 'Die leeren Felder der Revision wurden damit gefüllt.', 'lindenweg16' )
				: __( 'Die Revision hat bereits eigene Werte – die bleiben stehen.', 'lindenweg16' )
		);
	}

	/**
	 * Hinterlegte Datei entfernen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function model_remove( WP_REST_Request $request ) {
		$revision = self::revision( $request );

		if ( ! $revision ) {
			return self::no_revision();
		}

		$old = RevisionRepository::clear_model( (int) $revision['id'] );

		if ( '' === $old ) {
			return self::fail( __( 'Es war keine Datei hinterlegt.', 'lindenweg16' ), 409 );
		}

		ModelFile::delete( $old );

		return self::done( __( 'Datei entfernt.', 'lindenweg16' ) );
	}

	/**
	 * Hinterlegte 3MF ausliefern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|void
	 */
	public static function model_download( WP_REST_Request $request ) {
		$revision = self::revision( $request );

		if ( ! $revision ) {
			return self::no_revision();
		}

		$path = ModelFile::safe_path( (string) $revision['model_file'] );

		if ( '' === $path ) {
			return self::fail( __( 'Zu dieser Revision ist keine Datei hinterlegt.', 'lindenweg16' ), 404 );
		}

		$article = ArticleRepository::find( (int) $revision['article_id'] );
		$name    = ProductFile::download_name( $article ? $article['sku'] : 'artikel', $revision['version'] );

		nocache_headers();
		header( 'Content-Type: model/3mf' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ====================================================================
	 * Stückliste
	 * ================================================================== */

	/**
	 * Stückliste eines Artikels.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function bom_get( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$id         = (int) $article['id'];
		$articles   = ArticleRepository::all_by_id();
		$components = ComponentRepository::all_by_id();
		$rows       = array();

		foreach ( BomRepository::for_article( $id ) as $row ) {
			$child = (int) $row['child_id'];

			if ( 'artikel' === $row['child_type'] ) {
				$label = isset( $articles[ $child ] ) ? $articles[ $child ]['sku'] . ' – ' . $articles[ $child ]['name'] : __( '(gelöscht)', 'lindenweg16' );
				$type  = __( 'Unterbaugruppe', 'lindenweg16' );
			} else {
				$label = isset( $components[ $child ] ) ? $components[ $child ]['code'] . ' – ' . $components[ $child ]['name'] : __( '(gelöscht)', 'lindenweg16' );
				$type  = __( 'Komponente', 'lindenweg16' );
			}

			$rows[] = array(
				'id'       => (int) $row['id'],
				'type'     => (string) $row['child_type'],
				'type_txt' => $type,
				'label'    => $label,
				'qty'      => (float) $row['qty'],
				'position' => (int) $row['position'],
				'note'     => (string) $row['note'],
			);
		}

		$options = array();

		foreach ( $components as $component ) {
			$options[] = array( 'value' => 'komponente:' . (int) $component['id'], 'label' => sprintf( /* translators: 1: Code, 2: Bezeichnung. */ __( 'Komponente: %1$s – %2$s', 'lindenweg16' ), $component['code'], $component['name'] ) );
		}

		foreach ( $articles as $candidate ) {
			if ( (int) $candidate['id'] === $id ) {
				continue;
			}

			$options[] = array( 'value' => 'artikel:' . (int) $candidate['id'], 'label' => sprintf( /* translators: 1: Artikelnummer, 2: Bezeichnung. */ __( 'Artikel: %1$s – %2$s', 'lindenweg16' ), $candidate['sku'], $candidate['name'] ) );
		}

		// Zu tiefe oder zyklische Stücklisten zeigen, statt sie still abzuschneiden.
		$resolved = BomResolver::resolve( $id, array( 'bom' => BomRepository::all_by_parent() ) );
		$problems = array();

		foreach ( $resolved['errors'] as $error ) {
			$problems[] = (string) $error['message'];
		}

		return new WP_REST_Response(
			array(
				'article'  => array( 'id' => $id, 'sku' => (string) $article['sku'], 'name' => (string) $article['name'] ),
				'rows'     => $rows,
				'options'  => $options,
				'problems' => $problems,
				'next'     => count( $rows ) + 1,
				'depth'    => BomResolver::MAX_DEPTH,
			)
		);
	}

	/**
	 * Position hinzufügen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function bom_add( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$input                      = self::input( $request, ProductFile::BOM_FIELDS );
		$input['parent_article_id'] = (int) $article['id'];

		if ( isset( $input['child'] ) ) {
			$input = array_merge( $input, ProductFile::split_child( $input['child'] ) );
		}

		$result = BomRepository::validate( $input, null );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		$data = $result['data'];

		if ( ProductFile::adds_depth_error( $data['parent_article_id'], $data['child_type'], $data['child_id'], BomRepository::all_by_parent(), BomResolver::MAX_DEPTH ) ) {
			return self::fail(
				sprintf(
					/* translators: %d: erlaubte Ebenen. */
					__( 'Diese Position würde die Stückliste tiefer machen als %d Ebenen – eine so verschachtelte Baugruppe lässt sich nicht kalkulieren. Bitte die Baugruppe flacher aufbauen.', 'lindenweg16' ),
					BomResolver::MAX_DEPTH
				)
			);
		}

		if ( ! BomRepository::insert( $data ) ) {
			return self::fail( __( 'Die Position konnte nicht gespeichert werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done( __( 'Position hinzugefügt.', 'lindenweg16' ), array(), 201 );
	}

	/**
	 * Position entfernen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function bom_remove( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$row = BomRepository::find( (int) $request['bid'] );

		if ( ! ProductFile::belongs_to( $row, 'parent_article_id', (int) $article['id'] ) ) {
			return self::fail( __( 'Diese Position gibt es nicht (mehr) in dieser Stückliste. Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		if ( ! BomRepository::delete( (int) $row['id'], self::reason( $request ) ) ) {
			return self::fail( __( 'Die Position konnte nicht entfernt werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done( __( 'Position entfernt.', 'lindenweg16' ) );
	}

	/* ====================================================================
	 * Kalkulation
	 * ================================================================== */

	/**
	 * Was steht gerade im Shop?
	 *
	 * @param array $article Artikel.
	 * @return string
	 */
	protected static function shop_price_note( array $article ) {
		$wc_id = (int) $article['wc_product_id'];

		if ( ! $wc_id ) {
			return __( 'kein Shop-Produkt verknüpft', 'lindenweg16' );
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $wc_id ) : null;

		if ( ! $product ) {
			return __( 'verknüpftes Shop-Produkt gibt es nicht mehr', 'lindenweg16' );
		}

		$price = $product->get_regular_price();

		if ( '' === $price || null === $price ) {
			return __( 'noch kein Normalpreis im Shop', 'lindenweg16' );
		}

		return number_format_i18n( (float) $price, 2 ) . ' €';
	}

	/**
	 * Kalkulation über alle Preisebenen.
	 *
	 * @param WP_REST_Request $request Anfrage; stichtag, marge.
	 * @return WP_REST_Response
	 */
	public static function calc_get( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$id       = (int) $article['id'];
		$stichtag = ProductFile::stichtag( $request->get_param( 'stichtag' ), gmdate( 'Y-m-d' ) );
		$marge    = ProductFile::margin_percent( $request->get_param( 'marge' ) );
		$result   = CalculationContext::for_article( $id, $stichtag );
		$uvp      = $result['levels']['uvp'];
		$settings = $result['settings'];
		$hk       = (float) $uvp['herstellkosten'];

		$parts = array();

		foreach ( array( array( __( 'Eigenmaterial', 'lindenweg16' ), __( 'Material', 'lindenweg16' ), (float) $uvp['eigenmaterial'] ), array( __( 'Maschinenzeit', 'lindenweg16' ), __( 'Maschine', 'lindenweg16' ), (float) $uvp['eigenmaschine'] ) ) as $own ) {
			if ( $own[2] > 0 ) {
				$parts[] = array( 'label' => $own[0], 'kind' => $own[1], 'depth' => 0, 'qty' => null, 'unit' => null, 'cost' => round( $own[2], 4 ), 'share' => ProductFile::share( $own[2], $hk ) );
			}
		}

		foreach ( $uvp['positionen'] as $pos ) {
			$parts[] = array(
				'label' => $pos['code'] ? $pos['code'] . ' – ' . $pos['label'] : (string) $pos['label'],
				'kind'  => 'komponente' === $pos['type'] ? __( 'Komponente', 'lindenweg16' ) : __( 'Unterbaugruppe', 'lindenweg16' ),
				'depth' => (int) $pos['depth'],
				'qty'   => (float) $pos['qty'],
				'unit'  => round( (float) $pos['unit_price'], 4 ),
				'cost'  => round( (float) $pos['cost'], 4 ),
				'share' => ProductFile::share( $pos['cost'], $hk ),
			);
		}

		$akquise = (float) $settings['customer_acquisition_cost'];
		$direct  = (float) $uvp['db2'] - $akquise;
		$levels  = array();

		foreach ( $result['levels'] as $key => $level ) {
			$label = 'uvp' === $key ? __( 'UVP', 'lindenweg16' ) : ( 'haendler' === $key ? __( 'Händlerpreis', 'lindenweg16' ) : (string) $level['price_level'] );

			$levels[] = array(
				'key'      => (string) $key,
				'label'    => $label,
				'gross'    => round( (float) $level['price_gross'], 2 ),
				'net'      => round( (float) $level['netto_erloes'], 2 ),
				'cost'     => round( (float) $level['herstellkosten'], 2 ),
				'scrap'    => round( (float) $level['ausschuss'], 2 ),
				'pack'     => round( (float) $level['verpackung'], 2 ),
				'payment'  => round( (float) $level['zahlung'], 2 ),
				'db1'      => round( (float) $level['db1'], 2 ),
				'db1_pct'  => round( (float) $level['db1_pct'], 4 ),
				'handling' => round( (float) $level['handling'], 2 ),
				'db2'      => round( (float) $level['db2'], 2 ),
				'db2_pct'  => round( (float) $level['db2_pct'], 4 ),
				'vs_direct' => round( (float) $level['db2'] - $direct, 2 ),
			);
		}

		$errors = array();

		foreach ( $uvp['errors'] as $error ) {
			$errors[] = (string) $error['message'];
		}

		// Dieselben Herstellkosten wie die Tabelle: der Vorschlag rechnet gegen die volle Kostenaufstellung.
		$cost      = CostCalculator::manufacturing_cost( $id, CalculationContext::build( $stichtag ), $settings );
		$suggested = CostCalculator::price_for_margin( $cost, $settings, $marge / 100 );

		return new WP_REST_Response(
			array(
				'article'      => array( 'id' => $id, 'sku' => (string) $article['sku'], 'name' => (string) $article['name'] ),
				'stichtag'     => $stichtag,
				'machine_rate' => round( (float) $settings['machine_rate_per_h'], 4 ),
				'acquisition'  => round( $akquise, 2 ),
				'cost'         => round( $hk, 4 ),
				'breakdown'    => array(
					'material'  => round( (float) $uvp['eigenmaterial'], 4 ),
					'machine'   => round( (float) $uvp['eigenmaschine'], 4 ),
					'component' => round( (float) $uvp['komponenten'], 4 ),
					'assembly'  => round( (float) $uvp['unterbaugruppen'], 4 ),
				),
				'parts'        => $parts,
				'levels'       => $levels,
				'errors'       => $errors,
				'margin'       => $marge,
				'suggested'    => null === $suggested ? null : round( (float) $suggested, 2 ),
				'price'        => null === $article['price_gross'] ? null : (float) $article['price_gross'],
				'shop'         => self::shop_price_note( $article ),
				'can_adopt'    => Access::can( 'fertigung.stammdaten.edit' ),
			)
		);
	}

	/**
	 * Vorschlagspreis zur gewünschten Marge als Artikelpreis übernehmen.
	 *
	 * Der Preis wird hier neu gerechnet, nicht aus der Anfrage übernommen.
	 *
	 * @param WP_REST_Request $request Anfrage; marge, stichtag.
	 * @return WP_REST_Response
	 */
	public static function calc_adopt( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$stichtag = ProductFile::stichtag( $request->get_param( 'stichtag' ), gmdate( 'Y-m-d' ) );
		$marge    = ProductFile::margin_percent( $request->get_param( 'marge' ) );
		$settings = CalculationContext::settings( $stichtag );
		$cost     = CostCalculator::manufacturing_cost( (int) $article['id'], CalculationContext::build( $stichtag ), $settings );
		$price    = ProductFile::adoptable_price( CostCalculator::price_for_margin( $cost, $settings, $marge / 100 ) );

		if ( null === $price ) {
			return self::fail( __( 'Bei dieser Marge gibt es keinen übernehmbaren Preis. Bitte eine niedrigere Zielmarge wählen oder die Kosten prüfen.', 'lindenweg16' ) );
		}

		ArticleRepository::update( (int) $article['id'], array( 'price_gross' => $price ), __( 'Preis aus der Kalkulation übernommen.', 'lindenweg16' ) );

		return self::done(
			self::with_price_note(
				sprintf(
					/* translators: %s: Preis. */
					__( 'Neuer Preis: %s €.', 'lindenweg16' ),
					number_format_i18n( $price, 2 )
				)
			),
			array( 'price' => $price )
		);
	}

	/**
	 * Alle verkäuflichen Artikel in den Shop übertragen.
	 *
	 * @return WP_REST_Response
	 */
	public static function prices_push_all() {
		$count = PriceSync::push_all();

		return self::done(
			sprintf(
				/* translators: 1: übertragen, 2: unverändert, 3: ohne Shop-Produkt. */
				__( '%1$d Preise übertragen, %2$d waren schon gleich, %3$d Artikel haben kein Shop-Produkt.', 'lindenweg16' ),
				isset( $count['uebertragen'] ) ? $count['uebertragen'] : 0,
				isset( $count['gleich'] ) ? $count['gleich'] : 0,
				isset( $count['ohne_shop'] ) ? $count['ohne_shop'] : 0
			)
		);
	}

	/* ====================================================================
	 * Beschaffung
	 * ================================================================== */

	/**
	 * Beschaffungsdaten eines Zukauf- oder Hybridartikels.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function sourcing_get( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$money   = Access::can( 'finanzen.kosten' );
		$applies = in_array( $article['sourcing_type'], array( 'zukauf', 'hybrid' ), true );
		$history = array();

		if ( $applies ) {
			foreach ( SourcingRepository::history( (int) $article['id'] ) as $row ) {
				$history[] = array(
					'valid_from' => (string) $row['valid_from'],
					'supplier'   => (string) $row['supplier_name'],
					'contact'    => (string) $row['supplier_contact'],
					'price'      => $money ? (float) $row['purchase_price_net'] : null,
					'min_qty'    => (float) $row['min_order_qty'],
					'lead_days'  => (int) $row['lead_time_days'],
					'own_min'    => (int) $row['own_processing_min'],
					'note'       => (string) $row['unit_note'],
				);
			}
		}

		return new WP_REST_Response(
			array(
				'article' => array( 'id' => (int) $article['id'], 'sku' => (string) $article['sku'], 'name' => (string) $article['name'], 'sourcing_type' => (string) $article['sourcing_type'] ),
				'applies' => $applies,
				'history' => $history,
				'money'   => $money,
				'today'   => gmdate( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Neue Konditionen festschreiben.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function sourcing_save( WP_REST_Request $request ) {
		$article = self::article( $request );

		if ( ! $article ) {
			return self::no_article();
		}

		$input               = self::input( $request, ProductFile::SOURCING_FIELDS );
		$input['article_id'] = (int) $article['id'];
		$result              = SourcingRepository::validate( $input );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		if ( ! SourcingRepository::insert( $result['data'] ) ) {
			return self::fail( __( 'Die Konditionen konnten nicht gespeichert werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done( __( 'Beschaffungsdaten festgeschrieben.', 'lindenweg16' ), array(), 201 );
	}
}
