<form role="search" method="get" class="lw16-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<input type="search" name="s" placeholder="<?php esc_attr_e( 'Suchen...', 'lindenweg16' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>">
	<?php if ( function_exists( 'WC' ) ) : ?>
		<input type="hidden" name="post_type" value="product">
	<?php endif; ?>
	<button type="submit" class="lw16-btn lw16-btn--pill lw16-btn--sm"><?php esc_html_e( 'Suchen', 'lindenweg16' ); ?></button>
</form>
