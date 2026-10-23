<?php
/**
 * Der Gutschein als PDF (A4, eine Seite) in den Farben der gewählten Vorlage.
 *
 * Der PDF-Generator des Plugins kennt nur Helvetica, Rechtecke und Linien;
 * die Karte ist deshalb eine Nachzeichnung der Mail-Vorlage ohne Rundungen
 * und Serifenschrift – Farben, Aufbau und Inhalt sind dieselben.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Gutschein;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Pdf;
use Lindenweg16\Core\Settings;

/**
 * Baut das PDF und legt es als Datei ab.
 */
class GutscheinPdf {

	/**
	 * Das PDF als Binärstring.
	 *
	 * @param string $kennung Vorlage aus Vorlagen::alle().
	 * @param array  $d       von, an, text, betrag, code, gueltig_bis.
	 * @return string Leer bei unbekannter Vorlage.
	 */
	public static function binaer( $kennung, array $d ) {
		$alle = Vorlagen::alle();

		if ( ! isset( $alle[ $kennung ] ) ) {
			return '';
		}

		$p   = $alle[ $kennung ];
		$pdf = new Pdf();
		$w   = $pdf->page_width();
		$h   = $pdf->page_height();

		$x  = 56.7;
		$cw = $w - 2 * $x;
		$cy = 110.0;
		$ch = 560.0;
		$ix = $x + 40;
		$iw = $cw - 80;

		// Seitenhintergrund und Karte.
		$pdf->set_color( $p['outer'] );
		$pdf->rect( 0, 0, $w, $h );
		$pdf->set_color( $p['line'] );
		$pdf->rect( $x - 1, $cy - 1, $cw + 2, $ch + 2 );
		$pdf->set_color( $p['card'] );
		$pdf->rect( $x, $cy, $cw, $ch );

		// Kopf.
		$pdf->set_color( $p['accent'] );
		$pdf->text_xy( strtoupper( (string) Settings::get( 'firma_name', 'Lindenweg16' ) ), $ix, $cy + 40, 10, true );
		$pdf->set_color( $p['text'] );
		$pdf->text_xy( 'Gutschein', $ix, $cy + 62, 40, true );
		$pdf->set_color( $p['accent'] );
		$pdf->rect( $ix, $cy + 118, 48, 3 );

		// Betrag.
		$pdf->text_xy( (string) $d['betrag'], $ix, $cy + 140, 44, true );

		// Für / Von.
		$pdf->set_color( $p['muted'] );
		$pdf->text_xy( 'FÜR', $ix, $cy + 220, 9 );
		$pdf->text_xy( 'VON', $ix + $iw / 2, $cy + 220, 9 );
		$pdf->set_color( $p['text'] );
		$pdf->text_xy( self::kuerzen( $pdf, (string) $d['an'], 15, $iw / 2 - 12, true ), $ix, $cy + 234, 15, true );
		$pdf->text_xy( self::kuerzen( $pdf, (string) $d['von'], 15, $iw / 2 - 12, true ), $ix + $iw / 2, $cy + 234, 15, true );

		// Text, umbrochen, mit Balken links.
		$zeilen = self::umbrechen( $pdf, (string) $d['text'], 13, $iw - 18 );
		$zeilen = array_slice( $zeilen, 0, 7 );
		if ( $zeilen ) {
			$pdf->set_color( $p['accent'] );
			$pdf->rect( $ix, $cy + 280, 3, count( $zeilen ) * 18 );
			$pdf->set_color( $p['text'] );
			foreach ( $zeilen as $i => $zeile ) {
				$pdf->text_xy( $zeile, $ix + 16, $cy + 282 + $i * 18, 13 );
			}
		}

		// Code-Kasten mit Rahmen in Akzentfarbe.
		$by = $cy + 430;
		$bh = 64.0;
		$pdf->set_color( $p['accent'] );
		$pdf->rect( $ix - 1, $by - 1, $iw + 2, $bh + 2 );
		$pdf->set_color( $p['box'] );
		$pdf->rect( $ix, $by, $iw, $bh );
		$pdf->set_color( $p['accent'] );
		$pdf->text_xy( 'GUTSCHEINCODE', $ix + ( $iw - $pdf->text_width( 'GUTSCHEINCODE', 9 ) ) / 2, $by + 12, 9 );
		$pdf->set_color( $p['on_box'] );
		$pdf->text_xy( (string) $d['code'], $ix + ( $iw - $pdf->text_width( (string) $d['code'], 22, true ) ) / 2, $by + 28, 22, true );

		// Fuß.
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? $host : 'lindenweg16.de';
		/* translators: 1: Shop-Adresse, 2: Datum. */
		$fuss  = sprintf( __( 'Einlösbar im Shop auf %1$s bis zum %2$s.', 'lindenweg16' ), $host, $d['gueltig_bis'] );
		$fuss2 = __( 'Code im Warenkorb unter "Gutschein" eingeben.', 'lindenweg16' );
		$pdf->set_color( $p['muted'] );
		$pdf->text_xy( $fuss, $x + ( $cw - $pdf->text_width( $fuss, 10 ) ) / 2, $cy + $ch - 52, 10 );
		$pdf->text_xy( $fuss2, $x + ( $cw - $pdf->text_width( $fuss2, 10 ) ) / 2, $cy + $ch - 36, 10 );

		return $pdf->output();
	}

	/**
	 * PDF in eine Datei mit sprechendem Namen schreiben.
	 *
	 * Der Name steht im Anhang der Mail; deshalb eigener Ordner statt
	 * zufälligem Dateinamen. Aufräumen mit loeschen().
	 *
	 * @param string $kennung Vorlage.
	 * @param array  $d       Daten wie bei binaer().
	 * @return string Pfad oder leer.
	 */
	public static function datei( $kennung, array $d ) {
		$inhalt = self::binaer( $kennung, $d );

		if ( '' === $inhalt ) {
			return '';
		}

		$ordner = trailingslashit( get_temp_dir() ) . 'lw16-gutschein-' . wp_generate_password( 10, false );

		if ( ! wp_mkdir_p( $ordner ) ) {
			return '';
		}

		$pfad = $ordner . '/Gutschein-' . sanitize_file_name( (string) $d['code'] ) . '.pdf';

		if ( false === file_put_contents( $pfad, $inhalt ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '';
		}

		return $pfad;
	}

	/**
	 * Datei samt Ordner entfernen.
	 *
	 * @param string $pfad Pfad aus datei().
	 */
	public static function loeschen( $pfad ) {
		if ( $pfad && is_file( $pfad ) ) {
			wp_delete_file( $pfad );
			@rmdir( dirname( $pfad ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Text auf Zeilen einer Höchstbreite verteilen.
	 *
	 * @param Pdf    $pdf   Dokument (für die Textbreite).
	 * @param string $text  Text mit optionalen Zeilenumbrüchen.
	 * @param float  $size  Schriftgröße.
	 * @param float  $breit Höchstbreite in pt.
	 * @return string[]
	 */
	protected static function umbrechen( Pdf $pdf, $text, $size, $breit ) {
		$zeilen = array();

		foreach ( preg_split( '/\R/u', trim( $text ) ) as $absatz ) {
			$aktuell = '';

			foreach ( preg_split( '/\s+/u', $absatz, -1, PREG_SPLIT_NO_EMPTY ) as $wort ) {
				$probe = '' === $aktuell ? $wort : $aktuell . ' ' . $wort;

				if ( '' !== $aktuell && $pdf->text_width( $probe, $size ) > $breit ) {
					$zeilen[] = $aktuell;
					$aktuell  = $wort;
				} else {
					$aktuell = $probe;
				}
			}

			$zeilen[] = $aktuell;
		}

		return '' === trim( $text ) ? array() : $zeilen;
	}

	/**
	 * Einzeiligen Text auf eine Breite kürzen.
	 *
	 * @param Pdf    $pdf   Dokument.
	 * @param string $text  Text.
	 * @param float  $size  Schriftgröße.
	 * @param float  $breit Höchstbreite.
	 * @param bool   $fett  Fettdruck.
	 * @return string
	 */
	protected static function kuerzen( Pdf $pdf, $text, $size, $breit, $fett ) {
		while ( mb_strlen( $text ) > 1 && $pdf->text_width( $text, $size, $fett ) > $breit ) {
			$text = mb_substr( $text, 0, -2 ) . '…';
		}

		return $text;
	}
}
