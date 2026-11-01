<?php
/**
 * Anbindung an die Vivid Business API (Version 1, nur lesend).
 *
 * Dokumentation: https://api.prime.vivid.money/sme-integration/docs/
 * - Basis: https://api.prime.vivid.money/sme-integration, alle Aufrufe POST mit JSON.
 * - Schlüssel im Header `Authorization`, genau wie ausgegeben, ohne „Bearer“.
 * - Rechte des Schlüssels: accounts:read, transactions:read.
 * - Umsätze der letzten 90 Tage, seitenweise (limit bis 500, cursor/next_cursor).
 *   Zum Abgleich die letzten 14 Tage erneut lesen und nach Kennung überschreiben
 *   (vorgemerkte Umsätze ändern noch Betrag und Zustand).
 * - 60 Aufrufe je Minute und Schlüssel; bei 429 „Retry-After“ abwarten.
 *
 * Wichtig: Version 1 liefert bei Überweisungen weder Verwendungszweck noch
 * Name oder IBAN der Gegenseite, nur bei Kartenzahlungen den Händler. Darum
 * ergänzt ein CAMT-Auszug derselben Umsätze diese Angaben (Bank::merge_key).
 *
 * Der Schlüssel liegt verschlüsselt in der Datenbank (libsodium, Schlüssel aus
 * den WordPress-Salts oder LW16_MAIL_KEY) und verlässt den Server nicht.
 *
 * Reine Regeln sind getestet (tests/ErpVividTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Vivid Business API.
 */
class Vivid {

	const BASE = 'https://api.prime.vivid.money/sme-integration';

	/**
	 * Option mit Schlüssel (verschlüsselt) und Zustand der Anbindung.
	 */
	const OPTION = 'lw16_erp_vivid';

	/**
	 * Tage, die bei jedem Abruf erneut gelesen werden.
	 */
	const RELOAD_DAYS = 14;

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Sieht der Text aus wie ein Vivid-Schlüssel?
	 *
	 * @param string $key Schlüssel.
	 * @return bool
	 */
	public static function looks_like_key( $key ) {
		return (bool) preg_match( '/^vivid_sk_v\d+_[A-Za-z0-9_\-]{16,}$/', trim( (string) $key ) );
	}

	/**
	 * Verständlicher Text zu einem Fehler der API.
	 *
	 * @param int    $status HTTP-Status.
	 * @param string $code   Fehlercode der API.
	 * @return string
	 */
	public static function error_text( $status, $code ) {
		$texts = array(
			'invalid_key'            => __( 'Vivid kennt diesen API-Schlüssel nicht. Bitte den Schlüssel vollständig neu eintragen.', 'lindenweg16' ),
			'key_revoked'            => __( 'Der API-Schlüssel wurde in Vivid gelöscht. Bitte in Vivid Business → API einen neuen anlegen.', 'lindenweg16' ),
			'key_expired'            => __( 'Der API-Schlüssel ist abgelaufen (höchstens 180 Tage gültig). Bitte in Vivid Business → API einen neuen anlegen.', 'lindenweg16' ),
			'address_not_allowed'    => __( 'Der Schlüssel ist auf bestimmte IP-Adressen beschränkt, und dieser Server gehört nicht dazu. In Vivid die IP-Adresse des Servers erlauben.', 'lindenweg16' ),
			'scope_denied'           => __( 'Dem Schlüssel fehlt ein Recht. Bitte einen Schlüssel mit „accounts:read“ und „transactions:read“ anlegen.', 'lindenweg16' ),
			'user_not_in_entity'     => __( 'Die Person, die den Schlüssel angelegt hat, ist nicht mehr im Vivid-Team. Bitte einen neuen Schlüssel von einem aktiven Mitglied.', 'lindenweg16' ),
			'issuer_not_permitted'   => __( 'Die Person, die den Schlüssel angelegt hat, darf keine API-Schlüssel mehr verwalten. Bitte einen neuen Schlüssel anlegen.', 'lindenweg16' ),
			'entity_inactive'        => __( 'Vivid erlaubt eurem Konto die API gerade nicht (z. B. kostenloser Tarif). Bitte den Vivid-Support fragen.', 'lindenweg16' ),
			'rate_limited'           => __( 'Zu viele Abrufe bei Vivid. Der nächste Abruf versucht es automatisch wieder.', 'lindenweg16' ),
			'outside_history_window' => __( 'Vivid gibt nur Umsätze der letzten 90 Tage heraus.', 'lindenweg16' ),
			'invalid_request'        => __( 'Vivid hat die Anfrage abgelehnt. Bitte melden – das ist ein Fehler im Plugin.', 'lindenweg16' ),
		);

		if ( isset( $texts[ $code ] ) ) {
			return $texts[ $code ];
		}
		if ( $status >= 500 || 0 === (int) $status ) {
			return __( 'Vivid ist gerade nicht erreichbar. Der nächste Abruf versucht es wieder.', 'lindenweg16' );
		}

		/* translators: 1: HTTP-Status, 2: Code. */
		return sprintf( __( 'Vivid meldet einen Fehler (%1$d %2$s).', 'lindenweg16' ), (int) $status, (string) $code );
	}

	/**
	 * Zeitpunkt RFC 3339 → MySQL UTC.
	 *
	 * @param string $rfc Zeit.
	 * @return string 'Y-m-d H:i:s' oder leer.
	 */
	public static function mysql_time( $rfc ) {
		$ts = strtotime( (string) $rfc );

		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}

	/**
	 * Beschreibung eines Umsatzes für Name und Zweck (Vivid liefert keinen Verwendungszweck).
	 *
	 * @param array $tx Umsatz der API.
	 * @return array {counterparty, purpose}
	 */
	public static function describe( array $tx ) {
		$types = array(
			'card_payment' => __( 'Kartenzahlung', 'lindenweg16' ),
			'card_refund'  => __( 'Kartenerstattung', 'lindenweg16' ),
			'atm'          => __( 'Geldautomat', 'lindenweg16' ),
			'transfer'     => __( 'Überweisung', 'lindenweg16' ),
			'exchange'     => __( 'Währungstausch', 'lindenweg16' ),
			'fee'          => __( 'Gebühr Vivid', 'lindenweg16' ),
			'other'        => __( 'Umsatz', 'lindenweg16' ),
		);
		$type     = isset( $types[ (string) $tx['type'] ] ) ? (string) $tx['type'] : 'other';
		$merchant = isset( $tx['merchant'] ) && is_array( $tx['merchant'] ) ? $tx['merchant'] : null;
		$parts    = array( $types[ $type ] );

		if ( $merchant && '' !== (string) $merchant['city'] ) {
			$parts[] = trim( (string) $merchant['city'] . ' ' . (string) $merchant['country'] );
		}
		if ( ! empty( $tx['card'] ) && is_array( $tx['card'] ) && '' !== (string) $tx['card']['last_4'] ) {
			$parts[] = '•••• ' . $tx['card']['last_4'];
		}
		if ( ! empty( $tx['original_amount'] ) && ! empty( $tx['original_currency'] ) ) {
			$parts[] = $tx['original_amount'] . ' ' . $tx['original_currency'];
		}

		return array(
			'counterparty' => $merchant ? trim( (string) $merchant['name'] ) : '',
			'purpose'      => implode( ' · ', $parts ),
		);
	}

	/**
	 * Umsatz der API → Zeile für bank_transactions.
	 *
	 * @param array $tx Umsatz.
	 * @return array|null Null bei fehlenden Pflichtwerten.
	 */
	public static function to_row( array $tx ) {
		if ( empty( $tx['id'] ) || ! isset( $tx['amount'] ) || ! is_numeric( $tx['amount'] ) ) {
			return null;
		}

		$when  = self::mysql_time( ! empty( $tx['show_at'] ) ? $tx['show_at'] : ( isset( $tx['created_at'] ) ? $tx['created_at'] : '' ) );
		$desc  = self::describe( $tx );
		$state = in_array( (string) $tx['state'], array( 'pending', 'completed', 'declined' ), true ) ? (string) $tx['state'] : 'other';

		if ( '' === $when ) {
			return null;
		}

		return array(
			'external_id'    => substr( (string) $tx['id'], 0, 64 ),
			'account_ext'    => (string) $tx['account_id'],
			'fingerprint'    => sha1( 'vivid|' . (string) $tx['id'] ),
			'booking_date'   => substr( $when, 0, 10 ),
			'value_date'     => substr( $when, 0, 10 ),
			'amount'         => round( (float) $tx['amount'], 2 ),
			'currency'       => strtoupper( substr( (string) $tx['currency'], 0, 3 ) ),
			'counterparty'   => $desc['counterparty'],
			'purpose'        => $desc['purpose'],
			'ext_state'      => $state,
			'ext_type'       => substr( (string) $tx['type'], 0, 20 ),
			'card_last4'     => ! empty( $tx['card'] ) && is_array( $tx['card'] ) ? substr( (string) $tx['card']['last_4'], 0, 4 ) : null,
			'ext_updated_at' => self::mysql_time( isset( $tx['updated_at'] ) ? $tx['updated_at'] : '' ),
		);
	}

	/**
	 * Abschnitt, ab dem beim nächsten Abruf gelesen wird.
	 *
	 * @param string $last_sync Letzter erfolgreicher Abruf (UTC, MySQL) oder leer.
	 * @param int    $now       Jetzt (Unix).
	 * @return string RFC 3339 oder leer (= alles, 90 Tage).
	 */
	public static function updated_from( $last_sync, $now ) {
		if ( '' === (string) $last_sync ) {
			return '';
		}

		$from = strtotime( $last_sync . ' UTC' ) - self::RELOAD_DAYS * 86400;

		return $from > $now - 89 * 86400 ? gmdate( 'Y-m-d\TH:i:s\Z', $from ) : '';
	}

	/**
	 * Konto der API → Zeile für bank_accounts.
	 *
	 * @param array $a Konto.
	 * @return array
	 */
	public static function account_row( array $a ) {
		$iban = '';
		$bic  = '';

		foreach ( (array) ( isset( $a['account_details'] ) ? $a['account_details'] : array() ) as $d ) {
			if ( '' === $iban && ! empty( $d['iban'] ) ) {
				$iban = strtoupper( str_replace( ' ', '', (string) $d['iban'] ) );
				$bic  = (string) $d['bic'];
			}
		}

		$bal = isset( $a['balance'] ) && is_array( $a['balance'] ) ? $a['balance'] : null;

		return array(
			'external_id'       => substr( (string) $a['id'], 0, 64 ),
			'name'              => '' !== (string) $a['name'] ? (string) $a['name'] : __( 'Vivid-Konto', 'lindenweg16' ),
			'iban'              => $iban,
			'bic'               => substr( $bic, 0, 20 ),
			'currency'          => strtoupper( substr( (string) $a['currency'], 0, 3 ) ),
			'state'             => substr( (string) $a['state'], 0, 20 ),
			'balance_available' => $bal && is_numeric( $bal['available'] ) ? round( (float) $bal['available'], 2 ) : null,
			'balance_pending'   => $bal && isset( $bal['pending'] ) && is_numeric( $bal['pending'] ) ? round( (float) $bal['pending'], 2 ) : null,
		);
	}

	/* ====================================================================
	 * Mit WordPress
	 * ================================================================== */

	/**
	 * Zustand der Anbindung.
	 *
	 * @return array {key, key_hint, expires, last_sync, last_error, last_error_at, enabled}
	 */
	public static function state() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();

		return array_merge(
			array(
				'key'           => '',
				'key_hint'      => '',
				'expires'       => '',
				'last_sync'     => '',
				'last_error'    => '',
				'last_error_at' => '',
				'enabled'       => 0,
			),
			$s
		);
	}

	/**
	 * Zustand ändern.
	 *
	 * @param array $change Änderungen.
	 */
	public static function update_state( array $change ) {
		update_option( self::OPTION, array_merge( self::state(), $change ), false );
	}

	/**
	 * Entschlüsselter Schlüssel.
	 *
	 * @return string
	 */
	public static function key() {
		$s = self::state();

		if ( '' === $s['key'] ) {
			return '';
		}

		$plain = \Lindenweg16\Modules\Mailbox\MailCrypto::decrypt( $s['key'] );

		return null === $plain ? '' : $plain;
	}

	/**
	 * Ist eine Anbindung eingerichtet?
	 *
	 * @return bool
	 */
	public static function connected() {
		$s = self::state();

		return '' !== $s['key'] && (int) $s['enabled'];
	}

	/**
	 * Ein Aufruf. Wiederholt bei 429 bis zu zweimal nach „Retry-After“ (höchstens 10 s).
	 *
	 * @param string $path Pfad ab /api/v1.
	 * @param array  $body Anfrage.
	 * @param string $key  Schlüssel (leer = gespeicherter).
	 * @return array {ok, status, data, code, message}
	 */
	public static function call( $path, array $body = array(), $key = '' ) {
		$key = '' !== $key ? $key : self::key();

		if ( '' === $key ) {
			return array( 'ok' => false, 'status' => 0, 'data' => null, 'code' => 'no_key', 'message' => __( 'Es ist kein Vivid-Schlüssel hinterlegt.', 'lindenweg16' ) );
		}

		for ( $try = 0; $try < 3; $try++ ) {
			$res = wp_remote_post(
				self::BASE . '/api/v1/' . ltrim( $path, '/' ),
				array(
					'timeout' => 25,
					'headers' => array(
						'Authorization' => $key,
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					),
					'body'    => wp_json_encode( $body ? $body : new \stdClass() ),
				)
			);

			if ( is_wp_error( $res ) ) {
				return array( 'ok' => false, 'status' => 0, 'data' => null, 'code' => 'network', 'message' => self::error_text( 0, 'network' ) . ' (' . $res->get_error_message() . ')' );
			}

			$status = (int) wp_remote_retrieve_response_code( $res );
			$data   = json_decode( (string) wp_remote_retrieve_body( $res ), true );

			if ( 429 === $status && $try < 2 ) {
				$wait = (int) wp_remote_retrieve_header( $res, 'retry-after' );
				sleep( max( 1, min( 10, $wait ? $wait : 2 << $try ) ) );
				continue;
			}

			if ( $status >= 200 && $status < 300 && is_array( $data ) ) {
				return array( 'ok' => true, 'status' => $status, 'data' => $data, 'code' => '', 'message' => '' );
			}

			$code  = is_array( $data ) && ! empty( $data['errors'][0]['code'] ) ? (string) $data['errors'][0]['code'] : '';
			$trace = (string) wp_remote_retrieve_header( $res, 'x-trace-id' );
			$msg   = self::error_text( $status, $code ) . ( '' !== $trace ? ' [Trace ' . $trace . ']' : '' );

			return array( 'ok' => false, 'status' => $status, 'data' => $data, 'code' => $code, 'message' => $msg );
		}

		return array( 'ok' => false, 'status' => 429, 'data' => null, 'code' => 'rate_limited', 'message' => self::error_text( 429, 'rate_limited' ) );
	}
}
