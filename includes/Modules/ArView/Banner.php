<?php
/**
 * Hinweis-Banner „Passt der Haster Haken an deine Wand?" auf Produktseiten.
 *
 * Der Server gibt nur Markup und Daten aus, **versteckt**. Ob der Banner
 * erscheint, entscheidet allein das Skript im Browser (AR-fähiges
 * Mobilgerät, Sperre, Verzögerung). So bleibt die Seite cachebar: Jeder
 * Besucher bekommt dieselbe HTML-Datei, nichts darin hängt vom Gerät oder
 * von früheren Besuchen ab.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\ArView;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;

/**
 * Bindet Banner, Stil und Skript auf passenden Produktseiten ein.
 */
class Banner {

	/**
	 * Ergebnis der Prüfung für die aktuelle Seite.
	 *
	 * @var array|null
	 */
	protected $context = null;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render' ), 20 );
	}

	/**
	 * Soll auf dieser Seite ein Banner ausgegeben werden?
	 *
	 * Alle Bedingungen zusammen: Funktion an, Produktseite, Modell
	 * hinterlegt, Hinweis am Produkt aktiviert, AR-Seite gewählt und
	 * veröffentlicht. Fehlt eine, erscheint nichts.
	 *
	 * @return array|null {product_id, url} oder null.
	 */
	protected function context() {
		if ( null !== $this->context ) {
			return $this->context ? $this->context : null;
		}

		$this->context = array();

		if ( ! Options::enabled() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return null;
		}

		$product_id = (int) get_queried_object_id();
		$data       = $product_id ? ProductMeta::get( $product_id ) : null;

		if ( ! $data || '' === $data['glb'] || ! $data['hint'] ) {
			return null;
		}

		$url = Options::viewer_url( $product_id );

		if ( '' === $url ) {
			return null;
		}

		$this->context = array(
			'product_id' => $product_id,
			'url'        => $url,
		);

		return $this->context;
	}

	/**
	 * Stil und Skript – nur auf Seiten mit Banner.
	 */
	public function enqueue() {
		if ( ! $this->context() ) {
			return;
		}

		wp_enqueue_style(
			'lw16-ar-view',
			LW16_PLUGIN_URL . 'assets/css/ar-view.css',
			array(),
			Assets::version( 'assets/css/ar-view.css' )
		);

		wp_enqueue_script(
			'lw16-ar-hint',
			LW16_PLUGIN_URL . 'assets/js/ar-hint.js',
			array(),
			Assets::version( 'assets/js/ar-hint.js' ),
			true
		);
	}

	/**
	 * Markup ausgeben, versteckt.
	 */
	public function render() {
		$context = $this->context();

		if ( ! $context ) {
			return;
		}

		$o = Options::get();
		?>
		<div class="lw16-ar" data-lw16-ar-hint hidden role="dialog" aria-modal="false" aria-labelledby="lw16-ar-titel" aria-describedby="lw16-ar-text"
			data-ar-url="<?php echo esc_url( $context['url'] ); ?>"
			data-product="<?php echo esc_attr( $context['product_id'] ); ?>"
			data-delay="<?php echo esc_attr( $o['delay'] ); ?>"
			data-days="<?php echo esc_attr( $o['lock_days'] ); ?>"
			data-rest="<?php echo esc_url( rest_url( RestApi::REST_NS . '/' ) ); ?>">
			<button type="button" class="lw16-ar__x" data-lw16-ar-close aria-label="<?php esc_attr_e( 'Schließen', 'lindenweg16' ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
			</button>
			<p class="lw16-ar__title" id="lw16-ar-titel"><?php echo esc_html( $o['title'] ); ?></p>
			<p class="lw16-ar__text" id="lw16-ar-text"><?php echo esc_html( $o['text'] ); ?></p>
			<div class="lw16-ar__actions">
				<a class="lw16-ar__btn lw16-ar__btn--primary" href="<?php echo esc_url( $context['url'] ); ?>" data-lw16-ar-yes><?php echo esc_html( $o['btn_yes'] ); ?></a>
				<button type="button" class="lw16-ar__btn lw16-ar__btn--ghost" data-lw16-ar-no><?php echo esc_html( $o['btn_no'] ); ?></button>
			</div>
		</div>
		<?php
	}
}
