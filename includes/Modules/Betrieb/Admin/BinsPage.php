<?php
/**
 * Lagerplätze und Picklisten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\BetriebModule;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\BinRepository;
use Lindenweg16\Modules\Betrieb\PicklistRepository;
use Lindenweg16\Modules\Betrieb\BomRepository;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\DeviceLabel;
use Lindenweg16\Modules\Betrieb\ComponentRepository;
use Lindenweg16\Modules\Betrieb\LaborCost;
use Lindenweg16\Modules\Betrieb\LabelSheet;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\TimeRepository;
use Lindenweg16\Modules\Betrieb\WageRepository;
use Lindenweg16\Modules\Betrieb\ScanResolver;
use Lindenweg16\Modules\Betrieb\WorkOrderRepository;
use Lindenweg16\Modules\Betrieb\StockRepository;
use Lindenweg16\Modules\Betrieb\WooIntegration;

/**
 * Verwaltung der Lagerplätze samt Etiketten und Picklisten.
 */
class BinsPage {

	const NONCE = 'lw16_betrieb_bins';

	/**
	 * Nonce der Auftragsformulare.
	 *
	 * Eigene Konstante, weil auch das Dashboard damit sendet: Ein dort
	 * abgetippter Name bräche stillschweigend, sobald er hier anders
	 * hiesse – und ein falscher Nonce sieht aus wie ein Rechtefehler.
	 */
	const NONCE_WORK = self::NONCE . '_work';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_lagerplaetze', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_save_bin', array( $this, 'handle_save' ) );
		add_action( 'admin_post_lw16_betrieb_make_picklist', array( $this, 'handle_picklist' ) );
		add_action( 'admin_post_lw16_betrieb_work_order', array( $this, 'handle_work_order' ) );
		add_action( 'admin_post_lw16_betrieb_work_done', array( $this, 'handle_work_done' ) );
		add_action( 'admin_post_lw16_betrieb_work_timer', array( $this, 'handle_work_timer' ) );
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap( $cap = 'lw16_manage_production' ) {
		if ( ! Roles::current_user_can_betrieb( $cap ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Wohin nach dem Speichern?
	 *
	 * Normalerweise zurück auf diese Seite. Kommt der Klick von woanders –
	 * etwa dem Dashboard –, soll er auch dorthin zurückführen; sonst
	 * reisst jeder Knopf den Benutzer aus seinem Kontext.
	 *
	 * Geprüft wird gegen die bekannten Seiten des Moduls. Ein Ziel aus
	 * dem Formular ungeprüft zu übernehmen, wäre eine offene
	 * Weiterleitung.
	 *
	 * @return string
	 */
	protected function target_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- die Handler pruefen den Nonce vorher.
		$wunsch = isset( $_POST['zurueck'] ) ? sanitize_key( wp_unslash( $_POST['zurueck'] ) ) : '';

		return BetriebModule::is_page( $wunsch ) ? $wunsch : 'lw16-betrieb-lagerplaetze';
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type success oder error.
	 * @param array  $args Zusätzliche Parameter.
	 */
	protected function redirect( $msg, $type = 'success', array $args = array() ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'     => $this->target_page(),
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

	/**
	 * Lagerplatz speichern.
	 */
	public function handle_save() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$id     = isset( $_POST['bin_id'] ) ? (int) $_POST['bin_id'] : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$input  = array_map(
			static function ( $value ) {
				return is_string( $value ) ? wp_unslash( $value ) : $value;
			},
			(array) $_POST
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = BinRepository::validate( $input, $id ? $id : null );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', $id ? array( 'edit' => $id ) : array() );
		}

		if ( $id ) {
			BinRepository::update( $id, $result['data'], $reason );
			$this->redirect( __( 'Lagerplatz gespeichert.', 'lindenweg16' ) );
		}

		BinRepository::insert( $result['data'] );
		$this->redirect( __( 'Lagerplatz angelegt. Etikett nicht vergessen.', 'lindenweg16' ) );
	}

	/**
	 * Pickliste aus den offenen Bestellungen erzeugen.
	 */
	public function handle_picklist() {
		$this->require_cap( 'lw16_capture' );
		check_admin_referer( self::NONCE . '_pick' );

		$lines = WooIntegration::open_order_lines();

		if ( ! $lines ) {
			$this->redirect( __( 'Keine offenen Bestellungen – es gibt nichts zu picken.', 'lindenweg16' ), 'error', array( 'view' => 'picklisten' ) );
		}

		$rows = PicklistRepository::condense( $lines, BinRepository::article_hints(), BinRepository::component_hints() );
		$id   = PicklistRepository::create( $rows );

		if ( ! $id ) {
			$this->redirect( __( 'Pickliste konnte nicht angelegt werden.', 'lindenweg16' ), 'error', array( 'view' => 'picklisten' ) );
		}

		$this->redirect(
			sprintf(
				/* translators: %d: Anzahl Positionen. */
				__( 'Pickliste mit %d Positionen angelegt. Sie steht jetzt am Handy bereit.', 'lindenweg16' ),
				count( $rows )
			),
			'success',
			array( 'view' => 'picklisten' )
		);
	}

	/* ====================================================================
	 * Ausgabe
	 * ================================================================== */

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		// phpcs:disable WordPress.Security.NonceVerification
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'plaetze';
		$edit = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Lagerplätze & Picklisten', 'lindenweg16' ) . '</h1>';
		$this->notice();

		$base = admin_url( 'admin.php?page=lw16-betrieb-lagerplaetze' );
		echo '<nav class="lw16-tabs">';
		foreach ( array(
			'plaetze'    => __( 'Lagerplätze', 'lindenweg16' ),
			'etiketten'  => __( 'Etiketten', 'lindenweg16' ),
			'fertigung'  => __( 'Fertigungsaufträge', 'lindenweg16' ),
			'picklisten' => __( 'Picklisten', 'lindenweg16' ),
		) as $key => $label ) {
			printf(
				'<a href="%s" class="%s">%s</a> ',
				esc_url( add_query_arg( 'view', $key, $base ) ),
				$view === $key ? 'active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'etiketten' === $view ) {
			$this->render_labels();
		} elseif ( 'fertigung' === $view ) {
			$this->render_work_orders();
		} elseif ( 'picklisten' === $view ) {
			$this->render_picklists();
		} else {
			$this->render_bins( $edit );
		}

		echo '</div>';
	}

	/**
	 * Meldung aus dem Umleitungsparameter.
	 */
	protected function notice() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['lw16_msg'] ) ) {
			return;
		}
		$type = isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success';
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Liste und Formular der Lagerplätze.
	 *
	 * @param int $edit Zu bearbeitender Platz.
	 */
	protected function render_bins( $edit ) {
		$bins     = BinRepository::all();
		$balances = BinRepository::balances( StockRepository::all() );
		$articles = ArticleRepository::all_by_id();
		$current  = $edit ? BinRepository::find( $edit ) : null;

		if ( $bins ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Code', 'lindenweg16' ),
				__( 'Bezeichnung', 'lindenweg16' ),
				__( 'Art', 'lindenweg16' ),
				__( 'Liegt dort', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
				'',
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $bins as $bin ) {
				$stock = BinRepository::stock_of( $balances, (int) $bin['id'] );
				$lines = array();
				foreach ( $stock as $article_id => $colors ) {
					foreach ( $colors as $color => $qty ) {
						$lines[] = sprintf(
							'%s %s: %s',
							isset( $articles[ $article_id ] ) ? $articles[ $article_id ]['sku'] : '?',
							$color,
							number_format_i18n( $qty, 0 )
						);
					}
				}

				echo '<tr>';
				printf( '<td><strong>%s</strong></td>', esc_html( $bin['code'] ) );
				printf( '<td>%s</td>', esc_html( (string) $bin['name'] ) );
				printf( '<td>%s</td>', esc_html( (string) $bin['kind'] ) );
				printf( '<td>%s</td>', $lines ? esc_html( implode( ' · ', $lines ) ) : '<span style="opacity:.5">—</span>' );
				printf(
					'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
					$bin['is_active'] ? 'gruen' : 'rot',
					$bin['is_active'] ? esc_html__( 'aktiv', 'lindenweg16' ) : esc_html__( 'stillgelegt', 'lindenweg16' )
				);
				printf(
					'<td><a href="%s">%s</a></td>',
					esc_url( add_query_arg( array( 'page' => 'lw16-betrieb-lagerplaetze', 'edit' => (int) $bin['id'] ), admin_url( 'admin.php' ) ) ),
					esc_html__( 'Bearbeiten', 'lindenweg16' )
				);
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Noch kein Lagerplatz angelegt. Ohne Plätze ist der Bestand da, aber niemand weiß wo.', 'lindenweg16' ) . '</p>';
		}

		$this->render_label_sheet();

		echo '<h2>' . esc_html( $current ? __( 'Lagerplatz bearbeiten', 'lindenweg16' ) : __( 'Lagerplatz anlegen', 'lindenweg16' ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Der Code steht auf dem Etikett und wird gescannt. Kurz halten: A01, KISTE3, VERSAND.', 'lindenweg16' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-form">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_save_bin" />';
		printf( '<input type="hidden" name="bin_id" value="%d" />', $current ? (int) $current['id'] : 0 );

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->field( __( 'Code', 'lindenweg16' ), 'code', $current ? $current['code'] : '' );
		$this->field( __( 'Bezeichnung', 'lindenweg16' ), 'name', $current ? (string) $current['name'] : '' );

		echo '<tr><th scope="row">' . esc_html__( 'Art', 'lindenweg16' ) . '</th><td><select name="kind">';
		foreach ( BinRepository::KINDS as $kind ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $kind ),
				$current && $kind === $current['kind'] ? ' selected' : '',
				esc_html( $kind )
			);
		}
		echo '</select></td></tr>';

		$this->field( __( 'Sortierung', 'lindenweg16' ), 'sort_order', $current ? (string) $current['sort_order'] : '0' );

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="is_active" value="1"%s /> %s</label></td></tr>',
			esc_html__( 'Status', 'lindenweg16' ),
			! $current || $current['is_active'] ? ' checked' : '',
			esc_html__( 'aktiv', 'lindenweg16' )
		);

		if ( $current ) {
			$this->field( __( 'Begründung der Änderung', 'lindenweg16' ), 'reason', '' );
		}

		echo '</tbody></table>';
		printf( '<p><button class="button button-primary">%s</button></p>', esc_html__( 'Speichern', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Eingabefeld.
	 *
	 * @param string $label Beschriftung.
	 * @param string $name  Feldname.
	 * @param string $value Wert.
	 */
	protected function field( $label, $name, $value ) {
		printf(
			'<tr><th scope="row"><label for="lw16-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="lw16-%1$s" name="%1$s" value="%3$s" /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	/**
	 * Etiketten zum Ausdrucken.
	 */
	protected function render_labels() {
		$bins = BinRepository::all( true );

		echo '<h2>' . esc_html__( 'Etiketten', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ausdrucken, zuschneiden, ankleben. Der QR-Code enthält den Platzcode – gescannt wird er in der mobilen Erfassung unter „Lager".', 'lindenweg16' ) . '</p>';

		if ( ! $bins ) {
			echo '<p>' . esc_html__( 'Keine aktiven Lagerplätze.', 'lindenweg16' ) . '</p>';
			return;
		}

		printf( '<p><button type="button" class="button" onclick="window.print()">%s</button></p>', esc_html__( 'Etiketten drucken', 'lindenweg16' ) );

		echo '<div class="lw16-labels">';
		foreach ( $bins as $bin ) {
			echo BinRepository::label( $bin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapt in label().
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Artikeletiketten', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Für Kisten und Fächer, in denen ein bestimmter Artikel liegt. Zugekaufte Ware braucht kein eigenes Etikett – dort genügt der Barcode des Lieferanten, wenn er am Artikel hinterlegt ist.', 'lindenweg16' ) . '</p>';

		echo '<div class="lw16-labels">';
		foreach ( ArticleRepository::all() as $article ) {
			printf(
				'<div class="lw16-label lw16-label--bin"><div class="lw16-label__code">%s</div><div class="lw16-label__model">%s</div><div class="lw16-label__qr">%s</div></div>',
				esc_html( $article['sku'] ),
				esc_html( $article['name'] ),
				\Lindenweg16\Modules\Betrieb\DeviceLabel::qr( ScanResolver::PREFIX_ARTICLE . $article['sku'], 110, 'Q' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- erzeugtes SVG.
			);
		}
		echo '</div>';
	}

	/**
	 * Fertigungsauftrag anlegen und Pickliste dazu erzeugen.
	 */
	public function handle_work_order() {
		$this->require_cap();
		check_admin_referer( self::NONCE_WORK );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$input = array_map(
			static function ( $value ) {
				return is_string( $value ) ? wp_unslash( $value ) : $value;
			},
			(array) $_POST
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = WorkOrderRepository::validate( $input );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', array( 'view' => 'fertigung' ) );
		}

		$id = WorkOrderRepository::insert( $result['data'] );

		if ( ! $id ) {
			$this->redirect( __( 'Auftrag konnte nicht angelegt werden.', 'lindenweg16' ), 'error', array( 'view' => 'fertigung' ) );
		}

		$order       = WorkOrderRepository::find( $id );
		$picklist_id = PicklistRepository::from_work_order( $order, BinRepository::article_hints(), BinRepository::component_hints() );

		if ( $picklist_id ) {
			WorkOrderRepository::attach_picklist( $id, $picklist_id );
		}

		$this->redirect(
			sprintf(
				/* translators: %s: Auftragscode. */
				'druck' === $order['kind']
					? __( 'Druckauftrag %s angelegt. Starten mit „Produktion starten", buchen über „Im Journal buchen".', 'lindenweg16' )
					: __( 'Fertigungsauftrag %s angelegt. Die Materialliste steht am Handy unter „Picken" bereit.', 'lindenweg16' ),
				$order['code']
			),
			'success',
			array( 'view' => 'fertigung' )
		);
	}

	/**
	 * Uhr an einem Fertigungsauftrag starten oder anhalten.
	 */
	public function handle_work_timer() {
		$this->require_cap( 'lw16_capture' );
		check_admin_referer( self::NONCE_WORK );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$id    = isset( $_POST['work_order_id'] ) ? (int) $_POST['work_order_id'] : 0;
		$pause = ! empty( $_POST['pause'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $pause
			? WorkOrderRepository::pause( $id )
			: WorkOrderRepository::start( $id );

		$this->redirect( $result['message'], $result['success'] ? 'success' : 'error', array( 'view' => 'fertigung' ) );
	}

	/**
	 * Auftrag fertigmelden oder stornieren.
	 */
	public function handle_work_done() {
		$this->require_cap( 'lw16_capture' );
		check_admin_referer( self::NONCE_WORK );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$id     = isset( $_POST['work_order_id'] ) ? (int) $_POST['work_order_id'] : 0;
		$qty    = isset( $_POST['qty_done'] ) ? (float) str_replace( ',', '.', wp_unslash( $_POST['qty_done'] ) ) : 0;
		$cancel = ! empty( $_POST['cancel'] );
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $cancel
			? WorkOrderRepository::cancel( $id, $reason )
			: WorkOrderRepository::complete( $id, $qty );

		$this->redirect( $result['message'], $result['success'] ? 'success' : 'error', array( 'view' => 'fertigung' ) );
	}

	/**
	 * Ampelfarbe je Auftragszustand.
	 *
	 * @param string $status Zustand.
	 * @return string
	 */
	protected function status_color( $status ) {
		if ( 'erledigt' === $status ) {
			return 'gruen';
		}

		if ( in_array( $status, WorkOrderRepository::OPEN_STATES, true ) ) {
			return 'gelb';
		}

		return 'rot';
	}

	/**
	 * Auftragszustand in Klartext.
	 *
	 * @param string $status Zustand.
	 * @return string
	 */
	protected function status_label( $status ) {
		$labels = array(
			'offen'      => __( 'offen', 'lindenweg16' ),
			'laufend'    => __( 'läuft', 'lindenweg16' ),
			'erledigt'   => __( 'erledigt', 'lindenweg16' ),
			'storniert'  => __( 'storniert', 'lindenweg16' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Gemessene Zeit als Text.
	 *
	 * Bei einem laufenden Auftrag zählt das angebrochene Segment mit –
	 * sonst stünde dort minutenlang eine Null, obwohl die Uhr läuft.
	 *
	 * @param array $order Auftrag.
	 * @return string
	 */
	protected function duration_label( array $order ) {
		$seconds = WorkOrderRepository::elapsed( $order, current_time( 'mysql', true ) );

		if ( $seconds <= 0 ) {
			return '—';
		}

		$text = WorkOrderRepository::format_duration( $seconds );

		if ( WorkOrderRepository::is_running( $order ) ) {
			return sprintf( /* translators: %s: Dauer. */ __( '%s (läuft)', 'lindenweg16' ), $text );
		}

		$je_stueck = WorkOrderRepository::minutes_per_piece( $seconds, (float) $order['qty_done'] );

		if ( null !== $je_stueck ) {
			return sprintf(
				/* translators: 1: Dauer, 2: Minuten je Stück. */
				__( '%1$s · %2$s min/Stk.', 'lindenweg16' ),
				$text,
				number_format_i18n( $je_stueck, 2 )
			);
		}

		return $text;
	}

	/**
	 * Zelle mit Link und QR-Code zur Materialliste.
	 *
	 * Der QR-Code ist der eigentliche Zweck: Man legt den Auftrag am
	 * Rechner an, scannt ihn vom Bildschirm und hat die Liste am Handy –
	 * ohne sie abzutippen oder sich durch alle offenen Listen zu suchen.
	 *
	 * @param array $order Auftrag.
	 */
	protected function render_picklist_cell( array $order ) {
		$picklist_id = isset( $order['picklist_id'] ) ? (int) $order['picklist_id'] : 0;

		if ( ! $picklist_id ) {
			echo '<span class="description">' . esc_html__( 'keine', 'lindenweg16' ) . '</span>';
			return;
		}

		$picklist = PicklistRepository::find( $picklist_id );

		if ( ! $picklist ) {
			echo '<span class="description">' . esc_html__( 'keine', 'lindenweg16' ) . '</span>';
			return;
		}

		$url = DeviceLabel::pick_url( $picklist['code'] );

		printf(
			'<div class="lw16-pickqr"><a href="%1$s" target="_blank" rel="noopener"><strong>%2$s</strong></a>'
			. '<div class="lw16-pickqr__code">%3$s</div>'
			. '<div class="lw16-pickqr__hint">%4$s</div></div>',
			esc_url( $url ),
			esc_html( $picklist['code'] ),
			DeviceLabel::qr( $url, 96, 'M' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- erzeugtes SVG.
			esc_html__( 'scannen fürs Handy', 'lindenweg16' )
		);
	}

	/**
	 * Was die erfasste Arbeit an diesem Auftrag gekostet hat.
	 *
	 * Gerechnet wird aus den Zeitbuchungen, nicht aus dem Timerstand: Nur
	 * die Buchung weiss, wer gearbeitet hat, und nur dann lässt sich der
	 * richtige Stundenlohn ansetzen.
	 *
	 * @param array $order Auftrag.
	 * @param array $wages Lohnzeilen.
	 * @return string
	 */
	protected function labor_label( array $order, array $wages ) {
		$entries = TimeRepository::for_work_order( (int) $order['id'] );

		if ( ! $entries ) {
			return '—';
		}

		$total = LaborCost::total( $entries, $wages );

		if ( $total['hours_unpriced'] > 0 && $total['hours'] <= 0 ) {
			return __( 'kein Lohn hinterlegt', 'lindenweg16' );
		}

		$text = number_format_i18n( $total['cost'], 2 ) . ' €';

		if ( $total['hours_unpriced'] > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %s: Stunden. */
				__( '(+%s h ohne Lohn)', 'lindenweg16' ),
				number_format_i18n( $total['hours_unpriced'], 2 )
			);
		}

		return $text;
	}

	/**
	 * Alle Etiketten als ein PDF.
	 *
	 * Einzelne QR-Codes stehen an ihrem Datensatz; zum Erstausstatten eines
	 * Lagers braucht es aber einen Bogen, den man einmal druckt.
	 */
	protected function render_label_sheet() {
		echo '<h2>' . esc_html__( 'Etiketten als PDF', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Alle QR-Codes auf einem Bogen – Lagerplätze, Artikel oder beides. Die Codes sind dieselben wie einzeln im Backend: Ein Lagerplatz führt beim Scannen auf sein Fach, ein Artikel auf seine Buchungsmaske.', 'lindenweg16' ) . '</p>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_betrieb_labels" />';
		wp_nonce_field( 'lw16_betrieb_export', '_wpnonce', false );

		echo '<p>';
		printf(
			'<label style="margin-right:16px"><input type="checkbox" name="was[]" value="bins" checked /> %s</label>',
			esc_html__( 'Lagerplätze', 'lindenweg16' )
		);
		printf(
			'<label style="margin-right:16px"><input type="checkbox" name="was[]" value="articles" checked /> %s</label>',
			esc_html__( 'Artikel', 'lindenweg16' )
		);
		printf(
			'<label><input type="checkbox" name="alle" value="1" /> %s</label>',
			esc_html__( 'auch stillgelegte', 'lindenweg16' )
		);
		echo '</p>';

		echo '<p><select name="layout">';
		foreach ( LabelSheet::layouts() as $key => $layout ) {
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $key ),
				esc_html( $layout['label'] )
			);
		}
		echo '</select> ';
		printf( '<button class="button button-primary">%s</button></p>', esc_html__( 'Etikettenbogen erzeugen', 'lindenweg16' ) );

		echo '<ul class="ul-disc" style="max-width:52em">';
		foreach ( LabelSheet::layouts() as $layout ) {
			printf( '<li><strong>%s</strong> – %s</li>', esc_html( $layout['label'] ), esc_html( $layout['hint'] ) );
		}
		echo '</ul>';

		echo '<p class="description">' . esc_html__( 'Drucke ohne Skalierung („Tatsächliche Grösse", nicht „An Seite anpassen"), sonst passen die Etiketten nicht auf die Stanzung.', 'lindenweg16' ) . '</p>';
		echo '</form>';
	}

	/**
	 * Verweis auf die 3MF-Datei der freigegebenen Revision.
	 *
	 * Steht direkt beim Auftrag, weil genau hier die Frage aufkommt: aus
	 * welcher Datei wird das jetzt gedruckt?
	 *
	 * @param array $order     Auftrag.
	 * @param array $revisions article_id => aktive Revision.
	 */
	protected function render_model_link( array $order, array $revisions ) {
		if ( 'druck' !== $order['kind'] ) {
			return;
		}

		$revision = isset( $revisions[ (int) $order['article_id'] ] ) ? $revisions[ (int) $order['article_id'] ] : null;

		if ( ! $revision || empty( $revision['model_file'] ) ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'lw16_betrieb_model_get',
					'revision' => (int) $revision['id'],
				),
				admin_url( 'admin-post.php' )
			),
			'lw16_betrieb_model_' . (int) $revision['id']
		);

		printf(
			'<br /><a href="%s">%s</a>',
			esc_url( $url ),
			esc_html( sprintf( /* translators: %s: Version. */ __( '3MF %s laden', 'lindenweg16' ), $revision['version'] ) )
		);
	}

	/**
	 * Fertigungsaufträge.
	 */
	protected function render_work_orders() {
		$articles = ArticleRepository::all_by_id();

		echo '<h2>' . esc_html__( 'Aufträge', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Zwischen Rohling und fertigem Artikel liegen Arbeitsschritte, die kein Druckjob sind: Gewindemuttern einschmelzen, Stange und Abstandshalter verbinden. Dafür ist der Fertigungsauftrag da – er ist das Gegenstück zum Druckjob, nur ohne Drucker.', 'lindenweg16' ) . '</p>';
		echo '<p>' . esc_html__( 'Beim Fertigungsauftrag entsteht mit dem Anlegen automatisch die Materialliste: eine Ebene tief, also genau das, was aus dem Regal geholt werden muss. Gebucht wird erst beim Fertigmelden – dann geht das Material ab und das Fertigteil zu.', 'lindenweg16' ) . '</p>';
		echo '<p>' . esc_html__( 'Der Druckauftrag plant, was aus dem Drucker kommen soll. Gebucht wird er im Druckjournal, weil Drucker, Charge und Ausschuss erst am Ende feststehen – das Formular ist dann schon ausgefüllt.', 'lindenweg16' ) . '</p>';

		// --- Neuer Auftrag ------------------------------------------------
		// Beide Auftragsarten, in einem Formular. Welche Arten ein Artikel
		// zulaesst, entscheidet sein Beschaffungsweg; der Hybridartikel
		// steht in beiden Listen, weil bei ihm erst gedruckt und danach
		// montiert wird.
		$makeable = array();
		$printable = array();

		foreach ( ArticleRepository::selectable() as $article ) {
			if ( WorkOrderRepository::fits_kind( $article, 'montage' ) ) {
				$makeable[] = $article;
			}
			if ( WorkOrderRepository::fits_kind( $article, 'druck' ) ) {
				$printable[] = $article;
			}
		}

		if ( ! $makeable && ! $printable ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Noch kein montierter Artikel angelegt. Ein Halbzeug bekommt unter Artikel & Revisionen den Beschaffungsweg „Halbzeug oder Baugruppe (Montage)" und eine Stückliste – dann erscheint es hier.', 'lindenweg16' ) . '</p></div>';
		} else {
			echo '<h3>' . esc_html__( 'Neuen Auftrag anlegen', 'lindenweg16' ) . '</h3>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::NONCE_WORK );
			echo '<input type="hidden" name="action" value="lw16_betrieb_work_order" />';

			echo '<p><select name="kind">';
			foreach ( WorkOrderRepository::kinds() as $key => $label ) {
				printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
			}
			echo '</select> ';

			// Beide Listen stehen im Formular; welche gilt, entscheidet die
			// Auftragsart beim Speichern. Ein Artikel, der zur gewaehlten Art
			// nicht passt, wird dort abgewiesen und benannt.
			$auswahl = array();
			foreach ( array_merge( $makeable, $printable ) as $article ) {
				$auswahl[ (int) $article['id'] ] = $article;
			}

			echo '<select name="article_id">';
			foreach ( $auswahl as $article ) {
				printf(
					'<option value="%d">%s</option>',
					(int) $article['id'],
					esc_html( $article['sku'] . ' – ' . $article['name'] )
				);
			}
			echo '</select> ';

			printf( '<input type="text" name="qty_planned" size="5" placeholder="%s" /> ', esc_attr__( 'Stück', 'lindenweg16' ) );

			echo '<select name="color">';
			foreach ( ColorRegistry::options() as $slug => $label ) {
				printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $label ) );
			}
			echo '</select> ';

			printf( '<button class="button button-primary">%s</button></p></form>', esc_html__( 'Auftrag anlegen', 'lindenweg16' ) );
		}

		// --- Bestehende Aufträge -----------------------------------------
		$orders = WorkOrderRepository::all();

		if ( ! $orders ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Aufträge', 'lindenweg16' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Auftrag', 'lindenweg16' ),
			__( 'Art', 'lindenweg16' ),
			__( 'Artikel', 'lindenweg16' ),
			__( 'Farbe', 'lindenweg16' ),
			__( 'Geplant', 'lindenweg16' ),
			__( 'Material', 'lindenweg16' ),
			__( 'Pickliste', 'lindenweg16' ),
			__( 'Zeit', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}

		// Was die Arbeit gekostet hat, sieht nur, wer Kosten sehen darf.
		$mit_kosten = Roles::current_user_can_betrieb( 'lw16_view_costs' );

		if ( $mit_kosten ) {
			echo '<th>' . esc_html__( 'Lohnkosten', 'lindenweg16' ) . '</th>';
		}

		foreach ( array(
			__( 'Status', 'lindenweg16' ),
			'',
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$components = array();
		foreach ( ComponentRepository::all() as $component ) {
			$components[ (int) $component['id'] ] = $component;
		}

		$revisions = RevisionRepository::active_by_article();
		$wages     = $mit_kosten ? WageRepository::all() : array();

		foreach ( $orders as $order ) {
			$article = isset( $articles[ (int) $order['article_id'] ] ) ? $articles[ (int) $order['article_id'] ] : null;

			$need  = WorkOrderRepository::material_need(
				BomRepository::for_article( (int) $order['article_id'] ),
				(float) $order['qty_planned']
			);
			$parts = array();
			foreach ( $need as $row ) {
				if ( 'komponente' === $row['child_type'] ) {
					$label = isset( $components[ $row['child_id'] ] ) ? $components[ $row['child_id'] ]['code'] : '?';
				} else {
					$label = isset( $articles[ $row['child_id'] ] ) ? $articles[ $row['child_id'] ]['sku'] : '?';
				}
				$parts[] = number_format_i18n( $row['qty'], 0 ) . '× ' . $label;
			}

			// Laeuft der Timer, faellt die Zeile durch ihre Farbe auf.
			printf( '<tr%s>', WorkOrderRepository::is_running( $order ) ? ' class="lw16-row--laeuft"' : '' );
			$kinds = WorkOrderRepository::kinds();

			printf( '<td><strong>%s</strong></td>', esc_html( $order['code'] ) );
			printf( '<td>%s</td>', esc_html( isset( $kinds[ $order['kind'] ] ) ? $kinds[ $order['kind'] ] : $order['kind'] ) );
			echo '<td>';
			echo esc_html( $article ? $article['sku'] . ' – ' . $article['name'] : '?' );
			$this->render_model_link( $order, $revisions );
			echo '</td>';
			printf( '<td>%s</td>', esc_html( ColorRegistry::label( $order['color'] ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $order['qty_planned'], 0 ) ) );
			printf( '<td>%s</td>', esc_html( implode( ' · ', $parts ) ) );

			echo '<td>';
			$this->render_picklist_cell( $order );
			echo '</td>';

			printf( '<td>%s</td>', esc_html( $this->duration_label( $order ) ) );

			if ( $mit_kosten ) {
				printf( '<td>%s</td>', esc_html( $this->labor_label( $order, $wages ) ) );
			}

			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
				esc_attr( $this->status_color( $order['status'] ) ),
				esc_html( $this->status_label( $order['status'] ) )
			);

			echo '<td>';
			if ( in_array( $order['status'], WorkOrderRepository::OPEN_STATES, true ) ) {
				// Uhr: starten, solange nichts läuft – anhalten, solange sie läuft.
				$laeuft = WorkOrderRepository::is_running( $order );

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:4px">';
				wp_nonce_field( self::NONCE_WORK );
				echo '<input type="hidden" name="action" value="lw16_betrieb_work_timer" />';
				printf( '<input type="hidden" name="work_order_id" value="%d" />', (int) $order['id'] );
				if ( $laeuft ) {
					echo '<input type="hidden" name="pause" value="1" />';
				}
				printf(
					'<button class="button button-small">%s</button>',
					esc_html( $laeuft ? __( 'Produktion pausieren', 'lindenweg16' ) : __( 'Produktion starten', 'lindenweg16' ) )
				);
				echo '</form>';

				if ( 'druck' === $order['kind'] ) {
					// Ein Druckauftrag wird im Journal fertiggemeldet: Drucker,
					// Charge und Ausschuss stehen erst am Ende fest.
					printf(
						'<a class="button button-primary button-small" href="%s">%s</a>',
						esc_url( admin_url( 'admin.php?page=lw16-betrieb-druckjournal&work_order=' . (int) $order['id'] ) ),
						esc_html__( 'Im Journal buchen', 'lindenweg16' )
					);
				} else {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
					wp_nonce_field( self::NONCE_WORK );
					echo '<input type="hidden" name="action" value="lw16_betrieb_work_done" />';
					printf( '<input type="hidden" name="work_order_id" value="%d" />', (int) $order['id'] );
					printf(
						'<input type="text" name="qty_done" size="4" value="%s" /> <button class="button button-primary button-small">%s</button>',
						esc_attr( number_format_i18n( (float) $order['qty_planned'], 0 ) ),
						esc_html__( 'Fertigmelden', 'lindenweg16' )
					);
					echo '</form>';
				}

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:4px">';
				wp_nonce_field( self::NONCE_WORK );
				echo '<input type="hidden" name="action" value="lw16_betrieb_work_done" />';
				echo '<input type="hidden" name="cancel" value="1" />';
				printf( '<input type="hidden" name="work_order_id" value="%d" />', (int) $order['id'] );
				printf(
					'<input type="text" name="reason" size="10" placeholder="%s" /> <button class="button button-small">%s</button>',
					esc_attr__( 'Grund', 'lindenweg16' ),
					esc_html__( 'Storno', 'lindenweg16' )
				);
				echo '</form>';
			} elseif ( 'erledigt' === $order['status'] ) {
				printf(
					'<span>%s</span>',
					esc_html( sprintf( /* translators: %s: Menge. */ __( '%s gefertigt', 'lindenweg16' ), number_format_i18n( (float) $order['qty_done'], 0 ) ) )
				);
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Picklisten.
	 */
	protected function render_picklists() {
		echo '<h2>' . esc_html__( 'Picklisten', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Eine Pickliste fasst alles zusammen, was aus den offenen Bestellungen raus muss – sortiert nach Lagerplatz, nicht nach Bestellung. Gebucht wird erst beim Abhaken am Handy.', 'lindenweg16' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE . '_pick' );
		echo '<input type="hidden" name="action" value="lw16_betrieb_make_picklist" />';
		printf( '<p><button class="button button-primary">%s</button></p>', esc_html__( 'Pickliste aus offenen Bestellungen erzeugen', 'lindenweg16' ) );
		echo '</form>';

		$lists = PicklistRepository::all();

		if ( ! $lists ) {
			echo '<p>' . esc_html__( 'Noch keine Pickliste.', 'lindenweg16' ) . '</p>';
			return;
		}

		$articles   = ArticleRepository::all_by_id();
		$components = array();
		foreach ( ComponentRepository::all() as $component ) {
			$components[ (int) $component['id'] ] = $component;
		}

		foreach ( $lists as $list ) {
			printf(
				'<h3>%s <span class="lw16-ampel lw16-ampel--%s">%s</span> <small>%s</small></h3>',
				esc_html( $list['code'] ),
				'offen' === $list['status'] ? 'gelb' : 'gruen',
				esc_html( $list['status'] ),
				esc_html( 'fertigung' === $list['source'] ? __( 'Fertigung', 'lindenweg16' ) : __( 'Bestellungen', 'lindenweg16' ) )
			);

			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Platz', 'lindenweg16' ),
				__( 'Artikel', 'lindenweg16' ),
				__( 'Farbe', 'lindenweg16' ),
				__( 'Bestellung', 'lindenweg16' ),
				__( 'Soll', 'lindenweg16' ),
				__( 'Entnommen', 'lindenweg16' ),
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( PicklistRepository::items( (int) $list['id'] ) as $item ) {
				$bin    = $item['bin_id'] ? BinRepository::find( (int) $item['bin_id'] ) : null;
				$target = PicklistRepository::describe( $item, $articles, $components );

				echo '<tr>';
				printf( '<td>%s</td>', esc_html( $bin ? $bin['code'] : '—' ) );
				printf(
					'<td>%s%s</td>',
					esc_html( $target['sku'] . ' · ' . $target['name'] ),
					'komponente' === $item['item_type'] ? ' <small>' . esc_html__( 'Kleinteil', 'lindenweg16' ) . '</small>' : ''
				);
				printf( '<td>%s</td>', esc_html( 'komponente' === $item['item_type'] ? '—' : (string) $item['color'] ) );
				printf( '<td>%s</td>', $item['wc_order_id'] ? esc_html( '#' . (int) $item['wc_order_id'] ) : '—' );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $item['qty_required'], 0 ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $item['qty_picked'], 0 ) ) );
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}
}
