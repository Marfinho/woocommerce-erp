<?php
/**
 * Events als Inhaltstyp: Social Ride, Markt, Rennen.
 *
 * Bewusst ein Custom Post Type statt einer eigenen Tabelle wie im übrigen
 * Plugin: Ein Event ist öffentlicher Inhalt mit Titel, Text, Bild und
 * Adresse. Editor, Beitragsbild, Vorschau, Permalinks und die Sichtbarkeit
 * für Suchmaschinen gäbe es sonst alle noch einmal zu bauen. Die
 * Anmeldungen sind Bewegungsdaten und liegen deshalb in einer eigenen
 * Tabelle.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert den Inhaltstyp, die Typ-Taxonomie und die Eckdaten-Meta.
 */
class EventPostType {

	/**
	 * Post-Type-Schlüssel.
	 */
	const POST_TYPE = 'lw16_event';

	/**
	 * Taxonomie der Eventtypen.
	 */
	const TAXONOMY = 'lw16_event_typ';

	/**
	 * Meta-Schlüssel der Eckdaten.
	 */
	const META = array(
		'beginn'          => '_lw16_event_beginn',
		'ende'            => '_lw16_event_ende',
		'ort'             => '_lw16_event_ort',
		'treffpunkt'      => '_lw16_event_treffpunkt',
		'rsvp_aktiv'      => '_lw16_event_rsvp_aktiv',
		'max_teilnehmer'  => '_lw16_event_max_teilnehmer',
		'kampagne_id'     => '_lw16_event_kampagne_id',
		'felder'          => '_lw16_event_felder',
	);

	/**
	 * Typen, die beim ersten Start angelegt werden. Weitere lassen sich im
	 * Backend jederzeit ergänzen – die Liste ist ein Startpunkt, keine Grenze.
	 *
	 * @return array Slug => Bezeichnung.
	 */
	public static function standard_typen() {
		return array(
			'social-ride' => __( 'Social Ride', 'lindenweg16' ),
			'markt'       => __( 'Markt', 'lindenweg16' ),
			'rennen'      => __( 'Rennen', 'lindenweg16' ),
		);
	}

	/**
	 * Inhaltstyp und Taxonomie anmelden.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'        => array(
					'name'               => __( 'Events', 'lindenweg16' ),
					'singular_name'      => __( 'Event', 'lindenweg16' ),
					'add_new'            => __( 'Neues Event', 'lindenweg16' ),
					'add_new_item'       => __( 'Neues Event anlegen', 'lindenweg16' ),
					'edit_item'          => __( 'Event bearbeiten', 'lindenweg16' ),
					'new_item'           => __( 'Neues Event', 'lindenweg16' ),
					'view_item'          => __( 'Event ansehen', 'lindenweg16' ),
					'search_items'       => __( 'Events durchsuchen', 'lindenweg16' ),
					'not_found'          => __( 'Keine Events gefunden.', 'lindenweg16' ),
					'not_found_in_trash' => __( 'Keine Events im Papierkorb.', 'lindenweg16' ),
					'all_items'          => __( 'Alle Events', 'lindenweg16' ),
					'menu_name'          => __( 'Events', 'lindenweg16' ),
				),
				'public'        => true,
				'has_archive'   => 'events',
				'menu_icon'     => 'dashicons-calendar-alt',
				'menu_position' => 57,
				'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
				'rewrite'       => array( 'slug' => 'event', 'with_front' => false ),
				'show_in_rest'  => true,
				'capability_type' => 'post',
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Eventtypen', 'lindenweg16' ),
					'singular_name' => __( 'Eventtyp', 'lindenweg16' ),
					'add_new_item'  => __( 'Eventtyp hinzufügen', 'lindenweg16' ),
					'menu_name'     => __( 'Eventtypen', 'lindenweg16' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'eventtyp', 'with_front' => false ),
			)
		);

		self::register_meta();
	}

	/**
	 * Eckdaten als Post-Meta anmelden.
	 *
	 * Einzeln registriert, damit sie im Block-Editor und über die REST-API
	 * sichtbar sind – und damit jedes Feld seine eigene Bereinigung hat.
	 */
	protected static function register_meta() {
		// rsvp_aktiv liegt als 0/1 in der Datenbank und wird deshalb auch als
		// Zahl angemeldet: Ein als 'boolean' deklariertes Feld, das 1 statt
		// true zurückgibt, fällt über die REST-Schnittstelle auf die Nase.
		$typen = array(
			'beginn'         => 'string',
			'ende'           => 'string',
			'ort'            => 'string',
			'treffpunkt'     => 'string',
			'rsvp_aktiv'     => 'integer',
			'max_teilnehmer' => 'integer',
			'kampagne_id'    => 'integer',
		);

		foreach ( $typen as $key => $typ ) {
			register_post_meta(
				self::POST_TYPE,
				self::META[ $key ],
				array(
					'type'              => $typ,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'integer' === $typ ? 'absint' : 'sanitize_text_field',
					'auth_callback'     => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}

		// Die variablen Felder sind ein verschachteltes Array. Sie bewusst
		// nicht über die REST-API freigeben: Ihre Struktur prüft
		// EventFields beim Speichern, ein zweiter Schreibweg daran vorbei
		// wäre eine Lücke.
		register_post_meta(
			self::POST_TYPE,
			self::META['felder'],
			array(
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Standardtypen anlegen, falls die Taxonomie noch leer ist.
	 *
	 * Läuft nur einmal: Wer einen Typ später löscht, soll ihn nicht beim
	 * nächsten Seitenaufruf wiederhaben.
	 */
	public static function seed_typen() {
		if ( get_option( 'lw16_event_typen_gesetzt' ) ) {
			return;
		}

		foreach ( self::standard_typen() as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $name, self::TAXONOMY, array( 'slug' => $slug ) );
			}
		}

		update_option( 'lw16_event_typen_gesetzt', 1 );
	}

	/**
	 * Eckdaten eines Events als Array.
	 *
	 * @param int $post_id Event.
	 * @return array
	 */
	public static function daten( $post_id ) {
		$post_id = (int) $post_id;

		return array(
			'beginn'         => (string) get_post_meta( $post_id, self::META['beginn'], true ),
			'ende'           => (string) get_post_meta( $post_id, self::META['ende'], true ),
			'ort'            => (string) get_post_meta( $post_id, self::META['ort'], true ),
			'treffpunkt'     => (string) get_post_meta( $post_id, self::META['treffpunkt'], true ),
			'rsvp_aktiv'     => (int) get_post_meta( $post_id, self::META['rsvp_aktiv'], true ) === 1,
			'max_teilnehmer' => (int) get_post_meta( $post_id, self::META['max_teilnehmer'], true ),
			'kampagne_id'    => (int) get_post_meta( $post_id, self::META['kampagne_id'], true ),
		);
	}

	/**
	 * Typ-Slug eines Events (der erste, falls mehrere gesetzt sind).
	 *
	 * @param int $post_id Event.
	 * @return string
	 */
	public static function typ_slug( $post_id ) {
		$terms = get_the_terms( (int) $post_id, self::TAXONOMY );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		$erster = reset( $terms );
		return (string) $erster->slug;
	}
}
