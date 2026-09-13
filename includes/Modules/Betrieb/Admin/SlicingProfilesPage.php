<?php
/**
 * Admin-Oberfläche der Slicing-Profile und des Worker-Tokens.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\Nesting\SlicingProfileRepository;
use Lindenweg16\Modules\Betrieb\Nesting\SlicingWorkerAuth;
use Lindenweg16\Modules\Betrieb\RevisionRepository;

/**
 * „Slicing-Profile": Worker-Token verwalten und je Material × Druckermodell
 * die drei OrcaSlicer-Profildateien hinterlegen, die der Headless-Slicing-
 * Worker für einen automatischen Slice-Lauf braucht.
 */
class SlicingProfilesPage {

	const NONCE = 'lw16_slicing_profile';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'admin_post_lw16_slicing_regen_token', array( $this, 'handle_regen_token' ) );
		add_action( 'admin_post_lw16_slicing_save_profile', array( $this, 'handle_save_profile' ) );
		add_action( 'admin_post_lw16_slicing_delete_profile', array( $this, 'handle_delete_profile' ) );
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
	 * Zurück zum Reiter „Slicing-Profile".
	 *
	 * @param string $msg   Meldung.
	 * @param string $token Klartext-Token, nur einmalig transportiert.
	 */
	protected function redirect( $msg, $token = '' ) {
		$args = array( 'page' => BambuPrintersPage::SLUG, 'tab' => 'slicing', 'lw16_msg' => rawurlencode( $msg ) );
		if ( '' !== $token ) {
			$args['lw16_token'] = rawurlencode( $token );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Worker-Token neu erzeugen.
	 */
	public function handle_regen_token() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$token = SlicingWorkerAuth::regenerate();

		$this->redirect( __( 'Neues Worker-Token erzeugt. Es wird nur jetzt angezeigt.', 'lindenweg16' ), $token );
	}

	/**
	 * Profil speichern (drei Dateien hochladen).
	 */
	public function handle_save_profile() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$material      = isset( $_POST['material'] ) ? sanitize_text_field( wp_unslash( $_POST['material'] ) ) : '';
		$color         = isset( $_POST['color'] ) ? sanitize_text_field( wp_unslash( $_POST['color'] ) ) : '';
		$printer_model = isset( $_POST['printer_model'] ) ? sanitize_text_field( wp_unslash( $_POST['printer_model'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $material || '' === $printer_model ) {
			$this->redirect( __( 'Material und Druckermodell sind Pflicht.', 'lindenweg16' ) );
		}

		$printer_path  = ! empty( $_FILES['printer_profile'] ) ? SlicingProfileRepository::store_file( $_FILES['printer_profile'], 'printer' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$filament_path = ! empty( $_FILES['filament_profile'] ) ? SlicingProfileRepository::store_file( $_FILES['filament_profile'], 'filament' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$process_path  = ! empty( $_FILES['process_profile'] ) ? SlicingProfileRepository::store_file( $_FILES['process_profile'], 'process' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $printer_path || ! $filament_path || ! $process_path ) {
			$this->redirect( __( 'Bitte alle drei Profildateien als gültiges JSON hochladen (Drucker-, Filament- und Prozessprofil aus OrcaSlicer/Bambu Studio).', 'lindenweg16' ) );
		}

		$id = SlicingProfileRepository::upsert(
			array(
				'material'              => $material,
				'color'                 => $color,
				'printer_model'         => $printer_model,
				'printer_profile_path'  => $printer_path,
				'filament_profile_path' => $filament_path,
				'process_profile_path'  => $process_path,
			)
		);

		$this->redirect(
			$id
				? __( 'Slicing-Profil gespeichert.', 'lindenweg16' )
				: __( 'Profil konnte nicht gespeichert werden.', 'lindenweg16' )
		);
	}

	/**
	 * Profil löschen.
	 */
	public function handle_delete_profile() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$id = isset( $_POST['profile_id'] ) ? (int) $_POST['profile_id'] : 0;
		SlicingProfileRepository::delete( $id );

		$this->redirect( __( 'Profil gelöscht.', 'lindenweg16' ) );
	}

	/**
	 * Inhalt des Reiters.
	 */
	public function render_content() {
		$this->require_cap();

		$this->render_token_section();
		$this->render_profiles_section();
	}

	/**
	 * Worker-Token: Status + Neuerzeugen.
	 */
	protected function render_token_section() {
		echo '<h2>' . esc_html__( 'Slicing-Worker', 'lindenweg16' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Ein geteiltes Token für den Headless-Slicing-Worker (siehe raspberry-pi/slicing_worker.py) – anders als bei den Druckern gibt es hier typischerweise nur einen Worker.', 'lindenweg16' ) . '</p>';

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_token'] ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong><br><code style="font-size:14px;user-select:all">%s</code></p><p class="description">%s</p></div>',
				esc_html__( 'Token (wird nur jetzt angezeigt – jetzt in die .env.slicing des Workers eintragen):', 'lindenweg16' ),
				esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_token'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification
				esc_html__( 'Diese Seite neu laden oder verlassen, und das Token ist nicht mehr abrufbar – dann hilft nur „Token neu erzeugen".', 'lindenweg16' )
			);
		}

		printf(
			'<p><strong>%s:</strong> %s</p>',
			esc_html__( 'Status', 'lindenweg16' ),
			SlicingWorkerAuth::has_token()
				? esc_html__( 'Token vergeben.', 'lindenweg16' )
				: esc_html__( 'Noch kein Token vergeben.', 'lindenweg16' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Neues Token erzeugen? Das alte wird sofort ungültig – der Worker muss neu konfiguriert werden.', 'lindenweg16' ) ) . '\')">';
		echo '<input type="hidden" name="action" value="lw16_slicing_regen_token">';
		wp_nonce_field( self::NONCE );
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Token neu erzeugen', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Profile: Formular + Liste.
	 */
	protected function render_profiles_section() {
		echo '<h2>' . esc_html__( 'Slicing-Profile', 'lindenweg16' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Drucker-, Filament- und Prozessprofil aus OrcaSlicer bzw. Bambu Studio exportieren (Rechtsklick auf das Profil → „Export") und hier je Material und Druckermodell hinterlegen.', 'lindenweg16' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="lw16_slicing_save_profile">';
		wp_nonce_field( self::NONCE );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Material', 'lindenweg16' ) . '</th><td><select name="material">';
		foreach ( RevisionRepository::MATERIALS as $material ) {
			printf( '<option value="%s">%s</option>', esc_attr( $material ), esc_html( $material ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Farbe', 'lindenweg16' ) . '</th><td>';
		echo '<input type="text" name="color" class="regular-text" placeholder="' . esc_attr__( 'z. B. Weiß - leer = das eine farblose Profil dieses Materials', 'lindenweg16' ) . '">';
		echo '<p class="description">' . esc_html__( 'Muss zur Farbe passen, die bei der Artikelrevision hinterlegt ist (Betrieb → Artikel → Revision).', 'lindenweg16' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Druckermodell', 'lindenweg16' ) . '</th><td>';
		echo '<input type="text" name="printer_model" class="regular-text" placeholder="P1S">';
		echo '<p class="description">' . esc_html__( 'Muss zum von der Bridge gemeldeten Druckermodell passen (Teilstring-Vergleich, z. B. "P1S" trifft auf "Bambu Lab P1S").', 'lindenweg16' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Druckerprofil (JSON)', 'lindenweg16' ) . '</th><td><input type="file" name="printer_profile" accept=".json" required></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Filamentprofil (JSON)', 'lindenweg16' ) . '</th><td><input type="file" name="filament_profile" accept=".json" required></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Prozessprofil (JSON)', 'lindenweg16' ) . '</th><td><input type="file" name="process_profile" accept=".json" required></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Profil speichern', 'lindenweg16' ) );
		echo '</form>';

		$profiles = SlicingProfileRepository::all();

		if ( ! $profiles ) {
			echo '<p>' . esc_html__( 'Noch kein Profil hinterlegt.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Material', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Farbe', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Druckermodell', 'lindenweg16' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $profiles as $profile ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $profile->material ) );
			printf( '<td>%s</td>', esc_html( $profile->color ? $profile->color : __( '(farblos)', 'lindenweg16' ) ) );
			printf( '<td>%s</td>', esc_html( $profile->printer_model ) );
			echo '<td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Profil wirklich löschen?', 'lindenweg16' ) ) . '\')">';
			echo '<input type="hidden" name="action" value="lw16_slicing_delete_profile">';
			printf( '<input type="hidden" name="profile_id" value="%d">', (int) $profile->id );
			wp_nonce_field( self::NONCE );
			printf( '<button type="submit" class="button-link-delete">%s</button>', esc_html__( 'Löschen', 'lindenweg16' ) );
			echo '</form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
