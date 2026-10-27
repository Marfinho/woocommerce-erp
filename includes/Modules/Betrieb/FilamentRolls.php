<?php
/**
 * Filamentrollen: anlegen, einsetzen, wiegen, leer melden.
 *
 * Eine Charge ist eine Lieferung; sie besteht aus Rollen, und jede Rolle
 * trägt ein eigenes Etikett mit QR-Code. Der Bestand einer Charge ist die
 * Summe ihrer Rollen, die nicht leer sind (siehe RollAllocator).
 *
 * Wird eine leere Rolle gescannt, verschwindet sie aus dem Bestand - mit dem,
 * was die Rechnung ihr noch zugeschrieben hatte, als Schwund festgehalten.
 * Das ist der ehrliche Weg, Filament zu führen: Die Druckjobs schätzen, die
 * leere Spule weiß es genau.
 *
 * Chargen ohne Rollen (alte Daten) laufen unverändert weiter: gelieferte
 * Menge minus Verbrauch.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Lock;
use Lindenweg16\Core\Log;
use Lindenweg16\Core\Settings;
use Lindenweg16\Erp\Material;

/**
 * Zugriff auf die Rollen.
 */
class FilamentRolls {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_filament_rolls';
	}

	/**
	 * Gewicht einer Standardrolle in Gramm.
	 *
	 * @return int
	 */
	public static function default_roll_g() {
		return max( 100, (int) Settings::get( 'filament_rolle_g', 1000 ) );
	}

	/**
	 * Gewicht der leeren Spule in Gramm.
	 *
	 * @return int
	 */
	public static function default_tare_g() {
		return max( 0, (int) Settings::get( 'filament_spule_g', 250 ) );
	}

	/**
	 * Eine Rolle holen.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Eine Rolle über ihren Code holen.
	 *
	 * @param string $code Code (R-00012).
	 * @return array|null
	 */
	public static function find_by_code( $code ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE roll_code = %s", strtoupper( trim( (string) $code ) ) ), ARRAY_A ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Rollen einer Charge.
	 *
	 * @param int $lot_id Charge.
	 * @return array
	 */
	public static function for_lot( $lot_id ) {
		global $wpdb;
		$table = self::table();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE lot_id = %d ORDER BY id ASC", (int) $lot_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Hat die Charge Rollen?
	 *
	 * @param int $lot_id Charge.
	 * @return bool
	 */
	public static function lot_has_rolls( $lot_id ) {
		global $wpdb;
		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE lot_id = %d", (int) $lot_id ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Nächster Rollencode.
	 *
	 * @return string
	 */
	protected static function next_code() {
		global $wpdb;
		$table = self::table();
		$max   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return sprintf( 'R-%05d', $max + 1 );
	}

	/**
	 * Rollen für eine Charge anlegen.
	 *
	 * Bei einer Charge mit bisherigem Verbrauch (alte Daten) werden Rollen,
	 * die rechnerisch schon aufgebraucht sind, sofort als leer geführt - sie
	 * liegen nicht mehr im Regal, und ein Etikett dafür wäre sinnlos.
	 *
	 * @param int      $lot_id Charge.
	 * @param int|null $roll_g Gewicht je Rolle in Gramm; leer = Standard.
	 * @return array Angelegte Rollen (IDs).
	 */
	public static function create_for_lot( $lot_id, $roll_g = null ) {
		global $wpdb;

		$lot = FilamentRepository::find( (int) $lot_id );

		if ( ! $lot || self::lot_has_rolls( $lot_id ) ) {
			return array();
		}

		$weights = RollAllocator::split( (float) $lot['kg_received'], null === $roll_g ? self::default_roll_g() : (int) $roll_g );
		$ids     = array();

		if ( ! Lock::acquire( 'filament_roll_code', 5 ) ) {
			return array();
		}

		try {
			foreach ( $weights as $grams ) {
				$ok = $wpdb->insert(
					self::table(),
					array(
						'roll_code'  => self::next_code(),
						'lot_id'     => (int) $lot_id,
						'start_g'    => (int) $grams,
						'status'     => RollAllocator::STATUS_NEW,
						'location'   => ! empty( $lot['location'] ) ? (string) $lot['location'] : null,
						'created_at' => current_time( 'mysql', true ),
						'created_by' => get_current_user_id(),
					)
				);

				if ( $ok ) {
					$ids[] = (int) $wpdb->insert_id;
				}
			}
		} finally {
			Lock::release( 'filament_roll_code' );
		}

		// Rechnerisch aufgebrauchte Rollen gleich schließen.
		$allocation = self::allocation( (int) $lot_id );
		foreach ( self::for_lot( $lot_id ) as $roll ) {
			if ( isset( $allocation[ (int) $roll['id'] ] ) && $allocation[ (int) $roll['id'] ]['remaining_g'] <= 0.05 && RollAllocator::STATUS_NEW === $roll['status'] ) {
				$wpdb->update(
					self::table(),
					array(
						'status'              => RollAllocator::STATUS_EMPTY,
						'emptied_at'          => current_time( 'mysql', true ),
						'consumed_g_at_close' => $allocation[ (int) $roll['id'] ]['absorbed_g'],
						'written_off_g'       => 0,
						'notes'               => __( 'Bei der Anlage rechnerisch schon aufgebraucht.', 'lindenweg16' ),
					),
					array( 'id' => (int) $roll['id'] )
				);
			}
		}

		if ( $ids ) {
			BetriebAudit::record( 'create', 'filament_rolls', (int) $ids[0], null, array( 'lot_id' => (int) $lot_id, 'rolls' => count( $ids ), 'roll_g' => $weights ), 'Rollen angelegt' );
			Log::normal( 'filament', sprintf( 'Charge %s: %d Rolle(n) angelegt', $lot['lot_code'], count( $ids ) ), array( 'lot_id' => (int) $lot_id ) );
			self::refresh();
		}

		return $ids;
	}

	/**
	 * Anrechnung des Verbrauchs auf die Rollen einer Charge.
	 *
	 * @param int $lot_id Charge.
	 * @return array id => {absorbed_g, remaining_g}
	 */
	public static function allocation( $lot_id ) {
		$rolls = array();

		foreach ( self::for_lot( $lot_id ) as $roll ) {
			$rolls[] = array(
				'id'                  => (int) $roll['id'],
				'status'              => (string) $roll['status'],
				'start_g'             => (float) $roll['start_g'],
				'corr_g'              => (float) $roll['corr_g'],
				'consumed_g_at_close' => (float) $roll['consumed_g_at_close'],
				'opened_ts'           => $roll['opened_at'] ? (int) strtotime( $roll['opened_at'] . ' UTC' ) : 0,
			);
		}

		return RollAllocator::allocate( $rolls, FilamentRepository::consumption_kg( (int) $lot_id ) * 1000 );
	}

	/**
	 * Restbestand einer Charge in Kilogramm aus ihren Rollen.
	 *
	 * @param int $lot_id Charge.
	 * @return float|null Null, wenn die Charge keine Rollen hat.
	 */
	public static function remaining_kg( $lot_id ) {
		if ( ! self::lot_has_rolls( $lot_id ) ) {
			return null;
		}

		return RollAllocator::remaining_g( self::allocation( (int) $lot_id ) ) / 1000;
	}

	/**
	 * Eine Rolle mit allem, was die Anzeige braucht.
	 *
	 * @param array      $roll       Rolle.
	 * @param array|null $allocation Anrechnung der Charge (sonst wird sie gerechnet).
	 * @return array
	 */
	public static function describe( array $roll, $allocation = null ) {
		$lot = FilamentRepository::find( (int) $roll['lot_id'] );

		if ( null === $allocation ) {
			$allocation = self::allocation( (int) $roll['lot_id'] );
		}

		$a         = isset( $allocation[ (int) $roll['id'] ] ) ? $allocation[ (int) $roll['id'] ] : array( 'remaining_g' => 0.0, 'absorbed_g' => 0.0 );
		$color     = $lot ? Material::color_of( $lot['color'] ) : '';
		$remaining = RollAllocator::STATUS_EMPTY === $roll['status'] ? 0.0 : (float) $a['remaining_g'];

		return array(
			'id'          => (int) $roll['id'],
			'code'        => (string) $roll['roll_code'],
			'lot_id'      => (int) $roll['lot_id'],
			'lot_code'    => $lot ? (string) $lot['lot_code'] : '',
			'material'    => $lot ? (string) $lot['material'] : '',
			'manufacturer' => $lot ? (string) $lot['manufacturer'] : '',
			'color'       => $color,
			'color_label' => '' !== $color ? ColorRegistry::label( $color ) : '',
			'status'      => (string) $roll['status'],
			'start_g'     => (int) $roll['start_g'],
			'remaining_g' => round( $remaining ),
			'percent'     => $roll['start_g'] > 0 ? (int) round( $remaining / (float) $roll['start_g'] * 100 ) : 0,
			'location'    => (string) $roll['location'],
			'opened_at'   => (string) $roll['opened_at'],
			'emptied_at'  => (string) $roll['emptied_at'],
			'written_off_g' => null === $roll['written_off_g'] ? null : (float) $roll['written_off_g'],
			'tare_g'      => null === $roll['tare_g'] ? self::default_tare_g() : (int) $roll['tare_g'],
		);
	}

	/**
	 * Rolle in Benutzung nehmen (in den Drucker stecken).
	 *
	 * @param int    $id      Rolle.
	 * @param string $printer Drucker oder Ort, optional.
	 * @return array {ok, message}
	 */
	public static function start_use( $id, $printer = '' ) {
		global $wpdb;

		$roll = self::find( $id );

		if ( ! $roll ) {
			return self::result( false, __( 'Rolle nicht gefunden.', 'lindenweg16' ) );
		}

		if ( RollAllocator::STATUS_EMPTY === $roll['status'] ) {
			return self::result( false, __( 'Diese Rolle ist als leer gemeldet. Mit „Rückgängig" lässt sie sich wieder öffnen.', 'lindenweg16' ) );
		}

		$wpdb->update(
			self::table(),
			array(
				'status'    => RollAllocator::STATUS_IN_USE,
				'opened_at' => $roll['opened_at'] ? $roll['opened_at'] : current_time( 'mysql', true ),
				'location'  => '' !== trim( (string) $printer ) ? sanitize_text_field( $printer ) : $roll['location'],
			),
			array( 'id' => (int) $id )
		);

		BetriebAudit::record( 'update', 'filament_rolls', (int) $id, $roll, self::find( $id ), 'in Benutzung' );
		Log::normal( 'filament', 'Rolle ' . $roll['roll_code'] . ' in Benutzung genommen' );
		self::refresh();

		return self::result( true, sprintf( /* translators: %s: Rollencode. */ __( 'Rolle %s ist in Benutzung.', 'lindenweg16' ), $roll['roll_code'] ) );
	}

	/**
	 * Rolle ist leer: aus dem Bestand nehmen, Schwund festhalten.
	 *
	 * @param int $id Rolle.
	 * @return array {ok, message, written_off_g, level}
	 */
	public static function empty_roll( $id ) {
		global $wpdb;

		$roll = self::find( $id );

		if ( ! $roll ) {
			return self::result( false, __( 'Rolle nicht gefunden.', 'lindenweg16' ) );
		}

		if ( RollAllocator::STATUS_EMPTY === $roll['status'] ) {
			return self::result( false, sprintf( /* translators: %s: Rollencode. */ __( 'Rolle %s war schon als leer gemeldet.', 'lindenweg16' ), $roll['roll_code'] ) );
		}

		// Unter Sperre: Zwei Scans derselben Rolle dürfen nicht zweimal abschreiben.
		if ( ! Lock::acquire( 'filament_roll_' . (int) $id, 5 ) ) {
			return self::result( false, __( 'Die Rolle wird gerade bearbeitet.', 'lindenweg16' ) );
		}

		try {
			$roll = self::find( $id );

			if ( RollAllocator::STATUS_EMPTY === $roll['status'] ) {
				return self::result( false, __( 'Die Rolle wurde gerade schon als leer gemeldet.', 'lindenweg16' ) );
			}

			$alloc   = self::allocation( (int) $roll['lot_id'] );
			$a       = isset( $alloc[ (int) $id ] ) ? $alloc[ (int) $id ] : array( 'absorbed_g' => 0.0, 'remaining_g' => 0.0 );
			$written = max( 0.0, (float) $a['remaining_g'] );

			$wpdb->update(
				self::table(),
				array(
					'status'              => RollAllocator::STATUS_EMPTY,
					'emptied_at'          => current_time( 'mysql', true ),
					'emptied_by'          => get_current_user_id(),
					'consumed_g_at_close' => (float) $a['absorbed_g'],
					'written_off_g'       => $written,
				),
				array( 'id' => (int) $id )
			);

			BetriebAudit::record( 'update', 'filament_rolls', (int) $id, $roll, self::find( $id ), 'Rolle leer gemeldet' );
		} finally {
			Lock::release( 'filament_roll_' . (int) $id );
		}

		$d     = self::describe( self::find( $id ) );
		$level = RollAllocator::shrinkage_level( $written, (float) $roll['start_g'] );
		$text  = sprintf(
			/* translators: 1: Rollencode, 2: Farbe, 3: Gramm Schwund. */
			__( 'Rolle %1$s (%2$s) leer gemeldet - %3$s g Schwund gegenüber den Druckjobs.', 'lindenweg16' ),
			$roll['roll_code'],
			$d['color_label'],
			number_format_i18n( $written, 0 )
		);

		if ( 'attention' === $level ) {
			Log::attention( 'filament', $text . ' Das ist viel: Wird Ausschuss gemeldet, stimmt das Bauteilgewicht?', array( 'roll' => $roll['roll_code'] ) );
		} else {
			Log::normal( 'filament', $text, array( 'roll' => $roll['roll_code'] ) );
		}

		self::refresh();

		return array_merge(
			self::result( true, sprintf( /* translators: 1: Rollencode, 2: Rest in Kilogramm. */ __( 'Rolle %1$s ist leer und aus dem Bestand genommen. In dieser Farbe sind noch %2$s kg da.', 'lindenweg16' ), $roll['roll_code'], number_format_i18n( Material::available_kg( $d['color'] ), 2 ) ) ),
			array(
				'written_off_g' => $written,
				'level'         => $level,
				'color_left_kg' => Material::available_kg( $d['color'] ),
			)
		);
	}

	/**
	 * Leermeldung zurücknehmen (Fehlscan).
	 *
	 * @param int $id Rolle.
	 * @return array {ok, message}
	 */
	public static function undo_empty( $id ) {
		global $wpdb;

		$roll = self::find( $id );

		if ( ! $roll || RollAllocator::STATUS_EMPTY !== $roll['status'] ) {
			return self::result( false, __( 'Diese Rolle ist nicht als leer gemeldet.', 'lindenweg16' ) );
		}

		$wpdb->update(
			self::table(),
			array(
				'status'              => $roll['opened_at'] ? RollAllocator::STATUS_IN_USE : RollAllocator::STATUS_NEW,
				'emptied_at'          => null,
				'emptied_by'          => null,
				'consumed_g_at_close' => null,
				'written_off_g'       => null,
			),
			array( 'id' => (int) $id )
		);

		BetriebAudit::record( 'update', 'filament_rolls', (int) $id, $roll, self::find( $id ), 'Leermeldung zurückgenommen' );
		Log::attention( 'filament', 'Leermeldung der Rolle ' . $roll['roll_code'] . ' zurückgenommen' );
		self::refresh();

		return self::result( true, sprintf( /* translators: %s: Rollencode. */ __( 'Rolle %s ist wieder im Bestand.', 'lindenweg16' ), $roll['roll_code'] ) );
	}

	/**
	 * Rolle wiegen: Das Restgewicht richtet sich nach der Waage.
	 *
	 * @param int        $id       Rolle.
	 * @param float      $gross_g  Gewicht samt Spule in Gramm.
	 * @param float|null $tare_g   Gewicht der leeren Spule; leer = hinterlegter Wert.
	 * @return array {ok, message}
	 */
	public static function measure( $id, $gross_g, $tare_g = null ) {
		global $wpdb;

		$roll = self::find( $id );

		if ( ! $roll || RollAllocator::STATUS_EMPTY === $roll['status'] ) {
			return self::result( false, __( 'Diese Rolle lässt sich nicht wiegen (nicht gefunden oder leer).', 'lindenweg16' ) );
		}

		$gross_g = (float) $gross_g;
		if ( $gross_g <= 0 || $gross_g > 20000 ) {
			return self::result( false, __( 'Bitte das Gewicht der Rolle samt Spule in Gramm angeben.', 'lindenweg16' ) );
		}

		$tare = null === $tare_g ? ( null === $roll['tare_g'] ? self::default_tare_g() : (int) $roll['tare_g'] ) : max( 0, (int) $tare_g );
		$d    = self::describe( $roll );
		$m    = RollAllocator::measurement( (float) $d['remaining_g'], $gross_g, $tare );

		$wpdb->update(
			self::table(),
			array(
				'corr_g' => round( (float) $roll['corr_g'] + $m['delta_g'], 1 ),
				'tare_g' => $tare,
			),
			array( 'id' => (int) $id )
		);

		BetriebAudit::record( 'update', 'filament_rolls', (int) $id, $roll, self::find( $id ), 'Gewogen: ' . $m['measured_g'] . ' g Rest' );
		Log::normal( 'filament', sprintf( 'Rolle %s gewogen: %s g Rest (rechnerisch waren %s g)', $roll['roll_code'], $m['measured_g'], $d['remaining_g'] ) );
		self::refresh();

		return self::result( true, sprintf( /* translators: 1: Rollencode, 2: Gramm. */ __( 'Rolle %1$s: Rest %2$s g übernommen.', 'lindenweg16' ), $roll['roll_code'], number_format_i18n( $m['measured_g'], 0 ) ) );
	}

	/**
	 * Alle Rollen einer Charge für die Anzeige.
	 *
	 * @param int  $lot_id    Charge.
	 * @param bool $only_open Leere ausblenden.
	 * @return array
	 */
	public static function described_for_lot( $lot_id, $only_open = true ) {
		$alloc = self::allocation( (int) $lot_id );
		$out   = array();

		foreach ( self::for_lot( $lot_id ) as $roll ) {
			if ( $only_open && RollAllocator::STATUS_EMPTY === $roll['status'] ) {
				continue;
			}

			$out[] = self::describe( $roll, $alloc );
		}

		return $out;
	}

	/**
	 * Zwischenspeicher der Materialübersicht leeren.
	 */
	protected static function refresh() {
		Material::flush();
	}

	/**
	 * Ergebnis bauen.
	 *
	 * @param bool   $ok      Erfolg.
	 * @param string $message Meldung.
	 * @return array
	 */
	protected static function result( $ok, $message ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => (string) $message,
		);
	}
}
