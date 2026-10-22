<?php
/**
 * Montage im Workflow: aus Rohlingen und Kleinteilen die Halbzeuge und Module bauen.
 *
 * Der Drucker liefert Rohlinge; was daraus wird, entscheidet die Stückliste
 * des Artikels (Beschaffungsweg „Montage“). Der DemandPlanner kennt die
 * Aktion „montieren“ längst, der Workflow zeigte sie bisher nicht. Hier
 * stehen die Karten dazu und der Weg vom Bedarf zum Fertigungsauftrag, den
 * die Lagerseite schon kannte (WorkOrderRepository): Auftrag mit
 * Materialliste und Pickliste, Uhr, Fertigmelden mit Buchung.
 *
 * Ein Auftrag wird erst angelegt, wenn alle Teile da sind. Sonst stünde der
 * Auftrag in der Planung und der Planer hielte den Bedarf an Rohlingen für
 * gedeckt, die noch gar nicht gedruckt sind.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\BinRepository;
use Lindenweg16\Modules\Betrieb\BomRepository;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\ComponentRepository;
use Lindenweg16\Modules\Betrieb\PicklistRepository;
use Lindenweg16\Modules\Betrieb\StockLedger;
use Lindenweg16\Modules\Betrieb\StockRepository;
use Lindenweg16\Modules\Betrieb\WorkOrderRepository;

/**
 * Karten, Aufträge und Aktionen der Montage.
 */
class Assembly {

	/**
	 * Obergrenze der Stückzahl, gegen Tippfehler.
	 */
	const MAX_QTY = 100000;

	/**
	 * Zustand eines Teils für die Montage – reine Regel.
	 *
	 * @param float $need     Bedarf.
	 * @param float $have     Im Lager.
	 * @param float $in_work  Schon in Arbeit (Druck oder Auftrag), nur bei Rohlingen.
	 * @return string da, druck oder fehlt.
	 */
	public static function part_state( $need, $have, $in_work = 0.0 ) {
		if ( $have + 0.0005 >= $need ) {
			return 'da';
		}

		if ( $have + $in_work + 0.0005 >= $need ) {
			return 'druck';
		}

		return 'fehlt';
	}

	/**
	 * Teile einer Montage mit Bestand und Zustand.
	 *
	 * @param int   $article_id Artikel.
	 * @param float $qty        Stückzahl.
	 * @param array $ctx        Kontext aus DemandPlanner::context().
	 * @param array $bom_rows   Direkte Stücklistenzeilen (leer = aus der Datenbank).
	 * @return array Zeilen {kind, name, label, need, have, state}.
	 */
	public static function parts( $article_id, $qty, array $ctx, array $bom_rows = array() ) {
		$bom_rows = $bom_rows ? $bom_rows : (array) ( isset( $ctx['bom'][ (int) $article_id ] ) ? $ctx['bom'][ (int) $article_id ] : BomRepository::for_article( (int) $article_id ) );
		$articles = isset( $ctx['articles'] ) ? (array) $ctx['articles'] : array();
		$stock    = isset( $ctx['stock'] ) ? (array) $ctx['stock'] : array();
		$planned  = isset( $ctx['planned'] ) ? (array) $ctx['planned'] : array();
		$comps    = isset( $ctx['components'] ) ? (array) $ctx['components'] : array();
		$cstock   = isset( $ctx['component_stock'] ) ? (array) $ctx['component_stock'] : array();
		$out      = array();

		foreach ( WorkOrderRepository::material_need( $bom_rows, (float) $qty ) as $need ) {
			$id = (int) $need['child_id'];

			if ( 'komponente' === $need['child_type'] ) {
				$have = isset( $cstock[ $id ] ) ? (float) $cstock[ $id ] : 0.0;
				$comp = isset( $comps[ $id ] ) ? (array) $comps[ $id ] : array();
				$out[] = array(
					'kind'  => 'komponente',
					'id'    => $id,
					'name'  => isset( $comp['name'] ) ? (string) $comp['name'] : '',
					'label' => isset( $comp['code'] ) ? (string) $comp['code'] : '#' . $id,
					'need'  => round( $need['qty'], 3 ),
					'have'  => round( $have, 3 ),
					'state' => self::part_state( $need['qty'], $have ),
				);
				continue;
			}

			$have    = isset( $stock[ $id ] ) ? (float) $stock[ $id ] : 0.0;
			$running = isset( $planned[ $id ] ) ? (float) $planned[ $id ] : 0.0;
			$art     = isset( $articles[ $id ] ) ? (array) $articles[ $id ] : array();
			$out[]   = array(
				'kind'  => 'artikel',
				'id'    => $id,
				'name'  => isset( $art['name'] ) ? (string) $art['name'] : '',
				'label' => isset( $art['sku'] ) ? (string) $art['sku'] : '#' . $id,
				'need'  => round( $need['qty'], 3 ),
				'have'  => round( $have, 3 ),
				'state' => self::part_state( $need['qty'], $have, $running ),
			);
		}

		return $out;
	}

	/**
	 * Sind alle Teile da – reine Regel.
	 *
	 * @param array $parts Zeilen aus parts().
	 * @return bool
	 */
	public static function ready( array $parts ) {
		foreach ( $parts as $part ) {
			if ( 'da' !== $part['state'] ) {
				return false;
			}
		}

		return (bool) $parts;
	}

	/**
	 * Karten für Halbzeuge und Module, die montiert werden müssen.
	 *
	 * @param array $plan  Ergebnis von DemandPlanner::plan().
	 * @param array $ctx   Kontext aus DemandPlanner::context().
	 * @param array $labels Quellen-Beschriftungen je Bedarfsart (DemandPlanner::source_labels()).
	 * @return array
	 */
	public static function cards( array $plan, array $ctx, array $labels ) {
		$cards = array();

		foreach ( \Lindenweg16\Modules\Betrieb\DemandPlanner::actionable( $plan['articles'] ) as $row ) {
			if ( 'montieren' !== $row['action'] ) {
				continue;
			}

			$qty   = (int) ceil( $row['net'] );
			$parts = self::parts( (int) $row['article_id'], $qty, $ctx );
			$chips = array();

			foreach ( $row['sources'] as $source => $info ) {
				$count   = count( $info['labels'] );
				$chips[] = 'bestellung' === $source && $count
					/* translators: %d: Anzahl Bestellungen. */
					? sprintf( _n( '%d Bestellung', '%d Bestellungen', $count, 'lindenweg16' ), $count )
					: ( 'hochrechnung' === $source && $info['labels'] ? (string) $info['labels'][0] : ( isset( $labels[ $source ] ) ? $labels[ $source ] : (string) $source ) );
			}

			$cards[] = array(
				'article_id' => (int) $row['article_id'],
				'sku'        => $row['sku'],
				'name'       => $row['name'],
				'qty'        => $qty,
				'stock'      => $row['stock'],
				'chips'      => array_values( array_unique( $chips ) ),
				'parts'      => $parts,
				'ready'      => self::ready( $parts ),
				'has_bom'    => (bool) $parts,
				'color'      => self::suggest_color( $parts ),
			);
		}

		return $cards;
	}

	/**
	 * Farbe, in der die Rohlinge am ehesten vorrätig sind – Vorschlag fürs Anlegen.
	 *
	 * Der Bestand ist je Artikel und Farbe gebucht; ein Auftrag hat genau eine
	 * Farbe. Genommen wird die Farbe, in der die meisten Rohlingsmengen reichen.
	 *
	 * @param array $parts Zeilen aus parts().
	 * @return string Farbkennung.
	 */
	public static function suggest_color( array $parts ) {
		$balances = StockLedger::balances( StockRepository::all() );
		$score    = array();

		foreach ( $parts as $part ) {
			if ( 'artikel' !== $part['kind'] || empty( $balances[ $part['id'] ] ) ) {
				continue;
			}

			foreach ( $balances[ $part['id'] ] as $color => $locations ) {
				$have = isset( $locations['lager'] ) ? (float) $locations['lager'] : 0.0;

				if ( $have + 0.0005 >= $part['need'] ) {
					$score[ $color ] = ( isset( $score[ $color ] ) ? $score[ $color ] : 0 ) + 1;
				}
			}
		}

		if ( ! $score ) {
			return ColorRegistry::default_color();
		}

		arsort( $score );

		return (string) key( $score );
	}

	/**
	 * Einen Montageauftrag aus dem Bedarf anlegen, samt Pickliste.
	 *
	 * @param int    $article_id Artikel.
	 * @param float  $qty        Stückzahl.
	 * @param string $color      Farbe (leer = Vorschlag).
	 * @return array {ok, message, id, status}
	 */
	public static function create( $article_id, $qty, $color = '' ) {
		$article_id = (int) $article_id;
		$qty        = (float) $qty;

		if ( $qty <= 0 || $qty > self::MAX_QTY ) {
			return self::result( false, __( 'Bitte eine Stückzahl zwischen 1 und 100000 eintragen.', 'lindenweg16' ), 0, 422 );
		}

		$ctx   = \Lindenweg16\Modules\Betrieb\DemandPlanner::context();
		$parts = self::parts( $article_id, $qty, $ctx );
		$miss  = array();

		foreach ( $parts as $part ) {
			if ( 'da' !== $part['state'] ) {
				$miss[] = self::missing_text( $part );
			}
		}

		if ( $miss ) {
			return self::result(
				false,
				sprintf(
					/* translators: %s: Aufzählung fehlender Teile. */
					__( 'Es sind noch nicht alle Teile da: %s. Erst wenn sie im Lager sind, lässt sich die Montage anlegen.', 'lindenweg16' ),
					implode( '; ', $miss )
				),
				0,
				422
			);
		}

		$checked = WorkOrderRepository::validate(
			array(
				'kind'        => 'montage',
				'article_id'  => $article_id,
				'qty_planned' => $qty,
				'color'       => '' !== $color ? $color : self::suggest_color( $parts ),
			)
		);

		if ( $checked['errors'] ) {
			return self::result( false, implode( ' ', $checked['errors'] ), 0, 422 );
		}

		$id = WorkOrderRepository::insert( $checked['data'] );

		if ( ! $id ) {
			return self::result( false, __( 'Der Auftrag konnte nicht angelegt werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 0, 500 );
		}

		$order       = WorkOrderRepository::find( $id );
		$picklist_id = PicklistRepository::from_work_order( $order, BinRepository::article_hints(), BinRepository::component_hints() );

		if ( $picklist_id ) {
			WorkOrderRepository::attach_picklist( $id, $picklist_id );
		}

		return self::result(
			true,
			sprintf(
				/* translators: %s: Auftragscode. */
				__( 'Montage %s angelegt. Die Materialliste steht unter „Picken“ bereit.', 'lindenweg16' ),
				$order['code']
			),
			$id,
			201
		);
	}

	/**
	 * Satz zu einem fehlenden Teil, mit Menge und Stand.
	 *
	 * @param array $part Zeile aus parts().
	 * @return string
	 */
	public static function missing_text( array $part ) {
		return sprintf(
			/* translators: 1: Menge, 2: Teil, 3: Bestand, 4: Zusatz. */
			__( '%1$s× %2$s (im Lager %3$s%4$s)', 'lindenweg16' ),
			self::num( $part['need'] ),
			trim( $part['label'] . ' ' . $part['name'] ),
			self::num( $part['have'] ),
			'druck' === $part['state'] ? __( ', wird gerade gedruckt', 'lindenweg16' ) : ''
		);
	}

	/**
	 * Zahl ohne überflüssige Nachkommastellen, deutsch.
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	protected static function num( $n ) {
		return function_exists( 'number_format_i18n' )
			? number_format_i18n( $n, abs( $n - round( $n ) ) < 0.0005 ? 0 : 2 )
			: (string) $n;
	}

	/**
	 * Ein Auftrag für die Anzeige.
	 *
	 * @param array $order    Zeile aus WorkOrderRepository.
	 * @param bool  $detail   Mit Teilen, Bestand und Pickliste.
	 * @param array $ctx      Kontext (nur bei $detail gebraucht; leer = laden).
	 * @param array $articles Artikelstamm nach ID (null = laden).
	 * @return array
	 */
	public static function view( array $order, $detail = false, array $ctx = array(), $articles = null ) {
		$articles = is_array( $articles ) ? $articles : ArticleRepository::all_by_id();
		$article  = isset( $articles[ (int) $order['article_id'] ] ) ? $articles[ (int) $order['article_id'] ] : null;
		$now      = current_time( 'mysql', true );
		$labels   = array(
			'offen'     => __( 'offen', 'lindenweg16' ),
			'laufend'   => __( 'läuft', 'lindenweg16' ),
			'erledigt'  => __( 'erledigt', 'lindenweg16' ),
			'storniert' => __( 'storniert', 'lindenweg16' ),
		);

		$view = array(
			'id'         => (int) $order['id'],
			'code'       => (string) $order['code'],
			'article_id' => (int) $order['article_id'],
			'sku'        => $article ? (string) $article['sku'] : '',
			'name'       => $article ? (string) $article['name'] : '?',
			'qty'        => (float) $order['qty_planned'],
			'done'       => (float) $order['qty_done'],
			'status'     => (string) $order['status'],
			'status_text' => isset( $labels[ $order['status'] ] ) ? $labels[ $order['status'] ] : (string) $order['status'],
			'open'       => in_array( $order['status'], WorkOrderRepository::OPEN_STATES, true ),
			'running'    => WorkOrderRepository::is_running( $order ),
			'elapsed'    => WorkOrderRepository::elapsed( $order, $now ),
			'fetched'    => time(),
			'color'      => ColorRegistry::label( $order['color'] ),
		);

		if ( $detail ) {
			$ctx   = $ctx ? $ctx : \Lindenweg16\Modules\Betrieb\DemandPlanner::context();
			$parts = self::parts( (int) $order['article_id'], (float) $order['qty_planned'], $ctx );
			$list  = ! empty( $order['picklist_id'] ) ? PicklistRepository::find( (int) $order['picklist_id'] ) : null;

			$view['parts']    = $parts;
			$view['picklist'] = $list ? (string) $list['code'] : '';
			$view['seconds']  = (int) $order['seconds_worked'];
		}

		return $view;
	}

	/**
	 * Offene und heute erledigte Montageaufträge fürs Workflow-Brett.
	 *
	 * @return array {open, done}
	 */
	public static function board() {
		$open     = array();
		$done     = array();
		$day      = gmdate( 'Y-m-d' );
		$articles = ArticleRepository::all_by_id();

		foreach ( WorkOrderRepository::all() as $order ) {
			if ( 'montage' !== $order['kind'] ) {
				continue;
			}

			if ( in_array( $order['status'], WorkOrderRepository::OPEN_STATES, true ) ) {
				$open[] = self::view( $order, false, array(), $articles );
			} elseif ( 'erledigt' === $order['status'] && 0 === strpos( (string) $order['finished_at'], $day ) ) {
				$done[] = self::view( $order, false, array(), $articles );
			}
		}

		return array( 'open' => array_reverse( $open ), 'done' => $done );
	}

	/**
	 * Aktion auf einem Montageauftrag.
	 *
	 * @param int    $id     Auftrag.
	 * @param string $action start, pause, fertig oder abbrechen.
	 * @param array  $params qty_done, reason.
	 * @return array {ok, message, status}
	 */
	public static function act( $id, $action, array $params = array() ) {
		$order = WorkOrderRepository::find( (int) $id );

		if ( ! $order || 'montage' !== $order['kind'] ) {
			return self::result( false, __( 'Diesen Montageauftrag gibt es nicht (mehr).', 'lindenweg16' ), (int) $id, 404 );
		}

		switch ( $action ) {
			case 'start':
				$r = WorkOrderRepository::start( (int) $id );
				break;
			case 'pause':
				$r = WorkOrderRepository::pause( (int) $id );
				break;
			case 'fertig':
				$qty = isset( $params['qty_done'] ) ? (float) str_replace( ',', '.', (string) $params['qty_done'] ) : 0.0;

				if ( $qty < 0 || $qty > self::MAX_QTY ) {
					return self::result( false, __( 'Bitte die fertige Stückzahl als Zahl eintragen, zum Beispiel 12.', 'lindenweg16' ), (int) $id, 422 );
				}

				$r = WorkOrderRepository::complete( (int) $id, $qty );
				break;
			case 'abbrechen':
				$reason = isset( $params['reason'] ) ? trim( (string) $params['reason'] ) : '';
				$r      = WorkOrderRepository::cancel( (int) $id, '' !== $reason ? $reason : __( 'Im ERP abgebrochen', 'lindenweg16' ) );
				break;
			default:
				return self::result( false, __( 'Diese Aktion kennt die Montage nicht.', 'lindenweg16' ), (int) $id, 422 );
		}

		return self::result( (bool) $r['success'], (string) $r['message'], (int) $id, $r['success'] ? 200 : 422 );
	}

	/**
	 * Ergebnis einer Aktion.
	 *
	 * @param bool   $ok      Erfolg.
	 * @param string $message Meldung.
	 * @param int    $id      Auftrag.
	 * @param int    $status  HTTP-Status.
	 * @return array
	 */
	protected static function result( $ok, $message, $id, $status ) {
		return array( 'ok' => (bool) $ok, 'message' => (string) $message, 'id' => (int) $id, 'status' => (int) $status );
	}
}
