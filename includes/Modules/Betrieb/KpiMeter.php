<?php
/**
 * Kennzahl als Messbalken mit Ziel- und Alarmmarke.
 *
 * Eine Kennzahl gegen eine Grenze ist kein Balkendiagramm – es gibt nur
 * einen Wert. Was der Leser braucht, ist die Lage dieses Werts zwischen
 * zwei Marken: Wo will ich hin, ab wo wird es eng.
 *
 * Rechnen und Zeichnen sind hier getrennt. scale() ist reine Mathematik
 * und geprüft; svg() setzt nur um, was scale() ausgerechnet hat.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Skalenrechnung und SVG-Ausgabe.
 */
class KpiMeter {

	/**
	 * Luft rechts neben dem größten Wert, damit eine Marke am Rand nicht
	 * mit dem Rahmen verschmilzt.
	 */
	const HEADROOM = 1.15;

	/**
	 * Farben je Bewertungsstufe.
	 *
	 * Aus der Statuspalette, nicht aus den Diagrammfarben: Status ist
	 * reserviert und wird nie als „noch eine Serie" verwendet. Die
	 * Beschriftung daneben trägt die Bedeutung mit – Farbe allein sagt
	 * hier nichts.
	 *
	 * @return array
	 */
	public static function colors() {
		return array(
			'gruen'   => '#0ca30c',
			'gelb'    => '#fab219',
			'rot'     => '#d03b3b',
			'neutral' => '#8a959a',
		);
	}

	/**
	 * Lage von Wert, Ziel und Alarm auf einer gemeinsamen Skala.
	 *
	 * Reine Rechnung. Die Skala beginnt bei 0 und endet über dem größten
	 * beteiligten Wert – so bleiben Wert und Marken immer im Bild, auch
	 * wenn der Wert das Ziel weit überschreitet.
	 *
	 * @param float|null $value  Istwert.
	 * @param float|null $target Zielwert.
	 * @param float|null $alarm  Alarmgrenze.
	 * @return array {max, value, target, alarm} – Anteile 0..1, null wenn nicht gesetzt.
	 */
	public static function scale( $value, $target, $alarm ) {
		$werte = array();

		foreach ( array( $value, $target, $alarm ) as $kandidat ) {
			if ( null !== $kandidat && is_numeric( $kandidat ) ) {
				$werte[] = max( 0.0, (float) $kandidat );
			}
		}

		// Ohne jeden Wert gibt es nichts zu zeichnen; eine Skala von 0 bis 0
		// wuerde beim Teilen zum Fehler.
		$max = $werte ? max( $werte ) * self::HEADROOM : 0.0;

		if ( $max <= 0 ) {
			return array(
				'max'    => 0.0,
				'value'  => null,
				'target' => null,
				'alarm'  => null,
			);
		}

		$anteil = static function ( $zahl ) use ( $max ) {
			if ( null === $zahl || ! is_numeric( $zahl ) ) {
				return null;
			}

			return min( 1.0, max( 0.0, (float) $zahl ) / $max );
		};

		return array(
			'max'    => $max,
			'value'  => $anteil( $value ),
			'target' => $anteil( $target ),
			'alarm'  => $anteil( $alarm ),
		);
	}

	/**
	 * Messbalken als Auszeichnung.
	 *
	 * Bewusst kein SVG: Ein Balken mit zwei Marken braucht keine Kurven,
	 * und eine feste viewBox skaliert entweder verzerrt oder gar nicht –
	 * in einer Kachel unbekannter Breite ist das der falsche Handel.
	 * Prozentbreiten treffen die Kachel immer.
	 *
	 * @param array $args {level, value, target, alarm, aria}.
	 * @return string Leer, wenn es nichts zu zeigen gibt.
	 */
	public static function bar( array $args ) {
		$value  = isset( $args['value'] ) ? $args['value'] : null;
		$target = isset( $args['target'] ) ? $args['target'] : null;
		$alarm  = isset( $args['alarm'] ) ? $args['alarm'] : null;

		// Ohne Ziel und Alarm gibt es keine Skala, gegen die ein Balken
		// etwas aussagen wuerde. Ein Balken bei 87 Prozent taeuschte einen
		// Massstab vor, den niemand gesetzt hat – dann sagt die Zahl allein
		// mehr.
		if ( null === $target && null === $alarm ) {
			return '';
		}

		$lage = self::scale( $value, $target, $alarm );

		if ( $lage['max'] <= 0 ) {
			return '';
		}

		$level  = isset( $args['level'] ) ? (string) $args['level'] : 'neutral';
		$stufen = array( 'gruen', 'gelb', 'rot', 'neutral' );

		if ( ! in_array( $level, $stufen, true ) ) {
			$level = 'neutral';
		}

		$out = '<div class="lw16-meter" role="img" aria-label="' . esc_attr( isset( $args['aria'] ) ? $args['aria'] : '' ) . '">';
		$out .= '<div class="lw16-meter__track">';

		if ( null !== $lage['value'] ) {
			$out .= sprintf(
				'<div class="lw16-meter__value lw16-meter__value--%s" style="width:%s%%"></div>',
				esc_attr( $level ),
				esc_attr( self::pct( $lage['value'] ) )
			);
		}

		// Alarm zuerst, damit die Zielmarke oben liegt, wenn beide dicht
		// beieinanderstehen – das Ziel ist die wichtigere Marke.
		if ( null !== $lage['alarm'] ) {
			$out .= sprintf(
				'<div class="lw16-meter__mark lw16-meter__mark--alarm" style="left:%s%%"></div>',
				esc_attr( self::pct( $lage['alarm'] ) )
			);
		}

		if ( null !== $lage['target'] ) {
			$out .= sprintf(
				'<div class="lw16-meter__mark lw16-meter__mark--ziel" style="left:%s%%"></div>',
				esc_attr( self::pct( $lage['target'] ) )
			);
		}

		$out .= '</div></div>';

		return $out;
	}

	/**
	 * Anteil als Prozentzahl mit zwei Nachkommastellen.
	 *
	 * Immer mit Punkt: Das ist CSS, keine Anzeige für Menschen – ein
	 * Komma je nach Spracheinstellung ergäbe eine kaputte Breitenangabe.
	 *
	 * @param float $anteil 0..1.
	 * @return string
	 */
	public static function pct( $anteil ) {
		return number_format( min( 1.0, max( 0.0, (float) $anteil ) ) * 100, 2, '.', '' );
	}

	/**
	 * Legende unter den Kacheln – einmal, nicht je Balken.
	 *
	 * @return string
	 */
	public static function legend() {
		return '<p class="lw16-meter__legend">'
			. '<span class="lw16-meter__key lw16-meter__key--ziel"></span> ' . esc_html__( 'Ziel', 'lindenweg16' )
			. ' <span class="lw16-meter__key lw16-meter__key--alarm"></span> ' . esc_html__( 'Alarmgrenze', 'lindenweg16' )
			. '</p>';
	}
}
