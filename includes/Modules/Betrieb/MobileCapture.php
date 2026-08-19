<?php
/**
 * Mobile Schnellerfassung.
 *
 * Erfasst wird am Drucker mit dem Handy, nicht am Schreibtisch. Wenn das
 * umständlich ist, wird es nicht gemacht – und dann ist das ganze Modul
 * wertlos. Deshalb: eigene Route hinter Login, große Bedienelemente, fünf
 * Eingaben je Druckjob, alles andere vorbelegt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;

/**
 * Registriert Route und Ausgabe der Erfassungsseite.
 */
class MobileCapture {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Route anmelden.
	 */
	public function add_rewrite() {
		add_rewrite_rule( '^' . DeviceLabel::ROUTE . '/?$', 'index.php?lw16_erfassung=1', 'top' );
	}

	/**
	 * Abfragevariable anmelden.
	 *
	 * @param array $vars Variablen.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'lw16_erfassung';
		return $vars;
	}

	/**
	 * Seite ausgeben, wenn die Route getroffen wurde.
	 */
	public function maybe_render() {
		if ( ! get_query_var( 'lw16_erfassung' ) ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}

		$this->render();
		exit;
	}

	/**
	 * Erfassungsseite ausgeben.
	 */
	protected function render() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$printer = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : '';

		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php esc_html_e( 'Erfassung · Betrieb', 'lindenweg16' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( LW16_PLUGIN_URL . 'assets/css/betrieb-mobile.css?v=' . LW16_VERSION ); ?>" />
</head>
<body class="lw16-mobile">
	<header class="lw16-mobile__head">
		<h1><?php esc_html_e( 'Erfassung', 'lindenweg16' ); ?></h1>
		<span class="lw16-mobile__status" id="lw16-status" hidden></span>
	</header>

	<nav class="lw16-mobile__tabs">
		<button type="button" class="is-active" data-tab="druck"><?php esc_html_e( 'Druckjob', 'lindenweg16' ); ?></button>
		<button type="button" data-tab="zeit"><?php esc_html_e( 'Zeit', 'lindenweg16' ); ?></button>
	</nav>

	<main>
		<form id="lw16-form-druck" class="lw16-mobile__form" data-panel="druck">
			<label class="lw16-field">
				<span><?php esc_html_e( 'Drucker', 'lindenweg16' ); ?></span>
				<select name="printer_id" id="lw16-printer" required></select>
			</label>

			<label class="lw16-field">
				<span><?php esc_html_e( 'Artikel', 'lindenweg16' ); ?></span>
				<select name="article_id" id="lw16-article" required></select>
			</label>

			<div class="lw16-field lw16-stepper">
				<span><?php esc_html_e( 'Teile in Ordnung', 'lindenweg16' ); ?></span>
				<div>
					<button type="button" class="lw16-step" data-step="-1" data-target="parts_ok">−</button>
					<input type="number" inputmode="numeric" name="parts_ok" id="lw16-parts_ok" value="0" min="0" step="1" />
					<button type="button" class="lw16-step" data-step="1" data-target="parts_ok">+</button>
				</div>
			</div>

			<div class="lw16-field lw16-stepper">
				<span><?php esc_html_e( 'Plattenwechsel', 'lindenweg16' ); ?></span>
				<div>
					<button type="button" class="lw16-step" data-step="-1" data-target="plate_changes">−</button>
					<input type="number" inputmode="numeric" name="plate_changes" id="lw16-plate_changes" value="1" min="0" step="1" />
					<button type="button" class="lw16-step" data-step="1" data-target="plate_changes">+</button>
				</div>
			</div>

			<label class="lw16-field">
				<span><?php esc_html_e( 'Fehlerursache', 'lindenweg16' ); ?></span>
				<select name="failure_reason" id="lw16-failure">
					<?php foreach ( PrintJobRepository::failure_labels() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<details class="lw16-more">
				<summary><?php esc_html_e( 'Vorbelegung ändern', 'lindenweg16' ); ?></summary>

				<label class="lw16-field">
					<span><?php esc_html_e( 'Teile geplant', 'lindenweg16' ); ?></span>
					<input type="number" inputmode="numeric" name="parts_planned" id="lw16-parts_planned" min="0" step="1" />
				</label>

				<label class="lw16-field">
					<span><?php esc_html_e( 'Druckzeit (h)', 'lindenweg16' ); ?></span>
					<input type="text" inputmode="decimal" name="print_time_h" id="lw16-print_time_h" />
				</label>

				<label class="lw16-field">
					<span><?php esc_html_e( 'Farbe', 'lindenweg16' ); ?></span>
					<select name="color" id="lw16-color"></select>
				</label>

				<label class="lw16-field">
					<span><?php esc_html_e( 'Filamentcharge', 'lindenweg16' ); ?></span>
					<select name="filament_lot_id" id="lw16-lot"></select>
				</label>
			</details>

			<button type="submit" class="lw16-submit"><?php esc_html_e( 'Buchen', 'lindenweg16' ); ?></button>
		</form>

		<form id="lw16-form-zeit" class="lw16-mobile__form" data-panel="zeit" hidden>
			<label class="lw16-field">
				<span><?php esc_html_e( 'Kategorie', 'lindenweg16' ); ?></span>
				<select name="category" id="lw16-category"></select>
			</label>

			<div class="lw16-field lw16-stepper">
				<span><?php esc_html_e( 'Stunden', 'lindenweg16' ); ?></span>
				<div>
					<button type="button" class="lw16-step" data-step="-0.5" data-target="hours">−</button>
					<input type="text" inputmode="decimal" name="hours" id="lw16-hours" value="1" />
					<button type="button" class="lw16-step" data-step="0.5" data-target="hours">+</button>
				</div>
			</div>

			<label class="lw16-field">
				<span><?php esc_html_e( 'Was hast du gemacht?', 'lindenweg16' ); ?></span>
				<input type="text" name="description" id="lw16-description" />
			</label>

			<button type="submit" class="lw16-submit"><?php esc_html_e( 'Buchen', 'lindenweg16' ); ?></button>
		</form>

		<section class="lw16-queue" id="lw16-queue" hidden>
			<h2><?php esc_html_e( 'Wartet auf Verbindung', 'lindenweg16' ); ?></h2>
			<ul id="lw16-queue-list"></ul>
		</section>
	</main>

	<script>
		window.LW16Erfassung = <?php
			echo wp_json_encode(
				array(
					'root'        => esc_url_raw( rest_url( RestApi::NAMESPACE_V1 . '/' ) ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'printerCode' => $printer,
					'i18n'        => array(
						'saved'    => __( 'Gebucht.', 'lindenweg16' ),
						'queued'   => __( 'Offline – wird beim nächsten Mal gesendet.', 'lindenweg16' ),
						'sending'  => __( 'Sende …', 'lindenweg16' ),
						'sent'     => __( 'Nachgesendet.', 'lindenweg16' ),
						'error'    => __( 'Fehler:', 'lindenweg16' ),
						'noRevision' => __( 'Für diesen Artikel ist keine Revision freigegeben.', 'lindenweg16' ),
					),
				)
			);
		?>;
	</script>
	<script src="<?php echo esc_url( LW16_PLUGIN_URL . 'assets/js/betrieb-mobile.js?v=' . LW16_VERSION ); ?>"></script>
</body>
</html>
		<?php
	}
}
