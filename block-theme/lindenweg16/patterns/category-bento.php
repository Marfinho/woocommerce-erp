<?php
/**
 * Title: Kategorien: Sets und Module
 * Slug: lindenweg16/category-bento
 * Categories: lindenweg16
 * Keywords: kategorien, bento, kacheln, sortiment
 * Viewport Width: 1400
 * Description: Zwei große Kacheln in den Akzentfarben mit Bild – Sets und Module. Beim Hover heben sie sich an und drehen sich leicht.
 *
 * @package Lindenweg16
 */

$lindenweg16_tiles = array(
	array(
		'size'  => 'half',
		'color' => 'accent-1',
		'image' => 'kachel-sets.svg',
		'slot'  => 'sets',
		'title' => __( 'Sets', 'lindenweg16' ),
		'text'  => __( 'Fertig zusammengestellt: Schiene und passende Module in einem Paket. Auspacken, anschrauben, losfahren.', 'lindenweg16' ),
		'url'   => lindenweg16_category_url( 'sets' ),
	),
	array(
		'size'  => 'half',
		'color' => 'accent-3',
		'image' => 'kachel-module.svg',
		'slot'  => 'module',
		'title' => __( 'Module', 'lindenweg16' ),
		'text'  => __( 'Einzeln nachrüsten, wann du willst – jedes Modul passt auf jede Haster-Haken-Schiene.', 'lindenweg16' ),
		'url'   => lindenweg16_category_url( 'module' ),
	),
);
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section">
	<!-- wp:group {"className":"lw16-section-head","layout":{"type":"flex","orientation":"vertical"}} -->
	<div class="wp-block-group lw16-section-head">
		<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
		<p class="is-style-eyebrow"><?php esc_html_e( 'Sortiment', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:heading -->
		<h2 class="wp-block-heading"><?php esc_html_e( 'Set oder einzeln – du entscheidest', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"lw16-bento","layout":{"type":"default"}} -->
	<div class="wp-block-group lw16-bento">
<?php foreach ( $lindenweg16_tiles as $lindenweg16_tile ) : ?>
<?php
	$lindenweg16_photo = lindenweg16_front_image( $lindenweg16_tile['slot'] );
	$lindenweg16_class = 'lw16-bento__tile' . ( $lindenweg16_tile['size'] ? ' lw16-bento__tile--' . $lindenweg16_tile['size'] : '' );
?>
		<!-- wp:group {"className":"<?php echo esc_attr( $lindenweg16_class ); ?>","backgroundColor":"<?php echo esc_attr( $lindenweg16_tile['color'] ); ?>","layout":{"type":"default"}} -->
		<div class="wp-block-group <?php echo esc_attr( $lindenweg16_class ); ?> has-<?php echo esc_attr( $lindenweg16_tile['color'] ); ?>-background-color has-background">
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

<?php if ( $lindenweg16_photo ) : ?>
			<!-- wp:image {"id":<?php echo (int) $lindenweg16_photo['id']; ?>,"sizeSlug":"large","linkDestination":"none","className":"lw16-photo"} -->
			<figure class="wp-block-image size-large lw16-photo"><img src="<?php echo esc_url( $lindenweg16_photo['url'] ); ?>" alt="" class="wp-image-<?php echo (int) $lindenweg16_photo['id']; ?>" loading="lazy" decoding="async" sizes="(max-width: 781px) 40vw, 240px"/></figure>
			<!-- /wp:image -->
<?php else : ?>
			<!-- wp:image {"sizeSlug":"full","linkDestination":"none"} -->
			<figure class="wp-block-image size-full"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/' . $lindenweg16_tile['image'] ) ); ?>" alt=""/></figure>
			<!-- /wp:image -->
<?php endif; ?>
		</div>
		<!-- /wp:group -->
<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->
