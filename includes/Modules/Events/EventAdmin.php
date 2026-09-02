<?php
/**
 * Backend des Event-Moduls: Eckdaten, variable Felder, QR-Code, Anmeldungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Events;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Modules\Betrieb\QrCode;
use Lindenweg16\Modules\Marketing\KampagnenRepository;

/**
 * Metaboxen und Aktionen rund um ein Event.
 */
class EventAdmin {

	/**
	 * Nonce-Aktion beim Speichern.
	 */
	const NONCE = 'lw16_event_speichern';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_boxes' ) );
		add_action( 'save_post_' . EventPostType::POST_TYPE, array( $this, 'save' ), 10, 2 );

		add_action( 'admin_post_lw16_event_qr', array( $this, 'download_qr' ) );
		add_action( 'admin_post_lw16_event_rsvp_export', array( $this, 'export_rsvp' ) );
		add_action( 'admin_post_lw16_event_rsvp_status', array( $this, 'set_rsvp_status' ) );

		add_filter( 'manage_' . EventPostType::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . EventPostType::POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
	}

	/* --------------------------------------------------------------------
	 * Metaboxen
	 * ------------------------------------------------------------------ */

	/**
	 * Boxen anmelden.
	 */
	public function add_boxes() {
		add_meta_box( 'lw16-event-eckdaten', __( 'Eckdaten', 'lindenweg16' ), array( $this, 'box_eckdaten' ), EventPostType::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'lw16-event-felder', __( 'Zusatzfelder', 'lindenweg16' ), array( $this, 'box_felder' ), EventPostType::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'lw16-event-rsvp', __( 'Anmeldungen', 'lindenweg16' ), array( $this, 'box_rsvp' ), EventPostType::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'lw16-event-qr', __( 'QR-Code', 'lindenweg16' ), array( $this, 'box_qr' ), EventPostType::POST_TYPE, 'side', 'default' );
	}

	/**
	 * Termin, Ort, Anmeldung.
	 *
	 * @param \WP_Post $post Beitrag.
	 */
	public function box_eckdaten( $post ) {
		$d = EventPostType::daten( $post->ID );
		wp_nonce_field( self::NONCE, 'lw16_event_nonce' );
		?>
		<table class="form-table"><tbody>
			<tr><th><label for="lw16-beginn"><?php esc_html_e( 'Beginn', 'lindenweg16' ); ?></label></th><td>
				<input type="datetime-local" id="lw16-beginn" name="lw16_beginn" value="<?php echo esc_attr( self::fuer_input( $d['beginn'] ) ); ?>">
			</td></tr>
			<tr><th><label for="lw16-ende"><?php esc_html_e( 'Ende', 'lindenweg16' ); ?></label></th><td>
				<input type="datetime-local" id="lw16-ende" name="lw16_ende" value="<?php echo esc_attr( self::fuer_input( $d['ende'] ) ); ?>">
			</td></tr>
			<tr><th><label for="lw16-ort"><?php esc_html_e( 'Ort', 'lindenweg16' ); ?></label></th><td>
				<input type="text" id="lw16-ort" class="large-text" name="lw16_ort" value="<?php echo esc_attr( $d['ort'] ); ?>">
			</td></tr>
			<tr><th><label for="lw16-treffpunkt"><?php esc_html_e( 'Treffpunkt', 'lindenweg16' ); ?></label></th><td>
				<input type="text" id="lw16-treffpunkt" class="large-text" name="lw16_treffpunkt" value="<?php echo esc_attr( $d['treffpunkt'] ); ?>">
			</td></tr>
			<tr><th><?php esc_html_e( 'Anmeldung', 'lindenweg16' ); ?></th><td>
				<label><input type="checkbox" name="lw16_rsvp_aktiv" value="1" <?php checked( $d['rsvp_aktiv'] ); ?>>
					<?php esc_html_e( 'Anmeldung auf der Eventseite anbieten', 'lindenweg16' ); ?></label>
			</td></tr>
			<tr><th><label for="lw16-max"><?php esc_html_e( 'Maximale Teilnehmerzahl', 'lindenweg16' ); ?></label></th><td>
				<input type="number" min="0" step="1" id="lw16-max" class="small-text" name="lw16_max_teilnehmer" value="<?php echo esc_attr( (string) $d['max_teilnehmer'] ); ?>">
				<p class="description"><?php esc_html_e( '0 oder leer bedeutet unbegrenzt. Ist die Zahl erreicht, landen weitere Anmeldungen auf der Warteliste und rücken bei einer Absage automatisch nach.', 'lindenweg16' ); ?></p>
			</td></tr>
			<?php $kampagnen = KampagnenRepository::options(); ?>
			<?php if ( $kampagnen ) : ?>
			<tr><th><label for="lw16-kampagne"><?php esc_html_e( 'Kostenstelle', 'lindenweg16' ); ?></label></th><td>
				<select id="lw16-kampagne" name="lw16_kampagne_id">
					<option value="0"><?php esc_html_e( '– keine –', 'lindenweg16' ); ?></option>
					<?php foreach ( $kampagnen as $kid => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $kid ); ?>" <?php selected( $d['kampagne_id'], $kid ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Verbindet das Event mit seiner Kampagne im Marketing – dort stehen dann Standgebühr, Fahrtkosten und Material des Events zusammen.', 'lindenweg16' ); ?></p>
			</td></tr>
			<?php endif; ?>
		</tbody></table>
		<?php
	}

	/**
	 * Variable Felder.
	 *
	 * @param \WP_Post $post Beitrag.
	 */
	public function box_felder( $post ) {
		$felder   = EventFields::get( $post->ID );
		$typen    = EventFields::typen();
		$vorlagen = EventFields::vorlagen();
		$typ_slug = EventPostType::typ_slug( $post->ID );

		// Bei einem noch feldlosen Event die Vorlage seines Typs anbieten.
		$vorlage = ( ! $felder && isset( $vorlagen[ $typ_slug ] ) ) ? $vorlagen[ $typ_slug ] : array();
		?>
		<p class="description">
			<?php esc_html_e( 'Beliebig viele eigene Felder je Event. Beim Typ „Komoot-Tour" die Adresse der Tour einfügen – auf der Eventseite erscheint daraus eine eingebettete Karte.', 'lindenweg16' ); ?>
		</p>

		<table class="widefat" id="lw16-felder-tabelle">
			<thead><tr>
				<th style="width:20%"><?php esc_html_e( 'Beschriftung', 'lindenweg16' ); ?></th>
				<th style="width:20%"><?php esc_html_e( 'Feldtyp', 'lindenweg16' ); ?></th>
				<th><?php esc_html_e( 'Inhalt', 'lindenweg16' ); ?></th>
				<th style="width:20%"><?php esc_html_e( 'Darstellung', 'lindenweg16' ); ?></th>
				<th style="width:5%"></th>
			</tr></thead>
			<tbody>
			<?php
			$zeilen = $felder ? $felder : $vorlage;
			foreach ( $zeilen as $i => $feld ) {
				$this->feld_zeile( (int) $i, $feld, $typen );
			}
			?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="lw16-feld-hinzu"><?php esc_html_e( 'Feld hinzufügen', 'lindenweg16' ); ?></button></p>

		<script type="text/html" id="lw16-feld-vorlage">
			<?php $this->feld_zeile( -1, array( 'label' => '', 'typ' => 'text', 'wert' => '' ), $typen ); ?>
		</script>
		<script>
		(function(){
			var tbody = document.querySelector('#lw16-felder-tabelle tbody');
			var knopf = document.getElementById('lw16-feld-hinzu');
			var vorlage = document.getElementById('lw16-feld-vorlage').innerHTML;
			var naechster = <?php echo (int) ( count( $zeilen ) + 1 ); ?>;

			knopf.addEventListener('click', function(){
				var html = vorlage.split('__I__').join(String(naechster));
				naechster++;
				var tr = document.createElement('tbody');
				tr.innerHTML = html;
				tbody.appendChild(tr.firstElementChild);
			});

			tbody.addEventListener('click', function(e){
				if (!e.target.classList.contains('lw16-feld-weg')) return;
				var zeile = e.target.closest('tr');
				if (zeile) zeile.remove();
			});

			// Die Darstellung gehoert nur zum Komoot-Typ. Das Feld bleibt im
			// Formular stehen und wird nur versteckt - sonst waere die
			// Einstellung nach einem versehentlichen Typwechsel weg.
			tbody.addEventListener('change', function(e){
				if (e.target.name && e.target.name.indexOf('[typ]') === -1) return;
				var zeile = e.target.closest('tr');
				if (!zeile) return;
				var auswahl = zeile.querySelector('.lw16-feld-option');
				if (auswahl) auswahl.hidden = (e.target.value !== 'komoot');
			});
		})();
		</script>
		<?php
	}

	/**
	 * Eine Feldzeile ausgeben.
	 *
	 * @param int   $i     Laufender Index, -1 für die Vorlage.
	 * @param array $feld  Felddaten.
	 * @param array $typen Verfügbare Feldtypen.
	 */
	protected function feld_zeile( $i, array $feld, array $typen ) {
		$idx  = $i < 0 ? '__I__' : (string) $i;
		$wert = isset( $feld['wert'] ) ? (string) $feld['wert'] : '';
		$typ  = isset( $feld['typ'] ) ? (string) $feld['typ'] : 'text';
		?>
		<tr>
			<td><input type="text" class="widefat" name="lw16_feld[<?php echo esc_attr( $idx ); ?>][label]"
				value="<?php echo esc_attr( isset( $feld['label'] ) ? $feld['label'] : '' ); ?>"></td>
			<td>
				<select class="widefat" name="lw16_feld[<?php echo esc_attr( $idx ); ?>][typ]">
					<?php foreach ( $typen as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $typ, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" class="widefat" name="lw16_feld[<?php echo esc_attr( $idx ); ?>][wert]"
				value="<?php echo esc_attr( $wert ); ?>"></td>
			<td>
				<?php
				// Steht im Markup immer, wird aber nur beim Komoot-Typ
				// eingeblendet: Ein Feld, das beim Speichern fehlt, verlöre
				// beim Umschalten des Typs seinen Wert.
				$option = isset( $feld['option'] ) ? (string) $feld['option'] : 'profil';
				?>
				<select class="widefat lw16-feld-option" name="lw16_feld[<?php echo esc_attr( $idx ); ?>][option]"
					<?php echo 'komoot' === $typ ? '' : 'hidden'; ?>>
					<?php foreach ( EventFields::komoot_darstellungen() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $option, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><button type="button" class="button-link lw16-feld-weg" title="<?php esc_attr_e( 'Feld entfernen', 'lindenweg16' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Anmeldeliste.
	 *
	 * @param \WP_Post $post Beitrag.
	 */
	public function box_rsvp( $post ) {
		$d        = EventPostType::daten( $post->ID );
		$alle     = RsvpRepository::all( $post->ID );
		$zugesagt = RsvpRepository::plaetze( $post->ID, RsvpRechnung::ZUGESAGT );

		echo '<p><strong>' . esc_html( RsvpRechnung::belegung_text( $d['max_teilnehmer'], $zugesagt ) ) . '</strong>';
		$frei = RsvpRechnung::freie_plaetze( $d['max_teilnehmer'], $zugesagt );
		if ( null !== $frei ) {
			echo ' – ' . esc_html( sprintf(
				/* translators: %d: freie Plätze. */
				_n( '%d Platz frei', '%d Plätze frei', $frei, 'lindenweg16' ),
				$frei
			) );
		}
		echo '</p>';

		if ( ! $alle ) {
			echo '<p>' . esc_html__( 'Noch keine Anmeldungen.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'E-Mail', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Begleitung', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Plätze', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Bestätigt', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Anmerkung', 'lindenweg16' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $alle as $r ) {
			echo '<tr>';
			$begleiter = RsvpRepository::begleiter( $r );

			echo '<td>' . esc_html( $r->name ) . '</td>';
			echo '<td>' . esc_html( $r->email ) . '</td>';
			echo '<td>' . esc_html( $begleiter ? implode( ', ', $begleiter ) : '–' ) . '</td>';
			echo '<td>' . esc_html( (string) max( 1, (int) $r->plaetze ) ) . '</td>';
			echo '<td>' . esc_html( self::status_label( $r->status ) ) . '</td>';
			echo '<td>' . esc_html( $r->bestaetigt_am ? $r->bestaetigt_am : '–' ) . '</td>';
			echo '<td>' . esc_html( $r->notiz ) . '</td>';
			echo '<td>';
			foreach ( array( RsvpRechnung::ZUGESAGT, RsvpRechnung::WARTELISTE, RsvpRechnung::ABGESAGT ) as $ziel ) {
				if ( $ziel === $r->status ) {
					continue;
				}
				$url = wp_nonce_url(
					admin_url( 'admin-post.php?action=lw16_event_rsvp_status&rsvp=' . (int) $r->id . '&status=' . $ziel ),
					'lw16_event_rsvp_status_' . (int) $r->id
				);
				printf( '<a href="%s" style="margin-right:6px">%s</a>', esc_url( $url ), esc_html( self::status_label( $ziel ) ) );
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$export = wp_nonce_url(
			admin_url( 'admin-post.php?action=lw16_event_rsvp_export&event=' . (int) $post->ID ),
			'lw16_event_rsvp_export_' . (int) $post->ID
		);
		echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Teilnehmerliste als CSV', 'lindenweg16' ) . '</a></p>';
	}

	/**
	 * QR-Code auf die Eventseite.
	 *
	 * @param \WP_Post $post Beitrag.
	 */
	public function box_qr( $post ) {
		if ( 'publish' !== $post->post_status ) {
			echo '<p>' . esc_html__( 'Der QR-Code entsteht, sobald das Event veröffentlicht ist – vorher steht seine Adresse noch nicht fest.', 'lindenweg16' ) . '</p>';
			return;
		}

		$code = self::qr_code( (int) $post->ID );

		if ( '' === $code['svg'] ) {
			echo '<p>' . esc_html__( 'Für diese Adresse lässt sich kein QR-Code erzeugen – sie ist zu lang. Ein kürzerer Beitragstitel hilft.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<div style="text-align:center">' . $code['svg'] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p style="word-break:break-all"><code>' . esc_html( $code['url'] ) . '</code></p>';

		$download = wp_nonce_url(
			admin_url( 'admin-post.php?action=lw16_event_qr&event=' . (int) $post->ID ),
			'lw16_event_qr_' . (int) $post->ID
		);
		echo '<p><a class="button" href="' . esc_url( $download ) . '">' . esc_html__( 'Als SVG herunterladen', 'lindenweg16' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'SVG lässt sich beliebig groß drucken – für Flyer, Plakat oder das Schild am Stand.', 'lindenweg16' ) . '</p>';
	}

	/* --------------------------------------------------------------------
	 * Speichern
	 * ------------------------------------------------------------------ */

	/**
	 * Eckdaten und Felder speichern.
	 *
	 * @param int      $post_id Beitrag.
	 * @param \WP_Post $post    Beitrag.
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['lw16_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lw16_event_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$vorher = EventPostType::daten( $post_id );

		update_post_meta( $post_id, EventPostType::META['beginn'], self::aus_input( $_POST['lw16_beginn'] ?? '' ) );
		update_post_meta( $post_id, EventPostType::META['ende'], self::aus_input( $_POST['lw16_ende'] ?? '' ) );
		update_post_meta( $post_id, EventPostType::META['ort'], sanitize_text_field( wp_unslash( $_POST['lw16_ort'] ?? '' ) ) );
		update_post_meta( $post_id, EventPostType::META['treffpunkt'], sanitize_text_field( wp_unslash( $_POST['lw16_treffpunkt'] ?? '' ) ) );
		update_post_meta( $post_id, EventPostType::META['rsvp_aktiv'], empty( $_POST['lw16_rsvp_aktiv'] ) ? 0 : 1 );
		update_post_meta( $post_id, EventPostType::META['kampagne_id'], (int) ( $_POST['lw16_kampagne_id'] ?? 0 ) );

		$max = max( 0, (int) ( $_POST['lw16_max_teilnehmer'] ?? 0 ) );
		update_post_meta( $post_id, EventPostType::META['max_teilnehmer'], $max );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$felder = isset( $_POST['lw16_feld'] ) && is_array( $_POST['lw16_feld'] ) ? wp_unslash( $_POST['lw16_feld'] ) : array();
		EventFields::save( $post_id, $felder );

		// Wurde die Grenze angehoben, dürfen Wartende sofort nachrücken.
		if ( $max > (int) $vorher['max_teilnehmer'] || 0 === $max ) {
			RsvpRepository::nachruecken( $post_id, $max );
		}

		AuditLog::record( 'event_gespeichert', 'event', (int) $post_id, array(
			'titel'          => $post->post_title,
			'rsvp_aktiv'     => empty( $_POST['lw16_rsvp_aktiv'] ) ? 0 : 1,
			'max_teilnehmer' => $max,
		) );
	}

	/* --------------------------------------------------------------------
	 * Aktionen
	 * ------------------------------------------------------------------ */

	/**
	 * QR-Code als SVG ausliefern.
	 */
	public function download_qr() {
		$event_id = (int) ( $_GET['event'] ?? 0 );
		$this->guard( 'lw16_event_qr_' . $event_id, $event_id );

		$code = self::qr_code( $event_id, 512 );
		if ( '' === $code['svg'] ) {
			wp_die( esc_html__( 'Für diese Adresse lässt sich kein QR-Code erzeugen – sie ist zu lang.', 'lindenweg16' ) );
		}

		$svg  = $code['svg'];
		$name = 'event-' . $event_id . '-qr.svg';

		nocache_headers();
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Teilnehmerliste als CSV.
	 */
	public function export_rsvp() {
		$event_id = (int) ( $_GET['event'] ?? 0 );
		$this->guard( 'lw16_event_rsvp_export_' . $event_id, $event_id );

		$zeilen = RsvpRepository::all( $event_id );

		// Eine Zeile je Person, nicht je Anmeldung: Am Start steht die
		// Begleitperson genauso da wie die, die sie angemeldet hat.
		// BOM, damit Tabellenprogramme die Umlaute richtig lesen.
		$csv  = "\xEF\xBB\xBF";
		$csv .= "Name;Angemeldet von;E-Mail;Status;Bestaetigt am;Anmerkung\r\n";

		// Die Namen kommen aus einem oeffentlichen Formular. Ein Wert, der mit
		// = + - @ beginnt, ist in Excel und Calc eine Formel - deshalb bekommt
		// er ein Hochkomma vorangestellt und bleibt Text.
		$feld = static function ( $wert ) {
			$wert = (string) $wert;
			if ( '' !== $wert && false !== strpos( '=+-@', $wert[0] ) ) {
				$wert = "'" . $wert;
			}
			return '"' . str_replace( '"', '""', $wert ) . '"';
		};

		foreach ( $zeilen as $r ) {
			$status = self::status_label( $r->status );

			$csv .= implode( ';', array(
				$feld( $r->name ),
				$feld( '' ),
				$feld( $r->email ),
				$feld( $status ),
				$feld( (string) $r->bestaetigt_am ),
				$feld( (string) $r->notiz ),
			) ) . "\r\n";

			foreach ( RsvpRepository::begleiter( $r ) as $begleiter ) {
				$csv .= implode( ';', array(
					$feld( $begleiter ),
					$feld( $r->name ),
					$feld( $r->email ),
					$feld( $status ),
					$feld( (string) $r->bestaetigt_am ),
					$feld( '' ),
				) ) . "\r\n";
			}
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="event-' . $event_id . '-teilnehmer.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Status einer Anmeldung von Hand ändern.
	 */
	public function set_rsvp_status() {
		global $wpdb;

		$rsvp_id = (int) ( $_GET['rsvp'] ?? 0 );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_event_rsvp_status_' . $rsvp_id );

		$t    = RsvpRepository::table();
		$rsvp = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $rsvp_id ) ); // phpcs:ignore
		if ( ! $rsvp ) {
			wp_die( esc_html__( 'Anmeldung nicht gefunden.', 'lindenweg16' ) );
		}

		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		RsvpRepository::set_status( $rsvp_id, $status );

		AuditLog::record( 'event_rsvp_status_geaendert', 'event', (int) $rsvp->event_id, array(
			'rsvp_id' => $rsvp_id,
			'status'  => $status,
		) );

		// Ein von Hand freigegebener Platz kann einen Nachrücker auslösen.
		$daten = EventPostType::daten( (int) $rsvp->event_id );
		RsvpRepository::nachruecken( (int) $rsvp->event_id, $daten['max_teilnehmer'] );

		wp_safe_redirect( get_edit_post_link( (int) $rsvp->event_id, 'redirect' ) );
		exit;
	}

	/* --------------------------------------------------------------------
	 * Listenspalten
	 * ------------------------------------------------------------------ */

	/**
	 * Spalten der Eventübersicht.
	 *
	 * @param array $spalten Spalten.
	 * @return array
	 */
	public function columns( $spalten ) {
		$neu = array();
		foreach ( $spalten as $key => $label ) {
			$neu[ $key ] = $label;
			if ( 'title' === $key ) {
				$neu['lw16_termin'] = __( 'Termin', 'lindenweg16' );
				$neu['lw16_rsvp']   = __( 'Anmeldungen', 'lindenweg16' );
			}
		}
		return $neu;
	}

	/**
	 * Inhalt der eigenen Spalten.
	 *
	 * @param string $spalte  Spaltenschlüssel.
	 * @param int    $post_id Beitrag.
	 */
	public function column( $spalte, $post_id ) {
		$d = EventPostType::daten( $post_id );

		if ( 'lw16_termin' === $spalte ) {
			echo esc_html( $d['beginn'] ? EventFrontend::termin_text( $d['beginn'], $d['ende'] ) : '–' );
			return;
		}

		if ( 'lw16_rsvp' === $spalte ) {
			if ( ! $d['rsvp_aktiv'] ) {
				echo '<span class="description">' . esc_html__( 'aus', 'lindenweg16' ) . '</span>';
				return;
			}
			$zugesagt = RsvpRepository::plaetze( $post_id, RsvpRechnung::ZUGESAGT );
			$wartend  = RsvpRepository::plaetze( $post_id, RsvpRechnung::WARTELISTE );
			echo esc_html( RsvpRechnung::belegung_text( $d['max_teilnehmer'], $zugesagt ) );
			if ( $wartend ) {
				echo '<br><span class="description">' . esc_html( sprintf(
					/* translators: %d: Wartende Personen. */
					_n( '%d auf der Warteliste', '%d auf der Warteliste', $wartend, 'lindenweg16' ),
					$wartend
				) ) . '</span>';
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * QR-Code auf ein Event, mit Kurzlink als Rückfallebene.
	 *
	 * Der Codierer bricht bei zu langem Inhalt ab, statt einen Code zu
	 * erzeugen, den kein Scanner liest. Ein langer Beitragstitel ergibt eine
	 * lange Adresse – dann greift der Kurzlink, der immer kurz ist.
	 *
	 * @param int $event_id Event.
	 * @param int $px       Kantenlänge.
	 * @return array {svg, url} – svg ist leer, wenn beides nicht ging.
	 */
	public static function qr_code( $event_id, $px = 180 ) {
		$kandidaten = array( get_permalink( $event_id ) );

		$kurz = wp_get_shortlink( $event_id );
		if ( $kurz && ! in_array( $kurz, $kandidaten, true ) ) {
			$kandidaten[] = $kurz;
		}

		foreach ( $kandidaten as $url ) {
			if ( ! $url ) {
				continue;
			}
			try {
				return array( 'svg' => QrCode::svg( $url, $px ), 'url' => $url );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return array( 'svg' => '', 'url' => '' );
	}

	/**
	 * Beschriftung eines Anmeldestatus.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			RsvpRechnung::OFFEN      => __( 'unbestätigt', 'lindenweg16' ),
			RsvpRechnung::ZUGESAGT   => __( 'zugesagt', 'lindenweg16' ),
			RsvpRechnung::WARTELISTE => __( 'Warteliste', 'lindenweg16' ),
			RsvpRechnung::ABGESAGT   => __( 'abgesagt', 'lindenweg16' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Gespeicherten Zeitpunkt für ein datetime-local-Feld aufbereiten.
	 *
	 * @param string $wert Y-m-d H:i:s.
	 * @return string
	 */
	public static function fuer_input( $wert ) {
		$wert = trim( (string) $wert );
		return $wert ? str_replace( ' ', 'T', substr( $wert, 0, 16 ) ) : '';
	}

	/**
	 * Wert eines datetime-local-Feldes zum Speichern normalisieren.
	 *
	 * @param string $roh Rohwert.
	 * @return string Y-m-d H:i oder leerer String.
	 */
	public static function aus_input( $roh ) {
		$roh = sanitize_text_field( wp_unslash( (string) $roh ) );
		$roh = str_replace( 'T', ' ', trim( $roh ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $roh ) ? $roh : '';
	}

	/**
	 * Berechtigung und Nonce prüfen.
	 *
	 * @param string $nonce    Nonce-Aktion.
	 * @param int    $event_id Event.
	 */
	protected function guard( $nonce, $event_id ) {
		if ( ! $event_id || ! current_user_can( 'edit_post', $event_id ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( $nonce );
	}
}
