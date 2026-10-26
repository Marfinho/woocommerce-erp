<?php
/**
 * Title: Kontaktseite (Template)
 * Slug: lindenweg16/hidden-kontakt
 * Inserter: no
 * Description: Inhalt des Templates „Kontakt“: Einleitung mit Kontakt-Chips, Themen-Kacheln, Formular (aus dem Seiteninhalt) und Direktkontakt mit kurzen Antworten.
 *
 * Das Formular ist der Inhalt der Seite selbst (z. B. der WPForms-Block) –
 * so bleibt es im Seiten-Editor bearbeitbar. E-Mail, Telefon und Adresse
 * kommen aus den Firmendaten des ERP-Plugins (inc/kontakt.php).
 *
 * @package Lindenweg16
 */

$lindenweg16_contact = lindenweg16_contact_data();
$lindenweg16_account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
$lindenweg16_privacy = get_privacy_policy_url() ?: lindenweg16_page_url( 'datenschutz' );
$lindenweg16_free    = function_exists( 'lindenweg16_free_shipping_threshold' ) ? (float) lindenweg16_free_shipping_threshold() : 0.0;

$lindenweg16_mailto = 'mailto:' . $lindenweg16_contact['email'];
$lindenweg16_b2b    = $lindenweg16_mailto . '?subject=' . rawurlencode( __( 'Händleranfrage Haster Haken', 'lindenweg16' ) );

$lindenweg16_tiles = array(
	array(
		'color' => 'accent-3',
		'title' => __( 'Bestellung & Versand', 'lindenweg16' ),
		'text'  => __( 'Status und Sendungsnummer stehen in deiner Versandmail und unter „Mein Konto“.', 'lindenweg16' ),
		'url'   => $lindenweg16_account,
	),
	array(
		'color' => 'accent-4',
		'title' => __( 'Montage', 'lindenweg16' ),
		'text'  => __( 'Schritt für Schritt: Schiene an die Wand, Module drauf.', 'lindenweg16' ),
		'url'   => lindenweg16_page_url( 'anleitungen' ),
	),
	array(
		'color' => 'accent-2',
		'title' => __( 'Welches Set passt?', 'lindenweg16' ),
		'text'  => __( 'Stell dir deine Schiene im Konfigurator zusammen.', 'lindenweg16' ),
		'url'   => lindenweg16_page_url( 'konfigurator' ),
	),
	array(
		'color' => 'surface',
		'title' => __( 'Händler & Unterkünfte', 'lindenweg16' ),
		'text'  => __( 'Radladen, Bike-Hotel oder Ferienwohnung? Unsere Sets gibt es auch zu Händlerkonditionen.', 'lindenweg16' ),
		'url'   => $lindenweg16_b2b,
	),
);

$lindenweg16_shipping = __( 'Wir versenden innerhalb Deutschlands mit DHL. Die Lieferzeit steht auf jeder Produktseite direkt unter dem Preis.', 'lindenweg16' );
if ( $lindenweg16_free > 0 && function_exists( 'wc_price' ) ) {
	$lindenweg16_shipping .= ' ' . sprintf(
		/* translators: %s: Mindestbestellwert für kostenlosen Versand, z. B. „49,00 €“. */
		__( 'Ab %s ist der Versand kostenlos.', 'lindenweg16' ),
		wp_strip_all_tags( wc_price( $lindenweg16_free ) )
	);
}

$lindenweg16_faq = array(
	array( __( 'Wie schnell kommt mein Paket?', 'lindenweg16' ), $lindenweg16_shipping, '' ),
	array( __( 'Wie viel trägt der Haster Haken?', 'lindenweg16' ), __( 'Das System ist für bis zu 5 kg ausgelegt – Helm, Schuhe, Brille, Flasche. Fahrräder bitte nicht daran aufhängen.', 'lindenweg16' ), '' ),
	array( __( 'Ich möchte etwas zurückschicken.', 'lindenweg16' ), __( 'Alles zur Rücksendung steht auf der Retouren-Seite.', 'lindenweg16' ), lindenweg16_page_url( 'retouren' ) ),
	array( __( 'Ich hätte eine Idee für ein Modul …', 'lindenweg16' ), __( 'Immer her damit – einfach übers Formular. Wir lesen alles.', 'lindenweg16' ), '' ),
);
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-kontakt-intro","layout":{"type":"flex","orientation":"vertical"}} -->
<section class="wp-block-group alignwide lw16-section lw16-kontakt-intro">
	<!-- wp:paragraph {"className":"is-style-sticker"} -->
	<p class="is-style-sticker"><?php esc_html_e( 'Handgemacht in Haste', 'lindenweg16' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"level":1,"fontSize":"hero"} -->
	<h1 class="wp-block-heading has-hero-font-size"><?php
		printf(
			/* translators: %s: hervorgehobenes Wort. */
			esc_html__( 'Schreib uns – wir antworten %s.', 'lindenweg16' ),
			'<mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color">' . esc_html__( 'selbst', 'lindenweg16' ) . '</mark>'
		);
	?></h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"textColor":"text-muted","fontSize":"large","className":"lw16-kontakt-intro__lead"} -->
	<p class="lw16-kontakt-intro__lead has-text-muted-color has-text-color has-large-font-size"><?php esc_html_e( 'Fragen zum Haster Haken, zu deiner Bestellung oder zur Montage? Kein Callcenter, kein Ticketsystem – deine Nachricht landet direkt bei uns in der Werkstatt.', 'lindenweg16' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:group {"className":"lw16-kontakt-intro__chips","layout":{"type":"flex","flexWrap":"wrap"}} -->
	<div class="wp-block-group lw16-kontakt-intro__chips">
<?php if ( $lindenweg16_contact['email'] ) : ?>
		<!-- wp:paragraph {"className":"is-style-chip lw16-icon lw16-icon--mail"} -->
		<p class="is-style-chip lw16-icon lw16-icon--mail"><a href="<?php echo esc_url( $lindenweg16_mailto ); ?>"><?php echo esc_html( $lindenweg16_contact['email'] ); ?></a></p>
		<!-- /wp:paragraph -->
<?php endif; ?>
<?php if ( $lindenweg16_contact['phone'] ) : ?>
		<!-- wp:paragraph {"className":"is-style-chip lw16-icon lw16-icon--phone"} -->
		<p class="is-style-chip lw16-icon lw16-icon--phone"><a href="<?php echo esc_url( $lindenweg16_contact['phone_href'] ); ?>"><?php echo esc_html( $lindenweg16_contact['phone'] ); ?></a></p>
		<!-- /wp:paragraph -->
<?php endif; ?>
		<!-- wp:paragraph {"className":"is-style-chip lw16-icon lw16-icon--arrow-down"} -->
		<p class="is-style-chip lw16-icon lw16-icon--arrow-down"><a href="#nachricht"><?php esc_html_e( 'Zum Formular', 'lindenweg16' ); ?></a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-kontakt-topics","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section lw16-kontakt-topics">
	<!-- wp:group {"className":"lw16-section-head","layout":{"type":"flex","orientation":"vertical"}} -->
	<div class="wp-block-group lw16-section-head">
		<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
		<p class="is-style-eyebrow"><?php esc_html_e( 'Schneller zur Antwort', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:heading -->
		<h2 class="wp-block-heading"><?php esc_html_e( 'Worum geht’s?', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"lw16-bento lw16-kontakt-topics__grid","layout":{"type":"default"}} -->
	<div class="wp-block-group lw16-bento lw16-kontakt-topics__grid">
<?php foreach ( $lindenweg16_tiles as $lindenweg16_tile ) : ?>
		<!-- wp:group {"className":"lw16-bento__tile","backgroundColor":"<?php echo esc_attr( $lindenweg16_tile['color'] ); ?>","layout":{"type":"default"}} -->
		<div class="wp-block-group lw16-bento__tile has-<?php echo esc_attr( $lindenweg16_tile['color'] ); ?>-background-color has-background">
			<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group">
				<!-- wp:heading {"level":3} -->
				<h3 class="wp-block-heading"><a href="<?php echo esc_url( $lindenweg16_tile['url'] ); ?>"><?php echo esc_html( $lindenweg16_tile['title'] ); ?></a></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html( $lindenweg16_tile['text'] ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:html -->
			<span class="lw16-bento__arrow" aria-hidden="true">→</span>
			<!-- /wp:html -->
		</div>
		<!-- /wp:group -->
<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","anchor":"nachricht","align":"wide","className":"lw16-section lw16-kontakt-main","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section lw16-kontakt-main" id="nachricht">
	<!-- wp:columns {"className":"lw16-kontakt-main__columns"} -->
	<div class="wp-block-columns lw16-kontakt-main__columns">
		<!-- wp:column {"width":"60%"} -->
		<div class="wp-block-column" style="flex-basis:60%">
			<!-- wp:group {"className":"is-style-card lw16-kontakt-form","layout":{"type":"default"}} -->
			<div class="wp-block-group is-style-card lw16-kontakt-form">
				<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
				<p class="is-style-eyebrow"><?php esc_html_e( 'Nachricht', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading -->
				<h2 class="wp-block-heading"><?php esc_html_e( 'Was können wir für dich tun?', 'lindenweg16' ); ?></h2>
				<!-- /wp:heading -->
				<!-- wp:paragraph {"textColor":"text-muted"} -->
				<p class="has-text-muted-color has-text-color"><?php esc_html_e( 'Bei Fragen zu einer Bestellung gern die Bestellnummer mit angeben.', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:post-content {"className":"lw16-kontakt-form__content","layout":{"type":"default"}} /-->

				<!-- wp:paragraph {"textColor":"text-muted","fontSize":"small"} -->
				<p class="has-text-muted-color has-text-color has-small-font-size"><?php
					printf(
						/* translators: %s: Link zur Datenschutzerklärung. */
						esc_html__( 'Wir nutzen deine Angaben nur, um deine Anfrage zu beantworten. Mehr dazu in der %s.', 'lindenweg16' ),
						'<a href="' . esc_url( $lindenweg16_privacy ) . '">' . esc_html__( 'Datenschutzerklärung', 'lindenweg16' ) . '</a>'
					);
				?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"40%"} -->
		<div class="wp-block-column" style="flex-basis:40%">
			<!-- wp:group {"className":"is-style-card lw16-kontakt-direct","backgroundColor":"surface-muted","layout":{"type":"default"}} -->
			<div class="wp-block-group is-style-card lw16-kontakt-direct has-surface-muted-background-color has-background">
				<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
				<p class="is-style-eyebrow"><?php esc_html_e( 'Direktkontakt', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->
<?php if ( $lindenweg16_contact['email'] ) : ?>
				<!-- wp:paragraph {"className":"lw16-kontakt-direct__row"} -->
				<p class="lw16-kontakt-direct__row"><span class="lw16-kontakt-direct__label"><?php esc_html_e( 'E-Mail', 'lindenweg16' ); ?></span><a href="<?php echo esc_url( $lindenweg16_mailto ); ?>"><?php echo esc_html( $lindenweg16_contact['email'] ); ?></a></p>
				<!-- /wp:paragraph -->
<?php endif; ?>
<?php if ( $lindenweg16_contact['phone'] ) : ?>
				<!-- wp:paragraph {"className":"lw16-kontakt-direct__row"} -->
				<p class="lw16-kontakt-direct__row"><span class="lw16-kontakt-direct__label"><?php esc_html_e( 'Telefon', 'lindenweg16' ); ?></span><a href="<?php echo esc_url( $lindenweg16_contact['phone_href'] ); ?>"><?php echo esc_html( $lindenweg16_contact['phone'] ); ?></a></p>
				<!-- /wp:paragraph -->
<?php endif; ?>
<?php if ( $lindenweg16_contact['street'] || $lindenweg16_contact['city'] ) : ?>
				<!-- wp:paragraph {"className":"lw16-kontakt-direct__row"} -->
				<p class="lw16-kontakt-direct__row"><span class="lw16-kontakt-direct__label"><?php esc_html_e( 'Werkstatt', 'lindenweg16' ); ?></span><?php echo esc_html( $lindenweg16_contact['street'] ); ?><br><?php echo esc_html( $lindenweg16_contact['city'] ); ?></p>
				<!-- /wp:paragraph -->
<?php endif; ?>
				<!-- wp:paragraph {"textColor":"text-muted","fontSize":"small"} -->
				<p class="has-text-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'Kein Ladengeschäft – wir sind eine kleine Werkstatt. Alles läuft online und per Post.', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"lw16-faq__list lw16-kontakt-faq","layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
			<div class="wp-block-group lw16-faq__list lw16-kontakt-faq">
<?php foreach ( $lindenweg16_faq as $lindenweg16_item ) : ?>
				<!-- wp:details {"className":"lw16-faq__item"} -->
				<details class="wp-block-details lw16-faq__item"><summary><?php echo esc_html( $lindenweg16_item[0] ); ?></summary><!-- wp:paragraph -->
				<p><?php echo esc_html( $lindenweg16_item[1] ); ?><?php if ( $lindenweg16_item[2] ) : ?> <a href="<?php echo esc_url( $lindenweg16_item[2] ); ?>"><?php esc_html_e( 'Zur Seite', 'lindenweg16' ); ?></a><?php endif; ?></p>
				<!-- /wp:paragraph --></details>
				<!-- /wp:details -->
<?php endforeach; ?>
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</section>
<!-- /wp:group -->
