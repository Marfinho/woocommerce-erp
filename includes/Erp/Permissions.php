<?php
/**
 * Feingranulare Rechte der ERP-Oberfläche.
 *
 * Die sechs Betrieb-Rechte aus AccessProfiles sind grob: „Stammdaten
 * ändern“ öffnet zugleich Artikel, Konfigurator, Kapazität, Drucker,
 * Kontakte und Material. Für die eigene Oberfläche braucht es feinere
 * Schalter, die sich pro Person setzen lassen – wer Versand packt, muss
 * nicht an die Kontakte.
 *
 * Jedes feine Recht nennt deshalb, welche der sechs alten Rechte es
 * voraussetzt (`caps`). Beim Speichern werden diese Rechte am Nutzer
 * nachgeführt, damit die bestehenden Seiten im wp-admin weiter
 * funktionieren. Umgekehrt gilt: Was nicht in `caps` steht, kann das alte
 * Backend nicht trennen – diese Rechte tragen deshalb den Geltungsbereich
 * `phase2`, bis es dafür eigene Seiten gibt.
 *
 * Geltungsbereich (`scope`):
 *
 * - `page`   – schaltet eine Seite frei; wird beim Aufruf geprüft.
 * - `cap`    – wirkt über ein altes Recht auch innerhalb der Seiten
 *              (z. B. Kosten ausblenden).
 * - `phase2` – wird gespeichert und angezeigt, aber erst von nativen
 *              ERP-Seiten durchgesetzt.
 * - `full`   – Reserve für Module, die nur Vollzugriff kennen; zurzeit
 *              ungenutzt, seit jedes Modul ein eigenes Recht hat.
 *
 * Die Klasse ist frei von Datenbank und WordPress, damit die Zuordnung
 * prüfbar ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Rechtekatalog, Vorlagen und Normalisierung.
 */
class Permissions {

	const SCOPE_PAGE   = 'page';
	const SCOPE_CAP    = 'cap';
	const SCOPE_PHASE2 = 'phase2';
	const SCOPE_FULL   = 'full';

	/**
	 * Bereiche in der Reihenfolge der Oberfläche.
	 *
	 * @return array Kennung => Titel.
	 */
	public static function groups() {
		return array(
			'verkauf'    => __( 'Verkauf', 'lindenweg16' ),
			'fertigung'  => __( 'Fertigung', 'lindenweg16' ),
			'lager'      => __( 'Lager', 'lindenweg16' ),
			'finanzen'   => __( 'Finanzen', 'lindenweg16' ),
			'verwaltung' => __( 'Verwaltung', 'lindenweg16' ),
		);
	}

	/**
	 * Einzelnes Recht definieren.
	 *
	 * @param string $group    Bereich.
	 * @param string $label    Beschriftung.
	 * @param string $hint     Erklärung in einem Satz.
	 * @param array  $caps     Alte Betrieb-Rechte, die es voraussetzt.
	 * @param string $scope    Geltungsbereich.
	 * @param array  $requires Andere feine Rechte, ohne die es nichts nützt.
	 * @return array
	 */
	protected static function def( $group, $label, $hint, array $caps, $scope, array $requires = array() ) {
		return array(
			'group'    => $group,
			'label'    => $label,
			'hint'     => $hint,
			'caps'     => $caps,
			'scope'    => $scope,
			'requires' => $requires,
		);
	}

	/**
	 * Der vollständige Katalog.
	 *
	 * @return array Schlüssel => Definition.
	 */
	public static function catalog() {
		$capture    = 'lw16_capture';
		$production = 'lw16_manage_production';
		$reports    = 'lw16_view_reports';
		$costs      = 'lw16_view_costs';
		$settings   = 'lw16_manage_settings';
		$users      = 'lw16_manage_users';

		$page  = self::SCOPE_PAGE;
		$cap   = self::SCOPE_CAP;
		$later = self::SCOPE_PHASE2;

		return array(
			// Verkauf.
			'verkauf.shop_orders'     => self::def( 'verkauf', __( 'Shop-Bestellungen', 'lindenweg16' ), __( 'WooCommerce-Bestellungen ansehen und bearbeiten. Braucht zusätzlich die WordPress-Rolle „Shop-Manager“.', 'lindenweg16' ), array(), $page ),
			'verkauf.shop_products'   => self::def( 'verkauf', __( 'Shop-Produkte', 'lindenweg16' ), __( 'Produkte im Shop pflegen. Braucht zusätzlich die WordPress-Rolle „Shop-Manager“.', 'lindenweg16' ), array(), $page ),
			'verkauf.orders'          => self::def( 'verkauf', __( 'Aufträge', 'lindenweg16' ), __( 'Betriebsaufträge zu Bestellungen: offene Aufträge, Positionen, Status.', 'lindenweg16' ), array( $production ), $page ),
			'verkauf.orders.status'   => self::def( 'verkauf', __( 'Auftragsstatus ändern', 'lindenweg16' ), __( 'Aufträge bestätigen, stornieren oder fertigmelden.', 'lindenweg16' ), array( $production ), $cap, array( 'verkauf.orders' ) ),
			'verkauf.versand'         => self::def( 'verkauf', __( 'Versand', 'lindenweg16' ), __( 'Picken, packen, Versandstatus.', 'lindenweg16' ), array( $capture ), $page ),
			'verkauf.picken'          => self::def( 'verkauf', __( 'Picklisten abarbeiten', 'lindenweg16' ), __( 'Native Seite: offene Picklisten ansehen, Positionen entnehmen und abhaken.', 'lindenweg16' ), array( $capture ), $page ),
			'verkauf.versand.etikett' => self::def( 'verkauf', __( 'Versandetiketten erzeugen', 'lindenweg16' ), __( 'DHL-Etiketten kaufen und drucken – kostet Geld.', 'lindenweg16' ), array( $capture ), $cap, array( 'verkauf.versand' ) ),
			'verkauf.kontakte'        => self::def( 'verkauf', __( 'Kontakte / B2B', 'lindenweg16' ), __( 'Geschäftskunden, Lieferanten, Konditionen.', 'lindenweg16' ), array( $production ), $page ),
			'verkauf.loyalty'         => self::def( 'verkauf', __( 'Loyalty', 'lindenweg16' ), __( 'Kundenbindung und Guthaben.', 'lindenweg16' ), array( 'lw16_mod_loyalty' ), $page ),
			'verkauf.loyalty.regeln'  => self::def( 'verkauf', __( 'Loyalty-Regeln einstellen', 'lindenweg16' ), __( 'Punkte je Euro, Bonuspunkte und Aktionen festlegen – gilt sofort für alle Kunden.', 'lindenweg16' ), array( 'lw16_mod_loyalty', $settings ), $cap, array( 'verkauf.loyalty' ) ),
			'verkauf.kasse'           => self::def( 'verkauf', __( 'Kasse', 'lindenweg16' ), __( 'Verkauf vor Ort.', 'lindenweg16' ), array( 'lw16_mod_kasse' ), $page ),
			'verkauf.marketing'       => self::def( 'verkauf', __( 'Marketing', 'lindenweg16' ), __( 'Kampagnen und Auswertung.', 'lindenweg16' ), array( 'lw16_mod_marketing' ), $page ),
			'verkauf.kurzlinks'       => self::def( 'verkauf', __( 'Kurzlinks', 'lindenweg16' ), __( 'Feste Adressen für Printmaterial verwalten, Aufrufe und Bestellungen nach Herkunft sehen.', 'lindenweg16' ), array( 'manage_woocommerce' ), $page ),

			// Fertigung.
			'fertigung.workflow'      => self::def( 'fertigung', __( 'Workflow: Platten abarbeiten', 'lindenweg16' ), __( 'Den Auftragsablauf sehen, Platten rüsten, drucken, per QR-Code am Handy bearbeiten und die Ist-Menge melden.', 'lindenweg16' ), array( $capture ), $page ),
			'fertigung.workflow.plan' => self::def( 'fertigung', __( 'Workflow: Plan freigeben', 'lindenweg16' ), __( 'Aus dem Bedarf Platten und Dateien erzeugen, Etiketten drucken, Platten abbrechen, Etikett und Hochrechnung einstellen.', 'lindenweg16' ), array( $production ), $cap, array( 'fertigung.workflow' ) ),
			'fertigung.artikel'       => self::def( 'fertigung', __( 'Artikel & Revisionen', 'lindenweg16' ), __( 'Artikel, Revisionen und Stücklisten.', 'lindenweg16' ), array( $production ), $page ),
			'fertigung.konfigurator'  => self::def( 'fertigung', __( 'Konfigurator', 'lindenweg16' ), __( 'Produktkonfigurator pflegen.', 'lindenweg16' ), array( $production ), $page ),
			'fertigung.sollist'       => self::def( 'fertigung', __( 'Soll gegen Ist', 'lindenweg16' ), __( 'Gestempelte Druck- und Rüstzeiten gegen die Planzeiten. Planzeit übernehmen braucht zusätzlich „Stammdaten ändern“, die Marge „Preise, Kosten und Löhne sehen“.', 'lindenweg16' ), array( $production ), $page ),
			'fertigung.tagesplan'     => self::def( 'fertigung', __( 'Tagesplan', 'lindenweg16' ), __( 'Was heute auf welchem Drucker läuft, welche Platten in die Nacht gehören und was am Tag zu bestücken ist.', 'lindenweg16' ), array( $production ), $page ),
			'fertigung.kapazitaet'    => self::def( 'fertigung', __( 'Kapazität', 'lindenweg16' ), __( 'Auslastung und Planung.', 'lindenweg16' ), array( $production ), $page ),
			'fertigung.qualitaet'     => self::def( 'fertigung', __( 'Qualität', 'lindenweg16' ), __( 'Chargen, Prüfungen, Reklamationen.', 'lindenweg16' ), array( $capture ), $page ),
			'fertigung.druckjournal'  => self::def( 'fertigung', __( 'Druckjournal', 'lindenweg16' ), __( 'Drucke protokollieren und nachsehen.', 'lindenweg16' ), array( $capture ), $page ),
			'fertigung.drucker'       => self::def( 'fertigung', __( '3D-Drucker & Wartung', 'lindenweg16' ), __( 'Geräte, Stundensätze, Wartung.', 'lindenweg16' ), array( $production ), $page ),
			'fertigung.bambu'         => self::def( 'fertigung', __( 'Bambu-Lab-Drucker', 'lindenweg16' ), __( 'Live-Anbindung der Drucker.', 'lindenweg16' ), array( $capture ), $page ),
			'fertigung.druckfarm'     => self::def( 'fertigung', __( 'Druckfarm', 'lindenweg16' ), __( 'Live-Status für die Werkstatt-Anzeige.', 'lindenweg16' ), array( $capture ), $page ),
			'fertigung.produktion'    => self::def( 'fertigung', __( 'Produktion', 'lindenweg16' ), __( 'Produktionsmodul.', 'lindenweg16' ), array( 'lw16_mod_produktion' ), $page ),
			'fertigung.auftraege.start' => self::def( 'fertigung', __( 'Aufträge starten und fertigmelden', 'lindenweg16' ), __( 'Druck- und Fertigungsaufträge buchen.', 'lindenweg16' ), array( $capture ), $cap ),
			'fertigung.stammdaten.edit' => self::def( 'fertigung', __( 'Stammdaten ändern', 'lindenweg16' ), __( 'Artikel, Stücklisten und Drucker anlegen und ändern – nicht nur ansehen.', 'lindenweg16' ), array( $production ), $cap, array( 'fertigung.artikel' ) ),

			// Lager.
			'lager.bestand'           => self::def( 'lager', __( 'Lager', 'lindenweg16' ), __( 'Bestände ansehen.', 'lindenweg16' ), array( $capture ), $page ),
			'lager.scannen'           => self::def( 'lager', __( 'Scannen', 'lindenweg16' ), __( 'Native Seite: Codes scannen (Kamera oder Handscanner) und sehen, was dort liegt.', 'lindenweg16' ), array( $capture ), $page ),
			'lager.lagerplaetze'      => self::def( 'lager', __( 'Lagerplätze & Picklisten', 'lindenweg16' ), __( 'Fächer, Fertigung und Picklisten.', 'lindenweg16' ), array( $capture ), $page ),
			'lager.material'          => self::def( 'lager', __( 'Material', 'lindenweg16' ), __( 'Filament und Zukaufteile.', 'lindenweg16' ), array( $production ), $page ),
			'lager.einkauf'           => self::def( 'lager', __( 'Einkauf', 'lindenweg16' ), __( 'Nachbestellen und Wareneingang: Bestellungen anlegen, eingegangene Ware buchen. Der Wareneingang erhöht den Bestand und braucht zusätzlich das Recht zum Lagerbuchen.', 'lindenweg16' ), array( $production ), $page ),
			'lager.bedarf'            => self::def( 'lager', __( 'Bedarf', 'lindenweg16' ), __( 'Bedarfsplanung und Nachbestellung.', 'lindenweg16' ), array( $production ), $page ),
			'lager.buchen'            => self::def( 'lager', __( 'Bestand buchen und korrigieren', 'lindenweg16' ), __( 'Zu- und Abgänge buchen, Inventurdifferenzen korrigieren – auch beim Scannen und in der mobilen Erfassung.', 'lindenweg16' ), array( $capture ), $cap, array( 'lager.bestand' ) ),

			// Finanzen.
			'finanzen.berichte'       => self::def( 'finanzen', __( 'Berichte', 'lindenweg16' ), __( 'Kennzahlen und Auswertungen.', 'lindenweg16' ), array( $reports ), $page ),
			'finanzen.prognose'       => self::def( 'finanzen', __( 'Prognose & Planung', 'lindenweg16' ), __( 'Umsatz, Bestellungen und Quoten mit Prognoseband gegen Ziele, Bedarfsprognose je Artikel, Lieferzusage und Auslöser.', 'lindenweg16' ), array( $reports ), $page ),
			'finanzen.buchhaltung'    => self::def( 'finanzen', __( 'Buchhaltung', 'lindenweg16' ), __( 'Belege, Buchungen, EÜR, GoBD.', 'lindenweg16' ), array( 'lw16_mod_finanzen' ), $page ),
			'finanzen.reporting'      => self::def( 'finanzen', __( 'Reporting', 'lindenweg16' ), __( 'Berichte des Buchhaltungsmoduls.', 'lindenweg16' ), array( 'lw16_mod_reporting' ), $page ),
			'finanzen.dashboard'      => self::def( 'finanzen', __( 'Dashboard Finanzen', 'lindenweg16' ), __( 'Steuer-Rücklage und Übersicht.', 'lindenweg16' ), array( 'lw16_mod_finanzen' ), $page ),
			'finanzen.kosten'         => self::def( 'finanzen', __( 'Preise, Kosten und Löhne sehen', 'lindenweg16' ), __( 'Kalkulation, Herstellkosten, Verkaufspreise. Ohne dieses Recht bleiben die Spalten leer.', 'lindenweg16' ), array( $costs ), $cap ),
			'finanzen.export'         => self::def( 'finanzen', __( 'Daten exportieren', 'lindenweg16' ), __( 'Auswertungen als Datei herunterladen.', 'lindenweg16' ), array( $reports ), $cap, array( 'finanzen.berichte' ) ),

			// Verwaltung.
			'verwaltung.zeit'         => self::def( 'verwaltung', __( 'Zeiterfassung', 'lindenweg16' ), __( 'Eigene Arbeitszeit erfassen.', 'lindenweg16' ), array( $capture ), $page ),
			'verwaltung.etiketten'    => self::def( 'verwaltung', __( 'Etikettendrucker', 'lindenweg16' ), __( 'Etiketten drucken, Warteschlange.', 'lindenweg16' ), array( $capture ), $page ),
			'verwaltung.einstellungen' => self::def( 'verwaltung', __( 'Einstellungen', 'lindenweg16' ), __( 'Kalkulationsgrundlagen, Ziele, Plugin- und DHL-Einstellungen.', 'lindenweg16' ), array( $settings ), $page ),
			'verwaltung.import'       => self::def( 'verwaltung', __( 'Import & Doku', 'lindenweg16' ), __( 'Datenimport und Verfahrensdokumentation.', 'lindenweg16' ), array( $settings ), $page ),
			'verwaltung.support'      => self::def( 'verwaltung', __( 'Support', 'lindenweg16' ), __( 'Kundenanfragen.', 'lindenweg16' ), array( 'lw16_mod_support' ), $page ),
			'verwaltung.anleitungen'  => self::def( 'verwaltung', __( 'Anleitungen', 'lindenweg16' ), __( 'Betriebsanleitungen.', 'lindenweg16' ), array( 'lw16_mod_anleitungen' ), $page ),
			'verwaltung.arview'       => self::def( 'verwaltung', __( 'AR-Ansicht', 'lindenweg16' ), __( '3D-/AR-Ansicht der Produkte im Shop einrichten.', 'lindenweg16' ), array( 'lw16_mod_arview' ), $page ),
			'verwaltung.mailbox'      => self::def( 'verwaltung', __( 'Mailbox', 'lindenweg16' ), __( 'Eigene und freigegebene Postfächer öffnen. Was je Postfach erlaubt ist, steht in der Mailbox selbst.', 'lindenweg16' ), array( 'lw16_mail_use' ), $page ),
			'verwaltung.mailbox_konten' => self::def( 'verwaltung', __( 'Postfächer verwalten', 'lindenweg16' ), __( 'Postfächer anlegen, Zugangsdaten und Freigaben ändern.', 'lindenweg16' ), array( 'lw16_mail_manage' ), $page, array( 'verwaltung.mailbox' ) ),
			'verwaltung.rechte'       => self::def( 'verwaltung', __( 'Rechte vergeben', 'lindenweg16' ), __( 'ERP-Rechte anderer Personen ändern – nur solche, die man selbst hat.', 'lindenweg16' ), array( $users ), $page ),
			'verwaltung.loehne'       => self::def( 'verwaltung', __( 'Löhne & Protokoll', 'lindenweg16' ), __( 'Stundenlöhne pflegen, Protokoll aller Nutzer einsehen.', 'lindenweg16' ), array( $users ), $page ),
		);
	}

	/**
	 * Gibt es dieses Recht?
	 *
	 * @param string $key Schlüssel.
	 * @return bool
	 */
	public static function exists( $key ) {
		return array_key_exists( (string) $key, self::catalog() );
	}

	/**
	 * Lässt sich das Recht einzeln vergeben?
	 *
	 * @param string $key Schlüssel.
	 * @return bool
	 */
	public static function grantable( $key ) {
		$catalog = self::catalog();

		return isset( $catalog[ $key ] ) && self::SCOPE_FULL !== $catalog[ $key ]['scope'];
	}

	/**
	 * Alle einzeln vergebbaren Schlüssel.
	 *
	 * @return string[]
	 */
	public static function grantable_keys() {
		return array_values( array_filter( array_keys( self::catalog() ), array( __CLASS__, 'grantable' ) ) );
	}

	/**
	 * Eine Auswahl bereinigen: Unbekanntes und nicht Vergebbares fliegt
	 * raus, Voraussetzungen kommen dazu, Duplikate verschwinden, die
	 * Reihenfolge folgt dem Katalog.
	 *
	 * Wer „Bestand buchen“ bekommt, braucht „Lager“ – ohne die Seite
	 * wäre das Recht nicht benutzbar. Statt still ein totes Recht zu
	 * speichern, kommt die Seite dazu.
	 *
	 * @param array $keys Gewählte Schlüssel.
	 * @return string[]
	 */
	public static function normalize( array $keys ) {
		$catalog = self::catalog();
		$have    = array();

		$add = function ( $key ) use ( &$add, &$have, $catalog ) {
			if ( ! self::grantable( $key ) || isset( $have[ $key ] ) ) {
				return;
			}
			$have[ $key ] = true;
			foreach ( $catalog[ $key ]['requires'] as $required ) {
				$add( $required );
			}
		};

		foreach ( $keys as $key ) {
			$add( (string) $key );
		}

		return array_values( array_intersect( array_keys( $catalog ), array_keys( $have ) ) );
	}

	/**
	 * Alte Betrieb-Rechte, die diese Auswahl voraussetzt.
	 *
	 * @param array $keys Gewählte Schlüssel.
	 * @return string[] Einmalig, in der Reihenfolge von AccessProfiles.
	 */
	public static function caps_for( array $keys ) {
		$catalog = self::catalog();
		$caps    = array();

		foreach ( self::normalize( $keys ) as $key ) {
			foreach ( $catalog[ $key ]['caps'] as $cap ) {
				$caps[ $cap ] = true;
			}
		}

		return array_values( array_intersect( self::all_caps(), array_keys( $caps ) ) );
	}

	/**
	 * Alle alten Rechte, die ein feines Recht voraussetzt – die sechs
	 * Betrieb-Rechte zuerst, dann die Modul- und Mailbox-Rechte.
	 *
	 * @return string[]
	 */
	public static function all_caps() {
		$caps = array(
			'lw16_capture',
			'lw16_manage_production',
			'lw16_view_reports',
			'lw16_view_costs',
			'lw16_manage_settings',
			'lw16_manage_users',
		);

		foreach ( self::catalog() as $def ) {
			foreach ( $def['caps'] as $cap ) {
				if ( ! in_array( $cap, $caps, true ) ) {
					$caps[] = $cap;
				}
			}
		}

		return $caps;
	}

	/**
	 * Aus alten Betrieb-Rechten ableiten, was jemand im ERP darf.
	 *
	 * Gebraucht für Nutzer, die vor der neuen Oberfläche eingerichtet
	 * wurden: Sie sollen sehen, was sie bisher konnten – nicht mehr, nicht
	 * weniger. Rechte ohne alte Entsprechung (Shop) gehören nie dazu.
	 *
	 * @param array $caps Vorhandene alte Rechte.
	 * @return string[]
	 */
	public static function derive_from_caps( array $caps ) {
		$keys = array();

		foreach ( self::catalog() as $key => $def ) {
			if ( self::SCOPE_FULL === $def['scope'] || ! $def['caps'] ) {
				continue;
			}
			if ( ! array_diff( $def['caps'], $caps ) ) {
				$keys[] = $key;
			}
		}

		return self::normalize( $keys );
	}

	/**
	 * Vorlagen als Ausgangspunkt – nach dem Anwenden bleibt alles einzeln
	 * änderbar.
	 *
	 * @return array Kennung => [label, hint, keys].
	 */
	public static function presets() {
		$shop = array( 'verkauf.shop_orders', 'verkauf.shop_products' );

		return array(
			'keine'       => array(
				'label' => __( 'Kein Zugriff', 'lindenweg16' ),
				'hint'  => __( 'Sieht das ERP nicht.', 'lindenweg16' ),
				'keys'  => array(),
			),
			'werkstatt'   => array(
				'label' => __( 'Werkstatt', 'lindenweg16' ),
				'hint'  => __( 'Drucke protokollieren, Zeit erfassen, Lager einsehen. Keine Stammdaten, kein Geld.', 'lindenweg16' ),
				'keys'  => array( 'fertigung.workflow', 'fertigung.druckjournal', 'fertigung.qualitaet', 'fertigung.druckfarm', 'fertigung.bambu', 'fertigung.auftraege.start', 'lager.bestand', 'lager.scannen', 'lager.buchen', 'lager.lagerplaetze', 'verwaltung.zeit' ),
			),
			'packtisch'   => array(
				'label' => __( 'Packtisch', 'lindenweg16' ),
				'hint'  => __( 'Picken, packen, versenden und Etiketten drucken.', 'lindenweg16' ),
				'keys'  => array( 'verkauf.versand', 'verkauf.versand.etikett', 'verkauf.picken', 'lager.bestand', 'lager.scannen', 'lager.lagerplaetze', 'verwaltung.etiketten', 'verwaltung.zeit' ),
			),
			'fertigung'   => array(
				'label' => __( 'Fertigung', 'lindenweg16' ),
				'hint'  => __( 'Wie Werkstatt, dazu Stammdaten, Material, Bedarf und Berichte – aber kein Geld.', 'lindenweg16' ),
				'keys'  => array( 'fertigung.workflow', 'fertigung.workflow.plan', 'fertigung.sollist', 'fertigung.tagesplan', 'fertigung.artikel', 'fertigung.konfigurator', 'fertigung.kapazitaet', 'fertigung.qualitaet', 'fertigung.druckjournal', 'fertigung.drucker', 'fertigung.bambu', 'fertigung.druckfarm', 'fertigung.auftraege.start', 'fertigung.stammdaten.edit', 'lager.bestand', 'lager.scannen', 'lager.lagerplaetze', 'lager.material', 'lager.bedarf', 'lager.einkauf', 'lager.buchen', 'finanzen.berichte', 'verwaltung.zeit' ),
			),
			'leitung'     => array(
				'label' => __( 'Betriebsleitung', 'lindenweg16' ),
				'hint'  => __( 'Alles Fachliche samt Shop, Kosten und Berichten. Keine Grundeinstellungen, keine Rechtevergabe.', 'lindenweg16' ),
				'keys'  => array_merge(
					$shop,
					array( 'verkauf.orders', 'verkauf.orders.status', 'verkauf.versand', 'verkauf.versand.etikett', 'verkauf.picken', 'verkauf.kontakte' ),
					array( 'fertigung.workflow', 'fertigung.workflow.plan', 'fertigung.sollist', 'fertigung.tagesplan', 'fertigung.artikel', 'fertigung.konfigurator', 'fertigung.kapazitaet', 'fertigung.qualitaet', 'fertigung.druckjournal', 'fertigung.drucker', 'fertigung.bambu', 'fertigung.druckfarm', 'fertigung.auftraege.start', 'fertigung.stammdaten.edit' ),
					array( 'lager.bestand', 'lager.scannen', 'lager.lagerplaetze', 'lager.material', 'lager.bedarf', 'lager.einkauf', 'lager.buchen' ),
					array( 'finanzen.berichte', 'finanzen.prognose', 'finanzen.kosten', 'finanzen.export' ),
					array( 'verwaltung.zeit', 'verwaltung.etiketten' )
				),
			),
			'vollzugriff' => array(
				'label' => __( 'Alles einzeln vergebbare', 'lindenweg16' ),
				'hint'  => __( 'Jedes einzeln vergebbare Recht – bis auf Rechte und Einstellungen sollte das nur die Leitung bekommen.', 'lindenweg16' ),
				'keys'  => self::grantable_keys(),
			),
		);
	}

	/**
	 * Welche Vorlage passt genau zu dieser Auswahl?
	 *
	 * Passt keine, bleibt die Antwort leer – ein ungefähr passendes
	 * Etikett anzuzeigen wäre eine Falschaussage.
	 *
	 * @param array $keys Auswahl.
	 * @return string
	 */
	public static function match_preset( array $keys ) {
		$have = self::normalize( $keys );

		foreach ( self::presets() as $id => $preset ) {
			if ( self::normalize( $preset['keys'] ) === $have ) {
				return $id;
			}
		}

		return '';
	}
}
