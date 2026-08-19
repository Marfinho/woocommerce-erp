<?php
/**
 * Einmaliger Importer für die bisherige Excel-Mappe.
 *
 * Bewusst simpel gehalten: CSV je Blatt hochladen, Spalten zuordnen, Vorschau
 * mit Validierungsfehlern, Import erst nach Bestätigung. Er wird genau einmal
 * benutzt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\ComponentRepository;
use Lindenweg16\Modules\Betrieb\ContactRepository;
use Lindenweg16\Modules\Betrieb\FilamentRepository;
use Lindenweg16\Modules\Betrieb\PrinterRepository;
use Lindenweg16\Modules\Betrieb\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\TimeRepository;
use Lindenweg16\Modules\Betrieb\VerfahrensDokuBetrieb;

/**
 * Importseite und Verfahrensdokumentation.
 */
class ImportPage {

	const NONCE = 'lw16_betrieb_import';

	/**
	 * Importierbare Blätter mit ihren Zielfeldern.
	 *
	 * @return array
	 */
	public static function sheets() {
		return array(
			'artikel'      => array(
				'label'  => __( '01 Artikelstamm', 'lindenweg16' ),
				'fields' => array(
					'sku'                => __( 'Art.-Nr (Pflicht)', 'lindenweg16' ),
					'name'               => __( 'Bezeichnung (Pflicht)', 'lindenweg16' ),
					'version'            => __( 'Version', 'lindenweg16' ),
					'material'           => __( 'Material', 'lindenweg16' ),
					'parts_per_plate'    => __( 'Teile je Platte', 'lindenweg16' ),
					'print_time_plate_h' => __( 'Druckzeit Platte h', 'lindenweg16' ),
					'weight_g'           => __( 'Gewicht g', 'lindenweg16' ),
					'postprocessing_min' => __( 'Nachbearb. min', 'lindenweg16' ),
					'price_gross'        => __( 'VK brutto', 'lindenweg16' ),
					'dealer_price'       => __( 'Händlerpreis', 'lindenweg16' ),
					'load_rating_kg'     => __( 'Traglast kg', 'lindenweg16' ),
				),
			),
			'drucker'      => array(
				'label'  => __( '04 Drucker', 'lindenweg16' ),
				'fields' => array(
					'code'             => __( 'Gerät-ID (Pflicht)', 'lindenweg16' ),
					'model'            => __( 'Modell', 'lindenweg16' ),
					'serial'           => __( 'Seriennummer', 'lindenweg16' ),
					'acquired_on'      => __( 'Anschaffung', 'lindenweg16' ),
					'acquisition_cost' => __( 'Preis', 'lindenweg16' ),
					'nozzle_mm'        => __( 'Düse mm', 'lindenweg16' ),
				),
			),
			'druckjournal' => array(
				'label'  => __( '03 Druckjournal', 'lindenweg16' ),
				'fields' => array(
					'job_date'       => __( 'Datum (Pflicht)', 'lindenweg16' ),
					'printer_code'   => __( 'Drucker (Pflicht)', 'lindenweg16' ),
					'sku'            => __( 'Art.-Nr (Pflicht)', 'lindenweg16' ),
					'color'          => __( 'Farbe', 'lindenweg16' ),
					'lot_code'       => __( 'Material-Charge', 'lindenweg16' ),
					'parts_planned'  => __( 'Teile geplant', 'lindenweg16' ),
					'parts_ok'       => __( 'Teile i.O.', 'lindenweg16' ),
					'failure_reason' => __( 'Ursache', 'lindenweg16' ),
					'print_time_h'   => __( 'Druckzeit h', 'lindenweg16' ),
					'plate_changes'  => __( 'Plattenwechsel', 'lindenweg16' ),
				),
			),
			'filament'     => array(
				'label'  => __( '06 Filament', 'lindenweg16' ),
				'fields' => array(
					'lot_code'     => __( 'Charge (Pflicht)', 'lindenweg16' ),
					'received_on'  => __( 'Zugang am', 'lindenweg16' ),
					'material'     => __( 'Typ', 'lindenweg16' ),
					'manufacturer' => __( 'Hersteller', 'lindenweg16' ),
					'color'        => __( 'Farbe', 'lindenweg16' ),
					'kg_received'  => __( 'kg Zugang', 'lindenweg16' ),
					'price_per_kg' => __( '€/kg', 'lindenweg16' ),
					'dried_on'     => __( 'getrocknet am', 'lindenweg16' ),
					'location'     => __( 'Standort', 'lindenweg16' ),
				),
			),
			'zeit'         => array(
				'label'  => __( '09 Zeiterfassung', 'lindenweg16' ),
				'fields' => array(
					'entry_date'  => __( 'Datum (Pflicht)', 'lindenweg16' ),
					'category'    => __( 'Kategorie', 'lindenweg16' ),
					'description' => __( 'Tätigkeit', 'lindenweg16' ),
					'hours'       => __( 'Stunden (Pflicht)', 'lindenweg16' ),
					'phase_block' => __( 'Block', 'lindenweg16' ),
				),
			),
			'kontakte'     => array(
				'label'  => __( '12 Kontakte B2B', 'lindenweg16' ),
				'fields' => array(
					'company'        => __( 'Firma (Pflicht)', 'lindenweg16' ),
					'type'           => __( 'Typ', 'lindenweg16' ),
					'city'           => __( 'Ort', 'lindenweg16' ),
					'contact_person' => __( 'Ansprechpartner', 'lindenweg16' ),
					'phone'          => __( 'Telefon', 'lindenweg16' ),
					'email'          => __( 'E-Mail', 'lindenweg16' ),
					'source'         => __( 'Quelle', 'lindenweg16' ),
					'follow_up_on'   => __( 'Wiedervorlage', 'lindenweg16' ),
					'note'           => __( 'Notizen', 'lindenweg16' ),
				),
			),
		);
	}

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_import', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_import', array( $this, 'handle' ) );
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_settings' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Upload verarbeiten: Vorschau oder Import.
	 */
	public function handle() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$sheet   = isset( $_POST['sheet'] ) ? sanitize_key( wp_unslash( $_POST['sheet'] ) ) : '';
		$confirm = ! empty( $_POST['confirm'] );
		$mapping = isset( $_POST['map'] ) && is_array( $_POST['map'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['map'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$sheets = self::sheets();
		if ( ! isset( $sheets[ $sheet ] ) ) {
			$this->redirect( __( 'Unbekanntes Blatt.', 'lindenweg16' ), 'error' );
		}

		$rows = $this->read_upload();
		if ( ! $rows ) {
			$this->redirect( __( 'Keine lesbaren Zeilen in der Datei gefunden.', 'lindenweg16' ), 'error' );
		}

		// Ohne Zuordnung zuerst die Vorschau zeigen.
		if ( ! $mapping ) {
			set_transient( 'lw16_import_' . get_current_user_id(), array( 'sheet' => $sheet, 'rows' => $rows ), HOUR_IN_SECONDS );
			$this->redirect( __( 'Datei gelesen. Bitte Spalten zuordnen.', 'lindenweg16' ), 'success', array( 'step' => 'map', 'sheet' => $sheet ) );
		}

		$result = $this->process( $sheet, $rows, $mapping, $confirm );

		if ( ! $confirm ) {
			set_transient(
				'lw16_import_' . get_current_user_id(),
				array( 'sheet' => $sheet, 'rows' => $rows, 'map' => $mapping, 'result' => $result ),
				HOUR_IN_SECONDS
			);
			$this->redirect( __( 'Vorschau erstellt.', 'lindenweg16' ), 'success', array( 'step' => 'preview', 'sheet' => $sheet ) );
		}

		delete_transient( 'lw16_import_' . get_current_user_id() );

		$this->redirect(
			sprintf(
				/* translators: 1: importiert, 2: übersprungen. */
				__( 'Import abgeschlossen: %1$d Datensätze übernommen, %2$d übersprungen.', 'lindenweg16' ),
				$result['imported'],
				count( $result['errors'] )
			),
			'success'
		);
	}

	/**
	 * Hochgeladene CSV lesen.
	 *
	 * @return array
	 */
	protected function read_upload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- vom Aufrufer geprüft.
		if ( empty( $_FILES['csv']['tmp_name'] ) ) {
			return array();
		}

		$tmp = sanitize_text_field( wp_unslash( $_FILES['csv']['tmp_name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! is_uploaded_file( $tmp ) ) {
			return array();
		}

		$handle = fopen( $tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return array();
		}

		$rows = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, ';' ) ) ) {
			// Auch Komma-getrennte Dateien lesen.
			if ( 1 === count( $row ) && false !== strpos( (string) $row[0], ',' ) ) {
				$row = str_getcsv( (string) $row[0], ',' );
			}
			$rows[] = array_map(
				static function ( $cell ) {
					return trim( (string) $cell );
				},
				$row
			);
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $rows;
	}

	/**
	 * Zeilen prüfen und – bei Bestätigung – importieren.
	 *
	 * @param string $sheet   Blatt.
	 * @param array  $rows    Zeilen.
	 * @param array  $mapping Spaltenzuordnung Feld => Spaltenindex.
	 * @param bool   $commit  Wirklich schreiben.
	 * @return array {imported, errors, preview}
	 */
	protected function process( $sheet, array $rows, array $mapping, $commit ) {
		$imported = 0;
		$errors   = array();
		$preview  = array();

		// Erste Zeile ist die Kopfzeile.
		array_shift( $rows );

		foreach ( $rows as $index => $row ) {
			$record = array();

			foreach ( $mapping as $field => $column ) {
				if ( '' === $column ) {
					continue;
				}
				$record[ $field ] = isset( $row[ (int) $column ] ) ? $row[ (int) $column ] : '';
			}

			// Leere Zeilen der Mappe überspringen.
			if ( '' === implode( '', $record ) ) {
				continue;
			}

			$outcome = $this->import_record( $sheet, $record, $commit );

			if ( $outcome['error'] ) {
				$errors[] = sprintf(
					/* translators: 1: Zeilennummer, 2: Fehler. */
					__( 'Zeile %1$d: %2$s', 'lindenweg16' ),
					$index + 2,
					$outcome['error']
				);
			} else {
				$imported++;
			}

			if ( count( $preview ) < 10 ) {
				$preview[] = array_merge( $record, array( '_status' => $outcome['error'] ? $outcome['error'] : __( 'ok', 'lindenweg16' ) ) );
			}
		}

		return array(
			'imported' => $imported,
			'errors'   => $errors,
			'preview'  => $preview,
		);
	}

	/**
	 * Einen Datensatz importieren.
	 *
	 * @param string $sheet  Blatt.
	 * @param array  $record Werte.
	 * @param bool   $commit Schreiben.
	 * @return array {error}
	 */
	protected function import_record( $sheet, array $record, $commit ) {
		switch ( $sheet ) {
			case 'artikel':
				return $this->import_article( $record, $commit );
			case 'drucker':
				return $this->import_printer( $record, $commit );
			case 'druckjournal':
				return $this->import_job( $record, $commit );
			case 'filament':
				return $this->import_lot( $record, $commit );
			case 'zeit':
				return $this->import_time( $record, $commit );
			case 'kontakte':
				return $this->import_contact( $record, $commit );
		}

		return array( 'error' => __( 'Unbekanntes Blatt.', 'lindenweg16' ) );
	}

	/**
	 * Artikel samt erster Revision.
	 *
	 * @param array $record Werte.
	 * @param bool  $commit Schreiben.
	 * @return array
	 */
	protected function import_article( array $record, $commit ) {
		$record['sourcing_type'] = 'druck';
		$record['sellable']      = 1;
		$record['discountable']  = 1;
		$record['status']        = 'aktiv';

		$existing = ! empty( $record['sku'] ) ? ArticleRepository::find_by_sku( strtoupper( $record['sku'] ) ) : null;
		$check    = ArticleRepository::validate( $record, $existing ? (int) $existing['id'] : null );

		if ( $check['errors'] ) {
			return array( 'error' => implode( ' ', $check['errors'] ) );
		}

		if ( ! $commit ) {
			return array( 'error' => '' );
		}

		$article_id = $existing ? (int) $existing['id'] : ArticleRepository::insert( $check['data'] );

		if ( $existing ) {
			ArticleRepository::update( $article_id, $check['data'], __( 'Import aus der Datenerfassungsmappe.', 'lindenweg16' ) );
		}

		// Revision aus denselben Spalten, sofern Gewicht vorhanden ist.
		if ( $article_id && ! empty( $record['weight_g'] ) ) {
			$revision = RevisionRepository::validate(
				array_merge(
					$record,
					array(
						'article_id' => $article_id,
						'version'    => ! empty( $record['version'] ) ? $record['version'] : 'v1.0',
					)
				)
			);

			if ( ! $revision['errors'] ) {
				RevisionRepository::insert( $revision['data'] );
			}
		}

		return array( 'error' => '' );
	}

	/**
	 * Drucker.
	 *
	 * @param array $record Werte.
	 * @param bool  $commit Schreiben.
	 * @return array
	 */
	protected function import_printer( array $record, $commit ) {
		$check = PrinterRepository::validate( $record );

		if ( $check['errors'] ) {
			return array( 'error' => implode( ' ', $check['errors'] ) );
		}

		if ( $commit ) {
			PrinterRepository::insert( $check['data'] );
		}

		return array( 'error' => '' );
	}

	/**
	 * Druckjob.
	 *
	 * @param array $record Werte.
	 * @param bool  $commit Schreiben.
	 * @return array
	 */
	protected function import_job( array $record, $commit ) {
		$printer = ! empty( $record['printer_code'] ) ? PrinterRepository::find_by_code( strtoupper( $record['printer_code'] ) ) : null;
		$article = ! empty( $record['sku'] ) ? ArticleRepository::find_by_sku( strtoupper( $record['sku'] ) ) : null;

		if ( ! $printer ) {
			return array( 'error' => __( 'Drucker nicht gefunden – zuerst Blatt 04 importieren.', 'lindenweg16' ) );
		}
		if ( ! $article ) {
			return array( 'error' => __( 'Artikel nicht gefunden – zuerst Blatt 01 importieren.', 'lindenweg16' ) );
		}

		$lot = ! empty( $record['lot_code'] ) ? $this->find_lot( $record['lot_code'] ) : null;

		$record['printer_id']      = (int) $printer['id'];
		$record['article_id']      = (int) $article['id'];
		$record['filament_lot_id'] = $lot ? (int) $lot['id'] : 0;

		// Die Mappe kennt „—" als leere Ursache.
		if ( empty( $record['failure_reason'] ) || '—' === $record['failure_reason'] ) {
			$record['failure_reason'] = 'keine';
		}

		$check = PrintJobRepository::validate( $record );

		if ( $check['errors'] ) {
			return array( 'error' => implode( ' ', $check['errors'] ) );
		}

		if ( $commit ) {
			PrintJobRepository::insert( $check['data'] );
		}

		return array( 'error' => '' );
	}

	/**
	 * Filamentcharge.
	 *
	 * @param array $record Werte.
	 * @param bool  $commit Schreiben.
	 * @return array
	 */
	protected function import_lot( array $record, $commit ) {
		$check = FilamentRepository::validate( $record );

		if ( $check['errors'] ) {
			return array( 'error' => implode( ' ', $check['errors'] ) );
		}

		if ( $commit ) {
			FilamentRepository::insert( $check['data'] );
		}

		return array( 'error' => '' );
	}

	/**
	 * Zeiteintrag.
	 *
	 * @param array $record Werte.
	 * @param bool  $commit Schreiben.
	 * @return array
	 */
	protected function import_time( array $record, $commit ) {
		// Die Mappe benutzt Klartextkategorien wie „Verwaltung/Recht".
		if ( ! empty( $record['category'] ) ) {
			$record['category'] = self::map_category( $record['category'] );
		}

		$check = TimeRepository::validate( $record );

		if ( $check['errors'] ) {
			return array( 'error' => implode( ' ', $check['errors'] ) );
		}

		if ( $commit ) {
			TimeRepository::insert( $check['data'] );
		}

		return array( 'error' => '' );
	}

	/**
	 * Kontakt.
	 *
	 * @param array $record Werte.
	 * @param bool  $commit Schreiben.
	 * @return array
	 */
	protected function import_contact( array $record, $commit ) {
		if ( ! empty( $record['type'] ) ) {
			$record['type'] = self::map_contact_type( $record['type'] );
		}

		if ( ! $commit ) {
			return array( 'error' => empty( $record['company'] ) ? __( 'Firma fehlt.', 'lindenweg16' ) : '' );
		}

		$result = ContactRepository::save( $record );

		return array( 'error' => $result['errors'] ? implode( ' ', $result['errors'] ) : '' );
	}

	/**
	 * Filamentcharge über ihren Code suchen.
	 *
	 * @param string $code Code.
	 * @return array|null
	 */
	protected function find_lot( $code ) {
		foreach ( FilamentRepository::all() as $lot ) {
			if ( strtoupper( $lot['lot_code'] ) === strtoupper( $code ) ) {
				return $lot;
			}
		}
		return null;
	}

	/**
	 * Kategorien der Mappe auf die des Moduls abbilden.
	 *
	 * @param string $raw Rohwert.
	 * @return string
	 */
	public static function map_category( $raw ) {
		$raw = strtolower( (string) $raw );

		foreach ( array(
			'produktion'   => 'produktion',
			'druck'        => 'produktion',
			'versand'      => 'versand',
			'konstruktion' => 'konstruktion',
			'cad'          => 'konstruktion',
			'marketing'    => 'marketing',
			'vertrieb'     => 'vertrieb',
			'verwaltung'   => 'verwaltung',
			'recht'        => 'verwaltung',
			'wartung'      => 'wartung',
		) as $needle => $category ) {
			if ( false !== strpos( $raw, $needle ) ) {
				return $category;
			}
		}

		return 'sonstiges';
	}

	/**
	 * Kontakttypen der Mappe abbilden.
	 *
	 * @param string $raw Rohwert.
	 * @return string
	 */
	public static function map_contact_type( $raw ) {
		$raw = strtolower( (string) $raw );

		foreach ( array(
			'händler' => 'haendler',
			'haendler' => 'haendler',
			'verein'  => 'verein',
			'presse'  => 'presse',
			'kommune' => 'kommune',
			'firma'   => 'firma',
		) as $needle => $type ) {
			if ( false !== strpos( $raw, $needle ) ) {
				return $type;
			}
		}

		return 'lead';
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type Typ.
	 * @param array  $args Zusatzparameter.
	 */
	protected function redirect( $msg, $type, array $args = array() ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'     => 'lw16-betrieb-import',
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
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		// phpcs:disable WordPress.Security.NonceVerification
		$step  = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'upload';
		$sheet = isset( $_GET['sheet'] ) ? sanitize_key( wp_unslash( $_GET['sheet'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Import & Verfahrensdokumentation', 'lindenweg16' ) . '</h1>';

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_msg'] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success',
				esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification

		$stored = get_transient( 'lw16_import_' . get_current_user_id() );

		if ( 'map' === $step && $stored ) {
			$this->render_mapping( $sheet, $stored );
		} elseif ( 'preview' === $step && $stored ) {
			$this->render_preview( $sheet, $stored );
		} else {
			$this->render_upload();
		}

		$this->render_docs();

		echo '</div>';
	}

	/**
	 * Schritt 1: Datei hochladen.
	 */
	protected function render_upload() {
		echo '<h2>' . esc_html__( 'Schritt 1: Blatt hochladen', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Jedes Blatt der Mappe als CSV exportieren (Semikolon oder Komma) und hier einzeln hochladen. Reihenfolge: Artikel, Drucker, Filament, dann Druckjournal.', 'lindenweg16' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_import" />';
		echo '<p><select name="sheet">';

		foreach ( self::sheets() as $key => $sheet ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $sheet['label'] ) );
		}

		echo '</select> <input type="file" name="csv" accept=".csv,text/csv" /> ';
		printf( '<button class="button button-primary">%s</button></p></form>', esc_html__( 'Datei lesen', 'lindenweg16' ) );
	}

	/**
	 * Schritt 2: Spalten zuordnen.
	 *
	 * @param string $sheet  Blatt.
	 * @param array  $stored Zwischenstand.
	 */
	protected function render_mapping( $sheet, array $stored ) {
		$sheets = self::sheets();

		if ( ! isset( $sheets[ $sheet ] ) || empty( $stored['rows'] ) ) {
			$this->render_upload();
			return;
		}

		$header = $stored['rows'][0];

		echo '<h2>' . esc_html__( 'Schritt 2: Spalten zuordnen', 'lindenweg16' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_import" />';
		printf( '<input type="hidden" name="sheet" value="%s" />', esc_attr( $sheet ) );

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $sheets[ $sheet ]['fields'] as $field => $label ) {
			printf( '<tr><th scope="row">%s</th><td><select name="map[%s]">', esc_html( $label ), esc_attr( $field ) );
			printf( '<option value="">%s</option>', esc_html__( '– nicht importieren –', 'lindenweg16' ) );

			foreach ( $header as $index => $column ) {
				// Gleichnamige Spalten vorauswählen.
				$selected = 0 === strcasecmp( trim( (string) $column ), trim( strip_tags( $label ) ) ) ? ' selected' : '';
				printf(
					'<option value="%d"%s>%s</option>',
					(int) $index,
					esc_attr( $selected ),
					esc_html( '' !== $column ? $column : sprintf( /* translators: %d: Spaltennummer. */ __( 'Spalte %d', 'lindenweg16' ), $index + 1 ) )
				);
			}

			echo '</select></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Die Datei bitte erneut auswählen – sie wird aus Sicherheitsgründen nicht zwischengespeichert.', 'lindenweg16' ) . '</p>';
		echo '<p><input type="file" name="csv" accept=".csv,text/csv" /> ';
		printf( '<button class="button button-primary">%s</button></p></form>', esc_html__( 'Vorschau erstellen', 'lindenweg16' ) );
	}

	/**
	 * Schritt 3: Vorschau und Bestätigung.
	 *
	 * @param string $sheet  Blatt.
	 * @param array  $stored Zwischenstand.
	 */
	protected function render_preview( $sheet, array $stored ) {
		if ( empty( $stored['result'] ) ) {
			$this->render_upload();
			return;
		}

		$result = $stored['result'];

		echo '<h2>' . esc_html__( 'Schritt 3: Vorschau', 'lindenweg16' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: gültig, 2: fehlerhaft. */
					__( '%1$d Zeilen sind gültig, %2$d haben Fehler.', 'lindenweg16' ),
					$result['imported'],
					count( $result['errors'] )
				)
			)
		);

		if ( $result['errors'] ) {
			echo '<div class="notice notice-warning inline"><ul style="list-style:disc;margin-left:20px">';
			foreach ( array_slice( $result['errors'], 0, 20 ) as $error ) {
				printf( '<li>%s</li>', esc_html( $error ) );
			}
			echo '</ul></div>';
		}

		if ( $result['preview'] ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array_keys( $result['preview'][0] ) as $column ) {
				echo '<th>' . esc_html( $column ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $result['preview'] as $row ) {
				echo '<tr>';
				foreach ( $row as $value ) {
					printf( '<td>%s</td>', esc_html( (string) $value ) );
				}
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_import" />';
		printf( '<input type="hidden" name="sheet" value="%s" />', esc_attr( $sheet ) );
		echo '<input type="hidden" name="confirm" value="1" />';

		foreach ( (array) $stored['map'] as $field => $column ) {
			printf( '<input type="hidden" name="map[%s]" value="%s" />', esc_attr( $field ), esc_attr( $column ) );
		}

		echo '<p><input type="file" name="csv" accept=".csv,text/csv" /> ';
		printf( '<button class="button button-primary">%s</button></p></form>', esc_html__( 'Import jetzt durchführen', 'lindenweg16' ) );
	}

	/**
	 * Verfahrensdokumentation.
	 */
	protected function render_docs() {
		echo '<hr /><h2>' . esc_html__( 'Verfahrensdokumentation', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Beschreibt Konfiguration, Datenmodell, Berechnungsformeln und Prozesse des Moduls. Das Dokument liest die Werte beim Erzeugen aus dem System – es ist keine Abschrift, sondern der tatsächliche Stand.', 'lindenweg16' ) . '</p>';

		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lw16_betrieb_verfahren' ), 'lw16_betrieb_verfahren' ) ),
			esc_html__( 'Als PDF erzeugen', 'lindenweg16' )
		);

		echo '<h3>' . esc_html__( 'Vorschau', 'lindenweg16' ) . '</h3><div class="lw16-doku">';

		foreach ( VerfahrensDokuBetrieb::lines() as $line ) {
			list( $type, $text ) = $line;

			if ( 'h1' === $type ) {
				printf( '<h4>%s</h4>', esc_html( $text ) );
			} elseif ( 'h2' === $type ) {
				printf( '<h5>%s</h5>', esc_html( $text ) );
			} else {
				printf( '<p>%s</p>', esc_html( $text ) );
			}
		}

		echo '</div>';
	}
}
