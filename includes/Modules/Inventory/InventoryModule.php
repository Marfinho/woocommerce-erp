<?php
/**
 * Modul 2: Lagerverwaltung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Inventory;

defined( 'ABSPATH' ) || exit;

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
		// Echtzeit-Lagerabzug bei abgeschlossener Bestellung.
		add_action( 'woocommerce_order_status_completed', array( $this, 'deduct_stock_for_order' ), 20, 1 );

		// Admin-Seite.
		add_action( 'lw16_render_page_lw16_lager', array( $this, 'render_admin' ) );
		add_action( 'admin_post_lw16_save_product', array( $this, 'handle_save_product' ) );
		add_action( 'admin_post_lw16_wareneingang', array( $this, 'handle_wareneingang' ) );
		add_action( 'admin_post_lw16_produktion', array( $this, 'handle_produktion' ) );
		add_action( 'admin_post_lw16_update_product', array( $this, 'handle_update_product' ) );
		add_action( 'admin_post_lw16_delete_product', array( $this, 'handle_delete_product' ) );
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
			$produkt        = Repository::get_by_woo_id( $woo_product_id );
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
		printf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( 'view', 'bewegungen', $base ) ), 'bewegungen' === $view ? 'active' : '', esc_html__( 'Bewegungen', 'lindenweg16' ) );
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
			case 'bewegungen':
				$this->render_movements();
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
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Typ', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Bestand', 'lindenweg16' ) . '</th>';
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
			echo '<td>' . esc_html( $this->fmt( $bestand ) . ' ' . $p->basis_einheit ) . '</td>';
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
				<tr><th><?php esc_html_e( 'WooCommerce-Produkt-ID', 'lindenweg16' ); ?></th><td><input type="number" name="woo_product_id" class="small-text"> <span class="description"><?php esc_html_e( 'optional, für Sets/Module', 'lindenweg16' ); ?></span></td></tr>
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

			<h2><?php esc_html_e( 'Stückliste (nur bei Set): Modul → Menge', 'lindenweg16' ); ?></h2>
			<table class="widefat striped"><tbody>
			<?php foreach ( $module_liste as $m ) : ?>
				<tr><td><?php echo esc_html( $m->name ); ?></td><td><input type="number" step="any" name="stueckliste[<?php echo (int) $m->id; ?>]" value="0" class="small-text"></td></tr>
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
		?>
		<h2><?php echo esc_html( sprintf( /* translators: %s: Produktname */ __( 'Produkt bearbeiten: %s', 'lindenweg16' ), $produkt->name ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_update_product">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'lw16_update_product_' . $id ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Name', 'lindenweg16' ); ?></th><td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $produkt->name ); ?>" required></td></tr>
				<tr><th><?php esc_html_e( 'Typ', 'lindenweg16' ); ?></th><td><strong><?php echo esc_html( $produkt->typ ); ?></strong> <span class="description"><?php esc_html_e( '(nicht änderbar)', 'lindenweg16' ); ?></span></td></tr>
				<tr><th><?php esc_html_e( 'WooCommerce-Produkt-ID', 'lindenweg16' ); ?></th><td><input type="number" name="woo_product_id" class="small-text" value="<?php echo esc_attr( $produkt->woo_product_id ); ?>"></td></tr>
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
			<h2><?php esc_html_e( 'Stückliste: Modul → Menge', 'lindenweg16' ); ?></h2>
			<table class="widefat striped"><tbody>
			<?php foreach ( $module_liste as $m ) : ?>
				<tr><td><?php echo esc_html( $m->name ); ?></td><td><input type="number" step="any" name="stueckliste[<?php echo (int) $m->id; ?>]" value="<?php echo esc_attr( $stueckliste[ $m->id ] ?? 0 ); ?>" class="small-text"></td></tr>
			<?php endforeach; ?>
			</tbody></table>
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
		$roh = Repository::get_products( 'rohmaterial' );
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

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.*, p.name AS produkt_name, p.basis_einheit FROM {$tb} b LEFT JOIN {$tp} p ON p.id = b.produkt_id ORDER BY b.id DESC LIMIT %d OFFSET %d", $per_page, $offset ) ); // phpcs:ignore
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tb}" ); // phpcs:ignore

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Produkt', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Menge', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Typ', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Referenz', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Beleg', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( $r->erstellt_am ) . '</td>';
			echo '<td>' . esc_html( $r->produkt_name ) . '</td>';
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
			$pos = array();
			foreach ( wp_unslash( $_POST['stueckliste'] ) as $modul_id => $menge ) {
				$pos[ (int) $modul_id ] = (float) $menge;
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

		StockManager::record_movement(
			$produkt_id,
			abs( $menge_basis ),
			'wareneingang',
			array(
				'beleg_id' => $beleg_id,
				'referenz' => __( 'Wareneingang', 'lindenweg16' ),
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
			$pos = array();
			foreach ( wp_unslash( $_POST['stueckliste'] ) as $modul_id => $menge ) {
				$pos[ (int) $modul_id ] = (float) $menge;
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
	 * @param string $page Seiten-Suffix.
	 * @param string $msg  Meldung.
	 */
	protected function redirect( $page, $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-' . $page, 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
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
