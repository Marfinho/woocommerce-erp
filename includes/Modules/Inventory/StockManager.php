<?php
/**
 * Kernlogik der Lagerbewegungen: Verbuchung, Kaskadierung, Ampel.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Inventory;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Calc;
use Lindenweg16\Core\Format;
use Lindenweg16\Core\Roles;
use Lindenweg16\Core\Time;
use Lindenweg16\Core\MailTemplate;

/**
 * Verbucht Lagerbewegungen und aktualisiert den Bestand konsistent.
 */
class StockManager {

	/**
	 * Maximale Tiefe beim Auflösen von Stücklisten.
	 */
	const MAX_TREE_DEPTH = 10;

	/**
	 * Eine Lagerbewegung verbuchen und den Bestand fortschreiben.
	 * Positive Menge = Zugang, negative Menge = Abgang.
	 *
	 * @param int    $produkt_id Produkt-ID.
	 * @param float  $menge      Signierte Menge in Basis-Einheit.
	 * @param string $typ        wareneingang|produktion|verkauf|korrektur|retoure|inventur.
	 * @param array  $args       Optional: referenz, beleg_id, notiz, lagerort_id.
	 * @return int Bewegungs-ID.
	 */
	public static function record_movement( $produkt_id, $menge, $typ, array $args = array() ) {
		global $wpdb;

		$produkt_id  = (int) $produkt_id;
		$menge       = (float) $menge;
		$lagerort_id = isset( $args['lagerort_id'] ) && $args['lagerort_id'] ? (int) $args['lagerort_id'] : null;

		if ( ! $produkt_id || 0.0 === $menge ) {
			return 0;
		}

		$wpdb->insert(
			Repository::table( 'lagerbewegung' ),
			array(
				'produkt_id'   => $produkt_id,
				'lagerort_id'  => $lagerort_id,
				'menge'        => $menge,
				'typ'          => $typ,
				'referenz'     => isset( $args['referenz'] ) ? $args['referenz'] : null,
				'beleg_id'     => isset( $args['beleg_id'] ) && $args['beleg_id'] ? (int) $args['beleg_id'] : null,
				'notiz'        => isset( $args['notiz'] ) ? $args['notiz'] : null,
				'erstellt_von' => get_current_user_id(),
				'erstellt_am'  => Time::now(),
			),
			array( '%d', '%d', '%f', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
		$bewegung_id = (int) $wpdb->insert_id;

		// Atomar fortschreiben: die Addition passiert in der Datenbank, damit
		// gleichzeitige Bestellungen sich nicht gegenseitig überschreiben.
		$neuer_bestand  = Repository::adjust_stock( $produkt_id, $menge );
		$bestand_vorher = $neuer_bestand - $menge;

		if ( $lagerort_id ) {
			Repository::adjust_stock_at_location( $produkt_id, $lagerort_id, $menge );
		}

		// Mindestbestand-Prüfung bei Abgang.
		if ( $menge < 0 ) {
			self::maybe_notify_low_stock( $produkt_id, $neuer_bestand );
		}

		// Warteliste („wieder verfügbar") bei Zugang über 0 auslösen.
		if ( $menge > 0 && $bestand_vorher <= 0 && $neuer_bestand > 0 ) {
			do_action( 'lw16_stock_replenished', $produkt_id, $neuer_bestand );
		}

		do_action( 'lw16_movement_recorded', $produkt_id, $menge, $typ, $bewegung_id );

		return $bewegung_id;
	}

	/**
	 * Stücklistenbaum eines Produkts aus der Datenbank aufbauen.
	 *
	 * Sets verzweigen über die Stückliste in ihre Module, Module über die
	 * Rezeptur in ihr Rohmaterial. Zyklen und übermäßige Tiefe werden
	 * abgefangen, damit ein fehlerhaft verschachteltes Set nicht in eine
	 * Endlosrekursion läuft.
	 *
	 * @param int   $produkt_id Wurzelprodukt.
	 * @param float $faktor     Menge pro Elterneinheit.
	 * @param array $pfad       Besuchte IDs auf diesem Ast.
	 * @param int   $tiefe      Aktuelle Rekursionstiefe.
	 * @return array|null Baumknoten oder null.
	 */
	public static function build_tree( $produkt_id, $faktor = 1.0, array $pfad = array(), $tiefe = 0 ) {
		$produkt_id = (int) $produkt_id;
		if ( isset( $pfad[ $produkt_id ] ) || $tiefe > self::MAX_TREE_DEPTH ) {
			return null;
		}
		$produkt = Repository::get_product( $produkt_id );
		if ( ! $produkt ) {
			return null;
		}
		$pfad[ $produkt_id ] = true;

		$node = array(
			'produkt_id' => $produkt_id,
			'typ'        => (string) $produkt->typ,
			'menge'      => (float) $faktor,
			'children'   => array(),
		);

		if ( 'set' === $produkt->typ ) {
			foreach ( Repository::get_stueckliste( $produkt_id ) as $pos ) {
				$child = self::build_tree( (int) $pos->modul_id, (float) $pos->menge, $pfad, $tiefe + 1 );
				if ( $child ) {
					$node['children'][] = $child;
				}
			}
			return $node;
		}

		if ( 'modul' === $produkt->typ ) {
			foreach ( Repository::get_rezeptur( $produkt_id ) as $rez ) {
				$child = self::build_tree( (int) $rez->rohmaterial_id, (float) $rez->menge, $pfad, $tiefe + 1 );
				if ( $child ) {
					$node['children'][] = $child;
				}
			}
		}

		return $node;
	}

	/**
	 * Verkauf eines Produkts kaskadierend abbuchen.
	 * Set -> Module (Stückliste) -> Rohmaterial (Rezeptur).
	 *
	 * @param int    $produkt_id Verkauftes Produkt (set, modul oder rohmaterial).
	 * @param float  $menge      Verkaufte Menge.
	 * @param string $referenz   Bestellreferenz.
	 * @param array  $args       Zusätzliche Bewegungsargumente.
	 */
	public static function process_sale( $produkt_id, $menge, $referenz, array $args = array() ) {
		self::process_cascade( $produkt_id, $menge, 'verkauf', $referenz, -1, $args );
	}

	/**
	 * Rücknahme eines Produkts kaskadierend zurückbuchen.
	 *
	 * Spiegelbild zu process_sale(): ein zurückgenommenes Set schreibt den
	 * Bestand seinen Modulen und deren Rohmaterial zu – nicht der Set-Zeile,
	 * die im Verkaufspfad nie berührt wird.
	 *
	 * @param int    $produkt_id Zurückgenommenes Produkt.
	 * @param float  $menge      Menge.
	 * @param string $referenz   Referenz.
	 * @param string $typ        Bewegungstyp (retoure|korrektur).
	 * @param array  $args       Zusätzliche Bewegungsargumente.
	 */
	public static function process_return( $produkt_id, $menge, $referenz, $typ = 'retoure', array $args = array() ) {
		self::process_cascade( $produkt_id, $menge, $typ, $referenz, 1, $args );
	}

	/**
	 * Gemeinsamer Kern von process_sale() und process_return().
	 *
	 * Die Mengenverteilung selbst steckt in der seiteneffektfreien und
	 * getesteten Calc::cascade_deductions(); hier passiert nur noch das
	 * Laden des Baums und das Verbuchen der Ergebnisse.
	 *
	 * @param int    $produkt_id Wurzelprodukt.
	 * @param float  $menge      Menge.
	 * @param string $typ        Bewegungstyp.
	 * @param string $referenz   Referenz.
	 * @param int    $vorzeichen -1 Abgang, +1 Zugang.
	 * @param array  $args       Zusätzliche Bewegungsargumente.
	 */
	protected static function process_cascade( $produkt_id, $menge, $typ, $referenz, $vorzeichen, array $args = array() ) {
		$tree = self::build_tree( $produkt_id );
		if ( ! $tree ) {
			return;
		}

		$bewegungen = Calc::cascade_deductions( $menge, $tree, $vorzeichen );

		foreach ( $bewegungen as $pid => $delta ) {
			if ( 0.0 === (float) $delta ) {
				continue;
			}
			self::record_movement(
				(int) $pid,
				(float) $delta,
				$typ,
				array_merge( $args, array( 'referenz' => $referenz ) )
			);
		}
	}

	/**
	 * Ampel-Status eines Produkts anhand des Mindestbestands.
	 *
	 * @param object     $produkt Produktobjekt.
	 * @param float|null $bestand Optional vorberechneter Bestand (spart eine Abfrage).
	 * @return string gruen|gelb|rot.
	 */
	public static function ampel( $produkt, $bestand = null ) {
		if ( null === $bestand ) {
			$bestand = isset( $produkt->bestand ) ? (float) $produkt->bestand : Repository::get_stock( $produkt->id );
		}
		$bestand = (float) $bestand;
		$min     = (float) $produkt->mindestbestand;

		// Ohne Mindestbestand gibt es keine Sollvorgabe – ein negativer
		// Bestand ist aber immer ein Fehlerzustand.
		if ( $min <= 0 ) {
			return $bestand < 0 ? 'rot' : 'gruen';
		}
		if ( $bestand < $min ) {
			return 'rot';
		}
		if ( $bestand < $min * 1.5 ) {
			return 'gelb';
		}
		return 'gruen';
	}

	/**
	 * Bei Unterschreitung des Mindestbestands die Manager benachrichtigen.
	 * Doppelbenachrichtigung per Transient-Dämpfer vermeiden.
	 *
	 * @param int   $produkt_id    Produkt-ID.
	 * @param float $neuer_bestand Aktueller Bestand.
	 */
	protected static function maybe_notify_low_stock( $produkt_id, $neuer_bestand ) {
		$produkt = Repository::get_product( $produkt_id );
		if ( ! $produkt || (float) $produkt->mindestbestand <= 0 ) {
			return;
		}
		if ( $neuer_bestand >= (float) $produkt->mindestbestand ) {
			return;
		}

		$lock_key = 'lw16_lowstock_' . $produkt_id;
		if ( get_transient( $lock_key ) ) {
			return; // In den letzten 24h schon gemeldet.
		}
		set_transient( $lock_key, 1, DAY_IN_SECONDS );

		$emails = Roles::get_manager_emails();
		if ( empty( $emails ) ) {
			return;
		}

		MailTemplate::send( $emails, 'mindestbestand', array(
			'{produktname}'    => $produkt->name,
			'{bestand}'        => Format::qty( $neuer_bestand ),
			'{mindestbestand}' => Format::qty( $produkt->mindestbestand ),
			'{einheit}'        => $produkt->basis_einheit,
		) );
	}
}
