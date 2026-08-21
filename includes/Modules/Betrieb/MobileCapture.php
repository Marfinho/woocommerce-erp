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

use Lindenweg16\Core\Assets;

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
	 * Version und Stand der ausgelieferten Dateien.
	 *
	 * Beantwortet die eine Frage, die man sich beim Suchen nach einem
	 * Fehler zuerst stellt: Sieht das Geraet ueberhaupt die neue Fassung,
	 * oder haengt noch die alte im Zwischenspeicher?
	 *
	 * @return string
	 */
	protected static function build_label() {
		$stamp = Assets::version( 'assets/js/betrieb-mobile.js' );

		if ( ctype_digit( (string) $stamp ) ) {
			return sprintf(
				/* translators: 1: Plugin-Version, 2: Datum der Skriptdatei. */
				__( 'Version %1$s · Stand %2$s', 'lindenweg16' ),
				LW16_VERSION,
				gmdate( 'd.m.Y H:i', (int) $stamp )
			);
		}

		return sprintf(
			/* translators: %s: Plugin-Version. */
			__( 'Version %s', 'lindenweg16' ),
			LW16_VERSION
		);
	}

	/**
	 * Erfassungsseite ausgeben.
	 */
	protected function render() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$printer = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification
		$pick = isset( $_GET['pick'] ) ? sanitize_text_field( wp_unslash( $_GET['pick'] ) ) : '';

		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php esc_html_e( 'Erfassung · Betrieb', 'lindenweg16' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( Assets::url( 'assets/css/betrieb-mobile.css' ) ); ?>" />
</head>
<body class="lw16-mobile">
	<header class="lw16-mobile__head">
		<h1><?php esc_html_e( 'Erfassung', 'lindenweg16' ); ?></h1>
		<span class="lw16-mobile__status" id="lw16-status" hidden></span>
	</header>

	<nav class="lw16-mobile__tabs">
		<button type="button" class="is-active" data-tab="druck"><?php esc_html_e( 'Druckjob', 'lindenweg16' ); ?></button>
		<button type="button" data-tab="lager"><?php esc_html_e( 'Lager', 'lindenweg16' ); ?></button>
		<button type="button" data-tab="picken"><?php esc_html_e( 'Picken', 'lindenweg16' ); ?></button>
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

		<form id="lw16-form-lager" class="lw16-mobile__form" data-panel="lager" hidden>
			<div class="lw16-scanbox">
				<button type="button" class="lw16-scan" data-scan="lager"><?php esc_html_e( 'Scannen', 'lindenweg16' ); ?></button>
				<span class="lw16-slot" id="lw16-slot-bin"><?php esc_html_e( 'kein Platz', 'lindenweg16' ); ?></span>
			</div>
			<div class="lw16-scanbox">
				<button type="button" class="lw16-scan lw16-scan--ghost" data-scan="lager"><?php esc_html_e( 'Scannen', 'lindenweg16' ); ?></button>
				<span class="lw16-slot" id="lw16-slot-article"><?php esc_html_e( 'kein Artikel', 'lindenweg16' ); ?></span>
			</div>

			<label class="lw16-field">
				<span><?php esc_html_e( 'Code eintippen', 'lindenweg16' ); ?></span>
				<input type="text" id="lw16-manual" class="lw16-manual" inputmode="text" autocapitalize="characters" autocomplete="off" placeholder="<?php esc_attr_e( 'Code und Enter', 'lindenweg16' ); ?>" />
			</label>

			<div class="lw16-stock" id="lw16-stock" hidden></div>

			<label class="lw16-field">
				<span><?php esc_html_e( 'Vorgang', 'lindenweg16' ); ?></span>
				<select name="movement_type" id="lw16-movetype"></select>
			</label>

			<label class="lw16-field">
				<span><?php esc_html_e( 'Farbe', 'lindenweg16' ); ?></span>
				<select name="color" id="lw16-lager-color"></select>
			</label>

			<div class="lw16-field lw16-stepper">
				<span><?php esc_html_e( 'Menge', 'lindenweg16' ); ?></span>
				<div>
					<button type="button" class="lw16-step" data-step="-1" data-target="lager-qty">&minus;</button>
					<input type="text" inputmode="decimal" id="lw16-lager-qty" value="1" />
					<button type="button" class="lw16-step" data-step="1" data-target="lager-qty">+</button>
				</div>
			</div>

			<label class="lw16-field" id="lw16-tobin-field" hidden>
				<span><?php esc_html_e( 'Zielplatz', 'lindenweg16' ); ?></span>
				<select id="lw16-tobin"></select>
			</label>

			<button type="submit" class="lw16-submit"><?php esc_html_e( 'Buchen', 'lindenweg16' ); ?></button>
		</form>

		<section class="lw16-mobile__form" data-panel="picken" hidden>
			<div class="lw16-scanbox">
				<button type="button" class="lw16-scan" data-scan="pick"><?php esc_html_e( 'Position scannen', 'lindenweg16' ); ?></button>
				<button type="button" class="lw16-scan lw16-scan--ghost" id="lw16-pick-reload"><?php esc_html_e( 'Neu laden', 'lindenweg16' ); ?></button>
			</div>

			<label class="lw16-field">
				<span><?php esc_html_e( 'Liste', 'lindenweg16' ); ?></span>
				<select id="lw16-picklist-select"></select>
			</label>

			<label class="lw16-field">
				<span><?php esc_html_e( 'Code eintippen', 'lindenweg16' ); ?></span>
				<input type="text" id="lw16-pick-manual" class="lw16-manual" inputmode="text" autocapitalize="characters" autocomplete="off" placeholder="<?php esc_attr_e( 'Artikelnummer und Enter', 'lindenweg16' ); ?>" />
			</label>

			<div id="lw16-picklists"></div>
		</section>

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

		<p class="lw16-build"><?php echo esc_html( self::build_label() ); ?></p>

		<section class="lw16-queue" id="lw16-queue" hidden>
			<h2><?php esc_html_e( 'Wartet auf Verbindung', 'lindenweg16' ); ?></h2>
			<ul id="lw16-queue-list"></ul>
		</section>
	</main>

	<div class="lw16-camera" id="lw16-camera" hidden>
		<video id="lw16-video" playsinline muted></video>
		<button type="button" class="lw16-camera__close" id="lw16-camera-close"><?php esc_html_e( 'Abbrechen', 'lindenweg16' ); ?></button>
		<p class="lw16-camera__hint" id="lw16-camera-hint"></p>
	</div>

	<script>
		window.LW16Erfassung = <?php
			echo wp_json_encode(
				array(
					'root'        => esc_url_raw( rest_url( RestApi::NAMESPACE_V1 . '/' ) ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'printerCode' => $printer,
					'pickCode'    => $pick,
					// Wird nur geladen, wenn der Browser keinen eigenen
					// Code-Leser mitbringt - also auf iPhone und iPad.
					'decoderUrl'  => Assets::url( 'assets/vendor/jsqr/jsQR.js' ),
					'movementTypes' => StockLedger::movement_labels(),
					'colors'        => ColorRegistry::options(),
					'bins'          => array_map(
						static function ( $bin ) {
							return array(
								'id'    => (int) $bin['id'],
								'code'  => (string) $bin['code'],
								'label' => trim( $bin['code'] . ' ' . (string) $bin['name'] ),
							);
						},
						BinRepository::all( true )
					),
					'i18n'        => array(
						'saved'    => __( 'Gebucht.', 'lindenweg16' ),
						'queued'   => __( 'Offline – wird beim nächsten Mal gesendet.', 'lindenweg16' ),
						'sending'  => __( 'Sende …', 'lindenweg16' ),
						'sent'     => __( 'Nachgesendet.', 'lindenweg16' ),
						'error'    => __( 'Fehler:', 'lindenweg16' ),
						'noRevision' => __( 'Für diesen Artikel ist keine Revision freigegeben.', 'lindenweg16' ),
						'scanHint'   => __( 'Code vor die Kamera halten', 'lindenweg16' ),
						'noCamera'   => __( 'Kein Kamerazugriff. Code bitte eintippen.', 'lindenweg16' ),
						'noScanner'  => __( 'Scannen geht hier nicht. Code bitte eintippen.', 'lindenweg16' ),
						'noSecure'   => __( 'Die Kamera gibt es nur über eine https-Adresse. Diese Seite läuft über http – Code bitte eintippen.', 'lindenweg16' ),
						'noCameraApi' => __( 'Dieser Browser gibt keinen Kamerazugriff frei. Code bitte eintippen.', 'lindenweg16' ),
						'noDecoderLoad' => __( 'Der Code-Leser konnte nicht geladen werden. Code bitte eintippen.', 'lindenweg16' ),
						'notFound'   => __( 'Unbekannter Code:', 'lindenweg16' ),
						'needBoth'   => __( 'Erst Lagerplatz und Artikel scannen.', 'lindenweg16' ),
						'noPicklist' => __( 'Keine offene Pickliste.', 'lindenweg16' ),
						'pickGone'   => __( 'Diese Pickliste ist nicht mehr offen – vermutlich schon abgearbeitet.', 'lindenweg16' ),
						'allLists'   => __( 'Alle Listen', 'lindenweg16' ),
						'fromOrders' => __( 'Bestellungen', 'lindenweg16' ),
						'fromWork'   => __( 'Fertigung', 'lindenweg16' ),
						'openCount'  => __( '%d offen', 'lindenweg16' ),
						'listDone'   => __( 'fertig', 'lindenweg16' ),
						'picked'     => __( 'Entnommen.', 'lindenweg16' ),
						'noBin'      => __( 'kein Platz', 'lindenweg16' ),
						'noArticle'  => __( 'kein Artikel', 'lindenweg16' ),
						'inStock'    => __( 'Bestand', 'lindenweg16' ),
						'noStock'    => __( 'Kein Bestand gebucht.', 'lindenweg16' ),
						'unplaced'   => __( 'ohne Platz', 'lindenweg16' ),
					),
				)
			);
		?>;
	</script>
	<script src="<?php echo esc_url( Assets::url( 'assets/js/betrieb-mobile.js' ) ); ?>"></script>
</body>
</html>
		<?php
	}
}
