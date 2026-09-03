<?php
/**
 * DHL-Versandlabel: Produktwahl, Abrechnungsnummer, Anschrift, Auftrag,
 * Antwort.
 *
 * Ein Fehler in dieser Kette kostet echtes Geld: Die Abrechnungsnummer
 * entscheidet, welches Konto belastet wird, eine falsch getrennte Adresse
 * fällt erst auf, wenn das Paket zurückkommt, und ein zu leicht
 * frankiertes Paket wird nachberechnet. Deshalb ist alles, was ohne Netz
 * entschieden werden kann, hier geprüft.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Dhl\DhlAddress;
use Lindenweg16\Modules\Betrieb\Dhl\DhlProduct;
use Lindenweg16\Modules\Betrieb\Dhl\DhlRequest;
use Lindenweg16\Modules\Betrieb\Dhl\DhlResponse;
use Lindenweg16\Modules\Betrieb\Dhl\DhlLabelRepository;

/**
 * Prüft die netzfreie Hälfte des Versandmoduls.
 */
class DhlLabelTest extends TestCase {

	/* ----------------------------------------------------------------
	 * Produktwahl
	 * -------------------------------------------------------------- */

	/**
	 * Inland ist ein Paket, solange Warenpost nicht gebucht ist.
	 */
	public function test_inland_ist_ein_paket() {
		$this->assertSame( 'V01PAK', DhlProduct::for_destination( 'DE', 800 ) );
		$this->assertSame( 'V01PAK', DhlProduct::for_destination( 'DE', 800, false ) );
	}

	/**
	 * Mit gebuchter Warenpost geht Leichtes als Warenpost – aber nur bis
	 * zur Gewichtsgrenze. Ein Warenpost-Label für 2 kg weist die DHL
	 * zurück, und zwar erst, wenn das Paket schon gepackt ist.
	 */
	public function test_warenpost_nur_bis_zur_grenze() {
		$this->assertSame( 'V62WP', DhlProduct::for_destination( 'DE', 900, true ) );
		$this->assertSame( 'V62WP', DhlProduct::for_destination( 'DE', DhlProduct::WARENPOST_MAX_G, true ) );
		$this->assertSame( 'V01PAK', DhlProduct::for_destination( 'DE', DhlProduct::WARENPOST_MAX_G + 1, true ) );
	}

	/**
	 * EU ist Europaket, der Rest der Welt Weltpaket.
	 */
	public function test_eu_und_welt() {
		$this->assertSame( 'V54EPAK', DhlProduct::for_destination( 'AT', 1500 ) );
		$this->assertSame( 'V54EPAK', DhlProduct::for_destination( 'NL', 300, true ) );
		$this->assertSame( 'V53WPAK', DhlProduct::for_destination( 'US', 1500 ) );
		$this->assertSame( 'V66WPI', DhlProduct::for_destination( 'US', 300, true ) );
	}

	/**
	 * Ohne Land wird Inland angenommen – ein leeres Länderfeld darf nicht
	 * in ein Weltpaket laufen.
	 */
	public function test_ohne_land_ist_inland() {
		$this->assertSame( 'V01PAK', DhlProduct::for_destination( '', 500 ) );
	}

	/**
	 * Die Schweiz ist kein EU-Land – dort braucht es Zollpapiere.
	 */
	public function test_zoll_ausserhalb_der_eu() {
		$this->assertFalse( DhlProduct::needs_customs( 'DE' ) );
		$this->assertFalse( DhlProduct::needs_customs( 'FR' ) );
		$this->assertTrue( DhlProduct::needs_customs( 'CH' ) );
		$this->assertTrue( DhlProduct::needs_customs( 'GB' ) );
		$this->assertFalse( DhlProduct::needs_customs( '' ), 'Ohne Land wird nichts behauptet.' );
	}

	/* ----------------------------------------------------------------
	 * Abrechnungsnummer
	 * -------------------------------------------------------------- */

	/**
	 * Vierzehn Stellen, und das Verfahren stammt aus dem Produktschlüssel.
	 */
	public function test_abrechnungsnummer_ist_vierzehnstellig() {
		$nummer = DhlProduct::billing_number( '3333333330', 'V01PAK', '02' );

		$this->assertSame( 14, strlen( $nummer ) );
		$this->assertSame( '3333333330', substr( $nummer, 0, 10 ) );
		$this->assertSame( '01', substr( $nummer, 10, 2 ), 'Das Verfahren steckt im Produktschlüssel.' );
		$this->assertSame( '02', substr( $nummer, 12, 2 ) );

		$this->assertSame( '33333333306201', DhlProduct::billing_number( '3333333330', 'V62WP', '01' ) );
	}

	/**
	 * Fehlt oder passt etwas nicht, entsteht keine halbe Nummer. Eine
	 * falsche Abrechnungsnummer bucht auf ein fremdes Konto.
	 */
	public function test_unvollstaendige_angaben_ergeben_keine_nummer() {
		$this->assertSame( '', DhlProduct::billing_number( '123', 'V01PAK', '01' ), 'EKP zu kurz.' );
		$this->assertSame( '', DhlProduct::billing_number( '3333333330', 'V01PAK', '' ), 'Teilnahme fehlt.' );
		$this->assertSame( '', DhlProduct::billing_number( '3333333330', 'V01PAK', '1' ), 'Teilnahme einstellig.' );
		$this->assertSame( '', DhlProduct::billing_number( '3333333330', 'UNSINN', '01' ), 'Produkt ohne Verfahren.' );
	}

	/**
	 * Getrennt geschriebene Kundennummern werden verstanden.
	 */
	public function test_kundennummer_darf_trennzeichen_haben() {
		$this->assertSame( '33333333300101', DhlProduct::billing_number( '33 3333 3330', 'V01PAK', '01' ) );
	}

	/* ----------------------------------------------------------------
	 * Anschriften
	 * -------------------------------------------------------------- */

	/**
	 * Straße und Hausnummer trennen – der Regelfall und seine Varianten.
	 */
	public function test_hausnummer_wird_abgetrennt() {
		$faelle = array(
			'Lindenweg 16'          => array( 'Lindenweg', '16' ),
			'Lindenweg 16a'         => array( 'Lindenweg', '16a' ),
			'Lindenweg 16-18'       => array( 'Lindenweg', '16-18' ),
			'Am Alten Markt 3'      => array( 'Am Alten Markt', '3' ),
			'Hauptstr. 7'           => array( 'Hauptstr.', '7' ),
			'16 Lindenweg'          => array( 'Lindenweg', '16' ),
		);

		foreach ( $faelle as $eingabe => $erwartet ) {
			$ergebnis = DhlAddress::split_street( $eingabe );

			$this->assertSame( $erwartet[0], $ergebnis['strasse'], $eingabe );
			$this->assertSame( $erwartet[1], $ergebnis['hausnummer'], $eingabe );
		}
	}

	/**
	 * Ohne erkennbare Nummer wird keine erfunden – lieber ein leeres Feld
	 * als ein Paket an die falsche Tür.
	 */
	public function test_ohne_hausnummer_bleibt_das_feld_leer() {
		$ergebnis = DhlAddress::split_street( 'Postfach' );

		$this->assertSame( 'Postfach', $ergebnis['strasse'] );
		$this->assertSame( '', $ergebnis['hausnummer'] );
	}

	/**
	 * Postleitzahl und Ort aus der einen Zeile der Firmenstammdaten.
	 */
	public function test_plz_und_ort_werden_getrennt() {
		$this->assertSame( array( 'plz' => '49626', 'ort' => 'Bippen' ), DhlAddress::split_postal_city( '49626 Bippen' ) );
		$this->assertSame( array( 'plz' => '1010', 'ort' => 'Wien' ), DhlAddress::split_postal_city( 'A-1010 Wien' ) );
		$this->assertSame( array( 'plz' => '49626', 'ort' => 'Bippen-Ohrtermersch' ), DhlAddress::split_postal_city( '49626  Bippen-Ohrtermersch' ) );
	}

	/**
	 * Steht keine Postleitzahl da, wird keine erfunden.
	 */
	public function test_ohne_plz_bleibt_die_plz_leer() {
		$this->assertSame( array( 'plz' => '', 'ort' => 'Bippen' ), DhlAddress::split_postal_city( 'Bippen' ) );
	}

	/**
	 * Die DHL will das dreibuchstabige Länderkürzel. Ein ungewandeltes
	 * „DE" weist sie zurück.
	 */
	public function test_land_wird_dreibuchstabig() {
		$this->assertSame( 'DEU', DhlAddress::country( 'DE' ) );
		$this->assertSame( 'AUT', DhlAddress::country( 'at' ) );
		$this->assertSame( 'CHE', DhlAddress::country( 'CHE' ) );
		$this->assertSame( '', DhlAddress::country( 'XX' ), 'Unbekanntes Land wird gemeldet, nicht geraten.' );
	}

	/**
	 * Leere Felder fliegen raus – die Schnittstelle weist einen leeren
	 * String zurück, wo sie ein fehlendes Feld hinnimmt.
	 */
	public function test_leere_felder_stehen_nicht_im_auftrag() {
		$adresse = DhlAddress::to_dhl(
			array(
				'name1'   => 'Katrin Beispiel',
				'strasse' => 'Lindenweg',
				'hausnummer' => '16',
				'plz'     => '49626',
				'ort'     => 'Bippen',
				'land'    => 'DE',
			)
		);

		$this->assertArrayNotHasKey( 'name2', $adresse );
		$this->assertArrayNotHasKey( 'email', $adresse );
		$this->assertSame( 'DEU', $adresse['country'] );
		$this->assertSame( '16', $adresse['addressHouse'] );
	}

	/**
	 * Zu lange Werte werden gekürzt, nicht durchgereicht: Ein zu langer
	 * Name weist die ganze Sendung zurück.
	 */
	public function test_zu_lange_werte_werden_gekuerzt() {
		$adresse = DhlAddress::to_dhl(
			array(
				'name1' => str_repeat( 'A', 90 ),
				'strasse' => 'Lindenweg',
				'plz' => '49626',
				'ort' => 'Bippen',
				'land' => 'DE',
			)
		);

		$this->assertSame( 50, strlen( $adresse['name1'] ) );
	}

	/**
	 * Fehlende Pflichtfelder werden in Klartext gemeldet – die DHL
	 * antwortet sonst mit einer Nummer.
	 */
	public function test_fehlende_pflichtfelder_werden_benannt() {
		$fehlt = DhlAddress::missing( DhlAddress::to_dhl( array( 'name1' => 'Katrin Beispiel' ) ) );

		$this->assertNotEmpty( $fehlt );
		$this->assertContains( 'Straße', $fehlt );
		$this->assertContains( 'Postleitzahl', $fehlt );
		$this->assertSame( array(), DhlAddress::missing( DhlAddress::to_dhl( array(
			'name1' => 'Katrin Beispiel',
			'strasse' => 'Lindenweg',
			'plz' => '49626',
			'ort' => 'Bippen',
			'land' => 'DE',
		) ) ) );
	}

	/* ----------------------------------------------------------------
	 * Auftrag
	 * -------------------------------------------------------------- */

	/**
	 * Das Gewicht steht in der Struktur, die die DHL erwartet.
	 */
	public function test_gewicht_hat_einheit_und_wert() {
		$this->assertSame( array( 'uom' => 'g', 'value' => 1250 ), DhlRequest::weight( 1250.4 ) );
	}

	/**
	 * Eine Sendung ohne Gewicht weist die DHL zurück. Statt dessen gilt
	 * ein ehrlicher Mindestwert.
	 */
	public function test_ohne_gewicht_gilt_der_mindestwert() {
		$this->assertSame( DhlRequest::MIN_WEIGHT_G, DhlRequest::weight( 0 )['value'] );
		$this->assertSame( DhlRequest::MIN_WEIGHT_G, DhlRequest::weight( -5 )['value'] );
	}

	/**
	 * Die Referenz erscheint auf dem Label und taugt nur ab acht Zeichen
	 * zum Suchen.
	 */
	public function test_referenz_wird_auf_acht_zeichen_gebracht() {
		$this->assertSame( '00000412', DhlRequest::reference( '412' ) );
		$this->assertSame( 'LW16-2026-000123', DhlRequest::reference( 'LW16-2026-000123' ) );
		$this->assertSame( 35, strlen( DhlRequest::reference( str_repeat( 'X', 60 ) ) ) );
		$this->assertSame( '', DhlRequest::reference( '' ) );
	}

	/**
	 * Der Auftrag trägt genau eine Sendung mit allen Pflichtteilen.
	 */
	public function test_auftrag_hat_die_erwartete_form() {
		$auftrag = $this->auftrag();

		$this->assertSame( 'STANDARD_GRUPPENPROFIL', $auftrag['profile'] );
		$this->assertCount( 1, $auftrag['shipments'] );

		$shipment = $auftrag['shipments'][0];

		$this->assertSame( 'V01PAK', $shipment['product'] );
		$this->assertSame( '33333333300101', $shipment['billingNumber'] );
		$this->assertSame( 850, $shipment['details']['weight']['value'] );
		$this->assertSame( 'DEU', $shipment['consignee']['country'] );
		$this->assertArrayNotHasKey( 'services', $shipment, 'Ohne Zusatzleistung steht das Feld nicht im Auftrag.' );
	}

	/**
	 * Sperrgut und Vorabmeldung landen unter den Zusatzleistungen.
	 */
	public function test_zusatzleistungen() {
		$services = DhlRequest::services( array( 'bulky' => true, 'email' => 'kunde@example.test', 'notify' => true ) );

		$this->assertTrue( $services['bulkyGoods'] );
		$this->assertSame( 'kunde@example.test', $services['shippingConfirmation']['email'] );

		$this->assertSame( array(), DhlRequest::services( array( 'email' => 'kunde@example.test' ) ), 'Ohne Einwilligung keine Vorabmeldung.' );
	}

	/**
	 * Vor dem Senden wird gemeldet, was fehlt – sonst kostet jeder Fehler
	 * einen Aufruf und die Antwort nennt nur eine Feldnummer.
	 */
	public function test_luecken_werden_vor_dem_senden_gemeldet() {
		$auftrag = DhlRequest::build( array( 'product' => 'V01PAK' ) );
		$fehlt   = DhlRequest::missing( $auftrag );

		$this->assertNotEmpty( $fehlt );
		$this->assertSame( array(), DhlRequest::missing( $this->auftrag() ) );
	}

	/**
	 * Eine unvollständige Abrechnungsnummer wird als solche erkannt.
	 */
	public function test_halbe_abrechnungsnummer_wird_erkannt() {
		$auftrag = $this->auftrag();
		$auftrag['shipments'][0]['billingNumber'] = '3333333330';

		$this->assertNotEmpty( DhlRequest::missing( $auftrag ) );
	}

	/* ----------------------------------------------------------------
	 * Antwort
	 * -------------------------------------------------------------- */

	/**
	 * Die glückliche Antwort: Nummer und Label.
	 */
	public function test_erfolgreiche_antwort() {
		$ergebnis = DhlResponse::parse(
			array(
				'status' => array( 'statusCode' => 200, 'title' => 'ok' ),
				'items'  => array(
					array(
						'shipmentNo' => '00340434161094042557',
						'sstatus'    => array( 'statusCode' => 200 ),
						'label'      => array( 'b64' => 'JVBERi0=', 'fileFormat' => 'PDF' ),
					),
				),
			),
			200
		);

		$this->assertTrue( $ergebnis['ok'] );
		$this->assertSame( '00340434161094042557', $ergebnis['shipment_no'] );
		$this->assertSame( 'JVBERi0=', $ergebnis['label_b64'] );
		$this->assertSame( array(), $ergebnis['messages'] );
	}

	/**
	 * Die DHL antwortet auf zwei Ebenen. Ein Aufruf kann „teilweise
	 * erfolgreich" melden und die Sendung darin trotzdem abgelehnt sein –
	 * wer nur den HTTP-Code prüft, hält eine Ablehnung für ein Label.
	 */
	public function test_abgelehnte_sendung_in_erfolgreichem_aufruf() {
		$ergebnis = DhlResponse::parse(
			array(
				'status' => array( 'statusCode' => 207, 'title' => 'Multi-Status' ),
				'items'  => array(
					array(
						'sstatus'            => array( 'statusCode' => 400, 'title' => 'Bad Request' ),
						'validationMessages' => array(
							array( 'property' => 'consignee.postalCode', 'validationMessage' => 'Postleitzahl unbekannt' ),
						),
					),
				),
			),
			207
		);

		$this->assertFalse( $ergebnis['ok'] );
		$this->assertContains( 'consignee.postalCode: Postleitzahl unbekannt', $ergebnis['messages'] );
	}

	/**
	 * Eine Nummer ohne Label ist ein halber Vorgang – es gibt nichts zu
	 * kleben. Das darf nicht als Erfolg durchgehen.
	 */
	public function test_nummer_ohne_label_ist_kein_erfolg() {
		$ergebnis = DhlResponse::parse(
			array( 'items' => array( array( 'shipmentNo' => '003404341610', 'sstatus' => array( 'statusCode' => 200 ) ) ) ),
			200
		);

		$this->assertFalse( $ergebnis['ok'] );
		$this->assertNotEmpty( $ergebnis['messages'], 'Ein stiller Fehlschlag wäre der schlimmste.' );
	}

	/**
	 * Kaputte oder leere Antworten führen zu einer Meldung, nicht zu
	 * einem Absturz.
	 */
	public function test_unlesbare_antwort() {
		foreach ( array( 'kein json', '', null ) as $body ) {
			$ergebnis = DhlResponse::parse( $body, 500 );

			$this->assertFalse( $ergebnis['ok'] );
			$this->assertNotEmpty( $ergebnis['messages'] );
		}
	}

	/**
	 * Ein Label mit Hinweisen (207 auf Sendungsebene) ist trotzdem ein
	 * Label – es liegt vor und ist frankiert.
	 */
	public function test_label_mit_hinweisen_gilt() {
		$ergebnis = DhlResponse::parse(
			array(
				'items' => array(
					array(
						'shipmentNo' => '003404341610',
						'sstatus'    => array( 'statusCode' => 207, 'title' => 'Weiche Warnung' ),
						'label'      => array( 'b64' => 'JVBERi0=' ),
					),
				),
			),
			207
		);

		$this->assertTrue( $ergebnis['ok'] );
	}

	/* ----------------------------------------------------------------
	 * Ablage
	 * -------------------------------------------------------------- */

	/**
	 * Der Dateiname trägt Bestellung und Sendungsnummer – eine Datei im
	 * Verzeichnis lässt sich so auch ohne Datenbank zuordnen.
	 */
	public function test_dateiname_traegt_bestellung_und_sendung() {
		$this->assertSame( 'dhl-label-412-00340434161094042557.pdf', DhlLabelRepository::filename( 412, '00340434161094042557' ) );
		$this->assertSame( 'dhl-label-412-003404.pdf', DhlLabelRepository::filename( 412, '00 34/04..' ), 'Kein Pfad, kein Punkt, kein Schrägstrich im Namen.' );
	}

	/**
	 * Ein vollständiger Auftrag zum Weiterverwenden.
	 *
	 * @return array
	 */
	protected function auftrag() {
		return DhlRequest::build(
			array(
				'product'       => 'V01PAK',
				'billingNumber' => DhlProduct::billing_number( '3333333330', 'V01PAK', '01' ),
				'refNo'         => '000000412',
				'weight_g'      => 850,
				'shipper'       => DhlAddress::to_dhl(
					array(
						'name1'      => 'Lindenweg16',
						'strasse'    => 'Lindenweg',
						'hausnummer' => '16',
						'plz'        => '49626',
						'ort'        => 'Bippen',
						'land'       => 'DE',
					)
				),
				'consignee'     => DhlAddress::to_dhl(
					array(
						'name1'      => 'Katrin Beispiel',
						'strasse'    => 'Musterweg',
						'hausnummer' => '3a',
						'plz'        => '10115',
						'ort'        => 'Berlin',
						'land'       => 'DE',
					)
				),
			)
		);
	}
}
