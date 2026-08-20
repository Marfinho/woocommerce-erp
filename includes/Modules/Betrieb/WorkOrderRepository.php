<?php
/**
 * Fertigungsaufträge für Halbzeuge und Baugruppen.
 *
 * Zwischen Rohling und verkaufsfertigem Artikel liegen Arbeitsschritte, die
 * kein Druckjob sind: Gewindemuttern einschmelzen, Stange und Abstandshalter
 * verbinden. Genau dafür ist der Fertigungsauftrag da.
 *
 * Er ist das Gegenstück zum Druckjob – nur ohne Drucker, Filament und
 * Druckprofil. Was beide gemeinsam haben, ist die Buchungsregel: Mit dem
 * Fertigmelden entsteht der Zugang des gefertigten Artikels und der Abgang
 * dessen, was direkt in ihn hineingeht. Direkt heißt: nur die eigene
 * Stückliste. Was eine Ebene tiefer liegt, hat sich beim eigenen
 * Fertigungsauftrag schon abgebucht – sonst ginge alles doppelt vom Bestand.
 *
 * Gebucht wird beim Fertigmelden, nicht beim Picken. Das Picken holt das
 * Material physisch aus dem Regal und liefert die Platzangabe; die Buchung
 * bleibt an einer Stelle. Dieselbe Regel wie bei Verkaufspicklisten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Anlegen, Auflösen und Fertigmelden von Fertigungsaufträgen.
 */
class WorkOrderRepository {

	use SanitizesInput;

	/**
	 * Mögliche Zustände.
	 */
	const STATES = array( 'offen', 'erledigt', 'storniert' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_work_orders';
	}

	/**
	 * Welche Artikel lassen sich überhaupt fertigen?
	 *
	 * Reine Logik. Ein Zukaufartikel wird bestellt, nicht gefertigt; ein
	 * gedruckter Rohling entsteht im Druckjournal. Bleibt die Montage – und
	 * der Hybridartikel, an dem nach dem Druck noch etwas dazukommt.
	 *
	 * @param array $article Artikel.
	 * @return bool
	 */
	public static function is_makeable( array $article ) {
		$type = isset( $article['sourcing_type'] ) ? (string) $article['sourcing_type'] : '';

		return in_array( $type, array( 'montage', 'hybrid' ), true );
	}

	/**
	 * Materialbedarf eines Auftrags – reine Logik, ohne Datenbank.
	 *
	 * Aufgelöst wird bewusst nur die erste Ebene: Was darunter liegt, ist ein
	 * eigener Bestand mit eigenem Fertigungsauftrag. Wer zehn Basismodule
	 * baut, holt zehn Halbzeuge aus dem Regal – nicht die Stangen und
	 * Abstandshalter, aus denen die Halbzeuge einmal entstanden sind.
	 *
	 * @param array $bom_rows Direkte Stücklistenzeilen des Artikels.
	 * @param float $qty      Zu fertigende Menge.
	 * @return array Zeilen mit child_type, child_id und benötigter Menge.
	 */
	public static function material_need( array $bom_rows, $qty ) {
		$qty  = (float) $qty;
		$need = array();

		if ( $qty <= 0 ) {
			return $need;
		}

		foreach ( $bom_rows as $row ) {
			$row  = (array) $row;
			$type = isset( $row['child_type'] ) ? (string) $row['child_type'] : '';
			$id   = isset( $row['child_id'] ) ? (int) $row['child_id'] : 0;

			if ( ! $id || ! in_array( $type, array( 'artikel', 'komponente' ), true ) ) {
				continue;
			}

			$key = $type . ':' . $id;

			if ( ! isset( $need[ $key ] ) ) {
				$need[ $key ] = array(
					'child_type' => $type,
					'child_id'   => $id,
					'qty'        => 0.0,
				);
			}

			$need[ $key ]['qty'] += (float) $row['qty'] * $qty;
		}

		return array_values( $need );
	}

	/**
	 * Nächsten Auftragscode vergeben.
	 *
	 * @return string
	 */
	public static function next_code() {
		global $wpdb;

		$table  = self::table();
		$prefix = 'FA' . gmdate( 'ymd' );
		$count  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE code LIKE %s", $prefix . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $prefix . '-' . str_pad( (string) ( $count + 1 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array $input Eingaben.
	 * @return array {data, errors}
	 */
	public static function validate( array $input ) {
		$errors     = array();
		$article_id = self::int_or( $input, 'article_id', 0 );
		$article    = $article_id ? ArticleRepository::find( $article_id ) : null;

		if ( ! $article ) {
			$errors[] = __( 'Bitte einen Artikel wählen.', 'lindenweg16' );
		} elseif ( ! self::is_makeable( $article ) ) {
			$errors[] = __( 'Dieser Artikel wird nicht montiert. Gedruckte Rohlinge entstehen im Druckjournal, Zukaufartikel werden bestellt.', 'lindenweg16' );
		}

		$qty = self::decimal_or( $input, 'qty_planned', 0 );
		if ( $qty <= 0 ) {
			$errors[] = __( 'Wie viele Stück sollen entstehen?', 'lindenweg16' );
		}

		if ( $article && ! BomRepository::for_article( $article_id ) ) {
			$errors[] = __( 'Dieser Artikel hat keine Stückliste – dann gäbe es nichts zu montieren und nichts zu picken.', 'lindenweg16' );
		}

		return array(
			'data'   => array(
				'article_id'  => $article_id,
				'color'       => ColorRegistry::normalize( isset( $input['color'] ) ? $input['color'] : '' ),
				'qty_planned' => $qty,
				'qty_done'    => 0,
				'status'      => 'offen',
				'note'        => isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : null,
			),
			'errors' => $errors,
		);
	}

	/**
	 * Auftrag anlegen.
	 *
	 * @param array $data Geprüfte Daten.
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;

		if ( '' === (string) $data['color'] ) {
			$data['color'] = ColorRegistry::default_color();
		}

		$data['code']       = self::next_code();
		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'work_orders', $id, null, $data );

		return $id;
	}

	/**
	 * Aufträge, neueste zuerst.
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
	 * Auftrag anhand der ID.
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
	 * Pickliste zu einem Auftrag hinterlegen.
	 *
	 * @param int $id          Auftrag.
	 * @param int $picklist_id Pickliste.
	 * @return bool
	 */
	public static function attach_picklist( $id, $picklist_id ) {
		global $wpdb;

		return false !== $wpdb->update(
			self::table(),
			array( 'picklist_id' => (int) $picklist_id ),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Auftrag fertigmelden.
	 *
	 * Bucht in einem Zug: Zugang des gefertigten Artikels, Abgang der
	 * direkten Kleinteile und Abgang der direkten Unterbaugruppen.
	 *
	 * @param int   $id  Auftrag.
	 * @param float $qty Tatsächlich gefertigte Menge.
	 * @return array {success, message}
	 */
	public static function complete( $id, $qty ) {
		global $wpdb;

		$order = self::find( $id );

		if ( ! $order ) {
			return array(
				'success' => false,
				'message' => __( 'Fertigungsauftrag nicht gefunden.', 'lindenweg16' ),
			);
		}

		if ( 'offen' !== $order['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'Dieser Auftrag ist bereits abgeschlossen.', 'lindenweg16' ),
			);
		}

		$qty = (float) $qty;
		if ( $qty <= 0 ) {
			$qty = (float) $order['qty_planned'];
		}

		$article_id = (int) $order['article_id'];

		// Zugang des gefertigten Artikels.
		StockRepository::book(
			array(
				'article_id'    => $article_id,
				'color'         => (string) $order['color'],
				'qty'           => $qty,
				'movement_type' => 'produktion',
				'location'      => 'lager',
				'ref_type'      => 'work_order',
				'ref_id'        => (int) $id,
				'note'          => sprintf(
					/* translators: %s: Auftragscode. */
					__( 'Fertigungsauftrag %s', 'lindenweg16' ),
					$order['code']
				),
			)
		);

		// Abgang der direkten Stückliste. Nur die erste Ebene – tiefer
		// liegende Teile haben sich bei ihrem eigenen Auftrag abgebucht.
		$direct = BomRepository::for_article( $article_id );

		ComponentConsumption::consume_for_production( $article_id, $qty, 'work_order', (int) $id );

		ComponentConsumption::book_assemblies(
			ComponentConsumption::assemblies_at_production( $direct, $qty ),
			(string) $order['color'],
			'work_order',
			(int) $id
		);

		$data = array(
			'qty_done'    => $qty,
			'status'      => 'erledigt',
			'finished_at' => current_time( 'mysql', true ),
		);

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'update', 'work_orders', (int) $id, $order, $data );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: Menge, 2: Auftragscode. */
				__( '%1$s Stück aus %2$s zugebucht, Material abgebucht.', 'lindenweg16' ),
				number_format_i18n( $qty, 0 ),
				$order['code']
			),
		);
	}

	/**
	 * Auftrag stornieren – nur solange nichts gebucht wurde.
	 *
	 * @param int    $id     Auftrag.
	 * @param string $reason Begründung.
	 * @return array {success, message}
	 */
	public static function cancel( $id, $reason ) {
		global $wpdb;

		$order = self::find( $id );

		if ( ! $order ) {
			return array(
				'success' => false,
				'message' => __( 'Fertigungsauftrag nicht gefunden.', 'lindenweg16' ),
			);
		}

		if ( 'erledigt' === $order['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'Ein fertiggemeldeter Auftrag wird nicht storniert – die Buchungen werden einzeln im Lager storniert.', 'lindenweg16' ),
			);
		}

		$reason = trim( (string) $reason );
		if ( '' === $reason ) {
			return array(
				'success' => false,
				'message' => __( 'Ein Storno braucht eine Begründung.', 'lindenweg16' ),
			);
		}

		$data = array( 'status' => 'storniert' );

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'update', 'work_orders', (int) $id, $order, $data, $reason );

		return array(
			'success' => true,
			'message' => __( 'Auftrag storniert.', 'lindenweg16' ),
		);
	}
}
