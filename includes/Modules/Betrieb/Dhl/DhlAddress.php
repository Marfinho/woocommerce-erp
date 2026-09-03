<?php
/**
 * Anschriften für die DHL aufbereiten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl;

defined( 'ABSPATH' ) || exit;

/**
 * Die DHL will Straße und Hausnummer getrennt, WooCommerce führt sie in
 * einem Feld. Dazwischen steht diese Klasse – reine Zeichenkettenlogik,
 * damit sie prüfbar bleibt.
 *
 * Eine falsch getrennte Adresse fällt nicht beim Erzeugen des Labels auf,
 * sondern wenn das Paket zurückkommt.
 */
class DhlAddress {

	/**
	 * Straße und Hausnummer trennen.
	 *
	 * Die Hausnummer steht im deutschen Sprachraum hinten und darf einen
	 * Buchstaben oder einen Zusatz tragen: „Lindenweg 16", „Lindenweg 16a",
	 * „Lindenweg 16-18". Steht sie ausnahmsweise vorn („16 Lindenweg"),
	 * wird auch das erkannt. Findet sich gar keine Nummer, bleibt das Feld
	 * leer – lieber keine Hausnummer als eine erfundene.
	 *
	 * @param string $street Ganze Zeile.
	 * @return array {strasse, hausnummer}
	 */
	public static function split_street( $street ) {
		$street = trim( preg_replace( '/\s+/u', ' ', (string) $street ) );

		if ( '' === $street ) {
			return array(
				'strasse'    => '',
				'hausnummer' => '',
			);
		}

		// Hausnummer am Ende – der Regelfall.
		if ( preg_match( '/^(.*?)[\s,]+(\d+\s*[-\/]?\s*\d*\s*[a-zA-Z]?)$/u', $street, $treffer ) ) {
			return array(
				'strasse'    => trim( $treffer[1] ),
				'hausnummer' => preg_replace( '/\s+/', '', $treffer[2] ),
			);
		}

		// Hausnummer am Anfang – englische Schreibweise.
		if ( preg_match( '/^(\d+\s*[a-zA-Z]?)[\s,]+(.+)$/u', $street, $treffer ) ) {
			return array(
				'strasse'    => trim( $treffer[2] ),
				'hausnummer' => preg_replace( '/\s+/', '', $treffer[1] ),
			);
		}

		return array(
			'strasse'    => $street,
			'hausnummer' => '',
		);
	}

	/**
	 * „49626 Bippen" in Postleitzahl und Ort zerlegen.
	 *
	 * Die Firmenstammdaten führen beides in einem Feld. Für das Label
	 * braucht die DHL zwei.
	 *
	 * @param string $line Zeile aus den Stammdaten.
	 * @return array {plz, ort}
	 */
	public static function split_postal_city( $line ) {
		$line = trim( preg_replace( '/\s+/u', ' ', (string) $line ) );

		if ( preg_match( '/^([A-Z]{1,2}-)?(\d{4,6})\s+(.+)$/u', $line, $treffer ) ) {
			return array(
				'plz' => $treffer[2],
				'ort' => trim( $treffer[3] ),
			);
		}

		return array(
			'plz' => '',
			'ort' => $line,
		);
	}

	/**
	 * Anschrift in die Struktur der DHL bringen.
	 *
	 * Leere Felder fliegen raus: Die Schnittstelle weist einen leeren
	 * String zurück, wo sie ein fehlendes Feld stillschweigend hinnimmt.
	 *
	 * @param array $daten {name1, name2, strasse, hausnummer, plz, ort, land, email, telefon, zusatz}
	 * @return array
	 */
	public static function to_dhl( array $daten ) {
		$adresse = array(
			'name1'        => self::cut( self::text( $daten, 'name1' ), 50 ),
			'name2'        => self::cut( self::text( $daten, 'name2' ), 50 ),
			'addressStreet' => self::cut( self::text( $daten, 'strasse' ), 50 ),
			'addressHouse' => self::cut( self::text( $daten, 'hausnummer' ), 10 ),
			'postalCode'   => self::text( $daten, 'plz' ),
			'city'         => self::cut( self::text( $daten, 'ort' ), 40 ),
			'country'      => self::country( self::text( $daten, 'land' ) ),
			'email'        => self::text( $daten, 'email' ),
			'phone'        => self::text( $daten, 'telefon' ),
			'additionalAddressInformation1' => self::cut( self::text( $daten, 'zusatz' ), 60 ),
		);

		return array_filter(
			$adresse,
			static function ( $wert ) {
				return '' !== $wert;
			}
		);
	}

	/**
	 * Fehlt etwas, das die DHL zwingend braucht?
	 *
	 * Besser hier gemeldet als von der Schnittstelle: Dort kommt der
	 * Fehler als Nummer zurück, hier als Satz.
	 *
	 * @param array $adresse Ergebnis von to_dhl().
	 * @return array Liste fehlender Felder in Klartext.
	 */
	public static function missing( array $adresse ) {
		$pflicht = array(
			'name1'         => __( 'Name', 'lindenweg16' ),
			'addressStreet' => __( 'Straße', 'lindenweg16' ),
			'postalCode'    => __( 'Postleitzahl', 'lindenweg16' ),
			'city'          => __( 'Ort', 'lindenweg16' ),
			'country'       => __( 'Land', 'lindenweg16' ),
		);

		$fehlt = array();

		foreach ( $pflicht as $feld => $klartext ) {
			if ( empty( $adresse[ $feld ] ) ) {
				$fehlt[] = $klartext;
			}
		}

		return $fehlt;
	}

	/**
	 * Zweibuchstabiges Länderkürzel in das dreibuchstabige der DHL.
	 *
	 * WooCommerce führt ISO-3166-1 alpha-2, die DHL will alpha-3. Ein
	 * ungewandeltes „DE" weist sie zurück.
	 *
	 * @param string $code Kürzel, zwei oder drei Buchstaben.
	 * @return string
	 */
	public static function country( $code ) {
		$code = strtoupper( trim( (string) $code ) );

		if ( 3 === strlen( $code ) ) {
			return $code;
		}

		$karte = self::alpha3();

		return isset( $karte[ $code ] ) ? $karte[ $code ] : '';
	}

	/**
	 * Zuordnung alpha-2 => alpha-3 für die Länder, in die geliefert wird.
	 *
	 * Bewusst keine Weltkarte: Was hier fehlt, meldet das Plugin als
	 * fehlendes Land, statt ein falsches Kürzel zu schicken.
	 *
	 * @return array
	 */
	public static function alpha3() {
		return array(
			'AT' => 'AUT', 'BE' => 'BEL', 'BG' => 'BGR', 'CH' => 'CHE',
			'CY' => 'CYP', 'CZ' => 'CZE', 'DE' => 'DEU', 'DK' => 'DNK',
			'EE' => 'EST', 'ES' => 'ESP', 'FI' => 'FIN', 'FR' => 'FRA',
			'GB' => 'GBR', 'GR' => 'GRC', 'HR' => 'HRV', 'HU' => 'HUN',
			'IE' => 'IRL', 'IT' => 'ITA', 'LI' => 'LIE', 'LT' => 'LTU',
			'LU' => 'LUX', 'LV' => 'LVA', 'MT' => 'MLT', 'NL' => 'NLD',
			'NO' => 'NOR', 'PL' => 'POL', 'PT' => 'PRT', 'RO' => 'ROU',
			'SE' => 'SWE', 'SI' => 'SVN', 'SK' => 'SVK', 'US' => 'USA',
		);
	}

	/**
	 * Feld lesen.
	 *
	 * @param array  $daten Datensatz.
	 * @param string $key   Feld.
	 * @return string
	 */
	protected static function text( array $daten, $key ) {
		return isset( $daten[ $key ] ) ? trim( (string) $daten[ $key ] ) : '';
	}

	/**
	 * Auf die Feldlänge der DHL kürzen.
	 *
	 * Ein zu langer Wert wird nicht gekürzt angenommen, sondern weist die
	 * ganze Sendung zurück.
	 *
	 * @param string $wert  Wert.
	 * @param int    $laenge Höchstlänge.
	 * @return string
	 */
	protected static function cut( $wert, $laenge ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $wert, 0, $laenge );
		}

		return substr( $wert, 0, $laenge );
	}
}
