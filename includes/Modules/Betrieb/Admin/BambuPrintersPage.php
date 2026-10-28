<?php
/**
 * Admin-Übersicht der Bambu-Lab-Anbindung: Drucker.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\Bambu\BambuJobRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuService;
use Lindenweg16\Modules\Betrieb\PrinterRepository;

/**
 * „Bambu-Lab-Drucker": Registrierte Drucker samt Live-Status, Token-
 * Verwaltung und manuellem Einreihen von Druckdateien.
 */
class BambuPrintersPage {

	const SLUG  = 'lw16-betrieb-bambu';
	const NONCE = 'lw16_bambu_printers';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_bambu', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_bambu_add_printer', array( $this, 'handle_add' ) );
		add_action( 'admin_post_lw16_bambu_save_printer', array( $this, 'handle_save' ) );
		add_action( 'admin_post_lw16_bambu_regen_token', array( $this, 'handle_regen' ) );
		add_action( 'admin_post_lw16_bambu_delete_printer', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_lw16_bambu_enqueue_job', array( $this, 'handle_enqueue_job' ) );
		add_action( 'admin_post_lw16_bambu_enqueue_model', array( $this, 'handle_enqueue_model' ) );
		add_action( 'admin_post_lw16_bambu_snapshot', array( $this, 'handle_snapshot' ) );
		add_action( 'wp_ajax_lw16_bambu_cards', array( $this, 'handle_ajax_cards' ) );
	}

	/**
	 * Zugriff prüfen.
	 *
	 * @param string $cap Benötigte Capability.
	 */
	protected function require_cap( $cap = 'lw16_capture' ) {
		if ( ! Roles::current_user_can_betrieb( $cap ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Darf der aktuelle Nutzer die Drucker verwalten?
	 *
	 * Maßgeblich für alles, was Verbindungsgeheimnisse zeigt oder ändert:
	 * Der LAN-Zugangscode ist faktisch das Passwort des Druckers – wer ihn
	 * kennt, kann im selben Netz Dateien auf das Gerät legen und Drucke
	 * auslösen, ganz ohne dieses Plugin. Deshalb sehen ihn nur Nutzer, die
	 * die Drucker auch verwalten dürfen; zum Einreihen eines Druckauftrags
	 * (lw16_capture) wird er nicht gebraucht.
	 *
	 * @return bool
	 */
	protected function can_manage() {
		return Roles::current_user_can_betrieb( 'lw16_manage_production' );
	}

	/**
	 * Neuen Drucker anlegen.
	 */
	public function handle_add() {
		$this->require_cap( 'lw16_manage_production' );
		check_admin_referer( self::NONCE );

		$printer_id   = isset( $_POST['printer_id'] ) ? sanitize_key( wp_unslash( $_POST['printer_id'] ) ) : '';
		$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$bambu_serial = isset( $_POST['bambu_serial'] ) ? sanitize_text_field( wp_unslash( $_POST['bambu_serial'] ) ) : '';
		$ip           = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
		$geraet_id    = isset( $_POST['geraet_id'] ) ? (int) $_POST['geraet_id'] : 0;

		if ( '' === $printer_id || '' === $name ) {
			$this->back( __( 'Drucker-ID und Name sind Pflicht.', 'lindenweg16' ), 0 );
		}

		if ( BambuPrinterRepository::find_by_printer_id( $printer_id ) ) {
			$this->back( __( 'Diese Drucker-ID gibt es schon.', 'lindenweg16' ), 0 );
		}

		$result = BambuPrinterRepository::create( $printer_id, $name, $bambu_serial, $ip, $geraet_id );

		if ( ! $result['id'] ) {
			$this->back(
				$result['error']
					? sprintf(
						/* translators: %s: Datenbankfehler. */
						__( 'Drucker konnte nicht gespeichert werden: %s', 'lindenweg16' ),
						$result['error']
					)
					: __( 'Drucker konnte nicht gespeichert werden.', 'lindenweg16' ),
				0
			);
		}

		$this->back( __( 'Drucker angelegt.', 'lindenweg16' ), $result['id'], $result['token'] );
	}

	/**
	 * Verbindungsdaten eines bestehenden Druckers ändern.
	 */
	public function handle_save() {
		$this->require_cap( 'lw16_manage_production' );
		check_admin_referer( self::NONCE );

		$id = isset( $_POST['printer_pk'] ) ? (int) $_POST['printer_pk'] : 0;

		$ok = BambuPrinterRepository::update(
			$id,
			array(
				'name'         => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
				'bambu_serial' => isset( $_POST['bambu_serial'] ) ? wp_unslash( $_POST['bambu_serial'] ) : '',
				'ip'           => isset( $_POST['ip'] ) ? wp_unslash( $_POST['ip'] ) : '',
				'geraet_id'    => isset( $_POST['geraet_id'] ) ? (int) $_POST['geraet_id'] : 0,
				// Leeres Feld heißt "automatisch anhand des gemeldeten Modells"
				// (siehe BambuPrinterRepository::effective_bed_size()) - dafür
				// ausdrücklich auf null setzen, nicht auf 0.
				'bed_width_mm'  => isset( $_POST['bed_width_mm'] ) && '' !== trim( wp_unslash( $_POST['bed_width_mm'] ) ) ? (int) $_POST['bed_width_mm'] : null,
				'bed_height_mm' => isset( $_POST['bed_height_mm'] ) && '' !== trim( wp_unslash( $_POST['bed_height_mm'] ) ) ? (int) $_POST['bed_height_mm'] : null,
				'printer_model' => isset( $_POST['printer_model'] ) ? wp_unslash( $_POST['printer_model'] ) : '',
			)
		);

		if ( ! $ok ) {
			$this->back( __( 'Drucker nicht gefunden – nichts gespeichert.', 'lindenweg16' ), 0 );
		}

		$this->back( __( 'Verbindungsdaten gespeichert.', 'lindenweg16' ), 0 );
	}

	/**
	 * Token neu erzeugen – das alte wird sofort ungültig.
	 */
	public function handle_regen() {
		$this->require_cap( 'lw16_manage_production' );
		check_admin_referer( self::NONCE );

		$id    = isset( $_POST['printer_pk'] ) ? (int) $_POST['printer_pk'] : 0;
		$token = BambuPrinterRepository::regenerate_token( $id );

		if ( '' === $token ) {
			$this->back( __( 'Drucker nicht gefunden.', 'lindenweg16' ), 0 );
		}

		$this->back( __( 'Neues Token erzeugt. Es wird nur jetzt angezeigt.', 'lindenweg16' ), $id, $token );
	}

	/**
	 * Drucker löschen.
	 */
	public function handle_delete() {
		$this->require_cap( 'lw16_manage_production' );
		check_admin_referer( self::NONCE );

		$id = isset( $_POST['printer_pk'] ) ? (int) $_POST['printer_pk'] : 0;

		if ( ! BambuPrinterRepository::delete( $id ) ) {
			$this->back( __( 'Drucker nicht gefunden.', 'lindenweg16' ), 0 );
		}

		$this->back( __( 'Drucker gelöscht.', 'lindenweg16' ), 0 );
	}

	/**
	 * Druckdatei (.gcode.3mf) hochladen und in die Warteschlange legen.
	 */
	public function handle_enqueue_job() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$printer_id = isset( $_POST['printer_id'] ) ? sanitize_key( wp_unslash( $_POST['printer_id'] ) ) : '';

		if ( empty( $_FILES['datei']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['datei']['error'] ) {
			$this->back( __( 'Keine Datei hochgeladen.', 'lindenweg16' ), 0 );
		}

		$result = BambuService::enqueue_upload(
			$printer_id,
			$_FILES['datei']['tmp_name'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			sanitize_file_name( wp_unslash( $_FILES['datei']['name'] ) ),
			$this->read_ams_tray_id()
		);

		$this->back(
			$result['ok']
				? __( 'Druckauftrag angelegt. Der Bambu-Drucker holt ihn beim nächsten Kontakt der Bridge ab.', 'lindenweg16' )
				: $result['error'],
			0
		);
	}

	/**
	 * Geslicte Druckdatei einer Artikelrevision in die Warteschlange legen.
	 */
	public function handle_enqueue_model() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$printer_id = isset( $_POST['printer_id'] ) ? sanitize_key( wp_unslash( $_POST['printer_id'] ) ) : '';
		$datei      = isset( $_POST['model_file'] ) ? sanitize_file_name( wp_unslash( $_POST['model_file'] ) ) : '';

		$result = BambuService::enqueue_model( $printer_id, $datei, 0, $this->read_ams_tray_id() );

		$this->back(
			$result['ok']
				? __( 'Druckauftrag angelegt. Der Bambu-Drucker holt ihn beim nächsten Kontakt der Bridge ab.', 'lindenweg16' )
				: $result['error'],
			0
		);
	}

	/**
	 * Gewähltes AMS-Fach aus $_POST lesen - leer/nicht gesetzt bedeutet
	 * "kein AMS" (siehe render_ams_tray_select()), nicht "Fach 0".
	 *
	 * @return int|null
	 */
	protected function read_ams_tray_id() {
		// phpcs:ignore WordPress.Security.NonceVerification -- durch check_admin_referer() im Aufrufer abgedeckt.
		if ( ! isset( $_POST['ams_tray_id'] ) || '' === $_POST['ams_tray_id'] ) {
			return null;
		}

		return max( 0, min( 255, (int) $_POST['ams_tray_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Aktuelles Kamera-Standbild eines Druckers ausliefern.
	 *
	 * GET-Aufruf aus einem <img src="…"> - das kann sehr wohl eine Nonce
	 * als URL-Parameter mitschicken (siehe render_snapshot(), wp_nonce_url()),
	 * genau wie die Datei-Downloads in Exporter.php.
	 */
	public function handle_snapshot() {
		$this->require_cap();
		check_admin_referer( self::NONCE . '_snapshot' );

		// phpcs:ignore WordPress.Security.NonceVerification
		$printer_id = isset( $_GET['printer_id'] ) ? sanitize_key( wp_unslash( $_GET['printer_id'] ) ) : '';
		$printer    = $printer_id ? BambuPrinterRepository::find_by_printer_id( $printer_id ) : null;

		if ( ! $printer ) {
			wp_die( esc_html__( 'Drucker nicht gefunden.', 'lindenweg16' ), '', array( 'response' => 404 ) );
		}

		$pfad = BambuService::snapshot_path( $printer->printer_id );

		if ( ! is_file( $pfad ) ) {
			wp_die( esc_html__( 'Noch kein Kamera-Standbild vorhanden.', 'lindenweg16' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . filesize( $pfad ) );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $pfad ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Live-Status-Karten aller Drucker neu ausliefern - für das
	 * automatische Aktualisieren per AJAX (siehe assets/js/admin.js),
	 * damit Status, Temperaturen und Kamera-Standbild ohne manuelles Neu-
	 * laden aktuell bleiben.
	 */
	public function handle_ajax_cards() {
		$this->require_cap();
		check_ajax_referer( 'lw16_admin', 'nonce' );

		ob_start();
		$this->render_printer_cards_inner();
		$html = ob_get_clean();

		wp_die( $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- render_card() escapt selbst, das hier ist nur der fertige HTML-Block.
	}

	/**
	 * Zurück zur Liste, optional mit einmalig angezeigtem Klartext-Token.
	 *
	 * @param string $msg   Meldung.
	 * @param int    $ignore Ungenutzt (Kompatibilität mit anderen Seiten dieses Musters).
	 * @param string $token Klartext-Token, nur einmalig transportiert.
	 */
	protected function back( $msg, $ignore = 0, $token = '' ) {
		$args = array(
			'page'     => self::SLUG,
			'lw16_msg' => rawurlencode( $msg ),
		);

		if ( '' !== $token ) {
			$args['lw16_token'] = rawurlencode( $token );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		echo '<h1>' . esc_html__( 'Bambu-Lab-Drucker', 'lindenweg16' ) . '</h1>';

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'drucker'; // phpcs:ignore WordPress.Security.NonceVerification
		$this->render_tabs( $tab );

		if ( 'auftraege' === $tab ) {
			$base = add_query_arg( 'tab', 'auftraege', admin_url( 'admin.php?page=' . self::SLUG ) );
			$this->render_notice();
			$this->render_enqueue_form();
			( new BambuJobsPage() )->render_content( $base );
			return;
		}

		if ( 'platte' === $tab ) {
			$this->render_notice();
			( new BambuPlatesPage() )->render_content();
			return;
		}

		if ( 'slicing' === $tab ) {
			$this->render_notice();
			( new SlicingProfilesPage() )->render_content();
			return;
		}

		$this->render_notice();

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_token'] ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong><br><code style="font-size:14px;user-select:all">%s</code></p><p class="description">%s</p></div>',
				esc_html__( 'Token (wird nur jetzt angezeigt – jetzt in die .env der Bambu-Bridge auf dem Raspberry Pi eintragen):', 'lindenweg16' ),
				esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_token'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification
				esc_html__( 'Diese Seite neu laden oder verlassen, und das Token ist nicht mehr abrufbar – dann hilft nur „Token neu erzeugen".', 'lindenweg16' )
			);
		}

		if ( $this->can_manage() ) {
			$this->render_add_form();
		}

		echo '<h2>' . esc_html__( 'Registrierte Drucker', 'lindenweg16' ) . '</h2>';

		$this->render_printer_cards();
	}

	/**
	 * Live-Status-Karten aller registrierten Drucker, automatisch
	 * aktualisiert (siehe handle_ajax_cards()). Eigene Methode, weil
	 * sowohl dieser Standard-Reiter als auch die eigenständige
	 * "Druckfarm"-Übersichtsseite (siehe Fertigung → Druckfarm,
	 * DruckfarmPage) genau dieselbe Liste zeigen.
	 */
	public function render_printer_cards() {
		printf( '<div id="lw16-bambu-cards" data-lw16-autorefresh="lw16_bambu_cards" data-lw16-interval="5000">' );
		$this->render_printer_cards_inner();
		echo '</div>';
	}

	/**
	 * Nur der Karten-Inhalt, ohne den umschließenden Auto-Refresh-
	 * Container - der bleibt beim AJAX-Nachladen stehen, nur sein Inhalt
	 * wird ersetzt (siehe handle_ajax_cards()).
	 */
	protected function render_printer_cards_inner() {
		$printers = BambuPrinterRepository::all();

		if ( ! $printers ) {
			echo '<p>' . esc_html__( 'Noch kein Bambu-Drucker angelegt.', 'lindenweg16' ) . '</p>';
			return;
		}

		foreach ( $printers as $printer ) {
			$this->render_card( $printer );
		}
	}

	/**
	 * Meldung anzeigen.
	 */
	protected function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_GET['lw16_msg'] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
		);
	}

	/**
	 * Reiter „Drucker" / „Aufträge".
	 *
	 * @param string $active Aktiver Reiter.
	 */
	protected function render_tabs( $active ) {
		$base = admin_url( 'admin.php?page=' . self::SLUG );

		$tabs = array(
			'drucker'   => __( 'Drucker', 'lindenweg16' ),
			'auftraege' => __( 'Aufträge', 'lindenweg16' ),
			'platte'    => __( 'Platte anordnen', 'lindenweg16' ),
			'slicing'   => __( 'Slicing-Profile', 'lindenweg16' ),
		);

		echo '<nav class="lw16-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="%s">%s</a> ',
				esc_url( 'drucker' === $key ? $base : add_query_arg( 'tab', $key, $base ) ),
				$active === $key ? 'active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Formular für einen neuen Drucker.
	 */
	protected function render_add_form() {
		echo '<h2>' . esc_html__( 'Neuen Bambu-Drucker anlegen', 'lindenweg16' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'IP-Adresse und LAN-Zugangscode stehen am Drucker unter Einstellungen → LAN-Modus (der Drucker muss im selben Netz wie der Raspberry Pi mit der Bambu-Bridge stehen). Die Seriennummer steht darunter.', 'lindenweg16' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_bambu_add_printer">';
		wp_nonce_field( self::NONCE );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Drucker-ID', 'lindenweg16' ) . '</th><td>';
		echo '<input type="text" name="printer_id" class="regular-text" placeholder="bambu_p1s_1" pattern="[a-z0-9_\-]+" required>';
		echo '<p class="description">' . esc_html__( 'Technischer Schlüssel, den die Bridge auf dem Raspberry Pi in PRINTER_ID einträgt.', 'lindenweg16' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Name', 'lindenweg16' ) . '</th><td>';
		echo '<input type="text" name="name" class="regular-text" placeholder="P1S Werkstatt" required>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'IP-Adresse', 'lindenweg16' ) . '</th><td>';
		echo '<input type="text" name="ip" class="regular-text" placeholder="192.168.1.50">';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'LAN-Zugangscode', 'lindenweg16' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'Wird hier nicht gespeichert – nur in der .env.bambu der Bridge auf dem Raspberry Pi (BAMBU_ACCESS_CODE).', 'lindenweg16' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Bambu-Seriennummer', 'lindenweg16' ) . '</th><td>';
		echo '<input type="text" name="bambu_serial" class="regular-text" placeholder="01P00A000000000">';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Verknüpftes Gerät (Kalkulation)', 'lindenweg16' ) . '</th><td>';
		echo '<select name="geraet_id"><option value="0">' . esc_html__( '– keine Verknüpfung –', 'lindenweg16' ) . '</option>';
		foreach ( PrinterRepository::all() as $geraet ) {
			printf(
				'<option value="%d">%s</option>',
				(int) $geraet['id'],
				esc_html( $geraet['code'] . ' — ' . trim( $geraet['manufacturer'] . ' ' . $geraet['model'] ) )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Optional: verknüpft die Live-Anbindung mit dem Geräteeintrag unter „3D-Drucker & Wartung" (Stundensatz, Betriebsstunden, Wartungsfälligkeit bleiben dort gepflegt).', 'lindenweg16' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Drucker anlegen', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Formular zum manuellen Einreihen einer Druckdatei.
	 */
	protected function render_enqueue_form() {
		$printers = BambuPrinterRepository::all();

		echo '<h2>' . esc_html__( 'Druckauftrag einreihen', 'lindenweg16' ) . '</h2>';

		if ( ! $printers ) {
			echo '<p>' . esc_html__( 'Zuerst einen Bambu-Drucker anlegen (Reiter „Drucker").', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="lw16_bambu_enqueue_job">';
		wp_nonce_field( self::NONCE );
		echo '<select name="printer_id" required>';
		foreach ( $printers as $printer ) {
			printf( '<option value="%s">%s</option>', esc_attr( $printer->printer_id ), esc_html( $printer->name ) );
		}
		echo '</select> ';
		$this->render_ams_tray_select( $printers );
		echo '<input type="file" name="datei" accept=".3mf" required> ';
		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Hochladen & einreihen', 'lindenweg16' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: Höchstgröße, z. B. "200 MB". */
					__( 'Nur bereits geslicte Druckdateien (.gcode.3mf aus Bambu Studio), höchstens %s – der Drucker kann keine reinen Projektdateien ausführen.', 'lindenweg16' ),
					size_format( BambuService::MAX_FILE_BYTES )
				)
			)
		);
		echo '</form>';

		$this->render_model_form( $printers );
	}

	/**
	 * Formular zum Einreihen einer Druckdatei aus der Modellablage – der
	 * Weg für Artikel, deren geslicte Datei ohnehin schon an der aktiven
	 * Revision hängt (Artikel & Revisionen). Kein erneuter Upload nötig.
	 *
	 * @param array $printers Registrierte Bambu-Drucker.
	 */
	protected function render_model_form( $printers ) {
		$dateien = BambuService::verfuegbare_druckdateien();

		echo '<h2>' . esc_html__( 'Aus der Modellablage einreihen', 'lindenweg16' ) . '</h2>';

		if ( ! $dateien ) {
			echo '<p class="description">' . esc_html__( 'Keine aktive Artikelrevision hat eine geslicte Druckdatei hinterlegt (Artikel & Revisionen).', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_bambu_enqueue_model">';
		wp_nonce_field( self::NONCE );
		echo '<select name="printer_id" required>';
		foreach ( $printers as $printer ) {
			printf( '<option value="%s">%s</option>', esc_attr( $printer->printer_id ), esc_html( $printer->name ) );
		}
		echo '</select> ';
		echo '<select name="model_file" required>';
		foreach ( $dateien as $datei ) {
			printf( '<option value="%s">%s</option>', esc_attr( $datei['file'] ), esc_html( $datei['label'] ) );
		}
		echo '</select> ';
		$this->render_ams_tray_select( $printers );
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Einreihen', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Auswahl "welches Filament" für ein Einreih-Formular - ein Fach gilt
	 * für den gesamten Druckauftrag (kein Mehrfarb-/Mehrmaterial-Mapping
	 * pro Bauteil, siehe SlicingService::enqueue_plate()). Gruppiert nach
	 * Drucker, weil die Fach-Nummer nur zusammen mit dem im selben
	 * Formular gewählten Drucker Sinn ergibt.
	 *
	 * @param array $printers Registrierte Bambu-Drucker.
	 */
	protected function render_ams_tray_select( $printers ) {
		$gruppen = array();

		foreach ( $printers as $printer ) {
			$optionen = BambuPrinterRepository::ams_tray_options( $printer );

			if ( $optionen ) {
				$gruppen[ $printer->name ] = $optionen;
			}
		}

		if ( ! $gruppen ) {
			// Kein Drucker meldet aktuell AMS-Fächer - Formular funktioniert
			// trotzdem, der Drucker nutzt dann das eingelegte/externe
			// Filament wie bisher.
			return;
		}

		echo '<select name="ams_tray_id">';
		echo '<option value="">' . esc_html__( '– aktuell eingelegtes Filament (kein AMS) –', 'lindenweg16' ) . '</option>';
		foreach ( $gruppen as $drucker_name => $optionen ) {
			printf( '<optgroup label="%s">', esc_attr( $drucker_name ) );
			foreach ( $optionen as $option ) {
				printf( '<option value="%d">%s</option>', (int) $option['value'], esc_html( $option['label'] ) );
			}
			echo '</optgroup>';
		}
		echo '</select> ';
	}

	/**
	 * Eine Druckerkarte mit Live-Status.
	 *
	 * @param object $printer Datensatz.
	 */
	protected function render_card( $printer ) {
		$online = BambuPrinterRepository::is_online( $printer );
		$queue  = BambuJobRepository::count_queued( $printer->printer_id );

		echo '<div class="lw16-card" style="border:1px solid #dcdcde;border-radius:4px;padding:16px;margin-bottom:12px;max-width:520px">';
		printf( '<h3 style="margin-top:0">%s <code>%s</code></h3>', esc_html( $printer->name ), esc_html( $printer->printer_id ) );

		printf(
			'<p><strong>%s:</strong> %s</p>',
			esc_html__( 'Bridge-Status', 'lindenweg16' ),
			$online
				? '<span style="color:#00a32a">●</span> ' . esc_html__( 'Online', 'lindenweg16' )
				: '<span style="color:#d63638">●</span> ' . esc_html__( 'Offline', 'lindenweg16' )
		);

		printf(
			'<p><strong>%s:</strong> %s</p>',
			esc_html__( 'Letzter Kontakt', 'lindenweg16' ),
			$printer->last_seen_at ? esc_html( $this->human_time_diff( $printer->last_seen_at ) ) : esc_html__( 'nie', 'lindenweg16' )
		);

		if ( $online && $printer->status ) {
			printf(
				'<p><strong>%s:</strong> %s%s%s</p>',
				esc_html__( 'Drucker-Status', 'lindenweg16' ),
				esc_html( self::status_text( $printer->status ) ),
				null !== $printer->progress_percent ? ' — ' . esc_html( (string) (int) $printer->progress_percent ) . ' %' : '',
				null !== $printer->remaining_minutes ? ' — ' . sprintf(
					/* translators: %d: Minuten. */
					esc_html__( 'noch %d min', 'lindenweg16' ),
					(int) $printer->remaining_minutes
				) : ''
			);
		}

		if ( $online && ( null !== $printer->nozzle_temp || null !== $printer->bed_temp ) ) {
			printf(
				'<p><strong>%s:</strong> %s</p>',
				esc_html__( 'Temperaturen', 'lindenweg16' ),
				esc_html(
					sprintf(
						/* translators: 1: Düsentemperatur, 2: Betttemperatur. */
						__( 'Düse %1$s °C, Bett %2$s °C', 'lindenweg16' ),
						null !== $printer->nozzle_temp ? number_format_i18n( (float) $printer->nozzle_temp, 0 ) : '–',
						null !== $printer->bed_temp ? number_format_i18n( (float) $printer->bed_temp, 0 ) : '–'
					)
				)
			);
		}

		if ( $online ) {
			$this->render_ams( $printer );
		}

		if ( $printer->last_error ) {
			printf( '<p class="lw16-fehlend"><strong>%s:</strong> %s</p>', esc_html__( 'Letzter Fehler', 'lindenweg16' ), esc_html( $printer->last_error ) );
		}

		if ( $printer->snapshot_at ) {
			$this->render_snapshot( $printer );
		}

		if ( $printer->printer_model ) {
			printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Modell', 'lindenweg16' ), esc_html( $printer->printer_model ) );
		}
		if ( $printer->ip && $this->can_manage() ) {
			printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'IP', 'lindenweg16' ), esc_html( $printer->ip ) );
		}

		printf( '<p><strong>%s:</strong> %d</p>', esc_html__( 'Wartende Aufträge', 'lindenweg16' ), (int) $queue );

		if ( ! $this->can_manage() ) {
			// Verbindungsdaten (v. a. der LAN-Zugangscode), Token-Erneuerung
			// und Löschen bleiben der Druckerverwaltung vorbehalten – siehe
			// can_manage(). Zum Einreihen eines Auftrags braucht es das alles
			// nicht.
			echo '</div>';
			return;
		}

		echo '<details style="margin:8px 0"><summary>' . esc_html__( 'Verbindungsdaten bearbeiten', 'lindenweg16' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		echo '<input type="hidden" name="action" value="lw16_bambu_save_printer">';
		printf( '<input type="hidden" name="printer_pk" value="%d">', (int) $printer->id );
		wp_nonce_field( self::NONCE );
		printf( '<p><label>%s <input type="text" name="name" value="%s"></label></p>', esc_html__( 'Name', 'lindenweg16' ), esc_attr( $printer->name ) );
		printf( '<p><label>%s <input type="text" name="ip" value="%s"></label></p>', esc_html__( 'IP', 'lindenweg16' ), esc_attr( (string) $printer->ip ) );
		printf( '<p><label>%s <input type="text" name="bambu_serial" value="%s"></label></p>', esc_html__( 'Seriennummer', 'lindenweg16' ), esc_attr( (string) $printer->bambu_serial ) );
		// Ein hier eingetragenes Modell hat Vorrang; die Bridge füllt nur ein
		// leeres Feld (aus der Seriennummer, siehe BambuPrinterRepository::
		// apply_status()). Muss mit dem beim Slicing-Profil eingetragenen
		// Modell übereinstimmen (exakter Vergleich, siehe
		// SlicingProfileRepository::find_for()).
		printf(
			'<p><label>%s <input type="text" name="printer_model" value="%s" placeholder="%s"></label> <span class="description">%s</span></p>',
			esc_html__( 'Druckermodell', 'lindenweg16' ),
			esc_attr( (string) $printer->printer_model ),
			esc_attr__( 'z. B. X1C', 'lindenweg16' ),
			esc_html__( 'leer = die Bridge trägt das Modell aus der Seriennummer ein', 'lindenweg16' )
		);
		// Nur ein Rückfallwert, falls die Bridge kein erkennbares Modell
		// meldet oder das eigene Gerät (z. B. ein modifiziertes Bett) von
		// der Werksangabe abweicht - siehe BambuPrinterRepository::
		// effective_bed_size()/BedSizeLookup. Leer lassen übernimmt die
		// automatische Erkennung.
		printf(
			'<p><label>%s <input type="number" min="1" name="bed_width_mm" value="%s" style="width:6em"> × <input type="number" min="1" name="bed_height_mm" value="%s" style="width:6em"> mm</label> <span class="description">%s</span></p>',
			esc_html__( 'Druckbettmass (Breite × Höhe)', 'lindenweg16' ),
			esc_attr( $printer->bed_width_mm ? (string) (int) $printer->bed_width_mm : '' ),
			esc_attr( $printer->bed_height_mm ? (string) (int) $printer->bed_height_mm : '' ),
			esc_html__( 'leer = automatisch anhand des gemeldeten Modells', 'lindenweg16' )
		);
		echo '<p><label>' . esc_html__( 'Verknüpftes Gerät', 'lindenweg16' ) . ' <select name="geraet_id"><option value="0">' . esc_html__( '– keine –', 'lindenweg16' ) . '</option>';
		foreach ( PrinterRepository::all() as $geraet ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $geraet['id'],
				selected( (int) $printer->geraet_id, (int) $geraet['id'], false ),
				esc_html( $geraet['code'] )
			);
		}
		echo '</select></label></p>';
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Speichern', 'lindenweg16' ) );
		echo '</form></details>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px" onsubmit="return confirm(\'' . esc_js( __( 'Neues Token erzeugen? Das alte wird sofort ungültig – die Bridge muss neu konfiguriert werden.', 'lindenweg16' ) ) . '\')">';
		echo '<input type="hidden" name="action" value="lw16_bambu_regen_token">';
		printf( '<input type="hidden" name="printer_pk" value="%d">', (int) $printer->id );
		wp_nonce_field( self::NONCE );
		printf( '<button type="submit" class="button button-secondary">%s</button>', esc_html__( 'Token neu erzeugen', 'lindenweg16' ) );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block" onsubmit="return confirm(\'' . esc_js( __( 'Diesen Drucker wirklich löschen? Alle seine Druckaufträge werden mitgelöscht.', 'lindenweg16' ) ) . '\')">';
		echo '<input type="hidden" name="action" value="lw16_bambu_delete_printer">';
		printf( '<input type="hidden" name="printer_pk" value="%d">', (int) $printer->id );
		wp_nonce_field( self::NONCE );
		printf( '<button type="submit" class="button-link-delete">%s</button>', esc_html__( 'Löschen', 'lindenweg16' ) );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Vom Drucker gemeldeten Zustand in Klartext übersetzen.
	 *
	 * "mqtt_getrennt" meldet die Bridge selbst, wenn sie den Drucker im
	 * LAN nicht erreicht – die Bridge läuft dann, das Gerät antwortet aber
	 * nicht (aus, andere IP, falscher Zugangscode).
	 *
	 * @param string $status Gemeldeter Zustand.
	 * @return string
	 */
	protected static function status_text( $status ) {
		$texte = array(
			'mqtt_getrennt' => __( 'Drucker nicht erreichbar (Bridge läuft, keine Verbindung zum Gerät)', 'lindenweg16' ),
			'idle'          => __( 'bereit', 'lindenweg16' ),
			'running'       => __( 'druckt', 'lindenweg16' ),
			'prepare'       => __( 'bereitet vor', 'lindenweg16' ),
			'pause'         => __( 'pausiert', 'lindenweg16' ),
			'finish'        => __( 'fertig', 'lindenweg16' ),
			'failed'        => __( 'Fehler', 'lindenweg16' ),
		);

		$key = strtolower( (string) $status );

		return isset( $texte[ $key ] ) ? $texte[ $key ] : (string) $status;
	}

	/**
	 * AMS-Belegung (Material, Farbe, Restmenge je Fach) einer Druckerkarte.
	 *
	 * @param object $printer Datensatz.
	 */
	protected function render_ams( $printer ) {
		$einheiten = BambuPrinterRepository::ams_units( $printer );

		if ( ! $einheiten ) {
			return;
		}

		echo '<div><strong>' . esc_html__( 'AMS-Filamente', 'lindenweg16' ) . ':</strong>';
		echo '<ul style="margin:4px 0 0 18px;padding:0;list-style:disc">';

		foreach ( $einheiten as $einheit ) {
			foreach ( (array) ( $einheit['trays'] ?? array() ) as $fach ) {
				$material = isset( $fach['type'] ) ? trim( (string) $fach['type'] ) : '';

				if ( '' === $material ) {
					// Leeres AMS-Fach - für die Auswahl beim Drucken
					// irrelevant, in der Übersicht nur Verwirrung.
					continue;
				}

				$farbe = isset( $fach['color'] ) ? preg_replace( '/[^0-9A-Fa-f]/', '', (string) $fach['color'] ) : '';
				$farbe = $farbe ? substr( str_pad( $farbe, 6, '0' ), 0, 6 ) : 'cccccc';
				$rest  = isset( $fach['remain'] ) && null !== $fach['remain'] && '' !== $fach['remain'] ? (int) $fach['remain'] : null;

				printf(
					'<li><span style="display:inline-block;width:12px;height:12px;border:1px solid #999;background:#%s;vertical-align:middle;margin-right:4px"></span>%s%s</li>',
					esc_attr( $farbe ),
					esc_html( $material ),
					null !== $rest ? ' – ' . esc_html( (string) $rest ) . ' %' : ''
				);
			}
		}

		echo '</ul></div>';
	}

	/**
	 * Zuletzt gemeldetes Kamera-Standbild einer Druckerkarte - wird beim
	 * automatischen Aktualisieren der Karte (siehe render_printer_cards())
	 * mit ausgetauscht, weil der Zeitstempel in der Bild-URL jedes Mal neu
	 * ist und der Browser das Bild dadurch neu lädt statt es aus dem Cache
	 * zu zeigen.
	 *
	 * @param object $printer Datensatz.
	 */
	protected function render_snapshot( $printer ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'lw16_bambu_snapshot',
					'printer_id' => $printer->printer_id,
					't'          => strtotime( $printer->snapshot_at . ' UTC' ),
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE . '_snapshot'
		);

		printf(
			'<p><img src="%s" alt="%s" style="max-width:100%%;border:1px solid #dcdcde;border-radius:4px"></p>',
			esc_url( $url ),
			esc_attr__( 'Kamera-Standbild', 'lindenweg16' )
		);
	}

	/**
	 * "vor X Sekunden/Minuten" aus einem GMT-Datetime.
	 *
	 * @param string $mysql_gmt GMT-Datetime.
	 * @return string
	 */
	protected function human_time_diff( $mysql_gmt ) {
		$timestamp = strtotime( $mysql_gmt . ' UTC' );

		if ( ! $timestamp ) {
			return __( 'unbekannt', 'lindenweg16' );
		}

		/* translators: %s: Zeitspanne, z. B. "3 Sekunden". */
		return sprintf( __( 'vor %s', 'lindenweg16' ), human_time_diff( $timestamp, time() ) );
	}
}
