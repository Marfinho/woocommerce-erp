<?php
/**
 * Bambu-Lab-Drucker (LAN-Modus, angebunden über einen Bridge-Dienst auf
 * dem Raspberry Pi).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Bambu;

defined( 'ABSPATH' ) || exit;

/**
 * Verwaltet die registrierten Bambu-Drucker samt Bearer-Token und dem
 * zuletzt von der Bridge gemeldeten Live-Status.
 *
 * Dasselbe Token-Muster wie bei den Etikettendruckern (siehe
 * PrintQueue\PrinterRepository): Der Klartext wird nie gespeichert, nur
 * sein SHA-256-Hash. Der Klartext ist ausschließlich das Ergebnis von
 * create() bzw. regenerate_token() und wird sonst nirgends abgelegt.
 */
class BambuPrinterRepository {

	/**
	 * Nach so vielen Sekunden ohne Bridge-Kontakt gilt ein Drucker als
	 * offline. Etwas großzügiger als bei den Etikettendruckern, weil die
	 * Bridge nicht im Long-Poll steckt, sondern in festen Abständen meldet.
	 */
	const ONLINE_THRESHOLD_SECONDS = 120;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_bambu_printers';
	}

	/**
	 * Alle Drucker, nach Namen sortiert.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY name ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Einzelner Drucker per Datensatz-ID.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drucker per stabiler printer_id (z. B. bambu_p1s_1).
	 *
	 * @param string $printer_id Schlüssel.
	 * @return object|null
	 */
	public static function find_by_printer_id( $printer_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE printer_id = %s', (string) $printer_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Neues, zufälliges Bearer-Token erzeugen.
	 *
	 * @return string 64 Zeichen Hex (256 Bit Entropie).
	 */
	public static function generate_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Token hashen – reine Logik, ohne Datenbank.
	 *
	 * @param string $token Klartext-Token.
	 * @return string SHA-256-Hex.
	 */
	public static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Zeitsicherer Vergleich, siehe PrintQueue\PrinterRepository für die
	 * Begründung (hash_equals statt ===, gegen zeitbasiertes Erraten).
	 *
	 * @param string $token      Vorgelegtes Klartext-Token.
	 * @param string $token_hash Gespeicherter Hash.
	 * @return bool
	 */
	public static function token_matches( $token, $token_hash ) {
		if ( '' === (string) $token || '' === (string) $token_hash ) {
			return false;
		}
		return hash_equals( (string) $token_hash, self::hash_token( $token ) );
	}

	/**
	 * Drucker anhand des vorgelegten Bearer-Tokens auflösen. Ein voller
	 * Scan ist bei der überschaubaren Zahl an Druckern unbedenklich – und
	 * nur so bleibt jeder Vergleich zeitsicher.
	 *
	 * @param string $token Vorgelegtes Klartext-Token.
	 * @return object|null Nur aktive Drucker.
	 */
	public static function find_by_token( $token ) {
		if ( '' === trim( (string) $token ) ) {
			return null;
		}

		foreach ( self::all() as $printer ) {
			if ( ! (int) $printer->active ) {
				continue;
			}
			if ( self::token_matches( $token, $printer->token_hash ) ) {
				return $printer;
			}
		}

		return null;
	}

	/**
	 * Drucker anlegen.
	 *
	 * @param string $printer_id   Stabiler Schlüssel (z. B. bambu_p1s_1).
	 * @param string $name         Anzeigename.
	 * @param string $bambu_serial Bambu-Geräteseriennummer (aus der Drucker-App).
	 * @param string $ip           LAN-IP des Druckers.
	 * @param string $access_code  LAN-Zugangscode (vom Display des Druckers).
	 * @param int    $geraet_id    Optionale Verknüpfung zum Geräteeintrag
	 *                              der Kalkulation (wp_lw16_printers).
	 * @return array {id, token} Das Token wird nur hier im Klartext zurückgegeben.
	 */
	public static function create( $printer_id, $name, $bambu_serial = '', $ip = '', $access_code = '', $geraet_id = 0 ) {
		global $wpdb;

		$token = self::generate_token();

		$wpdb->insert(
			self::table(),
			array(
				'geraet_id'        => $geraet_id ? (int) $geraet_id : null,
				'printer_id'       => sanitize_key( $printer_id ),
				'name'             => sanitize_text_field( $name ),
				'bambu_serial'     => sanitize_text_field( $bambu_serial ),
				'ip'               => sanitize_text_field( $ip ),
				'access_code'      => sanitize_text_field( $access_code ),
				'token_hash'       => self::hash_token( $token ),
				'token_created_at' => current_time( 'mysql', true ),
				'active'           => 1,
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return array( 'id' => (int) $wpdb->insert_id, 'token' => $token );
	}

	/**
	 * Verbindungsdaten eines bestehenden Druckers ändern (IP, Zugangscode,
	 * Seriennummer, Name, Verknüpfung zum Kalkulationsgerät). Das Token
	 * bleibt unangetastet – dafür gibt es regenerate_token().
	 *
	 * @param int   $id   Datensatz-ID.
	 * @param array $data {name, bambu_serial, ip, access_code, geraet_id, active}
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		if ( ! self::find( $id ) ) {
			return false;
		}

		$update = array();
		if ( array_key_exists( 'name', $data ) ) {
			$update['name'] = sanitize_text_field( (string) $data['name'] );
		}
		if ( array_key_exists( 'bambu_serial', $data ) ) {
			$update['bambu_serial'] = sanitize_text_field( (string) $data['bambu_serial'] );
		}
		if ( array_key_exists( 'ip', $data ) ) {
			$update['ip'] = sanitize_text_field( (string) $data['ip'] );
		}
		// Ein leerer Zugangscode heißt "unverändert lassen", nicht "löschen":
		// Das Bearbeitungsformular zeigt den hinterlegten Code nicht an
		// (siehe BambuPrintersPage::render_card()), also käme er bei jedem
		// Speichern leer zurück und würde sonst den funktionierenden Code
		// überschreiben. Zum Entfernen dient das ausdrückliche Flag.
		if ( array_key_exists( 'access_code', $data ) && '' !== trim( (string) $data['access_code'] ) ) {
			$update['access_code'] = sanitize_text_field( (string) $data['access_code'] );
		} elseif ( ! empty( $data['access_code_loeschen'] ) ) {
			$update['access_code'] = '';
		}
		if ( array_key_exists( 'geraet_id', $data ) ) {
			$update['geraet_id'] = $data['geraet_id'] ? (int) $data['geraet_id'] : null;
		}
		if ( array_key_exists( 'active', $data ) ) {
			$update['active'] = ! empty( $data['active'] ) ? 1 : 0;
		}

		if ( ! $update ) {
			return true;
		}

		return false !== $wpdb->update( self::table(), $update, array( 'id' => (int) $id ) );
	}

	/**
	 * Drucker löschen (samt seiner Jobs – ein Job ohne Drucker ist sinnlos).
	 *
	 * @param int $id Datensatz-ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$printer = self::find( $id );
		if ( ! $printer ) {
			return false;
		}

		BambuJobRepository::delete_for_printer( $printer->printer_id );

		return false !== $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Neues Token für einen bestehenden Drucker erzeugen. Das alte Token
	 * wird dadurch sofort ungültig.
	 *
	 * @param int $id Datensatz-ID.
	 * @return string Neues Klartext-Token, oder leer bei unbekanntem Drucker.
	 */
	public static function regenerate_token( $id ) {
		global $wpdb;

		if ( ! self::find( $id ) ) {
			return '';
		}

		$token = self::generate_token();

		$wpdb->update(
			self::table(),
			array(
				'token_hash'       => self::hash_token( $token ),
				'token_created_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);

		return $token;
	}

	/**
	 * Heartbeat + Live-Status aktualisieren. Wird bei jedem POST /status
	 * der Bridge aufgerufen, unabhängig davon, ob sich der Zustand
	 * gegenüber der letzten Meldung geändert hat – sonst wirkt ein Drucker
	 * zwischen zwei echten Zustandswechseln fälschlich offline.
	 *
	 * @param int    $id   Datensatz-ID.
	 * @param array  $data {status, progress_percent, remaining_minutes,
	 *                       nozzle_temp, bed_temp, printer_model, last_error, ip}
	 */
	public static function apply_status( $id, array $data ) {
		global $wpdb;

		$update = array(
			'last_seen_at'   => current_time( 'mysql', true ),
			'last_status_at' => current_time( 'mysql', true ),
		);

		if ( array_key_exists( 'ip', $data ) && '' !== (string) $data['ip'] ) {
			$update['last_ip'] = substr( (string) $data['ip'], 0, 45 );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$update['status'] = sanitize_key( (string) $data['status'] );
		}
		if ( array_key_exists( 'progress_percent', $data ) && null !== $data['progress_percent'] ) {
			$update['progress_percent'] = max( 0, min( 100, (int) $data['progress_percent'] ) );
		}
		if ( array_key_exists( 'remaining_minutes', $data ) && null !== $data['remaining_minutes'] ) {
			$update['remaining_minutes'] = max( 0, (int) $data['remaining_minutes'] );
		}
		if ( array_key_exists( 'nozzle_temp', $data ) && null !== $data['nozzle_temp'] ) {
			$update['nozzle_temp'] = (float) $data['nozzle_temp'];
		}
		if ( array_key_exists( 'bed_temp', $data ) && null !== $data['bed_temp'] ) {
			$update['bed_temp'] = (float) $data['bed_temp'];
		}
		if ( array_key_exists( 'printer_model', $data ) && '' !== (string) $data['printer_model'] ) {
			$update['printer_model'] = sanitize_text_field( (string) $data['printer_model'] );
		}
		if ( array_key_exists( 'last_error', $data ) ) {
			$update['last_error'] = '' === (string) $data['last_error'] ? null : sanitize_textarea_field( (string) $data['last_error'] );
		}

		$wpdb->update( self::table(), $update, array( 'id' => (int) $id ) );
	}

	/**
	 * Gilt ein Drucker als online? Ein Heartbeat aus den letzten $seconds
	 * Sekunden reicht.
	 *
	 * @param object|null $printer Datensatz.
	 * @param int         $seconds Schwelle in Sekunden.
	 * @return bool
	 */
	public static function is_online( $printer, $seconds = self::ONLINE_THRESHOLD_SECONDS ) {
		if ( ! $printer || ! $printer->last_seen_at ) {
			return false;
		}

		$last = strtotime( $printer->last_seen_at . ' UTC' );

		if ( ! $last ) {
			return false;
		}

		return ( time() - $last ) <= (int) $seconds;
	}
}
