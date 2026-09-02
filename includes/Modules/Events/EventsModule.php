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
		( new EventsWidget() )->register();
		( new EventsBlock() )->register();

		if ( is_admin() ) {
			( new EventAdmin() )->register();
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		// Unbestätigte Anmeldungen mit abgelaufenem Link verfallen lassen.
		add_action( \Lindenweg16\Core\Scheduler::HOOK_DAILY_EVENTS, array( $this, 'aufraeumen' ) );
	}

	/**
	 * Frontend-Stil und Skript nur dort laden, wo Events vorkommen.
	 */
	public function enqueue() {
		if ( ! $this->braucht_assets() ) {
			return;
		}

		wp_enqueue_style(
			'lw16-events',
			LW16_PLUGIN_URL . 'assets/css/events.css',
			array(),
			\Lindenweg16\Core\Assets::version( 'assets/css/events.css' )
		);

		wp_enqueue_script(
			'lw16-events',
			LW16_PLUGIN_URL . 'assets/js/events.js',
			array(),
			\Lindenweg16\Core\Assets::version( 'assets/js/events.js' ),
			true
		);
	}

	/**
	 * Kommen auf dieser Seite Events vor?
	 *
	 * Bewusst eine ausdrückliche Liste statt eines pauschalen Ladens: Der
	 * Stil gehört auf Eventseiten, auf die Startseite und dorthin, wo der
	 * Shortcode oder das Widget tatsächlich steht.
	 *
	 * @return bool
	 */
	protected function braucht_assets() {
		if ( is_singular( EventPostType::POST_TYPE ) || is_post_type_archive( EventPostType::POST_TYPE ) ) {
			return true;
		}

		// Die Startseite bindet die Reihe über die Theme-Vorlage ein.
		if ( is_front_page() ) {
			return true;
		}

		if ( is_active_widget( false, false, 'lw16_events', true ) ) {
			return true;
		}

		$post = get_post();
		if ( ! $post ) {
			return false;
		}

		if ( has_shortcode( (string) $post->post_content, EventsWidget::SHORTCODE ) ) {
			return true;
		}

		if ( function_exists( 'has_block' ) && has_block( EventsBlock::NAME, $post ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Abgelaufene, nie bestätigte Anmeldungen löschen.
	 */
	public function aufraeumen() {
		RsvpRepository::purge_abgelaufene();
	}
}
