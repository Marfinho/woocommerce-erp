<?php
/**
 * Seite „Claude-Zugang“ – Schlüssel für den MCP-Zugang verwalten.
 *
 * Nur für Administratoren: Ein Schlüssel öffnet den ganzen Shop.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Mcp\Server;
use Lindenweg16\Mcp\Tokens;
use Lindenweg16\Mcp\Tools;

/**
 * Verwaltungsseite.
 */
class McpPage {

	const SLUG  = 'lw16-claude';
	const NONCE = 'lw16_mcp';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_page_lw16_claude', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_mcp_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_lw16_mcp_revoke', array( $this, 'handle_revoke' ) );
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Der Claude-Zugang ist Administratoren vorbehalten.', 'lindenweg16' ) );
		}
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg Meldung.
	 */
	protected function redirect( $msg = '' ) {
		wp_safe_redirect( add_query_arg( array_filter( array( 'page' => self::SLUG, 'lw16_msg' => $msg ? rawurlencode( $msg ) : '' ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Schlüssel anlegen.
	 */
	public function handle_create() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$write = ! empty( $_POST['write'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$token = Tokens::create( $label, get_current_user_id(), $write );

		// Klartext nur einmal zeigen: kurz für genau diese Person ablegen.
		set_transient( 'lw16_mcp_new_' . get_current_user_id(), $token, 10 * MINUTE_IN_SECONDS );
		AuditLog::record( 'mcp_schluessel_angelegt', 'mcp', 0, array( 'label' => $label, 'schreiben' => $write ) );

		$this->redirect();
	}

	/**
	 * Schlüssel löschen.
	 */
	public function handle_revoke() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Tokens::revoke( $id );
		AuditLog::record( 'mcp_schluessel_geloescht', 'mcp', 0, array( 'id' => $id ) );

		$this->redirect( __( 'Schlüssel gelöscht. Claude kommt damit nicht mehr hinein.', 'lindenweg16' ) );
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Claude-Zugang (MCP)', 'lindenweg16' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['lw16_msg'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		echo '<p style="max-width:800px">' . esc_html__( 'Über diesen Zugang kann Claude den Shop und das ERP lesen: Aufgaben, Bestellungen, Lager, Fertigung, Buchhaltung, Liquidität, Marketing – alles, was das Konto des Schlüssels sehen darf, dazu die Datenbank (nur lesend). Damit kann Claude dir jeden Morgen einen Lagebericht mit Handlungsanweisungen schreiben. Technisch ist es ein MCP-Server (Model Context Protocol).', 'lindenweg16' ) . '</p>';

		$this->render_new_token();
		$this->render_list();
		$this->render_create();
		$this->render_howto();

		echo '</div>';
	}

	/**
	 * Gerade angelegten Schlüssel einmal zeigen.
	 */
	protected function render_new_token() {
		$key   = 'lw16_mcp_new_' . get_current_user_id();
		$token = get_transient( $key );

		if ( ! is_string( $token ) || '' === $token ) {
			return;
		}

		delete_transient( $key );

		$cli = 'claude mcp add --transport http lindenweg16 ' . Server::url() . ' --header "Authorization: Bearer ' . $token . '"';

		echo '<div class="notice notice-warning" style="padding:12px 16px;max-width:900px">';
		echo '<p><strong>' . esc_html__( 'Neuer Schlüssel – jetzt kopieren, er wird nur dieses eine Mal angezeigt:', 'lindenweg16' ) . '</strong></p>';
		$this->copy_field( $token );

		echo '<p><strong>' . esc_html__( 'Adresse mit Schlüssel', 'lindenweg16' ) . '</strong> – ' . esc_html__( 'für claude.ai (Einstellungen → Connectors → Eigenen Connector hinzufügen). Wie ein Passwort behandeln:', 'lindenweg16' ) . '</p>';
		$this->copy_field( Server::url( $token ) );

		echo '<p><strong>' . esc_html__( 'Für Claude Code', 'lindenweg16' ) . '</strong> – ' . esc_html__( 'Schlüssel als Kopfzeile, die Adresse bleibt ohne Geheimnis:', 'lindenweg16' ) . '</p>';
		$this->copy_field( $cli );
		echo '</div>';
	}

	/**
	 * Feld zum Kopieren.
	 *
	 * @param string $value Wert.
	 */
	protected function copy_field( $value ) {
		printf(
			'<p><input type="text" readonly class="large-text code" value="%s" onclick="this.select()" /></p>',
			esc_attr( $value )
		);
	}

	/**
	 * Vorhandene Schlüssel.
	 */
	protected function render_list() {
		$list = Tokens::all();

		echo '<h2>' . esc_html__( 'Schlüssel', 'lindenweg16' ) . '</h2>';

		if ( ! $list ) {
			echo '<p>' . esc_html__( 'Noch kein Schlüssel – der Zugang ist geschlossen.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array( __( 'Bezeichnung', 'lindenweg16' ), __( 'Schlüssel', 'lindenweg16' ), __( 'Konto', 'lindenweg16' ), __( 'Darf', 'lindenweg16' ), __( 'Angelegt', 'lindenweg16' ), __( 'Zuletzt benutzt', 'lindenweg16' ), '' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $list as $entry ) {
			$user = get_userdata( (int) $entry['user_id'] );
			echo '<tr>';
			echo '<td>' . esc_html( $entry['label'] ) . '</td>';
			echo '<td><code>' . esc_html( Tokens::PREFIX . $entry['hint'] ) . '</code></td>';
			echo '<td>' . esc_html( $user ? $user->display_name : __( '(gelöscht)', 'lindenweg16' ) ) . '</td>';
			echo '<td>' . esc_html( ! empty( $entry['write'] ) ? __( 'lesen und ändern', 'lindenweg16' ) : __( 'nur lesen', 'lindenweg16' ) ) . '</td>';
			echo '<td>' . esc_html( wp_date( 'd.m.Y H:i', (int) $entry['created'] ) ) . '</td>';
			echo '<td>' . esc_html( $entry['last_used'] ? wp_date( 'd.m.Y H:i', (int) $entry['last_used'] ) : '–' ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Schlüssel wirklich löschen?', 'lindenweg16' ) ) . '\')">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="lw16_mcp_revoke" /><input type="hidden" name="id" value="' . esc_attr( $entry['id'] ) . '" />';
			echo '<button class="button button-link-delete">' . esc_html__( 'Löschen', 'lindenweg16' ) . '</button></form></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Formular für einen neuen Schlüssel.
	 */
	protected function render_create() {
		echo '<h2>' . esc_html__( 'Neuer Schlüssel', 'lindenweg16' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_mcp_create" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="lw16-mcp-label">' . esc_html__( 'Bezeichnung', 'lindenweg16' ) . '</label></th><td><input type="text" id="lw16-mcp-label" name="label" class="regular-text" placeholder="' . esc_attr__( 'z. B. Claude Tagesbericht', 'lindenweg16' ) . '" /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Rechte', 'lindenweg16' ) . '</th><td><label><input type="checkbox" name="write" value="1" /> ' . esc_html__( 'Darf auch ändern (Aufträge bestätigen, Status setzen, Bestände buchen …)', 'lindenweg16' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Für den täglichen Bericht reicht „nur lesen“. Auch mit Änderungsrecht bleiben Rechte, Konten, Plugins und Einstellungen gesperrt; jede Änderung steht im Protokoll.', 'lindenweg16' ) . '</p>';
		/* translators: %s: Name des Kontos. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Der Schlüssel arbeitet mit den Rechten deines Kontos (%s).', 'lindenweg16' ), wp_get_current_user()->display_name ) ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Schlüssel anlegen', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Anleitung für den täglichen Bericht.
	 */
	protected function render_howto() {
		echo '<h2>' . esc_html__( 'Täglicher Lagebericht', 'lindenweg16' ) . '</h2>';
		echo '<ol style="max-width:800px">';
		echo '<li>' . esc_html__( 'Schlüssel „nur lesen“ anlegen und die Adresse mit Schlüssel kopieren.', 'lindenweg16' ) . '</li>';
		echo '<li>' . esc_html__( 'In claude.ai: Einstellungen → Connectors → „Eigenen Connector hinzufügen“, Name „Lindenweg16 ERP“, die Adresse einfügen, OAuth-Felder leer lassen.', 'lindenweg16' ) . '</li>';
		echo '<li>' . esc_html__( 'Eine geplante Aufgabe anlegen (z. B. werktags 7:30 Uhr) und als Auftrag den Text unten einfügen. Den Connector für die Aufgabe einschalten.', 'lindenweg16' ) . '</li>';
		echo '</ol>';
		echo '<p>' . esc_html__( 'Auftrag für Claude (auch als Vorlage „tagesbericht“ im Connector):', 'lindenweg16' ) . '</p>';
		printf( '<p><textarea readonly class="large-text code" rows="14" onclick="this.select()">%s</textarea></p>', esc_textarea( Tools::daily_prompt() ) );
		echo '<p class="description">' . esc_html__( 'Ausführlich mit Claude Code und Fehlersuche: docs/claude-mcp.md im Plugin.', 'lindenweg16' ) . ' ' . esc_html__( 'Adresse ohne Schlüssel:', 'lindenweg16' ) . ' <code>' . esc_html( Server::url() ) . '</code></p>';
	}
}
