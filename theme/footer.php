<footer class="lw16-footer">
	<div class="lw16-footer__inner">
		<div class="lw16-footer__brand">
			<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/lindenweg16-logo-tinte.png' ); ?>"
				 alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
				 class="lw16-footer__logo" height="36">
			<p><?php esc_html_e( 'Modulare Fahrradorganisation aus Holz und 3D-Druck. Mit Liebe aus Haste.', 'lindenweg16' ); ?></p>
		</div>
		<div class="lw16-footer__links">
			<h4><?php esc_html_e( 'Quick Links', 'lindenweg16' ); ?></h4>
			<?php
			wp_nav_menu( array(
				'theme_location' => 'footer',
				'container'      => false,
				'menu_class'     => 'lw16-footer-nav',
				'depth'          => 1,
				'fallback_cb'    => function() {
					echo '<ul class="lw16-footer-nav">';
					echo '<li><a href="' . esc_url( home_url( '/' ) ) . '">Start</a></li>';
					if ( function_exists( 'wc_get_page_id' ) ) {
						echo '<li><a href="' . esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ) . '">Shop</a></li>';
					}
					$about = get_page_by_path( 'ueber-uns' );
					if ( $about ) {
						echo '<li><a href="' . esc_url( get_permalink( $about ) ) . '">' . esc_html__( 'Über uns', 'lindenweg16' ) . '</a></li>';
					}
					echo '</ul>';
				},
			) );
			?>
		</div>
		<div class="lw16-footer__contact">
			<h4><?php esc_html_e( 'Kontakt', 'lindenweg16' ); ?></h4>
			<ul>
				<?php
				$contact = get_page_by_path( 'kontakt' );
				if ( $contact ) : ?>
					<li><a href="<?php echo esc_url( get_permalink( $contact ) ); ?>"><?php esc_html_e( 'Kontaktformular', 'lindenweg16' ); ?></a></li>
				<?php endif; ?>
				<li>Lindenweg 16, 31559 Haste</li>
			</ul>
		</div>
	</div>
	<div class="lw16-footer__bottom">
		&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
