<?php
/**
 * REST-Schnittstelle der ERP-Oberfläche unter lw16/v1/erp.
 *
 * Anmeldung über das WordPress-Cookie samt REST-Nonce – dieselben Konten
 * wie im Shop. Jede Route prüft ein feines Recht; ein Recht, das die
 * aufrufende Person selbst nicht hat, kann sie auch nicht vergeben.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen.
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
	 * Routen anlegen.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'bootstrap' ),
				'permission_callback' => array( $this, 'can_use' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/aufgaben',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'aufgaben' ),
				'permission_callback' => array( $this, 'can_use' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/drucker/(?P<id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'drucker_akte' ),
				'permission_callback' => $this->needs( array( 'fertigung.druckfarm' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'dashboard' ),
				'permission_callback' => array( $this, 'can_use' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/druckfarm',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'druckfarm' ),
				'permission_callback' => $this->needs( array( 'fertigung.druckfarm' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/auftraege',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'auftraege' ),
				'permission_callback' => $this->needs( array( 'verkauf.orders' ) ),
				'args'                => array( 'status' => array( 'type' => 'string', 'required' => false ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/auftraege/(?P<id>\d+)/bestaetigen',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'auftrag_bestaetigen' ),
				'permission_callback' => $this->needs( array( 'verkauf.orders', 'verkauf.orders.status' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/versand',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'versand' ),
				'permission_callback' => $this->needs( array( 'verkauf.versand' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/versand/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'versand_order' ),
				'permission_callback' => $this->needs( array( 'verkauf.versand' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/versand/(?P<id>\d+)/versendet',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'versand_versendet' ),
				'permission_callback' => $this->needs( array( 'verkauf.versand' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/versand/(?P<id>\d+)/label',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'versand_label' ),
				'permission_callback' => $this->needs( array( 'verkauf.versand', 'verkauf.versand.etikett' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/versand/(?P<id>\d+)/kontakt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'versand_kontakt' ),
				'permission_callback' => $this->needs( array( 'verkauf.versand', 'verkauf.kontakte' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/versand/label/(?P<id>\d+)/storno',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'versand_storno' ),
				'permission_callback' => $this->needs( array( 'verkauf.versand', 'verkauf.versand.etikett' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/material',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'material' ),
				'permission_callback' => $this->needs( array( 'lager.material' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/material/rollen',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'material_rollen_speichern' ),
				'permission_callback' => $this->needs( array( 'lager.material' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/material/spule',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'material_spule' ),
				'permission_callback' => $this->needs( array( 'lager.material', 'lager.buchen' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/material/spule/etikett',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'material_spule_etikett' ),
				'permission_callback' => $this->needs( array( 'lager.material', 'verwaltung.etiketten' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/produkte',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'produkte' ),
				'permission_callback' => $this->needs( array( 'fertigung.artikel' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/produkte/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'produkt' ),
				'permission_callback' => $this->needs( array( 'fertigung.artikel' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/einkauf',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'einkauf' ),
				'permission_callback' => $this->needs( array( 'lager.einkauf' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/einkauf/offen',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'einkauf_offen' ),
				'permission_callback' => $this->needs( array( 'lager.einkauf' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/einkauf/bestellt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'einkauf_bestellt' ),
				'permission_callback' => $this->needs( array( 'lager.einkauf' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/einkauf/(?P<id>\d+)/eingang',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'einkauf_eingang' ),
				'permission_callback' => $this->needs( array( 'lager.einkauf', 'lager.buchen' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/sollist',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'sollist' ),
				'permission_callback' => $this->needs( array( 'fertigung.sollist' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/sollist/(?P<id>\d+)/uebernehmen',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sollist_uebernehmen' ),
				'permission_callback' => $this->needs( array( 'fertigung.sollist', 'fertigung.stammdaten.edit' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/workflow',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'workflow' ),
				'permission_callback' => array( $this, 'can_workflow' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/workflow/bedarf',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'workflow_demand' ),
				'permission_callback' => array( $this, 'can_plan' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/workflow/vorschau',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'workflow_preview' ),
				'permission_callback' => array( $this, 'can_plan' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/workflow/freigeben',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'workflow_release' ),
				'permission_callback' => array( $this, 'can_plan' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/workflow/einstellungen',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'workflow_settings' ),
					'permission_callback' => array( $this, 'can_plan' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'workflow_settings_save' ),
					'permission_callback' => array( $this, 'can_plan' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/workflow/montage',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'assembly_create' ),
				'permission_callback' => array( $this, 'can_plan' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/montage/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'assembly_get' ),
				'permission_callback' => array( $this, 'can_workflow' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/montage/(?P<id>\d+)/(?P<action>start|pause|fertig|abbrechen)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'assembly_act' ),
				'permission_callback' => array( $this, 'can_workflow' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/runs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'run_get' ),
				'permission_callback' => array( $this, 'can_workflow' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/runs/(?P<id>\d+)/(?P<action>ruesten|druckstart|druckende|melden|abbrechen|etikett|platte-frei|slicen|erneut)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_act' ),
				'permission_callback' => array( $this, 'can_workflow' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'users' ),
				'permission_callback' => array( $this, 'can_manage_rights' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/erp/users/(?P<id>\d+)/permissions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_permissions' ),
				'permission_callback' => array( $this, 'can_manage_rights' ),
				'args'                => array(
					'keys' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
					),
				),
			)
		);

		// Seiten, die in eigenen Dateien leben: includes/Erp/Pages/<Name>.php
		// mit einer statischen register( RestApi $api ). Jede Seite bringt ihre
		// Routen selbst mit.
		foreach ( (array) glob( __DIR__ . '/Pages/*.php' ) as $file ) {
			$class = __NAMESPACE__ . '\\Pages\\' . basename( $file, '.php' );

			if ( class_exists( $class ) && method_exists( $class, 'register' ) ) {
				$class::register( $this );
			}
		}
	}

	/**
	 * Berechtigungsprüfung für eine Route: alle genannten Rechte nötig.
	 *
	 * @param string[] $perms Rechte.
	 * @return callable
	 */
	public function needs( array $perms ) {
		return function () use ( $perms ) {
			foreach ( $perms as $perm ) {
				if ( ! Access::can( $perm ) ) {
					return false;
				}
			}

			return true;
		};
	}

	/**
	 * Druckfarm: Live-Stand der Drucker.
	 *
	 * @return WP_REST_Response
	 */
	public function druckfarm() {
		return new WP_REST_Response( array( 'printers' => Screens::printer_farm(), 'now' => time() ) );
	}

	/**
	 * Aufträge.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function auftraege( WP_REST_Request $request ) {
		return new WP_REST_Response(
			array(
				'orders'  => Screens::orders( sanitize_key( (string) $request->get_param( 'status' ) ) ),
				'confirm' => Access::can( 'verkauf.orders.status' ),
			)
		);
	}

	/**
	 * Entwurf bestätigen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function auftrag_bestaetigen( WP_REST_Request $request ) {
		$result = Screens::confirm_order( (int) $request['id'] );

		return new WP_REST_Response( $result, $result['ok'] ? 200 : 409 );
	}

	/**
	 * Versandliste.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function versand( WP_REST_Request $request ) {
		$data          = Screens::shipping();
		$data['label'] = Access::can( 'verkauf.versand.etikett' );

		return new WP_REST_Response( $data );
	}

	/**
	 * Eine Bestellung für die Packseite.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function versand_order( WP_REST_Request $request ) {
		$data = Screens::ship_order( (int) $request['id'] );

		if ( null === $data ) {
			return new WP_REST_Response( array( 'message' => __( 'Bestellung nicht gefunden.', 'lindenweg16' ) ), 404 );
		}

		$data['can_label'] = Access::can( 'verkauf.versand.etikett' );

		return new WP_REST_Response( $data );
	}

	/**
	 * Bestellung einem Kontakt zuordnen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function versand_kontakt( WP_REST_Request $request ) {
		$result = Screens::link_contact( (int) $request['id'], (int) $request->get_param( 'contact_id' ) );

		return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/**
	 * Als versendet melden (Lagerabgang inklusive).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function versand_versendet( WP_REST_Request $request ) {
		$result = Screens::mark_shipped( (int) $request['id'] );

		return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/**
	 * DHL-Label erzeugen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function versand_label( WP_REST_Request $request ) {
		$result = \Lindenweg16\Modules\Betrieb\Dhl\DhlShipping::create_label(
			(int) $request['id'],
			array(
				'product'  => sanitize_text_field( (string) $request->get_param( 'product' ) ),
				'weight_g' => (float) $request->get_param( 'weight_g' ),
				'bulky'    => (bool) $request->get_param( 'bulky' ),
			)
		);

		return new WP_REST_Response( $result, $result['ok'] ? 201 : 422 );
	}

	/**
	 * DHL-Label stornieren.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function versand_storno( WP_REST_Request $request ) {
		$result = \Lindenweg16\Modules\Betrieb\Dhl\DhlShipping::void_label( (int) $request['id'] );

		return new WP_REST_Response( $result, ! empty( $result['ok'] ) ? 200 : 422 );
	}

	/**
	 * Produktliste mit Problemen.
	 *
	 * @return WP_REST_Response
	 */
	public function produkte() {
		return new WP_REST_Response( Catalog::listing( Access::can( 'finanzen.kosten' ) ) );
	}

	/**
	 * Produktakte.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function produkt( WP_REST_Request $request ) {
		$file = Catalog::file( (int) $request['id'], Access::can( 'finanzen.kosten' ) );

		if ( null === $file ) {
			return new WP_REST_Response( array( 'message' => __( 'Dieses Produkt gibt es nicht (mehr).', 'lindenweg16' ) ), 404 );
		}

		$file['shop_edit'] = $file['shop'] && Access::can( 'verkauf.shop_products' ) && current_user_can( 'edit_products' );
		$file['edit']      = Access::can( 'fertigung.stammdaten.edit' );
		$file['money']     = Access::can( 'finanzen.kosten' );

		return new WP_REST_Response( $file );
	}

	/**
	 * Material: eine Karte je Filamentfarbe.
	 *
	 * @return WP_REST_Response
	 */
	public function material() {
		return new WP_REST_Response(
			array(
				'colors'  => Material::overview(),
				'rolls'   => array(
					'roll_g' => \Lindenweg16\Modules\Betrieb\FilamentRolls::default_roll_g(),
					'tare_g' => \Lindenweg16\Modules\Betrieb\FilamentRolls::default_tare_g(),
				),
				'spule'   => array(
					'can'       => Access::can( 'lager.buchen' ),
					'can_label' => Access::can( 'verwaltung.etiketten' ),
					'materials' => Spools::MATERIALS,
					'colors'    => array_map(
						static function ( $slug, $label ) {
							return array( 'slug' => (string) $slug, 'label' => (string) $label );
						},
						array_keys( \Lindenweg16\Modules\Betrieb\ColorRegistry::all() ),
						array_values( \Lindenweg16\Modules\Betrieb\ColorRegistry::all() )
					),
				),
				'order'   => Access::can( 'lager.einkauf' ),
				'receive' => Access::can( 'lager.einkauf' ) && Access::can( 'lager.buchen' ),
			)
		);
	}

	/**
	 * Rolleneinstellungen speichern: Gewicht je Rolle und der leeren Spule.
	 *
	 * @param WP_REST_Request $request Anfrage (roll_g, tare_g).
	 * @return WP_REST_Response
	 */
	public function material_rollen_speichern( WP_REST_Request $request ) {
		\Lindenweg16\Modules\Betrieb\FilamentRolls::save_settings(
			array(
				'roll_g' => $request->get_param( 'roll_g' ),
				'tare_g' => $request->get_param( 'tare_g' ),
			)
		);

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Gespeichert.', 'lindenweg16' ),
				'rolls'   => array(
					'roll_g' => \Lindenweg16\Modules\Betrieb\FilamentRolls::default_roll_g(),
					'tare_g' => \Lindenweg16\Modules\Betrieb\FilamentRolls::default_tare_g(),
				),
			)
		);
	}

	/**
	 * Spule erfassen (Material, Farbe, Menge), auf Wunsch gleich mit Etikett.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function material_spule( WP_REST_Request $request ) {
		$in          = (array) $request->get_json_params();
		$in['print'] = ! empty( $in['print'] ) && Access::can( 'verwaltung.etiketten' );
		$res         = Spools::create( $in );

		return new WP_REST_Response( $res, $res['ok'] ? 200 : 400 );
	}

	/**
	 * Etikett einer Spule drucken.
	 *
	 * @param WP_REST_Request $request Anfrage mit code.
	 * @return WP_REST_Response
	 */
	public function material_spule_etikett( WP_REST_Request $request ) {
		$res = Spools::print_label( (string) $request->get_param( 'code' ) );

		return new WP_REST_Response( $res, $res['ok'] ? 200 : 400 );
	}

	/**
	 * Einkaufs-Board.
	 *
	 * @return WP_REST_Response
	 */
	public function einkauf() {
		return new WP_REST_Response( Procurement::board() );
	}

	/**
	 * Offene Bestellungen zu einem Teil (Wareneingang per Scan).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function einkauf_offen( WP_REST_Request $request ) {
		return new WP_REST_Response(
			array(
				'open'    => Procurement::open_for( sanitize_key( (string) $request->get_param( 'type' ) ), (int) $request->get_param( 'id' ) ),
				'receive' => Access::can( 'lager.buchen' ),
			)
		);
	}

	/**
	 * Als bestellt vermerken.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function einkauf_bestellt( WP_REST_Request $request ) {
		$result = Procurement::place(
			array(
				'item_type'      => sanitize_key( (string) $request->get_param( 'type' ) ),
				'item_id'        => (int) $request->get_param( 'item_id' ),
				'item_ref'       => sanitize_key( (string) $request->get_param( 'item_ref' ) ),
				'qty'            => (string) $request->get_param( 'qty' ),
				'unit_price_net' => (string) $request->get_param( 'price' ),
				'supplier'       => sanitize_text_field( (string) $request->get_param( 'supplier' ) ),
				'order_ref'      => sanitize_text_field( (string) $request->get_param( 'order_ref' ) ),
				'expected_on'    => sanitize_text_field( (string) $request->get_param( 'expected_on' ) ),
			)
		);

		return new WP_REST_Response( $result, $result['ok'] ? 201 : 422 );
	}

	/**
	 * Wareneingang buchen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function einkauf_eingang( WP_REST_Request $request ) {
		$result = Procurement::receive(
			(int) $request['id'],
			(string) $request->get_param( 'qty' ),
			array(
				'material' => sanitize_text_field( (string) $request->get_param( 'material' ) ),
			)
		);

		return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/**
	 * Soll gegen Ist.
	 *
	 * @return WP_REST_Response
	 */
	public function sollist() {
		$data         = SollIst::report( Access::can( 'finanzen.kosten' ) );
		$data['edit'] = Access::can( 'fertigung.stammdaten.edit' );

		return new WP_REST_Response( $data );
	}

	/**
	 * Gemessene Planzeit übernehmen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function sollist_uebernehmen( WP_REST_Request $request ) {
		$result = SollIst::adopt( (int) $request['id'] );

		return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/**
	 * Darf die Person im Workflow arbeiten?
	 *
	 * @return bool
	 */
	public function can_workflow() {
		return Access::can( 'fertigung.workflow' );
	}

	/**
	 * Darf die Person den Plan freigeben?
	 *
	 * @return bool
	 */
	public function can_plan() {
		return Access::can( 'fertigung.workflow.plan' );
	}

	/**
	 * Der Workflow: Platten in ihren Spalten, dazu Einstellungen.
	 *
	 * Der Bedarf kommt getrennt (workflow/bedarf): Er rechnet über alle
	 * Stücklistenstufen und gehört nur denen, die den Plan freigeben.
	 *
	 * @return WP_REST_Response
	 */
	public function workflow() {
		$plan = Access::can( 'fertigung.workflow.plan' );

		return new WP_REST_Response(
			array(
				'columns'  => Workflow::columns(),
				'plan'     => $plan,
				'assembly' => Assembly::board(),
				'printers' => $plan ? Workflow::printers() : array(),
				'alerts'   => array_map(
					static function ( $alert ) {
						return array(
							'label'     => $alert['label'],
							'coverage'  => $alert['coverage_days'],
							'threshold' => $alert['threshold_days'],
							'order_kg'  => $alert['order_qty'],
						);
					},
					Material::alerts()
				),
				'now'      => time(),
			)
		);
	}

	/**
	 * Bedarf als Karten.
	 *
	 * @return WP_REST_Response
	 */
	public function workflow_demand() {
		return new WP_REST_Response( Workflow::demand() );
	}

	/**
	 * Platten anordnen (Vorschau).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function workflow_preview( WP_REST_Request $request ) {
		$result = Workflow::preview( (array) $request->get_param( 'items' ), sanitize_key( (string) $request->get_param( 'printer' ) ) );

		return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
	}

	/**
	 * Vorschau freigeben.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function workflow_release( WP_REST_Request $request ) {
		$labels = null === $request->get_param( 'labels' ) ? (bool) Workflow::settings()['label_auto'] : (bool) $request->get_param( 'labels' );
		$result = Workflow::release( array_map( 'intval', (array) $request->get_param( 'plate_ids' ) ), $labels, (bool) $request->get_param( 'slice' ) );

		return new WP_REST_Response( $result, $result['ok'] ? 201 : 422 );
	}

	/**
	 * Einstellungen lesen: Etikett, Hochrechnung, Auswahllisten.
	 *
	 * @return WP_REST_Response
	 */
	public function workflow_settings() {
		$rolls = array();

		foreach ( \Lindenweg16\Modules\Betrieb\LabelSheet::rolls() as $key => $roll ) {
			$rolls[] = array( 'id' => $key, 'label' => $roll['label'], 'hint' => $roll['hint'] );
		}

		$fields = array();
		foreach ( Workflow::label_field_options() as $key => $label ) {
			$fields[] = array( 'id' => $key, 'label' => $label );
		}

		return new WP_REST_Response(
			array(
				'settings'       => Workflow::settings(),
				'rolls'          => $rolls,
				'fields'         => $fields,
				'queue_printers' => Workflow::queue_printers(),
			)
		);
	}

	/**
	 * Einstellungen speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function workflow_settings_save( WP_REST_Request $request ) {
		$saved = Workflow::save_settings( (array) $request->get_json_params() );

		AuditLog::record( 'erp_workflow_einstellungen', 'settings', 0, $saved );

		return new WP_REST_Response( array( 'settings' => $saved ) );
	}

	/**
	 * Eine Platte (für die mobile Seite).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_get( WP_REST_Request $request ) {
		Workflow::sync_printers();

		$view = Workflow::view_by_id( (int) $request['id'] );

		if ( ! $view ) {
			return new WP_Error( 'lw16_erp_run', __( 'Platte nicht gefunden.', 'lindenweg16' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'run'          => $view,
				'plan'         => Access::can( 'fertigung.workflow.plan' ),
				'reasons'      => Workflow::failure_reasons(),
			)
		);
	}

	/**
	 * Aktion auf einer Platte.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function run_act( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$action = (string) $request['action'];

		if ( 'etikett' === $action ) {
			$result = Workflow::print_labels( array( $id ) );

			return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
		}

		if ( 'platte-frei' === $action ) {
			$result = Workflow::clear_bed( $id );

			return new WP_REST_Response( $result, $result['ok'] ? 200 : $result['status'] );
		}

		if ( 'slicen' === $action || 'erneut' === $action ) {
			$result = 'slicen' === $action ? Workflow::slice( $id ) : Workflow::requeue( $id );

			return new WP_REST_Response( $result, $result['ok'] ? 200 : $result['status'] );
		}

		// Abbrechen vernichtet Arbeit: nur, wer auch den Plan freigibt.
		if ( 'abbrechen' === $action && ! Access::can( 'fertigung.workflow.plan' ) ) {
			return new WP_Error( 'lw16_forbidden', __( 'Abbrechen darf nur, wer Pläne freigeben darf.', 'lindenweg16' ), array( 'status' => 403 ) );
		}

		$result = Workflow::act( $id, $action, (array) $request->get_json_params() );

		return new WP_REST_Response( $result, $result['ok'] ? 200 : $result['status'] );
	}

	/**
	 * Montageauftrag aus dem Bedarf anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function assembly_create( WP_REST_Request $request ) {
		$result = Assembly::create( (int) $request->get_param( 'article_id' ), $request->get_param( 'qty' ), (string) $request->get_param( 'color' ) );

		return new WP_REST_Response( $result, $result['ok'] ? 201 : $result['status'] );
	}

	/**
	 * Ein Montageauftrag mit Teilen und Bestand.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function assembly_get( WP_REST_Request $request ) {
		$order = \Lindenweg16\Modules\Betrieb\WorkOrderRepository::find( (int) $request['id'] );

		if ( ! $order || 'montage' !== $order['kind'] ) {
			return new WP_Error( 'lw16_erp_montage', __( 'Montageauftrag nicht gefunden.', 'lindenweg16' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'order' => Assembly::view( $order, true ),
				'plan'  => Access::can( 'fertigung.workflow.plan' ),
			)
		);
	}

	/**
	 * Aktion auf einem Montageauftrag.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function assembly_act( WP_REST_Request $request ) {
		$action = (string) $request['action'];

		// Abbrechen vernichtet Arbeit: nur, wer auch Pläne freigibt.
		if ( 'abbrechen' === $action && ! Access::can( 'fertigung.workflow.plan' ) ) {
			return new WP_Error( 'lw16_forbidden', __( 'Abbrechen darf nur, wer Pläne freigeben darf.', 'lindenweg16' ), array( 'status' => 403 ) );
		}

		$result = Assembly::act( (int) $request['id'], $action, (array) $request->get_json_params() );
		$order  = \Lindenweg16\Modules\Betrieb\WorkOrderRepository::find( (int) $request['id'] );

		if ( $order ) {
			$result['order'] = Assembly::view( $order, true );
		}

		return new WP_REST_Response( $result, $result['ok'] ? 200 : $result['status'] );
	}

	/**
	 * Darf die Person das ERP nutzen?
	 *
	 * @return bool
	 */
	public function can_use() {
		return Access::has_any();
	}

	/**
	 * Darf die Person Rechte vergeben?
	 *
	 * @return bool
	 */
	public function can_manage_rights() {
		return Access::can( 'verwaltung.rechte' );
	}

	/**
	 * Start: Person, Navigation, Shop.
	 *
	 * @return WP_REST_Response
	 */
	public function bootstrap() {
		$user     = wp_get_current_user();
		$sections = Navigation::sections();
		$tree     = Access::sections();
		$out      = array();

		foreach ( $tree as $section => $tabs ) {
			$flat     = array();
			$tab_rows = array();

			foreach ( $tabs as $tab ) {
				$rows = array();

				foreach ( $tab['items'] as $item ) {
					$row = array(
						'id'    => $item['id'],
						'title' => $item['title'],
						'kind'  => $item['kind'],
						'tab'   => $tab['label'],
						'hint'  => Permissions::catalog()[ $item['perm'] ]['hint'],
					);

					// Eingebettet (legacy) bzw. als „klassische Ansicht“ (native mit Vorgänger).
					if ( 'legacy' === $item['kind'] || '' !== $item['alt'] ) {
						$row['path'] = $this->legacy_path( $item );
					}

					// Fehlt die WordPress-Rolle für die Shop-Seite, bleibt der
					// Eintrag sichtbar, erklärt aber, warum er nicht öffnet.
					if ( $item['wp_cap'] && ! current_user_can( $item['wp_cap'] ) ) {
						$row['locked'] = __( 'Dafür braucht dein WordPress-Konto die Rolle „Shop-Manager“.', 'lindenweg16' );
					}

					$rows[] = $row;
					$flat[] = $row;
				}

				$tab_rows[] = array( 'label' => $tab['label'], 'items' => $rows );
			}

			$out[] = array(
				'id'    => $section,
				'title' => $sections[ $section ][0],
				'icon'  => $sections[ $section ][1],
				'hint'  => $sections[ $section ][2],
				'rail'  => $sections[ $section ][3],
				'tabs'  => $tab_rows,
				'items' => $flat,
			);
		}

		return new WP_REST_Response(
			array(
				'user'  => array(
					'id'     => $user->ID,
					'name'   => $user->display_name,
					'email'  => $user->user_email,
					'avatar' => get_avatar_url( $user->ID, array( 'size' => 80 ) ),
					'full'   => Access::is_full(),
					'preset' => Access::is_full() ? 'vollzugriff' : Permissions::match_preset( Access::keys() ),
				),
				'perms' => Access::keys(),
				'nav'   => $out,
				'shop'  => array(
					'name' => get_bloginfo( 'name' ),
					'url'  => home_url( '/' ),
				),
				'now'   => time(),
			)
		);
	}

	/**
	 * Adresse einer eingebetteten Seite.
	 *
	 * @param array $item Eintrag.
	 * @return string Relativ zu wp-admin/.
	 */
	protected function legacy_path( array $item ) {
		// Ohne HPOS liegen Bestellungen noch als Beitragstyp vor.
		if ( 'shop-bestellungen' === $item['id'] && ! $this->hpos_enabled() ) {
			return 'edit.php?post_type=shop_order';
		}

		return Navigation::legacy_path( $item );
	}

	/**
	 * Nutzt der Shop die eigene Bestelltabelle?
	 *
	 * @return bool
	 */
	protected function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Akte eines Druckers.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function drucker_akte( WP_REST_Request $request ) {
		$file = Screens::printer_file( (string) $request['id'] );

		if ( null === $file ) {
			return new WP_REST_Response( array( 'message' => __( 'Diesen Drucker gibt es nicht (mehr).', 'lindenweg16' ) ), 404 );
		}

		$file['now'] = time();

		return new WP_REST_Response( $file );
	}

	/**
	 * Aufgaben dieser Person für „Heute“.
	 *
	 * @return WP_REST_Response
	 */
	public function aufgaben() {
		return new WP_REST_Response(
			array(
				'tasks' => Tasks::collect(),
				'mail'  => Tasks::unread_mail(),
			)
		);
	}

	/**
	 * Kennzahlen für die Startseite – nur, was die Person sehen darf.
	 *
	 * Die Zahlen stammen aus WooCommerce und werden fünf Minuten
	 * zwischengespeichert, damit ein Tablet, das alle paar Sekunden
	 * nachlädt, die Datenbank nicht belastet.
	 *
	 * @return WP_REST_Response
	 */
	public function dashboard() {
		$out = array(
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR',
			'tiles'    => array(),
			'orders'   => array(),
			'shop'     => function_exists( 'wc_get_orders' ),
		);

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_REST_Response( $out );
		}

		if ( Access::can( 'verkauf.shop_orders' ) ) {
			$shop = $this->shop_orders();

			$out['tiles'][] = array( 'id' => 'processing', 'label' => __( 'In Bearbeitung', 'lindenweg16' ), 'value' => $shop['processing'], 'type' => 'int', 'go' => 'shop-bestellungen' );
			$out['tiles'][] = array( 'id' => 'on-hold', 'label' => __( 'Wartet auf Zahlung', 'lindenweg16' ), 'value' => $shop['on_hold'], 'type' => 'int', 'go' => 'shop-bestellungen' );
			$out['orders']  = $shop['latest'];
		}

		if ( Access::can( 'finanzen.berichte' ) ) {
			$sales = $this->sales();

			$out['tiles'][] = array( 'id' => 'sales-today', 'label' => __( 'Umsatz heute', 'lindenweg16' ), 'value' => $sales['today'], 'type' => 'money', 'go' => 'berichte' );
			$out['tiles'][] = array( 'id' => 'sales-month', 'label' => __( 'Umsatz Monat', 'lindenweg16' ), 'value' => $sales['month'], 'type' => 'money', 'go' => 'berichte', 'note' => $sales['truncated'] ? __( 'Teilsumme, mehr als 1000 Bestellungen', 'lindenweg16' ) : '' );
		}

		if ( Access::can( 'lager.bestand' ) ) {
			$out['tiles'][] = array( 'id' => 'out-of-stock', 'label' => __( 'Nicht lieferbar', 'lindenweg16' ), 'value' => $this->out_of_stock(), 'type' => 'int', 'go' => 'lager' );
		}

		return new WP_REST_Response( $out );
	}

	/**
	 * Bestellzähler und letzte Bestellungen.
	 *
	 * @return array
	 */
	protected function shop_orders() {
		$cached = get_transient( 'lw16_erp_shop_orders' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$latest = array();

		foreach ( wc_get_orders( array( 'limit' => 6, 'orderby' => 'date', 'order' => 'DESC', 'status' => array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed' ) ) ) as $order ) {
			$created  = $order->get_date_created();
			$latest[] = array(
				'id'     => $order->get_id(),
				'number' => $order->get_order_number(),
				'name'   => trim( $order->get_billing_first_name() . ' ' . mb_substr( (string) $order->get_billing_last_name(), 0, 1 ) . '.' ),
				'total'  => (float) $order->get_total(),
				'status' => wc_get_order_status_name( $order->get_status() ),
				'code'   => $order->get_status(),
				'ts'     => $created ? $created->getTimestamp() : 0,
			);
		}

		$data = array(
			'processing' => (int) wc_orders_count( 'processing' ),
			'on_hold'    => (int) wc_orders_count( 'on-hold' ) + (int) wc_orders_count( 'pending' ),
			'latest'     => $latest,
		);

		set_transient( 'lw16_erp_shop_orders', $data, 5 * MINUTE_IN_SECONDS );

		return $data;
	}

	/**
	 * Umsatz heute und im Monat.
	 *
	 * @return array
	 */
	protected function sales() {
		$cached = get_transient( 'lw16_erp_sales' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$limit       = 1000;
		$tz          = wp_timezone();
		$start       = ( new \DateTimeImmutable( 'today', $tz ) )->getTimestamp();
		$month_start = ( new \DateTimeImmutable( 'first day of this month midnight', $tz ) )->getTimestamp();
		$month       = wc_get_orders(
			array(
				'limit'        => $limit,
				'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'date_created' => '>=' . $month_start,
			)
		);
		$today       = 0.0;
		$total       = 0.0;

		foreach ( $month as $order ) {
			$total   += (float) $order->get_total();
			$created  = $order->get_date_created();
			if ( $created && $created->getTimestamp() >= $start ) {
				$today += (float) $order->get_total();
			}
		}

		$data = array(
			'today'     => round( $today, 2 ),
			'month'     => round( $total, 2 ),
			'truncated' => count( $month ) >= $limit,
		);

		set_transient( 'lw16_erp_sales', $data, 5 * MINUTE_IN_SECONDS );

		return $data;
	}

	/**
	 * Anzahl nicht lieferbarer Produkte.
	 *
	 * @return int
	 */
	protected function out_of_stock() {
		$cached = get_transient( 'lw16_erp_oos' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = count( wc_get_products( array( 'limit' => -1, 'return' => 'ids', 'status' => 'publish', 'stock_status' => 'outofstock' ) ) );

		set_transient( 'lw16_erp_oos', $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Personen mit ihren Rechten, dazu Katalog und Vorlagen.
	 *
	 * @return WP_REST_Response
	 */
	public function users() {
		$areas   = Navigation::areas();
		$groups  = array();
		$catalog = Permissions::catalog();

		foreach ( Permissions::groups() as $id => $title ) {
			$rows = array();

			foreach ( $catalog as $key => $def ) {
				if ( $def['group'] === $id ) {
					$rows[] = array(
						'key'      => $key,
						'label'    => $def['label'],
						'hint'     => $def['hint'],
						'scope'    => $def['scope'],
						'requires' => $def['requires'],
						'mine'     => Access::can( $key ),
					);
				}
			}

			$groups[] = array(
				'id'    => $id,
				'title' => $title,
				'icon'  => $areas[ $id ][1],
				'perms' => $rows,
			);
		}

		$nav = array();
		foreach ( Navigation::items() as $item ) {
			$nav[] = array(
				'id'    => $item['id'],
				'title' => $item['title'],
				'area'  => $item['area'],
				'perm'  => $item['perm'],
			);
		}

		$presets = array();
		foreach ( Permissions::presets() as $id => $preset ) {
			$presets[] = array(
				'id'    => $id,
				'label' => $preset['label'],
				'hint'  => $preset['hint'],
				'keys'  => Permissions::normalize( $preset['keys'] ),
			);
		}

		$people = array();
		foreach ( get_users( array( 'orderby' => 'display_name', 'number' => 200 ) ) as $user ) {
			$full  = Access::is_full( $user->ID );
			$keys  = Access::keys( $user->ID );
			$roles = array();

			$names = wp_roles()->get_names();
			foreach ( (array) $user->roles as $role ) {
				$roles[] = isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role;
			}

			$people[] = array(
				'id'     => $user->ID,
				'name'   => $user->display_name,
				'email'  => $user->user_email,
				'avatar' => get_avatar_url( $user->ID, array( 'size' => 80 ) ),
				'roles'  => $roles,
				'full'   => $full,
				'keys'   => $full ? array() : $keys,
				'stored' => is_array( get_user_meta( $user->ID, Access::META, true ) ),
				'preset' => $full ? 'vollzugriff' : Permissions::match_preset( $keys ),
				'self'   => get_current_user_id() === $user->ID,
				'shop'   => user_can( $user->ID, 'edit_shop_orders' ),
			);
		}

		return new WP_REST_Response(
			array(
				'groups'  => $groups,
				'nav'     => $nav,
				'presets' => $presets,
				'people'  => $people,
				'newUser' => admin_url( 'user-new.php' ),
			)
		);
	}

	/**
	 * Rechte einer Person speichern.
	 *
	 * Schutz vor Rechteausweitung und Selbstaussperrung:
	 * - Nur Rechte, die die speichernde Person selbst hat, lassen sich
	 *   setzen oder entziehen; alle anderen bleiben, wie sie waren.
	 * - Das eigene „Rechte vergeben“ lässt sich nicht selbst entziehen.
	 * - Personen mit Vollzugriff sind hier nicht änderbar.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_permissions( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$user = get_userdata( $id );

		if ( ! $user ) {
			return new WP_Error( 'lw16_erp_user', __( 'Person nicht gefunden.', 'lindenweg16' ), array( 'status' => 404 ) );
		}

		if ( Access::is_full( $id ) ) {
			return new WP_Error( 'lw16_erp_full', __( 'Vollzugriff lässt sich hier nicht beschneiden.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		$before = Access::keys( $id );
		$wanted = Permissions::normalize( array_map( 'strval', (array) $request['keys'] ) );
		$mine   = Access::keys();
		$final  = array();

		// Was die speichernde Person nicht selbst hat, bleibt unverändert.
		foreach ( Permissions::grantable_keys() as $key ) {
			$in_before = in_array( $key, $before, true );
			$in_wanted = in_array( $key, $wanted, true );

			if ( Access::is_full() || in_array( $key, $mine, true ) ) {
				if ( $in_wanted ) {
					$final[] = $key;
				}
			} elseif ( $in_before ) {
				$final[] = $key;
			}
		}

		if ( get_current_user_id() === $id && ! in_array( 'verwaltung.rechte', Permissions::normalize( $final ), true ) ) {
			return new WP_Error( 'lw16_erp_self', __( 'Du kannst dir das Recht zum Vergeben von Rechten nicht selbst entziehen.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		$saved = Access::save( $id, $final );

		if ( false === $saved ) {
			return new WP_Error( 'lw16_erp_save', __( 'Speichern nicht möglich.', 'lindenweg16' ), array( 'status' => 500 ) );
		}

		AuditLog::record(
			'erp_rechte_geaendert',
			'user',
			$id,
			array(
				'hinzu'  => array_values( array_diff( $saved, $before ) ),
				'entfernt' => array_values( array_diff( $before, $saved ) ),
			)
		);

		return new WP_REST_Response(
			array(
				'keys'   => $saved,
				'preset' => Permissions::match_preset( $saved ),
			)
		);
	}
}
