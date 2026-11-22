<?php
/**
 * Kommissionieren: der Schritt zwischen „bezahlt“ und „packen“.
 *
 * Ablauf einer Shop-Bestellung: bezahlt (in Bearbeitung) → kommissioniert
 * → Versendet (abgeschlossen). Kommissioniert heißt: Die Ware ist geholt,
 * Position für Position gescannt, und der Lagerabgang ist gebucht. Der
 * Status ist ein eigener Bestellstatus des Shops („wc-kommissioniert“).
 *
 * Die Regeln (Codes abgleichen, Zählstand fortschreiben, „ist alles da?“)
 * sind reine Funktionen ohne WooCommerce und damit testbar. Der Zählstand
 * liegt an der Bestellung, nicht im Browser – so sehen Handy und Packtisch
 * denselben Stand.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Eigener Bestellstatus und Scan-Regeln.
 */
class OrderPicking {

	/**
	 * Status ohne Präfix „wc-“.
	 */
	const STATUS = 'kommissioniert';

	/**
	 * Zählstand an der Bestellung: Positions-ID → gescannte Menge.
	 */
	const META_SCANS = '_lw16_pick_scans';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status' ) );
		// Kommissioniert ist bezahlt und zählt in Auswertungen wie „in Bearbeitung“.
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'add_paid' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'add_paid' ) );
	}

	/**
	 * Status bei WordPress anmelden (für die klassische Bestellliste).
	 */
	public static function register_status() {
		register_post_status(
			'wc-' . self::STATUS,
			array(
				'label'                     => _x( 'Kommissioniert', 'Bestellstatus', 'lindenweg16' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: Anzahl. */
				'label_count'               => _n_noop( 'Kommissioniert <span class="count">(%s)</span>', 'Kommissioniert <span class="count">(%s)</span>', 'lindenweg16' ),
			)
		);
	}

	/**
	 * Status in die Liste der Shop-Status einreihen: direkt nach „in Bearbeitung“.
	 *
	 * @param array $statuses Schlüssel → Name.
	 * @return array
	 */
	public static function add_status( $statuses ) {
		$out = array();

		foreach ( (array) $statuses as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'wc-processing' === $key ) {
				$out[ 'wc-' . self::STATUS ] = _x( 'Kommissioniert', 'Bestellstatus', 'lindenweg16' );
			}
		}

		if ( ! isset( $out[ 'wc-' . self::STATUS ] ) ) {
			$out[ 'wc-' . self::STATUS ] = _x( 'Kommissioniert', 'Bestellstatus', 'lindenweg16' );
		}

		return $out;
	}

	/**
	 * Status zu den „bezahlt“-Status legen (ohne Präfix).
	 *
	 * @param array $statuses Status.
	 * @return array
	 */
	public static function add_paid( $statuses ) {
		$statuses   = (array) $statuses;
		$statuses[] = self::STATUS;

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Codes, mit denen eine Position gescannt werden darf – normalisiert.
	 *
	 * @param string[] $codes Artikelnummer, EAN/GTIN usw.
	 * @return string[]
	 */
	public static function normalize_codes( array $codes ) {
		$out = array();

		foreach ( $codes as $code ) {
			$code = strtoupper( trim( (string) $code ) );

			if ( '' !== $code ) {
				$out[] = $code;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Einen Scan gegen die Positionen halten – reine Regel.
	 *
	 * Gesucht wird die erste Position mit diesem Code, die noch nicht
	 * vollständig ist; sind alle passenden Positionen voll, ist es ein
	 * Zuviel (eine Ware zu viel im Korb ist ein Fehler, den man sehen soll).
	 *
	 * @param array  $lines Positionen: id, qty, codes (normalisiert).
	 * @param array  $scans Positions-ID → gescannte Menge.
	 * @param string $code  Gescannter Code.
	 * @return array {result: ok|unknown|too_many, id: int, scans: array}
	 */
	public static function apply_scan( array $lines, array $scans, $code ) {
		$code = strtoupper( trim( (string) $code ) );

		if ( '' === $code ) {
			return array( 'result' => 'unknown', 'id' => 0, 'scans' => $scans );
		}

		$matching = array();

		foreach ( $lines as $line ) {
			if ( in_array( $code, (array) $line['codes'], true ) ) {
				$matching[] = $line;
			}
		}

		if ( ! $matching ) {
			return array( 'result' => 'unknown', 'id' => 0, 'scans' => $scans );
		}

		foreach ( $matching as $line ) {
			$have = (int) ( $scans[ $line['id'] ] ?? 0 );

			if ( $have < (int) $line['qty'] ) {
				$scans[ $line['id'] ] = $have + 1;

				return array( 'result' => 'ok', 'id' => (int) $line['id'], 'scans' => $scans );
			}
		}

		return array( 'result' => 'too_many', 'id' => (int) $matching[0]['id'], 'scans' => $scans );
	}

	/**
	 * Eine Position ohne scanbaren Code von Hand als geholt abhaken.
	 *
	 * Positionen mit Code lassen sich nicht abhaken – sie müssen gescannt
	 * werden, sonst wäre der Scan nur Zierde.
	 *
	 * @param array $lines  Positionen.
	 * @param array $scans  Zählstand.
	 * @param int   $id     Positions-ID.
	 * @return array {ok, scans}
	 */
	public static function confirm_manual( array $lines, array $scans, $id ) {
		foreach ( $lines as $line ) {
			if ( (int) $line['id'] !== (int) $id ) {
				continue;
			}

			if ( ! empty( $line['codes'] ) ) {
				return array( 'ok' => false, 'scans' => $scans );
			}

			$scans[ $line['id'] ] = (int) $line['qty'];

			return array( 'ok' => true, 'scans' => $scans );
		}

		return array( 'ok' => false, 'scans' => $scans );
	}

	/**
	 * Ist alles geholt?
	 *
	 * @param array $lines Positionen.
	 * @param array $scans Zählstand.
	 * @return bool Wahr, wenn jede Position ihre Menge erreicht hat (und es Positionen gibt).
	 */
	public static function is_complete( array $lines, array $scans ) {
		if ( ! $lines ) {
			return false;
		}

		foreach ( $lines as $line ) {
			if ( (int) ( $scans[ $line['id'] ] ?? 0 ) < (int) $line['qty'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Positionen einer Bestellung mit Codes und Zählstand.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array Liste aus id, name, sku, qty, codes, scanned.
	 */
	public static function lines( $order ) {
		$scans = self::scans( $order );
		$out   = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$codes   = array();
			$sku     = '';

			if ( $product ) {
				$sku     = (string) $product->get_sku();
				$codes[] = $sku;

				if ( method_exists( $product, 'get_global_unique_id' ) ) {
					$codes[] = (string) $product->get_global_unique_id();
				}
			}

			$id    = (int) $item->get_id();
			$out[] = array(
				'id'      => $id,
				'name'    => (string) $item->get_name(),
				'sku'     => $sku,
				'qty'     => (int) $item->get_quantity(),
				'codes'   => self::normalize_codes( $codes ),
				'scanned' => min( (int) $item->get_quantity(), (int) ( $scans[ $id ] ?? 0 ) ),
			);
		}

		return $out;
	}

	/**
	 * Zählstand einer Bestellung.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array Positions-ID → Menge.
	 */
	public static function scans( $order ) {
		$raw = $order->get_meta( self::META_SCANS );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $id => $n ) {
			$out[ (int) $id ] = (int) $n;
		}

		return $out;
	}

	/**
	 * Zählstand speichern.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @param array     $scans Positions-ID → Menge.
	 */
	public static function save_scans( $order, array $scans ) {
		if ( $scans ) {
			$order->update_meta_data( self::META_SCANS, $scans );
		} else {
			$order->delete_meta_data( self::META_SCANS );
		}

		$order->save();
	}
}
