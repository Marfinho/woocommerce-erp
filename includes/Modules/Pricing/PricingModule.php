<?php
/**
 * Modul 5: Preiskalkulation.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Pricing;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\CalculationContext;
use Lindenweg16\Modules\Betrieb\CostCalculator;

/**
 * Admin-Oberfläche für die Preiskalkulation samt „Preis übernehmen".
 * Schreibt _regular_price und _lw16_margin_percent ins WooCommerce-Produkt.
 */
class PricingModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_page_lw16_preiskalkulation', array( $this, 'render_admin' ) );
		add_action( 'admin_post_lw16_preis_uebernehmen', array( $this, 'handle_uebernehmen' ) );
		add_action( 'admin_post_lw16_preis_alle_uebernehmen', array( $this, 'handle_alle_uebernehmen' ) );
	}

	/**
	 * Kalkulationsseite rendern.
	 */
	public function render_admin() {
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Preiskalkulation', 'lindenweg16' ) . '</h1>';

		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		echo '<p>' . esc_html__( 'Gerechnet wird mit den Zahlen aus dem Modul Betrieb: Herstellkosten aus Revision und Stückliste, Maschinenstundensatz je Gerät, Annahmen zum Stichtag. Diese Seite macht daraus einen Preisvorschlag und schreibt ihn auf Wunsch ins WooCommerce-Produkt.', 'lindenweg16' ) . '</p>';

		printf(
			'<p class="description">%s <a href="%s">%s</a></p>',
			esc_html__( 'Die Annahmen selbst werden nicht mehr hier gepflegt, sondern historisiert unter:', 'lindenweg16' ),
			esc_url( admin_url( 'admin.php?page=lw16-betrieb-einstellungen' ) ),
			esc_html__( 'Betrieb → Einstellungen → Kalkulationsannahmen', 'lindenweg16' )
		);

		$margin = $this->target_margin();

		echo '<form method="get" style="margin:16px 0">';
		echo '<input type="hidden" name="page" value="lw16-preiskalkulation" />';
		printf(
			'<label>%s <input type="number" step="1" min="0" max="95" name="marge" value="%s" class="small-text" /> %%</label> ',
			esc_html__( 'Zielmarge (Deckungsbeitrag II vom Nettoerlös)', 'lindenweg16' ),
			esc_attr( (string) round( $margin * 100 ) )
		);
		printf( '<button class="button">%s</button>', esc_html__( 'Neu rechnen', 'lindenweg16' ) );
		echo '</form>';

		$this->render_article_table( $margin );

		echo '</div>';
	}

	/**
	 * Zielmarge aus der Adresszeile, sonst der Startwert.
	 *
	 * @return float Anteil zwischen 0 und 1.
	 */
	protected function target_margin() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$raw = isset( $_GET['marge'] ) ? (float) $_GET['marge'] : 45.0;

		return max( 0.0, min( 0.95, $raw / 100 ) );
	}

	/**
	 * Alle verkäuflichen Artikel mit Kosten, Vorschlag und Ist-Preis.
	 *
	 * @param float $margin Zielmarge.
	 */
	protected function render_article_table( $margin ) {
		$ctx      = CalculationContext::build();
		$settings = CalculationContext::settings();
		$articles = ArticleRepository::selectable( true );

		if ( ! $articles ) {
			echo '<p>' . esc_html__( 'Noch kein verkäuflicher Artikel angelegt.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<div class="lw16-scrollx"><table class="widefat striped lw16-table--zahlen"><thead><tr>';
		foreach ( array(
			__( 'Artikel', 'lindenweg16' ),
			__( 'Herstellkosten', 'lindenweg16' ),
			__( 'Vorschlag', 'lindenweg16' ),
			__( 'Aktuell im Shop', 'lindenweg16' ),
			__( 'DB II aktuell', 'lindenweg16' ),
			__( 'Marge aktuell', 'lindenweg16' ),
			'',
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$uebernehmbar = 0;

		foreach ( $articles as $article ) {
			$id   = (int) $article['id'];
			$cost = CostCalculator::manufacturing_cost( $id, $ctx, $settings );

			$wc      = ! empty( $article['wc_product_id'] ) && function_exists( 'wc_get_product' )
				? wc_get_product( (int) $article['wc_product_id'] )
				: null;
			$aktuell = $wc ? (float) $wc->get_regular_price() : 0.0;

			$vorschlag = CostCalculator::price_for_margin( $cost, $settings, $margin );
			$ist       = CostCalculator::contribution_from_cost( $cost, $aktuell, $settings );

			echo '<tr>';
			printf( '<td><strong>%s</strong><br /><small>%s</small></td>', esc_html( $article['sku'] ), esc_html( $article['name'] ) );
			printf( '<td>%s</td>', esc_html( $this->euro( $cost['herstellkosten'] ) ) );
			printf(
				'<td><strong>%s</strong></td>',
				null === $vorschlag ? esc_html__( 'nicht erreichbar', 'lindenweg16' ) : esc_html( $this->euro( $vorschlag ) )
			);
			printf( '<td>%s</td>', $aktuell > 0 ? esc_html( $this->euro( $aktuell ) ) : '—' );
			printf( '<td>%s</td>', $aktuell > 0 ? esc_html( $this->euro( $ist['db2'] ) ) : '—' );

			if ( $aktuell > 0 ) {
				printf(
					'<td><span class="lw16-ampel lw16-ampel--%s">%s %%</span></td>',
					$ist['db2_pct'] >= $margin ? 'gruen' : ( $ist['db2_pct'] > 0 ? 'gelb' : 'rot' ),
					esc_html( number_format_i18n( $ist['db2_pct'] * 100, 1 ) )
				);
			} else {
				echo '<td>—</td>';
			}

			echo '<td>';
			if ( $wc && null !== $vorschlag ) {
				$uebernehmbar++;
				printf(
					'<a class="button button-small" href="%s" onclick="return confirm(\'%s\')">%s</a>',
					esc_url(
						wp_nonce_url(
							admin_url( 'admin-post.php?action=lw16_preis_uebernehmen&article=' . $id . '&marge=' . rawurlencode( (string) $margin ) ),
							'lw16_preis_' . $id
						)
					),
					esc_attr__( 'Diesen Preis ins Shop-Produkt schreiben?', 'lindenweg16' ),
					esc_html__( 'Übernehmen', 'lindenweg16' )
				);
			} elseif ( ! $wc ) {
				echo '<small>' . esc_html__( 'kein Shop-Produkt', 'lindenweg16' ) . '</small>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table></div>';

		if ( ! $uebernehmbar ) {
			return;
		}

		printf(
			'<p><a class="button button-primary" href="%s" onclick="return confirm(\'%s\')">%s</a> <span class="description">%s</span></p>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=lw16_preis_alle_uebernehmen&marge=' . rawurlencode( (string) $margin ) ),
					'lw16_alle_uebernehmen'
				)
			),
			esc_attr__( 'Wirklich alle Shop-Preise überschreiben?', 'lindenweg16' ),
			esc_html__( 'Alle Preise übernehmen', 'lindenweg16' ),
			esc_html__( 'Überschreibt den Normalpreis jedes verknüpften Produkts. Ein Angebotspreis in WooCommerce bleibt unberührt.', 'lindenweg16' )
		);
	}

	/**
	 * Betrag mit Währung.
	 *
	 * @param float $value Wert.
	 * @return string
	 */
	protected function euro( $value ) {
		return number_format_i18n( (float) $value, 2 ) . ' €';
	}

	/**
	 * Vorgeschlagenen Preis ins WooCommerce-Produkt übernehmen und die
	 * tatsächliche Marge in _lw16_margin_percent schreiben.
	 */
	public function handle_uebernehmen() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		$id = (int) ( $_GET['article'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'lw16_preis_' . $id );

		$result = $this->apply_price( $id, $this->target_margin() );

		$this->redirect( $result['message'] );
	}

	/**
	 * Alle kalkulierten Preise in die WooCommerce-Produkte schreiben.
	 */
	public function handle_alle_uebernehmen() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_alle_uebernehmen' );

		$margin = $this->target_margin();
		$count  = 0;

		foreach ( ArticleRepository::selectable( true ) as $article ) {
			if ( $this->apply_price( (int) $article['id'], $margin )['success'] ) {
				$count++;
			}
		}

		$this->redirect(
			sprintf(
				/* translators: %d: Anzahl. */
				__( '%d Preise übernommen.', 'lindenweg16' ),
				$count
			)
		);
	}

	/**
	 * Vorschlagspreis eines Artikels ins Shop-Produkt schreiben.
	 *
	 * Geschrieben wird ausschließlich der Normalpreis. Ein laufendes Angebot
	 * in WooCommerce bleibt unberührt – sonst wäre eine Preisaktion mit einem
	 * Klick weg, ohne dass es jemand merkt.
	 *
	 * @param int   $article_id Artikel.
	 * @param float $margin     Zielmarge.
	 * @return array {success, message}
	 */
	protected function apply_price( $article_id, $margin ) {
		$article = ArticleRepository::find( $article_id );

		if ( ! $article || empty( $article['wc_product_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Kein zugeordnetes WooCommerce-Produkt.', 'lindenweg16' ),
			);
		}

		$wc = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $article['wc_product_id'] ) : null;

		if ( ! $wc ) {
			return array(
				'success' => false,
				'message' => __( 'WooCommerce-Produkt nicht gefunden.', 'lindenweg16' ),
			);
		}

		$settings = CalculationContext::settings();
		$cost     = CostCalculator::manufacturing_cost( $article_id, CalculationContext::build(), $settings );
		$preis    = CostCalculator::price_for_margin( $cost, $settings, $margin );

		if ( null === $preis ) {
			return array(
				'success' => false,
				'message' => __( 'Diese Marge ist mit den aktuellen Annahmen nicht erreichbar.', 'lindenweg16' ),
			);
		}

		$wc->set_regular_price( (string) $preis );
		$wc->save();

		$ergebnis = CostCalculator::contribution_from_cost( $cost, $preis, $settings );

		// Marge fürs Empfehlungsprogramm hinterlegen – dort wird die
		// Prämie daraus abgeleitet.
		update_post_meta( (int) $article['wc_product_id'], '_lw16_margin_percent', round( $ergebnis['db2_pct'] * 100, 2 ) );

		// Auch am Betriebsartikel festhalten, damit die Kalkulation dort
		// denselben Preis zeigt wie der Shop.
		ArticleRepository::update( $article_id, array( 'price_gross' => $preis ), __( 'Preisübernahme aus der Kalkulation.', 'lindenweg16' ) );

		AuditLog::record(
			'preis_uebernommen',
			'article',
			$article_id,
			array( 'preis' => $preis, 'marge' => $ergebnis['db2_pct'] )
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: Artikelnummer, 2: Preis, 3: Marge. */
				__( '%1$s: %2$s € übernommen (Marge %3$s %%).', 'lindenweg16' ),
				$article['sku'],
				number_format_i18n( $preis, 2 ),
				number_format_i18n( $ergebnis['db2_pct'] * 100, 1 )
			),
		);
	}

	/**
	 * Berechtigung + Nonce.
	 *
	 * @param string $nonce Nonce.
	 */
	protected function guard( $nonce ) {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Zurück zur Kalkulationsseite.
	 *
	 * @param string $msg Meldung.
	 */
	protected function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-preiskalkulation', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
