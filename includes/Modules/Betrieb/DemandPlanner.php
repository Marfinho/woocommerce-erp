<?php
/**
 * Bedarfsplanung über alle Fertigungsstufen.
 *
 * Beantwortet die Frage, die man morgens hat: Was muss heute gedruckt,
 * montiert und bestellt werden?
 *
 * Gerechnet wird von oben nach unten. Aus dem Bedarf an Basismodulen wird
 * der Bedarf an Halbzeugen, daraus der an Rohlingen und Schrauben. Auf jeder
 * Stufe wird gegen den vorhandenen Bestand und gegen das, was bereits in
 * Arbeit ist, verrechnet – sonst würde für jede Bestellung neu gedruckt,
 * obwohl das Regal voll ist.
 *
 * Die Reihenfolge ist entscheidend: Ein Halbzeug darf erst aufgelöst werden,
 * wenn feststeht, wie viele davon insgesamt gebraucht werden. Sonst käme aus
 * einer zweiten Bestellung ein zweiter, überflüssiger Fertigungsauftrag.
 * Deshalb werden die Artikel zuerst nach Fertigungstiefe sortiert.
 *
 * Alle Rechenschritte hier sind ohne Datenbank – das Sammeln der Bedarfe
 * steht getrennt in collect().
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Rechnet Bedarfe über die Stückliste nach unten durch.
 */
class DemandPlanner {

	/**
	 * Größte zulässige Stücklistentiefe – wie im Auflöser.
	 */
	const MAX_DEPTH = 5;

	/**
	 * Bedarfsquellen mit ihrer Verbindlichkeit.
	 *
	 * Eine Bestellung ist verkauft, ein Sollbestand ist eine Entscheidung,
	 * ein Wunschlisteneintrag ist ein Hinweis. Sie werden getrennt
	 * ausgewiesen, damit niemand auf Verdacht die Werkstatt vollstellt.
	 *
	 * @return array
	 */
	public static function source_labels() {
		return array(
			'bestellung'  => __( 'Bestellung', 'lindenweg16' ),
			'sollbestand' => __( 'Sollbestand', 'lindenweg16' ),
			'wunschliste' => __( 'Wunschliste', 'lindenweg16' ),
			'hochrechnung' => __( 'Hochrechnung', 'lindenweg16' ),
			'warteliste'  => __( 'Warteliste', 'lindenweg16' ),
			'stueckliste' => __( 'aus Stückliste', 'lindenweg16' ),
		);
	}

	/**
	 * Fertigungstiefe je Artikel bestimmen.
	 *
	 * Tiefe 0 heißt: Der Artikel steckt in keiner Stückliste, er ist ein
	 * Endprodukt. Ein Halbzeug hat Tiefe 1, ein Rohling darunter Tiefe 2.
	 * Gerechnet wird die größte Tiefe, denn ein Rohling, der sowohl direkt
	 * als auch über ein Halbzeug gebraucht wird, muss zuletzt aufgelöst
	 * werden.
	 *
	 * @param array $bom Stückliste als parent_id => Zeilen.
	 * @return array article_id => Tiefe.
	 */
	public static function depths( array $bom ) {
		$depth = array();

		// Alle beteiligten Artikel auf Tiefe 0 setzen.
		foreach ( $bom as $parent => $rows ) {
			if ( ! isset( $depth[ (int) $parent ] ) ) {
				$depth[ (int) $parent ] = 0;
			}
			foreach ( (array) $rows as $row ) {
				$row = (array) $row;
				if ( 'artikel' === $row['child_type'] && ! isset( $depth[ (int) $row['child_id'] ] ) ) {
					$depth[ (int) $row['child_id'] ] = 0;
				}
			}
		}

		// So lange durchlaufen, bis sich nichts mehr ändert. Bei einem
		// Zirkelbezug bricht die Tiefenbegrenzung die Schleife ab.
		for ( $round = 0; $round <= self::MAX_DEPTH; $round++ ) {
			$changed = false;

			foreach ( $bom as $parent => $rows ) {
				$parent_depth = $depth[ (int) $parent ];

				foreach ( (array) $rows as $row ) {
					$row = (array) $row;
					if ( 'artikel' !== $row['child_type'] ) {
						continue;
					}
					$child = (int) $row['child_id'];
					if ( $depth[ $child ] < $parent_depth + 1 ) {
						$depth[ $child ] = $parent_depth + 1;
						$changed         = true;
					}
				}
			}

			if ( ! $changed ) {
				break;
			}
		}

		return $depth;
	}

	/**
	 * Bedarf über alle Stufen durchrechnen – reine Logik.
	 *
	 * @param array $demands Bedarfe: article_id, qty, source, label.
	 * @param array $ctx     Kontext:
	 *                       articles  => article_id => Artikelstamm,
	 *                       bom       => article_id => Stücklistenzeilen,
	 *                       stock     => article_id => verfügbarer Bestand,
	 *                       planned   => article_id => bereits in Arbeit,
	 *                       components=> component_id => Stammsatz,
	 *                       component_stock => component_id => Bestand.
	 * @return array {articles, components, warnings}
	 */
	public static function plan( array $demands, array $ctx ) {
		$articles   = isset( $ctx['articles'] ) ? (array) $ctx['articles'] : array();
		$bom        = isset( $ctx['bom'] ) ? (array) $ctx['bom'] : array();
		$stock      = isset( $ctx['stock'] ) ? (array) $ctx['stock'] : array();
		$planned    = isset( $ctx['planned'] ) ? (array) $ctx['planned'] : array();
		$warnings   = array();

		$depth = self::depths( $bom );

		// Bruttobedarf je Artikel sammeln, Herkunft mitführen.
		$gross   = array();
		$sources = array();

		$add = static function ( $article_id, $qty, $source, $label ) use ( &$gross, &$sources ) {
			$article_id = (int) $article_id;
			$qty        = (float) $qty;

			if ( ! $article_id || $qty <= 0 ) {
				return;
			}

			if ( ! isset( $gross[ $article_id ] ) ) {
				$gross[ $article_id ]   = 0.0;
				$sources[ $article_id ] = array();
			}

			$gross[ $article_id ] += $qty;

			if ( ! isset( $sources[ $article_id ][ $source ] ) ) {
				$sources[ $article_id ][ $source ] = array(
					'qty'    => 0.0,
					'labels' => array(),
				);
			}
			$sources[ $article_id ][ $source ]['qty'] += $qty;

			if ( '' !== (string) $label && ! in_array( $label, $sources[ $article_id ][ $source ]['labels'], true ) ) {
				$sources[ $article_id ][ $source ]['labels'][] = $label;
			}
		};

		foreach ( $demands as $demand ) {
			$demand = (array) $demand;
			$add(
				isset( $demand['article_id'] ) ? $demand['article_id'] : 0,
				isset( $demand['qty'] ) ? $demand['qty'] : 0,
				isset( $demand['source'] ) ? (string) $demand['source'] : 'bestellung',
				isset( $demand['label'] ) ? (string) $demand['label'] : ''
			);
		}

		// Artikel nach Fertigungstiefe abarbeiten: Erst die Endprodukte, dann
		// das, was in ihnen steckt.
		$queue = array_keys( $gross );
		$done  = array();
		$rows  = array();
		$needed_components = array();

		for ( $pass = 0; $pass <= self::MAX_DEPTH + 1 && $queue; $pass++ ) {
			// Aus der Warteschlange die flachste Stufe herausnehmen.
			$level = null;
			foreach ( $queue as $article_id ) {
				$own = isset( $depth[ $article_id ] ) ? $depth[ $article_id ] : 0;
				if ( null === $level || $own < $level ) {
					$level = $own;
				}
			}

			$batch = array();
			foreach ( $queue as $index => $article_id ) {
				if ( ( isset( $depth[ $article_id ] ) ? $depth[ $article_id ] : 0 ) === $level ) {
					$batch[] = $article_id;
					unset( $queue[ $index ] );
				}
			}
			$queue = array_values( $queue );

			foreach ( $batch as $article_id ) {
				if ( isset( $done[ $article_id ] ) ) {
					continue;
				}
				$done[ $article_id ] = true;

				$article = isset( $articles[ $article_id ] ) ? (array) $articles[ $article_id ] : array();

				if ( ! $article ) {
					$warnings[] = sprintf(
						/* translators: %d: Artikel-ID. */
						__( 'Artikel %d steht in einer Stückliste, ist aber nicht im Stamm.', 'lindenweg16' ),
						$article_id
					);
					continue;
				}

				$have    = isset( $stock[ $article_id ] ) ? (float) $stock[ $article_id ] : 0.0;
				$in_work = isset( $planned[ $article_id ] ) ? (float) $planned[ $article_id ] : 0.0;
				$need    = max( 0.0, $gross[ $article_id ] - $have - $in_work );

				$rows[] = array(
					'article_id'    => $article_id,
					'sku'           => isset( $article['sku'] ) ? (string) $article['sku'] : '',
					'name'          => isset( $article['name'] ) ? (string) $article['name'] : '',
					'sourcing_type' => isset( $article['sourcing_type'] ) ? (string) $article['sourcing_type'] : 'druck',
					'action'        => self::action_for( isset( $article['sourcing_type'] ) ? $article['sourcing_type'] : 'druck' ),
					'depth'         => isset( $depth[ $article_id ] ) ? (int) $depth[ $article_id ] : 0,
					'gross'         => round( $gross[ $article_id ], 3 ),
					'stock'         => round( $have, 3 ),
					'in_work'       => round( $in_work, 3 ),
					'net'           => round( $need, 3 ),
					'sources'       => $sources[ $article_id ],
				);

				// Nur was tatsächlich gefertigt werden muss, erzeugt weiter
				// unten Bedarf. Was aus dem Regal kommt, verbraucht nichts.
				if ( $need <= 0 ) {
					continue;
				}

				$children = isset( $bom[ $article_id ] ) ? (array) $bom[ $article_id ] : array();

				foreach ( $children as $child ) {
					$child = (array) $child;
					$qty   = (float) $child['qty'] * $need;

					if ( 'komponente' === $child['child_type'] ) {
						$cid = (int) $child['child_id'];
						if ( ! isset( $needed_components[ $cid ] ) ) {
							$needed_components[ $cid ] = 0.0;
						}
						$needed_components[ $cid ] += $qty;
						continue;
					}

					$cid = (int) $child['child_id'];

					if ( isset( $done[ $cid ] ) ) {
						$warnings[] = sprintf(
							/* translators: %s: Artikelnummer. */
							__( '%s wurde bereits aufgelöst, bevor sein voller Bedarf feststand – bitte die Stücklistentiefe prüfen.', 'lindenweg16' ),
							isset( $articles[ $cid ]['sku'] ) ? $articles[ $cid ]['sku'] : $cid
						);
						continue;
					}

					$add( $cid, $qty, 'stueckliste', isset( $article['sku'] ) ? (string) $article['sku'] : '' );

					if ( ! in_array( $cid, $queue, true ) ) {
						$queue[] = $cid;
					}
				}
			}
		}

		// Kleinteile gegen ihren Bestand rechnen.
		$components       = isset( $ctx['components'] ) ? (array) $ctx['components'] : array();
		$component_stock  = isset( $ctx['component_stock'] ) ? (array) $ctx['component_stock'] : array();
		$component_rows   = array();

		foreach ( $needed_components as $cid => $qty ) {
			$component = isset( $components[ $cid ] ) ? (array) $components[ $cid ] : array();
			$have      = isset( $component_stock[ $cid ] ) ? (float) $component_stock[ $cid ] : 0.0;

			$component_rows[] = array(
				'component_id' => (int) $cid,
				'code'         => isset( $component['code'] ) ? (string) $component['code'] : '',
				'name'         => isset( $component['name'] ) ? (string) $component['name'] : '',
				'gross'        => round( $qty, 3 ),
				'stock'        => round( $have, 3 ),
				'net'          => round( max( 0.0, $qty - $have ), 3 ),
			);
		}

		// Ausgabe nach Handlung und Tiefe sortieren: Was am tiefsten liegt,
		// muss zuerst entstehen.
		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['depth'] === $b['depth'] ) {
					return strcmp( $a['sku'], $b['sku'] );
				}
				return $b['depth'] <=> $a['depth'];
			}
		);

		usort(
			$component_rows,
			static function ( $a, $b ) {
				return $b['net'] <=> $a['net'];
			}
		);

		return array(
			'articles'   => $rows,
			'components' => $component_rows,
			'warnings'   => $warnings,
		);
	}

	/**
	 * Welche Handlung folgt aus dem Beschaffungsweg?
	 *
	 * @param string $sourcing Beschaffungsweg.
	 * @return string drucken, montieren oder bestellen.
	 */
	public static function action_for( $sourcing ) {
		if ( 'druck' === $sourcing ) {
			return 'drucken';
		}

		if ( in_array( $sourcing, array( 'montage', 'hybrid' ), true ) ) {
			return 'montieren';
		}

		return 'bestellen';
	}

	/**
	 * Bezeichnungen der Handlungen.
	 *
	 * @return array
	 */
	public static function action_labels() {
		return array(
			'drucken'   => __( 'drucken', 'lindenweg16' ),
			'montieren' => __( 'montieren', 'lindenweg16' ),
			'bestellen' => __( 'bestellen', 'lindenweg16' ),
		);
	}

	/**
	 * Bedarfe aus dem laufenden System sammeln.
	 *
	 * Getrennt von plan() gehalten, weil hier die Datenbank ins Spiel kommt
	 * und die Rechnung darüber prüfbar bleiben soll.
	 *
	 * @param bool $with_soft Wunsch- und Warteliste einbeziehen.
	 * @return array
	 */
	public static function collect( $with_soft = true ) {
		$demands = array();

		// Ausgelaufene Artikel erzeugen keinen Bedarf mehr - ausser aus
		// einer Bestellung. Wer vor der Stilllegung bestellt hat, bekommt
		// seine Ware; auf Vorrat wird dafuer nichts mehr gebaut.
		$aktiv = array();
		foreach ( ArticleRepository::all() as $article ) {
			$aktiv[ (int) $article['id'] ] = ArticleRepository::is_active( $article );
		}

		$noch_aktiv = static function ( $article_id ) use ( $aktiv ) {
			return ! isset( $aktiv[ (int) $article_id ] ) || $aktiv[ (int) $article_id ];
		};

		// 1. Offene Bestellungen – das Verbindlichste, was es gibt.
		foreach ( WooIntegration::open_order_lines() as $line ) {
			$demands[] = array(
				'article_id' => (int) $line['article_id'],
				'qty'        => (float) $line['qty'],
				'source'     => 'bestellung',
				'label'      => '#' . (int) $line['wc_order_id'],
			);
		}

		// 2. Unterdeckung gegenüber dem Sollbestand.
		$balances = StockLedger::balances( StockRepository::all() );

		foreach ( StockRepository::targets() as $target ) {
			$have = StockLedger::pick( $balances, (int) $target['article_id'], (string) $target['color'] );
			$gap  = (float) $target['qty'] - $have['lager'];

			if ( $gap > 0 && $noch_aktiv( $target['article_id'] ) ) {
				$demands[] = array(
					'article_id' => (int) $target['article_id'],
					'qty'        => $gap,
					'source'     => 'sollbestand',
					'label'      => (string) $target['color'],
				);
			}
		}

		if ( ! $with_soft ) {
			return $demands;
		}

		// 3. Wunschliste – ein Hinweis, keine Bestellung. Sie wird getrennt
		// ausgewiesen, damit niemand auf Verdacht die Werkstatt vollstellt.
		foreach ( self::wishlist_demand() as $article_id => $count ) {
			if ( ! $noch_aktiv( $article_id ) ) {
				continue;
			}

			$demands[] = array(
				'article_id' => (int) $article_id,
				'qty'        => (float) $count,
				'source'     => 'wunschliste',
				'label'      => sprintf(
					/* translators: %d: Anzahl Merkungen. */
					__( '%d Merkungen', 'lindenweg16' ),
					(int) $count
				),
			);
		}

		return $demands;
	}

	/**
	 * Wunschlisteneinträge auf Betrieb-Artikel abbilden.
	 *
	 * Die Wunschliste kennt WooCommerce-Produkte; das Modul Betrieb kennt
	 * Artikel. Was sich nicht zuordnen lässt, wird stillschweigend
	 * übergangen – ein gemerktes Produkt ohne eigenen Artikel wird hier
	 * nicht gefertigt.
	 *
	 * @return array article_id => Anzahl.
	 */
	protected static function wishlist_demand() {
		if ( ! class_exists( 'Lindenweg16\\Modules\\Retention\\Wishlist' ) ) {
			return array();
		}

		$out = array();

		foreach ( \Lindenweg16\Modules\Retention\Wishlist::most_wished( 30 ) as $product_id => $count ) {
			$article_id = StockOwnership::article_for_product( (int) $product_id );

			if ( ! $article_id ) {
				continue;
			}

			if ( ! isset( $out[ $article_id ] ) ) {
				$out[ $article_id ] = 0;
			}
			$out[ $article_id ] += (int) $count;
		}

		return $out;
	}

	/**
	 * Kontext für plan() aus der Datenbank aufbauen.
	 *
	 * @return array
	 */
	public static function context() {
		$balances = StockLedger::balances( StockRepository::all() );

		$stock = array();
		foreach ( $balances as $article_id => $colors ) {
			$sum = 0.0;
			foreach ( $colors as $locations ) {
				$sum += isset( $locations['lager'] ) ? (float) $locations['lager'] : 0.0;
			}
			$stock[ (int) $article_id ] = $sum;
		}

		$components      = array();
		$component_stock = array();
		foreach ( ComponentRepository::all() as $component ) {
			$components[ (int) $component['id'] ]      = $component;
			$component_stock[ (int) $component['id'] ] = (float) $component['stock_qty'];
		}

		return array(
			'articles'        => ArticleRepository::all_by_id(),
			'bom'             => BomRepository::all_by_parent(),
			'stock'           => $stock,
			'planned'         => self::in_work(),
			'components'      => $components,
			'component_stock' => $component_stock,
		);
	}

	/**
	 * Mengen, die bereits in noch nicht abgeschlossenen Aufträgen stecken.
	 *
	 * Ohne diese Verrechnung entstünde bei jedem Aufruf ein neuer Vorschlag
	 * für dasselbe Teil, obwohl der Auftrag längst auf der Werkbank liegt.
	 *
	 * @return array article_id => Menge.
	 */
	public static function in_work() {
		$out = array();

		foreach ( WorkOrderRepository::unfinished() as $order ) {
			$id = (int) $order['article_id'];
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = 0.0;
			}
			$out[ $id ] += (float) $order['qty_planned'];
		}

		// Platten aus dem ERP-Workflow stecken ebenfalls schon in Arbeit.
		foreach ( \Lindenweg16\Erp\RunRepository::in_work() as $id => $qty ) {
			$out[ (int) $id ] = ( isset( $out[ (int) $id ] ) ? $out[ (int) $id ] : 0.0 ) + (float) $qty;
		}

		return $out;
	}

	/**
	 * Nur die Zeilen, bei denen tatsächlich etwas zu tun ist.
	 *
	 * @param array $rows Ergebniszeilen.
	 * @return array
	 */
	public static function actionable( array $rows ) {
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return $row['net'] > 0.0005;
				}
			)
		);
	}
}
