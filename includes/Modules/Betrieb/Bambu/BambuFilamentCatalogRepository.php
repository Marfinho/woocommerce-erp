<?php
/**
 * Katalog echter Bambu-Lab-Filamente (per RFID-Filament-ID), gesammelt
 * aus AMS-Meldungen und hochgeladenen Slicing-Profilen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Bambu;

defined( 'ABSPATH' ) || exit;

/**
 * Jede Bambu-Filamentfarbe hat eine eigene, feste RFID-Filament-ID (z. B.
 * "GFA01") - die steckt der Spule buchstäblich im Chip und taucht deshalb
 * überall identisch wieder auf: im AMS-Live-Report, in jedem daraus
 * exportierten Slicing-Profil, in jeder damit geslicten 3MF. Diese ID ist
 * deshalb der Schlüssel des Katalogs statt einer frei getippten
 * Farbbezeichnung - sie ist eindeutig, kollisionsfrei, und ein
 * Drittanbieter-/Custom-Filament ohne RFID-Chip hat gar keine gültige
 * ID und taucht hier nie auf. Genau das war die Anforderung: "nur mit
 * Bambu-Lab-Filamenten arbeiten".
 *
 * Aus dem AMS-Report kommen Material und Farbwert (Hex) mit, aus einem
 * hochgeladenen Slicing-Profil nur die ID und das Material (Profile
 * tragen keinen Hex-Farbwert - der ist eine Eigenschaft der einzelnen
 * Spule, nicht des Presets). Der Anzeigename wird beim ersten Sichten
 * aus dem Hex-Wert geschätzt (siehe guess_label()) und bleibt danach
 * unangetastet, sobald ein Bediener ihn einmal auf den echten
 * Bambu-Produktnamen korrigiert hat (label_edited).
 */
class BambuFilamentCatalogRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_bambu_filament_catalog';
	}

	/**
	 * Alle bekannten Filamente, zur Anzeige/Auswahl.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY material ASC, label ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Einzelnes Filament.
	 *
	 * @param string $filament_id Bambu-Filament-ID.
	 * @return object|null
	 */
	public static function find( $filament_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE filament_id = %s', (string) $filament_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Gültige Bambu-Filament-ID? Grobe, bewusst weite Prüfung (Bambus
	 * eigenes Schema ist nicht öffentlich fest dokumentiert) - verhindert
	 * nur, dass leere oder offensichtlich unpassende Werte (z. B. ein
	 * Custom-Filament-Name ohne RFID-ID) in den Katalog gelangen.
	 *
	 * @param string $filament_id Zu prüfender Wert.
	 * @return bool
	 */
	public static function is_valid_filament_id( $filament_id ) {
		return (bool) preg_match( '/^GF[A-Z0-9]{2,12}$/i', trim( (string) $filament_id ) );
	}

	/**
	 * Ein gesichtetes Filament im Katalog vermerken - beim ersten Mal
	 * neu anlegen (mit geschätztem Label), danach nur Material/Farbe und
	 * den Zeitstempel auffrischen. Ein bereits von Hand korrigiertes
	 * Label (label_edited) bleibt unangetastet.
	 *
	 * @param string $filament_id Bambu-Filament-ID, z. B. "GFA01".
	 * @param string $material    z. B. "PLA" - leer, wenn unbekannt.
	 * @param string $color_hex   6-stelliger Hex-Farbwert ohne "#" - leer,
	 *                            wenn unbekannt (z. B. aus einem
	 *                            Slicing-Profil ohne Spuleninformation).
	 * @return void
	 */
	public static function observe( $filament_id, $material = '', $color_hex = '' ) {
		global $wpdb;

		$filament_id = trim( (string) $filament_id );
		if ( ! self::is_valid_filament_id( $filament_id ) ) {
			return;
		}

		$material  = strtoupper( sanitize_text_field( (string) $material ) );
		$color_hex = strtolower( preg_replace( '/[^0-9a-f]/i', '', (string) $color_hex ) );
		$now       = current_time( 'mysql', true );

		$existing = self::find( $filament_id );

		if ( ! $existing ) {
			$wpdb->insert(
				self::table(),
				array(
					'filament_id'   => $filament_id,
					'material'      => $material,
					'color_hex'     => $color_hex,
					'label'         => self::guess_label( $material, $color_hex ),
					'label_edited'  => 0,
					'first_seen_at' => $now,
					'last_seen_at'  => $now,
				)
			);
			return;
		}

		$update = array( 'last_seen_at' => $now );

		// Fehlende Angaben nachtragen (z. B. Farbe von einem AMS-Sighting
		// nach einer vorherigen Profil-only-Beobachtung), Bekanntes nie
		// mit einem leeren Wert überschreiben.
		if ( '' === (string) $existing->material && '' !== $material ) {
			$update['material'] = $material;
		}
		if ( '' === (string) $existing->color_hex && '' !== $color_hex ) {
			$update['color_hex'] = $color_hex;
		}

		if ( ! $existing->label_edited && isset( $update['color_hex'] ) ) {
			// Erst jetzt kam die Farbe dazu - das anfangs nur aus dem
			// Material geschätzte Label nachbessern.
			$update['label'] = self::guess_label(
				isset( $update['material'] ) ? $update['material'] : $existing->material,
				$update['color_hex']
			);
		}

		$wpdb->update( self::table(), $update, array( 'id' => (int) $existing->id ) );
	}

	/**
	 * Alle Fächer eines AMS-Live-Reports (dasselbe JSON wie
	 * BambuPrinterRepository::ams_json) in den Katalog übernehmen. Wird
	 * bei jedem POST /status mit AMS-Daten aufgerufen.
	 *
	 * @param string|null $ams_json JSON-String, wie in ams_json gespeichert.
	 * @return void
	 */
	public static function observe_ams_json( $ams_json ) {
		if ( empty( $ams_json ) ) {
			return;
		}

		$einheiten = json_decode( (string) $ams_json, true );
		if ( ! is_array( $einheiten ) ) {
			return;
		}

		foreach ( $einheiten as $einheit ) {
			if ( ! is_array( $einheit ) ) {
				continue;
			}
			foreach ( (array) ( $einheit['trays'] ?? array() ) as $fach ) {
				if ( ! is_array( $fach ) || empty( $fach['tray_info_idx'] ) ) {
					continue;
				}
				self::observe(
					(string) $fach['tray_info_idx'],
					isset( $fach['type'] ) ? (string) $fach['type'] : '',
					isset( $fach['color'] ) ? (string) $fach['color'] : ''
				);
			}
		}
	}

	/**
	 * Ein hochgeladenes Filamentprofil (filament.json aus OrcaSlicer/Bambu
	 * Studio) in den Katalog übernehmen - trägt keinen Hex-Farbwert (der
	 * ist eine Eigenschaft der Spule, nicht des Presets), aber Material
	 * und die Filament-ID. So landet ein Profil auch dann im Katalog,
	 * wenn die zugehörige Spule noch nie im hier angebundenen AMS steckte.
	 *
	 * @param string $json_content Inhalt der hochgeladenen Datei.
	 * @return void
	 */
	public static function observe_filament_profile_json( $json_content ) {
		$daten = json_decode( (string) $json_content, true );
		if ( ! is_array( $daten ) || empty( $daten['filament_id'] ) ) {
			return;
		}

		$material = $daten['filament_type'] ?? '';
		if ( is_array( $material ) ) {
			$material = reset( $material );
		}

		self::observe( (string) $daten['filament_id'], (string) $material, '' );
	}

	/**
	 * Label von Hand auf den echten Bambu-Produktnamen setzen - bleibt ab
	 * dann bei künftigen observe()-Aufrufen unangetastet.
	 *
	 * @param string $filament_id Bambu-Filament-ID.
	 * @param string $label       Neuer Anzeigename.
	 * @return bool
	 */
	public static function rename( $filament_id, $label ) {
		global $wpdb;

		$label = trim( sanitize_text_field( (string) $label ) );
		if ( '' === $label ) {
			return false;
		}

		return false !== $wpdb->update(
			self::table(),
			array(
				'label'        => $label,
				'label_edited' => 1,
			),
			array( 'filament_id' => (string) $filament_id )
		);
	}

	/**
	 * Anzeigename schätzen: "<Material> <Farbname>" - der Farbname kommt
	 * aus einer groben Zuordnung des Hex-Werts zur nächstliegenden
	 * gängigen Farbe (siehe nearest_color_name()). Ohne bekannten
	 * Hex-Wert bleibt nur das Material (oder gar nichts) - ein Bediener
	 * kann jederzeit über rename() den echten Bambu-Produktnamen
	 * eintragen (z. B. "Jade White").
	 *
	 * @param string $material  z. B. "PLA".
	 * @param string $color_hex Hex-Farbwert ohne "#", oder leer.
	 * @return string
	 */
	protected static function guess_label( $material, $color_hex ) {
		$teile = array();
		if ( '' !== $material ) {
			$teile[] = $material;
		}
		if ( '' !== $color_hex ) {
			$teile[] = self::nearest_color_name( $color_hex );
		}
		return implode( ' – ', $teile );
	}

	/**
	 * Gängige, ungefähre deutsche Farbnamen für die üblichen
	 * Bambu-Filamentfarben - kein Ersatz für die echten Bambu-
	 * Produktnamen (die kennt nur Bambus eigener, nicht offen
	 * zugänglicher Katalog), nur eine brauchbare erste Schätzung, bis
	 * ein Bediener sie über rename() korrigiert.
	 *
	 * @var array<string,string>
	 */
	const NAMED_COLORS = array(
		'FFFFFF' => 'Weiß',
		'000000' => 'Schwarz',
		'808080' => 'Grau',
		'C0C0C0' => 'Silber',
		'FF0000' => 'Rot',
		'8B0000' => 'Dunkelrot',
		'FFA500' => 'Orange',
		'FFFF00' => 'Gelb',
		'808000' => 'Oliv',
		'00FF00' => 'Grün',
		'008000' => 'Dunkelgrün',
		'00FFFF' => 'Türkis',
		'0000FF' => 'Blau',
		'000080' => 'Dunkelblau',
		'800080' => 'Lila',
		'FFC0CB' => 'Rosa',
		'A52A2A' => 'Braun',
		'D2B48C' => 'Beige',
		'FFD700' => 'Gold',
	);

	/**
	 * Nächstgelegener Farbname zu einem Hex-Wert (kleinster euklidischer
	 * Abstand im RGB-Raum zu NAMED_COLORS) - liefert immer einen Namen,
	 * auch für Zwischentöne, statt bei einem nicht exakt passenden Hex
	 * leer zu bleiben.
	 *
	 * @param string $hex 6-stelliger Hex-Wert ohne "#".
	 * @return string
	 */
	protected static function nearest_color_name( $hex ) {
		$hex = str_pad( substr( $hex, 0, 6 ), 6, '0' );
		$r   = hexdec( substr( $hex, 0, 2 ) );
		$g   = hexdec( substr( $hex, 2, 2 ) );
		$b   = hexdec( substr( $hex, 4, 2 ) );

		$bester_name    = '';
		$kleinster_abst = null;

		foreach ( self::NAMED_COLORS as $kandidat_hex => $name ) {
			$cr = hexdec( substr( $kandidat_hex, 0, 2 ) );
			$cg = hexdec( substr( $kandidat_hex, 2, 2 ) );
			$cb = hexdec( substr( $kandidat_hex, 4, 2 ) );

			$abstand = ( $r - $cr ) ** 2 + ( $g - $cg ) ** 2 + ( $b - $cb ) ** 2;

			if ( null === $kleinster_abst || $abstand < $kleinster_abst ) {
				$kleinster_abst = $abstand;
				$bester_name    = $name;
			}
		}

		return $bester_name;
	}
}
