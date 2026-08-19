<?php
/**
 * REST-Schnittstelle des Moduls unter lw16/v1.
 *
 * Grundlage der mobilen Schnellerfassung und späterer Auswertungen.
 * Authentifizierung über Cookie plus Nonce – kein eigenes Tokenverfahren,
 * damit kein zweiter Anmeldeweg entsteht, der abgesichert werden muss.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use WP_Error;
use WP_REST_Response;

/**
 * Registriert die Endpunkte.
 */
class RestApi {

	const NAMESPACE_V1 = 'lw16/v1';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Routen anmelden.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/erfassung/kontext',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_context' ),
				'permission_callback' => array( $this, 'can_produce' ),
				'args'                => array(
					'printer' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/druckjobs',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_print_job' ),
				'permission_callback' => array( $this, 'can_produce' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/zeiten',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_time_entry' ),
				'permission_callback' => array( $this, 'can_produce' ),
			)
		);
	}

	/**
	 * Berechtigung prüfen.
	 *
	 * @return bool|WP_Error
	 */
	public function can_produce() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			return new WP_Error(
				'lw16_forbidden',
				__( 'Keine Berechtigung.', 'lindenweg16' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Kontext für die Erfassungsmaske: Drucker, Artikel und die Vorbelegung
	 * aus dem letzten Job des gewählten Geräts.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function get_context( $request ) {
		$printer_code = (string) $request->get_param( 'printer' );
		$printers     = array();

		foreach ( PrinterRepository::all() as $printer ) {
			$printers[] = array(
				'id'     => (int) $printer['id'],
				'code'   => $printer['code'],
				'label'  => trim( (string) $printer['manufacturer'] . ' ' . (string) $printer['model'] ),
				'status' => $printer['status'],
			);
		}

		$articles = array();
		foreach ( ArticleRepository::all() as $article ) {
			if ( 'zukauf' === $article['sourcing_type'] ) {
				continue;
			}
			$revision   = RevisionRepository::current_for_article( (int) $article['id'] );
			$articles[] = array(
				'id'                 => (int) $article['id'],
				'sku'                => $article['sku'],
				'name'               => $article['name'],
				'has_active_revision' => (bool) $revision,
				'parts_per_plate'    => $revision ? (float) $revision['parts_per_plate'] : 1.0,
				'print_time_plate_h' => $revision ? (float) $revision['print_time_plate_h'] : 0.0,
			);
		}

		// Vorbelegung: letzte Verwendung an diesem Drucker.
		$defaults = array(
			'job_date'        => gmdate( 'Y-m-d' ),
			'printer_id'      => 0,
			'article_id'      => 0,
			'color'           => ColorRegistry::default_color(),
			'filament_lot_id' => 0,
			'plate_changes'   => 1,
		);

		$printer = $printer_code ? PrinterRepository::find_by_code( $printer_code ) : null;
		if ( $printer ) {
			$defaults['printer_id'] = (int) $printer['id'];
			$last                   = PrintJobRepository::last_for_printer( (int) $printer['id'] );
			if ( $last ) {
				$defaults['article_id']      = (int) $last['article_id'];
				$defaults['color']           = (string) $last['color'];
				$defaults['filament_lot_id'] = (int) $last['filament_lot_id'];
			}
		}

		$lots = array();
		foreach ( FilamentRepository::all() as $lot ) {
			$lots[] = array(
				'id'       => (int) $lot['id'],
				'lot_code' => $lot['lot_code'],
				'material' => $lot['material'],
				'color'    => $lot['color'],
			);
		}

		return new WP_REST_Response(
			array(
				'printers'   => $printers,
				'articles'   => $articles,
				'lots'       => $lots,
				'colors'     => ColorRegistry::all(),
				'categories' => TimeRepository::category_labels(),
				'defaults'   => $defaults,
			),
			200
		);
	}

	/**
	 * Druckjob anlegen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_print_job( $request ) {
		$input = (array) $request->get_json_params();

		// Vorbelegung ergänzen, damit die Maske mit fünf Eingaben auskommt.
		$input = self::apply_defaults( $input );

		$result = PrintJobRepository::validate( $input );

		if ( $result['errors'] ) {
			return new WP_Error(
				'lw16_invalid',
				implode( ' ', $result['errors'] ),
				array( 'status' => 422 )
			);
		}

		$id = PrintJobRepository::insert( $result['data'] );

		if ( ! $id ) {
			return new WP_Error( 'lw16_failed', __( 'Der Druckjob konnte nicht gespeichert werden.', 'lindenweg16' ), array( 'status' => 500 ) );
		}

		$job = PrintJobRepository::find( $id );

		return new WP_REST_Response(
			array(
				'id'         => $id,
				'client_id'  => isset( $input['client_id'] ) ? sanitize_text_field( (string) $input['client_id'] ) : '',
				'batch_code' => $job && $job['batch_id'] ? (string) BatchRepository::find( (int) $job['batch_id'] )['batch_code'] : '',
				'message'    => __( 'Gebucht.', 'lindenweg16' ),
			),
			201
		);
	}

	/**
	 * Zeiteintrag anlegen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_time_entry( $request ) {
		$input  = (array) $request->get_json_params();
		$result = TimeRepository::validate( $input );

		if ( $result['errors'] ) {
			return new WP_Error( 'lw16_invalid', implode( ' ', $result['errors'] ), array( 'status' => 422 ) );
		}

		$id = TimeRepository::insert( $result['data'] );

		return new WP_REST_Response(
			array(
				'id'        => $id,
				'client_id' => isset( $input['client_id'] ) ? sanitize_text_field( (string) $input['client_id'] ) : '',
				'message'   => __( 'Gebucht.', 'lindenweg16' ),
			),
			201
		);
	}

	/**
	 * Fehlende Felder aus der aktiven Revision ergänzen – reine Logik.
	 *
	 * Datum, Revision, geplante Stückzahl und Druckzeit müssen am Drucker
	 * nicht eingegeben werden; sie stehen im Druckprofil.
	 *
	 * @param array      $input    Eingaben.
	 * @param array|null $revision Aktive Revision, sonst aus der Datenbank.
	 * @return array
	 */
	public static function apply_defaults( array $input, $revision = null ) {
		if ( empty( $input['job_date'] ) ) {
			$input['job_date'] = gmdate( 'Y-m-d' );
		}

		if ( null === $revision && ! empty( $input['article_id'] ) ) {
			$revision = RevisionRepository::current_for_article( (int) $input['article_id'] );
		}

		if ( $revision ) {
			if ( ! isset( $input['parts_planned'] ) || '' === $input['parts_planned'] ) {
				$input['parts_planned'] = (float) $revision['parts_per_plate'];
			}
			if ( ! isset( $input['print_time_h'] ) || '' === $input['print_time_h'] ) {
				$input['print_time_h'] = (float) $revision['print_time_plate_h'];
			}
		}

		if ( ! isset( $input['plate_changes'] ) || '' === $input['plate_changes'] ) {
			$input['plate_changes'] = 1;
		}

		if ( empty( $input['color'] ) ) {
			$input['color'] = ColorRegistry::default_color();
		}

		return $input;
	}
}
