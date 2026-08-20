<?php
/**
 * Globale Schnellsuche über alle Module.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX-Schnellsuche über Produkte, Bestellungen, Tickets und Belege.
 */
class GlobalSearch {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'wp_ajax_lw16_global_search', array( $this, 'handle_search' ) );
		add_action( 'admin_footer', array( $this, 'render_search_bar' ) );
	}

	/**
	 * Suchleiste im Admin rendern (nur auf Plugin-Seiten).
	 */
	public function render_search_bar() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'lw16' ) && false === strpos( $screen->id, LW16_MENU_SLUG ) ) {
			return;
		}
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			return;
		}
		?>
		<div id="lw16-search-overlay" style="display:none">
			<div class="lw16-search-modal">
				<input type="text" id="lw16-search-input" placeholder="<?php esc_attr_e( 'Produkt, Bestellung, Ticket suchen…', 'lindenweg16' ); ?>" autocomplete="off">
				<div id="lw16-search-results"></div>
				<div class="lw16-search-hint"><?php esc_html_e( 'ESC zum Schließen', 'lindenweg16' ); ?></div>
			</div>
		</div>
		<style>
			#lw16-search-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:100000;display:flex;align-items:flex-start;justify-content:center;padding-top:120px}
			.lw16-search-modal{background:#fff;border-radius:12px;width:560px;max-width:90vw;box-shadow:0 16px 48px rgba(0,0,0,.2);overflow:hidden}
			#lw16-search-input{width:100%;padding:16px 20px;font-size:16px;border:none;outline:none;border-bottom:1px solid #e2e4e7}
			#lw16-search-results{max-height:400px;overflow-y:auto}
			.lw16-search-hint{padding:8px 20px;font-size:12px;color:#646970;text-align:center;border-top:1px solid #e2e4e7}
			.lw16-sr-item{display:block;padding:10px 20px;text-decoration:none;color:#1d2327;border-bottom:1px solid #f0f0f0}
			.lw16-sr-item:hover{background:#f0f7ff}
			.lw16-sr-item .lw16-sr-type{font-size:11px;color:#2271b1;font-weight:600;text-transform:uppercase}
			.lw16-sr-item .lw16-sr-title{font-weight:600}
			.lw16-sr-item .lw16-sr-meta{font-size:12px;color:#646970}
			.lw16-sr-empty{padding:20px;text-align:center;color:#646970}
			.lw16-search-trigger{cursor:pointer;margin-left:8px;vertical-align:middle}
		</style>
		<script>
		(function(){
			var overlay=document.getElementById('lw16-search-overlay'),input=document.getElementById('lw16-search-input'),results=document.getElementById('lw16-search-results');
			var timer;
			document.addEventListener('keydown',function(e){
				if((e.ctrlKey||e.metaKey)&&e.key==='k'){e.preventDefault();overlay.style.display='flex';input.value='';results.innerHTML='';input.focus();}
				if(e.key==='Escape'&&overlay.style.display!=='none'){overlay.style.display='none';}
			});
			overlay.addEventListener('click',function(e){if(e.target===overlay)overlay.style.display='none';});
			input.addEventListener('input',function(){
				clearTimeout(timer);
				var q=input.value.trim();
				if(q.length<2){results.innerHTML='';return;}
				timer=setTimeout(function(){
					var xhr=new XMLHttpRequest();
					xhr.open('POST',LW16Admin.ajaxUrl);
					xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
					xhr.onload=function(){
						if(xhr.status===200){
							try{var d=JSON.parse(xhr.responseText);if(d.success)results.innerHTML=d.data.html;else results.innerHTML='<div class="lw16-sr-empty">Fehler</div>';}
							catch(e){results.innerHTML='';}
						}
					};
					xhr.send('action=lw16_global_search&_ajax_nonce='+LW16Admin.nonce+'&q='+encodeURIComponent(q));
				},300);
			});
		})();
		</script>
		<?php
	}

	/**
	 * AJAX-Suche.
	 */
	public function handle_search() {
		check_ajax_referer( 'lw16_admin' );
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_send_json_error();
		}

		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( strlen( $q ) < 2 ) {
			wp_send_json_error();
		}

		$html    = '';
		$results = array();

		$results = array_merge(
			$results,
			$this->search_products( $q ),
			$this->search_orders( $q ),
			$this->search_tickets( $q )
		);

		if ( empty( $results ) ) {
			$html = '<div class="lw16-sr-empty">' . esc_html__( 'Keine Ergebnisse gefunden.', 'lindenweg16' ) . '</div>';
		} else {
			foreach ( array_slice( $results, 0, 15 ) as $r ) {
				$html .= '<a href="' . esc_url( $r['url'] ) . '" class="lw16-sr-item">';
				$html .= '<span class="lw16-sr-type">' . esc_html( $r['type'] ) . '</span><br>';
				$html .= '<span class="lw16-sr-title">' . esc_html( $r['title'] ) . '</span>';
				if ( ! empty( $r['meta'] ) ) {
					$html .= '<br><span class="lw16-sr-meta">' . esc_html( $r['meta'] ) . '</span>';
				}
				$html .= '</a>';
			}
		}

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * @param string $q Suchbegriff.
	 * @return array
	 */
	protected function search_products( $q ) {
		global $wpdb;
		// Gesucht wird im Artikelstamm des Moduls Betrieb – seit der
		// Zusammenlegung die einzige Lagerverwaltung. Auch die Artikelnummer
		// zaehlt, weil man die eher im Kopf hat als die Bezeichnung.
		$t       = $wpdb->prefix . 'lw16_articles';
		$like    = '%' . $wpdb->esc_like( $q ) . '%';
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, sku, name, sourcing_type FROM {$t} WHERE name LIKE %s OR sku LIKE %s LIMIT 5", $like, $like ) ); // phpcs:ignore
		$results = array();
		$base    = admin_url( 'admin.php?page=lw16-betrieb-artikel&tab=artikel' );
		foreach ( $rows as $r ) {
			$results[] = array(
				'type'  => __( 'Artikel', 'lindenweg16' ),
				'title' => $r->sku . ' – ' . $r->name,
				'meta'  => $r->sourcing_type,
				'url'   => add_query_arg( 'edit', $r->id, $base ),
			);
		}
		return $results;
	}

	/**
	 * @param string $q Suchbegriff.
	 * @return array
	 */
	protected function search_orders( $q ) {
		$results = array();
		if ( preg_match( '/\d+/', $q, $m ) ) {
			$order = wc_get_order( (int) $m[0] );
			if ( $order ) {
				$results[] = array(
					'type'  => __( 'Bestellung', 'lindenweg16' ),
					'title' => '#' . $order->get_order_number() . ' – ' . $order->get_formatted_billing_full_name(),
					'meta'  => $order->get_formatted_order_total() . ' – ' . wc_get_order_status_name( $order->get_status() ),
					'url'   => $order->get_edit_order_url(),
				);
			}
		}

		$orders = wc_get_orders( array(
			'limit' => 5,
			's'     => $q,
			'return' => 'objects',
		) );
		foreach ( $orders as $o ) {
			$key = '#' . $o->get_order_number();
			$already = false;
			foreach ( $results as $r ) {
				if ( strpos( $r['title'], $key ) !== false ) {
					$already = true;
					break;
				}
			}
			if ( ! $already ) {
				$results[] = array(
					'type'  => __( 'Bestellung', 'lindenweg16' ),
					'title' => '#' . $o->get_order_number() . ' – ' . $o->get_formatted_billing_full_name(),
					'meta'  => $o->get_formatted_order_total() . ' – ' . wc_get_order_status_name( $o->get_status() ),
					'url'   => $o->get_edit_order_url(),
				);
			}
		}
		return array_slice( $results, 0, 5 );
	}

	/**
	 * @param string $q Suchbegriff.
	 * @return array
	 */
	protected function search_tickets( $q ) {
		global $wpdb;
		$t       = $wpdb->prefix . 'lw16_tickets';
		$like    = '%' . $wpdb->esc_like( $q ) . '%';
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, betreff, status, user_id FROM {$t} WHERE betreff LIKE %s OR nachricht LIKE %s LIMIT 5", $like, $like ) ); // phpcs:ignore
		$results = array();
		foreach ( $rows as $r ) {
			$user = get_userdata( $r->user_id );
			$results[] = array(
				'type'  => __( 'Ticket', 'lindenweg16' ),
				'title' => '#' . $r->id . ' – ' . $r->betreff,
				'meta'  => ( $user ? $user->display_name : '' ) . ' – ' . $r->status,
				'url'   => admin_url( 'admin.php?page=lw16-support' ),
			);
		}
		return $results;
	}
}
