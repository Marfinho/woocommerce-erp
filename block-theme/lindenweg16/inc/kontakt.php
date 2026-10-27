<?php
/**
 * Kontaktseite: Kontaktdaten für das Template „Kontakt“ (page-kontakt.html).
 *
 * Die Daten kommen aus den Firmendaten des ERP-Plugins (Lindenweg16 →
 * Einstellungen → Firmendaten), dieselben wie auf Rechnungen und DHL-Labels.
 * Ohne Plugin greifen die WooCommerce-Shopadresse und die Admin-E-Mail.
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ein Wert aus den Plugin-Einstellungen (Option lw16_settings).
 */
function lindenweg16_plugin_setting( string $key ): string {
	if ( class_exists( '\Lindenweg16\Core\Settings' ) ) {
		return trim( (string) \Lindenweg16\Core\Settings::get( $key, '' ) );
	}

	$settings = get_option( 'lw16_settings', array() );

	return is_array( $settings ) && isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
}

/**
 * Kontaktdaten für die Kontaktseite.
 *
 * @return array{email:string,phone:string,phone_href:string,street:string,city:string}
 */
function lindenweg16_contact_data(): array {
	$street = lindenweg16_plugin_setting( 'firma_strasse' );
	$city   = lindenweg16_plugin_setting( 'firma_plz_ort' );

	if ( '' === $street ) {
		$street = trim( get_option( 'woocommerce_store_address', '' ) . ' ' . get_option( 'woocommerce_store_address_2', '' ) );
	}
	if ( '' === $city ) {
		$city = trim( get_option( 'woocommerce_store_postcode', '' ) . ' ' . get_option( 'woocommerce_store_city', '' ) );
	}

	$email = sanitize_email( lindenweg16_plugin_setting( 'firma_email' ) ?: (string) get_option( 'admin_email' ) );
	$phone = lindenweg16_plugin_setting( 'firma_telefon' );

	$data = array(
		'email'      => $email,
		'phone'      => $phone,
		'phone_href' => '' !== $phone ? 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) : '',
		'street'     => $street,
		'city'       => $city,
	);

	/**
	 * Filtert die Kontaktdaten der Kontaktseite.
	 *
	 * @param array $data email, phone, phone_href, street, city.
	 */
	return (array) apply_filters( 'lindenweg16_contact_data', $data );
}

/**
 * Adresse der Kontaktseite: Slug „kontakt“, sonst „contact-us“ (alter Slug),
 * sonst /kontakt/.
 */
function lindenweg16_contact_page_url(): string {
	foreach ( array( 'kontakt', 'contact-us' ) as $path ) {
		$page = get_page_by_path( $path );
		if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
			return (string) get_permalink( $page );
		}
	}

	return home_url( '/kontakt/' );
}
