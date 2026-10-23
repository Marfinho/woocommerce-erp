<?php
/**
 * Der Gutschein als PDF (A5 hoch, eine Seite, randlos) im Stil des Flyers.
 *
 * Der PDF-Generator des Plugins kennt nur Helvetica. Wortmarke und Titel
 * sind deshalb mit Bricolage Grotesque vorgezeichnete Bilder (siehe
 * branding/gutschein/erzeuge-bilder.py); Namen, Text, Betrag und Code sind
 * Text in Helvetica Bold.
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

		$p = $alle[ $kennung ];
		$w = 419.53; // A5 hoch, randlos wie der Flyer.
		$h = 595.28;

		$pdf = new Pdf( $w, $h, 36 );
		$x   = 36.0;
		$iw  = $w - 2 * $x;

		$pdf->set_color( $p['bg'] );
		$pdf->rect( 0, 0, $w, $h );

		// Wortmarke und Titel sind mit Bricolage Grotesque vorgezeichnet.
		$pdf->image_jpeg_at( LW16_PLUGIN_DIR . 'assets/gutschein/marke-' . $kennung . '.jpg', $x, 34, 150, 38 );
		$pdf->image_jpeg_at( LW16_PLUGIN_DIR . 'assets/gutschein/titel-' . $kennung . '.jpg', $x - 1, 98, 230, 56 );
		$pdf->set_color( $p['accent'] );
		$pdf->rect( $x, 164, 32, 3 );

		// Betrag.
		$pdf->set_color( $p['fg'] );
		$pdf->text_xy( (string) $d['betrag'], $x, 184, 34, true );

		// Für / Von zwischen dünnen Linien.
		$pdf->set_color( $p['line'] );
		$pdf->rect( $x, 238, $iw, 0.6 );
		$pdf->set_color( $p['accent'] );
		$pdf->text_xy( 'FÜR', $x, 250, 7.5, true, 1.6 );
		$pdf->text_xy( 'VON', $x + $iw / 2, 250, 7.5, true, 1.6 );
		$pdf->set_color( $p['fg'] );
		$pdf->text_xy( self::kuerzen( $pdf, (string) $d['an'], 14, $iw / 2 - 12, true ), $x, 263, 14, true );
		$pdf->text_xy( self::kuerzen( $pdf, (string) $d['von'], 14, $iw / 2 - 12, true ), $x + $iw / 2, 263, 14, true );
		$pdf->set_color( $p['line'] );
		$pdf->rect( $x, 288, $iw, 0.6 );

		// Text, höchstens sechs Zeilen.
		$zeilen = array_slice( self::umbrechen( $pdf, (string) $d['text'], 11, $iw ), 0, 6 );
		$pdf->set_color( $p['fg'] );
		foreach ( $zeilen as $i => $zeile ) {
			$pdf->text_xy( $zeile, $x, 300 + $i * 16, 11 );
		}
		$pdf->set_color( $p['line'] );
		$pdf->rect( $x, 404, $iw, 0.6 );

		// Code-Kasten.
		$pdf->set_color( $p['box_ln'] );
		$pdf->rect( $x, 420, $iw, 50 );
		$pdf->set_color( $p['box'] );
		$pdf->rect( $x + 0.8, 420.8, $iw - 1.6, 48.4 );
		$pdf->set_color( '#EE1C4F' );
		$pdf->text_xy( 'GUTSCHEINCODE', $x + 14, 431, 7.5, true, 1.6 );
		$pdf->set_color( $p['box_fg'] );
		$pdf->text_xy( (string) $d['code'], $x + 14, 444, 20, true, 1.0 );

		// Fuß wie die Rückseite des Flyers.
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? $host : 'lindenweg16.de';
		$pdf->set_color( $p['muted'] );
		$pdf->text_xy( __( 'Jetzt einlösen', 'lindenweg16' ), $x, 492, 9 );
		$pdf->set_color( $p['fg'] );
		$pdf->text_xy( $host, $x, 504, 17, true );
		$pdf->set_color( $p['accent'] );
		$pdf->rect( $x, 530, 32, 3 );
		$pdf->set_color( $p['muted'] );
		$pdf->text_xy( __( 'Code im Warenkorb unter "Gutschein" eingeben.', 'lindenweg16' ), $x, 541, 8.5 );
		/* translators: %s: Datum. */
		$pdf->text_xy( sprintf( __( 'Gültig bis zum %s.', 'lindenweg16' ), $d['gueltig_bis'] ), $x, 553, 8.5 );

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
