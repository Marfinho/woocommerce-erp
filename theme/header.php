<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php if ( get_theme_mod( 'lw16_announcement_show', true ) ) : ?>
	<div class="lw16-announcement">
		<?php echo esc_html( get_theme_mod( 'lw16_announcement_text', 'WIR LAUNCHEN! Sei dabei und erhalte 15% Rabatt — Code START15' ) ); ?>
	</div>
<?php endif; ?>

<header class="lw16-header">
	<div class="lw16-header__logo">
		<?php lw16_logo(); ?>
	</div>
	<nav class="lw16-header__nav">
		<?php
		wp_nav_menu( array(
			'theme_location' => 'primary',
			'container'      => false,
			'menu_class'     => 'lw16-nav',
			'depth'          => 1,
			'fallback_cb'    => function() {
				echo '<ul class="lw16-nav">';
				echo '<li><a href="' . esc_url( home_url( '/' ) ) . '">Start</a></li>';
				if ( function_exists( 'wc_get_page_id' ) ) {
					echo '<li><a href="' . esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ) . '">Shop</a></li>';
				}
				$about = get_page_by_path( 'ueber-uns' );
				if ( $about ) {
					echo '<li><a href="' . esc_url( get_permalink( $about ) ) . '">' . esc_html__( 'Über uns', 'lindenweg16' ) . '</a></li>';
				}
				$contact = get_page_by_path( 'kontakt' );
				if ( $contact ) {
					echo '<li><a href="' . esc_url( get_permalink( $contact ) ) . '">Kontakt</a></li>';
				}
				echo '</ul>';
			},
		) );
		?>
	</nav>
	<div class="lw16-header__actions">
		<?php if ( function_exists( 'wc_get_account_endpoint_url' ) ) : ?>
			<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="lw16-header__account">
				<?php esc_html_e( 'Konto', 'lindenweg16' ); ?>
			</a>
		<?php endif; ?>
		<?php lw16_cart_link(); ?>
	</div>
	<button class="lw16-header__burger" aria-label="<?php esc_attr_e( 'Menü', 'lindenweg16' ); ?>">
		<span></span><span></span><span></span>
	</button>
</header>
