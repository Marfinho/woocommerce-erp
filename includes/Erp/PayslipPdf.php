<?php
/**
 * Lohnabrechnung als PDF und die Monatsliste als CSV für den Steuerberater.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Pdf;
use Lindenweg16\Modules\Betrieb\TimeRepository;

/**
 * Erzeugt und liefert die Dokumente.
 */
class PayslipPdf {

	/**
	 * Adresse zum Herunterladen des PDF.
	 *
	 * @param int $id Abrechnung.
	 * @return string
	 */
	public static function pdf_url( $id ) {
		return Shell::nonce_url( admin_url( 'admin-post.php?action=lw16_erp_payslip_pdf&id=' . (int) $id ), 'lw16_erp_payslip_pdf_' . (int) $id );
	}

	/**
	 * Adresse zum Herunterladen der Monats-CSV.
	 *
	 * @param string $month Monat JJJJ-MM.
	 * @return string
	 */
	public static function csv_url( $month ) {
		return Shell::nonce_url( admin_url( 'admin-post.php?action=lw16_erp_payslip_csv&monat=' . rawurlencode( $month ) ), 'lw16_erp_payslip_csv_' . $month );
	}

	/**
	 * Das PDF einer Abrechnung.
	 *
	 * @param array  $slip    Abrechnung aus Payslips::find().
	 * @param string $company Name des Betriebs.
	 * @return string PDF-Inhalt.
	 */
	public static function build( array $slip, $company ) {
		$labels = TimeRepository::category_labels();
		$status = $slip['status'];
		$pdf    = new Pdf();
		$widths = array( 70, 285, 60, 80 );
		$aligns = array( 'l', 'l', 'r', 'r' );

		$pdf->text( $company, 10 );
		$pdf->space( 4 );
		$pdf->text( __( 'Lohnabrechnung (brutto)', 'lindenweg16' ), 20, true );

		if ( Payslips::DRAFT === $status ) {
			$pdf->text( __( 'ENTWURF – noch nicht festgeschrieben', 'lindenweg16' ), 11, true );
		} elseif ( Payslips::VOID === $status ) {
			$pdf->text( sprintf( /* translators: %s: Nummer. */ __( 'STORNIERT – %s', 'lindenweg16' ), (string) $slip['number'] ), 11, true );
		} else {
			$pdf->text( sprintf( /* translators: %s: Nummer. */ __( 'Nummer %s', 'lindenweg16' ), (string) $slip['number'] ), 11 );
		}

		$pdf->space( 10 );
		$pdf->row( __( 'Mitarbeiter', 'lindenweg16' ), (string) $slip['employee_name'], 11, true );
		$pdf->row( __( 'Zeitraum', 'lindenweg16' ), Payroll::dmy( $slip['period_von'] ) . ' – ' . Payroll::dmy( $slip['period_bis'] ) );

		if ( $slip['paid_on'] ) {
			$pdf->row( __( 'Bezahlt am', 'lindenweg16' ), Payroll::dmy( $slip['paid_on'] ) );
		}

		$pdf->space( 8 );
		$pdf->hr();
		$pdf->table_cols( array( __( 'Datum', 'lindenweg16' ), __( 'Tätigkeit', 'lindenweg16' ), __( 'Std.', 'lindenweg16' ), __( 'Satz', 'lindenweg16' ) ), $widths, $aligns, 9, true );
		$pdf->hr( 0.3 );

		foreach ( $slip['days'] as $day ) {
			$what = isset( $labels[ $day['category'] ] ) ? $labels[ $day['category'] ] : (string) $day['category'];

			if ( '' !== (string) $day['description'] ) {
				$what .= ': ' . $day['description'];
			}

			$pdf->table_cols(
				array(
					Payroll::dmy( $day['date'] ),
					mb_strimwidth( $what, 0, 62, '…', 'UTF-8' ),
					Payroll::hours_text( $day['hours'] ),
					null === $day['rate'] ? '–' : Payroll::eur( $day['rate'] ),
				),
				$widths,
				$aligns,
				9
			);
		}

		$pdf->space( 6 );
		$pdf->hr();
		$pdf->space( 4 );

		foreach ( $slip['lines'] as $line ) {
			$pdf->row(
				sprintf( '%s h × %s je Stunde', Payroll::hours_text( $line['hours'] ), Payroll::eur( $line['rate'] ) ),
				Payroll::eur( $line['amount'] )
			);
		}

		$pdf->row( sprintf( /* translators: %s: Stunden. */ __( 'Bruttolohn für %s Stunden', 'lindenweg16' ), Payroll::hours_text( $slip['hours'] ) ), Payroll::eur( $slip['gross'] ), 11, true );

		if ( $slip['adjustments'] ) {
			$pdf->space( 4 );
			foreach ( $slip['adjustments'] as $row ) {
				$pdf->row( (string) $row['label'], Payroll::eur( $row['amount'] ) );
			}
		}

		$pdf->space( 4 );
		$pdf->hr( 1 );
		$pdf->row( __( 'Gesamtbetrag brutto', 'lindenweg16' ), Payroll::eur( $slip['total'] ), 13, true );

		if ( '' !== (string) $slip['note'] ) {
			$pdf->space( 6 );
			$pdf->text( __( 'Bemerkung: ', 'lindenweg16' ) . $slip['note'], 10 );
		}

		$pdf->space( 14 );
		$pdf->text( __( 'Der Bruttobetrag ergibt sich aus den erfassten Arbeitszeiten und dem zum jeweiligen Tag gültigen Stundenlohn.', 'lindenweg16' ), 8 );
		$pdf->text( __( 'Lohnsteuer und Sozialversicherungsbeiträge sind hier nicht berechnet.', 'lindenweg16' ), 8 );

		if ( $slip['checksum'] ) {
			$pdf->text( sprintf( /* translators: %s: Prüfsumme. */ __( 'Prüfsumme: %s', 'lindenweg16' ), substr( $slip['checksum'], 0, 16 ) ), 8 );
		}

		return $pdf->output();
	}

	/**
	 * Monatsliste als CSV (Semikolon, UTF-8 mit Marke für Excel).
	 *
	 * @param array $slips Abrechnungen des Monats.
	 * @return string
	 */
	public static function csv( array $slips ) {
		$out = "\xEF\xBB\xBF" . Payroll::csv_line(
			array( 'Nummer', 'Status', 'Name', 'Von', 'Bis', 'Stunden', 'Brutto aus Stunden', 'Zuschläge/Abzüge', 'Gesamt brutto', 'Bezahlt am', 'Bemerkung' )
		);

		foreach ( $slips as $slip ) {
			if ( Payslips::VOID === $slip['status'] ) {
				continue;
			}

			$out .= Payroll::csv_line(
				array(
					(string) $slip['number'],
					Payslips::status_labels()[ $slip['status'] ],
					$slip['employee_name'],
					Payroll::dmy( $slip['period_von'] ),
					Payroll::dmy( $slip['period_bis'] ),
					number_format( $slip['hours'], 2, ',', '' ),
					number_format( $slip['gross'], 2, ',', '' ),
					number_format( $slip['adjustments_total'], 2, ',', '' ),
					number_format( $slip['total'], 2, ',', '' ),
					$slip['paid_on'] ? Payroll::dmy( $slip['paid_on'] ) : '',
					(string) $slip['note'],
				)
			);
		}

		return $out;
	}

	/**
	 * Auslieferung des PDF (admin-post).
	 */
	public static function serve_pdf() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		check_admin_referer( 'lw16_erp_payslip_pdf_' . $id );

		if ( ! Access::can( 'verwaltung.loehne' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}

		$slip = Payslips::find( $id );

		if ( ! $slip ) {
			wp_die( esc_html__( 'Abrechnung nicht gefunden.', 'lindenweg16' ), '', array( 'response' => 404 ) );
		}

		$name = ( $slip['number'] ? $slip['number'] : 'entwurf-' . $id ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		echo self::build( $slip, get_bloginfo( 'name' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Auslieferung der Monats-CSV (admin-post).
	 */
	public static function serve_csv() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$month = isset( $_GET['monat'] ) ? sanitize_text_field( wp_unslash( $_GET['monat'] ) ) : '';

		check_admin_referer( 'lw16_erp_payslip_csv_' . $month );

		if ( ! Access::can( 'verwaltung.loehne' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}

		$bounds = Payroll::month_bounds( $month );

		if ( ! $bounds ) {
			wp_die( esc_html__( 'Der Monat ist ungültig.', 'lindenweg16' ), '', array( 'response' => 400 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lohn-' . $month . '.csv"' );
		echo self::csv( Payslips::between( $bounds['von'], $bounds['bis'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
