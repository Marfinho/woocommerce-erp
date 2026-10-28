<?php
/**
 * Mein Konto – Übersicht: Punkte, Bestellungen, Adressen auf einen Blick.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Retention;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Calc;
use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Loyalty\LoyaltyCampaigns;
use Lindenweg16\Modules\Loyalty\LoyaltyModule;
use Lindenweg16\Modules\Loyalty\LoyaltyRepository;
use Lindenweg16\Modules\Loyalty\LoyaltyTiers;
use Lindenweg16\Modules\Loyalty\LoyaltyTriggers;

/**
 * Ersetzt WooCommerces Text-Dashboard („Von deinem Konto aus kannst du …“)
 * durch eine Übersicht. Die Prämienpunkte stehen oben: Sie sind der Grund,
 * wiederzukommen. Das Aussehen liefert das Theme (Abschnitt „Mein Konto –
 * Übersicht“ in theme.css).
 */
class AccountOverview {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'wc_get_template', array( $this, 'template' ), 10, 2 );
	}

	/**
	 * Das Dashboard-Template auf die eigene Übersicht umbiegen.
	 *
	 * @param string $template      Gefundener Pfad.
	 * @param string $template_name Template-Name.
	 * @return string
	 */
	public function template( $template, $template_name ) {
		if ( 'myaccount/dashboard.php' === $template_name ) {
			return __DIR__ . '/views/account-dashboard.php';
		}
		return $template;
	}

	/**
	 * Übersicht ausgeben.
	 */
	public static function render() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$customer = new \WC_Customer( $user_id );
		$first    = $customer->get_first_name() ? $customer->get_first_name() : $customer->get_display_name();
		$since    = $customer->get_date_created();

		echo '<div class="lw16-konto">';

		echo '<header class="lw16-konto__head">';
		/* translators: %s: Vorname */
		echo '<h2>' . esc_html( sprintf( __( 'Moin, %s.', 'lindenweg16' ), $first ) ) . '</h2>';
		if ( $since ) {
			/* translators: %s: Monat und Jahr */
			echo '<p>' . esc_html( sprintf( __( 'Dabei seit %s', 'lindenweg16' ), date_i18n( 'F Y', $since->getTimestamp() ) ) ) . '</p>';
		}
		echo '</header>';

		self::render_points( $user_id );
		self::render_stats( $user_id );
		self::render_last_order( $user_id );
		self::render_addresses( $customer );

		echo '</div>';
	}

	/**
	 * Prämienkarte: Punktestand, Gegenwert, Fortschritt, wie man sammelt.
	 *
	 * @param int $user_id Kunde.
	 */
	protected static function render_points( $user_id ) {
		$points  = LoyaltyRepository::get_points( $user_id );
		$pending = LoyaltyRepository::pending_points( $user_id );
		$step    = max( 1, (int) Settings::get( 'punkte_einloesewert', 100 ) );
		$euro    = Calc::points_to_euro( $points, $step );
		$rest    = $points % $step;
		$percent = (int) round( $rest / $step * 100 );

		echo '<section class="lw16-konto__punkte" aria-labelledby="lw16-konto-punkte">';
		echo '<div class="lw16-konto__punkte-stand">';
		echo '<p class="lw16-konto__eyebrow" id="lw16-konto-punkte">' . esc_html__( 'Deine Prämienpunkte', 'lindenweg16' ) . '</p>';
		echo '<p class="lw16-konto__zahl">' . esc_html( number_format_i18n( $points ) ) . '</p>';
		if ( $euro > 0 ) {
			/* translators: %s: Betrag */
			echo '<p class="lw16-konto__wert">' . esc_html( sprintf( __( 'Das sind %s Rabatt – einlösbar an der Kasse.', 'lindenweg16' ), self::money( $euro ) ) ) . '</p>';
		} else {
			echo '<p class="lw16-konto__wert">' . esc_html__( 'Mit jeder Bestellung sammelst du Punkte und sparst beim nächsten Einkauf.', 'lindenweg16' ) . '</p>';
		}

		// Die Leiste zeigt den Weg zur nächsten Stufe; ohne Stufen die Punkte bis zum nächsten Euro Rabatt.
		$tier = LoyaltyTiers::for_user( $user_id );
		if ( $tier && $tier['next'] ) {
			$from    = (float) $tier['tier']['ab'];
			$to      = (float) $tier['next']['ab'];
			$percent = $to > $from ? (int) max( 0, min( 100, round( ( (float) $tier['revenue'] - $from ) / ( $to - $from ) * 100 ) ) ) : 0;
			echo '<div class="lw16-konto__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $percent . '" aria-label="' . esc_attr__( 'Umsatz bis zur nächsten Stufe', 'lindenweg16' ) . '"><i style="width:' . (int) $percent . '%"></i></div>';
			/* translators: 1: Betrag, 2: Stufe */
			echo '<p class="lw16-konto__klein">' . esc_html( sprintf( __( 'Noch %1$s Umsatz bis zur Stufe %2$s', 'lindenweg16' ), self::money( $tier['missing'] ), $tier['next']['name'] ) ) . '</p>';
		} else {
			echo '<div class="lw16-konto__bar" role="progressbar" aria-valuemin="0" aria-valuemax="' . (int) $step . '" aria-valuenow="' . (int) $rest . '" aria-label="' . esc_attr__( 'Punkte bis zum nächsten Euro Rabatt', 'lindenweg16' ) . '"><i style="width:' . (int) $percent . '%"></i></div>';
			/* translators: %s: Punkte */
			echo '<p class="lw16-konto__klein">' . esc_html( sprintf( __( 'Noch %s Punkte bis zum nächsten vollen Euro Rabatt', 'lindenweg16' ), number_format_i18n( $step - $rest ) ) ) . '</p>';
		}

		if ( $pending['points'] > 0 ) {
			$when = $pending['next_at'] ? date_i18n( 'j. F', strtotime( get_date_from_gmt( $pending['next_at'] ) ) ) : '';
			echo '<p class="lw16-konto__unterwegs">';
			echo $when
				/* translators: 1: Punkte, 2: Datum */
				? esc_html( sprintf( __( '+%1$s Punkte sind unterwegs – die ersten werden am %2$s frei.', 'lindenweg16' ), number_format_i18n( $pending['points'] ), $when ) )
				/* translators: %s: Punkte */
				: esc_html( sprintf( __( '+%s Punkte sind unterwegs.', 'lindenweg16' ), number_format_i18n( $pending['points'] ) ) );
			echo '</p>';
		}

		if ( $points > 0 ) {
			$expires = LoyaltyTriggers::expires_at( $user_id );
			if ( $expires ) {
				/* translators: %s: Datum */
				echo '<p class="lw16-konto__klein">' . esc_html( sprintf( __( 'Gültig bis %s – jede Bestellung verlängert.', 'lindenweg16' ), date_i18n( 'j. F Y', $expires ) ) ) . '</p>';
			}
		}

		self::render_tier( $user_id );
		echo '</div>';

		echo '<div class="lw16-konto__sammeln">';
		self::render_campaigns();
		echo '<p class="lw16-konto__eyebrow">' . esc_html__( 'So sammelst du', 'lindenweg16' ) . '</p>';
		echo '<ul>';
		foreach ( self::ways_to_earn( $user_id ) as $way ) {
			echo '<li' . ( $way[2] ? ' class="is-offen"' : '' ) . '><b>' . esc_html( $way[0] ) . '</b> ';
			echo $way[3] ? '<a href="' . esc_url( $way[3] ) . '">' . esc_html( $way[1] ) . '</a>' : esc_html( $way[1] );
			echo '</li>';
		}
		echo '</ul>';

		$link = home_url( '/?' . LoyaltyModule::COOKIE . '=' . rawurlencode( LoyaltyRepository::get_or_create_code( $user_id ) ) );
		echo '<div class="lw16-copy-row">';
		echo '<label class="screen-reader-text" for="lw16-ref-link">' . esc_html__( 'Dein Empfehlungslink', 'lindenweg16' ) . '</label>';
		echo '<input type="text" readonly value="' . esc_attr( $link ) . '" id="lw16-ref-link" class="lw16-ref-input">';
		echo '<button type="button" class="button lw-soft" id="lw16-copy-btn" data-target="lw16-ref-link">' . esc_html__( 'Link kopieren', 'lindenweg16' ) . '</button>';
		echo '</div>';
		echo '<a class="lw16-konto__mehr" href="' . esc_url( wc_get_account_endpoint_url( 'praemien' ) ) . '">' . esc_html__( 'Punkteverlauf und Empfehlungen', 'lindenweg16' ) . '</a>';
		echo '</div>';

		echo '</section>';
	}

	/**
	 * Stufe des Kunden mit Weg zur nächsten.
	 *
	 * @param int $user_id Kunde.
	 */
	protected static function render_tier( $user_id ) {
		$info = LoyaltyTiers::for_user( $user_id );
		if ( ! $info ) {
			return;
		}
		$tiers = LoyaltyTiers::all();
		echo '<div class="lw16-konto__stufe">';
		echo '<ol class="lw16-konto__stufen" aria-label="' . esc_attr__( 'Stufen', 'lindenweg16' ) . '">';
		foreach ( $tiers as $i => $t ) {
			$cls = $i < $info['index'] ? 'is-erreicht' : ( $i === $info['index'] ? 'is-aktuell' : '' );
			echo '<li class="' . esc_attr( $cls ) . '"' . ( $i === $info['index'] ? ' aria-current="step"' : '' ) . '>' . esc_html( $t['name'] ) . '</li>';
		}
		echo '</ol>';
		$factor = (float) $info['tier']['faktor'];
		$line   = $factor > 1
			/* translators: 1: Stufe, 2: Faktor */
			? sprintf( __( 'Deine Stufe: %1$s – du sammelst %2$s-fach.', 'lindenweg16' ), $info['tier']['name'], self::factor_txt( $factor ) )
			/* translators: %s: Stufe */
			: sprintf( __( 'Deine Stufe: %s', 'lindenweg16' ), $info['tier']['name'] );
		echo '<p class="lw16-konto__klein">' . esc_html( $line ) . '</p>';
		echo '</div>';
	}

	/**
	 * Laufende Aktionen als Hinweis über der Sammel-Liste.
	 */
	protected static function render_campaigns() {
		foreach ( LoyaltyCampaigns::active() as $c ) {
			$cats = array();
			foreach ( (array) $c['kategorien'] as $id ) {
				$term = get_term( (int) $id, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$cats[] = $term->name;
				}
			}
			$factor = (float) $c['faktor'];
			$what   = 2.0 === $factor ? __( 'Doppelte Punkte', 'lindenweg16' ) : ( 3.0 === $factor
				? __( 'Dreifache Punkte', 'lindenweg16' )
				/* translators: %s: Faktor */
				: sprintf( __( '%s-fache Punkte', 'lindenweg16' ), number_format_i18n( $factor, floor( $factor ) === $factor ? 0 : 1 ) ) );
			if ( $cats ) {
				/* translators: 1: Punkte-Art, 2: Kategorien */
				$what = sprintf( __( '%1$s auf %2$s', 'lindenweg16' ), $what, implode( ', ', $cats ) );
			}
			echo '<p class="lw16-konto__aktion"><b>' . esc_html( $c['name'] ) . '</b> ';
			/* translators: 1: Beschreibung, 2: Datum */
			echo esc_html( sprintf( __( '%1$s – noch bis %2$s', 'lindenweg16' ), $what, date_i18n( 'j. F', strtotime( $c['bis'] ) ) ) ) . '</p>';
		}
	}

	/**
	 * Wege zu Punkten, wie sie gerade eingestellt sind. Schon Erledigtes
	 * (Newsletter, erste Bestellung) fällt weg; Offenes bekommt einen Link.
	 *
	 * @param int $user_id Kunde.
	 * @return array Liste aus [Punkte, Text, offen?, Link].
	 */
	protected static function ways_to_earn( $user_id ) {
		$ways     = array();
		$per_euro = (float) Settings::get( 'punkte_pro_euro', 1 );
		$pts      = function ( $n ) {
			/* translators: %s: Punkte */
			return sprintf( __( '+%s Punkte', 'lindenweg16' ), number_format_i18n( $n ) );
		};

		if ( $per_euro > 0 ) {
			/* translators: %s: Punkte je Euro */
			$ways[] = array( sprintf( _n( '%s Punkt', '%s Punkte', (int) ceil( $per_euro ), 'lindenweg16' ), number_format_i18n( $per_euro, floor( $per_euro ) === $per_euro ? 0 : 1 ) ), __( 'für jeden Euro Einkauf', 'lindenweg16' ), false, '' );
		}
		$first = (int) Settings::get( 'punkte_erste_bestellung', 0 );
		if ( $first > 0 && ! get_user_meta( $user_id, 'lw16_bonus_erste_bestellung', true ) ) {
			$ways[] = array( $pts( $first ), __( 'extra für deine erste Bestellung', 'lindenweg16' ), true, '' );
		}
		$review = (int) Settings::get( 'review_bonus_punkte', 20 );
		if ( $review > 0 ) {
			$photo  = (int) Settings::get( 'punkte_bewertung_foto', 0 );
			$text   = $photo > 0 && (int) Settings::get( 'review_foto_max', 3 ) > 0
				/* translators: %s: Punkte */
				? sprintf( __( 'für jede Produktbewertung, %s mehr mit Foto', 'lindenweg16' ), number_format_i18n( $photo ) )
				: __( 'für jede Produktbewertung', 'lindenweg16' );
			$ways[] = array( $pts( $review ), $text, false, '' );
		}
		$news = (int) Settings::get( 'punkte_newsletter', 0 );
		if ( $news > 0 && ! get_user_meta( $user_id, 'lw16_bonus_newsletter', true ) ) {
			$ways[] = array( $pts( $news ), __( 'für die Newsletter-Anmeldung', 'lindenweg16' ), true, home_url( '/#newsletter' ) );
		}
		$profile = (int) Settings::get( 'punkte_profil', 0 );
		if ( $profile > 0 && ! get_user_meta( $user_id, 'lw16_bonus_profil', true ) ) {
			$missing = LoyaltyTriggers::profile_missing( $user_id );
			if ( $missing ) {
				/* translators: %s: fehlende Angaben */
				$ways[] = array( $pts( $profile ), sprintf( __( 'fürs vollständige Profil – fehlt: %s', 'lindenweg16' ), implode( ', ', $missing ) ), true, wc_get_endpoint_url( 'edit-address', 'billing', wc_get_page_permalink( 'myaccount' ) ) );
			}
		}
		$anniversary = (int) Settings::get( 'punkte_jahrestag', 0 );
		if ( $anniversary > 0 ) {
			$ways[] = array( $pts( $anniversary ), __( 'jedes Jahr am Tag deiner ersten Bestellung', 'lindenweg16' ), false, '' );
		}
		$birthday = (int) Settings::get( 'punkte_geburtstag', 0 );
		if ( $birthday > 0 ) {
			$has    = (bool) get_user_meta( $user_id, 'lw16_geburtstag', true );
			$ways[] = array( $pts( $birthday ), $has ? __( 'zu deinem Geburtstag', 'lindenweg16' ) : __( 'zum Geburtstag – trag ihn in den Kontodetails ein', 'lindenweg16' ), ! $has, $has ? '' : wc_get_account_endpoint_url( 'edit-account' ) );
		}
		$referrer = (int) Settings::get( 'punkte_empfehlung_werber', 0 );
		$ways[]   = array(
			$referrer > 0 ? $pts( $referrer ) : __( 'Guthaben', 'lindenweg16' ),
			$referrer > 0 ? __( 'und Guthaben, wenn Freunde über deinen Link bestellen', 'lindenweg16' ) : __( 'wenn Freunde über deinen Link bestellen', 'lindenweg16' ),
			false,
			'',
		);
		return $ways;
	}

	/**
	 * Kennzahlen: Bestellungen, offene Bestellungen, Guthaben, Wunschliste.
	 *
	 * @param int $user_id Kunde.
	 */
	protected static function render_stats( $user_id ) {
		$open = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-pending', 'wc-on-hold', 'wc-processing' ),
				'limit'       => -1,
				'return'      => 'ids',
			)
		);

		$tiles = array(
			array( __( 'Bestellungen', 'lindenweg16' ), number_format_i18n( wc_get_customer_order_count( $user_id ) ), wc_get_account_endpoint_url( 'orders' ) ),
			array( __( 'In Arbeit', 'lindenweg16' ), number_format_i18n( count( $open ) ), wc_get_account_endpoint_url( 'orders' ) ),
			array( __( 'Guthaben', 'lindenweg16' ), self::money( LoyaltyRepository::get_credit( $user_id ) ), wc_get_account_endpoint_url( 'praemien' ) ),
			array( __( 'Wunschliste', 'lindenweg16' ), number_format_i18n( Wishlist::count_for( $user_id ) ), wc_get_account_endpoint_url( Wishlist::ENDPOINT ) ),
		);

		echo '<div class="lw16-konto__stats">';
		foreach ( $tiles as $t ) {
			echo '<a class="lw16-konto__stat" href="' . esc_url( $t[2] ) . '"><span>' . esc_html( $t[0] ) . '</span><b>' . esc_html( $t[1] ) . '</b></a>';
		}
		echo '</div>';
	}

	/**
	 * Letzte Bestellung mit Status, oder der Weg in den Shop.
	 *
	 * @param int $user_id Kunde.
	 */
	protected static function render_last_order( $user_id ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$order = $orders ? $orders[0] : null;

		echo '<section class="lw16-konto__karte">';
		echo '<div class="lw16-konto__karte-kopf"><h3>' . esc_html__( 'Letzte Bestellung', 'lindenweg16' ) . '</h3>';
		if ( $order ) {
			echo '<a href="' . esc_url( wc_get_account_endpoint_url( 'orders' ) ) . '">' . esc_html__( 'Alle Bestellungen', 'lindenweg16' ) . '</a>';
		}
		echo '</div>';

		if ( ! $order ) {
			echo '<div class="lw16-konto__leer"><p>' . esc_html__( 'Noch keine Bestellung – deine erste bringt schon Punkte.', 'lindenweg16' ) . '</p>';
			echo '<a class="button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Zum Shop', 'lindenweg16' ) . '</a></div>';
			echo '</section>';
			return;
		}

		$items = array_values( $order->get_items() );
		$line  = $items ? $items[0]->get_name() : '';
		if ( count( $items ) > 1 ) {
			/* translators: %d: weitere Positionen */
			$line .= ' ' . sprintf( _n( '+ %d weiterer Artikel', '+ %d weitere Artikel', count( $items ) - 1, 'lindenweg16' ), count( $items ) - 1 );
		}
		$date = $order->get_date_created();

		echo '<a class="lw16-konto__order" href="' . esc_url( $order->get_view_order_url() ) . '">';
		/* translators: %s: Bestellnummer */
		echo '<span class="lw16-konto__order-main"><b>' . esc_html( sprintf( __( 'Bestellung #%s', 'lindenweg16' ), $order->get_order_number() ) ) . '</b>';
		echo '<small>' . esc_html( trim( ( $date ? date_i18n( 'j. F Y', $date->getTimestamp() ) . ' · ' : '' ) . $line ) ) . '</small></span>';
		echo '<span class="lw16-konto__status is-' . esc_attr( $order->get_status() ) . '">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span>';
		echo '<span class="lw16-konto__betrag">' . wp_kses_post( $order->get_formatted_order_total() ) . '</span>';
		echo '</a>';
		echo '</section>';
	}

	/**
	 * Rechnungs- und Lieferadresse.
	 *
	 * @param \WC_Customer $customer Kunde.
	 */
	protected static function render_addresses( $customer ) {
		$blocks = array(
			'billing'  => array( __( 'Rechnungsadresse', 'lindenweg16' ), $customer->get_billing() ),
			'shipping' => array( __( 'Lieferadresse', 'lindenweg16' ), $customer->get_shipping() ),
		);
		if ( wc_ship_to_billing_address_only() || ! wc_shipping_enabled() ) {
			unset( $blocks['shipping'] );
		}

		echo '<div class="lw16-konto__adressen">';
		foreach ( $blocks as $type => $b ) {
			$formatted = WC()->countries->get_formatted_address( $b[1] );
			echo '<section class="lw16-konto__karte">';
			echo '<div class="lw16-konto__karte-kopf"><h3>' . esc_html( $b[0] ) . '</h3>';
			echo '<a class="lw16-konto__pill" href="' . esc_url( wc_get_endpoint_url( 'edit-address', $type ) ) . '">' . esc_html( $formatted ? __( 'Bearbeiten', 'lindenweg16' ) : __( 'Hinzufügen', 'lindenweg16' ) ) . '</a></div>';
			echo $formatted
				? '<address>' . wp_kses_post( $formatted ) . '</address>'
				: '<p class="lw16-konto__klein">' . esc_html__( 'Noch nicht hinterlegt.', 'lindenweg16' ) . '</p>';
			echo '</section>';
		}
		echo '</div>';
	}

	/**
	 * Faktor ohne überflüssige Nullen („1,1“, „1,25“, „2“).
	 *
	 * @param float $factor Faktor.
	 * @return string
	 */
	protected static function factor_txt( $factor ) {
		$txt = number_format_i18n( (float) $factor, 2 );
		return false === strpbrk( $txt, ',.' ) ? $txt : rtrim( rtrim( $txt, '0' ), ',.' );
	}

	/**
	 * Betrag ohne HTML (für Fließtext und Kacheln).
	 *
	 * @param float $amount Betrag.
	 * @return string
	 */
	protected static function money( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
}
