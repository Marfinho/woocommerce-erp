<?php
/**
 * Verbrauch von Kleinteilen und Verbrauchsmaterial.
 *
 * Der Verbrauch wird nicht geschätzt, sondern gebucht: Bei jedem Druckjob und
 * bei jeder abgeschlossenen Bestellung wird die Stückliste aufgelöst und der
 * Komponentenbestand entsprechend reduziert. Verkaufst du ein Set mit Stange,
 * gehen automatisch die Dübel, Schrauben und der Bit vom Bestand ab.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Bucht Komponenten- und Verpackungsverbrauch.
 */
class ComponentConsumption {

	/**
	 * Benötigte Komponenten einer Menge eines Artikels – reine Logik.
	 *
	 * @param int   $article_id Artikel.
	 * @param float $qty        Menge.
	 * @param array $ctx        Kontext mit 'bom'.
	 * @return array component_id => Menge.
	 */
	public static function required( $article_id, $qty, array $ctx ) {
		$result = BomResolver::resolve( $article_id, $ctx, (float) $qty );
		$needed = array();

		foreach ( $result['lines'] as $line ) {
			if ( 'komponente' !== $line['child_type'] ) {
				continue;
			}
			$id = (int) $line['child_id'];
			if ( ! isset( $needed[ $id ] ) ) {
				$needed[ $id ] = 0.0;
			}
			$needed[ $id ] += (float) $line['qty'];
		}

		return $needed;
	}

	/**
	 * Komponenten eines Fertigungsvorgangs abbuchen.
	 *
	 * Nur die direkte Stückliste des gefertigten Artikels: Unterbaugruppen
	 * werden als eigene Druckjobs gefertigt und buchen dort ihre eigenen
	 * Kleinteile ab. Sonst würde doppelt verbraucht.
	 *
	 * @param int    $article_id Artikel.
	 * @param float  $qty        Gefertigte Menge.
	 * @param string $ref_type   Herkunft.
	 * @param int    $ref_id     Herkunfts-ID.
	 * @return array Gebuchte Mengen.
	 */
	public static function consume_for_production( $article_id, $qty, $ref_type, $ref_id ) {
		if ( (float) $qty <= 0 ) {
			return array();
		}

		$direct = BomRepository::for_article( $article_id );
		$needed = array();

		foreach ( $direct as $row ) {
			if ( 'komponente' !== $row['child_type'] ) {
				continue;
			}
			$id = (int) $row['child_id'];
			if ( ! isset( $needed[ $id ] ) ) {
				$needed[ $id ] = 0.0;
			}
			$needed[ $id ] += (float) $row['qty'] * (float) $qty;
		}

		return self::book( $needed, $ref_type, $ref_id );
	}

	/**
	 * Kleinteile, die beim Verkauf abgehen – reine Logik.
	 *
	 * Entscheidend ist, wo ein Kleinteil verbaut wird, denn abgebucht werden
	 * darf es genau einmal:
	 *
	 * - Kleinteile unter einem gefertigten Artikel (druck, montage oder
	 *   hybrid) werden beim Fertigen verbaut und dort abgebucht – beim
	 *   Druckjob oder beim Fertigmelden des Fertigungsauftrags.
	 * - Kleinteile unter einem Zukaufartikel – die Dübel und Schrauben der
	 *   Stange – werden erst beim Packen beigelegt und deshalb hier abgebucht.
	 *
	 * Ohne diese Unterscheidung ginge jeder Gewindeeinsatz zweimal vom
	 * Bestand ab: einmal beim Drucken und noch einmal beim Verkaufen.
	 *
	 * @param int   $article_id Verkaufter Artikel.
	 * @param float $qty        Menge.
	 * @param array $ctx        Kontext mit 'bom' und 'articles'.
	 * @return array component_id => Menge.
	 */
	public static function components_at_sale( $article_id, $qty, array $ctx ) {
		$result = BomResolver::resolve( $article_id, $ctx, (float) $qty );
		$needed = array();

		foreach ( $result['lines'] as $line ) {
			if ( 'komponente' !== $line['child_type'] ) {
				continue;
			}

			$path   = $line['path'];
			$parent = (int) end( $path );

			if ( self::is_produced( $parent, $ctx ) ) {
				continue;
			}

			$id = (int) $line['child_id'];
			if ( ! isset( $needed[ $id ] ) ) {
				$needed[ $id ] = 0.0;
			}
			$needed[ $id ] += (float) $line['qty'];
		}

		return $needed;
	}

	/**
	 * Unterbaugruppen, die beim Verkauf vom Bestand abgehen – reine Logik.
	 *
	 * Gedruckte Unterbaugruppen sind eigene Artikel mit eigenem Bestand: Der
	 * Druckjob bucht sie zu, der Einbau muss sie wieder abbuchen. Es gilt
	 * dieselbe Regel wie bei den Kleinteilen – eingebaut wird beim Fertigen
	 * des Elternteils, und wenn das Elternteil ein Zukaufartikel ist, erst
	 * beim Packen.
	 *
	 * Ohne diese Buchung wüchse der Bestand an Abstandshaltern und Wandankern
	 * unbegrenzt, obwohl sie längst in ausgelieferten Stangen stecken.
	 *
	 * @param int   $article_id Verkaufter Artikel.
	 * @param float $qty        Menge.
	 * @param array $ctx        Kontext mit 'bom' und 'articles'.
	 * @return array article_id => Menge.
	 */
	public static function assemblies_at_sale( $article_id, $qty, array $ctx ) {
		$result = BomResolver::resolve( $article_id, $ctx, (float) $qty );
		$needed = array();

		foreach ( $result['lines'] as $line ) {
			if ( 'artikel' !== $line['child_type'] ) {
				continue;
			}

			$path   = $line['path'];
			$parent = (int) end( $path );

			if ( self::is_produced( $parent, $ctx ) ) {
				continue;
			}

			$id = (int) $line['child_id'];
			if ( ! isset( $needed[ $id ] ) ) {
				$needed[ $id ] = 0.0;
			}
			$needed[ $id ] += (float) $line['qty'];
		}

		return $needed;
	}

	/**
	 * Direkte Unterbaugruppen eines gefertigten Artikels – reine Logik.
	 *
	 * Sie werden beim Nachbearbeiten des Druckjobs eingebaut.
	 *
	 * @param array $rows Direkte Stücklistenzeilen.
	 * @param float $qty  Gefertigte Menge.
	 * @return array article_id => Menge.
	 */
	public static function assemblies_at_production( array $rows, $qty ) {
		$needed = array();

		foreach ( $rows as $row ) {
			$row = (array) $row;

			if ( 'artikel' !== $row['child_type'] ) {
				continue;
			}

			$id = (int) $row['child_id'];
			if ( ! isset( $needed[ $id ] ) ) {
				$needed[ $id ] = 0.0;
			}
			$needed[ $id ] += (float) $row['qty'] * (float) $qty;
		}

		return $needed;
	}

	/**
	 * Unterbaugruppen als Lagerabgänge buchen.
	 *
	 * @param array  $needed   article_id => Menge.
	 * @param string $color    Farbe.
	 * @param string $ref_type Herkunft.
	 * @param int    $ref_id   Herkunfts-ID.
	 * @param float  $sign     -1 für Abgang, +1 für Rückbuchung.
	 * @return int Anzahl Buchungen.
	 */
	public static function book_assemblies( array $needed, $color, $ref_type, $ref_id, $sign = -1.0 ) {
		$count = 0;

		foreach ( $needed as $article_id => $qty ) {
			if ( $qty <= 0 ) {
				continue;
			}

			StockRepository::book(
				array(
					'article_id'    => (int) $article_id,
					'color'         => $color,
					'qty'           => (float) $sign * (float) $qty,
					'movement_type' => $sign < 0 ? 'produktion' : 'korrektur',
					'location'      => 'lager',
					'ref_type'      => $ref_type,
					'ref_id'        => (int) $ref_id,
					'note'          => __( 'Einbau als Unterbaugruppe', 'lindenweg16' ),
				)
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Wird dieser Artikel selbst gefertigt und bucht damit seine Kleinteile
	 * bereits beim Fertigen ab – statt erst beim Verkauf?
	 *
	 * Gefertigt heißt: Es gibt einen Vorgang, an dem der Artikel entsteht.
	 * Das ist der Druckjob beim Rohling und der Fertigungsauftrag beim
	 * Halbzeug. Nur beim Zukaufartikel gibt es keinen solchen Vorgang – dort
	 * wird alles erst beim Packen zusammengelegt.
	 *
	 * @param int   $article_id Artikel.
	 * @param array $ctx        Kontext mit 'articles'.
	 * @return bool
	 */
	public static function is_produced( $article_id, array $ctx ) {
		if ( ! isset( $ctx['articles'][ $article_id ] ) ) {
			return false;
		}

		$article = (array) $ctx['articles'][ $article_id ];
		$type    = isset( $article['sourcing_type'] ) ? (string) $article['sourcing_type'] : 'druck';

		return in_array( $type, array( 'druck', 'montage', 'hybrid' ), true );
	}

	/**
	 * Kleinteile einer verkauften Menge abbuchen.
	 *
	 * @param int    $article_id Artikel.
	 * @param float  $qty        Menge.
	 * @param string $ref_type   Herkunft.
	 * @param int    $ref_id     Herkunfts-ID.
	 * @return array
	 */
	public static function consume_for_sale( $article_id, $qty, $ref_type, $ref_id ) {
		if ( (float) $qty <= 0 ) {
			return array();
		}

		$ctx = array(
			'bom'      => BomRepository::all_by_parent(),
			'articles' => ArticleRepository::all_by_id(),
		);

		return self::book( self::components_at_sale( $article_id, $qty, $ctx ), $ref_type, $ref_id );
	}

	/**
	 * Verpackungsmaterial einer Bestellung pauschal abbuchen.
	 *
	 * @param int $order_op_id Bestellung.
	 * @return int Anzahl gebuchter Positionen.
	 */
	public static function consume_supplies( $order_op_id ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'lw16_supplies';
		$supplies = $wpdb->get_results( "SELECT * FROM {$table} WHERE consumption_per_order > 0", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count    = 0;

		foreach ( (array) $supplies as $supply ) {
			$before = $supply;
			$data   = array(
				'stock_qty'  => (float) $supply['stock_qty'] - (float) $supply['consumption_per_order'],
				'updated_at' => current_time( 'mysql', true ),
			);

			$wpdb->update( $table, $data, array( 'id' => (int) $supply['id'] ) );
			BetriebAudit::record(
				'update',
				'supplies',
				(int) $supply['id'],
				$before,
				$data,
				sprintf(
					/* translators: %d: Bestell-ID. */
					__( 'Verbrauch durch Bestellung %d', 'lindenweg16' ),
					(int) $order_op_id
				)
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Kleinteile eines stornierten Fertigungsvorgangs zurückbuchen.
	 *
	 * Die Menge wird aus der Stückliste neu abgeleitet. Hat sich die
	 * Stückliste seit dem Druckjob geändert, weicht die Rückbuchung davon ab –
	 * deshalb steht die Begründung im Protokoll.
	 *
	 * @param int    $article_id Artikel.
	 * @param float  $qty        Gefertigte Menge.
	 * @param string $ref_type   Herkunft.
	 * @param int    $ref_id     Herkunfts-ID.
	 * @return array
	 */
	public static function restore_for_production( $article_id, $qty, $ref_type, $ref_id ) {
		if ( (float) $qty <= 0 ) {
			return array();
		}

		$needed = array();

		foreach ( BomRepository::for_article( $article_id ) as $row ) {
			if ( 'komponente' !== $row['child_type'] ) {
				continue;
			}
			$id = (int) $row['child_id'];
			if ( ! isset( $needed[ $id ] ) ) {
				$needed[ $id ] = 0.0;
			}
			// Negativ, damit die Kleinteile dem Bestand wieder zugehen.
			$needed[ $id ] -= (float) $row['qty'] * (float) $qty;
		}

		return self::book( $needed, $ref_type, $ref_id );
	}

	/**
	 * Mengen vom Komponentenbestand abziehen und protokollieren.
	 *
	 * @param array  $needed   component_id => Menge.
	 * @param string $ref_type Herkunft.
	 * @param int    $ref_id   Herkunfts-ID.
	 * @return array
	 */
	protected static function book( array $needed, $ref_type, $ref_id ) {
		global $wpdb;

		$table  = ComponentRepository::table();
		$booked = array();

		foreach ( $needed as $component_id => $qty ) {
			$component = ComponentRepository::find( $component_id );
			if ( ! $component ) {
				continue;
			}

			$data = array(
				'stock_qty'  => (float) $component['stock_qty'] - (float) $qty,
				'updated_at' => current_time( 'mysql', true ),
			);

			$wpdb->update( $table, $data, array( 'id' => (int) $component_id ) );
			BetriebAudit::record(
				'update',
				'components',
				(int) $component_id,
				$component,
				$data,
				sprintf(
					/* translators: 1: Menge, 2: Herkunft, 3: ID. */
					__( 'Verbrauch %1$s Stück aus %2$s %3$d', 'lindenweg16' ),
					number_format_i18n( (float) $qty, 2 ),
					$ref_type,
					(int) $ref_id
				)
			);

			$booked[ (int) $component_id ] = (float) $qty;
		}

		return $booked;
	}
}
