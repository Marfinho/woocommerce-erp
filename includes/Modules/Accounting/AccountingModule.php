<?php
/**
 * Modul 1: Buchhaltung (Belege, EÜR, geschützte Beleg-Auslieferung).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Verdrahtet Belegerfassung, EÜR-Ansicht, Dashboard-Rücklage und die
 * authentifizierte Auslieferung von Beleg-Fotos.
 */
class AccountingModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_dashboard', array( $this, 'render_dashboard' ) );
		add_action( 'lw16_render_page_lw16_buchhaltung', array( $this, 'render_admin' ) );

		add_action( 'admin_post_lw16_save_beleg', array( $this, 'handle_save_beleg' ) );
		add_action( 'admin_post_lw16_festschreiben', array( $this, 'handle_festschreiben' ) );

		// Geschützte Beleg-Foto-Auslieferung (nur mit Capability).
		add_action( 'admin_post_lw16_beleg_datei', array( $this, 'serve_beleg_file' ) );
	}

	/* --------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Dashboard mit Steuer-Rücklage-Empfehlung rendern.
	 */
	public function render_dashboard() {
		$steuersatz = (float) Settings::get( 'steuersatz_ruecklage', 25 );
		$ruecklage  = EuerCalculator::ruecklage( $steuersatz );

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Lindenweg16 Dashboard', 'lindenweg16' ) . '</h1>';

		echo '<div class="lw16-cards">';
		printf(
			'<div class="lw16-card lw16-card-highlight"><h2>%s</h2><p class="lw16-big">%s</p><p class="lw16-sub">%s <strong>%s</strong></p><p class="lw16-hint">%s</p></div>',
			esc_html__( 'Steuer-Rücklage (laufendes Jahr)', 'lindenweg16' ),
			esc_html( self::euro( $ruecklage['ruecklage'] ) ),
			esc_html__( 'Aktueller Gewinn:', 'lindenweg16' ),
			esc_html( self::euro( $ruecklage['gewinn'] ) ),
			esc_html__( 'Richtwert – ersetzt keine steuerliche Beratung, dient nur der eigenen Orientierung.', 'lindenweg16' )
		);
		echo '</div>';

		do_action( 'lw16_dashboard_widgets' );

		echo '</div>';
	}

	/* --------------------------------------------------------------------
	 * Admin: Belege & EÜR
	 * ------------------------------------------------------------------ */

	/**
	 * Buchhaltungs-Admin-Seite rendern.
	 */
	public function render_admin() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'belege'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Buchhaltung', 'lindenweg16' ) . '</h1>';

		$base = admin_url( 'admin.php?page=lw16-buchhaltung' );
		echo '<nav class="lw16-tabs">';
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( $base ), 'belege' === $view ? 'active' : '', esc_html__( 'Belege', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'neu', $base ) ), 'neu' === $view ? 'active' : '', esc_html__( 'Beleg erfassen', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( 'view', 'euer', $base ) ), 'euer' === $view ? 'active' : '', esc_html__( 'EÜR', 'lindenweg16' ) );
		echo '</nav>';

		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		if ( 'neu' === $view ) {
			$this->render_beleg_form();
		} elseif ( 'euer' === $view ) {
			$this->render_euer();
		} else {
			$this->render_belege_list();
		}
		echo '</div>';
	}

	/**
	 * Belegliste (paginiert) mit Festschreibungs-Aktion.
	 */
	protected function render_belege_list() {
		$page   = isset( $_GET['bp'] ) ? max( 1, (int) $_GET['bp'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$belege = BelegRepository::query( array( 'per_page' => 50, 'page' => $page ) );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Händler', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Kategorie', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Betrag', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Quelle', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Foto', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $belege as $b ) {
			echo '<tr>';
			echo '<td>' . esc_html( $b->datum ) . '</td>';
			echo '<td>' . esc_html( (string) $b->haendler ) . '</td>';
			echo '<td>' . esc_html( (string) $b->eur_kategorie ) . '</td>';
			echo '<td>' . esc_html( self::euro( $b->betrag ) ) . '</td>';
			$quelle = 'wareneingang' === $b->quelle
				? '<span title="' . esc_attr__( 'automatisch aus Wareneingang übernommen', 'lindenweg16' ) . '">' . esc_html__( 'Wareneingang', 'lindenweg16' ) . '</span>'
				: esc_html__( 'manuell', 'lindenweg16' );
			echo '<td>' . $quelle . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $b->datei_pfad ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_beleg_datei&id=' . $b->id ), 'lw16_beleg_datei_' . $b->id );
				echo '<td><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'ansehen', 'lindenweg16' ) . '</a></td>';
			} else {
				echo '<td>–</td>';
			}
			if ( (int) $b->festgeschrieben === 1 ) {
				echo '<td><span class="lw16-locked">' . esc_html__( 'festgeschrieben', 'lindenweg16' ) . '</span></td><td></td>';
			} else {
				echo '<td>' . esc_html__( 'offen', 'lindenweg16' ) . '</td>';
				$fs = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_festschreiben&id=' . $b->id ), 'lw16_festschreiben_' . $b->id );
				echo '<td><a class="button button-small" href="' . esc_url( $fs ) . '" onclick="return confirm(\'' . esc_attr__( 'Beleg festschreiben? Danach nicht mehr änderbar.', 'lindenweg16' ) . '\')">' . esc_html__( 'Festschreiben', 'lindenweg16' ) . '</a></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Formular zur manuellen Belegerfassung.
	 */
	protected function render_beleg_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="lw16_save_beleg">
			<?php wp_nonce_field( 'lw16_save_beleg' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Beleg-Foto', 'lindenweg16' ); ?></th><td><input type="file" name="beleg_foto" accept="image/*"></td></tr>
				<tr><th><?php esc_html_e( 'Betrag (€)', 'lindenweg16' ); ?></th><td><input type="number" step="0.01" name="betrag" class="small-text" required></td></tr>
				<tr><th><?php esc_html_e( 'Datum', 'lindenweg16' ); ?></th><td><input type="date" name="datum" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Händler', 'lindenweg16' ); ?></th><td><input type="text" name="haendler" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'EÜR-Kategorie', 'lindenweg16' ); ?></th><td>
					<select name="eur_kategorie">
						<?php foreach ( self::eur_kategorien() as $k ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $k ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Notiz', 'lindenweg16' ); ?></th><td><textarea name="notiz" class="large-text" rows="2"></textarea></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Beleg speichern', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	/**
	 * EÜR-Ansicht mit Zeitraumauswahl.
	 */
	protected function render_euer() {
		$jahr = (int) gmdate( 'Y' );
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : $jahr . '-01-01'; // phpcs:ignore WordPress.Security.NonceVerification
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : $jahr . '-12-31'; // phpcs:ignore WordPress.Security.NonceVerification

		$rep = EuerCalculator::report( $from, $to );

		echo '<form method="get" class="lw16-euer-filter">';
		echo '<input type="hidden" name="page" value="lw16-buchhaltung"><input type="hidden" name="view" value="euer">';
		echo '<label>' . esc_html__( 'Von', 'lindenweg16' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> ';
		echo '<label>' . esc_html__( 'Bis', 'lindenweg16' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> ';
		submit_button( __( 'Berechnen', 'lindenweg16' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped" style="max-width:640px">';
		echo '<tr><th>' . esc_html__( 'Einnahmen (WooCommerce, abzgl. Refunds)', 'lindenweg16' ) . '</th><td>' . esc_html( self::euro( $rep['einnahmen'] ) ) . '</td></tr>';
		echo '<tr><th colspan="2">' . esc_html__( 'Ausgaben nach EÜR-Kategorie', 'lindenweg16' ) . '</th></tr>';
		foreach ( $rep['ausgaben_kategorien'] as $kat => $summe ) {
			echo '<tr><td style="padding-left:2em">' . esc_html( $kat ) . '</td><td>' . esc_html( self::euro( $summe ) ) . '</td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Ausgaben gesamt', 'lindenweg16' ) . '</th><td>' . esc_html( self::euro( $rep['ausgaben_summe'] ) ) . '</td></tr>';
		echo '<tr class="lw16-euer-result"><th>' . esc_html__( 'Gewinn', 'lindenweg16' ) . '</th><td><strong>' . esc_html( self::euro( $rep['gewinn'] ) ) . '</strong></td></tr>';
		echo '</table>';

		if ( (int) Settings::get( 'kleinunternehmer', 1 ) === 1 ) {
			echo '<p class="description">' . esc_html__( 'Kleinunternehmer §19 UStG: keine Umsatzsteuer ausgewiesen, alle Beträge brutto.', 'lindenweg16' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'PDF-Export inkl. Beleg-Fotos: in Vorbereitung.', 'lindenweg16' ) . '</p>';
	}

	/* --------------------------------------------------------------------
	 * POST-Handler
	 * ------------------------------------------------------------------ */

	/**
	 * Manuellen Beleg speichern.
	 */
	public function handle_save_beleg() {
		$this->guard( 'lw16_save_beleg' );

		$datei_pfad = '';
		if ( ! empty( $_FILES['beleg_foto']['name'] ) ) {
			$datei_pfad = BelegRepository::store_upload( $_FILES['beleg_foto'] );
		}

		BelegRepository::create(
			array(
				'datei_pfad'    => $datei_pfad,
				'betrag'        => (float) ( $_POST['betrag'] ?? 0 ),
				'datum'         => sanitize_text_field( wp_unslash( $_POST['datum'] ?? gmdate( 'Y-m-d' ) ) ),
				'haendler'      => sanitize_text_field( wp_unslash( $_POST['haendler'] ?? '' ) ),
				'eur_kategorie' => sanitize_text_field( wp_unslash( $_POST['eur_kategorie'] ?? '' ) ),
				'notiz'         => sanitize_textarea_field( wp_unslash( $_POST['notiz'] ?? '' ) ),
				'quelle'        => 'manuell',
			)
		);

		$this->redirect( __( 'Beleg gespeichert.', 'lindenweg16' ) );
	}

	/**
	 * Beleg festschreiben.
	 */
	public function handle_festschreiben() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_festschreiben_' . $id );
		BelegRepository::festschreiben( $id );
		$this->redirect( __( 'Beleg festgeschrieben.', 'lindenweg16' ) );
	}

	/* --------------------------------------------------------------------
	 * Geschützte Datei-Auslieferung
	 * ------------------------------------------------------------------ */

	/**
	 * Beleg-Foto nur für berechtigte Nutzer ausliefern.
	 */
	public function serve_beleg_file() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_beleg_datei_' . $id );

		$beleg = BelegRepository::get( $id );
		if ( ! $beleg || empty( $beleg->datei_pfad ) ) {
			wp_die( esc_html__( 'Beleg nicht gefunden.', 'lindenweg16' ) );
		}

		$path = BelegRepository::file_path( $beleg->datei_pfad );
		// Path-Traversal-Schutz.
		$real_base = realpath( \Lindenweg16\Core\Installer::belege_dir() );
		$real_file = realpath( $path );
		if ( ! $real_file || ! $real_base || strpos( $real_file, $real_base ) !== 0 || ! is_file( $real_file ) ) {
			wp_die( esc_html__( 'Datei nicht verfügbar.', 'lindenweg16' ) );
		}

		$mime = wp_check_filetype( $real_file );
		header( 'Content-Type: ' . ( $mime['type'] ? $mime['type'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $real_file ) );
		header( 'Content-Disposition: inline; filename="' . basename( $real_file ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $real_file ); // phpcs:ignore
		exit;
	}

	/* --------------------------------------------------------------------
	 * Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Amtliche EÜR-Kategorien (vereinfacht).
	 *
	 * @return string[]
	 */
	public static function eur_kategorien() {
		return array(
			'Wareneinkauf',
			'Büromaterial',
			'Fahrtkosten',
			'Telefon/Internet',
			'Fortbildung',
			'Porto/Versand',
			'Hosting/Software',
			'AfA',
			'Sonstiges',
		);
	}

	/**
	 * Betrag als Euro formatieren.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	public static function euro( $n ) {
		return number_format( (float) $n, 2, ',', '.' ) . ' €';
	}

	/**
	 * Berechtigung + Nonce prüfen.
	 *
	 * @param string $nonce Nonce-Aktion.
	 */
	protected function guard( $nonce ) {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Zurück zur Belegliste mit Meldung.
	 *
	 * @param string $msg Meldung.
	 */
	protected function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-buchhaltung', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
