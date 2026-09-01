<?php
/**
 * Modul: Events – Social Rides, Märkte, Rennen mit Anmeldung und QR-Code.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Verdrahtet Inhaltstyp, Backend, Frontend und den täglichen Aufräumlauf.
 */
class EventsModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'init', array( EventPostType::class, 'register' ) );
		add_action( 'init', array( EventPostType::class, 'seed_typen' ), 20 );

		( new EventFrontend() )->register();

		if ( is_admin() ) {
			( new EventAdmin() )->register();
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		// Unbestätigte Anmeldungen mit abgelaufenem Link verfallen lassen.
		add_action( \Lindenweg16\Core\Scheduler::HOOK_DAILY_EVENTS, array( $this, 'aufraeumen' ) );
	}

	/**
	 * Frontend-Stil nur auf Eventseiten laden.
	 */
	public function enqueue() {
		if ( ! is_singular( EventPostType::POST_TYPE ) && ! is_post_type_archive( EventPostType::POST_TYPE ) ) {
			return;
		}

		wp_enqueue_style(
			'lw16-events',
			LW16_PLUGIN_URL . 'assets/css/events.css',
			array(),
			\Lindenweg16\Core\Assets::version( 'assets/css/events.css' )
		);
	}

	/**
	 * Abgelaufene, nie bestätigte Anmeldungen löschen.
	 */
	public function aufraeumen() {
		RsvpRepository::purge_abgelaufene();
	}
}
