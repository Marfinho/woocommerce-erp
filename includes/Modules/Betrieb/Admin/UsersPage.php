<?php
/**
 * Benutzer und Rechte.
 *
 * Drei Dinge an einer Stelle, weil sie zusammengehören: wer darf was, wer
 * hat was gemacht, und was kostet seine Stunde.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\AccessProfiles;
use Lindenweg16\Modules\Betrieb\BetriebAudit;
use Lindenweg16\Modules\Betrieb\CalcSettings;
use Lindenweg16\Modules\Betrieb\LaborCost;
use Lindenweg16\Modules\Betrieb\TimeRepository;
use Lindenweg16\Modules\Betrieb\UserActivity;
use Lindenweg16\Modules\Betrieb\WageRepository;

/**
 * Seite „Benutzer & Rechte".
 */
class UsersPage {

	const NONCE = 'lw16_betrieb_users';
	const SLUG  = 'lw16-betrieb-benutzer';

	/**
	 * Wie viele Protokollzeilen die Detailansicht zeigt.
	 */
	const LOG_LIMIT = 100;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_benutzer', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_user_profile', array( $this, 'handle_profile' ) );
		add_action( 'admin_post_lw16_betrieb_user_wage', array( $this, 'handle_wage' ) );
	}

	/**
	 * Rechte prüfen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( AccessProfiles::CAP_USERS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type success oder error.
	 * @param array  $args Zusatzparameter.
	 */
	protected function redirect( $msg, $type, array $args = array() ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'     => self::SLUG,
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
	 * Profil zuweisen.
	 */
	public function handle_profile() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$profile = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$args = array( 'user' => $user_id );

		if ( ! AccessProfiles::exists( $profile ) ) {
			$this->redirect( __( 'Dieses Profil gibt es nicht.', 'lindenweg16' ), 'error', $args );
		}

		// Sich selbst die Rechte zu nehmen, ist immer ein Versehen.
		if ( get_current_user_id() === $user_id ) {
			$this->redirect( __( 'Die eigenen Rechte lassen sich hier nicht ändern – dafür braucht es einen zweiten Verwalter.', 'lindenweg16' ), 'error', $args );
		}

		$before = Roles::betrieb_caps( $user_id );

		if ( ! Roles::set_betrieb_profile( $user_id, $profile ) ) {
			$this->redirect( __( 'Das Profil ließ sich nicht setzen.', 'lindenweg16' ), 'error', $args );
		}

		// Rechtevergabe ist protokollpflichtig wie jede andere Änderung.
		BetriebAudit::record(
			'update',
			'user_access',
			$user_id,
			array( 'caps' => $before ),
			array(
				'caps'    => AccessProfiles::caps( $profile ),
				'profile' => $profile,
			),
			sprintf(
				/* translators: %s: Profilname. */
				__( 'Profil %s zugewiesen.', 'lindenweg16' ),
				AccessProfiles::label( $profile )
			)
		);

		$this->redirect(
			sprintf(
				/* translators: %s: Profilname. */
				__( 'Profil %s zugewiesen.', 'lindenweg16' ),
				AccessProfiles::label( $profile )
			),
			'success',
			$args
		);
	}

	/**
	 * Stundenlohn festlegen.
	 */
	public function handle_wage() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$rate    = isset( $_POST['rate_per_h'] ) ? sanitize_text_field( wp_unslash( $_POST['rate_per_h'] ) ) : '';
		$from    = isset( $_POST['valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) ) : '';
		$note    = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = WageRepository::set( $user_id, $rate, $from, $note );

		$this->redirect( $result['message'], $result['success'] ? 'success' : 'error', array( 'user' => $user_id ) );
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$user_id = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0;
		$von     = isset( $_GET['von'] ) ? sanitize_text_field( wp_unslash( $_GET['von'] ) ) : gmdate( 'Y-m-01' );
		$bis     = isset( $_GET['bis'] ) ? sanitize_text_field( wp_unslash( $_GET['bis'] ) ) : gmdate( 'Y-m-d' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Benutzer & Rechte', 'lindenweg16' ) . '</h1>';
		$this->notice();

		if ( $user_id ) {
			$this->render_detail( $user_id, $von, $bis );
		} else {
			$this->render_list( $von, $bis );
		}

		echo '</div>';
	}

	/**
	 * Meldung nach einer Weiterleitung.
	 */
	protected function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['lw16_msg'] ) ) {
			return;
		}

		$msg = sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) );
		$typ = isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $typ ), esc_html( $msg ) );
	}

	/**
	 * Zeitraumwähler.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @param int    $user Nutzer, falls Detailansicht.
	 */
	protected function period_form( $von, $bis, $user = 0 ) {
		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		if ( $user ) {
			printf( '<input type="hidden" name="user" value="%d" />', (int) $user );
		}
		printf( '<input type="date" name="von" value="%s" /> ', esc_attr( $von ) );
		printf( '<input type="date" name="bis" value="%s" /> ', esc_attr( $bis ) );
		printf( '<button class="button">%s</button>', esc_html__( 'Zeitraum anzeigen', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Übersicht aller Nutzer.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 */
	protected function render_list( $von, $bis ) {
		global $wpdb;

		echo '<p>' . esc_html__( 'Wer im Modul Betrieb etwas darf, steht hier – mit seinem Rechteprofil, seiner erfassten Arbeitszeit im gewählten Zeitraum und dem, was er zuletzt getan hat.', 'lindenweg16' ) . '</p>';

		$this->period_form( $von, $bis );

		$entries = TimeRepository::between( $von, $bis );
		$wages   = WageRepository::all();
		$by_user = LaborCost::by_user( $entries, $wages );

		$table = BetriebAudit::table();
		$log   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE aktion LIKE 'betrieb_%%' AND erstellt_am BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$von . ' 00:00:00',
				$bis . ' 23:59:59'
			),
			ARRAY_A
		);

		$log_by_user = array();
		foreach ( (array) $log as $row ) {
			$log_by_user[ (int) $row['user_id'] ][] = $row;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Nutzer', 'lindenweg16' ),
			__( 'Profil', 'lindenweg16' ),
			__( 'Stundenlohn', 'lindenweg16' ),
			__( 'Stunden im Zeitraum', 'lindenweg16' ),
			__( 'Lohnkosten', 'lindenweg16' ),
			__( 'Vorgänge', 'lindenweg16' ),
			__( 'Zuletzt', 'lindenweg16' ),
			'',
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( Roles::betrieb_users() as $user ) {
			$id      = (int) $user->ID;
			$profile = Roles::betrieb_profile( $id );
			$rate    = WageRepository::current( $id );
			$cost    = isset( $by_user[ $id ] ) ? $by_user[ $id ] : null;
			$summary = UserActivity::summarize( isset( $log_by_user[ $id ] ) ? $log_by_user[ $id ] : array() );

			echo '<tr>';
			printf( '<td><strong>%s</strong><br /><span class="description">%s</span></td>', esc_html( $user->display_name ), esc_html( $user->user_email ) );

			printf(
				'<td>%s</td>',
				esc_html( '' === $profile ? __( 'eigene Zusammenstellung', 'lindenweg16' ) : AccessProfiles::label( $profile ) )
			);

			printf( '<td>%s</td>', esc_html( null === $rate ? '—' : number_format_i18n( $rate, 2 ) . ' €' ) );

			printf(
				'<td>%s</td>',
				esc_html( $cost ? number_format_i18n( $cost['hours'] + $cost['hours_unpriced'], 2 ) : '0' )
			);

			// Unbewertete Stunden werden genannt, nicht mit null bewertet.
			$lohn = '—';
			if ( $cost && ( $cost['hours'] > 0 || $cost['hours_unpriced'] > 0 ) ) {
				$lohn = number_format_i18n( $cost['cost'], 2 ) . ' €';
				if ( $cost['hours_unpriced'] > 0 ) {
					$lohn .= ' ' . sprintf(
						/* translators: %s: Stunden. */
						__( '(%s h ohne Lohn)', 'lindenweg16' ),
						number_format_i18n( $cost['hours_unpriced'], 2 )
					);
				}
			}
			printf( '<td>%s</td>', esc_html( $lohn ) );

			printf( '<td>%d</td>', (int) $summary['total'] );
			printf( '<td>%s</td>', esc_html( $summary['last'] ? mysql2date( 'd.m.Y H:i', $summary['last'] ) : '—' ) );

			printf(
				'<td><a class="button button-small" href="%s">%s</a></td>',
				esc_url( add_query_arg( array( 'page' => self::SLUG, 'user' => $id, 'von' => $von, 'bis' => $bis ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Ansehen', 'lindenweg16' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		$this->render_rate_comparison( $entries, $wages, $bis );
		$this->render_profile_legend();
	}

	/**
	 * Ist-Stundensatz gegen den kalkulatorischen Satz.
	 *
	 * @param array  $entries Zeitbuchungen.
	 * @param array  $wages   Lohnzeilen.
	 * @param string $bis     Stichtag für die Annahmen.
	 */
	protected function render_rate_comparison( array $entries, array $wages, $bis ) {
		$total      = LaborCost::total( $entries, $wages );
		$settings   = CalcSettings::for_date( $bis );
		$vergleich  = LaborCost::compare( $total['rate'], $settings['labor_rate_per_h'] );

		echo '<h2>' . esc_html__( 'Stundensatz: gerechnet gegen bezahlt', 'lindenweg16' ) . '</h2>';

		if ( ! $vergleich['known'] ) {
			echo '<p>' . esc_html__( 'Solange keine bewerteten Stunden im Zeitraum liegen, gibt es nichts zu vergleichen. Trage Stundenlöhne ein und erfasse Zeit – dann steht hier, ob die Kalkulation noch passt.', 'lindenweg16' ) . '</p>';
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: Ist-Satz, 2: kalkulatorischer Satz, 3: Abweichung. */
					__( 'Tatsächlich bezahlt wurden im Zeitraum %1$s €/h. Gerechnet wird mit %2$s €/h. Abweichung: %3$s.', 'lindenweg16' ),
					number_format_i18n( $vergleich['actual'], 2 ),
					number_format_i18n( $vergleich['calculated'], 2 ),
					number_format_i18n( $vergleich['diff_pct'] * 100, 1 ) . ' %'
				)
			)
		);

		printf(
			'<p><span class="lw16-ampel lw16-ampel--%s">%s</span></p>',
			esc_attr( $vergleich['level'] ),
			esc_html(
				'gruen' === $vergleich['level']
					? __( 'Die Kalkulation passt zu den Löhnen.', 'lindenweg16' )
					: __( 'Der kalkulatorische Stundensatz weicht spürbar ab.', 'lindenweg16' )
			)
		);

		echo '<p class="description">' . esc_html__( 'Der kalkulatorische Stundensatz wird bewusst nicht automatisch nachgezogen: Er steckt in jedem Preis, und ein Preis, der sich jeden Monat von selbst verschiebt, ist keiner. Ändern kannst du ihn unter Betrieb → Einstellungen mit einem Stichtag – dann bleiben die alten Kalkulationen, wie sie waren.', 'lindenweg16' ) . '</p>';

		if ( $total['hours_unpriced'] > 0 ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: Stunden. */
						__( 'Achtung: %s Stunden im Zeitraum haben keinen hinterlegten Lohn und stecken in dieser Rechnung nicht drin.', 'lindenweg16' ),
						number_format_i18n( $total['hours_unpriced'], 2 )
					)
				)
			);
		}
	}

	/**
	 * Was die Profile bedeuten.
	 */
	protected function render_profile_legend() {
		echo '<h2>' . esc_html__( 'Was die Profile dürfen', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Profil', 'lindenweg16' ) . '</th>';

		$caps = AccessProfiles::capabilities();
		foreach ( $caps as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( AccessProfiles::profiles() as $key => $profile ) {
			echo '<tr>';
			printf(
				'<td><strong>%s</strong><br /><span class="description">%s</span></td>',
				esc_html( $profile['label'] ),
				esc_html( $profile['hint'] )
			);

			foreach ( array_keys( $caps ) as $cap ) {
				printf(
					'<td>%s</td>',
					AccessProfiles::allows( $profile['caps'], $cap ) ? '<span aria-hidden="true">✓</span><span class="screen-reader-text">' . esc_html__( 'ja', 'lindenweg16' ) . '</span>' : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'nein', 'lindenweg16' ) . '</span>'
				);
			}
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Die einzelnen Rechte', 'lindenweg16' ) . '</h3><ul class="ul-disc">';
		foreach ( AccessProfiles::explanations() as $cap => $text ) {
			printf( '<li><strong>%s</strong> – %s</li>', esc_html( $caps[ $cap ] ), esc_html( $text ) );
		}
		echo '</ul>';
	}

	/**
	 * Ein einzelner Nutzer.
	 *
	 * @param int    $user_id Nutzer.
	 * @param string $von     Von.
	 * @param string $bis     Bis.
	 */
	protected function render_detail( $user_id, $von, $bis ) {
		global $wpdb;

		$user = get_userdata( $user_id );

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) ),
			esc_html__( '← Alle Benutzer', 'lindenweg16' )
		);

		if ( ! $user ) {
			echo '<p>' . esc_html__( 'Diesen Nutzer gibt es nicht mehr.', 'lindenweg16' ) . '</p>';
			return;
		}

		printf( '<h2>%s</h2>', esc_html( $user->display_name ) );

		$this->render_profile_form( $user_id );
		$this->render_wage_form( $user_id );

		$this->period_form( $von, $bis, $user_id );

		$table = BetriebAudit::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND aktion LIKE 'betrieb_%%' ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				self::LOG_LIMIT
			),
			ARRAY_A
		);

		$summary = UserActivity::summarize( (array) $rows );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: Anzahl, 2: Bereiche. */
					__( '%1$d protokollierte Vorgänge, vor allem: %2$s', 'lindenweg16' ),
					$summary['total'],
					$summary['areas'] ? UserActivity::areas_label( $summary['areas'] ) : __( 'noch nichts', 'lindenweg16' )
				)
			)
		);

		$this->render_time( $user_id, $von, $bis );

		echo '<h3>' . esc_html__( 'Protokoll', 'lindenweg16' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Die letzten Vorgänge dieses Nutzers aus dem hashverketteten Protokoll. Es sagt, was geschah – nicht, ob es richtig war.', 'lindenweg16' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Zeit', 'lindenweg16' ), __( 'Vorgang', 'lindenweg16' ), __( 'Begründung', 'lindenweg16' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="3">' . esc_html__( 'Für diesen Nutzer ist nichts protokolliert.', 'lindenweg16' ) . '</td></tr>';
		}

		foreach ( (array) $rows as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( mysql2date( 'd.m.Y H:i', $row['erstellt_am'] ) ),
				esc_html( UserActivity::describe( $row ) ),
				esc_html( empty( $row['grund'] ) ? '—' : $row['grund'] )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Zeiterfassung eines Nutzers im Zeitraum.
	 *
	 * @param int    $user_id Nutzer.
	 * @param string $von     Von.
	 * @param string $bis     Bis.
	 */
	protected function render_time( $user_id, $von, $bis ) {
		$wages  = WageRepository::all();
		$labels = TimeRepository::category_labels();

		$entries = array_filter(
			TimeRepository::between( $von, $bis ),
			static function ( $entry ) use ( $user_id ) {
				return (int) $entry['created_by'] === (int) $user_id;
			}
		);

		$total = LaborCost::total( $entries, $wages );

		echo '<h3>' . esc_html__( 'Erfasste Zeit im Zeitraum', 'lindenweg16' ) . '</h3>';

		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'In diesem Zeitraum hat dieser Nutzer keine Zeit erfasst.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Datum', 'lindenweg16' ), __( 'Kategorie', 'lindenweg16' ), __( 'Beschreibung', 'lindenweg16' ), __( 'Stunden', 'lindenweg16' ), __( 'Kosten', 'lindenweg16' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$cost = LaborCost::entry_cost( $entry, $wages );

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( mysql2date( 'd.m.Y', $entry['entry_date'] ) ),
				esc_html( isset( $labels[ $entry['category'] ] ) ? $labels[ $entry['category'] ] : $entry['category'] ),
				esc_html( (string) $entry['description'] ),
				esc_html( number_format_i18n( (float) $entry['hours'], 2 ) ),
				esc_html( null === $cost ? __( 'kein Lohn hinterlegt', 'lindenweg16' ) : number_format_i18n( $cost, 2 ) . ' €' )
			);
		}

		printf(
			'<tr><th colspan="3">%s</th><th>%s</th><th>%s</th></tr>',
			esc_html__( 'Summe', 'lindenweg16' ),
			esc_html( number_format_i18n( $total['hours'] + $total['hours_unpriced'], 2 ) ),
			esc_html( number_format_i18n( $total['cost'], 2 ) . ' €' )
		);

		echo '</tbody></table>';
	}

	/**
	 * Profil zuweisen.
	 *
	 * @param int $user_id Nutzer.
	 */
	protected function render_profile_form( $user_id ) {
		$current = Roles::betrieb_profile( $user_id );

		echo '<h3>' . esc_html__( 'Rechte', 'lindenweg16' ) . '</h3>';

		if ( get_current_user_id() === (int) $user_id ) {
			echo '<p>' . esc_html__( 'Das sind deine eigenen Rechte. Ändern kann sie ein anderer Verwalter – so kann sich niemand versehentlich selbst aussperren.', 'lindenweg16' ) . '</p>';
			return;
		}

		if ( user_can( $user_id, LW16_CAPABILITY ) ) {
			echo '<p>' . esc_html__( 'Dieser Nutzer ist Administrator der Anlage und hat darüber ohnehin vollen Zugriff. Ein Profil hier würde daran nichts ändern.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_user_profile" />';
		printf( '<input type="hidden" name="user_id" value="%d" />', (int) $user_id );

		echo '<p><select name="profile">';
		foreach ( AccessProfiles::profiles() as $key => $profile ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				esc_html( $profile['label'] )
			);
		}
		echo '</select> ';
		printf( '<button class="button button-primary">%s</button></p>', esc_html__( 'Profil zuweisen', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Stundenlohn festlegen und Verlauf zeigen.
	 *
	 * @param int $user_id Nutzer.
	 */
	protected function render_wage_form( $user_id ) {
		echo '<h3>' . esc_html__( 'Stundenlohn', 'lindenweg16' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Ein Lohn gilt ab seinem Stichtag und wird nicht rückwirkend geändert. Eine Erhöhung ist deshalb eine neue Zeile, keine Korrektur – sonst würde jede vergangene Auswertung mit umgeschrieben.', 'lindenweg16' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_user_wage" />';
		printf( '<input type="hidden" name="user_id" value="%d" />', (int) $user_id );

		echo '<p>';
		printf( '<input type="text" name="rate_per_h" placeholder="%s" style="width:8em" /> ', esc_attr__( '€ je Stunde', 'lindenweg16' ) );
		printf( '<input type="date" name="valid_from" value="%s" /> ', esc_attr( gmdate( 'Y-m-01' ) ) );
		printf( '<input type="text" name="note" placeholder="%s" class="regular-text" /> ', esc_attr__( 'Bemerkung, z. B. Tariferhöhung', 'lindenweg16' ) );
		printf( '<button class="button">%s</button>', esc_html__( 'Lohn festlegen', 'lindenweg16' ) );
		echo '</p></form>';

		$history = WageRepository::history( $user_id );

		if ( ! $history ) {
			echo '<p>' . esc_html__( 'Für diesen Nutzer ist kein Stundenlohn hinterlegt. Seine Stunden erscheinen in den Auswertungen dann als unbewertet – nicht als kostenlos.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:44em"><thead><tr>';
		foreach ( array( __( 'gültig ab', 'lindenweg16' ), __( '€ je Stunde', 'lindenweg16' ), __( 'Bemerkung', 'lindenweg16' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $history as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( mysql2date( 'd.m.Y', $row['valid_from'] ) ),
				esc_html( number_format_i18n( (float) $row['rate_per_h'], 2 ) ),
				esc_html( empty( $row['note'] ) ? '—' : $row['note'] )
			);
		}

		echo '</tbody></table>';
	}
}
