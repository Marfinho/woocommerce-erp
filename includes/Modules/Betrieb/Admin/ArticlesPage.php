<?php
/**
 * Artikel, Revisionen, Komponenten, Stückliste und Kalkulation.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;
use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuFilamentCatalogRepository;
use Lindenweg16\Modules\Betrieb\Nesting\SlicingProfileRepository;
use Lindenweg16\Modules\Betrieb\PriceSync;
use Lindenweg16\Modules\Betrieb\BinRepository;
use Lindenweg16\Modules\Betrieb\BomRepository;
use Lindenweg16\Modules\Betrieb\BomResolver;
use Lindenweg16\Modules\Betrieb\BundleCalculator;
use Lindenweg16\Modules\Betrieb\BundleRepository;
use Lindenweg16\Modules\Betrieb\CalculationContext;
use Lindenweg16\Modules\Betrieb\ComponentRepository;
use Lindenweg16\Modules\Betrieb\ModelFile;
use Lindenweg16\Modules\Betrieb\Purchase;
use Lindenweg16\Modules\Betrieb\PurchaseRepository;
use Lindenweg16\Modules\Betrieb\ThreeMf;
use Lindenweg16\Modules\Betrieb\CostCalculator;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\SourcingRepository;

/**
 * Rendert die Seite „Artikel & Revisionen" mit ihren Reitern.
 */
class ArticlesPage {

	const NONCE = 'lw16_betrieb_article';
	const SLUG  = 'lw16-betrieb-artikel';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_artikel', array( $this, 'render' ) );

		foreach ( array( 'article', 'component', 'revision', 'bom', 'sourcing', 'activate', 'bom_delete', 'bundle', 'model_remove' ) as $what ) {
			add_action( 'admin_post_lw16_betrieb_save_' . $what, array( $this, 'handle_' . $what ) );
		}

		add_action( 'admin_post_lw16_betrieb_article_state', array( $this, 'handle_article_state' ) );
		add_action( 'admin_post_lw16_betrieb_price_adopt', array( $this, 'handle_price_adopt' ) );
		add_action( 'admin_post_lw16_betrieb_price_push_all', array( $this, 'handle_price_push_all' ) );
		add_action( 'admin_post_lw16_betrieb_model_get', array( $this, 'handle_model_get' ) );
		add_action( 'admin_post_lw16_betrieb_purchase', array( $this, 'handle_purchase' ) );
		add_action( 'admin_post_lw16_betrieb_purchase_state', array( $this, 'handle_purchase_state' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Auswahlfeld-Skript nur auf der Artikelseite laden.
	 *
	 * @param string $hook Aktueller Admin-Hook.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'lw16-betrieb-artikel' ) ) {
			return;
		}

		wp_enqueue_script(
			'lw16-betrieb-bestellen',
			LW16_PLUGIN_URL . 'assets/js/betrieb-bestellen.js',
			array(),
			Assets::version( 'assets/js/betrieb-bestellen.js' ),
			true
		);

		wp_enqueue_script(
			'lw16-betrieb-productpicker',
			LW16_PLUGIN_URL . 'assets/js/betrieb-productpicker.js',
			array(),
			Assets::version( 'assets/js/betrieb-productpicker.js' ),
			true
		);
	}

	/**
	 * Suchbares Auswahlfeld für ein WooCommerce-Produkt.
	 *
	 * @param string $label       Beschriftung.
	 * @param string $name        Feldname.
	 * @param int    $current     Aktuelle Produkt-ID.
	 * @param string $description Hilfetext.
	 */
	protected function product_picker( $label, $name, $current, $description = '' ) {
		$products = self::shop_products();

		// Ohne WooCommerce oder bei sehr grossem Sortiment bleibt das
		// schlichte Zahlenfeld: Ein Auswahlfeld mit tausenden Eintraegen
		// waere langsamer als das Eintippen der ID.
		if ( ! $products ) {
			$this->field( $label, $name, $current ? (string) $current : '', 'text', $description );
			return;
		}

		$selected = '';
		foreach ( $products as $product ) {
			if ( (int) $product['id'] === (int) $current ) {
				$selected = $product['label'];
				break;
			}
		}

		// Verknuepft, aber nicht mehr im Katalog: Die ID trotzdem zeigen,
		// statt die Verknuepfung stillschweigend zu verlieren.
		if ( $current && '' === $selected ) {
			$selected = sprintf(
				/* translators: %d: Produkt-ID. */
				__( 'Produkt #%d (nicht im Katalog)', 'lindenweg16' ),
				(int) $current
			);
		}

		printf(
			'<tr><th scope="row"><label for="lw16-%1$s-search">%2$s</label></th><td>'
			. '<div class="lw16-picker" data-products="%3$s" data-empty="%4$s" data-more="%5$s">'
			. '<input type="text" class="lw16-picker__input regular-text" id="lw16-%1$s-search" value="%6$s" placeholder="%7$s" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" />'
			. '<button type="button" class="lw16-picker__clear" aria-label="%8$s"%9$s>&times;</button>'
			. '<input type="hidden" class="lw16-picker__value" name="%1$s" value="%10$s" />'
			. '<ul class="lw16-picker__list" role="listbox" hidden></ul>'
			. '</div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( wp_json_encode( $products ) ),
			esc_attr__( 'Kein Produkt gefunden.', 'lindenweg16' ),
			esc_attr__( '… und %d weitere. Bitte genauer eingrenzen.', 'lindenweg16' ),
			esc_attr( $selected ),
			esc_attr__( 'Tippen zum Suchen …', 'lindenweg16' ),
			esc_attr__( 'Verknüpfung entfernen', 'lindenweg16' ),
			$current ? '' : ' hidden',
			esc_attr( $current ? (string) $current : '' )
		);

		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}

		echo '</td></tr>';
	}

	/**
	 * Shop-Produkte für das Auswahlfeld.
	 *
	 * Einmal je Seitenaufruf gelesen und mitgeliefert, damit im Browser ohne
	 * Nachladen gefiltert werden kann.
	 *
	 * @return array Leeres Array, wenn kein Auswahlfeld sinnvoll ist.
	 */
	protected static function shop_products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		// Eins mehr holen als angezeigt werden soll: Kommt die Zusatzzeile
		// zurueck, ist das Sortiment zu gross fuers Mitliefern, und das Feld
		// faellt auf die Eingabe der ID zurueck – lieber ehrlich als eine
		// stillschweigend gekuerzte Liste, in der ein Produkt fehlt.
		$limit    = 400;
		$products = wc_get_products(
			array(
				'status'  => array( 'publish', 'private', 'draft' ),
				'limit'   => $limit + 1,
				'orderby' => 'title',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		if ( count( (array) $products ) > $limit ) {
			return array();
		}

		$rows = array();

		foreach ( (array) $products as $product ) {
			$rows[] = array(
				'id'    => (int) $product->get_id(),
				'label' => $product->get_name(),
				'sku'   => (string) $product->get_sku(),
			);
		}

		return $rows;
	}

	/* ====================================================================
	 * Infrastruktur
	 * ================================================================== */

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Abgesicherte POST-Daten holen.
	 *
	 * @return array
	 */
	protected function post_input() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		return array_map(
			static function ( $value ) {
				return is_string( $value ) ? wp_unslash( $value ) : $value;
			},
			(array) $_POST // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type success oder error.
	 * @param array  $args Zusätzliche Parameter (tab, article).
	 */
	protected function redirect( $msg, $type, array $args = array() ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'     => self::SLUG,
						'lw16_msg' => rawurlencode( $msg ),
						'lw16_typ' => $type,
					),
					$args
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ====================================================================
	 * Formular-Handler
	 * ================================================================== */

	/**
	 * Meldung um das Ergebnis der Preisübergabe ergänzen.
	 *
	 * Die Übergabe läuft still am Speichern mit. Ohne diesen Satz wüsste
	 * niemand, ob der Shop den neuen Preis hat – und man ginge doch wieder
	 * nachsehen.
	 *
	 * @param string $msg Grundmeldung.
	 * @return string
	 */
	protected function with_price_note( $msg ) {
		$ergebnis = PriceSync::take_last();

		if ( ! $ergebnis || 'gleich' === $ergebnis['status'] || 'ohne_shop' === $ergebnis['status'] ) {
			return $msg;
		}

		return $msg . ' ' . $ergebnis['message'];
	}

	/**
	 * Kalkulierten Vorschlagspreis als Artikelpreis übernehmen.
	 *
	 * Danach greift dieselbe Übergabe wie bei jedem anderen Preis – es
	 * gibt nur eine Stelle, an der ein Preis entsteht.
	 */
	public function handle_price_adopt() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$id     = isset( $_POST['article_id'] ) ? (int) $_POST['article_id'] : 0;
		$preis  = isset( $_POST['vorschlag'] ) ? round( (float) wp_unslash( $_POST['vorschlag'] ), 2 ) : 0.0;
		$artikel = $id ? ArticleRepository::find( $id ) : null;

		if ( ! $artikel || $preis <= 0 ) {
			$this->redirect( __( 'Kein übernehmbarer Preis.', 'lindenweg16' ), 'error', array( 'tab' => 'kalkulation', 'article' => $id ) );
		}

		ArticleRepository::update( $id, array( 'price_gross' => $preis ), __( 'Preis aus der Kalkulation übernommen.', 'lindenweg16' ) );

		$this->redirect(
			$this->with_price_note(
				sprintf(
					/* translators: %s: Preis. */
					__( 'Neuer Preis: %s €.', 'lindenweg16' ),
					number_format_i18n( $preis, 2 )
				)
			),
			'success',
			array( 'tab' => 'kalkulation', 'article' => $id )
		);
	}

	/**
	 * Alle verkäuflichen Artikel in den Shop schieben.
	 *
	 * Für den Fall, dass sich nicht ein Preis geändert hat, sondern eine
	 * Annahme darunter.
	 */
	public function handle_price_push_all() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$zaehler = PriceSync::push_all();

		$this->redirect(
			sprintf(
				/* translators: 1: übertragen, 2: unverändert, 3: ohne Shop-Produkt. */
				__( '%1$d Preise übertragen, %2$d waren schon gleich, %3$d Artikel haben kein Shop-Produkt.', 'lindenweg16' ),
				isset( $zaehler['uebertragen'] ) ? $zaehler['uebertragen'] : 0,
				isset( $zaehler['gleich'] ) ? $zaehler['gleich'] : 0,
				isset( $zaehler['ohne_shop'] ) ? $zaehler['ohne_shop'] : 0
			),
			'success',
			array( 'tab' => 'kalkulation' )
		);
	}

	/**
	 * Artikel speichern.
	 */
	public function handle_article() {
		$input  = $this->post_input();
		$id     = isset( $input['article_id'] ) ? (int) $input['article_id'] : 0;
		$reason = isset( $input['reason'] ) ? sanitize_text_field( $input['reason'] ) : '';
		$result = ArticleRepository::validate( $input, $id ? $id : null );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', array( 'tab' => 'artikel', 'edit' => $id ) );
		}

		if ( $id ) {
			ArticleRepository::update( $id, $result['data'], $reason );
			$this->redirect( $this->with_price_note( __( 'Artikel gespeichert.', 'lindenweg16' ) ), 'success', array( 'tab' => 'artikel' ) );
		}

		$new = ArticleRepository::insert( $result['data'] );
		$this->redirect(
			$new ? $this->with_price_note( __( 'Artikel angelegt.', 'lindenweg16' ) ) : __( 'Artikel konnte nicht angelegt werden.', 'lindenweg16' ),
			$new ? 'success' : 'error',
			array( 'tab' => 'artikel' )
		);
	}

	/**
	 * Komponente speichern.
	 */
	public function handle_component() {
		$input  = $this->post_input();
		$id     = isset( $input['component_id'] ) ? (int) $input['component_id'] : 0;
		$reason = isset( $input['reason'] ) ? sanitize_text_field( $input['reason'] ) : '';
		$result = ComponentRepository::validate( $input, $id ? $id : null );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', array( 'tab' => 'komponenten', 'edit' => $id ) );
		}

		if ( $id ) {
			ComponentRepository::update( $id, $result['data'], $reason );
			$this->redirect( __( 'Komponente gespeichert.', 'lindenweg16' ), 'success', array( 'tab' => 'komponenten' ) );
		}

		ComponentRepository::insert( $result['data'] );
		$this->redirect( __( 'Komponente angelegt.', 'lindenweg16' ), 'success', array( 'tab' => 'komponenten' ) );
	}

	/**
	 * Revision speichern.
	 */
	public function handle_revision() {
		$input   = $this->post_input();
		$id      = isset( $input['revision_id'] ) ? (int) $input['revision_id'] : 0;
		$article = isset( $input['article_id'] ) ? (int) $input['article_id'] : 0;
		$reason  = isset( $input['reason'] ) ? sanitize_text_field( $input['reason'] ) : '';
		$result  = RevisionRepository::validate( $input, $id ? $id : null );

		$args = array( 'tab' => 'revisionen', 'article' => $article );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', $args );
		}

		if ( $id ) {
			RevisionRepository::update( $id, $result['data'], $reason );
			$hint = $this->maybe_store_model( $id );
			$this->redirect( __( 'Revision gespeichert.', 'lindenweg16' ) . $hint, 'success', $args );
		}

		$new  = RevisionRepository::insert( $result['data'] );
		$hint = $new ? $this->maybe_store_model( $new ) : '';
		$this->redirect( __( 'Revision angelegt. Sie ist noch nicht aktiv – setze sie in der Liste aktiv.', 'lindenweg16' ) . $hint, 'success', $args );
	}

	/**
	 * Mitgeschickte 3MF-Datei ablegen, falls eine dabei war.
	 *
	 * Ein Fehler beim Anhang verwirft die Revision nicht: die Stammdaten
	 * sind gespeichert, nur die Datei fehlt – und genau das steht dann in
	 * der Meldung.
	 *
	 * @param int $revision_id Revision.
	 * @return string Leerer String, wenn alles gut ging.
	 */
	protected function maybe_store_model( $revision_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- in post_input() geprüft.
		$file = isset( $_FILES['model'] ) ? $_FILES['model'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

		if ( ! $file || empty( $file['name'] ) ) {
			return '';
		}

		$stored = ModelFile::store( $file );
		if ( ! $stored['success'] ) {
			return ' ' . $stored['message'];
		}

		$old = RevisionRepository::clear_model( $revision_id );
		if ( '' !== $old ) {
			ModelFile::delete( $old );
		}

		RevisionRepository::set_model(
			$revision_id,
			$stored['name'],
			sanitize_file_name( (string) $file['name'] ),
			$stored['sha1'],
			$stored['size'],
			$stored['kind']
		);

		return $this->adopt_slice_values( $revision_id, $stored );
	}

	/**
	 * Aus einer geslicten Druckdatei die Zahlen des Slicers übernehmen.
	 *
	 * Übernommen wird nur, was noch leer ist. Eine gemessene Angabe zu
	 * überschreiben, weil zufällig eine Datei hochgeladen wurde, wäre eine
	 * stille Änderung an der Kalkulation.
	 *
	 * @param int   $revision_id Revision.
	 * @param array $stored      Ergebnis von ModelFile::store().
	 * @return string Hinweistext, leer wenn nichts zu sagen ist.
	 */
	protected function adopt_slice_values( $revision_id, array $stored ) {
		if ( 'druckdatei' !== $stored['kind'] ) {
			return '';
		}

		$info = ModelFile::slice_info( ModelFile::path( $stored['name'] ) );

		if ( ! $info ) {
			return ' ' . __( 'Die Datei ist eine geslicte Druckdatei ohne lesbare Slicer-Angaben.', 'lindenweg16' );
		}

		$werte    = ThreeMf::revision_values( $info );
		$revision = RevisionRepository::find( $revision_id );
		$data     = array();

		// Nur leere Felder füllen.
		if ( $revision && (float) $revision['print_time_plate_h'] <= 0 && null !== $werte['print_time_plate_h'] ) {
			$data['print_time_plate_h'] = $werte['print_time_plate_h'];
		}
		if ( $revision && (float) $revision['weight_g'] <= 0 && null !== $werte['weight_g'] ) {
			$data['weight_g']        = $werte['weight_g'];
			$data['parts_per_plate'] = $werte['parts_per_plate'];
		}

		if ( $data ) {
			RevisionRepository::update( $revision_id, $data, __( 'Aus der geslicten Druckdatei übernommen.', 'lindenweg16' ) );
		}

		return ' ' . sprintf(
			/* translators: 1: Druckzeit, 2: Gewicht, 3: Teile, 4: übernommen oder nicht. */
			__( 'Geslicte Druckdatei erkannt: %1$s h Druckzeit, %2$s g Material, %3$d Teile auf der Platte. %4$s', 'lindenweg16' ),
			null === $werte['print_time_plate_h'] ? '?' : number_format_i18n( $werte['print_time_plate_h'], 3 ),
			null === $info['weight_g'] ? '?' : number_format_i18n( $info['weight_g'], 2 ),
			(int) $werte['parts_per_plate'],
			$data
				? __( 'Die leeren Felder der Revision wurden damit gefüllt.', 'lindenweg16' )
				: __( 'Die Revision hat bereits eigene Werte – die bleiben stehen.', 'lindenweg16' )
		);
	}

	/**
	 * Eine Nachbestellung erfassen.
	 */
	public function handle_purchase() {
		$input = $this->post_input();
		$args  = array( 'tab' => 'komponenten' );

		$result = PurchaseRepository::create( $input );

		if ( ! $result['success'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', $args );
		}

		$this->redirect( __( 'Bestellung vermerkt. Sie steht jetzt unter „Unterwegs".', 'lindenweg16' ), 'success', $args );
	}

	/**
	 * Wareneingang buchen oder Bestellung stornieren.
	 */
	public function handle_purchase_state() {
		$input = $this->post_input();
		$args  = array( 'tab' => 'komponenten' );
		$id    = isset( $input['purchase_id'] ) ? (int) $input['purchase_id'] : 0;

		if ( ! empty( $input['cancel'] ) ) {
			$result = PurchaseRepository::cancel( $id, isset( $input['reason'] ) ? $input['reason'] : '' );
		} else {
			$result = PurchaseRepository::receive(
				$id,
				isset( $input['received_qty'] ) ? $input['received_qty'] : 0,
				isset( $input['received_on'] ) ? $input['received_on'] : ''
			);
		}

		$this->redirect( $result['message'], $result['success'] ? 'success' : 'error', $args );
	}

	/**
	 * Hinterlegte 3MF-Datei ausliefern.
	 */
	public function handle_model_get() {
		$this->require_cap();

		$id = isset( $_GET['revision'] ) ? (int) $_GET['revision'] : 0;
		check_admin_referer( 'lw16_betrieb_model_' . $id );

		$revision = RevisionRepository::find( $id );
		$path     = $revision ? ModelFile::safe_path( (string) $revision['model_file'] ) : '';

		if ( '' === $path ) {
			wp_die( esc_html__( 'Zu dieser Revision ist keine Datei hinterlegt.', 'lindenweg16' ) );
		}

		$this->stream( $path, $this->model_download_name( $revision ) );
	}

	/**
	 * Hinterlegte Datei wieder entfernen.
	 */
	public function handle_model_remove() {
		$input   = $this->post_input();
		$id      = isset( $input['revision_id'] ) ? (int) $input['revision_id'] : 0;
		$article = isset( $input['article_id'] ) ? (int) $input['article_id'] : 0;
		$args    = array( 'tab' => 'revisionen', 'article' => $article );

		$old = RevisionRepository::clear_model( $id );
		if ( '' === $old ) {
			$this->redirect( __( 'Es war keine Datei hinterlegt.', 'lindenweg16' ), 'error', $args );
		}

		ModelFile::delete( $old );
		$this->redirect( __( 'Datei entfernt.', 'lindenweg16' ), 'success', $args );
	}

	/**
	 * Sprechender Dateiname für den Download.
	 *
	 * @param array $revision Revision.
	 * @return string
	 */
	protected function model_download_name( array $revision ) {
		$article = ArticleRepository::find( (int) $revision['article_id'] );
		$sku     = $article ? $article['sku'] : 'artikel';

		return sanitize_file_name( $sku . '-' . $revision['version'] . '.3mf' );
	}

	/**
	 * Datei ausliefern und danach beenden.
	 *
	 * @param string $path Absoluter Pfad.
	 * @param string $name Dateiname für den Browser.
	 */
	protected function stream( $path, $name ) {
		nocache_headers();
		header( 'Content-Type: model/3mf' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/**
	 * Revision aktiv setzen.
	 */
	public function handle_activate() {
		$input   = $this->post_input();
		$id      = isset( $input['revision_id'] ) ? (int) $input['revision_id'] : 0;
		$article = isset( $input['article_id'] ) ? (int) $input['article_id'] : 0;
		$args    = array( 'tab' => 'revisionen', 'article' => $article );

		$result = RevisionRepository::activate( $id );

		if ( ! $result['success'] ) {
			$this->redirect( implode( ' ', $result['reasons'] ), 'error', $args );
		}

		$this->redirect( __( 'Revision aktiv gesetzt.', 'lindenweg16' ), 'success', $args );
	}

	/**
	 * Stücklistenzeile speichern.
	 */
	public function handle_bom() {
		$input  = $this->post_input();
		$id     = isset( $input['bom_id'] ) ? (int) $input['bom_id'] : 0;
		$parent = isset( $input['parent_article_id'] ) ? (int) $input['parent_article_id'] : 0;
		$reason = isset( $input['reason'] ) ? sanitize_text_field( $input['reason'] ) : '';

		// Auswahl kommt als „typ:id" aus einem einzigen Auswahlfeld.
		if ( isset( $input['child'] ) && false !== strpos( (string) $input['child'], ':' ) ) {
			list( $type, $child_id ) = explode( ':', (string) $input['child'], 2 );
			$input['child_type']     = $type;
			$input['child_id']       = $child_id;
		}

		$result = BomRepository::validate( $input, $id ? $id : null );
		$args   = array( 'tab' => 'stueckliste', 'article' => $parent );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', $args );
		}

		if ( $id ) {
			BomRepository::update( $id, $result['data'], $reason );
			$this->redirect( __( 'Position gespeichert.', 'lindenweg16' ), 'success', $args );
		}

		BomRepository::insert( $result['data'] );
		$this->redirect( __( 'Position hinzugefügt.', 'lindenweg16' ), 'success', $args );
	}

	/**
	 * Stücklistenzeile entfernen.
	 */
	public function handle_bom_delete() {
		$input  = $this->post_input();
		$id     = isset( $input['bom_id'] ) ? (int) $input['bom_id'] : 0;
		$parent = isset( $input['parent_article_id'] ) ? (int) $input['parent_article_id'] : 0;
		$reason = isset( $input['reason'] ) ? sanitize_text_field( $input['reason'] ) : '';

		BomRepository::delete( $id, $reason );
		$this->redirect( __( 'Position entfernt.', 'lindenweg16' ), 'success', array( 'tab' => 'stueckliste', 'article' => $parent ) );
	}

	/**
	 * Beschaffungsdaten festschreiben.
	 */
	public function handle_sourcing() {
		$input  = $this->post_input();
		$result = SourcingRepository::validate( $input );
		$args   = array( 'tab' => 'beschaffung', 'article' => isset( $input['article_id'] ) ? (int) $input['article_id'] : 0 );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', $args );
		}

		SourcingRepository::insert( $result['data'] );
		$this->redirect( __( 'Beschaffungsdaten festgeschrieben.', 'lindenweg16' ), 'success', $args );
	}

	/**
	 * Bestellart speichern.
	 */
	public function handle_bundle() {
		$input = $this->post_input();

		$mengen = isset( $input['menge'] ) && is_array( $input['menge'] ) ? array_map( 'floatval', $input['menge'] ) : array();
		$name   = isset( $input['bundle_name'] ) ? $input['bundle_name'] : '';
		$preis  = isset( $input['bundle_price'] ) ? (float) str_replace( ',', '.', (string) $input['bundle_price'] ) : 0.0;

		$result = BundleRepository::save( $name, $preis, $mengen );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', array( 'tab' => 'bestellarten' ) );
		}

		$this->redirect( __( 'Bestellart gespeichert.', 'lindenweg16' ), 'success', array( 'tab' => 'bestellarten', 'bundle' => $result['id'] ) );
	}

	/* ====================================================================
	 * Darstellung
	 * ================================================================== */

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		// phpcs:disable WordPress.Security.NonceVerification
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'artikel';
		$article = isset( $_GET['article'] ) ? (int) $_GET['article'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification

		$tabs = array(
			'artikel'      => __( 'Artikel', 'lindenweg16' ),
			'komponenten'  => __( 'Komponenten', 'lindenweg16' ),
			'revisionen'   => __( 'Revisionen', 'lindenweg16' ),
			'stueckliste'  => __( 'Stückliste', 'lindenweg16' ),
			'beschaffung'  => __( 'Beschaffung', 'lindenweg16' ),
		);

		// Geld ist ein eigenes Recht: Wer Stammdaten pflegen darf, muss
		// deshalb noch lange nicht die Kalkulation sehen.
		if ( Roles::current_user_can_betrieb( 'lw16_view_costs' ) ) {
			$tabs['kalkulation']  = __( 'Kalkulation', 'lindenweg16' );
			$tabs['bestellarten'] = __( 'Bestellart-Rechner', 'lindenweg16' );
		}

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'artikel';
		}

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Artikel & Revisionen', 'lindenweg16' ) . '</h1>';

		echo '<nav class="lw16-tabs">';
		foreach ( $tabs as $key => $label ) {
			$args = array( 'page' => self::SLUG, 'tab' => $key );
			if ( $article ) {
				$args['article'] = $article;
			}
			printf(
				'<a href="%s" class="%s">%s</a> ',
				esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
				$tab === $key ? 'active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		$this->render_notice();

		switch ( $tab ) {
			case 'komponenten':
				$this->tab_components();
				break;
			case 'revisionen':
				$this->tab_revisions( $article );
				break;
			case 'stueckliste':
				$this->tab_bom( $article );
				break;
			case 'beschaffung':
				$this->tab_sourcing( $article );
				break;
			case 'kalkulation':
				$this->tab_calculation( $article );
				break;
			case 'bestellarten':
				$this->tab_bundles();
				break;
			default:
				$this->tab_articles();
		}

		echo '</div>';
	}

	/**
	 * Meldung anzeigen.
	 */
	protected function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['lw16_msg'] ) ) {
			return;
		}
		$msg  = sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) );
		$type = isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success';
		// phpcs:enable WordPress.Security.NonceVerification
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
	}

	/**
	 * Artikelauswahl für die artikelbezogenen Reiter.
	 *
	 * @param int    $selected Aktueller Artikel.
	 * @param string $tab      Reiter.
	 */
	protected function article_switcher( $selected, $tab ) {
		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		printf( '<input type="hidden" name="tab" value="%s" />', esc_attr( $tab ) );
		echo '<label>' . esc_html__( 'Artikel:', 'lindenweg16' ) . ' <select name="article">';
		printf( '<option value="0">%s</option>', esc_html__( '– bitte wählen –', 'lindenweg16' ) );
		foreach ( ArticleRepository::all() as $article ) {
			printf(
				'<option value="%d"%s>%s – %s</option>',
				(int) $article['id'],
				selected( $selected, (int) $article['id'], false ),
				esc_html( $article['sku'] ),
				esc_html( $article['name'] )
			);
		}
		echo '</select></label> <button class="button">' . esc_html__( 'Anzeigen', 'lindenweg16' ) . '</button></form>';
	}

	/**
	 * Formularkopf.
	 *
	 * @param string $action    admin_post-Aktion.
	 * @param bool   $multipart Formular nimmt Dateien entgegen.
	 */
	protected function form_open( $action, $multipart = false ) {
		printf(
			'<form method="post"%s action="%s">',
			$multipart ? ' enctype="multipart/form-data"' : '',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="action" value="lw16_betrieb_save_%s" />', esc_attr( $action ) );
	}

	/**
	 * Ein Textfeld in einer form-table.
	 *
	 * @param string $label       Beschriftung.
	 * @param string $name        Feldname.
	 * @param string $value       Wert.
	 * @param string $type        Typ.
	 * @param string $description Hilfetext.
	 */
	protected function field( $label, $name, $value = '', $type = 'text', $description = '' ) {
		printf(
			'<tr><th scope="row"><label for="lw16-%1$s">%2$s</label></th><td><input type="%3$s" name="%1$s" id="lw16-%1$s" value="%4$s" class="regular-text" />',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value )
		);
		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
		echo '</td></tr>';
	}

	/**
	 * Lagerplätze als Auswahlliste, mit Leereintrag an erster Stelle.
	 *
	 * @return array id => Beschriftung.
	 */
	protected function bin_options() {
		$options = array( 0 => __( '— kein fester Platz —', 'lindenweg16' ) );

		foreach ( BinRepository::all( true ) as $bin ) {
			$options[ (int) $bin['id'] ] = trim( $bin['code'] . ' ' . (string) $bin['name'] );
		}

		return $options;
	}

	/**
	 * Auswahlliste der Revisions-Farbe: {Bambu-Filament-ID => Anzeigetext}.
	 *
	 * Bewusst NICHT der volle Bambu-Filament-Katalog (der enthält auch
	 * Filamente, die nur einmal im AMS steckten, aber nie ein Slicing-
	 * Profil dafür hochgeladen wurde) - eingeschränkt auf die (Material,
	 * Farbe)-Kombinationen, für die tatsächlich ein Profil existiert
	 * (SlicingProfileRepository::existing_material_colors()). So kann bei
	 * der Revisionspflege gar nicht erst eine Farbe gewählt werden, für
	 * die das automatische Slicen später mit "kein Slicing-Profil
	 * hinterlegt" scheitern würde - die Verknüpfung ist damit erzwungen,
	 * nicht nur eine Konvention.
	 *
	 * @return array
	 */
	protected function filament_color_options() {
		$options = array();

		foreach ( SlicingProfileRepository::existing_material_colors() as $kombination ) {
			if ( '' === $kombination->color ) {
				$options[''] = __( '(farblos - das eine Profil dieses Materials)', 'lindenweg16' );
				continue;
			}

			$filament = BambuFilamentCatalogRepository::find( $kombination->color );

			$options[ $kombination->color ] = sprintf(
				'%s – %s (%s)',
				$kombination->material,
				$filament && $filament->label ? $filament->label : $kombination->color,
				$kombination->color
			);
		}

		if ( ! $options ) {
			// Noch gar kein Slicing-Profil hinterlegt - lieber das
			// sichtbar machen, als eine Auswahl vorzugaukeln, die ins
			// Leere liefe.
			$options[''] = __( '(noch kein Slicing-Profil hinterlegt - siehe Betrieb → Bambu-Lab-Drucker → Slicing-Profile)', 'lindenweg16' );
		}

		return $options;
	}

	/**
	 * Anzeigename zu einer gespeicherten Bambu-Filament-ID (Revisions-
	 * Farbe) - "(farblos)" wenn leer, sonst der Katalog-Anzeigename, oder
	 * die rohe ID, falls sie (noch) nicht im Katalog steht.
	 *
	 * @param string $filament_id Gespeicherter Wert des Farbfelds.
	 * @return string
	 */
	protected function color_label( $filament_id ) {
		if ( '' === $filament_id ) {
			return __( '(farblos)', 'lindenweg16' );
		}

		$filament = BambuFilamentCatalogRepository::find( $filament_id );

		return $filament ? sprintf( '%s (%s)', $filament->label, $filament_id ) : $filament_id;
	}

	/**
	 * Beschriftetes Auswahlfeld.
	 *
	 * @param string $label       Beschriftung.
	 * @param string $name        Feldname.
	 * @param array  $options     {Wert => Beschriftung}.
	 * @param string $current     Aktueller Wert.
	 * @param string $description Zusatztext.
	 */
	protected function select( $label, $name, array $options, $current = '', $description = '' ) {
		printf(
			'<tr><th scope="row"><label for="lw16-%1$s">%2$s</label></th><td><select name="%1$s" id="lw16-%1$s">',
			esc_attr( $name ),
			esc_html( $label )
		);
		foreach ( $options as $key => $option_label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( (string) $current, (string) $key, false ),
				esc_html( $option_label )
			);
		}
		echo '</select>';
		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
		echo '</td></tr>';
	}

	/**
	 * Ein Kontrollkästchen.
	 *
	 * @param string $label       Beschriftung.
	 * @param string $name        Feldname.
	 * @param bool   $checked     Zustand.
	 * @param string $description Hilfetext.
	 */
	protected function checkbox( $label, $name, $checked, $description = '' ) {
		printf(
			'<tr><th scope="row">%2$s</th><td><label><input type="checkbox" name="%1$s" value="1"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_html( $label ),
			checked( (bool) $checked, true, false ),
			esc_html( $description )
		);
		echo '</td></tr>';
	}

	/**
	 * Artikel stilllegen, wieder aufnehmen oder löschen.
	 */
	public function handle_article_state() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$id  = isset( $_POST['article_id'] ) ? (int) $_POST['article_id'] : 0;
		$was = isset( $_POST['was'] ) ? sanitize_key( wp_unslash( $_POST['was'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$article = $id ? ArticleRepository::find( $id ) : null;

		if ( ! $article ) {
			$this->redirect( __( 'Diesen Artikel gibt es nicht mehr.', 'lindenweg16' ), 'error' );
		}

		if ( 'loeschen' === $was ) {
			$result = ArticleRepository::delete( $id );
			$this->redirect( $result['message'], $result['success'] ? 'success' : 'error' );
		}

		// Stilllegen heisst ausgelaufen, nicht gesperrt: Gesperrt ist ein
		// Qualitaetsurteil, ausgelaufen eine Sortimentsentscheidung.
		$neu = 'stilllegen' === $was ? 'ausgelaufen' : 'aktiv';

		ArticleRepository::set_status( $id, $neu );

		$this->redirect(
			sprintf(
				/* translators: 1: Artikelnummer, 2: neuer Status. */
				__( '%1$s ist jetzt %2$s.', 'lindenweg16' ),
				$article['sku'],
				ArticleRepository::status_labels()[ $neu ]
			),
			'success'
		);
	}

	/**
	 * Stilllegen, wieder aufnehmen, löschen.
	 *
	 * Löschen steht nur da, wenn es auch geht. Ein Knopf, der jedes Mal
	 * mit einer Fehlermeldung antwortet, ist schlimmer als keiner.
	 *
	 * @param array $row Artikel.
	 */
	protected function render_state_actions( array $row ) {
		$id     = (int) $row['id'];
		$aktiv  = ArticleRepository::is_active( $row );
		$knopf  = static function ( $was, $label, $frage = '' ) use ( $id ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="lw16_betrieb_article_state" />';
			printf( '<input type="hidden" name="was" value="%s" />', esc_attr( $was ) );
			printf( '<input type="hidden" name="article_id" value="%d" />', $id );
			printf(
				'<button class="button-link"%s>%s</button>',
				$frage ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $frage ) ) . ')"' : '',
				esc_html( $label )
			);
			echo '</form> ';
		};

		if ( $aktiv ) {
			$knopf(
				'stilllegen',
				__( 'Stilllegen', 'lindenweg16' ),
				__( 'Stilllegen? Der Artikel verschwindet aus allen Auswahllisten. Bestand, Bewegungen und Auswertungen bleiben.', 'lindenweg16' )
			);
		} else {
			$knopf( 'aufnehmen', __( 'Wieder aufnehmen', 'lindenweg16' ) );
		}

		$blocker = ArticleRepository::delete_blockers( $id );

		if ( $blocker ) {
			printf(
				'<span class="description" title="%s">%s</span>',
				esc_attr( sprintf(
					/* translators: %s: Aufzählung. */
					__( 'Nicht löschbar: %s', 'lindenweg16' ),
					implode( ', ', $blocker )
				) ),
				esc_html__( 'nicht löschbar', 'lindenweg16' )
			);

			return;
		}

		$knopf(
			'loeschen',
			__( 'Löschen', 'lindenweg16' ),
			__( 'Wirklich löschen? An diesem Artikel hängt nichts – Stückliste, Revisionen und Beschaffungsdaten gehen mit.', 'lindenweg16' )
		);
	}

	/* ====================================================================
	 * Reiter
	 * ================================================================== */

	/**
	 * Artikelstamm.
	 */
	protected function tab_articles() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$edit    = $edit_id ? ArticleRepository::find( $edit_id ) : null;
		$rows    = ArticleRepository::all();
		$status  = ArticleRepository::status_labels();
		$sourcing = ArticleRepository::sourcing_labels();

		echo '<h2>' . esc_html__( 'Artikel', 'lindenweg16' ) . '</h2>';

		if ( $rows ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Nummer', 'lindenweg16' ),
				__( 'Bezeichnung', 'lindenweg16' ),
				__( 'Beschaffung', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
				__( 'VK brutto', 'lindenweg16' ),
				__( 'Händlerpreis', 'lindenweg16' ),
				__( 'Traglast', 'lindenweg16' ),
				__( 'verkäuflich', 'lindenweg16' ),
				__( 'Etikett', 'lindenweg16' ),
				'',
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				$id = (int) $row['id'];
				echo '<tr>';
				printf( '<td><strong>%s</strong></td>', esc_html( $row['sku'] ) );
				printf( '<td>%s</td>', esc_html( $row['name'] ) );
				printf( '<td>%s</td>', esc_html( isset( $sourcing[ $row['sourcing_type'] ] ) ? $sourcing[ $row['sourcing_type'] ] : $row['sourcing_type'] ) );
				printf( '<td>%s</td>', esc_html( isset( $status[ $row['status'] ] ) ? $status[ $row['status'] ] : $row['status'] ) );
				printf( '<td>%s</td>', esc_html( null === $row['price_gross'] ? '—' : $this->money( $row['price_gross'] ) ) );
				printf( '<td>%s</td>', esc_html( null === $row['dealer_price'] ? '—' : $this->money( $row['dealer_price'] ) ) );
				printf( '<td>%s</td>', esc_html( null === $row['load_rating_kg'] ? '—' : number_format_i18n( (float) $row['load_rating_kg'], 1 ) . ' kg' ) );
				printf( '<td>%s</td>', $row['sellable'] ? esc_html__( 'ja', 'lindenweg16' ) : esc_html__( 'nein', 'lindenweg16' ) );

				printf(
					'<td><a class="button button-small" href="%s" target="_blank" rel="noopener">%s</a></td>',
					esc_url( BinsPage::label_link( 'articles', $row['sku'] ) ),
					esc_html__( 'Etikett', 'lindenweg16' )
				);

				echo '<td>';
				foreach ( array(
					'artikel'     => array( __( 'Bearbeiten', 'lindenweg16' ), array( 'tab' => 'artikel', 'edit' => $id ) ),
					'revisionen'  => array( __( 'Revisionen', 'lindenweg16' ), array( 'tab' => 'revisionen', 'article' => $id ) ),
					'stueckliste' => array( __( 'Stückliste', 'lindenweg16' ), array( 'tab' => 'stueckliste', 'article' => $id ) ),
					'kalkulation' => array( __( 'Kalkulation', 'lindenweg16' ), array( 'tab' => 'kalkulation', 'article' => $id ) ),
				) as $link ) {
					printf(
						'<a href="%s">%s</a> ',
						esc_url( add_query_arg( array_merge( array( 'page' => self::SLUG ), $link[1] ), admin_url( 'admin.php' ) ) ),
						esc_html( $link[0] )
					);
				}

				$this->render_state_actions( $row );

				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Noch keine Artikel erfasst.', 'lindenweg16' ) . '</p>';
		}

		echo '<h2>' . esc_html( $edit ? __( 'Artikel bearbeiten', 'lindenweg16' ) : __( 'Artikel anlegen', 'lindenweg16' ) ) . '</h2>';

		$this->form_open( 'article' );
		printf( '<input type="hidden" name="article_id" value="%d" />', $edit ? (int) $edit['id'] : 0 );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->field( __( 'Artikelnummer', 'lindenweg16' ), 'sku', $this->val( $edit, 'sku' ), 'text', __( 'Pflicht, eindeutig, z. B. HH-001.', 'lindenweg16' ) );
		$this->field( __( 'Bezeichnung', 'lindenweg16' ), 'name', $this->val( $edit, 'name' ) );
		// Bei einem neuen Artikel steht bewusst nichts vorne: Der
		// Beschaffungsweg entscheidet über alles Weitere, den soll niemand
		// versehentlich übernehmen.
		$wege = $edit ? $sourcing : array( '' => __( '— bitte wählen —', 'lindenweg16' ) ) + $sourcing;

		$this->select(
			__( 'Beschaffungsweg', 'lindenweg16' ),
			'sourcing_type',
			$wege,
			$edit ? $edit['sourcing_type'] : '',
			__( 'Rohling = kommt aus dem Drucker, braucht eine Revision mit Gewicht und Druckzeit. Set/Baugruppe/Halbzeug = besteht aus anderen Artikeln und Komponenten, hat selbst kein Druckprofil – die Teile darin sind einzeln revisioniert; erfasst wird das im Reiter Stückliste. Zukauf = wird bestellt, Kosten aus dem Reiter Beschaffung. Hybrid = beides.', 'lindenweg16' )
		);
		$this->select( __( 'Status', 'lindenweg16' ), 'status', $status, $edit ? $edit['status'] : 'entwicklung' );
		$this->checkbox( __( 'Im Shop verkäuflich', 'lindenweg16' ), 'sellable', $edit ? $edit['sellable'] : 1, __( 'Abstandshalter und Wandanker sind eigene Artikel, aber nicht verkäuflich.', 'lindenweg16' ) );
		$this->checkbox( __( 'Rabattfähig', 'lindenweg16' ), 'discountable', $edit ? $edit['discountable'] : 1, __( 'Personalisierte Artikel sind von der Händlerstaffel ausgenommen.', 'lindenweg16' ) );
		$this->field(
			__( 'VK brutto (€)', 'lindenweg16' ),
			'price_gross',
			$this->val( $edit, 'price_gross' ),
			'text',
			__( 'Der Preis dieses Artikels – hier und nirgends sonst. Ist ein Shop-Produkt verknüpft, bekommt es den Normalpreis beim Speichern automatisch; ein laufendes Angebot in WooCommerce bleibt unberührt.', 'lindenweg16' )
		);
		$this->field( __( 'Händlerpreis (€)', 'lindenweg16' ), 'dealer_price', $this->val( $edit, 'dealer_price' ) );
		$this->field( __( 'Nachbearbeitung (min)', 'lindenweg16' ), 'postprocessing_min', $this->val( $edit, 'postprocessing_min' ), 'text', __( 'Entgraten, Gewindeeinsätze setzen, Beipack.', 'lindenweg16' ) );
		$this->field( __( 'Traglast (kg)', 'lindenweg16' ), 'load_rating_kg', $this->val( $edit, 'load_rating_kg' ), 'text', __( 'Angegebene Traglast. Erscheint in der Chargenakte.', 'lindenweg16' ) );
		$this->field( __( 'Barcode', 'lindenweg16' ), 'barcode', $this->val( $edit, 'barcode' ), 'text', __( 'Fremder Barcode zugekaufter Ware (EAN). Wird beim Scannen erkannt – dann muss nichts umetikettiert werden.', 'lindenweg16' ) );
		$this->product_picker( __( 'Shop-Produkt', 'lindenweg16' ), 'wc_product_id', (int) $this->val( $edit, 'wc_product_id' ), __( 'Optional, aber nötig für Lagerabgang beim Verkauf, Produktbild und Nachbestellseite. Tippen filtert nach Name, Artikelnummer oder ID.', 'lindenweg16' ) );
		$this->select( __( 'Standardplatz', 'lindenweg16' ), 'default_bin_id', $this->bin_options(), (int) $this->val( $edit, 'default_bin_id' ), __( 'Wo das Teil üblicherweise liegt. Nur ein Vorschlag für Buchungsmaske und Pickliste – der Bestand je Platz kommt weiter aus den Bewegungen, und das Teil darf auch woanders liegen.', 'lindenweg16' ) );

		echo '<tr><th scope="row"><label for="lw16-notes">' . esc_html__( 'Bemerkung', 'lindenweg16' ) . '</label></th>';
		printf( '<td><textarea name="notes" id="lw16-notes" rows="3" class="large-text">%s</textarea></td></tr>', esc_textarea( $this->val( $edit, 'notes' ) ) );

		if ( $edit ) {
			$this->field( __( 'Begründung der Änderung', 'lindenweg16' ), 'reason', '', 'text', __( 'Erscheint im Protokoll.', 'lindenweg16' ) );
		}

		echo '</tbody></table>';
		printf( '<p><button class="button button-primary">%s</button></p></form>', esc_html( $edit ? __( 'Speichern', 'lindenweg16' ) : __( 'Anlegen', 'lindenweg16' ) ) );
	}

	/**
	 * Komponenten.
	 */
	protected function tab_components() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$edit    = $edit_id ? ComponentRepository::find( $edit_id ) : null;
		$rows    = ComponentRepository::all();
		$labels  = ComponentRepository::category_labels();
		$offen   = PurchaseRepository::open();

		echo '<h2>' . esc_html__( 'Komponenten', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Kleinteile werden im Gebinde gekauft und im Stück verbraucht. Der Stückpreis wird deshalb gerechnet, nicht gepflegt.', 'lindenweg16' ) . '</p>';

		if ( $rows ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Code', 'lindenweg16' ),
				__( 'Bezeichnung', 'lindenweg16' ),
				__( 'Kategorie', 'lindenweg16' ),
				__( 'Lieferant', 'lindenweg16' ),
				__( 'Gebinde', 'lindenweg16' ),
				__( 'Gebindepreis', 'lindenweg16' ),
				__( 'Stückpreis', 'lindenweg16' ),
				__( 'Bestand', 'lindenweg16' ),
				__( 'Lieferzeit', 'lindenweg16' ),
				__( 'Unterwegs', 'lindenweg16' ),
				__( 'Nachbestellen', 'lindenweg16' ),
				'',
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				echo '<tr>';
				printf( '<td><strong>%s</strong></td>', esc_html( $row['code'] ) );
				printf( '<td>%s</td>', esc_html( $row['name'] ) );
				printf( '<td>%s</td>', esc_html( isset( $labels[ $row['category'] ] ) ? $labels[ $row['category'] ] : $row['category'] ) );
				printf( '<td>%s</td>', esc_html( (string) $row['supplier'] ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $row['pack_qty'], 0 ) ) );
				printf( '<td>%s</td>', esc_html( $this->money( $row['pack_price_net'] ) ) );
				printf( '<td><strong>%s</strong></td>', esc_html( $this->money( CostCalculator::unit_price( $row ), 4 ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $row['stock_qty'], 0 ) ) );
				printf( '<td>%s</td>', esc_html( sprintf( /* translators: %d: Tage. */ __( '%d Tage', 'lindenweg16' ), (int) $row['lead_time_days'] ) ) );

				$unterwegs = Purchase::incoming( $offen, 'component', (int) $row['id'] );
				printf(
					'<td>%s</td>',
					esc_html( $unterwegs > 0 ? number_format_i18n( $unterwegs, 0 ) : '—' )
				);

				echo '<td>';
				$this->order_button( 'component', $row );
				echo '</td>';

				printf(
					'<td><a href="%s">%s</a></td>',
					esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'komponenten', 'edit' => (int) $row['id'] ), admin_url( 'admin.php' ) ) ),
					esc_html__( 'Bearbeiten', 'lindenweg16' )
				);
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Noch keine Komponenten erfasst.', 'lindenweg16' ) . '</p>';
		}

		$this->render_purchases( $rows );
		$this->render_purchase_dialog();

		echo '<h2>' . esc_html( $edit ? __( 'Komponente bearbeiten', 'lindenweg16' ) : __( 'Komponente anlegen', 'lindenweg16' ) ) . '</h2>';

		$this->form_open( 'component' );
		printf( '<input type="hidden" name="component_id" value="%d" />', $edit ? (int) $edit['id'] : 0 );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->field( __( 'Code', 'lindenweg16' ), 'code', $this->val( $edit, 'code' ), 'text', __( 'Pflicht, eindeutig, z. B. SCHR-M4-80.', 'lindenweg16' ) );
		$this->field( __( 'Barcode', 'lindenweg16' ), 'barcode', $this->val( $edit, 'barcode' ), 'text', __( 'Barcode des Lieferanten, falls die Packung einen trägt.', 'lindenweg16' ) );
		$this->field( __( 'Bezeichnung', 'lindenweg16' ), 'name', $this->val( $edit, 'name' ) );
		$this->select( __( 'Kategorie', 'lindenweg16' ), 'category', $labels, $edit ? $edit['category'] : 'sonstiges' );
		$this->field( __( 'Lieferant', 'lindenweg16' ), 'supplier', $this->val( $edit, 'supplier' ) );
		$this->field( __( 'Gebindegröße', 'lindenweg16' ), 'pack_qty', $edit ? $this->val( $edit, 'pack_qty' ) : '100', 'text', __( 'Stück je Packung, z. B. 100 Dübel.', 'lindenweg16' ) );
		$this->field( __( 'Gebindepreis netto (€)', 'lindenweg16' ), 'pack_price_net', $this->val( $edit, 'pack_price_net' ), 'text', __( 'Der Stückpreis ergibt sich daraus automatisch.', 'lindenweg16' ) );
		$this->field( __( 'Bestell-Link', 'lindenweg16' ), 'order_url', $this->val( $edit, 'order_url' ), 'url', __( 'Adresse der Artikelseite beim Lieferanten. Der Knopf „Bestellen" öffnet sie in einem neuen Tab und hier gleichzeitig das Formular für die Bestelldaten. Nur http- und https-Adressen.', 'lindenweg16' ) );
		$this->field( __( 'Mindestbestellmenge', 'lindenweg16' ), 'min_order_qty', $edit ? $this->val( $edit, 'min_order_qty' ) : '1' );
		$this->field( __( 'Lieferzeit (Tage)', 'lindenweg16' ), 'lead_time_days', $this->val( $edit, 'lead_time_days' ) );
		$this->field( __( 'Bestand', 'lindenweg16' ), 'stock_qty', $this->val( $edit, 'stock_qty' ) );
		$this->field( __( 'Sicherheitspuffer (Tage)', 'lindenweg16' ), 'safety_days', $edit ? $this->val( $edit, 'safety_days' ) : '7', 'text', __( 'Zusätzlich zur Lieferzeit, bevor der Beschaffungsalarm auslöst.', 'lindenweg16' ) );
		$this->select( __( 'Standardplatz', 'lindenweg16' ), 'default_bin_id', $this->bin_options(), (int) $this->val( $edit, 'default_bin_id' ), __( 'Wo die Kiste steht. Kleinteile führen kein Bewegungskonto – hier ist der Platz deshalb die einzige Angabe, aus der eine Pickliste den Weg kennt.', 'lindenweg16' ) );

		echo '<tr><th scope="row"><label for="lw16-note">' . esc_html__( 'Bemerkung', 'lindenweg16' ) . '</label></th>';
		printf( '<td><textarea name="note" id="lw16-note" rows="2" class="large-text">%s</textarea></td></tr>', esc_textarea( $this->val( $edit, 'note' ) ) );

		if ( $edit ) {
			$this->field( __( 'Begründung der Änderung', 'lindenweg16' ), 'reason' );
		}

		echo '</tbody></table>';
		printf( '<p><button class="button button-primary">%s</button></p></form>', esc_html( $edit ? __( 'Speichern', 'lindenweg16' ) : __( 'Anlegen', 'lindenweg16' ) ) );
	}

	/**
	 * Revisionen eines Artikels.
	 *
	 * @param int $article_id Artikel.
	 */
	protected function tab_revisions( $article_id ) {
		$this->article_switcher( $article_id, 'revisionen' );

		$article = $article_id ? ArticleRepository::find( $article_id ) : null;
		if ( ! $article ) {
			echo '<p>' . esc_html__( 'Bitte oben einen Artikel wählen.', 'lindenweg16' ) . '</p>';
			return;
		}

		if ( in_array( $article['sourcing_type'], array( 'zukauf', 'montage' ), true ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Dieser Artikel hat kein eigenes Druckprofil und braucht auch keins. Ein Set oder eine Baugruppe besteht aus anderen Artikeln und Komponenten – die sind jeweils für sich revisioniert. Was drin ist, steht im Reiter Stückliste; ein Zukaufartikel kommt aus dem Reiter Beschaffung.', 'lindenweg16' ) . '</p></div>';
			return;
		}

		$rows = RevisionRepository::for_article( $article_id );

		printf( '<h2>%s</h2>', esc_html( sprintf( /* translators: 1: Artikelnummer, 2: Bezeichnung. */ __( 'Revisionen von %1$s – %2$s', 'lindenweg16' ), $article['sku'], $article['name'] ) ) );

		if ( $rows ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Version', 'lindenweg16' ),
				__( 'Material', 'lindenweg16' ),
				__( 'Farbe', 'lindenweg16' ),
				__( 'Gewicht', 'lindenweg16' ),
				__( 'Teile je Platte', 'lindenweg16' ),
				__( 'Druckzeit Platte', 'lindenweg16' ),
				__( 'Druckzeit je Teil', 'lindenweg16' ),
				__( '3MF', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
				'',
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				echo '<tr>';
				printf( '<td><strong>%s</strong></td>', esc_html( $row['version'] ) );
				printf( '<td>%s</td>', esc_html( (string) $row['material'] ) );
				printf( '<td>%s</td>', esc_html( $this->color_label( (string) $row['color'] ) ) );
				printf( '<td>%s g</td>', esc_html( number_format_i18n( (float) $row['weight_g'], 1 ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $row['parts_per_plate'], 0 ) ) );
				printf( '<td>%s h</td>', esc_html( number_format_i18n( (float) $row['print_time_plate_h'], 2 ) ) );
				printf( '<td><strong>%s h</strong></td>', esc_html( number_format_i18n( CostCalculator::print_time_per_part( $row ), 3 ) ) );

				echo '<td>';
				$this->model_cell( $row, $article_id );
				echo '</td>';

				echo '<td>';
				if ( $row['is_active'] ) {
					printf( '<span class="lw16-ampel lw16-ampel--gruen">%s</span>', esc_html__( 'aktiv', 'lindenweg16' ) );
				} else {
					printf( '<span class="lw16-ampel lw16-ampel--gelb">%s</span>', esc_html__( 'inaktiv', 'lindenweg16' ) );
				}
				echo '</td>';

				echo '<td>';
				if ( ! $row['is_active'] ) {
					$this->form_open( 'activate' );
					printf( '<input type="hidden" name="revision_id" value="%d" />', (int) $row['id'] );
					printf( '<input type="hidden" name="article_id" value="%d" />', (int) $article_id );
					printf( '<button class="button">%s</button>', esc_html__( 'Aktiv setzen', 'lindenweg16' ) );
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Noch keine Revision erfasst.', 'lindenweg16' ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Revision anlegen', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Neue Revisionen sind zunächst inaktiv. Aktiv gesetzt wird immer genau eine Revision je Artikel – sie löst die bisherige ab.', 'lindenweg16' ) . '</p>';

		$this->form_open( 'revision', true );
		printf( '<input type="hidden" name="article_id" value="%d" />', (int) $article_id );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->field( __( 'Version', 'lindenweg16' ), 'version', '', 'text', __( 'z. B. v1.0', 'lindenweg16' ) );
		$this->select( __( 'Material', 'lindenweg16' ), 'material', array_combine( RevisionRepository::MATERIALS, RevisionRepository::MATERIALS ), 'PETG' );
		$this->select(
			__( 'Farbe', 'lindenweg16' ),
			'color',
			$this->filament_color_options(),
			'',
			__( 'Nur Farben, für die tatsächlich ein Slicing-Profil hinterlegt ist (Betrieb → Bambu-Lab-Drucker → Slicing-Profile, dort auch umbenennbar) - das automatische Slicen kann damit nie an einer fehlenden Farbzuordnung scheitern.', 'lindenweg16' )
		);
		$this->field( __( 'Bauteilgewicht (g)', 'lindenweg16' ), 'weight_g', '', 'text', __( 'Aus dem Slicer. Grundlage der Materialkosten.', 'lindenweg16' ) );
		$this->field( __( 'Teile je Platte', 'lindenweg16' ), 'parts_per_plate', '1' );
		$this->field( __( 'Druckzeit je Platte (h)', 'lindenweg16' ), 'print_time_plate_h', '', 'text', __( 'Die Druckzeit je Teil ergibt sich daraus automatisch.', 'lindenweg16' ) );
		$this->field( __( 'Düse (mm)', 'lindenweg16' ), 'nozzle_mm', '0.4' );
		$this->field( __( 'Schichthöhe (mm)', 'lindenweg16' ), 'layer_mm', '0.2' );
		$this->field( __( 'Perimeter', 'lindenweg16' ), 'perimeters', '5' );
		$this->field( __( 'Infill (Anteil)', 'lindenweg16' ), 'infill_pct', '0.35' );
		$this->field( __( 'Infill-Muster', 'lindenweg16' ), 'infill_pattern' );
		$this->field( __( 'Düsentemperatur (°C)', 'lindenweg16' ), 'nozzle_temp' );
		$this->field( __( 'Betttemperatur (°C)', 'lindenweg16' ), 'bed_temp' );
		$this->field( __( 'Kühlung (%)', 'lindenweg16' ), 'cooling_pct' );
		$this->checkbox( __( 'Stützen', 'lindenweg16' ), 'supports', false );

		echo '<tr><th scope="row"><label for="lw16-model">' . esc_html__( '3MF-Datei', 'lindenweg16' ) . '</label></th>';
		printf(
			'<td><input type="file" name="model" id="lw16-model" accept=".3mf" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Die Datei, aus der diese Version gedruckt wird – am besten die Projektdatei mit dem Modell und allen Druckeinstellungen. Sie hängt an der Revision: wer die aktive Revision lädt, hat immer den freigegebenen Stand. Vervielfältigt wird im Slicer, der ordnet die Teile besser an. Auch eine geslicte Druckdatei (.gcode.3mf) ist erlaubt; aus ihr liest das System Druckzeit, Materialverbrauch und Teile je Platte selbst aus.', 'lindenweg16' )
		);

		echo '<tr><th scope="row"><label for="lw16-orientation_note">' . esc_html__( 'Orientierung', 'lindenweg16' ) . '</label></th>';
		printf(
			'<td><textarea name="orientation_note" id="lw16-orientation_note" rows="2" class="large-text" placeholder="%s"></textarea></td></tr>',
			esc_attr__( 'z. B. Last quer zur Z-Achse', 'lindenweg16' )
		);

		echo '</tbody></table>';
		printf( '<p><button class="button button-primary">%s</button></p></form>', esc_html__( 'Revision anlegen', 'lindenweg16' ) );
	}

	/**
	 * Die 3MF-Spalte einer Revisionszeile.
	 *
	 * @param array $row        Revision.
	 * @param int   $article_id Artikel.
	 */
	protected function model_cell( array $row, $article_id ) {
		$has = ! empty( $row['model_file'] );

		if ( $has ) {
			$link = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'lw16_betrieb_model_get',
						'revision' => (int) $row['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'lw16_betrieb_model_' . (int) $row['id']
			);

			printf(
				'<a href="%s">%s</a><br /><span class="description">%s · %s</span><br />',
				esc_url( $link ),
				esc_html( (string) $row['model_name'] ),
				esc_html( ModelFile::kind_label( (string) $row['model_kind'] ) ),
				esc_html( size_format( (int) $row['model_bytes'] ) )
			);

			$this->form_open( 'model_remove' );
			printf( '<input type="hidden" name="revision_id" value="%d" />', (int) $row['id'] );
			printf( '<input type="hidden" name="article_id" value="%d" />', (int) $article_id );
			printf(
				'<button class="button-link delete" onclick="return confirm(\'%s\')">%s</button>',
				esc_attr__( 'Datei wirklich entfernen?', 'lindenweg16' ),
				esc_html__( 'entfernen', 'lindenweg16' )
			);
			echo '</form>';
			return;
		}

		echo '<span class="description">' . esc_html__( 'keine Datei', 'lindenweg16' ) . '</span><br />';

		$this->form_open( 'revision', true );
		printf( '<input type="hidden" name="revision_id" value="%d" />', (int) $row['id'] );
		printf( '<input type="hidden" name="article_id" value="%d" />', (int) $article_id );

		// Die Revision wird mit ihren eigenen Werten erneut gespeichert –
		// so bleibt der Datensatz unverändert und nur die Datei kommt dazu.
		foreach ( array( 'version', 'material', 'color', 'weight_g', 'parts_per_plate', 'print_time_plate_h', 'nozzle_mm', 'layer_mm', 'perimeters', 'infill_pct', 'infill_pattern', 'nozzle_temp', 'bed_temp', 'cooling_pct', 'orientation_note' ) as $keep ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $keep ), esc_attr( (string) $row[ $keep ] ) );
		}
		if ( ! empty( $row['supports'] ) ) {
			echo '<input type="hidden" name="supports" value="1" />';
		}

		echo '<input type="file" name="model" accept=".3mf" style="max-width:12em" /> ';
		printf( '<button class="button button-small">%s</button>', esc_html__( 'anhängen', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Der Knopf „Bestellen" einer Zeile.
	 *
	 * Ohne hinterlegten Link bleibt er ein einfacher Knopf, der nur das
	 * Formular öffnet – bestellt wird dann eben per Telefon, erfasst wird
	 * es trotzdem.
	 *
	 * @param string $type component oder supply.
	 * @param array  $row  Datensatz.
	 */
	protected function order_button( $type, array $row ) {
		$url   = Purchase::clean_url( isset( $row['order_url'] ) ? $row['order_url'] : '' );
		$preis = 'supply' === $type
			? (float) $row['price_per_unit']
			: CostCalculator::unit_price( $row );

		printf(
			'<a class="button button-small lw16-bestellen" href="%s"%s data-typ="%s" data-id="%d" data-name="%s" data-menge="%s" data-preis="%s" data-lieferant="%s" data-lieferzeit="%d">%s</a>',
			$url ? esc_url( $url ) : '#',
			$url ? ' target="_blank" rel="noopener"' : '',
			esc_attr( $type ),
			(int) $row['id'],
			esc_attr( $row['name'] ),
			esc_attr( number_format( (float) $row['min_order_qty'], 3, '.', '' ) ),
			esc_attr( number_format( $preis, 4, '.', '' ) ),
			esc_attr( (string) $row['supplier'] ),
			(int) $row['lead_time_days'],
			esc_html__( 'Bestellen', 'lindenweg16' )
		);
	}

	/**
	 * Was gerade unterwegs ist, mit Wareneingang.
	 *
	 * @param array $components Komponenten, für die Namensauflösung.
	 */
	protected function render_purchases( array $components ) {
		$offen = PurchaseRepository::open();

		echo '<h2>' . esc_html__( 'Unterwegs', 'lindenweg16' ) . '</h2>';

		if ( ! $offen ) {
			echo '<p>' . esc_html__( 'Nichts bestellt. Wenn du beim Lieferanten bestellst, halte es hier fest – dann weißt du beim nächsten leeren Fach, ob schon etwas unterwegs ist.', 'lindenweg16' ) . '</p>';
			return;
		}

		$namen = array();
		foreach ( $components as $component ) {
			$namen[ (int) $component['id'] ] = $component['code'] . ' – ' . $component['name'];
		}

		$heute  = gmdate( 'Y-m-d' );
		$labels = Purchase::type_labels();

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Bestellt am', 'lindenweg16' ),
			__( 'Was', 'lindenweg16' ),
			__( 'Menge', 'lindenweg16' ),
			__( 'Wert netto', 'lindenweg16' ),
			__( 'Lieferant', 'lindenweg16' ),
			__( 'Bestellnummer', 'lindenweg16' ),
			__( 'Erwartet', 'lindenweg16' ),
			__( 'Wareneingang', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $offen as $purchase ) {
			$id        = (int) $purchase['item_id'];
			$name      = 'component' === $purchase['item_type'] && isset( $namen[ $id ] )
				? $namen[ $id ]
				: $labels[ $purchase['item_type'] ] . ' #' . $id;
			$verspaetet = Purchase::overdue_days( $purchase, $heute );

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $purchase['ordered_on'] ) ) );
			printf( '<td><strong>%s</strong></td>', esc_html( $name ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $purchase['qty'], 0 ) ) );
			printf( '<td>%s</td>', esc_html( $this->money( Purchase::total( $purchase ) ) ) );
			printf( '<td>%s</td>', esc_html( (string) $purchase['supplier'] ) );
			printf( '<td>%s</td>', esc_html( (string) $purchase['order_ref'] ) );

			// Überfällig wird genannt, nicht nur eingefärbt.
			printf(
				'<td>%s%s</td>',
				esc_html( $purchase['expected_on'] ? mysql2date( 'd.m.Y', $purchase['expected_on'] ) : '—' ),
				null !== $verspaetet && $verspaetet > 0
					? sprintf(
						'<br /><span class="lw16-ampel lw16-ampel--rot">%s</span>',
						esc_html(
							sprintf(
								/* translators: %d: Tage. */
								_n( '%d Tag überfällig', '%d Tage überfällig', $verspaetet, 'lindenweg16' ),
								$verspaetet
							)
						)
					)
					: ''
			);

			echo '<td>';
			$this->form_open( 'purchase_state' );
			printf( '<input type="hidden" name="purchase_id" value="%d" />', (int) $purchase['id'] );
			printf(
				'<input type="text" name="received_qty" value="%s" size="5" /> ',
				esc_attr( number_format( (float) $purchase['qty'], 0, '.', '' ) )
			);
			printf( '<button class="button button-small">%s</button> ', esc_html__( 'eingegangen', 'lindenweg16' ) );
			printf(
				'<button class="button-link delete" name="cancel" value="1" onclick="return confirm(\'%s\')">%s</button>',
				esc_attr__( 'Bestellung wirklich stornieren?', 'lindenweg16' ),
				esc_html__( 'storniert', 'lindenweg16' )
			);
			echo '</form></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Der Wareneingang erhöht den Bestand um die tatsächlich gelieferte Menge – nicht um die bestellte. Er lässt sich je Bestellung nur einmal buchen.', 'lindenweg16' ) . '</p>';
	}

	/**
	 * Das Formular, das beim Bestellen aufgeht.
	 */
	protected function render_purchase_dialog() {
		echo '<dialog id="lw16-bestellung" class="lw16-dialog" hidden>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_purchase" />';
		echo '<input type="hidden" name="item_type" value="component" />';
		echo '<input type="hidden" name="item_id" value="0" />';

		echo '<h2>' . esc_html__( 'Bestellung vermerken', 'lindenweg16' ) . '</h2>';
		echo '<p class="lw16-bestellung__titel"></p>';
		echo '<p class="description">' . esc_html__( 'Der Shop ist in einem neuen Tab offen. Trag hier ein, was du dort tatsächlich bestellst – jetzt, solange du die Zahlen vor dir hast.', 'lindenweg16' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->field( __( 'Menge', 'lindenweg16' ), 'qty', '' );
		$this->field( __( 'Stückpreis netto (€)', 'lindenweg16' ), 'unit_price_net', '' );
		$this->field( __( 'Versand netto (€)', 'lindenweg16' ), 'shipping_net', '0' );
		$this->field( __( 'Lieferant', 'lindenweg16' ), 'supplier', '' );
		$this->field( __( 'Bestellnummer', 'lindenweg16' ), 'order_ref', '', 'text', __( 'Die Nummer aus der Bestellbestätigung – damit findest du die Lieferung später wieder.', 'lindenweg16' ) );
		$this->field( __( 'Bestellt am', 'lindenweg16' ), 'ordered_on', '', 'date' );
		$this->field( __( 'Voraussichtlich da', 'lindenweg16' ), 'expected_on', '', 'date', __( 'Vorbelegt aus der hinterlegten Lieferzeit. Was der Shop sagt, ist genauer.', 'lindenweg16' ) );
		$this->field( __( 'Notiz', 'lindenweg16' ), 'note', '' );
		echo '</tbody></table>';

		printf( '<p><button class="button button-primary">%s</button> ', esc_html__( 'Bestellung vermerken', 'lindenweg16' ) );
		printf( '<button type="button" class="button" data-schliessen="1">%s</button></p>', esc_html__( 'Abbrechen', 'lindenweg16' ) );
		echo '</form></dialog>';
	}

	/**
	 * Stückliste eines Artikels.
	 *
	 * @param int $article_id Artikel.
	 */
	protected function tab_bom( $article_id ) {
		$this->article_switcher( $article_id, 'stueckliste' );

		$article = $article_id ? ArticleRepository::find( $article_id ) : null;
		if ( ! $article ) {
			echo '<p>' . esc_html__( 'Bitte oben einen Artikel wählen.', 'lindenweg16' ) . '</p>';
			return;
		}

		$rows       = BomRepository::for_article( $article_id );
		$articles   = ArticleRepository::all_by_id();
		$components = ComponentRepository::all_by_id();

		printf( '<h2>%s</h2>', esc_html( sprintf( /* translators: 1: Artikelnummer, 2: Bezeichnung. */ __( 'Stückliste von %1$s – %2$s', 'lindenweg16' ), $article['sku'], $article['name'] ) ) );

		if ( $rows ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Pos.', 'lindenweg16' ),
				__( 'Typ', 'lindenweg16' ),
				__( 'Position', 'lindenweg16' ),
				__( 'Menge', 'lindenweg16' ),
				__( 'Bemerkung', 'lindenweg16' ),
				'',
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				$child_id = (int) $row['child_id'];
				if ( 'artikel' === $row['child_type'] ) {
					$label = isset( $articles[ $child_id ] )
						? $articles[ $child_id ]['sku'] . ' – ' . $articles[ $child_id ]['name']
						: __( '(gelöscht)', 'lindenweg16' );
					$typ   = __( 'Unterbaugruppe', 'lindenweg16' );
				} else {
					$label = isset( $components[ $child_id ] )
						? $components[ $child_id ]['code'] . ' – ' . $components[ $child_id ]['name']
						: __( '(gelöscht)', 'lindenweg16' );
					$typ   = __( 'Komponente', 'lindenweg16' );
				}

				echo '<tr>';
				printf( '<td>%d</td>', (int) $row['position'] );
				printf( '<td>%s</td>', esc_html( $typ ) );
				printf( '<td>%s</td>', esc_html( $label ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $row['qty'], 3 ) ) );
				printf( '<td>%s</td>', esc_html( (string) $row['note'] ) );

				echo '<td>';
				$this->form_open( 'bom_delete' );
				printf( '<input type="hidden" name="bom_id" value="%d" />', (int) $row['id'] );
				printf( '<input type="hidden" name="parent_article_id" value="%d" />', (int) $article_id );
				printf(
					'<input type="text" name="reason" placeholder="%s" size="14" /> <button class="button button-link-delete">%s</button>',
					esc_attr__( 'Begründung', 'lindenweg16' ),
					esc_html__( 'Entfernen', 'lindenweg16' )
				);
				echo '</form></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Noch keine Positionen. Ohne Stückliste fehlen die Kleinteilkosten in der Kalkulation.', 'lindenweg16' ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Position hinzufügen', 'lindenweg16' ) . '</h2>';

		$this->form_open( 'bom' );
		printf( '<input type="hidden" name="parent_article_id" value="%d" />', (int) $article_id );
		echo '<table class="form-table" role="presentation"><tbody>';

		$options = array();
		foreach ( $components as $component ) {
			$options[ 'komponente:' . (int) $component['id'] ] = sprintf(
				/* translators: 1: Code, 2: Bezeichnung. */
				__( 'Komponente: %1$s – %2$s', 'lindenweg16' ),
				$component['code'],
				$component['name']
			);
		}
		foreach ( $articles as $candidate ) {
			if ( (int) $candidate['id'] === (int) $article_id ) {
				continue;
			}
			$options[ 'artikel:' . (int) $candidate['id'] ] = sprintf(
				/* translators: 1: Artikelnummer, 2: Bezeichnung. */
				__( 'Artikel: %1$s – %2$s', 'lindenweg16' ),
				$candidate['sku'],
				$candidate['name']
			);
		}

		$this->select( __( 'Position', 'lindenweg16' ), 'child', $options, '', __( 'Ein Artikel als Position bildet eine Unterbaugruppe mit eigenem Druckprofil und eigener Charge.', 'lindenweg16' ) );
		$this->field( __( 'Menge', 'lindenweg16' ), 'qty', '1' );
		$this->field( __( 'Reihenfolge', 'lindenweg16' ), 'position', (string) ( count( $rows ) + 1 ) );
		$this->field( __( 'Bemerkung', 'lindenweg16' ), 'note' );

		echo '</tbody></table>';
		printf( '<p><button class="button button-primary">%s</button></p></form>', esc_html__( 'Hinzufügen', 'lindenweg16' ) );
	}

	/**
	 * Beschaffungsdaten eines Zukauf- oder Hybridartikels.
	 *
	 * @param int $article_id Artikel.
	 */
	protected function tab_sourcing( $article_id ) {
		$this->article_switcher( $article_id, 'beschaffung' );

		$article = $article_id ? ArticleRepository::find( $article_id ) : null;
		if ( ! $article ) {
			echo '<p>' . esc_html__( 'Bitte oben einen Artikel wählen.', 'lindenweg16' ) . '</p>';
			return;
		}

		if ( in_array( $article['sourcing_type'], array( 'druck', 'montage' ), true ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Dieser Artikel wird selbst gefertigt. Beschaffungsdaten gibt es nur für Zukauf- und Hybridartikel.', 'lindenweg16' ) . '</p></div>';
			return;
		}

		$history = SourcingRepository::history( $article_id );

		printf( '<h2>%s</h2>', esc_html( sprintf( /* translators: 1: Artikelnummer, 2: Bezeichnung. */ __( 'Beschaffung von %1$s – %2$s', 'lindenweg16' ), $article['sku'], $article['name'] ) ) );
		echo '<p>' . esc_html__( 'Ein Preiswechsel des Lieferanten erzeugt eine neue Zeile. Alte Kalkulationen rechnen weiter mit dem damaligen Preis.', 'lindenweg16' ) . '</p>';

		if ( $history ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Gültig ab', 'lindenweg16' ),
				__( 'Lieferant', 'lindenweg16' ),
				__( 'EK netto', 'lindenweg16' ),
				__( 'Mindestmenge', 'lindenweg16' ),
				__( 'Lieferzeit', 'lindenweg16' ),
				__( 'Eigene Nacharbeit', 'lindenweg16' ),
				__( 'Ausführung', 'lindenweg16' ),
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $history as $row ) {
				echo '<tr>';
				printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $row['valid_from'] ) ) );
				printf( '<td>%s</td>', esc_html( (string) $row['supplier_name'] ) );
				printf( '<td><strong>%s</strong></td>', esc_html( $this->money( $row['purchase_price_net'] ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $row['min_order_qty'], 0 ) ) );
				printf( '<td>%s</td>', esc_html( sprintf( /* translators: %d: Tage. */ __( '%d Tage', 'lindenweg16' ), (int) $row['lead_time_days'] ) ) );
				printf( '<td>%s min</td>', esc_html( number_format_i18n( (float) $row['own_processing_min'], 0 ) ) );
				printf( '<td>%s</td>', esc_html( (string) $row['unit_note'] ) );
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Noch keine Beschaffungsdaten. Ohne sie rechnet die Kalkulation diesen Artikel mit 0 € Material.', 'lindenweg16' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Neue Konditionen festschreiben', 'lindenweg16' ) . '</h2>';

		$this->form_open( 'sourcing' );
		printf( '<input type="hidden" name="article_id" value="%d" />', (int) $article_id );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->field( __( 'Gültig ab', 'lindenweg16' ), 'valid_from', gmdate( 'Y-m-d' ), 'date' );
		$this->field( __( 'Lieferant', 'lindenweg16' ), 'supplier_name' );
		$this->field( __( 'Kontakt', 'lindenweg16' ), 'supplier_contact' );
		$this->field( __( 'Einkaufspreis netto (€)', 'lindenweg16' ), 'purchase_price_net' );
		$this->field( __( 'Mindestbestellmenge', 'lindenweg16' ), 'min_order_qty', '1' );
		$this->field( __( 'Lieferzeit (Tage)', 'lindenweg16' ), 'lead_time_days', '0', 'text', __( 'Grundlage des Beschaffungsalarms.', 'lindenweg16' ) );
		$this->field( __( 'Eigene Nacharbeit (min)', 'lindenweg16' ), 'own_processing_min', '0', 'text', __( 'Ölen, Bohren, Ablängen – zählt als Handling in der Kalkulation.', 'lindenweg16' ) );

		echo '<tr><th scope="row"><label for="lw16-unit_note">' . esc_html__( 'Ausführung', 'lindenweg16' ) . '</label></th>';
		printf(
			'<td><textarea name="unit_note" id="lw16-unit_note" rows="2" class="large-text" placeholder="%s"></textarea></td></tr>',
			esc_attr__( 'Holzart, Oberfläche, Querschnitt, Toleranz', 'lindenweg16' )
		);

		echo '</tbody></table>';
		printf( '<p><button class="button button-primary">%s</button></p></form>', esc_html__( 'Festschreiben', 'lindenweg16' ) );
	}

	/**
	 * Kalkulation eines Artikels über alle Preisebenen.
	 *
	 * @param int $article_id Artikel.
	 */
	protected function tab_calculation( $article_id ) {
		$this->article_switcher( $article_id, 'kalkulation' );

		$article = $article_id ? ArticleRepository::find( $article_id ) : null;
		if ( ! $article ) {
			echo '<p>' . esc_html__( 'Bitte oben einen Artikel wählen.', 'lindenweg16' ) . '</p>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$stichtag = isset( $_GET['stichtag'] ) ? sanitize_text_field( wp_unslash( $_GET['stichtag'] ) ) : gmdate( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stichtag ) ) {
			$stichtag = gmdate( 'Y-m-d' );
		}

		$result = CalculationContext::for_article( $article_id, $stichtag );
		$uvp    = $result['levels']['uvp'];

		printf( '<h2>%s</h2>', esc_html( sprintf( /* translators: 1: Artikelnummer, 2: Bezeichnung. */ __( 'Kalkulation %1$s – %2$s', 'lindenweg16' ), $article['sku'], $article['name'] ) ) );

		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		echo '<input type="hidden" name="tab" value="kalkulation" />';
		printf( '<input type="hidden" name="article" value="%d" />', (int) $article_id );
		printf(
			'<label>%s <input type="date" name="stichtag" value="%s" /></label> <button class="button">%s</button>',
			esc_html__( 'Stichtag:', 'lindenweg16' ),
			esc_attr( $stichtag ),
			esc_html__( 'Neu rechnen', 'lindenweg16' )
		);
		echo '</form>';

		if ( $uvp['errors'] ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Die Kalkulation ist unvollständig:', 'lindenweg16' ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $uvp['errors'] as $error ) {
				printf( '<li>%s</li>', esc_html( $error['message'] ) );
			}
			echo '</ul></div>';
		}

		printf(
			'<p>%s <strong>%s €/h</strong></p>',
			esc_html__( 'Gerechnet mit dem Maschinenstundensatz aus den Gerätedaten:', 'lindenweg16' ),
			esc_html( number_format_i18n( (float) $result['settings']['machine_rate_per_h'], 4 ) )
		);

		// --- Aufgeklappte Stückliste -------------------------------------
		echo '<h3>' . esc_html__( 'Kostenanteile je Position', 'lindenweg16' ) . '</h3>';
		echo '<div class="lw16-scrollx"><table class="widefat striped lw16-table--zahlen"><thead><tr>';
		foreach ( array(
			__( 'Position', 'lindenweg16' ),
			__( 'Art', 'lindenweg16' ),
			__( 'Menge', 'lindenweg16' ),
			__( 'Stückkosten', 'lindenweg16' ),
			__( 'Kosten', 'lindenweg16' ),
			__( 'Anteil', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$hk = (float) $uvp['herstellkosten'];

		$eigene = array(
			array( __( 'Eigenmaterial', 'lindenweg16' ), __( 'Material', 'lindenweg16' ), (float) $uvp['eigenmaterial'] ),
			array( __( 'Maschinenzeit', 'lindenweg16' ), __( 'Maschine', 'lindenweg16' ), (float) $uvp['eigenmaschine'] ),
		);
		foreach ( $eigene as $zeile ) {
			if ( $zeile[2] <= 0 ) {
				continue;
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>—</td><td>—</td><td>%s</td><td>%s %%</td></tr>',
				esc_html( $zeile[0] ),
				esc_html( $zeile[1] ),
				esc_html( $this->money( $zeile[2], 4 ) ),
				esc_html( number_format_i18n( $hk > 0 ? $zeile[2] / $hk * 100 : 0, 1 ) )
			);
		}

		foreach ( $uvp['positionen'] as $pos ) {
			printf(
				'<tr><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s %%</td></tr>',
				esc_html( str_repeat( '— ', (int) $pos['depth'] ) ),
				esc_html( $pos['code'] ? $pos['code'] . ' – ' . $pos['label'] : $pos['label'] ),
				esc_html( 'komponente' === $pos['type'] ? __( 'Komponente', 'lindenweg16' ) : __( 'Unterbaugruppe', 'lindenweg16' ) ),
				esc_html( number_format_i18n( (float) $pos['qty'], 2 ) ),
				esc_html( $this->money( $pos['unit_price'], 4 ) ),
				esc_html( $this->money( $pos['cost'], 4 ) ),
				esc_html( number_format_i18n( $hk > 0 ? (float) $pos['cost'] / $hk * 100 : 0, 1 ) )
			);
		}

		printf(
			'<tr><td colspan="4"><strong>%s</strong></td><td><strong>%s</strong></td><td>100,0 %%</td></tr>',
			esc_html__( 'Herstellkosten', 'lindenweg16' ),
			esc_html( $this->money( $hk, 4 ) )
		);
		echo '</tbody></table></div>';

		// --- Preisebenen nebeneinander -----------------------------------
		$akquise = (float) $result['settings']['customer_acquisition_cost'];

		echo '<h3>' . esc_html__( 'Preisebenen im Vergleich', 'lindenweg16' ) . '</h3>';
		echo '<div class="lw16-scrollx"><table class="widefat striped lw16-table--zahlen"><thead><tr>';
		foreach ( array(
			__( 'Ebene', 'lindenweg16' ),
			__( 'Brutto', 'lindenweg16' ),
			__( 'Netto', 'lindenweg16' ),
			__( 'Herstellkosten', 'lindenweg16' ),
			__( 'Ausschuss', 'lindenweg16' ),
			__( 'Verpackung', 'lindenweg16' ),
			__( 'Zahlung', 'lindenweg16' ),
			__( 'DB I', 'lindenweg16' ),
			__( 'DB I %', 'lindenweg16' ),
			__( 'Handling', 'lindenweg16' ),
			__( 'DB II', 'lindenweg16' ),
			__( 'DB II %', 'lindenweg16' ),
			__( 'gegen Direktverkauf', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$direkt_db2 = (float) $uvp['db2'] - $akquise;

		foreach ( $result['levels'] as $key => $level ) {
			$label = 'uvp' === $key
				? __( 'UVP', 'lindenweg16' )
				: ( 'haendler' === $key ? __( 'Händlerpreis', 'lindenweg16' ) : $level['price_level'] );

			$vergleich = (float) $level['db2'] - $direkt_db2;
			$ampel     = $vergleich >= 0 ? 'gruen' : 'rot';

			echo '<tr>';
			printf( '<td><strong>%s</strong></td>', esc_html( $label ) );
			foreach ( array( 'price_gross', 'netto_erloes', 'herstellkosten', 'ausschuss', 'verpackung', 'zahlung', 'db1' ) as $field ) {
				printf( '<td>%s</td>', esc_html( $this->money( $level[ $field ] ) ) );
			}
			printf( '<td>%s %%</td>', esc_html( number_format_i18n( (float) $level['db1_pct'] * 100, 1 ) ) );
			printf( '<td>%s</td>', esc_html( $this->money( $level['handling'] ) ) );
			printf( '<td><strong>%s</strong></td>', esc_html( $this->money( $level['db2'] ) ) );
			printf( '<td>%s %%</td>', esc_html( number_format_i18n( (float) $level['db2_pct'] * 100, 1 ) ) );
			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
				esc_attr( $ampel ),
				esc_html( ( $vergleich >= 0 ? '+' : '' ) . $this->money( $vergleich ) )
			);
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: Kundengewinnungskosten. */
					__( 'Der Vergleich stellt jede Ebene gegen einen beworbenen Direktverkauf zum UVP abzüglich %s Kundengewinnungskosten. Grün heißt: Diese Stufe trägt sich besser als der Direktverkauf.', 'lindenweg16' ),
					$this->money( $akquise )
				)
			)
		);

		$this->render_price_box( $article, $result['settings'], $stichtag );
	}

	/**
	 * Zielmarge, Vorschlagspreis, Übernahme und Stand im Shop.
	 *
	 * Das stand einmal auf einer eigenen Seite unter Lindenweg16 →
	 * Preiskalkulation. Dort war es dieselbe Rechnung mit denselben
	 * Zahlen, nur zwei Menüs entfernt von dem Artikel, um den es geht –
	 * und der Preis musste zweimal angefasst werden: einmal am Artikel,
	 * einmal für den Shop.
	 *
	 * @param array  $article  Artikel.
	 * @param array  $settings Kalkulationsannahmen.
	 * @param string $stichtag Stichtag der Kalkulation.
	 */
	protected function render_price_box( array $article, array $settings, $stichtag ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$marge = isset( $_GET['marge'] ) ? (float) $_GET['marge'] : 45.0;
		$marge = max( 0.0, min( 95.0, $marge ) );

		// Dieselben Herstellkosten wie die Tabelle darüber – der Vorschlag
		// rechnet gegen die vollständige Kostenaufstellung, nicht gegen die
		// gerundete Anzeige.
		$cost      = CostCalculator::manufacturing_cost( (int) $article['id'], CalculationContext::build( $stichtag ), $settings );
		$vorschlag = CostCalculator::price_for_margin( $cost, $settings, $marge / 100 );
		$aktuell   = null === $article['price_gross'] ? null : (float) $article['price_gross'];

		echo '<h3>' . esc_html__( 'Preis festlegen', 'lindenweg16' ) . '</h3>';

		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		echo '<input type="hidden" name="tab" value="kalkulation" />';
		printf( '<input type="hidden" name="article" value="%d" />', (int) $article['id'] );
		printf( '<input type="hidden" name="stichtag" value="%s" />', esc_attr( (string) $stichtag ) );
		printf(
			'<label>%s <input type="number" step="1" min="0" max="95" name="marge" value="%s" class="small-text" /> %%</label> <button class="button">%s</button>',
			esc_html__( 'Zielmarge (DB II vom Nettoerlös)', 'lindenweg16' ),
			esc_attr( (string) round( $marge ) ),
			esc_html__( 'Neu rechnen', 'lindenweg16' )
		);
		echo '</form>';

		echo '<table class="widefat striped lw16-table--zahlen" style="max-width:640px"><tbody>';
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Vorschlag bei dieser Marge', 'lindenweg16' ),
			null === $vorschlag
				? esc_html__( 'mit den aktuellen Annahmen nicht erreichbar', 'lindenweg16' )
				: '<strong>' . esc_html( $this->money( $vorschlag ) ) . '</strong>'
		);
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Preis am Artikel', 'lindenweg16' ),
			null === $aktuell ? '—' : esc_html( $this->money( $aktuell ) )
		);
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Stand im Shop', 'lindenweg16' ),
			esc_html( $this->shop_price_note( $article ) )
		);
		echo '</tbody></table>';

		if ( null !== $vorschlag ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="lw16_betrieb_price_adopt" />';
			printf( '<input type="hidden" name="article_id" value="%d" />', (int) $article['id'] );
			printf( '<input type="hidden" name="vorschlag" value="%s" />', esc_attr( (string) round( (float) $vorschlag, 2 ) ) );
			printf(
				'<button class="button button-primary">%s</button>',
				esc_html(
					sprintf(
						/* translators: %s: Preis. */
						__( '%s als Artikelpreis übernehmen', 'lindenweg16' ),
						$this->money( $vorschlag )
					)
				)
			);
			echo '</form>';
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Der Artikelpreis ist der eine Preis: Beim Speichern bekommt das verknüpfte Shop-Produkt ihn als Normalpreis, ein laufendes Angebot in WooCommerce bleibt unberührt. Den Preis von Hand setzen geht weiter im Reiter Artikel.', 'lindenweg16' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_price_push_all" />';
		printf(
			'<button class="button" onclick="return confirm(\'%s\')">%s</button> <span class="description">%s</span>',
			esc_attr__( 'Alle verkäuflichen Artikel in den Shop übertragen?', 'lindenweg16' ),
			esc_html__( 'Alle Preise in den Shop übertragen', 'lindenweg16' ),
			esc_html__( 'Nur nötig, wenn ein Shop-Preis von Hand verstellt wurde – sonst zieht der Shop bei jedem Speichern von selbst nach.', 'lindenweg16' )
		);
		echo '</form>';
	}

	/**
	 * Was steht gerade im Shop?
	 *
	 * @param array $article Artikel.
	 * @return string
	 */
	protected function shop_price_note( array $article ) {
		$wc_id = isset( $article['wc_product_id'] ) ? (int) $article['wc_product_id'] : 0;

		if ( ! $wc_id ) {
			return __( 'kein Shop-Produkt verknüpft', 'lindenweg16' );
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $wc_id ) : null;

		if ( ! $product ) {
			return __( 'verknüpftes Shop-Produkt gibt es nicht mehr', 'lindenweg16' );
		}

		$preis = $product->get_regular_price();

		if ( '' === $preis || null === $preis ) {
			return __( 'noch kein Normalpreis im Shop', 'lindenweg16' );
		}

		return $this->money( (float) $preis );
	}

	/**
	 * Bestellart-Rechner: Sets rechnen und gegen Einzelverkauf stellen.
	 */
	protected function tab_bundles() {
		global $wpdb;

		$articles = ArticleRepository::all();
		$settings = CalculationContext::settings();
		$ctx      = CalculationContext::build();

		echo '<h2>' . esc_html__( 'Bestellart-Rechner', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Verpackung und Zahlungsgebühr fallen genau einmal je Bestellung an. Deshalb trägt sich ein Set besser als dieselben Positionen einzeln bestellt.', 'lindenweg16' ) . '</p>';

		if ( ! $articles ) {
			echo '<p>' . esc_html__( 'Zuerst Artikel anlegen.', 'lindenweg16' ) . '</p>';
			return;
		}

		// Eingaben aus der Abfrage übernehmen (reine Ansicht, kein Schreibvorgang).
		// phpcs:disable WordPress.Security.NonceVerification
		$mengen    = isset( $_GET['menge'] ) && is_array( $_GET['menge'] ) ? array_map( 'floatval', wp_unslash( $_GET['menge'] ) ) : array();
		$preis     = isset( $_GET['bundle_price'] ) ? (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_GET['bundle_price'] ) ) ) : 0.0;
		$bundle_id = isset( $_GET['bundle'] ) ? (int) $_GET['bundle'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification

		// Gespeicherte Bestellart laden und die Maske damit vorbelegen.
		$bundles = BundleRepository::all();
		if ( $bundle_id && ! $mengen ) {
			$bundle = BundleRepository::find( $bundle_id );
			if ( $bundle ) {
				$preis = (float) $bundle['bundle_price_gross'];
				foreach ( BundleRepository::items( $bundle_id ) as $item ) {
					$mengen[ (int) $item['article_id'] ] = (float) $item['qty'];
				}
			}
		}

		if ( $bundles ) {
			echo '<h3>' . esc_html__( 'Gespeicherte Bestellarten', 'lindenweg16' ) . '</h3><p>';
			foreach ( $bundles as $bundle ) {
				printf(
					'<a class="button%s" href="%s">%s – %s</a> ',
					$bundle_id === (int) $bundle['id'] ? ' button-primary' : '',
					esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'bestellarten', 'bundle' => (int) $bundle['id'] ), admin_url( 'admin.php' ) ) ),
					esc_html( $bundle['name'] ),
					esc_html( $this->money( $bundle['bundle_price_gross'] ) )
				);
			}
			echo '</p>';
		}

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		echo '<input type="hidden" name="tab" value="bestellarten" />';

		echo '<table class="widefat striped" style="max-width:820px"><thead><tr>';
		foreach ( array( __( 'Artikel', 'lindenweg16' ), __( 'Einzelpreis', 'lindenweg16' ), __( 'Menge im Set', 'lindenweg16' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $articles as $article ) {
			$id = (int) $article['id'];
			echo '<tr>';
			printf( '<td>%s – %s</td>', esc_html( $article['sku'] ), esc_html( $article['name'] ) );
			printf( '<td>%s</td>', esc_html( null === $article['price_gross'] ? '—' : $this->money( $article['price_gross'] ) ) );
			printf(
				'<td><input type="number" min="0" step="1" name="menge[%d]" value="%s" style="width:80px" /></td>',
				$id,
				esc_attr( isset( $mengen[ $id ] ) ? (string) (int) $mengen[ $id ] : '0' )
			);
			echo '</tr>';
		}
		echo '</tbody></table>';

		printf(
			'<p><label>%s <input type="text" name="bundle_price" value="%s" size="10" /></label> <button class="button button-primary">%s</button></p>',
			esc_html__( 'Setpreis brutto (€):', 'lindenweg16' ),
			esc_attr( $preis > 0 ? (string) $preis : '' ),
			esc_html__( 'Rechnen', 'lindenweg16' )
		);
		echo '</form>';

		$items = array();
		foreach ( $mengen as $article_id => $qty ) {
			if ( $qty > 0 ) {
				$items[] = array( 'article_id' => (int) $article_id, 'qty' => (float) $qty );
			}
		}

		if ( ! $items ) {
			return;
		}

		// Ohne eigenen Setpreis mit der Summe der Einzelpreise rechnen.
		if ( $preis <= 0 ) {
			// Einmal alle Artikel, nicht je Position eine Abfrage.
			$artikel = ArticleRepository::all_by_id();

			foreach ( $items as $item ) {
				$article = isset( $artikel[ (int) $item['article_id'] ] ) ? $artikel[ (int) $item['article_id'] ] : null;
				$preis  += $item['qty'] * ( $article && null !== $article['price_gross'] ? (float) $article['price_gross'] : 0.0 );
			}
		}

		$vergleich = BundleCalculator::compare( $items, $preis, $ctx, $settings );
		$set       = $vergleich['set'];

		echo '<h3>' . esc_html__( 'Ergebnis', 'lindenweg16' ) . '</h3>';

		if ( $set['errors'] ) {
			echo '<div class="notice notice-warning inline"><ul style="list-style:disc;margin-left:20px">';
			foreach ( $set['errors'] as $error ) {
				printf( '<li>%s</li>', esc_html( $error['message'] ) );
			}
			echo '</ul></div>';
		}

		echo '<table class="widefat striped" style="max-width:560px"><tbody>';
		foreach ( array(
			array( __( 'Setpreis brutto', 'lindenweg16' ), $this->money( $set['price_gross'] ) ),
			array( __( 'Nettoerlös', 'lindenweg16' ), $this->money( $set['netto_erloes'] ) ),
			array( __( 'Material Druck', 'lindenweg16' ), $this->money( $set['material_druck'] ) ),
			array( __( 'Material Zukauf', 'lindenweg16' ), $this->money( $set['material_zukauf'] ) ),
			array( __( 'Maschinenzeit', 'lindenweg16' ), $this->money( $set['eigenmaschine'] ) ),
			array( __( 'Kleinteile', 'lindenweg16' ), $this->money( $set['komponenten'] ) ),
			array( __( 'Herstellkosten gesamt', 'lindenweg16' ), $this->money( $set['herstellkosten'] ) ),
			array( __( 'Ausschusszuschlag', 'lindenweg16' ), $this->money( $set['ausschuss'] ) ),
			array( __( 'Verpackung (einmal)', 'lindenweg16' ), $this->money( $set['verpackung'] ) ),
			array( __( 'Zahlungsgebühr (einmal)', 'lindenweg16' ), $this->money( $set['zahlung'] ) ),
			array( __( 'Druckzeit gesamt', 'lindenweg16' ), number_format_i18n( (float) $set['print_time_h'], 2 ) . ' h' ),
			array( __( 'Nachbearbeitung gesamt', 'lindenweg16' ), number_format_i18n( (float) $set['postprocessing_min'], 0 ) . ' min' ),
			array( __( 'DB I', 'lindenweg16' ), $this->money( $set['db1'] ) . ' (' . number_format_i18n( (float) $set['db1_pct'] * 100, 1 ) . ' %)' ),
			array( __( 'DB II', 'lindenweg16' ), $this->money( $set['db2'] ) . ' (' . number_format_i18n( (float) $set['db2_pct'] * 100, 1 ) . ' %)' ),
		) as $zeile ) {
			printf( '<tr><td>%s</td><td style="text-align:right">%s</td></tr>', esc_html( $zeile[0] ), esc_html( $zeile[1] ) );
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Gegenüber Einzelbestellungen', 'lindenweg16' ) . '</h3>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: Anzahl Bestellungen, 2: DB II einzeln, 3: Differenz. */
					__( 'Dieselben Positionen einzeln bestellt wären %1$d Bestellungen mit zusammen %2$s DB II. Das Set liegt um %3$s darüber – das sind die eingesparten Verpackungen und Fixgebühren.', 'lindenweg16' ),
					(int) $vergleich['einzeln']['bestellungen'],
					$this->money( $vergleich['einzeln']['db2'] ),
					$this->money( $vergleich['differenz_db2'] )
				)
			)
		);

		// Diese Zusammenstellung als benannte Bestellart sichern.
		echo '<h3>' . esc_html__( 'Als Bestellart speichern', 'lindenweg16' ) . '</h3>';
		$this->form_open( 'bundle' );
		foreach ( $items as $item ) {
			printf(
				'<input type="hidden" name="menge[%d]" value="%s" />',
				(int) $item['article_id'],
				esc_attr( (string) $item['qty'] )
			);
		}
		printf( '<input type="hidden" name="bundle_price" value="%s" />', esc_attr( (string) $preis ) );
		printf(
			'<p><label>%s <input type="text" name="bundle_name" placeholder="%s" class="regular-text" /></label> <button class="button">%s</button></p></form>',
			esc_html__( 'Bezeichnung:', 'lindenweg16' ),
			esc_attr__( 'z. B. Gravel-Set', 'lindenweg16' ),
			esc_html__( 'Speichern', 'lindenweg16' )
		);

		$min_db2 = (float) $settings['min_db2_warning'];
		if ( (float) $set['db2'] < $min_db2 ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: DB II, 2: Mindestwert. */
						__( 'Der Deckungsbeitrag II liegt mit %1$s unter der Warnschwelle von %2$s.', 'lindenweg16' ),
						$this->money( $set['db2'] ),
						$this->money( $min_db2 )
					)
				)
			);
		}
	}

	/**
	 * Wert eines Datensatzes lesen.
	 *
	 * @param array|null $row Datensatz.
	 * @param string     $key Feld.
	 * @return string
	 */
	protected function val( $row, $key ) {
		return isset( $row[ $key ] ) && null !== $row[ $key ] ? (string) $row[ $key ] : '';
	}

	/**
	 * Betrag formatieren.
	 *
	 * @param float $value    Wert.
	 * @param int   $decimals Nachkommastellen.
	 * @return string
	 */
	protected function money( $value, $decimals = 2 ) {
		return number_format_i18n( (float) $value, $decimals ) . ' €';
	}
}
