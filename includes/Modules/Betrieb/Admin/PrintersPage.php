<?php
/**
 * Geräteliste der Druckfarm: anlegen, bearbeiten, Stundensatz nachvollziehen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;
use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\CalcSettings;
use Lindenweg16\Modules\Betrieb\DeviceLabel;
use Lindenweg16\Modules\Betrieb\LabelSheet;
use Lindenweg16\Modules\Betrieb\MaintenanceChecklist;
use Lindenweg16\Modules\Betrieb\MaintenanceRepository;
use Lindenweg16\Modules\Betrieb\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\MachineRate;
use Lindenweg16\Modules\Betrieb\PrinterRepository;

/**
 * Rendert die Seite „Drucker & Wartung" und verarbeitet ihre Formulare.
 */
class PrintersPage {

	const NONCE = 'lw16_betrieb_printer';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_drucker', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_save_printer', array( $this, 'handle_save' ) );
		add_action( 'admin_post_lw16_betrieb_flush_routes', array( $this, 'handle_flush' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Die Checklisten-Steuerung nur auf dieser Seite laden.
	 *
	 * @param string $hook Aktueller Admin-Hook.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'lw16-betrieb-drucker' ) ) {
			return;
		}

		wp_enqueue_script(
			'lw16-betrieb-wartung',
			LW16_PLUGIN_URL . 'assets/js/betrieb-wartung.js',
			array(),
			Assets::version( 'assets/js/betrieb-wartung.js' ),
			true
		);
	}

	/**
	 * Adressen neu registrieren.
	 *
	 * Rewrite-Regeln stehen in einer Zwischenablage der Datenbank. Kommt eine
	 * Regel durch ein Update dazu, ohne dass diese Ablage neu geschrieben
	 * wird, laeuft die Adresse ins Leere. Genau das raeumt dieser Knopf auf –
	 * er ist die Kurzfassung von „Permalinks einmal speichern".
	 */
	public function handle_flush() {
		$this->require_cap();
		check_admin_referer( self::NONCE . '_flush' );

		flush_rewrite_rules();

		$this->redirect( __( 'Adressen neu registriert. Der Link vom Aufkleber sollte jetzt aufgehen.', 'lindenweg16' ), 'success', 0 );
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Gerät speichern.
	 */
	public function handle_save() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$id     = isset( $_POST['printer_id'] ) ? (int) $_POST['printer_id'] : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$input  = array_map(
			static function ( $value ) {
				return is_string( $value ) ? wp_unslash( $value ) : $value;
			},
			(array) $_POST
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = PrinterRepository::validate( $input, $id ? $id : null );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', $id );
		}

		if ( $id ) {
			PrinterRepository::update( $id, $result['data'], $reason );
			$this->redirect( __( 'Gerät gespeichert.', 'lindenweg16' ), 'success', 0 );
		}

		$new_id = PrinterRepository::insert( $result['data'] );
		if ( ! $new_id ) {
			$this->redirect( __( 'Gerät konnte nicht angelegt werden.', 'lindenweg16' ), 'error', 0 );
		}
		$this->redirect( __( 'Gerät angelegt.', 'lindenweg16' ), 'success', 0 );
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type success oder error.
	 * @param int    $edit Bei Fehler die ID zum Weiterbearbeiten.
	 */
	protected function redirect( $msg, $type, $edit = 0 ) {
		$args = array(
			'page'     => 'lw16-betrieb-drucker',
			'lw16_msg' => rawurlencode( $msg ),
			'lw16_typ' => $type,
		);
		if ( $edit ) {
			$args['edit'] = (int) $edit;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		// phpcs:disable WordPress.Security.NonceVerification
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification

		$printers = PrinterRepository::all();
		$settings = CalcSettings::for_date();
		$edit     = $edit_id ? PrinterRepository::find( $edit_id ) : null;

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( '3D-Drucker & Wartung', 'lindenweg16' ) . '</h1>';
		$this->render_notice();

		$this->render_list( $printers, $settings );
		$this->render_form( $edit, $settings );

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
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $msg )
		);
	}

	/**
	 * Geräteliste mit aufgeschlüsseltem Stundensatz.
	 *
	 * @param array $printers Geräte.
	 * @param array $settings Annahmen.
	 */
	protected function render_list( array $printers, array $settings ) {
		$labels = PrinterRepository::status_labels();

		echo '<h2>' . esc_html__( 'Geräte', 'lindenweg16' ) . '</h2>';

		if ( ! $printers ) {
			echo '<p>' . esc_html__( 'Noch keine Geräte erfasst. Solange hier nichts steht, fehlt in jeder Kalkulation die Abschreibung.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Kürzel', 'lindenweg16' ),
			__( 'Hersteller / Typ', 'lindenweg16' ),
			__( 'Status', 'lindenweg16' ),
			__( 'Anschaffung', 'lindenweg16' ),
			__( 'Betriebsstunden', 'lindenweg16' ),
			__( 'Abschreibung €/h', 'lindenweg16' ),
			__( 'Verschleiß €/h', 'lindenweg16' ),
			__( 'Energie €/h', 'lindenweg16' ),
			__( 'Fläche €/h', 'lindenweg16' ),
			__( 'Satz €/h', 'lindenweg16' ),
			'',
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $printers as $printer ) {
			$b        = MachineRate::breakdown( $printer, $settings );
			$produktiv = in_array( $printer['status'], MachineRate::PRODUCTIVE_STATUS, true );

			echo '<tr>';
			printf( '<td><strong>%s</strong></td>', esc_html( $printer['code'] ) );
			printf(
				'<td>%s</td>',
				esc_html( trim( (string) $printer['manufacturer'] . ' ' . (string) $printer['model'] ) )
			);
			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
				esc_attr( $produktiv ? 'gruen' : 'rot' ),
				esc_html( isset( $labels[ $printer['status'] ] ) ? $labels[ $printer['status'] ] : $printer['status'] )
			);
			printf(
				'<td>%s</td>',
				esc_html( null === $printer['acquisition_cost'] ? '—' : number_format_i18n( (float) $printer['acquisition_cost'], 2 ) . ' €' )
			);
			printf(
				'<td>%s h</td>',
				esc_html( number_format_i18n( PrintJobRepository::operating_hours( (int) $printer['id'] ), 1 ) )
			);
			foreach ( array( 'abschreibung', 'verschleiss', 'energie', 'flaeche' ) as $part ) {
				printf( '<td>%s</td>', esc_html( number_format_i18n( $b[ $part ], 4 ) ) );
			}
			printf( '<td><strong>%s</strong></td>', esc_html( number_format_i18n( $b['summe'], 4 ) ) );
			printf(
				'<td><a href="%s">%s</a></td>',
				esc_url( add_query_arg( array( 'page' => 'lw16-betrieb-drucker', 'edit' => (int) $printer['id'] ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Bearbeiten', 'lindenweg16' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		$this->render_maintenance( $printers );
		$this->render_labels( $printers );

		$fleet = MachineRate::fleet_breakdown( $printers, $settings );
		printf(
			'<p>%s <strong>%s €/h</strong> — %s</p>',
			esc_html__( 'Flottenmittel für die Artikelkalkulation:', 'lindenweg16' ),
			esc_html( number_format_i18n( $fleet['rate'], 4 ) ),
			esc_html(
				sprintf(
					/* translators: %d: Anzahl Geräte. */
					_n( '%d produzierendes Gerät (in Betrieb oder Wartung).', '%d produzierende Geräte (in Betrieb oder Wartung).', (int) $fleet['printer_count'], 'lindenweg16' ),
					(int) $fleet['printer_count']
				)
			)
		);
	}

	/**
	 * Wartungsfälligkeiten und Erfassung.
	 *
	 * @param array $printers Geräte.
	 */
	protected function render_maintenance( array $printers ) {
		echo '<h2>' . esc_html__( 'Wartung', 'lindenweg16' ) . '</h2>';

		$due = array_filter(
			MaintenanceRepository::due(),
			static function ( $row ) {
				return ! empty( $row['reasons'] );
			}
		);

		if ( $due ) {
			echo '<div class="notice notice-warning inline"><ul style="list-style:disc;margin-left:20px">';
			foreach ( $due as $row ) {
				printf(
					'<li><strong>%s</strong>: %s</li>',
					esc_html( $row['printer']['code'] ),
					esc_html( implode( '; ', $row['reasons'] ) )
				);
			}
			echo '</ul></div>';
		} else {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Keine Wartung fällig.', 'lindenweg16' ) . '</p></div>';
		}

		echo '<form method="post" id="lw16-wartung" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lw16_betrieb_pages' );
		echo '<input type="hidden" name="action" value="lw16_betrieb_maintenance" />';
		echo '<p><select name="printer_id">';
		foreach ( $printers as $printer ) {
			printf( '<option value="%d">%s</option>', (int) $printer['id'], esc_html( $printer['code'] ) );
		}
		echo '</select> <select name="interval_type" id="lw16-wartung-intervall">';
		foreach ( MaintenanceChecklist::intervals() as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select> ';
		printf( '<input type="date" name="performed_on" value="%s" /> ', esc_attr( gmdate( 'Y-m-d' ) ) );
		printf( '<input type="text" name="part_replaced" placeholder="%s" size="18" /> ', esc_attr__( 'Ersatzteil', 'lindenweg16' ) );
		printf(
			'<label><input type="checkbox" name="nozzle_changed" value="1" /> %s</label>',
			esc_html__( 'Düse gewechselt', 'lindenweg16' )
		);
		echo '</p>';

		$this->render_checklists();

		printf(
			'<p><button class="button button-primary" id="lw16-wartung-senden">%s</button> <span id="lw16-wartung-stand" class="description" data-offen="%s" data-fertig="%s" data-frei="%s"></span></p></form>',
			esc_html__( 'Wartung erfassen', 'lindenweg16' ),
			esc_attr__( '%1 von %2 Punkten erledigt.', 'lindenweg16' ),
			esc_attr__( 'Alle Punkte erledigt.', 'lindenweg16' ),
			esc_attr__( 'Anlassbezogen – hier gibt es keine feste Liste. Was gemacht wurde, gehört ins Feld Ersatzteil oder in die Notiz.', 'lindenweg16' )
		);
	}

	/**
	 * Die Checklisten aller Intervalle. Sichtbar ist immer nur eine; ohne
	 * JavaScript stehen alle da und der Server prüft beim Speichern.
	 */
	protected function render_checklists() {
		echo '<div class="lw16-checkliste">';

		foreach ( MaintenanceChecklist::intervals() as $key => $label ) {
			$items   = MaintenanceChecklist::items( $key );
			$pflicht = MaintenanceChecklist::requires_all( $key );

			printf(
				'<fieldset data-intervall="%s" data-pflicht="%d"><legend>%s</legend>',
				esc_attr( $key ),
				$pflicht ? 1 : 0,
				esc_html(
					sprintf(
						/* translators: %s: Intervall. */
						__( 'Checkliste %s', 'lindenweg16' ),
						$label
					)
				)
			);

			if ( ! $items ) {
				printf(
					'<p class="description">%s</p>',
					esc_html__( 'Für eine anlassbezogene Wartung gibt es keine feste Liste – was gemacht wurde, gehört in Ersatzteil und Notiz.', 'lindenweg16' )
				);
				echo '</fieldset>';
				continue;
			}

			if ( 'woechentlich' !== $key ) {
				printf(
					'<p class="description">%s</p>',
					esc_html__( 'Enthält die Punkte der kürzeren Intervalle: Wer die Achsen schmiert, saugt vorher aus.', 'lindenweg16' )
				);
			}

			echo '<ul class="lw16-checkliste__punkte">';
			foreach ( $items as $item_key => $text ) {
				printf(
					'<li><label><input type="checkbox" name="tasks[]" value="%s" /> %s</label></li>',
					esc_attr( $item_key ),
					esc_html( $text )
				);
			}
			echo '</ul></fieldset>';
		}

		echo '</div>';
	}

	/**
	 * Geräteaufkleber zum Ausdrucken und Ankleben.
	 *
	 * @param array $printers Geräte.
	 */
	protected function render_labels( array $printers ) {
		echo '<h2>' . esc_html__( 'Aufkleber für die Geräte', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ausdrucken und ans Gerät kleben. Die URL öffnet die Erfassung mit vorausgewähltem Drucker – am Handy genügen dann fünf Eingaben.', 'lindenweg16' ) . '</p>';

		$this->render_route_hint();

		$this->render_label_pdf_link();

		echo '<div class="lw16-labels">';
		foreach ( $printers as $printer ) {
			echo DeviceLabel::render( $printer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapt in render().
		}
		echo '</div>';
	}

	/**
	 * Dieselben Aufkleber für den Etikettendrucker.
	 *
	 * Die Kacheln darunter sind zum Ausdrucken auf Papier und Ankleben. Wer
	 * einen Brother QL stehen hat, will sie auf der Rolle – und zwar im
	 * selben Format wie Lagerplätze und Artikel, damit nicht für jede Sorte
	 * eine andere Rolle eingelegt werden muss.
	 */
	protected function render_label_pdf_link() {
		$roll = LabelSheet::roll( LabelSheet::DEFAULT_ROLL );

		echo '<p>';
		printf(
			'<a class="button button-primary" href="%s">%s</a> ',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'lw16_betrieb_labels',
							'was[]'  => 'printers',
							'format' => LabelSheet::DEFAULT_ROLL,
						),
						admin_url( 'admin-post.php' )
					),
					'lw16_betrieb_export'
				)
			),
			esc_html__( 'Aufkleber als PDF für den Etikettendrucker', 'lindenweg16' )
		);
		printf(
			'<span class="description">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: Rollenformat. */
					__( 'Format %s – dasselbe wie bei Lagerplätzen und Artikeln. Eine Seite ist ein Etikett; im Druckdialog den Brother wählen und die Skalierung ausschalten.', 'lindenweg16' ),
					$roll['label']
				)
			)
		);
		echo '</p>';
	}

	/**
	 * Hinweis und Reparaturknopf, wenn die Erfassungsadresse nicht greifen kann.
	 */
	protected function render_route_hint() {
		if ( ! DeviceLabel::pretty_permalinks() ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'Diese Seite nutzt keine sprechenden Permalinks. Die Erfassung ist deshalb nur über die lange Adresse mit Fragezeichen erreichbar – so steht sie auch auf dem Aufkleber. Kürzer wird sie, sobald unter Einstellungen → Permalinks eine Struktur wie „Beitragsname" gewählt ist.', 'lindenweg16' );
			echo '</p></div>';
			return;
		}

		echo '<div class="notice notice-info inline"><p>';
		echo esc_html__( 'Führt der Link zu „Seite nicht gefunden“, sind die Adressen nach einem Update noch nicht neu registriert. Ein Klick genügt:', 'lindenweg16' );
		echo ' </p><p>';
		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=lw16_betrieb_flush_routes' ),
					self::NONCE . '_flush'
				)
			),
			esc_html__( 'Adressen neu registrieren', 'lindenweg16' )
		);
		echo '</p></div>';
	}

	/**
	 * Formular zum Anlegen oder Bearbeiten.
	 *
	 * @param array|null $edit     Gerät oder null.
	 * @param array      $settings Annahmen für die Platzhalter.
	 */
	protected function render_form( $edit, array $settings ) {
		$v = static function ( $row, $key ) {
			return isset( $row[ $key ] ) && null !== $row[ $key ] ? (string) $row[ $key ] : '';
		};

		echo '<h2>' . esc_html( $edit ? __( 'Gerät bearbeiten', 'lindenweg16' ) : __( 'Gerät hinzufügen', 'lindenweg16' ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Leer gelassene Felder greifen auf die globale Annahme zurück. Der Stundensatz wird daraus berechnet – er wird nie von Hand gesetzt.', 'lindenweg16' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-form">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_save_printer" />';
		printf( '<input type="hidden" name="printer_id" value="%d" />', $edit ? (int) $edit['id'] : 0 );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->field( __( 'Gerätekürzel', 'lindenweg16' ), 'code', $v( $edit, 'code' ), 'text', __( 'Pflicht, eindeutig. Erscheint auf dem QR-Aufkleber am Gerät, z. B. P1.', 'lindenweg16' ) );
		$this->field( __( 'Hersteller', 'lindenweg16' ), 'manufacturer', $v( $edit, 'manufacturer' ), 'text', __( 'z. B. Bambu Lab', 'lindenweg16' ) );
		$this->field( __( 'Typ', 'lindenweg16' ), 'model', $v( $edit, 'model' ), 'text', __( 'z. B. P1S', 'lindenweg16' ) );
		$this->field( __( 'Seriennummer', 'lindenweg16' ), 'serial', $v( $edit, 'serial' ), 'text' );

		// Status.
		echo '<tr><th scope="row"><label for="lw16-status">' . esc_html__( 'Status', 'lindenweg16' ) . '</label></th><td><select name="status" id="lw16-status">';
		$current = $edit ? $edit['status'] : 'betrieb';
		foreach ( PrinterRepository::status_labels() as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select><p class="description">' . esc_html__( 'Nur Geräte in Betrieb oder Wartung gehen in den Flottensatz ein.', 'lindenweg16' ) . '</p></td></tr>';

		$this->field( __( 'Angeschafft am', 'lindenweg16' ), 'acquired_on', $v( $edit, 'acquired_on' ), 'date' );
		$this->field( __( 'Anschaffungspreis (€)', 'lindenweg16' ), 'acquisition_cost', $v( $edit, 'acquisition_cost' ), 'text', __( 'Kaufpreis netto. Grundlage der Abschreibung.', 'lindenweg16' ) );
		$this->field(
			__( 'Lebensdauer (Betriebsstunden)', 'lindenweg16' ),
			'lifetime_h',
			$v( $edit, 'lifetime_h' ),
			'text',
			sprintf(
				/* translators: %s: globale Annahme. */
				__( 'Leer = globale Annahme (%s h). Anschaffungspreis geteilt durch diesen Wert ergibt die Abschreibung je Stunde.', 'lindenweg16' ),
				number_format_i18n( $settings['printer_lifetime_h'], 0 )
			)
		);
		$this->field(
			__( 'Stromkosten (€/h)', 'lindenweg16' ),
			'power_cost_per_h',
			$v( $edit, 'power_cost_per_h' ),
			'text',
			sprintf(
				/* translators: %s: errechneter Vorgabewert. */
				__( 'Direkt gemessener Wert. Leer = aus Leistung × Strompreis gerechnet (derzeit %s €/h).', 'lindenweg16' ),
				number_format_i18n( (float) $settings['printer_power_kw'] * (float) $settings['power_price_per_kwh'], 4 )
			)
		);
		$this->field( __( 'Leistungsaufnahme (kW)', 'lindenweg16' ), 'power_kw', $v( $edit, 'power_kw' ), 'text', __( 'Alternative zu den Stromkosten: wird mit dem globalen Strompreis multipliziert. Wird ignoriert, wenn oben ein Eurobetrag steht.', 'lindenweg16' ) );
		$this->field(
			__( 'Ersatzteilkosten (€/Jahr)', 'lindenweg16' ),
			'spare_parts_per_year',
			$v( $edit, 'spare_parts_per_year' ),
			'text',
			sprintf(
				/* translators: %s: globale Annahme. */
				__( 'Düsen, Riemen, Platten, Lüfter. Leer = globale Annahme (%s € pro Jahr).', 'lindenweg16' ),
				number_format_i18n( $settings['spare_parts_per_year'], 2 )
			)
		);
		$this->field(
			__( 'Betriebsstunden je Jahr', 'lindenweg16' ),
			'annual_operating_hours',
			$v( $edit, 'annual_operating_hours' ),
			'text',
			sprintf(
				/* translators: %s: globale Annahme. */
				__( 'Nenner für die Ersatzteilumlage. Leer = globale Annahme (%s h). Läuft das Gerät tatsächlich weniger, ist der Anteil je Teil höher als hier ausgewiesen.', 'lindenweg16' ),
				number_format_i18n( $settings['annual_operating_hours'], 0 )
			)
		);
		$this->field( __( 'Düsendurchmesser (mm)', 'lindenweg16' ), 'nozzle_mm', $v( $edit, 'nozzle_mm' ), 'text' );
		$this->field( __( 'Betriebsstunden beim letzten Düsenwechsel', 'lindenweg16' ), 'hours_at_last_nozzle_change', $v( $edit, 'hours_at_last_nozzle_change' ), 'text', __( 'Grundlage der Wartungsfälligkeit (Düsentausch nach 500–800 h).', 'lindenweg16' ) );

		// Notizen.
		echo '<tr><th scope="row"><label for="lw16-notes">' . esc_html__( 'Bemerkung', 'lindenweg16' ) . '</label></th>';
		printf(
			'<td><textarea name="notes" id="lw16-notes" rows="3" class="large-text">%s</textarea></td></tr>',
			esc_textarea( $v( $edit, 'notes' ) )
		);

		if ( $edit ) {
			$this->field( __( 'Begründung der Änderung', 'lindenweg16' ), 'reason', '', 'text', __( 'Erscheint im Protokoll.', 'lindenweg16' ) );
		}

		echo '</tbody></table>';

		printf(
			'<p><button class="button button-primary">%s</button> ',
			esc_html( $edit ? __( 'Änderungen speichern', 'lindenweg16' ) : __( 'Gerät anlegen', 'lindenweg16' ) )
		);
		if ( $edit ) {
			printf(
				'<a class="button" href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=lw16-betrieb-drucker' ) ),
				esc_html__( 'Abbrechen', 'lindenweg16' )
			);
		}
		echo '</p></form>';
	}

	/**
	 * Ein Formularfeld ausgeben.
	 *
	 * @param string $label       Beschriftung.
	 * @param string $name        Feldname.
	 * @param string $value       Wert.
	 * @param string $type        Eingabetyp.
	 * @param string $description Hilfetext.
	 */
	protected function field( $label, $name, $value, $type = 'text', $description = '' ) {
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
}
