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
				'args'                => array( 'filter' => array( 'type' => 'string', 'required' => false ) ),
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
			'/erp/versand/label/(?P<id>\d+)/storno',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'versand_storno' ),
				'permission_callback' => $this->needs( array( 'verkauf.versand', 'verkauf.versand.etikett' ) ),
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
	}

	/**
	 * Berechtigungsprüfung für eine Route: alle genannten Rechte nötig.
	 *
	 * @param string[] $perms Rechte.
	 * @return callable
	 */
	protected function needs( array $perms ) {
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
		$data           = Screens::shipping( sanitize_key( (string) $request->get_param( 'filter' ) ) );
		$data['label']  = Access::can( 'verkauf.versand.etikett' );

		return new WP_REST_Response( $data );
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
		$user   = wp_get_current_user();
		$areas  = Navigation::areas();
		$tree   = Access::navigation();
		$out    = array();
		$locked = array();

		foreach ( $tree as $area => $items ) {
			$rows = array();

			foreach ( $items as $item ) {
				$row = array(
					'id'    => $item['id'],
					'title' => $item['title'],
					'kind'  => $item['kind'],
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
			}

			$out[] = array(
				'id'    => $area,
				'title' => $areas[ $area ][0],
				'icon'  => $areas[ $area ][1],
				'hint'  => $areas[ $area ][2],
				'items' => $rows,
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
