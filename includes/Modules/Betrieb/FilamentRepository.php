<?php
/**
 * Filamentchargen.
 *
 * Der Verbrauch wird nicht gepflegt, sondern aus den Druckjobs gerechnet:
 * Summe über geplante Teile mal Bauteilgewicht der jeweiligen Revision.
 * Der Restbestand ergibt sich daraus.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Filamentchargen.
 */
class FilamentRepository {

	use SanitizesInput;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_filament_lots';
	}

	/**
	 * Alle Chargen.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY received_on DESC, id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Eine Charge holen.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Die Charge, aus der eine Farbe gerade verbraucht wird: die älteste mit Rest.
	 *
	 * Die Charge ist ein innerer Anker (eine Lieferung); gesehen und gewählt
	 * wird sie nie. Wer druckt, nennt Farbe und Material, der Verbrauch geht
	 * von selbst auf die älteste Lieferung dieser Farbe.
	 *
	 * @param string $color    Farbe (Slug).
	 * @param string $material Material, leer = egal.
	 * @return int Charge oder 0.
	 */
	public static function default_lot_for_color( $color, $material = '' ) {
		$color = ColorRegistry::normalize( $color );

		if ( '' === $color ) {
			return 0;
		}

		$lots = array_reverse( self::all() ); // älteste zuerst.

		foreach ( $lots as $lot ) {
			if ( ColorRegistry::normalize( (string) $lot['color'] ) !== $color ) {
				continue;
			}

			if ( '' !== $material && strcasecmp( (string) $lot['material'], $material ) !== 0 ) {
				continue;
			}

			if ( self::remaining_kg( $lot ) > 0.0005 ) {
				return (int) $lot['id'];
			}
		}

		return 0;
	}

	/**
	 * Verbrauch einer Charge in Kilogramm.
	 *
	 * @param int $lot_id Charge.
	 * @return float
	 */
	public static function consumption_kg( $lot_id ) {
		global $wpdb;

		$jobs      = PrintJobRepository::table();
		$revisions = RevisionRepository::table();

		$gramm = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( j.parts_planned * r.weight_g ), 0 )
				 FROM {$jobs} j
				 INNER JOIN {$revisions} r ON r.id = j.article_revision_id
				 WHERE j.filament_lot_id = %d AND j.voided_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $lot_id
			)
		);

		return $gramm / 1000;
	}

	/**
	 * Restbestand einer Charge.
	 *
	 * @param array $lot Charge.
	 * @return float
	 */
	public static function remaining_kg( array $lot ) {
		// Hat die Charge Rollen, zählen sie: leer gescannte fehlen im Bestand.
		$from_rolls = FilamentRolls::remaining_kg( (int) $lot['id'] );

		if ( null !== $from_rolls ) {
			return $from_rolls;
		}

		return (float) $lot['kg_received'] - self::consumption_kg( (int) $lot['id'] );
	}

	/**
	 * Muss die Charge getrocknet werden?
	 *
	 * PETG und ASA ziehen Feuchtigkeit. Eine Charge, die seit über 30 Tagen
	 * nicht getrocknet wurde, ist der häufigste Grund für schlechte erste
	 * Schichten – deshalb erscheint sie als Hinweis.
	 *
	 * @param array $lot Charge.
	 * @return bool
	 */
	public static function needs_drying( array $lot ) {
		if ( empty( $lot['dried_on'] ) ) {
			return true;
		}

		$dried = strtotime( (string) $lot['dried_on'] . ' 12:00:00 UTC' );

		return $dried && ( time() - $dried ) > 30 * DAY_IN_SECONDS;
	}

	/**
	 * Interner Code für eine Lieferung, die keinen bekommen hat.
	 *
	 * @return string
	 */
	protected static function auto_code() {
		global $wpdb;
		$table = self::table();

		for ( $i = 0; $i < 20; $i++ ) {
			$code = 'F' . gmdate( 'ymd' ) . '-' . wp_rand( 1000, 9999 );

			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE lot_code = %s", $code ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $code;
			}
		}

		return 'F' . gmdate( 'ymdHis' );
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array    $input Eingaben.
	 * @param int|null $id    Eigene ID.
	 * @return array {data, errors}
	 */
	public static function validate( array $input, $id = null ) {
		global $wpdb;

		$errors = array();
		$code   = isset( $input['lot_code'] ) ? strtoupper( sanitize_text_field( $input['lot_code'] ) ) : '';

		// Die Charge ist nur noch ein innerer Anker: Ohne Code wird einer vergeben.
		if ( '' === $code ) {
			$code = self::auto_code();
		}

		if ( '' !== $code ) {
			$table    = self::table();
			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE lot_code = %s", $code ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			if ( $existing && (int) $existing['id'] !== (int) $id ) {
				$errors[] = sprintf(
					/* translators: %s: Chargencode. */
					__( 'Der Chargencode %s ist bereits vergeben.', 'lindenweg16' ),
					$code
				);
			}
		}

		$kg = self::decimal_or( $input, 'kg_received', 0 );
		if ( $kg <= 0 ) {
			$errors[] = __( 'Die Zugangsmenge muss größer als 0 sein.', 'lindenweg16' );
		}

		return array(
			'data'   => array(
				'lot_code'     => $code,
				'received_on'  => self::date_or_today( $input, 'received_on' ),
				'material'     => isset( $input['material'] ) ? strtoupper( sanitize_text_field( $input['material'] ) ) : 'PETG',
				'manufacturer' => self::text( $input, 'manufacturer' ),
				'color'        => ColorRegistry::normalize( isset( $input['color'] ) ? $input['color'] : '' ),
				'kg_received'  => $kg,
				'price_per_kg' => self::decimal_or( $input, 'price_per_kg', 0 ),
				'dried_on'     => self::date( $input, 'dried_on' ),
				'location'     => self::text( $input, 'location' ),
				'notes'        => isset( $input['notes'] ) ? sanitize_textarea_field( $input['notes'] ) : null,
			),
			'errors' => $errors,
		);
	}

	/**
	 * Charge anlegen.
	 *
	 * @param array $data Geprüfte Daten.
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'filament_lots', $id, null, $data );

		// Jede Lieferung bekommt gleich ihre Rollen - mit Etikett zum Aufkleben.
		FilamentRolls::create_for_lot( $id );

		return $id;
	}

	/**
	 * Trocknung vermerken.
	 *
	 * @param int    $id   Charge.
	 * @param string $date Datum.
	 * @return bool
	 */
	public static function mark_dried( $id, $date = '' ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		$data = array(
			'dried_on'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? $date : gmdate( 'Y-m-d' ),
			'updated_at' => current_time( 'mysql', true ),
		);

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'update', 'filament_lots', (int) $id, $before, $data, __( 'Trocknung vermerkt.', 'lindenweg16' ) );

		return true;
	}
}
