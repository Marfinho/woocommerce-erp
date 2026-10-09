<?php
/**
 * Die AR-Seite: Shortcode `[l16_ar_ansicht]`.
 *
 * Der Shortcode liest `?produkt=ID`, prüft Produkt und Modell und zeigt
 * Googles `<model-viewer>` mit Platzierung an der Wand in Originalgröße
 * (`ar-scale="fixed"`). Die Bibliothek liegt lokal im Plugin; es gibt
 * keinen CDN-Aufruf und damit keine IP-Übertragung an Dritte.
 *
 * AR lässt sich nur durch eine Nutzergeste starten, deshalb hat die Seite
 * einen eigenen, großen Knopf im Slot `ar-button`.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\ArView;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;
use Lindenweg16\Modules\Betrieb\QrCode;

/**
 * Shortcode, Skripte und Ausgabe.
 */
class Viewer {

	const SHORTCODE = 'l16_ar_ansicht';
	const HANDLE_JS = 'lw16-ar-viewer';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_style' ) );
		add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 2 );
	}

	/**
	 * Enthält die aktuelle Seite den Shortcode?
	 *
	 * @return bool
	 */
	protected function page_has_shortcode() {
		$post = get_post();

		return $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * Stil schon im Kopf einbinden, damit die Seite nicht springt.
	 */
	public function maybe_enqueue_style() {
		if ( $this->page_has_shortcode() ) {
			$this->enqueue_style();
		}
	}

	/**
	 * Stil einbinden.
	 */
	protected function enqueue_style() {
		wp_enqueue_style(
			'lw16-ar-view',
			LW16_PLUGIN_URL . 'assets/css/ar-view.css',
			array(),
			Assets::version( 'assets/css/ar-view.css' )
		);
	}

	/**
	 * Das Skript einbinden – nur, wenn wirklich ein Viewer ausgegeben wird.
	 */
	protected function enqueue_script() {
		wp_enqueue_script(
			self::HANDLE_JS,
			LW16_PLUGIN_URL . 'assets/js/ar-viewer.js',
			array(),
			Assets::version( 'assets/js/ar-viewer.js' ),
			true
		);
	}

	/**
	 * Aus dem Skript-Tag ein ES-Modul machen.
	 *
	 * Nur der Tag mit `src` wird verändert. Davor oder danach hängende
	 * Inline-Skripte desselben Handles bleiben unberührt (sie sind eigene
	 * `<script>`-Tags ohne `src`).
	 *
	 * @param string $tag    HTML des Skript-Tags (gegebenenfalls mehrere Tags).
	 * @param string $handle Handle.
	 * @return string
	 */
	public function module_tag( $tag, $handle ) {
		return self::to_module_tag( (string) $tag, (string) $handle );
	}

	/**
	 * Reine Umschreibung, ohne WordPress testbar.
	 *
	 * @param string $tag    HTML.
	 * @param string $handle Handle.
	 * @return string
	 */
	public static function to_module_tag( $tag, $handle ) {
		if ( self::HANDLE_JS !== $handle ) {
			return $tag;
		}

		return preg_replace_callback(
			'/<script\b([^>]*\bsrc=[^>]*)>/i',
			static function ( $m ) {
				$attrs = preg_replace( '/\s+type=(["\'])[^"\']*\1/i', '', $m[1] );

				return '<script type="module"' . $attrs . '>';
			},
			$tag,
			1
		);
	}

	/**
	 * Darf der Besucher dieses Produkt auf der AR-Seite sehen?
	 *
	 * @param int $product_id Produkt.
	 * @return bool
	 */
	protected function product_visible( $product_id ) {
		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		if ( post_password_required( $product_id ) ) {
			return false;
		}

		return 'publish' === get_post_status( $product_id ) || current_user_can( 'edit_post', $product_id );
	}

	/**
	 * Gewünschtes Produkt aus der Adresse.
	 *
	 * @return array {id, asked} id = 0, wenn keines oder ungültig; asked = ob überhaupt ein Parameter da war.
	 */
	protected function requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Ansicht, jede Wirkung ist ein Lesezugriff.
		$raw = isset( $_GET['produkt'] ) ? sanitize_text_field( wp_unslash( $_GET['produkt'] ) ) : '';

		if ( '' === $raw ) {
			return array( 'id' => 0, 'asked' => false );
		}

		$id = ctype_digit( $raw ) ? (int) $raw : 0;

		if ( $id > 0 && $this->product_visible( $id ) && ProductMeta::has_model( $id ) ) {
			return array( 'id' => $id, 'asked' => true );
		}

		return array( 'id' => 0, 'asked' => true );
	}

	/**
	 * Shortcode ausgeben.
	 *
	 * @return string
	 */
	public function render() {
		$this->enqueue_style();

		if ( ! Options::enabled() ) {
			$extra = current_user_can( LW16_CAPABILITY ) ? ' ' . __( '(Hinweis für dich: Die Funktion ist unter Verwaltung → AR-Ansicht ausgeschaltet.)', 'lindenweg16' ) : '';

			return '<div class="lw16-arv lw16-arv--leer"><p>' . esc_html__( 'Die AR-Ansicht ist derzeit nicht verfügbar.', 'lindenweg16' ) . esc_html( $extra ) . '</p></div>';
		}

		$request = $this->requested();

		ob_start();

		if ( $request['id'] ) {
			$this->enqueue_script();
			$this->render_viewer( $request['id'] );
		} else {
			$this->render_picker( $request['asked'] );
		}

		return (string) ob_get_clean();
	}

	/**
	 * Auswahl aller Produkte mit Modell.
	 *
	 * @param bool $invalid Ob eine ungültige Produkt-ID mitgekommen ist.
	 */
	protected function render_picker( $invalid ) {
		$ids = ProductMeta::products_with_model();
		?>
		<div class="lw16-arv lw16-arv--auswahl">
			<?php if ( $invalid ) : ?>
				<p class="lw16-arv__hinweis" role="status"><?php esc_html_e( 'Zu diesem Link gibt es kein Produkt mit 3D-Modell. Such dir hier eines aus:', 'lindenweg16' ); ?></p>
			<?php else : ?>
				<p class="lw16-arv__hinweis"><?php esc_html_e( 'Wähle ein Produkt und sieh es dir in Originalgröße an deiner Wand an:', 'lindenweg16' ); ?></p>
			<?php endif; ?>

			<?php if ( ! $ids ) : ?>
				<p><?php esc_html_e( 'Aktuell ist noch kein Produkt mit 3D-Modell hinterlegt.', 'lindenweg16' ); ?></p>
			<?php else : ?>
				<ul class="lw16-arv__liste">
					<?php foreach ( $ids as $id ) : ?>
						<?php $thumb = get_the_post_thumbnail_url( $id, 'medium' ); ?>
						<li>
							<a class="lw16-arv__karte" href="<?php echo esc_url( Options::viewer_url( $id ) ?: add_query_arg( 'produkt', $id ) ); ?>">
								<?php if ( $thumb ) : ?>
									<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy" width="120" height="120">
								<?php endif; ?>
								<span><?php echo esc_html( get_the_title( $id ) ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * QR-Code als Schwarz-Weiß-SVG; leer, wenn die Adresse nicht passt.
	 *
	 * @param string $url Adresse.
	 * @return string
	 */
	protected function qr( $url ) {
		try {
			return QrCode::svg( $url, 160, 'L' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Erlaubtes Markup für das QR-SVG.
	 *
	 * @return array
	 */
	protected function svg_kses() {
		return array(
			'svg'  => array(
				'xmlns'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true,
				'shape-rendering' => true,
				'role'            => true,
				'aria-label'      => true,
			),
			'rect' => array(
				'width'  => true,
				'height' => true,
				'fill'   => true,
			),
			'path' => array(
				'd'    => true,
				'fill' => true,
			),
		);
	}

	/**
	 * Viewer, Anleitung und Produktzeile.
	 *
	 * @param int $product_id Produkt.
	 */
	protected function render_viewer( $product_id ) {
		$data    = ProductMeta::get( $product_id );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$name    = get_the_title( $product_id );
		$page    = Options::viewer_url( $product_id );
		$page    = '' !== $page ? $page : add_query_arg( 'produkt', $product_id );
		$qr      = $this->qr( $page );
		$back    = get_permalink( $product_id );

		$cart_url   = '';
		$cart_label = '';

		if ( $product ) {
			$cart_url   = $product->add_to_cart_url();
			$cart_label = ( $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() )
				? __( 'In den Warenkorb', 'lindenweg16' )
				: $product->add_to_cart_text();
		}
		?>
		<div class="lw16-arv" data-lw16-arv data-product="<?php echo esc_attr( $product_id ); ?>" data-rest="<?php echo esc_url( rest_url( RestApi::REST_NS . '/' ) ); ?>" data-vendor="<?php echo esc_url( LW16_PLUGIN_URL . 'assets/vendor/model-viewer/' ); ?>">
			<div class="lw16-arv__buehne">
				<model-viewer
					src="<?php echo esc_url( $data['glb'] ); ?>"
					<?php if ( $data['usdz'] ) : ?>
						ios-src="<?php echo esc_url( $data['usdz'] ); ?>"
					<?php endif; ?>
					<?php if ( $data['poster'] ) : ?>
						poster="<?php echo esc_url( $data['poster'] ); ?>"
					<?php endif; ?>
					alt="<?php echo esc_attr( sprintf( /* translators: %s: Produktname. */ __( '3D-Modell: %s', 'lindenweg16' ), $name ) ); ?>"
					ar
					ar-modes="webxr scene-viewer quick-look"
					ar-placement="wall"
					ar-scale="fixed"
					camera-controls
					touch-action="pan-y"
					shadow-intensity="1">
					<button slot="ar-button" type="button" class="lw16-arv__start" data-lw16-arv-start>
						<?php esc_html_e( 'An meiner Wand platzieren', 'lindenweg16' ); ?>
					</button>
				</model-viewer>
				<p class="lw16-arv__fehler" role="alert" data-lw16-arv-fehler hidden><?php esc_html_e( 'Das 3D-Modell konnte nicht geladen werden. Bitte versuche es später noch einmal.', 'lindenweg16' ); ?></p>
			</div>

			<div class="lw16-arv__desktop" data-lw16-arv-desktop hidden>
				<div>
					<p class="lw16-arv__desktop-titel"><?php esc_html_e( 'Öffne diese Seite auf deinem Smartphone', 'lindenweg16' ); ?></p>
					<p><?php esc_html_e( 'Die Ansicht an deiner Wand funktioniert mit der Kamera eines iPhones oder eines Android-Handys. Hier kannst du das Modell drehen und ansehen.', 'lindenweg16' ); ?></p>
				</div>
				<?php if ( $qr ) : ?>
					<div class="lw16-arv__qr"><?php echo wp_kses( $qr, $this->svg_kses() ); ?></div>
				<?php endif; ?>
			</div>

			<div class="lw16-arv__anleitung">
				<p class="lw16-arv__ueberschrift"><?php esc_html_e( 'So geht’s', 'lindenweg16' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Tippe auf „An meiner Wand platzieren“ und erlaube der Seite die Kamera.', 'lindenweg16' ); ?></li>
					<li><?php esc_html_e( 'Richte das Handy auf die Wand und bewege es langsam hin und her.', 'lindenweg16' ); ?></li>
					<li><?php esc_html_e( 'Sorge für gute Beleuchtung – im Dunkeln erkennt das Handy die Wand nicht.', 'lindenweg16' ); ?></li>
				</ol>
			</div>

			<div class="lw16-arv__produkt">
				<div class="lw16-arv__produkt-info">
					<p class="lw16-arv__name"><?php echo esc_html( $name ); ?></p>
					<?php if ( $product ) : ?>
						<p class="lw16-arv__preis"><?php echo wp_kses_post( $product->get_price_html() ); ?></p>
					<?php endif; ?>
				</div>
				<div class="lw16-arv__aktionen">
					<?php if ( $cart_url ) : ?>
						<a class="lw16-arv__btn lw16-arv__btn--primary" href="<?php echo esc_url( $cart_url ); ?>"><?php echo esc_html( $cart_label ); ?></a>
					<?php endif; ?>
					<a class="lw16-arv__btn lw16-arv__btn--ghost" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Zurück zum Produkt', 'lindenweg16' ); ?></a>
				</div>
			</div>
		</div>
		<?php
	}
}
