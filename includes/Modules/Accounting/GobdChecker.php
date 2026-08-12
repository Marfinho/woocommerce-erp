<?php
/**
 * GoBD-Integritätsprüfung.
 *
 * Verifiziert die Hash-Ketten, prüft auf Lücken in der Nummerierung
 * und protokolliert das Ergebnis.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\HashChain;
use Lindenweg16\Core\AuditLog;

/**
 * Führt die jährliche (oder manuelle) GoBD-Konformitätsprüfung durch.
 */
class GobdChecker {

	/**
	 * Tabelle für Prüfergebnisse.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_gobd_pruefung';
	}

	/**
	 * Vollständige GoBD-Prüfung durchführen.
	 *
	 * @param int|null $jahr Kalenderjahr (null = aktuelles Jahr).
	 * @return array Prüfergebnis.
	 */
	public static function run( $jahr = null ) {
		global $wpdb;

		if ( null === $jahr ) {
			$jahr = (int) gmdate( 'Y' );
		}

		$ergebnis = array(
			'jahr'        => $jahr,
			'zeitpunkt'   => current_time( 'mysql' ),
			'status'      => 'ok',
			'pruefungen'  => array(),
			'fehler'      => 0,
			'warnungen'   => 0,
		);

		// 1. Hash-Ketten prüfen.
		$hash_tables = array(
			'Belege'           => BelegRepository::table(),
			'Rechnungen'       => InvoiceGenerator::table(),
			'Buchungsjournal'  => BuchungRepository::table(),
			'Audit-Log'        => AuditLog::table(),
			'E-Rechnungen'     => ERechnungImporter::table(),
		);

		foreach ( $hash_tables as $label => $table ) {
			$check = array(
				'name'    => 'Hash-Kette: ' . $label,
				'status'  => 'ok',
				'details' => '',
			);

			// Prüfen ob die Tabelle existiert und Hash-Spalte hat.
			$col = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'hash'",
				DB_NAME,
				$table
			) );

			if ( ! $col ) {
				$check['status']  = 'uebersprungen';
				$check['details'] = 'Hash-Spalte nicht vorhanden (Migration ausstehend?)';
				$ergebnis['warnungen']++;
			} else {
				$errors = HashChain::verify( $table );
				if ( ! empty( $errors ) ) {
					$check['status']  = 'fehler';
					$check['details'] = count( $errors ) . ' Fehler: ' . wp_json_encode(
						array_slice( $errors, 0, 5 ),
						JSON_UNESCAPED_UNICODE
					);
					$ergebnis['fehler'] += count( $errors );
				} else {
					$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE hash != '' AND hash IS NOT NULL" ); // phpcs:ignore
					$check['details'] = $count . ' Datensätze geprüft, Kette intakt';
				}
			}

			$ergebnis['pruefungen'][] = $check;
		}

		// 2. Belegnummern-Lücken prüfen.
		$beleg_check = array(
			'name'    => 'Belegnummern-Lückenlosigkeit (' . $jahr . ')',
			'status'  => 'ok',
			'details' => '',
		);
		$beleg_col = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'beleg_laufnummer'",
			DB_NAME,
			BelegRepository::table()
		) );
		if ( $beleg_col ) {
			$gaps = HashChain::find_gaps( BelegRepository::table(), 'beleg_laufnummer', $jahr, 'beleg_jahr' );
			if ( ! empty( $gaps ) ) {
				$beleg_check['status']  = 'fehler';
				$beleg_check['details'] = 'Fehlende Nummern: ' . implode( ', ', array_slice( $gaps, 0, 20 ) );
				$ergebnis['fehler']++;
			} else {
				$max = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(MAX(beleg_laufnummer), 0) FROM " . BelegRepository::table() . " WHERE beleg_jahr = %d", // phpcs:ignore
					$jahr
				) );
				$beleg_check['details'] = $max . ' Belege, keine Lücken';
			}
		} else {
			$beleg_check['status']  = 'uebersprungen';
			$beleg_check['details'] = 'Belegnummern-Spalte nicht vorhanden';
			$ergebnis['warnungen']++;
		}
		$ergebnis['pruefungen'][] = $beleg_check;

		// 3. Rechnungsnummern-Lücken prüfen.
		$re_check = array(
			'name'    => 'Rechnungsnummern-Lückenlosigkeit (' . $jahr . ')',
			'status'  => 'ok',
			'details' => '',
		);
		$re_gaps = HashChain::find_gaps( InvoiceGenerator::table(), 'laufnummer', $jahr );
		if ( ! empty( $re_gaps ) ) {
			$re_check['status']  = 'fehler';
			$re_check['details'] = 'Fehlende Nummern: ' . implode( ', ', array_slice( $re_gaps, 0, 20 ) );
			$ergebnis['fehler']++;
		} else {
			$max = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(MAX(laufnummer), 0) FROM " . InvoiceGenerator::table() . " WHERE jahr = %d", // phpcs:ignore
				$jahr
			) );
			$re_check['details'] = $max . ' Rechnungen, keine Lücken';
		}
		$ergebnis['pruefungen'][] = $re_check;

		// 4. Buchungsnummern-Lücken prüfen.
		$bu_check = array(
			'name'    => 'Buchungsnummern-Lückenlosigkeit (' . $jahr . ')',
			'status'  => 'ok',
			'details' => '',
		);
		$bu_col = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'laufnummer'",
			DB_NAME,
			BuchungRepository::table()
		) );
		if ( $bu_col ) {
			$bu_gaps = HashChain::find_gaps( BuchungRepository::table(), 'laufnummer', $jahr );
			if ( ! empty( $bu_gaps ) ) {
				$bu_check['status']  = 'fehler';
				$bu_check['details'] = 'Fehlende Nummern: ' . implode( ', ', array_slice( $bu_gaps, 0, 20 ) );
				$ergebnis['fehler']++;
			} else {
				$max = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(MAX(laufnummer), 0) FROM " . BuchungRepository::table() . " WHERE jahr = %d", // phpcs:ignore
					$jahr
				) );
				$bu_check['details'] = $max . ' Buchungen, keine Lücken';
			}
		} else {
			$bu_check['status']  = 'uebersprungen';
			$bu_check['details'] = 'Buchungstabelle nicht vorhanden';
			$ergebnis['warnungen']++;
		}
		$ergebnis['pruefungen'][] = $bu_check;

		// 5. Zeitgerechte Erfassung prüfen.
		$zeit_check = array(
			'name'    => 'Zeitgerechte Erfassung (Belege)',
			'status'  => 'ok',
			'details' => '',
		);
		$spaet = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . BelegRepository::table() . " WHERE DATEDIFF(erstellt_am, datum) > 10 AND YEAR(datum) = %d", // phpcs:ignore
			$jahr
		) );
		if ( (int) $spaet > 0 ) {
			$zeit_check['status']  = 'warnung';
			$zeit_check['details'] = $spaet . ' Belege mit >10 Tagen Verzug zwischen Belegdatum und Erfassung';
			$ergebnis['warnungen']++;
		} else {
			$zeit_check['details'] = 'Alle Belege innerhalb von 10 Tagen erfasst';
		}
		$ergebnis['pruefungen'][] = $zeit_check;

		// Gesamtstatus.
		if ( $ergebnis['fehler'] > 0 ) {
			$ergebnis['status'] = 'fehler';
		} elseif ( $ergebnis['warnungen'] > 0 ) {
			$ergebnis['status'] = 'warnung';
		}

		// Ergebnis speichern.
		self::save_result( $ergebnis );

		return $ergebnis;
	}

	/**
	 * Prüfergebnis in der Datenbank speichern.
	 *
	 * @param array $ergebnis Prüfergebnis.
	 */
	protected static function save_result( array $ergebnis ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'pruef_datum'   => current_time( 'mysql' ),
				'jahr'          => $ergebnis['jahr'],
				'ergebnis'      => $ergebnis['status'],
				'fehler_anzahl' => $ergebnis['fehler'],
				'warn_anzahl'   => $ergebnis['warnungen'],
				'details'       => wp_json_encode( $ergebnis['pruefungen'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
				'geprueft_von'  => get_current_user_id(),
			)
		);

		AuditLog::record( 'gobd_pruefung', 'pruefung', $wpdb->insert_id, array(
			'jahr'   => $ergebnis['jahr'],
			'status' => $ergebnis['status'],
			'fehler' => $ergebnis['fehler'],
		) );
	}

	/**
	 * Letzte Prüfergebnisse holen.
	 *
	 * @param int $limit Anzahl.
	 * @return array
	 */
	public static function get_results( $limit = 10 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", // phpcs:ignore
			$limit
		) );
	}
}
