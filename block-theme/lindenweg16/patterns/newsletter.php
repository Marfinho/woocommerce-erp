<?php
/**
 * Title: Newsletter
 * Slug: lindenweg16/newsletter
 * Categories: lindenweg16, call-to-action
 * Keywords: newsletter, e-mail, anmeldung, formular
 * Viewport Width: 1200
 * Description: Karte mit E-Mail-Feld und Button in einer Pille und einem Hinweis auf den Datenschutz. Die Anmeldung läuft mit Double-Opt-in über das Theme, ohne Plugin.
 *
 * @package Lindenweg16
 */

$lindenweg16_privacy = get_privacy_policy_url() ? get_privacy_policy_url() : lindenweg16_page_url( 'datenschutz' );
?>
<!-- wp:group {"tagName":"section","anchor":"newsletter","align":"wide","className":"lw16-section","layout":{"type":"constrained","contentSize":"880px"}} -->
<section class="wp-block-group alignwide lw16-section" id="newsletter">
	<!-- wp:group {"className":"is-style-card lw16-newsletter","layout":{"type":"flex","orientation":"vertical","justifyContent":"center"}} -->
	<div class="wp-block-group is-style-card lw16-newsletter">
		<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
		<p class="is-style-eyebrow"><?php esc_html_e( 'Post aus der Werkstatt', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:heading {"textAlign":"center"} -->
		<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e( 'Neue Module zuerst erfahren', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"align":"center","textColor":"text-muted"} -->
		<p class="has-text-align-center has-text-muted-color has-text-color"><?php esc_html_e( 'Ein bis zwei Mails im Monat mit Neuheiten, Werkstatt-Einblicken und Tipps für dein Rad. Kein Spam, versprochen.', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:html -->
		<form class="lw16-newsletter__form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="lindenweg16_newsletter">
			<label class="screen-reader-text" for="lw16-newsletter-email"><?php esc_html_e( 'Deine E-Mail-Adresse', 'lindenweg16' ); ?></label>
			<input id="lw16-newsletter-email" type="email" name="email" required autocomplete="email" placeholder="<?php esc_attr_e( 'deine@mail.de', 'lindenweg16' ); ?>">
			<span class="lw16-newsletter__hp" aria-hidden="true"><input type="text" name="lw16_website" tabindex="-1" autocomplete="off"></span>
			<button type="submit"><?php esc_html_e( 'Anmelden', 'lindenweg16' ); ?></button>
		</form>
		<!-- /wp:html -->

		<!-- wp:paragraph {"align":"center","className":"lw16-newsletter__hint","fontSize":"small"} -->
		<p class="has-text-align-center lw16-newsletter__hint has-small-font-size"><?php
			printf(
				/* translators: %s: Link „Datenschutzerklärung“. */
				esc_html__( 'Du bekommst erst eine Mail zum Bestätigen. Abmelden geht jederzeit. Mehr dazu in der %s.', 'lindenweg16' ),
				'<a href="' . esc_url( $lindenweg16_privacy ) . '">' . esc_html__( 'Datenschutzerklärung', 'lindenweg16' ) . '</a>'
			);
		?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->
