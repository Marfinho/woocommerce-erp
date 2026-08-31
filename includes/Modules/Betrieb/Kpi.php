<?php
/**
 * Kennzahlen mit Zielwerten und Alarmschwellen.
 *
 * Die Bewertung ist reine Logik und damit prüfbar; die Beschaffung der Werte
 * liegt im Reporting-Service.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Definition und Bewertung der Steuerungskennzahlen.
 */
class Kpi {

	/**
	 * Richtung der Bewertung.
	 */
	const HIGHER_IS_BETTER = 'hoch';
	const LOWER_IS_BETTER  = 'niedrig';

	/**
	 * Art der Kennzahl.
	 *
	 * Sie entscheidet, wie ein Quartalsziel auf kürzere Zeiträume kommt:
	 * Eine Summe wird geteilt, ein Verhältnis nicht. Siehe KpiGoals.
	 */
	const SUM   = 'summe';
	const RATIO = 'verhaeltnis';

	/**
	 * Definition aller Kennzahlen.
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			'umsatz'                 => array(
				'label'     => __( 'Umsatz', 'lindenweg16' ),
				'unit'      => '€',
				'kind'      => self::SUM,
				'direction' => self::HIGHER_IS_BETTER,
				'target'    => null,
				'alarm'     => null,
			),
			'bestellungen'           => array(
				'label'     => __( 'Bestellungen', 'lindenweg16' ),
				'unit'      => '',
				'kind'      => self::SUM,
				'direction' => self::HIGHER_IS_BETTER,
				'target'    => null,
				'alarm'     => null,
			),
			'warenkorb'              => array(
				'label'     => __( 'Ø-Warenkorb', 'lindenweg16' ),
				'unit'      => '€',
				'kind'      => self::RATIO,
				'direction' => self::HIGHER_IS_BETTER,
				'target'    => 55.0,
				'alarm'     => 45.0,
			),
			'handlingzeit'           => array(
				'label'     => __( 'Ø Handlingzeit', 'lindenweg16' ),
				'unit'      => 'min',
				'kind'      => self::RATIO,
				'direction' => self::LOWER_IS_BETTER,
				'target'    => 9.0,
				'alarm'     => 12.0,
			),
			'b2b_anteil'             => array(
				'label'     => __( 'B2B-Anteil', 'lindenweg16' ),
				'unit'      => '%',
				'kind'      => self::RATIO,
				'direction' => self::HIGHER_IS_BETTER,
				'target'    => 15.0,
				'alarm'     => null,
			),
			'reklamationsquote'      => array(
				'label'     => __( 'Reklamationsquote', 'lindenweg16' ),
				'unit'      => '%',
				'kind'      => self::RATIO,
				'direction' => self::LOWER_IS_BETTER,
				'target'    => 4.0,
				'alarm'     => 7.0,
			),
			'ausschussquote'         => array(
				'label'     => __( 'Ausschussquote', 'lindenweg16' ),
				'unit'      => '%',
				'kind'      => self::RATIO,
				'direction' => self::LOWER_IS_BETTER,
				'target'    => 6.0,
				'alarm'     => 10.0,
			),
			'teile_je_plattenwechsel' => array(
				'label'     => __( 'Teile je Plattenwechsel', 'lindenweg16' ),
				'unit'      => '',
				'kind'      => self::RATIO,
				'direction' => self::HIGHER_IS_BETTER,
				'target'    => 4.0,
				'alarm'     => 3.0,
			),
			'auslastung'             => array(
				'label'     => __( 'Auslastung Druckfarm', 'lindenweg16' ),
				'unit'      => '%',
				'kind'      => self::RATIO,
				'direction' => self::HIGHER_IS_BETTER,
				'target'    => 70.0,
				'alarm'     => 90.0,
				'alarm_high' => true,
			),
			'bedienzeit_anteil'      => array(
				'label'     => __( 'Bedienzeit am Arbeitsanteil', 'lindenweg16' ),
				'unit'      => '%',
				'kind'      => self::RATIO,
				'direction' => self::LOWER_IS_BETTER,
				'target'    => null,
				'alarm'     => 25.0,
			),
			'wochenstunden'          => array(
				'label'     => __( 'Arbeitsstunden je Woche', 'lindenweg16' ),
				'unit'      => 'h',
				'kind'      => self::RATIO,
				'direction' => self::LOWER_IS_BETTER,
				'target'    => 14.0,
				'alarm'     => 25.0,
			),
			'wiederkaufsrate'        => array(
				'label'     => __( 'Wiederkaufsrate', 'lindenweg16' ),
				'unit'      => '%',
				'kind'      => self::RATIO,
				'direction' => self::HIGHER_IS_BETTER,
				'target'    => 15.0,
				'alarm'     => 8.0,
			),
		);
	}

	/**
	 * Manuelle Kennzahlen, die nicht aus den Daten kommen.
	 *
	 * @return array
	 */
	public static function manual_metrics() {
		return array(
			'kosten_je_neukunde' => __( 'Kosten je Neukunde (€)', 'lindenweg16' ),
			'roas'               => __( 'ROAS bezahlte Werbung', 'lindenweg16' ),
			'organischer_traffic' => __( 'Organischer Traffic', 'lindenweg16' ),
			'db_ist'             => __( 'Deckungsbeitrag Ist (€)', 'lindenweg16' ),
		);
	}

	/**
	 * Eine Kennzahl bewerten – reine Logik.
	 *
	 * @param string     $key   Kennzahl.
	 * @param float|null $value Wert.
	 * @return array {level, label, reached}
	 */
	public static function evaluate( $key, $value ) {
		$definitions = self::definitions();

		if ( ! isset( $definitions[ $key ] ) ) {
			return array(
				'level'   => 'neutral',
				'reached' => null,
			);
		}

		return self::evaluate_against( $key, $value, $definitions[ $key ]['target'], $definitions[ $key ]['alarm'] );
	}

	/**
	 * Eine Kennzahl gegen gesetzte Grenzen bewerten.
	 *
	 * Getrennt von evaluate(), weil die Grenzen nicht mehr nur aus der
	 * Definition kommen: Für einen Zeitraum gelten die aus dem Quartalsziel
	 * abgeleiteten Werte, und die kennt nur der Aufrufer.
	 *
	 * @param string     $key    Kennzahl.
	 * @param float|null $value  Wert.
	 * @param float|null $target Zielwert.
	 * @param float|null $alarm  Alarmgrenze.
	 * @return array {level, reached}
	 */
	public static function evaluate_against( $key, $value, $target, $alarm ) {
		$definitions = self::definitions();

		if ( ! isset( $definitions[ $key ] ) || null === $value ) {
			return array(
				'level'   => 'neutral',
				'reached' => null,
			);
		}

		$definition = $definitions[ $key ];
		$value      = (float) $value;

		// Die Auslastung ist der Sonderfall: Sie soll hoch sein, aber der
		// Alarm greift bei zu hoher Auslastung, nicht bei zu niedriger.
		if ( ! empty( $definition['alarm_high'] ) ) {
			if ( null !== $alarm && $value > $alarm ) {
				return array( 'level' => 'rot', 'reached' => false );
			}
			if ( null !== $target && $value >= $target ) {
				return array( 'level' => 'gruen', 'reached' => true );
			}
			return array( 'level' => 'gelb', 'reached' => false );
		}

		if ( self::LOWER_IS_BETTER === $definition['direction'] ) {
			if ( null !== $alarm && $value > $alarm ) {
				return array( 'level' => 'rot', 'reached' => false );
			}
			if ( null !== $target && $value <= $target ) {
				return array( 'level' => 'gruen', 'reached' => true );
			}
			return array( 'level' => 'gelb', 'reached' => false );
		}

		if ( null !== $alarm && $value < $alarm ) {
			return array( 'level' => 'rot', 'reached' => false );
		}
		if ( null !== $target && $value >= $target ) {
			return array( 'level' => 'gruen', 'reached' => true );
		}

		return array( 'level' => null === $target ? 'neutral' : 'gelb', 'reached' => false );
	}

	/**
	 * Wert formatieren.
	 *
	 * @param string     $key   Kennzahl.
	 * @param float|null $value Wert.
	 * @return string
	 */
	public static function format( $key, $value ) {
		$definitions = self::definitions();

		if ( null === $value ) {
			return '—';
		}

		$unit     = isset( $definitions[ $key ] ) ? $definitions[ $key ]['unit'] : '';
		$decimals = ( '' === $unit || 'min' === $unit ) ? 1 : ( '%' === $unit ? 1 : 2 );

		return number_format_i18n( (float) $value, $decimals ) . ( $unit ? ' ' . $unit : '' );
	}
}
