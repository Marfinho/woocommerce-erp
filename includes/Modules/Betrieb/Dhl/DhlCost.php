<?php
/**
 * Porto je Label: Preise aus dem DHL-Vertrag und Zuschläge – reine Rechnung.
 *
 * Die DHL nennt beim Erzeugen eines Labels keinen Preis; er steht erst auf der
 * Monatsrechnung. Dieses Modul rechnet ihn aus den vertraglichen Preisen nach
 * (netto, ohne Mehrwertsteuer). Das Ergebnis ist eine Schätzung: Zuschläge, die
 * die DHL prozentual oder nach Zeitraum festlegt, trägt man in den Einstellungen ein.
 *
 * Voreinstellung: Anlage 1 und Länderpreisliste P01 (Vertrag vom 09. und
 * 22.11.2026, EKP 6394652951). Die Preise sind einstellbar, weil sie sich ändern.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Preise und Porto-Rechnung.
 */
class DhlCost {

	/**
	 * Einstellungsschlüssel der Preise.
	 */
	const SETTING = 'dhl_preise';

	/**
	 * Höchstgewicht der Preisstufen in kg.
	 */
	const MAX_KG = 31.5;

	/**
	 * Länder je Zone des Europakets (Länderpreisliste P01).
	 *
	 * @return array Zone => Ländercodes.
	 */
	public static function zones() {
		return array(
			1 => array( 'BE', 'DK', 'FR', 'LI', 'LU', 'MC', 'NL', 'AT', 'PL', 'CH', 'CZ' ),
			2 => array( 'GB', 'IT', 'SM', 'SE', 'SK', 'SI', 'ES', 'HU', 'VA' ),
			3 => array( 'BG', 'EE', 'FI', 'GR', 'IE', 'HR', 'LV', 'LT', 'NO', 'PT', 'RO' ),
		);
	}

	/**
	 * Zone eines Landes.
	 *
	 * @param string $country Ländercode.
	 * @return int 0, wenn das Land nicht im Vertrag steht.
	 */
	public static function zone_of( $country ) {
		$country = strtoupper( trim( (string) $country ) );

		foreach ( self::zones() as $zone => $codes ) {
			if ( in_array( $country, $codes, true ) ) {
				return $zone;
			}
		}

		return 0;
	}

	/**
	 * Voreingestellte Preise aus dem Vertrag.
	 *
	 * @return array
	 */
	public static function defaults() {
		$euro1 = array( array( 1, 14.20 ), array( 2, 15.30 ), array( 3, 16.20 ), array( 4, 17.10 ), array( 5, 18.10 ), array( 6, 19.00 ), array( 7, 20.00 ), array( 8, 21.00 ), array( 9, 22.00 ), array( 10, 22.90 ), array( 12, 24.70 ), array( 14, 26.70 ), array( 16, 28.70 ), array( 18, 30.60 ), array( 20, 32.30 ), array( 22, 34.30 ), array( 24, 36.20 ), array( 26, 38.10 ), array( 28, 40.10 ), array( 30, 42.00 ), array( 31.5, 43.30 ) );
		$euro2 = array( array( 1, 14.40 ), array( 2, 15.70 ), array( 3, 16.60 ), array( 4, 17.80 ), array( 5, 18.80 ), array( 6, 19.90 ), array( 7, 20.90 ), array( 8, 21.90 ), array( 9, 22.90 ), array( 10, 23.90 ), array( 12, 25.90 ), array( 14, 27.90 ), array( 16, 29.90 ), array( 18, 31.90 ), array( 20, 33.90 ), array( 22, 35.90 ), array( 24, 37.90 ), array( 26, 39.90 ), array( 28, 41.90 ), array( 30, 43.90 ), array( 31.5, 45.40 ) );
		$euro3 = array( array( 1, 14.70 ), array( 2, 16.00 ), array( 3, 17.10 ), array( 4, 18.30 ), array( 5, 19.40 ), array( 6, 20.50 ), array( 7, 21.70 ), array( 8, 22.80 ), array( 9, 23.90 ), array( 10, 25.00 ), array( 12, 27.20 ), array( 14, 29.40 ), array( 16, 31.60 ), array( 18, 33.80 ), array( 20, 36.00 ), array( 22, 38.20 ), array( 24, 40.40 ), array( 26, 42.60 ), array( 28, 44.80 ), array( 30, 47.00 ), array( 31.5, 48.65 ) );

		return array(
			'paket'      => array( array( 1, 5.00 ), array( 3, 5.80 ), array( 5, 6.95 ), array( 10, 9.20 ), array( 20, 14.35 ), array( 31.5, 17.25 ) ),
			'euro'       => array( 1 => $euro1, 2 => $euro2, 3 => $euro3 ),
			'sperrgut'   => array( 'paket' => 21.00, 'euro' => 29.00 ),
			'zuschlaege' => array(
				'maut'         => 0.19,
				'peak'         => 0.19,
				'peak_monate'  => '11,12',
				'pip'          => 0.50,
				'pip_von'      => '',
				'pip_bis'      => '',
				'energie_prozent' => 0.0,
			),
		);
	}

	/**
	 * Die gültigen Preise: gespeichert oder voreingestellt.
	 *
	 * @return array
	 */
	public static function config() {
		$saved = Settings::get( self::SETTING, null );

		return is_array( $saved ) ? self::sanitize( $saved ) : self::defaults();
	}

	/**
	 * Preise speichern.
	 *
	 * @param array $raw Rohe Eingabe.
	 * @return array Die gespeicherten, bereinigten Preise.
	 */
	public static function save( array $raw ) {
		$cfg = self::sanitize( $raw );

		Settings::update( array( self::SETTING => $cfg ) );

		return $cfg;
	}

	/**
	 * Eingabe bereinigen – reine Regel.
	 *
	 * Die Gewichtsstufen bleiben die des Vertrags; geändert werden nur die
	 * Preise. Fehlt etwas oder ist es keine Zahl, gilt der Vertragswert.
	 *
	 * @param array $raw Rohe Preise.
	 * @return array
	 */
	public static function sanitize( array $raw ) {
		$def = self::defaults();
		$out = $def;

		$table = static function ( $rows, $default ) {
			$prices = array();

			foreach ( (array) $rows as $row ) {
				if ( is_array( $row ) && isset( $row[0], $row[1] ) ) {
					$prices[ (string) (float) $row[0] ] = $row[1];
				}
			}

			$out = array();

			foreach ( $default as $step ) {
				$key   = (string) (float) $step[0];
				$price = isset( $prices[ $key ] ) ? self::number( $prices[ $key ] ) : null;
				$out[] = array( $step[0], null !== $price && $price >= 0 ? $price : $step[1] );
			}

			return $out;
		};

		$out['paket'] = $table( isset( $raw['paket'] ) ? $raw['paket'] : array(), $def['paket'] );

		foreach ( array( 1, 2, 3 ) as $zone ) {
			$out['euro'][ $zone ] = $table( isset( $raw['euro'][ $zone ] ) ? $raw['euro'][ $zone ] : array(), $def['euro'][ $zone ] );
		}

		foreach ( array( 'paket', 'euro' ) as $key ) {
			$value                 = isset( $raw['sperrgut'][ $key ] ) ? self::number( $raw['sperrgut'][ $key ] ) : null;
			$out['sperrgut'][ $key ] = null !== $value && $value >= 0 ? $value : $def['sperrgut'][ $key ];
		}

		$z = isset( $raw['zuschlaege'] ) && is_array( $raw['zuschlaege'] ) ? $raw['zuschlaege'] : array();

		foreach ( array( 'maut', 'peak', 'pip' ) as $key ) {
			$value                       = isset( $z[ $key ] ) ? self::number( $z[ $key ] ) : null;
			$out['zuschlaege'][ $key ] = null !== $value && $value >= 0 ? $value : $def['zuschlaege'][ $key ];
		}

		$months = array();

		foreach ( explode( ',', isset( $z['peak_monate'] ) ? (string) $z['peak_monate'] : $def['zuschlaege']['peak_monate'] ) as $m ) {
			$m = (int) trim( $m );

			if ( $m >= 1 && $m <= 12 ) {
				$months[ $m ] = $m;
			}
		}

		sort( $months );
		$out['zuschlaege']['peak_monate'] = implode( ',', $months );

		foreach ( array( 'pip_von', 'pip_bis' ) as $key ) {
			$date                        = isset( $z[ $key ] ) ? trim( (string) $z[ $key ] ) : '';
			$out['zuschlaege'][ $key ] = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
		}

		$percent                               = isset( $z['energie_prozent'] ) ? self::number( $z['energie_prozent'] ) : null;
		$out['zuschlaege']['energie_prozent'] = null !== $percent && $percent >= 0 && $percent <= 100 ? $percent : 0.0;

		return $out;
	}

	/**
	 * Zahl aus Eingabe (auch mit Komma).
	 *
	 * @param mixed $value Eingabe.
	 * @return float|null
	 */
	protected static function number( $value ) {
		$value = str_replace( ',', '.', trim( (string) $value ) );

		return is_numeric( $value ) ? round( (float) $value, 2 ) : null;
	}

	/**
	 * Preis der Gewichtsstufe.
	 *
	 * @param array $rows      Stufen: [bis kg, Preis].
	 * @param float $weight_kg Gewicht in kg.
	 * @return float|null Null, wenn schwerer als die letzte Stufe.
	 */
	public static function bracket_price( array $rows, $weight_kg ) {
		foreach ( $rows as $row ) {
			if ( $weight_kg <= (float) $row[0] + 0.000001 ) {
				return (float) $row[1];
			}
		}

		return null;
	}

	/**
	 * Porto eines Labels berechnen – reine Regel.
	 *
	 * @param array  $cfg      Preise aus config().
	 * @param string $product  Produktschlüssel (V01PAK, V54EPAK).
	 * @param string $country  Zielland (Ländercode).
	 * @param float  $weight_g Gewicht in Gramm.
	 * @param bool   $bulky    Sperrgut?
	 * @param string $date     Versanddatum (Y-m-d) für zeitabhängige Zuschläge.
	 * @return array {ok, net, parts, message}
	 */
	public static function estimate( array $cfg, $product, $country, $weight_g, $bulky, $date ) {
		$procedure = DhlProduct::procedure( $product );
		$kg        = max( 0.0, (float) $weight_g ) / 1000;

		if ( $kg <= 0 ) {
			return self::none( __( 'Ohne Gewicht lässt sich kein Porto berechnen.', 'lindenweg16' ) );
		}

		if ( '01' === $procedure ) {
			$base  = self::bracket_price( $cfg['paket'], $kg );
			$label = __( 'DHL Paket', 'lindenweg16' );
			$bulk  = $cfg['sperrgut']['paket'];
		} elseif ( '54' === $procedure ) {
			$zone = self::zone_of( $country );

			if ( ! $zone ) {
				/* translators: %s: Ländercode. */
				return self::none( sprintf( __( 'Das Land %s steht nicht in Ihrer Europaket-Preisliste.', 'lindenweg16' ), strtoupper( (string) $country ) ) );
			}

			$base  = self::bracket_price( $cfg['euro'][ $zone ], $kg );
			/* translators: %d: Zone. */
			$label = sprintf( __( 'DHL Europaket, Zone %d', 'lindenweg16' ), $zone );
			$bulk  = $cfg['sperrgut']['euro'];
		} else {
			return self::none( __( 'Für dieses Produkt sind keine Preise hinterlegt.', 'lindenweg16' ) );
		}

		if ( null === $base ) {
			return self::none( __( 'Das Gewicht liegt über der höchsten Preisstufe (31,5 kg).', 'lindenweg16' ) );
		}

		$parts = array( array( $label, $base ) );
		$z     = $cfg['zuschlaege'];

		if ( $bulky ) {
			$parts[] = array( __( 'Sperrgut', 'lindenweg16' ), (float) $bulk );
		}

		if ( $z['maut'] > 0 ) {
			$parts[] = array( __( 'Maut- und CO₂-Zuschlag', 'lindenweg16' ), (float) $z['maut'] );
		}

		$month = (int) substr( (string) $date, 5, 2 );

		if ( $z['peak'] > 0 && in_array( $month, array_map( 'intval', explode( ',', (string) $z['peak_monate'] ) ), true ) ) {
			$parts[] = array( __( 'Peakzuschlag', 'lindenweg16' ), (float) $z['peak'] );
		}

		if ( $z['pip'] > 0 && '' !== $z['pip_von'] && '' !== $z['pip_bis'] && $date >= $z['pip_von'] && $date <= $z['pip_bis'] ) {
			$parts[] = array( __( 'Peak-in-Peak-Zuschlag', 'lindenweg16' ), (float) $z['pip'] );
		}

		if ( $z['energie_prozent'] > 0 ) {
			/* translators: %s: Prozent. */
			$parts[] = array( sprintf( __( 'Energiezuschlag %s %%', 'lindenweg16' ), rtrim( rtrim( number_format( (float) $z['energie_prozent'], 2, ',', '' ), '0' ), ',' ) ), round( $base * $z['energie_prozent'] / 100, 2 ) );
		}

		$net = 0.0;

		foreach ( $parts as $part ) {
			$net += $part[1];
		}

		return array( 'ok' => true, 'net' => round( $net, 2 ), 'parts' => $parts, 'message' => '' );
	}

	/**
	 * Ergebnis ohne Preis.
	 *
	 * @param string $message Grund.
	 * @return array
	 */
	protected static function none( $message ) {
		return array( 'ok' => false, 'net' => null, 'parts' => array(), 'message' => $message );
	}

	/**
	 * Kurztext zur Aufschlüsselung („DHL Paket 5,80 + Maut 0,19 …“).
	 *
	 * @param array $parts Teile aus estimate().
	 * @return string
	 */
	public static function describe( array $parts ) {
		$out = array();

		foreach ( $parts as $part ) {
			$out[] = $part[0] . ' ' . number_format( (float) $part[1], 2, ',', '.' );
		}

		return implode( ' + ', $out );
	}
}
