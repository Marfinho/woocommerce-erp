<?php
/**
 * Admin-Oberfläche der Slicing-Profile und des Worker-Tokens.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\Bambu\BambuFilamentCatalogRepository;
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
		add_action( 'admin_post_lw16_slicing_rename_filament', array( $this, 'handle_rename_filament' ) );
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

		// Die Farbe kommt NICHT aus einer manuellen Eingabe, sondern
		// ausschließlich aus der Bambu-Filament-ID im hochgeladenen
		// Filamentprofil selbst ("filament_id", z. B. "GFA01") - so kann
		// die Farbe eines Profils nie versehentlich von der Datei
		// abweichen, die tatsächlich hochgeladen wurde. Gleichzeitig
		// landet das Filament im Katalog (BambuFilamentCatalogRepository),
		// damit Artikelrevisionen später daraus auswählen können. Ein
		// Profil ohne gültige Bambu-Filament-ID (z. B. ein Drittanbieter-
		// Filament ohne RFID-Chip) bekommt keine Farbe zugeordnet - "nur
		// mit Bambu-Lab-Filamenten arbeiten" gilt hier als weicher
		// Hinweis, kein harter Upload-Block.
		$filament_json_path = SlicingProfileRepository::safe_path( $filament_path );
		$filament_json      = $filament_json_path ? json_decode( file_get_contents( $filament_json_path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		$filament_id   = is_array( $filament_json ) && ! empty( $filament_json['filament_id'] ) ? (string) $filament_json['filament_id'] : '';
		$color         = BambuFilamentCatalogRepository::is_valid_filament_id( $filament_id ) ? $filament_id : '';

		if ( $filament_json_path ) {
			BambuFilamentCatalogRepository::observe_filament_profile_json(
				file_get_contents( $filament_json_path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
			);
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

		if ( ! $id ) {
			$this->redirect( __( 'Profil konnte nicht gespeichert werden.', 'lindenweg16' ) );
		}

		$this->redirect(
			$color
				? sprintf(
					/* translators: %s: Bambu-Filament-ID. */
					__( 'Slicing-Profil gespeichert (Farbe erkannt: %s).', 'lindenweg16' ),
					$color
				)
				: __( 'Slicing-Profil gespeichert - ohne gültige Bambu-Filament-ID im Filamentprofil, deshalb als farbloses Profil dieses Materials.', 'lindenweg16' )
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
	 * Anzeigename eines katalogisierten Filaments auf den echten
	 * Bambu-Produktnamen korrigieren.
	 */
	public function handle_rename_filament() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$filament_id = isset( $_POST['filament_id'] ) ? sanitize_text_field( wp_unslash( $_POST['filament_id'] ) ) : '';
		$label       = isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$ok = BambuFilamentCatalogRepository::rename( $filament_id, $label );

		$this->redirect(
			$ok
				? __( 'Filamentname gespeichert.', 'lindenweg16' )
				: __( 'Filamentname konnte nicht gespeichert werden.', 'lindenweg16' )
		);
	}

	/**
	 * Inhalt des Reiters.
	 */
	public function render_content() {
		$this->require_cap();

		$this->render_token_section();
		$this->render_profiles_section();
		$this->render_filament_catalog_section();
	}

	/**
	 * Bambu-Filament-Katalog: alle je im AMS gesehenen oder per Profil
	 * hochgeladenen echten Bambu-Filamente, mit korrigierbarem Anzeigenamen.
	 */
	protected function render_filament_catalog_section() {
		echo '<h2>' . esc_html__( 'Bekannte Bambu-Filamente', 'lindenweg16' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Automatisch gesammelt aus allen AMS-Meldungen und hochgeladenen Filamentprofilen - anhand der festen Bambu-Filament-ID (RFID-Chip der Spule), nicht per Freitext. Der Anzeigename ist zunächst nur geschätzt; hier auf den echten Bambu-Produktnamen korrigieren, dann erscheint er überall (Artikelrevision, Slicing-Profile) so.', 'lindenweg16' ) . '</p>';

		$filamente = BambuFilamentCatalogRepository::all();

		if ( ! $filamente ) {
			echo '<p>' . esc_html__( 'Noch kein Bambu-Filament gesehen - taucht automatisch auf, sobald eines im AMS steckt oder ein Slicing-Profil damit hochgeladen wird.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Farbe', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Material', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Bambu-ID', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Anzeigename', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Zuletzt gesehen', 'lindenweg16' ) . '</th></tr></thead><tbody>';

		foreach ( $filamente as $filament ) {
			echo '<tr>';
			printf(
				'<td><span style="display:inline-block;width:20px;height:20px;border:1px solid #8888;border-radius:3px;background:#%s" title="#%s"></span></td>',
				esc_attr( $filament->color_hex ? $filament->color_hex : 'ffffff' ),
				esc_attr( $filament->color_hex )
			);
			printf( '<td>%s</td>', esc_html( $filament->material ) );
			printf( '<td><code>%s</code></td>', esc_html( $filament->filament_id ) );

			echo '<td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:4px">';
			echo '<input type="hidden" name="action" value="lw16_slicing_rename_filament">';
			printf( '<input type="hidden" name="filament_id" value="%s">', esc_attr( $filament->filament_id ) );
			wp_nonce_field( self::NONCE );
			printf( '<input type="text" name="label" value="%s" class="regular-text">', esc_attr( $filament->label ) );
			printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Speichern', 'lindenweg16' ) );
			echo '</form>';
			echo '</td>';

			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y H:i', $filament->last_seen_at ) ) );
			echo '</tr>';
		}
		echo '</tbody></table>';
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

		echo '<tr><th scope="row">' . esc_html__( 'Druckermodell', 'lindenweg16' ) . '</th><td>';
		echo '<input type="text" name="printer_model" class="regular-text" placeholder="P1S">';
		echo '<p class="description">' . esc_html__( 'Muss zum von der Bridge gemeldeten Druckermodell passen (Teilstring-Vergleich, z. B. "P1S" trifft auf "Bambu Lab P1S").', 'lindenweg16' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Druckerprofil (JSON)', 'lindenweg16' ) . '</th><td><input type="file" name="printer_profile" accept=".json" required></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Filamentprofil (JSON)', 'lindenweg16' ) . '</th><td><input type="file" name="filament_profile" accept=".json" required>';
		echo '<p class="description">' . esc_html__( 'Die Farbe wird automatisch aus der Bambu-Filament-ID dieser Datei erkannt ("filament_id", z. B. "GFA01") - keine manuelle Eingabe nötig, dafür auch keine Verwechslungsgefahr. Ohne gültige ID (z. B. ein Drittanbieter-Filament ohne RFID-Chip) wird das Profil als farbloses Profil dieses Materials gespeichert.', 'lindenweg16' ) . '</p>';
		echo '</td></tr>';
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
			$filament = $profile->color ? BambuFilamentCatalogRepository::find( $profile->color ) : null;
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $profile->material ) );
			printf(
				'<td>%s</td>',
				esc_html(
					$filament
						? sprintf( '%s (%s)', $filament->label, $profile->color )
						: ( $profile->color ? $profile->color : __( '(farblos)', 'lindenweg16' ) )
				)
			);
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
