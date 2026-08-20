<?php
/**
 * Öffentliche Nachbestell-Seite.
 *
 * Kein Checkout, keine Zahlung, keine Preisverhandlung im Frontend. Die
 * Anfrage landet als Bestellentwurf; erst mit der Bestätigung im Backend
 * entstehen Lagerabgang und offener Posten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Core\Roles;

/**
 * Route, Formular und Entwurfserzeugung.
 */
class ReorderPage {

	const NONCE = 'lw16_nachbestellen';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Route anmelden.
	 */
	public function add_rewrite() {
		add_rewrite_rule(
			'^' . ReorderToken::ROUTE . '/([a-f0-9]{64})/?$',
			'index.php?lw16_reorder=1&lw16_token=$matches[1]',
			'top'
		);
	}

	/**
	 * Abfragevariablen anmelden.
	 *
	 * @param array $vars Variablen.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'lw16_reorder';
		$vars[] = 'lw16_token';
		return $vars;
	}

	/**
	 * Seite ausliefern.
	 */
	public function maybe_render() {
		if ( ! get_query_var( 'lw16_reorder' ) ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );

		$token  = (string) get_query_var( 'lw16_token' );
		$result = ReorderToken::resolve( $token );

		if ( ! $result['valid'] ) {
			// Neutrale Seite: Sie verrät nicht, ob das Token je gültig war.
			$this->render_neutral();
			exit;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! ReorderToken::within_rate_limit( (int) $result['row']['id'], $ip ) ) {
			status_header( 429 );
			$this->render_neutral();
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_POST['lw16_reorder_submit'] ) ) {
			$this->handle_submit( $result );
			exit;
		}

		ReorderToken::touch( (int) $result['row']['id'] );
		$this->render_form( $result );
		exit;
	}

	/**
	 * Anfrage entgegennehmen.
	 *
	 * @param array $result Token-Auflösung.
	 */
	protected function handle_submit( array $result ) {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			$this->render_neutral();
			return;
		}

		$contact_id = (int) $result['row']['contact_id'];

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$mengen = isset( $_POST['menge'] ) && is_array( $_POST['menge'] ) ? array_map( 'floatval', wp_unslash( $_POST['menge'] ) ) : array();
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$items = array();

		foreach ( $mengen as $article_id => $qty ) {
			if ( $qty <= 0 ) {
				continue;
			}

			$article = ArticleRepository::find( (int) $article_id );

			// Nur verkäufliche Artikel – ein Token darf keine internen
			// Baugruppen bestellen.
			if ( ! $article || ! $article['sellable'] ) {
				continue;
			}

			$items[] = array(
				'article_id'       => (int) $article_id,
				'qty'              => $qty,
				'unit_price_gross' => (float) $article['price_gross'],
				'discountable'     => (int) $article['discountable'],
			);
		}

		if ( ! $items ) {
			$this->render_form( $result, __( 'Bitte mindestens eine Menge eintragen.', 'lindenweg16' ) );
			return;
		}

		$discount = DiscountResolver::for_contact( $contact_id, $items, gmdate( 'Y-m-d' ) );

		// Der Entwurf gehört zwingend zum Kontakt des Tokens.
		$order_id = OrderOpsRepository::create_manual(
			array(
				'contact_id'           => $contact_id,
				'manual_order_date'    => gmdate( 'Y-m-d' ),
				'manual_order_value'   => $discount['order_value_net_of_discount'],
				'channel'              => 'Fachhandel',
				'customer_type'        => 'B2B',
				'price_level'          => 'haendler',
				'applied_discount_pct' => $discount['discount_pct'],
				'applied_tier_id'      => $discount['tier_id'],
				'payment_terms_days'   => $discount['payment_terms_days'],
				'invoice_status'       => 'offen',
				'order_status'         => 'entwurf',
				'note'                 => $note,
			),
			$items
		);

		ReorderToken::touch( (int) $result['row']['id'] );
		$this->notify( $result['contact'], $order_id, $items, $discount );
		$this->render_thanks( $result['contact'], $items );
	}

	/**
	 * Benachrichtigungen versenden.
	 *
	 * @param array $contact  Kontakt.
	 * @param int   $order_id Bestellung.
	 * @param array $items    Positionen.
	 * @param array $discount Rabattauflösung.
	 */
	protected function notify( $contact, $order_id, array $items, array $discount ) {
		$zeilen = array();

		foreach ( $items as $item ) {
			$article  = ArticleRepository::find( (int) $item['article_id'] );
			$zeilen[] = sprintf( '%s × %s', number_format_i18n( $item['qty'], 0 ), $article ? $article['sku'] . ' – ' . $article['name'] : '' );
		}

		// An den Betrieb: mit Wert und Rabattstufe.
		$admin_text = sprintf(
			/* translators: 1: Händler, 2: Positionen, 3: Wert. */
			__( "Neue Anfrage über den Nachbestell-Link.\n\nHändler: %1\$s\n\n%2\$s\n\nBestellwert nach Staffel: %3\$s €\n\nDie Anfrage liegt als Entwurf unter Betrieb → Bestellungen und wartet auf deine Bestätigung.", 'lindenweg16' ),
			$contact['company'],
			implode( "\n", $zeilen ),
			number_format_i18n( $discount['order_value_net_of_discount'], 2 )
		);

		foreach ( Roles::get_manager_emails() as $email ) {
			wp_mail(
				$email,
				sprintf(
					/* translators: %s: Händler. */
					__( 'Nachbestellung angefragt: %s', 'lindenweg16' ),
					$contact['company']
				),
				$admin_text
			);
		}

		// An den Händler: Eingangsbestätigung ohne Zusage von Preis, Menge
		// oder Liefertermin.
		if ( ! empty( $contact['email'] ) && is_email( $contact['email'] ) ) {
			wp_mail(
				$contact['email'],
				__( 'Ihre Anfrage ist eingegangen', 'lindenweg16' ),
				sprintf(
					/* translators: %s: Positionsliste. */
					__( "Vielen Dank, Ihre Anfrage ist bei uns eingegangen:\n\n%s\n\nWir prüfen Verfügbarkeit und Konditionen und melden uns mit einer Bestätigung. Diese Nachricht ist noch keine Auftragsbestätigung – Preise, Mengen und Liefertermin stehen erst mit unserer Rückmeldung fest.", 'lindenweg16' ),
					implode( "\n", $zeilen )
				)
			);
		}
	}

	/**
	 * Neutrale Seite bei ungültigem Token.
	 */
	protected function render_neutral() {
		$this->layout(
			__( 'Nachbestellung', 'lindenweg16' ),
			'<p>' . esc_html__( 'Dieser Link ist nicht mehr gültig. Bitte melden Sie sich bei uns, wir schicken Ihnen einen neuen.', 'lindenweg16' ) . '</p>' .
			'<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Zur Startseite', 'lindenweg16' ) . '</a></p>'
		);
	}

	/**
	 * Bestellformular.
	 *
	 * @param array  $result Token-Auflösung.
	 * @param string $error  Fehlermeldung.
	 */
	protected function render_form( array $result, $error = '' ) {
		$contact  = $result['contact'];
		$profile  = ContactRepository::profile( (int) $contact['id'] );
		$last     = $this->last_order_items( (int) $contact['id'] );
		$articles = ArticleRepository::all( true );

		// Vorbelegung: bevorzugte Artikel aus dem Profil, sonst die letzte
		// Bestellung.
		$preset = $last;
		if ( $profile && $profile['preferred_articles'] ) {
			$preferred = json_decode( (string) $profile['preferred_articles'], true );
			if ( is_array( $preferred ) ) {
				foreach ( $preferred as $article_id => $qty ) {
					$preset[ (int) $article_id ] = (float) $qty;
				}
			}
		}

		ob_start();

		printf( '<p class="lw16-reorder__greeting">%s</p>', esc_html( sprintf( /* translators: %s: Firma. */ __( 'Guten Tag, %s', 'lindenweg16' ), $contact['company'] ) ) );

		if ( $error ) {
			printf( '<p class="lw16-reorder__error">%s</p>', esc_html( $error ) );
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="lw16_reorder_submit" value="1" />';

		echo '<table class="lw16-reorder__table"><tbody>';

		foreach ( $articles as $article ) {
			$id = (int) $article['id'];

			echo '<tr>';
			echo '<td class="lw16-reorder__thumb">';
			if ( $article['wc_product_id'] && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( (int) $article['wc_product_id'] );
				if ( $product ) {
					echo wp_kses_post( $product->get_image( 'thumbnail' ) );
				}
			}
			echo '</td>';

			printf(
				'<td><strong>%s</strong><br /><small>%s</small></td>',
				esc_html( $article['name'] ),
				esc_html( $article['sku'] )
			);

			printf(
				'<td class="lw16-reorder__qty"><input type="number" inputmode="numeric" min="0" step="1" name="menge[%d]" value="%s" /></td>',
				$id,
				esc_attr( isset( $preset[ $id ] ) ? (string) (int) $preset[ $id ] : '0' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Live-Anzeige der eigenen Konditionen: Bestellwert, erreichte Stufe
		// und was bis zur nächsten fehlt.
		echo '<div id="lw16-staffel" class="lw16-staffel"></div>';

		printf(
			'<p><label>%s<br /><textarea name="note" rows="3"></textarea></label></p>',
			esc_html__( 'Anmerkung', 'lindenweg16' )
		);

		printf( '<button type="submit" class="lw16-reorder__submit">%s</button>', esc_html__( 'Bestellung anfragen', 'lindenweg16' ) );
		echo '</form>';

		printf(
			'<p class="lw16-reorder__hint">%s</p>',
			esc_html__( 'Die Anfrage ist unverbindlich. Wir prüfen Verfügbarkeit und Konditionen und bestätigen Ihnen den Auftrag.', 'lindenweg16' )
		);

		$this->layout(
			__( 'Nachbestellung', 'lindenweg16' ),
			ob_get_clean(),
			$this->staffel_config( $contact, $articles )
		);
	}

	/**
	 * Daten für die Live-Anzeige der Konditionen.
	 *
	 * Bewusst nur Preise und die für **diesen** Händler gültigen Stufen.
	 * Keine Herstellkosten, keine Deckungsbeiträge, keine Bestände und keine
	 * Konditionen anderer Händler – die Seite steht ohne Login im Netz.
	 *
	 * @param array $contact  Kontakt.
	 * @param array $articles Verkäufliche Artikel.
	 * @return array
	 */
	protected function staffel_config( array $contact, array $articles ) {
		$list = array();

		foreach ( $articles as $article ) {
			$list[ (int) $article['id'] ] = array(
				'sku'          => $article['sku'],
				'price'        => (float) $article['price_gross'],
				'discountable' => (bool) $article['discountable'],
			);
		}

		$resolved = DiscountResolver::tiers_for_contact( (int) $contact['id'], gmdate( 'Y-m-d' ) );
		$tiers    = array();

		foreach ( $resolved['tiers'] as $tier ) {
			$tiers[] = array(
				'name' => isset( $tier['tier_name'] ) ? $tier['tier_name'] : __( 'Ihre Kondition', 'lindenweg16' ),
				'min'  => (float) $tier['min_order_value'],
				'pct'  => (float) $tier['discount_pct'],
			);
		}

		return array(
			'mode'     => 'public',
			'articles' => $list,
			'tiers'    => $tiers,
			'i18n'     => array(
				'orderValue'      => __( 'Bestellwert', 'lindenweg16' ),
				'tier'            => __( 'Ihre Rabattstufe', 'lindenweg16' ),
				'noTier'          => __( 'noch keine erreicht', 'lindenweg16' ),
				'afterDiscount'   => __( 'Nach Abzug', 'lindenweg16' ),
				'notDiscountable' => __( 'Davon %s nicht rabattfähig.', 'lindenweg16' ),
				'nextTier'        => __( 'Noch %1$s bis %2$s mit %3$s – Sie sparen dann %4$s.', 'lindenweg16' ),
			),
		);
	}

	/**
	 * Bestätigungsseite.
	 *
	 * @param array $contact Kontakt.
	 * @param array $items   Positionen.
	 */
	protected function render_thanks( $contact, array $items ) {
		ob_start();

		printf( '<p>%s</p>', esc_html__( 'Vielen Dank, Ihre Anfrage ist eingegangen.', 'lindenweg16' ) );
		echo '<ul>';

		foreach ( $items as $item ) {
			$article = ArticleRepository::find( (int) $item['article_id'] );
			printf(
				'<li>%s × %s</li>',
				esc_html( number_format_i18n( $item['qty'], 0 ) ),
				esc_html( $article ? $article['name'] : '' )
			);
		}

		echo '</ul>';
		printf(
			'<p class="lw16-reorder__hint">%s</p>',
			esc_html__( 'Wir prüfen Verfügbarkeit und Konditionen und melden uns mit einer Bestätigung. Preise, Mengen und Liefertermin stehen erst damit fest.', 'lindenweg16' )
		);

		$this->layout( __( 'Anfrage eingegangen', 'lindenweg16' ), ob_get_clean() );
	}

	/**
	 * Positionen der letzten bestätigten Bestellung eines Kontakts.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array article_id => Menge.
	 */
	protected function last_order_items( $contact_id ) {
		$orders = OrderOpsRepository::all( array( 'contact_id' => $contact_id, 'limit' => 20 ) );
		$preset = array();

		foreach ( $orders as $order ) {
			if ( 'bestaetigt' !== $order['order_status'] ) {
				continue;
			}

			foreach ( OrderOpsRepository::items( (int) $order['id'] ) as $item ) {
				$preset[ (int) $item['article_id'] ] = (float) $item['qty'];
			}
			break;
		}

		return $preset;
	}

	/**
	 * Seitengerüst.
	 *
	 * @param string $title   Titel.
	 * @param string $body    Inhalt (bereits escapt).
	 * @param array  $staffel Konfiguration der Live-Anzeige, leer = keine.
	 */
	protected function layout( $title, $body, array $staffel = array() ) {
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow, noarchive" />
	<title><?php echo esc_html( $title ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( LW16_PLUGIN_URL . 'assets/css/betrieb-mobile.css?v=' . LW16_VERSION ); ?>" />
</head>
<body class="lw16-mobile lw16-reorder">
	<header class="lw16-mobile__head"><h1><?php echo esc_html( $title ); ?></h1></header>
	<main class="lw16-mobile__form">
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- vom Aufrufer escapt. ?>
	</main>
	<?php if ( $staffel ) : ?>
	<script>window.LW16Staffel = <?php echo wp_json_encode( $staffel ); ?>;</script>
	<script src="<?php echo esc_url( LW16_PLUGIN_URL . 'assets/js/betrieb-staffel.js?v=' . LW16_VERSION ); ?>"></script>
	<?php endif; ?>
</body>
</html>
		<?php
	}
}
