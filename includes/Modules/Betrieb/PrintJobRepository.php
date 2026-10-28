<?php
/**
 * Druckjournal – das zentrale Blatt der Fertigung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Druckjobs samt Folgebuchungen.
 */
class PrintJobRepository {

	use SanitizesInput;

	/**
	 * Erlaubte Fehlerursachen.
	 */
	const FAILURE_REASONS = array(
		'keine',
		'erste_schicht',
		'verstopfung',
		'filament_feucht',
		'warping',
		'abbruch',
		'bedienfehler',
		'konstruktion',
		'sonstiges',
	);

	/**
	 * Bezeichnungen der Fehlerursachen.
	 *
	 * @return array
	 */
	public static function failure_labels() {
		return array(
			'keine'           => __( 'keine', 'lindenweg16' ),
			'erste_schicht'   => __( 'erste Schicht', 'lindenweg16' ),
			'verstopfung'     => __( 'Verstopfung', 'lindenweg16' ),
			'filament_feucht' => __( 'Filament feucht', 'lindenweg16' ),
			'warping'         => __( 'Warping', 'lindenweg16' ),
			'abbruch'         => __( 'Abbruch', 'lindenweg16' ),
			'bedienfehler'    => __( 'Bedienfehler', 'lindenweg16' ),
			'konstruktion'    => __( 'Konstruktion', 'lindenweg16' ),
			'sonstiges'       => __( 'Sonstiges', 'lindenweg16' ),
		);
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_print_jobs';
	}

	/**
	 * Kennzahlen eines Jobs – berechnet, nicht gespeichert.
	 *
	 * @param array $job Druckjob.
	 * @return array
	 */
	public static function metrics( array $job ) {
		$planned = isset( $job['parts_planned'] ) ? (float) $job['parts_planned'] : 0.0;
		$ok      = isset( $job['parts_ok'] ) ? (float) $job['parts_ok'] : 0.0;
		$changes = isset( $job['plate_changes'] ) ? (float) $job['plate_changes'] : 0.0;

		return array(
			'parts_scrap'            => $planned - $ok,
			'scrap_rate'             => $planned > 0 ? ( $planned - $ok ) / $planned : 0.0,
			'parts_per_plate_change' => $changes > 0 ? $ok / $changes : 0.0,
		);
	}

	/**
	 * Alle Jobs, neueste zuerst.
	 *
	 * @param array $args Filter: printer_id, article_id, iso_year, iso_week, limit.
	 * @return array
	 */
	public static function all( array $args = array() ) {
		global $wpdb;

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'printer_id', 'article_id', 'iso_year', 'iso_week' ) as $field ) {
			if ( ! empty( $args[ $field ] ) ) {
				$where[]  = "{$field} = %d";
				$params[] = (int) $args[ $field ];
			}
		}

		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY job_date DESC, id DESC LIMIT %d';
		$params[] = isset( $args['limit'] ) ? (int) $args['limit'] : 200;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Einen Job holen.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Letzter Job eines Druckers – Grundlage der Vorbelegung in der
	 * mobilen Schnellerfassung.
	 *
	 * @param int $printer_id Drucker.
	 * @return array|null
	 */
	public static function last_for_printer( $printer_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE printer_id = %d AND voided_at IS NULL ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $printer_id
			),
			ARRAY_A
		);
	}

	/**
	 * Betriebsstunden eines Druckers – Summe der Druckzeiten.
	 *
	 * @param int $printer_id Drucker.
	 * @return float
	 */
	public static function operating_hours( $printer_id ) {
		global $wpdb;
		$table = self::table();
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( print_time_h ), 0 ) FROM {$table} WHERE printer_id = %d AND voided_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $printer_id
			)
		);
	}

	/**
	 * Druckstunden bestimmter Journaleinträge, die auf einem anderen Gerät stehen.
	 *
	 * Gebraucht, wenn eine Platte beim Freigeben ein anderes Gerät kannte als das,
	 * mit dem der Bambu-Drucker heute verknüpft ist: Ihre Stunden stehen dann beim
	 * alten Gerät und fehlten sonst in der Betriebsstunden-Anzeige des Druckers.
	 *
	 * @param int[] $ids       Journaleinträge.
	 * @param int   $printer_id Gerät, dessen Stunden schon gezählt sind.
	 * @return float
	 */
	public static function hours_elsewhere( array $ids, $printer_id ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0.0;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( print_time_h ), 0 ) FROM {$table} WHERE id IN ({$placeholders}) AND printer_id <> %d AND voided_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $ids, array( (int) $printer_id ) )
			)
		);
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array $input Eingaben.
	 * @return array {data, errors}
	 */
	public static function validate( array $input ) {
		$errors = array();

		$printer_id = self::int_or( $input, 'printer_id', 0 );
		$article_id = self::int_or( $input, 'article_id', 0 );
		$job_date   = self::date_or_today( $input, 'job_date' );

		if ( ! $printer_id || ! PrinterRepository::find( $printer_id ) ) {
			$errors[] = __( 'Bitte einen Drucker wählen.', 'lindenweg16' );
		}

		$article = $article_id ? ArticleRepository::find( $article_id ) : null;
		if ( ! $article ) {
			$errors[] = __( 'Bitte einen Artikel wählen.', 'lindenweg16' );
		} elseif ( in_array( $article['sourcing_type'], array( 'zukauf', 'montage' ), true ) ) {
			$errors[] = __( 'Dieser Artikel wird nicht gedruckt. Ein Halbzeug entsteht über einen Fertigungsauftrag, ein Zukaufartikel über eine Bestellung.', 'lindenweg16' );
		}

		$revision = $article_id ? RevisionRepository::current_for_article( $article_id ) : null;

		if ( $article && ! in_array( $article['sourcing_type'], array( 'zukauf', 'montage' ), true ) && ! $revision ) {
			$errors[] = __( 'Für diesen Artikel ist keine Revision hinterlegt. Ohne Bauteilgewicht lassen sich weder Filamentverbrauch noch Charge nachvollziehen.', 'lindenweg16' );
		}

		$planned = self::decimal_or( $input, 'parts_planned', 0 );
		$ok      = self::decimal_or( $input, 'parts_ok', 0 );

		if ( $planned <= 0 ) {
			$errors[] = __( 'Die geplante Stückzahl muss größer als 0 sein.', 'lindenweg16' );
		}
		if ( $ok < 0 ) {
			$errors[] = __( 'Gute Teile können nicht negativ sein.', 'lindenweg16' );
		}
		if ( $ok > $planned ) {
			$errors[] = __( 'Es können nicht mehr Teile gut sein als geplant waren.', 'lindenweg16' );
		}

		// Ausschuss ohne Ursache lässt sich später nicht auswerten.
		$failure = self::enum( $input, 'failure_reason', self::FAILURE_REASONS, 'keine' );
		if ( $ok < $planned && 'keine' === $failure ) {
			$errors[] = __( 'Bei Ausschuss ist eine Fehlerursache Pflicht – sonst lässt sich die Ausschussquote später nicht auswerten.', 'lindenweg16' );
		}

		$iso = IsoWeek::from_date( $job_date );

		return array(
			'data'   => array(
				'job_date'            => $job_date,
				'iso_week'            => $iso['iso_week'],
				'iso_year'            => $iso['iso_year'],
				'printer_id'          => $printer_id,
				'article_id'          => $article_id,
				'article_revision_id' => $revision ? (int) $revision['id'] : null,
				'color'               => ColorRegistry::normalize( isset( $input['color'] ) ? $input['color'] : '' ),
				// Eine Charge wird nicht mehr gewählt: Der Verbrauch geht auf die älteste Lieferung dieser Farbe.
				'filament_lot_id'     => self::int_or( $input, 'filament_lot_id', 0 )
					? self::int_or( $input, 'filament_lot_id', 0 )
					: ( FilamentRepository::default_lot_for_color( isset( $input['color'] ) ? (string) $input['color'] : '' ) ?: null ),
				'parts_planned'       => $planned,
				'parts_ok'            => $ok,
				'failure_reason'      => $failure,
				'print_time_h'        => self::decimal_or( $input, 'print_time_h', 0 ),
				'plate_changes'       => self::int_or( $input, 'plate_changes', 0 ),
				'note'                => isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : null,
			),
			'errors' => $errors,
		);
	}

	/**
	 * Druckjob buchen.
	 *
	 * Erzeugt automatisch den Lagerzugang über die guten Teile und – sofern
	 * eine Filamentcharge angegeben ist – einen Chargeneintrag für die
	 * Rückverfolgung.
	 *
	 * @param array $data   Geprüfte Daten.
	 * @param int   $bin_id Lagerplatz für den Lagerzugang (0 = ohne Platz).
	 * @return int Neue ID oder 0.
	 */
	public static function insert( array $data, $bin_id = 0 ) {
		global $wpdb;

		if ( '' === $data['color'] ) {
			$data['color'] = ColorRegistry::default_color();
		}

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'print_jobs', $id, null, $data );

		// Charge anlegen, solange gute Teile entstanden sind.
		$batch_id = 0;
		if ( (float) $data['parts_ok'] > 0 ) {
			$batch_id = BatchRepository::create_for_job( $id, $data );
			if ( $batch_id ) {
				$wpdb->update( self::table(), array( 'batch_id' => $batch_id ), array( 'id' => $id ) );
			}

			// Lagerzugang über die guten Teile.
			StockRepository::book(
				array(
					'article_id'    => (int) $data['article_id'],
					'color'         => $data['color'],
					'qty'           => (float) $data['parts_ok'],
					'movement_type' => 'produktion',
					'location'      => 'lager',
					'bin_id'        => (int) $bin_id,
					'ref_type'      => 'print_job',
					'ref_id'        => $id,
					'moved_at'      => $data['job_date'] . ' 12:00:00',
				)
			);
		}

		// Kleinteile und direkte Unterbaugruppen mit den guten Teilen verbauen.
		ComponentConsumption::consume_for_production( (int) $data['article_id'], (float) $data['parts_ok'], 'print_job', $id );
		ComponentConsumption::book_assemblies(
			ComponentConsumption::assemblies_at_production(
				BomRepository::for_article( (int) $data['article_id'] ),
				(float) $data['parts_ok']
			),
			$data['color'],
			'print_job',
			$id
		);

		return $id;
	}

	/**
	 * Druckjob stornieren – durch Gegenbuchung, nie durch Löschen.
	 *
	 * @param int    $id     Job.
	 * @param string $reason Begründung.
	 * @return array {success, message}
	 */
	public static function void( $id, $reason ) {
		global $wpdb;

		$reason = trim( (string) $reason );
		if ( '' === $reason ) {
			return array(
				'success' => false,
				'message' => __( 'Ein Storno braucht eine Begründung.', 'lindenweg16' ),
			);
		}

		$job = self::find( $id );
		if ( ! $job ) {
			return array(
				'success' => false,
				'message' => __( 'Druckjob nicht gefunden.', 'lindenweg16' ),
			);
		}
		if ( $job['voided_at'] ) {
			return array(
				'success' => false,
				'message' => __( 'Dieser Druckjob ist bereits storniert.', 'lindenweg16' ),
			);
		}

		$data = array(
			'voided_at'   => current_time( 'mysql', true ),
			'voided_by'   => get_current_user_id(),
			'void_reason' => sanitize_text_field( $reason ),
		);

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'void', 'print_jobs', (int) $id, $job, $data, $reason );

		// Zugehörige Lagerzugänge gegenbuchen.
		$stock_table = StockRepository::table();
		$movements   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$stock_table} WHERE ref_type = 'print_job' AND ref_id = %d AND reverses_id IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $id
			),
			ARRAY_A
		);
		foreach ( $movements as $movement ) {
			StockRepository::void( (int) $movement['id'], $reason );
		}

		// Die Kleinteile wurden beim Buchen verbraucht – sie gehen dem
		// Bestand wieder zu.
		ComponentConsumption::restore_for_production(
			(int) $job['article_id'],
			(float) $job['parts_ok'],
			'print_job_storno',
			(int) $id
		);
		ComponentConsumption::book_assemblies(
			ComponentConsumption::assemblies_at_production(
				BomRepository::for_article( (int) $job['article_id'] ),
				(float) $job['parts_ok']
			),
			$job['color'],
			'print_job_storno',
			(int) $id,
			1.0
		);

		// Die Charge wurde nie gefertigt und darf in der Rückverfolgung nicht
		// als ausgeliefert erscheinen.
		if ( $job['batch_id'] ) {
			BatchRepository::void( (int) $job['batch_id'], $reason );
		}

		return array(
			'success' => true,
			'message' => __( 'Druckjob storniert: Lagerzugang gegengebucht, Kleinteile zurückgebucht, Charge storniert.', 'lindenweg16' ),
		);
	}

	/**
	 * Aggregierte Kennzahlen über einen Zeitraum.
	 *
	 * @param string $von Datum von.
	 * @param string $bis Datum bis.
	 * @return array
	 */
	public static function aggregate( $von, $bis ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE( SUM( parts_planned ), 0 ) AS planned,
					COALESCE( SUM( parts_ok ), 0 ) AS ok,
					COALESCE( SUM( plate_changes ), 0 ) AS changes,
					COALESCE( SUM( print_time_h ), 0 ) AS hours,
					COUNT(*) AS jobs
				 FROM {$table}
				 WHERE voided_at IS NULL AND job_date BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$von,
				$bis
			),
			ARRAY_A
		);

		$planned = (float) $row['planned'];
		$ok      = (float) $row['ok'];
		$changes = (float) $row['changes'];

		return array(
			'jobs'                   => (int) $row['jobs'],
			'parts_planned'          => $planned,
			'parts_ok'               => $ok,
			'parts_scrap'            => $planned - $ok,
			'scrap_rate'             => $planned > 0 ? ( $planned - $ok ) / $planned : 0.0,
			'plate_changes'          => $changes,
			'parts_per_plate_change' => $changes > 0 ? $ok / $changes : 0.0,
			'print_time_h'           => (float) $row['hours'],
		);
	}
}
