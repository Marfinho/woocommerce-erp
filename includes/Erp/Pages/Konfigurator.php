<?php
/**
 * Konfigurator: Teile und Sperrbereiche in der ERP-Oberfläche.
 *
 * Läuft über dieselben Repositories und Prüfungen wie die alte Seite
 * (Modules/Configurator/Admin/ConfiguratorPage.php): PartRepository::validate,
 * BlockRepository::create, WebModel::store. Die Repositories schreiben auch
 * das Protokoll (BetriebAudit).
 *
 * Rechte: fertigung.konfigurator zum Ansehen, zusätzlich
 * fertigung.stammdaten.edit zum Ändern (wie ActionMap der alten Aktionen).
 * Nicht nativ: die Vorschau im Shop (die alte Seite hat keine; der
 * Konfigurator selbst läuft über den Shortcode auf einer Shopseite).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\ProductFile;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Configurator\BlockRepository;
use Lindenweg16\Modules\Configurator\Catalog;
use Lindenweg16\Modules\Configurator\Frontend;
use Lindenweg16\Modules\Configurator\Layout;
use Lindenweg16\Modules\Configurator\PartRepository;
use Lindenweg16\Modules\Configurator\GlbMeasure;
use Lindenweg16\Modules\Configurator\WebModel;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen erp/konfigurator/….
 */
class Konfigurator {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api REST-Zugang (Rechteprüfung).
	 */
	public static function register( RestApi $api ) {
		$view = $api->needs( array( 'fertigung.konfigurator' ) );
		$edit = $api->needs( array( 'fertigung.konfigurator', 'fertigung.stammdaten.edit' ) );
		$part = '/erp/konfigurator/teile/(?P<id>\d+)';

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

		$route( '/erp/konfigurator', 'GET', array( __CLASS__, 'overview' ), $view );
		$route( '/erp/konfigurator/teile', 'POST', array( __CLASS__, 'part_save' ), $edit );
		$route( $part, 'POST', array( __CLASS__, 'part_save' ), $edit );
		$route( $part . '/aktiv', 'POST', array( __CLASS__, 'part_active' ), $edit );
		$route( $part . '/loeschen', 'POST', array( __CLASS__, 'part_delete' ), $edit );
		$route( $part . '/sperrbereiche', 'POST', array( __CLASS__, 'block_add' ), $edit );
		$route( $part . '/sperrbereiche/(?P<bid>\d+)/loeschen', 'POST', array( __CLASS__, 'block_delete' ), $edit );
	}

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank, getestet)
	 * ================================================================== */

	/**
	 * Was mit dem Schaumodell geschehen soll.
	 *
	 * Wie die alte Seite: eine hochgeladene Datei sticht die Auswahl, „keines“
	 * entfernt das Modell, ein Demo-Modell wird gesetzt, sonst bleibt alles.
	 *
	 * @param string $choice     Wert der Auswahl („keines“, „demo:…“ oder leer).
	 * @param bool   $has_upload Kommt eine Datei mit?
	 * @return array {action: upload|none|demo|keep, value: string}
	 */
	public static function model_plan( $choice, $has_upload ) {
		$choice = (string) $choice;

		if ( $has_upload ) {
			return array( 'action' => 'upload', 'value' => '' );
		}

		if ( 'keines' === $choice ) {
			return array( 'action' => 'none', 'value' => '' );
		}

		if ( '' !== WebModel::demo_key( $choice ) ) {
			return array( 'action' => 'demo', 'value' => $choice );
		}

		return array( 'action' => 'keep', 'value' => '' );
	}

	/**
	 * Art des hinterlegten Modells.
	 *
	 * @param string $file Wert aus model_file.
	 * @return string none, demo oder upload.
	 */
	public static function model_kind( $file ) {
		$file = (string) $file;

		if ( '' === $file ) {
			return 'none';
		}

		return '' !== WebModel::demo_key( $file ) ? 'demo' : 'upload';
	}

	/**
	 * Haken-Wert aus Formular oder JSON.
	 *
	 * @param mixed $value Eingabe.
	 * @return int 0 oder 1.
	 */
	public static function flag( $value ) {
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'on', 'ja' ), true ) ? 1 : 0;
		}

		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Teile nach Suchtext, Rolle und Zustand filtern.
	 *
	 * @param array  $rows  Teile (name, sku, teaser, role, is_active, article_active).
	 * @param string $query Suchtext (Name, Artikelnummer, Kurzbeschreibung).
	 * @param string $role  basis, modul oder leer.
	 * @param string $state aktiv, inaktiv, ohne-modell oder leer.
	 * @return array
	 */
	public static function filter_parts( array $rows, $query, $role, $state ) {
		$query = self::lower( trim( (string) $query ) );
		$out   = array();

		foreach ( $rows as $row ) {
			if ( in_array( $role, array( 'basis', 'modul' ), true ) && (string) $row['role'] !== $role ) {
				continue;
			}

			$live = ! empty( $row['is_active'] ) && ! empty( $row['article_active'] );

			if ( 'aktiv' === $state && ! $live ) {
				continue;
			}

			if ( 'inaktiv' === $state && $live ) {
				continue;
			}

			if ( 'ohne-modell' === $state && 'none' !== (string) $row['model_kind'] ) {
				continue;
			}

			if ( '' !== $query ) {
				$hay = self::lower( implode( ' ', array( (string) $row['name'], (string) $row['sku'], (string) $row['teaser'] ) ) );

				if ( false === strpos( $hay, $query ) ) {
					continue;
				}
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Darf an diesem Teil ein Sperrbereich hängen?
	 *
	 * @param array|null $part Teil.
	 * @return string Fehlertext oder leer.
	 */
	public static function block_error( $part ) {
		if ( ! $part ) {
			return __( 'Dieses Teil gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' );
		}

		if ( 'basis' !== $part['role'] ) {
			return __( 'Sperrbereiche gibt es nur am Basismodul.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * Kleinschreibung mit Umlauten.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/* ====================================================================
	 * Hilfen
	 * ================================================================== */

	/**
	 * Fehlerantwort.
	 *
	 * @param string $message Text.
	 * @param int    $status  HTTP-Status.
	 * @param array  $extra   Weitere Felder.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422, array $extra = array() ) {
		return new WP_REST_Response( array_merge( array( 'ok' => false, 'message' => $message ), $extra ), $status );
	}

	/**
	 * Erfolgsantwort.
	 *
	 * @param string $message Text.
	 * @param array  $extra   Weitere Felder.
	 * @return WP_REST_Response
	 */
	protected static function done( $message, array $extra = array() ) {
		return new WP_REST_Response( array_merge( array( 'ok' => true, 'message' => $message ), $extra ) );
	}

	/**
	 * Meldung bei unbekanntem Teil.
	 *
	 * @return WP_REST_Response
	 */
	protected static function no_part() {
		return self::fail( __( 'Dieses Teil gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
	}

	/**
	 * Eingaben (JSON oder Formular) als Liste.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array
	 */
	protected static function params( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		return is_array( $params ) ? $params : array();
	}

	/**
	 * Ein Teil für die Oberfläche.
	 *
	 * @param array $row      Zeile aus PartRepository.
	 * @param array $articles Artikel nach ID.
	 * @param array $blocks   Sperrbereiche nach Teil.
	 * @return array
	 */
	protected static function part_row( array $row, array $articles, array $blocks ) {
		$article = isset( $articles[ (int) $row['article_id'] ] ) ? $articles[ (int) $row['article_id'] ] : null;
		$file    = (string) $row['model_file'];
		$kind    = self::model_kind( $file );
		$demos   = WebModel::demo_models();
		$zones   = isset( $blocks[ (int) $row['id'] ] ) ? $blocks[ (int) $row['id'] ] : array();
		$url     = 'none' === $kind ? '' : WebModel::url( $file );
		$shop    = Catalog::shop_facts( $article ? (int) $article['wc_product_id'] : 0 );
		$size    = 'none' === $kind ? null : WebModel::measure( $file );

		return array(
			'id'             => (int) $row['id'],
			'article_id'     => (int) $row['article_id'],
			'name'           => $article ? (string) $article['name'] : '',
			'sku'            => $article ? (string) $article['sku'] : '',
			'article_ok'     => (bool) $article,
			'article_active' => $article ? ArticleRepository::is_active( $article ) : false,
			'role'           => (string) $row['role'],
			'height_mm'      => (float) $row['height_mm'],
			'width_mm'       => (float) $row['width_mm'],
			'depth_mm'       => (float) $row['depth_mm'],
			'max_modules'    => (int) $row['max_modules'],
			'sort_order'     => (int) $row['sort_order'],
			'is_active'      => (int) $row['is_active'],
			'teaser'         => (string) $row['teaser'],
			'teaser_shop'    => $shop['short'],
			'shop_found'     => $shop['found'],
			'shop_price'     => $shop['price'],
			'shop_in_stock'  => $shop['in_stock'],
			'model_measure'  => $size,
			'model_kind'     => $kind,
			'model_file'     => $file,
			'model_demo'     => 'demo' === $kind && isset( $demos[ WebModel::demo_key( $file ) ] ) ? $demos[ WebModel::demo_key( $file ) ] : '',
			'model_url'      => (string) $url,
			'blocks'         => array_map(
				static function ( $b ) {
					return array(
						'id'      => (int) $b['id'],
						'from_mm' => (float) $b['from_mm'],
						'to_mm'   => (float) $b['to_mm'],
						'label'   => (string) $b['label'],
					);
				},
				$zones
			),
		);
	}

	/**
	 * Alle Teile als Oberflächenzeilen.
	 *
	 * @return array
	 */
	protected static function all_rows() {
		$articles = ArticleRepository::all_by_id();
		$blocks   = BlockRepository::all_by_part();
		$rows     = array();

		foreach ( PartRepository::all() as $row ) {
			$rows[] = self::part_row( $row, $articles, $blocks );
		}

		return $rows;
	}

	/**
	 * Ein Teil nach dem Schreiben, für die Antwort.
	 *
	 * @param int $id Teil.
	 * @return array|null
	 */
	protected static function row_of( $id ) {
		$row = PartRepository::find( $id );

		return $row ? self::part_row( $row, ArticleRepository::all_by_id(), BlockRepository::all_by_part() ) : null;
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Übersicht: Teile mit Filter, dazu alles, was das Formular braucht.
	 *
	 * @param WP_REST_Request $request Anfrage (q, rolle, zustand).
	 * @return WP_REST_Response
	 */
	public static function overview( WP_REST_Request $request ) {
		$all  = self::all_rows();
		$rows = self::filter_parts(
			$all,
			sanitize_text_field( (string) $request->get_param( 'q' ) ),
			sanitize_key( (string) $request->get_param( 'rolle' ) ),
			sanitize_key( (string) $request->get_param( 'zustand' ) )
		);

		$used     = array();
		$articles = array();

		foreach ( $all as $row ) {
			$used[ $row['article_id'] ] = $row['id'];
		}

		foreach ( ArticleRepository::selectable( true ) as $article ) {
			$articles[] = array(
				'id'      => (int) $article['id'],
				'name'    => (string) $article['name'],
				'sku'     => (string) $article['sku'],
				'part_id' => isset( $used[ (int) $article['id'] ] ) ? (int) $used[ (int) $article['id'] ] : 0,
			);
		}

		$demos = array();
		foreach ( WebModel::demo_models() as $key => $label ) {
			$demos[] = array( 'value' => WebModel::DEMO_PREFIX . $key, 'label' => $label );
		}

		$roles = array();
		foreach ( PartRepository::role_labels() as $key => $label ) {
			$roles[] = array( 'value' => $key, 'label' => $label );
		}

		return new WP_REST_Response(
			array(
				'parts'       => $rows,
				'total'       => count( $all ),
				'articles'    => $articles,
				'roles'       => $roles,
				'demos'       => $demos,
				'max_modules' => (int) Layout::MAX_MODULES,
				'max_bytes'   => min( (int) WebModel::MAX_BYTES, (int) wp_max_upload_size() ),
				'shortcode'   => '[' . Frontend::SHORTCODE . ']',
			)
		);
	}

	/**
	 * Teil anlegen oder ändern (multipart, optionales Feld „model“).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function part_save( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( $id && ! PartRepository::find( $id ) ) {
			return self::no_part();
		}

		$input = self::params( $request );

		$file = null;
		$all  = $request->get_file_params();

		if ( isset( $all['model'] ) && ( ! empty( $all['model']['name'] ) || ! empty( $all['model']['error'] ) ) ) {
			$file = $all['model'];
		}

		// Die Datei vorab prüfen, bevor etwas gespeichert wird: ein halb
		// gespeichertes Teil mit abgelehnter Datei wäre verwirrend.
		if ( $file ) {
			$max   = min( (int) WebModel::MAX_BYTES, (int) wp_max_upload_size() );
			$error = ProductFile::upload_error( isset( $file['error'] ) ? (int) $file['error'] : 0, $max );

			if ( '' !== $error ) {
				return self::fail( $error );
			}
		}

		$choice   = isset( $input['model_choice'] ) ? sanitize_text_field( (string) $input['model_choice'] ) : '';
		$plan     = self::model_plan( $choice, (bool) $file );
		$existing = $id ? PartRepository::find( $id ) : null;

		// Das Modell, das nach dem Speichern gilt, kennt die Maße.
		$measured = null;

		if ( 'upload' === $plan['action'] ) {
			$measured = GlbMeasure::measure_file( (string) $file['tmp_name'] );
		} elseif ( 'demo' === $plan['action'] ) {
			$measured = WebModel::measure( $plan['value'] );
		} elseif ( 'keep' === $plan['action'] && $existing ) {
			$measured = WebModel::measure( (string) $existing['model_file'] );
		}

		$result = PartRepository::validate( $input, $id ? $id : null, $measured );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		if ( $id ) {
			PartRepository::update( $id, $result['data'] );
		} else {
			$id = PartRepository::insert( $result['data'] );

			if ( ! $id ) {
				return self::fail( __( 'Das Teil ließ sich nicht anlegen.', 'lindenweg16' ), 500 );
			}
		}

		$old = $existing ? (string) $existing['model_file'] : '';

		if ( 'upload' === $plan['action'] ) {
			$article = ArticleRepository::find( (int) $result['data']['article_id'] );
			$stored  = WebModel::store( $file, $article ? $article['sku'] : 'teil' );

			if ( $stored['errors'] ) {
				return self::fail(
					__( 'Das Teil ist gespeichert, das Modell aber nicht: ', 'lindenweg16' ) . implode( ' ', $stored['errors'] ),
					422,
					array( 'saved' => true, 'part' => self::row_of( $id ) )
				);
			}

			PartRepository::set_model( $id, $stored['name'] );
			WebModel::delete( $old );
			$message = __( 'Teil und Modell gespeichert.', 'lindenweg16' );
		} elseif ( 'none' === $plan['action'] ) {
			PartRepository::set_model( $id, '' );
			WebModel::delete( $old );
			$message = __( 'Teil gespeichert, das Modell ist entfernt – es zeigt jetzt den Ersatzkörper.', 'lindenweg16' );
		} elseif ( 'demo' === $plan['action'] ) {
			PartRepository::set_model( $id, $plan['value'] );
			WebModel::delete( $old );
			$message = __( 'Teil gespeichert, es zeigt ein mitgeliefertes Demo-Modell.', 'lindenweg16' );
		} else {
			$message = __( 'Teil gespeichert.', 'lindenweg16' );
		}

		$message .= self::default_zones( $id );

		return self::done( $message, array( 'id' => $id, 'part' => self::row_of( $id ) ) );
	}

	/**
	 * Sperrbereiche für die Halter der Holzstange anlegen.
	 *
	 * Ein Basismodul mit dem Standardmodell hat zwei Halter, an denen kein
	 * Modul sitzen kann. Gibt es noch keinen Sperrbereich, entstehen sie
	 * hier aus der Länge. Vorhandene bleiben unangetastet.
	 *
	 * @param int $id Teil.
	 * @return string Anhang für die Rückmeldung, leer wenn nichts geschah.
	 */
	protected static function default_zones( $id ) {
		$part = PartRepository::find( $id );

		if ( ! $part || 'basis' !== $part['role'] || BlockRepository::for_part( $id ) ) {
			return '';
		}

		$file = (string) $part['model_file'];

		if ( '' !== $file && WebModel::demo_key( $file ) !== WebModel::STANDARD_KEY ) {
			return '';
		}

		$made = 0;

		foreach ( Layout::holder_zones( (float) $part['height_mm'] ) as $zone ) {
			$result = BlockRepository::create(
				$id,
				array(
					'from_mm' => $zone['from'],
					'to_mm'   => $zone['to'],
					'label'   => __( 'Halter', 'lindenweg16' ),
				),
				(float) $part['height_mm']
			);

			if ( $result['id'] ) {
				++$made;
			}
		}

		return $made ? ' ' . __( 'Die Sperrbereiche für die beiden Halter sind angelegt.', 'lindenweg16' ) : '';
	}

	/**
	 * Teil ein- oder ausschalten (Feld „aktiv“).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function part_active( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! PartRepository::find( $id ) ) {
			return self::no_part();
		}

		$params = self::params( $request );
		$on     = self::flag( isset( $params['aktiv'] ) ? $params['aktiv'] : 0 );

		if ( ! PartRepository::update( $id, array( 'is_active' => $on ) ) ) {
			return self::fail( __( 'Die Änderung ließ sich nicht speichern. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done(
			$on
				? __( 'Das Teil wird im Konfigurator wieder angeboten.', 'lindenweg16' )
				: __( 'Das Teil ist ausgeblendet – im Konfigurator erscheint es nicht mehr. Es bleibt hier erhalten.', 'lindenweg16' ),
			array( 'part' => self::row_of( $id ) )
		);
	}

	/**
	 * Teil samt Sperrbereichen und Modelldatei löschen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function part_delete( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! PartRepository::find( $id ) ) {
			return self::no_part();
		}

		if ( ! PartRepository::delete( $id ) ) {
			return self::fail( __( 'Das Teil ließ sich nicht löschen.', 'lindenweg16' ), 500 );
		}

		return self::done( __( 'Teil entfernt. Der Artikel selbst bleibt unverändert.', 'lindenweg16' ) );
	}

	/**
	 * Sperrbereich anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function block_add( WP_REST_Request $request ) {
		$part  = PartRepository::find( (int) $request['id'] );
		$error = self::block_error( $part );

		if ( '' !== $error ) {
			return self::fail( $error, $part ? 422 : 404 );
		}

		$params = self::params( $request );
		$input  = array(
			'from_mm' => isset( $params['from_mm'] ) ? (string) $params['from_mm'] : '',
			'to_mm'   => isset( $params['to_mm'] ) ? (string) $params['to_mm'] : '',
			'label'   => isset( $params['label'] ) ? (string) $params['label'] : '',
		);

		$result = BlockRepository::create( (int) $part['id'], $input, (float) $part['height_mm'] );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		return self::done( __( 'Sperrbereich angelegt.', 'lindenweg16' ), array( 'part' => self::row_of( (int) $part['id'] ) ) );
	}

	/**
	 * Sperrbereich löschen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function block_delete( WP_REST_Request $request ) {
		$part = PartRepository::find( (int) $request['id'] );

		if ( ! $part ) {
			return self::no_part();
		}

		$mine = false;
		foreach ( BlockRepository::for_part( (int) $part['id'] ) as $block ) {
			if ( (int) $block['id'] === (int) $request['bid'] ) {
				$mine = true;
			}
		}

		if ( ! $mine ) {
			return self::fail( __( 'Diesen Sperrbereich gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		BlockRepository::delete( (int) $request['bid'] );

		return self::done( __( 'Sperrbereich entfernt.', 'lindenweg16' ), array( 'part' => self::row_of( (int) $part['id'] ) ) );
	}
}
