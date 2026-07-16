<?php
defined( 'ABSPATH' ) || exit;

define( 'LW16_THEME_VERSION', '1.0.0' );

function lw16_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array(
		'height'      => 40,
		'width'       => 200,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	register_nav_menus( array(
		'primary' => __( 'Hauptnavigation', 'lindenweg16' ),
		'footer'  => __( 'Footer Links', 'lindenweg16' ),
	) );

	set_post_thumbnail_size( 600, 600, true );
	add_image_size( 'lw16-hero', 1920, 640, true );
	add_image_size( 'lw16-product-thumb', 80, 80, true );
}
add_action( 'after_setup_theme', 'lw16_setup' );

function lw16_enqueue() {
	wp_enqueue_style( 'lw16-fonts', 'https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600&display=swap', array(), null );
	wp_enqueue_style( 'lw16-theme', get_template_directory_uri() . '/assets/css/lindenweg16.css', array(), LW16_THEME_VERSION );
	wp_enqueue_script( 'lw16-theme', get_template_directory_uri() . '/assets/js/lindenweg16.js', array(), LW16_THEME_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'lw16_enqueue' );

function lw16_widgets_init() {
	register_sidebar( array(
		'name'          => __( 'Shop Sidebar', 'lindenweg16' ),
		'id'            => 'shop-sidebar',
		'before_widget' => '<div class="lw16-widget">',
		'after_widget'  => '</div>',
		'before_title'  => '<h3 class="lw16-widget__title">',
		'after_title'   => '</h3>',
	) );
}
add_action( 'widgets_init', 'lw16_widgets_init' );

// WooCommerce: products per row
function lw16_wc_loop_columns() {
	if ( is_shop() || is_product_taxonomy() ) {
		return 4;
	}
	return 4;
}
add_filter( 'loop_shop_columns', 'lw16_wc_loop_columns' );

// WooCommerce: products per page
add_filter( 'loop_shop_per_page', function() { return 12; } );

// WooCommerce: remove default wrapper
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );

function lw16_wc_wrapper_start() {
	echo '<main class="lw16-main">';
}
function lw16_wc_wrapper_end() {
	echo '</main>';
}
add_action( 'woocommerce_before_main_content', 'lw16_wc_wrapper_start', 10 );
add_action( 'woocommerce_after_main_content', 'lw16_wc_wrapper_end', 10 );

// WooCommerce: remove default sidebar
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

// WooCommerce: related products count
add_filter( 'woocommerce_output_related_products_args', function( $args ) {
	$args['posts_per_page'] = 4;
	$args['columns']        = 4;
	return $args;
} );

// WooCommerce: gallery thumbnails count
add_filter( 'woocommerce_product_thumbnails_columns', function() { return 4; } );

// Announcement bar via Customizer
function lw16_customizer( $wp_customize ) {
	$wp_customize->add_section( 'lw16_announcement', array(
		'title'    => __( 'Announcement Bar', 'lindenweg16' ),
		'priority' => 30,
	) );
	$wp_customize->add_setting( 'lw16_announcement_text', array(
		'default'           => 'WIR LAUNCHEN! Sei dabei und erhalte 15% Rabatt — Code START15',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'lw16_announcement_text', array(
		'label'   => __( 'Announcement Text', 'lindenweg16' ),
		'section' => 'lw16_announcement',
		'type'    => 'text',
	) );
	$wp_customize->add_setting( 'lw16_announcement_show', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'lw16_announcement_show', array(
		'label'   => __( 'Announcement anzeigen', 'lindenweg16' ),
		'section' => 'lw16_announcement',
		'type'    => 'checkbox',
	) );

	// Hero section
	$wp_customize->add_section( 'lw16_hero', array(
		'title'    => __( 'Hero Bereich', 'lindenweg16' ),
		'priority' => 31,
	) );
	$wp_customize->add_setting( 'lw16_hero_image', array( 'sanitize_callback' => 'absint' ) );
	$wp_customize->add_control( new WP_Customize_Media_Control( $wp_customize, 'lw16_hero_image', array(
		'label'     => __( 'Hero Bild', 'lindenweg16' ),
		'section'   => 'lw16_hero',
		'mime_type' => 'image',
	) ) );
	$wp_customize->add_setting( 'lw16_hero_eyebrow', array(
		'default'           => 'Handgemacht in Haste',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'lw16_hero_eyebrow', array(
		'label'   => __( 'Eyebrow Text', 'lindenweg16' ),
		'section' => 'lw16_hero',
	) );
	$wp_customize->add_setting( 'lw16_hero_title', array(
		'default'           => 'Ordnung für dein Fahrrad-Gear',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'lw16_hero_title', array(
		'label'   => __( 'Hero Titel', 'lindenweg16' ),
		'section' => 'lw16_hero',
	) );
	$wp_customize->add_setting( 'lw16_hero_text', array(
		'default'           => 'Modulare Wandhalter aus Massivholz und 3D-Druck — individuell zusammengestellt für Helm, Schloss, Trikot und Werkzeug.',
		'sanitize_callback' => 'sanitize_textarea_field',
	) );
	$wp_customize->add_control( 'lw16_hero_text', array(
		'label'   => __( 'Hero Text', 'lindenweg16' ),
		'section' => 'lw16_hero',
		'type'    => 'textarea',
	) );

	// About teaser
	$wp_customize->add_section( 'lw16_about_teaser', array(
		'title'    => __( 'Story Teaser (Startseite)', 'lindenweg16' ),
		'priority' => 32,
	) );
	$wp_customize->add_setting( 'lw16_about_teaser_image', array( 'sanitize_callback' => 'absint' ) );
	$wp_customize->add_control( new WP_Customize_Media_Control( $wp_customize, 'lw16_about_teaser_image', array(
		'label'     => __( 'Werkstattfoto', 'lindenweg16' ),
		'section'   => 'lw16_about_teaser',
		'mime_type' => 'image',
	) ) );

	// Newsletter
	$wp_customize->add_section( 'lw16_newsletter', array(
		'title'    => __( 'Newsletter', 'lindenweg16' ),
		'priority' => 33,
	) );
	$wp_customize->add_setting( 'lw16_newsletter_action', array(
		'default'           => '',
		'sanitize_callback' => 'esc_url_raw',
	) );
	$wp_customize->add_control( 'lw16_newsletter_action', array(
		'label'       => __( 'Newsletter Formular URL', 'lindenweg16' ),
		'description' => __( 'Z.B. Mailchimp oder CleverReach Action URL', 'lindenweg16' ),
		'section'     => 'lw16_newsletter',
	) );
}
add_action( 'customize_register', 'lw16_customizer' );

// Custom body class
add_filter( 'body_class', function( $classes ) {
	$classes[] = 'lw16';
	return $classes;
} );

// WooCommerce breadcrumb args
add_filter( 'woocommerce_breadcrumb_defaults', function( $defaults ) {
	$defaults['delimiter']   = ' / ';
	$defaults['wrap_before'] = '<nav class="lw16-breadcrumb">';
	$defaults['wrap_after']  = '</nav>';
	$defaults['before']      = '<span>';
	$defaults['after']       = '</span>';
	$defaults['home']        = __( 'Start', 'lindenweg16' );
	return $defaults;
} );

// Remove WooCommerce default page title on shop
add_filter( 'woocommerce_show_page_title', '__return_false' );

// Include template tags
require_once get_template_directory() . '/inc/template-tags.php';
