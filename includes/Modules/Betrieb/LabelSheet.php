<?php
/**
 * Geometrie eines Etikettenbogens.
 *
 * Einzeln erzeugte QR-Codes sind zum Nachdrucken gut, zum Erstausstatten
 * eines Lagers unbrauchbar: Niemand öffnet vierzig Seiten. Diese Klasse
 * rechnet aus, wo auf einem A4-Bogen welches Etikett sitzt.
 *
 * Zwei Dinge steckt sie sich zur Aufgabe, die man beim ersten Ausdruck
 * bemerkt und danach nie wieder vergisst:
 *
 * - Bei einem Klebebogen zählt das **genaue Mass**, nicht die gleichmässige
 *   Aufteilung. 70 × 37 mm sind 70 × 37 mm; der Rest der Seite ist Rand.
 *   Wer stattdessen die Seite durch acht teilt, bekommt über den Bogen
 *   hinweg einen Versatz von einem Millimeter – genug, dass die untersten
 *   Etiketten neben der Stanzung liegen.
 * - Ein QR-Code besteht aus tausend kleinen Quadraten. Nebeneinander
 *   liegende schwarze Module werden zu einem Rechteck zusammengefasst, sonst
 *   wird die PDF-Datei unnötig gross.
 *
 * Frei von Datenbank, PDF und WordPress, damit die Rechnung prüfbar ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Rechnet Bogenaufteilung und QR-Rechtecke.
 */
class LabelSheet {

	/**
	 * A4 in Punkten.
	 */
	const PAGE_WIDTH  = 595.28;
	const PAGE_HEIGHT = 841.89;

	/**
	 * Ein Millimeter in Punkten.
	 */
	const MM = 2.834645669;

	/**
	 * Die wählbaren Aufteilungen.
	 *
	 * label_w und label_h sind das Sollmass des Etiketts; ist es gesetzt,
	 * wird der Block mittig auf die Seite gelegt statt die Seite geteilt.
	 *
	 * @return array
	 */
	public static function layouts() {
		return array(
			'bogen'  => array(
				'label'   => __( 'Klebebogen 70 × 37 mm (24 je Seite)', 'lindenweg16' ),
				'hint'    => __( 'Passt auf handelsübliche Universal-Etikettenbögen mit 24 Etiketten. Ohne Schnittlinien.', 'lindenweg16' ),
				'cols'    => 3,
				'rows'    => 8,
				'label_w' => 70.0 * self::MM,
				'label_h' => 37.0 * self::MM,
				'cut'     => false,
			),
			'regal'  => array(
				'label'   => __( 'Regalschilder 99 × 57 mm (10 je Seite)', 'lindenweg16' ),
				'hint'    => __( 'Grosse Schilder für Regalböden und Kisten – aus einigen Metern noch lesbar. Mit Schnittlinien.', 'lindenweg16' ),
				'cols'    => 2,
				'rows'    => 5,
				'label_w' => 99.0 * self::MM,
				'label_h' => 57.0 * self::MM,
				'cut'     => true,
			),
			'klein'  => array(
				'label'   => __( 'Klein 48 × 25 mm (44 je Seite)', 'lindenweg16' ),
				'hint'    => __( 'Für kleine Fächer und Artikeletiketten. Mit Schnittlinien.', 'lindenweg16' ),
				'cols'    => 4,
				'rows'    => 11,
				'label_w' => 48.0 * self::MM,
				'label_h' => 25.0 * self::MM,
				'cut'     => true,
			),
		);
	}

	/**
	 * Gibt es diese Aufteilung?
	 *
	 * @param string $key Kennung.
	 * @return bool
	 */
	public static function has_layout( $key ) {
		return array_key_exists( (string) $key, self::layouts() );
	}

	/**
	 * Eine Aufteilung, mit Rückfall auf den Klebebogen.
	 *
	 * @param string $key Kennung.
	 * @return array
	 */
	public static function layout( $key ) {
		$layouts = self::layouts();

		return isset( $layouts[ $key ] ) ? $layouts[ $key ] : $layouts['bogen'];
	}

	/**
	 * Wie viele Etiketten auf eine Seite passen.
	 *
	 * @param array $layout Aufteilung.
	 * @return int
	 */
	public static function per_page( array $layout ) {
		return max( 1, (int) $layout['cols'] * (int) $layout['rows'] );
	}

	/**
	 * Die Felder einer Seite, zeilenweise von oben links.
	 *
	 * @param array $layout Aufteilung.
	 * @return array Liste aus x, y, w, h – y von oben gemessen.
	 */
	public static function cells( array $layout ) {
		$cols = max( 1, (int) $layout['cols'] );
		$rows = max( 1, (int) $layout['rows'] );

		$w = isset( $layout['label_w'] ) && $layout['label_w'] > 0
			? (float) $layout['label_w']
			: self::PAGE_WIDTH / $cols;
		$h = isset( $layout['label_h'] ) && $layout['label_h'] > 0
			? (float) $layout['label_h']
			: self::PAGE_HEIGHT / $rows;

		// Der belegte Block wird mittig gelegt. Was übrig bleibt, ist Rand –
		// und zwar oben wie unten gleich viel, damit ein falsch eingelegter
		// Bogen nicht auffällt.
		$left = ( self::PAGE_WIDTH - $cols * $w ) / 2;
		$top  = ( self::PAGE_HEIGHT - $rows * $h ) / 2;

		$out = array();
		for ( $row = 0; $row < $rows; $row++ ) {
			for ( $col = 0; $col < $cols; $col++ ) {
				$out[] = array(
					'x' => $left + $col * $w,
					'y' => $top + $row * $h,
					'w' => $w,
					'h' => $h,
				);
			}
		}

		return $out;
	}

	/**
	 * Etiketten auf Seiten aufteilen.
	 *
	 * @param array $labels Etiketten.
	 * @param array $layout Aufteilung.
	 * @return array Liste von Seiten mit je einer Liste von Etiketten.
	 */
	public static function paginate( array $labels, array $layout ) {
		if ( ! $labels ) {
			return array();
		}

		return array_chunk( array_values( $labels ), self::per_page( $layout ) );
	}

	/**
	 * Waagerechte Läufe schwarzer Module eines QR-Rasters.
	 *
	 * Statt für jedes Modul ein Rechteck zu zeichnen, wird eine Folge
	 * nebeneinanderliegender Module zu einem Rechteck zusammengefasst. Das
	 * Bild ist dasselbe, die Datei ein Vielfaches kleiner.
	 *
	 * @param array $matrix Zeilen aus 0 und 1.
	 * @return array Liste aus row, col, length.
	 */
	public static function runs( array $matrix ) {
		$runs = array();

		foreach ( $matrix as $y => $row ) {
			$start = null;

			foreach ( $row as $x => $on ) {
				if ( $on && null === $start ) {
					$start = $x;
				}

				if ( ! $on && null !== $start ) {
					$runs[] = array( 'row' => $y, 'col' => $start, 'length' => $x - $start );
					$start  = null;
				}
			}

			if ( null !== $start ) {
				$runs[] = array( 'row' => $y, 'col' => $start, 'length' => count( $row ) - $start );
			}
		}

		return $runs;
	}

	/**
	 * Wo der QR-Code im Etikett sitzt und wie gross ein Modul ist.
	 *
	 * Der Code bekommt die Höhe des Etiketts abzüglich Innenabstand und
	 * sitzt links; rechts daneben bleibt der Text. Die Modulgrösse wird
	 * abgerundet, damit der Code nie über seine Fläche hinausläuft.
	 *
	 * @param array $cell    Feld mit x, y, w, h.
	 * @param int   $modules Kantenlänge des Rasters in Modulen.
	 * @param float $padding Innenabstand.
	 * @param int   $quiet   Ruhezone in Modulen.
	 * @return array {x, y, module, size}
	 */
	public static function qr_box( array $cell, $modules, $padding = 6.0, $quiet = 2 ) {
		$modules = max( 1, (int) $modules );
		$total   = $modules + 2 * (int) $quiet;

		// Der Code darf höchstens halb so breit wie das Etikett werden,
		// sonst bleibt für den Klartext nichts übrig.
		$verfuegbar = min( $cell['h'] - 2 * $padding, $cell['w'] / 2 );
		$module     = floor( ( $verfuegbar / $total ) * 100 ) / 100;
		$size       = $module * $total;

		return array(
			'x'      => $cell['x'] + $padding,
			'y'      => $cell['y'] + ( $cell['h'] - $size ) / 2,
			'module' => $module,
			'size'   => $size,
			'quiet'  => (int) $quiet,
		);
	}

	/**
	 * Text auf eine Breite kürzen. Gekürzt wird mit Auslassungspunkten,
	 * damit erkennbar bleibt, dass etwas fehlt.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Zeichen.
	 * @return string
	 */
	public static function shorten( $text, $chars ) {
		$text  = trim( (string) $text );
		$chars = max( 4, (int) $chars );

		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) <= $chars ? $text : mb_substr( $text, 0, $chars - 1 ) . '…';
		}

		return strlen( $text ) <= $chars ? $text : substr( $text, 0, $chars - 1 ) . '…';
	}
}
