<?php
/**
 * Verfahrensdokumentation des Moduls Betrieb.
 *
 * Ein Prüfungsdokument, kein Nice-to-have: Es beschreibt die aktuelle
 * Konfiguration, das Datenmodell, die Berechnungsformeln und die Prozesse.
 * Die Werte werden zum Zeitpunkt der Erzeugung aus dem System gelesen, damit
 * das Dokument den tatsächlichen Stand zeigt und keine Abschrift ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Baut die Zeilen der Verfahrensdokumentation.
 */
class VerfahrensDokuBetrieb {

	/**
	 * Alle Zeilen als [Typ, Text].
	 *
	 * @return array
	 */
	public static function lines() {
		$lines = array(
			array( 'h1', __( 'Verfahrensdokumentation – Modul Betrieb', 'lindenweg16' ) ),
			array(
				'p',
				sprintf(
					/* translators: 1: Firma, 2: Datum. */
					__( '%1$s · Stand %2$s', 'lindenweg16' ),
					(string) Settings::get( 'firma_name' ),
					gmdate( 'd.m.Y' )
				),
			),
			array( 'p', __( 'Dieses Dokument beschreibt, wie Fertigungs-, Lager- und Vertriebsdaten erfasst, verarbeitet und aufbewahrt werden.', 'lindenweg16' ) ),
		);

		$lines = array_merge( $lines, self::processes(), self::data_model(), self::formulas(), self::assumptions(), self::gobd() );

		return $lines;
	}

	/**
	 * Prozessbeschreibung.
	 *
	 * @return array
	 */
	protected static function processes() {
		return array(
			array( 'h2', __( '1. Prozesse', 'lindenweg16' ) ),
			array( 'p', __( 'Druckjob: Erfassung am Gerät über die mobile Schnellerfassung oder im Backend. Mit dem Buchen entstehen automatisch ein Lagerzugang über die guten Teile, eine Charge und der Verbrauch der Kleinteile aus der Stückliste.', 'lindenweg16' ) ),
			array( 'p', __( 'Verkauf über den Shop: Beim Statuswechsel auf „in Bearbeitung" entsteht der Betriebsdatensatz, beim Abschluss die Lagerabgänge je Position und Farbe. Lässt sich die Farbe nicht bestimmen, wird die Bewegung als ungeklärt gebucht und im Dashboard gemeldet – sie verschwindet nie stillschweigend.', 'lindenweg16' ) ),
			array( 'p', __( 'Verkauf außerhalb des Shops: Manuelle Bestellerfassung mit Kontakt, Positionen und aufgelöster Rabattstaffel. Der ermittelte Rabatt wird auf der Bestellung festgeschrieben.', 'lindenweg16' ) ),
			array( 'p', __( 'Kommission: Auslieferung, Abrechnung und Rücknahme werden als Bewegungspaare gebucht. Der Gesamtbestand über alle Lagerorte bleibt dabei konstant; nur der abgerechnete Teil verlässt ihn.', 'lindenweg16' ) ),
			array( 'p', __( 'Nachbestell-Link: Händler können über einen persönlichen Link eine Anfrage stellen. Diese entsteht ausschließlich als Entwurf; Lagerabgang und offener Posten entstehen erst mit der Bestätigung im Backend.', 'lindenweg16' ) ),
			array( 'p', __( 'Fertigung von Halbzeugen: Zwischen Rohling und verkaufsfertigem Artikel liegen Arbeitsschritte, die kein Druckjob sind. Sie laufen über einen Fertigungsauftrag. Mit dem Fertigmelden entsteht der Zugang des gefertigten Artikels und der Abgang seiner direkten Stückliste – nur der ersten Ebene, denn was darunter liegt, hat sich beim eigenen Auftrag bereits abgebucht.', 'lindenweg16' ) ),
			array( 'p', __( 'Kommissionierung: Picklisten entstehen aus offenen Bestellungen oder aus einem Fertigungsauftrag, sortiert nach Lagerplatz. Das Abhaken bucht bewusst keinen Lagerabgang – der entsteht genau einmal beim Bestellabschluss beziehungsweise beim Fertigmelden. Was das Picken beiträgt, ist die Ortsangabe.', 'lindenweg16' ) ),
			array( 'p', __( 'Bedarfsermittlung: Bedarfe aus offenen Bestellungen, aus der Unterdeckung gegenüber dem Sollbestand und – getrennt ausgewiesen – aus der Wunschliste werden über alle Fertigungsstufen nach unten gerechnet und dabei gegen Bestand und laufende Aufträge verrechnet.', 'lindenweg16' ) ),
			array( 'p', __( 'Marktverkauf: Verkäufe an der Kasse buchen über dieselbe Zuständigkeitsweiche wie Shop-Bestellungen. Der Storno läuft über denselben Weg, damit Verkauf und Storno nie in verschiedenen Lagerverwaltungen landen.', 'lindenweg16' ) ),
			array( 'p', __( 'Korrektur: Druckjobs, Lagerbewegungen und Chargen werden nicht gelöscht. Korrekturen erfolgen ausschließlich durch Storno-Gegenbuchung mit Begründung und Verweis auf den Ursprungssatz.', 'lindenweg16' ) ),
			array( 'p', __( 'Zurücksetzen vor dem Livegang: Probedaten lassen sich einmalig abräumen. Der Vorgang ist zweistufig, lässt Stammdaten unberührt und wird mit Zeitpunkt, Benutzer und Mengen dauerhaft protokolliert. Nach Aufnahme des echten Betriebs wird er nicht mehr verwendet.', 'lindenweg16' ) ),
		);
	}

	/**
	 * Datenmodell.
	 *
	 * @return array
	 */
	protected static function data_model() {
		$lines = array(
			array( 'h2', __( '2. Datenmodell', 'lindenweg16' ) ),
			array( 'p', __( 'Eigene Datenbanktabellen mit dem Präfix lw16_, angelegt über dbDelta mit versionierter Migration. Keine Custom Post Types: Es handelt sich um Journaldaten mit fester Feldstruktur.', 'lindenweg16' ) ),
		);

		$tables = array(
			'articles'          => __( 'Artikelstamm mit Beschaffungsweg (Druck, Zukauf, Hybrid)', 'lindenweg16' ),
			'article_revisions' => __( 'Versioniertes Druckprofil je Artikel; nur eine Revision ist aktiv', 'lindenweg16' ),
			'article_sourcing'  => __( 'Beschaffungsdaten, historisiert über valid_from', 'lindenweg16' ),
			'components'        => __( 'Kleinteile mit Gebindegröße; der Stückpreis wird gerechnet', 'lindenweg16' ),
			'bom'               => __( 'Stückliste, rekursiv über Artikel und Komponenten', 'lindenweg16' ),
			'printers'          => __( 'Geräte der Druckfarm', 'lindenweg16' ),
			'print_jobs'        => __( 'Druckjournal; Ausschuss und Quoten werden gerechnet', 'lindenweg16' ),
			'filament_lots'     => __( 'Filamentchargen; Verbrauch aus den Druckjobs', 'lindenweg16' ),
			'batches'           => __( 'Chargenbuch für die Rückverfolgbarkeit', 'lindenweg16' ),
			'stock_movements'   => __( 'Lagerbestand als Bewegungskonto; es gibt kein Bestandsfeld', 'lindenweg16' ),
			'order_ops'         => __( 'Betriebliche Zusatzdaten zu Bestellungen', 'lindenweg16' ),
			'contacts'          => __( 'Händler, Vereine, Leads mit Aktivitäten und Konditionen', 'lindenweg16' ),
			'calc_settings'     => __( 'Kalkulationsannahmen, historisiert über valid_from', 'lindenweg16' ),
			'audit_log'         => __( 'Hashverkettetes Protokoll aller Änderungen', 'lindenweg16' ),

			// Fertigung zwischen Rohling und fertigem Artikel.
			'work_orders'       => __( 'Fertigungsaufträge für Halbzeuge und Baugruppen', 'lindenweg16' ),
			'maintenance_log'   => __( 'Wartungsprotokoll je Gerät', 'lindenweg16' ),
			'supplies'          => __( 'Verbrauchsmaterial ohne eigene Stückliste', 'lindenweg16' ),
			'purchases'         => __( 'Nachbestellungen bei Lieferanten; der Bestand steigt erst mit dem Wareneingang, und der lässt sich je Bestellung nur einmal buchen', 'lindenweg16' ),

			// Lagerort und Kommissionierung.
			'storage_bins'      => __( 'Lagerplätze; der Platz hängt an der Bewegung, nicht als zweites Bestandsfeld', 'lindenweg16' ),
			'stock_targets'     => __( 'Sollbestände je Artikel und Farbe', 'lindenweg16' ),
			'picklists'         => __( 'Picklisten aus Bestellungen oder Fertigungsaufträgen', 'lindenweg16' ),
			'picklist_items'    => __( 'Positionen einer Pickliste; das Abhaken bucht nicht, es hält den Ort fest', 'lindenweg16' ),

			// Verkauf und Konditionen.
			'order_op_items'    => __( 'Positionen manuell erfasster Bestellungen', 'lindenweg16' ),
			'price_tiers'       => __( 'Standard-Rabattstaffel, historisiert über valid_from', 'lindenweg16' ),
			'bundles'           => __( 'Benannte Bestellarten für den Vergleich der Preisebenen', 'lindenweg16' ),
			'bundle_items'      => __( 'Positionen einer Bestellart', 'lindenweg16' ),

			// Kontakte.
			'contact_activities' => __( 'Aktivitäten je Kontakt mit Wiedervorlage', 'lindenweg16' ),
			'contact_terms'     => __( 'Sonderkonditionen je Kontakt, historisiert über valid_from', 'lindenweg16' ),
			'contact_profiles'  => __( 'Bevorzugte Artikel und Vorbelegungen je Kontakt', 'lindenweg16' ),
			'reorder_tokens'    => __( 'Nachbestell-Links; gespeichert wird nur der Hash, nie der Link', 'lindenweg16' ),

			// Auswertung.
			'time_entries'      => __( 'Zeiterfassung je Woche und Tätigkeit, bei Auftragszeiten mit dem zugehörigen Fertigungsauftrag', 'lindenweg16' ),
			'user_rates'        => __( 'Stundenlöhne je Nutzer mit Stichtag; frühere Sätze bleiben stehen, damit vergangene Kosten vergangene Kosten bleiben', 'lindenweg16' ),
			'capacity_weeks'    => __( 'Kapazitätsannahmen je Kalenderwoche', 'lindenweg16' ),
			'kpi_snapshots'     => __( 'Festgehaltene Kennzahlen je Zeitraum', 'lindenweg16' ),
		);

		foreach ( $tables as $table => $description ) {
			$lines[] = array( 'p', sprintf( 'lw16_%s — %s', $table, $description ) );
		}

		return $lines;
	}

	/**
	 * Berechnungsformeln.
	 *
	 * @return array
	 */
	protected static function formulas() {
		return array(
			array( 'h2', __( '3. Berechnungsformeln', 'lindenweg16' ) ),
			array( 'p', __( 'Keine berechnete Größe wird gespeichert. Jede Auswertung rechnet gegen die zum Stichtag gültigen Annahmen.', 'lindenweg16' ) ),
			array( 'p', __( 'Stückpreis Komponente = Gebindepreis netto / Gebindegröße', 'lindenweg16' ) ),
			array( 'p', __( 'Druckzeit je Teil = Druckzeit der Platte / Teile je Platte', 'lindenweg16' ) ),
			array( 'p', __( 'Maschinenstundensatz = Anschaffung / Lebensdauer + Ersatzteile je Jahr / Betriebsstunden je Jahr + Stromkosten je Stunde + Flächenkosten je Stunde', 'lindenweg16' ) ),
			array( 'p', __( 'Herstellkosten = Eigenmaterial + Maschinenzeit + Komponenten + Unterbaugruppen (rekursiv, höchstens fünf Ebenen)', 'lindenweg16' ) ),
			array( 'p', __( 'Deckungsbeitrag I = Nettoerlös − (Herstellkosten + Ausschusszuschlag + Verpackung + Zahlungsgebühr)', 'lindenweg16' ) ),
			array( 'p', __( 'Deckungsbeitrag II = Deckungsbeitrag I − Nachbearbeitungszeit × kalkulatorischem Stundensatz', 'lindenweg16' ) ),
			array( 'p', __( 'Beim Set fallen Verpackung und Zahlungsgebühr genau einmal je Bestellung an, nicht je Position.', 'lindenweg16' ) ),
			array( 'p', __( 'Ausschussquote = (geplante Teile − gute Teile) / geplante Teile', 'lindenweg16' ) ),
			array( 'p', __( 'Reichweite in Tagen = Bestand / durchschnittlicher Tagesverbrauch der letzten vier Wochen', 'lindenweg16' ) ),
			array( 'p', __( 'Preisvorschlag zu einer Zielmarge: Der Bruttopreis wird rückwärts aus der Deckungsbeitragsrechnung bestimmt. Ein Aufschlag genügt nicht, weil die variable Zahlungsgebühr am Preis selbst hängt; die Gleichung wird deshalb nach dem Nettoerlös aufgelöst.', 'lindenweg16' ) ),
			array( 'p', __( 'Kalenderwochen nach ISO 8601: Woche 1 enthält den ersten Donnerstag des Jahres; das ISO-Jahr weicht am Jahreswechsel vom Kalenderjahr ab.', 'lindenweg16' ) ),
		);
	}

	/**
	 * Aktuelle Annahmen.
	 *
	 * @return array
	 */
	protected static function assumptions() {
		$lines    = array( array( 'h2', __( '4. Aktuelle Kalkulationsannahmen', 'lindenweg16' ) ) );
		$settings = CalcSettings::for_date();
		$labels   = CalcSettings::labels();

		foreach ( $labels as $key => $label ) {
			$lines[] = array(
				'p',
				sprintf( '%s: %s', $label, number_format_i18n( isset( $settings[ $key ] ) ? $settings[ $key ] : 0, 4 ) ),
			);
		}

		$rate    = MachineRate::current_breakdown();
		$lines[] = array(
			'p',
			sprintf(
				/* translators: 1: Satz, 2: Geräte. */
				__( 'Daraus berechneter Maschinenstundensatz: %1$s €/h (Mittel über %2$d Geräte)', 'lindenweg16' ),
				number_format_i18n( $rate['rate'], 4 ),
				(int) $rate['printer_count']
			),
		);

		$lines[] = array( 'p', __( 'Änderungen an Annahmen überschreiben keine Werte: Jede Änderung erzeugt eine neue Zeile mit eigenem Gültigkeitsdatum. Alte Kalkulationen bleiben damit nachrechenbar.', 'lindenweg16' ) );

		return $lines;
	}

	/**
	 * GoBD-Abschnitt.
	 *
	 * @return array
	 */
	protected static function gobd() {
		$errors = BetriebAudit::verify_chain();

		return array(
			array( 'h2', __( '5. Unveränderbarkeit und Nachvollziehbarkeit', 'lindenweg16' ) ),
			array( 'p', __( 'Jede Änderung an Stammdaten, Journaldaten und Kalkulationsannahmen wird protokolliert: Zeitpunkt, Benutzer, Tabelle, Datensatz, Zustand vorher und nachher sowie die Begründung.', 'lindenweg16' ) ),
			array( 'p', __( 'Die Protokolleinträge sind über SHA-256 verkettet: Jeder Eintrag enthält den Hash seines Vorgängers. Eine nachträgliche Änderung bricht die Kette an dieser Stelle und ist damit erkennbar.', 'lindenweg16' ) ),
			array(
				'p',
				$errors
					? sprintf(
						/* translators: %d: Datensatz-ID. */
						__( 'Prüfung zum Zeitpunkt der Erstellung: Bruch bei Datensatz %d.', 'lindenweg16' ),
						(int) $errors[0]['id']
					)
					: __( 'Prüfung zum Zeitpunkt der Erstellung: Kette unversehrt.', 'lindenweg16' ),
			),
			array( 'p', __( 'Zugriffsschutz: Der Zugriff auf das Modul ist an eigene Berechtigungen gebunden (Produktion, Auswertungen, Einstellungen). Die öffentliche Nachbestell-Seite arbeitet mit zufälligen Token, von denen nur der Hash gespeichert wird; sie zeigt keine Bestands-, Umsatz- oder Kundendaten und kann ausschließlich Entwürfe erzeugen.', 'lindenweg16' ) ),
			array( 'p', __( 'Aufbewahrung: Datensätze werden nicht gelöscht. Stornierte Sätze bleiben mit Kennzeichnung und Begründung erhalten.', 'lindenweg16' ) ),
		);
	}
}
