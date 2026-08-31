<?php
/**
 * Bestellungen: WooCommerce-Bestellungen mit Betriebsfeldern plus manuelle
 * Erfassung für Offline-Verkäufe.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;
use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\CalculationContext;
use Lindenweg16\Modules\Betrieb\CostCalculator;
use Lindenweg16\Modules\Betrieb\ContactRepository;
use Lindenweg16\Modules\Betrieb\DeliveryNote;
use Lindenweg16\Modules\Betrieb\DiscountResolver;
use Lindenweg16\Modules\Betrieb\OrderOpsRepository;

/**
 * Rendert die Bestellseite.
 */
class OrdersPage {

	const NONCE = 'lw16_betrieb_order';
	const SLUG  = 'lw16-betrieb-bestellungen';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_bestellungen', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_save_order', array( $this, 'handle_save' ) );
		add_action( 'admin_post_lw16_betrieb_manual_order', array( $this, 'handle_manual' ) );
		add_action( 'admin_post_lw16_betrieb_confirm_order', array( $this, 'handle_confirm' ) );
		add_action( 'admin_post_lw16_betrieb_delivery_note', array( $this, 'handle_delivery_note' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Live-Rechner für die Rabattstaffel laden.
	 *
	 * @param string $hook Aktueller Admin-Hook.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			return;
		}

		wp_enqueue_script(
			'lw16-betrieb-staffel',
			LW16_PLUGIN_URL . 'assets/js/betrieb-staffel.js',
			array(),
			Assets::version( 'assets/js/betrieb-staffel.js' ),
			true
		);

		wp_add_inline_script(
			'lw16-betrieb-staffel',
			'window.LW16Staffel = ' . wp_json_encode( self::staffel_config() ) . ';',
			'before'
		);
	}

	/**
	 * Daten für den Live-Rechner.
	 *
	 * Enthält neben Preisen auch Herstellkosten und Nachbearbeitungszeit –
	 * das ist eine reine Backend-Ansicht. Die öffentliche Nachbestell-Seite
	 * bekommt diese Werte ausdrücklich nicht.
	 *
	 * @return array
	 */
	protected static function staffel_config() {
		$ctx      = CalculationContext::build();
		$settings = CalculationContext::settings();

		$articles = array();
		$costs    = array();

		foreach ( ArticleRepository::selectable( true ) as $article ) {
			$id = (int) $article['id'];

			$articles[ $id ] = array(
				'sku'          => $article['sku'],
				'price'        => (float) $article['price_gross'],
				'discountable' => (bool) $article['discountable'],
			);

			$cost           = CostCalculator::manufacturing_cost( $id, $ctx, $settings );
			$costs[ $id ]   = array(
				'hk'   => (float) $cost['herstellkosten'],
				'post' => (float) $cost['postprocessing_min'],
			);
		}

		$tiers = array();
		foreach ( CalculationContext::price_tiers() as $tier ) {
			$tiers[] = array(
				'name' => $tier['tier_name'],
				'min'  => (float) $tier['min_order_value'],
				'pct'  => (float) $tier['discount_pct'],
			);
		}

		return array(
			'mode'     => 'admin',
			'articles' => $articles,
			'costs'    => $costs,
			'tiers'    => $tiers,
			'settings' => array(
				'vat_rate'            => (float) $settings['vat_rate'],
				'scrap_surcharge_pct' => (float) $settings['scrap_surcharge_pct'],
				'payment_fee_pct'     => (float) $settings['payment_fee_pct'],
				'labor_rate_per_h'    => (float) $settings['labor_rate_per_h'],
				'min_db2'             => (float) $settings['min_db2_warning'],
			),
			'i18n'     => self::staffel_labels(),
		);
	}

	/**
	 * Beschriftungen des Live-Rechners.
	 *
	 * @return array
	 */
	public static function staffel_labels() {
		return array(
			'orderValue'      => __( 'Bestellwert zum UVP', 'lindenweg16' ),
			'tier'            => __( 'Rabattstufe', 'lindenweg16' ),
			'noTier'          => __( 'keine erreicht', 'lindenweg16' ),
			'afterDiscount'   => __( 'Bestellwert nach Rabatt', 'lindenweg16' ),
			'notDiscountable' => __( 'Davon %s nicht rabattfähig (personalisiert).', 'lindenweg16' ),
			'nextTier'        => __( 'Noch %1$s bis %2$s mit %3$s – das spart dem Händler %4$s.', 'lindenweg16' ),
			'marginWarning'   => __( 'Deckungsbeitrag unter der Schwelle:', 'lindenweg16' ),
			'lowMargin'       => __( '%1$s: DB II %2$s je Stück, Schwelle %3$s', 'lindenweg16' ),
		);
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Betriebsfelder speichern.
	 */
	public function handle_save() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$input = array_map( 'wp_unslash', array_filter( (array) $_POST, 'is_scalar' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id    = isset( $input['order_op_id'] ) ? (int) $input['order_op_id'] : 0;

		$ok = OrderOpsRepository::update_fields( $id, $input, isset( $input['reason'] ) ? $input['reason'] : '' );

		$this->redirect(
			$ok ? __( 'Bestellung gespeichert.', 'lindenweg16' ) : __( 'Ein Geschäftskunde braucht einen zugeordneten Kontakt.', 'lindenweg16' ),
			$ok ? 'success' : 'error'
		);
	}

	/**
	 * Manuelle Bestellung erfassen.
	 */
	public function handle_manual() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$contact_id = isset( $_POST['contact_id'] ) ? (int) $_POST['contact_id'] : 0;
		$date       = isset( $_POST['manual_order_date'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_order_date'] ) ) : gmdate( 'Y-m-d' );
		$channel    = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : 'Fachhandel';
		$mengen     = isset( $_POST['menge'] ) && is_array( $_POST['menge'] ) ? array_map( 'floatval', wp_unslash( $_POST['menge'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$items = array();
		$brutto_uvp = 0.0;

		// Einmal alle Artikel, nicht je Position eine Abfrage.
		$artikel = ArticleRepository::all_by_id();

		foreach ( $mengen as $article_id => $qty ) {
			if ( $qty <= 0 ) {
				continue;
			}
			$article = isset( $artikel[ (int) $article_id ] ) ? $artikel[ (int) $article_id ] : null;
			if ( ! $article ) {
				continue;
			}
			$preis        = (float) $article['price_gross'];
			$brutto_uvp  += $preis * $qty;
			$items[]      = array(
				'article_id'       => (int) $article_id,
				'qty'              => $qty,
				'unit_price_gross' => $preis,
				'discountable'     => (int) $article['discountable'],
			);
		}

		if ( ! $items ) {
			$this->redirect( __( 'Bitte mindestens eine Position mit Menge angeben.', 'lindenweg16' ), 'error' );
		}

		// Rabatt zum Bestelldatum auflösen und festschreiben.
		$discount = DiscountResolver::for_contact( $contact_id, $items, $date );

		$id = OrderOpsRepository::create_manual(
			array(
				'contact_id'           => $contact_id ? $contact_id : null,
				'manual_order_date'    => $date,
				'manual_order_value'   => $discount['order_value_net_of_discount'],
				'channel'              => $channel,
				'customer_type'        => $contact_id ? 'B2B' : 'B2C',
				'price_level'          => 'haendler',
				'applied_discount_pct' => $discount['discount_pct'],
				'applied_tier_id'      => $discount['tier_id'],
				'payment_terms_days'   => $discount['payment_terms_days'],
				'invoice_status'       => 'offen',
				'order_status'         => 'bestaetigt',
			),
			$items
		);

		$this->redirect(
			$id ? sprintf(
				/* translators: 1: Rabatt, 2: Bestellwert. */
				__( 'Bestellung erfasst: %1$s %% Rabatt, %2$s.', 'lindenweg16' ),
				number_format_i18n( $discount['discount_pct'], 1 ),
				number_format_i18n( $discount['order_value_net_of_discount'], 2 ) . ' €'
			) : __( 'Bestellung konnte nicht angelegt werden.', 'lindenweg16' ),
			$id ? 'success' : 'error'
		);
	}

	/**
	 * Entwurf bestätigen.
	 */
	public function handle_confirm() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$id = isset( $_POST['order_op_id'] ) ? (int) $_POST['order_op_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$ok = OrderOpsRepository::confirm( $id );

		$this->redirect(
			$ok ? __( 'Bestellung bestätigt, Lagerabgang und offener Posten gebucht.', 'lindenweg16' ) : __( 'Nur Entwürfe lassen sich bestätigen.', 'lindenweg16' ),
			$ok ? 'success' : 'error'
		);
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type Typ.
	 */
	protected function redirect( $msg, $type, array $extra = array() ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'     => self::SLUG,
						'lw16_msg' => rawurlencode( $msg ),
						'lw16_typ' => $type,
					),
					$extra
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Bestellungen', 'lindenweg16' ) . '</h1>';

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_msg'] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success',
				esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification

		$this->render_drafts();
		$this->render_list();
		$this->render_manual_form();

		echo '</div>';
	}

	/**
	 * Entwürfe aus dem Nachbestell-Link.
	 */
	protected function render_drafts() {
		$drafts = OrderOpsRepository::all( array( 'order_status' => 'entwurf' ) );

		if ( ! $drafts ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Anfragen zur Bestätigung', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Aus dem Nachbestell-Link. Erst mit deiner Bestätigung entstehen Lagerabgang und offener Posten.', 'lindenweg16' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Datum', 'lindenweg16' ), __( 'Händler', 'lindenweg16' ), __( 'Positionen', 'lindenweg16' ), __( 'Wert', 'lindenweg16' ), '' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $drafts as $draft ) {
			$contact = $draft['contact_id'] ? ContactRepository::find( (int) $draft['contact_id'] ) : null;
			$items   = OrderOpsRepository::items( (int) $draft['id'] );

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $draft['manual_order_date'] ) ) );
			printf( '<td>%s</td>', esc_html( $contact ? $contact['company'] : '—' ) );
			printf( '<td>%d</td>', count( $items ) );
			printf( '<td>%s €</td>', esc_html( number_format_i18n( (float) $draft['manual_order_value'], 2 ) ) );

			echo '<td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="lw16_betrieb_confirm_order" />';
			printf( '<input type="hidden" name="order_op_id" value="%d" />', (int) $draft['id'] );
			printf( '<button class="button button-primary">%s</button>', esc_html__( 'Bestätigen', 'lindenweg16' ) );
			echo '</form></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Bestellliste mit Betriebsfeldern.
	 */
	protected function render_list() {
		$orders = OrderOpsRepository::all( array( 'limit' => 100 ) );

		echo '<h2>' . esc_html__( 'Bestellungen', 'lindenweg16' ) . '</h2>';

		if ( ! $orders ) {
			echo '<p>' . esc_html__( 'Noch keine Bestellungen erfasst.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Datum', 'lindenweg16' ),
			__( 'Herkunft', 'lindenweg16' ),
			__( 'Typ', 'lindenweg16' ),
			__( 'Kanal', 'lindenweg16' ),
			__( 'Wert', 'lindenweg16' ),
			__( 'Rabatt', 'lindenweg16' ),
			__( 'Handling', 'lindenweg16' ),
			__( 'Rechnung', 'lindenweg16' ),
			__( 'Status', 'lindenweg16' ),
			'',
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $orders as $order ) {
			$overdue = OrderOpsRepository::is_overdue( $order );

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', OrderOpsRepository::order_date( $order ) ) ) );
			printf(
				'<td>%s</td>',
				esc_html( $order['wc_order_id'] ? '#' . $order['wc_order_id'] : __( 'manuell', 'lindenweg16' ) )
			);
			printf( '<td>%s</td>', esc_html( $order['customer_type'] ) );
			printf( '<td>%s</td>', esc_html( $order['channel'] ) );
			printf( '<td>%s €</td>', esc_html( number_format_i18n( OrderOpsRepository::order_value( $order ), 2 ) ) );
			printf( '<td>%s %%</td>', esc_html( number_format_i18n( (float) $order['applied_discount_pct'], 1 ) ) );
			printf(
				'<td>%s</td>',
				esc_html( null === $order['handling_minutes'] ? '—' : number_format_i18n( (float) $order['handling_minutes'], 0 ) . ' min' )
			);
			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
				esc_attr( $overdue ? 'rot' : ( 'bezahlt' === $order['invoice_status'] ? 'gruen' : 'gelb' ) ),
				esc_html( $overdue ? __( 'überfällig', 'lindenweg16' ) : $order['invoice_status'] )
			);
			printf( '<td>%s</td>', esc_html( $order['order_status'] ) );

			// Nur fuer Bestellungen, die im Betrieb entstanden sind.
			// WooCommerce-Bestellungen bekommen ihre Packliste aus dem
			// Produktionsmodul - zwei Lieferscheine fuer dieselbe Sendung
			// waeren nur verwirrend.
			echo '<td>';
			if ( ! $order['wc_order_id'] && 'entwurf' !== $order['order_status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="action" value="lw16_betrieb_delivery_note" />';
				printf( '<input type="hidden" name="order_op_id" value="%d" />', (int) $order['id'] );
				printf( '<button class="button button-small">%s</button>', esc_html__( 'Lieferschein', 'lindenweg16' ) );
				echo '</form>';
			}
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Lieferschein erzeugen und zum Herunterladen anbieten.
	 */
	public function handle_delivery_note() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$id    = isset( $_POST['order_op_id'] ) ? (int) $_POST['order_op_id'] : 0;
		$order = $id ? OrderOpsRepository::find( $id ) : null;

		if ( ! $order ) {
			$this->redirect( __( 'Bestellung nicht gefunden.', 'lindenweg16' ), 'error' );
		}

		$contact = $order['contact_id'] ? ContactRepository::find( (int) $order['contact_id'] ) : null;

		if ( ! $contact ) {
			$this->redirect( __( 'Ohne Kontakt gibt es keine Lieferadresse – bitte der Bestellung einen Kontakt zuordnen.', 'lindenweg16' ), 'error' );
		}

		// Eine unvollstaendige Anschrift ist kein Grund, den Schein nicht zu
		// erzeugen - aber einer, es zu sagen, bevor er im Karton liegt.
		if ( DeliveryNote::address_incomplete( $contact ) ) {
			$this->redirect(
				__( 'Der Kontakt hat keine vollständige Anschrift. Straße, PLZ und Ort unter Kontakte / B2B ergänzen – sonst steht auf dem Lieferschein eine halbe Adresse.', 'lindenweg16' ),
				'error'
			);
		}

		$filename = DeliveryNote::generate( $id );

		if ( ! $filename ) {
			$this->redirect( __( 'Lieferschein konnte nicht erzeugt werden.', 'lindenweg16' ), 'error' );
		}

		$path = trailingslashit( \Lindenweg16\Core\Installer::docs_dir() ) . $filename;

		if ( ! is_file( $path ) ) {
			$this->redirect( __( 'Lieferschein wurde erzeugt, ist aber nicht lesbar.', 'lindenweg16' ), 'error' );
		}

		// Direkt ausliefern statt zu verlinken: Das Dokumentverzeichnis ist
		// bewusst nicht oeffentlich erreichbar.
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Manuelle Bestellerfassung.
	 */
	protected function render_manual_form() {
		$articles = ArticleRepository::selectable( true );
		$contacts = ContactRepository::all();

		if ( ! $articles ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Bestellung manuell erfassen', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Für Fachhandel und Barverkauf. Der Staffelrabatt wird zum Bestelldatum aufgelöst und auf der Bestellung festgeschrieben.', 'lindenweg16' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_manual_order" />';

		echo '<p>';
		echo '<select name="contact_id"><option value="0">' . esc_html__( '– ohne Kontakt (Barverkauf) –', 'lindenweg16' ) . '</option>';
		foreach ( $contacts as $contact ) {
			printf(
				'<option value="%d">%s%s</option>',
				(int) $contact['id'],
				esc_html( $contact['company'] ),
				$contact['city'] ? ', ' . esc_html( $contact['city'] ) : ''
			);
		}
		echo '</select> ';
		printf( '<input type="date" name="manual_order_date" value="%s" /> ', esc_attr( gmdate( 'Y-m-d' ) ) );
		echo '<select name="channel">';
		foreach ( OrderOpsRepository::CHANNELS as $channel ) {
			printf( '<option value="%s">%s</option>', esc_attr( $channel ), esc_html( $channel ) );
		}
		echo '</select></p>';

		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
		foreach ( array( __( 'Artikel', 'lindenweg16' ), __( 'UVP', 'lindenweg16' ), __( 'rabattfähig', 'lindenweg16' ), __( 'Menge', 'lindenweg16' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $articles as $article ) {
			echo '<tr>';
			printf( '<td>%s – %s</td>', esc_html( $article['sku'] ), esc_html( $article['name'] ) );
			printf( '<td>%s €</td>', esc_html( number_format_i18n( (float) $article['price_gross'], 2 ) ) );
			printf( '<td>%s</td>', $article['discountable'] ? esc_html__( 'ja', 'lindenweg16' ) : esc_html__( 'nein', 'lindenweg16' ) );
			printf(
				'<td><input type="number" min="0" step="1" name="menge[%d]" value="0" style="width:80px" /></td>',
				(int) $article['id']
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Der Live-Rechner füllt diesen Bereich, während Mengen eingetragen
		// werden. Verbindlich rechnet der Server beim Absenden.
		echo '<div id="lw16-staffel" class="lw16-staffel"></div>';

		printf( '<p><button class="button button-primary">%s</button></p></form>', esc_html__( 'Bestellung erfassen', 'lindenweg16' ) );
	}
}
