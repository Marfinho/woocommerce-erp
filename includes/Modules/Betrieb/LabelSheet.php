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
	 * Formate für einen Etikettendrucker mit Endlos- oder Einzelrolle.
	 *
	 * Hier ist die Seite das Etikett: eine Seite, ein Etikett, kein Rand.
	 * Nur so setzt der Druckertreiber das Bild ohne Skalierung auf die
	 * Rolle.
	 *
	 * **Die erste Zahl ist die Rollenbreite, die zweite die Etikettenlänge** –
	 * so benennt Brother seine Rollen, und so steht es hier: DK-11209 ist
	 * 62 mm breit und 29 mm lang, DK-11201 dagegen 29 mm breit und 90 mm
	 * lang. Beides gibt es zur Wahl, denn beides kann im Gerät liegen; die
	 * gewählte Breite muss zur eingelegten Rolle passen, sonst druckt der
	 * Treiber daneben oder skaliert.
	 *
	 * Die Länge ist bei einer Endlosrolle frei wählbar; sie bestimmt, wo
	 * geschnitten wird. Bei gestanzten Etiketten ist sie vorgegeben.
	 *
	 * @return array
	 */
	/**
	 * Das Format, das im Betrieb liegt.
	 *
	 * Eine Anlage hat einen Etikettendrucker, und in dem liegt eine Rolle.
	 * Jede Stelle, die Etiketten erzeugt, geht deshalb von derselben aus –
	 * sonst kommt aus dem einen Knopf ein Bogen für die schmale und aus dem
	 * anderen einer für die breite Rolle, und beim Drucken merkt man es erst
	 * am schiefen Ergebnis.
	 *
	 * Steht hier, nicht in den Aufrufern: Wer die Rolle wechselt, ändert
	 * eine Zeile.
	 */
	const DEFAULT_ROLL = 'dk11201';

	public static function rolls() {
		return array(
			'dk11209'  => array(
				'label' => __( 'DK-11209 · Einzeletiketten 62 × 29 mm', 'lindenweg16' ),
				'hint'  => __( 'Gestanzte Etiketten auf der 62-mm-Rolle. Der Allrounder für Fächer und Artikel.', 'lindenweg16' ),
				'w'     => 62.0,
				'h'     => 29.0,
			),
			'dk11201'  => array(
				'label' => __( 'DK-11201 · Adressetiketten 29 × 90 mm, quer beschriftet', 'lindenweg16' ),
				'hint'  => __( 'Gestanzte Etiketten auf der schmalen 29-mm-Rolle. Beschriftet wird der Länge nach: Code links, Klartext rechts – neun Zentimeter Platz für lange Bezeichnungen.', 'lindenweg16' ),
				'w'     => 29.0,
				'h'     => 90.0,
				'quer'  => true,
			),
			'endlos30' => array(
				'label' => __( 'DK-22205 · endlos, auf 30 mm geschnitten', 'lindenweg16' ),
				'hint'  => __( 'Endlosrolle 62 mm. Kurzes Etikett, so viel wie nötig – spart Material.', 'lindenweg16' ),
				'w'     => 62.0,
				'h'     => 30.0,
			),
			'endlos40' => array(
				'label' => __( 'DK-22205 · endlos, auf 40 mm geschnitten', 'lindenweg16' ),
				'hint'  => __( 'Etwas mehr Luft für lange Bezeichnungen und einen grösseren Code.', 'lindenweg16' ),
				'w'     => 62.0,
				'h'     => 40.0,
			),
			'endlos62' => array(
				'label' => __( 'DK-22205 · endlos, auf 62 mm geschnitten (quadratisch)', 'lindenweg16' ),
				'hint'  => __( 'Code oben über die volle Breite (rund 37 mm), Text darunter – für Regalböden, die man aus zwei Metern scannt.', 'lindenweg16' ),
				'w'     => 62.0,
				'h'     => 62.0,
			),
		);
	}

	/**
	 * Breiteste Rolle, die das Gerät aufnimmt.
	 *
	 * Beim QL-710W ist das die Bauform. Schmalere Rollen laufen ebenfalls –
	 * der Druckkopf bleibt derselbe, er bedruckt dann nur einen Teil seiner
	 * Breite.
	 */
	const MAX_ROLL_WIDTH_MM = 62.0;

	/**
	 * Breite des Druckkopfs in Millimetern.
	 *
	 * 720 Punkte bei 300 dpi. Mehr geht in keiner Rolle.
	 */
	const HEAD_WIDTH_MM = 60.96;

	/**
	 * Streifen an jedem Rollenrand, auf dem nichts ankommt.
	 *
	 * Die Rolle läuft nie auf den Zehntelmillimeter mittig, und der
	 * Druckkopf erreicht die äusserste Kante nicht. Wer bis an den Rand
	 * zeichnet, bekommt ein angeschnittenes Etikett – bei einem QR-Code
	 * heisst das: unlesbar.
	 */
	const UNPRINTABLE_EDGE_MM = 1.5;

	/**
	 * Tatsächlich bedruckbare Breite eines Rollenformats.
	 *
	 * @param array $roll Rollenformat.
	 * @return float Millimeter.
	 */
	public static function printable_width( array $roll ) {
		return min(
			self::HEAD_WIDTH_MM,
			max( 0.0, (float) $roll['w'] - 2 * self::UNPRINTABLE_EDGE_MM )
		);
	}

	/**
	 * Gibt es dieses Rollenformat?
	 *
	 * @param string $key Kennung.
	 * @return bool
	 */
	public static function has_roll( $key ) {
		return array_key_exists( (string) $key, self::rolls() );
	}

	/**
	 * Ein Rollenformat mit Rückfall auf das gängigste.
	 *
	 * @param string $key Kennung.
	 * @return array
	 */
	public static function roll( $key ) {
		$rolls = self::rolls();

		return isset( $rolls[ $key ] ) ? $rolls[ $key ] : $rolls[ self::DEFAULT_ROLL ];
	}

	/**
	 * Wird dieses Etikett der Länge nach beschriftet?
	 *
	 * Ein Adressetikett ist 29 mm breit und 90 mm lang, gelesen wird es
	 * aber quer – wie eine Anschrift. Das Etikett bleibt dabei, was es
	 * ist; nur der Aufdruck steht gedreht darauf.
	 *
	 * @param array $roll Rollenformat.
	 * @return bool
	 */
	public static function is_landscape( array $roll ) {
		return ! empty( $roll['quer'] );
	}

	/**
	 * Das Mass der Seite: immer genau das Etikett.
	 *
	 * Auch beim quer beschrifteten Etikett – gedreht wird der Inhalt, nicht
	 * die Seite. Eine Seite, die anders herum steht als die Rolle, lässt
	 * den Treiber drehen oder skalieren, und beides verdirbt den QR-Code.
	 *
	 * @param array $roll Rollenformat.
	 * @return array {w, h} in pt.
	 */
	public static function roll_page( array $roll ) {
		return array(
			'w' => (float) $roll['w'] * self::MM,
			'h' => (float) $roll['h'] * self::MM,
		);
	}

	/**
	 * Das eine Feld eines Rollenetiketts: die ganze Seite, in der
	 * Richtung, in der beschriftet wird.
	 *
	 * Beim quer beschrifteten Etikett sind Breite und Höhe deshalb
	 * vertauscht – die Aufteilung rechnet mit der langen Kante als Breite,
	 * genau wie sie später gelesen wird.
	 *
	 * @param array $roll Rollenformat.
	 * @return array
	 */
	public static function roll_cell( array $roll ) {
		$quer = self::is_landscape( $roll );

		return array(
			'x' => 0.0,
			'y' => 0.0,
			'w' => (float) ( $quer ? $roll['h'] : $roll['w'] ) * self::MM,
			'h' => (float) ( $quer ? $roll['w'] : $roll['h'] ) * self::MM,
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
	 * Steht der Text unter dem Code statt daneben?
	 *
	 * Auf einem breiten Etikett ist nebeneinander richtig: der Code links,
	 * der Klartext rechts. Auf einem quadratischen oder hohen Etikett
	 * verschenkt das die halbe Fläche – dort gehört der Code nach oben und
	 * der Text darunter, und der Code darf so gross werden, wie das
	 * Etikett breit ist.
	 *
	 * @param array $cell Feld.
	 * @return bool
	 */
	public static function is_stacked( array $cell ) {
		return $cell['h'] >= $cell['w'] * 0.9;
	}

	/**
	 * Der Code eines gestapelten Etiketts: oben, mittig, so gross wie
	 * möglich – abzüglich des Platzes, den der Text darunter braucht.
	 *
	 * @param array $cell     Feld.
	 * @param int   $modules  Kantenlänge des Rasters in Modulen.
	 * @param float $padding  Innenabstand.
	 * @param float $text_box Höhe des Textblocks darunter.
	 * @param int   $quiet    Ruhezone in Modulen.
	 * @return array {x, y, module, size, quiet}
	 */
	public static function qr_box_stacked( array $cell, $modules, $padding, $text_box, $quiet = 2 ) {
		$modules = max( 1, (int) $modules );
		$total   = $modules + 2 * (int) $quiet;

		$verfuegbar = min(
			$cell['w'] - 2 * $padding,
			$cell['h'] - 2 * $padding - (float) $text_box
		);

		$module = floor( ( $verfuegbar / $total ) * 100 ) / 100;
		$size   = $module * $total;

		return array(
			'x'      => $cell['x'] + ( $cell['w'] - $size ) / 2,
			'y'      => $cell['y'] + $padding,
			'module' => $module,
			'size'   => $size,
			'quiet'  => (int) $quiet,
		);
	}

	/**
	 * Schriftgrössen und Platzbedarf des Textblocks unter dem Code.
	 *
	 * Steht hier und nicht beim Zeichnen, damit die Prüfung mit denselben
	 * Zahlen rechnet wie das PDF. Sonst misst ein Test eine Aufteilung,
	 * die so nie gedruckt wird – genau daran ist aufgefallen, dass das
	 * schmale Etikett gar nicht nebeneinander gesetzt wird.
	 *
	 * @param array $cell     Feld.
	 * @param bool  $has_name Steht unter dem Code noch eine Bezeichnung?
	 * @return array {gross, klein, box}
	 */
	public static function stacked_text( array $cell, $has_name = true ) {
		$gross = max( 10.0, min( 24.0, $cell['w'] / 7 ) );
		$klein = max( 7.0, $gross * 0.5 );

		return array(
			'gross' => $gross,
			'klein' => $klein,
			'box'   => $gross + ( $has_name ? $klein + 4 : 0 ) + 6,
		);
	}

	/**
	 * Der Rand, den ein Etikett dieser Höhe bekommt.
	 *
	 * Eine Stelle, damit Zeichnung und Prüfung nicht auseinanderlaufen.
	 *
	 * @param float $height Etikettenhöhe in pt.
	 * @return float
	 */
	public static function padding( $height ) {
		return max( self::UNPRINTABLE_EDGE_MM * self::MM, min( 10.0, (float) $height / 12 ) );
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
