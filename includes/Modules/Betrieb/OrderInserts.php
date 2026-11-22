<?php
/**
 * Beileger: was zu einer Bestellung in den Karton muss.
 *
 * Beispiele: der Danke-Zettel (immer, mit dem Freunde-werben-Freunde-Code des
 * Kunden), die Anleitung und das Montageset, sobald ein Basismodul dabei ist,
 * die Modulanleitung einmal, egal wie viele Module bestellt wurden.
 *
 * Die Regeln sind einstellbar (Seite Versand → Beileger). Sie berechnen aus
 * den Positionen einer Bestellung eine Liste mit Menge und Pflicht; beim
 * Kommissionieren muss jede Pflicht-Zeile abgehakt werden. Das Rechnen ist
 * reine Regel ohne WooCommerce und damit testbar.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Regeln für Beileger.
 */
class OrderInserts {

	/**
	 * Einstellungsschlüssel der Regeln.
	 */
	const SETTING = 'betrieb_beilagen_regeln';

	/**
	 * Abgehakte Beileger an der Bestellung (Liste von Regel-IDs).
	 */
	const META_CHECKED = '_lw16_pick_beilagen';

	/**
	 * Höchstzahl Regeln.
	 */
	const MAX_RULES = 50;

	/**
	 * Auswahl „wenn …“.
	 *
	 * @return array Schlüssel => Text.
	 */
	public static function conditions() {
		return array(
			'immer' => __( 'immer', 'lindenweg16' ),
			'basis' => __( 'ein Basismodul dabei ist', 'lindenweg16' ),
			'modul' => __( 'ein Modul dabei ist', 'lindenweg16' ),
			'sku'   => __( 'diese Artikelnummer dabei ist', 'lindenweg16' ),
		);
	}

	/**
	 * Voreinstellung, solange niemand etwas gespeichert hat.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			array( 'id' => 'danke', 'name' => __( 'Danke-Zettel', 'lindenweg16' ), 'wenn' => 'immer', 'sku' => '', 'menge' => 'einmal', 'pflicht' => true, 'code' => true ),
			array( 'id' => 'bsm-anleitung', 'name' => __( 'Anleitung Basismodul', 'lindenweg16' ), 'wenn' => 'basis', 'sku' => '', 'menge' => 'einmal', 'pflicht' => true, 'code' => false ),
			array( 'id' => 'montageset', 'name' => __( 'Montageset', 'lindenweg16' ), 'wenn' => 'basis', 'sku' => '', 'menge' => 'je_stueck', 'pflicht' => true, 'code' => false ),
			array( 'id' => 'modul-anleitung', 'name' => __( 'Anleitung Module', 'lindenweg16' ), 'wenn' => 'modul', 'sku' => '', 'menge' => 'einmal', 'pflicht' => true, 'code' => false ),
		);
	}

	/**
	 * Die gültigen Regeln.
	 *
	 * @return array
	 */
	public static function rules() {
		$saved = Settings::get( self::SETTING, null );

		return is_array( $saved ) ? self::sanitize( $saved ) : self::defaults();
	}

	/**
	 * Regeln speichern.
	 *
	 * @param array $raw Rohe Eingabe.
	 * @return array Die gespeicherten, bereinigten Regeln.
	 */
	public static function save( array $raw ) {
		$rules = self::sanitize( $raw );

		Settings::update( array( self::SETTING => $rules ) );

		return $rules;
	}

	/**
	 * Eingabe bereinigen – reine Regel.
	 *
	 * Zeilen ohne Namen entfallen, unbekannte Werte werden zu den
	 * sicheren Voreinstellungen, doppelte Kennungen neu vergeben.
	 *
	 * @param array $raw Rohe Regeln.
	 * @return array
	 */
	public static function sanitize( array $raw ) {
		$out  = array();
		$seen = array();

		foreach ( array_slice( array_values( $raw ), 0, self::MAX_RULES ) as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $row['name'] ?? '' ) ) ) );

			if ( '' === $name ) {
				continue;
			}

			$wenn = isset( $row['wenn'], self::conditions()[ $row['wenn'] ] ) ? (string) $row['wenn'] : 'immer';
			$id   = strtolower( preg_replace( '/[^a-z0-9\-]/i', '', (string) ( $row['id'] ?? '' ) ) );

			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = 'r' . ( $i + 1 ) . substr( md5( $name . $i ), 0, 4 );
			}

			$seen[ $id ] = true;

			$out[] = array(
				'id'      => $id,
				'name'    => mb_substr( $name, 0, 80 ),
				'wenn'    => $wenn,
				'sku'     => 'sku' === $wenn ? mb_substr( trim( wp_strip_all_tags( (string) ( $row['sku'] ?? '' ) ) ), 0, 200 ) : '',
				'menge'   => isset( $row['menge'] ) && 'je_stueck' === $row['menge'] ? 'je_stueck' : 'einmal',
				'pflicht' => ! empty( $row['pflicht'] ),
				'code'    => ! empty( $row['code'] ),
			);
		}

		return $out;
	}

	/**
	 * Aus Regeln und Positionen die Beileger einer Bestellung berechnen – reine Regel.
	 *
	 * @param array $rules Regeln.
	 * @param array $lines Positionen: role (basis|modul|leer), sku, qty.
	 * @return array Liste aus id, name, qty, required, code.
	 */
	public static function compute( array $rules, array $lines ) {
		$total = 0;
		$basis = 0;
		$modul = 0;

		foreach ( $lines as $line ) {
			$qty    = max( 0, (int) $line['qty'] );
			$total += $qty;

			if ( 'basis' === ( $line['role'] ?? '' ) ) {
				$basis += $qty;
			} elseif ( 'modul' === ( $line['role'] ?? '' ) ) {
				$modul += $qty;
			}
		}

		$out = array();

		foreach ( $rules as $rule ) {
			switch ( $rule['wenn'] ) {
				case 'basis':
					$count = $basis;
					break;
				case 'modul':
					$count = $modul;
					break;
				case 'sku':
					$count = 0;
					$wanted = array_filter( array_map( 'strtoupper', array_map( 'trim', explode( ',', (string) $rule['sku'] ) ) ), 'strlen' );

					foreach ( $lines as $line ) {
						if ( $wanted && in_array( strtoupper( trim( (string) ( $line['sku'] ?? '' ) ) ), $wanted, true ) ) {
							$count += max( 0, (int) $line['qty'] );
						}
					}
					break;
				default:
					$count = $total > 0 ? $total : 1;
			}

			if ( $count < 1 ) {
				continue;
			}

			$out[] = array(
				'id'       => (string) $rule['id'],
				'name'     => (string) $rule['name'],
				'qty'      => 'je_stueck' === $rule['menge'] ? $count : 1,
				'required' => ! empty( $rule['pflicht'] ),
				'code'     => ! empty( $rule['code'] ),
			);
		}

		return $out;
	}

	/**
	 * Fehlende Pflicht-Beileger – reine Regel.
	 *
	 * @param array    $items   Ergebnis von compute().
	 * @param string[] $checked Abgehakte Regel-IDs.
	 * @return string[] Namen der Pflicht-Beileger, die noch fehlen.
	 */
	public static function missing( array $items, array $checked ) {
		$out = array();

		foreach ( $items as $item ) {
			if ( ! empty( $item['required'] ) && ! in_array( $item['id'], $checked, true ) ) {
				$out[] = $item['name'];
			}
		}

		return $out;
	}

	/**
	 * Positionen einer Bestellung mit Rolle (Basismodul, Modul) und Artikelnummer.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array
	 */
	public static function lines_for_order( $order ) {
		$roles = array();

		if ( class_exists( '\Lindenweg16\Modules\Configurator\PartRepository' ) ) {
			try {
				$ctx = \Lindenweg16\Modules\Configurator\PartRepository::context( false );

				foreach ( (array) $ctx['parts'] as $part ) {
					if ( ! empty( $part['wc_product_id'] ) ) {
						$roles[ (int) $part['wc_product_id'] ] = (string) $part['role'];
					}
				}
			} catch ( \Throwable $e ) {
				$roles = array();
			}
		}

		$lines = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			$lines[] = array(
				'role' => $roles[ (int) $item->get_product_id() ] ?? '',
				'sku'  => $product ? (string) $product->get_sku() : '',
				'qty'  => (int) $item->get_quantity(),
			);
		}

		return $lines;
	}

	/**
	 * Abgehakte Beileger einer Bestellung.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return string[]
	 */
	public static function checked( $order ) {
		$raw = $order->get_meta( self::META_CHECKED );

		return is_array( $raw ) ? array_values( array_map( 'strval', $raw ) ) : array();
	}

	/**
	 * Beileger einer Bestellung mit Haken-Stand.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array Einträge aus compute() plus checked.
	 */
	public static function for_order( $order ) {
		$checked = self::checked( $order );
		$items   = self::compute( self::rules(), self::lines_for_order( $order ) );

		foreach ( $items as $i => $item ) {
			$items[ $i ]['checked'] = in_array( $item['id'], $checked, true );
		}

		return $items;
	}

	/**
	 * Haken setzen oder lösen.
	 *
	 * @param \WC_Order $order   Bestellung.
	 * @param string    $id      Regel-ID.
	 * @param bool      $checked Abgehakt?
	 * @return bool Ob es die Regel für diese Bestellung gibt.
	 */
	public static function tick( $order, $id, $checked ) {
		$ids = array();

		foreach ( self::for_order( $order ) as $item ) {
			$ids[] = $item['id'];
		}

		if ( ! in_array( $id, $ids, true ) ) {
			return false;
		}

		$now = self::checked( $order );
		$now = array_values( array_diff( $now, array( $id ) ) );

		if ( $checked ) {
			$now[] = $id;
		}

		if ( $now ) {
			$order->update_meta_data( self::META_CHECKED, $now );
		} else {
			$order->delete_meta_data( self::META_CHECKED );
		}

		$order->save();

		return true;
	}
}
