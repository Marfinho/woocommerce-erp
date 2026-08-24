<?php
/**
 * Modul: Marktkasse (POS) – Mobile Kassenfunktion für Märkte.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\POS;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Accounting\KassenbuchRepository;
use Lindenweg16\Modules\Inventory\Repository as LagerRepository;
use Lindenweg16\Modules\Inventory\StockManager;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\StockOwnership;

/**
 * Kassenfunktion für den Marktverkauf: Tagesbetrieb mit Anfangs-/Endbestand,
 * Verkäufe aus ERP-Produkten oder manueller Eingabe, Tagesabschluss mit
 * Buchhaltungs-Integration.
 */
class POSModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_page_lw16_kasse', array( $this, 'render_admin' ) );

		// POST-Handler.
		add_action( 'admin_post_lw16_pos_tag_start', array( $this, 'handle_tag_start' ) );
		add_action( 'admin_post_lw16_pos_verkauf', array( $this, 'handle_verkauf' ) );
		add_action( 'admin_post_lw16_pos_storno', array( $this, 'handle_storno' ) );
		add_action( 'admin_post_lw16_pos_tag_abschluss', array( $this, 'handle_tag_abschluss' ) );

		// AJAX für schnelle Produktsuche in der Kasse.
		add_action( 'wp_ajax_lw16_pos_produkt_suche', array( $this, 'ajax_produkt_suche' ) );
	}

	/* ------------------------------------------------------------------
	 * Admin-Router
	 * ---------------------------------------------------------------- */

	public function render_admin() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'uebersicht'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap lw16-wrap">';

		switch ( $view ) {
			case 'kasse':
				$this->render_kasse();
				break;
			case 'historie':
				$this->render_historie();
				break;
			case 'tag_detail':
				$this->render_tag_detail();
				break;
			case 'mobil':
				$this->render_mobil();
				return; // Eigenes HTML-Dokument.
			default:
				$this->render_uebersicht();
		}

		echo '</div>';
	}

	/* ------------------------------------------------------------------
	 * Übersicht: Offener Tag oder neuen starten
	 * ---------------------------------------------------------------- */

	protected function render_uebersicht() {
		echo '<h1>' . esc_html__( 'Marktkasse', 'lindenweg16' ) . '</h1>';

		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		$base = admin_url( 'admin.php?page=lw16-kasse' );

		// Aktiver Tag?
		$offener_tag = POSRepository::get_offener_tag();

		if ( $offener_tag ) {
			$positionen = POSRepository::get_positionen( $offener_tag->id );
			$summe      = 0;
			foreach ( $positionen as $pos ) {
				if ( ! $pos->storniert ) {
					$summe += (float) $pos->betrag;
				}
			}
			echo '<div class="lw16-cards">';
			echo '<div class="lw16-card lw16-card-highlight"><strong>' . esc_html__( 'Offener Kassatag', 'lindenweg16' ) . '</strong>';
			echo '<div class="lw16-big">' . esc_html( $offener_tag->markt_name ?: gmdate( 'd.m.Y', strtotime( $offener_tag->datum ) ) ) . '</div>';
			echo '<p>' . esc_html( sprintf( __( 'Kassenbestand Anfang: %s €', 'lindenweg16' ), number_format( (float) $offener_tag->kassenbestand_anfang, 2, ',', '.' ) ) ) . '</p>';
			echo '<p>' . esc_html( sprintf( __( 'Verkäufe: %d Positionen – %s €', 'lindenweg16' ), count( $positionen ), number_format( $summe, 2, ',', '.' ) ) ) . '</p>';
			echo '<p style="margin-top:12px">';
			echo '<a class="button button-primary button-hero" href="' . esc_url( add_query_arg( 'view', 'kasse', $base ) ) . '">' . esc_html__( 'Kasse öffnen', 'lindenweg16' ) . '</a> ';
			echo '<a class="button button-hero" href="' . esc_url( add_query_arg( 'view', 'mobil', $base ) ) . '" target="_blank">' . esc_html__( 'Mobile Kasse', 'lindenweg16' ) . '</a>';
			echo '</p></div></div>';
		} else {
			echo '<h2>' . esc_html__( 'Neuen Kassatag starten', 'lindenweg16' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="lw16_pos_tag_start">';
			wp_nonce_field( 'lw16_pos_tag_start' );
			echo '<table class="form-table"><tbody>';
			echo '<tr><th>' . esc_html__( 'Markt / Anlass', 'lindenweg16' ) . '</th><td><input type="text" name="markt_name" class="regular-text" placeholder="' . esc_attr__( 'z. B. Weihnachtsmarkt Altstadt', 'lindenweg16' ) . '" required></td></tr>';
			echo '<tr><th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><td><input type="date" name="datum" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '" required></td></tr>';
			echo '<tr><th>' . esc_html__( 'Kassenbestand Anfang (€)', 'lindenweg16' ) . '</th><td><input type="number" step="0.01" name="kassenbestand_anfang" class="small-text" value="0.00" required></td></tr>';
			echo '</tbody></table>';
			submit_button( __( 'Kassatag starten', 'lindenweg16' ) );
			echo '</form>';
		}

		// Abgeschlossene Tage.
		echo '<h2 style="margin-top:30px">' . esc_html__( 'Abgeschlossene Kassatage', 'lindenweg16' ) . '</h2>';
		$tage = POSRepository::get_tage( 'abgeschlossen', 20 );
		if ( empty( $tage ) ) {
			echo '<p>' . esc_html__( 'Noch keine abgeschlossenen Kassatage.', 'lindenweg16' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th>';
			echo '<th>' . esc_html__( 'Markt', 'lindenweg16' ) . '</th>';
			echo '<th>' . esc_html__( 'Umsatz', 'lindenweg16' ) . '</th>';
			echo '<th>' . esc_html__( 'Kasse Anfang', 'lindenweg16' ) . '</th>';
			echo '<th>' . esc_html__( 'Kasse Ende', 'lindenweg16' ) . '</th>';
			echo '<th>' . esc_html__( 'Differenz', 'lindenweg16' ) . '</th>';
			echo '<th>' . esc_html__( 'Aktion', 'lindenweg16' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $tage as $t ) {
				$diff = (float) $t->kassenbestand_ende - (float) $t->kassenbestand_anfang - (float) $t->umsatz_gesamt;
				$diff_class = abs( $diff ) < 0.01 ? '' : ( $diff < 0 ? 'color:#dc3232' : 'color:#46b450' );
				echo '<tr>';
				echo '<td>' . esc_html( gmdate( 'd.m.Y', strtotime( $t->datum ) ) ) . '</td>';
				echo '<td>' . esc_html( $t->markt_name ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) $t->umsatz_gesamt, 2, ',', '.' ) ) . ' &euro;</td>';
				echo '<td>' . esc_html( number_format( (float) $t->kassenbestand_anfang, 2, ',', '.' ) ) . ' &euro;</td>';
				echo '<td>' . esc_html( number_format( (float) $t->kassenbestand_ende, 2, ',', '.' ) ) . ' &euro;</td>';
				echo '<td style="' . $diff_class . ';font-weight:600">' . esc_html( ( $diff >= 0 ? '+' : '' ) . number_format( $diff, 2, ',', '.' ) ) . ' &euro;</td>';
				echo '<td><a href="' . esc_url( add_query_arg( array( 'view' => 'tag_detail', 'id' => $t->id ), $base ) ) . '">' . esc_html__( 'Details', 'lindenweg16' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/* ------------------------------------------------------------------
	 * Desktop-Kasse
	 * ---------------------------------------------------------------- */

	protected function render_kasse() {
		$tag = POSRepository::get_offener_tag();
		if ( ! $tag ) {
			echo '<p>' . esc_html__( 'Kein offener Kassatag. Bitte zuerst einen Tag starten.', 'lindenweg16' ) . '</p>';
			return;
		}

		$positionen = POSRepository::get_positionen( $tag->id );
		$summe      = 0;
		foreach ( $positionen as $pos ) {
			if ( ! $pos->storniert ) {
				$summe += (float) $pos->betrag;
			}
		}

		$base     = admin_url( 'admin.php?page=lw16-kasse' );
		$produkte = self::sale_options();
		?>
		<h1><?php echo esc_html( sprintf( __( 'Kasse – %s', 'lindenweg16' ), $tag->markt_name ) ); ?></h1>
		<p>
			<?php echo esc_html( sprintf( __( 'Datum: %s | Kassenbestand Anfang: %s € | Verkäufe bisher: %s €', 'lindenweg16' ),
				gmdate( 'd.m.Y', strtotime( $tag->datum ) ),
				number_format( (float) $tag->kassenbestand_anfang, 2, ',', '.' ),
				number_format( $summe, 2, ',', '.' )
			) ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'view', 'mobil', $base ) ); ?>" target="_blank"><?php esc_html_e( 'Mobile Ansicht', 'lindenweg16' ); ?></a>
		</p>

		<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:16px">
			<div style="flex:1;min-width:320px">
				<h2><?php esc_html_e( 'Neuer Verkauf', 'lindenweg16' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="lw16_pos_verkauf">
					<input type="hidden" name="tag_id" value="<?php echo (int) $tag->id; ?>">
					<?php wp_nonce_field( 'lw16_pos_verkauf' ); ?>
					<table class="form-table"><tbody>
						<tr><th><?php esc_html_e( 'ERP-Produkt', 'lindenweg16' ); ?></th><td>
							<select name="produkt_id" id="lw16-pos-produkt">
								<option value=""><?php esc_html_e( '— Manuell eingeben —', 'lindenweg16' ); ?></option>
								<?php foreach ( $produkte as $p ) : ?>
									<option value="<?php echo esc_attr( $p['value'] ); ?>" data-name="<?php echo esc_attr( $p['name'] ); ?>" data-preis="<?php echo esc_attr( $p['preis'] ); ?>"><?php echo esc_html( $p['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td></tr>
						<tr><th><?php esc_html_e( 'Artikelname', 'lindenweg16' ); ?></th><td>
							<input type="text" name="artikel_name" id="lw16-pos-name" class="regular-text" placeholder="<?php esc_attr_e( 'z. B. Sonderteil XY', 'lindenweg16' ); ?>">
							<p class="description"><?php esc_html_e( 'Wird automatisch gefüllt, wenn ein ERP-Produkt gewählt wird.', 'lindenweg16' ); ?></p>
						</td></tr>
						<tr><th><?php esc_html_e( 'Menge', 'lindenweg16' ); ?></th><td><input type="number" step="1" min="1" name="menge" value="1" class="small-text" required></td></tr>
						<tr><th><?php esc_html_e( 'Einzelpreis (€)', 'lindenweg16' ); ?></th><td><input type="number" step="0.01" name="einzelpreis" class="small-text" required></td></tr>
					</tbody></table>
					<?php submit_button( __( 'Verkauf buchen', 'lindenweg16' ), 'primary' ); ?>
				</form>
				<script>
				document.getElementById('lw16-pos-produkt').addEventListener('change',function(){
					var sel=this.options[this.selectedIndex];
					if(!sel.value){return;}
					document.getElementById('lw16-pos-name').value=sel.dataset.name||sel.textContent.trim();
					// Preis nur vorschlagen, wenn das Feld noch leer ist -
					// am Marktstand wird oft ein Sonderpreis eingetippt.
					var preis=document.querySelector('[name="einzelpreis"]');
					if(preis&&sel.dataset.preis&&!parseFloat(preis.value)){preis.value=sel.dataset.preis;}
				});
				</script>

				<hr style="margin:24px 0">
				<h2><?php esc_html_e( 'Tagesabschluss', 'lindenweg16' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="lw16_pos_tag_abschluss">
					<input type="hidden" name="tag_id" value="<?php echo (int) $tag->id; ?>">
					<?php wp_nonce_field( 'lw16_pos_tag_abschluss' ); ?>
					<table class="form-table"><tbody>
						<tr><th><?php esc_html_e( 'Kassenbestand Ende (€)', 'lindenweg16' ); ?></th><td>
							<input type="number" step="0.01" name="kassenbestand_ende" class="small-text" required>
							<p class="description"><?php esc_html_e( 'Zähle das Bargeld in der Kasse und trage den Betrag ein.', 'lindenweg16' ); ?></p>
						</td></tr>
						<tr><th><?php esc_html_e( 'Notiz', 'lindenweg16' ); ?></th><td>
							<textarea name="notiz" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Anmerkungen zum Markttag…', 'lindenweg16' ); ?>"></textarea>
						</td></tr>
					</tbody></table>
					<?php submit_button( __( 'Tag abschließen & in Buchhaltung übernehmen', 'lindenweg16' ), 'primary' ); ?>
				</form>
			</div>

			<div style="flex:1;min-width:320px">
				<h2><?php esc_html_e( 'Heutige Verkäufe', 'lindenweg16' ); ?></h2>
				<?php if ( empty( $positionen ) ) : ?>
					<p><?php esc_html_e( 'Noch keine Verkäufe.', 'lindenweg16' ); ?></p>
				<?php else : ?>
				<table class="widefat striped"><thead><tr>
					<th><?php esc_html_e( 'Artikel', 'lindenweg16' ); ?></th>
					<th><?php esc_html_e( 'Menge', 'lindenweg16' ); ?></th>
					<th><?php esc_html_e( 'Betrag', 'lindenweg16' ); ?></th>
					<th><?php esc_html_e( 'Aktion', 'lindenweg16' ); ?></th>
				</tr></thead><tbody>
				<?php foreach ( $positionen as $pos ) : ?>
					<tr<?php echo $pos->storniert ? ' style="opacity:.4;text-decoration:line-through"' : ''; ?>>
						<td><?php echo esc_html( $pos->artikel_name ); ?></td>
						<td><?php echo (int) $pos->menge; ?></td>
						<td><?php echo esc_html( number_format( (float) $pos->betrag, 2, ',', '.' ) ); ?> &euro;</td>
						<td>
							<?php if ( ! $pos->storniert ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lw16_pos_storno&id=' . $pos->id ), 'lw16_pos_storno_' . $pos->id ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Verkauf stornieren?', 'lindenweg16' ); ?>')"><?php esc_html_e( 'Storno', 'lindenweg16' ); ?></a>
							<?php else : ?>
								<?php esc_html_e( 'storniert', 'lindenweg16' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr style="font-weight:700;border-top:2px solid #2271b1">
					<td colspan="2"><?php esc_html_e( 'Gesamt', 'lindenweg16' ); ?></td>
					<td><?php echo esc_html( number_format( $summe, 2, ',', '.' ) ); ?> &euro;</td>
					<td></td>
				</tr>
				</tbody></table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Tag-Detail (abgeschlossen)
	 * ---------------------------------------------------------------- */

	protected function render_tag_detail() {
		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$tag = $id ? POSRepository::get_tag( $id ) : null;
		if ( ! $tag ) {
			echo '<p>' . esc_html__( 'Kassatag nicht gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}

		$positionen = POSRepository::get_positionen( $tag->id );
		$summe      = 0;
		foreach ( $positionen as $pos ) {
			if ( ! $pos->storniert ) {
				$summe += (float) $pos->betrag;
			}
		}
		$diff = (float) $tag->kassenbestand_ende - (float) $tag->kassenbestand_anfang - $summe;
		?>
		<h1><?php echo esc_html( sprintf( __( 'Kassatag: %s – %s', 'lindenweg16' ), $tag->markt_name, gmdate( 'd.m.Y', strtotime( $tag->datum ) ) ) ); ?></h1>

		<div class="lw16-cards">
			<div class="lw16-card"><strong><?php esc_html_e( 'Kasse Anfang', 'lindenweg16' ); ?></strong><div class="lw16-big"><?php echo esc_html( number_format( (float) $tag->kassenbestand_anfang, 2, ',', '.' ) ); ?> &euro;</div></div>
			<div class="lw16-card lw16-card-highlight"><strong><?php esc_html_e( 'Umsatz', 'lindenweg16' ); ?></strong><div class="lw16-big"><?php echo esc_html( number_format( $summe, 2, ',', '.' ) ); ?> &euro;</div></div>
			<div class="lw16-card"><strong><?php esc_html_e( 'Kasse Ende', 'lindenweg16' ); ?></strong><div class="lw16-big"><?php echo esc_html( number_format( (float) $tag->kassenbestand_ende, 2, ',', '.' ) ); ?> &euro;</div></div>
			<div class="lw16-card <?php echo abs( $diff ) < 0.01 ? 'lw16-card-success' : 'lw16-card-warn'; ?>"><strong><?php esc_html_e( 'Differenz', 'lindenweg16' ); ?></strong><div class="lw16-big"><?php echo esc_html( ( $diff >= 0 ? '+' : '' ) . number_format( $diff, 2, ',', '.' ) ); ?> &euro;</div></div>
		</div>

		<?php if ( $tag->notiz ) : ?>
			<p style="margin-top:12px"><strong><?php esc_html_e( 'Notiz:', 'lindenweg16' ); ?></strong> <?php echo esc_html( $tag->notiz ); ?></p>
		<?php endif; ?>

		<?php if ( $tag->beleg_id ) : ?>
			<p><?php echo esc_html( sprintf( __( 'Beleg-Nr. in Buchhaltung: #%d', 'lindenweg16' ), $tag->beleg_id ) ); ?></p>
		<?php endif; ?>

		<h2 style="margin-top:24px"><?php esc_html_e( 'Verkäufe', 'lindenweg16' ); ?></h2>
		<table class="widefat striped"><thead><tr>
			<th><?php esc_html_e( 'Zeit', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Artikel', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Menge', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Betrag', 'lindenweg16' ); ?></th>
			<th><?php esc_html_e( 'Status', 'lindenweg16' ); ?></th>
		</tr></thead><tbody>
		<?php foreach ( $positionen as $pos ) : ?>
			<tr<?php echo $pos->storniert ? ' style="opacity:.4;text-decoration:line-through"' : ''; ?>>
				<td><?php echo esc_html( gmdate( 'H:i', strtotime( $pos->erstellt_am ) ) ); ?></td>
				<td><?php echo esc_html( $pos->artikel_name ); ?></td>
				<td><?php echo (int) $pos->menge; ?></td>
				<td><?php echo esc_html( number_format( (float) $pos->betrag, 2, ',', '.' ) ); ?> &euro;</td>
				<td><?php echo $pos->storniert ? esc_html__( 'Storniert', 'lindenweg16' ) : esc_html__( 'OK', 'lindenweg16' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Historie
	 * ---------------------------------------------------------------- */

	protected function render_historie() {
		// Wird aktuell über render_uebersicht() abgebildet (abgeschlossene Tage).
		$this->render_uebersicht();
	}

	/* ------------------------------------------------------------------
	 * Mobile Kasse (eigenständiges HTML – wird im Vollbild gerendert)
	 * ---------------------------------------------------------------- */

	protected function render_mobil() {
		$tag = POSRepository::get_offener_tag();
		if ( ! $tag ) {
			echo '<p>' . esc_html__( 'Kein offener Kassatag.', 'lindenweg16' ) . '</p></div>';
			return;
		}

		$positionen = POSRepository::get_positionen( $tag->id );
		$summe      = 0;
		foreach ( $positionen as $pos ) {
			if ( ! $pos->storniert ) {
				$summe += (float) $pos->betrag;
			}
		}

		$produkte = self::sale_options();
		$sell_url = admin_url( 'admin-post.php' );
		$close_url = admin_url( 'admin-post.php' );
		$storno_base = admin_url( 'admin-post.php?action=lw16_pos_storno' );
		?>
		<!DOCTYPE html>
		<html lang="de">
		<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php echo esc_html( sprintf( __( 'Kasse – %s', 'lindenweg16' ), $tag->markt_name ) ); ?></title>
		<style>
			*{box-sizing:border-box;margin:0;padding:0}
			body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f0f1;padding:12px;padding-bottom:120px}
			.pos-header{background:#1d2327;color:#fff;border-radius:10px;padding:16px;margin-bottom:12px;text-align:center}
			.pos-header h1{font-size:20px;margin-bottom:4px}
			.pos-header .pos-total{font-size:36px;font-weight:700;margin:8px 0}
			.pos-header .pos-meta{font-size:13px;opacity:.7}
			.pos-section{background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
			.pos-section h2{font-size:16px;margin-bottom:12px}
			.pos-field{margin-bottom:12px}
			.pos-field label{display:block;font-size:13px;font-weight:600;margin-bottom:4px}
			.pos-field select,.pos-field input[type=text],.pos-field input[type=number],.pos-field textarea{width:100%;padding:12px;font-size:16px;border:2px solid #ddd;border-radius:8px;-webkit-appearance:none}
			.pos-field select:focus,.pos-field input:focus{border-color:#2271b1;outline:none}
			.pos-btn{display:block;width:100%;padding:16px;font-size:18px;font-weight:700;border:none;border-radius:10px;cursor:pointer;margin-top:8px}
			.pos-btn-primary{background:#2271b1;color:#fff}
			.pos-btn-danger{background:#dc3232;color:#fff}
			.pos-btn-secondary{background:#ddd;color:#1d2327}
			.pos-items{max-height:300px;overflow-y:auto}
			.pos-item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #eee}
			.pos-item.storno{opacity:.3;text-decoration:line-through}
			.pos-item .pos-item-name{font-weight:600;font-size:15px}
			.pos-item .pos-item-price{font-weight:700;font-size:15px;color:#2271b1}
			.pos-item .pos-item-storno{color:#dc3232;font-size:13px;text-decoration:none;margin-left:8px}
			.pos-row{display:flex;gap:8px}
			.pos-row > *{flex:1}
			.pos-close-section{background:#1d2327;border-radius:10px;padding:16px}
			.pos-close-section h2{color:#fff;font-size:16px;margin-bottom:12px}
			.pos-close-section label{color:#fff}
		</style>
		</head>
		<body>

		<div class="pos-header">
			<h1><?php echo esc_html( $tag->markt_name ); ?></h1>
			<div class="pos-meta"><?php echo esc_html( gmdate( 'd.m.Y', strtotime( $tag->datum ) ) ); ?> | <?php echo esc_html( sprintf( __( 'Start: %s €', 'lindenweg16' ), number_format( (float) $tag->kassenbestand_anfang, 2, ',', '.' ) ) ); ?></div>
			<div class="pos-total" id="pos-total"><?php echo esc_html( number_format( $summe, 2, ',', '.' ) ); ?> &euro;</div>
			<div class="pos-meta"><?php esc_html_e( 'Tagesumsatz', 'lindenweg16' ); ?></div>
		</div>

		<div class="pos-section">
			<h2><?php esc_html_e( 'Verkauf', 'lindenweg16' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $sell_url ); ?>">
				<input type="hidden" name="action" value="lw16_pos_verkauf">
				<input type="hidden" name="tag_id" value="<?php echo (int) $tag->id; ?>">
				<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( add_query_arg( array( 'page' => 'lw16-kasse', 'view' => 'mobil' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php wp_nonce_field( 'lw16_pos_verkauf' ); ?>

				<div class="pos-field">
					<label><?php esc_html_e( 'Produkt', 'lindenweg16' ); ?></label>
					<select name="produkt_id" id="m-pos-produkt">
						<option value=""><?php esc_html_e( '— Manuell —', 'lindenweg16' ); ?></option>
						<?php foreach ( $produkte as $p ) : ?>
							<option value="<?php echo esc_attr( $p['value'] ); ?>" data-name="<?php echo esc_attr( $p['name'] ); ?>" data-preis="<?php echo esc_attr( $p['preis'] ); ?>"><?php echo esc_html( $p['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="pos-field">
					<label><?php esc_html_e( 'Artikelname', 'lindenweg16' ); ?></label>
					<input type="text" name="artikel_name" id="m-pos-name" placeholder="<?php esc_attr_e( 'Artikelbezeichnung', 'lindenweg16' ); ?>">
				</div>
				<div class="pos-row">
					<div class="pos-field">
						<label><?php esc_html_e( 'Menge', 'lindenweg16' ); ?></label>
						<input type="number" name="menge" value="1" min="1" step="1">
					</div>
					<div class="pos-field">
						<label><?php esc_html_e( 'Preis (€)', 'lindenweg16' ); ?></label>
						<input type="number" name="einzelpreis" step="0.01" min="0" required inputmode="decimal">
					</div>
				</div>
				<button type="submit" class="pos-btn pos-btn-primary"><?php esc_html_e( 'Kassieren', 'lindenweg16' ); ?></button>
			</form>
		</div>

		<script>
		document.getElementById('m-pos-produkt').addEventListener('change',function(){
			var o=this.options[this.selectedIndex];
			if(!o.value){return;}
			document.getElementById('m-pos-name').value=o.dataset.name||'';
			var preis=document.querySelector('[name="einzelpreis"]');
			if(preis&&o.dataset.preis&&!parseFloat(preis.value)){preis.value=o.dataset.preis;}
		});
		</script>

		<div class="pos-section">
			<h2><?php esc_html_e( 'Verkäufe heute', 'lindenweg16' ); ?> (<?php echo count( $positionen ); ?>)</h2>
			<div class="pos-items">
			<?php foreach ( array_reverse( $positionen ) as $pos ) : ?>
				<div class="pos-item <?php echo $pos->storniert ? 'storno' : ''; ?>">
					<div>
						<div class="pos-item-name"><?php echo esc_html( $pos->artikel_name ); ?> &times;<?php echo (int) $pos->menge; ?></div>
						<div style="font-size:12px;color:#666"><?php echo esc_html( gmdate( 'H:i', strtotime( $pos->erstellt_am ) ) ); ?></div>
					</div>
					<div>
						<span class="pos-item-price"><?php echo esc_html( number_format( (float) $pos->betrag, 2, ',', '.' ) ); ?> &euro;</span>
						<?php if ( ! $pos->storniert ) : ?>
							<a href="<?php echo esc_url( wp_nonce_url( $storno_base . '&id=' . $pos->id . '&_wp_http_referer=' . rawurlencode( add_query_arg( array( 'page' => 'lw16-kasse', 'view' => 'mobil' ), admin_url( 'admin.php' ) ) ), 'lw16_pos_storno_' . $pos->id ) ); ?>" class="pos-item-storno" onclick="return confirm('<?php esc_attr_e( 'Stornieren?', 'lindenweg16' ); ?>')">&times;</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
		</div>

		<div class="pos-close-section">
			<h2><?php esc_html_e( 'Tagesabschluss', 'lindenweg16' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $close_url ); ?>">
				<input type="hidden" name="action" value="lw16_pos_tag_abschluss">
				<input type="hidden" name="tag_id" value="<?php echo (int) $tag->id; ?>">
				<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( add_query_arg( array( 'page' => 'lw16-kasse', 'view' => 'mobil' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php wp_nonce_field( 'lw16_pos_tag_abschluss' ); ?>
				<div class="pos-field">
					<label><?php esc_html_e( 'Kassenbestand Ende (€)', 'lindenweg16' ); ?></label>
					<input type="number" name="kassenbestand_ende" step="0.01" required inputmode="decimal" style="background:#fff;color:#1d2327">
				</div>
				<div class="pos-field">
					<label><?php esc_html_e( 'Notiz', 'lindenweg16' ); ?></label>
					<textarea name="notiz" rows="2" placeholder="<?php esc_attr_e( 'Anmerkungen…', 'lindenweg16' ); ?>" style="background:#fff;color:#1d2327"></textarea>
				</div>
				<button type="submit" class="pos-btn pos-btn-danger"><?php esc_html_e( 'Tag abschließen', 'lindenweg16' ); ?></button>
			</form>
		</div>

		</body>
		</html>
		<?php
		exit;
	}

	/* ------------------------------------------------------------------
	 * POST-Handler
	 * ---------------------------------------------------------------- */

	public function handle_tag_start() {
		$this->guard( 'lw16_pos_tag_start' );

		$existing = POSRepository::get_offener_tag();
		if ( $existing ) {
			$this->redirect( __( 'Es gibt bereits einen offenen Kassatag. Bitte zuerst abschließen.', 'lindenweg16' ) );
		}

		$id = POSRepository::create_tag( array(
			'markt_name'           => sanitize_text_field( wp_unslash( $_POST['markt_name'] ?? '' ) ),
			'datum'                => sanitize_text_field( wp_unslash( $_POST['datum'] ?? gmdate( 'Y-m-d' ) ) ),
			'kassenbestand_anfang' => (float) ( $_POST['kassenbestand_anfang'] ?? 0 ),
		) );

		AuditLog::record( 'pos_tag_gestartet', 'pos_tag', $id );
		$this->redirect( __( 'Kassatag gestartet.', 'lindenweg16' ) );
	}

	/**
	 * Verkäufliche Positionen für die Kassenauswahl.
	 *
	 * Führend sind die Betriebsartikel – seit der Zusammenlegung die einzige
	 * Lagerverwaltung. Produkte der abgelösten Verwaltung erscheinen nur
	 * noch, solange sie keinen Betriebsartikel haben; sonst stünde dieselbe
	 * Ware zweimal in der Liste und man könnte ins falsche Lager buchen.
	 *
	 * @return array Einträge mit value, label, name und preis.
	 */
	public static function sale_options() {
		$options = array();
		$legacy  = array();

		foreach ( ArticleRepository::selectable( true ) as $article ) {
			$options[] = array(
				'value' => 'a:' . (int) $article['id'],
				'label' => $article['sku'] . ' – ' . $article['name'],
				'name'  => (string) $article['name'],
				'preis' => null === $article['price_gross'] ? '' : number_format( (float) $article['price_gross'], 2, '.', '' ),
			);

			if ( ! empty( $article['legacy_produkt_id'] ) ) {
				$legacy[ (int) $article['legacy_produkt_id'] ] = true;
			}
		}

		foreach ( LagerRepository::get_products() as $product ) {
			if ( isset( $legacy[ (int) $product->id ] ) ) {
				continue;
			}

			$options[] = array(
				'value' => 'p:' . (int) $product->id,
				'label' => $product->name . ' (' . $product->typ . ')',
				'name'  => (string) $product->name,
				'preis' => '',
			);
		}

		return $options;
	}

	/**
	 * Auswahlwert der Kasse zerlegen – reine Zeichenkettenlogik.
	 *
	 * Alte Kassentage schickten die nackte Produkt-ID. Die wird weiterhin
	 * verstanden, damit ein offener Tab von vor dem Update nichts kaputt
	 * macht.
	 *
	 * @param string $raw Wert aus dem Formular.
	 * @return array {article_id, produkt_id} – jeweils null, wenn nicht gesetzt.
	 */
	public static function parse_choice( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return array( 'article_id' => null, 'produkt_id' => null );
		}

		if ( 0 === strpos( $raw, 'a:' ) ) {
			$id = (int) substr( $raw, 2 );
			return array( 'article_id' => $id ? $id : null, 'produkt_id' => null );
		}

		if ( 0 === strpos( $raw, 'p:' ) ) {
			$id = (int) substr( $raw, 2 );
			return array( 'article_id' => null, 'produkt_id' => $id ? $id : null );
		}

		$id = (int) $raw;

		return array( 'article_id' => null, 'produkt_id' => $id ? $id : null );
	}

	public function handle_verkauf() {
		$this->guard( 'lw16_pos_verkauf' );

		$tag_id      = (int) ( $_POST['tag_id'] ?? 0 );
		$tag         = POSRepository::get_tag( $tag_id );
		if ( ! $tag || 'offen' !== $tag->status ) {
			$this->redirect( __( 'Kein offener Kassatag.', 'lindenweg16' ) );
		}

		$auswahl     = self::parse_choice( wp_unslash( $_POST['produkt_id'] ?? '' ) );
		$produkt_id  = $auswahl['produkt_id'];
		$article_id  = $auswahl['article_id'];
		$artikel     = sanitize_text_field( wp_unslash( $_POST['artikel_name'] ?? '' ) );
		$menge       = max( 1, (int) ( $_POST['menge'] ?? 1 ) );
		$einzelpreis = (float) ( $_POST['einzelpreis'] ?? 0 );

		if ( ! $artikel && $article_id ) {
			$a       = ArticleRepository::find( $article_id );
			$artikel = $a ? $a['name'] : __( 'Unbekannt', 'lindenweg16' );
		}
		if ( ! $artikel && $produkt_id ) {
			$p = LagerRepository::get_product( $produkt_id );
			$artikel = $p ? $p->name : __( 'Unbekannt', 'lindenweg16' );
		}
		if ( ! $artikel ) {
			$artikel = __( 'Manueller Artikel', 'lindenweg16' );
		}

		$betrag = $menge * $einzelpreis;

		POSRepository::create_position( array(
			'tag_id'       => $tag_id,
			'produkt_id'   => $produkt_id,
			'article_id'   => $article_id,
			'artikel_name' => $artikel,
			'menge'        => $menge,
			'einzelpreis'  => $einzelpreis,
			'betrag'       => $betrag,
		) );

		// Lagerbestand abbuchen. Die Weiche entscheidet, welche
		// Lagerverwaltung zustaendig ist - seit der Zusammenlegung ist das
		// bei bekannten Artikeln das Modul Betrieb. Ohne sie blieb der
		// Marktverkauf dort unsichtbar und die Bedarfsplanung rechnete mit
		// Ware, die laengst im Karton lag.
		if ( $produkt_id || $article_id ) {
			$referenz = sprintf( /* translators: %s: Marktname. */ __( 'Marktverkauf %s', 'lindenweg16' ), $tag->markt_name );

			$booked = StockOwnership::book(
				array( 'article_id' => $article_id, 'legacy_produkt_id' => $produkt_id ),
				-1 * abs( $menge ),
				'verkauf',
				array(
					'ref_type' => 'kasse',
					'ref_id'   => (int) $tag_id,
					'note'     => $referenz,
				)
			);

			if ( 'betrieb' !== $booked && $produkt_id ) {
				StockManager::record_movement( $produkt_id, -1 * abs( $menge ), 'verkauf', array( 'referenz' => $referenz ) );
			}
		}

		$this->redirect_back( __( 'Verkauf gebucht.', 'lindenweg16' ) );
	}

	public function handle_storno() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_pos_storno_' . $id );

		$pos = POSRepository::get_position( $id );
		if ( $pos && ! $pos->storniert ) {
			POSRepository::update_position( $id, array( 'storniert' => 1 ) );

			// Lagerbestand zurückbuchen – über dieselbe Weiche wie der
			// Verkauf, sonst landet der Storno im anderen Lager als der
			// Verkauf und beide stehen dauerhaft falsch.
			if ( $pos->produkt_id || ! empty( $pos->article_id ) ) {
				$tag      = POSRepository::get_tag( $pos->tag_id );
				$referenz = sprintf( /* translators: %s: Marktname. */ __( 'Storno Marktverkauf %s', 'lindenweg16' ), $tag ? $tag->markt_name : '' );

				$booked = StockOwnership::book(
					array(
						'article_id'        => isset( $pos->article_id ) ? (int) $pos->article_id : 0,
						'legacy_produkt_id' => (int) $pos->produkt_id,
					),
					abs( (int) $pos->menge ),
					'retoure',
					array(
						'ref_type' => 'kasse',
						'ref_id'   => (int) $pos->tag_id,
						'note'     => $referenz,
					)
				);

				if ( 'betrieb' !== $booked && $pos->produkt_id ) {
					StockManager::record_movement( (int) $pos->produkt_id, abs( (int) $pos->menge ), 'retoure', array( 'referenz' => $referenz ) );
				}
			}
		}

		$this->redirect_back( __( 'Verkauf storniert.', 'lindenweg16' ) );
	}

	public function handle_tag_abschluss() {
		$this->guard( 'lw16_pos_tag_abschluss' );

		$tag_id = (int) ( $_POST['tag_id'] ?? 0 );
		$tag    = POSRepository::get_tag( $tag_id );
		if ( ! $tag || 'offen' !== $tag->status ) {
			$this->redirect( __( 'Kein offener Kassatag.', 'lindenweg16' ) );
		}

		$kassenbestand_ende = (float) ( $_POST['kassenbestand_ende'] ?? 0 );
		$notiz              = sanitize_textarea_field( wp_unslash( $_POST['notiz'] ?? '' ) );

		// Umsatz berechnen.
		$positionen = POSRepository::get_positionen( $tag_id );
		$umsatz     = 0;
		foreach ( $positionen as $pos ) {
			if ( ! $pos->storniert ) {
				$umsatz += (float) $pos->betrag;
			}
		}

		// Beleg in der Buchhaltung anlegen (Einnahme).
		$beleg_id = null;
		if ( $umsatz > 0 ) {
			$beleg_id = BelegRepository::create( array(
				'betrag'        => $umsatz,
				'datum'         => $tag->datum,
				'haendler'      => $tag->markt_name,
				'eur_kategorie' => 'Marktverkauf',
				'notiz'         => $notiz ?: sprintf( __( 'Tagesumsatz Markt: %s', 'lindenweg16' ), $tag->markt_name ),
				'quelle'        => 'marktkasse',
			) );
		}

		// Kassenbuch-Eintrag: Tagesumsatz als Bareinnahme buchen.
		$kassenbuch_id = null;
		if ( $umsatz > 0 ) {
			$kassenbuch_id = KassenbuchRepository::create( array(
				'datum'        => $tag->datum,
				'beschreibung' => sprintf( __( 'Marktkasse %s – Tagesumsatz', 'lindenweg16' ), $tag->markt_name ),
				'einnahme'     => $umsatz,
				'ausgabe'      => 0,
				'beleg_id'     => $beleg_id,
			) );
		}

		POSRepository::update_tag( $tag_id, array(
			'status'              => 'abgeschlossen',
			'kassenbestand_ende'  => $kassenbestand_ende,
			'umsatz_gesamt'       => $umsatz,
			'notiz'               => $notiz,
			'beleg_id'            => $beleg_id,
			'abgeschlossen_am'    => current_time( 'mysql' ),
		) );

		AuditLog::record( 'pos_tag_abgeschlossen', 'pos_tag', $tag_id, array(
			'umsatz'        => $umsatz,
			'kasse_ende'    => $kassenbestand_ende,
			'beleg_id'      => $beleg_id,
			'kassenbuch_id' => $kassenbuch_id,
		) );

		$this->redirect( sprintf( __( 'Kassatag abgeschlossen. Umsatz: %s € – Beleg #%d in Buchhaltung, Kassenbuch-Eintrag #%d angelegt.', 'lindenweg16' ), number_format( $umsatz, 2, ',', '.' ), $beleg_id ?: 0, $kassenbuch_id ?: 0 ) );
	}

	/* ------------------------------------------------------------------
	 * AJAX Produktsuche
	 * ---------------------------------------------------------------- */

	public function ajax_produkt_suche() {
		check_ajax_referer( 'lw16_admin' );
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_send_json_error();
		}
		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( array( 'items' => array() ) );
		}
		global $wpdb;
		$t    = $wpdb->prefix . 'lw16_produkte';
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, typ FROM {$t} WHERE name LIKE %s LIMIT 10", $like ) ); // phpcs:ignore
		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array( 'id' => (int) $r->id, 'name' => $r->name, 'typ' => $r->typ );
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	/* ------------------------------------------------------------------
	 * Helfer
	 * ---------------------------------------------------------------- */

	protected function guard( $nonce ) {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( $nonce );
	}

	protected function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-kasse', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	protected function redirect_back( $msg ) {
		$referer = wp_get_referer();
		if ( $referer && false !== strpos( $referer, 'view=mobil' ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-kasse', 'view' => 'mobil' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-kasse', 'view' => 'kasse', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
