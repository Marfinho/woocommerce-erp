<?php
/**
 * WooCommerce-Anbindung.
 *
 * Grundsatz: **Keine Änderung am Kernverhalten.** Scheitert die Zuordnung von
 * Artikel oder Farbe, wird die Bewegung als „ungeklärt" protokolliert und im
 * Dashboard gemeldet – sie darf niemals stillschweigend verschwinden und
 * niemals die Bestellabwicklung blockieren.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Lock;
use Lindenweg16\Core\Roles;
use Lindenweg16\Core\Settings;


/**
 * Verbindet Bestellungen mit dem Bewegungskonto und der Betriebsakte.
 */
class WooIntegration {

	/**
	 * Meta-Schlüssel, der doppelte Buchungen verhindert.
	 */
	const META_BOOKED = '_lw16_betrieb_stock_booked';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'ensure_record' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'book_stock' ), 30, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'reverse_stock' ), 30, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'reverse_stock' ), 30, 1 );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
			add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_meta_box' ), 20, 1 );
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
			add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ), 20 );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 20, 2 );
			add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column' ), 20, 2 );
		}
	}

	/**
	 * Betriebsdatensatz beim Statuswechsel auf „in Bearbeitung" anlegen.
	 *
	 * @param int $order_id Bestellung.
	 */
	public function ensure_record( $order_id ) {
		OrderOpsRepository::ensure_for_order( (int) $order_id );
	}

	/**
	 * Lagerabgänge beim Abschluss buchen.
	 *
	 * @param int $order_id Bestellung.
	 */
	public function book_stock( $order_id ) {
		if ( ! StockOwnership::is_active() ) {
			return;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		// Erst die Sperre, dann die Prüfung. „Gebucht?" zu fragen und danach
		// zu buchen genügt nicht: Ein wiederholter Webhook, ein Doppelklick
		// oder eine Sammelaktion neben einem Webhook laufen gleichzeitig,
		// beide sehen die Bestellung als ungebucht und beide ziehen den
		// Bestand ab. Bekommen wir die Sperre nicht, bucht gerade ein
		// anderer Lauf – dann ist Nichtstun genau richtig.
		if ( ! Lock::acquire( 'wc_stock_' . (int) $order_id, 10 ) ) {
			return;
		}

		try {
			$this->book_stock_locked( (int) $order_id );
		} finally {
			Lock::release( 'wc_stock_' . (int) $order_id );
		}
	}

	/**
	 * Lagerabgang einer stornierten oder erstatteten Bestellung zurücknehmen.
	 *
	 * Als Gegenbuchung je Bewegung, nie durch Löschen. Nur Bestellungen, für
	 * die der Abgang überhaupt gebucht wurde. Danach fällt die Marke weg,
	 * damit eine später wieder abgeschlossene Bestellung neu bucht. Der
	 * Verbrauch an Kleinteilen und Verpackung geht bewusst nicht zurück
	 * (siehe `OrderOpsRepository::cancel_orphaned()`).
	 *
	 * Abschaltbar, weil eine versandte und dann stornierte Bestellung erst
	 * mit der Retoure wieder im Lager liegt.
	 *
	 * @param int $order_id Bestellung.
	 */
	public function reverse_stock( $order_id ) {
		if ( ! StockOwnership::is_active() || ! (int) Settings::get( 'betrieb_storno_lager', 1 ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		if ( ! Lock::acquire( 'wc_stock_' . (int) $order_id, 10 ) ) {
			return;
		}

		try {
			$order = wc_get_order( (int) $order_id );

			if ( ! $order || ! $order->get_meta( self::META_BOOKED ) ) {
				return;
			}

			$reason = sprintf(
				/* translators: %s: Bestellstatus. */
				__( 'Bestellung %s (automatisch)', 'lindenweg16' ),
				wc_get_order_status_name( $order->get_status() )
			);
			$zurueck = 0;

			foreach ( StockRepository::by_ref( 'wc_order', (int) $order_id ) as $movement ) {
				$result = StockRepository::void( (int) $movement['id'], $reason );
				if ( $result['success'] ) {
					$zurueck++;
				}
			}

			$order->delete_meta_data( self::META_BOOKED );
			$order->add_order_note(
				sprintf(
					/* translators: %d: Anzahl Bewegungen. */
					_n( 'Betrieb: %d Lagerbewegung zurückgebucht.', 'Betrieb: %d Lagerbewegungen zurückgebucht.', $zurueck, 'lindenweg16' ),
					$zurueck
				)
			);
			$order->save();
		} finally {
			Lock::release( 'wc_stock_' . (int) $order_id );
		}
	}

	/**
	 * Der eigentliche Lagerabgang, unter Sperre.
	 *
	 * @param int $order_id Bestellung.
	 */
	protected function book_stock_locked( $order_id ) {
		// Frisch laden: Die Prüfung muss den Stand nach der Sperre sehen,
		// nicht den davor zwischengespeicherten.
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Doppelbuchung verhindern – HPOS-kompatibel über Order-Meta.
		if ( $order->get_meta( self::META_BOOKED ) ) {
			return;
		}

		$order_op_id = OrderOpsRepository::ensure_for_order( (int) $order_id );
		$unresolved  = 0;

		// Einmal laden statt je Position: Beide Tabellen sind klein, die
		// Auflösung läuft aber je Position mehrfach durch sie hindurch.
		$ctx = array(
			'bom'      => BomRepository::all_by_parent(),
			'articles' => ArticleRepository::all_by_id(),
		);

		// Wurde die Bestellung gepickt, ist bekannt, aus welchem Fach die Ware
		// kam. Ohne diese Zuordnung bliebe der Abgang platzlos und der
		// Fachbestand stünde dauerhaft zu hoch.
		$bins      = PicklistRepository::bins_for_order( (int) $order_id );
		$zuordnung = StockOwnership::article_map();

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			$article_id = isset( $zuordnung[ $product_id ] ) ? $zuordnung[ $product_id ] : 0;

			if ( ! $article_id ) {
				// Kein Betrieb-Artikel: Das Lager-Modul ist zuständig.
				continue;
			}

			$color = ColorRegistry::resolve_order_item( $item );
			$qty   = (float) $item->get_quantity();

			if ( ColorRegistry::UNRESOLVED === $color['color'] ) {
				$unresolved++;
			}

			StockRepository::book(
				array(
					'article_id'    => $article_id,
					'color'         => $color['color'],
					'qty'           => -1 * $qty,
					'movement_type' => 'verkauf',
					'location'      => 'lager',
					'bin_id'        => isset( $bins[ $article_id ] ) ? $bins[ $article_id ] : 0,
					'ref_type'      => 'wc_order',
					'ref_id'        => (int) $order_id,
					'note'          => sprintf(
						/* translators: %s: Quelle der Farbzuordnung. */
						__( 'Farbe ermittelt aus: %s', 'lindenweg16' ),
						$color['resolved_from']
					),
				)
			);

			ComponentConsumption::consume_for_sale( $article_id, $qty, 'wc_order', (int) $order_id );

			ComponentConsumption::book_assemblies(
				ComponentConsumption::assemblies_at_sale( $article_id, $qty, $ctx ),
				$color['color'],
				'wc_order',
				(int) $order_id
			);
		}

		ComponentConsumption::consume_supplies( $order_op_id );

		$order->update_meta_data( self::META_BOOKED, current_time( 'mysql' ) );
		if ( $unresolved ) {
			$order->add_order_note(
				sprintf(
					/* translators: %d: Anzahl Positionen. */
					__( 'Betrieb: %d Position(en) ohne eindeutige Farbe gebucht – im Betrieb-Dashboard als ungeklärt gemeldet.', 'lindenweg16' ),
					$unresolved
				)
			);
		}
		$order->save();
	}

	/**
	 * Positionen aller noch nicht gebuchten Bestellungen.
	 *
	 * Grundlage der Pickliste. Bereits gebuchte Bestellungen bleiben außen
	 * vor – was das Lager schon verlassen hat, muss niemand mehr holen.
	 *
	 * @return array Zeilen mit article_id, color, qty und wc_order_id.
	 */
	public static function open_order_lines() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'status' => array( 'processing', 'on-hold' ),
				'limit'  => 200,
				'return' => 'objects',
			)
		);

		$lines = array();

		// Einmal die Zuordnung Produkt zu Artikel, statt je Position eine
		// Abfrage: Bei zweihundert offenen Bestellungen waren das schnell
		// mehrere hundert Abfragen für eine einzige Pickliste.
		$zuordnung = StockOwnership::article_map();

		foreach ( (array) $orders as $order ) {
			if ( $order->get_meta( self::META_BOOKED ) ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				$product_id = (int) $item->get_product_id();
				$article_id = isset( $zuordnung[ $product_id ] ) ? $zuordnung[ $product_id ] : 0;

				if ( ! $article_id ) {
					continue;
				}

				$color   = ColorRegistry::resolve_order_item( $item );
				$lines[] = array(
					'article_id'  => $article_id,
					'color'       => $color['color'],
					'qty'         => (float) $item->get_quantity(),
					'wc_order_id' => (int) $order->get_id(),
				);
			}
		}

		return $lines;
	}

	/**
	 * Metabox „Betriebsdaten" registrieren.
	 */
	public function add_meta_box() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

		foreach ( $screens as $screen ) {
			add_meta_box(
				'lw16-betrieb',
				__( 'Betriebsdaten', 'lindenweg16' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Metabox ausgeben.
	 *
	 * @param mixed $post_or_order Beitrag oder Bestellung.
	 */
	public function render_meta_box( $post_or_order ) {
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			echo '<p>' . esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) . '</p>';
			return;
		}

		$order_id = is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' )
			? $post_or_order->get_id()
			: (int) $post_or_order->ID;

		$id     = OrderOpsRepository::ensure_for_order( $order_id );
		$record = OrderOpsRepository::find( $id );

		wp_nonce_field( 'lw16_betrieb_order', 'lw16_betrieb_order_nonce' );

		printf(
			'<p><label>%s<br /><input type="number" name="lw16_handling_minutes" value="%s" min="0" step="1" class="widefat" /></label></p>',
			esc_html__( 'Handlingzeit (min)', 'lindenweg16' ),
			esc_attr( null === $record['handling_minutes'] ? '' : $record['handling_minutes'] )
		);

		printf(
			'<p><label><input type="checkbox" name="lw16_personalized" value="1"%s /> %s</label></p>',
			checked( $record['personalized'], 1, false ),
			esc_html__( 'Personalisiert', 'lindenweg16' )
		);

		printf(
			'<p><label><input type="checkbox" name="lw16_returned" value="1"%s /> %s</label></p>',
			checked( $record['returned'], 1, false ),
			esc_html__( 'Retoure', 'lindenweg16' )
		);

		printf(
			'<p><label><input type="checkbox" name="lw16_complaint" value="1"%s /> %s</label></p>',
			checked( $record['complaint'], 1, false ),
			esc_html__( 'Reklamation', 'lindenweg16' )
		);

		// Chargen der gebuchten Positionen – die Rückverfolgung im Ernstfall.
		$batches = self::batches_for_order( $order_id );
		if ( $batches ) {
			echo '<p><strong>' . esc_html__( 'Chargen', 'lindenweg16' ) . '</strong><br />';
			foreach ( $batches as $batch ) {
				printf( '<code>%s</code><br />', esc_html( $batch ) );
			}
			echo '</p>';
		}
	}

	/**
	 * Metabox speichern.
	 *
	 * @param int $order_id Bestellung.
	 */
	public function save_meta_box( $order_id ) {
		if ( ! isset( $_POST['lw16_betrieb_order_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lw16_betrieb_order_nonce'] ) ), 'lw16_betrieb_order' ) ) {
			return;
		}
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			return;
		}

		$id     = OrderOpsRepository::ensure_for_order( (int) $order_id );
		$record = OrderOpsRepository::find( $id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$input = array(
			'handling_minutes' => isset( $_POST['lw16_handling_minutes'] ) ? sanitize_text_field( wp_unslash( $_POST['lw16_handling_minutes'] ) ) : '',
			'personalized'     => isset( $_POST['lw16_personalized'] ) ? 1 : 0,
			'returned'         => isset( $_POST['lw16_returned'] ) ? 1 : 0,
			'complaint'        => isset( $_POST['lw16_complaint'] ) ? 1 : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Bestehende Werte erhalten, die die Metabox nicht anzeigt.
		foreach ( array( 'shipping_method', 'shipping_cost_actual', 'review_received', 'customer_type', 'channel', 'price_level', 'payment_terms_days', 'invoice_status', 'invoice_date', 'paid_date', 'note', 'contact_id' ) as $field ) {
			$input[ $field ] = $record[ $field ];
		}

		$saved = OrderOpsRepository::update_fields( $id, $input, __( 'Über die Bestellansicht geändert.', 'lindenweg16' ) );

		// Ein stiller Fehlschlag wäre hier besonders tückisch: Die Maske sähe
		// gespeichert aus, die Handlingzeit fehlte aber weiterhin.
		if ( ! $saved ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( __( 'Betrieb: Betriebsdaten nicht gespeichert – ein Geschäftskunde braucht einen zugeordneten Kontakt.', 'lindenweg16' ) );
				$order->save();
			}
		}
	}

	/**
	 * Spalte „Handlingzeit" in der Bestellliste.
	 *
	 * @param array $columns Spalten.
	 * @return array
	 */
	public function add_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['lw16_handling'] = __( 'Handling', 'lindenweg16' );
			}
		}

		if ( ! isset( $new['lw16_handling'] ) ) {
			$new['lw16_handling'] = __( 'Handling', 'lindenweg16' );
		}

		return $new;
	}

	/**
	 * Spalteninhalt ausgeben.
	 *
	 * @param string $column        Spalte.
	 * @param mixed  $post_or_order Beitrag oder Bestellung.
	 */
	public function render_column( $column, $post_or_order ) {
		if ( 'lw16_handling' !== $column ) {
			return;
		}

		$order_id = is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' )
			? $post_or_order->get_id()
			: (int) $post_or_order;

		$record = OrderOpsRepository::find_by_order( $order_id );

		if ( ! $record || null === $record['handling_minutes'] ) {
			// Fehlende Werte sollen auffallen – das ist der Zweck der Spalte.
			echo '<span class="lw16-ampel lw16-ampel--gelb">' . esc_html__( 'fehlt', 'lindenweg16' ) . '</span>';
			return;
		}

		$minutes = (float) $record['handling_minutes'];
		printf(
			'<span class="lw16-ampel lw16-ampel--%s">%s min</span>',
			esc_attr( $minutes > 12 ? 'rot' : ( $minutes > 9 ? 'gelb' : 'gruen' ) ),
			esc_html( number_format_i18n( $minutes, 0 ) )
		);
	}

	/**
	 * Chargencodes zu einer Bestellung – über die gebuchten Artikel.
	 *
	 * @param int $order_id Bestellung.
	 * @return string[]
	 */
	public static function batches_for_order( $order_id ) {
		global $wpdb;

		$stock   = StockRepository::table();
		$batches = BatchRepository::table();

		$codes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT b.batch_code
				 FROM {$stock} s
				 INNER JOIN {$batches} b ON b.article_id = s.article_id
				 WHERE s.ref_type = 'wc_order' AND s.ref_id = %d
				 AND b.produced_on <= DATE( s.moved_at )
				 ORDER BY b.produced_on DESC
				 LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $order_id
			)
		);

		return $codes ? $codes : array();
	}
}
