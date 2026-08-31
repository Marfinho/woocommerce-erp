<?php
/**
 * Wiederkaufsrate: Anteil der Kunden, die mehr als einmal gekauft haben.
 *
 * Eigene Klasse, weil die Kennzahl im Verlaufsgraphen zur Falle wird. Sie
 * braucht ein Rückblickfenster – wer nur die Bestellungen eines einzelnen
 * Tages ansieht, findet dort naturgemäss keinen Kunden, der zweimal gekauft
 * hat, und meldet null Prozent. Die Rate wird deshalb immer über die letzten
 * 365 Tage **vor dem Stichtag** gerechnet: Der Wert ändert sich über die
 * Zeit, ohne vom gewählten Zeitraster abzuhängen.
 *
 * Vorher stand hier eine Rate, die stur die letzten 365 Tage ab *heute*
 * nahm. Im Verlauf ergab das eine waagerechte Linie durch die gesamte
 * Vergangenheit – der heutige Wert, zwölfmal hingemalt, als wäre er
 * Geschichte.
 *
 * Frei von Datenbank und WordPress-Umgebung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Rechnung der Wiederkaufsrate.
 */
class RepeatRate {

	/**
	 * Rückblick in Tagen.
	 */
	const WINDOW_DAYS = 365;

	/**
	 * Beginn des Rückblickfensters zu einem Stichtag.
	 *
	 * @param string $bis  Stichtag (Y-m-d).
	 * @param int    $days Fensterbreite.
	 * @return string
	 */
	public static function window_start( $bis, $days = self::WINDOW_DAYS ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $bis ) ) {
			return '';
		}

		$zeit = strtotime( (string) $bis . ' 12:00:00 UTC' );

		if ( ! $zeit ) {
			return '';
		}

		return gmdate( 'Y-m-d', $zeit - max( 0, (int) $days ) * DAY_IN_SECONDS );
	}

	/**
	 * Anteil der wiederkaufenden Kunden in Prozent.
	 *
	 * @param array  $orders Liste aus {customer, date}.
	 * @param string $von    Fensterbeginn (Y-m-d).
	 * @param string $bis    Stichtag (Y-m-d).
	 * @return float|null Null, wenn im Fenster niemand gekauft hat – null
	 *                    heisst „nicht erfasst", nicht „null Prozent".
	 */
	public static function share( array $orders, $von, $bis ) {
		$je_kunde = array();

		foreach ( $orders as $order ) {
			$order  = (array) $order;
			$kunde  = isset( $order['customer'] ) ? trim( (string) $order['customer'] ) : '';
			$datum  = isset( $order['date'] ) ? (string) $order['date'] : '';

			// Ohne Kennung lässt sich nicht sagen, ob es derselbe Kunde war.
			// Solche Bestellungen zählen gar nicht mit, statt jede für sich
			// als Neukunde zu gelten und die Rate zu drücken.
			if ( '' === $kunde || '' === $datum ) {
				continue;
			}

			if ( $datum < $von || $datum > $bis ) {
				continue;
			}

			$je_kunde[ $kunde ] = isset( $je_kunde[ $kunde ] ) ? $je_kunde[ $kunde ] + 1 : 1;
		}

		if ( ! $je_kunde ) {
			return null;
		}

		$wieder = 0;

		foreach ( $je_kunde as $anzahl ) {
			if ( $anzahl >= 2 ) {
				$wieder++;
			}
		}

		return $wieder / count( $je_kunde ) * 100;
	}

	/**
	 * Die Rate zu einem Stichtag, mit dem üblichen Rückblick.
	 *
	 * @param array  $orders Liste aus {customer, date}.
	 * @param string $bis    Stichtag.
	 * @param int    $days   Fensterbreite.
	 * @return float|null
	 */
	public static function at( array $orders, $bis, $days = self::WINDOW_DAYS ) {
		$von = self::window_start( $bis, $days );

		if ( '' === $von ) {
			return null;
		}

		return self::share( $orders, $von, $bis );
	}
}
