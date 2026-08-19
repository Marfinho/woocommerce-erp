<?php
/**
 * Hash-Kette des Audit-Logs: Verkettung und Manipulationserkennung.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\HashChain;

/**
 * Prüft die reine Rechenlogik der Kette ohne Datenbank.
 */
class BetriebAuditChainTest extends TestCase {

	/**
	 * Drei aufeinanderfolgende Protokolleinträge.
	 *
	 * @return array
	 */
	protected function entries() {
		return array(
			array(
				'user_id'     => 1,
				'aktion'      => 'betrieb_create',
				'objekt_typ'  => 'calc_settings',
				'objekt_id'   => 1,
				'details'     => '{"after":{"setting_key":"filament_price_per_kg","value_decimal":22}}',
				'erstellt_am' => '2026-09-01 08:00:00',
			),
			array(
				'user_id'     => 1,
				'aktion'      => 'betrieb_update',
				'objekt_typ'  => 'calc_settings',
				'objekt_id'   => 1,
				'details'     => '{"after":{"setting_key":"filament_price_per_kg","value_decimal":24.5}}',
				'erstellt_am' => '2027-01-15 09:30:00',
			),
			array(
				'user_id'     => 2,
				'aktion'      => 'betrieb_void',
				'objekt_typ'  => 'print_jobs',
				'objekt_id'   => 17,
				'details'     => '{"reason":"Teile doppelt gezaehlt"}',
				'erstellt_am' => '2027-01-16 07:15:00',
			),
		);
	}

	/**
	 * Kette aus Einträgen bauen: je Eintrag Hash und Vorgänger-Hash.
	 *
	 * @param array $entries Einträge.
	 * @return array
	 */
	protected function build_chain( array $entries ) {
		$prev  = '';
		$chain = array();
		foreach ( $entries as $entry ) {
			$hash                    = HashChain::compute( 'audit_log', $entry, $prev );
			$entry['vorheriger_hash'] = $prev;
			$entry['hash']            = $hash;
			$chain[]                  = $entry;
			$prev                     = $hash;
		}
		return $chain;
	}

	/**
	 * Kette nachrechnen. Liefert die Position des ersten Bruchs oder -1.
	 *
	 * @param array $chain Kette.
	 * @return int
	 */
	protected function first_break( array $chain ) {
		$prev = '';
		foreach ( $chain as $i => $row ) {
			if ( $row['vorheriger_hash'] !== $prev ) {
				return $i;
			}
			if ( HashChain::compute( 'audit_log', $row, $prev ) !== $row['hash'] ) {
				return $i;
			}
			$prev = $row['hash'];
		}
		return -1;
	}

	/**
	 * Gleiche Eingabe, gleicher Hash.
	 */
	public function test_hash_ist_deterministisch() {
		$entry = $this->entries()[0];
		$this->assertSame(
			HashChain::compute( 'audit_log', $entry, '' ),
			HashChain::compute( 'audit_log', $entry, '' )
		);
		$this->assertSame( 64, strlen( HashChain::compute( 'audit_log', $entry, '' ) ) );
	}

	/**
	 * Der erste Eintrag hängt am Genesis-Wert, jeder weitere am Vorgänger.
	 */
	public function test_kette_ist_verkettet() {
		$chain = $this->build_chain( $this->entries() );

		$this->assertSame( '', $chain[0]['vorheriger_hash'] );
		$this->assertSame( $chain[0]['hash'], $chain[1]['vorheriger_hash'] );
		$this->assertSame( $chain[1]['hash'], $chain[2]['vorheriger_hash'] );
		$this->assertSame( -1, $this->first_break( $chain ) );
	}

	/**
	 * Nachträgliche Änderung eines Eintrags bricht die Kette an genau
	 * dieser Stelle – das ist der Zweck der Übung.
	 */
	public function test_manipulation_bricht_die_kette() {
		$chain = $this->build_chain( $this->entries() );

		// Jemand ändert den protokollierten Wert im mittleren Eintrag.
		$chain[1]['details'] = '{"after":{"setting_key":"filament_price_per_kg","value_decimal":19}}';

		$this->assertSame( 1, $this->first_break( $chain ) );
	}

	/**
	 * Auch ein gelöschter Eintrag fällt auf: der Nachfolger zeigt dann auf
	 * einen Hash, der in der Kette nicht mehr vorkommt.
	 */
	public function test_geloeschter_eintrag_faellt_auf() {
		$chain = $this->build_chain( $this->entries() );
		unset( $chain[1] );
		$chain = array_values( $chain );

		$this->assertSame( 1, $this->first_break( $chain ) );
	}

	/**
	 * Felder außerhalb von HASH_FIELDS gehen nicht in den Hash ein – sonst
	 * wären die abfragbaren Kopien der Nutzdaten nicht änderbar, ohne die
	 * Kette zu brechen.
	 */
	public function test_nicht_gehashte_spalten_beeinflussen_den_hash_nicht() {
		$entry = $this->entries()[0];
		$mit   = $entry;
		$mit['payload_after_json'] = '{"beliebig":true}';

		$this->assertSame(
			HashChain::compute( 'audit_log', $entry, '' ),
			HashChain::compute( 'audit_log', $mit, '' )
		);
	}
}
