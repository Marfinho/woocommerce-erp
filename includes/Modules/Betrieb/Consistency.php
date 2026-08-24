<?php
/**
 * Systemprüfung: Widersprüche finden, die beim Speichern noch keine waren.
 *
 * Jedes Formular prüft seine eigene Eingabe – ob Pflichtfelder da sind,
 * ob ein Verweis existiert, ob eine Stückliste einen Kreis erzeugt. Das
 * fängt den Moment ab, in dem etwas entsteht.
 *
 * Es fängt nicht ab, was danach passiert. Ein Artikel war gedruckt und
 * hatte eine Revision; jemand stellt ihn auf Montage um. Ein Shop-Produkt
 * wird gelöscht, die Verknüpfung bleibt. Beide Datensätze sind für sich
 * gültig – zusammen ergeben sie keinen Sinn mehr. Genau das sucht diese
 * Prüfung.
 *
 * check() ist reine Logik: Zustand rein, Befunde raus. Das Einsammeln des
 * Zustands steht getrennt in collect(), damit sich die Regeln prüfen
 * lassen, ohne eine Datenbank zu stellen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Regeln, Befunde und das Einsammeln des Zustands.
 */
class Consistency {

	/**
	 * Ein Befund.
	 *
	 * @param string $level  fehler oder warnung.
	 * @param string $area   Bereich für die Gruppierung.
	 * @param string $text   Was nicht zusammenpasst.
	 * @param string $fix    Was zu tun ist.
	 * @return array
	 */
	protected static function finding( $level, $area, $text, $fix ) {
		return array(
			'level' => $level,
			'area'  => $area,
			'text'  => $text,
			'fix'   => $fix,
		);
	}

	/**
	 * Zustand aus der Datenbank einsammeln.
	 *
	 * Getrennt von check(), damit die Regeln ohne Datenbank prüfbar sind.
	 *
	 * @return array
	 */
	public static function collect() {
		global $wpdb;

		$p        = $wpdb->prefix . 'lw16_';
		$articles = ArticleRepository::all_by_id();

		// Stücklisten: nur zählen, der Inhalt spielt hier keine Rolle.
		$bom_parents = array();
		foreach ( (array) $wpdb->get_results( "SELECT parent_article_id, COUNT(*) AS n FROM {$p}bom GROUP BY parent_article_id", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$bom_parents[ (int) $row['parent_article_id'] ] = (int) $row['n'];
		}

		$revisions = array();
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT article_id FROM {$p}article_revisions WHERE is_active = 1" ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$revisions[ (int) $id ] = true;
		}

		// Verknüpfungen ins Leere: Ein Produkt, das es nicht mehr gibt,
		// nimmt beim Verkauf keinen Bestand mit.
		$wc_missing = array();
		foreach ( $articles as $id => $article ) {
			$product = isset( $article['wc_product_id'] ) ? (int) $article['wc_product_id'] : 0;

			if ( ! $product ) {
				continue;
			}

			if ( ! function_exists( 'wc_get_product' ) || ! wc_get_product( $product ) ) {
				$wc_missing[ (int) $id ] = $product;
			}
		}

		// Geprüft wird der Bestand im eigenen Lager, nicht die Summe über
		// alle Orte: Kommissionsware ist aus dem Lager herausgebucht, ein
		// Minus dort wäre also echt und würde in einer Gesamtsumme
		// verschwinden.
		$stock    = array();
		$balances = StockLedger::balances( StockRepository::all() );

		foreach ( $balances as $article_id => $colors ) {
			$summe = 0.0;

			foreach ( array_keys( (array) $colors ) as $color ) {
				$summe += StockLedger::pick( $balances, $article_id, $color )['lager'];
			}

			$stock[ (int) $article_id ] = $summe;
		}

		$bins = array();
		foreach ( BinRepository::all() as $bin ) {
			$bins[ (int) $bin['id'] ] = ! empty( $bin['is_active'] );
		}

		// Welche verkauften Produkte kennt der Artikelstamm nicht? Genau die
		// bucht noch die alte Lagerverwaltung.
		$bekannt = array();
		foreach ( $articles as $article ) {
			if ( ! empty( $article['wc_product_id'] ) ) {
				$bekannt[ (int) $article['wc_product_id'] ] = true;
			}
		}

		$legacy = array();

		if ( function_exists( 'wc_get_products' ) ) {
			$produkte = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => 200,
					'type'   => array( 'simple', 'variable' ),
				)
			);

			foreach ( (array) $produkte as $produkt ) {
				if ( ! is_object( $produkt ) || ! method_exists( $produkt, 'get_id' ) ) {
					continue;
				}

				// Nur was tatsaechlich ein Lager braucht.
				if ( method_exists( $produkt, 'is_virtual' ) && $produkt->is_virtual() ) {
					continue;
				}

				$id = (int) $produkt->get_id();

				if ( isset( $bekannt[ $id ] ) ) {
					continue;
				}

				$legacy[] = array(
					'id'   => $id,
					'name' => method_exists( $produkt, 'get_name' ) ? (string) $produkt->get_name() : '',
				);
			}
		}

		return array(
			'articles'    => $articles,
			'legacy_products' => $legacy,
			'bom_parents' => $bom_parents,
			'revisions'   => $revisions,
			'wc_missing'  => $wc_missing,
			'stock'       => $stock,
			'targets'     => StockRepository::targets(),
			'bins'        => $bins,
			'open_orders' => WorkOrderRepository::unfinished(),
		);
	}

	/**
	 * Befunde nach Schwere zählen.
	 *
	 * @param array $findings Befunde.
	 * @return array {fehler, warnung}
	 */
	public static function summarize( array $findings ) {
		$summe = array(
			'fehler'  => 0,
			'warnung' => 0,
		);

		foreach ( $findings as $finding ) {
			$level = isset( $finding['level'] ) ? $finding['level'] : 'warnung';

			if ( isset( $summe[ $level ] ) ) {
				$summe[ $level ]++;
			}
		}

		return $summe;
	}

	/**
	 * Alle Regeln auf einen Zustand anwenden.
	 *
	 * @param array $ctx {
	 *     @type array $articles     article_id => Stammsatz.
	 *     @type array $bom_parents  article_id => Anzahl Stücklistenzeilen.
	 *     @type array $revisions    article_id => true, wenn aktive Revision.
	 *     @type array $wc_missing   article_id => Produkt-ID, die es nicht mehr gibt.
	 *     @type array $stock        article_id => Gesamtbestand.
	 *     @type array $targets      Zeilen mit article_id.
	 *     @type array $bins         bin_id => aktiv (bool).
	 *     @type array $open_orders  Offene Aufträge mit article_id und kind.
	 * }
	 * @return array Befunde.
	 */
	public static function check( array $ctx ) {
		$articles = isset( $ctx['articles'] ) ? (array) $ctx['articles'] : array();

		$findings = array_merge(
			self::check_makeable( $articles, isset( $ctx['bom_parents'] ) ? (array) $ctx['bom_parents'] : array() ),
			self::check_printable( $articles, isset( $ctx['revisions'] ) ? (array) $ctx['revisions'] : array() ),
			self::check_shop_links( $articles, isset( $ctx['wc_missing'] ) ? (array) $ctx['wc_missing'] : array() ),
			self::check_stock( $articles, isset( $ctx['stock'] ) ? (array) $ctx['stock'] : array() ),
			self::check_targets( $articles, isset( $ctx['targets'] ) ? (array) $ctx['targets'] : array() ),
			self::check_bins( $articles, isset( $ctx['bins'] ) ? (array) $ctx['bins'] : array() ),
			self::check_open_orders( $articles, isset( $ctx['open_orders'] ) ? (array) $ctx['open_orders'] : array() ),
			self::check_legacy_stock( isset( $ctx['legacy_products'] ) ? (array) $ctx['legacy_products'] : array() )
		);

		// Fehler zuerst: Was einen Vorgang blockiert, gehört nach oben.
		usort(
			$findings,
			static function ( $a, $b ) {
				if ( $a['level'] !== $b['level'] ) {
					return 'fehler' === $a['level'] ? -1 : 1;
				}

				return strcmp( $a['area'] . $a['text'], $b['area'] . $b['text'] );
			}
		);

		return $findings;
	}

	/**
	 * Artikelnummer für eine Meldung.
	 *
	 * @param array $article Artikel.
	 * @return string
	 */
	protected static function label( array $article ) {
		$sku  = isset( $article['sku'] ) ? (string) $article['sku'] : '';
		$name = isset( $article['name'] ) ? (string) $article['name'] : '';

		return trim( $sku . ( $name ? ' – ' . $name : '' ) );
	}

	/**
	 * Montageartikel ohne Stückliste.
	 *
	 * @param array $articles    Artikel.
	 * @param array $bom_parents article_id => Anzahl Zeilen.
	 * @return array
	 */
	public static function check_makeable( array $articles, array $bom_parents ) {
		$out = array();

		foreach ( $articles as $id => $article ) {
			if ( ! ArticleRepository::is_active( $article ) ) {
				continue;
			}

			if ( 'montage' !== ( isset( $article['sourcing_type'] ) ? $article['sourcing_type'] : '' ) ) {
				continue;
			}

			if ( empty( $bom_parents[ (int) $id ] ) ) {
				$out[] = self::finding(
					'fehler',
					__( 'Artikel', 'lindenweg16' ),
					sprintf(
						/* translators: %s: Artikel. */
						__( '%s soll montiert werden, hat aber keine Stückliste.', 'lindenweg16' ),
						self::label( $article )
					),
					__( 'Stückliste anlegen oder den Beschaffungsweg ändern – sonst lässt sich kein Fertigungsauftrag anlegen.', 'lindenweg16' )
				);
			}
		}

		return $out;
	}

	/**
	 * Gedruckte Artikel ohne freigegebene Revision.
	 *
	 * @param array $articles  Artikel.
	 * @param array $revisions article_id => true.
	 * @return array
	 */
	public static function check_printable( array $articles, array $revisions ) {
		$out = array();

		foreach ( $articles as $id => $article ) {
			if ( ! ArticleRepository::is_active( $article ) ) {
				continue;
			}

			$type = isset( $article['sourcing_type'] ) ? (string) $article['sourcing_type'] : '';

			if ( ! in_array( $type, array( 'druck', 'hybrid' ), true ) ) {
				continue;
			}

			if ( empty( $revisions[ (int) $id ] ) ) {
				$out[] = self::finding(
					'fehler',
					__( 'Artikel', 'lindenweg16' ),
					sprintf(
						/* translators: %s: Artikel. */
						__( '%s wird gedruckt, hat aber keine freigegebene Revision.', 'lindenweg16' ),
						self::label( $article )
					),
					__( 'Revision anlegen und aktiv setzen – ohne Bauteilgewicht gibt es weder Kalkulation noch Druckauftrag.', 'lindenweg16' )
				);
			}
		}

		return $out;
	}

	/**
	 * Verknüpfung zum Shop: fehlend, doppelt oder ins Leere.
	 *
	 * @param array $articles   Artikel.
	 * @param array $wc_missing article_id => Produkt-ID ohne Produkt.
	 * @return array
	 */
	public static function check_shop_links( array $articles, array $wc_missing ) {
		$out    = array();
		$belegt = array();

		foreach ( $articles as $id => $article ) {
			if ( ! ArticleRepository::is_active( $article ) ) {
				continue;
			}

			$id      = (int) $id;
			$product = isset( $article['wc_product_id'] ) ? (int) $article['wc_product_id'] : 0;

			if ( ! empty( $article['sellable'] ) && ! $product ) {
				$out[] = self::finding(
					'fehler',
					__( 'Shop', 'lindenweg16' ),
					sprintf(
						/* translators: %s: Artikel. */
						__( '%s ist verkäuflich, aber mit keinem Shop-Produkt verknüpft.', 'lindenweg16' ),
						self::label( $article )
					),
					__( 'Ohne Verknüpfung entsteht beim Verkauf kein Lagerabgang – der Bestand läuft still auseinander.', 'lindenweg16' )
				);
			}

			if ( $product && isset( $wc_missing[ $id ] ) ) {
				$out[] = self::finding(
					'fehler',
					__( 'Shop', 'lindenweg16' ),
					sprintf(
						/* translators: 1: Artikel, 2: Produkt-ID. */
						__( '%1$s zeigt auf Shop-Produkt %2$d, das es nicht mehr gibt.', 'lindenweg16' ),
						self::label( $article ),
						(int) $wc_missing[ $id ]
					),
					__( 'Neu verknüpfen oder die Verknüpfung entfernen.', 'lindenweg16' )
				);
			}

			if ( $product ) {
				if ( isset( $belegt[ $product ] ) ) {
					$out[] = self::finding(
						'fehler',
						__( 'Shop', 'lindenweg16' ),
						sprintf(
							/* translators: 1: Produkt-ID, 2: erster Artikel, 3: zweiter Artikel. */
							__( 'Shop-Produkt %1$d ist mit zwei Artikeln verknüpft: %2$s und %3$s.', 'lindenweg16' ),
							$product,
							$belegt[ $product ],
							self::label( $article )
						),
						__( 'Ein Verkauf würde von beiden abbuchen. Eine der beiden Verknüpfungen lösen.', 'lindenweg16' )
					);
				} else {
					$belegt[ $product ] = self::label( $article );
				}
			}
		}

		return $out;
	}

	/**
	 * Negativer Bestand.
	 *
	 * @param array $articles Artikel.
	 * @param array $stock    article_id => Menge.
	 * @return array
	 */
	public static function check_stock( array $articles, array $stock ) {
		$out = array();

		foreach ( $stock as $id => $qty ) {
			if ( (float) $qty >= -0.0005 ) {
				continue;
			}

			$article = isset( $articles[ (int) $id ] ) ? (array) $articles[ (int) $id ] : array();

			$out[] = self::finding(
				'fehler',
				__( 'Lager', 'lindenweg16' ),
				sprintf(
					/* translators: 1: Artikel, 2: Menge. */
					__( '%1$s hat einen Bestand von %2$s – weniger als nichts.', 'lindenweg16' ),
					$article ? self::label( $article ) : '#' . (int) $id,
					number_format_i18n( (float) $qty, 0 )
				),
				__( 'Es fehlt ein Zugang: ein nicht erfasster Druckjob, ein vergessener Wareneingang. Such die fehlende Bewegung, statt den Bestand zu überschreiben.', 'lindenweg16' )
			);
		}

		return $out;
	}

	/**
	 * Sollbestand für einen stillgelegten Artikel.
	 *
	 * @param array $articles Artikel.
	 * @param array $targets  Zeilen mit article_id.
	 * @return array
	 */
	public static function check_targets( array $articles, array $targets ) {
		$out = array();

		foreach ( $targets as $target ) {
			$id      = isset( $target['article_id'] ) ? (int) $target['article_id'] : 0;
			$article = isset( $articles[ $id ] ) ? (array) $articles[ $id ] : array();

			if ( ! $article || ArticleRepository::is_active( $article ) ) {
				continue;
			}

			$out[] = self::finding(
				'warnung',
				__( 'Lager', 'lindenweg16' ),
				sprintf(
					/* translators: %s: Artikel. */
					__( '%s ist stillgelegt, hat aber noch einen Sollbestand.', 'lindenweg16' ),
					self::label( $article )
				),
				__( 'Der Sollbestand erzeugt keinen Bedarf mehr – er steht nur noch da. Entfernen unter Betrieb → Lager.', 'lindenweg16' )
			);
		}

		return $out;
	}

	/**
	 * Standardplatz zeigt auf einen stillgelegten Lagerplatz.
	 *
	 * @param array $articles Artikel.
	 * @param array $bins     bin_id => aktiv.
	 * @return array
	 */
	public static function check_bins( array $articles, array $bins ) {
		$out = array();

		foreach ( $articles as $article ) {
			$bin = isset( $article['default_bin_id'] ) ? (int) $article['default_bin_id'] : 0;

			if ( ! $bin || ! array_key_exists( $bin, $bins ) ) {
				continue;
			}

			if ( $bins[ $bin ] ) {
				continue;
			}

			$out[] = self::finding(
				'warnung',
				__( 'Lagerplätze', 'lindenweg16' ),
				sprintf(
					/* translators: %s: Artikel. */
					__( '%s hat einen Standardplatz, der stillgelegt ist.', 'lindenweg16' ),
					self::label( $article )
				),
				__( 'Picklisten schlagen dann einen Platz vor, an dem niemand mehr sucht.', 'lindenweg16' )
			);
		}

		return $out;
	}

	/**
	 * Shop-Produkte, die noch an der alten Lagerverwaltung hängen.
	 *
	 * Es gibt zwei Lagerverwaltungen im Plugin: die ältere unter
	 * `Modules/Inventory` und das Modul Betrieb. Zuständig ist Betrieb für
	 * genau die Produkte, die auf einen Artikel zeigen; alles andere bucht
	 * weiterhin die alte. Deren Oberfläche ist längst aus dem Menü
	 * verschwunden – die Buchungen laufen aber weiter, unsichtbar.
	 *
	 * Diese Liste ist deshalb das Maß dafür, wie weit der Umzug ist. Ist
	 * sie leer, bucht nur noch eine Verwaltung, und die alte kann
	 * ausgebaut werden.
	 *
	 * @param array $products Produkte ohne Betrieb-Artikel: {id, name}.
	 * @return array
	 */
	public static function check_legacy_stock( array $products ) {
		if ( ! $products ) {
			return array();
		}

		$namen = array();

		foreach ( array_slice( $products, 0, 5 ) as $product ) {
			$namen[] = isset( $product['name'] ) && '' !== $product['name']
				? (string) $product['name']
				: '#' . ( isset( $product['id'] ) ? (int) $product['id'] : 0 );
		}

		$liste = implode( ', ', $namen );

		if ( count( $products ) > 5 ) {
			/* translators: 1: Namen, 2: Anzahl weiterer. */
			$liste = sprintf( __( '%1$s und %2$d weitere', 'lindenweg16' ), $liste, count( $products ) - 5 );
		}

		return array(
			self::finding(
				'warnung',
				__( 'Lagerhoheit', 'lindenweg16' ),
				sprintf(
					/* translators: 1: Anzahl, 2: Namen. */
					__( '%1$d Shop-Produkte werden noch von der alten Lagerverwaltung gebucht: %2$s.', 'lindenweg16' ),
					count( $products ),
					$liste
				),
				__( 'Solange hier etwas steht, führt das Plugin zwei Lager parallel. Diese Produkte unter Betrieb → Artikel & Revisionen mit einem Artikel verknüpfen oder unter Import & Doku übernehmen – dann bucht nur noch eine Stelle.', 'lindenweg16' )
			),
		);
	}

	/**
	 * Offener Auftrag, dessen Artikel inzwischen anders beschafft wird.
	 *
	 * @param array $articles Artikel.
	 * @param array $orders   Offene Aufträge.
	 * @return array
	 */
	public static function check_open_orders( array $articles, array $orders ) {
		$out = array();

		foreach ( $orders as $order ) {
			$id      = isset( $order['article_id'] ) ? (int) $order['article_id'] : 0;
			$kind    = isset( $order['kind'] ) ? (string) $order['kind'] : 'montage';
			$article = isset( $articles[ $id ] ) ? (array) $articles[ $id ] : array();

			if ( ! $article ) {
				continue;
			}

			if ( WorkOrderRepository::fits_kind( $article, $kind ) ) {
				continue;
			}

			$out[] = self::finding(
				'warnung',
				__( 'Aufträge', 'lindenweg16' ),
				sprintf(
					/* translators: 1: Auftragscode, 2: Artikel. */
					__( 'Auftrag %1$s passt nicht mehr zu %2$s – der Beschaffungsweg wurde seit dem Anlegen geändert.', 'lindenweg16' ),
					isset( $order['code'] ) ? (string) $order['code'] : '?',
					self::label( $article )
				),
				__( 'Auftrag stornieren und neu anlegen, oder den Beschaffungsweg zurückstellen.', 'lindenweg16' )
			);
		}

		return $out;
	}
}
