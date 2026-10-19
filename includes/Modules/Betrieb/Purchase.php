<?php
/**
 * Nachbestellungen bei Lieferanten – die reine Logik.
 *
 * Eine Bestellung ist ein Vorgang mit Verlauf: heute rausgeschickt,
 * übermorgen erwartet, nächste Woche da. Deshalb eine eigene Tabelle und
 * kein Feld am Kleinteil – es können mehrere gleichzeitig unterwegs sein,
 * und was gestern bestellt wurde, soll morgen noch nachlesbar sein.
 *
 * Was diese Klasse ausdrücklich **nicht** tut: den Bestand ändern. Das
 * passiert erst beim Wareneingang, und zwar an einer Stelle. Eine
 * Bestellung ist eine Absicht, keine Ware.
 *
 * Frei von Datenbank und WordPress-Umgebung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Zustände, Fristen und Prüfungen einer Nachbestellung.
 */
class Purchase {

	/**
	 * Was bestellt werden kann.
	 */
	const TYPES = array( 'component', 'supply', 'article', 'filament' );

	/**
	 * Die Zustände einer Bestellung.
	 */
	const STATES = array( 'bestellt', 'geliefert', 'storniert' );

	/**
	 * Zustände, bei denen noch Ware unterwegs ist.
	 */
	const OPEN_STATES = array( 'bestellt' );

	/**
	 * Beschriftungen der Zustände.
	 *
	 * @return array
	 */
	public static function state_labels() {
		return array(
			'bestellt'  => __( 'unterwegs', 'lindenweg16' ),
			'geliefert' => __( 'geliefert', 'lindenweg16' ),
			'storniert' => __( 'storniert', 'lindenweg16' ),
		);
	}

	/**
	 * Beschriftungen der Bestellarten.
	 *
	 * @return array
	 */
	public static function type_labels() {
		return array(
			'component' => __( 'Komponente', 'lindenweg16' ),
			'supply'    => __( 'Verbrauchsmaterial', 'lindenweg16' ),
			'article'   => __( 'Zukaufartikel', 'lindenweg16' ),
			'filament'  => __( 'Filament', 'lindenweg16' ),
		);
	}

	/**
	 * Ist noch Ware unterwegs?
	 *
	 * @param array $purchase Bestellung.
	 * @return bool
	 */
	public static function is_open( array $purchase ) {
		return in_array( (string) $purchase['status'], self::OPEN_STATES, true );
	}

	/**
	 * Voraussichtlicher Liefertermin aus Bestelldatum und Lieferzeit.
	 *
	 * @param string $ordered_on Bestelldatum (Y-m-d).
	 * @param int    $lead_days  Lieferzeit in Tagen.
	 * @return string
	 */
	public static function expected_date( $ordered_on, $lead_days ) {
		// Erst die Form prüfen: strtotime('') liefert den heutigen Tag statt
		// false, und aus einer fehlenden Angabe würde sonst ein Termin.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ordered_on ) ) {
			return '';
		}

		$zeit = strtotime( (string) $ordered_on . ' 12:00:00 UTC' );

		if ( ! $zeit ) {
			return '';
		}

		return gmdate( 'Y-m-d', $zeit + max( 0, (int) $lead_days ) * DAY_IN_SECONDS );
	}

	/**
	 * Wie viele Tage eine Bestellung überfällig ist.
	 *
	 * Negativ heisst: noch nicht fällig. Ohne erwarteten Termin gibt es
	 * keine Überfälligkeit – geschätzt wird hier nichts.
	 *
	 * @param array  $purchase Bestellung.
	 * @param string $heute    Stichtag (Y-m-d).
	 * @return int|null
	 */
	public static function overdue_days( array $purchase, $heute ) {
		if ( empty( $purchase['expected_on'] ) || ! self::is_open( $purchase ) ) {
			return null;
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $heute ) ) {
			return null;
		}

		$erwartet = strtotime( (string) $purchase['expected_on'] . ' 12:00:00 UTC' );
		$stichtag = strtotime( (string) $heute . ' 12:00:00 UTC' );

		if ( ! $erwartet || ! $stichtag ) {
			return null;
		}

		return (int) floor( ( $stichtag - $erwartet ) / DAY_IN_SECONDS );
	}

	/**
	 * Menge, die für ein Teil unterwegs ist.
	 *
	 * Sie gehört in die Beschaffungsplanung: Wer heute schon bestellt hat,
	 * soll morgen nicht ein zweites Mal bestellen, weil der Bestand noch
	 * niedrig ist.
	 *
	 * @param array  $purchases Bestellungen.
	 * @param string $type      component oder supply.
	 * @param int    $item_id   Teil.
	 * @return float
	 */
	public static function incoming( array $purchases, $type, $item_id, $ref = '' ) {
		$summe = 0.0;

		foreach ( $purchases as $purchase ) {
			$purchase = (array) $purchase;

			if ( (string) $purchase['item_type'] !== (string) $type ) {
				continue;
			}
			if ( (int) $purchase['item_id'] !== (int) $item_id ) {
				continue;
			}
			// Filament hat keine Nummer, es wird über die Farbe erkannt.
			if ( '' !== (string) $ref && (string) ( isset( $purchase['item_ref'] ) ? $purchase['item_ref'] : '' ) !== (string) $ref ) {
				continue;
			}
			if ( ! self::is_open( $purchase ) ) {
				continue;
			}

			$summe += (float) $purchase['qty'];
		}

		return $summe;
	}

	/**
	 * Gesamtwert einer Bestellung.
	 *
	 * @param array $purchase Bestellung.
	 * @return float
	 */
	public static function total( array $purchase ) {
		return (float) $purchase['qty'] * (float) $purchase['unit_price_net'] + (float) $purchase['shipping_net'];
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array  $input Eingaben.
	 * @param string $heute Stichtag für die Vorgabe des Bestelldatums.
	 * @return array {data, errors}
	 */
	public static function validate( array $input, $heute = '' ) {
		$errors = array();
		$heute  = '' === $heute ? gmdate( 'Y-m-d' ) : $heute;

		$type = isset( $input['item_type'] ) ? (string) $input['item_type'] : 'component';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$errors[] = __( 'Unbekannte Bestellart.', 'lindenweg16' );
			$type     = 'component';
		}

		$item_id  = isset( $input['item_id'] ) ? (int) $input['item_id'] : 0;
		$item_ref = isset( $input['item_ref'] ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $input['item_ref'] ) ) : '';

		if ( 'filament' === $type ) {
			// Filament wird über die Farbe bestellt, nicht über eine Nummer.
			$item_id = 0;
			if ( '' === $item_ref ) {
				$errors[] = __( 'Es ist nicht klar, welche Filamentfarbe bestellt wurde.', 'lindenweg16' );
			}
		} elseif ( $item_id <= 0 ) {
			$errors[] = __( 'Es ist nicht klar, was bestellt wurde.', 'lindenweg16' );
		}

		$qty = isset( $input['qty'] ) ? (float) str_replace( ',', '.', (string) $input['qty'] ) : 0.0;
		if ( $qty <= 0 ) {
			$errors[] = __( 'Eine Bestellmenge größer als 0 ist Pflicht.', 'lindenweg16' );
		}

		$ordered_on = self::date_or( $input, 'ordered_on', $heute );
		$expected   = self::date_or( $input, 'expected_on', '' );

		// Ein Liefertermin vor der Bestellung ist ein Tippfehler, kein Wunsch.
		if ( '' !== $expected && $expected < $ordered_on ) {
			$errors[] = __( 'Der erwartete Liefertermin liegt vor dem Bestelldatum.', 'lindenweg16' );
		}

		return array(
			'data'   => array(
				'item_type'      => $type,
				'item_id'        => $item_id,
				'item_ref'       => 'filament' === $type ? $item_ref : null,
				'status'         => 'bestellt',
				'qty'            => $qty,
				'unit_price_net' => isset( $input['unit_price_net'] ) ? (float) str_replace( ',', '.', (string) $input['unit_price_net'] ) : 0.0,
				'shipping_net'   => isset( $input['shipping_net'] ) ? (float) str_replace( ',', '.', (string) $input['shipping_net'] ) : 0.0,
				'supplier'       => isset( $input['supplier'] ) ? (string) $input['supplier'] : '',
				'order_ref'      => isset( $input['order_ref'] ) ? (string) $input['order_ref'] : '',
				'ordered_on'     => $ordered_on,
				'expected_on'    => '' === $expected ? null : $expected,
				'note'           => isset( $input['note'] ) ? (string) $input['note'] : '',
			),
			'errors' => $errors,
		);
	}

	/**
	 * Ein Datumsfeld lesen, mit Rückfall.
	 *
	 * @param array  $input Eingaben.
	 * @param string $key   Feld.
	 * @param string $fallback Rückfall.
	 * @return string
	 */
	protected static function date_or( array $input, $key, $fallback ) {
		$raw = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return $fallback;
		}

		return $raw;
	}

	/**
	 * Ist die Adresse eine brauchbare Bestelladresse?
	 *
	 * Nur http und https – ein `javascript:`-Link im Backend wäre eine
	 * offene Tür, und ein Bestell-Link ist immer eine Webseite.
	 *
	 * @param string $url Adresse.
	 * @return string Geprüfte Adresse oder leerer String.
	 */
	public static function clean_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$schema = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		if ( ! in_array( $schema, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}
}
