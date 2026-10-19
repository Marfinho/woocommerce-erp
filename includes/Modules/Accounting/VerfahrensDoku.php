<?php
/**
 * GoBD-Verfahrensdokumentation als PDF.
 *
 * Erzeugt eine schriftliche Beschreibung, wie das System Belege erfasst,
 * verarbeitet und archiviert – von der GoBD gefordert.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Pdf;
use Lindenweg16\Core\AuditLog;

/**
 * Generiert die Verfahrensdokumentation als PDF.
 */
class VerfahrensDoku {

	/**
	 * PDF erzeugen und als Download ausliefern.
	 */
	public static function download() {
		$pdf = new Pdf();
		$pdf->enable_ci();

		$firma  = (string) Settings::get( 'firma_name' );
		$datum  = gmdate( 'd.m.Y' );

		// Titel.
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->text( 'Verfahrensdokumentation', 22, true );
		$pdf->set_color( '#6E7882' );
		$pdf->text( 'gemäß GoBD (BMF-Schreiben, zuletzt aktualisiert 14.07.2025)', 10 );
		$pdf->text( $firma . ' – Stand: ' . $datum, 10 );
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->space( 12 );

		// 1. Allgemeine Beschreibung.
		self::section( $pdf, '1. Allgemeine Beschreibung' );
		self::para( $pdf, sprintf(
			'%s nutzt das Plugin „Lindenweg16 ERP" (Version %s) als integriertes '
			. 'Buchhaltungs- und Warenwirtschaftssystem innerhalb von WordPress/WooCommerce. '
			. 'Das Plugin übernimmt die Belegerfassung, Rechnungsstellung, das Buchungsjournal, '
			. 'die Einnahmen-Überschuss-Rechnung (EÜR) sowie die Archivierung digitaler Belege.',
			$firma,
			defined( 'LW16_VERSION' ) ? LW16_VERSION : '0.x'
		) );
		if ( (int) Settings::get( 'kleinunternehmer', 1 ) === 1 ) {
			self::para( $pdf, 'Der Betrieb wendet die Kleinunternehmerregelung nach §19 UStG an. '
				. 'Rechnungen werden ohne Umsatzsteuerausweis mit entsprechendem Pflichthinweis erstellt. '
				. 'Die Gewinnermittlung erfolgt als EÜR nach §4 Abs. 3 EStG.' );
		}

		// 2. Belegerfassung.
		self::section( $pdf, '2. Belegerfassung' );
		self::para( $pdf, 'Ausgabenbelege werden auf zwei Wegen erfasst:' );
		self::bullet( $pdf, 'Manuell: Beleg-Foto hochladen, Betrag, Datum, Händler, Aufwandskonto eingeben.' );
		self::bullet( $pdf, 'Automatisch: Bei Wareneingang wird ein Beleg mit Verknüpfung zum Einkauf erzeugt.' );
		self::bullet( $pdf, 'E-Rechnung: Upload von XRechnung (XML) oder ZUGFeRD (PDF+XML), automatische Datenextraktion.' );
		self::bullet( $pdf, 'Eigenbeleg: Für Fahrten mit dem privaten Fahrzeug erzeugt das System aus den '
			. 'erfassten Kilometern und der hinterlegten Pauschale einen Eigenbeleg auf dem Reisekostenkonto. '
			. 'Grundlage, Kilometerzahl, Satz und Anlass stehen auf dem Beleg. Eine Korrektur der '
			. 'Kilometerangabe storniert den bisherigen Eigenbeleg und bucht einen neuen; überschrieben '
			. 'wird nichts.' );
		self::para( $pdf, 'Einnahmen werden automatisch aus abgeschlossenen WooCommerce-Bestellungen ermittelt. '
			. 'Für jede abgeschlossene Bestellung wird eine Rechnung mit lückenloser Rechnungsnummer erstellt.' );
		self::para( $pdf, 'Zeitgerechte Erfassung: Die GoBD-Prüffunktion überwacht, ob Belege innerhalb von '
			. '10 Tagen nach Belegdatum erfasst werden.' );

		// 2a. Kontierung.
		self::section( $pdf, '2a. Kontierung: Kostenart und Kostenstelle' );
		self::para( $pdf, 'Jeder Ausgabenbeleg trägt zwei voneinander unabhängige Merkmale. '
			. 'Die Kostenart ist ein Konto aus dem Standardkontenrahmen SKR03 (kuratierter Auszug) '
			. 'und bestimmt die Zuordnung in der Einnahmen-Überschuss-Rechnung. '
			. 'Die Kostenstelle ist eine Kampagne im Format K-JJJJ-NNN und dient ausschließlich der '
			. 'innerbetrieblichen Auswertung; sie hat keine steuerliche Wirkung.' );
		self::para( $pdf, 'Nicht abziehbare Anteile werden über das Konto abgebildet: Bewirtungsaufwendungen '
			. 'auf Konto 4650 gehen nur zu 70 Prozent in die Betriebsausgaben ein. Die Kürzung wird in der '
			. 'EÜR-Auswertung gesondert ausgewiesen, der Belegbetrag bleibt unverändert.' );
		self::para( $pdf, 'Die Kostenstellenzuordnung liegt in einer eigenen Tabelle und lässt den '
			. 'festgeschriebenen Beleg unberührt. Sie kann nachträglich korrigiert und über einen '
			. 'Prozentanteil auf mehrere Kostenstellen verteilt werden; in Summe nie über 100 Prozent. '
			. 'Jede Zuordnung und jede Änderung steht im Audit-Log.' );
		self::para( $pdf, 'Belege aus der Zeit vor Einführung des Kontenrahmens behalten ihre '
			. 'ursprüngliche Kategoriebezeichnung; das Konto wurde einmalig daraus abgeleitet und in '
			. 'einem eigenen Feld ergänzt. Die Hash-Kette der Belege blieb dabei unverändert.' );

		// 3. Nummernvergabe.
		self::section( $pdf, '3. Nummernvergabe' );
		$modus = Settings::get( 'rechnungsnummer_modus', 'jahr' );
		self::para( $pdf, sprintf(
			'Rechnungsnummern: Modus „%s" – Format %s, fortlaufend, lückenlos.',
			$modus,
			'jahr' === $modus ? 'JJJJ-NNNN (z. B. 2026-0001)' : 'RE-NNNNN (z. B. RE-00001)'
		) );
		self::para( $pdf, 'Belegnummern: Format B-JJJJ-NNNN (z. B. B-2026-0001), fortlaufend pro Kalenderjahr.' );
		self::para( $pdf, 'Buchungsnummern: Format BU-JJJJ-NNNN (z. B. BU-2026-0001), fortlaufend pro Kalenderjahr.' );
		self::para( $pdf, 'Kostenstellennummern: Format K-JJJJ-NNN (z. B. K-2026-004), fortlaufend pro '
			. 'Kalenderjahr. Rein innerbetrieblich, ohne steuerliche Wirkung.' );
		self::para( $pdf, 'Die Lückenlosigkeit aller Nummernkreise wird durch die GoBD-Prüffunktion überwacht.' );

		// 4. Unveränderbarkeit.
		self::section( $pdf, '4. Unveränderbarkeit' );
		self::para( $pdf, 'Append-only-Prinzip: Buchungssätze, Rechnungen und festgeschriebene Belege werden '
			. 'niemals per UPDATE oder DELETE verändert. Korrekturen laufen ausschließlich über Stornobuchungen '
			. '(Gegenbuchung + ggf. neue korrekte Buchung).' );
		self::para( $pdf, 'Hash-Verkettung: Jeder Datensatz in den Tabellen Belege, Rechnungen, Buchungsjournal '
			. 'und Audit-Log enthält einen SHA-256-Hash aus seinem Inhalt und dem Hash des Vorgänger-Datensatzes. '
			. 'Nachträgliche Manipulation bricht die Kette sichtbar.' );
		self::para( $pdf, 'Festschreibung: Belege werden nach Prüfung durch den Nutzer festgeschrieben. '
			. 'Danach sind nur noch Stornierungen möglich, keine inhaltlichen Änderungen.' );

		// 5. Archivierung.
		self::section( $pdf, '5. Archivierung und Aufbewahrung' );
		self::para( $pdf, 'Beleg-Fotos und PDF-Rechnungen werden in geschützten Verzeichnissen gespeichert '
			. '(außerhalb des öffentlichen Uploads-Ordners). Der Zugriff erfolgt ausschließlich über '
			. 'authentifizierte Endpunkte mit Berechtigungsprüfung (Capability lw16_mod_finanzen).' );
		self::para( $pdf, '.htaccess-Schutz verhindert den direkten Zugriff auf Belegdateien über den Webserver.' );
		self::para( $pdf, 'Aufbewahrungsfristen: Rechnungen 10 Jahre (§14b UStG), '
			. 'sonstige Buchungsbelege 8 Jahre (§147 AO n.F.). '
			. 'Die Frist beginnt mit Schluss des Kalenderjahres der letzten Eintragung.' );
		self::para( $pdf, 'E-Rechnungen im XRechnung-/ZUGFeRD-Format werden als Original-Dateien '
			. 'archiviert (nicht nur als Ausdruck).' );

		$pdf->add_page();

		// 6. Zugriffsrechte.
		self::section( $pdf, '6. Zugriffsrechte' );
		self::para( $pdf, 'Rollenbasierte Berechtigungen: Das Plugin definiert die Capability „lw16_mod_finanzen" '
			. 'für die Buchhaltungsfunktionen. Sie wird pro Person in der Rechteverwaltung der '
			. 'ERP-Oberfläche vergeben (jede Änderung steht im Audit-Log). Die Sammel-Capability '
			. '„manage_lw16" (Rolle „lw16_manager", Administratoren) schließt sie ein.' );
		self::para( $pdf, 'Authentifizierung: WordPress-Benutzeranmeldung (keine geteilten Logins). '
			. 'Alle Aktionen werden mit der Benutzer-ID im Audit-Log protokolliert.' );
		self::para( $pdf, 'Nonce-Prüfung: Alle schreibenden Aktionen sind durch WordPress-Nonces '
			. 'gegen CSRF-Angriffe geschützt.' );

		// 7. Änderungsprotokoll.
		self::section( $pdf, '7. Änderungsprotokoll (Audit-Log)' );
		self::para( $pdf, 'Jede steuerrelevante Aktion wird im Audit-Log protokolliert:' );
		self::bullet( $pdf, 'Wer: WordPress-Benutzer-ID des Ausführenden' );
		self::bullet( $pdf, 'Wann: Systemzeitstempel (nicht manuell änderbar)' );
		self::bullet( $pdf, 'Was: Aktionstyp, betroffenes Objekt, Details (alter/neuer Wert)' );
		self::para( $pdf, 'Das Audit-Log selbst unterliegt derselben Hash-Verkettung wie die Buchungsdaten '
			. 'und ist dadurch unveränderbar geschützt.' );

		// 8. Datenexport.
		self::section( $pdf, '8. Datenexport für Betriebsprüfung' );
		self::para( $pdf, 'Der strukturierte Export (§147 Abs. 6 AO, Z3: Datenträgerüberlassung) steht '
			. 'unter Lindenweg16 → Buchhaltung → Betriebsprüfung zur Verfügung.' );
		self::para( $pdf, 'Exportformat: ZIP-Archiv mit CSV-Dateien (UTF-8, Semikolon-getrennt) '
			. 'und maschinenlesbarer Strukturbeschreibung (INDEX.xml). '
			. 'Enthalten sind: Belege, Rechnungen, Buchungsjournal, Audit-Log und EÜR-Zusammenfassung.' );
		self::para( $pdf, 'Zusätzlich steht ein wöchentlicher automatischer CSV-Export per E-Mail '
			. 'als laufende Datensicherung zur Verfügung.' );

		// 9. E-Rechnungsempfang.
		self::section( $pdf, '9. E-Rechnungsempfang' );
		self::para( $pdf, 'Seit dem 1. Januar 2025 besteht die Pflicht zum Empfang strukturierter '
			. 'E-Rechnungen im B2B-Bereich. Das System unterstützt:' );
		self::bullet( $pdf, 'XRechnung (XML im CII- oder UBL-Format)' );
		self::bullet( $pdf, 'ZUGFeRD/Factur-X (PDF mit eingebetteter XML)' );
		self::para( $pdf, 'Empfangene E-Rechnungen werden automatisch geparst (Rechnungsnummer, Datum, '
			. 'Betrag, Absender) und können als Beleg in die Buchhaltung übernommen werden. '
			. 'Die Original-Dateien werden archiviert.' );

		// 10. GoBD-Prüfung.
		self::section( $pdf, '10. Regelmäßige Integritätsprüfung' );
		self::para( $pdf, 'Die GoBD-Prüffunktion (Lindenweg16 → Buchhaltung → GoBD-Prüfung) verifiziert:' );
		self::bullet( $pdf, 'Integrität aller Hash-Ketten (Belege, Rechnungen, Buchungen, Audit-Log)' );
		self::bullet( $pdf, 'Lückenlosigkeit der Belegnummern, Rechnungsnummern und Buchungsnummern' );
		self::bullet( $pdf, 'Zeitgerechte Erfassung (max. 10 Tage Verzug)' );
		self::para( $pdf, 'Empfehlung: Prüfung mindestens jährlich vor dem Jahresabschluss durchführen. '
			. 'Ergebnisse werden in der Datenbank protokolliert.' );

		// 11. Versionierung.
		self::section( $pdf, '11. Dokumentenpflege' );
		self::para( $pdf, 'Diese Verfahrensdokumentation wird aus den aktuellen Systemeinstellungen generiert. '
			. 'Bei Software-Updates, neuen Prozessen oder Änderungen an der Systemlandschaft sollte sie '
			. 'neu erzeugt und aufbewahrt werden.' );
		self::para( $pdf, 'Verantwortlich: Der Unternehmer (nicht der Steuerberater). '
			. 'Ein Steuerberater kann die Dokumentation prüfen und bestätigen.' );

		// Footer-Hinweis.
		$pdf->space( 20 );
		$pdf->set_color( '#6E7882' );
		$pdf->text( 'Generiert am ' . $datum . ' aus Lindenweg16 ERP '
			. ( defined( 'LW16_VERSION' ) ? LW16_VERSION : '' ), 8 );
		$pdf->text( 'Diese Dokumentation ersetzt keine steuerliche Beratung.', 8 );

		AuditLog::record( 'verfahrensdoku_generiert', 'system', 0 );

		$content = $pdf->output();
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="verfahrensdokumentation-' . gmdate( 'Y-m-d' ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $content ) );
		echo $content; // phpcs:ignore
		exit;
	}

	/**
	 * Abschnittsüberschrift.
	 *
	 * @param Pdf    $pdf  PDF-Objekt.
	 * @param string $text Überschrift.
	 */
	protected static function section( $pdf, $text ) {
		$pdf->space( 8 );
		$pdf->set_color( Pdf::COLOR_COGNAC );
		$pdf->text( $text, 13, true );
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->space( 2 );
	}

	/**
	 * Absatz.
	 *
	 * @param Pdf    $pdf  PDF-Objekt.
	 * @param string $text Text.
	 */
	protected static function para( $pdf, $text ) {
		// Einfache Zeilenumbrüche bei ~80 Zeichen simulieren.
		$lines = self::wrap( $text, 85 );
		foreach ( $lines as $line ) {
			$pdf->text( $line, 10 );
		}
		$pdf->space( 4 );
	}

	/**
	 * Aufzählungspunkt.
	 *
	 * @param Pdf    $pdf  PDF-Objekt.
	 * @param string $text Text.
	 */
	protected static function bullet( $pdf, $text ) {
		$lines = self::wrap( $text, 80 );
		$first = true;
		foreach ( $lines as $line ) {
			$pdf->text( ( $first ? '• ' : '  ' ) . $line, 10, false, $first ? 8 : 14 );
			$first = false;
		}
	}

	/**
	 * Text in Zeilen umbrechen.
	 *
	 * @param string $text  Eingabetext.
	 * @param int    $width Max. Zeichen pro Zeile.
	 * @return string[]
	 */
	protected static function wrap( $text, $width = 85 ) {
		$words = explode( ' ', $text );
		$lines = array();
		$line  = '';
		foreach ( $words as $word ) {
			if ( '' !== $line && mb_strlen( $line . ' ' . $word ) > $width ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = '' !== $line ? $line . ' ' . $word : $word;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines ?: array( '' );
	}
}
