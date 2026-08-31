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
		return array(
			'katalog' => Catalog::payload(),
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
			),
			'grenzen' => array(
				'max_module' => Layout::MAX_MODULES,
				'max_menge'  => Layout::MAX_QTY,
				'zeilen'     => self::FALLBACK_ROWS,
			),
		);
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
		$colors = $catalog['colors'];
		$code   = ConfigCodec::encode( $config );
		?>
		<div class="lw16-haken" data-lw16-haken>
			<div class="lw16-haken__buehne">
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
				<div class="lw16-haken__werkzeuge">
					<button type="button" class="lw16-haken__knopf" data-lw16-ansicht-zuruecksetzen hidden>
						<?php esc_html_e( 'Ansicht zurücksetzen', 'lindenweg16' ); ?>
					</button>
					<button type="button" class="lw16-haken__knopf" data-lw16-teilen hidden>
						<?php esc_html_e( 'Link zu dieser Konfiguration kopieren', 'lindenweg16' ); ?>
					</button>
					<span class="lw16-haken__meldung" role="status" data-lw16-meldung></span>
				</div>
			</div>

			<form class="lw16-haken__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-lw16-form>
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="rueckkehr" value="<?php echo esc_attr( $this->current_url() ); ?>">
				<input type="hidden" name="code" value="<?php echo esc_attr( $code ); ?>" data-lw16-code>

				<h3 class="lw16-haken__titel"><?php esc_html_e( '1. Basismodul', 'lindenweg16' ); ?></h3>
				<label class="lw16-haken__feld">
					<span class="screen-reader-text"><?php esc_html_e( 'Basismodul', 'lindenweg16' ); ?></span>
					<select name="basis" data-lw16-basis>
						<?php foreach ( $catalog['bases'] as $base ) : ?>
							<option value="<?php echo esc_attr( $base['article'] ); ?>" <?php selected( (int) $base['article'], (int) $config['base'] ); ?>>
								<?php
								printf(
									/* translators: 1: Bezeichnung, 2: Länge in Millimetern. */
									esc_html__( '%1$s (%2$s mm)', 'lindenweg16' ),
									esc_html( $base['name'] ),
									esc_html( Layout::format_mm( $base['height'] ) )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<?php if ( count( $colors ) > 1 ) : ?>
					<h3 class="lw16-haken__titel"><?php esc_html_e( '2. Farbe', 'lindenweg16' ); ?></h3>
					<label class="lw16-haken__feld">
						<span class="screen-reader-text"><?php esc_html_e( 'Farbe', 'lindenweg16' ); ?></span>
						<select name="farbe" data-lw16-farbe>
							<?php foreach ( $colors as $color ) : ?>
								<option value="<?php echo esc_attr( $color['slug'] ); ?>" <?php selected( $color['slug'], $config['color'] ); ?>>
									<?php echo esc_html( $color['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php else : ?>
					<input type="hidden" name="farbe" value="<?php echo esc_attr( $config['color'] ); ?>" data-lw16-farbe>
				<?php endif; ?>

				<h3 class="lw16-haken__titel"><?php esc_html_e( 'Module', 'lindenweg16' ); ?></h3>
				<p class="lw16-haken__erklaerung">
					<?php esc_html_e( 'Module lassen sich am Basismodul frei in der Höhe verschieben – im Bild mit der Maus oder dem Finger, hier über die Millimeterangabe. Gesperrte Bereiche sind die Stellen, an denen der Haken an der Wand verschraubt wird.', 'lindenweg16' ); ?>
				</p>

				<div class="lw16-haken__module" data-lw16-modulliste>
					<?php $this->render_module_rows( $catalog, $config ); ?>
				</div>

				<div class="lw16-haken__auswahl" data-lw16-angebot hidden>
					<?php foreach ( $catalog['modules'] as $module ) : ?>
						<button type="button" class="lw16-haken__modulknopf" data-lw16-hinzufuegen="<?php echo esc_attr( $module['article'] ); ?>">
							<span class="lw16-haken__modulname"><?php echo esc_html( $module['name'] ); ?></span>
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

				<h3 class="lw16-haken__titel"><?php esc_html_e( 'Menge', 'lindenweg16' ); ?></h3>
				<label class="lw16-haken__feld">
					<span class="screen-reader-text"><?php esc_html_e( 'Menge', 'lindenweg16' ); ?></span>
					<input type="number" name="menge" value="<?php echo esc_attr( $config['qty'] ); ?>" min="1" max="<?php echo esc_attr( Layout::MAX_QTY ); ?>" step="1" data-lw16-menge>
				</label>

				<div class="lw16-haken__abschluss">
					<p class="lw16-haken__preis" data-lw16-preis></p>
					<button type="submit" class="lw16-haken__kaufen button alt">
						<?php esc_html_e( 'In den Warenkorb', 'lindenweg16' ); ?>
					</button>
				</div>
			</form>
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
					<input type="number" name="position[]" value="<?php echo esc_attr( Layout::format_mm( $position ) ); ?>" min="0" step="1" inputmode="numeric" data-lw16-position>
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
