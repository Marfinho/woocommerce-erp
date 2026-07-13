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

	/**
	 * Content-Streams je Seite.
	 *
	 * @var string[]
	 */
	protected $pages = array();

	/**
	 * Aktueller Seiten-Content-Puffer.
	 *
	 * @var string
	 */
	protected $buffer = '';

	/**
	 * Aktuelle Y-Position (von oben gemessen, in pt).
	 *
	 * @var float
	 */
	protected $y = self::MARGIN;

	/**
	 * Eingebettete Bild-XObjects: name => array( data, w, h ).
	 *
	 * @var array
	 */
	protected $images = array();

	/**
	 * Bildzähler für eindeutige Ressourcennamen.
	 *
	 * @var int
	 */
	protected $img_counter = 0;

	/**
	 * Neues Dokument – erste Seite anlegen.
	 */
	public function __construct() {
		$this->add_page();
	}

	/**
	 * Neue Seite beginnen.
	 */
	public function add_page() {
		if ( '' !== $this->buffer ) {
			$this->pages[] = $this->buffer;
		}
		$this->buffer = '';
		$this->y      = self::MARGIN;
	}

	/**
	 * PDF-Textkodierung (WinAnsi/Latin-1) mit Escaping.
	 *
	 * @param string $text Eingabetext (UTF-8).
	 * @return string
	 */
	protected function enc( $text ) {
		$latin = function_exists( 'mb_convert_encoding' )
			? mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' )
			: utf8_decode( $text );
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $latin );
	}

	/**
	 * Automatischen Seitenumbruch prüfen.
	 *
	 * @param float $needed Benötigte Höhe.
	 */
	protected function ensure_space( $needed ) {
		if ( $this->y + $needed > self::PAGE_HEIGHT - self::MARGIN ) {
			$this->add_page();
		}
	}

	/**
	 * Eine Textzeile ausgeben.
	 *
	 * @param string $text  Text.
	 * @param float  $size  Schriftgröße.
	 * @param bool   $bold  Fett?
	 * @param float  $indent Einzug von links.
	 */
	public function text( $text, $size = 11, $bold = false, $indent = 0 ) {
		$this->ensure_space( $size + 4 );
		$font = $bold ? '/F2' : '/F1';
		$x    = self::MARGIN + $indent;
		$ty   = self::PAGE_HEIGHT - $this->y - $size;
		$this->buffer .= sprintf(
			"BT %s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
			$font,
			$size,
			$x,
			$ty,
			$this->enc( $text )
		);
		$this->y += $size + 4;
	}

	/**
	 * Zwei Spalten (links Label, rechts rechtsbündiger Wert) ausgeben.
	 *
	 * @param string $left  Linker Text.
	 * @param string $right Rechter Text.
	 * @param float  $size  Größe.
	 * @param bool   $bold  Fett?
	 */
	public function row( $left, $right, $size = 11, $bold = false ) {
		$this->ensure_space( $size + 4 );
		$font = $bold ? '/F2' : '/F1';
		$ty   = self::PAGE_HEIGHT - $this->y - $size;
		$right_w = strlen( $right ) * $size * 0.5;
		$rx      = self::PAGE_WIDTH - self::MARGIN - $right_w;
		$this->buffer .= sprintf(
			"BT %s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
			$font,
			$size,
			self::MARGIN,
			$ty,
			$this->enc( $left )
		);
		$this->buffer .= sprintf(
			"BT %s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
			$font,
			$size,
			$rx,
			$ty,
			$this->enc( $right )
		);
		$this->y += $size + 4;
	}

	/**
	 * Etwas vertikalen Abstand einfügen.
	 *
	 * @param float $h Höhe in pt.
	 */
	public function space( $h = 8 ) {
		$this->y += $h;
	}

	/**
	 * Horizontale Trennlinie.
	 */
	public function hr() {
		$this->ensure_space( 6 );
		$yy = self::PAGE_HEIGHT - $this->y;
		$this->buffer .= sprintf(
			"%.2F %.2F m %.2F %.2F l 0.5 w S\n",
			self::MARGIN,
			$yy,
			self::PAGE_WIDTH - self::MARGIN,
			$yy
		);
		$this->y += 6;
	}

	/**
	 * Ein JPEG-Bild einbetten (skaliert auf max. Breite/Höhe).
	 *
	 * @param string $file    Absoluter Pfad zu einer JPEG-Datei.
	 * @param float  $max_w   Maximale Breite.
	 * @param float  $max_h   Maximale Höhe.
	 * @return bool Erfolg.
	 */
	public function image_jpeg( $file, $max_w = 400, $max_h = 500 ) {
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

		$this->ensure_space( $h + 8 );

		$this->img_counter++;
		$name = 'Img' . $this->img_counter;
		$this->images[ $name ] = array(
			'data' => $data,
			'w'    => $iw,
			'h'    => $ih,
		);

		$x  = self::MARGIN;
		$yy = self::PAGE_HEIGHT - $this->y - $h;
		$this->buffer .= sprintf(
			"q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
			$w,
			$h,
			$x,
			$yy,
			$name
		);
		$this->y += $h + 8;
		return true;
	}

	/**
	 * Das fertige PDF als Binärstring bauen.
	 *
	 * @return string
	 */
	public function output() {
		if ( '' !== $this->buffer ) {
			$this->pages[] = $this->buffer;
			$this->buffer  = '';
		}
		if ( empty( $this->pages ) ) {
			$this->pages[] = '';
		}

		$objects   = array();
		$n_pages   = count( $this->pages );

		// Objektnummern planen:
		// 1 = Catalog, 2 = Pages, 3 = F1, 4 = F2,
		// danach je Seite: Page-Obj + Content-Obj, danach Bilder.
		$font1 = 3;
		$font2 = 4;
		$first_page_obj = 5;

		// Bild-Objektnummern zuweisen.
		$image_obj_start = $first_page_obj + $n_pages * 2;
		$image_names     = array_keys( $this->images );
		$image_obj_map   = array();
		foreach ( $image_names as $i => $name ) {
			$image_obj_map[ $name ] = $image_obj_start + $i;
		}

		// Catalog.
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

		// Pages.
		$kids = array();
		for ( $i = 0; $i < $n_pages; $i++ ) {
			$kids[] = ( $first_page_obj + $i * 2 ) . ' 0 R';
		}
		$objects[2] = '<< /Type /Pages /Count ' . $n_pages . ' /Kids [' . implode( ' ', $kids ) . '] >>';

		// Fonts.
		$objects[ $font1 ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[ $font2 ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		// XObject-Ressourcen-Referenzen für alle Seiten (der Einfachheit halber global).
		$xobjects = '';
		foreach ( $image_obj_map as $name => $obj ) {
			$xobjects .= '/' . $name . ' ' . $obj . ' 0 R ';
		}
		$resources = '<< /Font << /F1 ' . $font1 . ' 0 R /F2 ' . $font2 . ' 0 R >>';
		if ( $xobjects ) {
			$resources .= ' /XObject << ' . trim( $xobjects ) . ' >>';
		}
		$resources .= ' >>';

		// Seiten + Content.
		for ( $i = 0; $i < $n_pages; $i++ ) {
			$page_obj    = $first_page_obj + $i * 2;
			$content_obj = $page_obj + 1;
			$objects[ $page_obj ] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>',
				self::PAGE_WIDTH,
				self::PAGE_HEIGHT,
				$resources,
				$content_obj
			);
			$stream = $this->pages[ $i ];
			$objects[ $content_obj ] = "<< /Length " . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
		}

		// Bild-XObjects.
		foreach ( $this->images as $name => $img ) {
			$obj = $image_obj_map[ $name ];
			$objects[ $obj ] = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} "
				. "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen( $img['data'] ) . " >>\n"
				. "stream\n" . $img['data'] . "\nendstream";
		}

		// PDF zusammensetzen mit xref.
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

	/**
	 * PDF in eine Datei schreiben.
	 *
	 * @param string $path Zielpfad.
	 * @return bool
	 */
	public function save( $path ) {
		return (bool) file_put_contents( $path, $this->output() ); // phpcs:ignore
	}
}
