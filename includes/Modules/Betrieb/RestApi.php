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

		register_rest_route(
			self::NAMESPACE_V1,
			'/scan',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => array( $this, 'can_produce' ),
				'args'                => array(
					'code' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/lager/bewegung',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_movement' ),
				'permission_callback' => array( $this, 'can_produce' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/kommission',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'book_commission' ),
				'permission_callback' => array( $this, 'can_produce' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/picklisten',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_picklists' ),
				'permission_callback' => array( $this, 'can_produce' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/picklisten/position',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pick_item' ),
				'permission_callback' => array( $this, 'can_produce' ),
			)
		);
	}

	/**
	 * Gescannten Code auflösen.
	 *
	 * Gibt zusätzlich den Bestand zurück, wenn der Code auf einen Lagerplatz
	 * oder einen Artikel zeigt: Wer scannt, will fast immer sofort wissen,
	 * was da liegen müsste.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function scan( $request ) {
		$result = ScanResolver::resolve( (string) $request->get_param( 'code' ) );

		if ( 'bin' === $result['type'] ) {
			$result['stock'] = $this->bin_stock( (int) $result['id'] );
		}

		if ( 'article' === $result['type'] ) {
			$result['stock'] = $this->article_stock( (int) $result['id'] );
		}

		if ( 'batch' === $result['type'] ) {
			$result['stock'] = $this->article_stock( (int) $result['extra']['article_id'] );
		}

		return new WP_REST_Response( $result, 'none' === $result['type'] ? 404 : 200 );
	}

	/**
	 * Bestand eines Lagerplatzes, für die Anzeige aufbereitet.
	 *
	 * @param int $bin_id Platz.
	 * @return array
	 */
	protected function bin_stock( $bin_id ) {
		$balances = BinRepository::balances( StockRepository::all() );
		$rows     = BinRepository::stock_of( $balances, $bin_id );
		$articles = ArticleRepository::all_by_id();
		$out      = array();

		foreach ( $rows as $article_id => $colors ) {
			foreach ( $colors as $color => $qty ) {
				$out[] = array(
					'article_id' => (int) $article_id,
					'sku'        => isset( $articles[ $article_id ] ) ? $articles[ $article_id ]['sku'] : '',
					'name'       => isset( $articles[ $article_id ] ) ? $articles[ $article_id ]['name'] : '',
					'color'      => (string) $color,
					'qty'        => (float) $qty,
				);
			}
		}

		return $out;
	}

	/**
	 * Bestand eines Artikels je Farbe und Lagerplatz.
	 *
	 * @param int $article_id Artikel.
	 * @return array
	 */
	protected function article_stock( $article_id ) {
		$movements = StockRepository::all( (int) $article_id );
		$balances  = BinRepository::balances( $movements );
		$bins      = array();

		foreach ( BinRepository::all() as $bin ) {
			$bins[ (int) $bin['id'] ] = $bin;
		}

		$out = array();
		foreach ( $balances as $bin_id => $articles ) {
			foreach ( $articles as $id => $colors ) {
				foreach ( $colors as $color => $qty ) {
					if ( (int) $id !== (int) $article_id || abs( $qty ) < 0.0005 ) {
						continue;
					}
					$out[] = array(
						'bin_id' => (int) $bin_id,
						'bin'    => isset( $bins[ $bin_id ] ) ? $bins[ $bin_id ]['code'] : '',
						'color'  => (string) $color,
						'qty'    => (float) $qty,
					);
				}
			}
		}

		// Bestand ohne Platzangabe getrennt ausweisen, statt ihn zu verschweigen.
		// Wer den Platz nachpflegt, soll sehen, wie viel noch offen ist.
		$located = 0.0;
		foreach ( $out as $row ) {
			$located += $row['qty'];
		}

		$total = StockLedger::total( $movements );

		return array(
			'bins'       => $out,
			'ohne_platz' => round( $total - $located, 3 ),
			'gesamt'     => round( $total, 3 ),
		);
	}

	/**
	 * Wurde dieser Vorgang schon verbucht?
	 *
	 * Die ERP-Oberfläche merkt Buchungen ohne Verbindung vor und sendet sie
	 * später mit einer Kennung (client_ref). Kam die erste Antwort nicht
	 * mehr an, die Buchung aber schon, würde das Nachsenden doppelt buchen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return bool
	 */
	protected function already_done( $request ) {
		$ref = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $request->get_param( 'client_ref' ) );

		return '' !== $ref && false !== get_transient( 'lw16_cref_' . md5( get_current_user_id() . '|' . $ref ) );
	}

	/**
	 * Vorgang als verbucht merken (einen Tag lang).
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 */
	protected function remember_done( $request ) {
		$ref = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $request->get_param( 'client_ref' ) );

		if ( '' !== $ref ) {
			set_transient( 'lw16_cref_' . md5( get_current_user_id() . '|' . $ref ), 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Lagerbewegung vom Handy buchen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_movement( $request ) {
		// Feines ERP-Recht: Erfassen darf jeder mit lw16_capture, Bestand
		// buchen nur, wem es die ERP-Rechteverwaltung gibt (ohne eigene
		// Einrichtung gilt, was die alten Rechte hergeben).
		if ( ! \Lindenweg16\Erp\Access::can( 'lager.buchen' ) ) {
			return new WP_Error( 'lw16_forbidden', __( 'Dir fehlt das Recht, Bestand zu buchen.', 'lindenweg16' ), array( 'status' => 403 ) );
		}

		if ( $this->already_done( $request ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}

		$article_id = (int) $request->get_param( 'article_id' );
		$qty        = (float) $request->get_param( 'qty' );
		$type       = sanitize_key( (string) $request->get_param( 'movement_type' ) );
		$bin_id     = (int) $request->get_param( 'bin_id' );
		$to_bin_id  = (int) $request->get_param( 'to_bin_id' );
		$color      = (string) $request->get_param( 'color' );
		$note       = (string) $request->get_param( 'note' );

		if ( ! $article_id || ! ArticleRepository::find( $article_id ) ) {
			return new WP_Error( 'lw16_no_article', __( 'Artikel nicht gefunden.', 'lindenweg16' ), array( 'status' => 400 ) );
		}

		if ( abs( $qty ) < 0.0005 ) {
			return new WP_Error( 'lw16_no_qty', __( 'Eine Bewegung ohne Menge ist keine Bewegung.', 'lindenweg16' ), array( 'status' => 400 ) );
		}

		if ( ! array_key_exists( $type, StockLedger::movement_labels() ) ) {
			$type = 'korrektur';
		}

		$common = array(
			'article_id'    => $article_id,
			'color'         => $color,
			'movement_type' => $type,
			'note'          => $note,
			'ref_type'      => 'mobil',
		);

		// Umlagern ist kein eigener Bewegungstyp, sondern ein Paar: Abgang am
		// einen Platz, Zugang am anderen. Der Gesamtbestand bleibt gleich, und
		// beide Buchungen stehen einzeln im Journal.
		if ( $to_bin_id && $bin_id && $to_bin_id !== $bin_id ) {
			$ids = StockRepository::book_many(
				array(
					array( 'qty' => -1 * abs( $qty ), 'bin_id' => $bin_id ),
					array( 'qty' => abs( $qty ), 'bin_id' => $to_bin_id ),
				),
				array_merge( $common, array( 'movement_type' => 'umlagerung' ) )
			);

			$this->remember_done( $request );

			return new WP_REST_Response( array( 'ok' => true, 'ids' => $ids ), 201 );
		}

		$id = StockRepository::book( array_merge( $common, array( 'qty' => $qty, 'bin_id' => $bin_id ) ) );

		if ( ! $id ) {
			return new WP_Error( 'lw16_book_failed', __( 'Buchung fehlgeschlagen.', 'lindenweg16' ), array( 'status' => 500 ) );
		}

		$this->remember_done( $request );

		return new WP_REST_Response( array( 'ok' => true, 'ids' => array( $id ) ), 201 );
	}

	/**
	 * Offene Picklisten mit ihren Positionen.
	 *
	 * @return WP_REST_Response
	 */
	public function get_picklists() {
		return new WP_REST_Response( PicklistRepository::open_with_items(), 200 );
	}

	/**
	 * Eine Position abhaken.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pick_item( $request ) {
		if ( $this->already_done( $request ) ) {
			return new WP_REST_Response( array( 'success' => true, 'duplicate' => true, 'done' => true ), 200 );
		}

		$result = PicklistRepository::pick(
			(int) $request->get_param( 'item_id' ),
			(float) $request->get_param( 'qty' ),
			(string) $request->get_param( 'scanned' )
		);

		if ( ! $result['success'] ) {
			return new WP_Error( 'lw16_pick_failed', $result['message'], array( 'status' => 400 ) );
		}

		$this->remember_done( $request );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Berechtigung prüfen.
	 *
	 * @return bool|WP_Error
	 */
	public function can_produce() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_capture' ) ) {
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
		foreach ( ArticleRepository::selectable() as $article ) {
			// Die Druckmaske zeigt nur, was auch gedruckt wird.
			if ( in_array( $article['sourcing_type'], array( 'zukauf', 'montage' ), true ) ) {
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

		// Für die Kommissionsliste: alle verkäuflichen Artikel, nicht nur die
		// gedruckten – ausgeliefert wird auch Zukauf. Dazu die Händler mit
		// dem, was bei ihnen schon liegt, damit niemand mehr abrechnet, als
		// draussen ist.
		$verkaufbar = array();

		foreach ( ArticleRepository::selectable( true ) as $article ) {
			$verkaufbar[] = array(
				'id'   => (int) $article['id'],
				'sku'  => $article['sku'],
				'name' => (string) $article['name'],
			);
		}

		$contacts = array();

		foreach ( ContactRepository::all() as $contact ) {
			$bestand = array();

			foreach ( ContactRepository::commission_stock( (int) $contact['id'] ) as $row ) {
				$bestand[ (int) $row['article_id'] . '|' . (string) $row['color'] ] = (float) $row['qty'];
			}

			$contacts[] = array(
				'id'         => (int) $contact['id'],
				'name'       => (string) $contact['name'],
				'commission' => $bestand,
			);
		}

		return new WP_REST_Response(
			array(
				'printers'   => $printers,
				'articles'   => $articles,
				'lots'       => $lots,
				'colors'     => ColorRegistry::all(),
				'categories' => TimeRepository::category_labels(),
				'defaults'        => $defaults,
				'sellable'        => $verkaufbar,
				'contacts'        => $contacts,
				'commissionTypes' => CommissionBatch::types(),
			),
			200
		);
	}

	/**
	 * Eine Kommissionsliste vom Handy buchen.
	 *
	 * Geprüft und gebucht wird über dieselben Klassen wie am Schreibtisch –
	 * zwei Fassungen derselben Regel drifteten sonst auseinander, und die
	 * eine buchte den Bestellsatz, die andere nicht.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function book_commission( $request ) {
		$contact_id = (int) $request->get_param( 'contact_id' );
		$type       = sanitize_key( (string) $request->get_param( 'movement_type' ) );
		$rows       = (array) $request->get_param( 'lines' );

		if ( CommissionBatch::too_long( $rows ) ) {
			return new WP_Error( 'lw16_zu_lang', __( 'Die Liste ist zu lang für eine Lieferung.', 'lindenweg16' ), array( 'status' => 400 ) );
		}

		$sauber = array();

		foreach ( $rows as $row ) {
			$row      = (array) $row;
			$sauber[] = array(
				'article_id' => isset( $row['article_id'] ) ? (int) $row['article_id'] : 0,
				'color'      => isset( $row['color'] ) ? sanitize_text_field( (string) $row['color'] ) : '',
				'qty'        => isset( $row['qty'] ) && is_scalar( $row['qty'] ) ? sanitize_text_field( (string) $row['qty'] ) : '',
			);
		}

		$result = CommissionBatch::validate( $sauber, $type, $contact_id );

		if ( $result['errors'] ) {
			return new WP_Error( 'lw16_ungueltig', implode( ' ', $result['errors'] ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'message'   => CommissionRepository::book_batch( $result['lines'], $type, $contact_id ),
				'positions' => $result['positions'],
				'pieces'    => $result['pieces'],
			),
			201
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
