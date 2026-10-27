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
	public static function roll_labels( $only_active = true, $lot_id = 0 ) {
		$out = array();

		foreach ( FilamentRepository::all() as $lot ) {
			if ( $lot_id && (int) $lot['id'] !== (int) $lot_id ) {
				continue;
			}

			foreach ( FilamentRolls::described_for_lot( (int) $lot['id'], (bool) $only_active ) as $roll ) {
				$name = trim( $roll['color_label'] . ' ' . $roll['material'] );

				$out[] = array(
					'code'    => $roll['code'],
					'name'    => ( '' !== $name ? $name : $roll['code'] ) . ' · ' . number_format_i18n( $roll['start_g'] / 1000, 2 ) . ' kg',
					'payload' => ScanResolver::PREFIX_ROLL . $roll['code'],
					'kind'    => sprintf(
						/* translators: %s: Chargencode. */
						__( 'Filament · Charge %s', 'lindenweg16' ),
						$roll['lot_code']
					),
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
}
