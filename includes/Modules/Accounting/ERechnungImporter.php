<?php
/**
 * E-Rechnungs-Empfang: XRechnung (XML) und ZUGFeRD (PDF+XML).
 *
 * Seit 1.1.2025 müssen alle inländischen Unternehmen im B2B-Bereich
 * strukturierte E-Rechnungen empfangen und verarbeiten können.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;
use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\HashChain;

/**
 * Importiert E-Rechnungen im XRechnung- oder ZUGFeRD-Format.
 */
class ERechnungImporter {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_erechnung_eingang';
	}

	/**
	 * Upload verarbeiten: Datei speichern, XML extrahieren, Daten parsen.
	 *
	 * @param array $file $_FILES-Eintrag.
	 * @return array|false Parsed data oder false bei Fehler.
	 */
	public static function process_upload( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return false;
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'xml', 'pdf' ), true ) ) {
			return false;
		}

		// Datei sicher speichern.
		$dir = Installer::docs_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$filename = 'erechnung-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false ) . '.' . $ext;
		$target   = trailingslashit( $dir ) . $filename;
		if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore
			return false;
		}

		$xml_content = '';
		$format      = '';

		if ( 'xml' === $ext ) {
			$xml_content = file_get_contents( $target ); // phpcs:ignore
			$format      = 'xrechnung';
		} elseif ( 'pdf' === $ext ) {
			$xml_content = self::extract_xml_from_pdf( $target );
			$format      = $xml_content ? 'zugferd' : 'pdf_ohne_xml';
		}

		$parsed = array(
			'datei_pfad'           => $filename,
			'format'               => $format,
			'xml_inhalt'           => $xml_content,
			'absender_name'        => '',
			'absender_steuer_id'   => '',
			'rechnungsnummer_ext'  => '',
			'betrag'               => 0,
			'datum'                => gmdate( 'Y-m-d' ),
		);

		if ( $xml_content ) {
			$extracted = self::parse_xml( $xml_content );
			$parsed    = array_merge( $parsed, $extracted );
		}

		return $parsed;
	}

	/**
	 * E-Rechnung in der Datenbank speichern.
	 *
	 * @param array $data Geparste Daten.
	 * @return int Neue ID.
	 */
	public static function save( array $data ) {
		global $wpdb;

		$row = array(
			'datei_pfad'          => $data['datei_pfad'] ?? '',
			'format'              => $data['format'] ?? '',
			'absender_name'       => $data['absender_name'] ?? '',
			'absender_steuer_id'  => $data['absender_steuer_id'] ?? '',
			'rechnungsnummer_ext' => $data['rechnungsnummer_ext'] ?? '',
			'betrag'              => (float) ( $data['betrag'] ?? 0 ),
			'datum'               => $data['datum'] ?? gmdate( 'Y-m-d' ),
			'xml_inhalt'          => $data['xml_inhalt'] ?? '',
			'status'              => 'empfangen',
			'beleg_id'            => null,
			'erstellt_am'         => current_time( 'mysql' ),
		);

		// Hash-Verkettung (über zentrale HashChain wie alle anderen Tabellen).
		$hashes                   = HashChain::prepare_insert( 'erechnung_eingang', $row );
		$row['hash']              = $hashes['hash'];
		$row['vorheriger_hash']   = $hashes['vorheriger_hash'];

		$wpdb->insert( self::table(), $row );
		$id = (int) $wpdb->insert_id;

		AuditLog::record( 'erechnung_empfangen', 'erechnung', $id, array(
			'format'  => $row['format'],
			'betrag'  => $row['betrag'],
			'absender' => $row['absender_name'],
		) );

		return $id;
	}

	/**
	 * E-Rechnung als Beleg übernehmen.
	 *
	 * @param int    $erechnung_id E-Rechnungs-ID.
	 * @param string $kategorie    EÜR-Kategorie.
	 * @return int|false Beleg-ID oder false.
	 */
	public static function create_beleg( $erechnung_id, $kategorie = 'Wareneinkauf' ) {
		global $wpdb;
		$er = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE id = %d", // phpcs:ignore
			$erechnung_id
		) );
		if ( ! $er || 'verarbeitet' === $er->status ) {
			return false;
		}

		$beleg_id = BelegRepository::create( array(
			'datei_pfad'    => $er->datei_pfad,
			'betrag'        => (float) $er->betrag,
			'datum'         => $er->datum,
			'haendler'      => $er->absender_name,
			'eur_kategorie' => $kategorie,
			'notiz'         => sprintf( 'E-Rechnung %s (%s)', $er->rechnungsnummer_ext, $er->format ),
			'quelle'        => 'erechnung',
		) );

		$wpdb->update(
			self::table(),
			array( 'status' => 'verarbeitet', 'beleg_id' => $beleg_id ),
			array( 'id' => (int) $erechnung_id )
		);

		AuditLog::record( 'erechnung_als_beleg', 'erechnung', $erechnung_id, array(
			'beleg_id' => $beleg_id,
		) );

		return $beleg_id;
	}

	/**
	 * XML aus einem ZUGFeRD-/Factur-X-PDF extrahieren.
	 *
	 * @param string $pdf_path Absoluter Pfad zum PDF.
	 * @return string XML-Inhalt oder leerer String.
	 */
	protected static function extract_xml_from_pdf( $pdf_path ) {
		$content = @file_get_contents( $pdf_path ); // phpcs:ignore
		if ( false === $content ) {
			return '';
		}

		// Typische XML-Startpunkte in ZUGFeRD-/Factur-X-PDFs suchen.
		$markers = array(
			'<?xml',
		);

		foreach ( $markers as $marker ) {
			$pos = 0;
			while ( false !== ( $start = strpos( $content, $marker, $pos ) ) ) {
				// Prüfen ob es eine CII- oder UBL-Rechnung ist.
				$preview = substr( $content, $start, 2000 );
				if ( self::looks_like_invoice_xml( $preview ) ) {
					return self::extract_xml_block( $content, $start );
				}
				$pos = $start + 1;
			}
		}

		return '';
	}

	/**
	 * Prüft, ob ein XML-Fragment nach einer Rechnungs-XML aussieht.
	 *
	 * @param string $preview Anfang des XML.
	 * @return bool
	 */
	protected static function looks_like_invoice_xml( $preview ) {
		$invoice_indicators = array(
			'CrossIndustryInvoice',
			'rsm:CrossIndustryInvoice',
			'ubl:Invoice',
			'Invoice xmlns',
			'urn:un:unece:uncefact',
			'urn:oasis:names:specification:ubl',
			'factur-x',
			'zugferd',
		);
		foreach ( $invoice_indicators as $indicator ) {
			if ( false !== stripos( $preview, $indicator ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Vollständigen XML-Block ab einer Position extrahieren.
	 *
	 * @param string $content  Gesamtinhalt.
	 * @param int    $start    Startposition.
	 * @return string XML-Block.
	 */
	protected static function extract_xml_block( $content, $start ) {
		// Schlusstags suchen.
		$end_tags = array(
			'</rsm:CrossIndustryInvoice>',
			'</CrossIndustryInvoice>',
			'</Invoice>',
			'</ubl:Invoice>',
		);
		foreach ( $end_tags as $tag ) {
			$end = strpos( $content, $tag, $start );
			if ( false !== $end ) {
				return substr( $content, $start, $end - $start + strlen( $tag ) );
			}
		}
		// Fallback: bis zum nächsten endstream oder bis 100 KB.
		$end = strpos( $content, 'endstream', $start );
		if ( false !== $end && ( $end - $start ) < 102400 ) {
			$xml = substr( $content, $start, $end - $start );
			// Trailing-Müll abschneiden.
			$last_gt = strrpos( $xml, '>' );
			if ( false !== $last_gt ) {
				$xml = substr( $xml, 0, $last_gt + 1 );
			}
			return $xml;
		}
		return '';
	}

	/**
	 * Rechnungsdaten aus XML (CII oder UBL) extrahieren.
	 *
	 * @param string $xml XML-Inhalt.
	 * @return array Extrahierte Felder.
	 */
	protected static function parse_xml( $xml ) {
		$result = array(
			'absender_name'       => '',
			'absender_steuer_id'  => '',
			'rechnungsnummer_ext' => '',
			'betrag'              => 0,
			'datum'               => gmdate( 'Y-m-d' ),
		);

		// XML laden – Fehler unterdrücken, da E-Rechnungen manchmal nicht valide sind.
		$prev = libxml_use_internal_errors( true );
		$doc  = @simplexml_load_string( $xml ); // phpcs:ignore
		libxml_use_internal_errors( $prev );

		if ( ! $doc ) {
			return $result;
		}

		// Format erkennen: CII (Cross Industry Invoice) oder UBL.
		$root_name = $doc->getName();

		if ( false !== stripos( $root_name, 'CrossIndustryInvoice' ) ) {
			return self::parse_cii( $doc, $result );
		}
		if ( false !== stripos( $root_name, 'Invoice' ) ) {
			return self::parse_ubl( $doc, $result );
		}

		// Namespace-basierte Erkennung.
		$namespaces = $doc->getNamespaces( true );
		foreach ( $namespaces as $ns ) {
			if ( false !== strpos( $ns, 'uncefact' ) || false !== strpos( $ns, 'CrossIndustryInvoice' ) ) {
				return self::parse_cii( $doc, $result );
			}
			if ( false !== strpos( $ns, 'oasis' ) || false !== strpos( $ns, 'ubl' ) ) {
				return self::parse_ubl( $doc, $result );
			}
		}

		return $result;
	}

	/**
	 * CII-Format (XRechnung/ZUGFeRD) parsen.
	 *
	 * @param \SimpleXMLElement $doc    XML-Dokument.
	 * @param array             $result Ergebnis-Array.
	 * @return array
	 */
	protected static function parse_cii( $doc, $result ) {
		$namespaces = $doc->getNamespaces( true );
		$ram = isset( $namespaces['ram'] ) ? $namespaces['ram'] : 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
		$udt = isset( $namespaces['udt'] ) ? $namespaces['udt'] : 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

		$doc->registerXPathNamespace( 'ram', $ram );
		$doc->registerXPathNamespace( 'udt', $udt );

		// Rechnungsnummer.
		$id = $doc->xpath( '//ram:ExchangedDocument/ram:ID' );
		if ( ! $id ) {
			$id = $doc->xpath( '//*[local-name()="ExchangedDocument"]/*[local-name()="ID"]' );
		}
		if ( $id ) {
			$result['rechnungsnummer_ext'] = (string) $id[0];
		}

		// Datum.
		$date = $doc->xpath( '//ram:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString' );
		if ( ! $date ) {
			$date = $doc->xpath( '//*[local-name()="ExchangedDocument"]//*[local-name()="DateTimeString"]' );
		}
		if ( $date ) {
			$d = (string) $date[0];
			// Format 102 = YYYYMMDD.
			if ( 8 === strlen( $d ) && ctype_digit( $d ) ) {
				$result['datum'] = substr( $d, 0, 4 ) . '-' . substr( $d, 4, 2 ) . '-' . substr( $d, 6, 2 );
			} elseif ( preg_match( '/\d{4}-\d{2}-\d{2}/', $d, $m ) ) {
				$result['datum'] = $m[0];
			}
		}

		// Verkäufer.
		$seller = $doc->xpath( '//*[local-name()="SellerTradeParty"]/*[local-name()="Name"]' );
		if ( $seller ) {
			$result['absender_name'] = (string) $seller[0];
		}

		// Steuernummer/USt-ID.
		$tax = $doc->xpath( '//*[local-name()="SellerTradeParty"]//*[local-name()="ID"]' );
		if ( $tax ) {
			$result['absender_steuer_id'] = (string) $tax[0];
		}

		// Gesamtbetrag.
		$total = $doc->xpath( '//*[local-name()="GrandTotalAmount"]' );
		if ( ! $total ) {
			$total = $doc->xpath( '//*[local-name()="DuePayableAmount"]' );
		}
		if ( $total ) {
			$result['betrag'] = (float) $total[0];
		}

		return $result;
	}

	/**
	 * UBL-Format parsen.
	 *
	 * @param \SimpleXMLElement $doc    XML-Dokument.
	 * @param array             $result Ergebnis-Array.
	 * @return array
	 */
	protected static function parse_ubl( $doc, $result ) {
		$namespaces = $doc->getNamespaces( true );
		$cbc = isset( $namespaces['cbc'] ) ? $namespaces['cbc'] : 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
		$cac = isset( $namespaces['cac'] ) ? $namespaces['cac'] : 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

		$doc->registerXPathNamespace( 'cbc', $cbc );
		$doc->registerXPathNamespace( 'cac', $cac );

		// Rechnungsnummer.
		$id = $doc->xpath( '//cbc:ID' );
		if ( ! $id ) {
			$id = $doc->xpath( '//*[local-name()="ID"]' );
		}
		if ( $id ) {
			$result['rechnungsnummer_ext'] = (string) $id[0];
		}

		// Datum.
		$date = $doc->xpath( '//cbc:IssueDate' );
		if ( ! $date ) {
			$date = $doc->xpath( '//*[local-name()="IssueDate"]' );
		}
		if ( $date ) {
			$result['datum'] = (string) $date[0];
		}

		// Verkäufer.
		$seller = $doc->xpath( '//cac:AccountingSupplierParty//cbc:Name' );
		if ( ! $seller ) {
			$seller = $doc->xpath( '//*[local-name()="AccountingSupplierParty"]//*[local-name()="Name"]' );
		}
		if ( $seller ) {
			$result['absender_name'] = (string) $seller[0];
		}

		// Gesamtbetrag.
		$total = $doc->xpath( '//cbc:PayableAmount' );
		if ( ! $total ) {
			$total = $doc->xpath( '//*[local-name()="PayableAmount"]' );
		}
		if ( ! $total ) {
			$total = $doc->xpath( '//*[local-name()="TaxInclusiveAmount"]' );
		}
		if ( $total ) {
			$result['betrag'] = (float) $total[0];
		}

		return $result;
	}

	/**
	 * Paginierte Liste der empfangenen E-Rechnungen.
	 *
	 * @param int $per_page Pro Seite.
	 * @param int $page     Seitennummer.
	 * @return array
	 */
	public static function get_list( $per_page = 50, $page = 1 ) {
		global $wpdb;
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore
			$per_page,
			$offset
		) );
	}

	/**
	 * Gesamtanzahl.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() ); // phpcs:ignore
	}

	/**
	 * Einzelne E-Rechnung holen.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE id = %d", // phpcs:ignore
			$id
		) );
	}
}
