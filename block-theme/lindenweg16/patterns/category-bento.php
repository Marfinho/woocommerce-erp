<?php
/**
 * Title: Kategorien als Bento-Grid
 * Slug: lindenweg16/category-bento
 * Categories: lindenweg16
 * Keywords: kategorien, bento, kacheln, sortiment
 * Viewport Width: 1400
 * Description: Kacheln in den Akzentfarben mit Bild, in unterschiedlichen Größen. Beim Hover heben sie sich an und drehen sich leicht.
 *
 * @package Lindenweg16
 */

$lindenweg16_tiles = array(
	array(
		'size'  => 'xl',
		'color' => 'accent-1',
		'image' => 'kachel-sets.svg',
		'title' => __( 'Haster-Haken-Sets', 'lindenweg16' ),
		'text'  => __( 'Fertig zusammengestellt für Helm, Schloss und Kleinkram – auspacken, anschrauben, losfahren.', 'lindenweg16' ),
		'url'   => lindenweg16_category_url( 'sets' ),
	),
	array(
		'size'  => '',
		'color' => 'accent-3',
		'image' => 'kachel-module.svg',
		'title' => __( 'Module', 'lindenweg16' ),
		'text'  => __( 'Einzeln nachrüsten, wann du willst.', 'lindenweg16' ),
		'url'   => lindenweg16_category_url( 'module' ),
	),
	array(
		'size'  => '',
		'color' => 'accent-4',
		'image' => 'kachel-helm.svg',
		'title' => __( 'Helmhalter', 'lindenweg16' ),
		'text'  => __( 'Luftig aufgehängt, trocknet schneller.', 'lindenweg16' ),
		'url'   => lindenweg16_category_url( 'module' ),
	),
	array(
		'size'  => 'wide',
		'color' => 'accent-2',
		'image' => 'kachel-schloss.svg',
		'title' => __( 'Schloss & Zubehör', 'lindenweg16' ),
		'text'  => __( 'Bügelschloss, Kette, Licht – alles griffbereit an der Tür.', 'lindenweg16' ),
		'url'   => lindenweg16_category_url( 'zubehoer' ),
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
		<h2 class="wp-block-heading"><?php esc_html_e( 'Was suchst du?', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"lw16-bento","layout":{"type":"default"}} -->
	<div class="wp-block-group lw16-bento">
<?php foreach ( $lindenweg16_tiles as $lindenweg16_tile ) : ?>
<?php
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

			<!-- wp:image {"sizeSlug":"full","linkDestination":"none"} -->
			<figure class="wp-block-image size-full"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/' . $lindenweg16_tile['image'] ) ); ?>" alt=""/></figure>
			<!-- /wp:image -->
		</div>
		<!-- /wp:group -->
<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->
