<?php
/**
 * Katalog: Produkte mit ihren Problemen, und die Produktakte.
 *
 * Statt Artikel, Revisionen, Stückliste, Kalkulation und Shop-Produkt an fünf
 * Orten zu suchen, zeigt die Liste, woran ein Produkt hängt: fehlende
 * Projektdatei, kritische Marge, kein Shop-Eintrag, kein Preis. Die Akte
 * fasst es zusammen und führt zu den Formularen.
 *
 * Reine Regeln stehen oben, Abfragen unten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\CalculationContext;
use Lindenweg16\Modules\Betrieb\CostCalculator;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\StockLedger;
use Lindenweg16\Modules\Betrieb\StockRepository;

/**
 * Produktliste und Produktakte.
 */
class Catalog {

	/**
	 * Darunter gilt die Marge (DB II) als kritisch.
	 */
	const MIN_MARGIN = 0.20;

	/**
	 * Welche Probleme hat ein Produkt? – reine Regel.
	 *
	 * @param array $f Tatsachen: sourcing, active, sellable, has_model, wc_id, price, margin (Anteil oder null), db2 (oder null).
	 * @return string[] Schlüssel: ohne_3mf, marge, nicht_im_shop, kein_preis
	 */
	public static function flags( array $f ) {
		$out = array();

		if ( ! $f['active'] ) {
			return $out;
		}

		if ( 'druck' === $f['sourcing'] && ! $f['has_model'] ) {
			$out[] = 'ohne_3mf';
		}

		if ( $f['sellable'] && ( null === $f['price'] || $f['price'] <= 0 ) ) {
			$out[] = 'kein_preis';
		}

		if ( $f['sellable'] && empty( $f['wc_id'] ) ) {
			$out[] = 'nicht_im_shop';
		}

		if ( $f['sellable'] && null !== $f['margin'] && $f['price'] > 0 && ( $f['margin'] < self::MIN_MARGIN || ( null !== $f['db2'] && $f['db2'] < 0 ) ) ) {
			$out[] = 'marge';
		}

		return $out;
	}

	/**
	 * Klartext zu einem Problem.
	 *
	 * @return array Schlüssel => [Kurz, Erklärung].
	 */
	public static function flag_texts() {
		return array(
			'ohne_3mf'      => array( __( 'ohne 3MF', 'lindenweg16' ), __( 'Es ist keine ungeslicte Projekt-3MF hinterlegt. Ohne sie lässt sich das Produkt nicht in einen Plan aufnehmen. Bitte unter „3MF und Revisionen“ hochladen.', 'lindenweg16' ) ),
			'kein_preis'    => array( __( 'ohne Preis', 'lindenweg16' ), __( 'Das Produkt ist verkäuflich, hat aber keinen Verkaufspreis.', 'lindenweg16' ) ),
			'nicht_im_shop' => array( __( 'nicht im Shop', 'lindenweg16' ), __( 'Das Produkt ist verkäuflich, aber nicht mit einem Shop-Produkt verknüpft.', 'lindenweg16' ) ),
			'marge'         => array( __( 'Marge kritisch', 'lindenweg16' ), __( 'Der Deckungsbeitrag II liegt unter der Schwelle von 20 % oder ist negativ. Preis oder Herstellkosten prüfen.', 'lindenweg16' ) ),
		);
	}

	/**
	 * Bestand je Artikel (Lagerort „lager“).
	 *
	 * @return array Artikel => Menge.
	 */
	protected static function stock_by_article() {
		$out = array();

		foreach ( StockLedger::balances( StockRepository::all() ) as $article => $colors ) {
			$sum = 0.0;
			foreach ( $colors as $locations ) {
				$sum += isset( $locations['lager'] ) ? (float) $locations['lager'] : 0.0;
			}
			$out[ (int) $article ] = $sum;
		}

		return $out;
	}

	/**
	 * Alle Produkte mit Kennzahlen und Problemen.
	 *
	 * @param bool $with_money Marge und Herstellkosten ausgeben.
	 * @return array {rows, counts}
	 */
	public static function listing( $with_money ) {
		$revisions = RevisionRepository::active_by_article();
		$stock     = self::stock_by_article();
		$ctx       = $with_money ? CalculationContext::build() : array();
		$settings  = $with_money ? CalculationContext::settings() : array();
		$status    = ArticleRepository::status_labels();
		$sourcing  = ArticleRepository::sourcing_labels();
		$rows      = array();
		$counts    = array( 'alle' => 0, 'ohne_3mf' => 0, 'marge' => 0, 'nicht_im_shop' => 0, 'kein_preis' => 0 );

		foreach ( ArticleRepository::all() as $article ) {
			$id       = (int) $article['id'];
			$revision = isset( $revisions[ $id ] ) ? $revisions[ $id ] : null;
			$price    = null === $article['price_gross'] ? null : (float) $article['price_gross'];
			$margin   = null;
			$db2      = null;

			if ( $with_money && $price > 0 ) {
				$calc   = CostCalculator::contribution( $id, $ctx, $settings, 'uvp' );
				$margin = (float) $calc['db2_pct'];
				$db2    = (float) $calc['db2'];
			}

			$flags = self::flags(
				array(
					'sourcing'  => (string) $article['sourcing_type'],
					'active'    => ArticleRepository::is_active( $article ),
					'sellable'  => (bool) $article['sellable'],
					'has_model' => $revision && ! empty( $revision['model_file'] ) && 'modell' === $revision['model_kind'],
					'wc_id'     => (int) $article['wc_product_id'],
					'price'     => $price,
					'margin'    => $margin,
					'db2'       => $db2,
				)
			);

			++$counts['alle'];
			foreach ( $flags as $flag ) {
				++$counts[ $flag ];
			}

			$rows[] = array(
				'id'       => $id,
				'sku'      => (string) $article['sku'],
				'name'     => (string) $article['name'],
				'status'   => isset( $status[ $article['status'] ] ) ? $status[ $article['status'] ] : (string) $article['status'],
				'sourcing' => isset( $sourcing[ $article['sourcing_type'] ] ) ? $sourcing[ $article['sourcing_type'] ] : (string) $article['sourcing_type'],
				'price'    => $price,
				'stock'    => isset( $stock[ $id ] ) ? $stock[ $id ] : 0.0,
				'margin'   => null === $margin ? null : round( $margin, 3 ),
				'flags'    => $flags,
			);
		}

		return array( 'rows' => $rows, 'counts' => $counts, 'flags' => self::flag_texts(), 'money' => (bool) $with_money );
	}

	/**
	 * Die Akte eines Produkts.
	 *
	 * @param int  $id         Artikel.
	 * @param bool $with_money Kosten und Marge ausgeben.
	 * @return array|null
	 */
	public static function file( $id, $with_money ) {
		$article = ArticleRepository::find( (int) $id );

		if ( ! $article ) {
			return null;
		}

		$id       = (int) $article['id'];
		$revision = RevisionRepository::active_for_article( $id );
		$stock    = self::stock_by_article();
		$price    = null === $article['price_gross'] ? null : (float) $article['price_gross'];
		$calc     = null;

		if ( $with_money && $price > 0 ) {
			$result = CostCalculator::contribution( $id, CalculationContext::build(), CalculationContext::settings(), 'uvp' );
			$calc   = array(
				'cost'   => round( (float) $result['herstellkosten'], 2 ),
				'db2'    => round( (float) $result['db2'], 2 ),
				'margin' => round( (float) $result['db2_pct'], 3 ),
			);
		}

		$flags = self::flags(
			array(
				'sourcing'  => (string) $article['sourcing_type'],
				'active'    => ArticleRepository::is_active( $article ),
				'sellable'  => (bool) $article['sellable'],
				'has_model' => $revision && ! empty( $revision['model_file'] ) && 'modell' === $revision['model_kind'],
				'wc_id'     => (int) $article['wc_product_id'],
				'price'     => $price,
				'margin'    => $calc ? $calc['margin'] : null,
				'db2'       => $calc ? $calc['db2'] : null,
			)
		);

		$texts   = self::flag_texts();
		$problem = array();
		foreach ( $flags as $flag ) {
			$problem[] = array( 'key' => $flag, 'label' => $texts[ $flag ][0], 'text' => $texts[ $flag ][1] );
		}

		$variance = null;
		foreach ( SollIst::report( false )['articles'] as $entry ) {
			if ( $entry['article_id'] === $id ) {
				$variance = array( 'factor' => $entry['factor'], 'runs' => $entry['runs'], 'plate_h' => $entry['plate_h'], 'new_h' => $entry['new_h'], 'adjust' => $entry['adjust'] );
			}
		}

		$page  = \Lindenweg16\Modules\Betrieb\Admin\ArticlesPage::SLUG;
		$base  = 'admin.php?page=' . $page;
		$paths = array(
			'stamm' => $base . '&tab=artikel&edit=' . $id,
			'rev'   => $base . '&tab=revisionen&article=' . $id,
			'bom'   => $base . '&tab=stueckliste&article=' . $id,
			'calc'  => $base . '&tab=kalkulation&article=' . $id,
		);

		$wc = (int) $article['wc_product_id'];

		return array(
			'id'       => $id,
			'sku'      => (string) $article['sku'],
			'name'     => (string) $article['name'],
			'status'   => (string) $article['status'],
			'sourcing' => (string) $article['sourcing_type'],
			'sellable' => (bool) $article['sellable'],
			'price'    => $price,
			'stock'    => isset( $stock[ $id ] ) ? $stock[ $id ] : 0.0,
			'revision' => $revision ? array(
				'version'  => (string) $revision['version'],
				'material' => (string) $revision['material'],
				'color'    => (string) $revision['color'],
				'model'    => ! empty( $revision['model_name'] ) ? (string) $revision['model_name'] : '',
				'kind'     => (string) $revision['model_kind'],
				'parts'    => (int) $revision['parts_per_plate'],
				'plate_h'  => (float) $revision['print_time_plate_h'],
				'weight_g' => (float) $revision['weight_g'],
			) : null,
			'calc'     => $calc,
			'variance' => $variance,
			'problems' => $problem,
			'paths'    => $paths,
			'shop'     => $wc ? array( 'id' => $wc, 'path' => 'post.php?post=' . $wc . '&action=edit' ) : null,
		);
	}
}
