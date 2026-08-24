<?php
/**
 * Stundenlöhne je Nutzer.
 *
 * Ein Lohn wird nie überschrieben, sondern bekommt eine neue Zeile mit
 * einem Stichtag. Das ist dieselbe Regel wie bei den Kalkulationsgrundlagen
 * und aus demselben Grund: Vergangene Kosten müssen vergangene Kosten
 * bleiben.
 *
 * Löhne sind Personaldaten. Sie hängen deshalb am Recht lw16_manage_users
 * und werden wie jede andere Änderung ins hashverkettete Protokoll
 * geschrieben.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Löhne.
 */
class WageRepository {

	use SanitizesInput;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_user_rates';
	}

	/**
	 * Alle Lohnzeilen, älteste zuerst.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY valid_from ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Der Verlauf eines Nutzers, neueste zuerst.
	 *
	 * @param int $user_id Nutzer.
	 * @return array
	 */
	public static function history( $user_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY valid_from DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id
			),
			ARRAY_A
		);
	}

	/**
	 * Der heute gültige Lohn eines Nutzers.
	 *
	 * @param int    $user_id  Nutzer.
	 * @param string $stichtag Datum als Y-m-d. Leer = heute.
	 * @return float|null
	 */
	public static function current( $user_id, $stichtag = '' ) {
		$stichtag = '' === $stichtag ? gmdate( 'Y-m-d' ) : $stichtag;

		return LaborCost::rate_at( self::all(), $user_id, $stichtag );
	}

	/**
	 * Einen Lohn ab einem Stichtag festlegen.
	 *
	 * @param int    $user_id    Nutzer.
	 * @param float  $rate       Stundenlohn.
	 * @param string $valid_from Stichtag als Y-m-d.
	 * @param string $note       Bemerkung.
	 * @return array {success, message}
	 */
	public static function set( $user_id, $rate, $valid_from, $note = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$rate    = (float) str_replace( ',', '.', (string) $rate );
		$from    = CalcSettings::sanitize_date( $valid_from );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return array( 'success' => false, 'message' => __( 'Diesen Nutzer gibt es nicht.', 'lindenweg16' ) );
		}

		if ( $rate <= 0 ) {
			return array( 'success' => false, 'message' => __( 'Ein Stundenlohn muss größer als 0 sein.', 'lindenweg16' ) );
		}

		if ( $rate > 500 ) {
			return array( 'success' => false, 'message' => __( 'Über 500 € je Stunde ist ein Tippfehler.', 'lindenweg16' ) );
		}

		if ( '' === $from ) {
			return array( 'success' => false, 'message' => __( 'Bitte einen Stichtag angeben, ab dem der Lohn gilt.', 'lindenweg16' ) );
		}

		$table    = self::table();
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND valid_from = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$from
			),
			ARRAY_A
		);

		$data = array(
			'user_id'    => $user_id,
			'rate_per_h' => $rate,
			'valid_from' => $from,
			'note'       => $note ? sanitize_text_field( $note ) : null,
		);

		// Zum selben Stichtag gibt es genau einen Lohn – ein zweiter wäre
		// nicht auflösbar. Eine Korrektur am selben Tag ist deshalb eine
		// Änderung und wird als solche protokolliert.
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
			BetriebAudit::record( 'update', 'user_rates', (int) $existing['id'], $existing, $data );

			return array( 'success' => true, 'message' => __( 'Stundenlohn für diesen Stichtag geändert.', 'lindenweg16' ) );
		}

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( $table, $data ) ) {
			return array( 'success' => false, 'message' => __( 'Der Stundenlohn ließ sich nicht speichern.', 'lindenweg16' ) );
		}

		BetriebAudit::record( 'create', 'user_rates', (int) $wpdb->insert_id, null, $data );

		return array( 'success' => true, 'message' => __( 'Stundenlohn gespeichert.', 'lindenweg16' ) );
	}
}
