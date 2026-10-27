<?php
/**
 * Der Konfigurator auf der Website.
 *
 * Eingebunden wird er über den Shortcode `[lw16_konfigurator]` – auf einer
 * eigenen Seite, in einem Block oder in der Produktbeschreibung.
 *
 * Zwei Dinge sind hier bewusst entschieden:
 *
 * 1. **Das Formular ist die Wahrheit, die Ansicht ist Zugabe.** Alles,
 *    was der Kunde im 3D-Bild tut, landet in denselben Formularfeldern,
 *    die auch ohne JavaScript ausgefüllt werden können. Wer kein WebGL
 *    hat – altes Gerät, abgeschalteter Hardwarezugriff, Screenreader –,
 *    bestellt denselben Haken über Auswahlfelder. Die Ansicht darf
 *    ausfallen, das Geschäft nicht.
 * 2. **Abgeschickt wird klassisch.** Der Warenkorb wird über einen
 *    normalen Formularversand befüllt, nicht über einen Hintergrundruf.
 *    Das übersteht Seiten-Caches, abgelaufene Nonces und jeden
 *    Browserverlauf – und der Kunde landet dort, wo er hinwill.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;
use Lindenweg16\Modules\Betrieb\ColorRegistry;

/**
 * Shortcode, Skripte und Formularversand.
 */
class Frontend {

	const SHORTCODE = 'lw16_konfigurator';
	const NONCE     = 'lw16_haken_warenkorb';
	const ACTION    = 'lw16_haken_warenkorb';

	/**
	 * Zeilen im Ersatzformular, wenn kein JavaScript läuft.
	 */
	const FALLBACK_ROWS = 8;

	/**
	 * Wurde der Shortcode auf dieser Seite gebraucht?
	 *
	 * @var bool
	 */
	protected $needed = false;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_head', array( $this, 'print_import_map' ), 1 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_submit' ) );
	}

	/**
	 * Braucht die aktuelle Seite den Konfigurator?
	 *
	 * @return bool
	 */
	protected function is_wanted() {
		if ( $this->needed ) {
			return true;
		}

		$post   = get_post();
		$wanted = $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE );

		/**
		 * Skripte des Konfigurators auf dieser Seite laden.
		 *
		 * Für Themes, die den Shortcode aus einer Vorlage heraus aufrufen –
		 * dort steht er nicht im Inhalt und wäre nicht zu finden.
		 *
		 * @param bool $wanted Bisherige Entscheidung.
		 */
		return (bool) apply_filters( 'lw16_konfigurator_assets_laden', $wanted );
	}

	/**
	 * Stil und Skript einbinden.
	 */
	public function enqueue() {
		if ( ! $this->is_wanted() ) {
			return;
		}

		wp_enqueue_style(
			'lw16-konfigurator',
			LW16_PLUGIN_URL . 'assets/css/configurator.css',
			array(),
			Assets::version( 'assets/css/configurator.css' )
		);

		/*
		 * Ein ganz gewöhnliches Skript, kein `type="module"`.
		 *
		 * Der erste Anlauf hat das Skript über den Filter
		 * `script_loader_tag` zum Modul umgeschrieben – und damit den
		 * halben Konfigurator abgeschaltet: Der Filter bekommt den Tag
		 * **samt** der davorgesetzten Datenzeile, und wer ihn durch
		 * eigenes Markup ersetzt, wirft die Daten weg. Der Katalog kam
		 * nie im Browser an, und die Ansicht blieb beim Ladehinweis
		 * stehen.
		 *
		 * Gebraucht wird das Modul auch gar nicht: Die Datei lädt three.js
		 * über `import()` nach, und das löst nackte Namen über die
		 * Zuordnung im Seitenkopf auf – aus einem klassischen Skript
		 * genauso wie aus einem Modul.
		 */
		wp_enqueue_script(
			'lw16-konfigurator',
			LW16_PLUGIN_URL . 'assets/js/configurator.js',
			array(),
			Assets::version( 'assets/js/configurator.js' ),
			true
		);

		wp_add_inline_script(
			'lw16-konfigurator',
			'window.LW16_HAKEN = ' . wp_json_encode( $this->script_data() ) . ';',
			'before'
		);
	}

	/**
	 * Daten für den Browser.
	 *
	 * @return array
	 */
	protected function script_data() {
		$catalog = Catalog::payload();
		$icons   = array();

		foreach ( $catalog['modules'] as $module ) {
			$icons[ $module['article'] ] = self::icon_for( $module );
		}

		return array(
			'katalog' => $catalog,
			'symbole' => $icons,
			'urls'    => array(
				'lage' => Assets::url( 'assets/js/konfigurator-lage.js' ),
			),
			'texte'   => array(
				'entfernen'    => __( 'Entfernen', 'lindenweg16' ),
				'kein_platz'   => __( 'Für dieses Modul ist kein Platz mehr frei.', 'lindenweg16' ),
				'gesperrt'     => __( 'Hier wird der Haken an die Wand geschraubt – dieser Bereich bleibt frei.', 'lindenweg16' ),
				'belegt'       => __( 'Dort sitzt schon ein Modul.', 'lindenweg16' ),
				'leer'         => __( 'Noch kein Modul gewählt. Das Basismodul allein ist auch eine gültige Bestellung.', 'lindenweg16' ),
				'kein_webgl'   => __( 'Dieses Gerät kann die räumliche Ansicht nicht darstellen. Die Auswahl unten funktioniert trotzdem vollständig.', 'lindenweg16' ),
				'laedt'        => __( 'Räumliche Ansicht wird geladen …', 'lindenweg16' ),
				'position'     => __( '%s mm von unten', 'lindenweg16' ),
				'kopiert'      => __( 'Link kopiert.', 'lindenweg16' ),
				'ansicht_neu'  => __( 'Ansicht zurückgesetzt.', 'lindenweg16' ),
				'nicht_lieferbar'       => __( 'Nicht lieferbar', 'lindenweg16' ),
				'nicht_lieferbar_liste' => __( 'Gerade nicht lieferbar: %s. Nimm es heraus oder wähle eine andere Farbe oder Länge.', 'lindenweg16' ),
				'modul_nicht_lieferbar' => __( 'Dieses Modul ist gerade nicht lieferbar.', 'lindenweg16' ),
			),
			'grenzen' => array(
				'max_module' => Layout::MAX_MODULES,
				'max_menge'  => Layout::MAX_QTY,
				'zeilen'     => self::FALLBACK_ROWS,
			),
		);
	}

	/**
	 * Welches Symbol gehört zu einem Modul?
	 *
	 * Die Zuordnung folgt dem Namen: Wer „Ablage“ heißt, bekommt die Ablage,
	 * wer „Leiste“ heißt, die Leiste, „Haken“ den Haken. Alles andere
	 * (Brillenhalter, Schlüsselbrett …) bekommt ein neutrales Bausteinsymbol –
	 * ein falsches Bild wäre schlimmer als ein allgemeines.
	 *
	 * @param array $module Katalogeintrag.
	 * @return string haken|ablage|leiste|modul
	 */
	public static function icon_for( array $module ) {
		$text = strtolower( ( isset( $module['name'] ) ? $module['name'] : '' ) . ' ' . ( isset( $module['sku'] ) ? $module['sku'] : '' ) );

		if ( preg_match( '/ablage|brett|regal|tablett|schale/', $text ) ) {
			return 'ablage';
		}

		if ( preg_match( '/leiste|schl(ü|ue)ssel/u', $text ) ) {
			return 'leiste';
		}

		if ( preg_match( '/haken|garderobe|bügel|buegel/u', $text ) ) {
			return 'haken';
		}

		return 'modul';
	}

	/**
	 * Symbolsammlung für Modulkarten und -zeilen, einmal pro Formular.
	 *
	 * @return string
	 */
	protected function icon_sprite() {
		$svg  = '<svg class="lw16-haken__symbole" width="0" height="0" aria-hidden="true" focusable="false"><defs>';
		$svg .= '<symbol id="lw16-sym-haken" viewBox="0 0 32 32"><path d="M10 5h12M16 5v14a5 5 0 0 0 10 0v-5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></symbol>';
		$svg .= '<symbol id="lw16-sym-ablage" viewBox="0 0 32 32"><rect x="3" y="12" width="26" height="9" rx="3" fill="currentColor"/><rect x="3" y="12" width="26" height="3" rx="1.5" fill="#fff" opacity=".35"/></symbol>';
		$svg .= '<symbol id="lw16-sym-leiste" viewBox="0 0 32 32"><rect x="3" y="5" width="26" height="9" rx="4" fill="currentColor"/><path d="M8 14v8a2.5 2.5 0 0 0 5 0v-3M19 14v8a2.5 2.5 0 0 0 5 0v-3" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></symbol>';
		$svg .= '<symbol id="lw16-sym-modul" viewBox="0 0 32 32"><rect x="5" y="5" width="22" height="22" rx="6" fill="none" stroke="currentColor" stroke-width="2.4"/><path d="M16 11v10M11 16h10" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></symbol>';
		$svg .= '</defs></svg>';

		return $svg;
	}

	/**
	 * Die Zuordnung für nackte Modulnamen ausgeben.
	 *
	 * Der Modellader von three.js importiert `three` unter diesem Namen.
	 * Ohne die Zuordnung wüsste der Browser nicht, welche Datei gemeint
	 * ist – sie muss vor dem Skript stehen, deshalb im Kopf der Seite.
	 */
	public function print_import_map() {
		if ( ! $this->is_wanted() ) {
			return;
		}

		$map = array(
			'imports' => array(
				'three'          => Assets::url( 'assets/vendor/three/three.module.min.js' ),
				'three/addons/'  => LW16_PLUGIN_URL . 'assets/vendor/three/jsm/',
			),
		);

		echo '<script type="importmap">' . wp_json_encode( $map ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode escapt bereits.
	}

	/**
	 * Den Konfigurator ausgeben.
	 *
	 * @param array $atts Attribute des Shortcodes.
	 * @return string
	 */
	public function render( $atts ) {
		$this->needed = true;

		$atts = shortcode_atts(
			array(
				'basis' => '',
			),
			$atts,
			self::SHORTCODE
		);

		$catalog = Catalog::payload();

		if ( ! $catalog['bases'] ) {
			return '<div class="lw16-haken lw16-haken--leer"><p>' . esc_html__( 'Der Konfigurator ist noch nicht eingerichtet: Es fehlt ein Basismodul.', 'lindenweg16' ) . '</p></div>';
		}

		$config = $this->config_from_request( $catalog, (string) $atts['basis'] );

		ob_start();
		$this->render_form( $catalog, $config );

		return (string) ob_get_clean();
	}

	/**
	 * Vorbelegung: aus dem geteilten Link, sonst aus dem Shortcode.
	 *
	 * @param array  $catalog Katalog.
	 * @param string $sku     Gewünschtes Basismodul aus dem Shortcode.
	 * @return array
	 */
	protected function config_from_request( array $catalog, $sku ) {
		$default = array(
			'base'    => (int) $catalog['bases'][0]['article'],
			'color'   => (string) $catalog['default_color'],
			'qty'     => 1,
			'modules' => array(),
		);

		if ( '' !== $sku ) {
			foreach ( $catalog['bases'] as $base ) {
				if ( strcasecmp( $base['sku'], $sku ) === 0 ) {
					$default['base'] = (int) $base['article'];
					break;
				}
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur Vorbelegung, jede Wirkung entsteht erst beim Absenden.
		$code = isset( $_GET[ ConfigCodec::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ ConfigCodec::QUERY_VAR ] ) ) : '';

		if ( '' === $code ) {
			return $default;
		}

		$shared = ConfigCodec::decode( $code );

		if ( ! $shared ) {
			return $default;
		}

		$shared['qty'] = 1;

		if ( '' === (string) $shared['color'] ) {
			$shared['color'] = (string) $catalog['default_color'];
		}

		// Ein geteilter Link darf veraltet sein. Was der Katalog nicht mehr
		// kennt, fällt still heraus – ein halb aufgebauter Haken ist immer
		// noch besser als eine Fehlerseite.
		$known                = $this->known_articles( $catalog );
		$shared['modules']    = array_values(
			array_filter(
				$shared['modules'],
				static function ( $module ) use ( $known ) {
					return in_array( (int) $module['article'], $known['modules'], true );
				}
			)
		);

		if ( ! in_array( (int) $shared['base'], $known['bases'], true ) ) {
			return $default;
		}

		return $shared;
	}

	/**
	 * Welche Artikel bietet der Katalog gerade an?
	 *
	 * @param array $catalog Katalog.
	 * @return array {bases, modules}
	 */
	protected function known_articles( array $catalog ) {
		return array(
			'bases'   => wp_list_pluck( $catalog['bases'], 'article' ),
			'modules' => wp_list_pluck( $catalog['modules'], 'article' ),
		);
	}

	/**
	 * Formular samt Ansichtsfläche ausgeben.
	 *
	 * @param array $catalog Katalog.
	 * @param array $config  Vorbelegung.
	 */
	protected function render_form( array $catalog, array $config ) {
		$colors  = $catalog['colors'];
		$bases   = $catalog['bases'];
		$code    = ConfigCodec::encode( $config );
		$max_len = max( 1, (float) max( wp_list_pluck( $bases, 'height' ) ) );

		// Länge und Farbe sind nur dann eine Wahl, wenn es mehr als eine gibt.
		// Mit nur einer Länge oder Farbe stehen sie als feste Angabe da und
		// werden still mitgeschickt.
		$many_bases  = count( $bases ) > 1;
		$many_colors = count( $colors ) > 1;
		$fixed       = array();

		if ( ! $many_bases ) {
			$fixed[] = array(
				'label' => __( 'Länge', 'lindenweg16' ),
				'value' => Layout::format_mm( $bases[0]['height'] ) . ' mm',
				'hex'   => '',
			);
		}

		if ( ! $many_colors ) {
			$only    = $colors ? $colors[0] : array(
				'name' => '',
				'hex'  => '',
			);
			$fixed[] = array(
				'label' => __( 'Farbe', 'lindenweg16' ),
				'value' => (string) $only['name'],
				'hex'   => (string) $only['hex'],
			);
		}
		?>
		<div class="lw16-haken-huelle">
		<div class="lw16-haken" data-lw16-haken>
			<div class="lw16-haken__buehne">
				<div class="lw16-haken__kopf">
					<span class="lw16-haken__chip" data-lw16-chip></span>
					<div class="lw16-haken__werkzeuge">
						<button type="button" class="lw16-haken__knopf" data-lw16-ansicht-zuruecksetzen hidden>
							<?php esc_html_e( 'Ansicht zurücksetzen', 'lindenweg16' ); ?>
						</button>
						<button type="button" class="lw16-haken__knopf" data-lw16-teilen hidden>
							<?php esc_html_e( 'Link zu dieser Konfiguration kopieren', 'lindenweg16' ); ?>
						</button>
					</div>
				</div>
				<div class="lw16-haken__ansicht" data-lw16-ansicht>
					<p class="lw16-haken__hinweis" data-lw16-ansicht-hinweis>
						<?php
						// Der Ausgangstext gilt für den Fall, dass gar nichts
						// läuft. Sobald das Skript anläuft, ersetzt es ihn –
						// ein „wird geladen", das ewig stehen bleibt, wäre
						// eine Lüge über den Zustand der Seite.
						esc_html_e( 'Die räumliche Ansicht braucht JavaScript. Die Auswahl unten funktioniert auch ohne sie vollständig.', 'lindenweg16' );
						?>
					</p>
				</div>
				<p class="lw16-haken__meldung" role="status" data-lw16-meldung></p>
			</div>

			<form class="lw16-haken__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-lw16-form>
				<?php echo $this->icon_sprite(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- feste Zeichenkette. ?>
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="rueckkehr" value="<?php echo esc_attr( $this->current_url() ); ?>">
				<input type="hidden" name="code" value="<?php echo esc_attr( $code ); ?>" data-lw16-code>

				<?php if ( $fixed ) : ?>
					<ul class="lw16-haken__fest">
						<?php foreach ( $fixed as $item ) : ?>
							<li>
								<span class="lw16-haken__festname"><?php echo esc_html( $item['label'] ); ?></span>
								<?php if ( '' !== $item['hex'] ) : ?>
									<span class="lw16-haken__punkt" style="--punkt: <?php echo esc_attr( $item['hex'] ); ?>"></span>
								<?php endif; ?>
								<strong><?php echo esc_html( $item['value'] ); ?></strong>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $many_bases ) : ?>
					<section class="lw16-haken__block">
						<h3 class="lw16-haken__titel"><?php esc_html_e( 'Länge', 'lindenweg16' ); ?></h3>
						<div class="lw16-haken__optionen" role="radiogroup" aria-label="<?php esc_attr_e( 'Basismodul', 'lindenweg16' ); ?>">
							<?php foreach ( $bases as $base ) : ?>
								<label class="lw16-haken__option">
									<input type="radio" name="basis" value="<?php echo esc_attr( $base['article'] ); ?>" data-lw16-basis <?php checked( (int) $base['article'], (int) $config['base'] ); ?>>
									<span class="lw16-haken__optionskarte">
										<strong><?php echo esc_html( Layout::format_mm( $base['height'] ) ); ?> mm</strong>
										<span><?php echo esc_html( $base['name'] ); ?></span>
										<em class="lw16-haken__status" data-lw16-basis-status="<?php echo esc_attr( $base['article'] ); ?>"></em>
										<i style="--laenge: <?php echo esc_attr( round( 100 * (float) $base['height'] / $max_len ) ); ?>"></i>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					</section>
				<?php else : ?>
					<input type="hidden" name="basis" value="<?php echo esc_attr( $bases[0]['article'] ); ?>" data-lw16-basis>
				<?php endif; ?>

				<?php if ( $many_colors ) : ?>
					<section class="lw16-haken__block">
						<h3 class="lw16-haken__titel"><?php esc_html_e( 'Farbe', 'lindenweg16' ); ?></h3>
						<div class="lw16-haken__farben" role="radiogroup" aria-label="<?php esc_attr_e( 'Farbe', 'lindenweg16' ); ?>">
							<?php foreach ( $colors as $color ) : ?>
								<label class="lw16-haken__farbe">
									<input type="radio" name="farbe" value="<?php echo esc_attr( $color['slug'] ); ?>" data-lw16-farbe <?php checked( $color['slug'], $config['color'] ); ?>>
									<span class="lw16-haken__punkt" style="--punkt: <?php echo esc_attr( $color['hex'] ); ?>"></span>
									<span class="lw16-haken__farbname"><?php echo esc_html( $color['name'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</section>
				<?php else : ?>
					<input type="hidden" name="farbe" value="<?php echo esc_attr( $config['color'] ); ?>" data-lw16-farbe>
				<?php endif; ?>

				<section class="lw16-haken__block">
					<h3 class="lw16-haken__titel"><?php esc_html_e( 'Module', 'lindenweg16' ); ?></h3>
					<p class="lw16-haken__erklaerung">
						<?php esc_html_e( 'Module lassen sich am Basismodul frei in der Höhe verschieben – im Bild mit der Maus oder dem Finger, hier über die Millimeterangabe. Gesperrte Bereiche sind die Stellen, an denen der Haken an der Wand verschraubt wird.', 'lindenweg16' ); ?>
					</p>

					<div class="lw16-haken__auswahl" data-lw16-angebot hidden>
						<?php foreach ( $catalog['modules'] as $module ) : ?>
							<button type="button" class="lw16-haken__modulknopf" data-lw16-hinzufuegen="<?php echo esc_attr( $module['article'] ); ?>">
								<span class="lw16-haken__symbol" aria-hidden="true"><svg viewBox="0 0 32 32"><use href="#lw16-sym-<?php echo esc_attr( self::icon_for( $module ) ); ?>"></use></svg></span>
								<span class="lw16-haken__plus" aria-hidden="true">+</span>
								<span class="lw16-haken__modulname"><?php echo esc_html( $module['name'] ); ?></span>
								<?php if ( '' !== (string) $module['teaser'] ) : ?>
									<span class="lw16-haken__teaser"><?php echo esc_html( $module['teaser'] ); ?></span>
								<?php endif; ?>
								<span class="lw16-haken__modulpreis" data-lw16-modulpreis="<?php echo esc_attr( $module['article'] ); ?>"></span>
								<span class="lw16-haken__modulmass">
									<?php
									printf(
										/* translators: %s: Bauhöhe in Millimetern. */
										esc_html__( '%s mm hoch', 'lindenweg16' ),
										esc_html( Layout::format_mm( $module['height'] ) )
									);
									?>
								</span>
							</button>
						<?php endforeach; ?>
					</div>

					<div class="lw16-haken__module" data-lw16-modulliste>
						<?php $this->render_module_rows( $catalog, $config ); ?>
					</div>
				</section>

				<div class="lw16-haken__abschluss">
					<div class="lw16-haken__summe">
						<span class="lw16-haken__summenname"><?php esc_html_e( 'Gesamt', 'lindenweg16' ); ?></span>
						<p class="lw16-haken__preis" data-lw16-preis></p>
					</div>
					<p class="lw16-haken__lieferhinweis" role="status" data-lw16-liefer></p>
					<label class="lw16-haken__menge">
						<span><?php esc_html_e( 'Menge', 'lindenweg16' ); ?></span>
						<input type="number" name="menge" value="<?php echo esc_attr( $config['qty'] ); ?>" min="1" max="<?php echo esc_attr( Layout::MAX_QTY ); ?>" step="1" data-lw16-menge>
					</label>
					<button type="submit" class="lw16-haken__kaufen button alt">
						<?php esc_html_e( 'In den Warenkorb', 'lindenweg16' ); ?>
					</button>
				</div>
			</form>
		</div>
		</div>
		<?php
	}

	/**
	 * Die Modulzeilen des Formulars.
	 *
	 * Ohne JavaScript sind es leere Zeilen zum Ausfüllen, mit JavaScript
	 * spiegeln sie, was im Bild steht. In beiden Fällen sind es dieselben
	 * Felder – der Server sieht keinen Unterschied.
	 *
	 * @param array $catalog Katalog.
	 * @param array $config  Vorbelegung.
	 */
	protected function render_module_rows( array $catalog, array $config ) {
		$rows = max( self::FALLBACK_ROWS, count( $config['modules'] ) );

		for ( $i = 0; $i < $rows; $i++ ) {
			$chosen   = isset( $config['modules'][ $i ] ) ? $config['modules'][ $i ] : null;
			$article  = $chosen ? (int) $chosen['article'] : 0;
			$position = $chosen ? (float) $chosen['pos'] : 0;
			?>
			<div class="lw16-haken__zeile" data-lw16-zeile="<?php echo esc_attr( $i ); ?>">
				<label class="lw16-haken__feld">
					<span class="screen-reader-text">
						<?php
						printf(
							/* translators: %d: Nummer der Zeile. */
							esc_html__( 'Modul %d', 'lindenweg16' ),
							(int) $i + 1
						);
						?>
					</span>
					<select name="modul[]" data-lw16-modul>
						<option value="0"><?php esc_html_e( '— kein Modul —', 'lindenweg16' ); ?></option>
						<?php foreach ( $catalog['modules'] as $module ) : ?>
							<option value="<?php echo esc_attr( $module['article'] ); ?>" <?php selected( (int) $module['article'], $article ); ?>>
								<?php echo esc_html( $module['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="lw16-haken__feld lw16-haken__feld--mm">
					<span class="screen-reader-text">
						<?php
						printf(
							/* translators: %d: Nummer der Zeile. */
							esc_html__( 'Position von Modul %d in Millimetern', 'lindenweg16' ),
							(int) $i + 1
						);
						?>
					</span>
					<input type="number" name="position[]" value="<?php echo esc_attr( (string) round( (float) $position, 2 ) ); ?>" min="0" step="any" inputmode="decimal" data-lw16-position>
					<span class="lw16-haken__einheit">mm</span>
				</label>
			</div>
			<?php
		}
	}

	/**
	 * Adresse der aktuellen Seite, um nach dem Absenden zurückzufinden.
	 *
	 * @return string
	 */
	protected function current_url() {
		$permalink = get_permalink();

		return $permalink ? $permalink : home_url( '/' );
	}

	/**
	 * Formularversand: prüfen und in den Warenkorb legen.
	 */
	public function handle_submit() {
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$code     = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$qty      = isset( $_POST['menge'] ) ? (int) $_POST['menge'] : 1;
		$color    = isset( $_POST['farbe'] ) ? sanitize_title( wp_unslash( $_POST['farbe'] ) ) : '';
		$base     = isset( $_POST['basis'] ) ? (int) $_POST['basis'] : 0;
		$modules  = isset( $_POST['modul'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['modul'] ) ) : array();
		$position = isset( $_POST['position'] ) ? array_map( 'strval', (array) wp_unslash( $_POST['position'] ) ) : array();
		$return   = isset( $_POST['rueckkehr'] ) ? esc_url_raw( wp_unslash( $_POST['rueckkehr'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$config = self::config_from_post( $code, $base, $color, $modules, $position );

		$config['qty'] = $qty;

		// Ein geteilter Link kann ohne Farbe kommen, und ein Shop mit nur
		// einer Farbe schickt gar kein Feld mit. Beides ist kein Fehler:
		// Dann gilt die eingestellte Standardfarbe.
		if ( '' === (string) $config['color'] ) {
			$config['color'] = ColorRegistry::default_color();
		}

		$result = CartIntegration::add( $config );

		if ( ! $result['ok'] ) {
			foreach ( $result['errors'] as $error ) {
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( $error, 'error' );
				}
			}

			$back = $return ? $return : home_url( '/' );
			$back = add_query_arg( ConfigCodec::QUERY_VAR, rawurlencode( ConfigCodec::encode( $config ) ), $back );

			wp_safe_redirect( $back );
			exit;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Der konfigurierte Haken liegt im Warenkorb.', 'lindenweg16' ), 'success' );
		}

		wp_safe_redirect( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) );
		exit;
	}

	/**
	 * Aus den abgeschickten Feldern eine Konfiguration bauen.
	 *
	 * Der Code hat Vorrang: Ihn schreibt die Ansicht, und er enthält die
	 * Positionen auf den Hundertstelmillimeter. Fehlt er – kein
	 * JavaScript –, zählen die Auswahlfelder.
	 *
	 * @param string $code      Konfigurationscode.
	 * @param int    $base      Basismodul.
	 * @param string $color     Farbe.
	 * @param array  $modules   Gewählte Module je Zeile.
	 * @param array  $positions Positionen je Zeile.
	 * @return array
	 */
	public static function config_from_post( $code, $base, $color, array $modules, array $positions ) {
		$decoded = '' === $code ? null : ConfigCodec::decode( $code );

		if ( $decoded ) {
			return $decoded;
		}

		$chosen = array();

		foreach ( $modules as $index => $article ) {
			$article = (int) $article;

			if ( $article <= 0 ) {
				continue;
			}

			$raw = isset( $positions[ $index ] ) ? str_replace( ',', '.', (string) $positions[ $index ] ) : '0';

			$chosen[] = array(
				'article' => $article,
				'pos'     => round( (float) $raw, 2 ),
			);
		}

		return array(
			'base'    => (int) $base,
			'color'   => (string) $color,
			'modules' => $chosen,
		);
	}
}
