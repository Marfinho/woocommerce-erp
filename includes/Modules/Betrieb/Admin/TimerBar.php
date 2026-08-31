<?php
/**
 * Stoppuhr in der oberen Adminleiste.
 *
 * Zeit wird dort erfasst, wo sie entsteht – nicht abends aus dem
 * Gedächtnis. Deshalb sitzt die Uhr in der Leiste, die auf jeder Seite
 * steht, und nicht auf der Zeiterfassungsseite: Wer dorthin navigieren
 * muss, um zu starten, startet nicht.
 *
 * Die Uhr bucht nichts. Sie hält nur fest, dass etwas läuft; erst das
 * bestätigte Formular beim Stoppen erzeugt einen Zeiteintrag, und zwar
 * genau einen. Dieselbe Regel wie überall im Modul: Bestand und Arbeitszeit
 * ändern sich an genau einer Stelle.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;
use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\Stopwatch;
use Lindenweg16\Modules\Betrieb\TimerState;

/**
 * Knopf, Anzeige und Handler der Stoppuhr.
 */
class TimerBar {

	const NONCE = 'lw16_stoppuhr';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 90 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		// Die Leiste steht auch im Frontend – dort soll die Uhr genauso
		// weiterzählen, statt bei der Ladezeit stehenzubleiben.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this, 'track_page' ) );
		add_action( 'admin_post_lw16_timer_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_lw16_timer_discard', array( $this, 'handle_discard' ) );
	}

	/**
	 * Darf dieser Nutzer Zeit erfassen?
	 *
	 * @return bool
	 */
	protected static function allowed() {
		return is_user_logged_in() && Roles::current_user_can_betrieb( 'lw16_capture' );
	}

	/**
	 * Knoten in der Adminleiste.
	 *
	 * @param \WP_Admin_Bar $bar Adminleiste.
	 */
	public function add_node( $bar ) {
		if ( ! self::allowed() ) {
			return;
		}

		$state = TimerState::get();

		if ( ! $state ) {
			$bar->add_node(
				array(
					'id'    => 'lw16-stoppuhr',
					'title' => '<span class="ab-icon dashicons dashicons-clock" aria-hidden="true"></span>'
						. '<span class="ab-label">' . esc_html__( 'Zeit starten', 'lindenweg16' ) . '</span>',
					'href'  => $this->action_url( 'lw16_timer_start' ),
					'meta'  => array( 'class' => 'lw16-stoppuhr lw16-stoppuhr--aus' ),
				)
			);

			return;
		}

		$sekunden = Stopwatch::elapsed( $state['started_at'], current_time( 'mysql', true ) );

		$bar->add_node(
			array(
				'id'    => 'lw16-stoppuhr',
				'title' => '<span class="ab-icon dashicons dashicons-clock" aria-hidden="true"></span>'
					. '<span class="ab-label lw16-stoppuhr__zeit" data-start="' . esc_attr( (string) ( time() - $sekunden ) ) . '">'
					. esc_html( Stopwatch::short( $sekunden ) )
					. '</span>',
				'href'  => $this->stop_url(),
				'meta'  => array(
					'class' => 'lw16-stoppuhr lw16-stoppuhr--laeuft',
					'title' => __( 'Läuft seit', 'lindenweg16' ) . ' ' . $state['started_at'] . ' UTC',
				),
			)
		);

		$bar->add_node(
			array(
				'parent' => 'lw16-stoppuhr',
				'id'     => 'lw16-stoppuhr-stopp',
				'title'  => __( 'Stoppen und erfassen', 'lindenweg16' ),
				'href'   => $this->stop_url(),
			)
		);

		$bar->add_node(
			array(
				'parent' => 'lw16-stoppuhr',
				'id'     => 'lw16-stoppuhr-verwerfen',
				'title'  => __( 'Verwerfen, ohne zu buchen', 'lindenweg16' ),
				'href'   => $this->action_url( 'lw16_timer_discard' ),
			)
		);
	}

	/**
	 * Adresse eines Handlers.
	 *
	 * @param string $action Handler.
	 * @return string
	 */
	protected function action_url( $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'zurueck' => rawurlencode( $this->current_url() ),
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE
		);
	}

	/**
	 * Adresse des Stopp-Formulars.
	 *
	 * Gestoppt wird nicht per Klick, sondern über das Formular auf der
	 * Zeiterfassung: Die Zeit bekommt dort ihre Tätigkeit und ihre
	 * Beschreibung. Ein Klick, der stumm bucht, erzeugt Einträge, die
	 * niemand später zuordnen kann.
	 *
	 * @return string
	 */
	protected function stop_url() {
		return add_query_arg(
			array( 'page' => 'lw16-betrieb-zeit', 'stoppuhr' => 'stopp' ),
			admin_url( 'admin.php' )
		) . '#lw16-stoppuhr-formular';
	}

	/**
	 * Die Seite, auf der der Nutzer gerade steht.
	 *
	 * @return string
	 */
	protected function current_url() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( '' === $page ) {
			return admin_url();
		}

		return add_query_arg( array( 'page' => $page ), admin_url( 'admin.php' ) );
	}

	/**
	 * Skript und Stil laden.
	 */
	public function enqueue() {
		if ( ! self::allowed() || ! TimerState::is_running() ) {
			return;
		}

		wp_enqueue_script(
			'lw16-betrieb-stoppuhr',
			LW16_PLUGIN_URL . 'assets/js/betrieb-stoppuhr.js',
			array(),
			Assets::version( 'assets/js/betrieb-stoppuhr.js' ),
			true
		);
	}

	/**
	 * Festhalten, wo der Nutzer war.
	 *
	 * Nur bei einer laufenden Uhr und nur bei einem gewöhnlichen Aufruf –
	 * ein Formularversand oder eine AJAX-Anfrage ist kein Aufenthalt.
	 */
	public function track_page() {
		if ( wp_doing_ajax() || ! self::allowed() || ! TimerState::is_running() ) {
			return;
		}

		// admin-post.php ist kein Aufenthalt, sondern eine Weiterleitung.
		$skript = isset( $_SERVER['SCRIPT_NAME'] )
			? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) )
			: '';

		if ( 'admin-post.php' === $skript ) {
			return;
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		TimerState::touch( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' );
	}

	/**
	 * Uhr starten.
	 */
	public function handle_start() {
		$this->check();
		TimerState::start();
		$this->go_back();
	}

	/**
	 * Uhr verwerfen.
	 */
	public function handle_discard() {
		$this->check();
		TimerState::discard();
		$this->go_back();
	}

	/**
	 * Recht und Nonce prüfen.
	 */
	protected function check() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		check_admin_referer( self::NONCE );
	}

	/**
	 * Zurück, wo der Nutzer war.
	 */
	protected function go_back() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- oben geprüft.
		$zurueck = isset( $_GET['zurueck'] ) ? rawurldecode( wp_unslash( $_GET['zurueck'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_safe_redirect( $zurueck ? $zurueck : admin_url() );
		exit;
	}
}
