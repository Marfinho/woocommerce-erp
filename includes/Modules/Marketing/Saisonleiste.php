<?php
/**
 * Weihnachts-Leiste über dem Kopfbereich des Shops.
 *
 * Vom 1. November bis zum Stichtag: „Bestellt bis 14.12. – rechtzeitig unterm
 * Baum.“ Danach bis einschließlich 23. Dezember der Hinweis auf den Gutschein.
 * Ab dem 24. Dezember erscheint nichts mehr, im nächsten Jahr beginnt es von
 * vorn. Kunden können die Leiste schließen; das merkt sich ihr Browser je
 * Phase und Jahr. In der Kasse erscheint sie nie.
 *
 * Abschaltbar unter Einstellungen → Marketing („weihnacht_leiste“), Stichtag
 * als „TT.MM.“ in „weihnacht_stichtag“.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Marketing;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Saisonale Hinweisleiste.
 */
class Saisonleiste {

	/**
	 * Erster Tag der Leiste (MMTT).
	 */
	const START = 1101;

	/**
	 * Erster Tag ohne Leiste (MMTT).
	 */
	const ENDE = 1224;

	/**
	 * Stichtag, wenn keiner eingestellt ist.
	 */
	const STICHTAG = '14.12.';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'wp_body_open', array( $this, 'ausgeben' ), 5 );
	}

	/**
	 * Stichtag „TT.MM.“ als MMTT - reine Regel.
	 *
	 * @param string $stichtag Eingabe, z. B. „14.12.“ oder „14.12“.
	 * @return int MMTT, bei ungültiger Eingabe der Standard.
	 */
	public static function stichtag_mmtt( $stichtag ) {
		if ( is_string( $stichtag ) && preg_match( '/^\s*(\d{1,2})\.(\d{1,2})\.?\s*$/', $stichtag, $m ) ) {
			$tag   = (int) $m[1];
			$monat = (int) $m[2];
			$mmtt  = $monat * 100 + $tag;

			if ( checkdate( $monat, $tag, 2024 ) && $mmtt >= self::START && $mmtt < self::ENDE ) {
				return $mmtt;
			}
		}

		return self::stichtag_mmtt( self::STICHTAG );
	}

	/**
	 * Phase an einem Tag - reine Regel.
	 *
	 * @param int $heute    Heute als MMTT.
	 * @param int $stichtag Stichtag als MMTT.
	 * @return string „liefern“, „gutschein“ oder leer.
	 */
	public static function phase( $heute, $stichtag ) {
		$heute = (int) $heute;

		if ( $heute < self::START || $heute >= self::ENDE ) {
			return '';
		}

		return $heute <= (int) $stichtag ? 'liefern' : 'gutschein';
	}

	/**
	 * Stichtag lesbar, z. B. „14.12.“ - reine Regel.
	 *
	 * @param int $mmtt MMTT.
	 * @return string
	 */
	public static function datum( $mmtt ) {
		return sprintf( '%d.%d.', (int) $mmtt % 100, intdiv( (int) $mmtt, 100 ) );
	}

	/**
	 * Adresse des Gutscheins, sonst der Shop.
	 *
	 * @return string
	 */
	protected static function gutschein_url() {
		if ( function_exists( 'wc_get_products' ) ) {
			$ids = wc_get_products(
				array(
					'status'     => 'publish',
					'limit'      => 1,
					'return'     => 'ids',
					'meta_key'   => '_gc_is_voucher', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( ! empty( $ids ) ) {
				return (string) get_permalink( (int) $ids[0] );
			}
		}

		return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
	}

	/**
	 * Leiste ausgeben.
	 */
	public function ausgeben() {
		if ( is_admin() || ! (int) Settings::get( 'weihnacht_leiste', 1 ) ) {
			return;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return;
		}

		$stichtag = self::stichtag_mmtt( (string) Settings::get( 'weihnacht_stichtag', self::STICHTAG ) );
		$phase    = self::phase( (int) wp_date( 'md' ), $stichtag );

		if ( '' === $phase ) {
			return;
		}

		if ( 'liefern' === $phase ) {
			/* translators: %s: Stichtag, z. B. „14.12.“. */
			$text = sprintf( __( 'Weihnachten: Bestellt bis %s – rechtzeitig unterm Baum.', 'lindenweg16' ), self::datum( $stichtag ) );
			$link = __( 'Zum Shop', 'lindenweg16' );
			$url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		} else {
			$text = __( 'Zu knapp für die Post? Ein Gutschein passt immer.', 'lindenweg16' );
			$link = __( 'Gutschein wählen', 'lindenweg16' );
			$url  = self::gutschein_url();
		}

		$key = 'lw16-saison-' . $phase . '-' . wp_date( 'Y' );
		?>
		<div class="lw16-saison" id="lw16-saison" role="region" aria-label="<?php esc_attr_e( 'Hinweis', 'lindenweg16' ); ?>" data-key="<?php echo esc_attr( $key ); ?>">
			<script>try{if(localStorage.getItem(<?php echo wp_json_encode( $key ); ?>)){document.getElementById('lw16-saison').hidden=true;}}catch(e){}</script>
			<p class="lw16-saison__text"><?php echo esc_html( $text ); ?> <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $link ); ?></a></p>
			<button type="button" class="lw16-saison__zu" aria-label="<?php esc_attr_e( 'Hinweis schließen', 'lindenweg16' ); ?>" onclick="var b=this.parentNode;b.hidden=true;try{localStorage.setItem(b.getAttribute('data-key'),'1');}catch(e){}">&times;</button>
		</div>
		<style>
			.lw16-saison{display:flex;align-items:center;justify-content:center;gap:.75rem;padding:.5rem max(16px,env(safe-area-inset-right,0px)) .5rem max(16px,env(safe-area-inset-left,0px));background:var(--wp--preset--color--ink,#18171a);color:var(--wp--preset--color--on-ink,#fff);font-size:.875rem;line-height:1.4;text-align:center;position:relative;z-index:100}
			.lw16-saison[hidden]{display:none}
			.lw16-saison__text{margin:0}
			.lw16-saison a{color:inherit;font-weight:600;text-decoration:underline;text-underline-offset:3px;white-space:nowrap}
			.lw16-saison__zu{flex:none;background:none;border:0;color:inherit;font-size:1.25rem;line-height:1;width:2rem;height:2rem;border-radius:50%;cursor:pointer}
			.lw16-saison__zu:focus-visible,.lw16-saison a:focus-visible{outline:2px solid currentColor;outline-offset:2px}
		</style>
		<?php
	}
}
