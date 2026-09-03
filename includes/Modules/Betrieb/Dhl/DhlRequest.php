<?php
/**
 * Sendungsauftrag für die DHL zusammenstellen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl;

defined( 'ABSPATH' ) || exit;

/**
 * Baut den Rumpf eines Auftrags – reine Logik, kein Netz.
 *
 * Getrennt vom Versenden, damit sich prüfen lässt, was rausgeht, ohne
 * dass ein Label entsteht. Ein Fehler in dieser Struktur kostet sonst
 * jedes Mal ein echtes Paketlabel, um ihn zu finden.
 */
class DhlRequest {

	/**
	 * Kleinstes Gewicht, das die DHL annimmt (Gramm).
	 *
	 * Eine Sendung ohne Gewicht weist sie zurück. Wo der Artikelstamm
	 * nichts hergibt, ist ein ehrlicher Mindestwert besser als eine
	 * abgelehnte Sendung am Packtisch.
	 */
	const MIN_WEIGHT_G = 100;

	/**
	 * Auftrag bauen.
	 *
	 * @param array $args {
	 *     @type string $profile       Abrechnungsprofil.
	 *     @type string $product       Produktschlüssel.
	 *     @type string $billingNumber Abrechnungsnummer.
	 *     @type string $refNo         Referenz (Bestellnummer).
	 *     @type array  $shipper       Absender, schon über DhlAddress::to_dhl().
	 *     @type array  $consignee     Empfänger, schon über DhlAddress::to_dhl().
	 *     @type float  $weight_g      Gewicht in Gramm.
	 *     @type string $email         Empfängermail für die Vorabmeldung.
	 *     @type bool   $bulky         Sperrgut.
	 * }
	 * @return array
	 */
	public static function build( array $args ) {
		$shipment = array(
			'product'       => (string) self::arg( $args, 'product' ),
			'billingNumber' => (string) self::arg( $args, 'billingNumber' ),
			'shipper'       => isset( $args['shipper'] ) ? (array) $args['shipper'] : array(),
			'consignee'     => isset( $args['consignee'] ) ? (array) $args['consignee'] : array(),
			'details'       => array(
				'weight' => self::weight( self::arg( $args, 'weight_g' ) ),
			),
		);

		$ref = self::reference( self::arg( $args, 'refNo' ) );

		if ( '' !== $ref ) {
			$shipment['refNo'] = $ref;
		}

		$services = self::services( $args );

		if ( $services ) {
			$shipment['services'] = $services;
		}

		return array(
			'profile'   => (string) self::arg( $args, 'profile', 'STANDARD_GRUPPENPROFIL' ),
			'shipments' => array( $shipment ),
		);
	}

	/**
	 * Gewicht in der Struktur der DHL.
	 *
	 * @param mixed $gramm Gewicht in Gramm.
	 * @return array
	 */
	public static function weight( $gramm ) {
		$gramm = (float) $gramm;

		return array(
			'uom'   => 'g',
			'value' => (int) round( max( self::MIN_WEIGHT_G, $gramm ) ),
		);
	}

	/**
	 * Referenznummer auf das Format der DHL bringen.
	 *
	 * Sie erscheint auf dem Label und taugt nur dann zum Suchen, wenn sie
	 * mindestens acht Zeichen hat. Eine Bestellnummer wie „412" wird
	 * deshalb aufgefüllt statt weggelassen – und länger als 35 Zeichen
	 * weist die Schnittstelle sie zurück.
	 *
	 * @param string $ref Rohwert.
	 * @return string
	 */
	public static function reference( $ref ) {
		$ref = trim( (string) $ref );

		if ( '' === $ref ) {
			return '';
		}

		if ( strlen( $ref ) < 8 ) {
			$ref = str_pad( $ref, 8, '0', STR_PAD_LEFT );
		}

		return substr( $ref, 0, 35 );
	}

	/**
	 * Zusatzleistungen.
	 *
	 * @param array $args Aufrufparameter.
	 * @return array
	 */
	public static function services( array $args ) {
		$services = array();

		if ( ! empty( $args['bulky'] ) ) {
			$services['bulkyGoods'] = true;
		}

		// Die Vorabmeldung ist der Grund, warum Kunden nicht anrufen: Sie
		// bekommen die Sendungsnummer, sobald das Label entsteht.
		$email = isset( $args['email'] ) ? trim( (string) $args['email'] ) : '';

		if ( '' !== $email && ! empty( $args['notify'] ) ) {
			$services['shippingConfirmation'] = array( 'email' => $email );
		}

		return $services;
	}

	/**
	 * Was fehlt, bevor überhaupt gesendet wird?
	 *
	 * @param array $auftrag Ergebnis von build().
	 * @return array Liste in Klartext.
	 */
	public static function missing( array $auftrag ) {
		$fehlt    = array();
		$shipment = isset( $auftrag['shipments'][0] ) ? $auftrag['shipments'][0] : array();

		if ( empty( $auftrag['profile'] ) ) {
			$fehlt[] = __( 'Abrechnungsprofil', 'lindenweg16' );
		}

		if ( empty( $shipment['product'] ) ) {
			$fehlt[] = __( 'DHL-Produkt', 'lindenweg16' );
		}

		if ( empty( $shipment['billingNumber'] ) || 14 !== strlen( (string) $shipment['billingNumber'] ) ) {
			$fehlt[] = __( 'Abrechnungsnummer (Kundennummer + Teilnahme)', 'lindenweg16' );
		}

		foreach ( array( 'shipper' => __( 'Absender', 'lindenweg16' ), 'consignee' => __( 'Empfänger', 'lindenweg16' ) ) as $key => $klartext ) {
			$fehlend = DhlAddress::missing( isset( $shipment[ $key ] ) ? (array) $shipment[ $key ] : array() );

			if ( $fehlend ) {
				/* translators: 1: Absender oder Empfänger, 2: Liste der Felder. */
				$fehlt[] = sprintf( __( '%1$s: %2$s', 'lindenweg16' ), $klartext, implode( ', ', $fehlend ) );
			}
		}

		return $fehlt;
	}

	/**
	 * Wert mit Rückfallwert lesen.
	 *
	 * @param array  $args    Parameter.
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Rückfallwert.
	 * @return mixed
	 */
	protected static function arg( array $args, $key, $default = '' ) {
		return isset( $args[ $key ] ) && '' !== $args[ $key ] ? $args[ $key ] : $default;
	}
}
