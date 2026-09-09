<?php
/**
 * Anbindung der Druck-Warteschlange an die DHL-Label-Erzeugung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\PrintQueue;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Betrieb\Dhl\DhlLabelRepository;

/**
 * Der einzige Anknüpfungspunkt an die bestehende DHL-Logik fürs automatische Einreihen: Ein Druckjob
 * entsteht ausschließlich, nachdem ein Label erfolgreich erzeugt UND das
 * PDF erfolgreich abgelegt wurde – nie vorher, nie bei einem DHL- oder
 * Ablagefehler.
 */
class PrintQueueService {

	/**
	 * Nach einem soeben erzeugten Label ggf. einen Druckjob anlegen.
	 *
	 * Wird ausschließlich aufgerufen, wenn DhlShipping::create_label()
	 * das Label bereits vollständig erzeugt und abgelegt hat.
	 *
	 * @param int    $order_id Bestellung.
	 * @param int    $label_id Datensatz-ID in wp_lw16_shipping_labels.
	 * @param string $filename Dateiname des abgelegten Labels.
	 * @return int Job-ID, 0 wenn kein Job angelegt wurde.
	 */
	public static function maybe_enqueue_for_label( $order_id, $label_id, $filename ) {
		if ( 1 !== (int) Settings::get( 'dhl_print_automatisch', 0 ) ) {
			return 0;
		}

		$printer_id = sanitize_key( (string) Settings::get( 'dhl_print_standarddrucker', 'eg_dhl' ) );

		if ( '' === $printer_id ) {
			return 0;
		}

		$job_id = PrintJobRepository::create(
			array(
				'order_id'       => (int) $order_id,
				'label_id'       => (int) $label_id,
				'source'         => 'dhl_label',
				'label_path'     => DhlLabelRepository::path( $filename ),
				'label_filename' => $filename,
				'label_format'   => (string) Settings::get( 'dhl_versand_printformat', '910-300-610' ),
				'width_mm'       => 103,
				'height_mm'      => 199,
				'printer_id'     => $printer_id,
				'is_test'        => 0,
			)
		);

		if ( $job_id ) {
			AuditLog::record( 'dhl_druckjob_angelegt', 'order', (int) $order_id, array( 'job_id' => $job_id, 'drucker' => $printer_id ) );
		}

		return $job_id;
	}

	/**
	 * Testlabel für einen Drucker anlegen – keine echte Sendung, dient
	 * ausschließlich der Prüfung der Kette Pi → CUPS/brother_ql → Drucker.
	 *
	 * Grösse ist wählbar: Ein Testlabel im DHL-Mass (Standard) hilft beim
	 * DHL-Drucker nichts, wenn der zu testende Drucker tatsächlich eine
	 * schmale Etikettenrolle bedient – bei brother_ql würde die eigene
	 * Grössenprüfung des Pi-Clients einen so falsch bemessenen Job ohnehin
	 * zurückweisen (siehe README), aber auch bei CUPS druckt ein falsches
	 * Mass nicht das, was man eigentlich prüfen wollte.
	 *
	 * @param string $printer_id  Drucker.
	 * @param int    $width_mm    Breite in mm.
	 * @param int    $height_mm   Höhe in mm.
	 * @param string $label_format Freitext, nur zur Anzeige/Protokollierung.
	 * @return int Job-ID, 0 bei Fehler.
	 */
	public static function create_test_job( $printer_id, $width_mm = 103, $height_mm = 199, $label_format = '910-300-610' ) {
		$printer_id = sanitize_key( (string) $printer_id );
		$width_mm   = max( 1, (int) $width_mm );
		$height_mm  = max( 1, (int) $height_mm );

		if ( '' === $printer_id ) {
			return 0;
		}

		Installer::ensure_docs_dir();

		$filename = sprintf( 'dhl-test-label-%d-%s.pdf', time(), wp_generate_password( 6, false, false ) );
		$pfad     = DhlLabelRepository::path( $filename );

		$inhalt = TestLabelPdf::build(
			array(
				'HASTER HAKEN',
				'TESTLABEL',
				'KEINE VERSENDUNG',
				gmdate( 'Y-m-d H:i' ) . ' UTC',
			),
			$width_mm,
			$height_mm
		);

		if ( false === file_put_contents( $pfad, $inhalt ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return 0;
		}

		$job_id = PrintJobRepository::create(
			array(
				'source'         => 'test',
				'label_path'     => $pfad,
				'label_filename' => $filename,
				'label_format'   => (string) $label_format,
				'width_mm'       => $width_mm,
				'height_mm'      => $height_mm,
				'printer_id'     => $printer_id,
				'is_test'        => 1,
			)
		);

		if ( $job_id ) {
			AuditLog::record( 'dhl_testlabel_angelegt', 'dhl_print_job', $job_id, array( 'drucker' => $printer_id ) );
		}

		return $job_id;
	}

	/**
	 * Ein bereits erzeugtes PDF (z. B. Artikel- oder Lagerplatz-Etiketten
	 * aus Exporter::handle_labels_queue()) in die Warteschlange legen.
	 *
	 * Anders als beim DHL-Label gibt es hier keine schon abgelegte
	 * Originaldatei, auf die sich verweisen ließe – das PDF wird deshalb
	 * einmalig ins geschützte Dokumentenverzeichnis geschrieben, so wie
	 * das Testlabel.
	 *
	 * @param string $printer_id   Drucker.
	 * @param string $pdf          PDF-Inhalt (roh, nicht base64).
	 * @param string $source       Kurzbezeichnung der Herkunft, z. B. article_bin_label.
	 * @param float  $width_mm     Breite in mm.
	 * @param float  $height_mm    Höhe in mm.
	 * @param string $label_format Freitext fürs Anzeigen, z. B. der Rollenname.
	 * @return int Job-ID, 0 bei Fehler.
	 */
	public static function enqueue_pdf_labels( $printer_id, $pdf, $source, $width_mm, $height_mm, $label_format = '' ) {
		$printer_id = sanitize_key( (string) $printer_id );

		if ( '' === $printer_id || '' === (string) $pdf ) {
			return 0;
		}

		Installer::ensure_docs_dir();

		$filename = sprintf( '%s-%d-%s.pdf', sanitize_key( (string) $source ), time(), wp_generate_password( 6, false, false ) );
		$pfad     = trailingslashit( Installer::docs_dir() ) . $filename;

		if ( false === file_put_contents( $pfad, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return 0;
		}

		$job_id = PrintJobRepository::create(
			array(
				'source'         => $source,
				'label_path'     => $pfad,
				'label_filename' => $filename,
				'label_format'   => (string) $label_format,
				'width_mm'       => $width_mm,
				'height_mm'      => $height_mm,
				'printer_id'     => $printer_id,
				'is_test'        => 0,
			)
		);

		if ( $job_id ) {
			AuditLog::record( 'druckjob_angelegt', 'print_queue_job', $job_id, array( 'quelle' => $source, 'drucker' => $printer_id ) );
		}

		return $job_id;
	}

	/**
	 * Wartung: verwaiste Claims freigeben und alte Job-Einträge löschen.
	 * Aufgerufen aus dem wiederkehrenden Hintergrundjob.
	 */
	public static function run_maintenance() {
		$freigegeben = PrintJobRepository::release_stale_locks();

		if ( $freigegeben > 0 ) {
			AuditLog::record( 'dhl_druckjob_zurueckgesetzt', '', 0, array( 'anzahl' => $freigegeben ) );
		}

		PrintJobRepository::cleanup_retention( (int) Settings::get( 'dhl_print_aufbewahrung_tage', 30 ) );
	}
}
