<?php
/**
 * Bestellart-Rechner: Deckungsbeitrag eines Sets.
 *
 * Verkauft wird überwiegend als Set aus Stange und mehreren Modulen.
 * Entscheidend ist, dass Verpackung und Zahlungsgebühr genau einmal je
 * Bestellung anfallen und nicht je Position – das ist der Grund, warum ein
 * Set wirtschaftlich besser ist als drei Einzelbestellungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Rechnet Sets und vergleicht sie gegen den Einzelverkauf.
 */
class BundleCalculator {

	/**
	 * Ein Set rechnen.
	 *
	 * @param array $items    Positionen: [['article_id' => int, 'qty' => float], …].
	 * @param float $price    Bruttopreis des Sets.
	 * @param array $ctx      Kontext.
	 * @param array $settings Annahmen inklusive machine_rate_per_h.
	 * @return array
	 */
	public static function calculate( array $items, $price, array $ctx, array $settings ) {
		$summe = array(
			'herstellkosten'     => 0.0,
			'material_druck'     => 0.0,
			'material_zukauf'    => 0.0,
			'eigenmaschine'      => 0.0,
			'komponenten'        => 0.0,
			'print_time_h'       => 0.0,
			'postprocessing_min' => 0.0,
		);

		$positionen = array();
		$errors     = array();

		foreach ( $items as $item ) {
			$item       = (array) $item;
			$article_id = isset( $item['article_id'] ) ? (int) $item['article_id'] : 0;
			$qty        = isset( $item['qty'] ) ? (float) $item['qty'] : 0.0;

			if ( ! $article_id || $qty <= 0 ) {
				continue;
			}

			$cost   = CostCalculator::manufacturing_cost( $article_id, $ctx, $settings );
			$errors = array_merge( $errors, $cost['errors'] );

			$summe['herstellkosten']     += $qty * $cost['herstellkosten'];
			$summe['eigenmaschine']      += $qty * $cost['eigenmaschine'];
			$summe['komponenten']        += $qty * ( $cost['komponenten'] + $cost['unterbaugruppen'] );
			$summe['print_time_h']       += $qty * $cost['print_time_h'];
			$summe['postprocessing_min'] += $qty * $cost['postprocessing_min'];

			// Material getrennt nach Druck und Zukauf ausweisen.
			$type = isset( $cost['sourcing_type'] ) ? $cost['sourcing_type'] : 'druck';
			if ( 'zukauf' === $type ) {
				$summe['material_zukauf'] += $qty * $cost['eigenmaterial'];
			} else {
				$summe['material_druck'] += $qty * $cost['eigenmaterial'];
			}

			$positionen[] = array(
				'article_id'     => $article_id,
				'sku'            => $cost['sku'],
				'name'           => $cost['name'],
				'qty'            => $qty,
				'herstellkosten' => $cost['herstellkosten'],
				'cost'           => $qty * $cost['herstellkosten'],
				'einzelpreis'    => self::single_price( $article_id, $ctx ),
			);
		}

		// Verpackung und Zahlungsgebühr genau einmal je Bestellung.
		$brutto     = (float) $price;
		$vat        = self::setting( $settings, 'vat_rate' );
		$netto      = $brutto / ( 1 + $vat );
		$ausschuss  = $summe['herstellkosten'] * self::setting( $settings, 'scrap_surcharge_pct' );
		$verpackung = self::setting( $settings, 'packaging_cost' );
		$zahlung    = $brutto * self::setting( $settings, 'payment_fee_pct' ) + self::setting( $settings, 'payment_fee_fixed' );

		$db1      = $netto - ( $summe['herstellkosten'] + $ausschuss + $verpackung + $zahlung );
		$handling = $summe['postprocessing_min'] / 60 * self::setting( $settings, 'labor_rate_per_h' );
		$db2      = $db1 - $handling;

		return array(
			'price_gross'        => $brutto,
			'netto_erloes'       => $netto,
			'material_druck'     => $summe['material_druck'],
			'material_zukauf'    => $summe['material_zukauf'],
			'eigenmaschine'      => $summe['eigenmaschine'],
			'komponenten'        => $summe['komponenten'],
			'herstellkosten'     => $summe['herstellkosten'],
			'ausschuss'          => $ausschuss,
			'verpackung'         => $verpackung,
			'zahlung'            => $zahlung,
			'db1'                => $db1,
			'db1_pct'            => $netto > 0 ? $db1 / $netto : 0.0,
			'handling'           => $handling,
			'db2'                => $db2,
			'db2_pct'            => $netto > 0 ? $db2 / $netto : 0.0,
			'print_time_h'       => $summe['print_time_h'],
			'postprocessing_min' => $summe['postprocessing_min'],
			'positionen'         => $positionen,
			'errors'             => $errors,
		);
	}

	/**
	 * Dieselben Positionen als Einzelbestellungen gerechnet.
	 *
	 * Jede Position trägt dann ihre eigene Verpackung und Zahlungsgebühr –
	 * das ist der Vergleichsmaßstab für das Set.
	 *
	 * @param array $items    Positionen.
	 * @param array $ctx      Kontext.
	 * @param array $settings Annahmen.
	 * @return array
	 */
	public static function calculate_single_orders( array $items, array $ctx, array $settings ) {
		$db1   = 0.0;
		$db2   = 0.0;
		$netto = 0.0;
		$anzahl = 0;

		foreach ( $items as $item ) {
			$item       = (array) $item;
			$article_id = isset( $item['article_id'] ) ? (int) $item['article_id'] : 0;
			$qty        = isset( $item['qty'] ) ? (float) $item['qty'] : 0.0;

			if ( ! $article_id || $qty <= 0 ) {
				continue;
			}

			$preis = self::single_price( $article_id, $ctx );
			$cost  = CostCalculator::manufacturing_cost( $article_id, $ctx, $settings );

			// Je Stück eine eigene Bestellung – so wird nachgekauft.
			for ( $i = 0; $i < (int) ceil( $qty ); $i++ ) {
				$row    = CostCalculator::contribution_from_cost( $cost, $preis, $settings );
				$db1   += $row['db1'];
				$db2   += $row['db2'];
				$netto += $row['netto_erloes'];
				$anzahl++;
			}
		}

		return array(
			'bestellungen' => $anzahl,
			'netto_erloes' => $netto,
			'db1'          => $db1,
			'db2'          => $db2,
		);
	}

	/**
	 * Set gegen Einzelverkauf vergleichen.
	 *
	 * @param array $items    Positionen.
	 * @param float $price    Setpreis brutto.
	 * @param array $ctx      Kontext.
	 * @param array $settings Annahmen.
	 * @return array {set, einzeln, differenz_db2}
	 */
	public static function compare( array $items, $price, array $ctx, array $settings ) {
		$set     = self::calculate( $items, $price, $ctx, $settings );
		$einzeln = self::calculate_single_orders( $items, $ctx, $settings );

		return array(
			'set'            => $set,
			'einzeln'        => $einzeln,
			'differenz_db1'  => $set['db1'] - $einzeln['db1'],
			'differenz_db2'  => $set['db2'] - $einzeln['db2'],
			'ersparte_gebuehren' => max( 0, $einzeln['bestellungen'] - 1 )
				* ( self::setting( $settings, 'packaging_cost' ) + self::setting( $settings, 'payment_fee_fixed' ) ),
		);
	}

	/**
	 * Einzelverkaufspreis eines Artikels.
	 *
	 * @param int   $article_id Artikel.
	 * @param array $ctx        Kontext.
	 * @return float
	 */
	protected static function single_price( $article_id, array $ctx ) {
		if ( ! isset( $ctx['articles'][ $article_id ] ) ) {
			return 0.0;
		}
		$article = (array) $ctx['articles'][ $article_id ];
		return isset( $article['price_gross'] ) ? (float) $article['price_gross'] : 0.0;
	}

	/**
	 * Annahme lesen.
	 *
	 * @param array  $settings Annahmen.
	 * @param string $key      Schlüssel.
	 * @return float
	 */
	protected static function setting( array $settings, $key ) {
		return isset( $settings[ $key ] ) ? (float) $settings[ $key ] : 0.0;
	}
}
