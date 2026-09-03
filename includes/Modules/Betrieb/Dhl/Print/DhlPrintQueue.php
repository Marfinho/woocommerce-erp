<?php
/**
 * Anbindung der DHL-Druck-Warteschlange an die Label-Erzeugung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl\Print;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Betrieb\Dhl\DhlLabelRepository;

/**
 * Der einzige Anknüpfungspunkt an die bestehende DHL-Logik: Ein Druckjob
 * entsteht ausschließlich, nachdem ein Label erfolgreich erzeugt UND das
 * PDF erfolgreich abgelegt wurde – nie vorher, nie bei einem DHL- oder
 * Ablagefehler.
 */
class DhlPrintQueue {

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

		$job_id = DhlPrintJobRepository::create(
			array(
				'order_id'       => (int) $order_id,
				'label_id'       => (int) $label_id,
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
	 * ausschließlich der Prüfung der Kette Pi → CUPS → Drucker.
	 *
	 * @param string $printer_id Drucker.
	 * @return int Job-ID, 0 bei Fehler.
	 */
	public static function create_test_job( $printer_id ) {
		$printer_id = sanitize_key( (string) $printer_id );

		if ( '' === $printer_id ) {
			return 0;
		}

		Installer::ensure_docs_dir();

		$filename = sprintf( 'dhl-test-label-%d-%s.pdf', time(), wp_generate_password( 6, false, false ) );
		$pfad     = DhlLabelRepository::path( $filename );

		$inhalt = DhlTestLabelPdf::build(
			array(
				'HASTER HAKEN',
				'TESTLABEL',
				'KEINE VERSENDUNG',
				gmdate( 'Y-m-d H:i' ) . ' UTC',
			)
		);

		if ( false === file_put_contents( $pfad, $inhalt ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return 0;
		}

		$job_id = DhlPrintJobRepository::create(
			array(
				'label_path'     => $pfad,
				'label_filename' => $filename,
				'label_format'   => '910-300-610',
				'width_mm'       => 103,
				'height_mm'      => 199,
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
	 * Wartung: verwaiste Claims freigeben und alte Job-Einträge löschen.
	 * Aufgerufen aus dem wiederkehrenden Hintergrundjob.
	 */
	public static function run_maintenance() {
		$freigegeben = DhlPrintJobRepository::release_stale_locks();

		if ( $freigegeben > 0 ) {
			AuditLog::record( 'dhl_druckjob_zurueckgesetzt', '', 0, array( 'anzahl' => $freigegeben ) );
		}

		DhlPrintJobRepository::cleanup_retention( (int) Settings::get( 'dhl_print_aufbewahrung_tage', 30 ) );
	}
}
