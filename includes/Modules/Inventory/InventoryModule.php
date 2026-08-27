<?php
/**
 * Modul 2: Lagerverwaltung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Inventory;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Modules\Accounting\BelegRepository;

/**
 * Verdrahtet die Lagerverwaltung: Echtzeit-Abzug bei Bestellabschluss,
 * Admin-Oberfläche, Wareneingang (inkl. Beleg-Verknüpfung).
 */
class InventoryModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Echtzeit-Lagerabzug bei abgeschlossener Bestellung. Laeuft auch
		// ohne Backend – der Status wechselt im Kundenkonto und per Webhook.
		add_action( 'woocommerce_order_status_completed', array( $this, 'deduct_stock_for_order' ), 20, 1 );

		// Täglicher Bestandsreport. Der Action Scheduler arbeitet seine
		// Warteschlange in gewoehnlichen Seitenaufrufen ab.
		add_action( \Lindenweg16\Core\Scheduler::HOOK_DAILY_STOCK, array( $this, 'send_daily_stock_report' ) );

		if ( ! is_admin() ) {
			return;
		}

		$this->register_admin();
	}

	/**
	 * Bildschirmmasken und Formularempfänger – nur im Backend.
	 *
	 * `admin_post_*` und die eigenen Seiten-Hooks kommen ausschliesslich im
	 * Adminkontext zum Zug. Im Frontend waeren es tote Eintraege in der
	 * Hakentabelle, die WordPress bei jedem `do_action` mitschleppt.
	 */
	protected function register_admin() {
		// Admin-Seite.
		add_action( 'lw16_render_page_lw16_lager', array( $this, 'render_admin' ) );
		add_action( 'admin_post_lw16_save_product', array( $this, 'handle_save_product' ) );
		add_action( 'admin_post_lw16_wareneingang', array( $this, 'handle_wareneingang' ) );
		add_action( 'admin_post_lw16_produktion', array( $this, 'handle_produktion' ) );
		add_action( 'admin_post_lw16_update_product', array( $this, 'handle_update_product' ) );
		add_action( 'admin_post_lw16_delete_product', array( $this, 'handle_delete_product' ) );

		// Lagerorte.
		add_action( 'admin_post_lw16_save_lagerort', array( $this, 'handle_save_lagerort' ) );
		add_action( 'admin_post_lw16_update_lagerort', array( $this, 'handle_update_lagerort' ) );
		add_action( 'admin_post_lw16_delete_lagerort', array( $this, 'handle_delete_lagerort' ) );
		add_action( 'admin_post_lw16_zuordnung_lagerort', array( $this, 'handle_zuordnung_lagerort' ) );

		// Picklisten.
		add_action( 'admin_post_lw16_create_pickliste', array( $this, 'handle_create_pickliste' ) );
		add_action( 'admin_post_lw16_complete_pickliste', array( $this, 'handle_complete_pickliste' ) );
		add_action( 'admin_post_lw16_download_pickliste', array( $this, 'handle_download_pickliste' ) );

		// Retouren.
		add_action( 'admin_post_lw16_retoure', array( $this, 'handle_retoure' ) );

		// WooCommerce-Produktsync.
		add_action( 'admin_post_lw16_wc_import', array( $this, 'handle_wc_import' ) );

		// Inventur.
		add_action( 'admin_post_lw16_inventur', array( $this, 'handle_inventur' ) );
	}

	/**
	 * Bestellpositionen in Lagerabgänge umsetzen (idempotent per Meta-Flag).
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public function deduct_stock_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Doppelabzug verhindern (HPOS-kompatibel über Order-Meta).
		if ( $order->get_meta( '_lw16_stock_deducted' ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$woo_product_id = $item->get_product_id();

			/**
			 * Produkt beim Lagerabzug überspringen.
			 *
			 * Das Modul Betrieb führt für die Artikel, die es kennt, ein
			 * eigenes Bewegungskonto. Ohne diesen Filter würde derselbe
			 * Abgang zweimal gebucht.
			 *
			 * @param bool $skip           Überspringen.
			 * @param int  $woo_product_id WooCommerce-Produkt-ID.
			 */
			if ( apply_filters( 'lw16_inventory_skip_product', false, $woo_product_id ) ) {
				continue;
			}

			$produkt = Repository::get_by_woo_id( $woo_product_id );
			if ( ! $produkt ) {
				continue;
			}
			$qty = (float) $item->get_quantity();
			StockManager::process_sale( (int) $produkt->id, $qty, 'Bestellung #' . $order->get_order_number() );
		}

		$order->update_meta_data( '_lw16_stock_deducted', current_time( 'mysql' ) );
		$order->save();
	}

	/* --------------------------------------------------------------------
	 * Admin-Oberfläche
	 * ------------------------------------------------------------------ */

	/**
	 * Lager-Admin-Seite rendern.
	 */
	public function render_admin() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Lagerverwaltung', 'lindenweg16' ) . '</h1>';

		$base = admin_url( 'admin.php?page=lw16-lager' );
		echo '<nav class="lw16-tabs">';
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( $base ), 'overview' === $view ? 'active' : '', esc_html__( 'Übersicht', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'produkt', $base ) ), 'produkt' === $view ? 'active' : '', esc_html__( 'Produkt anlegen', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'edit', $base ) ), 'edit' === $view ? 'active' : '', esc_html__( 'Produkt bearbeiten', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'wareneingang', $base ) ), 'wareneingang' === $view ? 'active' : '', esc_html__( 'Wareneingang', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'produktion', $base ) ), 'produktion' === $view ? 'active' : '', esc_html__( 'Produktion', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'lagerorte', $base ) ), 'lagerorte' === $view ? 'active' : '', esc_html__( 'Lagerorte', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'picklisten', $base ) ), 'picklisten' === $view ? 'active' : '', esc_html__( 'Picklisten', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'bewegungen', $base ) ), 'bewegungen' === $view ? 'active' : '', esc_html__( 'Bewegungen', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'retouren', $base ) ), 'retouren' === $view ? 'active' : '', esc_html__( 'Retouren', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'wc_import', $base ) ), 'wc_import' === $view ? 'active' : '', esc_html__( 'WC Import', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'inventur', $base ) ), 'inventur' === $view ? 'active' : '', esc_html__( 'Inventur', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( 'view', 'historie', $base ) ), 'historie' === $view ? 'active' : '', esc_html__( 'Historie', 'lindenweg16' ) );
		echo '</nav>';

		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		switch ( $view ) {
			case 'produkt':
				$this->render_product_form();
				break;
			case 'edit':
				$this->render_edit_form();
				break;
			case 'wareneingang':
				$this->render_wareneingang_form();
				break;
			case 'produktion':
				$this->render_produktion_form();
				break;
			case 'lagerorte':
				$this->render_lagerorte();
				break;
			case 'lagerort_edit':
				$this->render_lagerort_edit();
				break;
			case 'lagerort_zuordnung':
				$this->render_lagerort_zuordnung();
				break;
			case 'picklisten':
				$this->render_picklisten();
				break;
			case 'pickliste_detail':
				$this->render_pickliste_detail();
				break;
			case 'bewegungen':
				$this->render_movements();
				break;
			case 'retouren':
				$this->render_retouren();
				break;
			case 'wc_import':
				$this->render_wc_import();
				break;
			case 'inventur':
				$this->render_inventur();
				break;
			case 'historie':
				$this->render_historie();
				break;
			default:
				$this->render_overview();
		}
		echo '</div>';
	}

	/**
	 * Bestandsübersicht mit Ampel.
	 */
	protected function render_overview() {
		$produkte = Repository::get_products();
		$base     = admin_url( 'admin.php?page=lw16-lager' );
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Typ', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'WooCommerce', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Bestand', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Lagerorte', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Mindestbestand', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Aktion', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $produkte as $p ) {
			$ampel   = StockManager::ampel( $p );
			$bestand = Repository::get_stock( $p->id );
			echo '<tr>';
			echo '<td><span class="lw16-ampel lw16-ampel-' . esc_attr( $ampel ) . '" title="' . esc_attr( $ampel ) . '"></span></td>';
			echo '<td>' . esc_html( $p->name ) . '</td>';
			echo '<td>' . esc_html( $p->typ ) . '</td>';
			// WooCommerce-Verknüpfung anzeigen.
			echo '<td>';
			if ( $p->woo_product_id ) {
				$wc = function_exists( 'wc_get_product' ) ? wc_get_product( $p->woo_product_id ) : null;
				$wc_name = $wc ? $wc->get_name() : '#' . $p->woo_product_id;
				$wc_edit = admin_url( 'post.php?post=' . (int) $p->woo_product_id . '&action=edit' );
				echo '<a href="' . esc_url( $wc_edit ) . '" title="' . esc_attr__( 'WooCommerce-Produkt bearbeiten', 'lindenweg16' ) . '">' . esc_html( $wc_name ) . '</a>';
			} else {
				echo '<span class="description">' . esc_html__( 'nicht verknüpft', 'lindenweg16' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $this->fmt( $bestand ) . ' ' . $p->basis_einheit ) . '</td>';
			// Lagerorte mit Bestand.
			$ort_bestaende = Repository::get_stock_by_location( $p->id );
			echo '<td>';
			if ( ! empty( $ort_bestaende ) ) {
				$ort_parts = array();
				foreach ( $ort_bestaende as $ob ) {
					if ( (float) $ob->menge > 0 ) {
						$ort_parts[] = esc_html( $ob->kuerzel . ': ' . $this->fmt( $ob->menge ) );
					}
				}
				echo implode( '<br>', $ort_parts ) ?: '<span class="description">–</span>';
			} else {
				echo '<span class="description">–</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $this->fmt( $p->mindestbestand ) ) . '</td>';
			$edit = add_query_arg( array( 'view' => 'edit', 'id' => $p->id ), $base );
			$del  = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_delete_product&id=' . $p->id ), 'lw16_delete_product_' . $p->id );
			echo '<td><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Bearbeiten', 'lindenweg16' ) . '</a> | <a href="' . esc_url( $del ) . '" onclick="return confirm(\'' . esc_attr__( 'Produkt wirklich löschen?', 'lindenweg16' ) . '\')">' . esc_html__( 'Löschen', 'lindenweg16' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Formular zum Anlegen eines Produkts inkl. Stückliste/Rezeptur.
	 */
	protected function render_product_form() {
		$module_liste = Repository::get_products( 'modul' );
		$roh_liste    = Repository::get_products( 'rohmaterial' );

		$wc_products = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$wc_products = wc_get_products( array( 'limit' => 200, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC', 'return' => 'objects' ) );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_save_product">
			<?php wp_nonce_field( 'lw16_save_product' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Name', 'lindenweg16' ); ?></th><td><input type="text" name="name" class="regular-text" required></td></tr>
				<tr><th><?php esc_html_e( 'Typ', 'lindenweg16' ); ?></th><td>
					<select name="typ" id="lw16-typ">
						<option value="set"><?php esc_html_e( 'Set', 'lindenweg16' ); ?></option>
						<option value="modul"><?php esc_html_e( 'Modul', 'lindenweg16' ); ?></option>
						<option value="rohmaterial"><?php esc_html_e( 'Rohmaterial', 'lindenweg16' ); ?></option>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'WooCommerce-Produkt', 'lindenweg16' ); ?></th><td>
					<select name="woo_product_id">
						<option value=""><?php esc_html_e( '— kein WooCommerce-Produkt —', 'lindenweg16' ); ?></option>
						<?php foreach ( $wc_products as $wc ) : ?>
							<option value="<?php echo (int) $wc->get_id(); ?>">
								<?php echo esc_html( $wc->get_name() . ' (#' . $wc->get_id() . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'optional, für Sets/Module', 'lindenweg16' ); ?></span>
				</td></tr>
				<tr class="lw16-roh-only"><th><?php esc_html_e( 'Rohmaterial-Kategorie', 'lindenweg16' ); ?></th><td>
					<select name="rohmaterial_kategorie">
						<option value="kaufteil"><?php esc_html_e( 'Kaufteil (mit Beleg)', 'lindenweg16' ); ?></option>
						<option value="gefertigt"><?php esc_html_e( 'Gefertigt (ohne Beleg)', 'lindenweg16' ); ?></option>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Basis-Einheit', 'lindenweg16' ); ?></th><td>
					<select name="basis_einheit">
						<option value="stueck"><?php esc_html_e( 'Stück', 'lindenweg16' ); ?></option>
						<option value="gramm">Gramm</option>
						<option value="milliliter">Milliliter</option>
						<option value="zentimeter">Zentimeter</option>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Einkaufseinheit-Name', 'lindenweg16' ); ?></th><td><input type="text" name="einkaufseinheit_name" class="regular-text" placeholder="<?php esc_attr_e( 'z. B. Spule, Packung', 'lindenweg16' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Umrechnung (Basis je Einkaufseinheit)', 'lindenweg16' ); ?></th><td><input type="number" step="any" name="einkaufseinheit_umrechnung" class="small-text" placeholder="1000"></td></tr>
				<tr><th><?php esc_html_e( 'Mindestbestand (Basis-Einheit)', 'lindenweg16' ); ?></th><td><input type="number" step="any" name="mindestbestand" class="small-text" value="0"></td></tr>
			</tbody></table>

			<h2><?php esc_html_e( 'Stückliste (nur bei Set): enthaltene Module', 'lindenweg16' ); ?></h2>
			<table class="widefat striped"><thead><tr>
				<th></th><th><?php esc_html_e( 'Modul', 'lindenweg16' ); ?></th><th><?php esc_html_e( 'Anzahl', 'lindenweg16' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $module_liste as $m ) : ?>
				<tr>
					<td><input type="checkbox" name="stueckliste_aktiv[<?php echo (int) $m->id; ?>]" value="1" class="lw16-set-check"></td>
					<td><?php echo esc_html( $m->name ); ?></td>
					<td><input type="number" step="1" min="1" name="stueckliste[<?php echo (int) $m->id; ?>]" value="1" class="small-text"></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2><?php esc_html_e( 'Rezeptur (nur bei Modul): Rohmaterial → Menge (Basis-Einheit)', 'lindenweg16' ); ?></h2>
			<table class="widefat striped"><tbody>
			<?php foreach ( $roh_liste as $r ) : ?>
				<tr><td><?php echo esc_html( $r->name ); ?></td><td><input type="number" step="any" name="rezeptur[<?php echo (int) $r->id; ?>]" value="0" class="small-text"> <?php echo esc_html( $r->basis_einheit ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>

			<?php submit_button( __( 'Produkt speichern', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Formular zum Bearbeiten eines bestehenden Produkts inkl. Stückliste/Rezeptur.
	 */
	protected function render_edit_form() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id ) {
			$produkte = Repository::get_products();
			echo '<h2>' . esc_html__( 'Produkt auswählen', 'lindenweg16' ) . '</h2>';
			echo '<ul>';
			$base = admin_url( 'admin.php?page=lw16-lager&view=edit' );
			foreach ( $produkte as $p ) {
				echo '<li><a href="' . esc_url( add_query_arg( 'id', $p->id, $base ) ) . '">' . esc_html( $p->name ) . ' (' . esc_html( $p->typ ) . ')</a></li>';
			}
			echo '</ul>';
			return;
		}

		$produkt = Repository::get_product( $id );
		if ( ! $produkt ) {
			echo '<p>' . esc_html__( 'Produkt nicht gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}

		$module_liste = Repository::get_products( 'modul' );
		$roh_liste    = Repository::get_products( 'rohmaterial' );
		$stueckliste  = array();
		foreach ( Repository::get_stueckliste( $id ) as $s ) {
			$stueckliste[ (int) $s->modul_id ] = (float) $s->menge;
		}
		$rezeptur = array();
		foreach ( Repository::get_rezeptur( $id ) as $r ) {
			$rezeptur[ (int) $r->rohmaterial_id ] = (float) $r->menge;
		}

		// WooCommerce-Produkte laden für den Picker.
		$wc_products = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$wc_products = wc_get_products( array( 'limit' => 200, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC', 'return' => 'objects' ) );
		}
		?>
		<h2><?php echo esc_html( sprintf( /* translators: %s: Produktname */ __( 'Produkt bearbeiten: %s', 'lindenweg16' ), $produkt->name ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_update_product">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'lw16_update_product_' . $id ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Name', 'lindenweg16' ); ?></th><td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $produkt->name ); ?>" required></td></tr>
				<tr><th><?php esc_html_e( 'Typ', 'lindenweg16' ); ?></th><td><strong><?php echo esc_html( $produkt->typ ); ?></strong> <span class="description"><?php esc_html_e( '(nicht änderbar)', 'lindenweg16' ); ?></span></td></tr>
				<tr><th><?php esc_html_e( 'WooCommerce-Produkt', 'lindenweg16' ); ?></th><td>
					<select name="woo_product_id">
						<option value=""><?php esc_html_e( '— kein WooCommerce-Produkt —', 'lindenweg16' ); ?></option>
						<?php foreach ( $wc_products as $wc ) : ?>
							<option value="<?php echo (int) $wc->get_id(); ?>" <?php selected( (int) $produkt->woo_product_id, $wc->get_id() ); ?>>
								<?php echo esc_html( $wc->get_name() . ' (#' . $wc->get_id() . ')' ); ?>
							</option>
						<?php endforeach; ?>
						<?php if ( $produkt->woo_product_id && ! in_array( (int) $produkt->woo_product_id, array_map( function ( $w ) { return $w->get_id(); }, $wc_products ), true ) ) : ?>
							<option value="<?php echo (int) $produkt->woo_product_id; ?>" selected>
								<?php echo esc_html( '#' . $produkt->woo_product_id . ' (' . __( 'nicht gefunden', 'lindenweg16' ) . ')' ); ?>
							</option>
						<?php endif; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Verknüpft das Lagerprodukt mit einem WooCommerce-Shop-Produkt.', 'lindenweg16' ); ?></span>
				</td></tr>
				<?php if ( 'rohmaterial' === $produkt->typ ) : ?>
				<tr><th><?php esc_html_e( 'Rohmaterial-Kategorie', 'lindenweg16' ); ?></th><td>
					<select name="rohmaterial_kategorie">
						<option value="kaufteil" <?php selected( $produkt->rohmaterial_kategorie, 'kaufteil' ); ?>><?php esc_html_e( 'Kaufteil (mit Beleg)', 'lindenweg16' ); ?></option>
						<option value="gefertigt" <?php selected( $produkt->rohmaterial_kategorie, 'gefertigt' ); ?>><?php esc_html_e( 'Gefertigt (ohne Beleg)', 'lindenweg16' ); ?></option>
					</select>
				</td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Basis-Einheit', 'lindenweg16' ); ?></th><td>
					<select name="basis_einheit">
						<option value="stueck" <?php selected( $produkt->basis_einheit, 'stueck' ); ?>><?php esc_html_e( 'Stück', 'lindenweg16' ); ?></option>
						<option value="gramm" <?php selected( $produkt->basis_einheit, 'gramm' ); ?>>Gramm</option>
						<option value="milliliter" <?php selected( $produkt->basis_einheit, 'milliliter' ); ?>>Milliliter</option>
						<option value="zentimeter" <?php selected( $produkt->basis_einheit, 'zentimeter' ); ?>>Zentimeter</option>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Einkaufseinheit-Name', 'lindenweg16' ); ?></th><td><input type="text" name="einkaufseinheit_name" class="regular-text" value="<?php echo esc_attr( $produkt->einkaufseinheit_name ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Umrechnung (Basis je Einkaufseinheit)', 'lindenweg16' ); ?></th><td><input type="number" step="any" name="einkaufseinheit_umrechnung" class="small-text" value="<?php echo esc_attr( $produkt->einkaufseinheit_umrechnung ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Mindestbestand (Basis-Einheit)', 'lindenweg16' ); ?></th><td><input type="number" step="any" name="mindestbestand" class="small-text" value="<?php echo esc_attr( $produkt->mindestbestand ); ?>"></td></tr>
			</tbody></table>

			<?php if ( 'set' === $produkt->typ ) : ?>
			<h2><?php esc_html_e( 'Stückliste: enthaltene Module', 'lindenweg16' ); ?></h2>
			<table class="widefat striped"><thead><tr>
				<th></th><th><?php esc_html_e( 'Modul', 'lindenweg16' ); ?></th><th><?php esc_html_e( 'Anzahl', 'lindenweg16' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $module_liste as $m ) :
				$aktiv = isset( $stueckliste[ $m->id ] ) && $stueckliste[ $m->id ] > 0;
				$menge = $aktiv ? $stueckliste[ $m->id ] : 1;
			?>
				<tr>
					<td><input type="checkbox" name="stueckliste_aktiv[<?php echo (int) $m->id; ?>]" value="1" <?php checked( $aktiv ); ?> class="lw16-set-check"></td>
					<td><?php echo esc_html( $m->name ); ?></td>
					<td><input type="number" step="1" min="1" name="stueckliste[<?php echo (int) $m->id; ?>]" value="<?php echo esc_attr( $menge ); ?>" class="small-text"></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="description"><?php esc_html_e( 'Den Set-Rabatt legst du in der Preiskalkulation fest.', 'lindenweg16' ); ?></p>
			<?php endif; ?>

			<?php if ( 'modul' === $produkt->typ ) : ?>
			<h2><?php esc_html_e( 'Rezeptur: Rohmaterial → Menge (Basis-Einheit)', 'lindenweg16' ); ?></h2>
			<table class="widefat striped"><tbody>
			<?php foreach ( $roh_liste as $r ) : ?>
				<tr><td><?php echo esc_html( $r->name ); ?></td><td><input type="number" step="any" name="rezeptur[<?php echo (int) $r->id; ?>]" value="<?php echo esc_attr( $rezeptur[ $r->id ] ?? 0 ); ?>" class="small-text"> <?php echo esc_html( $r->basis_einheit ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php endif; ?>

			<?php submit_button( __( 'Änderungen speichern', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Wareneingangsformular (mit dynamischer Beleg-Sektion bei Kaufteil).
	 */
	protected function render_wareneingang_form() {
		$roh       = Repository::get_products( 'rohmaterial' );
		$lagerorte = Repository::get_lagerorte();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="lw16_wareneingang">
			<?php wp_nonce_field( 'lw16_wareneingang' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Rohmaterial', 'lindenweg16' ); ?></th><td>
					<select name="produkt_id" id="lw16-we-produkt">
						<?php foreach ( $roh as $r ) : ?>
							<option value="<?php echo (int) $r->id; ?>"
								data-kategorie="<?php echo esc_attr( $r->rohmaterial_kategorie ); ?>"
								data-einheit="<?php echo esc_attr( $r->basis_einheit ); ?>"
								data-ekname="<?php echo esc_attr( $r->einkaufseinheit_name ); ?>"
								data-umrechnung="<?php echo esc_attr( $r->einkaufseinheit_umrechnung ); ?>">
								<?php echo esc_html( $r->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Datum', 'lindenweg16' ); ?></th><td><input type="date" name="datum" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Menge', 'lindenweg16' ); ?></th><td><input type="number" step="any" name="menge" class="small-text" required> <span id="lw16-we-einheit-hint" class="description"></span></td></tr>
				<?php if ( ! empty( $lagerorte ) ) : ?>
				<tr><th><?php esc_html_e( 'Lagerort', 'lindenweg16' ); ?></th><td>
					<select name="lagerort_id">
						<option value=""><?php esc_html_e( '— kein Lagerort —', 'lindenweg16' ); ?></option>
						<?php foreach ( $lagerorte as $lo ) : ?>
							<option value="<?php echo (int) $lo->id; ?>"><?php echo esc_html( $lo->kuerzel . ' – ' . $lo->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<?php endif; ?>
			</tbody></table>

			<div id="lw16-beleg-felder" style="display:none">
				<h2><?php esc_html_e( 'Beleg (nur bei Kaufteil)', 'lindenweg16' ); ?></h2>
				<table class="form-table"><tbody>
					<tr><th><?php esc_html_e( 'Beleg-Foto', 'lindenweg16' ); ?></th><td><input type="file" name="beleg_foto" accept="image/*"></td></tr>
					<tr><th><?php esc_html_e( 'Betrag (€)', 'lindenweg16' ); ?></th><td><input type="number" step="0.01" name="betrag" class="small-text"></td></tr>
					<tr><th><?php esc_html_e( 'Händler', 'lindenweg16' ); ?></th><td><input type="text" name="haendler" class="regular-text"></td></tr>
					<tr><th><?php esc_html_e( 'EÜR-Kategorie', 'lindenweg16' ); ?></th><td><input type="text" name="eur_kategorie" class="regular-text" value="Wareneinkauf"></td></tr>
				</tbody></table>
			</div>

			<?php submit_button( __( 'Wareneingang buchen', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Formular für manuelle Produktion (Module aus Rohmaterial gefertigt).
	 */
	protected function render_produktion_form() {
		$module_liste = Repository::get_products( 'modul' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_produktion">
			<?php wp_nonce_field( 'lw16_produktion' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Modul', 'lindenweg16' ); ?></th><td>
					<select name="produkt_id">
						<?php foreach ( $module_liste as $m ) : ?>
							<option value="<?php echo (int) $m->id; ?>"><?php echo esc_html( $m->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Gefertigte Menge', 'lindenweg16' ); ?></th><td><input type="number" step="any" name="menge" class="small-text" required></td></tr>
			</tbody></table>
			<p class="description"><?php esc_html_e( 'Der zugehörige Rohmaterial-Verbrauch laut Rezeptur wird automatisch abgezogen.', 'lindenweg16' ); ?></p>
			<?php submit_button( __( 'Produktion buchen', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Paginierte Bewegungshistorie.
	 */
	protected function render_movements() {
		global $wpdb;
		$per_page = 50;
		$page     = isset( $_GET['bp'] ) ? max( 1, (int) $_GET['bp'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$offset   = ( $page - 1 ) * $per_page;
		$tb        = Repository::table( 'lagerbewegung' );
		$tp        = Repository::table( 'produkte' );

		$tl     = Repository::table( 'lagerorte' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.*, p.name AS produkt_name, p.basis_einheit, l.kuerzel AS lagerort_kuerzel FROM {$tb} b LEFT JOIN {$tp} p ON p.id = b.produkt_id LEFT JOIN {$tl} l ON l.id = b.lagerort_id ORDER BY b.id DESC LIMIT %d OFFSET %d", $per_page, $offset ) ); // phpcs:ignore
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tb}" ); // phpcs:ignore

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Produkt', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Lagerort', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Menge', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Typ', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Referenz', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Beleg', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( $r->erstellt_am ) . '</td>';
			echo '<td>' . esc_html( $r->produkt_name ) . '</td>';
			echo '<td>' . esc_html( $r->lagerort_kuerzel ?: '–' ) . '</td>';
			echo '<td>' . esc_html( $this->fmt( $r->menge ) . ' ' . $r->basis_einheit ) . '</td>';
			echo '<td>' . esc_html( $r->typ ) . '</td>';
			echo '<td>' . esc_html( (string) $r->referenz ) . '</td>';
			echo '<td>' . ( $r->beleg_id ? '#' . (int) $r->beleg_id : '–' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$this->pagination( $total, $per_page, $page, 'bp' );
	}

	/* --------------------------------------------------------------------
	 * POST-Handler
	 * ------------------------------------------------------------------ */

	/**
	 * Produkt speichern.
	 */
	public function handle_save_product() {
		$this->guard( 'lw16_save_product' );

		$typ  = isset( $_POST['typ'] ) ? sanitize_key( wp_unslash( $_POST['typ'] ) ) : 'modul';
		$data = array(
			'name'                       => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'typ'                        => in_array( $typ, array( 'set', 'modul', 'rohmaterial' ), true ) ? $typ : 'modul',
			'woo_product_id'             => ! empty( $_POST['woo_product_id'] ) ? (int) $_POST['woo_product_id'] : null,
			'rohmaterial_kategorie'      => 'rohmaterial' === $typ ? sanitize_key( wp_unslash( $_POST['rohmaterial_kategorie'] ?? 'kaufteil' ) ) : null,
			'basis_einheit'              => sanitize_key( wp_unslash( $_POST['basis_einheit'] ?? 'stueck' ) ),
			'einkaufseinheit_name'       => sanitize_text_field( wp_unslash( $_POST['einkaufseinheit_name'] ?? '' ) ) ?: null,
			'einkaufseinheit_umrechnung' => ! empty( $_POST['einkaufseinheit_umrechnung'] ) ? (float) $_POST['einkaufseinheit_umrechnung'] : null,
			'mindestbestand'             => (float) ( $_POST['mindestbestand'] ?? 0 ),
		);

		$id = Repository::create_product( $data );

		if ( 'set' === $typ && ! empty( $_POST['stueckliste'] ) && is_array( $_POST['stueckliste'] ) ) {
			$aktiv = isset( $_POST['stueckliste_aktiv'] ) && is_array( $_POST['stueckliste_aktiv'] ) ? wp_unslash( $_POST['stueckliste_aktiv'] ) : array();
			$pos   = array();
			foreach ( wp_unslash( $_POST['stueckliste'] ) as $modul_id => $menge ) {
				if ( ! empty( $aktiv[ $modul_id ] ) ) {
					$pos[ (int) $modul_id ] = max( 1, (float) $menge );
				}
			}
			Repository::set_stueckliste( $id, $pos );
		}

		if ( 'modul' === $typ && ! empty( $_POST['rezeptur'] ) && is_array( $_POST['rezeptur'] ) ) {
			$pos = array();
			foreach ( wp_unslash( $_POST['rezeptur'] ) as $roh_id => $menge ) {
				$pos[ (int) $roh_id ] = (float) $menge;
			}
			Repository::set_rezeptur( $id, $pos );
		}

		$this->redirect( 'lager', __( 'Produkt gespeichert.', 'lindenweg16' ) );
	}

	/**
	 * Wareneingang verbuchen (inkl. Beleg bei Kaufteil).
	 */
	public function handle_wareneingang() {
		$this->guard( 'lw16_wareneingang' );

		$produkt_id = (int) ( $_POST['produkt_id'] ?? 0 );
		$produkt    = Repository::get_product( $produkt_id );
		if ( ! $produkt ) {
			$this->redirect( 'lager', __( 'Rohmaterial nicht gefunden.', 'lindenweg16' ) );
		}

		$menge_eingabe = (float) ( $_POST['menge'] ?? 0 );

		// In Basis-Einheit umrechnen, falls Einkaufseinheit hinterlegt.
		$menge_basis = $menge_eingabe;
		if ( ! empty( $produkt->einkaufseinheit_name ) && (float) $produkt->einkaufseinheit_umrechnung > 0 ) {
			$menge_basis = $menge_eingabe * (float) $produkt->einkaufseinheit_umrechnung;
		}

		$beleg_id = null;

		// Beleg nur bei Kaufteil anlegen.
		if ( 'kaufteil' === $produkt->rohmaterial_kategorie ) {
			$datei_pfad = '';
			if ( ! empty( $_FILES['beleg_foto']['name'] ) ) {
				$datei_pfad = BelegRepository::store_upload( $_FILES['beleg_foto'] );
			}
			$beleg_id = BelegRepository::create(
				array(
					'datei_pfad'    => $datei_pfad,
					'betrag'        => (float) ( $_POST['betrag'] ?? 0 ),
					'datum'         => sanitize_text_field( wp_unslash( $_POST['datum'] ?? gmdate( 'Y-m-d' ) ) ),
					'haendler'      => sanitize_text_field( wp_unslash( $_POST['haendler'] ?? '' ) ),
					'eur_kategorie' => sanitize_text_field( wp_unslash( $_POST['eur_kategorie'] ?? 'Wareneinkauf' ) ),
					'quelle'        => 'wareneingang',
				)
			);
		}

		$lagerort_id = ! empty( $_POST['lagerort_id'] ) ? (int) $_POST['lagerort_id'] : null;
		StockManager::record_movement(
			$produkt_id,
			abs( $menge_basis ),
			'wareneingang',
			array(
				'beleg_id'    => $beleg_id,
				'lagerort_id' => $lagerort_id,
				'referenz'    => __( 'Wareneingang', 'lindenweg16' ),
			)
		);

		$this->redirect( 'lager', __( 'Wareneingang gebucht.', 'lindenweg16' ) );
	}

	/**
	 * Manuelle Produktion verbuchen.
	 */
	public function handle_produktion() {
		$this->guard( 'lw16_produktion' );

		$produkt_id = (int) ( $_POST['produkt_id'] ?? 0 );
		$menge      = abs( (float) ( $_POST['menge'] ?? 0 ) );
		$produkt    = Repository::get_product( $produkt_id );
		if ( ! $produkt || 'modul' !== $produkt->typ || $menge <= 0 ) {
			$this->redirect( 'lager', __( 'Ungültige Produktionsangabe.', 'lindenweg16' ) );
		}

		// Modul-Zugang.
		StockManager::record_movement( $produkt_id, $menge, 'produktion', array( 'referenz' => __( 'Manuelle Produktion', 'lindenweg16' ) ) );

		// Rohmaterial-Verbrauch laut Rezeptur.
		foreach ( Repository::get_rezeptur( $produkt_id ) as $rez ) {
			StockManager::record_movement( (int) $rez->rohmaterial_id, -1 * $menge * (float) $rez->menge, 'produktion', array( 'referenz' => __( 'Verbrauch Produktion', 'lindenweg16' ) ) );
		}

		$this->redirect( 'lager', __( 'Produktion gebucht.', 'lindenweg16' ) );
	}

	/**
	 * Bestehendes Produkt aktualisieren.
	 */
	public function handle_update_product() {
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_update_product_' . $id );

		$produkt = Repository::get_product( $id );
		if ( ! $produkt ) {
			$this->redirect( 'lager', __( 'Produkt nicht gefunden.', 'lindenweg16' ) );
		}

		$data = array(
			'name'                       => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'woo_product_id'             => ! empty( $_POST['woo_product_id'] ) ? (int) $_POST['woo_product_id'] : null,
			'basis_einheit'              => sanitize_key( wp_unslash( $_POST['basis_einheit'] ?? 'stueck' ) ),
			'einkaufseinheit_name'       => sanitize_text_field( wp_unslash( $_POST['einkaufseinheit_name'] ?? '' ) ) ?: null,
			'einkaufseinheit_umrechnung' => ! empty( $_POST['einkaufseinheit_umrechnung'] ) ? (float) $_POST['einkaufseinheit_umrechnung'] : null,
			'mindestbestand'             => (float) ( $_POST['mindestbestand'] ?? 0 ),
		);
		if ( 'rohmaterial' === $produkt->typ ) {
			$data['rohmaterial_kategorie'] = sanitize_key( wp_unslash( $_POST['rohmaterial_kategorie'] ?? 'kaufteil' ) );
		}

		Repository::update_product( $id, $data );

		if ( 'set' === $produkt->typ && ! empty( $_POST['stueckliste'] ) && is_array( $_POST['stueckliste'] ) ) {
			$aktiv = isset( $_POST['stueckliste_aktiv'] ) && is_array( $_POST['stueckliste_aktiv'] ) ? wp_unslash( $_POST['stueckliste_aktiv'] ) : array();
			$pos   = array();
			foreach ( wp_unslash( $_POST['stueckliste'] ) as $modul_id => $menge ) {
				if ( ! empty( $aktiv[ $modul_id ] ) ) {
					$pos[ (int) $modul_id ] = max( 1, (float) $menge );
				}
			}
			Repository::set_stueckliste( $id, $pos );
		}

		if ( 'modul' === $produkt->typ && ! empty( $_POST['rezeptur'] ) && is_array( $_POST['rezeptur'] ) ) {
			$pos = array();
			foreach ( wp_unslash( $_POST['rezeptur'] ) as $roh_id => $menge ) {
				$pos[ (int) $roh_id ] = (float) $menge;
			}
			Repository::set_rezeptur( $id, $pos );
		}

		$this->redirect( 'lager', __( 'Produkt aktualisiert.', 'lindenweg16' ) );
	}

	/**
	 * Produkt löschen.
	 */
	public function handle_delete_product() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_delete_product_' . $id );
		Repository::delete_product( $id );
		$this->redirect( 'lager', __( 'Produkt gelöscht.', 'lindenweg16' ) );
	}

	/* --------------------------------------------------------------------
	 * Lagerorte
	 * ------------------------------------------------------------------ */

	protected function render_lagerorte() {
		$lagerorte = Repository::get_lagerorte( false );
		$base      = admin_url( 'admin.php?page=lw16-lager' );
		?>
		<h2><?php esc_html_e( 'Lagerorte verwalten', 'lindenweg16' ); ?></h2>

		<table class="widefat striped"><thead><tr>
			<th><?php esc_html_e( 'Kürzel', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Name', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Beschreibung', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Barcode', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Aktiv', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Aktion', 'lindenweg16' ); ?></th>
		</tr></thead><tbody>
		<?php if ( empty( $lagerorte ) ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'Noch keine Lagerorte angelegt.', 'lindenweg16' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $lagerorte as $lo ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $lo->kuerzel ); ?></strong></td>
				<td><?php echo esc_html( $lo->name ); ?></td>
				<td><?php echo esc_html( $lo->beschreibung ); ?></td>
				<td><div class="lw16-barcode" data-value="<?php echo esc_attr( $lo->kuerzel ); ?>"></div></td>
				<td><?php echo $lo->aktiv ? '&#10003;' : '&#10007;'; ?></td>
				<td>
					<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'lagerort_edit', 'id' => $lo->id ), $base ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'lindenweg16' ); ?></a> |
					<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'lagerort_zuordnung', 'id' => $lo->id ), $base ) ); ?>"><?php esc_html_e( 'Bestände', 'lindenweg16' ); ?></a> |
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lw16_delete_lagerort&id=' . $lo->id ), 'lw16_delete_lagerort_' . $lo->id ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Lagerort wirklich löschen?', 'lindenweg16' ); ?>')"><?php esc_html_e( 'Löschen', 'lindenweg16' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>

		<h3><?php esc_html_e( 'Neuen Lagerort anlegen', 'lindenweg16' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_save_lagerort">
			<?php wp_nonce_field( 'lw16_save_lagerort' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Kürzel (z. B. A-01, R2-F3)', 'lindenweg16' ); ?></th><td><input type="text" name="kuerzel" class="regular-text" required maxlength="20" pattern="[A-Za-z0-9_\-]+"></td></tr>
				<tr><th><?php esc_html_e( 'Name', 'lindenweg16' ); ?></th><td><input type="text" name="name" class="regular-text" required></td></tr>
				<tr><th><?php esc_html_e( 'Beschreibung', 'lindenweg16' ); ?></th><td><textarea name="beschreibung" rows="2" class="large-text"></textarea></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Lagerort speichern', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	protected function render_lagerort_edit() {
		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$ort = $id ? Repository::get_lagerort( $id ) : null;
		if ( ! $ort ) {
			echo '<p>' . esc_html__( 'Lagerort nicht gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}
		?>
		<h2><?php echo esc_html( sprintf( __( 'Lagerort bearbeiten: %s', 'lindenweg16' ), $ort->kuerzel ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_update_lagerort">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'lw16_update_lagerort_' . $id ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Kürzel', 'lindenweg16' ); ?></th><td><input type="text" name="kuerzel" class="regular-text" value="<?php echo esc_attr( $ort->kuerzel ); ?>" required maxlength="20" pattern="[A-Za-z0-9_\-]+"></td></tr>
				<tr><th><?php esc_html_e( 'Name', 'lindenweg16' ); ?></th><td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $ort->name ); ?>" required></td></tr>
				<tr><th><?php esc_html_e( 'Beschreibung', 'lindenweg16' ); ?></th><td><textarea name="beschreibung" rows="2" class="large-text"><?php echo esc_textarea( $ort->beschreibung ); ?></textarea></td></tr>
				<tr><th><?php esc_html_e( 'Aktiv', 'lindenweg16' ); ?></th><td><label><input type="checkbox" name="aktiv" value="1" <?php checked( $ort->aktiv ); ?>> <?php esc_html_e( 'Lagerort ist aktiv', 'lindenweg16' ); ?></label></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Änderungen speichern', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	protected function render_lagerort_zuordnung() {
		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$ort = $id ? Repository::get_lagerort( $id ) : null;
		if ( ! $ort ) {
			echo '<p>' . esc_html__( 'Lagerort nicht gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}
		$produkte = Repository::get_products();
		?>
		<h2><?php echo esc_html( sprintf( __( 'Bestände am Lagerort: %s – %s', 'lindenweg16' ), $ort->kuerzel, $ort->name ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_zuordnung_lagerort">
			<input type="hidden" name="lagerort_id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'lw16_zuordnung_lagerort_' . $id ); ?>
			<table class="widefat striped"><thead><tr>
				<th><?php esc_html_e( 'Produkt', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'Typ', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'Gesamtbestand', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'Bestand an diesem Ort', 'lindenweg16' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $produkte as $p ) :
				$ort_menge = Repository::get_stock_at_location( $p->id, $id );
				$gesamt    = Repository::get_stock( $p->id );
			?>
				<tr>
					<td><?php echo esc_html( $p->name ); ?></td>
					<td><?php echo esc_html( $p->typ ); ?></td>
					<td><?php echo esc_html( $this->fmt( $gesamt ) . ' ' . $p->basis_einheit ); ?></td>
					<td><input type="number" step="any" min="0" name="bestand[<?php echo (int) $p->id; ?>]" value="<?php echo esc_attr( $ort_menge ); ?>" class="small-text"> <?php echo esc_html( $p->basis_einheit ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="description"><?php esc_html_e( 'Hier kannst du die Bestände an diesem Lagerort direkt setzen (Inventur / Ersteinrichtung).', 'lindenweg16' ); ?></p>
			<?php submit_button( __( 'Bestände speichern', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	/* --------------------------------------------------------------------
	 * Picklisten
	 * ------------------------------------------------------------------ */

	protected function render_picklisten() {
		$base     = admin_url( 'admin.php?page=lw16-lager' );
		$per_page = 25;
		$page     = isset( $_GET['pp'] ) ? max( 1, (int) $_GET['pp'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$offset   = ( $page - 1 ) * $per_page;
		$listen   = Repository::get_picklisten( null, $per_page, $offset );
		$total    = Repository::count_picklisten();
		?>
		<h2><?php esc_html_e( 'Picklisten', 'lindenweg16' ); ?></h2>

		<?php $unpicked = Repository::get_unpicked_order_ids(); ?>
		<div style="margin-bottom:16px">
			<h3><?php esc_html_e( 'Neue Pickliste erstellen', 'lindenweg16' ); ?></h3>
			<?php if ( empty( $unpicked ) ) : ?>
				<p><?php esc_html_e( 'Alle offenen Bestellungen haben bereits eine Pickliste.', 'lindenweg16' ); ?></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:8px">
				<input type="hidden" name="action" value="lw16_create_pickliste">
				<?php wp_nonce_field( 'lw16_create_pickliste' ); ?>
				<label><?php esc_html_e( 'Bestellung:', 'lindenweg16' ); ?>
					<select name="order_id" required>
						<option value=""><?php esc_html_e( '— Bestellung wählen —', 'lindenweg16' ); ?></option>
						<?php foreach ( $unpicked as $oid ) :
							$o = wc_get_order( $oid );
							if ( ! $o ) continue;
							$kunde = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
							$datum  = $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd.m.Y' ) : '';
							$label  = sprintf( '#%s – %s – %s (%s)', $o->get_order_number(), $kunde, $o->get_formatted_order_total(), $datum );
						?>
						<option value="<?php echo (int) $oid; ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php submit_button( __( 'Pickliste erstellen', 'lindenweg16' ), 'primary', 'submit', false ); ?>
			</form>
			<?php endif; ?>
		</div>

		<table class="widefat striped"><thead><tr>
			<th><?php esc_html_e( 'Nr.', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Bestellung', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Status', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Erstellt', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Aktion', 'lindenweg16' ); ?></th>
		</tr></thead><tbody>
		<?php if ( empty( $listen ) ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'Noch keine Picklisten vorhanden.', 'lindenweg16' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $listen as $pl ) : ?>
			<tr>
				<td>#<?php echo (int) $pl->id; ?></td>
				<td>#<?php echo (int) $pl->order_id; ?></td>
				<td>
					<?php if ( 'offen' === $pl->status ) : ?>
						<span class="lw16-ampel lw16-ampel-gelb" style="vertical-align:middle"></span> <?php esc_html_e( 'Offen', 'lindenweg16' ); ?>
					<?php else : ?>
						<span class="lw16-ampel lw16-ampel-gruen" style="vertical-align:middle"></span> <?php esc_html_e( 'Abgeschlossen', 'lindenweg16' ); ?>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $pl->erstellt_am ); ?></td>
				<td>
					<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'pickliste_detail', 'id' => $pl->id ), $base ) ); ?>"><?php esc_html_e( 'Ansehen', 'lindenweg16' ); ?></a>
					<?php if ( $pl->datei_pfad ) : ?>
						| <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lw16_download_pickliste&id=' . $pl->id ), 'lw16_download_pickliste_' . $pl->id ) ); ?>"><?php esc_html_e( 'PDF', 'lindenweg16' ); ?></a>
					<?php endif; ?>
					| <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'pickliste_detail', 'id' => $pl->id, 'mobile' => '1' ), $base ) ); ?>" target="_blank"><?php esc_html_e( 'Mobil/Scanner', 'lindenweg16' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
		$this->pagination( $total, $per_page, $page, 'pp' );
	}

	protected function render_pickliste_detail() {
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$mobile = isset( $_GET['mobile'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$pl     = $id ? Repository::get_pickliste( $id ) : null;
		if ( ! $pl ) {
			echo '<p>' . esc_html__( 'Pickliste nicht gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}

		if ( $mobile ) {
			$this->render_pickliste_mobile( $pl );
			return;
		}

		$positionen = Repository::get_pickliste_positionen( $id );
		$order      = function_exists( 'wc_get_order' ) ? wc_get_order( $pl->order_id ) : null;
		$base       = admin_url( 'admin.php?page=lw16-lager' );
		?>
		<h2><?php echo esc_html( sprintf( __( 'Pickliste #%d – Bestellung #%d', 'lindenweg16' ), $pl->id, $pl->order_id ) ); ?></h2>
		<p>
			<?php echo esc_html( sprintf( __( 'Status: %s | Erstellt: %s', 'lindenweg16' ), $pl->status, $pl->erstellt_am ) ); ?>
			<?php if ( $order ) : ?>
				| <?php echo esc_html( sprintf( __( 'Kunde: %s', 'lindenweg16' ), trim( $order->get_shipping_full_name() ?: $order->get_billing_full_name() ) ) ); ?>
			<?php endif; ?>
		</p>

		<table class="widefat striped"><thead><tr>
			<th><?php esc_html_e( 'Lagerort', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Artikel', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Soll-Menge', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Einheit', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Gepickt', 'lindenweg16' ); ?></th>
		</tr></thead><tbody>
		<?php foreach ( $positionen as $pos ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $pos->lagerort_kuerzel ?: '–' ); ?></strong></td>
				<td><?php echo esc_html( $pos->produkt_name ); ?></td>
				<td><?php echo esc_html( $this->fmt( $pos->soll_menge ) ); ?></td>
				<td><?php echo esc_html( $pos->basis_einheit ); ?></td>
				<td><?php echo $pos->gepickt ? '&#10003;' : '&#10007;'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>

		<p style="margin-top:12px">
			<?php if ( $pl->datei_pfad ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lw16_download_pickliste&id=' . $pl->id ), 'lw16_download_pickliste_' . $pl->id ) ); ?>"><?php esc_html_e( 'PDF herunterladen', 'lindenweg16' ); ?></a>
			<?php endif; ?>
			<?php if ( 'offen' === $pl->status ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lw16_complete_pickliste&id=' . $pl->id ), 'lw16_complete_pickliste_' . $pl->id ) ); ?>"><?php esc_html_e( 'Als abgeschlossen markieren', 'lindenweg16' ); ?></a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'view' => 'pickliste_detail', 'id' => $pl->id, 'mobile' => '1' ), $base ) ); ?>" target="_blank"><?php esc_html_e( 'Mobile Ansicht mit Scanner', 'lindenweg16' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Mobile Picklisten-Ansicht mit Barcode-Scanner.
	 * Wird als eigenständige Seite ohne WP-Admin-Chrome ausgegeben.
	 */
	protected function render_pickliste_mobile( $pl ) {
		$positionen = Repository::get_pickliste_positionen( $pl->id );
		$order      = function_exists( 'wc_get_order' ) ? wc_get_order( $pl->order_id ) : null;
		$complete_url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_complete_pickliste&id=' . $pl->id ), 'lw16_complete_pickliste_' . $pl->id );
		?>
		<!DOCTYPE html>
		<html lang="de">
		<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php echo esc_html( sprintf( __( 'Pickliste #%d', 'lindenweg16' ), $pl->id ) ); ?></title>
		<style>
			* { box-sizing: border-box; margin: 0; padding: 0; }
			body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f0; padding: 12px; }
			.pl-header { background: #fff; border-radius: 8px; padding: 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
			.pl-header h1 { font-size: 20px; margin-bottom: 4px; }
			.pl-header p { color: #666; font-size: 14px; }
			.pl-scanner { background: #1d2327; color: #fff; border-radius: 8px; padding: 16px; margin-bottom: 12px; text-align: center; }
			.pl-scanner input { width: 100%; padding: 12px; font-size: 18px; border: 2px solid #2271b1; border-radius: 6px; text-align: center; margin-top: 8px; }
			.pl-scanner .scan-status { margin-top: 8px; font-size: 14px; min-height: 24px; }
			.pl-scanner .scan-ok { color: #46b450; }
			.pl-scanner .scan-err { color: #dc3232; }
			.pl-items { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
			.pl-item { display: flex; align-items: center; padding: 12px 16px; border-bottom: 1px solid #eee; gap: 12px; transition: background .2s; }
			.pl-item.picked { background: #e8f5e9; }
			.pl-item.highlight { background: #fff3cd; animation: flash .5s; }
			@keyframes flash { 0%,100%{background:#fff3cd} 50%{background:#ffecb5} }
			.pl-check { width: 28px; height: 28px; border-radius: 50%; border: 2px solid #ccc; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; cursor: pointer; }
			.pl-item.picked .pl-check { background: #46b450; border-color: #46b450; color: #fff; }
			.pl-info { flex: 1; min-width: 0; }
			.pl-info .pl-name { font-weight: 600; font-size: 15px; }
			.pl-info .pl-loc { color: #2271b1; font-size: 13px; font-weight: 600; }
			.pl-info .pl-qty { color: #666; font-size: 13px; }
			.pl-actions { text-align: center; margin-top: 16px; }
			.pl-actions a { display: inline-block; background: #2271b1; color: #fff; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-size: 16px; font-weight: 600; }
			.pl-progress { background: #fff; border-radius: 8px; padding: 12px 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
			.pl-progress-bar { height: 8px; background: #ddd; border-radius: 4px; overflow: hidden; margin-top: 6px; }
			.pl-progress-bar .fill { height: 100%; background: #46b450; transition: width .3s; }
		</style>
		</head>
		<body>
		<div class="pl-header">
			<h1><?php echo esc_html( sprintf( __( 'Pickliste #%d', 'lindenweg16' ), $pl->id ) ); ?></h1>
			<p><?php echo esc_html( sprintf( __( 'Bestellung #%d', 'lindenweg16' ), $pl->order_id ) ); ?>
			<?php if ( $order ) : ?>
				&mdash; <?php echo esc_html( trim( $order->get_shipping_full_name() ?: $order->get_billing_full_name() ) ); ?>
			<?php endif; ?>
			</p>
		</div>

		<div class="pl-progress">
			<strong><?php esc_html_e( 'Fortschritt:', 'lindenweg16' ); ?> <span id="pl-count">0</span>/<?php echo count( $positionen ); ?></strong>
			<div class="pl-progress-bar"><div class="fill" id="pl-bar" style="width:0%"></div></div>
		</div>

		<div class="pl-scanner">
			<strong><?php esc_html_e( 'Lagerort scannen oder eingeben', 'lindenweg16' ); ?></strong>
			<input type="text" id="scan-input" placeholder="<?php esc_attr_e( 'Barcode scannen...', 'lindenweg16' ); ?>" autofocus autocomplete="off">
			<div class="scan-status" id="scan-status"></div>
		</div>

		<div class="pl-items" id="pl-items">
		<?php foreach ( $positionen as $i => $pos ) : ?>
			<div class="pl-item <?php echo $pos->gepickt ? 'picked' : ''; ?>" data-loc="<?php echo esc_attr( $pos->lagerort_kuerzel ?: '' ); ?>" data-id="<?php echo (int) $pos->id; ?>" data-idx="<?php echo $i; ?>">
				<div class="pl-check" onclick="togglePick(this.parentNode)"><?php echo $pos->gepickt ? '&#10003;' : ''; ?></div>
				<div class="pl-info">
					<div class="pl-name"><?php echo esc_html( $pos->produkt_name ); ?></div>
					<div class="pl-loc"><?php echo esc_html( $pos->lagerort_kuerzel ? $pos->lagerort_kuerzel . ' – ' . $pos->lagerort_name : __( 'Kein Lagerort', 'lindenweg16' ) ); ?></div>
					<div class="pl-qty"><?php echo esc_html( $this->fmt( $pos->soll_menge ) . ' ' . $pos->basis_einheit ); ?></div>
				</div>
			</div>
		<?php endforeach; ?>
		</div>

		<?php if ( 'offen' === $pl->status ) : ?>
		<div class="pl-actions">
			<a href="<?php echo esc_url( $complete_url ); ?>" id="pl-finish"><?php esc_html_e( 'Pickliste abschliessen', 'lindenweg16' ); ?></a>
		</div>
		<?php endif; ?>

		<script>
		var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var nonce = <?php echo wp_json_encode( wp_create_nonce( 'lw16_admin' ) ); ?>;
		var total = <?php echo count( $positionen ); ?>;

		function updateProgress() {
			var picked = document.querySelectorAll('.pl-item.picked').length;
			document.getElementById('pl-count').textContent = picked;
			document.getElementById('pl-bar').style.width = (total ? (picked/total*100) : 0) + '%';
		}

		function togglePick(el) {
			el.classList.toggle('picked');
			var check = el.querySelector('.pl-check');
			check.innerHTML = el.classList.contains('picked') ? '&#10003;' : '';
			updateProgress();
		}

		var scanInput = document.getElementById('scan-input');
		var scanStatus = document.getElementById('scan-status');
		var scanTimeout;

		scanInput.addEventListener('input', function() {
			clearTimeout(scanTimeout);
			scanTimeout = setTimeout(function() {
				var val = scanInput.value.trim().toUpperCase();
				if (!val) return;
				var items = document.querySelectorAll('.pl-item:not(.picked)');
				var found = false;
				for (var i = 0; i < items.length; i++) {
					if (items[i].dataset.loc && items[i].dataset.loc.toUpperCase() === val) {
						togglePick(items[i]);
						items[i].classList.add('highlight');
						items[i].scrollIntoView({behavior:'smooth', block:'center'});
						setTimeout(function(el){el.classList.remove('highlight')}, 1500, items[i]);
						scanStatus.innerHTML = '<span class="scan-ok">&#10003; ' + items[i].querySelector('.pl-name').textContent + '</span>';
						found = true;
						break;
					}
				}
				if (!found) {
					scanStatus.innerHTML = '<span class="scan-err">&#10007; <?php echo esc_js( __( 'Kein offener Artikel an diesem Lagerort', 'lindenweg16' ) ); ?></span>';
				}
				scanInput.value = '';
			}, 200);
		});

		scanInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				clearTimeout(scanTimeout);
				scanInput.dispatchEvent(new Event('input'));
			}
		});

		updateProgress();
		</script>
		</body>
		</html>
		<?php
		exit;
	}

	/* --------------------------------------------------------------------
	 * Retouren
	 * ------------------------------------------------------------------ */

	protected function render_retouren() {
		$orders = wc_get_orders( array(
			'status' => array( 'wc-completed' ),
			'limit'  => 50,
			'return' => 'objects',
			'orderby' => 'date',
			'order'   => 'DESC',
		) );
		?>
		<h2><?php esc_html_e( 'Retoure verbuchen', 'lindenweg16' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_retoure">
			<?php wp_nonce_field( 'lw16_retoure' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Bestellung', 'lindenweg16' ); ?></th><td>
					<select name="order_id" id="lw16-ret-order" required>
						<option value=""><?php esc_html_e( '— Bestellung wählen —', 'lindenweg16' ); ?></option>
						<?php foreach ( $orders as $o ) :
							$kunde = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
							$datum  = $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd.m.Y' ) : '';
						?>
						<option value="<?php echo (int) $o->get_id(); ?>"><?php echo esc_html( '#' . $o->get_order_number() . ' – ' . $kunde . ' (' . $datum . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Artikel & Mengen', 'lindenweg16' ); ?></th><td>
					<p class="description"><?php esc_html_e( 'Gib die Retourenmengen je Produkt ein (nur Produkte mit ERP-Verknüpfung werden zurückgebucht).', 'lindenweg16' ); ?></p>
					<div id="lw16-ret-items">
						<?php
						$all_products = Repository::get_products();
						foreach ( $all_products as $p ) :
						?>
						<label style="display:block;margin:4px 0">
							<input type="number" step="any" min="0" name="retoure_menge[<?php echo (int) $p->id; ?>]" value="0" class="small-text">
							<?php echo esc_html( $p->name . ' (' . $p->typ . ', ' . $p->basis_einheit . ')' ); ?>
						</label>
						<?php endforeach; ?>
					</div>
				</td></tr>
				<tr><th><?php esc_html_e( 'Grund', 'lindenweg16' ); ?></th><td>
					<input type="text" name="grund" class="regular-text" placeholder="<?php esc_attr_e( 'z. B. Defekt, Falschlieferung', 'lindenweg16' ); ?>">
				</td></tr>
			</tbody></table>
			<?php submit_button( __( 'Retoure buchen', 'lindenweg16' ) ); ?>
		</form>

		<?php
		global $wpdb;
		$tb = Repository::table( 'lagerbewegung' );
		$tp = Repository::table( 'produkte' );
		$recent = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.*, p.name AS produkt_name, p.basis_einheit FROM {$tb} b LEFT JOIN {$tp} p ON p.id = b.produkt_id WHERE b.typ = 'retoure' ORDER BY b.id DESC LIMIT %d", 20
		) ); // phpcs:ignore
		if ( $recent ) :
		?>
		<h3><?php esc_html_e( 'Letzte Retouren', 'lindenweg16' ); ?></h3>
		<table class="widefat striped"><thead><tr>
			<th><?php esc_html_e( 'Datum', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Produkt', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Menge', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Referenz', 'lindenweg16' ); ?></th>
		</tr></thead><tbody>
		<?php foreach ( $recent as $r ) : ?>
			<tr>
				<td><?php echo esc_html( $r->erstellt_am ); ?></td>
				<td><?php echo esc_html( $r->produkt_name ); ?></td>
				<td><?php echo esc_html( $this->fmt( $r->menge ) . ' ' . $r->basis_einheit ); ?></td>
				<td><?php echo esc_html( (string) $r->referenz ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php endif;
	}

	public function handle_retoure() {
		$this->guard( 'lw16_retoure' );

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$grund    = sanitize_text_field( wp_unslash( $_POST['grund'] ?? '' ) );
		$mengen   = isset( $_POST['retoure_menge'] ) && is_array( $_POST['retoure_menge'] ) ? wp_unslash( $_POST['retoure_menge'] ) : array();
		$referenz = sprintf( __( 'Retoure Bestellung #%d', 'lindenweg16' ), $order_id );
		if ( $grund ) {
			$referenz .= ' – ' . $grund;
		}

		$gebucht = 0;
		foreach ( $mengen as $produkt_id => $menge ) {
			$menge = (float) $menge;
			if ( $menge <= 0 ) {
				continue;
			}
			StockManager::record_movement( (int) $produkt_id, abs( $menge ), 'retoure', array( 'referenz' => $referenz ) );
			$gebucht++;
		}

		$msg = $gebucht > 0
			? sprintf( __( 'Retoure gebucht: %d Produkte zurückgebucht.', 'lindenweg16' ), $gebucht )
			: __( 'Keine Mengen angegeben.', 'lindenweg16' );
		$this->redirect( 'lager&view=retouren', $msg );
	}

	/* --------------------------------------------------------------------
	 * WooCommerce-Produktimport
	 * ------------------------------------------------------------------ */

	protected function render_wc_import() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			echo '<p>' . esc_html__( 'WooCommerce nicht verfügbar.', 'lindenweg16' ) . '</p>';
			return;
		}

		$wc_products = wc_get_products( array( 'limit' => 200, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC', 'return' => 'objects' ) );

		$erp_woo_ids = array();
		foreach ( Repository::get_products() as $p ) {
			if ( $p->woo_product_id ) {
				$erp_woo_ids[] = (int) $p->woo_product_id;
			}
		}

		$unlinked = array();
		foreach ( $wc_products as $wc ) {
			if ( ! in_array( $wc->get_id(), $erp_woo_ids, true ) ) {
				$unlinked[] = $wc;
			}
		}
		?>
		<h2><?php esc_html_e( 'WooCommerce-Produkte importieren', 'lindenweg16' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Alle WooCommerce-Produkte, die noch nicht im ERP-Lager verknüpft sind. Ausgewählte Produkte werden als Modul angelegt.', 'lindenweg16' ); ?></p>

		<?php if ( empty( $unlinked ) ) : ?>
			<p><?php esc_html_e( 'Alle WooCommerce-Produkte sind bereits im ERP verknüpft.', 'lindenweg16' ); ?></p>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_wc_import">
			<?php wp_nonce_field( 'lw16_wc_import' ); ?>
			<table class="widefat striped"><thead><tr>
				<th style="width:30px"><input type="checkbox" id="lw16-import-all"></th>
				<th><?php esc_html_e( 'WC-ID', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'Produktname', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'Preis', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'Typ im ERP', 'lindenweg16' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $unlinked as $wc ) : ?>
				<tr>
					<td><input type="checkbox" name="import_ids[]" value="<?php echo (int) $wc->get_id(); ?>" class="lw16-import-check"></td>
					<td><?php echo (int) $wc->get_id(); ?></td>
					<td><?php echo esc_html( $wc->get_name() ); ?></td>
					<td><?php echo esc_html( $wc->get_price() ? number_format( (float) $wc->get_price(), 2, ',', '.' ) . ' €' : '–' ); ?></td>
					<td>
						<select name="import_typ[<?php echo (int) $wc->get_id(); ?>]">
							<option value="modul"><?php esc_html_e( 'Modul', 'lindenweg16' ); ?></option>
							<option value="set"><?php esc_html_e( 'Set', 'lindenweg16' ); ?></option>
							<option value="rohmaterial"><?php esc_html_e( 'Rohmaterial', 'lindenweg16' ); ?></option>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php submit_button( __( 'Ausgewählte importieren', 'lindenweg16' ) ); ?>
		</form>
		<script>
		document.getElementById('lw16-import-all').addEventListener('change',function(){
			var cbs=document.querySelectorAll('.lw16-import-check');
			for(var i=0;i<cbs.length;i++) cbs[i].checked=this.checked;
		});
		</script>
		<?php endif;
	}

	public function handle_wc_import() {
		$this->guard( 'lw16_wc_import' );

		$ids  = isset( $_POST['import_ids'] ) && is_array( $_POST['import_ids'] ) ? array_map( 'intval', $_POST['import_ids'] ) : array();
		$typs = isset( $_POST['import_typ'] ) && is_array( $_POST['import_typ'] ) ? wp_unslash( $_POST['import_typ'] ) : array();

		$count = 0;
		foreach ( $ids as $wc_id ) {
			$wc = wc_get_product( $wc_id );
			if ( ! $wc ) {
				continue;
			}
			$typ = isset( $typs[ $wc_id ] ) ? sanitize_key( $typs[ $wc_id ] ) : 'modul';
			if ( ! in_array( $typ, array( 'set', 'modul', 'rohmaterial' ), true ) ) {
				$typ = 'modul';
			}
			Repository::create_product( array(
				'name'           => sanitize_text_field( $wc->get_name() ),
				'typ'            => $typ,
				'woo_product_id' => $wc_id,
				'basis_einheit'  => 'stueck',
				'mindestbestand' => 0,
			) );
			$count++;
		}

		$this->redirect( 'lager&view=wc_import', sprintf( __( '%d Produkte importiert.', 'lindenweg16' ), $count ) );
	}

	/* --------------------------------------------------------------------
	 * Inventur
	 * ------------------------------------------------------------------ */

	protected function render_inventur() {
		$produkte = Repository::get_products();
		?>
		<h2><?php esc_html_e( 'Inventur durchführen', 'lindenweg16' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Trage die tatsächlichen Bestände ein. Die Differenz wird automatisch als Korrektur-Bewegung gebucht.', 'lindenweg16' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_inventur">
			<?php wp_nonce_field( 'lw16_inventur' ); ?>
			<table class="widefat striped"><thead><tr>
				<th><?php esc_html_e( 'Produkt', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'Typ', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'System-Bestand', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'Ist-Bestand', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'Einheit', 'lindenweg16' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $produkte as $p ) :
				$bestand = Repository::get_stock( $p->id );
			?>
				<tr>
					<td><?php echo esc_html( $p->name ); ?></td>
					<td><?php echo esc_html( $p->typ ); ?></td>
					<td><?php echo esc_html( $this->fmt( $bestand ) ); ?></td>
					<td>
						<input type="number" step="any" name="ist_bestand[<?php echo (int) $p->id; ?>]" value="<?php echo esc_attr( $bestand ); ?>" class="small-text">
						<input type="hidden" name="soll_bestand[<?php echo (int) $p->id; ?>]" value="<?php echo esc_attr( $bestand ); ?>">
					</td>
					<td><?php echo esc_html( $p->basis_einheit ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php submit_button( __( 'Inventur buchen', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	public function handle_inventur() {
		$this->guard( 'lw16_inventur' );

		$ist  = isset( $_POST['ist_bestand'] ) && is_array( $_POST['ist_bestand'] ) ? wp_unslash( $_POST['ist_bestand'] ) : array();
		$soll = isset( $_POST['soll_bestand'] ) && is_array( $_POST['soll_bestand'] ) ? wp_unslash( $_POST['soll_bestand'] ) : array();

		$korrekturen = 0;
		foreach ( $ist as $produkt_id => $ist_menge ) {
			$produkt_id = (int) $produkt_id;
			$ist_menge  = (float) $ist_menge;
			$soll_menge = isset( $soll[ $produkt_id ] ) ? (float) $soll[ $produkt_id ] : 0;
			$diff       = $ist_menge - $soll_menge;

			if ( abs( $diff ) < 0.001 ) {
				continue;
			}

			StockManager::record_movement( $produkt_id, $diff, 'inventur', array(
				'referenz' => sprintf( __( 'Inventur: Soll %s, Ist %s', 'lindenweg16' ), $this->fmt( $soll_menge ), $this->fmt( $ist_menge ) ),
			) );
			$korrekturen++;
		}

		$msg = $korrekturen > 0
			? sprintf( __( 'Inventur abgeschlossen: %d Korrekturen gebucht.', 'lindenweg16' ), $korrekturen )
			: __( 'Keine Abweichungen festgestellt.', 'lindenweg16' );
		$this->redirect( 'lager&view=inventur', $msg );
	}

	/* --------------------------------------------------------------------
	 * Historie (Bewegungsverlauf pro Produkt)
	 * ------------------------------------------------------------------ */

	protected function render_historie() {
		$produkte   = Repository::get_products();
		$produkt_id = isset( $_GET['produkt_id'] ) ? (int) $_GET['produkt_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$base       = admin_url( 'admin.php?page=lw16-lager&view=historie' );
		?>
		<h2><?php esc_html_e( 'Bestandsverlauf', 'lindenweg16' ); ?></h2>
		<form method="get" style="margin-bottom:16px">
			<input type="hidden" name="page" value="lw16-lager">
			<input type="hidden" name="view" value="historie">
			<select name="produkt_id">
				<option value=""><?php esc_html_e( '— Produkt wählen —', 'lindenweg16' ); ?></option>
				<?php foreach ( $produkte as $p ) : ?>
					<option value="<?php echo (int) $p->id; ?>" <?php selected( $produkt_id, $p->id ); ?>><?php echo esc_html( $p->name . ' (' . $p->typ . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Anzeigen', 'lindenweg16' ), 'secondary', '', false ); ?>
		</form>
		<?php

		if ( ! $produkt_id ) {
			echo '<p class="description">' . esc_html__( 'Wähle ein Produkt, um den Bestandsverlauf zu sehen.', 'lindenweg16' ) . '</p>';
			return;
		}

		$produkt = Repository::get_product( $produkt_id );
		if ( ! $produkt ) {
			echo '<p>' . esc_html__( 'Produkt nicht gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}

		global $wpdb;
		$tb   = Repository::table( 'lagerbewegung' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tb} WHERE produkt_id = %d ORDER BY id ASC", $produkt_id
		) ); // phpcs:ignore

		$bestand_aktuell = Repository::get_stock( $produkt_id );
		echo '<h3>' . esc_html( sprintf( __( '%s – Aktueller Bestand: %s %s', 'lindenweg16' ), $produkt->name, $this->fmt( $bestand_aktuell ), $produkt->basis_einheit ) ) . '</h3>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Noch keine Bewegungen für dieses Produkt.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Typ', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Menge', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Bestand danach', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Referenz', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';

		$laufender_bestand = 0;
		foreach ( $rows as $r ) {
			$laufender_bestand += (float) $r->menge;
			$sign_class = (float) $r->menge >= 0 ? 'color:#46b450' : 'color:#dc3232';
			$sign       = (float) $r->menge >= 0 ? '+' : '';
			echo '<tr>';
			echo '<td>' . esc_html( $r->erstellt_am ) . '</td>';
			echo '<td>' . esc_html( $r->typ ) . '</td>';
			echo '<td style="' . $sign_class . ';font-weight:600">' . esc_html( $sign . $this->fmt( $r->menge ) ) . '</td>';
			echo '<td>' . esc_html( $this->fmt( $laufender_bestand ) ) . '</td>';
			echo '<td>' . esc_html( (string) $r->referenz ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/* --------------------------------------------------------------------
	 * Täglicher Bestandsreport
	 * ------------------------------------------------------------------ */

	public function send_daily_stock_report() {
		$produkte = Repository::get_products();
		$warnings = array();

		foreach ( $produkte as $p ) {
			if ( (float) $p->mindestbestand <= 0 ) {
				continue;
			}
			$bestand = Repository::get_stock( $p->id );
			if ( $bestand < (float) $p->mindestbestand ) {
				$warnings[] = sprintf(
					'- %s: %s %s (Mindestbestand: %s)',
					$p->name,
					rtrim( rtrim( number_format( $bestand, 2, ',', '.' ), '0' ), ',' ),
					$p->basis_einheit,
					rtrim( rtrim( number_format( (float) $p->mindestbestand, 2, ',', '.' ), '0' ), ',' )
				);
			}
		}

		if ( empty( $warnings ) ) {
			return;
		}

		$emails = \Lindenweg16\Core\Roles::get_manager_emails();
		if ( empty( $emails ) ) {
			return;
		}

		MailTemplate::send( $emails, 'bestandsreport', array(
			'{warnungen}' => implode( "\n", $warnings ),
		) );
	}

	/* --------------------------------------------------------------------
	 * POST-Handler: Lagerorte
	 * ------------------------------------------------------------------ */

	public function handle_save_lagerort() {
		$this->guard( 'lw16_save_lagerort' );
		Repository::create_lagerort( array(
			'kuerzel'       => sanitize_text_field( wp_unslash( $_POST['kuerzel'] ?? '' ) ),
			'name'          => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'beschreibung'  => sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ?? '' ) ),
		) );
		$this->redirect( 'lager&view=lagerorte', __( 'Lagerort gespeichert.', 'lindenweg16' ) );
	}

	public function handle_update_lagerort() {
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_update_lagerort_' . $id );
		Repository::update_lagerort( $id, array(
			'kuerzel'       => sanitize_text_field( wp_unslash( $_POST['kuerzel'] ?? '' ) ),
			'name'          => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'beschreibung'  => sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ?? '' ) ),
			'aktiv'         => isset( $_POST['aktiv'] ) ? 1 : 0,
		) );
		$this->redirect( 'lager&view=lagerorte', __( 'Lagerort aktualisiert.', 'lindenweg16' ) );
	}

	public function handle_delete_lagerort() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_delete_lagerort_' . $id );
		Repository::delete_lagerort( $id );
		$this->redirect( 'lager&view=lagerorte', __( 'Lagerort gelöscht.', 'lindenweg16' ) );
	}

	public function handle_zuordnung_lagerort() {
		$lagerort_id = (int) ( $_POST['lagerort_id'] ?? 0 );
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_zuordnung_lagerort_' . $lagerort_id );

		if ( ! empty( $_POST['bestand'] ) && is_array( $_POST['bestand'] ) ) {
			foreach ( wp_unslash( $_POST['bestand'] ) as $produkt_id => $menge ) {
				Repository::set_stock_at_location( (int) $produkt_id, $lagerort_id, max( 0, (float) $menge ) );
			}
		}
		$this->redirect( 'lager&view=lagerorte', __( 'Bestände aktualisiert.', 'lindenweg16' ) );
	}

	/* --------------------------------------------------------------------
	 * POST-Handler: Picklisten
	 * ------------------------------------------------------------------ */

	public function handle_create_pickliste() {
		$this->guard( 'lw16_create_pickliste' );
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		if ( ! $order_id ) {
			$this->redirect( 'lager&view=picklisten', __( 'Ungültige Bestell-Nr.', 'lindenweg16' ) );
		}
		$id = PickListGenerator::generate( $order_id );
		if ( ! $id ) {
			$this->redirect( 'lager&view=picklisten', __( 'Pickliste konnte nicht erstellt werden. Bestellung nicht gefunden oder keine Produkte zugeordnet.', 'lindenweg16' ) );
		}
		$this->redirect( 'lager&view=pickliste_detail&id=' . $id, __( 'Pickliste erstellt.', 'lindenweg16' ) );
	}

	public function handle_complete_pickliste() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_complete_pickliste_' . $id );
		Repository::update_pickliste( $id, array(
			'status'           => 'abgeschlossen',
			'abgeschlossen_am' => current_time( 'mysql' ),
		) );
		$this->redirect( 'lager&view=pickliste_detail&id=' . $id, __( 'Pickliste abgeschlossen.', 'lindenweg16' ) );
	}

	public function handle_download_pickliste() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_download_pickliste_' . $id );
		$pl = Repository::get_pickliste( $id );
		if ( ! $pl || ! $pl->datei_pfad ) {
			wp_die( esc_html__( 'PDF nicht gefunden.', 'lindenweg16' ) );
		}
		$path = trailingslashit( \Lindenweg16\Core\Installer::docs_dir() ) . $pl->datei_pfad;
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'PDF-Datei nicht gefunden.', 'lindenweg16' ) );
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/* --------------------------------------------------------------------
	 * Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Berechtigung + Nonce prüfen.
	 *
	 * @param string $nonce Nonce-Aktion.
	 */
	protected function guard( $nonce ) {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Zurück zur Lager-Seite mit Meldung.
	 *
	 * @param string $page Seiten-Suffix (kann &view=xxx&id=yy enthalten).
	 * @param string $msg  Meldung.
	 */
	protected function redirect( $page, $msg ) {
		$url = admin_url( 'admin.php?page=lw16-' . $page . '&msg=' . rawurlencode( $msg ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Zahl hübsch formatieren (deutsche Notation, ohne überflüssige Nullen).
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	protected function fmt( $n ) {
		$s = number_format( (float) $n, 2, ',', '.' );
		return rtrim( rtrim( $s, '0' ), ',' );
	}

	/**
	 * Einfache Pagination.
	 *
	 * @param int    $total    Gesamtzahl.
	 * @param int    $per_page Pro Seite.
	 * @param int    $page     Aktuelle Seite.
	 * @param string $param    Query-Parameter.
	 */
	protected function pagination( $total, $per_page, $page, $param ) {
		$pages = (int) ceil( $total / $per_page );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url = add_query_arg( $param, $i );
			if ( $i === $page ) {
				echo '<span class="tablenav-pages-navspan button disabled">' . (int) $i . '</span> ';
			} else {
				echo '<a class="button" href="' . esc_url( $url ) . '">' . (int) $i . '</a> ';
			}
		}
		echo '</div></div>';
	}
}
