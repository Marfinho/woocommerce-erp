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

		$padding = $cell['h'] > 120 ? 10.0 : 6.0;

		// Fehlerkorrektur Q: Ein Etikett im Lager bekommt Staub, Fett und
		// Kratzer ab. Die höhere Stufe kostet Fläche und spart Ärger.
		$matrix = QrCode::matrix( $label['payload'], 'Q' );
		$box    = LabelSheet::qr_box( $cell, count( $matrix ), $padding );

		$pdf->set_color( '#000000' );

		foreach ( LabelSheet::runs( $matrix ) as $run ) {
			$pdf->rect(
				$box['x'] + ( $run['col'] + $box['quiet'] ) * $box['module'],
				$box['y'] + ( $run['row'] + $box['quiet'] ) * $box['module'],
				$run['length'] * $box['module'],
				$box['module']
			);
		}

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
}
