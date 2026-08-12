<?php
/**
 * Betriebsprüfungs-Export (GoBD Z3: Datenträgerüberlassung).
 *
 * Erzeugt ein ZIP-Archiv mit CSV-Dateien und einer Strukturbeschreibung,
 * lesbar für Prüfsoftware (IDEA o. ä.) und den Betriebsprüfer.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\AuditLog;

/**
 * Erzeugt den strukturierten Datenexport für die Betriebsprüfung.
 */
class ExportGenerator {

	/**
	 * Trennzeichen und Encoding.
	 */
	const SEPARATOR = ';';
	const ENCLOSURE = '"';
	const NEWLINE   = "\r\n";

	/**
	 * ZIP-Export erzeugen und als Download ausliefern.
	 *
	 * @param string $from Startdatum (Y-m-d).
	 * @param string $to   Enddatum (Y-m-d).
	 */
	public static function download( $from, $to ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZipArchive PHP-Erweiterung nicht verfügbar.', 'lindenweg16' ) );
		}

		$upload = wp_upload_dir();
		$tmp    = trailingslashit( $upload['basedir'] ) . 'lw16-export-' . wp_generate_password( 12, false ) . '.zip';

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'ZIP-Datei konnte nicht erstellt werden.', 'lindenweg16' ) );
		}

		// 1. Belege.
		$zip->addFromString( 'belege.csv', self::belege_csv( $from, $to ) );

		// 2. Rechnungen.
		$zip->addFromString( 'rechnungen.csv', self::rechnungen_csv( $from, $to ) );

		// 3. Buchungsjournal.
		$zip->addFromString( 'buchungen.csv', self::buchungen_csv( $from, $to ) );

		// 4. Audit-Log.
		$zip->addFromString( 'audit_log.csv', self::audit_csv( $from, $to ) );

		// 5. EÜR-Zusammenfassung.
		$zip->addFromString( 'euer.csv', self::euer_csv( $from, $to ) );

		// 6. Strukturbeschreibung.
		$zip->addFromString( 'BESCHREIBUNG.txt', self::beschreibung( $from, $to ) );

		// 7. INDEX.xml (GDPdU-ähnlich).
		$zip->addFromString( 'INDEX.xml', self::index_xml( $from, $to ) );

		$zip->close();

		AuditLog::record( 'bp_export', 'export', 0, array( 'from' => $from, 'to' => $to ) );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="bp-export-' . $from . '_' . $to . '.zip"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore
		@unlink( $tmp ); // phpcs:ignore
		exit;
	}

	/**
	 * CSV-Header + Zeilen erzeugen.
	 *
	 * @param array    $headers Spaltenüberschriften.
	 * @param array    $rows    Datensätze (assoziative Arrays).
	 * @param string[] $fields  Feldnamen in Reihenfolge.
	 * @return string CSV-Inhalt (UTF-8 mit BOM).
	 */
	protected static function build_csv( array $headers, array $rows, array $fields ) {
		// UTF-8 BOM für korrekte Erkennung in Excel.
		$csv = "\xEF\xBB\xBF";
		$csv .= implode( self::SEPARATOR, $headers ) . self::NEWLINE;

		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $fields as $f ) {
				$val    = isset( $row[ $f ] ) ? (string) $row[ $f ] : '';
				$val    = str_replace( self::ENCLOSURE, self::ENCLOSURE . self::ENCLOSURE, $val );
				$line[] = self::ENCLOSURE . $val . self::ENCLOSURE;
			}
			$csv .= implode( self::SEPARATOR, $line ) . self::NEWLINE;
		}
		return $csv;
	}

	/**
	 * Belege-CSV.
	 *
	 * @param string $from Start.
	 * @param string $to   Ende.
	 * @return string
	 */
	protected static function belege_csv( $from, $to ) {
		global $wpdb;
		$t    = BelegRepository::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE datum >= %s AND datum <= %s ORDER BY id ASC", // phpcs:ignore
			$from, $to
		), ARRAY_A );

		return self::build_csv(
			array( 'ID', 'Belegnummer', 'Datum', 'Betrag', 'Haendler', 'EÜR-Kategorie', 'Notiz', 'Quelle', 'Festgeschrieben', 'Festgeschrieben_am', 'Storniert', 'Storno_von_ID', 'Storno_Grund', 'Hash', 'Erstellt_am', 'Erstellt_von' ),
			$rows,
			array( 'id', 'belegnummer', 'datum', 'betrag', 'haendler', 'eur_kategorie', 'notiz', 'quelle', 'festgeschrieben', 'festgeschrieben_am', 'storniert', 'storno_von_id', 'storno_grund', 'hash', 'erstellt_am', 'erstellt_von' )
		);
	}

	/**
	 * Rechnungen-CSV.
	 *
	 * @param string $from Start.
	 * @param string $to   Ende.
	 * @return string
	 */
	protected static function rechnungen_csv( $from, $to ) {
		global $wpdb;
		$t    = InvoiceGenerator::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE erstellt_am >= %s AND erstellt_am <= %s ORDER BY id ASC", // phpcs:ignore
			$from . ' 00:00:00', $to . ' 23:59:59'
		), ARRAY_A );

		return self::build_csv(
			array( 'ID', 'Bestellung_ID', 'Rechnungsnummer', 'Jahr', 'Laufnummer', 'Betrag', 'Storniert', 'Storno_von_ID', 'Storno_Grund', 'Hash', 'Erstellt_am' ),
			$rows,
			array( 'id', 'order_id', 'rechnungsnummer', 'jahr', 'laufnummer', 'betrag', 'storniert', 'storno_von_id', 'storno_grund', 'hash', 'erstellt_am' )
		);
	}

	/**
	 * Buchungsjournal-CSV.
	 *
	 * @param string $from Start.
	 * @param string $to   Ende.
	 * @return string
	 */
	protected static function buchungen_csv( $from, $to ) {
		$rows = BuchungRepository::export( $from, $to );
		return self::build_csv(
			array( 'ID', 'Buchungsnummer', 'Jahr', 'Laufnummer', 'Buchungsdatum', 'Erfasst_am', 'Typ', 'Betrag', 'Kategorie', 'Beschreibung', 'Referenz_Typ', 'Referenz_ID', 'Storniert', 'Storno_von_ID', 'Hash', 'Erstellt_von' ),
			$rows,
			array( 'id', 'buchungsnummer', 'jahr', 'laufnummer', 'buchungsdatum', 'erfasst_am', 'typ', 'betrag', 'kategorie', 'beschreibung', 'referenz_typ', 'referenz_id', 'storniert', 'storno_von_id', 'hash', 'erstellt_von' )
		);
	}

	/**
	 * Audit-Log-CSV.
	 *
	 * @param string $from Start.
	 * @param string $to   Ende.
	 * @return string
	 */
	protected static function audit_csv( $from, $to ) {
		global $wpdb;
		$t    = AuditLog::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE erstellt_am >= %s AND erstellt_am <= %s ORDER BY id ASC", // phpcs:ignore
			$from . ' 00:00:00', $to . ' 23:59:59'
		), ARRAY_A );

		return self::build_csv(
			array( 'ID', 'User_ID', 'Aktion', 'Objekt_Typ', 'Objekt_ID', 'Details', 'Hash', 'Erstellt_am' ),
			$rows,
			array( 'id', 'user_id', 'aktion', 'objekt_typ', 'objekt_id', 'details', 'hash', 'erstellt_am' )
		);
	}

	/**
	 * EÜR-Zusammenfassung als CSV.
	 *
	 * @param string $from Start.
	 * @param string $to   Ende.
	 * @return string
	 */
	protected static function euer_csv( $from, $to ) {
		$rep = EuerCalculator::report( $from, $to );
		$csv = "\xEF\xBB\xBF";
		$csv .= 'Position;Betrag' . self::NEWLINE;
		$csv .= '"Einnahmen (WooCommerce, abzgl. Refunds)";"' . number_format( $rep['einnahmen'], 2, ',', '' ) . '"' . self::NEWLINE;
		foreach ( $rep['ausgaben_kategorien'] as $kat => $summe ) {
			$csv .= '"Ausgabe: ' . $kat . '";"' . number_format( $summe, 2, ',', '' ) . '"' . self::NEWLINE;
		}
		$csv .= '"Ausgaben gesamt";"' . number_format( $rep['ausgaben_summe'], 2, ',', '' ) . '"' . self::NEWLINE;
		$csv .= '"Gewinn";"' . number_format( $rep['gewinn'], 2, ',', '' ) . '"' . self::NEWLINE;
		return $csv;
	}

	/**
	 * Strukturbeschreibung für den Betriebsprüfer.
	 *
	 * @param string $from Start.
	 * @param string $to   Ende.
	 * @return string
	 */
	protected static function beschreibung( $from, $to ) {
		$firma = Settings::get( 'firma_name' );
		$text  = "DATENEXPORT FÜR BETRIEBSPRÜFUNG\r\n";
		$text .= "================================\r\n\r\n";
		$text .= "Unternehmen:   {$firma}\r\n";
		$text .= "Zeitraum:      {$from} bis {$to}\r\n";
		$text .= "Exportdatum:   " . gmdate( 'Y-m-d H:i:s' ) . " UTC\r\n";
		$text .= "Software:      Lindenweg16 ERP " . LW16_VERSION . " (WordPress/WooCommerce)\r\n";
		$text .= "Zeichensatz:   UTF-8 (mit BOM)\r\n";
		$text .= "Trennzeichen:  Semikolon (;)\r\n";
		$text .= "Textbegrenzer: Anführungszeichen (\")\r\n";
		$text .= "Dezimaltrennz: Komma (,)\r\n\r\n";

		$text .= "ENTHALTENE DATEIEN\r\n";
		$text .= "------------------\r\n\r\n";

		$text .= "belege.csv        Ausgabenbelege (Wareneinkauf, Betriebsausgaben)\r\n";
		$text .= "                  Felder: ID, Belegnummer, Datum, Betrag, Händler,\r\n";
		$text .= "                  EÜR-Kategorie, Notiz, Quelle, Festgeschrieben,\r\n";
		$text .= "                  Festgeschrieben_am, Storniert, Storno_von_ID,\r\n";
		$text .= "                  Storno_Grund, Hash (SHA-256), Erstellt_am, Erstellt_von\r\n\r\n";

		$text .= "rechnungen.csv    Ausgangsrechnungen (aus WooCommerce-Bestellungen)\r\n";
		$text .= "                  Felder: ID, Bestellung_ID, Rechnungsnummer, Jahr,\r\n";
		$text .= "                  Laufnummer, Betrag, Storniert, Storno_von_ID,\r\n";
		$text .= "                  Storno_Grund, Hash (SHA-256), Erstellt_am\r\n\r\n";

		$text .= "buchungen.csv     Buchungsjournal (alle Einnahmen, Ausgaben, Stornos)\r\n";
		$text .= "                  Felder: ID, Buchungsnummer, Jahr, Laufnummer,\r\n";
		$text .= "                  Buchungsdatum, Erfasst_am, Typ, Betrag, Kategorie,\r\n";
		$text .= "                  Beschreibung, Referenz_Typ, Referenz_ID, Storniert,\r\n";
		$text .= "                  Storno_von_ID, Hash (SHA-256), Erstellt_von\r\n\r\n";

		$text .= "audit_log.csv     Änderungsprotokoll (alle Aktionen)\r\n";
		$text .= "                  Felder: ID, User_ID, Aktion, Objekt_Typ, Objekt_ID,\r\n";
		$text .= "                  Details (JSON), Hash (SHA-256), Erstellt_am\r\n\r\n";

		$text .= "euer.csv          Einnahmen-Überschuss-Rechnung (Zusammenfassung)\r\n";
		$text .= "                  Felder: Position, Betrag\r\n\r\n";

		$text .= "INDEX.xml         Strukturbeschreibung (maschinenlesbar)\r\n\r\n";

		$text .= "INTEGRITÄTSSCHUTZ\r\n";
		$text .= "-----------------\r\n\r\n";
		$text .= "Alle steuerrelevanten Datensätze sind mit SHA-256-Hashes verkettet.\r\n";
		$text .= "Jeder Hash berechnet sich aus dem Inhalt des Datensatzes und dem\r\n";
		$text .= "Hash des Vorgänger-Datensatzes. Nachträgliche Manipulation an einem\r\n";
		$text .= "Eintrag bricht die Kette sichtbar.\r\n\r\n";
		$text .= "Die Integrität kann über die GoBD-Prüffunktion im Plugin verifiziert\r\n";
		$text .= "werden (Lindenweg16 → Buchhaltung → GoBD-Prüfung).\r\n";

		return $text;
	}

	/**
	 * INDEX.xml im GDPdU-ähnlichen Format.
	 *
	 * @param string $from Start.
	 * @param string $to   Ende.
	 * @return string
	 */
	protected static function index_xml( $from, $to ) {
		$firma = esc_xml( (string) Settings::get( 'firma_name' ) );
		$ort   = esc_xml( (string) Settings::get( 'firma_plz_ort' ) );
		$datum = gmdate( 'Y-m-d\TH:i:s' );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<DataSet xmlns="urn:lindenweg16:gobd-export:1.0">' . "\n";
		$xml .= "  <Version>1.0</Version>\n";
		$xml .= "  <DataSupplier>\n";
		$xml .= "    <Name>{$firma}</Name>\n";
		$xml .= "    <Location>{$ort}</Location>\n";
		$xml .= "  </DataSupplier>\n";
		$xml .= "  <ExportDate>{$datum}</ExportDate>\n";
		$xml .= "  <Period><From>{$from}</From><To>{$to}</To></Period>\n";
		$xml .= "  <Media>\n";

		// Tabellendefinitionen.
		$tables = array(
			array(
				'file'    => 'belege.csv',
				'name'    => 'Belege',
				'desc'    => 'Ausgabenbelege',
				'columns' => array(
					array( 'ID', 'Numeric', 'Primärschlüssel' ),
					array( 'Belegnummer', 'AlphaNumeric', 'Fortlaufende Belegnummer (B-JJJJ-NNNN)' ),
					array( 'Datum', 'Date', 'Belegdatum (YYYY-MM-DD)' ),
					array( 'Betrag', 'Numeric', 'Belegbetrag in EUR' ),
					array( 'Haendler', 'AlphaNumeric', 'Händler/Lieferant' ),
					array( 'EÜR-Kategorie', 'AlphaNumeric', 'Zuordnung zur EÜR-Kategorie' ),
					array( 'Notiz', 'AlphaNumeric', 'Freitext-Notiz' ),
					array( 'Quelle', 'AlphaNumeric', 'manuell/wareneingang' ),
					array( 'Festgeschrieben', 'Numeric', '1 = festgeschrieben (unveränderbar)' ),
					array( 'Storniert', 'Numeric', '1 = storniert' ),
					array( 'Hash', 'AlphaNumeric', 'SHA-256 Integritäts-Hash' ),
				),
			),
			array(
				'file'    => 'rechnungen.csv',
				'name'    => 'Rechnungen',
				'desc'    => 'Ausgangsrechnungen',
				'columns' => array(
					array( 'ID', 'Numeric', 'Primärschlüssel' ),
					array( 'Bestellung_ID', 'Numeric', 'WooCommerce-Bestell-ID' ),
					array( 'Rechnungsnummer', 'AlphaNumeric', 'Lückenlose Rechnungsnummer' ),
					array( 'Betrag', 'Numeric', 'Rechnungsbetrag in EUR' ),
					array( 'Storniert', 'Numeric', '1 = storniert' ),
					array( 'Hash', 'AlphaNumeric', 'SHA-256 Integritäts-Hash' ),
				),
			),
			array(
				'file'    => 'buchungen.csv',
				'name'    => 'Buchungsjournal',
				'desc'    => 'Chronologisches Buchungsjournal',
				'columns' => array(
					array( 'ID', 'Numeric', 'Primärschlüssel' ),
					array( 'Buchungsnummer', 'AlphaNumeric', 'Lückenlose Buchungsnummer (BU-JJJJ-NNNN)' ),
					array( 'Buchungsdatum', 'Date', 'Buchungsdatum (YYYY-MM-DD)' ),
					array( 'Erfasst_am', 'Date', 'Systemzeitpunkt der Erfassung' ),
					array( 'Typ', 'AlphaNumeric', 'einnahme/ausgabe/storno' ),
					array( 'Betrag', 'Numeric', 'Buchungsbetrag in EUR' ),
					array( 'Kategorie', 'AlphaNumeric', 'EÜR-Kategorie' ),
					array( 'Hash', 'AlphaNumeric', 'SHA-256 Integritäts-Hash' ),
				),
			),
			array(
				'file'    => 'audit_log.csv',
				'name'    => 'Änderungsprotokoll',
				'desc'    => 'Vollständiges Audit-Log aller Aktionen',
				'columns' => array(
					array( 'ID', 'Numeric', 'Primärschlüssel' ),
					array( 'User_ID', 'Numeric', 'WordPress-Benutzer-ID' ),
					array( 'Aktion', 'AlphaNumeric', 'Aktionsbezeichnung' ),
					array( 'Details', 'AlphaNumeric', 'Zusatzinformationen (JSON)' ),
					array( 'Hash', 'AlphaNumeric', 'SHA-256 Integritäts-Hash' ),
				),
			),
		);

		foreach ( $tables as $tbl ) {
			$xml .= "    <Table>\n";
			$xml .= '      <URL>' . esc_xml( $tbl['file'] ) . "</URL>\n";
			$xml .= '      <Name>' . esc_xml( $tbl['name'] ) . "</Name>\n";
			$xml .= '      <Description>' . esc_xml( $tbl['desc'] ) . "</Description>\n";
			$xml .= "      <Encoding>UTF-8</Encoding>\n";
			$xml .= "      <ColumnDelimiter>;</ColumnDelimiter>\n";
			$xml .= "      <TextEncapsulator>\"</TextEncapsulator>\n";
			$xml .= "      <DecimalSymbol>,</DecimalSymbol>\n";
			$xml .= "      <Columns>\n";
			foreach ( $tbl['columns'] as $col ) {
				$xml .= '        <Column><Name>' . esc_xml( $col[0] ) . '</Name><Type>' . esc_xml( $col[1] ) . '</Type><Description>' . esc_xml( $col[2] ) . "</Description></Column>\n";
			}
			$xml .= "      </Columns>\n";
			$xml .= "    </Table>\n";
		}

		$xml .= "  </Media>\n";
		$xml .= "</DataSet>\n";
		return $xml;
	}
}

/**
 * Fallback für esc_xml (erst ab WP 5.5).
 */
if ( ! function_exists( 'esc_xml' ) ) {
	function esc_xml( $text ) {
		return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
