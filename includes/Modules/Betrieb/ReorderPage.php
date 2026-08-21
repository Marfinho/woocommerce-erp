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

use Lindenweg16\Core\Assets;

use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Core\Roles;
use Lindenweg16\Core\Settings;

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
		$farben = isset( $_POST['farbe'] ) && is_array( $_POST['farbe'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['farbe'] ) ) : array();
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

			// Farbe nur aus der bekannten Liste übernehmen. Ein Wert aus dem
			// Formular darf nie ungeprüft in eine Lagerbewegung wandern.
			$farbe = self::pick_color(
				isset( $farben[ $article_id ] ) ? $farben[ $article_id ] : '',
				ColorRegistry::all(),
				ColorRegistry::default_color()
			);

			$items[] = array(
				'article_id'       => (int) $article_id,
				'qty'              => $qty,
				'color'            => $farbe,
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
			$zeilen[] = sprintf(
				'%s × %s%s',
				number_format_i18n( $item['qty'], 0 ),
				$article ? $article['sku'] . ' – ' . $article['name'] : '',
				$this->color_suffix( $item )
			);
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
		$articles = self::catalog();

		// Vorbelegung: bevorzugte Artikel aus dem Profil, sonst die letzte
		// Bestellung.
		$farben = ColorRegistry::all();
		$mehrfarbig = count( $farben ) > 1;

		$preset = $last['mengen'];
		$preset_farben = $last['farben'];

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
			$id  = (int) $article['id'];
			$qty = isset( $preset[ $id ] ) ? (int) $preset[ $id ] : 0;

			printf( '<tr class="lw16-reorder__row%s">', $qty > 0 ? ' is-preset' : '' );

			echo '<td class="lw16-reorder__thumb">';
			echo wp_kses_post( $article['image'] );
			echo '</td>';

			printf(
				'<td class="lw16-reorder__name"><strong>%s</strong><br /><small>%s%s</small></td>',
				esc_html( $article['name'] ),
				esc_html( $article['sku'] ),
				$article['price_gross'] > 0
					? ' · ' . esc_html( number_format_i18n( (float) $article['price_gross'], 2 ) ) . ' €'
					: ''
			);

			if ( $mehrfarbig ) {
				$gewaehlt = isset( $preset_farben[ $id ] ) ? $preset_farben[ $id ] : ColorRegistry::default_color();

				printf( '<td class="lw16-reorder__farbe"><label class="screen-reader-text" for="lw16-farbe-%1$d">%2$s</label><select id="lw16-farbe-%1$d" name="farbe[%1$d]">', $id, esc_html__( 'Farbe', 'lindenweg16' ) );

				foreach ( $farben as $slug => $label ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $slug ),
						selected( $gewaehlt, $slug, false ),
						esc_html( $label )
					);
				}

				echo '</select></td>';
			}

			printf(
				'<td class="lw16-reorder__qty"><label class="screen-reader-text" for="lw16-menge-%1$d">%4$s</label>'
				. '<input type="number" inputmode="numeric" min="0" step="1" id="lw16-menge-%1$d" name="menge[%1$d]" value="%2$s"%3$s /></td>',
				$id,
				esc_attr( (string) $qty ),
				$qty > 0 ? ' data-preset="1"' : '',
				esc_attr( $article['name'] )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $preset ) {
			printf(
				'<p class="lw16-reorder__hint">%s</p>',
				esc_html__( 'Die vorausgefüllten Mengen entsprechen Ihrer letzten Bestellung. Ändern Sie sie einfach – alles auf 0 lassen, was Sie diesmal nicht brauchen.', 'lindenweg16' )
			);
		}

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
	 * Bestellbarer Katalog, in der Reihenfolge des Shops.
	 *
	 * Führend ist WooCommerce: Was dort veröffentlicht und im Katalog
	 * sichtbar ist, steht auch hier – sonst müsste jede neue Farbe oder
	 * jedes neue Modul an zwei Stellen freigeschaltet werden.
	 *
	 * Bestellbar ist ein Produkt aber nur, wenn dahinter ein verkäuflicher
	 * Betriebsartikel steht. Ohne ihn gäbe es keinen Preis, keine
	 * Rabattstufe und keinen Lagerabgang – die Anfrage wäre nicht
	 * ausführbar. Solche Produkte werden übergangen und im Backend gemeldet,
	 * damit die Lücke nicht unbemerkt bleibt.
	 *
	 * @return array Zeilen mit id, sku, name, price_gross, discountable, image.
	 */
	public static function catalog() {
		$articles = array();
		foreach ( ArticleRepository::all( true ) as $article ) {
			$articles[ (int) $article['id'] ] = $article;
		}

		// Ohne WooCommerce bleibt der Artikelstamm die einzige Quelle.
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array_values( array_map( array( __CLASS__, 'catalog_row' ), $articles ) );
		}

		$products = wc_get_products(
			array(
				'status'         => 'publish',
				'catalog_visibility' => 'visible',
				'limit'          => -1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'return'         => 'objects',
			)
		);

		$rows = array();
		$seen = array();

		foreach ( (array) $products as $product ) {
			$article_id = StockOwnership::article_for_product( (int) $product->get_id() );

			if ( ! $article_id || ! isset( $articles[ $article_id ] ) || isset( $seen[ $article_id ] ) ) {
				continue;
			}

			$seen[ $article_id ] = true;
			$rows[]              = self::catalog_row( $articles[ $article_id ], $product );
		}

		// Verkäufliche Artikel ohne Shop-Produkt kämen sonst gar nicht vor.
		foreach ( $articles as $id => $article ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$rows[] = self::catalog_row( $article );
			}
		}

		return $rows;
	}

	/**
	 * Eine Katalogzeile aufbereiten.
	 *
	 * @param array       $article Betriebsartikel.
	 * @param object|null $product WooCommerce-Produkt, falls vorhanden.
	 * @return array
	 */
	protected static function catalog_row( array $article, $product = null ) {
		if ( null === $product && ! empty( $article['wc_product_id'] ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $article['wc_product_id'] );
		}

		return array(
			'id'           => (int) $article['id'],
			'sku'          => (string) $article['sku'],
			'name'         => $product ? $product->get_name() : (string) $article['name'],
			'price_gross'  => (float) $article['price_gross'],
			'discountable' => (int) $article['discountable'],
			'image'        => $product ? $product->get_image( 'thumbnail' ) : '',
		);
	}

	/**
	 * Verkäufliche Shop-Produkte ohne Betriebsartikel.
	 *
	 * Wird im Backend gemeldet: Sie fehlen dem Händler auf der
	 * Nachbestellseite, und das soll auffallen.
	 *
	 * @return array Produkt-ID => Name.
	 */
	public static function unlinked_products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				'status'             => 'publish',
				'catalog_visibility' => 'visible',
				'limit'              => -1,
				'return'             => 'objects',
			)
		);

		$missing = array();

		foreach ( (array) $products as $product ) {
			if ( ! StockOwnership::article_for_product( (int) $product->get_id() ) ) {
				$missing[ (int) $product->get_id() ] = $product->get_name();
			}
		}

		return $missing;
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
				'<li>%s × %s%s</li>',
				esc_html( number_format_i18n( $item['qty'], 0 ) ),
				esc_html( $article ? $article['name'] : '' ),
				esc_html( $this->color_suffix( $item ) )
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
	 * Eine Farbe aus dem Formular auf einen bekannten Slug abbilden.
	 *
	 * Reine Logik, ohne WordPress-Abfragen. Unbekannte oder leere Werte
	 * fallen auf die Standardfarbe zurück – nie auf den Rohwert, denn der
	 * würde sonst ungeprüft in eine Lagerbewegung wandern.
	 *
	 * @param string $raw     Rohwert aus dem Formular.
	 * @param array  $known   Bekannte Farben als Slug => Anzeigename.
	 * @param string $default Standardfarbe.
	 * @return string
	 */
	public static function pick_color( $raw, array $known, $default ) {
		$slug = ColorRegistry::normalize( (string) $raw );

		if ( '' !== $slug && array_key_exists( $slug, $known ) ) {
			return $slug;
		}

		return (string) $default;
	}

	/**
	 * Farbzusatz für eine Positionszeile.
	 *
	 * Solange nur eine Farbe gepflegt ist, sagt die Angabe nichts – dann
	 * bleibt die Zeile kurz.
	 *
	 * @param array $item Position.
	 * @return string Leer oder ' (Farbe)'.
	 */
	protected function color_suffix( array $item ) {
		$farben = ColorRegistry::all();

		if ( count( $farben ) < 2 ) {
			return '';
		}

		$slug = isset( $item['color'] ) ? (string) $item['color'] : '';

		if ( '' === $slug || ! isset( $farben[ $slug ] ) ) {
			return '';
		}

		return ' (' . $farben[ $slug ] . ')';
	}

	/**
	 * Mengen und Farben der letzten bestätigten Bestellung eines Kontakts.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array mengen => article_id => Menge, farben => article_id => Slug.
	 */
	protected function last_order_items( $contact_id ) {
		$orders = OrderOpsRepository::all( array( 'contact_id' => $contact_id, 'limit' => 20 ) );
		$preset = array(
			'mengen' => array(),
			'farben' => array(),
		);

		foreach ( $orders as $order ) {
			if ( 'bestaetigt' !== $order['order_status'] ) {
				continue;
			}

			foreach ( OrderOpsRepository::items( (int) $order['id'] ) as $item ) {
				$id                        = (int) $item['article_id'];
				$preset['mengen'][ $id ]   = (float) $item['qty'];
				$preset['farben'][ $id ]   = (string) $item['color'];
			}
			break;
		}

		return $preset;
	}

	/**
	 * Seitengerüst.
	 *
	 * Zwei Darstellungen: eingebettet in das Website-Design, damit der
	 * Händler die vertraute Seite mit Logo und Navigation sieht – oder
	 * schlicht und eigenständig, falls das Theme mit dem Formular nicht
	 * zurechtkommt. Umschaltbar in den Einstellungen.
	 *
	 * @param string $title   Titel.
	 * @param string $body    Inhalt (bereits escapt).
	 * @param array  $staffel Konfiguration der Live-Anzeige, leer = keine.
	 */
	protected function layout( $title, $body, array $staffel = array() ) {
		if ( 'schlicht' !== Settings::get( 'betrieb_reorder_layout' ) && ! is_admin() ) {
			$this->layout_theme( $title, $body, $staffel );
			return;
		}

		$this->layout_plain( $title, $body, $staffel );
	}

	/**
	 * Eingebettet in das Theme der Website.
	 *
	 * @param string $title   Titel.
	 * @param string $body    Inhalt.
	 * @param array  $staffel Konfiguration.
	 */
	protected function layout_theme( $title, $body, array $staffel ) {
		global $wp_query;

		// Die Route trifft keinen Beitrag, WordPress haelt sie deshalb fuer
		// einen 404. Ohne diese Korrektur liefert das Theme die
		// Fehlerseite samt Status 404 – der Haendler saehe „Seite nicht
		// gefunden" mit einem Bestellformular darunter.
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->is_404     = false;
			$wp_query->is_home    = false;
			$wp_query->is_archive = false;
		}
		status_header( 200 );

		add_filter( 'pre_get_document_title', static function () use ( $title ) {
			return $title;
		} );

		add_filter( 'body_class', static function ( $classes ) {
			$classes[] = 'lw16-reorder-body';
			return $classes;
		} );

		$this->enqueue_assets( $staffel );

		get_header();

		echo '<div class="lw16-reorder-page"><div class="lw16-reorder">';
		printf( '<h1 class="lw16-reorder__title">%s</h1>', esc_html( $title ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- vom Aufrufer escapt.
		echo '</div></div>';

		get_footer();
	}

	/**
	 * Eigenständige, schlichte Seite mit Logo.
	 *
	 * @param string $title   Titel.
	 * @param string $body    Inhalt.
	 * @param array  $staffel Konfiguration.
	 */
	protected function layout_plain( $title, $body, array $staffel ) {
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow, noarchive" />
	<title><?php echo esc_html( $title ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( Assets::url( 'assets/css/betrieb-mobile.css' ) ); ?>" />
</head>
<body class="lw16-mobile lw16-reorder">
	<header class="lw16-reorder__head">
		<?php echo wp_kses_post( self::brand() ); ?>
		<h1><?php echo esc_html( $title ); ?></h1>
	</header>
	<main class="lw16-mobile__form">
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- vom Aufrufer escapt. ?>
	</main>
	<?php if ( $staffel ) : ?>
	<script>window.LW16Staffel = <?php echo wp_json_encode( $staffel ); ?>;</script>
	<script src="<?php echo esc_url( Assets::url( 'assets/js/betrieb-staffel.js' ) ); ?>"></script>
	<?php endif; ?>
</body>
</html>
		<?php
	}

	/**
	 * Stil und Skript für die eingebettete Darstellung anmelden.
	 *
	 * @param array $staffel Konfiguration der Live-Anzeige.
	 */
	protected function enqueue_assets( array $staffel ) {
		wp_enqueue_style(
			'lw16-reorder',
			LW16_PLUGIN_URL . 'assets/css/betrieb-mobile.css',
			array(),
			Assets::version( 'assets/css/betrieb-mobile.css' )
		);

		if ( ! $staffel ) {
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
			'window.LW16Staffel = ' . wp_json_encode( $staffel ) . ';',
			'before'
		);
	}

	/**
	 * Logo oder Schriftzug der Firma.
	 *
	 * Genommen wird das in den Einstellungen hinterlegte CI-Logo, sonst das
	 * Website-Logo des Themes, sonst der Firmenname als Text. Eine Seite
	 * ohne jeden Absender wirkt beim Händler wie Phishing.
	 *
	 * @return string
	 */
	public static function brand() {
		$logo_id = (int) Settings::get( 'ci_logo_id' );

		if ( $logo_id ) {
			$image = wp_get_attachment_image( $logo_id, 'medium', false, array( 'class' => 'lw16-reorder__logo' ) );
			if ( $image ) {
				return $image;
			}
		}

		$custom = get_theme_mod( 'custom_logo' );
		if ( $custom ) {
			$image = wp_get_attachment_image( (int) $custom, 'medium', false, array( 'class' => 'lw16-reorder__logo' ) );
			if ( $image ) {
				return $image;
			}
		}

		$name = Settings::get( 'firma_name' );

		return '<span class="lw16-reorder__wordmark">' . esc_html( $name ? $name : get_bloginfo( 'name' ) ) . '</span>';
	}
}
