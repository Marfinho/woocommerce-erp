<?php
/**
 * Lagerplätze.
 *
 * Ein Lagerplatz ist eine Kiste, ein Fach, ein Regalboden – die Stelle, an
 * der ein Teil tatsächlich liegt. Er ist bewusst nur ein Etikett auf einer
 * Bewegung und kein zweites Bestandsfeld: Der Bestand je Platz ergibt sich
 * wie jeder andere Bestand aus der Summe der Bewegungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Lagerplätze.
 */
class BinRepository {

	use SanitizesInput;

	/**
	 * Bauarten eines Platzes.
	 */
	const KINDS = array( 'regal', 'kiste', 'fach', 'palette', 'werkbank', 'versand' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_storage_bins';
	}

	/**
	 * Alle Plätze.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function all( $only_active = false ) {
		global $wpdb;
		$table = self::table();
		$where = $only_active ? 'WHERE is_active = 1' : '';

		return $wpdb->get_results(
			"SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, code ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Platz anhand der ID.
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
	 * Platz anhand des Codes. Groß- und Kleinschreibung spielt keine Rolle –
	 * am Handy tippt niemand auf Großschreibung.
	 *
	 * @param string $code Code.
	 * @return array|null
	 */
	public static function find_by_code( $code ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", self::normalize_code( $code ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Codes vereinheitlichen: Großbuchstaben, keine Leerzeichen.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function normalize_code( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		return preg_replace( '/[^A-Z0-9\-_.]/', '', $code );
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array    $input Eingaben.
	 * @param int|null $id    Eigene ID bei Bearbeitung.
	 * @return array {data, errors}
	 */
	public static function validate( array $input, $id = null ) {
		global $wpdb;

		$errors = array();
		$code   = self::normalize_code( isset( $input['code'] ) ? $input['code'] : '' );

		if ( '' === $code ) {
			$errors[] = __( 'Ein Lagerplatz braucht einen Code – er steht später auf dem Etikett.', 'lindenweg16' );
		}

		if ( $code ) {
			$table  = self::table();
			$exists = $id
				? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s AND id <> %d", $code, (int) $id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $exists ) {
				$errors[] = __( 'Diesen Code gibt es schon. Zwei Plätze mit demselben Etikett wären beim Scannen nicht zu unterscheiden.', 'lindenweg16' );
			}
		}

		$kind = isset( $input['kind'] ) ? sanitize_key( $input['kind'] ) : 'regal';

		return array(
			'data'   => array(
				'code'       => $code,
				'name'       => self::text( $input, 'name' ),
				'kind'       => in_array( $kind, self::KINDS, true ) ? $kind : 'regal',
				'note'       => isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : null,
				'sort_order' => self::int_or( $input, 'sort_order', 0 ),
				'is_active'  => empty( $input['is_active'] ) ? 0 : 1,
			),
			'errors' => $errors,
		);
	}

	/**
	 * Platz anlegen.
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
		BetriebAudit::record( 'create', 'storage_bins', $id, null, $data );

		return $id;
	}

	/**
	 * Platz ändern.
	 *
	 * @param int    $id     ID.
	 * @param array  $data   Geprüfte Daten.
	 * @param string $reason Begründung.
	 * @return bool
	 */
	public static function update( $id, array $data, $reason = '' ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql', true );

		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ) ) {
			return false;
		}

		BetriebAudit::record( 'update', 'storage_bins', (int) $id, $before, $data, $reason );

		return true;
	}

	/**
	 * Bestand je Lagerplatz aus den Bewegungen summieren.
	 *
	 * Reine Rechnung ohne Datenbank, damit sie prüfbar bleibt.
	 *
	 * @param array $movements Bewegungen.
	 * @return array bin_id => article_id => color => Menge.
	 */
	public static function balances( array $movements ) {
		$out = array();

		foreach ( $movements as $movement ) {
			$movement = (array) $movement;
			$bin      = isset( $movement['bin_id'] ) ? (int) $movement['bin_id'] : 0;
			$article  = isset( $movement['article_id'] ) ? (int) $movement['article_id'] : 0;

			if ( ! $bin || ! $article ) {
				continue;
			}

			$color = isset( $movement['color'] ) && '' !== $movement['color']
				? (string) $movement['color']
				: ColorRegistry::UNRESOLVED;

			if ( ! isset( $out[ $bin ][ $article ][ $color ] ) ) {
				$out[ $bin ][ $article ][ $color ] = 0.0;
			}
			$out[ $bin ][ $article ][ $color ] += isset( $movement['qty'] ) ? (float) $movement['qty'] : 0.0;
		}

		return $out;
	}

	/**
	 * Bestand eines Platzes, leere Zeilen entfernt.
	 *
	 * @param array $balances Ergebnis von balances().
	 * @param int   $bin_id   Platz.
	 * @return array article_id => color => Menge.
	 */
	public static function stock_of( array $balances, $bin_id ) {
		$rows = isset( $balances[ (int) $bin_id ] ) ? $balances[ (int) $bin_id ] : array();
		$out  = array();

		foreach ( $rows as $article => $colors ) {
			foreach ( $colors as $color => $qty ) {
				if ( abs( (float) $qty ) > 0.0005 ) {
					$out[ $article ][ $color ] = (float) $qty;
				}
			}
		}

		return $out;
	}

	/**
	 * Etikett eines Platzes als druckbares HTML.
	 *
	 * @param array $bin Platz.
	 * @return string
	 */
	public static function label( array $bin ) {
		$payload = ScanResolver::PREFIX_BIN . $bin['code'];

		$html  = '<div class="lw16-label lw16-label--bin">';
		$html .= '<div class="lw16-label__code">' . esc_html( $bin['code'] ) . '</div>';
		$html .= '<div class="lw16-label__model">' . esc_html( (string) $bin['name'] ) . '</div>';
		$html .= '<div class="lw16-label__qr">' . DeviceLabel::qr( $payload, 110, 'Q' ) . '</div>';
		$html .= '</div>';

		return $html;
	}
}
