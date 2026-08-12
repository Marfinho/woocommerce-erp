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
use Lindenweg16\Core\HashChain;
use Lindenweg16\Core\Calc;

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
	 * Nächste Belegnummer für ein Kalenderjahr.
	 *
	 * @param int $jahr Kalenderjahr.
	 * @return array [ 'beleg_laufnummer' => int, 'belegnummer' => string ]
	 */
	public static function next_nummer( $jahr ) {
		global $wpdb;
		$t   = self::table();
		$lnr = 1 + (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(beleg_laufnummer), 0) FROM {$t} WHERE beleg_jahr = %d", // phpcs:ignore
			$jahr
		) );
		return array(
			'beleg_laufnummer' => $lnr,
			'belegnummer'      => Calc::belegnummer( $jahr, $lnr ),
		);
	}

	/**
	 * Beleg anlegen (Insert-only, mit Belegnummer und Hash-Kette).
	 *
	 * @param array $data Belegdaten.
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$datum = isset( $data['datum'] ) ? $data['datum'] : gmdate( 'Y-m-d' );
		$jahr  = (int) substr( $datum, 0, 4 );
		if ( ! $jahr ) {
			$jahr = (int) gmdate( 'Y' );
		}

		// Belegnummer zuweisen.
		if ( ! isset( $data['belegnummer'] ) ) {
			$next                      = self::next_nummer( $jahr );
			$data['belegnummer']       = $next['belegnummer'];
			$data['beleg_laufnummer']  = $next['beleg_laufnummer'];
			$data['beleg_jahr']        = $jahr;
		}

		$defaults = array(
			'belegnummer'     => '',
			'beleg_jahr'      => $jahr,
			'beleg_laufnummer' => 0,
			'datei_pfad'      => '',
			'betrag'          => 0,
			'datum'           => $datum,
			'haendler'        => '',
			'eur_kategorie'   => '',
			'notiz'           => '',
			'quelle'          => 'manuell',
			'festgeschrieben' => 0,
			'storniert'       => 0,
			'erstellt_am'     => current_time( 'mysql' ),
			'erstellt_von'    => get_current_user_id(),
		);
		$data = wp_parse_args( $data, $defaults );

		// Hash-Verkettung.
		$hashes                   = HashChain::prepare_insert( 'belege', $data );
		$data['hash']             = $hashes['hash'];
		$data['vorheriger_hash']  = $hashes['vorheriger_hash'];

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
				'festgeschrieben_am' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		AuditLog::record( 'beleg_festgeschrieben', 'beleg', $id, array( 'betrag' => $beleg->betrag ) );

		// Buchung im Journal erzeugen.
		BuchungRepository::create_from_beleg( $beleg );

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
	 * Summe festgeschriebener Belege je EÜR-Kategorie in einem Zeitraum.
	 *
	 * @param string $from Startdatum (Y-m-d).
	 * @param string $to   Enddatum (Y-m-d).
	 * @return array Kategorie => Summe.
	 */
	public static function sum_by_category( $from, $to ) {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT eur_kategorie, SUM(betrag) AS summe FROM {$t}
				 WHERE festgeschrieben = 1 AND storniert = 0 AND quelle != 'storno' AND datum >= %s AND datum <= %s
				 GROUP BY eur_kategorie ORDER BY eur_kategorie", // phpcs:ignore
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
	 * Beleg stornieren (Gegenbuchung statt DELETE).
	 *
	 * @param int    $id    Beleg-ID.
	 * @param string $grund Stornierungsgrund.
	 * @return int|false ID des Storno-Belegs oder false.
	 */
	public static function stornieren( $id, $grund = '' ) {
		global $wpdb;
		$beleg = self::get( $id );
		if ( ! $beleg || (int) $beleg->storniert === 1 ) {
			return false;
		}
		// Storno-Belege dürfen nicht nochmal storniert werden.
		if ( 'storno' === $beleg->quelle ) {
			return false;
		}

		// Original als storniert markieren (storniert ist nicht im Hash).
		$wpdb->update( self::table(), array( 'storniert' => 1 ), array( 'id' => (int) $id ) );

		// Storno-Beleg anlegen.
		$storno_id = self::create( array(
			'datei_pfad'    => '',
			'betrag'        => abs( (float) $beleg->betrag ),
			'datum'         => gmdate( 'Y-m-d' ),
			'haendler'      => $beleg->haendler,
			'eur_kategorie' => $beleg->eur_kategorie,
			'notiz'         => sprintf( 'Storno von %s: %s', $beleg->belegnummer ?? ( '#' . $beleg->id ), $grund ),
			'quelle'        => 'storno',
			'storno_von_id' => (int) $id,
			'storniert'     => 0,
		) );

		// Storno-Beleg sofort festschreiben (ohne erneute Buchung).
		$wpdb->update(
			self::table(),
			array(
				'festgeschrieben'    => 1,
				'festgeschrieben_am' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $storno_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		$storno_beleg = self::get( $storno_id );
		AuditLog::record( 'beleg_storniert', 'beleg', $id, array(
			'grund'              => $grund,
			'storno_belegnummer' => $storno_beleg ? $storno_beleg->belegnummer : '',
		) );

		// Ggf. zugehörige Buchung auch stornieren.
		$buchung = BuchungRepository::get_by_referenz( 'beleg', $id );
		if ( $buchung ) {
			BuchungRepository::create_storno( (int) $buchung->id, $grund );
		}

		return $storno_id;
	}

	/**
	 * Alle Belege für den Export (ungepaginiert, nach Datum sortiert).
	 *
	 * @param string $from Startdatum.
	 * @param string $to   Enddatum.
	 * @return array
	 */
	public static function export( $from, $to ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE datum >= %s AND datum <= %s ORDER BY id ASC", // phpcs:ignore
			$from,
			$to
		), ARRAY_A );
	}

	/* --------------------------------------------------------------------
	 * Datei-Handling (geschützt)
	 * ------------------------------------------------------------------ */

	/**
	 * Hochgeladenes Beleg-Foto sicher speichern, verkleinern und den
	 * relativen Pfad (innerhalb des geschützten Verzeichnisses) zurückgeben.
	 *
	 * @param array $file Eintrag aus $_FILES.
	 * @return string Relativer Dateiname oder leerer String bei Fehler.
	 */
	public static function store_upload( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return '';
		}

		// MIME-Typ prüfen – nur Bilder zulassen.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$allowed = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return '';
		}

		$dir = Installer::belege_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$ext      = pathinfo( $file['name'], PATHINFO_EXTENSION );
		$ext      = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $ext );
		$filename = 'beleg-' . gmdate( 'Ymd' ) . '-' . wp_generate_password( 12, false ) . '.' . strtolower( $ext );
		$target   = trailingslashit( $dir ) . $filename;

		if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore
			return '';
		}

		// Serverseitig verkleinern/komprimieren (Handyfotos sind groß).
		self::maybe_downscale( $target );

		return $filename;
	}

	/**
	 * Bild bei Bedarf auf max. 2000px lange Kante herunterrechnen.
	 *
	 * @param string $path Absoluter Dateipfad.
	 */
	protected static function maybe_downscale( $path ) {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return;
		}
		$size = $editor->get_size();
		if ( empty( $size['width'] ) ) {
			return;
		}
		$max = 2000;
		if ( $size['width'] > $max || $size['height'] > $max ) {
			$editor->resize( $max, $max, false );
		}
		$editor->set_quality( 82 );
		$editor->save( $path );
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
