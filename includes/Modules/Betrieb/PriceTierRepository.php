<?php
/**
 * Standard-Rabattstaffel für den Fachhandel.
 *
 * Die Staffel gilt für alle Händler ohne Sondervereinbarung. Sie ist
 * historisiert wie jede andere Annahme: Eine Änderung überschreibt nichts,
 * sondern schließt die bisherigen Stufen zum Vortag ab und schreibt neue ab
 * dem Stichtag fest. Eine Auswertung vom März rechnet damit weiter mit der
 * März-Staffel – auch wenn im Juli andere Sätze gelten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Fortschreiben der Standardstaffel.
 */
class PriceTierRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_price_tiers';
	}

	/**
	 * Zum Stichtag gültige Stufen.
	 *
	 * @param string $date Stichtag.
	 * @return array
	 */
	public static function at( $date = '' ) {
		return CalculationContext::price_tiers( $date );
	}

	/**
	 * Alle Stufen, auch abgelaufene – für die Verlaufsanzeige.
	 *
	 * @return array
	 */
	public static function history() {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY valid_from DESC, min_order_value ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Eingegebene Stufen prüfen – reine Logik, ohne Datenbank.
	 *
	 * @param array $rows Zeilen mit tier_name, min_order_value, discount_pct.
	 * @return array {tiers, errors}
	 */
	public static function validate( array $rows ) {
		$errors = array();
		$tiers  = array();

		foreach ( $rows as $row ) {
			$row   = (array) $row;
			$name  = isset( $row['tier_name'] ) ? trim( (string) $row['tier_name'] ) : '';
			$from  = isset( $row['min_order_value'] ) ? (float) str_replace( ',', '.', (string) $row['min_order_value'] ) : 0.0;
			$pct   = isset( $row['discount_pct'] ) ? (float) str_replace( ',', '.', (string) $row['discount_pct'] ) : 0.0;

			// Leere Zeilen sind kein Fehler – das Formular hat immer eine
			// Reservezeile mehr, als gebraucht wird.
			if ( '' === $name && $from <= 0 && $pct <= 0 ) {
				continue;
			}

			if ( '' === $name ) {
				$name = sprintf(
					/* translators: %d: laufende Nummer. */
					__( 'Stufe %d', 'lindenweg16' ),
					count( $tiers ) + 1
				);
			}

			if ( $pct < 0 || $pct >= 100 ) {
				$errors[] = sprintf(
					/* translators: %s: Stufenname. */
					__( 'Der Rabatt bei %s muss zwischen 0 und unter 100 Prozent liegen.', 'lindenweg16' ),
					$name
				);
				continue;
			}

			if ( $from < 0 ) {
				$errors[] = sprintf(
					/* translators: %s: Stufenname. */
					__( 'Ein negativer Mindestbestellwert bei %s ergibt keinen Sinn.', 'lindenweg16' ),
					$name
				);
				continue;
			}

			$tiers[] = array(
				'tier_name'       => $name,
				'min_order_value' => $from,
				'discount_pct'    => $pct,
			);
		}

		if ( ! $tiers ) {
			$errors[] = __( 'Mindestens eine Stufe ist nötig. Soll es gar keinen Staffelrabatt geben, trag eine Stufe mit 0 Prozent ein.', 'lindenweg16' );
			return array( 'tiers' => array(), 'errors' => $errors );
		}

		// Nach Bestellwert sortieren, damit die Auflösung eindeutig ist.
		usort(
			$tiers,
			static function ( $a, $b ) {
				return $a['min_order_value'] <=> $b['min_order_value'];
			}
		);

		// Zwei Stufen ab demselben Wert waeren nicht entscheidbar.
		$seen = array();
		foreach ( $tiers as $tier ) {
			$key = (string) $tier['min_order_value'];
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: Bestellwert. */
					__( 'Zwei Stufen ab demselben Bestellwert (%s) – dann wäre nicht entscheidbar, welche gilt.', 'lindenweg16' ),
					$key
				);
				break;
			}
			$seen[ $key ] = true;
		}

		// Mehr Umsatz muss mehr Rabatt bedeuten, sonst lohnt sich die
		// groessere Bestellung fuer den Haendler nicht.
		$previous = null;
		foreach ( $tiers as $tier ) {
			if ( null !== $previous && $tier['discount_pct'] < $previous ) {
				$errors[] = __( 'Die Rabatte müssen mit dem Bestellwert steigen – sonst wäre die größere Bestellung für den Händler die schlechtere.', 'lindenweg16' );
				break;
			}
			$previous = $tier['discount_pct'];
		}

		return array(
			'tiers'  => $tiers,
			'errors' => $errors,
		);
	}

	/**
	 * Neue Staffel ab einem Stichtag festschreiben.
	 *
	 * Die bisherigen Stufen werden zum Vortag abgeschlossen, nicht gelöscht.
	 *
	 * @param array  $tiers  Geprüfte Stufen.
	 * @param string $from   Gültig ab (Y-m-d).
	 * @param string $reason Begründung.
	 * @return int Anzahl neuer Stufen.
	 */
	public static function replace_from( array $tiers, $from, $reason = '' ) {
		global $wpdb;

		$from = CalcSettings::sanitize_date( $from );
		$day  = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$now  = current_time( 'mysql', true );
		$user = get_current_user_id();
		$table = self::table();

		// Laufende Stufen zum Vortag abschliessen.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET valid_until = %s, updated_at = %s WHERE valid_until IS NULL AND valid_from < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$day,
				$now,
				$from
			)
		);

		// Stufen, die am selben Tag beginnen, werden ersetzt: Zweimal
		// dieselbe Staffel ab demselben Tag waere nicht aufloesbar.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE valid_from = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from
			)
		);

		$count = 0;
		foreach ( $tiers as $tier ) {
			$data = array(
				'tier_name'       => sanitize_text_field( $tier['tier_name'] ),
				'min_order_value' => (float) $tier['min_order_value'],
				'discount_pct'    => (float) $tier['discount_pct'],
				'valid_from'      => $from,
				'note'            => $reason ? sanitize_text_field( $reason ) : null,
				'created_at'      => $now,
				'created_by'      => $user,
			);

			if ( false !== $wpdb->insert( $table, $data ) ) {
				BetriebAudit::record( 'create', 'price_tiers', (int) $wpdb->insert_id, null, $data, $reason );
				$count++;
			}
		}

		return $count;
	}
}
