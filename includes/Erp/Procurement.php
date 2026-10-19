<?php
/**
 * Einkauf im Workflow: Bestellen – Unterwegs – Eingegangen.
 *
 * Die Karten „Bestellen“ kommen aus dem bestehenden Beschaffungsalarm
 * (Reichweite gegen Lieferzeit plus Puffer, auch für Filament). Eine Karte
 * verschwindet, sobald eine offene Bestellung zum selben Teil existiert.
 * „Bestellt“ legt die Bestellung an, „Eingegangen“ bucht den Bestand: bei
 * Artikeln als Bewegung im Lagerjournal, bei Kleinteilen und Verbrauchsmaterial
 * als Aufschlag, bei Filament als neue Charge (Rolle).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\ComponentRepository;
use Lindenweg16\Modules\Betrieb\ProcurementAlert;
use Lindenweg16\Modules\Betrieb\Purchase;
use Lindenweg16\Modules\Betrieb\PurchaseRepository;
use Lindenweg16\Modules\Betrieb\SupplyRepository;

/**
 * Daten und Aktionen des Einkaufs-Workflows.
 */
class Procurement {

	/**
	 * Alarmart (ProcurementAlert) auf Bestellart (Purchase) abbilden – reine Regel.
	 *
	 * @param string $kind Alarmart.
	 * @return string Bestellart oder leer.
	 */
	public static function type_for_kind( $kind ) {
		$map = array(
			'artikel'            => 'article',
			'komponente'         => 'component',
			'verbrauchsmaterial' => 'supply',
			'filament'           => 'filament',
		);

		return isset( $map[ $kind ] ) ? $map[ $kind ] : '';
	}

	/**
	 * Einheit zur Bestellart – reine Regel.
	 *
	 * @param string $type Bestellart.
	 * @return string
	 */
	public static function unit( $type ) {
		return 'filament' === $type ? 'kg' : 'Stück';
	}

	/**
	 * Verspätung einer offenen Bestellung in Tagen – reine Regel.
	 *
	 * @param string $expected_on Erwartet am (Y-m-d) oder leer.
	 * @param string $today       Heute (Y-m-d).
	 * @return int 0, wenn nicht überfällig oder ohne Termin.
	 */
	public static function late_days( $expected_on, $today ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $expected_on ) || (string) $expected_on >= (string) $today ) {
			return 0;
		}

		return (int) floor( ( strtotime( $today . ' UTC' ) - strtotime( $expected_on . ' UTC' ) ) / 86400 );
	}

	/**
	 * Bezeichnung eines bestellten Teils.
	 *
	 * @param array $purchase Bestellung.
	 * @return string
	 */
	public static function label_of( array $purchase ) {
		$id = (int) $purchase['item_id'];

		switch ( $purchase['item_type'] ) {
			case 'filament':
				return sprintf( /* translators: %s: Farbe. */ __( 'Filament %s', 'lindenweg16' ), ColorRegistry::label( (string) $purchase['item_ref'] ) );
			case 'article':
				$a = ArticleRepository::find( $id );
				return $a ? $a['sku'] . ' – ' . $a['name'] : '#' . $id;
			case 'supply':
				$s = SupplyRepository::find( $id );
				return $s ? $s['name'] : '#' . $id;
			default:
				$c = ComponentRepository::find( $id );
				return $c ? $c['code'] . ' – ' . $c['name'] : '#' . $id;
		}
	}

	/**
	 * Eine Bestellung als Karte.
	 *
	 * @param array  $p     Bestellung.
	 * @param string $today Heute.
	 * @return array
	 */
	protected static function purchase_entry( array $p, $today ) {
		return array(
			'id'          => (int) $p['id'],
			'type'        => $p['item_type'],
			'item_ref'    => isset( $p['item_ref'] ) ? (string) $p['item_ref'] : '',
			'label'       => self::label_of( $p ),
			'qty'         => (float) $p['qty'],
			'unit'        => self::unit( $p['item_type'] ),
			'supplier'    => (string) $p['supplier'],
			'order_ref'   => (string) $p['order_ref'],
			'ordered_on'  => $p['ordered_on'],
			'expected_on' => (string) $p['expected_on'],
			'late'        => 'bestellt' === $p['status'] ? self::late_days( (string) $p['expected_on'], $today ) : 0,
			'received_on' => (string) $p['received_on'],
			'received'    => null === $p['received_qty'] ? null : (float) $p['received_qty'],
		);
	}

	/**
	 * Offene Bestellungen zu einem Teil – für den Wareneingang am Scanner.
	 *
	 * @param string $type Art (article, component, supply).
	 * @param int    $id   Teil.
	 * @return array Bestellungen wie im Board (Karten „Unterwegs“), älteste zuerst.
	 */
	public static function open_for( $type, $id ) {
		if ( ! in_array( $type, array( 'article', 'component', 'supply' ), true ) || (int) $id <= 0 ) {
			return array();
		}

		$today = gmdate( 'Y-m-d' );
		$out   = array();

		foreach ( array_reverse( PurchaseRepository::for_item( $type, (int) $id ) ) as $p ) {
			if ( 'bestellt' === $p['status'] ) {
				$out[] = self::purchase_entry( $p, $today );
			}
		}

		return $out;
	}

	/**
	 * Das Board.
	 *
	 * @return array {order, open, done}
	 */
	public static function board() {
		$today = gmdate( 'Y-m-d' );
		$open  = array();
		$done  = array();
		$known = array();
		$since = gmdate( 'Y-m-d', time() - 14 * DAY_IN_SECONDS );

		foreach ( PurchaseRepository::all() as $p ) {
			if ( 'bestellt' === $p['status'] ) {
				$open[]  = self::purchase_entry( $p, $today );
				$known[] = $p['item_type'] . ':' . (int) $p['item_id'] . ':' . (string) $p['item_ref'];
			} elseif ( 'geliefert' === $p['status'] && (string) $p['received_on'] >= $since ) {
				$done[] = self::purchase_entry( $p, $today );
			}
		}

		$order = array();

		foreach ( ProcurementAlert::all() as $alert ) {
			$type = self::type_for_kind( $alert['kind'] );

			if ( '' === $type ) {
				continue;
			}

			$ref = 'filament' === $type ? (string) $alert['color'] : '';
			$key = $type . ':' . (int) $alert['id'] . ':' . $ref;

			if ( in_array( $key, $known, true ) ) {
				continue;
			}

			$last = PurchaseRepository::last_for( $type, (int) $alert['id'], $ref );

			$order[] = array(
				'type'      => $type,
				'kind'      => $alert['kind_label'],
				'item_id'   => (int) $alert['id'],
				'item_ref'  => $ref,
				'label'     => $alert['label'],
				'unit'      => self::unit( $type ),
				'stock'     => (float) $alert['stock'],
				'coverage'  => null === $alert['coverage_days'] ? null : round( (float) $alert['coverage_days'], 1 ),
				'threshold' => (int) $alert['threshold_days'],
				'qty'       => (float) $alert['order_qty'],
				'supplier'  => $last ? (string) $last['supplier'] : '',
				'price'     => $last ? (float) $last['unit_price_net'] : 0.0,
			);
		}

		// Die dringendsten zuerst: kleinste Reichweite oben.
		usort(
			$order,
			function ( $a, $b ) {
				$ca = null === $a['coverage'] ? PHP_INT_MAX : $a['coverage'];
				$cb = null === $b['coverage'] ? PHP_INT_MAX : $b['coverage'];

				return $ca <=> $cb;
			}
		);

		return array( 'order' => $order, 'open' => $open, 'done' => $done );
	}

	/**
	 * Bestellung anlegen.
	 *
	 * @param array $input type, item_id, item_ref, qty, supplier, unit_price_net, expected_on, order_ref.
	 * @return array {ok, message}
	 */
	public static function place( array $input ) {
		$result = PurchaseRepository::create( $input );

		if ( ! $result['success'] ) {
			return array( 'ok' => false, 'message' => implode( ' ', $result['errors'] ) );
		}

		return array( 'ok' => true, 'message' => __( 'Als bestellt vermerkt. Die Karte wartet jetzt unter „Unterwegs“.', 'lindenweg16' ), 'id' => $result['id'] );
	}

	/**
	 * Wareneingang buchen.
	 *
	 * @param int   $id    Bestellung.
	 * @param float $qty   Gelieferte Menge.
	 * @param array $extra Bei Filament lot_code, material.
	 * @return array {ok, message}
	 */
	public static function receive( $id, $qty, array $extra = array() ) {
		$result = PurchaseRepository::receive( (int) $id, $qty, '', $extra );

		return array( 'ok' => (bool) $result['success'], 'message' => $result['message'] );
	}
}
