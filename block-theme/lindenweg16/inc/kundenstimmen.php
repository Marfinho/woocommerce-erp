<?php
/**
 * Kundenstimmen aus echten WooCommerce-Produktbewertungen.
 *
 * Shortcode: [lindenweg16_kundenstimmen anzahl="3" min_sterne="4"]
 *
 * Gepflegt werden die Stimmen im Backend unter Produkte → Bewertungen:
 * Freigegebene Bewertungen ab der Mindestzahl an Sternen erscheinen
 * automatisch, die neuesten zuerst. Gibt es keine, wird der ganze Abschnitt
 * ausgeblendet – es gibt keine erfundenen Beispieltexte.
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gibt den Abschnitt „Kundenstimmen“ aus.
 *
 * @param array<string, string>|string $atts Shortcode-Attribute.
 */
function lindenweg16_kundenstimmen_shortcode( $atts ): string {
	$atts = shortcode_atts(
		array(
			'anzahl'     => '3',
			'min_sterne' => '4',
		),
		is_array( $atts ) ? $atts : array(),
		'lindenweg16_kundenstimmen'
	);

	$count    = max( 1, min( 9, (int) $atts['anzahl'] ) );
	$min_star = max( 1, min( 5, (int) $atts['min_sterne'] ) );

	$reviews = get_comments(
		array(
			'type'       => 'review',
			'status'     => 'approve',
			'post_type'  => 'product',
			'number'     => $count,
			'orderby'    => 'comment_date_gmt',
			'order'      => 'DESC',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => 'rating',
					'value'   => $min_star,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);

	if ( ! $reviews ) {
		return '';
	}

	$cards = '';
	foreach ( $reviews as $review ) {
		$rating = (int) get_comment_meta( (int) $review->comment_ID, 'rating', true );
		$text   = wp_trim_words( wp_strip_all_tags( (string) $review->comment_content ), 45, ' …' );

		// Nur Vorname und Initial des Nachnamens – mehr braucht eine Kundenstimme nicht.
		$parts = preg_split( '/\s+/', trim( (string) $review->comment_author ) ) ?: array();
		$name  = $parts[0] ?? '';
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			$name .= ' ' . mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 ) . '.';
		}

		$product      = get_the_title( (int) $review->comment_post_ID );
		$product_link = get_permalink( (int) $review->comment_post_ID );

		$cards .= sprintf(
			'<div class="wp-block-group is-style-card lw16-testimonial"><blockquote class="wp-block-quote"><p>%1$s</p></blockquote><div class="lw16-testimonial__footer"><p class="lw16-stars" style="--lw16-stars:%2$d">%3$s</p><p class="lw16-testimonial__name">%4$s</p><p class="lw16-testimonial__meta">%5$s</p></div></div>',
			esc_html( $text ),
			$rating,
			/* translators: %d: Anzahl der Sterne (1–5). */
			esc_html( sprintf( __( '%d von 5 Sternen', 'lindenweg16' ), $rating ) ),
			esc_html( $name ),
			sprintf(
				/* translators: %s: Produktname mit Link. */
				esc_html__( 'über %s', 'lindenweg16' ),
				'<a href="' . esc_url( (string) $product_link ) . '">' . esc_html( $product ) . '</a>'
			)
		);
	}

	return sprintf(
		'<section class="wp-block-group alignwide lw16-section lw16-kundenstimmen"><div class="wp-block-group lw16-section-head is-layout-flex is-vertical"><p class="is-style-eyebrow">%1$s</p><h2 class="wp-block-heading">%2$s</h2></div><div class="lw16-kundenstimmen__grid">%3$s</div></section>',
		esc_html__( 'Kundenstimmen', 'lindenweg16' ),
		esc_html__( 'Das sagen andere Radfahrer', 'lindenweg16' ),
		$cards
	);
}
add_shortcode( 'lindenweg16_kundenstimmen', 'lindenweg16_kundenstimmen_shortcode' );

/**
 * Derselbe Abschnitt als dynamischer Block „Kundenstimmen“. Anders als ein
 * Shortcode funktioniert er auch in Templates und Patterns (dort laufen
 * Shortcodes vor den Blöcken). Im Editor zeigt er eine Live-Vorschau.
 */
function lindenweg16_register_kundenstimmen_block(): void {
	wp_register_script(
		'lindenweg16-kundenstimmen-editor',
		get_theme_file_uri( 'assets/js/kundenstimmen-block.js' ),
		array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-server-side-render', 'wp-block-editor', 'wp-components' ),
		LINDENWEG16_VERSION,
		true
	);
	wp_set_script_translations( 'lindenweg16-kundenstimmen-editor', 'lindenweg16', get_template_directory() . '/languages' );

	register_block_type(
		'lindenweg16/kundenstimmen',
		array(
			'api_version'     => 3,
			'title'           => __( 'Kundenstimmen', 'lindenweg16' ),
			'description'     => __( 'Zeigt die neuesten freigegebenen Produktbewertungen als Karten.', 'lindenweg16' ),
			'category'        => 'widgets',
			'icon'            => 'format-quote',
			'editor_script'   => 'lindenweg16-kundenstimmen-editor',
			'attributes'      => array(
				'anzahl'    => array(
					'type'    => 'number',
					'default' => 3,
				),
				'minSterne' => array(
					'type'    => 'number',
					'default' => 4,
				),
			),
			'supports'        => array(
				'html'  => false,
				'align' => array( 'wide' ),
			),
			'render_callback' => static function ( array $attributes ): string {
				return lindenweg16_kundenstimmen_shortcode(
					array(
						'anzahl'     => (string) ( $attributes['anzahl'] ?? 3 ),
						'min_sterne' => (string) ( $attributes['minSterne'] ?? 4 ),
					)
				);
			},
		)
	);
}
add_action( 'init', 'lindenweg16_register_kundenstimmen_block' );
