<?php
/**
 * Modul: Marketing – Kostenstellen für Kampagnen und Events.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Marketing;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Calc;
use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Accounting\Kontenrahmen;

/**
 * Verdrahtet die Kostenstellenverwaltung: Kampagnen anlegen, Belege
 * zuordnen, Kilometergeld buchen und Kosten gegen Umsatz stellen.
 */
class MarketingModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_page_lw16_marketing', array( $this, 'render_admin' ) );

		add_action( 'admin_post_lw16_save_kampagne', array( $this, 'handle_save_kampagne' ) );
		add_action( 'admin_post_lw16_kampagne_zuordnen', array( $this, 'handle_zuordnen' ) );
		add_action( 'admin_post_lw16_kampagne_loesen', array( $this, 'handle_loesen' ) );
		add_action( 'admin_post_lw16_kampagne_km', array( $this, 'handle_km' ) );
	}

	/* --------------------------------------------------------------------
	 * Ansichten
	 * ------------------------------------------------------------------ */

	/**
	 * Seite ausgeben.
	 */
	public function render_admin() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'uebersicht'; // phpcs:ignore WordPress.Security.NonceVerification
		$base = admin_url( 'admin.php?page=lw16-marketing' );

		echo '<div class="wrap lw16-wrap">';
		echo '<h1>' . esc_html__( 'Marketing', 'lindenweg16' ) . '</h1>';

		echo '<nav class="lw16-tabs">';
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( $base ), 'uebersicht' === $view ? 'active' : '', esc_html__( 'Kampagnen', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( 'view', 'neu', $base ) ), 'neu' === $view ? 'active' : '', esc_html__( 'Neue Kampagne', 'lindenweg16' ) );
		echo '</nav>';

		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		switch ( $view ) {
			case 'neu':
				$this->render_form();
				break;
			case 'detail':
				$this->render_detail();
				break;
			default:
				$this->render_uebersicht();
		}

		echo '</div>';
	}

	/**
	 * Liste aller Kostenstellen mit Budgetstand.
	 */
	protected function render_uebersicht() {
		$kampagnen = KampagnenRepository::all();

		if ( ! $kampagnen ) {
			echo '<p>' . esc_html__( 'Noch keine Kampagne angelegt. Ein Marktbesuch, eine Anzeigenschaltung oder eine Flyeraktion ist jeweils eine Kampagne – sie bekommt eine Kostenstelle, auf die alle zugehörigen Belege gebucht werden.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Kostenstelle', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Typ', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Zeitraum', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Budget', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Kosten', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Auslastung', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $kampagnen as $k ) {
			$kosten = KampagnenRepository::kosten( (int) $k->id );
			$budget = Kampagnenrechnung::budget_status( $k->budget_geplant, $kosten['summe'] );
			$url    = add_query_arg(
				array( 'page' => 'lw16-marketing', 'view' => 'detail', 'id' => (int) $k->id ),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '"><code>' . esc_html( $k->kostenstelle ) . '</code></a></td>';
			echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( $k->name ) . '</a></td>';
			echo '<td>' . esc_html( self::typ_label( $k->typ ) ) . '</td>';
			echo '<td>' . esc_html( self::zeitraum_text( $k ) ) . '</td>';
			echo '<td>' . esc_html( self::euro( $k->budget_geplant ) ) . '</td>';
			echo '<td>' . esc_html( self::euro( $kosten['summe'] ) ) . '</td>';
			echo '<td>' . wp_kses_post( self::ampel_html( $budget ) ) . '</td>';
			echo '<td>' . esc_html( self::status_label( $k->status ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Formular zum Anlegen oder Bearbeiten.
	 */
	protected function render_form() {
		$id       = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$kampagne = $id ? KampagnenRepository::get( $id ) : null;

		$wert = function ( $feld, $default = '' ) use ( $kampagne ) {
			return $kampagne && isset( $kampagne->$feld ) ? $kampagne->$feld : $default;
		};
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_save_kampagne">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( 'lw16_save_kampagne' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Name', 'lindenweg16' ); ?></th><td>
					<input type="text" name="name" class="regular-text" required value="<?php echo esc_attr( $wert( 'name' ) ); ?>"
						placeholder="<?php esc_attr_e( 'z. B. Weihnachtsmarkt Altstadt', 'lindenweg16' ); ?>">
					<?php if ( $kampagne ) : ?>
						<p class="description"><?php printf( esc_html__( 'Kostenstelle: %s (bleibt unverändert)', 'lindenweg16' ), '<code>' . esc_html( $kampagne->kostenstelle ) . '</code>' ); // phpcs:ignore ?></p>
					<?php endif; ?>
				</td></tr>

				<tr><th><?php esc_html_e( 'Typ', 'lindenweg16' ); ?></th><td>
					<select name="typ">
						<?php foreach ( KampagnenRepository::TYPEN as $typ ) : ?>
							<option value="<?php echo esc_attr( $typ ); ?>" <?php selected( $wert( 'typ', 'event' ), $typ ); ?>>
								<?php echo esc_html( self::typ_label( $typ ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td></tr>

				<tr><th><?php esc_html_e( 'Kanal', 'lindenweg16' ); ?></th><td>
					<input type="text" name="kanal" class="regular-text" value="<?php echo esc_attr( $wert( 'kanal' ) ); ?>"
						placeholder="<?php esc_attr_e( 'z. B. Google Ads, Wochenmarkt, Flyer', 'lindenweg16' ); ?>">
				</td></tr>

				<tr><th><?php esc_html_e( 'Beginn', 'lindenweg16' ); ?></th><td>
					<input type="date" name="start_datum" value="<?php echo esc_attr( $wert( 'start_datum', gmdate( 'Y-m-d' ) ) ); ?>">
				</td></tr>
				<tr><th><?php esc_html_e( 'Ende', 'lindenweg16' ); ?></th><td>
					<input type="date" name="ende_datum" value="<?php echo esc_attr( $wert( 'ende_datum' ) ); ?>">
				</td></tr>
				<tr><th><?php esc_html_e( 'Veranstaltungstag', 'lindenweg16' ); ?></th><td>
					<input type="date" name="event_datum" value="<?php echo esc_attr( $wert( 'event_datum' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Nur bei Events. Die Auswertung ordnet die Kampagne diesem Tag zu – auch wenn die Standgebühr steuerlich schon im Vorjahr abgeflossen ist.', 'lindenweg16' ); ?></p>
				</td></tr>

				<tr><th><?php esc_html_e( 'Geplantes Budget (€)', 'lindenweg16' ); ?></th><td>
					<input type="number" step="0.01" min="0" name="budget_geplant" class="small-text" value="<?php echo esc_attr( $wert( 'budget_geplant', '0' ) ); ?>">
				</td></tr>

				<tr><th><?php esc_html_e( 'Kampagnenkennung (utm_campaign)', 'lindenweg16' ); ?></th><td>
					<input type="text" name="utm_campaign" class="regular-text" value="<?php echo esc_attr( $wert( 'utm_campaign' ) ); ?>">
					<p class="description"><?php esc_html_e( 'WooCommerce merkt sich diese Kennung bei jeder Bestellung. Genau der Wert, der in der Anzeigen-URL als utm_campaign steht.', 'lindenweg16' ); ?></p>
				</td></tr>

				<tr><th><?php esc_html_e( 'Gutscheincode', 'lindenweg16' ); ?></th><td>
					<input type="text" name="gutschein_code" class="regular-text" value="<?php echo esc_attr( $wert( 'gutschein_code' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Ein Code, den es nur bei dieser Kampagne gab – die einzige belastbare Brücke von einem Flyer zu einer späteren Shop-Bestellung.', 'lindenweg16' ); ?></p>
				</td></tr>

				<tr><th><?php esc_html_e( 'Status', 'lindenweg16' ); ?></th><td>
					<select name="status">
						<?php foreach ( KampagnenRepository::STATUS as $status ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $wert( 'status', 'geplant' ), $status ); ?>>
								<?php echo esc_html( self::status_label( $status ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td></tr>

				<tr><th><?php esc_html_e( 'Notiz', 'lindenweg16' ); ?></th><td>
					<textarea name="notiz" rows="2" class="large-text"><?php echo esc_textarea( $wert( 'notiz' ) ); ?></textarea>
				</td></tr>
			</tbody></table>
			<?php submit_button( $kampagne ? __( 'Kampagne speichern', 'lindenweg16' ) : __( 'Kampagne anlegen', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Einzelansicht: Kosten, Umsatz, Kennzahlen, Zuordnung.
	 */
	protected function render_detail() {
		$id       = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$kampagne = KampagnenRepository::get( $id );

		if ( ! $kampagne ) {
			echo '<p>' . esc_html__( 'Kampagne nicht gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}

		$rep       = KampagnenReport::report( $kampagne );
		$edit_url  = add_query_arg(
			array( 'page' => 'lw16-marketing', 'view' => 'neu', 'id' => $id ),
			admin_url( 'admin.php' )
		);

		echo '<h2>' . esc_html( $kampagne->kostenstelle . ' – ' . $kampagne->name ) . ' ';
		echo '<a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Bearbeiten', 'lindenweg16' ) . '</a></h2>';

		// ── Kennzahlen ──
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		echo '<tr><th>' . esc_html__( 'Kosten (zugeordnet)', 'lindenweg16' ) . '</th><td>' . esc_html( self::euro( $rep['kosten'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Budget', 'lindenweg16' ) . '</th><td>' . esc_html( self::euro( $rep['budget']['budget'] ) ) . ' ' . wp_kses_post( self::ampel_html( $rep['budget'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Umsatz Marktkasse', 'lindenweg16' ) . '</th><td>' . esc_html( self::euro( $rep['markt']['summe'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Umsatz Shop (nachgewiesen)', 'lindenweg16' ) . '</th><td>' . esc_html( self::euro( $rep['shop']['summe'] ) );
		if ( $rep['shop']['anzahl'] ) {
			printf(
				' <span class="description">(%s)</span>',
				esc_html( sprintf(
					/* translators: 1: Anzahl Bestellungen, 2: über Kennung, 3: über Gutschein. */
					__( '%1$d Bestellungen – %2$d über Kampagnenkennung, %3$d über Gutschein', 'lindenweg16' ),
					$rep['shop']['anzahl'],
					$rep['shop']['per_utm'],
					$rep['shop']['per_gutschein']
				) )
			);
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Umsatz gesamt', 'lindenweg16' ) . '</th><td><strong>' . esc_html( self::euro( $rep['umsatz'] ) ) . '</strong></td></tr>';
		echo '<tr><th>' . esc_html__( 'Ergebnis (Umsatz − Kosten)', 'lindenweg16' ) . '</th><td><strong>' . esc_html( self::euro( $rep['ergebnis'] ) ) . '</strong></td></tr>';
		echo '<tr><th>' . esc_html__( 'Umsatz je Euro Werbekosten', 'lindenweg16' ) . '</th><td>' . esc_html( null === $rep['roas'] ? '–' : number_format( $rep['roas'], 2, ',', '.' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Kosten je Bestellung', 'lindenweg16' ) . '</th><td>' . esc_html( null === $rep['cpo'] ? '–' : self::euro( $rep['cpo'] ) ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<p class="description">' . esc_html( sprintf(
			/* translators: 1: Startdatum, 2: Enddatum. */
			__( 'Umsatz ausgewertet von %1$s bis %2$s (inklusive Nachlauffenster). Der Rohertrag lässt den Wareneinsatz außen vor – der hängt am Artikel, nicht an der Kampagne.', 'lindenweg16' ),
			$rep['zeitraum']['von'],
			$rep['zeitraum']['bis']
		) ) . '</p>';

		$this->render_km_form( $kampagne );
		$this->render_kosten_liste( $kampagne, $rep );
		$this->render_zuordnen_form( $kampagne );
	}

	/**
	 * Kilometergeld erfassen.
	 *
	 * @param object $kampagne Kostenstelle.
	 */
	protected function render_km_form( $kampagne ) {
		$satz    = (float) Settings::get( 'km_pauschale', 0.30 );
		$km      = (float) $kampagne->gefahrene_km;
		$betrag  = Calc::fahrtkosten( $km, $satz );
		?>
		<h3><?php esc_html_e( 'Fahrtkosten', 'lindenweg16' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_kampagne_km">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $kampagne->id ); ?>">
			<?php wp_nonce_field( 'lw16_kampagne_km_' . (int) $kampagne->id ); ?>
			<p>
				<label>
					<?php esc_html_e( 'Gefahrene Kilometer (Hin- und Rückweg)', 'lindenweg16' ); ?>
					<input type="number" step="0.1" min="0" name="gefahrene_km" class="small-text" value="<?php echo esc_attr( (string) $km ); ?>">
				</label>
				<?php submit_button( __( 'Fahrtkosten buchen', 'lindenweg16' ), 'secondary', '', false ); ?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: 1: Pauschale je km, 2: errechneter Betrag, 3: Konto. */
					esc_html__( 'Pauschale %1$s je km (in den Einstellungen änderbar) – das ergibt derzeit %2$s als Eigenbeleg auf Konto %3$s. Beim Ändern wird der bisherige Beleg storniert und neu gebucht.', 'lindenweg16' ),
					esc_html( number_format( $satz, 2, ',', '.' ) . ' €' ),
					esc_html( self::euro( $betrag ) ),
					esc_html( Kontenrahmen::label( Kontenrahmen::KONTO_FAHRTKOSTEN ) )
				);
				?>
			</p>
		</form>
		<?php
	}

	/**
	 * Zugeordnete Belege auflisten.
	 *
	 * @param object $kampagne Kostenstelle.
	 * @param array  $rep      Auswertung.
	 */
	protected function render_kosten_liste( $kampagne, array $rep ) {
		$belege = KampagnenRepository::belege( (int) $kampagne->id );

		echo '<h3>' . esc_html__( 'Zugeordnete Belege', 'lindenweg16' ) . '</h3>';

		if ( ! $belege ) {
			echo '<p>' . esc_html__( 'Noch nichts zugeordnet.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Beleg', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Händler', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Konto', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Betrag', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Anteil', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'davon Kampagne', 'lindenweg16' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $belege as $b ) {
			$konto = (string) $b->konto;
			if ( '' === $konto ) {
				$konto = Kontenrahmen::konto_fuer_kategorie( $b->eur_kategorie );
			}

			$loesen = wp_nonce_url(
				admin_url( 'admin-post.php?action=lw16_kampagne_loesen&id=' . (int) $kampagne->id . '&beleg=' . (int) $b->id ),
				'lw16_kampagne_loesen_' . (int) $kampagne->id . '_' . (int) $b->id
			);

			echo '<tr>';
			echo '<td><code>' . esc_html( $b->belegnummer ? $b->belegnummer : '#' . $b->id ) . '</code></td>';
			echo '<td>' . esc_html( $b->datum ) . '</td>';
			echo '<td>' . esc_html( $b->haendler ) . '</td>';
			echo '<td>' . esc_html( $konto ? Kontenrahmen::label( $konto ) : (string) $b->eur_kategorie ) . '</td>';
			echo '<td>' . esc_html( self::euro( $b->betrag ) ) . '</td>';
			echo '<td>' . esc_html( number_format( (float) $b->anteil_prozent, 1, ',', '.' ) . ' %' ) . '</td>';
			echo '<td>' . esc_html( self::euro( $b->anteil_betrag ) ) . '</td>';
			echo '<td><a href="' . esc_url( $loesen ) . '">' . esc_html__( 'lösen', 'lindenweg16' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $rep['kosten_konten'] ) {
			echo '<p><strong>' . esc_html__( 'Kosten nach Konto:', 'lindenweg16' ) . '</strong> ';
			$teile = array();
			foreach ( $rep['kosten_konten'] as $konto => $summe ) {
				$teile[] = ( 'ohne' === $konto ? __( 'ohne Konto', 'lindenweg16' ) : Kontenrahmen::label( $konto ) ) . ': ' . self::euro( $summe );
			}
			echo esc_html( implode( ' · ', $teile ) ) . '</p>';
		}
	}

	/**
	 * Beleg nachträglich zuordnen.
	 *
	 * @param object $kampagne Kostenstelle.
	 */
	protected function render_zuordnen_form( $kampagne ) {
		$offen = BelegRepository::query( array( 'per_page' => 100 ) );

		$ids = array();
		foreach ( $offen as $b ) {
			$ids[] = (int) $b->id;
		}
		$vergeben = KampagnenRepository::vergebene_anteile( $ids );
		?>
		<h3><?php esc_html_e( 'Beleg zuordnen', 'lindenweg16' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_kampagne_zuordnen">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $kampagne->id ); ?>">
			<?php wp_nonce_field( 'lw16_kampagne_zuordnen_' . (int) $kampagne->id ); ?>
			<p>
				<select name="beleg_id" required>
					<option value=""><?php esc_html_e( '– Beleg wählen –', 'lindenweg16' ); ?></option>
					<?php
					foreach ( $offen as $b ) {
						if ( (int) $b->storniert === 1 || 'storno' === $b->quelle ) {
							continue;
						}
						$frei = Kampagnenrechnung::freier_anteil( isset( $vergeben[ (int) $b->id ] ) ? $vergeben[ (int) $b->id ] : 0 );
						if ( $frei <= 0 ) {
							continue;
						}
						printf(
							'<option value="%d">%s</option>',
							(int) $b->id,
							esc_html( sprintf(
								'%s · %s · %s · %s (%s frei)',
								$b->belegnummer ? $b->belegnummer : '#' . $b->id,
								$b->datum,
								$b->haendler,
								self::euro( $b->betrag ),
								number_format( $frei, 0, ',', '.' ) . ' %'
							) )
						);
					}
					?>
				</select>
				<label>
					<?php esc_html_e( 'Anteil (%)', 'lindenweg16' ); ?>
					<input type="number" step="0.1" min="0" max="100" name="anteil" class="small-text" value="100">
				</label>
				<?php submit_button( __( 'Zuordnen', 'lindenweg16' ), 'secondary', '', false ); ?>
			</p>
			<p class="description"><?php esc_html_e( 'Ein Beleg lässt sich auf mehrere Kampagnen aufteilen – etwa eine Flyerrechnung für zwei Märkte. Über alle Kampagnen zusammen sind nie mehr als 100 % möglich.', 'lindenweg16' ); ?></p>
		</form>
		<?php
	}

	/* --------------------------------------------------------------------
	 * POST-Handler
	 * ------------------------------------------------------------------ */

	/**
	 * Kampagne anlegen oder speichern.
	 */
	public function handle_save_kampagne() {
		$this->guard( 'lw16_save_kampagne' );

		$id   = (int) ( $_POST['id'] ?? 0 );
		$data = array(
			'name'           => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'typ'            => sanitize_key( wp_unslash( $_POST['typ'] ?? 'event' ) ),
			'kanal'          => sanitize_text_field( wp_unslash( $_POST['kanal'] ?? '' ) ),
			'start_datum'    => $this->date_or_null( $_POST['start_datum'] ?? '' ),
			'ende_datum'     => $this->date_or_null( $_POST['ende_datum'] ?? '' ),
			'event_datum'    => $this->date_or_null( $_POST['event_datum'] ?? '' ),
			'budget_geplant' => max( 0, (float) ( $_POST['budget_geplant'] ?? 0 ) ),
			'utm_campaign'   => sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ?? '' ) ),
			'gutschein_code' => sanitize_text_field( wp_unslash( $_POST['gutschein_code'] ?? '' ) ),
			'status'         => sanitize_key( wp_unslash( $_POST['status'] ?? 'geplant' ) ),
			'notiz'          => sanitize_textarea_field( wp_unslash( $_POST['notiz'] ?? '' ) ),
		);

		if ( '' === $data['name'] ) {
			$this->redirect( __( 'Die Kampagne braucht einen Namen.', 'lindenweg16' ) );
		}

		if ( ! $data['start_datum'] ) {
			$data['start_datum'] = gmdate( 'Y-m-d' );
		}

		if ( $id ) {
			KampagnenRepository::update( $id, $data );
			$this->redirect_detail( $id, __( 'Kampagne gespeichert.', 'lindenweg16' ) );
		}

		$neu = KampagnenRepository::create( $data );
		$this->redirect_detail( $neu, __( 'Kampagne angelegt.', 'lindenweg16' ) );
	}

	/**
	 * Beleg zuordnen.
	 */
	public function handle_zuordnen() {
		$id = (int) ( $_POST['id'] ?? 0 );
		$this->guard( 'lw16_kampagne_zuordnen_' . $id );

		$beleg_id = (int) ( $_POST['beleg_id'] ?? 0 );
		$anteil   = (float) ( $_POST['anteil'] ?? 100 );

		if ( ! $beleg_id ) {
			$this->redirect_detail( $id, __( 'Kein Beleg gewählt.', 'lindenweg16' ) );
		}

		$zugeordnet = KampagnenRepository::zuordnen( $id, $beleg_id, $anteil );

		if ( $zugeordnet <= 0 ) {
			$this->redirect_detail( $id, __( 'Der Beleg ist bereits vollständig auf andere Kampagnen verteilt.', 'lindenweg16' ) );
		}

		$this->redirect_detail( $id, sprintf(
			/* translators: %s: Anteil in Prozent. */
			__( 'Beleg mit %s zugeordnet.', 'lindenweg16' ),
			number_format( $zugeordnet, 1, ',', '.' ) . ' %'
		) );
	}

	/**
	 * Zuordnung lösen.
	 */
	public function handle_loesen() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id       = (int) ( $_GET['id'] ?? 0 );
		$beleg_id = (int) ( $_GET['beleg'] ?? 0 );
		check_admin_referer( 'lw16_kampagne_loesen_' . $id . '_' . $beleg_id );

		KampagnenRepository::loesen( $id, $beleg_id );
		$this->redirect_detail( $id, __( 'Zuordnung gelöst.', 'lindenweg16' ) );
	}

	/**
	 * Kilometergeld als Eigenbeleg buchen.
	 *
	 * Ein bereits gebuchter Kilometerbeleg wird nicht überschrieben, sondern
	 * storniert und neu angelegt – der alte ist festgeschrieben.
	 */
	public function handle_km() {
		$id = (int) ( $_POST['id'] ?? 0 );
		$this->guard( 'lw16_kampagne_km_' . $id );

		$kampagne = KampagnenRepository::get( $id );
		if ( ! $kampagne ) {
			$this->redirect( __( 'Kampagne nicht gefunden.', 'lindenweg16' ) );
		}

		$km     = max( 0, (float) ( $_POST['gefahrene_km'] ?? 0 ) );
		$satz   = (float) Settings::get( 'km_pauschale', 0.30 );
		$betrag = Calc::fahrtkosten( $km, $satz );

		// Alten Kilometerbeleg zurücknehmen, damit nichts doppelt steht.
		if ( $kampagne->km_beleg_id ) {
			KampagnenRepository::loesen( $id, (int) $kampagne->km_beleg_id );
			BelegRepository::stornieren(
				(int) $kampagne->km_beleg_id,
				__( 'Kilometerangabe der Kampagne korrigiert', 'lindenweg16' )
			);
		}

		$beleg_id = null;
		if ( $betrag > 0 ) {
			$beleg_id = BelegRepository::create( array(
				'betrag'        => $betrag,
				'datum'         => $kampagne->event_datum ? $kampagne->event_datum : ( $kampagne->start_datum ? $kampagne->start_datum : gmdate( 'Y-m-d' ) ),
				'haendler'      => __( 'Kilometerpauschale (Eigenbeleg)', 'lindenweg16' ),
				'konto'         => Kontenrahmen::KONTO_FAHRTKOSTEN,
				'eur_kategorie' => 'Fahrtkosten',
				'notiz'         => sprintf(
					/* translators: 1: Kilometer, 2: Satz je km, 3: Kostenstelle, 4: Name. */
					__( '%1$s km × %2$s – Fahrt für %3$s %4$s', 'lindenweg16' ),
					number_format( $km, 1, ',', '.' ),
					number_format( $satz, 2, ',', '.' ) . ' €',
					$kampagne->kostenstelle,
					$kampagne->name
				),
				'quelle'        => 'kilometergeld',
			) );

			if ( $beleg_id ) {
				BelegRepository::festschreiben( $beleg_id );
				KampagnenRepository::zuordnen( $id, $beleg_id, 100 );
			}
		}

		KampagnenRepository::update( $id, array(
			'gefahrene_km' => $km,
			'km_beleg_id'  => $beleg_id,
		) );

		AuditLog::record( 'kampagne_km_gebucht', 'kampagne', $id, array(
			'km'       => $km,
			'satz'     => $satz,
			'betrag'   => $betrag,
			'beleg_id' => $beleg_id,
		) );

		$this->redirect_detail( $id, $betrag > 0
			? sprintf(
				/* translators: %s: Betrag. */
				__( 'Fahrtkosten gebucht: %s.', 'lindenweg16' ),
				self::euro( $betrag )
			)
			: __( 'Fahrtkosten zurückgenommen.', 'lindenweg16' )
		);
	}

	/* --------------------------------------------------------------------
	 * Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Datumsfeld prüfen – leer wird zu null, damit die Spalte NULL bleibt.
	 *
	 * @param string $raw Rohwert.
	 * @return string|null
	 */
	protected function date_or_null( $raw ) {
		$raw = sanitize_text_field( wp_unslash( (string) $raw ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : null;
	}

	/**
	 * Beschriftung eines Kampagnentyps.
	 *
	 * @param string $typ Typ.
	 * @return string
	 */
	public static function typ_label( $typ ) {
		$labels = array(
			'event'      => __( 'Event / Markt', 'lindenweg16' ),
			'online_ads' => __( 'Online-Anzeigen', 'lindenweg16' ),
			'print'      => __( 'Print', 'lindenweg16' ),
			'sonstiges'  => __( 'Sonstiges', 'lindenweg16' ),
		);
		return isset( $labels[ $typ ] ) ? $labels[ $typ ] : (string) $typ;
	}

	/**
	 * Beschriftung eines Status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'geplant'       => __( 'geplant', 'lindenweg16' ),
			'aktiv'         => __( 'aktiv', 'lindenweg16' ),
			'abgeschlossen' => __( 'abgeschlossen', 'lindenweg16' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Zeitraum als Text.
	 *
	 * @param object $k Kostenstelle.
	 * @return string
	 */
	public static function zeitraum_text( $k ) {
		if ( ! empty( $k->event_datum ) ) {
			return (string) $k->event_datum;
		}
		if ( ! empty( $k->ende_datum ) ) {
			return $k->start_datum . ' – ' . $k->ende_datum;
		}
		return (string) $k->start_datum;
	}

	/**
	 * Budgetampel als HTML.
	 *
	 * @param array $budget Ergebnis von Kampagnenrechnung::budget_status().
	 * @return string
	 */
	public static function ampel_html( array $budget ) {
		if ( 'kein_budget' === $budget['ampel'] ) {
			return '<span class="description">' . esc_html__( 'kein Budget gesetzt', 'lindenweg16' ) . '</span>';
		}

		$farben = array(
			'im_rahmen'  => '#46b450',
			'knapp'      => '#dba617',
			'ueberzogen' => '#dc3232',
		);
		$farbe = isset( $farben[ $budget['ampel'] ] ) ? $farben[ $budget['ampel'] ] : '#6E7882';

		return sprintf(
			'<span style="color:%s;font-weight:600">%s</span> <span class="description">(%s)</span>',
			esc_attr( $farbe ),
			esc_html( number_format( (float) $budget['auslastung'], 1, ',', '.' ) . ' %' ),
			esc_html( sprintf(
				/* translators: %s: Restbetrag. */
				$budget['rest'] < 0 ? __( '%s über Budget', 'lindenweg16' ) : __( '%s frei', 'lindenweg16' ),
				self::euro( abs( $budget['rest'] ) )
			) )
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
	 * Berechtigung und Nonce prüfen.
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
	 * Zurück zur Übersicht.
	 *
	 * @param string $msg Meldung.
	 */
	protected function redirect( $msg ) {
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'lw16-marketing', 'msg' => rawurlencode( $msg ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Zurück zur Einzelansicht.
	 *
	 * @param int    $id  Kostenstelle.
	 * @param string $msg Meldung.
	 */
	protected function redirect_detail( $id, $msg ) {
		wp_safe_redirect( add_query_arg(
			array(
				'page' => 'lw16-marketing',
				'view' => 'detail',
				'id'   => (int) $id,
				'msg'  => rawurlencode( $msg ),
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
