<?php
/**
 * Spulenverwaltung: Material, Farbe, Menge – ein Etikett je Spule.
 *
 * Hinter jeder Spule steht weiter eine Charge (der innere Anker, an dem der
 * Verbrauch aus den Druckjobs hängt), aber sie ist nie zu sehen: Eine Spule
 * erfassen heißt, eine Charge mit genau einer Rolle anzulegen. Das Etikett
 * geht über dieselbe Warteschlange an den Etikettendrucker wie die
 * Plattenetiketten; leer gemeldet wird die Spule durch Scannen ihres Codes.
 *
 * Die Prüfung der Eingaben ist rein (ohne WordPress) und getestet.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\FilamentRepository;
use Lindenweg16\Modules\Betrieb\FilamentRolls;
use Lindenweg16\Modules\Betrieb\LabelPdf;
use Lindenweg16\Modules\Betrieb\LabelSheet;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrintQueueService;
use Lindenweg16\Modules\Betrieb\ScanResolver;

/**
 * Spulen erfassen und etikettieren.
 */
class Spools {

	/**
	 * Wählbare Materialien.
	 */
	const MATERIALS = array( 'PETG', 'PLA', 'ASA', 'ABS', 'TPU', 'PETG-CF' );

	/**
	 * Größte erlaubte Menge je Spule in Gramm.
	 */
	const MAX_G = 10000;

	/**
	 * Zahl aus der Eingabe (auch mit Komma).
	 *
	 * @param mixed $raw Eingabe.
	 * @return float|null Null, wenn keine Zahl.
	 */
	public static function number( $raw ) {
		$raw = trim( str_replace( ',', '.', (string) $raw ) );

		return '' !== $raw && is_numeric( $raw ) ? (float) $raw : null;
	}

	/**
	 * Eingaben prüfen und die Menge in Gramm bestimmen.
	 *
	 * Eine neue Spule trägt die Menge direkt ein (meist 1000 g). Bei einer
	 * angebrochenen wird sie gewogen: Gewicht samt Spule abzüglich der leeren Spule.
	 *
	 * @param array $in    material, color, mode (neu|angebrochen), grams, gross_g, tare_g.
	 * @param int   $tare  Vorgabe für das Gewicht der leeren Spule.
	 * @param array $known Bekannte Farben (Kennung => Name); leer = nicht prüfen.
	 * @return array {errors, data: material, color, grams, partial}
	 */
	public static function validate( array $in, $tare = 0, array $known = array() ) {
		$errors   = array();
		$material = isset( $in['material'] ) ? strtoupper( trim( (string) $in['material'] ) ) : '';
		$color    = isset( $in['color'] ) ? trim( (string) $in['color'] ) : '';
		$partial  = isset( $in['mode'] ) && 'angebrochen' === $in['mode'];

		if ( ! in_array( $material, self::MATERIALS, true ) ) {
			$errors[] = 'Bitte ein Material wählen.';
		}

		if ( '' === $color || ( $known && ! array_key_exists( $color, $known ) ) ) {
			$errors[] = 'Bitte eine Farbe wählen.';
		}

		$grams = null;

		if ( $partial ) {
			$gross = self::number( isset( $in['gross_g'] ) ? $in['gross_g'] : '' );
			$spool = self::number( isset( $in['tare_g'] ) ? $in['tare_g'] : '' );
			$spool = null === $spool ? (float) $tare : $spool;

			if ( null === $gross ) {
				$errors[] = 'Bitte das gewogene Gewicht samt Spule eintragen.';
			} elseif ( $gross - $spool < 1 ) {
				$errors[] = 'Das Gewicht ist nicht größer als die leere Spule – dann ist nichts Filament mehr drauf.';
			} else {
				$grams = $gross - $spool;
			}
		} else {
			$grams = self::number( isset( $in['grams'] ) ? $in['grams'] : '' );

			if ( null === $grams || $grams < 1 ) {
				$errors[] = 'Bitte die Menge in Gramm eintragen, bei einer neuen Rolle meist 1000.';
				$grams    = null;
			}
		}

		if ( null !== $grams && $grams > self::MAX_G ) {
			$errors[] = 'Diese Menge ist zu groß – vermutlich ein Tippfehler (höchstens 10 000 g je Spule).';
		}

		return array(
			'errors' => $errors,
			'data'   => array(
				'material' => $material,
				'color'    => $color,
				'grams'    => null === $grams ? 0 : (int) round( $grams ),
				'partial'  => $partial,
			),
		);
	}

	/**
	 * Etikett einer Spule (Daten für LabelPdf).
	 *
	 * @param array $roll Beschriebene Rolle (FilamentRolls::describe).
	 * @return array code, name, payload, kind.
	 */
	public static function label( array $roll ) {
		$name = trim( $roll['color_label'] . ' ' . $roll['material'] );

		return array(
			'code'    => $roll['code'],
			'name'    => ( '' !== $name ? $name : $roll['code'] ) . ' · ' . number_format_i18n( $roll['start_g'] / 1000, 2 ) . ' kg',
			'payload' => ScanResolver::PREFIX_ROLL . $roll['code'],
			'kind'    => trim( __( 'Filament', 'lindenweg16' ) . ' ' . $roll['material'] ),
		);
	}

	/**
	 * Spule anlegen.
	 *
	 * @param array $in Eingaben (siehe validate()) und print (Etikett gleich drucken).
	 * @return array {ok, message, code, label}
	 */
	public static function create( array $in ) {
		$check = self::validate( $in, FilamentRolls::default_tare_g(), ColorRegistry::all() );

		if ( $check['errors'] ) {
			return array( 'ok' => false, 'message' => implode( ' ', $check['errors'] ) );
		}

		$d      = $check['data'];
		$result = FilamentRepository::validate(
			array(
				'material'    => $d['material'],
				'color'       => $d['color'],
				'kg_received' => $d['grams'] / 1000,
				'notes'       => $d['partial'] ? __( 'Angebrochene Spule beim Erfassen gewogen.', 'lindenweg16' ) : '',
			)
		);

		if ( $result['errors'] ) {
			return array( 'ok' => false, 'message' => implode( ' ', $result['errors'] ) );
		}

		// Eine Spule = eine Rolle: Das Rollengewicht ist die ganze Menge.
		$lot_id = FilamentRepository::insert( $result['data'], $d['grams'] );
		$rolls  = $lot_id ? FilamentRolls::described_for_lot( $lot_id, false ) : array();

		if ( ! $rolls ) {
			return array( 'ok' => false, 'message' => __( 'Die Spule konnte nicht angelegt werden.', 'lindenweg16' ) );
		}

		$roll    = $rolls[0];
		$message = sprintf(
			/* translators: 1: Code, 2: Menge in Gramm. */
			__( 'Spule %1$s mit %2$d g angelegt.', 'lindenweg16' ),
			$roll['code'],
			$d['grams']
		);

		if ( ! empty( $in['print'] ) ) {
			$printed = self::print_label( $roll['code'] );
			$message .= ' ' . $printed['message'];
		}

		return array( 'ok' => true, 'message' => $message, 'code' => $roll['code'] );
	}

	/**
	 * Etikett einer Spule an den Etikettendrucker schicken.
	 *
	 * @param string $code Rollencode (R-00012).
	 * @return array {ok, message}
	 */
	public static function print_label( $code ) {
		$settings = Workflow::settings();

		if ( '' === $settings['label_printer'] ) {
			return array( 'ok' => false, 'message' => __( 'Kein Etikettendrucker eingestellt – das Etikett wurde nicht gedruckt.', 'lindenweg16' ) );
		}

		$row = FilamentRolls::find_by_code( $code );
		if ( ! $row ) {
			return array( 'ok' => false, 'message' => __( 'Diese Spule gibt es nicht.', 'lindenweg16' ) );
		}

		$roll   = FilamentRolls::describe( $row );
		$sheet  = LabelSheet::roll( $settings['label_roll'] );
		$width  = LabelSheet::is_landscape( $sheet ) ? (float) $sheet['h'] : (float) $sheet['w'];
		$height = LabelSheet::is_landscape( $sheet ) ? (float) $sheet['w'] : (float) $sheet['h'];
		$pdf    = LabelPdf::build_roll( array( self::label( $roll ) ), $settings['label_roll'] );
		$job    = $pdf ? PrintQueueService::enqueue_pdf_labels( $settings['label_printer'], $pdf->output(), 'roll_label', $width, $height, $settings['label_roll'] ) : 0;

		if ( ! $job ) {
			return array( 'ok' => false, 'message' => __( 'Der Druckjob konnte nicht angelegt werden – ist der Etikettendrucker richtig eingestellt?', 'lindenweg16' ) );
		}

		return array( 'ok' => true, 'message' => __( 'Etikett geht an den Drucker.', 'lindenweg16' ) );
	}
}
