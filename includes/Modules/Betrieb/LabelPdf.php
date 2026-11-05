<?php
/**
 * Etikettenbogen mit QR-Codes als PDF.
 *
 * Gedacht für den Tag, an dem das Lager eingerichtet wird: alle Lagerplätze
 * und alle Artikel auf einmal ausdrucken, ausschneiden oder abziehen,
 * ankleben, fertig. Die Codes sind dieselben, die auch einzeln im Backend
 * stehen – gescannt wird über ScanResolver, hier wie dort.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Pdf;

/**
 * Baut den Bogen.
 */
class LabelPdf {

	/**
	 * Etiketten der Lagerplätze.
	 *
	 * @param bool $only_active Nur aktive Plätze.
	 * @return array
	 */
	public static function bin_labels( $only_active = true ) {
		$out = array();

		foreach ( BinRepository::all( (bool) $only_active ) as $bin ) {
			$out[] = array(
				'code'    => (string) $bin['code'],
				'name'    => (string) $bin['name'],
				'payload' => ScanResolver::PREFIX_BIN . $bin['code'],
				'kind'    => __( 'Lagerplatz', 'lindenweg16' ),
			);
		}

		return $out;
	}

	/**
	 * Etiketten der Artikel.
	 *
	 * @param bool $only_active Nur Artikel, die nicht stillgelegt sind.
	 * @return array
	 */
	public static function article_labels( $only_active = true ) {
		$out = array();

		foreach ( ArticleRepository::all() as $article ) {
			if ( $only_active && ! ArticleRepository::is_active( $article ) ) {
				continue;
			}

			$out[] = array(
				'code'    => (string) $article['sku'],
				'name'    => (string) $article['name'],
				'payload' => ScanResolver::PREFIX_ARTICLE . $article['sku'],
				'kind'    => __( 'Artikel', 'lindenweg16' ),
			);
		}

		return $out;
	}

	/**
	 * Etiketten der Produkte, die im Shop stehen.
	 *
	 * Je Etikett: Produktname, ein Foto des Moduls und die Artikelnummer.
	 * Aufgenommen werden nur Artikel mit Shop-Verknüpfung, deren Produkt
	 * es im Shop auch (noch) gibt.
	 *
	 * @param int[] $only_ids Nur diese Artikel (leer = alle).
	 * @return array
	 */
	public static function product_labels( array $only_ids = array() ) {
		$out = array();

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $out;
		}

		foreach ( ArticleRepository::all() as $article ) {
			$wc_id = (int) $article['wc_product_id'];

			if ( $wc_id < 1 || ( $only_ids && ! in_array( (int) $article['id'], $only_ids, true ) ) ) {
				continue;
			}

			$product = wc_get_product( $wc_id );

			if ( ! $product ) {
				continue;
			}

			$name = trim( wp_strip_all_tags( (string) $product->get_name() ) );

			$out[] = array(
				'code'    => (string) $article['sku'],
				'name'    => '' !== $name ? $name : (string) $article['name'],
				'payload' => ScanResolver::PREFIX_ARTICLE . $article['sku'],
				'kind'    => __( 'Produkt', 'lindenweg16' ),
				'produkt' => true,
				'image'   => self::product_image( (int) $product->get_image_id() ),
			);
		}

		return $out;
	}

	/**
	 * Das Produktfoto als JPEG-Datei, klein genug für ein Etikett.
	 *
	 * Das PDF bettet nur JPEG ein. Andere Formate (PNG, WebP) werden in eine
	 * Temporärdatei umgewandelt; sie wird nach dem Einbetten gelöscht.
	 *
	 * @param int $attachment_id Mediathek-Eintrag.
	 * @return array|null {path, temp} oder null ohne Bild.
	 */
	protected static function product_image( $attachment_id ) {
		if ( $attachment_id < 1 || ! function_exists( 'get_attached_file' ) ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );
		$size = function_exists( 'image_get_intermediate_size' ) ? image_get_intermediate_size( $attachment_id, 'medium' ) : false;

		if ( $size && ! empty( $size['path'] ) ) {
			$uploads = wp_get_upload_dir();
			$klein   = trailingslashit( $uploads['basedir'] ) . $size['path'];
			$file    = is_readable( $klein ) ? $klein : $file;
		}

		if ( ! $file || ! is_readable( $file ) ) {
			return null;
		}

		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( $info && IMAGETYPE_JPEG === $info[2] ) {
			return array( 'path' => $file, 'temp' => false );
		}

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			return null;
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return null;
		}

		$editor->resize( 400, 400, false );
		$editor->set_quality( 88 );
		$tmp   = wp_tempnam( 'lw16-etikett' );
		$saved = $editor->save( $tmp . '.jpg', 'image/jpeg' );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return null;
		}

		return array( 'path' => $saved['path'], 'temp' => true );
	}

	/**
	 * Etiketten der Drucker.
	 *
	 * Anders als Lagerplatz und Artikel trägt ein Druckeretikett keinen
	 * Scan-Code, sondern die Erfassungsadresse: Wer ihn scannt, landet mit
	 * dem Handy direkt im Formular für diesen Drucker. Der QR-Code ist damit
	 * länger als bei den anderen Sorten – deshalb ist er es wert, das
	 * Ergebnis einmal wirklich anzusehen, statt es zu vermuten.
	 *
	 * @param bool $only_active Nur Geräte, die produzieren.
	 * @return array
	 */
	public static function roll_labels( $only_active = true, $lot_id = 0, $color = '' ) {
		$out = array();

		foreach ( FilamentRepository::all() as $lot ) {
			if ( $lot_id && (int) $lot['id'] !== (int) $lot_id ) {
				continue;
			}

			foreach ( FilamentRolls::described_for_lot( (int) $lot['id'], (bool) $only_active ) as $roll ) {
				if ( '' !== $color && $roll['color'] !== $color ) {
					continue;
				}

				$name = trim( $roll['color_label'] . ' ' . $roll['material'] );

				$out[] = array(
					'code'    => $roll['code'],
					'name'    => ( '' !== $name ? $name : $roll['code'] ) . ' · ' . number_format_i18n( $roll['start_g'] / 1000, 2 ) . ' kg',
					'payload' => ScanResolver::PREFIX_ROLL . $roll['code'],
					'kind'    => trim( __( 'Filament', 'lindenweg16' ) . ' ' . $roll['material'] ),
				);
			}
		}

		return $out;
	}

	public static function printer_labels( $only_active = true ) {
		$out = array();

		foreach ( PrinterRepository::all() as $printer ) {
			if ( $only_active && ! in_array( (string) $printer['status'], MachineRate::PRODUCTIVE_STATUS, true ) ) {
				continue;
			}

			$geraet = trim( (string) $printer['manufacturer'] . ' ' . (string) $printer['model'] );

			$out[] = array(
				'code'    => (string) $printer['code'],
				'name'    => '' !== $geraet ? $geraet : (string) $printer['code'],
				'payload' => DeviceLabel::url( (string) $printer['code'] ),
				'kind'    => __( 'Drucker', 'lindenweg16' ),
			);
		}

		return $out;
	}

	/**
	 * Den Bogen erzeugen.
	 *
	 * @param array  $labels Etiketten mit code, name, payload, kind.
	 * @param string $layout Kennung der Aufteilung.
	 * @return Pdf|null Null, wenn es nichts zu drucken gibt.
	 */
	public static function build( array $labels, $layout = 'bogen' ) {
		if ( ! $labels ) {
			return null;
		}

		$layout = LabelSheet::layout( $layout );
		$cells  = LabelSheet::cells( $layout );
		$pdf    = new Pdf();

		$erste = true;

		foreach ( LabelSheet::paginate( $labels, $layout ) as $page ) {
			if ( ! $erste ) {
				$pdf->add_page();
			}
			$erste = false;

			foreach ( $page as $index => $label ) {
				self::render_label( $pdf, $cells[ $index ], $label, $layout );
			}
		}

		return $pdf;
	}

	/**
	 * Einen Stapel für den Etikettendrucker erzeugen: eine Seite je
	 * Etikett, die Seite genau so gross wie das Etikett.
	 *
	 * Nur so setzt der Brother-Treiber das Bild ohne Skalierung auf die
	 * Rolle. Ein A4-Bogen, den der Treiber auf die Rollenbreite
	 * herunterrechnet, ergibt einen QR-Code, den keine Kamera mehr liest.
	 *
	 * @param array  $labels Etiketten.
	 * @param string $roll   Kennung des Rollenformats.
	 * @return Pdf|null
	 */
	public static function build_roll( array $labels, $roll = LabelSheet::DEFAULT_ROLL ) {
		if ( ! $labels ) {
			return null;
		}

		$roll = LabelSheet::roll( $roll );
		$cell = LabelSheet::roll_cell( $roll );
		$page = LabelSheet::roll_page( $roll );
		$quer = LabelSheet::is_landscape( $roll );

		$pdf = new Pdf( $page['w'], $page['h'], 0 );

		$erste = true;

		foreach ( $labels as $label ) {
			if ( ! $erste ) {
				$pdf->add_page();
			}
			$erste = false;

			if ( $quer ) {
				$pdf->begin_quarter_turn();
			}

			self::render_label( $pdf, $cell, $label, array( 'cut' => false ) );

			if ( $quer ) {
				$pdf->end_quarter_turn();
			}
		}

		return $pdf;
	}

	/**
	 * Ein einzelnes Etikett zeichnen.
	 *
	 * @param Pdf   $pdf    Dokument.
	 * @param array $cell   Feld.
	 * @param array $label  Etikett.
	 * @param array $layout Aufteilung.
	 */
	protected static function render_label( Pdf $pdf, array $cell, array $label, array $layout ) {
		if ( ! empty( $layout['cut'] ) ) {
			$pdf->set_draw_color( '#c8ccd0' );
			$pdf->line( $cell['x'], $cell['y'], $cell['x'] + $cell['w'], $cell['y'] );
			$pdf->line( $cell['x'], $cell['y'] + $cell['h'], $cell['x'] + $cell['w'], $cell['y'] + $cell['h'] );
			$pdf->line( $cell['x'], $cell['y'], $cell['x'], $cell['y'] + $cell['h'] );
			$pdf->line( $cell['x'] + $cell['w'], $cell['y'], $cell['x'] + $cell['w'], $cell['y'] + $cell['h'] );
		}

		// Der Rand wächst mit dem Etikett, bleibt aber nie unter 1,5 mm:
		// Der Druckkopf des QL ist schmaler als die Rolle, ganz am Rand
		// würde abgeschnitten.
		$padding = LabelSheet::padding( $cell['h'] );

		if ( ! empty( $label['produkt'] ) ) {
			self::render_product( $pdf, $cell, $label, $padding );
			return;
		}

		// Fehlerkorrektur Q: Ein Etikett im Lager bekommt Staub, Fett und
		// Kratzer ab. Die höhere Stufe kostet Fläche und spart Ärger.
		$matrix = QrCode::matrix( $label['payload'], 'Q' );

		if ( LabelSheet::is_stacked( $cell ) ) {
			self::render_stacked( $pdf, $cell, $label, $matrix, $padding );
			return;
		}

		self::render_beside( $pdf, $cell, $label, $matrix, $padding );
	}

	/**
	 * Das Raster als Rechtecke zeichnen.
	 *
	 * @param Pdf   $pdf    Dokument.
	 * @param array $box    Ergebnis von qr_box() oder qr_box_stacked().
	 * @param array $matrix Raster.
	 */
	protected static function render_matrix( Pdf $pdf, array $box, array $matrix ) {
		$pdf->set_color( '#000000' );

		foreach ( LabelSheet::runs( $matrix ) as $run ) {
			$pdf->rect(
				$box['x'] + ( $run['col'] + $box['quiet'] ) * $box['module'],
				$box['y'] + ( $run['row'] + $box['quiet'] ) * $box['module'],
				$run['length'] * $box['module'],
				$box['module']
			);
		}
	}

	/**
	 * Breites Etikett: Code links, Text rechts.
	 *
	 * @param Pdf   $pdf     Dokument.
	 * @param array $cell    Feld.
	 * @param array $label   Etikett.
	 * @param array $matrix  Raster.
	 * @param float $padding Rand.
	 */
	protected static function render_beside( Pdf $pdf, array $cell, array $label, array $matrix, $padding ) {
		$box = LabelSheet::qr_box( $cell, count( $matrix ), $padding );
		self::render_matrix( $pdf, $box, $matrix );

		$text_x = $box['x'] + $box['size'] + $padding;
		$breite = $cell['x'] + $cell['w'] - $padding - $text_x;

		if ( $breite < 30 ) {
			return;
		}

		// Schriftgrössen aus der Etikettenhöhe: Ein Regalschild darf gross
		// sein, ein Fachetikett muss klein bleiben.
		$gross = max( 9.0, min( 20.0, $cell['h'] / 5 ) );
		$klein = max( 6.0, $gross * 0.5 );
		$mitte = $cell['y'] + $cell['h'] / 2;

		$pdf->set_color( '#3A434D' );
		$pdf->text_xy( LabelSheet::shorten( $label['kind'], (int) ( $breite / ( $klein * 0.5 ) ) ), $text_x, $mitte - $gross - $klein - 2, $klein );

		$pdf->set_color( '#000000' );
		$pdf->text_xy( LabelSheet::shorten( $label['code'], (int) ( $breite / ( $gross * 0.54 ) ) ), $text_x, $mitte - $gross, $gross, true );

		if ( '' !== trim( $label['name'] ) ) {
			$pdf->set_color( '#6E7882' );
			$pdf->text_xy( LabelSheet::shorten( $label['name'], (int) ( $breite / ( $klein * 0.52 ) ) ), $text_x, $mitte + 3, $klein );
		}
	}

	/**
	 * Quadratisches oder hohes Etikett: Code oben über die volle Breite,
	 * Text mittig darunter.
	 *
	 * @param Pdf   $pdf     Dokument.
	 * @param array $cell    Feld.
	 * @param array $label   Etikett.
	 * @param array $matrix  Raster.
	 * @param float $padding Rand.
	 */
	protected static function render_stacked( Pdf $pdf, array $cell, array $label, array $matrix, $padding ) {
		$hat_name = '' !== trim( $label['name'] );
		$text     = LabelSheet::stacked_text( $cell, $hat_name );
		$gross    = $text['gross'];
		$klein    = $text['klein'];

		$box = LabelSheet::qr_box_stacked( $cell, count( $matrix ), $padding, $text['box'] );
		self::render_matrix( $pdf, $box, $matrix );

		$breite = $cell['w'] - 2 * $padding;
		$y      = $box['y'] + $box['size'] + 4;

		$code = LabelSheet::shorten( $label['code'], (int) ( $breite / ( $gross * 0.54 ) ) );
		$pdf->set_color( '#000000' );
		$pdf->text_xy( $code, $cell['x'] + ( $cell['w'] - $pdf->text_width( $code, $gross, true ) ) / 2, $y, $gross, true );

		if ( ! $hat_name ) {
			return;
		}

		$name = LabelSheet::shorten( $label['name'], (int) ( $breite / ( $klein * 0.52 ) ) );
		$pdf->set_color( '#6E7882' );
		$pdf->text_xy( $name, $cell['x'] + ( $cell['w'] - $pdf->text_width( $name, $klein ) ) / 2, $y + $gross + 2, $klein );
	}

	/**
	 * Produktetikett: Foto des Moduls, Produktname, Artikelnummer.
	 *
	 * Breites Etikett: Foto links, Text rechts. Quadratisches oder hohes
	 * Etikett: Foto oben, Text darunter. Fehlt das Foto, nimmt der Text die
	 * ganze Breite.
	 *
	 * @param Pdf   $pdf     Dokument.
	 * @param array $cell    Feld.
	 * @param array $label   Etikett mit image {path, temp}, code, name.
	 * @param float $padding Rand.
	 */
	protected static function render_product( Pdf $pdf, array $cell, array $label, $padding ) {
		$image   = ! empty( $label['image']['path'] ) ? $label['image'] : null;
		$stapel  = LabelSheet::is_stacked( $cell );
		$innen_w = $cell['w'] - 2 * $padding;
		$innen_h = $cell['h'] - 2 * $padding;

		if ( $stapel ) {
			// Foto oben (höchstens 55 % der Höhe), Text darunter.
			$bild_h = $image ? min( $innen_w, $innen_h * 0.55 ) : 0;
			$bild_w = $bild_h;
			$bild_x = $cell['x'] + ( $cell['w'] - $bild_w ) / 2;
			$bild_y = $cell['y'] + $padding;
			$text_x = $cell['x'] + $padding;
			$text_w = $innen_w;
			$text_y = $cell['y'] + $padding + ( $image ? $bild_h + 4 : 0 );
			$text_h = $cell['y'] + $cell['h'] - $padding - $text_y;
		} else {
			$bild_h = $image ? $innen_h : 0;
			$bild_w = $image ? min( $innen_h, $innen_w * 0.42 ) : 0;
			$bild_x = $cell['x'] + $padding;
			$bild_y = $cell['y'] + $padding;
			$text_x = $cell['x'] + $padding + ( $image ? $bild_w + $padding : 0 );
			$text_w = $cell['x'] + $cell['w'] - $padding - $text_x;
			$text_y = $cell['y'] + $padding;
			$text_h = $innen_h;
		}

		if ( $image ) {
			$box = min( $bild_w, $bild_h );
			$pdf->image_jpeg_at( $image['path'], $bild_x + ( $bild_w - $box ) / 2, $bild_y + ( $bild_h - $box ) / 2, $box, $box );

			if ( ! empty( $image['temp'] ) ) {
				@unlink( $image['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		if ( $text_w < 30 ) {
			return;
		}

		// Die Artikelnummer ist das, was man liest: groß und fett, am Fuß
		// des Textblocks. Der Name füllt den Platz darüber.
		$nummer_pt = max( 8.0, min( 16.0, $text_h / 3 ) );
		$kopf_pt   = max( 6.0, $nummer_pt * 0.5 );

		// Die Breitenschätzung des PDF-Erzeugers gilt für Kleinbuchstaben;
		// Artikelnummern bestehen aus Großbuchstaben und Ziffern und sind
		// etwa ein Drittel breiter. Mit Zuschlag rechnen, sonst läuft die
		// Nummer über den Rand des Etiketts.
		$nummer = (string) $label['code'];
		while ( $nummer_pt > 6.0 && 1.35 * $pdf->text_width( $nummer, $nummer_pt, true ) > $text_w ) {
			$nummer_pt -= 0.5;
		}
		$nummer = LabelSheet::shorten( $nummer, (int) ( $text_w / ( $nummer_pt * 0.54 * 1.35 ) ) );

		$nummer_y = $text_y + $text_h - $nummer_pt;
		$kopf_y   = $nummer_y - $kopf_pt - 1;

		$pdf->set_color( '#6E7882' );
		$pdf->text_xy( __( 'Artikel-Nr.', 'lindenweg16' ), $text_x, $kopf_y, $kopf_pt );
		$pdf->set_color( '#000000' );
		$pdf->text_xy( $nummer, $text_x, $nummer_y, $nummer_pt, true );

		// Name: so groß wie möglich, bis zu drei Zeilen, im Rest über der Nummer.
		$name_h = $kopf_y - 3 - $text_y;
		$name   = (string) $label['name'];

		if ( $name_h < 7 || '' === trim( $name ) ) {
			return;
		}

		for ( $pt = min( 14.0, $name_h ); $pt >= 6.0; $pt -= 0.5 ) {
			$zeilen_max = max( 1, (int) floor( $name_h / ( $pt * 1.15 ) ) );
			$zeilen     = LabelSheet::wrap(
				$name,
				$text_w,
				min( 3, $zeilen_max ),
				static function ( $t ) use ( $pdf, $pt ) {
					return 1.15 * $pdf->text_width( $t, $pt, true );
				}
			);

			$passt = count( $zeilen ) <= min( 3, $zeilen_max ) && ! preg_match( '/…$/u', end( $zeilen ) );

			if ( $passt || $pt <= 6.0 ) {
				break;
			}
		}

		$pdf->set_color( '#000000' );
		foreach ( $zeilen as $i => $zeile ) {
			$pdf->text_xy( $zeile, $text_x, $text_y + $i * $pt * 1.15, $pt, true );
		}
	}
}
