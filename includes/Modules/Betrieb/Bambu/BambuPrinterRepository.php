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
	 * Druckerzustände (gcode_state, kleingeschrieben), in denen ein Druck
	 * läuft oder vorbereitet wird – ab hier ist die Platte belegt.
	 */
	const PRINTING_STATES = array( 'prepare', 'running', 'pause', 'slicing' );

	/**
	 * Meldet dieser Zustand einen laufenden Druck?
	 *
	 * @param string $status Zustand laut Bridge.
	 * @return bool
	 */
	public static function is_printing_status( $status ) {
		return in_array( strtolower( trim( (string) $status ) ), self::PRINTING_STATES, true );
	}

	/**
	 * Ist die Platte als frei bestätigt? Nur dann liefert die Warteschlange
	 * einen Job aus.
	 *
	 * @param object|null $printer Datensatz.
	 * @return bool
	 */
	public static function bed_is_clear( $printer ) {
		return $printer && ! empty( $printer->bed_clear );
	}

	/**
	 * Platte als belegt markieren – sobald ein Druck beginnt.
	 *
	 * @param string $printer_id Stabiler Schlüssel.
	 */
	public static function mark_bed_busy( $printer_id ) {
		global $wpdb;

		$wpdb->update( self::table(), array( 'bed_clear' => 0 ), array( 'printer_id' => (string) $printer_id ) );
	}

	/**
	 * „Platte ist frei“: Jemand hat am Drucker nachgesehen und die Platte
	 * geräumt. Abgelehnt, solange der Drucker einen laufenden Druck meldet –
	 * die nächste Statusmeldung setzte die Sperre ohnehin sofort zurück.
	 *
	 * @param int $id Datensatz-ID.
	 * @return array {ok, error} error: not_found oder printing.
	 */
	public static function mark_bed_clear( $id ) {
		global $wpdb;

		$printer = self::find( $id );

		if ( ! $printer ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}

		if ( self::is_online( $printer ) && self::is_printing_status( $printer->status ) ) {
			return array( 'ok' => false, 'error' => 'printing' );
		}

		$wpdb->update(
			self::table(),
			array(
				'bed_clear'      => 1,
				'bed_cleared_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);

		return array( 'ok' => true, 'error' => '' );
	}

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

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'geraet_id'        => $geraet_id ? (int) $geraet_id : null,
				'printer_id'       => substr( sanitize_key( $printer_id ), 0, 50 ),
				'name'             => mb_substr( sanitize_text_field( $name ), 0, 255 ),
				'bambu_serial'     => mb_substr( sanitize_text_field( $bambu_serial ), 0, 50 ),
				'ip'               => substr( sanitize_text_field( $ip ), 0, 45 ),
				'access_code'      => mb_substr( sanitize_text_field( $access_code ), 0, 20 ),
				'token_hash'       => self::hash_token( $token ),
				'token_created_at' => current_time( 'mysql', true ),
				'active'           => 1,
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		// $wpdb->insert() scheitert standardmässig lautlos (z. B. wenn die
		// Tabelle - etwa nach einem verpassten Schema-Upgrade - noch gar
		// nicht existiert): kein Fehler, kein Abbruch, nur insert_id bleibt
		// 0. Ohne diese Prüfung hätte der Aufrufer "Drucker angelegt"
		// gemeldet, obwohl in Wahrheit nichts gespeichert wurde - genau der
		// Fehlerbericht "Drucker angelegt, aber taucht nicht in der Liste
		// auf" wäre die Folge.
		if ( false === $inserted ) {
			return array( 'id' => 0, 'token' => '', 'error' => $wpdb->last_error );
		}

		return array( 'id' => (int) $wpdb->insert_id, 'token' => $token, 'error' => '' );
	}

	/**
	 * Verbindungsdaten eines bestehenden Druckers ändern (IP, Zugangscode,
	 * Seriennummer, Name, Verknüpfung zum Kalkulationsgerät). Das Token
	 * bleibt unangetastet – dafür gibt es regenerate_token().
	 *
	 * @param int   $id   Datensatz-ID.
	 * @param array $data {name, bambu_serial, ip, access_code, geraet_id,
	 *                      bed_width_mm, bed_height_mm, active}
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		if ( ! self::find( $id ) ) {
			return false;
		}

		$update = array();
		// Gekürzt wird auf die zugesagte Spaltenbreite – sonst weist MySQL
		// im strict mode das ganze UPDATE zurück, und das Speichern
		// scheiterte an einem zu langen Namen, ohne es zu sagen.
		if ( array_key_exists( 'name', $data ) ) {
			$update['name'] = mb_substr( sanitize_text_field( (string) $data['name'] ), 0, 255 );
		}
		if ( array_key_exists( 'bambu_serial', $data ) ) {
			$update['bambu_serial'] = mb_substr( sanitize_text_field( (string) $data['bambu_serial'] ), 0, 50 );
		}
		if ( array_key_exists( 'ip', $data ) ) {
			$update['ip'] = substr( sanitize_text_field( (string) $data['ip'] ), 0, 45 );
		}
		// Ein leerer Zugangscode heißt "unverändert lassen", nicht "löschen":
		// Das Bearbeitungsformular zeigt den hinterlegten Code nicht an
		// (siehe BambuPrintersPage::render_card()), also käme er bei jedem
		// Speichern leer zurück und würde sonst den funktionierenden Code
		// überschreiben. Zum Entfernen dient das ausdrückliche Flag.
		if ( array_key_exists( 'access_code', $data ) && '' !== trim( (string) $data['access_code'] ) ) {
			$update['access_code'] = mb_substr( sanitize_text_field( (string) $data['access_code'] ), 0, 20 );
		} elseif ( ! empty( $data['access_code_loeschen'] ) ) {
			$update['access_code'] = '';
		}
		if ( array_key_exists( 'geraet_id', $data ) ) {
			$update['geraet_id'] = $data['geraet_id'] ? (int) $data['geraet_id'] : null;
		}
		if ( array_key_exists( 'bed_width_mm', $data ) ) {
			$update['bed_width_mm'] = $data['bed_width_mm'] ? max( 1, (int) $data['bed_width_mm'] ) : null;
		}
		if ( array_key_exists( 'bed_height_mm', $data ) ) {
			$update['bed_height_mm'] = $data['bed_height_mm'] ? max( 1, (int) $data['bed_height_mm'] ) : null;
		}
		// Rückfallwert, falls die Bridge noch kein erkennbares Modell
		// gemeldet hat (siehe effective_bed_size()) und für die
		// Slicing-Profil-Zuordnung (SlicingService::printer_model_for(),
		// SlicingProfileRepository::find_for() - exakter String-Vergleich
		// mit dem beim Profil-Upload eingetragenen Modell, siehe
		// SlicingProfilesPage). Meldet die Bridge später selbst ein Modell
		// (report_status()), überschreibt das diesen Wert wieder - dann
		// muss das Slicing-Profil auf den gemeldeten Wortlaut umgestellt
		// werden.
		if ( array_key_exists( 'printer_model', $data ) ) {
			$update['printer_model'] = '' !== trim( (string) $data['printer_model'] )
				? mb_substr( sanitize_text_field( (string) $data['printer_model'] ), 0, 100 )
				: null;
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
	 * Wirksames Druckbett-Innenmass eines Druckers: eigens hinterlegtes
	 * Mass hat Vorrang, sonst die Schätzung nach gemeldetem Druckermodell
	 * (siehe BedSizeLookup).
	 *
	 * @param object $printer Datensatz.
	 * @return array|null {width_mm, height_mm} oder null, wenn beides fehlt.
	 */
	public static function effective_bed_size( $printer ) {
		if ( ! $printer ) {
			return null;
		}

		if ( ! empty( $printer->bed_width_mm ) && ! empty( $printer->bed_height_mm ) ) {
			return array(
				'width_mm'  => (int) $printer->bed_width_mm,
				'height_mm' => (int) $printer->bed_height_mm,
			);
		}

		return BedSizeLookup::match( isset( $printer->printer_model ) ? $printer->printer_model : '' );
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
		// Jeder Wert wird auf das zugesagte Format der Spalte gebracht.
		// Die Werte stammen aus einer HTTP-Anfrage der Bridge: Käme ein zu
		// langer Text oder eine unsinnig große Zahl an, wiese MySQL im
		// strict mode das gesamte UPDATE zurück – und der Drucker verlöre
		// stillschweigend seinen Heartbeat, statt nur einen krummen Wert
		// zu zeigen.
		if ( array_key_exists( 'status', $data ) ) {
			$update['status'] = substr( sanitize_key( (string) $data['status'] ), 0, 20 );

			// Jeder Druck belegt die Platte – auch einer, der von Hand am
			// Gerät, aus Bambu Studio oder der App gestartet wurde.
			if ( self::is_printing_status( $update['status'] ) ) {
				$update['bed_clear'] = 0;
			}
		}
		if ( array_key_exists( 'progress_percent', $data ) && null !== $data['progress_percent'] ) {
			$update['progress_percent'] = max( 0, min( 100, (int) $data['progress_percent'] ) );
		}
		if ( array_key_exists( 'remaining_minutes', $data ) && null !== $data['remaining_minutes'] ) {
			$update['remaining_minutes'] = max( 0, min( 65535, (int) $data['remaining_minutes'] ) );
		}
		if ( array_key_exists( 'nozzle_temp', $data ) && null !== $data['nozzle_temp'] ) {
			$update['nozzle_temp'] = self::clamp_temp( $data['nozzle_temp'] );
		}
		if ( array_key_exists( 'bed_temp', $data ) && null !== $data['bed_temp'] ) {
			$update['bed_temp'] = self::clamp_temp( $data['bed_temp'] );
		}
		if ( array_key_exists( 'printer_model', $data ) && '' !== (string) $data['printer_model'] ) {
			$update['printer_model'] = mb_substr( sanitize_text_field( (string) $data['printer_model'] ), 0, 100 );
		}
		if ( array_key_exists( 'last_error', $data ) ) {
			$update['last_error'] = '' === (string) $data['last_error']
				? null
				: mb_substr( sanitize_textarea_field( (string) $data['last_error'] ), 0, 1000 );
		}
		// Name der Datei auf dem Drucker. Leer lässt den letzten Namen stehen:
		// Bambu sendet Teilreports, ein fehlender Name heißt nicht „keine Datei“.
		if ( array_key_exists( 'current_file', $data ) && '' !== trim( (string) $data['current_file'] ) ) {
			$update['current_file'] = mb_substr( sanitize_text_field( (string) $data['current_file'] ), 0, 255 );
		}
		if ( array_key_exists( 'ams', $data ) ) {
			$update['ams_json'] = self::normalize_ams_json( $data['ams'] );

			if ( $update['ams_json'] ) {
				// In den Bambu-Filament-Katalog übernehmen (siehe
				// BambuFilamentCatalogRepository) - jedes AMS-Fach mit
				// erkannter RFID-Filament-ID wird dort gesammelt, damit
				// Artikelrevisionen und Slicing-Profile später aus einer
				// Auswahl echter, schon einmal gesehener Bambu-Filamente
				// wählen können statt Freitext einzutippen.
				BambuFilamentCatalogRepository::observe_ams_json( $update['ams_json'] );
			}
		}

		$wpdb->update( self::table(), $update, array( 'id' => (int) $id ) );
	}

	/**
	 * AMS-Rohdaten aus der Bridge auf ein sicher speicherbares JSON bringen.
	 *
	 * Kommt als bereits von der Bridge JSON-kodierter String an (sie liest
	 * die Werte selbst aus dem MQTT-Report). Nur strukturell prüfen und der
	 * Länge nach begrenzen - der genaue Aufbau (Material, Farbe, Restmenge
	 * je AMS-Fach) ist reine Anzeigesache, keine sicherheitsrelevante
	 * Auswertung hier nötig.
	 *
	 * @param mixed $roh Rohwert aus der Anfrage.
	 * @return string|null
	 */
	public static function normalize_ams_json( $roh ) {
		$roh = (string) $roh;

		if ( '' === $roh ) {
			return null;
		}

		// Nur speichern, was auch tatsächlich gültiges JSON ist - eine
		// kaputte oder unerwartet riesige Nutzlast soll nicht ungeprüft in
		// der Datenbank landen und beim Anzeigen erneut geparst werden.
		if ( null === json_decode( $roh, true ) || JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		return mb_substr( $roh, 0, 20000 );
	}

	/**
	 * Zeitpunkt eines neu gespeicherten Kamera-Standbilds vermerken - die
	 * Datei selbst liegt im Dokumentenordner (siehe BambuService::
	 * save_snapshot()), hier nur der Zeitstempel fürs Cache-Busting beim
	 * Anzeigen.
	 *
	 * @param int $id Primärschlüssel.
	 */
	public static function mark_snapshot( $id ) {
		global $wpdb;

		$wpdb->update( self::table(), array( 'snapshot_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) );
	}

	/**
	 * Zuletzt gemeldete AMS-Belegung eines Druckers, zum Anzeigen entpackt.
	 *
	 * @param object $printer Datensatz.
	 * @return array Liste von AMS-Einheiten ({id, humidity, temp, trays}),
	 *               leer wenn keine (mehr) gemeldet.
	 */
	public static function ams_units( $printer ) {
		if ( empty( $printer->ams_json ) ) {
			return array();
		}

		$daten = json_decode( (string) $printer->ams_json, true );

		return is_array( $daten ) ? $daten : array();
	}

	/**
	 * Belegte AMS-Fächer als Auswahlliste fürs Einreihen eines Druckauftrags
	 * - gemeinsam genutzt von allen Stellen, an denen ein Job in die
	 * Warteschlange gelegt wird (BambuPrintersPage, DemandPage).
	 *
	 * @param object $printer Datensatz.
	 * @return array Liste von {value, color, label}. value ist die "globale"
	 *               Fach-Nummer (AMS-Einheit * 4 + Fach-Index), dieselbe
	 *               Zählweise, die die Firmware selbst in ams_mapping
	 *               erwartet (siehe send_print_command() auf der Bridge).
	 */
	public static function ams_tray_options( $printer ) {
		$optionen = array();

		foreach ( self::ams_units( $printer ) as $einheit ) {
			if ( ! is_array( $einheit ) ) {
				continue;
			}

			$einheit_id = isset( $einheit['id'] ) ? (int) $einheit['id'] : 0;

			foreach ( (array) ( $einheit['trays'] ?? array() ) as $fach ) {
				$material = isset( $fach['type'] ) ? trim( (string) $fach['type'] ) : '';

				if ( '' === $material ) {
					// Leeres Fach - nicht auswählbar.
					continue;
				}

				$fach_id = isset( $fach['id'] ) ? (int) $fach['id'] : 0;
				$farbe   = isset( $fach['color'] ) ? preg_replace( '/[^0-9A-Fa-f]/', '', (string) $fach['color'] ) : '';
				$farbe   = $farbe ? substr( str_pad( $farbe, 6, '0' ), 0, 6 ) : 'cccccc';
				$rest    = isset( $fach['remain'] ) && null !== $fach['remain'] && '' !== $fach['remain'] ? (int) $fach['remain'] : null;

				$optionen[] = array(
					'value' => $einheit_id * 4 + $fach_id,
					'color' => $farbe,
					'label' => sprintf(
						/* translators: 1: AMS-Einheit, 2: Fach, 3: Material, 4: " (Restmenge %)" oder leer. */
						__( 'AMS %1$d Fach %2$d – %3$s%4$s', 'lindenweg16' ),
						$einheit_id + 1,
						$fach_id + 1,
						$material,
						null !== $rest ? ' (' . $rest . ' %)' : ''
					),
				);
			}
		}

		return $optionen;
	}

	/**
	 * Temperaturwert auf das Format der Spalte bringen (DECIMAL(5,1)).
	 *
	 * @param mixed $wert Gemeldeter Wert.
	 * @return float
	 */
	protected static function clamp_temp( $wert ) {
		return max( -999.9, min( 9999.9, round( (float) $wert, 1 ) ) );
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
