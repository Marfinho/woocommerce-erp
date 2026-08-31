<?php
/**
 * Verlaufsgraph einer Kennzahl gegen Ziel und Alarmgrenze.
 *
 * Rechnen und Zeichnen sind getrennt: `scale()`, `positions()` und `path()`
 * sind reine Mathematik und geprüft; `svg()` setzt nur um, was dabei
 * herauskam. Dieselbe Aufteilung wie in KpiMeter – der Balken zeigt einen
 * Wert gegen eine Grenze, dieser Graph zeigt den Weg dorthin.
 *
 * Drei Entscheidungen, die den Graphen ehrlich halten:
 *
 * 1. **Lücken bleiben Lücken.** Ein Zeitraum ohne Daten unterbricht die
 *    Linie. Eine durchgezogene Linie über eine Lücke behauptet einen
 *    Verlauf, den niemand gemessen hat.
 * 2. **Ziel und Alarm sind eigene Linien, keine Konstanten.** Bei
 *    Summengrößen ist das Monatsziel im Februar kleiner als im März – die
 *    Ziellinie springt, und das ist richtig so.
 * 3. **Die Skala beginnt bei null.** Eine abgeschnittene Achse macht aus
 *    drei Prozent Unterschied einen Berg.
 *
 * Frei von Datenbank und WordPress-Umgebung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Skalenrechnung und SVG-Ausgabe des Verlaufs.
 */
class TrendChart {

	/**
	 * Zeichenfläche in Nutzerkoordinaten. Das SVG skaliert über die viewBox
	 * auf die Spaltenbreite; die Verhältnisse bleiben dabei erhalten.
	 */
	const WIDTH  = 720;
	const HEIGHT = 240;

	/**
	 * Rand: links Platz für die Achsenbeschriftung, unten für die Zeitachse.
	 *
	 * Der linke Rand ist an der längsten vorkommenden Beschriftung bemessen –
	 * ein Geldbetrag mit Tausenderpunkt und Einheit, etwa „20.000,00 €".
	 * Enger, und die führende Ziffer wird abgeschnitten; das fällt keinem
	 * auf, weil „0.000,00 €" wie eine Zahl aussieht.
	 */
	const PAD_L = 96;
	const PAD_R = 14;
	const PAD_T = 12;
	const PAD_B = 30;

	/**
	 * Luft über dem größten Wert, damit die oberste Marke nicht am Rahmen
	 * klebt. Wie in KpiMeter.
	 */
	const HEADROOM = 1.12;

	/**
	 * Farben.
	 *
	 * Der Verlauf ist eine Reihe, also eine Diagrammfarbe. Ziel und Alarm
	 * sind keine zweite und dritte Reihe, sondern Zustandsmarken – sie
	 * kommen aus der Statuspalette und werden nie als Reihe verwendet.
	 *
	 * @return array
	 */
	public static function colors() {
		return array(
			'linie'  => '#2a78d6',
			'ziel'   => '#0ca30c',
			'alarm'  => '#d03b3b',
			'raster' => '#e1e0d9',
			'achse'  => '#898781',
			'grund'  => '#fcfcfb',
		);
	}

	/**
	 * Obere Grenze der Skala und die Marken darauf.
	 *
	 * Die Marke wird auf einen runden Schritt gebracht: 1, 2, 2,5 oder 5 mal
	 * einer Zehnerpotenz. Eine Achse, die bei 1.437,20 endet, liest niemand.
	 *
	 * @param array $points Punkte mit value, target, alarm.
	 * @return array {max, ticks}
	 */
	public static function scale( array $points ) {
		$werte = array();

		foreach ( $points as $point ) {
			foreach ( array( 'value', 'target', 'alarm' ) as $feld ) {
				if ( isset( $point[ $feld ] ) && null !== $point[ $feld ] && is_numeric( $point[ $feld ] ) ) {
					$werte[] = (float) $point[ $feld ];
				}
			}
		}

		$werte = array_filter(
			$werte,
			static function ( $wert ) {
				return $wert > 0;
			}
		);

		if ( ! $werte ) {
			return array( 'max' => 0.0, 'ticks' => array() );
		}

		$max = self::nice_max( max( $werte ) * self::HEADROOM );

		return array(
			'max'   => $max,
			'ticks' => array( 0.0, $max / 4, $max / 2, $max * 3 / 4, $max ),
		);
	}

	/**
	 * Eine runde Obergrenze über einem Wert.
	 *
	 * @param float $wert Wert.
	 * @return float
	 */
	public static function nice_max( $wert ) {
		$wert = (float) $wert;

		if ( $wert <= 0 ) {
			return 0.0;
		}

		$potenz = pow( 10, floor( log10( $wert ) ) );
		$rest   = $wert / $potenz;

		foreach ( array( 1.0, 2.0, 2.5, 5.0, 10.0 ) as $stufe ) {
			if ( $rest <= $stufe + 0.000001 ) {
				return $stufe * $potenz;
			}
		}

		return 10.0 * $potenz;
	}

	/**
	 * Bildkoordinaten der Punkte.
	 *
	 * Ein einzelner Punkt steht in der Mitte statt links am Rand – sonst
	 * klebt er an der Achse und sieht aus wie ein Fehler.
	 *
	 * @param int   $count Anzahl Punkte.
	 * @param float $max   Obergrenze der Skala.
	 * @return array {x: array, y: callable-freie Rechnung über self::y}
	 */
	public static function positions( $count, $max ) {
		$count = max( 0, (int) $count );

		if ( 0 === $count ) {
			return array();
		}

		$links  = self::PAD_L;
		$breite = self::WIDTH - self::PAD_L - self::PAD_R;

		if ( 1 === $count ) {
			return array( $links + $breite / 2 );
		}

		$x = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$x[] = $links + $breite * $i / ( $count - 1 );
		}

		return $x;
	}

	/**
	 * Höhe eines Werts im Bild.
	 *
	 * @param float|null $wert Wert.
	 * @param float      $max  Obergrenze.
	 * @return float|null
	 */
	public static function y( $wert, $max ) {
		if ( null === $wert || ! is_numeric( $wert ) || $max <= 0 ) {
			return null;
		}

		$oben  = self::PAD_T;
		$hoehe = self::HEIGHT - self::PAD_T - self::PAD_B;
		$teil  = min( 1.0, max( 0.0, (float) $wert / $max ) );

		return $oben + $hoehe * ( 1 - $teil );
	}

	/**
	 * Pfad einer Linie, mit Unterbrechungen an den Lücken.
	 *
	 * Jede zusammenhängende Folge beginnt mit einem eigenen M. Ohne das
	 * verbindet der Zeichner den letzten Wert vor der Lücke mit dem ersten
	 * danach und erfindet den Verlauf dazwischen.
	 *
	 * @param array $werte Werte in Reihenfolge, null für Lücken.
	 * @param array $x     X-Koordinaten.
	 * @param float $max   Obergrenze.
	 * @return string Pfaddaten, leer wenn nichts zu zeichnen ist.
	 */
	public static function path( array $werte, array $x, $max ) {
		$teile  = array();
		$offen  = false;
		$werte  = array_values( $werte );

		foreach ( $werte as $i => $wert ) {
			$y = self::y( $wert, $max );

			if ( null === $y || ! isset( $x[ $i ] ) ) {
				$offen = false;
				continue;
			}

			$teile[] = sprintf( '%s %s %s', $offen ? 'L' : 'M', self::num( $x[ $i ] ), self::num( $y ) );
			$offen   = true;
		}

		// Ein einzelner Punkt ergibt keinen Pfad – er wird als Marke
		// gezeichnet, nicht als Linie der Länge null.
		$striche = 0;

		foreach ( $teile as $teil ) {
			if ( 0 === strpos( $teil, 'L' ) ) {
				$striche++;
			}
		}

		return $striche > 0 ? implode( ' ', $teile ) : '';
	}

	/**
	 * Wie viele Beschriftungen die Zeitachse überspringt.
	 *
	 * Vierzig Wochen nebeneinander ergeben vierzig Beschriftungen, die
	 * übereinanderliegen. Sechs sind lesbar – acht waren es nicht: „KW
	 * 21/2026" ist rund siebzig Punkte breit, und die beiden am Rand
	 * ausgerichteten Marken rücken zusätzlich eine halbe Breite nach innen.
	 *
	 * @param int $count Anzahl Punkte.
	 * @return int
	 */
	public static function label_stride( $count ) {
		$count = max( 1, (int) $count );

		return (int) max( 1, ceil( $count / 6 ) );
	}

	/**
	 * Zahl für den SVG-Quelltext.
	 *
	 * Immer mit Punkt: Das ist Auszeichnung, keine Anzeige für Menschen –
	 * ein Komma je nach Spracheinstellung ergäbe kaputte Koordinaten.
	 *
	 * @param float $zahl Zahl.
	 * @return string
	 */
	public static function num( $zahl ) {
		return number_format( (float) $zahl, 2, '.', '' );
	}

	/**
	 * Der Verlauf als SVG.
	 *
	 * @param array $args {
	 *     @type array  $points  Liste aus {label, value, target, alarm, text}.
	 *     @type string $title   Beschriftung der Reihe.
	 *     @type string $unit    Einheit für die Achse.
	 *     @type string $id      Kennung für die Beschriftung.
	 * }
	 * @return string
	 */
	public static function svg( array $args ) {
		$points = isset( $args['points'] ) ? array_values( (array) $args['points'] ) : array();
		$titel  = isset( $args['title'] ) ? (string) $args['title'] : '';
		$id     = isset( $args['id'] ) ? (string) $args['id'] : 'lw16-verlauf';

		$hat_wert = false;

		foreach ( $points as $point ) {
			if ( isset( $point['value'] ) && null !== $point['value'] ) {
				$hat_wert = true;
				break;
			}
		}

		if ( ! $points || ! $hat_wert ) {
			return '<p class="lw16-verlauf__leer">' . esc_html__( 'Für diesen Zeitraum gibt es keine Werte.', 'lindenweg16' ) . '</p>';
		}

		$skala  = self::scale( $points );
		$max    = $skala['max'];
		$x      = self::positions( count( $points ), $max );
		$farben = self::colors();

		$werte  = self::column( $points, 'value' );
		$ziele  = self::column( $points, 'target' );
		$alarme = self::column( $points, 'alarm' );

		$out  = sprintf(
			'<svg class="lw16-verlauf__bild" viewBox="0 0 %d %d" role="img" aria-labelledby="%s-titel" preserveAspectRatio="xMidYMid meet">',
			self::WIDTH,
			self::HEIGHT,
			esc_attr( $id )
		);
		$out .= sprintf( '<title id="%s-titel">%s</title>', esc_attr( $id ), esc_html( $titel ) );

		$out .= self::grid( $skala, $args );
		$out .= self::reference_line( $alarme, $x, $max, $farben['alarm'] );
		$out .= self::reference_line( $ziele, $x, $max, $farben['ziel'] );

		$pfad = self::path( $werte, $x, $max );

		if ( '' !== $pfad ) {
			$out .= sprintf(
				'<path d="%s" fill="none" stroke="%s" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />',
				esc_attr( $pfad ),
				esc_attr( $farben['linie'] )
			);
		}

		$out .= self::markers( $points, $x, $max, $farben );
		$out .= self::time_axis( $points, $x );
		$out .= '</svg>';

		return $out;
	}

	/**
	 * Eine Spalte aus den Punkten, Lücken als null.
	 *
	 * @param array  $points Punkte.
	 * @param string $feld   Feld.
	 * @return array
	 */
	public static function column( array $points, $feld ) {
		$out = array();

		foreach ( $points as $point ) {
			$out[] = isset( $point[ $feld ] ) ? $point[ $feld ] : null;
		}

		return $out;
	}

	/**
	 * Waagerechtes Raster mit Achsenbeschriftung.
	 *
	 * @param array $skala Skala.
	 * @param array $args  Argumente der Ausgabe.
	 * @return string
	 */
	protected static function grid( array $skala, array $args ) {
		$farben = self::colors();
		$metric = isset( $args['metric'] ) ? (string) $args['metric'] : '';
		$out    = '';

		foreach ( $skala['ticks'] as $tick ) {
			$y = self::y( $tick, $skala['max'] );

			if ( null === $y ) {
				continue;
			}

			$out .= sprintf(
				'<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="1" />',
				self::num( self::PAD_L ),
				self::num( $y ),
				self::num( self::WIDTH - self::PAD_R ),
				self::num( $y ),
				esc_attr( $farben['raster'] )
			);

			$out .= sprintf(
				'<text x="%s" y="%s" text-anchor="end" font-size="11" fill="%s">%s</text>',
				self::num( self::PAD_L - 8 ),
				self::num( $y + 4 ),
				esc_attr( $farben['achse'] ),
				esc_html( $metric ? Kpi::format( $metric, $tick ) : number_format_i18n( $tick, 0 ) )
			);
		}

		return $out;
	}

	/**
	 * Ziel- oder Alarmlinie – gestrichelt, damit sie nicht als Messreihe
	 * gelesen wird.
	 *
	 * @param array  $werte Werte.
	 * @param array  $x     X-Koordinaten.
	 * @param float  $max   Obergrenze.
	 * @param string $farbe Farbe.
	 * @return string
	 */
	protected static function reference_line( array $werte, array $x, $max, $farbe ) {
		$pfad = self::path( $werte, $x, $max );

		if ( '' === $pfad ) {
			return '';
		}

		return sprintf(
			'<path d="%s" fill="none" stroke="%s" stroke-width="2" stroke-dasharray="6 4" stroke-linecap="round" opacity="0.85" />',
			esc_attr( $pfad ),
			esc_attr( $farbe )
		);
	}

	/**
	 * Punktmarken mit Kurzinfo.
	 *
	 * Bei vielen Punkten entfallen die Marken – die Linie trägt den Verlauf,
	 * und vierzig Kreise nebeneinander ergeben eine Perlenkette. Die
	 * Kurzinfo bleibt trotzdem: Sie hängt an einem unsichtbaren Feld über
	 * dem Punkt, das breit genug zum Treffen ist.
	 *
	 * @param array $points Punkte.
	 * @param array $x      X-Koordinaten.
	 * @param float $max    Obergrenze.
	 * @param array $farben Farben.
	 * @return string
	 */
	protected static function markers( array $points, array $x, $max, array $farben ) {
		$out     = '';
		$sichtbar = count( $points ) <= 20;
		$breite  = count( $x ) > 1 ? ( $x[1] - $x[0] ) : ( self::WIDTH - self::PAD_L - self::PAD_R );

		foreach ( $points as $i => $point ) {
			if ( ! isset( $x[ $i ] ) ) {
				continue;
			}

			$y = self::y( isset( $point['value'] ) ? $point['value'] : null, $max );

			$out .= '<g>';
			$out .= '<title>' . esc_html( isset( $point['text'] ) ? $point['text'] : '' ) . '</title>';

			// Das Trefferfeld liegt über die volle Spaltenbreite, damit die
			// Kurzinfo auch neben dem Punkt erscheint.
			$out .= sprintf(
				'<rect x="%s" y="%s" width="%s" height="%s" fill="transparent" />',
				self::num( $x[ $i ] - $breite / 2 ),
				self::num( self::PAD_T ),
				self::num( max( 6, $breite ) ),
				self::num( self::HEIGHT - self::PAD_T - self::PAD_B )
			);

			if ( null !== $y && $sichtbar ) {
				$out .= sprintf(
					'<circle cx="%s" cy="%s" r="4" fill="%s" stroke="%s" stroke-width="2" />',
					self::num( $x[ $i ] ),
					self::num( $y ),
					esc_attr( $farben['linie'] ),
					esc_attr( $farben['grund'] )
				);
			}

			$out .= '</g>';
		}

		return $out;
	}

	/**
	 * Zeitachse.
	 *
	 * @param array $points Punkte.
	 * @param array $x      X-Koordinaten.
	 * @return string
	 */
	protected static function time_axis( array $points, array $x ) {
		$farben = self::colors();
		$stride = self::label_stride( count( $points ) );
		$letzte = count( $points ) - 1;
		$out    = '';

		foreach ( $points as $i => $point ) {
			// Immer den letzten beschriften: Der Leser sucht zuerst, wo der
			// Verlauf endet.
			if ( 0 !== ( $letzte - $i ) % $stride ) {
				continue;
			}
			if ( ! isset( $x[ $i ] ) ) {
				continue;
			}

			// Am Rand wird die Marke gegen den Rand ausgerichtet: Mittig
			// gesetzt ragte die Hälfte des letzten Datums aus dem Bild.
			$anker = 'middle';

			if ( 0 === $i ) {
				$anker = 'start';
			} elseif ( $letzte === $i ) {
				$anker = 'end';
			}

			$out .= sprintf(
				'<text x="%s" y="%s" text-anchor="%s" font-size="11" fill="%s">%s</text>',
				self::num( $x[ $i ] ),
				self::num( self::HEIGHT - self::PAD_B + 18 ),
				esc_attr( $anker ),
				esc_attr( $farben['achse'] ),
				esc_html( isset( $point['label'] ) ? $point['label'] : '' )
			);
		}

		return $out;
	}

	/**
	 * Legende – ohne sie sind zwei gestrichelte Linien nur zwei Striche.
	 *
	 * @return string
	 */
	public static function legend() {
		$farben = self::colors();

		$eintrag = static function ( $farbe, $text, $gestrichelt ) {
			return sprintf(
				'<span class="lw16-verlauf__schluessel"><span class="lw16-verlauf__strich%s" style="color:%s"></span>%s</span>',
				$gestrichelt ? ' lw16-verlauf__strich--gestrichelt' : '',
				esc_attr( $farbe ),
				esc_html( $text )
			);
		};

		return '<p class="lw16-verlauf__legende">'
			. $eintrag( $farben['linie'], __( 'Istwert', 'lindenweg16' ), false )
			. $eintrag( $farben['ziel'], __( 'Ziel', 'lindenweg16' ), true )
			. $eintrag( $farben['alarm'], __( 'Alarmgrenze', 'lindenweg16' ), true )
			. '</p>';
	}
}
