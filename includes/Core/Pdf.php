<?php
/**
 * Minimaler, abhängigkeitsfreier PDF-Generator.
 *
 * Unterstützt Text, einfache Linien und die Einbettung von JPEG-Bildern
 * (DCTDecode) – ausreichend für Rechnungen, Packlisten und EÜR-Exporte.
 * Bewusst schlank gehalten, damit kein externes Build-Tooling nötig ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Erzeugt PDF-Dokumente im DIN-A4-Format (595 x 842 pt).
 */
class Pdf {

	const PAGE_WIDTH  = 595.28;
	const PAGE_HEIGHT = 841.89;
	const MARGIN      = 56.7; // ~2 cm.

	const COLOR_TINTE  = '#3A434D';
	const COLOR_COGNAC = '#8B6914';

	/**
	 * Seitengrösse und Rand dieses Dokuments.
	 *
	 * Die Konstanten oben sind der Normalfall A4. Ein Etikettendrucker
	 * bekommt dagegen eine Seite, die genau das Etikett ist – nur dann
	 * druckt der Treiber ohne Skalierung an die richtige Stelle.
	 *
	 * @var float
	 */
	protected $page_w = self::PAGE_WIDTH;
	protected $page_h = self::PAGE_HEIGHT;
	protected $margin = self::MARGIN;

	protected $pages = array();
	protected $buffer = '';
	protected $y = self::MARGIN;
	protected $images = array();
	protected $img_counter = 0;
	protected $ci_enabled = false;

	protected $fill_r = 0.0;
	protected $fill_g = 0.0;
	protected $fill_b = 0.0;
	protected $stroke_r = 0.0;
	protected $stroke_g = 0.0;
	protected $stroke_b = 0.0;

	/**
	 * @param float|null $width  Seitenbreite in pt, null = A4.
	 * @param float|null $height Seitenhöhe in pt, null = A4.
	 * @param float|null $margin Rand in pt, null = Standard.
	 */
	public function __construct( $width = null, $height = null, $margin = null ) {
		if ( null !== $width && $width > 0 ) {
			$this->page_w = (float) $width;
		}
		if ( null !== $height && $height > 0 ) {
			$this->page_h = (float) $height;
		}
		if ( null !== $margin && $margin >= 0 ) {
			$this->margin = (float) $margin;
		}

		$this->y = $this->margin;

		$this->set_color( self::COLOR_TINTE );
		$this->add_page();
	}

	/**
	 * Seitenbreite dieses Dokuments.
	 *
	 * @return float
	 */
	public function page_width() {
		return $this->page_w;
	}

	/**
	 * Seitenhöhe dieses Dokuments.
	 *
	 * @return float
	 */
	public function page_height() {
		return $this->page_h;
	}

	/**
	 * CI-Header und -Footer für alle Seiten aktivieren.
	 */
	public function enable_ci() {
		$this->ci_enabled = true;
		$this->render_ci_header();
	}

	/**
	 * CI-Firmenkopf: Logo links, Firmendaten rechts.
	 */
	protected function render_ci_header() {
		$logo_id  = (int) Settings::get( 'ci_logo_id' );
		$firma    = (string) Settings::get( 'firma_name' );
		$strasse  = (string) Settings::get( 'firma_strasse' );
		$plz_ort  = (string) Settings::get( 'firma_plz_ort' );
		$steuer   = (string) Settings::get( 'firma_steuernummer' );

		$header_y = $this->y;
		$logo_h   = 0;

		if ( $logo_id ) {
			$path = get_attached_file( $logo_id );
			if ( $path && is_readable( $path ) ) {
				$logo_h = $this->image_jpeg_at( $path, $this->margin, $header_y, 160, 60 );
			}
		}

		$info_lines = array_filter( array( $firma, $strasse, $plz_ort, $steuer ) );
		$line_h     = 13 * 1.6;
		$info_size  = 9;
		$text_y     = $header_y;
		$this->set_color( '#6E7882' );
		foreach ( $info_lines as $line ) {
			$this->text_xy_right( $line, $this->page_w - $this->margin, $text_y, $info_size );
			$text_y += $line_h;
		}
		$this->set_color( self::COLOR_TINTE );

		$this->y = max( $header_y + $logo_h + 8, $text_y + 8 );
		$this->space( 10 );
	}

	public function add_page() {
		if ( '' !== $this->buffer ) {
			$this->pages[] = $this->buffer;
		}
		$this->buffer = '';
		$this->y      = $this->margin;

		if ( $this->ci_enabled ) {
			$this->render_ci_header();
		}
	}

	protected function enc( $text ) {
		$latin = function_exists( 'mb_convert_encoding' )
			? mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' )
			: utf8_decode( $text );
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $latin );
	}

	protected function ensure_space( $needed ) {
		$footer_reserve = $this->ci_enabled ? 30 : 0;
		if ( $this->y + $needed > $this->page_h - $this->margin - $footer_reserve ) {
			$this->add_page();
		}
	}

	/**
	 * Hex-Farbe (#RRGGBB) in 0-1-RGB-Array umwandeln.
	 */
	protected static function hex_rgb( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ) / 255,
			hexdec( substr( $hex, 2, 2 ) ) / 255,
			hexdec( substr( $hex, 4, 2 ) ) / 255,
		);
	}

	/**
	 * Textfarbe setzen (Hex).
	 */
	public function set_color( $hex ) {
		list( $this->fill_r, $this->fill_g, $this->fill_b ) = self::hex_rgb( $hex );
	}

	/**
	 * Linienfarbe setzen (Hex).
	 */
	public function set_draw_color( $hex ) {
		list( $this->stroke_r, $this->stroke_g, $this->stroke_b ) = self::hex_rgb( $hex );
	}

	protected function color_cmd() {
		return sprintf( '%.3F %.3F %.3F rg', $this->fill_r, $this->fill_g, $this->fill_b );
	}

	protected function stroke_cmd() {
		return sprintf( '%.3F %.3F %.3F RG', $this->stroke_r, $this->stroke_g, $this->stroke_b );
	}

	/**
	 * Den weiteren Inhalt der Seite um eine Vierteldrehung gekippt zeichnen.
	 *
	 * Gedacht für Etiketten, die längs beschriftet werden: Ein
	 * Adressetikett ist 29 mm breit und 90 mm lang, gelesen wird es aber
	 * quer. Die Seite muss trotzdem genau so gross bleiben wie das
	 * Etikett – sonst dreht oder skaliert der Druckertreiber, und beides
	 * verdirbt einen QR-Code.
	 *
	 * Deshalb bleibt die Seite, wie sie ist, und der Inhalt wird gedreht.
	 * Zwischen `begin_quarter_turn()` und `end_quarter_turn()` rechnet
	 * alles in der gedrehten Seite: Breite und Höhe sind vertauscht, x
	 * läuft die lange Kante entlang.
	 */
	public function begin_quarter_turn() {
		// Die Matrix dreht um 90 Grad und schiebt das Ergebnis wieder auf
		// die Seite. `q` sichert den Zustand, `Q` in end_quarter_turn()
		// holt ihn zurück.
		$this->buffer .= sprintf( "q 0 1 -1 0 %.3F 0 cm\n", $this->page_w );

		$dreh          = $this->page_w;
		$this->page_w  = $this->page_h;
		$this->page_h  = $dreh;
	}

	/**
	 * Die Drehung beenden.
	 */
	public function end_quarter_turn() {
		$dreh         = $this->page_w;
		$this->page_w = $this->page_h;
		$this->page_h = $dreh;

		$this->buffer .= "Q\n";
	}

	/**
	 * Geschätzte Textbreite in pt.
	 */
	public function text_width( $text, $size, $bold = false ) {
		$factor = $bold ? 0.54 : 0.52;
		return strlen( $this->enc( $text ) ) * $size * $factor;
	}

	/**
	 * Textzeile an beliebiger Position (Y von oben, kein Y-Vorschub).
	 */
	public function text_xy( $text, $x, $y_top, $size, $bold = false ) {
		$font = $bold ? '/F2' : '/F1';
		$ty   = $this->page_h - $y_top - $size;
		$this->buffer .= sprintf(
			"BT %s %s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
			$this->color_cmd(),
			$font,
			$size,
			$x,
			$ty,
			$this->enc( $text )
		);
	}

	/**
	 * Textzeile rechts ausgerichtet an x-Position (Y von oben, kein Y-Vorschub).
	 */
	public function text_xy_right( $text, $x_right, $y_top, $size, $bold = false ) {
		$w = $this->text_width( $text, $size, $bold );
		$this->text_xy( $text, $x_right - $w, $y_top, $size, $bold );
	}

	/**
	 * Eine Textzeile ausgeben (fließend, verschiebt Y).
	 */
	public function text( $text, $size = 11, $bold = false, $indent = 0 ) {
		$this->ensure_space( $size + 4 );
		$x = $this->margin + $indent;
		$this->text_xy( $text, $x, $this->y, $size, $bold );
		$this->y += $size + 4;
	}

	/**
	 * Rechtsbündig fließend ausgeben.
	 */
	public function text_right( $text, $size = 11, $bold = false ) {
		$this->ensure_space( $size + 4 );
		$this->text_xy_right( $text, $this->page_w - $this->margin, $this->y, $size, $bold );
		$this->y += $size + 4;
	}

	/**
	 * Zwei Spalten (links/rechts).
	 */
	public function row( $left, $right, $size = 11, $bold = false ) {
		$this->ensure_space( $size + 4 );
		$this->text_xy( $left, $this->margin, $this->y, $size, $bold );
		$this->text_xy_right( $right, $this->page_w - $this->margin, $this->y, $size, $bold );
		$this->y += $size + 4;
	}

	/**
	 * Mehrspaltige Tabellenzeile.
	 *
	 * @param array $cols   Texte je Spalte.
	 * @param array $widths Breiten je Spalte (pt). Letzte Spalte füllt auf.
	 * @param array $aligns 'l' oder 'r' je Spalte.
	 * @param float $size   Schriftgröße.
	 * @param bool  $bold   Fett?
	 */
	public function table_cols( array $cols, array $widths, array $aligns, $size = 11, $bold = false ) {
		$this->ensure_space( $size + 8 );
		$x = $this->margin;
		foreach ( $cols as $i => $txt ) {
			$w = isset( $widths[ $i ] ) ? $widths[ $i ] : 60;
			if ( isset( $aligns[ $i ] ) && 'r' === $aligns[ $i ] ) {
				$this->text_xy_right( $txt, $x + $w, $this->y, $size, $bold );
			} else {
				$this->text_xy( $txt, $x, $this->y, $size, $bold );
			}
			$x += $w;
		}
		$this->y += $size + 8;
	}

	public function space( $h = 8 ) {
		$this->y += $h;
	}

	/**
	 * Horizontale Trennlinie mit optionaler Stärke.
	 */
	public function hr( $thickness = 0.5 ) {
		$this->ensure_space( 6 );
		$yy = $this->page_h - $this->y;
		$this->buffer .= sprintf(
			"%s %.2F %.2F m %.2F %.2F l %.2F w S\n",
			$this->stroke_cmd(),
			$this->margin,
			$yy,
			$this->page_w - $this->margin,
			$yy,
			$thickness
		);
		$this->y += 6;
	}

	/**
	 * Gefülltes Rechteck in der aktuellen Füllfarbe.
	 *
	 * Die Koordinate ist wie überall in dieser Klasse von oben gemessen;
	 * PDF selbst rechnet von unten.
	 *
	 * @param float $x     Linke Kante.
	 * @param float $y_top Obere Kante.
	 * @param float $w     Breite.
	 * @param float $h     Höhe.
	 */
	public function rect( $x, $y_top, $w, $h ) {
		$this->buffer .= sprintf(
			"%s %.3F %.3F %.3F %.3F re f\n",
			$this->color_cmd(),
			(float) $x,
			$this->page_h - (float) $y_top - (float) $h,
			(float) $w,
			(float) $h
		);
	}

	/**
	 * Linie in der aktuellen Strichfarbe.
	 *
	 * @param float $x1        Start x.
	 * @param float $y1_top    Start y von oben.
	 * @param float $x2        Ende x.
	 * @param float $y2_top    Ende y von oben.
	 * @param float $thickness Strichstärke.
	 */
	public function line( $x1, $y1_top, $x2, $y2_top, $thickness = 0.4 ) {
		$this->buffer .= sprintf(
			"%s %.3F %.3F m %.3F %.3F l %.2F w S\n",
			$this->stroke_cmd(),
			(float) $x1,
			$this->page_h - (float) $y1_top,
			(float) $x2,
			$this->page_h - (float) $y2_top,
			(float) $thickness
		);
	}

	/**
	 * JPEG-Bild einbetten (fließend, linksbündig).
	 */
	public function image_jpeg( $file, $max_w = 400, $max_h = 500 ) {
		$h = $this->embed_image( $file, $this->margin, $this->y, $max_w, $max_h );
		if ( false === $h ) {
			return false;
		}
		$this->y += $h + 8;
		return true;
	}

	/**
	 * JPEG-Bild an absoluter Position einbetten. Gibt Bildhöhe zurück.
	 */
	public function image_jpeg_at( $file, $x, $y_top, $max_w, $max_h ) {
		return $this->embed_image( $file, $x, $y_top, $max_w, $max_h );
	}

	protected function embed_image( $file, $x, $y_top, $max_w, $max_h ) {
		if ( ! is_readable( $file ) ) {
			return false;
		}
		$info = @getimagesize( $file ); // phpcs:ignore
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] ) {
			return false;
		}
		$data = file_get_contents( $file ); // phpcs:ignore
		if ( false === $data ) {
			return false;
		}

		list( $iw, $ih ) = $info;
		$ratio = min( $max_w / $iw, $max_h / $ih, 1 );
		$w     = $iw * $ratio;
		$h     = $ih * $ratio;

		$this->img_counter++;
		$name = 'Img' . $this->img_counter;
		$this->images[ $name ] = array(
			'data' => $data,
			'w'    => $iw,
			'h'    => $ih,
		);

		$yy = $this->page_h - $y_top - $h;
		$this->buffer .= sprintf(
			"q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
			$w,
			$h,
			$x,
			$yy,
			$name
		);
		return $h;
	}

	/**
	 * Aktuelle Y-Position abfragen.
	 */
	public function get_y() {
		return $this->y;
	}

	/**
	 * Y-Position setzen.
	 */
	public function set_y( $y ) {
		$this->y = $y;
	}

	/**
	 * Nutzbare Inhaltsbreite (zwischen den Rändern).
	 */
	public function content_width() {
		return $this->page_w - 2 * $this->margin;
	}

	/**
	 * Das fertige PDF als Binärstring bauen.
	 */
	public function output() {
		if ( '' !== $this->buffer ) {
			$this->pages[] = $this->buffer;
			$this->buffer  = '';
		}
		if ( empty( $this->pages ) ) {
			$this->pages[] = '';
		}

		if ( $this->ci_enabled ) {
			$n_pages  = count( $this->pages );
			$firma    = $this->enc( (string) Settings::get( 'firma_name' ) );
			$strasse  = (string) Settings::get( 'firma_strasse' );
			$plz_ort  = (string) Settings::get( 'firma_plz_ort' );
			$email    = (string) Settings::get( 'firma_email' );
			$footer_parts = array_filter( array( $strasse, $plz_ort, $email ) );
			$footer_info  = $this->enc( implode( ' \267 ', $footer_parts ) );

			$tinte = self::hex_rgb( self::COLOR_TINTE );
			$grey  = '0.600 0.600 0.600';

			foreach ( $this->pages as $i => &$stream ) {
				$page_str = $this->enc( sprintf( __( 'Seite %1$d von %2$d', 'lindenweg16' ), $i + 1, $n_pages ) );

				$line_y = 42;
				$stream .= sprintf(
					"0.85 0.85 0.85 RG %.2F %.2F m %.2F %.2F l 0.3 w S\n",
					$this->margin,
					$line_y,
					$this->page_w - $this->margin,
					$line_y
				);

				$tinte_cmd = sprintf( '%.3F %.3F %.3F rg', $tinte[0], $tinte[1], $tinte[2] );

				$stream .= sprintf(
					"BT %s /F2 7 Tf %.2F %.2F Td (%s) Tj ET\n",
					$tinte_cmd,
					$this->margin,
					30.0,
					$firma
				);

				if ( $footer_info ) {
					$info_w = strlen( $footer_info ) * 3.5;
					$info_x = ( $this->page_w - $info_w ) / 2;
					$stream .= sprintf(
						"BT %s rg /F1 7 Tf %.2F %.2F Td (%s) Tj ET\n",
						$grey,
						$info_x,
						30.0,
						$footer_info
					);
				}

				$page_w = strlen( $page_str ) * 3.5;
				$stream .= sprintf(
					"BT %s rg /F1 7 Tf %.2F %.2F Td (%s) Tj ET\n",
					$grey,
					$this->page_w - $this->margin - $page_w,
					30.0,
					$page_str
				);
			}
			unset( $stream );
		}

		$objects  = array();
		$n_pages = count( $this->pages );

		$font1 = 3;
		$font2 = 4;
		$first_page_obj = 5;

		$image_obj_start = $first_page_obj + $n_pages * 2;
		$image_names     = array_keys( $this->images );
		$image_obj_map   = array();
		foreach ( $image_names as $i => $name ) {
			$image_obj_map[ $name ] = $image_obj_start + $i;
		}

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

		$kids = array();
		for ( $i = 0; $i < $n_pages; $i++ ) {
			$kids[] = ( $first_page_obj + $i * 2 ) . ' 0 R';
		}
		$objects[2] = '<< /Type /Pages /Count ' . $n_pages . ' /Kids [' . implode( ' ', $kids ) . '] >>';

		$objects[ $font1 ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[ $font2 ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		$xobjects = '';
		foreach ( $image_obj_map as $name => $obj ) {
			$xobjects .= '/' . $name . ' ' . $obj . ' 0 R ';
		}
		$resources = '<< /Font << /F1 ' . $font1 . ' 0 R /F2 ' . $font2 . ' 0 R >>';
		if ( $xobjects ) {
			$resources .= ' /XObject << ' . trim( $xobjects ) . ' >>';
		}
		$resources .= ' >>';

		for ( $i = 0; $i < $n_pages; $i++ ) {
			$page_obj    = $first_page_obj + $i * 2;
			$content_obj = $page_obj + 1;
			$objects[ $page_obj ] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>',
				$this->page_w,
				$this->page_h,
				$resources,
				$content_obj
			);
			$stream = $this->pages[ $i ];
			$objects[ $content_obj ] = "<< /Length " . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
		}

		foreach ( $this->images as $name => $img ) {
			$obj = $image_obj_map[ $name ];
			$objects[ $obj ] = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} "
				. "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen( $img['data'] ) . " >>\n"
				. "stream\n" . $img['data'] . "\nendstream";
		}

		ksort( $objects );
		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		$max_obj = max( array_keys( $objects ) );

		for ( $i = 1; $i <= $max_obj; $i++ ) {
			if ( ! isset( $objects[ $i ] ) ) {
				continue;
			}
			$offsets[ $i ] = strlen( $pdf );
			$pdf          .= $i . " 0 obj\n" . $objects[ $i ] . "\nendobj\n";
		}

		$xref_pos = strlen( $pdf );
		$pdf     .= "xref\n0 " . ( $max_obj + 1 ) . "\n";
		$pdf     .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= $max_obj; $i++ ) {
			if ( isset( $offsets[ $i ] ) ) {
				$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
			} else {
				$pdf .= "0000000000 65535 f \n";
			}
		}
		$pdf .= "trailer\n<< /Size " . ( $max_obj + 1 ) . " /Root 1 0 R >>\n";
		$pdf .= "startxref\n" . $xref_pos . "\n%%EOF";

		return $pdf;
	}

	public function save( $path ) {
		return (bool) file_put_contents( $path, $this->output() ); // phpcs:ignore
	}
}
