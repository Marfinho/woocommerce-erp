<?php
/**
 * Entspricht die Wunschkonfiguration einem fertigen Set?
 *
 * Ein Set (BASE, DAILY, FULL STACK …) ist im Artikelstamm ein Artikel mit
 * Stückliste: ein Basismodul und einige Module. Stellt sich ein Kunde im
 * Konfigurator genau diese Teile zusammen, zahlt er einzeln mehr als für
 * das Set – und wir verpacken drei Pakete statt einem. Der Konfigurator
 * schlägt dann das Set vor.
 *
 * Verglichen wird nur, **was** gewählt ist, nicht **wo** es sitzt: Ein Set
 * legt keine Positionen fest. Die Anordnung des Kunden reist als Angabe an
 * der Bestellposition mit.
 *
 * Als Set zählt nur, was sich vollständig im Konfigurator darstellen lässt:
 * Jede Artikelzeile der Stückliste muss ein aktives Konfigurator-Teil sein,
 * genau eines davon ein Basismodul, alle Mengen ganze Stücke. Ein Set mit
 * einem Teil, das der Konfigurator nicht kennt, kann der Kunde gar nicht
 * nachbauen – es wird also auch nie vorgeschlagen. Komponenten der
 * Stückliste (Schrauben, Beutel) spielen für den Vergleich keine Rolle.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\BomRepository;
use Lindenweg16\Modules\Betrieb\Variants;

/**
 * Findet Sets und gleicht Konfigurationen mit ihnen ab.
 */
class SetMatcher {

	/**
	 * Sets, die sich im Konfigurator nachbauen lassen.
	 *
	 * @param array $parts    Teile nach Artikel-ID (PartRepository::context()['parts']).
	 * @param array $articles Artikel nach ID.
	 * @param array $bom      Stücklistenzeilen nach Elternartikel.
	 * @return array Liste von {article, sku, name, wc_product_id, base, modules[article => qty]}.
	 */
	public static function sets( array $parts, array $articles, array $bom ) {
		$sets = array();

		foreach ( $bom as $parent_id => $rows ) {
			$parent_id = (int) $parent_id;
			$article   = isset( $articles[ $parent_id ] ) ? $articles[ $parent_id ] : null;

			// Ein Teil des Konfigurators selbst (etwa das Basismodul mit
			// seinen Wandankern) ist kein Set, auch wenn es eine Stückliste hat.
			if ( ! $article || isset( $parts[ $parent_id ] ) ) {
				continue;
			}

			if ( ! ArticleRepository::is_active( $article ) || empty( $article['wc_product_id'] ) ) {
				continue;
			}

			if ( ( isset( $article['sellable'] ) && ! (int) $article['sellable'] ) || ! empty( $article['pos_only'] ) ) {
				continue;
			}

			$set = self::from_rows( (array) $rows, $parts );

			if ( ! $set ) {
				continue;
			}

			// Ein Set mit wählbaren Modulen (Holz am Basismodul, Typ am
			// Schuhhalter) gibt es in jeder Kombination: je eine Fassung
			// mit den tatsächlichen Teilen und den Merkmalen, die die
			// Variante des Set-Produkts auswählen.
			foreach ( self::variants_of( $set, $parts, $articles ) as $variant ) {
				$sets[] = array_merge(
					array(
						'article'       => $parent_id,
						'sku'           => (string) $article['sku'],
						'name'          => (string) $article['name'],
						'wc_product_id' => (int) $article['wc_product_id'],
					),
					$variant
				);
			}
		}

		return $sets;
	}

	/**
	 * Höchstzahl der Fassungen eines Sets – gegen eine Kombinationsexplosion.
	 */
	const MAX_VARIANTS = 64;

	/**
	 * Die Fassungen eines Sets nach Wahl der Varianten.
	 *
	 * Jedes Teil der Stückliste darf gegen ein Geschwister (gleiches
	 * Shop-Produkt, andere Shop-Variante) getauscht werden, sofern es ein
	 * Konfigurator-Teil ist. Kombinationen mit widersprüchlichen Merkmalen
	 * (zwei Schuhhalter, einer SPD, einer SPD-SL) entfallen: Das Set-Produkt
	 * kennt je Merkmal nur eine Wahl.
	 *
	 * @param array $set      {base, modules} aus from_rows().
	 * @param array $parts    Teile nach Artikel-ID.
	 * @param array $articles Artikel nach ID.
	 * @return array Liste von {base, modules, attrs, variant}.
	 */
	protected static function variants_of( array $set, array $parts, array $articles ) {
		$groups = Variants::groups( $articles );

		$siblings = static function ( $child ) use ( $groups, $articles, $parts ) {
			$product = isset( $articles[ $child ]['wc_product_id'] ) ? (int) $articles[ $child ]['wc_product_id'] : 0;
			$group   = $product && isset( $groups[ $product ] ) ? $groups[ $product ] : array( $child => array() );
			$out     = array();

			if ( ! isset( $group[ $child ] ) ) {
				$group[ $child ] = Variants::parse( isset( $articles[ $child ]['wc_variant'] ) ? (string) $articles[ $child ]['wc_variant'] : '' );
			}

			foreach ( $group as $id => $pairs ) {
				if ( (int) $id === (int) $child || ( $pairs && isset( $parts[ (int) $id ] ) ) ) {
					$out[ (int) $id ] = (int) $id === (int) $child ? $group[ $child ] : $pairs;
				}
			}

			return $out;
		};

		// Slots: das Basismodul und je Modul so viele Plätze wie Stück.
		$slots = array( array( 'role' => 'base', 'options' => $siblings( (int) $set['base'] ) ) );
		foreach ( $set['modules'] as $child => $qty ) {
			for ( $i = 0; $i < (int) $qty; $i++ ) {
				$slots[] = array( 'role' => 'module', 'options' => $siblings( (int) $child ) );
			}
		}

		$combos = array(
			array(
				'base'    => 0,
				'modules' => array(),
				'attrs'   => array(),
			),
		);

		foreach ( $slots as $slot ) {
			$next = array();

			foreach ( $combos as $combo ) {
				foreach ( $slot['options'] as $id => $pairs ) {
					$attrs = $combo['attrs'];
					$clash = false;

					foreach ( $pairs as $key => $value ) {
						if ( isset( $attrs[ $key ] ) && $attrs[ $key ] !== $value ) {
							$clash = true;
							break;
						}
						$attrs[ $key ] = $value;
					}

					if ( $clash ) {
						continue;
					}

					$new          = $combo;
					$new['attrs'] = $attrs;

					if ( 'base' === $slot['role'] ) {
						$new['base'] = (int) $id;
					} else {
						$new['modules'][ (int) $id ] = ( isset( $new['modules'][ (int) $id ] ) ? $new['modules'][ (int) $id ] : 0 ) + 1;
					}

					$next[] = $new;

					if ( count( $next ) > self::MAX_VARIANTS * 4 ) {
						break 2;
					}
				}
			}

			$combos = $next;
		}

		$out  = array();
		$seen = array();

		foreach ( $combos as $combo ) {
			ksort( $combo['modules'] );
			ksort( $combo['attrs'] );
			$key = $combo['base'] . '|' . http_build_query( $combo['modules'] );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$out[]        = array(
				'base'    => $combo['base'],
				'modules' => $combo['modules'],
				'attrs'   => $combo['attrs'],
				'variant' => Variants::format( $combo['attrs'] ),
			);

			if ( count( $out ) >= self::MAX_VARIANTS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Die Stückliste eines Kandidaten als Basismodul plus Module.
	 *
	 * @param array $rows  Stücklistenzeilen.
	 * @param array $parts Teile nach Artikel-ID.
	 * @return array|null {base, modules} oder null, wenn es kein Konfigurator-Set ist.
	 */
	protected static function from_rows( array $rows, array $parts ) {
		$base    = 0;
		$modules = array();

		foreach ( $rows as $row ) {
			if ( 'artikel' !== ( isset( $row['child_type'] ) ? $row['child_type'] : '' ) ) {
				continue;
			}

			$child = (int) $row['child_id'];
			$qty   = (float) $row['qty'];

			if ( ! isset( $parts[ $child ] ) || $qty <= 0 || floor( $qty ) !== $qty ) {
				return null;
			}

			if ( 'basis' === $parts[ $child ]['role'] ) {
				if ( $base || 1.0 !== $qty ) {
					return null;
				}
				$base = $child;
				continue;
			}

			$modules[ $child ] = ( isset( $modules[ $child ] ) ? $modules[ $child ] : 0 ) + (int) $qty;
		}

		if ( ! $base || ! $modules ) {
			return null;
		}

		ksort( $modules );

		return array(
			'base'    => $base,
			'modules' => $modules,
		);
	}

	/**
	 * Das Set, dem eine Konfiguration genau entspricht.
	 *
	 * @param array $sets   Sets aus sets().
	 * @param int   $base   Gewähltes Basismodul.
	 * @param array $chosen Gewählte Module: [['article' => int, 'pos' => float], …].
	 * @return array|null
	 */
	public static function match( array $sets, $base, array $chosen ) {
		$counts = self::counts( $chosen );

		foreach ( $sets as $set ) {
			if ( (int) $set['base'] === (int) $base && $set['modules'] === $counts ) {
				return $set;
			}
		}

		return null;
	}

	/**
	 * Module gezählt nach Artikel.
	 *
	 * @param array $chosen Gewählte Module.
	 * @return array [article => qty], nach Artikel sortiert.
	 */
	public static function counts( array $chosen ) {
		$counts = array();

		foreach ( $chosen as $module ) {
			$article = isset( $module['article'] ) ? (int) $module['article'] : 0;

			if ( $article > 0 ) {
				$counts[ $article ] = ( isset( $counts[ $article ] ) ? $counts[ $article ] : 0 ) + 1;
			}
		}

		ksort( $counts );

		return $counts;
	}

	/**
	 * Sets aus den Stammdaten.
	 *
	 * @param array $context PartRepository::context().
	 * @return array
	 */
	public static function from_context( array $context ) {
		return self::sets( $context['parts'], ArticleRepository::all_by_id(), BomRepository::all_by_parent() );
	}
}
