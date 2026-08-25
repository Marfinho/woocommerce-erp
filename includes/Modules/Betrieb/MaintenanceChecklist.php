<?php
/**
 * Die Wartungscheckliste je Intervall.
 *
 * Eine Wartung, die man mit einem Klick als „erledigt" meldet, ist keine
 * Wartung, sondern ein Eintrag. Deshalb steht hier, was tatsächlich zu tun
 * ist, und jeder Punkt wird einzeln abgehakt – erst dann lässt sich der
 * Vorgang erfassen. Was abgehakt wurde, steht später im Protokoll: Wenn ein
 * Gerät ausfällt, ist die erste Frage, was beim letzten Mal geprüft wurde.
 *
 * Die Listen bauen aufeinander auf. Eine Monatswartung enthält die
 * Wochenpunkte, eine Jahreswartung beides – so ist es in der Werkstatt auch:
 * Wer die Achsen schmiert, saugt vorher aus.
 *
 * Frei von Datenbank und WordPress-Umgebung, damit sie prüfbar bleibt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Punkte und Prüfung der Wartungscheckliste.
 */
class MaintenanceChecklist {

	/**
	 * Intervalle mit fester Checkliste, in der Reihenfolge, in der sie
	 * aufeinander aufbauen.
	 *
	 * @return array Kennung => Beschriftung.
	 */
	public static function periodic() {
		return array(
			'woechentlich' => __( 'wöchentlich', 'lindenweg16' ),
			'monatlich'    => __( 'monatlich', 'lindenweg16' ),
			'jaehrlich'    => __( 'jährlich', 'lindenweg16' ),
		);
	}

	/**
	 * Alle Intervalle, auch die anlassbezogenen.
	 *
	 * @return array Kennung => Beschriftung.
	 */
	public static function intervals() {
		return array_merge(
			self::periodic(),
			array(
				'verschleiss' => __( 'Verschleiß (anlassbezogen)', 'lindenweg16' ),
				'saison'      => __( 'Saison', 'lindenweg16' ),
			)
		);
	}

	/**
	 * Die Punkte, die genau zu diesem Intervall neu hinzukommen.
	 *
	 * @param string $interval Intervall.
	 * @return array Kennung => Text.
	 */
	public static function own_items( $interval ) {
		$lists = array(
			'woechentlich' => array(
				'platte'     => __( 'Druckplatte gereinigt (Spülmittel, dann Isopropanol) und auf Kratzer geprüft', 'lindenweg16' ),
				'duese'      => __( 'Düse und Heizblock auf angebackenes Filament geprüft', 'lindenweg16' ),
				'innenraum'  => __( 'Innenraum ausgesaugt, Filamentreste und Stützmaterial entfernt', 'lindenweg16' ),
				'riemen'     => __( 'Riemen auf Spannung und Fremdkörper geprüft', 'lindenweg16' ),
				'luefter'    => __( 'Lüfter drehen frei, kein auffälliges Geräusch', 'lindenweg16' ),
				'filament'   => __( 'Filament im Trockner bzw. in der Box, Restmenge notiert', 'lindenweg16' ),
			),
			'monatlich'    => array(
				'achsen'     => __( 'Linearachsen X, Y und Z gereinigt und geschmiert', 'lindenweg16' ),
				'spindel'    => __( 'Z-Spindel gefettet', 'lindenweg16' ),
				'schrauben'  => __( 'Schrauben an Portal und Werkzeugaufnahme nachgezogen', 'lindenweg16' ),
				'extruder'   => __( 'Extruder geöffnet, Förderräder gereinigt', 'lindenweg16' ),
				'luefter_in' => __( 'Bauteil-, Hotend- und Elektroniklüfter ausgeblasen', 'lindenweg16' ),
				'kalib'      => __( 'Kalibrierung gefahren (Bett und Vibration)', 'lindenweg16' ),
				'kabel'      => __( 'Kabelbaum und Stecker auf Scheuerstellen geprüft', 'lindenweg16' ),
			),
			'jaehrlich'    => array(
				'duese_neu'  => __( 'Düse getauscht oder ihr Zustand dokumentiert', 'lindenweg16' ),
				'ptfe'       => __( 'PTFE-Schlauch geprüft und bei Verfärbung getauscht', 'lindenweg16' ),
				'hotend'     => __( 'Hotend zerlegt, gereinigt und nach Herstellerangabe nachgezogen', 'lindenweg16' ),
				'riemen_ver' => __( 'Riemen auf Verschleiß geprüft, bei Rissen getauscht', 'lindenweg16' ),
				'netzteil'   => __( 'Netzteil und Filter gereinigt', 'lindenweg16' ),
				'firmware'   => __( 'Firmware-Stand geprüft', 'lindenweg16' ),
				'sicherheit' => __( 'Sicherheitsprüfung: Zuleitung, Zugentlastung, Standfestigkeit', 'lindenweg16' ),
			),
		);

		return isset( $lists[ $interval ] ) ? $lists[ $interval ] : array();
	}

	/**
	 * Alle Punkte eines Intervalls, einschliesslich der kürzeren.
	 *
	 * @param string $interval Intervall.
	 * @return array Kennung => Text.
	 */
	public static function items( $interval ) {
		$out = array();

		foreach ( array_keys( self::periodic() ) as $key ) {
			$out = array_merge( $out, self::own_items( $key ) );

			if ( $key === $interval ) {
				return $out;
			}
		}

		// Anlassbezogene Wartungen haben keine feste Liste.
		return array();
	}

	/**
	 * Muss bei diesem Intervall alles abgehakt sein?
	 *
	 * Bei einer anlassbezogenen Reparatur nicht: Wer einen gerissenen Riemen
	 * tauscht, fettet dabei nicht zwangsläufig die Spindel. Eine Liste zu
	 * erzwingen, die nicht zum Anlass passt, führt nur dazu, dass sie
	 * blind abgehakt wird.
	 *
	 * @param string $interval Intervall.
	 * @return bool
	 */
	public static function requires_all( $interval ) {
		return array_key_exists( (string) $interval, self::periodic() );
	}

	/**
	 * Welche Punkte fehlen noch?
	 *
	 * @param string $interval Intervall.
	 * @param array  $checked  Abgehakte Kennungen.
	 * @return array {ok, missing, done, total}
	 */
	public static function validate( $interval, array $checked ) {
		$items = self::items( $interval );

		if ( ! self::requires_all( $interval ) ) {
			return array(
				'ok'      => true,
				'missing' => array(),
				'done'    => count( array_intersect( $checked, array_keys( $items ) ) ),
				'total'   => count( $items ),
			);
		}

		$missing = array();
		foreach ( $items as $key => $text ) {
			if ( ! in_array( $key, $checked, true ) ) {
				$missing[ $key ] = $text;
			}
		}

		return array(
			'ok'      => empty( $missing ),
			'missing' => $missing,
			'done'    => count( $items ) - count( $missing ),
			'total'   => count( $items ),
		);
	}

	/**
	 * Eingegangene Häkchen auf bekannte Punkte eingrenzen.
	 *
	 * @param string $interval Intervall.
	 * @param array  $checked  Rohwerte aus dem Formular.
	 * @return array
	 */
	public static function sanitize( $interval, array $checked ) {
		$known = array_keys( self::items( $interval ) );

		return array_values(
			array_intersect(
				array_map( 'strval', $checked ),
				$known
			)
		);
	}

	/**
	 * Die abgehakten Punkte eines Protokolleintrags als Text.
	 *
	 * @param string $interval  Intervall.
	 * @param array  $checked   Abgehakte Kennungen.
	 * @return string
	 */
	public static function summary( $interval, array $checked ) {
		$items = self::items( $interval );
		$done  = array();

		foreach ( $checked as $key ) {
			if ( isset( $items[ $key ] ) ) {
				$done[] = $items[ $key ];
			}
		}

		if ( ! $done ) {
			return __( 'Keine Punkte vermerkt.', 'lindenweg16' );
		}

		return implode( ' · ', $done );
	}
}
