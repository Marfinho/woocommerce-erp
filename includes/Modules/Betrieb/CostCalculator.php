<?php
/**
 * Herstellkosten und Deckungsbeitragsrechnung.
 *
 * Nichts davon wird gespeichert – die Rechnung läuft bei jeder Anzeige neu
 * gegen die zum Stichtag gültigen Annahmen. Gespeicherte Kostenspalten wären
 * nach der ersten Preisänderung des Lieferanten falsch.
 *
 * Bewusst ohne Datenbankzugriff, damit die Formeln testbar bleiben. Der
 * Aufrufer (CalculationContext) lädt die Daten und übergibt sie als Kontext;
 * der Maschinenstundensatz kommt als Annahme herein und wird von MachineRate
 * aus den Gerätedaten berechnet.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Rechnet Herstellkosten rekursiv über die Stückliste und daraus DB I und II.
 */
class CostCalculator {

	/**
	 * Stückpreis einer Komponente aus der Gebindegröße.
	 *
	 * Kleinteile werden im Gebinde gekauft und im Stück verbraucht. Wer den
	 * Stückpreis von Hand pflegt, hat ihn nach dem zweiten Einkauf falsch.
	 *
	 * @param array $component Komponente mit pack_price_net und pack_qty.
	 * @return float
	 */
	public static function unit_price( array $component ) {
		$qty = isset( $component['pack_qty'] ) ? (float) $component['pack_qty'] : 0.0;
		if ( $qty <= 0 ) {
			return 0.0;
		}
		return ( isset( $component['pack_price_net'] ) ? (float) $component['pack_price_net'] : 0.0 ) / $qty;
	}

	/**
	 * Druckzeit je Teil aus dem Plattenwert.
	 *
	 * @param array $revision Revision mit print_time_plate_h und parts_per_plate.
	 * @return float
	 */
	public static function print_time_per_part( array $revision ) {
		$parts = isset( $revision['parts_per_plate'] ) ? (float) $revision['parts_per_plate'] : 0.0;
		if ( $parts <= 0 ) {
			return 0.0;
		}
		return ( isset( $revision['print_time_plate_h'] ) ? (float) $revision['print_time_plate_h'] : 0.0 ) / $parts;
	}

	/**
	 * Herstellkosten eines Artikels, rekursiv über die Stückliste.
	 *
	 * @param int   $article_id Artikel.
	 * @param array $ctx        Kontext: articles, revisions, components, bom, sourcing.
	 * @param array $settings   Annahmen zum Stichtag inklusive machine_rate_per_h.
	 * @param int   $depth      Aktuelle Rekursionstiefe.
	 * @return array Aufschlüsselung inklusive herstellkosten und Nachbearbeitungszeit.
	 */
	public static function manufacturing_cost( $article_id, array $ctx, array $settings, $depth = 0 ) {
		$article_id = (int) $article_id;
		$leer       = array(
			'article_id'         => $article_id,
			'sku'                => '',
			'name'               => '',
			'sourcing_type'      => '',
			'eigenmaterial'      => 0.0,
			'eigenmaschine'      => 0.0,
			'komponenten'        => 0.0,
			'unterbaugruppen'    => 0.0,
			'herstellkosten'     => 0.0,
			'print_time_h'       => 0.0,
			'postprocessing_min' => 0.0,
			'positionen'         => array(),
			'errors'             => array(),
		);

		// Die Wurzel steht auf Ebene 0, ihre Kinder auf Ebene 1 – erlaubt sind
		// damit fünf Kindebenen, dieselbe Grenze wie im BomResolver.
		if ( $depth > BomResolver::MAX_DEPTH ) {
			$leer['errors'][] = array(
				'type'    => 'depth',
				'message' => __( 'Stückliste tiefer als fünf Ebenen – Kalkulation abgebrochen.', 'lindenweg16' ),
			);
			return $leer;
		}

		if ( ! isset( $ctx['articles'][ $article_id ] ) ) {
			$leer['errors'][] = array(
				'type'    => 'missing_article',
				'message' => sprintf(
					/* translators: %d: Artikel-ID. */
					__( 'Artikel %d nicht gefunden.', 'lindenweg16' ),
					$article_id
				),
			);
			return $leer;
		}

		$article  = (array) $ctx['articles'][ $article_id ];
		$sourcing = isset( $ctx['sourcing'][ $article_id ] ) ? (array) $ctx['sourcing'][ $article_id ] : array();
		$revision = isset( $ctx['revisions'][ $article_id ] ) ? (array) $ctx['revisions'][ $article_id ] : array();
		$type     = isset( $article['sourcing_type'] ) ? (string) $article['sourcing_type'] : 'druck';

		$result               = $leer;
		$result['sourcing_type'] = $type;
		$result['name']       = isset( $article['name'] ) ? (string) $article['name'] : '';
		$result['sku']        = isset( $article['sku'] ) ? (string) $article['sku'] : '';

		// --- Eigenfertigung ------------------------------------------------
		// Nur gedruckte und hybride Artikel haben Filamentgewicht und Druckzeit.
		// Für einen Zukaufartikel wie die Holzstange gäbe es hier Unsinn.
		if ( 'druck' === $type || 'hybrid' === $type ) {
			if ( $revision ) {
				$weight  = isset( $revision['weight_g'] ) ? (float) $revision['weight_g'] : 0.0;
				$zeit    = self::print_time_per_part( $revision );
				$result['eigenmaterial'] += $weight / 1000 * self::setting( $settings, 'filament_price_per_kg' );
				$result['eigenmaschine'] += $zeit * self::setting( $settings, 'machine_rate_per_h' );
				$result['print_time_h']  += $zeit;
			} else {
				$result['errors'][] = array(
					'type'    => 'missing_revision',
					'message' => sprintf(
						/* translators: %s: Artikelnummer. */
						__( 'Für %s ist keine aktive Revision hinterlegt – Material und Druckzeit fehlen in der Kalkulation.', 'lindenweg16' ),
						$result['sku']
					),
				);
			}
		}

		// --- Beschaffung ---------------------------------------------------
		if ( 'zukauf' === $type || 'hybrid' === $type ) {
			if ( $sourcing ) {
				$result['eigenmaterial'] += isset( $sourcing['purchase_price_net'] ) ? (float) $sourcing['purchase_price_net'] : 0.0;
				$result['postprocessing_min'] += isset( $sourcing['own_processing_min'] ) ? (float) $sourcing['own_processing_min'] : 0.0;
			} else {
				$result['errors'][] = array(
					'type'    => 'missing_sourcing',
					'message' => sprintf(
						/* translators: %s: Artikelnummer. */
						__( 'Für den Zukaufartikel %s fehlen Beschaffungsdaten zum Stichtag.', 'lindenweg16' ),
						$result['sku']
					),
				);
			}
		}

		$result['postprocessing_min'] += isset( $article['postprocessing_min'] ) ? (float) $article['postprocessing_min'] : 0.0;

		// --- Stückliste ----------------------------------------------------
		$rows = isset( $ctx['bom'][ $article_id ] ) ? $ctx['bom'][ $article_id ] : array();

		foreach ( $rows as $row ) {
			$row      = (array) $row;
			$qty      = isset( $row['qty'] ) ? (float) $row['qty'] : 0.0;
			$child_id = isset( $row['child_id'] ) ? (int) $row['child_id'] : 0;

			if ( 'komponente' === $row['child_type'] ) {
				if ( ! isset( $ctx['components'][ $child_id ] ) ) {
					$result['errors'][] = array(
						'type'    => 'missing_component',
						'message' => sprintf(
							/* translators: %d: Komponenten-ID. */
							__( 'Komponente %d nicht gefunden.', 'lindenweg16' ),
							$child_id
						),
					);
					continue;
				}
				$component = (array) $ctx['components'][ $child_id ];
				$stueck    = self::unit_price( $component );
				$kosten    = $qty * $stueck;

				$result['komponenten'] += $kosten;
				$result['positionen'][] = array(
					'type'       => 'komponente',
					'id'         => $child_id,
					'label'      => isset( $component['name'] ) ? (string) $component['name'] : '',
					'code'       => isset( $component['code'] ) ? (string) $component['code'] : '',
					'qty'        => $qty,
					'unit_price' => $stueck,
					'cost'       => $kosten,
					'depth'      => $depth,
				);
				continue;
			}

			// Unterbaugruppe: rekursiv.
			$sub    = self::manufacturing_cost( $child_id, $ctx, $settings, $depth + 1 );
			$kosten = $qty * $sub['herstellkosten'];

			$result['unterbaugruppen']    += $kosten;
			$result['print_time_h']       += $qty * $sub['print_time_h'];
			$result['postprocessing_min'] += $qty * $sub['postprocessing_min'];
			$result['errors']              = array_merge( $result['errors'], $sub['errors'] );

			$result['positionen'][] = array(
				'type'       => 'artikel',
				'id'         => $child_id,
				'label'      => $sub['name'],
				'code'       => $sub['sku'],
				'qty'        => $qty,
				'unit_price' => $sub['herstellkosten'],
				'cost'       => $kosten,
				'depth'      => $depth,
			);

			foreach ( $sub['positionen'] as $sub_pos ) {
				$sub_pos['qty']   = $qty * $sub_pos['qty'];
				$sub_pos['cost']  = $qty * $sub_pos['cost'];
				$result['positionen'][] = $sub_pos;
			}
		}

		$result['herstellkosten'] = $result['eigenmaterial'] + $result['eigenmaschine']
			+ $result['komponenten'] + $result['unterbaugruppen'];

		return $result;
	}

	/**
	 * Deckungsbeitragsrechnung eines verkauften Artikels.
	 *
	 * @param int    $article_id Artikel.
	 * @param array  $ctx        Kontext.
	 * @param array  $settings   Annahmen.
	 * @param string $price_level uvp oder haendler.
	 * @param float  $price_override Abweichender Bruttopreis (z. B. Staffelstufe).
	 * @return array
	 */
	public static function contribution( $article_id, array $ctx, array $settings, $price_level = 'uvp', $price_override = null ) {
		$cost    = self::manufacturing_cost( $article_id, $ctx, $settings );
		$article = isset( $ctx['articles'][ $article_id ] ) ? (array) $ctx['articles'][ $article_id ] : array();

		if ( null !== $price_override ) {
			$brutto = (float) $price_override;
		} elseif ( 'haendler' === $price_level ) {
			$brutto = isset( $article['dealer_price'] ) ? (float) $article['dealer_price'] : 0.0;
		} else {
			$brutto = isset( $article['price_gross'] ) ? (float) $article['price_gross'] : 0.0;
		}

		return self::contribution_from_cost( $cost, $brutto, $settings, $price_level );
	}

	/**
	 * Deckungsbeitrag aus fertigen Herstellkosten.
	 *
	 * Verpackung und Zahlungsgebühr fallen genau einmal an – hier je Artikel,
	 * beim Set einmal je Bestellung (siehe BundleCalculator).
	 *
	 * @param array  $cost        Ergebnis von manufacturing_cost.
	 * @param float  $brutto      Bruttopreis.
	 * @param array  $settings    Annahmen.
	 * @param string $price_level Bezeichnung der Preisebene.
	 * @return array
	 */
	public static function contribution_from_cost( array $cost, $brutto, array $settings, $price_level = 'uvp' ) {
		$brutto = (float) $brutto;
		$vat    = self::setting( $settings, 'vat_rate' );

		$netto = $vat > -1 ? $brutto / ( 1 + $vat ) : $brutto;

		$herstellkosten = (float) $cost['herstellkosten'];
		$ausschuss      = $herstellkosten * self::setting( $settings, 'scrap_surcharge_pct' );
		$verpackung     = self::setting( $settings, 'packaging_cost' );
		$zahlung        = $brutto * self::setting( $settings, 'payment_fee_pct' ) + self::setting( $settings, 'payment_fee_fixed' );

		$db1     = $netto - ( $herstellkosten + $ausschuss + $verpackung + $zahlung );
		$handling = (float) $cost['postprocessing_min'] / 60 * self::setting( $settings, 'labor_rate_per_h' );
		$db2     = $db1 - $handling;

		return array(
			'price_level'        => $price_level,
			'price_gross'        => $brutto,
			'netto_erloes'       => $netto,
			'herstellkosten'     => $herstellkosten,
			'eigenmaterial'      => (float) $cost['eigenmaterial'],
			'eigenmaschine'      => (float) $cost['eigenmaschine'],
			'komponenten'        => (float) $cost['komponenten'],
			'unterbaugruppen'    => (float) $cost['unterbaugruppen'],
			'ausschuss'          => $ausschuss,
			'verpackung'         => $verpackung,
			'zahlung'            => $zahlung,
			'db1'                => $db1,
			'db1_pct'            => $netto > 0 ? $db1 / $netto : 0.0,
			'handling'           => $handling,
			'db2'                => $db2,
			'db2_pct'            => $netto > 0 ? $db2 / $netto : 0.0,
			'print_time_h'       => (float) $cost['print_time_h'],
			'postprocessing_min' => (float) $cost['postprocessing_min'],
			'positionen'         => $cost['positionen'],
			'errors'             => $cost['errors'],
		);
	}

	/**
	 * Deckungsbeitrag einer einzelnen Bestellposition.
	 *
	 * Bewusst **ohne** Verpackung und feste Zahlungsgebühr: Die fallen einmal
	 * je Bestellung an, nicht je Position. Wer sie auf jede Position umlegt,
	 * rechnet eine Bestellung mit fünf Positionen künstlich schlecht. Die
	 * variable Zahlungsgebühr bleibt drin, weil sie mit dem Preis skaliert.
	 *
	 * Diese Größe beantwortet die Frage vor der Zusage: Trägt sich diese
	 * Position zu diesem Preis noch?
	 *
	 * @param array $cost             Ergebnis von manufacturing_cost.
	 * @param float $unit_price_gross Stückpreis brutto nach Rabatt.
	 * @param array $settings         Annahmen.
	 * @return array
	 */
	public static function position_contribution( array $cost, $unit_price_gross, array $settings ) {
		$brutto = (float) $unit_price_gross;
		$netto  = $brutto / ( 1 + self::setting( $settings, 'vat_rate' ) );

		$herstellkosten = (float) $cost['herstellkosten'];
		$ausschuss      = $herstellkosten * self::setting( $settings, 'scrap_surcharge_pct' );
		$zahlung        = $brutto * self::setting( $settings, 'payment_fee_pct' );
		$handling       = (float) $cost['postprocessing_min'] / 60 * self::setting( $settings, 'labor_rate_per_h' );

		$db1 = $netto - ( $herstellkosten + $ausschuss + $zahlung );

		return array(
			'price_gross'    => $brutto,
			'netto_erloes'   => $netto,
			'herstellkosten' => $herstellkosten,
			'ausschuss'      => $ausschuss,
			'zahlung'        => $zahlung,
			'db1'            => $db1,
			'handling'       => $handling,
			'db2'            => $db1 - $handling,
		);
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
