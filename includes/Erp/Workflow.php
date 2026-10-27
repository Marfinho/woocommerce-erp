<?php
/**
 * Der Auftragsablauf der Werkstatt: Bedarf → Plan → Platte → Ist → Bestand.
 *
 * Der Dienst bündelt, was bisher auf vielen Seiten verteilt war, ohne
 * eigene Geschäftsregeln zu erfinden:
 *
 * - Bedarf: DemandPlanner (Bestellungen, Merklisten, Sollbestand) plus
 *   Hochrechnung aus dem Verkaufsverlauf.
 * - Dateien: PlateService ordnet die Artikel auf dem Druckbett an und
 *   erzeugt die Projekt-3MF; geslict wird vom Mitarbeiter.
 * - Etikett: das vorhandene Etikettensystem (LabelPdf, Druck-Warteschlange).
 * - Ist-Meldung: das Druckjournal (PrintJobRepository) – es bucht den
 *   Lagerzugang und verbaut Kleinteile; dazu kommen Rüst- und Druckzeit
 *   als Zeiteinträge.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\Pages\BambuDruck;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;
use Lindenweg16\Modules\Betrieb\DemandPlanner;
use Lindenweg16\Modules\Betrieb\FilamentRepository;
use Lindenweg16\Modules\Betrieb\LabelPdf;
use Lindenweg16\Modules\Betrieb\LabelSheet;
use Lindenweg16\Modules\Betrieb\MachineRate;
use Lindenweg16\Modules\Betrieb\Nesting\PlateJobRepository;
use Lindenweg16\Modules\Betrieb\Nesting\PlateService;
use Lindenweg16\Modules\Betrieb\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrintQueueService;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrinterRepository as QueuePrinterRepository;
use Lindenweg16\Modules\Betrieb\PrinterRepository;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\TimeRepository;

/**
 * Dienste des Workflows.
 */
class Workflow {

	/**
	 * Option mit den Einstellungen.
	 */
	const OPTION = 'lw16_erp_workflow';

	/**
	 * Mögliche Inhalte des Etiketts.
	 *
	 * @return array Kennung => Beschriftung.
	 */
	public static function label_field_options() {
		return array(
			'platte'   => __( 'Plattennummer', 'lindenweg16' ),
			'artikel'  => __( 'Artikel und Menge', 'lindenweg16' ),
			'material' => __( 'Material (Farbe)', 'lindenweg16' ),
			'drucker'  => __( 'Drucker und Dauer', 'lindenweg16' ),
			'auftrag'  => __( 'Plannummer', 'lindenweg16' ),
		);
	}

	/**
	 * Fehlerursachen für Ausschuss, wie sie das Druckjournal kennt.
	 *
	 * @return array {id, label}
	 */
	public static function failure_reasons() {
		$labels = PrintJobRepository::failure_labels();
		$out    = array();

		foreach ( PrintJobRepository::FAILURE_REASONS as $reason ) {
			if ( 'keine' !== $reason ) {
				$out[] = array( 'id' => $reason, 'label' => isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason );
			}
		}

		return $out;
	}

	/**
	 * Vorgaben.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'label_roll'    => LabelSheet::DEFAULT_ROLL,
			'label_printer' => '',
			'label_copies'  => 1,
			'label_auto'    => 1,
			'label_fields'  => array_keys( self::label_field_options() ),
			'weeks'         => Forecast::WEEKS,
			'cover_weeks'   => Forecast::COVER_WEEKS,
			'hide_wpadmin'  => 0,
			'filament_lead_days'   => 10,
			'filament_safety_days' => 5,
			// Reihenfolge der Platten nach Tageszeit (siehe ShiftOrder).
			'shift_end'            => '17:00',
			'shift_lead_h'         => 2.0,
			'long_h'               => 4.0,
		);
	}

	/**
	 * Aktuelle Einstellungen.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );

		return self::sanitize_settings( array_merge( self::defaults(), is_array( $saved ) ? $saved : array() ) );
	}

	/**
	 * Eingaben bereinigen – unbekannte Werte fallen auf die Vorgabe zurück.
	 *
	 * @param array $in Eingabe.
	 * @return array
	 */
	public static function sanitize_settings( array $in ) {
		$def = self::defaults();

		$roll = isset( $in['label_roll'] ) ? sanitize_key( (string) $in['label_roll'] ) : $def['label_roll'];
		$out  = array(
			'label_roll'    => LabelSheet::has_roll( $roll ) ? $roll : $def['label_roll'],
			'label_printer' => isset( $in['label_printer'] ) ? sanitize_key( (string) $in['label_printer'] ) : '',
			'label_copies'  => min( 5, max( 1, (int) ( isset( $in['label_copies'] ) ? $in['label_copies'] : 1 ) ) ),
			'label_auto'    => ! empty( $in['label_auto'] ) ? 1 : 0,
			'label_fields'  => array_values( array_intersect( array_keys( self::label_field_options() ), (array) ( isset( $in['label_fields'] ) ? $in['label_fields'] : array() ) ) ),
			'weeks'         => min( 26, max( 1, (int) ( isset( $in['weeks'] ) ? $in['weeks'] : $def['weeks'] ) ) ),
			'cover_weeks'   => min( 12.0, max( 0.0, (float) ( isset( $in['cover_weeks'] ) ? $in['cover_weeks'] : $def['cover_weeks'] ) ) ),
			'hide_wpadmin'  => ! empty( $in['hide_wpadmin'] ) ? 1 : 0,
			'filament_lead_days'   => min( 120, max( 0, (int) ( isset( $in['filament_lead_days'] ) ? $in['filament_lead_days'] : $def['filament_lead_days'] ) ) ),
			'filament_safety_days' => min( 60, max( 0, (int) ( isset( $in['filament_safety_days'] ) ? $in['filament_safety_days'] : $def['filament_safety_days'] ) ) ),
			'shift_end'            => preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', isset( $in['shift_end'] ) ? (string) $in['shift_end'] : '' ) ? (string) $in['shift_end'] : $def['shift_end'],
			'shift_lead_h'         => min( 8.0, max( 0.0, (float) ( isset( $in['shift_lead_h'] ) ? $in['shift_lead_h'] : $def['shift_lead_h'] ) ) ),
			'long_h'               => min( 48.0, max( 0.5, (float) ( isset( $in['long_h'] ) ? $in['long_h'] : $def['long_h'] ) ) ),
		);

		// Ohne irgendeinen Inhalt wäre das Etikett leer.
		if ( ! $out['label_fields'] ) {
			$out['label_fields'] = array( 'platte' );
		}

		return $out;
	}

	/**
	 * Einstellungen speichern.
	 *
	 * @param array $in Eingabe.
	 * @return array Gespeicherte Einstellungen.
	 */
	public static function save_settings( array $in ) {
		$settings = self::sanitize_settings( array_merge( self::settings(), $in ) );

		update_option( self::OPTION, $settings, false );
		delete_transient( 'lw16_erp_sales_' . $settings['weeks'] );

		return $settings;
	}

	/**
	 * Adresse der mobilen Seite einer Platte – steht im QR-Code.
	 *
	 * @param int $id Platte.
	 * @return string
	 */
	public static function run_url( $id ) {
		return Shell::url() . 'p/' . (int) $id;
	}

	/* ====================================================================
	 * Bedarf
	 * ================================================================== */

	/**
	 * Bedarf als Karten: nur, was gedruckt werden muss.
	 *
	 * @return array
	 */
	public static function demand() {
		$settings = self::settings();
		$demands  = DemandPlanner::collect( true );

		$open = array();
		foreach ( $demands as $demand ) {
			if ( 'bestellung' === $demand['source'] ) {
				$open[ (int) $demand['article_id'] ] = ( isset( $open[ (int) $demand['article_id'] ] ) ? $open[ (int) $demand['article_id'] ] : 0.0 ) + (float) $demand['qty'];
			}
		}

		$flat = Forecast::demands( Forecast::sales_by_article( $settings['weeks'] ), $open, $settings['weeks'], $settings['cover_weeks'] );

		// Das Prognosemodell (Trend, Saison, Dienstgrad) ersetzt die einfache
		// Hochrechnung für Artikel mit genug Verlauf; für alle anderen gilt
		// weiter das Wochenmittel.
		if ( \Lindenweg16\Modules\Betrieb\ForecastService::settings()['use_model'] ) {
			$modelled = \Lindenweg16\Modules\Betrieb\ForecastService::modelled_articles();
			$flat     = array_values(
				array_filter(
					$flat,
					static function ( $row ) use ( $modelled ) {
						return ! isset( $modelled[ (int) $row['article_id'] ] );
					}
				)
			);
			$flat = array_merge( $flat, \Lindenweg16\Modules\Betrieb\ForecastService::model_demands( $open ) );
		}

		$demands = array_merge( $demands, $flat );

		$ctx    = DemandPlanner::context();
		$plan   = DemandPlanner::plan( $demands, $ctx );
		$labels = DemandPlanner::source_labels();
		$cards  = array();

		foreach ( DemandPlanner::actionable( $plan['articles'] ) as $row ) {
			if ( 'drucken' !== $row['action'] ) {
				continue;
			}

			$chips = array();
			foreach ( $row['sources'] as $source => $info ) {
				$count   = count( $info['labels'] );
				$chips[] = 'bestellung' === $source && $count
					/* translators: %d: Anzahl Bestellungen. */
					? sprintf( _n( '%d Bestellung', '%d Bestellungen', $count, 'lindenweg16' ), $count )
					: ( 'hochrechnung' === $source && $info['labels'] ? (string) $info['labels'][0] : $labels[ $source ] );
			}

			$revision = RevisionRepository::active_for_article( (int) $row['article_id'] );

			$cards[] = array(
				'article_id' => (int) $row['article_id'],
				'sku'        => $row['sku'],
				'name'       => $row['name'],
				'qty'        => (int) ceil( $row['net'] ),
				'stock'      => $row['stock'],
				'chips'      => array_values( array_unique( $chips ) ),
				'has_model'  => $revision && ! empty( $revision['model_file'] ) && 'modell' === $revision['model_kind'],
			);
		}

		return array(
			'cards'    => $cards,
			'assembly' => Assembly::cards( $plan, $ctx, $labels ),
			'warnings' => $plan['warnings'],
		);
	}

	/* ====================================================================
	 * Platten: Vorschau und Freigabe
	 * ================================================================== */

	/**
	 * Bambu-Drucker zur Auswahl.
	 *
	 * @return array
	 */
	public static function printers() {
		$out = array();

		foreach ( BambuPrinterRepository::all() as $printer ) {
			if ( ! (int) $printer->active ) {
				continue;
			}

			$out[] = array(
				'id'     => $printer->printer_id,
				'name'   => $printer->name,
				'online' => (bool) BambuPrinterRepository::is_online( $printer ),
			);
		}

		return $out;
	}

	/**
	 * Etikettendrucker der Druck-Warteschlange zur Auswahl.
	 *
	 * @return array
	 */
	public static function queue_printers() {
		$out = array();

		foreach ( QueuePrinterRepository::all() as $printer ) {
			$out[] = array(
				'id'     => $printer->printer_id,
				'name'   => $printer->name,
				'online' => (bool) QueuePrinterRepository::is_online( $printer ),
			);
		}

		return $out;
	}

	/**
	 * Aus ausgewählten Bedarfszeilen Platten anordnen (noch ohne Freigabe).
	 *
	 * Die Dateien entstehen sofort, bleiben aber „entworfen“: Wer die
	 * Vorschau verwirft, hat nichts angerichtet.
	 *
	 * @param array  $items       Liste {article_id, qty}.
	 * @param string $bambu_id    Bambu-Drucker.
	 * @return array {ok, plates, warnings, error}
	 */
	public static function preview( array $items, $bambu_id ) {
		$clean = array();

		foreach ( $items as $item ) {
			$article_id = isset( $item['article_id'] ) ? (int) $item['article_id'] : 0;
			$qty        = isset( $item['qty'] ) ? (int) $item['qty'] : 0;

			if ( $article_id && $qty > 0 ) {
				$clean[] = array( 'article_id' => $article_id, 'qty' => $qty );
			}
		}

		if ( ! $clean ) {
			return array( 'ok' => false, 'plates' => array(), 'warnings' => array(), 'error' => __( 'Bitte mindestens eine Position wählen.', 'lindenweg16' ) );
		}

		$result = PlateService::build_plates( $bambu_id, $clean );

		if ( empty( $result['ok'] ) ) {
			// Die Hinweise zu einzelnen Artikeln sind die eigentliche Ursache;
			// ohne sie bliebe nur „Keine Platte konnte angeordnet werden“.
			$why = trim( (string) $result['error'] . ' ' . ( (string) $result['error'] === implode( ' ', (array) $result['warnings'] ) ? '' : implode( ' ', (array) $result['warnings'] ) ) );

			return array( 'ok' => false, 'plates' => array(), 'warnings' => (array) $result['warnings'], 'error' => $why );
		}

		$plates   = array();
		$all      = array();
		$warnings = (array) $result['warnings'];

		foreach ( $result['plate_ids'] as $plate_id ) {
			$view = self::plate_preview( (int) $plate_id );
			if ( $view ) {
				$plates[] = $view;
				$all      = array_merge( $all, $view['items'] );
			}
		}

		// Reicht das Filament? Vor dem Freigeben sagen, nicht nach dem
		// Rüsten feststellen, dass die Rolle leer ist.
		$material = self::material_view( Material::check( $all ) );

		foreach ( $material as $row ) {
			if ( ! $row['ok'] ) {
				$warnings[] = sprintf(
					/* translators: 1: Farbe, 2: fehlende Menge in Gramm. */
					__( 'Material reicht nicht: %1$s – es fehlen %2$s g.', 'lindenweg16' ),
					$row['label'],
					number_format_i18n( $row['missing_kg'] * 1000, 0 )
				);
			}
		}

		return array( 'ok' => true, 'plates' => $plates, 'material' => $material, 'warnings' => $warnings, 'error' => '' );
	}

	/**
	 * Inhalt einer angeordneten Platte.
	 *
	 * @param int $plate_id Plattendatei.
	 * @return array|null
	 */
	protected static function plate_preview( $plate_id ) {
		$plate = PlateJobRepository::find( $plate_id );

		if ( ! $plate ) {
			return null;
		}

		$items   = self::plate_items( $plate );
		$printer = BambuPrinterRepository::find_by_printer_id( $plate->printer_id );

		return array(
			'plate_id'    => (int) $plate->id,
			'printer'     => $printer ? $printer->name : $plate->printer_id,
			'items'       => $items['items'],
			'est_minutes' => $items['est_minutes'],
			'layout'      => BambuDruck::layout( $plate ),
		);
	}

	/**
	 * Positionen und geschätzte Dauer aus einer angeordneten Platte.
	 *
	 * Die Dauer ist eine Schätzung aus Plattenzeit und Teilen je Platte der
	 * Revision – ein Richtwert fürs Etikett und die Planung, keine Messung.
	 *
	 * @param object $plate Plattendatei.
	 * @return array {items, est_minutes}
	 */
	protected static function plate_items( $plate ) {
		$placements = json_decode( (string) $plate->placements, true );
		$counts     = is_array( $placements ) && isset( $placements['counts_by_article'] ) ? (array) $placements['counts_by_article'] : array();
		$items      = array();
		$minutes    = 0.0;

		foreach ( $counts as $article_id => $qty ) {
			$article  = ArticleRepository::find( (int) $article_id );
			$revision = RevisionRepository::active_for_article( (int) $article_id );
			$per      = $revision ? max( 1, (int) $revision['parts_per_plate'] ) : 1;

			if ( $revision ) {
				$minutes += ( (int) $qty / $per ) * (float) $revision['print_time_plate_h'] * 60;
			}

			$items[] = array(
				'article_id' => (int) $article_id,
				'sku'        => $article ? (string) $article['sku'] : '',
				'name'       => $article ? (string) $article['name'] : '#' . (int) $article_id,
				'color'      => $revision && ! empty( $revision['color'] ) ? (string) $revision['color'] : '',
				'qty'        => (int) $qty,
			);
		}

		return array( 'items' => $items, 'est_minutes' => (int) round( $minutes ) );
	}

	/**
	 * Betriebsdrucker (Gerätestamm) zu einem Bambu-Drucker.
	 *
	 * Das Druckjournal kennt nur Geräte aus dem Stamm. Fehlt die
	 * Verknüpfung, hilft der einzige produktive Drucker; sonst bleibt es
	 * leer und die Ist-Meldung sagt, was zu tun ist.
	 *
	 * @param string $bambu_id Bambu-Drucker.
	 * @return int
	 */
	public static function device_for( $bambu_id ) {
		$bambu = BambuPrinterRepository::find_by_printer_id( $bambu_id );

		if ( $bambu && (int) $bambu->geraet_id && PrinterRepository::find( (int) $bambu->geraet_id ) ) {
			return (int) $bambu->geraet_id;
		}

		$productive = array();
		foreach ( PrinterRepository::all() as $printer ) {
			if ( in_array( (string) $printer['status'], MachineRate::PRODUCTIVE_STATUS, true ) ) {
				$productive[] = (int) $printer['id'];
			}
		}

		return 1 === count( $productive ) ? $productive[0] : 0;
	}

	/**
	 * Vorschau freigeben: aus den angeordneten Platten werden Platten der
	 * Werkstatt, die Etiketten werden gedruckt.
	 *
	 * @param int[] $plate_ids   Plattendateien aus preview().
	 * @param bool  $print_labels Etiketten drucken.
	 * @return array {ok, runs, label, error}
	 */
	public static function release( array $plate_ids, $print_labels = true ) {
		$batch = RunRepository::next_batch();
		$runs  = array();

		foreach ( array_unique( array_map( 'intval', $plate_ids ) ) as $plate_id ) {
			$plate = PlateJobRepository::find( $plate_id );

			if ( ! $plate || RunRepository::find_by_plate( $plate_id ) ) {
				continue;
			}

			$data = self::plate_items( $plate );

			if ( ! $data['items'] ) {
				continue;
			}

			$id = RunRepository::create(
				array(
					'batch'         => $batch,
					'printer_id'    => self::device_for( $plate->printer_id ),
					'bambu_printer' => $plate->printer_id,
					'plate_job_id'  => $plate_id,
					'items'         => $data['items'],
					'est_minutes'   => $data['est_minutes'],
				)
			);

			if ( $id ) {
				$runs[] = $id;
				AuditLog::record( 'erp_platte_freigegeben', 'run', $id, array( 'plan' => $batch, 'platte' => $plate_id ) );
			}
		}

		if ( ! $runs ) {
			return array( 'ok' => false, 'runs' => array(), 'label' => null, 'error' => __( 'Es gab nichts freizugeben – die Platten sind schon freigegeben oder leer.', 'lindenweg16' ) );
		}

		$label = $print_labels ? self::print_labels( $runs ) : null;

		return array(
			'ok'    => true,
			'runs'  => array_map( array( __CLASS__, 'view_by_id' ), $runs ),
			'label' => $label,
			'error' => '',
		);
	}

	/**
	 * Etiketten für Platten in die Druck-Warteschlange legen.
	 *
	 * @param int[] $run_ids Platten.
	 * @return array {ok, message}
	 */
	public static function print_labels( array $run_ids ) {
		$settings = self::settings();

		if ( '' === $settings['label_printer'] ) {
			return array( 'ok' => false, 'message' => __( 'Kein Etikettendrucker eingestellt – die Etiketten wurden nicht gedruckt.', 'lindenweg16' ) );
		}

		$labels = array();

		foreach ( $run_ids as $run_id ) {
			$run = RunRepository::find( (int) $run_id );
			if ( ! $run ) {
				continue;
			}

			$text = RunFlow::label_text( self::label_context( $run ), $settings['label_fields'] );

			for ( $i = 0; $i < $settings['label_copies']; $i++ ) {
				$labels[] = array(
					'code'    => $text['code'],
					'name'    => $text['name'],
					'payload' => self::run_url( $run['id'] ),
					'kind'    => $text['kind'],
				);
			}
		}

		if ( ! $labels ) {
			return array( 'ok' => false, 'message' => __( 'Es gab nichts zu drucken.', 'lindenweg16' ) );
		}

		$roll   = LabelSheet::roll( $settings['label_roll'] );
		$width  = LabelSheet::is_landscape( $roll ) ? (float) $roll['h'] : (float) $roll['w'];
		$height = LabelSheet::is_landscape( $roll ) ? (float) $roll['w'] : (float) $roll['h'];

		// Ein Druckauftrag je Etikett: Der Etikettendrucker nimmt je Auftrag
		// eine Seite, weitere gingen sonst verloren.
		foreach ( $labels as $label ) {
			$pdf    = LabelPdf::build_roll( array( $label ), $settings['label_roll'] );
			$job_id = $pdf ? PrintQueueService::enqueue_pdf_labels( $settings['label_printer'], $pdf->output(), 'run_label', $width, $height, $settings['label_roll'] ) : 0;

			if ( ! $job_id ) {
				return array( 'ok' => false, 'message' => __( 'Der Druckjob konnte nicht angelegt werden – ist der Etikettendrucker richtig eingestellt?', 'lindenweg16' ) );
			}
		}

		return array(
			'ok'      => true,
			/* translators: %d: Anzahl Etiketten. */
			'message' => sprintf( _n( '%d Etikett gedruckt.', '%d Etiketten gedruckt.', count( $labels ), 'lindenweg16' ), count( $labels ) ),
		);
	}

	/**
	 * Angaben einer Platte für den Etikettentext.
	 *
	 * @param array $run Platte.
	 * @return array
	 */
	protected static function label_context( array $run ) {
		return array(
			'id'          => $run['id'],
			'printer'     => self::printer_name( $run ),
			'est_minutes' => (int) $run['est_minutes'],
			'order_ref'   => (string) $run['batch'],
			'items'       => $run['items'],
		);
	}

	/**
	 * Kurzname des Druckers einer Platte.
	 *
	 * @param array $run Platte.
	 * @return string
	 */
	protected static function printer_name( array $run ) {
		if ( ! empty( $run['bambu_printer'] ) ) {
			$bambu = BambuPrinterRepository::find_by_printer_id( $run['bambu_printer'] );

			if ( $bambu ) {
				return (string) $bambu->name;
			}
		}

		if ( ! empty( $run['printer_id'] ) ) {
			$printer = PrinterRepository::find( (int) $run['printer_id'] );

			if ( $printer ) {
				return (string) $printer['code'];
			}
		}

		return '';
	}

	/* ====================================================================
	 * Platten: Anzeige
	 * ================================================================== */

	/**
	 * Eine Platte nach ID für die Anzeige.
	 *
	 * @param int $id Platte.
	 * @return array|null
	 */
	public static function view_by_id( $id ) {
		$run = RunRepository::find( (int) $id );

		return $run ? self::view( $run, true ) : null;
	}

	/**
	 * Materialzeilen für die Anzeige: nur, was die Oberfläche braucht.
	 *
	 * @param array $rows Zeilen aus Material::check().
	 * @return array
	 */
	public static function material_view( array $rows ) {
		return array_map(
			static function ( $row ) {
				return array(
					'color'        => $row['color'],
					'label'        => $row['label'],
					'needed_kg'    => $row['needed_kg'],
					'available_kg' => $row['available_kg'],
					'missing_kg'   => $row['missing_kg'],
					'ok'           => $row['ok'],
					'suggested'    => $row['suggested'],
					'lots'         => array_map(
						static function ( $lot ) {
							return array( 'id' => $lot['id'], 'code' => $lot['code'], 'remaining_kg' => $lot['remaining_kg'] );
						},
						$row['lots']
					),
				);
			},
			$rows
		);
	}

	/**
	 * Eine Platte für die Anzeige aufbereiten.
	 *
	 * @param array $run    Platte.
	 * @param bool  $detail Mit Materialprüfung (für die Plattenseite, nicht für die Liste).
	 * @return array
	 */
	public static function view( array $run, $detail = false ) {
		$time = static function ( $value ) {
			return $value ? (int) strtotime( $value . ' UTC' ) : 0;
		};

		$live    = null;
		$bambu   = ! empty( $run['bambu_printer'] ) ? BambuPrinterRepository::find_by_printer_id( $run['bambu_printer'] ) : null;
		$primary = RunFlow::primary( $run['status'] );

		if ( $bambu && BambuPrinterRepository::is_online( $bambu ) ) {
			$live = array(
				'status'    => strtolower( (string) $bambu->status ),
				'progress'  => null !== $bambu->progress_percent ? (int) $bambu->progress_percent : null,
				'remaining' => null !== $bambu->remaining_minutes ? (int) $bambu->remaining_minutes : null,
				'nozzle'    => null !== $bambu->nozzle_temp ? (float) $bambu->nozzle_temp : null,
				'bed'       => null !== $bambu->bed_temp ? (float) $bambu->bed_temp : null,
			);
		}

		$file   = null;
		$layout = null;
		if ( ! empty( $run['plate_job_id'] ) ) {
			$plate = PlateJobRepository::find( (int) $run['plate_job_id'] );

			if ( $plate && $detail ) {
				$layout = BambuDruck::layout( $plate );
			}

			if ( $plate && is_file( $plate->file_path ) ) {
				$file = array(
					'name' => (string) $plate->file_name,
					'url'  => Shell::nonce_url( admin_url( 'admin-post.php?action=lw16_erp_run_file&run=' . (int) $run['id'] ), 'lw16_erp_run_file_' . (int) $run['id'] ),
				);
			}
		}

		$material = array();
		$items    = $run['items'];

		if ( $detail && in_array( $run['status'], array( RunFlow::FREIGEGEBEN, RunFlow::RUESTEN, RunFlow::DRUCKT, RunFlow::ZU_MELDEN ), true ) ) {
			$material = self::material_view( Material::check( $items ) );

			foreach ( $items as $index => $item ) {
				$items[ $index ]['kg']    = round( Material::weight_kg( $item['article_id'] ) * (float) $item['qty'], 3 );
				$items[ $index ]['ckey']  = Material::color_of( isset( $item['color'] ) ? $item['color'] : '' );
			}
		}

		return array(
			'layout'      => $layout,
			'material'    => $material,
			'id'          => (int) $run['id'],
			'batch'       => (string) $run['batch'],
			'status'      => $run['status'],
			'column'      => RunFlow::column( $run['status'] ),
			'items'       => $items,
			'printer'     => self::printer_name( $run ),
			'est_minutes' => (int) $run['est_minutes'],
			'released'    => $time( $run['released_at'] ),
			'setup'       => $time( $run['setup_at'] ),
			'print'       => $time( $run['print_at'] ),
			'print_end'   => $time( $run['print_end_at'] ),
			'reported'    => $time( $run['reported_at'] ),
			'primary'     => $primary,
			'live'        => $live,
			'file'        => $file,
			'failure'     => (string) $run['failure_reason'],
			'now'         => time(),
		);
	}

	/**
	 * Hat der Drucker die Datei dieser Platte geladen?
	 *
	 * @param array  $run     Platte.
	 * @param object $printer Bambu-Drucker.
	 * @return bool
	 */
	protected static function file_is_on_printer( array $run, $printer ) {
		$plate = PlateJobRepository::find( (int) $run['plate_job_id'] );

		return $plate && RunFlow::file_matches( (string) $printer->current_file, (string) $plate->file_name );
	}

	/**
	 * Alle Spalten des Workflows.
	 *
	 * @return array
	 */
	public static function columns() {
		self::sync_printers();

		$cols = array( 'freigegeben' => array(), 'im_druck' => array(), 'zu_melden' => array(), 'erledigt' => array() );

		foreach ( RunRepository::active() as $run ) {
			$col = RunFlow::column( $run['status'] );
			if ( isset( $cols[ $col ] ) ) {
				$cols[ $col ][] = self::view( $run );
			}
		}

		foreach ( RunRepository::done_today() as $run ) {
			$cols['erledigt'][] = self::view( $run );
		}

		$cols['freigegeben'] = self::shift_order( $cols['freigegeben'] );

		return $cols;
	}

	/**
	 * Jetzt in Minuten seit Mitternacht, in der Zeit des Shops.
	 *
	 * @return int
	 */
	public static function now_minutes() {
		$now = new \DateTimeImmutable( 'now', wp_timezone() );

		return (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
	}

	/**
	 * Bereite Platten nach Tageszeit ordnen: kurze am Tag, lange über Nacht.
	 *
	 * @param array $runs Platten mit id und est_minutes.
	 * @return array Platten mit zusätzlich long und hint (Code) sowie hint_text.
	 */
	public static function shift_order( array $runs ) {
		$s       = self::settings();
		$end     = ShiftOrder::minutes_of( $s['shift_end'] );
		$ordered = ShiftOrder::order( $runs, self::now_minutes(), $end, (float) $s['shift_lead_h'], (float) $s['long_h'] );
		$start   = $end - (int) round( (float) $s['shift_lead_h'] * 60 );

		foreach ( $ordered as $i => $run ) {
			$ordered[ $i ]['hint_text'] = ShiftOrder::hint_text( $run['hint'], (int) $run['est_minutes'], $start );
		}

		return $ordered;
	}

	/**
	 * Platten mit dem Stand der Bambu-Drucker abgleichen.
	 *
	 * Der Drucker meldet den Namen der geladenen Datei. Passt er zur
	 * Plattendatei einer Platte, ist genau diese gemeint – auch wenn mehrere
	 * Platten für denselben Drucker anstehen. Meldet der Drucker „druckt“,
	 * ist das Rüsten vorbei; meldet er „fertig“, ist der Druck zu Ende.
	 * Kennt die Bridge noch keinen Dateinamen, gilt wie früher: nur wenn auf
	 * dem Drucker genau eine Platte läuft – bei zwei wüsste niemand, welche.
	 */
	public static function sync_printers() {
		$by_printer = array();

		foreach ( RunRepository::active() as $run ) {
			if ( in_array( $run['status'], array( RunFlow::FREIGEGEBEN, RunFlow::RUESTEN, RunFlow::DRUCKT ), true ) && ! empty( $run['bambu_printer'] ) ) {
				$by_printer[ $run['bambu_printer'] ][] = $run;
			}
		}

		foreach ( $by_printer as $bambu_id => $runs ) {
			$printer = BambuPrinterRepository::find_by_printer_id( $bambu_id );

			if ( ! $printer || ! BambuPrinterRepository::is_online( $printer ) ) {
				continue;
			}

			$run = self::run_for_file( $runs, (string) $printer->current_file );

			if ( ! $run ) {
				// Ohne Dateinamen von der Bridge: alte Regel, aber nur für
				// Platten, die schon gerüstet werden oder drucken.
				if ( '' !== trim( (string) $printer->current_file ) ) {
					continue;
				}

				$started = array_values(
					array_filter(
						$runs,
						static function ( $candidate ) {
							return in_array( $candidate['status'], array( RunFlow::RUESTEN, RunFlow::DRUCKT ), true );
						}
					)
				);

				if ( 1 !== count( $started ) ) {
					continue;
				}

				$run = $started[0];
			}

			$status = strtolower( (string) $printer->status );

			if ( in_array( $run['status'], array( RunFlow::FREIGEGEBEN, RunFlow::RUESTEN ), true ) && RunFlow::printer_started( $status ) ) {
				self::act( $run['id'], 'druckstart', array(), 0 );
			} elseif ( RunFlow::DRUCKT === $run['status'] && RunFlow::printer_ended( $status ) && $run['print_at'] && strtotime( $run['print_at'] . ' UTC' ) < time() - 120 ) {
				self::act( $run['id'], 'druckende', array(), 0 );
			}
		}
	}

	/**
	 * Die Platte, deren Datei der Drucker gerade geladen hat.
	 *
	 * @param array  $runs Platten eines Druckers.
	 * @param string $file Dateiname laut Drucker.
	 * @return array|null
	 */
	protected static function run_for_file( array $runs, $file ) {
		if ( '' === RunFlow::normalize_file( $file ) ) {
			return null;
		}

		foreach ( $runs as $run ) {
			$plate = ! empty( $run['plate_job_id'] ) ? PlateJobRepository::find( (int) $run['plate_job_id'] ) : null;

			if ( $plate && RunFlow::file_matches( $file, (string) $plate->file_name ) ) {
				return $run;
			}
		}

		return null;
	}

	/* ====================================================================
	 * Übergänge und Ist-Meldung
	 * ================================================================== */

	/**
	 * Eine Aktion auf einer Platte ausführen.
	 *
	 * @param int    $id      Platte.
	 * @param string $action  ruesten, druckstart, druckende, melden oder abbrechen.
	 * @param array  $input   Eingaben (bei melden: items, failure_reason, note).
	 * @param int    $user_id Handelnde Person; 0 = automatisch.
	 * @return array {ok, message, run, status}
	 */
	public static function act( $id, $action, array $input = array(), $user_id = -1 ) {
		$run = RunRepository::find( (int) $id );

		if ( ! $run ) {
			return array( 'ok' => false, 'status' => 404, 'message' => __( 'Platte nicht gefunden.', 'lindenweg16' ) );
		}

		$user_id = -1 === $user_id ? get_current_user_id() : (int) $user_id;
		$step    = RunFlow::apply( $run['status'], $action );

		if ( ! $step['ok'] ) {
			return array( 'ok' => false, 'status' => 409, 'message' => __( 'Das geht bei dieser Platte gerade nicht – vermutlich hat sie jemand anders schon weitergeschaltet.', 'lindenweg16' ) );
		}

		if ( $step['noop'] ) {
			return array( 'ok' => true, 'status' => 200, 'message' => '', 'run' => self::view( $run ) );
		}

		$now = current_time( 'mysql', true );

		switch ( $action ) {
			case 'ruesten':
				RunRepository::update( $run['id'], array( 'status' => RunFlow::RUESTEN, 'setup_at' => $now, 'worker_id' => $user_id ? $user_id : null ) );
				break;

			case 'druckstart':
				RunRepository::update(
					$run['id'],
					array(
						'status'    => RunFlow::DRUCKT,
						'setup_at'  => $run['setup_at'] ? $run['setup_at'] : $now,
						'print_at'  => $now,
						'worker_id' => $run['worker_id'] ? $run['worker_id'] : ( $user_id ? $user_id : null ),
					)
				);
				break;

			case 'druckende':
				RunRepository::update( $run['id'], array( 'status' => RunFlow::ZU_MELDEN, 'print_end_at' => $now ) );
				break;

			case 'abbrechen':
				RunRepository::update(
					$run['id'],
					array(
						'status' => RunFlow::ABGEBROCHEN,
						'note'   => isset( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : null,
					)
				);
				AuditLog::record( 'erp_platte_abgebrochen', 'run', $run['id'], array() );
				break;

			case 'melden':
				return self::report( $run, $input, $user_id );
		}

		return array( 'ok' => true, 'status' => 200, 'message' => '', 'run' => self::view_by_id( $run['id'] ) );
	}

	/**
	 * Ist-Menge melden: Druckjournal, Lagerzugang, Zeiten.
	 *
	 * Erst werden alle Positionen geprüft, dann geschrieben – eine
	 * halb gebuchte Platte wäre schlimmer als eine abgelehnte Meldung.
	 *
	 * @param array $run     Platte.
	 * @param array $input   items [{article_id, color, ok}], failure_reason, note.
	 * @param int   $user_id Handelnde Person.
	 * @return array {ok, message, run, status, summary}
	 */
	protected static function report( array $run, array $input, $user_id ) {
		$reason = isset( $input['failure_reason'] ) ? sanitize_key( (string) $input['failure_reason'] ) : 'keine';
		$check  = RunFlow::validate_report( $run['items'], isset( $input['items'] ) ? (array) $input['items'] : array(), $reason, PrintJobRepository::FAILURE_REASONS );

		if ( $check['errors'] ) {
			return array( 'ok' => false, 'status' => 422, 'message' => implode( ' ', $check['errors'] ) );
		}

		// Das Gerät gilt, wie der Bambu-Drucker jetzt verknüpft ist; die Platte kennt nur den Stand beim Freigeben.
		$device     = ! empty( $run['bambu_printer'] ) ? self::device_for( (string) $run['bambu_printer'] ) : 0;
		$printer_id = $device ? $device : (int) $run['printer_id'];

		if ( ! $printer_id || ! PrinterRepository::find( $printer_id ) ) {
			return array( 'ok' => false, 'status' => 422, 'message' => __( 'Dieser Platte ist kein Drucker aus dem Gerätestamm zugeordnet. Bitte beim Bambu-Drucker das Gerät verknüpfen – ohne Gerät lässt sich nichts ins Druckjournal buchen.', 'lindenweg16' ) );
		}

		$now       = current_time( 'mysql', true );
		$print_end = $run['print_end_at'] ? $run['print_end_at'] : $now;
		$print_h   = $run['print_at'] ? RunFlow::hours_between( $run['print_at'], $print_end ) : 0.0;
		$setup_h   = $run['setup_at'] ? RunFlow::hours_between( $run['setup_at'], $run['print_at'] ? $run['print_at'] : $print_end ) : 0.0;

		$qtys = array();
		foreach ( $check['items'] as $index => $item ) {
			$qtys[ $index ] = $item['qty'];
		}
		$shares = RunFlow::split_hours( $print_h, $qtys );

		// Welche Rolle liefert das Filament? Gewählt (Mobilseite) oder die
		// älteste mit genug Rest – ohne Rolle würde der Verbrauch aus dem
		// Bestand verschwinden, ohne dass jemand es merkt.
		$chosen = isset( $input['lots'] ) && is_array( $input['lots'] ) ? $input['lots'] : array();
		$lot_of = array();
		$used   = array();

		foreach ( $check['items'] as $index => $item ) {
			$color = Material::color_of( $item['color'] );
			$kg    = Material::weight_kg( $item['article_id'] ) * $item['qty'];
			$key   = $item['article_id'] . '|' . $item['color'];
			$lot   = null;

			if ( ! empty( $chosen[ $key ] ) ) {
				foreach ( Material::lots_for( $color ) as $candidate ) {
					if ( (int) $candidate['id'] === (int) $chosen[ $key ] ) {
						$lot = $candidate;
					}
				}
			}

			if ( ! $lot ) {
				$lot = Material::pick_lot( Material::lots_for( $color ), $kg );
			}

			$lot_of[ $index ] = $lot ? (int) $lot['id'] : 0;
			$used[ $index ]   = array( 'color' => $color, 'kg' => round( $kg, 3 ), 'lot' => $lot );
		}

		// 1. Alles prüfen.
		$jobs   = array();
		$errors = array();

		foreach ( $check['items'] as $index => $item ) {
			$validated = PrintJobRepository::validate(
				array(
					'filament_lot_id' => $lot_of[ $index ],
					'printer_id'     => $printer_id,
					'article_id'     => $item['article_id'],
					'color'          => $item['color'],
					'parts_planned'  => $item['qty'],
					'parts_ok'       => $item['ok'],
					'failure_reason' => $item['scrap'] > 0 ? $reason : 'keine',
					'print_time_h'   => $shares[ $index ],
					'plate_changes'  => 0 === $index ? 1 : 0,
					'note'           => sprintf( /* translators: %d: Plattennummer. */ __( 'Platte %d', 'lindenweg16' ), $run['id'] ),
				)
			);

			if ( $validated['errors'] ) {
				$errors = array_merge( $errors, $validated['errors'] );
				continue;
			}

			$jobs[] = $validated['data'];
		}

		if ( $errors ) {
			return array( 'ok' => false, 'status' => 422, 'message' => implode( ' ', array_unique( $errors ) ) );
		}

		// 2. Schreiben.
		$job_ids = array();
		foreach ( $jobs as $job ) {
			$job_id = PrintJobRepository::insert( $job );

			if ( $job_id ) {
				$job_ids[] = $job_id;
			}
		}

		Material::flush();

		$material_summary = array();
		foreach ( $used as $row ) {
			$lot  = $row['lot'];
			$rest = null;

			if ( $lot ) {
				$fresh = FilamentRepository::find( (int) $lot['id'] );
				$rest  = $fresh ? round( FilamentRepository::remaining_kg( $fresh ), 3 ) : null;
			}

			$material_summary[] = array(
				'color'     => $row['color'],
				'label'     => \Lindenweg16\Modules\Betrieb\ColorRegistry::label( $row['color'] ),
				'kg'        => $row['kg'],
				'lot'       => $lot ? $lot['code'] : '',
				'remaining' => $rest,
			);
		}

		$description = sprintf( /* translators: %d: Plattennummer. */ __( 'Platte %d', 'lindenweg16' ), $run['id'] );
		// Nur das Rüsten ist Arbeitszeit. Der Druck selbst ist Maschinenzeit (Druckjournal):
		// Während er läuft, arbeitet die Person an etwas anderem.
		self::book_time( 'ruestzeit', $setup_h, $description, $run['id'] );

		$items = array();
		foreach ( $check['items'] as $item ) {
			$row = array_merge( $item, array( 'name' => '', 'sku' => '' ) );

			foreach ( $run['items'] as $source ) {
				if ( (int) $source['article_id'] === $item['article_id'] && (string) $source['color'] === $item['color'] ) {
					$row['name'] = $source['name'];
					$row['sku']  = $source['sku'];
				}
			}

			$items[] = $row;
		}

		RunRepository::update(
			$run['id'],
			array(
				'status'         => RunFlow::ERLEDIGT,
				'print_end_at'   => $print_end,
				'reported_at'    => $now,
				'items'          => $items,
				'failure_reason' => $reason,
				'note'           => isset( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : null,
				'print_job_ids'  => implode( ',', $job_ids ),
				'worker_id'      => $run['worker_id'] ? $run['worker_id'] : ( $user_id ? $user_id : null ),
			)
		);

		AuditLog::record( 'erp_platte_gemeldet', 'run', $run['id'], array( 'druckjobs' => $job_ids ) );

		return array(
			'ok'      => true,
			'status'  => 200,
			'message' => __( 'Gemeldet und eingelagert.', 'lindenweg16' ),
			'run'     => self::view_by_id( $run['id'] ),
			'summary' => array(
				'material' => $material_summary,
				'items'   => $items,
				'setup_h' => $setup_h,
				'print_h' => $print_h,
			),
		);
	}

	/**
	 * Zeit buchen – in Stücken von höchstens 24 Stunden.
	 *
	 * Ein Druck über das Wochenende dauert länger als ein Tag; die
	 * Zeiterfassung lässt je Eintrag höchstens 24 Stunden zu.
	 *
	 * @param string $category    Kategorie der Zeit (der Workflow bucht nur ruestzeit).
	 * @param float  $hours       Stunden.
	 * @param string $description Beschreibung.
	 * @param int    $run_id      Platte.
	 */
	protected static function book_time( $category, $hours, $description, $run_id ) {
		$left = round( (float) $hours, 3 );

		while ( $left > 0.0005 ) {
			$part      = min( 24.0, $left );
			$validated = TimeRepository::validate(
				array(
					'category'    => $category,
					'hours'       => $part,
					'description' => $description,
					'run_id'      => $run_id,
				)
			);

			if ( ! $validated['errors'] ) {
				TimeRepository::insert( $validated['data'] );
			}

			$left = round( $left - $part, 3 );
		}
	}
}
