<?php
/**
 * Die übrigen Seiten des Moduls: Qualität, Kapazität, Zeiterfassung,
 * Kontakte und Berichte.
 *
 * Zusammengefasst, weil sie sich denselben Aufbau teilen: Formular oben,
 * Liste darunter, Auswertung daneben.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\BatchRepository;
use Lindenweg16\Modules\Betrieb\CalcSettings;
use Lindenweg16\Modules\Betrieb\CalculationContext;
use Lindenweg16\Modules\Betrieb\CapacityPlanner;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\ContactRepository;
use Lindenweg16\Modules\Betrieb\DeviceLabel;
use Lindenweg16\Modules\Betrieb\DiscountResolver;
use Lindenweg16\Modules\Betrieb\FilamentRepository;
use Lindenweg16\Modules\Betrieb\IsoWeek;
use Lindenweg16\Modules\Betrieb\Kpi;
use Lindenweg16\Modules\Betrieb\KpiGoalRepository;
use Lindenweg16\Modules\Betrieb\KpiGoals;
use Lindenweg16\Modules\Betrieb\KpiMeter;
use Lindenweg16\Modules\Betrieb\MaintenanceRepository;
use Lindenweg16\Modules\Betrieb\OrderOpsRepository;
use Lindenweg16\Modules\Betrieb\Period;
use Lindenweg16\Modules\Betrieb\PrinterRepository;
use Lindenweg16\Modules\Betrieb\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\ReorderPage;
use Lindenweg16\Modules\Betrieb\ReorderToken;
use Lindenweg16\Modules\Betrieb\Reporting;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\StockLedger;
use Lindenweg16\Modules\Betrieb\StockRepository;
use Lindenweg16\Modules\Betrieb\Stopwatch;
use Lindenweg16\Modules\Betrieb\TimeCategoryGuess;
use Lindenweg16\Modules\Betrieb\TimeRepository;
use Lindenweg16\Modules\Betrieb\TimerState;
use Lindenweg16\Modules\Betrieb\TrendChart;
use Lindenweg16\Modules\Betrieb\Traceability;

/**
 * Registriert und rendert die übrigen Seiten.
 */
class SimplePages {

	const NONCE = 'lw16_betrieb_pages';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_qualitaet', array( $this, 'quality' ) );
		add_action( 'lw16_betrieb_render_lw16_betrieb_kapazitaet', array( $this, 'capacity' ) );
		add_action( 'lw16_betrieb_render_lw16_betrieb_zeit', array( $this, 'time' ) );
		add_action( 'lw16_betrieb_render_lw16_betrieb_kontakte', array( $this, 'contacts' ) );
		add_action( 'lw16_betrieb_render_lw16_betrieb_berichte', array( $this, 'reports' ) );

		foreach ( array( 'time', 'contact', 'activity', 'term', 'token', 'maintenance', 'freeze', 'commission', 'goals' ) as $what ) {
			add_action( 'admin_post_lw16_betrieb_' . $what, array( $this, 'handle_' . $what ) );
		}
	}

	/* ====================================================================
	 * Infrastruktur
	 * ================================================================== */

	/**
	 * Zugriff prüfen.
	 *
	 * @param string $cap Capability.
	 */
	protected function require_cap( $cap = 'lw16_manage_production' ) {
		if ( ! Roles::current_user_can_betrieb( $cap ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * POST-Daten holen.
	 *
	 * @param string $cap Erforderliches Recht.
	 * @return array
	 */
	protected function input( $cap = 'lw16_manage_production' ) {
		$this->require_cap( $cap );
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		return array_map( 'wp_unslash', array_filter( (array) $_POST, 'is_scalar' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $page Slug.
	 * @param string $msg  Meldung.
	 * @param string $type Typ.
	 * @param array  $args Zusatzparameter.
	 */
	protected function redirect( $page, $msg, $type = 'success', array $args = array() ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'     => $page,
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
	 * Meldung anzeigen.
	 */
	protected function notice() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['lw16_msg'] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success',
			esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Formularkopf.
	 *
	 * @param string $action Aktion.
	 */
	protected function form( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="action" value="lw16_betrieb_%s" />', esc_attr( $action ) );
	}

	/**
	 * Tabellenkopf.
	 *
	 * @param array $heads Spalten.
	 */
	protected function thead( array $heads ) {
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $heads as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
	}

	/* ====================================================================
	 * Handler
	 * ================================================================== */

	/**
	 * Zeiteintrag erfassen.
	 */
	public function handle_time() {
		// Zeit erfassen darf, wer arbeitet – nicht nur, wer Stammdaten pflegt.
		$input      = $this->input( 'lw16_capture' );
		$aus_der_uhr = ! empty( $input['stoppuhr'] );

		// Kommt der Eintrag aus der Stoppuhr, muss die Uhr auch noch laufen.
		// Sonst bucht ein zweiter Klick auf „Erfassen" – oder die
		// zurückgeschickte Seite – dieselbe Zeit ein weiteres Mal.
		if ( $aus_der_uhr && ! TimerState::is_running() ) {
			$this->redirect(
				'lw16-betrieb-zeit',
				__( 'Die Uhr wurde inzwischen gestoppt oder verworfen – es wurde nichts gebucht.', 'lindenweg16' ),
				'error'
			);
		}

		$result = TimeRepository::validate( $input );

		if ( $result['errors'] ) {
			$this->redirect( 'lw16-betrieb-zeit', implode( ' ', $result['errors'] ), 'error' );
		}

		TimeRepository::insert( $result['data'] );

		if ( $aus_der_uhr ) {
			TimerState::discard();
		}

		$this->redirect( 'lw16-betrieb-zeit', __( 'Zeit erfasst.', 'lindenweg16' ) );
	}

	/**
	 * Kontakt speichern.
	 */
	public function handle_contact() {
		$input  = $this->input();
		$result = ContactRepository::save( $input );

		$this->redirect(
			'lw16-betrieb-kontakte',
			$result['errors'] ? implode( ' ', $result['errors'] ) : __( 'Kontakt gespeichert.', 'lindenweg16' ),
			$result['errors'] ? 'error' : 'success',
			$result['id'] ? array( 'contact' => $result['id'] ) : array()
		);
	}

	/**
	 * Aktivität erfassen.
	 */
	public function handle_activity() {
		$input  = $this->input();
		$result = ContactRepository::add_activity( $input );

		$this->redirect(
			'lw16-betrieb-kontakte',
			$result['errors'] ? implode( ' ', $result['errors'] ) : __( 'Aktivität erfasst.', 'lindenweg16' ),
			$result['errors'] ? 'error' : 'success',
			array( 'contact' => isset( $input['contact_id'] ) ? (int) $input['contact_id'] : 0 )
		);
	}

	/**
	 * Konditionsstufe festschreiben.
	 */
	public function handle_term() {
		$input = $this->input();
		ContactRepository::add_term( $input );

		$this->redirect(
			'lw16-betrieb-kontakte',
			__( 'Kondition festgeschrieben.', 'lindenweg16' ),
			'success',
			array( 'contact' => isset( $input['contact_id'] ) ? (int) $input['contact_id'] : 0 )
		);
	}

	/**
	 * Nachbestell-Link erzeugen oder widerrufen.
	 */
	public function handle_token() {
		$input      = $this->input();
		$contact_id = isset( $input['contact_id'] ) ? (int) $input['contact_id'] : 0;

		if ( ! empty( $input['revoke'] ) ) {
			ReorderToken::revoke_all( $contact_id, __( 'Manuell widerrufen.', 'lindenweg16' ) );
			$this->redirect( 'lw16-betrieb-kontakte', __( 'Link widerrufen.', 'lindenweg16' ), 'success', array( 'contact' => $contact_id ) );
		}

		$token = ReorderToken::issue( $contact_id );

		// Der Link darf nicht in die Adresszeile: Er landete sonst im
		// Browserverlauf, im Referer und im Serverprotokoll. Stattdessen
		// einmalig in einem kurzlebigen Transient für genau diesen Benutzer.
		if ( $token['url'] ) {
			set_transient( 'lw16_reorder_link_' . get_current_user_id(), $token['url'], 5 * MINUTE_IN_SECONDS );
		}

		$this->redirect(
			'lw16-betrieb-kontakte',
			$token['url']
				? __( 'Neuer Link erzeugt – er wird unten nur dieses eine Mal angezeigt.', 'lindenweg16' )
				: __( 'Link konnte nicht erzeugt werden.', 'lindenweg16' ),
			$token['url'] ? 'success' : 'error',
			array( 'contact' => $contact_id )
		);
	}

	/**
	 * Kommissionsbewegung buchen.
	 */
	public function handle_commission() {
		$input = $this->input();

		$type       = isset( $input['movement_type'] ) ? sanitize_key( $input['movement_type'] ) : '';
		$contact_id = isset( $input['contact_id'] ) ? (int) $input['contact_id'] : 0;
		$article_id = isset( $input['article_id'] ) ? (int) $input['article_id'] : 0;
		$color      = isset( $input['color'] ) ? sanitize_text_field( $input['color'] ) : '';
		$qty        = isset( $input['qty'] ) ? (float) str_replace( ',', '.', (string) $input['qty'] ) : 0.0;

		$movements = StockLedger::commission_pair( $type, $article_id, $color, $qty, $contact_id );

		if ( ! $movements || $qty <= 0 ) {
			$this->redirect( 'lw16-betrieb-kontakte', __( 'Bitte Bewegungsart, Artikel und Menge angeben.', 'lindenweg16' ), 'error', array( 'contact' => $contact_id ) );
		}

		StockRepository::book_many(
			$movements,
			array(
				'ref_type' => 'manual',
				'note'     => __( 'Kommission', 'lindenweg16' ),
			)
		);

		// Erst mit der Abrechnung entsteht Umsatz: Die Ware verlässt den
		// Bestand endgültig. Ohne diesen Bestellsatz tauchte der Verkauf in
		// keiner Auswertung auf und der Händler bekäme keine Rechnung.
		if ( 'kommission_abgerechnet' === $type ) {
			$article = ArticleRepository::find( $article_id );

			if ( $article ) {
				$items = array(
					array(
						'article_id'       => $article_id,
						'color'            => $color,
						'qty'              => $qty,
						'unit_price_gross' => (float) $article['price_gross'],
						'discountable'     => (int) $article['discountable'],
					),
				);

				$discount = DiscountResolver::for_contact( $contact_id, $items, gmdate( 'Y-m-d' ) );

				// Die Lagerbuchung ist oben bereits erfolgt – der Bestellsatz
				// darf sie nicht ein zweites Mal auslösen.
				$order_id = OrderOpsRepository::create_manual(
					array(
						'contact_id'           => $contact_id ? $contact_id : null,
						'manual_order_date'    => gmdate( 'Y-m-d' ),
						'manual_order_value'   => $discount['order_value_net_of_discount'],
						'channel'              => 'Fachhandel',
						'customer_type'        => 'B2B',
						'price_level'          => 'haendler',
						'applied_discount_pct' => $discount['discount_pct'],
						'applied_tier_id'      => $discount['tier_id'],
						'payment_terms_days'   => $discount['payment_terms_days'],
						'invoice_status'       => 'offen',
						'order_status'         => 'bestaetigt',
						'note'                 => __( 'Kommissionsabrechnung', 'lindenweg16' ),
					),
					$items,
					false
				);

				$this->redirect(
					'lw16-betrieb-kontakte',
					sprintf(
						/* translators: %s: Bestellwert. */
						__( 'Kommission abgerechnet und als offener Posten über %s erfasst.', 'lindenweg16' ),
						number_format_i18n( $discount['order_value_net_of_discount'], 2 ) . ' €'
					),
					'success',
					array( 'contact' => $contact_id )
				);
			}
		}

		$this->redirect( 'lw16-betrieb-kontakte', __( 'Kommissionsbewegung gebucht.', 'lindenweg16' ), 'success', array( 'contact' => $contact_id ) );
	}

	/**
	 * Wartung erfassen.
	 */
	public function handle_maintenance() {
		$input = $this->input( 'lw16_capture' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- in input() geprüft.
		$input['tasks'] = isset( $_POST['tasks'] ) ? (array) wp_unslash( $_POST['tasks'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

		$check = MaintenanceRepository::validate( $input );

		if ( $check['errors'] ) {
			$this->redirect( 'lw16-betrieb-drucker', implode( ' ', $check['errors'] ), 'error' );
		}

		MaintenanceRepository::insert( $input );

		$this->redirect( 'lw16-betrieb-drucker', __( 'Wartung erfasst.', 'lindenweg16' ) );
	}

	/**
	 * Monatsabschluss.
	 */
	public function handle_freeze() {
		$this->require_cap( 'lw16_view_reports' );
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$year  = isset( $_POST['year'] ) ? (int) $_POST['year'] : (int) gmdate( 'Y' );
		$month = isset( $_POST['month'] ) ? (int) $_POST['month'] : (int) gmdate( 'n' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$count = Reporting::freeze_month( $year, $month );

		$this->redirect(
			'lw16-betrieb-berichte',
			sprintf(
				/* translators: %d: Anzahl. */
				__( 'Monatsabschluss: %d Kennzahlen eingefroren.', 'lindenweg16' ),
				$count
			)
		);
	}

	/* ====================================================================
	 * Qualität
	 * ================================================================== */

	/**
	 * Qualitätsseite.
	 */
	public function quality() {
		// Wie im Menü: Wer erfasst, darf hierher. Stand hier das
		// Stammdatenrecht, sähe die Werkstatt den Menüpunkt und bekäme
		// beim Klick „Keine Berechtigung".
		$this->require_cap( 'lw16_capture' );

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Qualität', 'lindenweg16' ) . '</h1>';
		$this->notice();

		// phpcs:disable WordPress.Security.NonceVerification
		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'chargen';
		$batch = isset( $_GET['batch'] ) ? (int) $_GET['batch'] : 0;
		$lot   = isset( $_GET['lot'] ) ? (int) $_GET['lot'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification

		$base = admin_url( 'admin.php?page=lw16-betrieb-qualitaet' );
		echo '<nav class="lw16-tabs">';
		foreach ( array(
			'chargen'         => __( 'Chargenbuch', 'lindenweg16' ),
			'rueckverfolgung' => __( 'Rückverfolgung', 'lindenweg16' ),
		) as $key => $label ) {
			printf(
				'<a href="%s" class="%s">%s</a> ',
				esc_url( add_query_arg( 'view', $key, $base ) ),
				$view === $key ? 'active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'rueckverfolgung' === $view ) {
			$this->trace( $lot, $batch );
		} else {
			$this->batches( $batch );
		}

		echo '</div>';
	}

	/**
	 * Chargenbuch.
	 *
	 * @param int $batch_id Charge für die Detailansicht.
	 */
	protected function batches( $batch_id ) {
		if ( $batch_id ) {
			$this->batch_detail( $batch_id );
			return;
		}

		$batches  = BatchRepository::all( array( 'limit' => 200 ) );
		$articles = ArticleRepository::all_by_id();

		echo '<h2>' . esc_html__( 'Chargenbuch', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Jeder Druckjob mit guten Teilen erzeugt eine Charge. Der Code wird ins Bauteil geprägt.', 'lindenweg16' ) . '</p>';

		if ( ! $batches ) {
			echo '<p>' . esc_html__( 'Noch keine Chargen.', 'lindenweg16' ) . '</p>';
			return;
		}

		$this->thead(
			array(
				__( 'Chargencode', 'lindenweg16' ),
				__( 'Produziert', 'lindenweg16' ),
				__( 'Artikel', 'lindenweg16' ),
				__( 'Stückzahl', 'lindenweg16' ),
				__( 'Geprüft', 'lindenweg16' ),
				'',
			)
		);

		foreach ( $batches as $batch ) {
			echo '<tr>';
			printf( '<td><code style="font-size:15px">%s</code></td>', esc_html( $batch['batch_code'] ) );
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $batch['produced_on'] ) ) );
			printf(
				'<td>%s</td>',
				esc_html( isset( $articles[ (int) $batch['article_id'] ] ) ? $articles[ (int) $batch['article_id'] ]['sku'] : '' )
			);
			printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $batch['qty'], 0 ) ) );
			printf( '<td>%s</td>', $batch['inspected'] ? esc_html__( 'ja', 'lindenweg16' ) : esc_html__( 'nein', 'lindenweg16' ) );
			printf(
				'<td><a href="%s">%s</a></td>',
				esc_url( add_query_arg( array( 'page' => 'lw16-betrieb-qualitaet', 'view' => 'chargen', 'batch' => (int) $batch['id'] ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Akte', 'lindenweg16' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Chargenakte mit GPSR-Export.
	 *
	 * @param int $batch_id Charge.
	 */
	protected function batch_detail( $batch_id ) {
		$dossier = Traceability::dossier( $batch_id );

		if ( ! $dossier ) {
			echo '<p>' . esc_html__( 'Charge nicht gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}

		printf(
			'<h2>%s</h2><p style="font-size:28px;font-weight:700"><code>%s</code></p><p>%s</p>',
			esc_html__( 'Chargenakte', 'lindenweg16' ),
			esc_html( $dossier['batch']['batch_code'] ),
			esc_html__( 'Diesen Code ins Bauteil prägen und in den Slicer übernehmen.', 'lindenweg16' )
		);

		printf(
			'<p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lw16_betrieb_gpsr&batch=' . (int) $batch_id ), 'lw16_betrieb_gpsr' ) ),
			esc_html__( 'GPSR-Nachweis als PDF', 'lindenweg16' ),
			esc_url( admin_url( 'admin.php?page=lw16-betrieb-qualitaet&view=chargen' ) ),
			esc_html__( 'Zurück', 'lindenweg16' )
		);

		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		foreach ( array(
			array( __( 'Artikel', 'lindenweg16' ), $dossier['article'] ? $dossier['article']['sku'] . ' – ' . $dossier['article']['name'] : '' ),
			array( __( 'Revision', 'lindenweg16' ), $dossier['revision'] ? $dossier['revision']['version'] : '—' ),
			array( __( 'Material', 'lindenweg16' ), $dossier['revision'] ? $dossier['revision']['material'] : '—' ),
			array( __( 'Filamentcharge', 'lindenweg16' ), $dossier['lot'] ? $dossier['lot']['lot_code'] : '—' ),
			array( __( 'Drucker', 'lindenweg16' ), $dossier['printer'] ? $dossier['printer']['code'] : '—' ),
			array( __( 'Stückzahl', 'lindenweg16' ), number_format_i18n( (float) $dossier['batch']['qty'], 0 ) ),
			array( __( 'Betroffene Bestellungen', 'lindenweg16' ), sprintf( '%d', count( $dossier['orders'] ) ) ),
		) as $row ) {
			printf( '<tr><td>%s</td><td><strong>%s</strong></td></tr>', esc_html( $row[0] ), esc_html( $row[1] ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Rückverfolgung in beide Richtungen.
	 *
	 * @param int $lot_id   Filamentcharge.
	 * @param int $batch_id Charge.
	 */
	protected function trace( $lot_id, $batch_id ) {
		echo '<h2>' . esc_html__( 'Rückverfolgung', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Von einer Filamentcharge zu allen betroffenen Chargen und Bestellungen – und umgekehrt.', 'lindenweg16' ) . '</p>';

		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="lw16-betrieb-qualitaet" />';
		echo '<input type="hidden" name="view" value="rueckverfolgung" />';
		echo '<select name="lot"><option value="0">' . esc_html__( '– Filamentcharge wählen –', 'lindenweg16' ) . '</option>';

		foreach ( FilamentRepository::all() as $lot ) {
			printf(
				'<option value="%d"%s>%s – %s</option>',
				(int) $lot['id'],
				selected( $lot_id, (int) $lot['id'], false ),
				esc_html( $lot['lot_code'] ),
				esc_html( (string) $lot['material'] )
			);
		}

		echo '</select> <button class="button">' . esc_html__( 'Verfolgen', 'lindenweg16' ) . '</button></form>';

		if ( ! $lot_id ) {
			return;
		}

		$trace    = Traceability::forward_from_lot( $lot_id );
		$articles = ArticleRepository::all_by_id();

		printf(
			'<h3>%s</h3>',
			esc_html( sprintf( /* translators: %s: Chargencode. */ __( 'Aus Filamentcharge %s entstanden', 'lindenweg16' ), $trace['lot']['lot_code'] ) )
		);

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: Druckjobs, 2: Chargen, 3: Bestellungen. */
					__( '%1$d Druckjobs, %2$d Chargen, %3$d betroffene Bestellungen.', 'lindenweg16' ),
					count( $trace['jobs'] ),
					count( $trace['batches'] ),
					count( $trace['orders'] )
				)
			)
		);

		if ( $trace['batches'] ) {
			$this->thead(
				array(
					__( 'Chargencode', 'lindenweg16' ),
					__( 'Produziert', 'lindenweg16' ),
					__( 'Artikel', 'lindenweg16' ),
					__( 'Stück', 'lindenweg16' ),
				)
			);
			foreach ( $trace['batches'] as $batch ) {
				echo '<tr>';
				printf( '<td><code>%s</code></td>', esc_html( $batch['batch_code'] ) );
				printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $batch['produced_on'] ) ) );
				printf(
					'<td>%s</td>',
					esc_html( isset( $articles[ (int) $batch['article_id'] ] ) ? $articles[ (int) $batch['article_id'] ]['sku'] : '' )
				);
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $batch['qty'], 0 ) ) );
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		if ( $trace['orders'] ) {
			echo '<h3>' . esc_html__( 'Betroffene Bestellungen', 'lindenweg16' ) . '</h3>';
			$this->thead( array( __( 'Herkunft', 'lindenweg16' ), __( 'Datum', 'lindenweg16' ), __( 'Menge', 'lindenweg16' ) ) );
			foreach ( $trace['orders'] as $order ) {
				echo '<tr>';
				printf( '<td>%s #%d</td>', esc_html( $order['ref_type'] ), (int) $order['ref_id'] );
				printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $order['moved_at'] ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( abs( (float) $order['qty'] ), 0 ) ) );
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/* ====================================================================
	 * Kapazität
	 * ================================================================== */

	/**
	 * Kapazitätsseite.
	 */
	public function capacity() {
		$this->require_cap();

		$settings = CalculationContext::settings();
		$ctx      = CalculationContext::build();
		$printers = count(
			array_filter(
				PrinterRepository::all(),
				static function ( $printer ) {
					return in_array( $printer['status'], array( 'betrieb', 'wartung' ), true );
				}
			)
		);

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Kapazität', 'lindenweg16' ) . '</h1>';
		$this->notice();

		// phpcs:ignore WordPress.Security.NonceVerification
		$mengen = isset( $_GET['bundle'] ) && is_array( $_GET['bundle'] ) ? array_map( 'floatval', wp_unslash( $_GET['bundle'] ) ) : array();

		$articles = ArticleRepository::selectable( true );

		echo '<h2>' . esc_html__( 'Vorschau', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Erwartete Bestellungen je Artikel und Woche eintragen. Die Rechnung löst Stücklisten auf und weist Druckzeit, Nachbearbeitung und benötigte Zukaufmengen aus.', 'lindenweg16' ) . '</p>';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="lw16-betrieb-kapazitaet" /><p>';
		foreach ( $articles as $article ) {
			printf(
				'<label style="margin-right:12px">%s <input type="number" min="0" step="1" name="bundle[%d]" value="%s" style="width:70px" /></label>',
				esc_html( $article['sku'] ),
				(int) $article['id'],
				esc_attr( isset( $mengen[ (int) $article['id'] ] ) ? (string) (int) $mengen[ (int) $article['id'] ] : '0' )
			);
		}
		printf( '<button class="button button-primary">%s</button></p></form>', esc_html__( 'Rechnen', 'lindenweg16' ) );

		$items = array();
		foreach ( $mengen as $article_id => $qty ) {
			if ( $qty > 0 ) {
				$items[] = array( 'article_id' => (int) $article_id, 'qty' => 1 );
			}
		}

		if ( $items ) {
			$bundles = array();
			foreach ( $mengen as $article_id => $qty ) {
				if ( $qty > 0 ) {
					$bundles[] = array(
						'items' => array( array( 'article_id' => (int) $article_id, 'qty' => 1 ) ),
						'count' => $qty,
					);
				}
			}

			$demand      = CapacityPlanner::demand( $bundles, $ctx, $settings );
			$utilisation = CapacityPlanner::utilisation( $demand['print_time_h'], $printers, (float) $settings['net_hours_per_printer'] );

			echo '<h3>' . esc_html__( 'Ergebnis der Woche', 'lindenweg16' ) . '</h3>';
			echo '<table class="widefat striped" style="max-width:620px"><tbody>';

			foreach ( array(
				array( __( 'Benötigte Druckstunden', 'lindenweg16' ), number_format_i18n( $demand['print_time_h'], 1 ) . ' h' ),
				array( __( 'Verfügbare Kapazität', 'lindenweg16' ), number_format_i18n( $utilisation['capacity_h'], 1 ) . ' h' ),
				array( __( 'Nachbearbeitung', 'lindenweg16' ), number_format_i18n( $demand['postprocessing_min'] / 60, 1 ) . ' h' ),
			) as $row ) {
				printf( '<tr><td>%s</td><td style="text-align:right"><strong>%s</strong></td></tr>', esc_html( $row[0] ), esc_html( $row[1] ) );
			}

			printf(
				'<tr><td>%s</td><td style="text-align:right"><span class="lw16-ampel lw16-ampel--%s">%s %%</span></td></tr>',
				esc_html__( 'Auslastung', 'lindenweg16' ),
				esc_attr( $utilisation['level'] ),
				esc_html( number_format_i18n( $utilisation['utilisation'] * 100, 1 ) )
			);

			echo '</tbody></table>';

			if ( $demand['purchased'] ) {
				echo '<h3>' . esc_html__( 'Benötigte Zukaufmengen', 'lindenweg16' ) . '</h3><ul>';
				foreach ( $demand['purchased'] as $row ) {
					printf( '<li>%s: %s</li>', esc_html( $row['sku'] ), esc_html( number_format_i18n( $row['qty'], 0 ) ) );
				}
				echo '</ul>';
			}

			// Rückwärtsrechnung.
			$orders = max( 1, $demand['orders'] );
			$stock  = array();

			// Einmal alle Bewegungen, nicht je Artikel eine eigene Abfrage:
			// Bei zwanzig Zukaufteilen waren das zwanzig Läufe über dieselbe
			// Tabelle.
			$balances = StockLedger::balances( StockRepository::all() );

			foreach ( array_keys( $demand['purchased'] ) as $article_id ) {
				$sum = 0.0;

				if ( isset( $balances[ $article_id ] ) ) {
					foreach ( $balances[ $article_id ] as $locations ) {
						$sum += isset( $locations['lager'] ) ? (float) $locations['lager'] : 0.0;
					}
				}

				$stock[ $article_id ] = $sum;
			}

			$per_order = array(
				'print_time_h'       => $demand['print_time_h'] / $orders,
				'postprocessing_min' => $demand['postprocessing_min'] / $orders,
				'purchased'          => array_map(
					static function ( $row ) use ( $orders ) {
						return $row['qty'] / $orders;
					},
					$demand['purchased']
				),
			);

			$backwards = CapacityPlanner::backwards(
				$per_order,
				array(
					'print_h' => $utilisation['capacity_h'],
					'work_h'  => 14.0,
					'stock'   => $stock,
				)
			);

			echo '<h3>' . esc_html__( 'Wie viele Bestellungen schaffe ich diese Woche?', 'lindenweg16' ) . '</h3>';
			printf(
				'<p style="font-size:18px"><strong>%s</strong> — %s</p>',
				esc_html(
					null === $backwards['max_orders']
						? __( 'unbegrenzt', 'lindenweg16' )
						: sprintf( /* translators: %d: Anzahl. */ __( '%d Bestellungen', 'lindenweg16' ), $backwards['max_orders'] )
				),
				esc_html(
					sprintf(
						/* translators: %s: Ressource. */
						__( 'begrenzt durch %s', 'lindenweg16' ),
						$backwards['limiting_label']
					)
				)
			);
		}

		// Ist-Auslastung der letzten Wochen.
		echo '<h2>' . esc_html__( 'Auslastungsverlauf', 'lindenweg16' ) . '</h2>';
		$this->thead(
			array(
				__( 'Woche', 'lindenweg16' ),
				__( 'Druckstunden', 'lindenweg16' ),
				__( 'Kapazität', 'lindenweg16' ),
				__( 'Auslastung', 'lindenweg16' ),
				__( 'Plattenwechsel', 'lindenweg16' ),
				__( 'Bedienzeit', 'lindenweg16' ),
				__( 'Arbeitsstunden', 'lindenweg16' ),
			)
		);

		$current = IsoWeek::from_date( gmdate( 'Y-m-d' ) );
		for ( $i = 7; $i >= 0; $i-- ) {
			$week   = IsoWeek::shift( $current['iso_year'], $current['iso_week'], -$i );
			$bounds = IsoWeek::bounds( $week['iso_year'], $week['iso_week'] );
			$agg    = PrintJobRepository::aggregate( $bounds['von'], $bounds['bis'] );
			$util   = CapacityPlanner::utilisation( $agg['print_time_h'], $printers, (float) $settings['net_hours_per_printer'] );
			$bedien = CapacityPlanner::operator_hours( $agg['plate_changes'], (float) $settings['plate_change_minutes'] );

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( IsoWeek::label( $week['iso_year'], $week['iso_week'] ) ) );
			printf( '<td>%s h</td>', esc_html( number_format_i18n( $agg['print_time_h'], 1 ) ) );
			printf( '<td>%s h</td>', esc_html( number_format_i18n( $util['capacity_h'], 0 ) ) );
			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s %%</span></td>',
				esc_attr( $util['level'] ),
				esc_html( number_format_i18n( $util['utilisation'] * 100, 0 ) )
			);
			printf( '<td>%s</td>', esc_html( number_format_i18n( $agg['plate_changes'], 0 ) ) );
			printf( '<td>%s h</td>', esc_html( number_format_i18n( $bedien, 1 ) ) );
			printf( '<td>%s h</td>', esc_html( number_format_i18n( TimeRepository::hours_in_week( $week['iso_year'], $week['iso_week'] ), 1 ) ) );
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/* ====================================================================
	 * Zeiterfassung
	 * ================================================================== */

	/**
	 * Zeiterfassungsseite.
	 */
	public function time() {
		// Zeit erfasst, wer arbeitet – dasselbe Recht wie im Menü.
		$this->require_cap( 'lw16_capture' );

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Zeiterfassung', 'lindenweg16' ) . '</h1>';
		$this->notice();

		$this->stopwatch_form();

		echo '<h2>' . esc_html__( 'Eintrag', 'lindenweg16' ) . '</h2>';
		$this->form( 'time' );
		echo '<p>';
		printf( '<input type="date" name="entry_date" value="%s" /> ', esc_attr( gmdate( 'Y-m-d' ) ) );
		echo '<select name="category">';
		foreach ( TimeRepository::category_labels() as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select> ';
		printf( '<input type="text" name="hours" placeholder="%s" size="5" /> ', esc_attr__( 'Stunden', 'lindenweg16' ) );
		printf( '<input type="text" name="description" placeholder="%s" size="34" /> ', esc_attr__( 'Tätigkeit', 'lindenweg16' ) );
		printf( '<input type="text" name="phase_block" placeholder="%s" size="3" /> ', esc_attr__( 'Block', 'lindenweg16' ) );
		printf( '<button class="button button-primary">%s</button>', esc_html__( 'Erfassen', 'lindenweg16' ) );
		echo '</p></form>';

		echo '<h2>' . esc_html__( 'Wochenübersicht', 'lindenweg16' ) . '</h2>';
		$this->thead( array( __( 'Woche', 'lindenweg16' ), __( 'Stunden', 'lindenweg16' ), __( 'Budget', 'lindenweg16' ), __( 'Differenz', 'lindenweg16' ) ) );

		$budget = 14.0;
		foreach ( TimeRepository::hours_by_week( 12 ) as $row ) {
			$hours = (float) $row['hours'];
			$diff  = $hours - $budget;

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( IsoWeek::label( (int) $row['iso_year'], (int) $row['iso_week'] ) ) );
			printf( '<td>%s h</td>', esc_html( number_format_i18n( $hours, 1 ) ) );
			printf( '<td>%s h</td>', esc_html( number_format_i18n( $budget, 0 ) ) );
			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s h</span></td>',
				esc_attr( $hours > 25 ? 'rot' : ( $hours > $budget ? 'gelb' : 'gruen' ) ),
				esc_html( ( $diff > 0 ? '+' : '' ) . number_format_i18n( $diff, 1 ) )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Letzte Einträge', 'lindenweg16' ) . '</h2>';
		$this->thead( array( __( 'Datum', 'lindenweg16' ), __( 'Kategorie', 'lindenweg16' ), __( 'Tätigkeit', 'lindenweg16' ), __( 'Stunden', 'lindenweg16' ) ) );

		$categories = TimeRepository::category_labels();
		foreach ( TimeRepository::all( array( 'limit' => 40 ) ) as $entry ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $entry['entry_date'] ) ) );
			printf( '<td>%s</td>', esc_html( isset( $categories[ $entry['category'] ] ) ? $categories[ $entry['category'] ] : $entry['category'] ) );
			printf( '<td>%s</td>', esc_html( (string) $entry['description'] ) );
			printf( '<td>%s h</td>', esc_html( number_format_i18n( (float) $entry['hours'], 2 ) ) );
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Das Formular zum Stoppen der Uhr.
	 *
	 * Es steht nur da, wenn eine Uhr läuft. Vorbelegt sind Datum, Stunden
	 * und – falls das System sich sicher genug ist – die Tätigkeit. Bestätigt
	 * wird von Hand: Ein Klick, der stumm bucht, erzeugt Einträge, die
	 * niemand später zuordnen kann.
	 */
	protected function stopwatch_form() {
		$stopp = TimerState::stop_form();

		if ( ! $stopp ) {
			return;
		}

		echo '<div class="lw16-stoppuhr-box" id="lw16-stoppuhr-formular">';
		echo '<h2>' . esc_html__( 'Laufende Zeit', 'lindenweg16' ) . '</h2>';

		printf(
			'<p class="lw16-stoppuhr-box__zeit">%s <strong>%s</strong> %s</p>',
			esc_html__( 'Die Uhr läuft seit', 'lindenweg16' ),
			esc_html( Stopwatch::format( $stopp['seconds'] ) ),
			esc_html__( 'Stunden.', 'lindenweg16' )
		);

		if ( $stopp['implausible'] ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Das sieht nach einer vergessenen Uhr aus. Trag die tatsächlich gearbeitete Zeit ein, bevor du erfasst – die Vorgabe unten ist die volle Laufzeit.', 'lindenweg16' )
			);
		} elseif ( ! $stopp['bookable'] ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html__( 'Unter einer Minute – vermutlich ein Fehlklick. Du kannst die Uhr verwerfen, statt sie zu buchen.', 'lindenweg16' )
			);
		}

		if ( $stopp['explanation'] ) {
			printf(
				'<p class="description">%s %s</p>',
				esc_html__( 'Währenddessen warst du hier:', 'lindenweg16' ),
				esc_html( $stopp['explanation'] )
			);
		}

		$vorschlag = $stopp['suggestion'];

		if ( ! $vorschlag['confident'] ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Die Zeit verteilt sich auf mehrere Bereiche – such die Tätigkeit selbst aus, geraten wird hier nicht.', 'lindenweg16' )
			);
		}

		$this->form( 'time' );
		echo '<input type="hidden" name="stoppuhr" value="1" />';

		if ( $stopp['work_order_id'] ) {
			printf( '<input type="hidden" name="work_order_id" value="%d" />', (int) $stopp['work_order_id'] );
		}

		echo '<p>';
		printf( '<input type="date" name="entry_date" value="%s" /> ', esc_attr( $stopp['entry_date'] ) );

		echo '<select name="category">';
		foreach ( TimeRepository::category_labels() as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $key, $vorschlag['confident'] ? $vorschlag['category'] : 'sonstiges', false ),
				esc_html( $label )
			);
		}
		echo '</select> ';

		printf(
			'<input type="text" name="hours" value="%s" size="5" /> ',
			esc_attr( number_format( $stopp['hours'], 2, ',', '' ) )
		);
		printf( '<input type="text" name="description" placeholder="%s" size="34" /> ', esc_attr__( 'Tätigkeit', 'lindenweg16' ) );
		printf( '<button class="button button-primary">%s</button> ', esc_html__( 'Stoppen und erfassen', 'lindenweg16' ) );
		echo '</p></form>';

		printf(
			'<p><a class="button-link-delete" href="%s">%s</a></p>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'  => 'lw16_timer_discard',
							'zurueck' => rawurlencode( add_query_arg( array( 'page' => 'lw16-betrieb-zeit' ), admin_url( 'admin.php' ) ) ),
						),
						admin_url( 'admin-post.php' )
					),
					TimerBar::NONCE
				)
			),
			esc_html__( 'Verwerfen, ohne zu buchen', 'lindenweg16' )
		);

		echo '</div>';
	}

	/* ====================================================================
	 * Kontakte
	 * ================================================================== */

	/**
	 * Kontaktseite.
	 */
	public function contacts() {
		$this->require_cap();

		// phpcs:ignore WordPress.Security.NonceVerification
		$contact_id = isset( $_GET['contact'] ) ? (int) $_GET['contact'] : 0;

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Kontakte / B2B', 'lindenweg16' ) . '</h1>';
		$this->notice();

		if ( $contact_id ) {
			$this->contact_detail( $contact_id );
		} else {
			$this->contact_list();
		}

		echo '</div>';
	}

	/**
	 * Kontaktliste mit Wiedervorlagen.
	 */
	protected function contact_list() {
		$due = ContactRepository::due_follow_ups();

		if ( $due ) {
			echo '<h2>' . esc_html__( 'Fällige Wiedervorlagen', 'lindenweg16' ) . '</h2>';
			$this->thead( array( __( 'Fällig', 'lindenweg16' ), __( 'Firma', 'lindenweg16' ), __( 'Status', 'lindenweg16' ), '' ) );

			$status = ContactRepository::status_labels();
			foreach ( $due as $contact ) {
				echo '<tr>';
				printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $contact['follow_up_on'] ) ) );
				printf( '<td><strong>%s</strong></td>', esc_html( $contact['company'] ) );
				printf( '<td>%s</td>', esc_html( isset( $status[ $contact['status'] ] ) ? $status[ $contact['status'] ] : $contact['status'] ) );
				printf(
					'<td><a href="%s">%s</a></td>',
					esc_url( add_query_arg( array( 'page' => 'lw16-betrieb-kontakte', 'contact' => (int) $contact['id'] ), admin_url( 'admin.php' ) ) ),
					esc_html__( 'Akte öffnen', 'lindenweg16' )
				);
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Alle Kontakte', 'lindenweg16' ) . '</h2>';
		$this->thead(
			array(
				__( 'Firma', 'lindenweg16' ),
				__( 'Typ', 'lindenweg16' ),
				__( 'Ort', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
				__( 'Umsatz', 'lindenweg16' ),
				__( 'Wiedervorlage', 'lindenweg16' ),
				'',
			)
		);

		$types  = ContactRepository::type_labels();
		$status = ContactRepository::status_labels();

		foreach ( ContactRepository::all() as $contact ) {
			echo '<tr>';
			printf( '<td><strong>%s</strong></td>', esc_html( $contact['company'] ) );
			printf( '<td>%s</td>', esc_html( isset( $types[ $contact['type'] ] ) ? $types[ $contact['type'] ] : $contact['type'] ) );
			printf( '<td>%s</td>', esc_html( (string) $contact['city'] ) );
			printf( '<td>%s</td>', esc_html( isset( $status[ $contact['status'] ] ) ? $status[ $contact['status'] ] : $contact['status'] ) );
			printf( '<td>%s €</td>', esc_html( number_format_i18n( ContactRepository::revenue( (int) $contact['id'] ), 2 ) ) );
			printf( '<td>%s</td>', esc_html( $contact['follow_up_on'] ? mysql2date( 'd.m.Y', $contact['follow_up_on'] ) : '—' ) );
			printf(
				'<td><a href="%s">%s</a></td>',
				esc_url( add_query_arg( array( 'page' => 'lw16-betrieb-kontakte', 'contact' => (int) $contact['id'] ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Akte', 'lindenweg16' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Kontakt anlegen', 'lindenweg16' ) . '</h2>';
		$this->form( 'contact' );
		echo '<p>';
		printf( '<input type="text" name="company" placeholder="%s" size="24" /> ', esc_attr__( 'Firma / Name', 'lindenweg16' ) );
		echo '<select name="type">';
		foreach ( $types as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select> ';
		printf( '<input type="text" name="street" placeholder="%s" size="20" /> ', esc_attr__( 'Straße und Nr.', 'lindenweg16' ) );
		printf( '<input type="text" name="zip" placeholder="%s" size="6" /> ', esc_attr__( 'PLZ', 'lindenweg16' ) );
		printf( '<input type="text" name="city" placeholder="%s" size="14" /> ', esc_attr__( 'Ort', 'lindenweg16' ) );
		printf( '<input type="text" name="contact_person" placeholder="%s" size="16" /> ', esc_attr__( 'Ansprechpartner', 'lindenweg16' ) );
		printf( '<input type="text" name="phone" placeholder="%s" size="14" /> ', esc_attr__( 'Telefon', 'lindenweg16' ) );
		printf( '<input type="email" name="email" placeholder="%s" size="18" /> ', esc_attr__( 'E-Mail', 'lindenweg16' ) );
		printf( '<button class="button button-primary">%s</button>', esc_html__( 'Anlegen', 'lindenweg16' ) );
		echo '</p></form>';

		$this->b2b_report();
	}

	/**
	 * B2B-Auswertung.
	 */
	protected function b2b_report() {
		$rows = Reporting::b2b();

		if ( ! $rows ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Auswertung B2B', 'lindenweg16' ) . '</h2>';

		$rate = Reporting::reorder_rate();
		printf(
			'<p>%s <strong>%s</strong> — %s</p>',
			esc_html__( 'Nachbestellquote:', 'lindenweg16' ),
			esc_html( null === $rate ? '—' : number_format_i18n( $rate, 0 ) . ' %' ),
			esc_html__( 'Ein Händler, der einmal bestellt, ist ein Zufall; einer, der nachbestellt, ist ein Kanal.', 'lindenweg16' )
		);

		$this->thead(
			array(
				__( 'Händler', 'lindenweg16' ),
				__( 'Bestellungen', 'lindenweg16' ),
				__( 'Umsatz', 'lindenweg16' ),
				__( 'nachbestellt', 'lindenweg16' ),
				__( 'Tage bis zur Erstbestellung', 'lindenweg16' ),
			)
		);

		foreach ( $rows as $row ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $row['contact']['company'] ) );
			printf( '<td>%d</td>', (int) $row['orders'] );
			printf( '<td>%s €</td>', esc_html( number_format_i18n( $row['revenue'], 2 ) ) );
			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
				esc_attr( $row['reorder'] ? 'gruen' : 'gelb' ),
				esc_html( $row['reorder'] ? __( 'ja', 'lindenweg16' ) : __( 'nein', 'lindenweg16' ) )
			);
			printf( '<td>%s</td>', esc_html( null === $row['days_to_first'] ? '—' : (string) $row['days_to_first'] ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Kontaktakte auf einer Seite.
	 *
	 * @param int $contact_id Kontakt.
	 */
	protected function contact_detail( $contact_id ) {
		$contact = ContactRepository::find( $contact_id );

		if ( ! $contact ) {
			echo '<p>' . esc_html__( 'Kontakt nicht gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}

		printf(
			'<h2>%s <a class="button" href="%s">%s</a></h2>',
			esc_html( $contact['company'] ),
			esc_url( admin_url( 'admin.php?page=lw16-betrieb-kontakte' ) ),
			esc_html__( 'Zurück', 'lindenweg16' )
		);

		// Kopfzahlen: alles Wichtige ohne Scrollen.
		$orders    = OrderOpsRepository::all( array( 'contact_id' => $contact_id, 'limit' => 200 ) );
		$open      = array_filter(
			$orders,
			static function ( $order ) {
				return in_array( $order['invoice_status'], array( 'offen', 'berechnet' ), true ) && 'bestaetigt' === $order['order_status'];
			}
		);
		$open_sum  = 0.0;
		foreach ( $open as $order ) {
			$open_sum += OrderOpsRepository::order_value( $order );
		}

		echo '<div class="lw16-tiles">';
		foreach ( array(
			array( __( 'Gesamtumsatz', 'lindenweg16' ), number_format_i18n( ContactRepository::revenue( $contact_id ), 2 ) . ' €' ),
			array( __( 'Bestellungen', 'lindenweg16' ), (string) count( $orders ) ),
			array( __( 'Offene Posten', 'lindenweg16' ), number_format_i18n( $open_sum, 2 ) . ' €' ),
			array( __( 'Wiedervorlage', 'lindenweg16' ), $contact['follow_up_on'] ? mysql2date( 'd.m.Y', $contact['follow_up_on'] ) : '—' ),
		) as $tile ) {
			printf(
				'<div class="lw16-tile"><span class="lw16-tile__label">%s</span><span class="lw16-tile__value">%s</span></div>',
				esc_html( $tile[0] ),
				esc_html( $tile[1] )
			);
		}
		echo '</div>';

		// Konditionen.
		echo '<h3>' . esc_html__( 'Konditionen', 'lindenweg16' ) . '</h3>';
		$terms = ContactRepository::terms( $contact_id );

		if ( $terms ) {
			$this->thead(
				array(
					__( 'Ab Bestellwert', 'lindenweg16' ),
					__( 'Rabatt', 'lindenweg16' ),
					__( 'Zahlungsziel', 'lindenweg16' ),
					__( 'Gültig ab', 'lindenweg16' ),
					__( 'Gültig bis', 'lindenweg16' ),
					__( 'Vereinbart durch', 'lindenweg16' ),
				)
			);
			foreach ( $terms as $term ) {
				echo '<tr>';
				printf( '<td>%s €</td>', esc_html( number_format_i18n( (float) $term['min_order_value'], 2 ) ) );
				printf( '<td>%s %%</td>', esc_html( number_format_i18n( (float) $term['discount_pct'], 1 ) ) );
				printf( '<td>%d</td>', (int) $term['payment_terms_days'] );
				printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $term['valid_from'] ) ) );
				printf( '<td>%s</td>', esc_html( $term['valid_until'] ? mysql2date( 'd.m.Y', $term['valid_until'] ) : '—' ) );
				printf( '<td>%s</td>', esc_html( (string) $term['agreed_by'] ) );
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Keine Sonderkonditionen – es gilt die Standardstaffel.', 'lindenweg16' ) . '</p>';
		}

		$this->form( 'term' );
		printf( '<input type="hidden" name="contact_id" value="%d" />', $contact_id );
		echo '<p>';
		printf( '<input type="text" name="min_order_value" placeholder="%s" size="10" /> ', esc_attr__( 'ab Bestellwert', 'lindenweg16' ) );
		printf( '<input type="text" name="discount_pct" placeholder="%s" size="6" /> ', esc_attr__( 'Rabatt %', 'lindenweg16' ) );
		printf( '<input type="text" name="payment_terms_days" placeholder="%s" size="6" /> ', esc_attr__( 'Zahlungsziel', 'lindenweg16' ) );
		printf( '<input type="date" name="valid_from" value="%s" /> ', esc_attr( gmdate( 'Y-m-d' ) ) );
		printf( '<input type="text" name="agreed_by" placeholder="%s" size="12" /> ', esc_attr__( 'vereinbart durch', 'lindenweg16' ) );
		printf( '<button class="button">%s</button>', esc_html__( 'Festschreiben', 'lindenweg16' ) );
		echo '</p></form>';

		// Kommissionsbestand.
		echo '<h3>' . esc_html__( 'Kommissionsbestand', 'lindenweg16' ) . '</h3>';
		$commission = ContactRepository::commission_stock( $contact_id );
		$articles   = ArticleRepository::all_by_id();

		if ( $commission ) {
			$this->thead( array( __( 'Artikel', 'lindenweg16' ), __( 'Farbe', 'lindenweg16' ), __( 'Menge', 'lindenweg16' ), __( 'Alter', 'lindenweg16' ) ) );
			foreach ( $commission as $row ) {
				echo '<tr>';
				printf(
					'<td>%s</td>',
					esc_html( isset( $articles[ (int) $row['article_id'] ] ) ? $articles[ (int) $row['article_id'] ]['sku'] : '' )
				);
				printf( '<td>%s</td>', esc_html( ColorRegistry::label( $row['color'] ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $row['qty'], 0 ) ) );
				printf(
					'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
					esc_attr( $row['age_days'] > 180 ? 'rot' : ( $row['age_days'] > 90 ? 'gelb' : 'gruen' ) ),
					esc_html( sprintf( /* translators: %d: Tage. */ __( '%d Tage', 'lindenweg16' ), (int) $row['age_days'] ) )
				);
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'Ware, die seit über sechs Monaten liegt, ist ein Signal – entweder falscher Artikel oder falscher Händler.', 'lindenweg16' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Keine Kommissionsware.', 'lindenweg16' ) . '</p>';
		}

		$this->form( 'commission' );
		printf( '<input type="hidden" name="contact_id" value="%d" />', $contact_id );
		echo '<p><select name="movement_type">';
		foreach ( array(
			'kommission_raus'        => __( 'ausliefern', 'lindenweg16' ),
			'kommission_abgerechnet' => __( 'abrechnen', 'lindenweg16' ),
			'kommission_retour'      => __( 'zurücknehmen', 'lindenweg16' ),
		) as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select> <select name="article_id">';
		foreach ( ArticleRepository::selectable( true ) as $article ) {
			printf( '<option value="%d">%s</option>', (int) $article['id'], esc_html( $article['sku'] ) );
		}
		echo '</select> <select name="color">';
		foreach ( ColorRegistry::all() as $slug => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $label ) );
		}
		echo '</select> ';
		printf( '<input type="text" name="qty" placeholder="%s" size="6" /> ', esc_attr__( 'Menge', 'lindenweg16' ) );
		printf( '<button class="button">%s</button></p></form>', esc_html__( 'Buchen', 'lindenweg16' ) );

		// Aktivitäten.
		echo '<h3>' . esc_html__( 'Aktivitäten', 'lindenweg16' ) . '</h3>';
		$activities = ContactRepository::activities( $contact_id );
		$labels     = ContactRepository::activity_labels();

		if ( $activities ) {
			$this->thead(
				array(
					__( 'Datum', 'lindenweg16' ),
					__( 'Art', 'lindenweg16' ),
					__( 'Notiz', 'lindenweg16' ),
					__( 'Nächster Schritt', 'lindenweg16' ),
					__( 'Wiedervorlage', 'lindenweg16' ),
				)
			);
			foreach ( $activities as $activity ) {
				echo '<tr>';
				printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $activity['activity_date'] ) ) );
				printf( '<td>%s</td>', esc_html( isset( $labels[ $activity['activity_type'] ] ) ? $labels[ $activity['activity_type'] ] : $activity['activity_type'] ) );
				printf( '<td>%s</td>', esc_html( (string) $activity['summary'] ) );
				printf( '<td>%s</td>', esc_html( (string) $activity['next_step'] ) );
				printf( '<td>%s</td>', esc_html( $activity['follow_up_on'] ? mysql2date( 'd.m.Y', $activity['follow_up_on'] ) : '' ) );
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$this->form( 'activity' );
		printf( '<input type="hidden" name="contact_id" value="%d" />', $contact_id );
		echo '<p>';
		printf( '<input type="date" name="activity_date" value="%s" /> ', esc_attr( gmdate( 'Y-m-d' ) ) );
		echo '<select name="activity_type">';
		foreach ( $labels as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select> ';
		printf( '<input type="text" name="summary" placeholder="%s" size="30" /> ', esc_attr__( 'Gesprächsnotiz', 'lindenweg16' ) );
		printf( '<input type="text" name="next_step" placeholder="%s" size="22" /> ', esc_attr__( 'nächster Schritt', 'lindenweg16' ) );
		printf( '<input type="date" name="follow_up_on" title="%s" /> ', esc_attr__( 'Wiedervorlage (Pflicht)', 'lindenweg16' ) );
		printf( '<button class="button button-primary">%s</button>', esc_html__( 'Erfassen', 'lindenweg16' ) );
		echo '</p></form>';
		echo '<p class="description">' . esc_html__( 'Ohne Wiedervorlage wird die Aktivität abgelehnt – ein Gespräch ohne nächsten Termin versandet.', 'lindenweg16' ) . '</p>';

		// Nachbestell-Link.
		echo '<h3>' . esc_html__( 'Nachbestell-Link', 'lindenweg16' ) . '</h3>';

		$unlinked = ReorderPage::unlinked_products();
		if ( $unlinked ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: Anzahl Produkte. */
						_n(
							'%d Shop-Produkt hat keinen Betriebsartikel und fehlt dem Händler deshalb auf der Nachbestellseite:',
							'%d Shop-Produkte haben keinen Betriebsartikel und fehlen dem Händler deshalb auf der Nachbestellseite:',
							count( $unlinked ),
							'lindenweg16'
						),
						count( $unlinked )
					)
				),
				esc_html( implode( ', ', array_slice( $unlinked, 0, 8 ) ) )
			);
		}
		$tokens = ReorderToken::for_contact( $contact_id );
		$active = array_filter(
			$tokens,
			static function ( $token ) {
				return ! $token['revoked_at'];
			}
		);

		// Frisch erzeugter Link: einmalig anzeigen und sofort verwerfen.
		$fresh = get_transient( 'lw16_reorder_link_' . get_current_user_id() );
		if ( $fresh ) {
			delete_transient( 'lw16_reorder_link_' . get_current_user_id() );
			$contact = ContactRepository::find( $contact_id );

			printf(
				'<div class="notice notice-success inline"><p><strong>%s</strong></p>'
				. '<div class="lw16-dealercard">'
				. '<div class="lw16-dealercard__qr">%s</div>'
				. '<div class="lw16-dealercard__body">'
				. '<div class="lw16-dealercard__name">%s</div>'
				. '<div class="lw16-dealercard__hint">%s</div>'
				. '<code class="lw16-dealercard__url">%s</code>'
				. '</div></div>'
				. '<p><button type="button" class="button" onclick="window.print()">%s</button> %s</p></div>',
				esc_html__( 'Neuer Link – jetzt weitergeben:', 'lindenweg16' ),
				DeviceLabel::qr( $fresh, 150, 'M' ),
				esc_html( $contact ? $contact['name'] : '' ),
				esc_html__( 'Scannen und nachbestellen', 'lindenweg16' ),
				esc_html( DeviceLabel::readable( $fresh ) ),
				esc_html__( 'Karte drucken', 'lindenweg16' ),
				esc_html__( 'Er wird nicht erneut angezeigt. Gespeichert ist nur sein Hash.', 'lindenweg16' )
			);
		}

		if ( $active ) {
			$token = reset( $active );
			printf(
				'<p>%s %s · %s</p>',
				esc_html__( 'Aktiver Link vorhanden, gültig bis', 'lindenweg16' ),
				esc_html( mysql2date( 'd.m.Y', $token['expires_at'] ) ),
				esc_html( sprintf( /* translators: %d: Aufrufe. */ __( '%d Aufrufe', 'lindenweg16' ), (int) $token['use_count'] ) )
			);
			echo '<p class="description">' . esc_html__( 'Die URL wird aus Sicherheitsgründen nur bei der Erzeugung angezeigt – gespeichert ist nur ihr Hash. Für einen neuen Link den alten ersetzen.', 'lindenweg16' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Kein aktiver Link.', 'lindenweg16' ) . '</p>';
		}

		$this->form( 'token' );
		printf( '<input type="hidden" name="contact_id" value="%d" />', $contact_id );
		printf( '<p><button class="button button-primary">%s</button> ', esc_html__( 'Neuen Link erzeugen', 'lindenweg16' ) );
		echo '</p></form>';

		if ( $active ) {
			$this->form( 'token' );
			printf( '<input type="hidden" name="contact_id" value="%d" />', $contact_id );
			echo '<input type="hidden" name="revoke" value="1" />';
			printf( '<p><button class="button">%s</button></p></form>', esc_html__( 'Link widerrufen', 'lindenweg16' ) );
		}
	}

	/**
	 * Beschriftung einer Kennzahl-Ampel.
	 *
	 * „neutral" heißt: kein Ziel hinterlegt oder kein Wert im Zeitraum.
	 * Es als „beobachten" auszugeben wäre eine Wertung, die niemand
	 * getroffen hat.
	 *
	 * @param string $level Stufe.
	 * @return string
	 */
	public static function kpi_status_label( $level ) {
		$labels = array(
			'gruen'   => __( 'erreicht', 'lindenweg16' ),
			'rot'     => __( 'Alarm', 'lindenweg16' ),
			'gelb'    => __( 'beobachten', 'lindenweg16' ),
			'neutral' => __( 'kein Ziel', 'lindenweg16' ),
		);

		return isset( $labels[ $level ] ) ? $labels[ $level ] : (string) $level;
	}

	/* ====================================================================
	 * Berichte
	 * ================================================================== */

	/**
	 * Berichtsseite.
	 */
	public function reports() {
		$this->require_cap( 'lw16_view_reports' );

		// phpcs:disable WordPress.Security.NonceVerification
		$von = isset( $_GET['von'] ) ? sanitize_text_field( wp_unslash( $_GET['von'] ) ) : gmdate( 'Y-m-01' );
		$bis = isset( $_GET['bis'] ) ? sanitize_text_field( wp_unslash( $_GET['bis'] ) ) : gmdate( 'Y-m-d' );
		// phpcs:enable WordPress.Security.NonceVerification

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Berichte', 'lindenweg16' ) . '</h1>';
		$this->notice();

		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="lw16-betrieb-berichte" />';

		// Raster, Kennzahl und Quartal stehen in der Adresse. Ohne diese
		// Felder fiele die Ansicht bei jedem Datumswechsel zurueck.
		foreach ( array( 'raster', 'kennzahl', 'quartal' ) as $merken ) {
			// phpcs:ignore WordPress.Security.NonceVerification
			if ( isset( $_GET[ $merken ] ) ) {
				printf(
					'<input type="hidden" name="%s" value="%s" />',
					esc_attr( $merken ),
					esc_attr( sanitize_text_field( wp_unslash( $_GET[ $merken ] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
				);
			}
		}

		printf( '<input type="date" name="von" value="%s" /> ', esc_attr( $von ) );
		printf( '<input type="date" name="bis" value="%s" /> ', esc_attr( $bis ) );
		printf( '<button class="button">%s</button> ', esc_html__( 'Auswerten', 'lindenweg16' ) );
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=lw16_betrieb_export&what=kpi&von=' . rawurlencode( $von ) . '&bis=' . rawurlencode( $bis ) ),
					'lw16_betrieb_export'
				)
			),
			esc_html__( 'CSV-Export', 'lindenweg16' )
		);
		echo '</form>';

		$this->render_trend( $von, $bis );

		echo '<h2>' . esc_html__( 'Kennzahlen im Zeitraum', 'lindenweg16' ) . '</h2>';

		$metrics     = Reporting::metrics( $von, $bis );
		$definitions = self::visible_kpis( Kpi::definitions() );
		$alle_ziele  = KpiGoalRepository::all();
		$zeitraum    = array( 'von' => $von, 'bis' => $bis );

		// Ziele werden hier eingetragen, nicht in einer zweiten Tabelle
		// weiter unten. Zwei Stellen fuer dieselbe Zahl heisst frueher oder
		// spaeter zwei verschiedene Zahlen.
		$darf_ziele = Roles::current_user_can_betrieb( 'lw16_manage_settings' );
		$quartal    = $this->goal_quarter( $bis );
		$gespeichert = $darf_ziele ? KpiGoalRepository::for_quarter( $quartal['key'] ) : array();

		if ( $darf_ziele ) {
			$this->goal_intro( $quartal );
			$this->form( 'goals' );
			printf( '<input type="hidden" name="quartal" value="%s" />', esc_attr( $quartal['key'] ) );
			printf( '<input type="hidden" name="von" value="%s" />', esc_attr( $von ) );
			printf( '<input type="hidden" name="bis" value="%s" />', esc_attr( $bis ) );

			// phpcs:disable WordPress.Security.NonceVerification
			foreach ( array( 'raster', 'kennzahl' ) as $merken ) {
				if ( isset( $_GET[ $merken ] ) ) {
					printf(
						'<input type="hidden" name="%s" value="%s" />',
						esc_attr( $merken ),
						esc_attr( sanitize_text_field( wp_unslash( $_GET[ $merken ] ) ) )
					);
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification
		}

		$kopf = array(
			__( 'Kennzahl', 'lindenweg16' ),
			__( 'Wert', 'lindenweg16' ),
			__( 'Ziel', 'lindenweg16' ),
			__( 'Alarm bei', 'lindenweg16' ),
			__( 'Lage', 'lindenweg16' ),
			__( 'Status', 'lindenweg16' ),
		);

		$this->thead( $kopf );

		foreach ( $definitions as $key => $definition ) {
			$value      = isset( $metrics[ $key ] ) ? $metrics[ $key ] : null;
			$grenzen    = Reporting::limits( $key, $zeitraum, isset( $alle_ziele[ $key ] ) ? $alle_ziele[ $key ] : array() );
			$evaluation = Kpi::evaluate_against( $key, $value, $grenzen['target'], $grenzen['alarm'] );

			// Ab hier zaehlen die abgeleiteten Grenzen, nicht die aus der
			// Definition: Ein Quartalsziel von 30.000 Euro ist im August ein
			// anderes als im ganzen Quartal.
			$definition['target'] = $grenzen['target'];
			$definition['alarm']  = $grenzen['alarm'];

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $definition['label'] ) );
			printf( '<td><strong>%s</strong></td>', esc_html( Kpi::format( $key, $value ) ) );

			foreach ( array( 'target', 'alarm' ) as $spalte ) {
				if ( $darf_ziele ) {
					$this->goal_cell( $key, $spalte, $gespeichert, $definition[ $spalte ] );
				} else {
					printf( '<td>%s</td>', esc_html( null === $definition[ $spalte ] ? '—' : Kpi::format( $key, $definition[ $spalte ] ) ) );
				}
			}

			// Dieselben Zahlen als Bild: Die Spalten links sagen wie viel,
			// der Balken sagt wo dazwischen. Zahlen ohne Ziel bekommen
			// keinen - es gaebe nichts, wogegen sie laegen.
			$marken = array();

			if ( null !== $definition['target'] ) {
				/* translators: %s: Zielwert. */
				$marken[] = sprintf( __( 'Ziel %s', 'lindenweg16' ), Kpi::format( $key, $definition['target'] ) );
			}

			if ( null !== $definition['alarm'] ) {
				/* translators: %s: Alarmgrenze. */
				$marken[] = sprintf( __( 'Alarm %s', 'lindenweg16' ), Kpi::format( $key, $definition['alarm'] ) );
			}

			printf(
				'<td class="lw16-cell--meter">%s</td>',
				KpiMeter::bar( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- erzeugte Auszeichnung.
					array(
						'level'  => $evaluation['level'],
						'value'  => $value,
						'target' => $definition['target'],
						'alarm'  => $definition['alarm'],
						'aria'   => $definition['label'] . ': ' . Kpi::format( $key, $value ) . '. ' . implode( ', ', $marken ),
					)
				)
			);

			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
				esc_attr( $evaluation['level'] ),
				esc_html( self::kpi_status_label( $evaluation['level'] ) )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $darf_ziele ) {
			echo '<p>';
			printf(
				'<button class="button button-primary">%s</button> ',
				esc_html(
					sprintf(
						/* translators: %s: Quartal. */
						__( 'Ziele für %s speichern', 'lindenweg16' ),
						$quartal['label']
					)
				)
			);
			echo '</p></form>';
		}

		echo KpiMeter::legend(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- feste Auszeichnung.

		if ( Roles::current_user_can_betrieb( 'lw16_view_costs' ) ) {
			$this->analyse_export( $von, $bis );
		}

		echo '<h2>' . esc_html__( 'Monatsabschluss', 'lindenweg16' ) . '</h2>';
		$this->freeze_form();

		echo '</div>';
	}

	/**
	 * Kennzahlen, die dieser Nutzer sehen darf.
	 *
	 * Geldgrössen fallen ohne das Kostenrecht heraus. Die Ausschussquote
	 * fällt nicht heraus – sie sagt etwas über die Arbeit, nicht über den
	 * Ertrag.
	 *
	 * @param array $definitions Alle Kennzahlen.
	 * @return array
	 */
	public static function visible_kpis( array $definitions ) {
		if ( Roles::current_user_can_betrieb( 'lw16_view_costs' ) ) {
			return $definitions;
		}

		return array_filter(
			$definitions,
			static function ( $definition ) {
				return '€' !== ( isset( $definition['unit'] ) ? $definition['unit'] : '' );
			}
		);
	}

	/**
	 * Knöpfe für den Auszug zur Analyse.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 */
	protected function analyse_export( $von, $bis ) {
		echo '<h2>' . esc_html__( 'Auszug zur Analyse', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Lädt den Betriebszustand als eine Textdatei herunter: Artikel mit Kalkulation und Bestand, Stücklisten, Kennzahlen, Druckjournal, Arbeitszeit, offene Aufträge und die Befunde der Systemprüfung. Die Datei erklärt sich selbst – sie nennt ihre Einheiten und am Ende die bekannten Lücken – und lässt sich so einer Auswertung übergeben, ohne dass jemand die Zahlen erst deuten muss.', 'lindenweg16' ) . '</p>';
		echo '<p>' . esc_html__( 'Namen von Geschäftskunden bleiben draußen, solange du sie nicht ausdrücklich mitgibst; die Kunden sind dann durchnummeriert. Was du weitergibst, verlässt den Betrieb – prüf die Datei vorher, sie ist lesbarer Text.', 'lindenweg16' ) . '</p>';

		foreach ( array(
			array( 0, __( 'Auszug herunterladen (ohne Kundennamen)', 'lindenweg16' ), 'button button-primary' ),
			array( 1, __( 'Mit Kundennamen', 'lindenweg16' ), 'button' ),
		) as $variant ) {
			printf(
				'<a class="%1$s" href="%2$s" style="margin-right:8px">%3$s</a>',
				esc_attr( $variant[2] ),
				esc_url(
					wp_nonce_url(
						admin_url(
							'admin-post.php?action=lw16_betrieb_analyse&namen=' . (int) $variant[0]
							. '&von=' . rawurlencode( $von ) . '&bis=' . rawurlencode( $bis )
						),
						'lw16_betrieb_export'
					)
				),
				esc_html( $variant[1] )
			);
		}

	}

	/**
	 * Formular für den Monatsabschluss.
	 */
	protected function freeze_form() {
		echo '<p>' . esc_html__( 'Friert die automatischen Kennzahlen des gewählten Monats ein. Eingefrorene Werte ändern sich danach nicht mehr, auch wenn Daten nachgetragen werden.', 'lindenweg16' ) . '</p>';

		$this->form( 'freeze' );
		echo '<p>';
		printf( '<input type="number" name="year" value="%s" style="width:90px" /> ', esc_attr( gmdate( 'Y' ) ) );
		printf( '<input type="number" name="month" min="1" max="12" value="%s" style="width:70px" /> ', esc_attr( gmdate( 'n' ) ) );
		printf( '<button class="button button-primary">%s</button>', esc_html__( 'Abschließen', 'lindenweg16' ) );
		echo '</p></form>';
	}

	/* ====================================================================
	 * Verlauf
	 * ================================================================== */

	/**
	 * Verlaufsgraph mit umschaltbarem Zeitraster.
	 *
	 * Der Graph endet immer am Bis-Datum des Filters. Das Raster bestimmt
	 * nur, wie fein die Zeit davor geteilt wird – so bleibt „was war letzte
	 * Woche" und „was war letztes Jahr" dieselbe Frage in zwei Auflösungen.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 */
	protected function render_trend( $von, $bis ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$raster  = Period::clean( isset( $_GET['raster'] ) ? sanitize_key( wp_unslash( $_GET['raster'] ) ) : 'monat' );
		$gewaehlt = isset( $_GET['kennzahl'] ) ? sanitize_key( wp_unslash( $_GET['kennzahl'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		$sichtbar = self::visible_kpis( Kpi::definitions() );

		if ( ! $sichtbar ) {
			return;
		}

		if ( ! isset( $sichtbar[ $gewaehlt ] ) ) {
			$schluessel = array_keys( $sichtbar );
			$gewaehlt   = $schluessel[0];
		}

		$definition = $sichtbar[ $gewaehlt ];
		$buckets    = Period::series( $raster, $bis, Period::default_count( $raster ) );
		$reihen     = Reporting::series( $buckets );
		$ziele      = KpiGoalRepository::for_metric( $gewaehlt );
		$kind       = KpiGoals::kind( $gewaehlt );

		$punkte = array();

		foreach ( $buckets as $bucket ) {
			$wert    = isset( $reihen[ $bucket['key'] ][ $gewaehlt ] ) ? $reihen[ $bucket['key'] ][ $gewaehlt ] : null;
			$grenzen = Reporting::limits( $gewaehlt, $bucket, $ziele );

			$punkte[] = array(
				'label'  => $bucket['label'],
				'value'  => $wert,
				'target' => $grenzen['target'],
				'alarm'  => $grenzen['alarm'],
				'level'  => Kpi::evaluate_against( $gewaehlt, $wert, $grenzen['target'], $grenzen['alarm'] ),
				'text'   => self::point_text( $gewaehlt, $bucket['label'], $wert, $grenzen ),
			);
		}

		echo '<h2>' . esc_html__( 'Verlauf', 'lindenweg16' ) . '</h2>';
		echo '<div class="lw16-verlauf">';

		$this->trend_controls( $von, $bis, $raster, $gewaehlt, $sichtbar );

		echo TrendChart::svg( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- erzeugte Auszeichnung.
			array(
				'points' => $punkte,
				'metric' => $gewaehlt,
				'title'  => $definition['label'],
				'id'     => 'lw16-verlauf-' . $gewaehlt,
			)
		);

		echo TrendChart::legend(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- feste Auszeichnung.

		printf(
			'<p class="description">%s %s</p>',
			esc_html( KpiGoals::explain( $kind ) ),
			esc_html__( 'Ziel- und Alarmlinie liegen nur über den Zeiträumen, für die ein Quartalsziel eingetragen ist – für die übrigen wird keines erfunden.', 'lindenweg16' )
		);

		$this->trend_table( $gewaehlt, $definition, $punkte );

		echo '</div>';
	}

	/**
	 * Bedienelemente über dem Graphen: Raster und Kennzahl.
	 *
	 * @param string $von      Von.
	 * @param string $bis      Bis.
	 * @param string $raster   Gewähltes Raster.
	 * @param string $gewaehlt Gewählte Kennzahl.
	 * @param array  $sichtbar Sichtbare Kennzahlen.
	 */
	protected function trend_controls( $von, $bis, $raster, $gewaehlt, array $sichtbar ) {
		echo '<div class="lw16-verlauf__steuerung">';
		echo '<span class="lw16-verlauf__raster">';

		foreach ( Period::labels() as $key => $label ) {
			$url = add_query_arg(
				array(
					'page'     => 'lw16-betrieb-berichte',
					'von'      => $von,
					'bis'      => $bis,
					'raster'   => $key,
					'kennzahl' => $gewaehlt,
				),
				admin_url( 'admin.php' )
			);

			printf(
				'<a class="button%1$s" href="%2$s"%3$s>%4$s</a>',
				$key === $raster ? ' button-primary' : '',
				esc_url( $url ),
				$key === $raster ? ' aria-current="true"' : '',
				esc_html( $label )
			);
		}

		echo '</span>';

		echo '<form method="get" class="lw16-verlauf__auswahl">';
		echo '<input type="hidden" name="page" value="lw16-betrieb-berichte" />';
		printf( '<input type="hidden" name="von" value="%s" />', esc_attr( $von ) );
		printf( '<input type="hidden" name="bis" value="%s" />', esc_attr( $bis ) );
		printf( '<input type="hidden" name="raster" value="%s" />', esc_attr( $raster ) );

		printf( '<label for="lw16-kennzahl" class="screen-reader-text">%s</label>', esc_html__( 'Kennzahl', 'lindenweg16' ) );
		echo '<select name="kennzahl" id="lw16-kennzahl">';

		foreach ( $sichtbar as $key => $definition ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $key, $gewaehlt, false ),
				esc_html( $definition['label'] )
			);
		}

		echo '</select> ';
		printf( '<button class="button">%s</button>', esc_html__( 'Anzeigen', 'lindenweg16' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Die Zahlen zum Graphen – zugeklappt, aber vorhanden.
	 *
	 * Ein Bild ohne die Zahlen daneben lässt sich weder nachrechnen noch
	 * vorlesen. Der Graph ist die schnelle Antwort, die Tabelle die genaue.
	 *
	 * @param string $metric     Kennzahl.
	 * @param array  $definition Definition.
	 * @param array  $punkte     Punkte.
	 */
	protected function trend_table( $metric, array $definition, array $punkte ) {
		echo '<details class="lw16-verlauf__zahlen"><summary>' . esc_html__( 'Zahlen zum Verlauf', 'lindenweg16' ) . '</summary>';

		$this->thead(
			array(
				__( 'Zeitraum', 'lindenweg16' ),
				$definition['label'],
				__( 'Ziel', 'lindenweg16' ),
				__( 'Alarm bei', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
			)
		);

		foreach ( $punkte as $punkt ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $punkt['label'] ) );
			printf( '<td><strong>%s</strong></td>', esc_html( Kpi::format( $metric, $punkt['value'] ) ) );
			printf( '<td>%s</td>', esc_html( null === $punkt['target'] ? '—' : Kpi::format( $metric, $punkt['target'] ) ) );
			printf( '<td>%s</td>', esc_html( null === $punkt['alarm'] ? '—' : Kpi::format( $metric, $punkt['alarm'] ) ) );
			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
				esc_attr( $punkt['level']['level'] ),
				esc_html( self::kpi_status_label( $punkt['level']['level'] ) )
			);
			echo '</tr>';
		}

		echo '</tbody></table></details>';
	}

	/**
	 * Kurzinfo eines Punktes.
	 *
	 * @param string     $metric  Kennzahl.
	 * @param string     $label   Zeitraum.
	 * @param float|null $wert    Wert.
	 * @param array      $grenzen Ziel und Alarm.
	 * @return string
	 */
	protected static function point_text( $metric, $label, $wert, array $grenzen ) {
		$text = $label . ': ' . Kpi::format( $metric, $wert );

		if ( null !== $grenzen['target'] ) {
			/* translators: %s: Zielwert. */
			$text .= ' — ' . sprintf( __( 'Ziel %s', 'lindenweg16' ), Kpi::format( $metric, $grenzen['target'] ) );
		}

		if ( null !== $grenzen['alarm'] ) {
			/* translators: %s: Alarmgrenze. */
			$text .= ', ' . sprintf( __( 'Alarm %s', 'lindenweg16' ), Kpi::format( $metric, $grenzen['alarm'] ) );
		}

		return $text;
	}

	/* ====================================================================
	 * Quartalsziele
	 * ================================================================== */

	/**
	 * Das Quartal, dessen Ziele bearbeitet werden.
	 *
	 * @param string $bis Bis-Datum des Filters.
	 * @return array
	 */
	protected function goal_quarter( $bis ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$key     = isset( $_GET['quartal'] ) ? sanitize_text_field( wp_unslash( $_GET['quartal'] ) ) : '';
		$quartal = Period::quarter_bounds( $key );

		return $quartal ? $quartal : Period::bucket( 'quartal', $bis );
	}

	/**
	 * Erklärung und Quartalswahl über der Tabelle.
	 *
	 * @param array $quartal Gewähltes Quartal.
	 */
	protected function goal_intro( array $quartal ) {
		printf(
			'<p>%s</p>',
			esc_html__( 'Ziel und Alarmgrenze trägst du hier ein, je Quartal – an einer Stelle, nicht zweimal. Beim Wechsel des Zeitrasters rechnet das System sie um: Summengrößen wie Umsatz und Bestellungen werden nach Tagen verteilt, Verhältnisgrößen wie Quoten und Durchschnitte gelten unverändert. Ein früheres Quartal behält seine Ziele, auch wenn du die Latte später höher legst.', 'lindenweg16' )
		);

		$this->quarter_picker( $quartal );
	}

	/**
	 * Eine Ziel- oder Alarmzelle: Eingabe für das Quartal, darunter der
	 * Wert, der daraus für den gezeigten Zeitraum folgt.
	 *
	 * Beides in einer Zelle, weil es dieselbe Zahl ist – einmal so, wie sie
	 * eingetragen wurde, und einmal so, wie sie hier gilt. Zwei getrennte
	 * Tabellen dafür führten dazu, dass man zweimal dasselbe eintippt.
	 *
	 * @param string     $key         Kennzahl.
	 * @param string     $spalte      target oder alarm.
	 * @param array      $gespeichert Ziele des Quartals.
	 * @param float|null $abgeleitet  Wert für den gezeigten Zeitraum.
	 */
	protected function goal_cell( $key, $spalte, array $gespeichert, $abgeleitet ) {
		$wert = isset( $gespeichert[ $key ][ $spalte ] ) ? $gespeichert[ $key ][ $spalte ] : null;

		$definitions = Kpi::definitions();
		$vorgabe     = isset( $definitions[ $key ][ $spalte ] ) ? $definitions[ $key ][ $spalte ] : null;

		echo '<td class="lw16-ziel">';
		printf(
			'<input type="text" inputmode="decimal" name="ziel[%1$s][%2$s]" value="%3$s" placeholder="%4$s" class="lw16-ziel__feld" /> %5$s',
			esc_attr( $key ),
			esc_attr( $spalte ),
			esc_attr( null === $wert ? '' : self::goal_input( $wert ) ),
			esc_attr( null === $vorgabe ? __( 'kein Ziel', 'lindenweg16' ) : self::goal_input( $vorgabe ) ),
			esc_html( isset( $definitions[ $key ]['unit'] ) ? $definitions[ $key ]['unit'] : '' )
		);

		// Der abgeleitete Wert steht nur da, wo er sich von dem unterscheidet,
		// was im Feld steht – bei einer Verhältnisgröße ist er derselbe, und
		// die Zeile wiederholte sich sonst nur selbst. Verglichen wird gegen
		// den wirksamen Wert: den eingetragenen, sonst die Vorgabe, die als
		// Platzhalter im Feld steht.
		$wirksam = null === $wert ? $vorgabe : $wert;

		if ( null !== $abgeleitet && ( null === $wirksam || abs( (float) $abgeleitet - (float) $wirksam ) > 0.005 ) ) {
			printf(
				'<span class="lw16-ziel__abgeleitet">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: Wert im Zeitraum. */
						__( 'im Zeitraum: %s', 'lindenweg16' ),
						Kpi::format( $key, $abgeleitet )
					)
				)
			);
		}

		echo '</td>';
	}

	/**
	 * Auswahl des Quartals.
	 *
	 * @param array $aktuell Aktuelles Quartal.
	 */
	protected function quarter_picker( array $aktuell ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$behalten = array();

		foreach ( array( 'von', 'bis', 'raster', 'kennzahl' ) as $merken ) {
			if ( isset( $_GET[ $merken ] ) ) {
				$behalten[ $merken ] = sanitize_text_field( wp_unslash( $_GET[ $merken ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification

		echo '<p class="lw16-verlauf__quartale">';
		printf( '<span class="lw16-quartal-label">%s</span> ', esc_html__( 'Ziele für:', 'lindenweg16' ) );

		// Vier Quartale zurück und eines voraus: Wer plant, trägt das
		// nächste Quartal ein, bevor es beginnt.
		$start = Period::bucket( 'quartal', $aktuell['von'] );

		for ( $i = -4; $i <= 1; $i++ ) {
			$q = $start;

			for ( $j = 0; $j < abs( $i ); $j++ ) {
				$q = $i < 0
					? Period::previous( 'quartal', $q )
					: Period::bucket( 'quartal', gmdate( 'Y-m-d', strtotime( $q['bis'] . ' 12:00:00 UTC' ) + DAY_IN_SECONDS ) );
			}

			printf(
				'<a class="button%1$s" href="%2$s">%3$s</a> ',
				$q['key'] === $aktuell['key'] ? ' button-primary' : '',
				esc_url(
					add_query_arg(
						array_merge( $behalten, array( 'page' => 'lw16-betrieb-berichte', 'quartal' => $q['key'] ) ),
						admin_url( 'admin.php' )
					)
				),
				esc_html( $q['label'] )
			);
		}

		echo '</p>';
	}

	/**
	 * Ein Zielwert als Eingabewert.
	 *
	 * Ohne Tausenderpunkte: Was im Feld steht, soll unverändert wieder
	 * eingelesen werden können.
	 *
	 * @param float $wert Wert.
	 * @return string
	 */
	protected static function goal_input( $wert ) {
		return rtrim( rtrim( number_format( (float) $wert, 2, ',', '' ), '0' ), ',' );
	}

	/**
	 * Quartalsziele speichern.
	 */
	public function handle_goals() {
		$this->require_cap( 'lw16_manage_settings' );
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$quartal = isset( $_POST['quartal'] ) ? sanitize_text_field( wp_unslash( $_POST['quartal'] ) ) : '';
		$roh     = isset( $_POST['ziel'] ) && is_array( $_POST['ziel'] ) ? wp_unslash( $_POST['ziel'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$eingaben = array();

		foreach ( (array) $roh as $metric => $werte ) {
			if ( ! is_array( $werte ) ) {
				continue;
			}

			$eingaben[ sanitize_key( $metric ) ] = array(
				'target' => isset( $werte['target'] ) ? sanitize_text_field( $werte['target'] ) : '',
				'alarm'  => isset( $werte['alarm'] ) ? sanitize_text_field( $werte['alarm'] ) : '',
			);
		}

		$result = KpiGoalRepository::save(
			$quartal,
			$eingaben,
			array_keys( self::visible_kpis( Kpi::definitions() ) )
		);

		// Zurueck in dieselbe Ansicht: Zeitraum, Raster und Kennzahl kommen
		// aus dem Formular mit, sonst steht man nach dem Speichern wieder
		// beim Vorgabemonat und sucht seinen Graphen.
		$zurueck = array( 'quartal' => $quartal );

		foreach ( array( 'von', 'bis', 'raster', 'kennzahl' ) as $merken ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
			if ( ! empty( $_POST[ $merken ] ) ) {
				$zurueck[ $merken ] = sanitize_text_field( wp_unslash( $_POST[ $merken ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		$this->redirect(
			'lw16-betrieb-berichte',
			$result['message'],
			$result['success'] ? 'success' : 'error',
			$zurueck
		);
	}
}
