<?php
/**
 * Artikelstamm.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Artikel samt Protokollierung.
 */
class ArticleRepository {

	use SanitizesInput;

	/**
	 * Erlaubte Status.
	 */
	const STATUS = array( 'aktiv', 'entwicklung', 'gesperrt', 'ausgelaufen' );

	/**
	 * Erlaubte Beschaffungswege.
	 */
	const SOURCING = array( 'druck', 'montage', 'zukauf', 'hybrid' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_articles';
	}

	/**
	 * Bezeichnungen der Beschaffungswege.
	 *
	 * @return array
	 */
	public static function sourcing_labels() {
		return array(
			'druck'   => __( 'Rohling aus dem Drucker', 'lindenweg16' ),
			'montage' => __( 'Set, Baugruppe oder Halbzeug (aus Stückliste)', 'lindenweg16' ),
			'zukauf'  => __( 'Zukauf', 'lindenweg16' ),
			'hybrid'  => __( 'Hybrid (Zukauf mit gedruckten Anbauteilen)', 'lindenweg16' ),
		);
	}

	/**
	 * Bezeichnungen der Status.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'aktiv'       => __( 'aktiv', 'lindenweg16' ),
			'entwicklung' => __( 'in Entwicklung', 'lindenweg16' ),
			'gesperrt'    => __( 'gesperrt', 'lindenweg16' ),
			'ausgelaufen' => __( 'ausgelaufen', 'lindenweg16' ),
		);
	}

	/**
	 * Nur den Status setzen.
	 *
	 * Getrennt von validate(): Stilllegen soll nicht daran scheitern,
	 * dass irgendein anderes Pflichtfeld heute strenger geprüft wird als
	 * beim Anlegen.
	 *
	 * @param int    $id     Artikel.
	 * @param string $status Neuer Status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;

		$id     = (int) $id;
		$labels = self::status_labels();

		if ( ! isset( $labels[ $status ] ) ) {
			return false;
		}

		$before = self::find( $id );

		if ( ! $before ) {
			return false;
		}

		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);

		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
		BetriebAudit::record( 'update', 'articles', $id, $before, $data );

		return true;
	}

	/**
	 * Zustände, in denen ein Artikel nicht mehr angeboten wird.
	 *
	 * Der Status gab es schon, er hatte nur nie eine Wirkung: Gefiltert
	 * wurde allein nach „verkäuflich". Ein gesperrter Artikel stand
	 * deshalb weiter in jeder Auswahlliste.
	 */
	const INACTIVE_STATES = array( 'gesperrt', 'ausgelaufen' );

	/**
	 * Wird dieser Artikel noch angeboten?
	 *
	 * Reine Logik.
	 *
	 * @param array $article Artikel.
	 * @return bool
	 */
	public static function is_active( array $article ) {
		$status = isset( $article['status'] ) ? (string) $article['status'] : '';

		return ! in_array( $status, self::INACTIVE_STATES, true );
	}

	/**
	 * Artikel, die für etwas Neues in Frage kommen.
	 *
	 * Für Auswahllisten: Was hier nicht steht, lässt sich nicht neu
	 * verkaufen, drucken, montieren oder bestellen. Bestand, Bewegungen
	 * und Auswertungen bleiben davon unberührt – ein ausgelaufener
	 * Artikel liegt weiter im Regal und steht weiter in den Büchern.
	 *
	 * @param bool $only_sellable Nur verkäufliche Artikel.
	 * @param bool $exclude_pos   Produkte nur für die Kasse weglassen.
	 * @return array
	 */
	public static function selectable( $only_sellable = false, $exclude_pos = false ) {
		return array_values(
			array_filter(
				self::all( $only_sellable ),
				static function ( $article ) use ( $exclude_pos ) {
					if ( $exclude_pos && ! empty( $article['pos_only'] ) ) {
						return false;
					}

					return self::is_active( $article );
				}
			)
		);
	}

	/**
	 * Nächste freie Artikelnummer für ein Kassenprodukt (K-0001, K-0002 …).
	 *
	 * @return string
	 */
	public static function next_pos_sku() {
		global $wpdb;

		$table = self::table();
		$max   = (int) $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING(sku, 3) AS UNSIGNED)) FROM {$table} WHERE sku REGEXP '^K-[0-9]+$'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return sprintf( 'K-%04d', $max + 1 );
	}

	/**
	 * Schnell ein Produkt nur für die Kasse anlegen: Name, Preis, Foto.
	 *
	 * Ein ganz normaler Artikel – damit gelten Bestand, Lagerabgang und
	 * Storno unverändert –, nur mit pos_only: Er bekommt keine
	 * Shop-Verknüpfung und taucht in Auftrags- und Konfigurator-Listen nicht
	 * auf. Der Beschaffungsweg „Zukauf“ verlangt weder Druckprofil noch Stückliste.
	 *
	 * @param string $name     Bezeichnung.
	 * @param mixed  $price    Bruttopreis, roh getippt.
	 * @param int    $image_id Mediendatei oder 0.
	 * @return array {success, message, id}
	 */
	public static function create_pos_article( $name, $price, $image_id = 0 ) {
		$check = self::validate(
			array(
				'sku'           => self::next_pos_sku(),
				'name'          => $name,
				'status'        => 'aktiv',
				'sourcing_type' => 'zukauf',
				'sellable'      => 1,
				'price_gross'   => $price,
			)
		);

		if ( $check['errors'] ) {
			return array( 'success' => false, 'message' => implode( ' ', $check['errors'] ), 'id' => 0 );
		}

		$data             = $check['data'];
		$data['pos_only'] = 1;
		$data['image_id'] = $image_id ? (int) $image_id : null;

		$id = self::insert( $data );

		if ( ! $id ) {
			return array( 'success' => false, 'message' => __( 'Das Produkt konnte nicht angelegt werden.', 'lindenweg16' ), 'id' => 0 );
		}

		return array( 'success' => true, 'message' => __( 'Produkt angelegt.', 'lindenweg16' ), 'id' => $id );
	}

	/**
	 * Hängt an diesem Artikel Geschichte?
	 *
	 * Gelöscht wird nur, was nie benutzt wurde – ein Fehlgriff beim
	 * Anlegen. Alles andere wird stillgelegt: Eine Bewegung, ein
	 * Druckjob oder eine Bestellposition, deren Artikel verschwindet,
	 * macht die Bücher unlesbar.
	 *
	 * @param int $id Artikel.
	 * @return array Liste der Gründe; leer heißt löschbar.
	 */
	public static function delete_blockers( $id ) {
		global $wpdb;

		$id = (int) $id;
		$p  = $wpdb->prefix . 'lw16_';

		$pruefungen = array(
			array( $p . 'stock_movements', 'article_id', __( 'Lagerbewegungen', 'lindenweg16' ) ),
			array( $p . 'print_jobs', 'article_id', __( 'Druckjobs', 'lindenweg16' ) ),
			array( $p . 'batches', 'article_id', __( 'Chargen', 'lindenweg16' ) ),
			array( $p . 'order_op_items', 'article_id', __( 'Bestellpositionen', 'lindenweg16' ) ),
			array( $p . 'picklist_items', 'article_id', __( 'Picklisten', 'lindenweg16' ) ),
			array( $p . 'work_orders', 'article_id', __( 'Aufträge', 'lindenweg16' ) ),
		);

		$gruende = array();

		foreach ( $pruefungen as $pruefung ) {
			list( $tabelle, $spalte, $label ) = $pruefung;

			$anzahl = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$tabelle} WHERE {$spalte} = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			if ( $anzahl > 0 ) {
				/* translators: 1: Anzahl, 2: Bereich. */
				$gruende[] = sprintf( __( '%1$d × %2$s', 'lindenweg16' ), $anzahl, $label );
			}
		}

		// Steckt er in der Stückliste eines anderen Artikels, wäre dessen
		// Kalkulation danach falsch – ohne dass es jemand merkt.
		$verbaut = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}bom WHERE child_type = 'artikel' AND child_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		if ( $verbaut > 0 ) {
			/* translators: %d: Anzahl Stücklisten. */
			$gruende[] = sprintf( __( 'in %d Stückliste(n) verbaut', 'lindenweg16' ), $verbaut );
		}

		return $gruende;
	}

	/**
	 * Artikel löschen – nur ohne Geschichte.
	 *
	 * Mit ihm gehen seine eigenen Stammdatenzeilen: Stückliste,
	 * Revisionen, Beschaffung, Sollbestände. Die hängen an ihm und
	 * ergeben ohne ihn nichts.
	 *
	 * @param int $id Artikel.
	 * @return array {success, message}
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id      = (int) $id;
		$article = self::find( $id );

		if ( ! $article ) {
			return array(
				'success' => false,
				'message' => __( 'Diesen Artikel gibt es nicht mehr.', 'lindenweg16' ),
			);
		}

		$gruende = self::delete_blockers( $id );

		if ( $gruende ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Aufzählung der Gründe. */
					__( 'Dieser Artikel lässt sich nicht löschen, es hängt Geschichte daran: %s. Setz ihn stattdessen auf „ausgelaufen" – dann verschwindet er aus allen Auswahllisten, und die Bücher bleiben lesbar.', 'lindenweg16' ),
					implode( ', ', $gruende )
				),
			);
		}

		$p = $wpdb->prefix . 'lw16_';

		$wpdb->delete( $p . 'bom', array( 'parent_article_id' => $id ) );
		$wpdb->delete( $p . 'article_revisions', array( 'article_id' => $id ) );
		$wpdb->delete( $p . 'article_sourcing', array( 'article_id' => $id ) );
		$wpdb->delete( $p . 'stock_targets', array( 'article_id' => $id ) );
		$wpdb->delete( self::table(), array( 'id' => $id ) );

		BetriebAudit::record( 'delete', 'articles', $id, $article, null );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: Artikelnummer. */
				__( 'Artikel %s gelöscht.', 'lindenweg16' ),
				$article['sku']
			),
		);
	}

	/**
	 * Alle Artikel.
	 *
	 * @param bool $only_sellable Nur verkäufliche Artikel.
	 * @return array
	 */
	public static function all( $only_sellable = false ) {
		global $wpdb;
		$table = self::table();
		$where = $only_sellable ? 'WHERE sellable = 1' : '';
		return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY sku ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Artikel nach ID indiziert – Kontext für die Kalkulation.
	 *
	 * @return array
	 */
	public static function all_by_id() {
		$rows = array();
		foreach ( self::all() as $row ) {
			$rows[ (int) $row['id'] ] = $row;
		}
		return $rows;
	}

	/**
	 * Einen Artikel holen.
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
	 * Artikel anhand eines fremden Barcodes.
	 *
	 * Zugekaufte Ware traegt den Barcode des Lieferanten. Ihn zu erfassen ist
	 * billiger, als jeden Beutel neu zu bekleben.
	 *
	 * @param string $barcode Barcode.
	 * @return array|null
	 */
	public static function find_by_barcode( $barcode ) {
		global $wpdb;

		$barcode = trim( (string) $barcode );
		if ( '' === $barcode ) {
			return null;
		}

		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE barcode = %s", $barcode ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Artikel über die Artikelnummer holen.
	 *
	 * @param string $sku Artikelnummer.
	 * @return array|null
	 */
	public static function find_by_sku( $sku ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE sku = %s", (string) $sku ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array    $input Rohe Eingaben.
	 * @param int|null $id    Bei Bearbeitung die eigene ID.
	 * @return array {data, errors}
	 */
	public static function validate( array $input, $id = null ) {
		$errors = array();

		$sku = isset( $input['sku'] ) ? strtoupper( sanitize_text_field( $input['sku'] ) ) : '';
		if ( '' === $sku ) {
			$errors[] = __( 'Eine Artikelnummer ist Pflicht.', 'lindenweg16' );
		} else {
			$existing = self::find_by_sku( $sku );
			if ( $existing && (int) $existing['id'] !== (int) $id ) {
				$errors[] = sprintf(
					/* translators: %s: Artikelnummer. */
					__( 'Die Artikelnummer %s ist bereits vergeben.', 'lindenweg16' ),
					$sku
				);
			}
		}

		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			$errors[] = __( 'Eine Bezeichnung ist Pflicht.', 'lindenweg16' );
		}

		// Der Beschaffungsweg entscheidet über alles Weitere: ob der Artikel
		// eine Revision braucht, ob er eine Stückliste hat, wie er kalkuliert
		// wird. Ein stillschweigend gesetzter Standard macht aus einem Set
		// einen Rohling, der dann grundlos nach einem Druckprofil verlangt.
		// Schickt das Formular das Feld leer mit, ist das eine fehlende
		// Angabe und kein Grund zu raten.
		if ( array_key_exists( 'sourcing_type', $input ) && '' === trim( (string) $input['sourcing_type'] ) ) {
			$errors[] = __( 'Bitte den Beschaffungsweg wählen – er entscheidet, ob der Artikel ein Druckprofil, eine Stückliste oder Beschaffungsdaten braucht.', 'lindenweg16' );
		}

		$sourcing = self::enum( $input, 'sourcing_type', self::SOURCING, 'druck' );

		$data = array(
			'sku'                => $sku,
			'name'               => $name,
			'status'             => self::enum( $input, 'status', self::STATUS, 'entwicklung' ),
			'sourcing_type'      => $sourcing,
			'sellable'           => self::flag( $input, 'sellable' ),
			'discountable'       => self::flag( $input, 'discountable' ),
			'default_bin_id'     => self::bin_or_null( $input ),
			'wc_product_id'      => self::decimal( $input, 'wc_product_id' ) ? (int) self::decimal( $input, 'wc_product_id' ) : null,
			'legacy_produkt_id'  => self::decimal( $input, 'legacy_produkt_id' ) ? (int) self::decimal( $input, 'legacy_produkt_id' ) : null,
			'price_gross'        => self::decimal( $input, 'price_gross' ),
			'dealer_price'       => self::decimal( $input, 'dealer_price' ),
			'weight_g'           => self::decimal( $input, 'weight_g' ),
			'postprocessing_min' => self::int_or( $input, 'postprocessing_min', 0 ),
			'load_rating_kg'     => self::decimal( $input, 'load_rating_kg' ),
			'barcode'            => self::text( $input, 'barcode' ) ? self::text( $input, 'barcode' ) : null,
			'notes'              => isset( $input['notes'] ) ? sanitize_textarea_field( $input['notes'] ) : null,
		);

		// Ein verkäuflicher Artikel braucht einen Preis, sonst rechnet die
		// Kalkulation gegen null und meldet einen Deckungsbeitrag von minus
		// den Fixkosten.
		if ( $data['sellable'] && ( null === $data['price_gross'] || $data['price_gross'] <= 0 ) ) {
			$errors[] = __( 'Ein verkäuflicher Artikel braucht einen Bruttopreis.', 'lindenweg16' );
		}

		if ( 'zukauf' === $sourcing && null !== $data['weight_g'] && $data['weight_g'] > 0 ) {
			$errors[] = __( 'Ein Zukaufartikel hat kein Filamentgewicht – die Kosten kommen aus der Beschaffung.', 'lindenweg16' );
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	/**
	 * Artikel anlegen.
	 *
	 * @param array $data Geprüfte Daten.
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'articles', $id, null, $data );

		/** This action is documented in includes/Modules/Betrieb/ArticleRepository.php */
		do_action( 'lw16_betrieb_article_saved', $id, $data, null );

		return $id;
	}

	/**
	 * Artikel ändern.
	 *
	 * @param int    $id     ID.
	 * @param array  $data   Geprüfte Daten.
	 * @param string $reason Begründung.
	 * @return bool
	 */
	public static function update( $id, array $data, $reason = '' ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql', true );

		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ) ) {
			return false;
		}
		BetriebAudit::record( 'update', 'articles', (int) $id, $before, $data, $reason );

		/**
		 * Ein gespeicherter Artikel. Der Preis hängt daran nicht allein –
		 * das Shop-Produkt soll ihn ohne zweiten Handgriff bekommen.
		 *
		 * @param int        $id     Artikel.
		 * @param array      $data   Gespeicherte Felder.
		 * @param array|null $before Stand davor.
		 */
		do_action( 'lw16_betrieb_article_saved', (int) $id, $data, $before );

		return true;
	}
}
