<?php
/**
 * Picklisten.
 *
 * Eine Pickliste ist der Zettel, mit dem man einmal durchs Lager geht und
 * alles einsammelt, was heute raus muss. Sie ist bewusst nach Lagerplatz
 * sortiert und nicht nach Bestellung: Der Weg durchs Regal ist die knappe
 * Ressource, nicht die Zuordnung – die passiert danach am Packtisch.
 *
 * Wichtig: Das Abhaken bucht **keinen** Lagerabgang. Der Abgang entsteht
 * weiterhin genau einmal, beim Abschluss der Bestellung – sonst wäre er
 * zweimal im Journal, einmal vom Picken und einmal vom Bestellabschluss, und
 * der Bestand liefe schleichend auseinander. Was das Picken beiträgt, ist die
 * Ortsangabe: Beim Abschluss wird der Abgang dem Platz zugeschrieben, aus dem
 * tatsächlich entnommen wurde. Damit stimmt der Platzbestand, ohne dass es
 * eine zweite Buchungsstelle gibt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Erzeugen und Abarbeiten der Picklisten.
 */
class PicklistRepository {

	/**
	 * Tabellenname der Listen.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_picklists';
	}

	/**
	 * Tabellenname der Positionen.
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_picklist_items';
	}

	/**
	 * Lagerplatz einer Picklistenzeile bestimmen.
	 *
	 * Reine Logik. Die Reihenfolge ist die Rangfolge: Was an der Zeile
	 * selbst steht, gilt. Sonst der Platz, an dem tatsächlich der meiste
	 * Bestand liegt. Sonst der am Teil hinterlegte Standardplatz –
	 * Kleinteile führen kein Bewegungskonto, für sie ist er die einzige
	 * Quelle. Bleibt nichts übrig, steht die Zeile ohne Platz und wandert
	 * ans Ende der Liste.
	 *
	 * @param array  $line           Zeile.
	 * @param string $type           artikel oder komponente.
	 * @param int    $id             Artikel- oder Komponenten-ID.
	 * @param array  $bins           Vermutete Plätze je Artikel.
	 * @param array  $component_bins Standardplätze je Komponente.
	 * @return int
	 */
	protected static function bin_for( array $line, $type, $id, array $bins, array $component_bins ) {
		if ( ! empty( $line['bin_id'] ) ) {
			return (int) $line['bin_id'];
		}

		$quelle = 'komponente' === $type ? $component_bins : $bins;

		return isset( $quelle[ $id ] ) ? (int) $quelle[ $id ] : 0;
	}

	/**
	 * Positionen aus Bestellungen zu Pickzeilen zusammenfassen.
	 *
	 * Reine Rechnung ohne Datenbank: gleiche Artikel und Farben derselben
	 * Bestellung werden zusammengezogen, damit niemand zweimal zum selben
	 * Fach läuft.
	 *
	 * @param array $lines Zeilen mit article_id, color, qty, wc_order_id.
	 * @param array $bins  Zuordnung article_id => bin_id, optional.
	 * @return array
	 */
	public static function condense( array $lines, array $bins = array(), array $component_bins = array() ) {
		$grouped = array();

		foreach ( $lines as $line ) {
			$line    = (array) $line;
			$article = isset( $line['article_id'] ) ? (int) $line['article_id'] : 0;
			$qty     = isset( $line['qty'] ) ? (float) $line['qty'] : 0.0;

			if ( ! $article || $qty <= 0 ) {
				continue;
			}

			// Eine Pickliste trägt Artikel und Kleinteile nebeneinander: Wer
			// zehn Halbzeuge baut, holt Rohlinge und Schrauben in einem Gang.
			$type = isset( $line['item_type'] ) && 'komponente' === $line['item_type'] ? 'komponente' : 'artikel';

			$color = isset( $line['color'] ) && '' !== $line['color']
				? (string) $line['color']
				: ColorRegistry::UNRESOLVED;
			$order = isset( $line['wc_order_id'] ) ? (int) $line['wc_order_id'] : 0;
			$key   = $type . '|' . $article . '|' . $color . '|' . $order;

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'item_type'    => $type,
					'article_id'   => $article,
					'color'        => $color,
					'wc_order_id'  => $order,
					'bin_id'       => self::bin_for( $line, $type, $article, $bins, $component_bins ),
					'qty_required' => 0.0,
				);
			}

			$grouped[ $key ]['qty_required'] += $qty;
		}

		$rows = array_values( $grouped );

		// Nach Lagerplatz sortieren; Zeilen ohne Platz ans Ende, weil sie
		// ohnehin gesucht werden müssen.
		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['bin_id'] === $b['bin_id'] ) {
					return $a['article_id'] <=> $b['article_id'];
				}
				if ( ! $a['bin_id'] ) {
					return 1;
				}
				if ( ! $b['bin_id'] ) {
					return -1;
				}
				return $a['bin_id'] <=> $b['bin_id'];
			}
		);

		return $rows;
	}

	/**
	 * Nächsten Listencode vergeben.
	 *
	 * @return string
	 */
	public static function next_code() {
		global $wpdb;

		$table  = self::table();
		$prefix = 'PL' . gmdate( 'ymd' );
		$count  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE code LIKE %s", $prefix . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $prefix . '-' . str_pad( (string) ( $count + 1 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Liste mit Positionen anlegen.
	 *
	 * @param array  $rows          Ergebnis von condense().
	 * @param string $note          Notiz.
	 * @param string $source        bestellungen oder fertigung.
	 * @param int    $work_order_id Zugehöriger Fertigungsauftrag.
	 * @return int Listen-ID oder 0.
	 */
	public static function create( array $rows, $note = '', $source = 'bestellungen', $work_order_id = 0 ) {
		global $wpdb;

		if ( ! $rows ) {
			return 0;
		}

		$list = array(
			'code'          => self::next_code(),
			'status'        => 'offen',
			'source'        => 'fertigung' === $source ? 'fertigung' : 'bestellungen',
			'work_order_id' => $work_order_id ? (int) $work_order_id : null,
			'note'          => $note ? sanitize_textarea_field( $note ) : null,
			'created_at'    => current_time( 'mysql', true ),
			'created_by'    => get_current_user_id(),
		);

		if ( false === $wpdb->insert( self::table(), $list ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'picklists', $id, null, $list );

		foreach ( $rows as $index => $row ) {
			$wpdb->insert(
				self::items_table(),
				array(
					'picklist_id'  => $id,
					'item_type'    => isset( $row['item_type'] ) ? (string) $row['item_type'] : 'artikel',
					'article_id'   => (int) $row['article_id'],
					'color'        => (string) $row['color'],
					'bin_id'       => ! empty( $row['bin_id'] ) ? (int) $row['bin_id'] : null,
					'wc_order_id'  => ! empty( $row['wc_order_id'] ) ? (int) $row['wc_order_id'] : null,
					'qty_required' => (float) $row['qty_required'],
					'qty_picked'   => 0,
					'sort_order'   => (int) $index,
				)
			);
		}

		return $id;
	}

	/**
	 * Alle Listen, neueste zuerst.
	 *
	 * @param string $status Filter oder leer.
	 * @return array
	 */
	public static function all( $status = '' ) {
		global $wpdb;
		$table = self::table();

		if ( $status ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC", $status ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Liste anhand der ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Positionen einer Liste.
	 *
	 * @param int $picklist_id Liste.
	 * @return array
	 */
	public static function items( $picklist_id ) {
		global $wpdb;
		$table = self::items_table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE picklist_id = %d ORDER BY sort_order ASC, id ASC", (int) $picklist_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Eine Position.
	 *
	 * @param int $id Position.
	 * @return array|null
	 */
	public static function find_item( $id ) {
		global $wpdb;
		$table = self::items_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Offene Listen samt Positionen, angereichert für die Anzeige am Handy.
	 *
	 * @return array
	 */
	public static function open_with_items() {
		$articles   = ArticleRepository::all_by_id();
		$components = array();
		foreach ( ComponentRepository::all() as $component ) {
			$components[ (int) $component['id'] ] = $component;
		}

		$bins = array();
		foreach ( BinRepository::all() as $bin ) {
			$bins[ (int) $bin['id'] ] = $bin;
		}

		$out = array();
		foreach ( self::all( 'offen' ) as $list ) {
			$items = array();
			foreach ( self::items( (int) $list['id'] ) as $item ) {
				$target = self::describe( $item, $articles, $components );
				$bin    = $item['bin_id'] && isset( $bins[ (int) $item['bin_id'] ] ) ? $bins[ (int) $item['bin_id'] ] : null;

				$items[] = array(
					'id'           => (int) $item['id'],
					'item_type'    => (string) $item['item_type'],
					'article_id'   => (int) $item['article_id'],
					'sku'          => $target['sku'],
					'name'         => $target['name'],
					'color'        => 'komponente' === $item['item_type'] ? '' : (string) $item['color'],
					'bin'          => $bin ? $bin['code'] : '',
					'bin_id'       => (int) $item['bin_id'],
					'wc_order_id'  => (int) $item['wc_order_id'],
					'qty_required' => (float) $item['qty_required'],
					'qty_picked'   => (float) $item['qty_picked'],
					'done'         => (float) $item['qty_picked'] >= (float) $item['qty_required'],
				);
			}

			// Offene Positionen mitzaehlen: Am Handy steht die Zahl in der
			// Auswahlliste, damit sich eine Liste waehlen laesst, ohne sie
			// erst zu oeffnen.
			$offen = 0;
			foreach ( $items as $item ) {
				if ( ! $item['done'] ) {
					$offen++;
				}
			}

			$out[] = array(
				'id'     => (int) $list['id'],
				'code'   => (string) $list['code'],
				'source' => (string) $list['source'],
				'note'   => (string) $list['note'],
				'open'   => $offen,
				'items'  => $items,
			);
		}

		return $out;
	}

	/**
	 * Nummer, Bezeichnung und Barcode einer Position auflösen.
	 *
	 * Eine Position zeigt entweder auf einen Artikel oder auf eine
	 * Komponente – dieselbe Spalte, zwei Stämme.
	 *
	 * @param array $item       Position.
	 * @param array $articles   Artikel nach ID.
	 * @param array $components Komponenten nach ID.
	 * @return array {sku, name, barcode}
	 */
	public static function describe( array $item, array $articles, array $components ) {
		$id = (int) $item['article_id'];

		if ( isset( $item['item_type'] ) && 'komponente' === $item['item_type'] ) {
			$row = isset( $components[ $id ] ) ? $components[ $id ] : null;

			return array(
				'sku'     => $row ? (string) $row['code'] : '',
				'name'    => $row ? (string) $row['name'] : '',
				'barcode' => $row && ! empty( $row['barcode'] ) ? (string) $row['barcode'] : '',
			);
		}

		$row = isset( $articles[ $id ] ) ? $articles[ $id ] : null;

		return array(
			'sku'     => $row ? (string) $row['sku'] : '',
			'name'    => $row ? (string) $row['name'] : '',
			'barcode' => $row && ! empty( $row['barcode'] ) ? (string) $row['barcode'] : '',
		);
	}

	/**
	 * Pickliste aus einem Fertigungsauftrag.
	 *
	 * @param array $order Auftrag.
	 * @param array $bins  Zuordnung article_id => bin_id.
	 * @return int Listen-ID oder 0.
	 */
	public static function from_work_order( array $order, array $bins = array(), array $component_bins = array() ) {
		$need = WorkOrderRepository::material_need(
			BomRepository::for_article( (int) $order['article_id'] ),
			(float) $order['qty_planned']
		);

		$lines = array();
		foreach ( $need as $row ) {
			$lines[] = array(
				'item_type'   => $row['child_type'] === 'komponente' ? 'komponente' : 'artikel',
				'article_id'  => (int) $row['child_id'],
				'color'       => 'komponente' === $row['child_type'] ? '' : (string) $order['color'],
				'qty'         => (float) $row['qty'],
				'wc_order_id' => 0,
			);
		}

		$rows = self::condense( $lines, $bins, $component_bins );

		return self::create(
			$rows,
			sprintf(
				/* translators: %s: Auftragscode. */
				__( 'Material für Fertigungsauftrag %s', 'lindenweg16' ),
				$order['code']
			),
			'fertigung',
			(int) $order['id']
		);
	}

	/**
	 * Prüfen, ob der gescannte Code zu dieser Position passt.
	 *
	 * Reine Zeichenkettenlogik, damit sie prüfbar bleibt. Ein leerer Scan
	 * gilt als bestätigt – wer ohne Scanner arbeitet, soll nicht blockiert
	 * sein; der Scan ist eine Absicherung, keine Hürde.
	 *
	 * @param string $scanned Gescannter Code.
	 * @param string $sku     Artikelnummer der Position.
	 * @param string $barcode Barcode der Position.
	 * @return bool
	 */
	public static function scan_matches( $scanned, $sku, $barcode = '' ) {
		$scanned = ScanResolver::clean( $scanned );

		if ( '' === $scanned ) {
			return true;
		}

		$split = ScanResolver::split( $scanned );
		$value = strtoupper( $split['value'] );

		return $value === strtoupper( (string) $sku )
			|| ( '' !== (string) $barcode && $value === strtoupper( (string) $barcode ) );
	}

	/**
	 * Position abhaken.
	 *
	 * Bucht bewusst keine Lagerbewegung – siehe Kopf dieser Datei.
	 *
	 * @param int    $item_id Position.
	 * @param float  $qty     Tatsächlich entnommene Menge.
	 * @param string $scanned Gescannter Code zur Absicherung.
	 * @return array {success, message, done}
	 */
	public static function pick( $item_id, $qty, $scanned = '' ) {
		global $wpdb;

		$item = self::find_item( $item_id );
		if ( ! $item ) {
			return array(
				'success' => false,
				'message' => __( 'Position nicht gefunden.', 'lindenweg16' ),
				'done'    => false,
			);
		}

		if ( 'komponente' === $item['item_type'] ) {
			$row = ComponentRepository::find( (int) $item['article_id'] );
			$sku = $row ? (string) $row['code'] : '';
		} else {
			$row = ArticleRepository::find( (int) $item['article_id'] );
			$sku = $row ? (string) $row['sku'] : '';
		}

		if ( ! $row ) {
			return array(
				'success' => false,
				'message' => __( 'Position zeigt auf einen Stammsatz, den es nicht mehr gibt.', 'lindenweg16' ),
				'done'    => false,
			);
		}

		if ( ! self::scan_matches( $scanned, $sku, isset( $row['barcode'] ) ? $row['barcode'] : '' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Der gescannte Code gehört nicht zu dieser Position.', 'lindenweg16' ),
				'done'    => false,
			);
		}

		$qty = (float) $qty;
		if ( $qty <= 0 ) {
			$qty = (float) $item['qty_required'] - (float) $item['qty_picked'];
		}

		if ( $qty <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Diese Position ist bereits vollständig entnommen.', 'lindenweg16' ),
				'done'    => true,
			);
		}

		$picked = (float) $item['qty_picked'] + $qty;

		$wpdb->update(
			self::items_table(),
			array(
				'qty_picked' => $picked,
				'picked_at'  => current_time( 'mysql', true ),
				'picked_by'  => get_current_user_id(),
			),
			array( 'id' => (int) $item_id )
		);

		self::maybe_finish( (int) $item['picklist_id'] );

		return array(
			'success' => true,
			'message' => __( 'Entnommen.', 'lindenweg16' ),
			'done'    => $picked >= (float) $item['qty_required'],
		);
	}

	/**
	 * Aus welchem Platz wurde für eine Bestellung entnommen?
	 *
	 * Wird beim Abschluss der Bestellung gebraucht, damit der Abgang dem
	 * richtigen Fach zugeschrieben wird.
	 *
	 * @param int $wc_order_id Bestellung.
	 * @return array article_id => bin_id.
	 */
	public static function bins_for_order( $wc_order_id ) {
		global $wpdb;

		$table = self::items_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT article_id, bin_id FROM {$table} WHERE wc_order_id = %d AND bin_id IS NOT NULL AND qty_picked > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $wc_order_id
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['article_id'] ] = (int) $row['bin_id'];
		}

		return $out;
	}

	/**
	 * Liste abschließen, sobald jede Position erledigt ist.
	 *
	 * @param int $picklist_id Liste.
	 * @return bool Ob abgeschlossen wurde.
	 */
	public static function maybe_finish( $picklist_id ) {
		global $wpdb;

		foreach ( self::items( $picklist_id ) as $item ) {
			if ( (float) $item['qty_picked'] < (float) $item['qty_required'] ) {
				return false;
			}
		}

		$wpdb->update(
			self::table(),
			array(
				'status'      => 'erledigt',
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $picklist_id )
		);

		BetriebAudit::record( 'update', 'picklists', (int) $picklist_id, null, array( 'status' => 'erledigt' ) );

		return true;
	}
}
