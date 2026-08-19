<?php
/**
 * Freigabelogik für Artikelrevisionen.
 *
 * Eine Revision darf nur aktiv gesetzt werden, wenn dazu ein freigegebener
 * Zugversuch existiert: mindestens fünf Proben und ein Sicherheitsfaktor von
 * mindestens 3,0 gegenüber der angegebenen Traglast. Das ist eine harte
 * Validierung, keine Warnung – an dem Haken hängt ein Fahrrad über einem Kopf.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Bewertet Zugversuche und entscheidet über die Freigabe.
 */
class ReleaseGate {

	/**
	 * Erdbeschleunigung für die Umrechnung Newton in Kilogramm.
	 */
	const G = 9.81;

	/**
	 * Mindestzahl der Proben.
	 */
	const MIN_SAMPLES = 5;

	/**
	 * Mindestsicherheitsfaktor.
	 */
	const MIN_SAFETY_FACTOR = 3.0;

	/**
	 * Zugversuche einer Revision auswerten.
	 *
	 * Maßgeblich ist die niedrigste Bruchlast, nicht der Mittelwert: Das
	 * Bauteil versagt an seiner schwächsten Probe, nicht an der mittleren.
	 *
	 * @param array $tests           Zugversuche mit break_load_n.
	 * @param float $load_rating_kg  Angegebene Traglast des Artikels.
	 * @return array
	 */
	public static function evaluate( array $tests, $load_rating_kg ) {
		$loads = array();
		foreach ( $tests as $test ) {
			$test = (array) $test;
			if ( ! empty( $test['voided_at'] ) ) {
				continue;
			}
			$n = isset( $test['break_load_n'] ) ? (float) $test['break_load_n'] : 0.0;
			if ( $n > 0 ) {
				$loads[] = $n;
			}
		}

		$count          = count( $loads );
		$min_n          = $count ? min( $loads ) : 0.0;
		$avg_n          = $count ? array_sum( $loads ) / $count : 0.0;
		$kg_min         = $min_n / self::G;
		$load_rating_kg = (float) $load_rating_kg;
		$factor         = $load_rating_kg > 0 ? $kg_min / $load_rating_kg : 0.0;

		$reasons = array();

		if ( $count < self::MIN_SAMPLES ) {
			$reasons[] = sprintf(
				/* translators: 1: vorhandene Proben, 2: geforderte Proben. */
				__( 'Nur %1$d von %2$d geforderten Proben erfasst.', 'lindenweg16' ),
				$count,
				self::MIN_SAMPLES
			);
		}

		if ( $load_rating_kg <= 0 ) {
			$reasons[] = __( 'Am Artikel ist keine Traglast angegeben – ohne sie lässt sich kein Sicherheitsfaktor bilden.', 'lindenweg16' );
		} elseif ( $factor < self::MIN_SAFETY_FACTOR ) {
			$reasons[] = sprintf(
				/* translators: 1: erreichter Faktor, 2: geforderter Faktor, 3: niedrigste Bruchlast in kg, 4: angegebene Traglast. */
				__( 'Sicherheitsfaktor %1$s liegt unter den geforderten %2$s (niedrigste Bruchlast %3$s kg gegen %4$s kg Traglast).', 'lindenweg16' ),
				number_format_i18n( $factor, 2 ),
				number_format_i18n( self::MIN_SAFETY_FACTOR, 1 ),
				number_format_i18n( $kg_min, 1 ),
				number_format_i18n( $load_rating_kg, 1 )
			);
		}

		return array(
			'sample_count'      => $count,
			'break_load_min_n'  => $min_n,
			'break_load_avg_n'  => $avg_n,
			'kg_min'            => $kg_min,
			'kg_avg'            => $avg_n / self::G,
			'safety_factor'     => $factor,
			'load_rating_kg'    => $load_rating_kg,
			'released'          => empty( $reasons ),
			'reasons'           => $reasons,
		);
	}

	/**
	 * Darf diese Revision aktiv gesetzt werden?
	 *
	 * @param array $tests          Zugversuche.
	 * @param float $load_rating_kg Traglast.
	 * @return bool
	 */
	public static function may_activate( array $tests, $load_rating_kg ) {
		$result = self::evaluate( $tests, $load_rating_kg );
		return $result['released'];
	}
}
