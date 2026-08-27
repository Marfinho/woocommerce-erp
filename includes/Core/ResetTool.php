<?php
/**
 * Zurücksetzen vor dem Livegang.
 *
 * Vor dem echten Start steht in jedem System Probedatenmüll: Testbestellungen,
 * Probebuchungen, Druckjobs zum Ausprobieren. Der muss weg, damit die erste
 * echte Rechnung die Nummer 1 trägt und die Auswertungen stimmen.
 *
 * Drei Grundsätze:
 *
 * 1. Stammdaten bleiben. Artikel, Stücklisten, Drucker, Lagerplätze,
 *    Kalkulationsannahmen, Rabattstaffel und Einstellungen sind Arbeit, die
 *    niemand zweimal machen will. Gelöscht werden ausschließlich
 *    Bewegungsdaten.
 * 2. Nichts passiert versehentlich. Der Vorgang ist zweistufig: erst die
 *    Vorschau mit echten Zahlen, dann die Ausführung mit getippter
 *    Bestätigung und einem Einmal-Schlüssel, der nach zehn Minuten verfällt.
 * 3. Der Schnitt wird dokumentiert. Wann, durch wen, mit welchen Zahlen –
 *    festgehalten in einer Option, die selbst nicht gelöscht wird. Eine
 *    Hash-Kette, die bei null beginnt, ist erklärbar; eine, die einfach
 *    kürzer geworden ist, nicht.
 *
 * Nach Aufnahme des echten Betriebs ist dieses Werkzeug tabu: Buchhaltungs-
 * daten unterliegen dann der Aufbewahrungspflicht. Deshalb warnt die Seite
 * ab dem ersten durchgeführten Zurücksetzen deutlich.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Räumt Bewegungsdaten ab und protokolliert den Schnitt.
 */
class ResetTool {

	/**
	 * Option mit dem Protokoll aller Zurücksetzungen.
	 */
	const LOG_OPTION = 'lw16_reset_log';

	/**
	 * Transient des Einmal-Schlüssels.
	 */
	const TICKET = 'lw16_reset_ticket_';

	/**
	 * Zu tippende Bestätigung.
	 */
	const PHRASE = 'ALLES ZURUECKSETZEN';

	/**
	 * Wie viele Bestellungen je Durchgang gelöscht werden.
	 *
	 * WooCommerce-Bestellungen werden einzeln und vollständig gelöscht, damit
	 * Positionen, Gutschriften und Metadaten mitgehen. Das dauert; deshalb
	 * portionsweise, statt in einen Zeitüberlauf zu laufen.
	 */
	const ORDER_BATCH = 300;

	/**
	 * Löschbare Bereiche.
	 *
	 * Reine Beschreibung ohne Datenbankzugriff, damit sie prüfbar bleibt.
	 *
	 * @return array
	 */
	public static function scopes() {
		return array(
			'woocommerce'  => array(
				'label'  => __( 'WooCommerce-Bestellungen', 'lindenweg16' ),
				'hint'   => __( 'Alle Bestellungen samt Positionen, Gutschriften und Notizen. Produkte, Kunden und Einstellungen bleiben.', 'lindenweg16' ),
				'tables' => array(),
			),
			'betrieb'      => array(
				'label'  => __( 'Betrieb: Bewegungen', 'lindenweg16' ),
				'hint'   => __( 'Druckjobs, Lagerbewegungen, Chargen, Fertigungsaufträge, Picklisten, Zeiten, Wartung und Bestelldaten. Artikel, Stücklisten, Drucker und Lagerplätze bleiben.', 'lindenweg16' ),
				'tables' => array(
					'print_jobs',
					'batches',
					'stock_movements',
					'work_orders',
					'picklists',
					'picklist_items',
					'order_ops',
					'order_op_items',
					'time_entries',
					'maintenance_log',
					'capacity_weeks',
					'kpi_snapshots',
					'supplies',
				),
			),
			'buchhaltung'  => array(
				'label'  => __( 'Buchhaltung', 'lindenweg16' ),
				'hint'   => __( 'Belege, Buchungen, Rechnungen, Kassenbuch, E-Rechnungseingang und GoBD-Prüfungen. Kontenrahmen, Dauerbuchungen und Anlagen bleiben. Wer die Belege wegräumt, sollte auch Marketing ankreuzen – sonst zeigen die Kostenstellenzuordnungen ins Leere.', 'lindenweg16' ),
				'tables' => array(
					'belege',
					'buchungen',
					'rechnungen',
					'kassenbuch',
					'erechnung_eingang',
					'gobd_pruefung',
				),
			),
			'kasse'        => array(
				'label'  => __( 'Kasse', 'lindenweg16' ),
				'hint'   => __( 'Kassentage und ihre Positionen.', 'lindenweg16' ),
				'tables' => array( 'kasse_tage', 'kasse_positionen' ),
			),
			'marketing'    => array(
				'label'  => __( 'Marketing', 'lindenweg16' ),
				'hint'   => __( 'Kampagnen samt Kostenstellennummern und ihren Belegzuordnungen.', 'lindenweg16' ),
				'tables' => array( 'kampagne_beleg', 'kampagnen' ),
			),
			'kunden'       => array(
				'label'  => __( 'Kundenbindung und Support', 'lindenweg16' ),
				'hint'   => __( 'Punktekonten, Empfehlungen, Wunsch- und Wartelisten, Warenkorbverfolgung und Tickets.', 'lindenweg16' ),
				'tables' => array(
					'points_ledger',
					'referrals',
					'wishlist',
					'warteliste',
					'cart_tracking',
					'tickets',
					'tracking_cache',
				),
			),
			'altes_lager'  => array(
				'label'  => __( 'Alte Lagerverwaltung', 'lindenweg16' ),
				'hint'   => __( 'Bewegungen, Bestände, Druckaufträge und Picklisten der abgelösten Lagerverwaltung. Nur nötig, wenn dort Probedaten stehen.', 'lindenweg16' ),
				'tables' => array(
					'lagerbewegung',
					'lagerbestand',
					'lagerbestand_ort',
					'pickliste',
					'pickliste_pos',
					'druckauftrag',
					'produktion_log',
				),
			),
			'kontakte'     => array(
				'label'  => __( 'Kontakte und B2B', 'lindenweg16' ),
				'hint'   => __( 'Händler, Aktivitäten, Konditionen und Nachbestell-Links. Nur ankreuzen, wenn auch die Kontakte Probedaten sind.', 'lindenweg16' ),
				'tables' => array(
					'contact_activities',
					'contact_terms',
					'contact_profiles',
					'reorder_tokens',
					'contacts',
				),
			),
			'protokoll'    => array(
				'label'  => __( 'Änderungsprotokolle', 'lindenweg16' ),
				'hint'   => __( 'Die hashverketteten Protokolle beider Module. Sie beginnen danach neu bei null – das ist genau der Sinn eines sauberen Starts.', 'lindenweg16' ),
				'tables' => array( 'audit_log' ),
			),
		);
	}

	/**
	 * Bereiche, die standardmäßig angekreuzt sind.
	 *
	 * Kontakte und die alte Lagerverwaltung bleiben bewusst außen vor: Dort
	 * stehen oft schon echte Daten.
	 *
	 * @return array
	 */
	public static function default_scopes() {
		return array( 'woocommerce', 'betrieb', 'buchhaltung', 'kasse', 'marketing', 'kunden', 'protokoll' );
	}

	/**
	 * Tabellen, die nie angefasst werden.
	 *
	 * Steht hier als ausdrückliche Liste, damit beim Lesen sofort klar ist,
	 * was der Schnitt überlebt.
	 *
	 * @return array
	 */
	public static function protected_tables() {
		return array(
			'articles',
			'article_revisions',
			'article_sourcing',
			'components',
			'bom',
			'bundles',
			'bundle_items',
			'printers',
			'filament_lots',
			'storage_bins',
			'stock_targets',
			'calc_settings',
			'price_tiers',
			'produkte',
			'stueckliste',
			'rezeptur',
			'lagerorte',
			'anlagen',
			'dauerbuchungen',
		);
	}

	/**
	 * Zählt, was gelöscht würde.
	 *
	 * @return array Bereich => {count, tables => Tabelle => Anzahl}.
	 */
	public static function preview() {
		global $wpdb;

		$out = array();

		foreach ( self::scopes() as $key => $scope ) {
			$tables = array();
			$total  = 0;

			foreach ( $scope['tables'] as $table ) {
				$full  = $wpdb->prefix . 'lw16_' . $table;
				$count = self::count_rows( $full );

				if ( null === $count ) {
					continue;
				}

				$tables[ $table ] = $count;
				$total           += $count;
			}

			if ( 'woocommerce' === $key ) {
				$total = self::count_orders();
			}

			$out[ $key ] = array(
				'count'  => $total,
				'tables' => $tables,
			);
		}

		return $out;
	}

	/**
	 * Zeilen einer Tabelle zählen, null wenn es sie nicht gibt.
	 *
	 * @param string $table Vollständiger Tabellenname.
	 * @return int|null
	 */
	protected static function count_rows( $table ) {
		global $wpdb;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( ! $exists ) {
			return null;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Anzahl aller WooCommerce-Bestellungen.
	 *
	 * @return int
	 */
	public static function count_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$result = wc_get_orders(
			array(
				'status'   => 'any',
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			)
		);

		return isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Einmal-Schlüssel für die Ausführung ausgeben.
	 *
	 * Bindet die Ausführung an den Benutzer, der die Vorschau gesehen hat,
	 * und lässt sie nach zehn Minuten verfallen.
	 *
	 * @return string
	 */
	public static function issue_ticket() {
		$ticket = wp_generate_password( 32, false, false );

		set_transient( self::TICKET . get_current_user_id(), $ticket, 10 * MINUTE_IN_SECONDS );

		return $ticket;
	}

	/**
	 * Einmal-Schlüssel prüfen und verbrauchen.
	 *
	 * @param string $ticket Übergebener Schlüssel.
	 * @return bool
	 */
	public static function consume_ticket( $ticket ) {
		$stored = get_transient( self::TICKET . get_current_user_id() );

		delete_transient( self::TICKET . get_current_user_id() );

		return $stored && hash_equals( (string) $stored, (string) $ticket );
	}

	/**
	 * Getippte Bestätigung prüfen – reine Logik.
	 *
	 * Groß- und Kleinschreibung sowie Leerzeichen am Rand spielen keine
	 * Rolle; der Wortlaut muss stimmen.
	 *
	 * @param string $input Eingabe.
	 * @return bool
	 */
	public static function phrase_matches( $input ) {
		$input = trim( (string) $input );
		$input = preg_replace( '/\s+/', ' ', $input );

		return 0 === strcasecmp( $input, self::PHRASE );
	}

	/**
	 * Ausführen.
	 *
	 * Die Rechnungsnummer braucht keine eigene Behandlung: Sie leitet sich
	 * aus der höchsten Laufnummer in der Rechnungstabelle ab. Ist die leer,
	 * beginnt die Zählung von selbst wieder bei 1.
	 *
	 * @param array $scopes Zu löschende Bereiche.
	 * @return array {success, message, deleted, remaining_orders}
	 */
	public static function run( array $scopes ) {
		global $wpdb;

		$known   = self::scopes();
		$scopes  = array_values( array_intersect( $scopes, array_keys( $known ) ) );
		$deleted = array();

		if ( ! $scopes ) {
			return array(
				'success'          => false,
				'message'          => __( 'Kein Bereich gewählt – es wurde nichts gelöscht.', 'lindenweg16' ),
				'deleted'          => array(),
				'remaining_orders' => 0,
			);
		}

		$protected = self::protected_tables();
		$remaining = 0;

		foreach ( $scopes as $key ) {
			if ( 'woocommerce' === $key ) {
				$result             = self::delete_orders();
				$deleted[ $key ]    = $result['deleted'];
				$remaining          = $result['remaining'];
				continue;
			}

			$count = 0;

			foreach ( $known[ $key ]['tables'] as $table ) {
				// Doppelter Boden: Eine geschützte Tabelle wird auch dann
				// nicht geleert, wenn sie versehentlich in einer Bereichsliste
				// stünde.
				if ( in_array( $table, $protected, true ) ) {
					continue;
				}

				$full = $wpdb->prefix . 'lw16_' . $table;

				if ( null === self::count_rows( $full ) ) {
					continue;
				}

				$count += (int) self::count_rows( $full );
				$wpdb->query( "TRUNCATE TABLE {$full}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			}

			$deleted[ $key ] = $count;
		}

		// Belege und Rechnungs-PDFs liegen als Dateien im Uploadverzeichnis.
		// Bleiben sie liegen, während ihre Datensätze verschwinden, hat man
		// Papierkorb ohne Verzeichnis.
		if ( in_array( 'buchhaltung', $scopes, true ) ) {
			$deleted['dateien'] = self::purge_files();
		}

		self::log( $scopes, $deleted );

		return array(
			'success'          => true,
			'message'          => self::summary( $deleted, $remaining ),
			'deleted'          => $deleted,
			'remaining_orders' => $remaining,
		);
	}

	/**
	 * WooCommerce-Bestellungen portionsweise vollständig löschen.
	 *
	 * @return array {deleted, remaining}
	 */
	protected static function delete_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'deleted' => 0, 'remaining' => 0 );
		}

		$orders = wc_get_orders(
			array(
				'status' => 'any',
				'limit'  => self::ORDER_BATCH,
				'return' => 'objects',
			)
		);

		$deleted = 0;

		foreach ( (array) $orders as $order ) {
			// true = endgültig, nicht in den Papierkorb: Ein halb geloeschter
			// Bestand waere schlimmer als gar keiner.
			if ( $order->delete( true ) ) {
				$deleted++;
			}
		}

		return array(
			'deleted'   => $deleted,
			'remaining' => self::count_orders(),
		);
	}

	/**
	 * Beleg- und Dokumentdateien entfernen.
	 *
	 * Gelöscht wird ausschließlich innerhalb der beiden bekannten
	 * Verzeichnisse und nur eine Ebene tief. Die Schutzdateien, die den
	 * Direktzugriff sperren, bleiben liegen – ohne sie stünde das
	 * Verzeichnis danach offen.
	 *
	 * @return int Anzahl gelöschter Dateien.
	 */
	protected static function purge_files() {
		$keep    = array( '.htaccess', 'index.php', 'index.html', '.', '..' );
		$deleted = 0;

		foreach ( array( Installer::belege_dir(), Installer::docs_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
				if ( ! is_file( $file ) || in_array( basename( $file ), $keep, true ) ) {
					continue;
				}

				if ( wp_delete_file( $file ) || ! file_exists( $file ) ) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Meldung zusammenbauen.
	 *
	 * @param array $deleted   Gelöschte Mengen je Bereich.
	 * @param int   $remaining Verbliebene Bestellungen.
	 * @return string
	 */
	protected static function summary( array $deleted, $remaining ) {
		$parts = array();
		$known = self::scopes();

		foreach ( $deleted as $key => $count ) {
			$label = isset( $known[ $key ]['label'] )
				? $known[ $key ]['label']
				: __( 'Dateien', 'lindenweg16' );

			$parts[] = sprintf( '%s: %d', $label, $count );
		}

		$message = __( 'Zurückgesetzt.', 'lindenweg16' ) . ' ' . implode( ' · ', $parts );

		if ( $remaining > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: Anzahl. */
				__( 'Es stehen noch %d Bestellungen aus – bitte den Vorgang erneut ausführen.', 'lindenweg16' ),
				$remaining
			);
		}

		return $message;
	}

	/**
	 * Schnitt dauerhaft festhalten.
	 *
	 * @param array $scopes  Bereiche.
	 * @param array $deleted Mengen.
	 */
	protected static function log( array $scopes, array $deleted ) {
		$log = get_option( self::LOG_OPTION, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$user = wp_get_current_user();

		$log[] = array(
			'zeit'          => current_time( 'mysql' ),
			'benutzer'      => $user ? $user->user_login : '',
			'bereiche'      => $scopes,
			'mengen'        => $deleted,
		);

		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Bisherige Zurücksetzungen.
	 *
	 * @return array
	 */
	public static function history() {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}
}
