<?php
/**
 * Datenzugriff und Datei-Handling für Belege.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;
use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Time;

/**
 * Verwaltet Belege inkl. geschützter Foto-Ablage außerhalb des
 * öffentlichen Uploads-Verzeichnisses.
 */
class BelegRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_belege';
	}

	/**
	 * Beleg anlegen.
	 *
	 * @param array $data Belegdaten.
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$defaults = array(
			'datei_pfad'    => '',
			'betrag'        => 0,
			'datum'         => Time::today(),
			'haendler'      => '',
			'eur_kategorie' => '',
			'notiz'         => '',
			'quelle'        => 'manuell',
			// Belege sind standardmäßig Ausgaben. Einnahmebelege (z. B. der
			// Tagesabschluss der Marktkasse) müssen 'einnahme' setzen, sonst
			// würden sie in der EÜR vom Gewinn abgezogen statt addiert.
			'richtung'      => 'ausgabe',
			'festgeschrieben' => 0,
			'erstellt_am'   => Time::now(),
			'erstellt_von'  => get_current_user_id(),
		);
		$data = wp_parse_args( $data, $defaults );
		if ( ! in_array( $data['richtung'], array( 'einnahme', 'ausgabe' ), true ) ) {
			$data['richtung'] = 'ausgabe';
		}
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Beleg holen.
	 *
	 * @param int $id Beleg-ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/**
	 * Beleg aktualisieren – nur wenn nicht festgeschrieben, außer Notiz.
	 *
	 * @param int   $id   Beleg-ID.
	 * @param array $data Zu ändernde Felder.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$beleg = self::get( $id );
		if ( ! $beleg ) {
			return false;
		}
		if ( (int) $beleg->festgeschrieben === 1 ) {
			// Nach Festschreibung nur noch Notiz erlaubt.
			$data = array_intersect_key( $data, array( 'notiz' => '' ) );
			if ( empty( $data ) ) {
				return false;
			}
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * Beleg festschreiben (GoBD): danach unveränderbar (außer Notiz).
	 *
	 * @param int $id Beleg-ID.
	 * @return bool
	 */
	public static function festschreiben( $id ) {
		global $wpdb;
		$beleg = self::get( $id );
		if ( ! $beleg || (int) $beleg->festgeschrieben === 1 ) {
			return false;
		}
		$wpdb->update(
			self::table(),
			array(
				'festgeschrieben'    => 1,
				'festgeschrieben_am' => Time::now(),
			),
			array( 'id' => (int) $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		AuditLog::record( 'beleg_festgeschrieben', 'beleg', $id, array( 'betrag' => $beleg->betrag ) );
		return true;
	}

	/**
	 * Paginierte Belegliste, optional nach Zeitraum gefiltert.
	 *
	 * @param array $args from, to, per_page, page.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$t        = self::table();
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['from'] ) ) {
			$where   .= ' AND datum >= %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where   .= ' AND datum <= %s';
			$params[] = $args['to'];
		}

		$sql      = "SELECT * FROM {$t} WHERE {$where} ORDER BY datum DESC, id DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * Gesamtanzahl der Belege (für Pagination).
	 *
	 * @param array $args from, to.
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;
		$t = self::table();

		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['from'] ) ) {
			$where   .= ' AND datum >= %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where   .= ' AND datum <= %s';
			$params[] = $args['to'];
		}

		$sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
	}

	/**
	 * Summe der Belege je EÜR-Kategorie in einem Zeitraum.
	 *
	 * Bewusst **ohne** Filter auf `festgeschrieben`: die Festschreibung regelt
	 * die Unveränderbarkeit eines Belegs (GoBD), nicht seine Zugehörigkeit zur
	 * EÜR. Vorher fehlten damit alle noch nicht festgeschriebenen Belege
	 * stillschweigend in Gewinn und Steuer-Rücklage.
	 *
	 * @param string $from     Startdatum (Y-m-d).
	 * @param string $to       Enddatum (Y-m-d).
	 * @param string $richtung einnahme|ausgabe.
	 * @return array Kategorie => Summe.
	 */
	public static function sum_by_category( $from, $to, $richtung = 'ausgabe' ) {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT eur_kategorie, SUM(betrag) AS summe FROM {$t}
				 WHERE richtung = %s AND datum >= %s AND datum <= %s
				 GROUP BY eur_kategorie ORDER BY eur_kategorie", // phpcs:ignore
				$richtung,
				$from,
				$to
			)
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r->eur_kategorie ? $r->eur_kategorie : __( 'ohne Kategorie', 'lindenweg16' ) ] = (float) $r->summe;
		}
		return $out;
	}

	/**
	 * Summe aller Einnahmebelege eines Zeitraums (z. B. Marktkasse).
	 *
	 * @param string $from Startdatum (Y-m-d).
	 * @param string $to   Enddatum (Y-m-d).
	 * @return float
	 */
	public static function sum_einnahmen( $from, $to ) {
		return array_sum( self::sum_by_category( $from, $to, 'einnahme' ) );
	}

	/**
	 * Anzahl noch nicht festgeschriebener Belege in einem Zeitraum.
	 *
	 * Wird in der EÜR-Ansicht als Hinweis ausgegeben, damit sichtbar ist,
	 * wie viel des Ergebnisses noch änderbar ist.
	 *
	 * @param string $from Startdatum (Y-m-d).
	 * @param string $to   Enddatum (Y-m-d).
	 * @return int
	 */
	public static function count_offen( $from, $to ) {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE festgeschrieben = 0 AND datum >= %s AND datum <= %s", // phpcs:ignore
				$from,
				$to
			)
		);
	}

	/* --------------------------------------------------------------------
	 * Datei-Handling (geschützt)
	 * ------------------------------------------------------------------ */

	/**
	 * Erlaubte Upload-Typen für Belegfotos.
	 *
	 * @return string[]
	 */
	public static function allowed_upload_types() {
		return array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
	}

	/**
	 * Hochgeladenes Beleg-Foto sicher speichern und den relativen Pfad
	 * (innerhalb des geschützten Verzeichnisses) zurückgeben.
	 *
	 * Gibt im Fehlerfall bewusst ein WP_Error zurück statt eines leeren
	 * Strings: vorher wurde ein abgelehnter Upload stillschweigend verworfen
	 * und der Beleg trotzdem angelegt – der Nutzer bekam „gespeichert" und
	 * hatte einen Buchungssatz ohne Nachweis.
	 *
	 * @param array $file Eintrag aus $_FILES.
	 * @return string|\WP_Error Relativer Dateiname oder Fehler.
	 */
	public static function store_upload( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) ) {
			return new \WP_Error( 'lw16_no_file', __( 'Es wurde keine Datei übermittelt.', 'lindenweg16' ) );
		}

		$error_code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;
		if ( UPLOAD_ERR_OK !== $error_code ) {
			return new \WP_Error( 'lw16_upload_error', self::upload_error_message( $error_code ) );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'lw16_not_uploaded', __( 'Die Datei konnte nicht gelesen werden.', 'lindenweg16' ) );
		}

		// MIME-Typ anhand des Dateiinhalts prüfen – nur Bilder zulassen.
		$check   = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$allowed = self::allowed_upload_types();
		if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return new \WP_Error(
				'lw16_bad_type',
				sprintf(
					/* translators: %s: Liste erlaubter Formate */
					__( 'Dieses Dateiformat wird nicht unterstützt. Erlaubt sind: %s. HEIC-Fotos vom iPhone bitte vorher als JPEG exportieren.', 'lindenweg16' ),
					'JPEG, PNG, WebP, GIF'
				)
			);
		}

		$dir = Installer::belege_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_writable( $dir ) ) {
			return new \WP_Error( 'lw16_dir_not_writable', __( 'Das Beleg-Verzeichnis ist nicht beschreibbar.', 'lindenweg16' ) );
		}

		$ext      = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) ) );
		$ext      = $ext ? $ext : 'jpg';
		$basis    = 'beleg-' . Time::today() . '-' . wp_generate_password( 12, false );
		$filename = $basis . '.' . $ext;
		$target   = trailingslashit( $dir ) . $filename;

		if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore
			return new \WP_Error( 'lw16_move_failed', __( 'Die Datei konnte nicht gespeichert werden.', 'lindenweg16' ) );
		}

		// Verkleinern und nach JPEG normalisieren. Der PDF-Generator kann nur
		// JPEG einbetten – ohne diesen Schritt landen PNG/WebP-Belege nicht im
		// EÜR-Export.
		$normalized = self::normalize_to_jpeg( $target, $basis );
		if ( $normalized ) {
			return $normalized;
		}

		// Konvertierung fehlgeschlagen (kein GD/Imagick): Beleg bleibt
		// erhalten, der PDF-Export überspringt ihn dann sauber.
		return $filename;
	}

	/**
	 * Bild auf max. 2000 px verkleinern und als JPEG ablegen.
	 *
	 * @param string $path  Absoluter Pfad der Originaldatei.
	 * @param string $basis Dateiname ohne Endung.
	 * @return string|false Neuer relativer Dateiname oder false.
	 */
	protected static function normalize_to_jpeg( $path, $basis ) {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}

		$size = $editor->get_size();
		$max  = 2000;
		if ( ! empty( $size['width'] ) && ( $size['width'] > $max || $size['height'] > $max ) ) {
			$editor->resize( $max, $max, false );
		}
		$editor->set_quality( 82 );

		$jpeg_path = trailingslashit( dirname( $path ) ) . $basis . '.jpg';
		$saved     = $editor->save( $jpeg_path, 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) {
			return false;
		}

		// Original entfernen, wenn die Konvertierung eine neue Datei erzeugt hat.
		$new_path = trailingslashit( dirname( $path ) ) . $saved['file'];
		if ( $new_path !== $path && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore
		}

		return $saved['file'];
	}

	/**
	 * Sprechende Meldung zu einem PHP-Upload-Fehlercode.
	 *
	 * @param int $code UPLOAD_ERR_*.
	 * @return string
	 */
	protected static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: maximale Uploadgröße */
					__( 'Die Datei ist zu groß (Serverlimit: %s).', 'lindenweg16' ),
					size_format( wp_max_upload_size() )
				);
			case UPLOAD_ERR_PARTIAL:
				return __( 'Die Datei wurde nur unvollständig übertragen. Bitte erneut versuchen.', 'lindenweg16' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Es wurde keine Datei ausgewählt.', 'lindenweg16' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Der Server konnte die Datei nicht zwischenspeichern.', 'lindenweg16' );
			default:
				return __( 'Der Upload ist fehlgeschlagen.', 'lindenweg16' );
		}
	}

	/**
	 * Absoluter Pfad zu einer Belegdatei.
	 *
	 * @param string $filename Relativer Dateiname.
	 * @return string
	 */
	public static function file_path( $filename ) {
		return trailingslashit( Installer::belege_dir() ) . ltrim( $filename, '/' );
	}
}
