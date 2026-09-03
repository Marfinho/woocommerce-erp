<?php
/**
 * Versandlabel der DHL erzeugen und stornieren.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\Dhl\Print\DhlPrintQueue;
use Lindenweg16\Modules\Betrieb\StockOwnership;
use Lindenweg16\Modules\Production\DhlTracking;

/**
 * Der Vorgang von der Bestellung bis zum abgelegten Label.
 *
 * Hier laufen die Teile zusammen: Adresse aus der Bestellung, Gewicht aus
 * dem Artikelstamm, Produkt und Abrechnungsnummer aus den Einstellungen,
 * Aufruf der Schnittstelle, Ablage des PDFs – und die Sendungsnummer
 * geht sofort in die Sendungsverfolgung, damit der Kunde sie im Konto
 * sieht, ohne dass sie jemand abtippt.
 */
class DhlShipping {

	/**
	 * Absender aus den Firmenstammdaten.
	 *
	 * @return array Anschrift in der Struktur der DHL.
	 */
	public static function shipper() {
		$strasse = DhlAddress::split_street( (string) Settings::get( 'firma_strasse', '' ) );
		$ort     = DhlAddress::split_postal_city( (string) Settings::get( 'firma_plz_ort', '' ) );

		return DhlAddress::to_dhl(
			array(
				'name1'      => (string) Settings::get( 'firma_name', '' ),
				'strasse'    => $strasse['strasse'],
				'hausnummer' => $strasse['hausnummer'],
				'plz'        => $ort['plz'],
				'ort'        => $ort['ort'],
				'land'       => (string) Settings::get( 'dhl_versand_land', 'DE' ),
				'email'      => (string) Settings::get( 'firma_email', '' ),
				'telefon'    => (string) Settings::get( 'firma_telefon', '' ),
			)
		);
	}

	/**
	 * Empfänger aus der Bestellung.
	 *
	 * Die Lieferanschrift gewinnt; fehlt sie, ist die Rechnungsanschrift
	 * gemeint – so hält es WooCommerce bei Abholung und digitalen Waren.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array
	 */
	public static function consignee( $order ) {
		$versand = trim( (string) $order->get_shipping_address_1() );
		$nutze   = '' !== $versand ? 'shipping' : 'billing';

		$strasse = DhlAddress::split_street(
			'shipping' === $nutze ? $order->get_shipping_address_1() : $order->get_billing_address_1()
		);

		$firma = 'shipping' === $nutze ? $order->get_shipping_company() : $order->get_billing_company();
		$name  = 'shipping' === $nutze
			? trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() )
			: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		// Steht eine Firma da, gehört sie in die erste Zeile: Sie steht am
		// Klingelschild, der Name des Bestellers oft nicht.
		return DhlAddress::to_dhl(
			array(
				'name1'      => '' !== trim( (string) $firma ) ? $firma : $name,
				'name2'      => '' !== trim( (string) $firma ) ? $name : '',
				'strasse'    => $strasse['strasse'],
				'hausnummer' => $strasse['hausnummer'],
				'zusatz'     => 'shipping' === $nutze ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
				'plz'        => 'shipping' === $nutze ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
				'ort'        => 'shipping' === $nutze ? $order->get_shipping_city() : $order->get_billing_city(),
				'land'       => 'shipping' === $nutze ? $order->get_shipping_country() : $order->get_billing_country(),
				'email'      => $order->get_billing_email(),
				'telefon'    => $order->get_billing_phone(),
			)
		);
	}

	/**
	 * Gewicht einer Bestellung in Gramm.
	 *
	 * Führend ist der Artikelstamm des Betriebs – dort steht das
	 * gewogene Bauteilgewicht. Erst wenn es das nicht gibt, zählt das
	 * Gewicht am Shop-Produkt. Dazu kommt der Verpackungszuschlag aus den
	 * Einstellungen: Karton und Polster wiegen mit, und ein Label, das
	 * 200 g unter dem tatsächlichen Gewicht liegt, ist eine Nachzahlung.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return float Gramm.
	 */
	public static function weight_g( $order ) {
		$gramm = 0.0;

		foreach ( $order->get_items() as $item ) {
			$menge      = (float) $item->get_quantity();
			$product_id = (int) $item->get_product_id();
			$einzeln    = 0.0;

			$article_id = StockOwnership::article_for_product( $product_id );

			if ( $article_id ) {
				$article = ArticleRepository::find( $article_id );

				if ( $article && ! empty( $article['weight_g'] ) ) {
					$einzeln = (float) $article['weight_g'];
				}
			}

			if ( $einzeln <= 0 && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );

				if ( $product && $product->get_weight() ) {
					// WooCommerce führt das Gewicht in der Shop-Einheit;
					// gerechnet wird hier in Gramm.
					$einzeln = self::to_grams( (float) $product->get_weight() );
				}
			}

			$gramm += $einzeln * $menge;
		}

		return $gramm + (float) Settings::get( 'dhl_versand_verpackung_g', 150 );
	}

	/**
	 * Shop-Gewicht in Gramm umrechnen.
	 *
	 * @param float $wert Gewicht in der Shop-Einheit.
	 * @return float
	 */
	public static function to_grams( $wert ) {
		$einheit = function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_weight_unit', 'kg' ) : 'kg';

		return self::convert_weight( $wert, $einheit );
	}

	/**
	 * Gewicht aus einer Shop-Einheit in Gramm – reine Logik.
	 *
	 * @param float  $wert    Wert.
	 * @param string $einheit kg, g, lbs oder oz.
	 * @return float
	 */
	public static function convert_weight( $wert, $einheit ) {
		$wert = (float) $wert;

		switch ( strtolower( trim( (string) $einheit ) ) ) {
			case 'g':
				return $wert;
			case 'lbs':
				return $wert * 453.59237;
			case 'oz':
				return $wert * 28.349523125;
			default:
				return $wert * 1000;
		}
	}

	/**
	 * Auftrag für eine Bestellung zusammenstellen.
	 *
	 * @param \WC_Order $order   Bestellung.
	 * @param array     $wunsch  Übersteuerungen aus dem Formular (product, weight_g, bulky).
	 * @return array
	 */
	public static function order_request( $order, array $wunsch = array() ) {
		$consignee = self::consignee( $order );
		$land      = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
		$gewicht   = isset( $wunsch['weight_g'] ) && $wunsch['weight_g'] > 0
			? (float) $wunsch['weight_g']
			: self::weight_g( $order );

		$product = isset( $wunsch['product'] ) && '' !== $wunsch['product']
			? (string) $wunsch['product']
			: DhlProduct::for_destination( $land, $gewicht, 1 === (int) Settings::get( 'dhl_versand_warenpost', 0 ) );

		// In der Sandbox bucht die DHL auf ihre eigene Testkundennummer.
		// Die echte EKP dort mitzuschicken ergäbe eine Abrechnungsnummer,
		// die es in der Testumgebung nicht gibt – die Ablehnung sähe dann
		// nach einem Adressfehler aus.
		$zugang = DhlSandbox::billing(
			DhlClient::sandbox(),
			array(
				'ekp'       => (string) Settings::get( 'dhl_versand_ekp', '' ),
				'teilnahme' => self::participation( $product ),
				'profil'    => (string) Settings::get( 'dhl_versand_profil', 'STANDARD_GRUPPENPROFIL' ),
			)
		);

		return DhlRequest::build(
			array(
				'profile'       => $zugang['profil'],
				'product'       => $product,
				'billingNumber' => DhlProduct::billing_number(
					$zugang['ekp'],
					$product,
					$zugang['teilnahme']
				),
				'refNo'         => (string) $order->get_order_number(),
				'shipper'       => self::shipper(),
				'consignee'     => $consignee,
				'weight_g'      => $gewicht,
				'email'         => $order->get_billing_email(),
				'notify'        => 1 === (int) Settings::get( 'dhl_versand_vorabmeldung', 1 ),
				'bulky'         => ! empty( $wunsch['bulky'] ),
			)
		);
	}

	/**
	 * Teilnahme zu einem Produkt.
	 *
	 * Die DHL vergibt sie je Verfahren. Für die meisten Kunden ist es
	 * überall dieselbe – deshalb dient die allgemeine Teilnahme als
	 * Rückfall, wenn für dieses Produkt keine eigene gepflegt ist.
	 *
	 * @param string $product Produktschlüssel.
	 * @return string
	 */
	public static function participation( $product ) {
		$eigene = trim( (string) Settings::get( 'dhl_versand_teilnahme_' . strtolower( DhlProduct::procedure( $product ) ), '' ) );

		return '' !== $eigene ? $eigene : trim( (string) Settings::get( 'dhl_versand_teilnahme', '' ) );
	}

	/**
	 * Label erzeugen, ablegen und die Sendungsnummer weiterreichen.
	 *
	 * @param int   $order_id Bestellung.
	 * @param array $wunsch   Übersteuerungen aus dem Formular.
	 * @return array {ok, message, shipment_no}
	 */
	public static function create_label( $order_id, array $wunsch = array() ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( ! $order ) {
			return self::fehler( __( 'Bestellung nicht gefunden.', 'lindenweg16' ) );
		}

		if ( DhlLabelRepository::for_order( $order_id ) ) {
			return self::fehler( __( 'Für diese Bestellung gibt es schon ein Label. Erst stornieren, dann neu erzeugen.', 'lindenweg16' ) );
		}

		$fehlende_zugaenge = DhlClient::missing_credentials();

		if ( $fehlende_zugaenge ) {
			/* translators: %s: Liste fehlender Angaben. */
			return self::fehler( sprintf( __( 'Der DHL-Zugang ist unvollständig: %s.', 'lindenweg16' ), implode( ', ', $fehlende_zugaenge ) ) );
		}

		$auftrag = self::order_request( $order, $wunsch );
		$fehlt   = DhlRequest::missing( $auftrag );

		if ( $fehlt ) {
			/* translators: %s: Liste fehlender Angaben. */
			return self::fehler( sprintf( __( 'So kann kein Label entstehen – es fehlt: %s.', 'lindenweg16' ), implode( '; ', $fehlt ) ) );
		}

		$antwort = DhlClient::create( $auftrag );

		if ( ! $antwort['ok'] ) {
			return self::fehler(
				$antwort['messages']
					? implode( ' ', $antwort['messages'] )
					: __( 'Die DHL hat das Label abgelehnt.', 'lindenweg16' )
			);
		}

		$datei = self::store_pdf( $order_id, $antwort );

		if ( '' === $datei ) {
			return self::fehler( __( 'Das Label kam an, ließ sich aber nicht ablegen. Prüfe die Schreibrechte im Dokumentenverzeichnis.', 'lindenweg16' ) );
		}

		$shipment = $auftrag['shipments'][0];

		$label_id = DhlLabelRepository::create(
			array(
				'order_id'       => (int) $order_id,
				'shipment_no'    => $antwort['shipment_no'],
				'product'        => $shipment['product'],
				'billing_number' => $shipment['billingNumber'],
				'filename'       => $datei,
				'weight_g'       => (int) $shipment['details']['weight']['value'],
			)
		);

		self::hand_over_tracking( (int) $order_id, $antwort['shipment_no'] );

		// Druck-Warteschlange: erst jetzt, wo das Label wirklich erzeugt
		// UND abgelegt ist – nie bei einem DHL- oder Ablagefehler.
		DhlPrintQueue::maybe_enqueue_for_label( (int) $order_id, $label_id, $datei );

		AuditLog::record(
			'dhl_label_erzeugt',
			'order',
			(int) $order_id,
			array(
				'sendung' => $antwort['shipment_no'],
				'produkt' => $shipment['product'],
				'gewicht' => $shipment['details']['weight']['value'],
			)
		);

		return array(
			'ok'          => true,
			'shipment_no' => $antwort['shipment_no'],
			'message'     => sprintf(
				/* translators: %s: Sendungsnummer. */
				__( 'Label erzeugt. Sendungsnummer %s – die Sendungsverfolgung kennt sie bereits.', 'lindenweg16' ),
				$antwort['shipment_no']
			),
		);
	}

	/**
	 * Label stornieren.
	 *
	 * @param int $label_id Label.
	 * @return array {ok, message}
	 */
	public static function void_label( $label_id ) {
		$label = DhlLabelRepository::find( $label_id );

		if ( ! $label || $label->storniert ) {
			return self::fehler( __( 'Kein stornierbares Label.', 'lindenweg16' ) );
		}

		$antwort = DhlClient::void( $label->shipment_no, (string) Settings::get( 'dhl_versand_profil', 'STANDARD_GRUPPENPROFIL' ) );

		if ( ! $antwort['ok'] ) {
			return self::fehler(
				$antwort['messages']
					? implode( ' ', $antwort['messages'] )
					: __( 'Die DHL hat die Stornierung abgelehnt. Eine bereits abgeholte Sendung lässt sich nicht mehr zurücknehmen.', 'lindenweg16' )
			);
		}

		DhlLabelRepository::mark_voided( (int) $label->id );

		AuditLog::record( 'dhl_label_storniert', 'order', (int) $label->order_id, array( 'sendung' => $label->shipment_no ) );

		return array(
			'ok'      => true,
			'message' => __( 'Label storniert. Die Frankierung wird nicht berechnet.', 'lindenweg16' ),
		);
	}

	/**
	 * PDF ablegen und Dateinamen zurückgeben.
	 *
	 * @param int   $order_id Bestellung.
	 * @param array $antwort  Ergebnis der Schnittstelle.
	 * @return string Dateiname oder leer.
	 */
	protected static function store_pdf( $order_id, array $antwort ) {
		$inhalt = '';

		if ( '' !== $antwort['label_b64'] ) {
			$inhalt = base64_decode( $antwort['label_b64'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		} elseif ( '' !== $antwort['label_url'] ) {
			$laden = wp_remote_get( $antwort['label_url'], array( 'timeout' => 30 ) );

			if ( ! is_wp_error( $laden ) ) {
				$inhalt = wp_remote_retrieve_body( $laden );
			}
		}

		if ( ! $inhalt ) {
			return '';
		}

		Installer::ensure_docs_dir();

		$datei = DhlLabelRepository::filename( $order_id, $antwort['shipment_no'] );
		$pfad  = DhlLabelRepository::path( $datei );

		return false === file_put_contents( $pfad, $inhalt ) ? '' : $datei; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Sendungsnummer in die Sendungsverfolgung schreiben.
	 *
	 * Ohne diesen Schritt müsste jemand die Nummer, die eben entstanden
	 * ist, von Hand in dasselbe Plugin eintippen, damit der Kunde sie im
	 * Konto sieht.
	 *
	 * @param int    $order_id    Bestellung.
	 * @param string $shipment_no Sendungsnummer.
	 */
	protected static function hand_over_tracking( $order_id, $shipment_no ) {
		global $wpdb;

		$table = DhlTracking::table();
		$vorhanden = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d", $order_id ) ); // phpcs:ignore

		if ( $vorhanden ) {
			$wpdb->update( $table, array( 'tracking_nummer' => $shipment_no ), array( 'id' => $vorhanden ) );

			return;
		}

		$wpdb->insert(
			$table,
			array(
				'order_id'         => $order_id,
				'tracking_nummer'  => $shipment_no,
				'aktueller_status' => '',
				'events'           => wp_json_encode( array() ),
				'letzter_abruf'    => null,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fehlerergebnis.
	 *
	 * @param string $message Meldung.
	 * @return array
	 */
	protected static function fehler( $message ) {
		return array(
			'ok'          => false,
			'shipment_no' => '',
			'message'     => $message,
		);
	}
}
