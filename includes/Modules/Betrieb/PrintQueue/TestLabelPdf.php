<?php
/**
 * Testlabel für die Druck-Warteschlange.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\PrintQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Erzeugt ein minimales, gültiges PDF im Format des Thermolabels
 * (103×199 mm), ohne jede DHL-Zugangsdaten oder Sendungsdaten zu
 * berühren – ein Testlabel erzeugt keine echte Sendung.
 *
 * Bewusst ohne PDF-Bibliothek: Für drei Zeilen Text reicht die rohe
 * PDF-Syntax, und das Plugin bekommt dadurch keine neue Abhängigkeit.
 */
class TestLabelPdf {

	/**
	 * Seitengröße in PDF-Punkten (1 mm = 2.834645 pt) – exakt das
	 * Thermolabel-Maß, keine A4-Anpassung.
	 *
	 * @param int $width_mm  Breite in mm.
	 * @param int $height_mm Höhe in mm.
	 * @return array [width_pt, height_pt]
	 */
	public static function page_points( $width_mm = 103, $height_mm = 199 ) {
		$faktor = 72 / 25.4;

		return array(
			round( $width_mm * $faktor, 2 ),
			round( $height_mm * $faktor, 2 ),
		);
	}

	/**
	 * PDF-Inhalt als Zeichenkette (roh, nicht base64).
	 *
	 * @param array $zeilen     Textzeilen, oben nach unten.
	 * @param int   $width_mm   Breite in mm.
	 * @param int   $height_mm  Höhe in mm.
	 * @param bool  $landscape  Quer beschriftet (z. B. DK-11201)? Die Seite
	 *                          bleibt dabei exakt so gross wie das Etikett -
	 *                          nur der Inhalt wird gedreht, genau wie
	 *                          Pdf::begin_quarter_turn() es für die echten
	 *                          Etiketten macht. Ohne das käme der Text bei
	 *                          einem quer beschrifteten Format um 90 Grad
	 *                          verdreht aus dem Drucker.
	 * @return string
	 */
	public static function build( array $zeilen = array( 'HASTER HAKEN', 'TESTLABEL', 'KEINE VERSENDUNG' ), $width_mm = 103, $height_mm = 199, $landscape = false ) {
		list( $w, $h ) = self::page_points( $width_mm, $height_mm );

		// Logische Zeichenfläche: bei quer beschrifteten Etiketten sind
		// Breite und Höhe vertauscht, die physische Seite (MediaBox)
		// bleibt unangetastet.
		$log_w = $landscape ? $h : $w;
		$log_h = $landscape ? $w : $h;

		$content = '';
		if ( $landscape ) {
			$content .= sprintf( "q 0 1 -1 0 %s 0 cm\n", self::pt( $w ) );
		}

		$content .= "BT /F1 20 Tf 14 " . ( $log_h - 40 ) . " Td 24 TL\n";
		foreach ( $zeilen as $zeile ) {
			$content .= '(' . self::escape( $zeile ) . ") Tj 0 -28 Td\n";
		}
		$content .= "ET\n";
		$content .= sprintf( '0 0 0 RG %s %s %s %s re S', self::pt( 6 ), self::pt( 6 ), self::pt( $log_w - 12 ), self::pt( $log_h - 12 ) );

		if ( $landscape ) {
			$content .= "\nQ";
		}

		$objects   = array();
		$objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
		$objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
		$objects[] = sprintf(
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
			self::pt( $w ),
			self::pt( $h )
		);
		$objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
		$objects[] = sprintf( "<< /Length %d >>\nstream\n%sendstream", strlen( $content ), $content );

		$pdf    = "%PDF-1.4\n";
		$offset = array();

		foreach ( $objects as $i => $body ) {
			$offset[] = strlen( $pdf );
			$pdf     .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref_start = strlen( $pdf );
		$pdf       .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offset as $off ) {
			$pdf .= sprintf( "%010d 00000 n \n", $off );
		}
		$pdf .= "trailer << /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n{$xref_start}\n%%EOF";

		return $pdf;
	}

	/**
	 * Punktwert für die PDF-Syntax formatieren (ohne Nachkommastellen,
	 * wenn nicht nötig).
	 *
	 * @param float $wert Wert in Punkten.
	 * @return string
	 */
	protected static function pt( $wert ) {
		return rtrim( rtrim( number_format( (float) $wert, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Klammern und Backslashes maskieren – sonst bricht die PDF-Syntax.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function escape( $text ) {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), (string) $text );
	}
}
