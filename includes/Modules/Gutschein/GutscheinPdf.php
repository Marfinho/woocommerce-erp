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
use Lindenweg16\Modules\Betrieb\QrCode;

/**
 * Baut das PDF und legt es als Datei ab.
 */
class GutscheinPdf {

	/**
	 * Kennungen für die Auswertung. WooCommerce merkt sie sich bei der
	 * Bestellung (Order Attribution); das Marketing-Modul ordnet über
	 * `utm_campaign` zu – dort einfach eine Kampagne mit dieser Kennung anlegen.
	 */
	const UTM_SOURCE   = 'gutschein';
	const UTM_MEDIUM   = 'qr';
	const UTM_CAMPAIGN = 'gutschein-pdf';

	/**
	 * Adresse hinter dem QR-Code, mit Auswertungskennung.
	 *
	 * utm_content trägt die Vorlage, damit sich vergleichen lässt, welche
	 * Gestaltung zum Einkauf führt.
	 *
	 * @param string $kennung Vorlage.
	 * @return string
	 */
	public static function qr_url( $kennung ) {
		$url = add_query_arg(
			array(
				'utm_source'   => self::UTM_SOURCE,
				'utm_medium'   => self::UTM_MEDIUM,
				'utm_campaign' => self::UTM_CAMPAIGN,
				'utm_content'  => $kennung,
			),
			home_url( '/' )
		);

		/**
		 * Adresse im QR-Code des Gutschein-PDFs ändern.
		 *
		 * @param string $url     Adresse mit utm-Parametern.
		 * @param string $kennung Vorlage.
		 */
		return (string) apply_filters( 'lw16_gutschein_qr_url', $url, $kennung );
	}

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

		self::qr( $pdf, $kennung, $w - $x - 84, 482, 84 );

		return $pdf->output();
	}

	/**
	 * QR-Code als Vektor auf weißer Fläche, damit er auf jedem Grund liest.
	 *
	 * @param Pdf    $pdf     Dokument.
	 * @param string $kennung Vorlage.
	 * @param float  $x       Linke Kante der Fläche.
	 * @param float  $y       Obere Kante der Fläche.
	 * @param float  $kante   Kantenlänge der Fläche inklusive Ruhezone.
	 */
	protected static function qr( Pdf $pdf, $kennung, $x, $y, $kante ) {
		try {
			$matrix = QrCode::matrix( self::qr_url( $kennung ), 'M' );
		} catch ( \InvalidArgumentException $e ) {
			return; // Lieber kein QR-Code als ein ungültiger.
		}

		$ruhe  = 3; // Module Ruhezone (Norm: 4, drei lesen alle gängigen Scanner).
		$n     = count( $matrix );
		$modul = $kante / ( $n + 2 * $ruhe );

		$pdf->set_color( '#FFFFFF' );
		$pdf->rect( $x, $y, $kante, $kante );
		$pdf->set_color( '#161616' );

		foreach ( $matrix as $zeile => $reihe ) {
			$start = null;

			for ( $spalte = 0; $spalte <= $n; $spalte++ ) {
				$an = $spalte < $n && ! empty( $reihe[ $spalte ] );

				if ( $an && null === $start ) {
					$start = $spalte;
				} elseif ( ! $an && null !== $start ) {
					$pdf->rect( $x + ( $ruhe + $start ) * $modul, $y + ( $ruhe + $zeile ) * $modul, ( $spalte - $start ) * $modul, $modul );
					$start = null;
				}
			}
		}
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
