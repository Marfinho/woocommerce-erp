<?php
/**
 * Täglicher Überblick per Mail: was heute zu tun ist.
 *
 * Fällige Mahnstufen, ablaufende Angebote, offene Urlaubsanträge, unbestätigte
 * Bestellungen an Lieferanten, lange offene Reklamationen, nicht zugeordnete
 * Bankumsätze, Vivid-Schlüssel kurz vor Ablauf oder mit Fehler, Engpass in der
 * Liquiditätsvorschau. Geht nur raus, wenn etwas zu tun ist.
 *
 * Empfänger und An/Aus: Einstellungen der Liquiditätsseite (Gruppe „digest“).
 * Die Zusammenstellung des Textes ist eine reine Regel (tests/ErpDigestTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Täglicher Überblick.
 */
class Digest {

	/**
	 * Mailtext aus den Abschnitten.
	 *
	 * @param array  $sections Liste {title, lines[]} – leere Abschnitte fallen weg.
	 * @param string $company  Firma.
	 * @param string $url      Adresse des ERP.
	 * @param string $today    Heute (JJJJ-MM-TT).
	 * @return array {subject, body, count}
	 */
	public static function compose( array $sections, $company, $url, $today ) {
		$parts = array();
		$count = 0;

		foreach ( $sections as $s ) {
			$lines = array_values( array_filter( (array) $s['lines'], 'strlen' ) );
			if ( ! $lines ) {
				continue;
			}
			$count  += count( $lines );
			$parts[] = $s['title'] . "\n" . implode( "\n", array_map( function ( $l ) { return '– ' . $l; }, array_slice( $lines, 0, 15 ) ) ) . ( count( $lines ) > 15 ? "\n– … " . ( count( $lines ) - 15 ) . ' ' . __( 'weitere', 'lindenweg16' ) : '' );
		}

		return array(
			/* translators: 1: Firma, 2: Datum, 3: Anzahl. */
			'subject' => sprintf( __( '%1$s · Heute zu tun (%2$s): %3$d Punkte', 'lindenweg16' ), $company, Kit::dmy( $today ), $count ),
			'body'    => $count ? implode( "\n\n", $parts ) . "\n\n" . __( 'Im ERP:', 'lindenweg16' ) . ' ' . $url : '',
			'count'   => $count,
		);
	}

	/**
	 * Abschnitte sammeln.
	 *
	 * @return array
	 */
	public static function sections() {
		global $wpdb;

		$today = current_time( 'Y-m-d' );
		$out   = array();

		// Mahnungen.
		$lines = array();
		foreach ( Pages\Mahnwesen::all_items() as $i ) {
			if ( $i['next_level'] && $i['next_due'] ) {
				/* translators: 1: Stufe, 2: Firma, 3: Nummer, 4: Betrag, 5: Tage. */
				$lines[] = sprintf( __( '%1$s für %2$s (%3$s, %4$s, %5$d Tage überfällig)', 'lindenweg16' ), $i['next_label'], $i['company'], $i['number'], Kit::eur( $i['value'] ), $i['overdue'] );
			}
		}
		$out[] = array( 'title' => __( 'Mahnungen fällig', 'lindenweg16' ), 'lines' => $lines );

		// Angebote, die in drei Tagen ablaufen.
		$lines = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT number, recipient, valid_until, total_gross FROM ' . Kit::table( 'quotes' ) . " WHERE status = 'versendet' AND valid_until >= %s AND valid_until <= %s", $today, Kit::add_days( $today, 3 ) ), ARRAY_A ) as $q ) { // phpcs:ignore WordPress.DB.PreparedSQL
			/* translators: 1: Nummer, 2: Empfänger, 3: Betrag, 4: Datum. */
			$lines[] = sprintf( __( 'Angebot %1$s an %2$s (%3$s) läuft am %4$s ab – nachfassen?', 'lindenweg16' ), $q['number'], $q['recipient'], Kit::eur( $q['total_gross'] ), Kit::dmy( $q['valid_until'] ) );
		}
		$out[] = array( 'title' => __( 'Angebote', 'lindenweg16' ), 'lines' => $lines );

		// Urlaubsanträge.
		$lines = array();
		foreach ( (array) $wpdb->get_results( "SELECT user_id, date_from, date_to, days FROM " . Kit::table( 'absences' ) . " WHERE status = 'beantragt' ORDER BY date_from ASC", ARRAY_A ) as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$u = get_userdata( (int) $a['user_id'] );
			/* translators: 1: Name, 2: von, 3: bis, 4: Tage. */
			$lines[] = sprintf( __( '%1$s: Urlaub %2$s – %3$s (%4$s Tage) entscheiden', 'lindenweg16' ), $u ? $u->display_name : '#' . (int) $a['user_id'], Kit::dmy( $a['date_from'] ), Kit::dmy( $a['date_to'] ), str_replace( '.', ',', (string) ( 0 + (float) $a['days'] ) ) );
		}
		$out[] = array( 'title' => __( 'Urlaubsanträge', 'lindenweg16' ), 'lines' => $lines );

		// Bestellscheine ohne Bestätigung nach drei Tagen.
		$lines = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT number, supplier, sent_at FROM ' . Kit::table( 'supplier_orders' ) . " WHERE status = 'versendet' AND sent_at < %s", gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), ARRAY_A ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL
			/* translators: 1: Nummer, 2: Lieferant, 3: Datum. */
			$lines[] = sprintf( __( 'Bestellung %1$s bei %2$s seit %3$s unbestätigt', 'lindenweg16' ), $o['number'], $o['supplier'], Kit::dmy( substr( $o['sent_at'], 0, 10 ) ) );
		}
		$out[] = array( 'title' => __( 'Bestellungen an Lieferanten', 'lindenweg16' ), 'lines' => $lines );

		// Reklamationen älter als 14 Tage.
		$lines = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT number, customer, created_at FROM ' . Kit::table( 'rma_cases' ) . " WHERE status <> 'erledigt' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ) ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
			/* translators: 1: Nummer, 2: Kunde, 3: Datum. */
			$lines[] = sprintf( __( 'Reklamation %1$s (%2$s) offen seit %3$s', 'lindenweg16' ), $c['number'], $c['customer'], Kit::dmy( substr( $c['created_at'], 0, 10 ) ) );
		}
		$out[] = array( 'title' => __( 'Reklamationen', 'lindenweg16' ), 'lines' => $lines );

		// Bank.
		$lines = array();
		$open  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Kit::table( 'bank_transactions' ) . " WHERE status = 'offen' AND (ext_state IS NULL OR ext_state <> 'pending')" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $open ) {
			/* translators: %d: Anzahl. */
			$lines[] = sprintf( _n( '%d Bankumsatz ist noch keinem Beleg oder keiner Rechnung zugeordnet', '%d Bankumsätze sind noch keinem Beleg oder keiner Rechnung zugeordnet', $open, 'lindenweg16' ), $open );
		}
		$v = Vivid::state();
		if ( '' !== $v['key'] && '' !== $v['expires'] ) {
			$days = Kit::days_between( $today, $v['expires'] );
			if ( $days <= 14 ) {
				$lines[] = $days < 0
					? __( 'Der Vivid-API-Schlüssel ist abgelaufen – Umsätze kommen nicht mehr. Neuen Schlüssel in Vivid Business → API anlegen.', 'lindenweg16' )
					/* translators: %d: Tage. */
					: sprintf( __( 'Der Vivid-API-Schlüssel läuft in %d Tagen ab. Rechtzeitig einen neuen anlegen.', 'lindenweg16' ), $days );
			}
		}
		if ( '' !== $v['last_error'] ) {
			$lines[] = __( 'Vivid-Abruf fehlgeschlagen:', 'lindenweg16' ) . ' ' . $v['last_error'];
		}
		$out[] = array( 'title' => __( 'Bank', 'lindenweg16' ), 'lines' => $lines );

		// Nachkauf: Händler, deren Bestellung in den nächsten Tagen fällig ist (nur sichere, noch nicht angeschrieben).
		$lines = array();
		$marks = get_option( 'lw16_erp_nachkauf', array() );
		foreach ( Pages\Nachkauf::customers() as $c ) {
			$m = is_array( $marks ) && isset( $marks[ $c['key'] ] ) ? $marks[ $c['key'] ] : array();
			if ( $c['b2b'] && 'bald' === $c['state'] && $c['days_to'] <= 7 && $c['confidence'] >= 0.5 && ( empty( $m['mailed'] ) || $m['mailed'] <= $c['last'] ) && ( empty( $m['snooze'] ) || $m['snooze'] < $today ) ) {
				/* translators: 1: Name, 2: Tage. */
				$lines[] = sprintf( __( '%1$s bestellt üblicherweise in %2$d Tagen wieder – jetzt melden?', 'lindenweg16' ), $c['name'], max( 0, $c['days_to'] ) );
			}
		}
		$out[] = array( 'title' => __( 'Nachkauf', 'lindenweg16' ), 'lines' => $lines );

		// Liquidität.
		$lines = array();
		$liq   = Pages\Liquiditaet::overview()->get_data();
		if ( $liq['first_below'] > -1 ) {
			$w = $liq['rows'][ $liq['first_below'] ];
			/* translators: 1: Woche, 2: Datum, 3: Betrag, 4: Puffer. */
			$lines[] = sprintf( __( 'In %1$s (ab %2$s) fällt der Kontostand voraussichtlich auf %3$s – unter den Puffer von %4$s.', 'lindenweg16' ), $w['label'], Kit::dmy( $w['from'] ), Kit::eur( $w['balance'] ), Kit::eur( $liq['buffer'] ) );
		}
		$out[] = array( 'title' => __( 'Liquidität', 'lindenweg16' ), 'lines' => $lines );

		return $out;
	}

	/**
	 * Tagesjob.
	 */
	public static function run() {
		$s = Kit::settings( 'digest' );

		if ( ! (int) $s['aktiv'] ) {
			return;
		}

		$c    = Kit::company();
		$to   = is_email( (string) $s['an'] ) ? (string) $s['an'] : $c['email'];
		$mail = self::compose( self::sections(), $c['name'], ( Subdomain::host() ? 'https://' . Subdomain::host() . '/' : home_url( '/erp/' ) ), current_time( 'Y-m-d' ) );

		if ( $mail['count'] && is_email( $to ) ) {
			wp_mail( $to, $mail['subject'], $mail['body'] );
		}
	}
}
