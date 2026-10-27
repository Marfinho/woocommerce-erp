<?php
/**
 * Lagerbestand als Bewegungskonto.
 *
 * Es gibt kein Bestandsfeld. Der Bestand ist immer die Summe der Bewegungen –
 * so kann er nicht driften, und jede Zahl lässt sich bis zu ihrer Ursache
 * zurückverfolgen.
 *
 * Kommissionsware bleibt Eigentum des Hauses: Sie zählt zum Gesamtbestand,
 * aber getrennt vom verfügbaren Lager. Die Kette raus → abgerechnet → retour
 * darf den Gesamtbestand über alle Lagerorte hinweg nicht verändern; nur der
 * abgerechnete Teil verlässt ihn als Verkauf.
 *
 * Reine Auswertungslogik ohne Datenbankzugriff.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Summiert Bewegungen zu Beständen.
 */
class StockLedger {

	/**
	 * Lagerorte.
	 */
	const LOCATIONS = array( 'lager', 'kommission', 'unterwegs' );

	/**
	 * Bewegungsarten mit ihrer Wirkung.
	 *
	 * Der Wert sagt, ob die Bewegung den Gesamtbestand über alle Lagerorte
	 * verändert (true) oder ihn nur umbucht (false).
	 */
	const MOVEMENT_TYPES = array(
		'produktion'             => true,
		'verkauf'                => true,
		'kommission_raus'        => false,
		'kommission_abgerechnet' => true,
		'kommission_retour'      => false,
		'muster'                 => true,
		'ausschuss'              => true,
		'korrektur'              => true,
		'retoure'                => true,
	);

	/**
	 * Bezeichnungen der Bewegungsarten.
	 *
	 * @return array
	 */
	public static function movement_labels() {
		return array(
			'produktion'             => __( 'Produktion', 'lindenweg16' ),
			'verkauf'                => __( 'Verkauf', 'lindenweg16' ),
			'kommission_raus'        => __( 'Kommission ausgeliefert', 'lindenweg16' ),
			'kommission_abgerechnet' => __( 'Kommission abgerechnet', 'lindenweg16' ),
			'kommission_retour'      => __( 'Kommission zurück', 'lindenweg16' ),
			'muster'                 => __( 'Muster abgegeben', 'lindenweg16' ),
			'ausschuss'              => __( 'Ausschuss', 'lindenweg16' ),
			'umlagerung'             => __( 'Umlagerung', 'lindenweg16' ),
			'korrektur'              => __( 'Korrektur', 'lindenweg16' ),
			'retoure'                => __( 'Retoure', 'lindenweg16' ),
		);
	}

	/**
	 * Bezeichnungen der Lagerorte.
	 *
	 * @return array
	 */
	public static function location_labels() {
		return array(
			'lager'      => __( 'Lager', 'lindenweg16' ),
			'kommission' => __( 'beim Händler', 'lindenweg16' ),
			'unterwegs'  => __( 'unterwegs', 'lindenweg16' ),
		);
	}

	/**
	 * Bestände je Artikel, Farbe und Lagerort.
	 *
	 * Stornierte Bewegungen und ihre Gegenbuchungen heben sich gegenseitig
	 * auf, weil beide in der Summe stehen – gelöscht wird nichts.
	 *
	 * @param array $movements Bewegungen.
	 * @return array Verschachtelt: article_id => color => location => Menge.
	 */
	public static function balances( array $movements ) {
		$balances = array();

		foreach ( $movements as $movement ) {
			$movement = (array) $movement;
			$article  = isset( $movement['article_id'] ) ? (int) $movement['article_id'] : 0;
			$color    = isset( $movement['color'] ) && '' !== $movement['color'] ? (string) $movement['color'] : ColorRegistry::UNRESOLVED;
			$location = isset( $movement['location'] ) ? (string) $movement['location'] : 'lager';
			$qty      = isset( $movement['qty'] ) ? (float) $movement['qty'] : 0.0;

			if ( ! $article ) {
				continue;
			}

			if ( ! isset( $balances[ $article ][ $color ][ $location ] ) ) {
				$balances[ $article ][ $color ][ $location ] = 0.0;
			}
			$balances[ $article ][ $color ][ $location ] += $qty;
		}

		return $balances;
	}

	/**
	 * Aus fertigen Summen (Artikel, Farbe, Ort, Menge) dieselbe Verschachtelung bauen wie balances().
	 *
	 * @param array $rows Zeilen mit article_id, color, location, qty.
	 * @return array article_id => color => location => Menge.
	 */
	public static function from_rows( array $rows ) {
		$balances = array();

		foreach ( $rows as $row ) {
			$article = (int) $row['article_id'];
			$color   = isset( $row['color'] ) && '' !== $row['color'] ? (string) $row['color'] : ColorRegistry::UNRESOLVED;
			$balances[ $article ][ $color ][ (string) $row['location'] ] = (float) $row['qty'];
		}

		return $balances;
	}

	/**
	 * Bestand eines Artikels in einer Farbe.
	 *
	 * @param array  $movements Bewegungen.
	 * @param int    $article   Artikel.
	 * @param string $color     Farbe.
	 * @return array {lager, kommission, unterwegs, gesamt}
	 */
	public static function balance_for( array $movements, $article, $color ) {
		return self::pick( self::balances( $movements ), $article, $color );
	}

	/**
	 * Bestand aus bereits berechneten Summen greifen.
	 *
	 * Wer viele Zeilen anzeigt, berechnet balances() einmal und liest hier –
	 * sonst läuft die gesamte Bewegungstabelle je Zeile erneut durch.
	 *
	 * @param array  $balances Ergebnis von balances().
	 * @param int    $article  Artikel.
	 * @param string $color    Farbe.
	 * @return array {lager, kommission, unterwegs, gesamt}
	 */
	public static function pick( array $balances, $article, $color ) {
		$row = isset( $balances[ (int) $article ][ (string) $color ] ) ? $balances[ (int) $article ][ (string) $color ] : array();

		// Über die bekannten Lagerorte laufen, nicht über drei fest
		// geschriebene Namen: Käme ein vierter dazu, fehlte er in der Summe,
		// ohne dass irgendwo etwas rot wird – der Gesamtbestand wäre zu
		// niedrig und jede Spalte für sich richtig.
		$result = array();
		$gesamt = 0.0;

		foreach ( array_keys( self::location_labels() ) as $location ) {
			$menge               = isset( $row[ $location ] ) ? (float) $row[ $location ] : 0.0;
			$result[ $location ] = $menge;
			$gesamt             += $menge;
		}

		$result['gesamt'] = $gesamt;

		return $result;
	}

	/**
	 * Gesamtbestand über alle Lagerorte.
	 *
	 * @param array $movements Bewegungen.
	 * @return float
	 */
	public static function total( array $movements ) {
		$sum = 0.0;
		foreach ( $movements as $movement ) {
			$movement = (array) $movement;
			$sum     += isset( $movement['qty'] ) ? (float) $movement['qty'] : 0.0;
		}
		return $sum;
	}

	/**
	 * Die Gegenbuchungen einer Kommissionslieferung.
	 *
	 * Eine Kommissionsbewegung ist immer ein Paar: Die Menge verlässt einen
	 * Lagerort und erscheint am anderen. Als einzelne Buchung wäre der
	 * Gesamtbestand danach falsch.
	 *
	 * @param string $type       Bewegungsart.
	 * @param int    $article_id Artikel.
	 * @param string $color      Farbe.
	 * @param float  $qty        Menge (positiv).
	 * @param int    $contact_id Händler.
	 * @return array Liste von Bewegungen.
	 */
	public static function commission_pair( $type, $article_id, $color, $qty, $contact_id ) {
		$qty  = abs( (float) $qty );
		$base = array(
			'article_id' => (int) $article_id,
			'color'      => (string) $color,
			'contact_id' => (int) $contact_id ? (int) $contact_id : null,
		);

		switch ( $type ) {
			case 'kommission_raus':
				// Aus dem Lager heraus, beim Händler hinein. Kein Umsatz.
				return array(
					array_merge( $base, array( 'qty' => -$qty, 'movement_type' => 'kommission_raus', 'location' => 'lager' ) ),
					array_merge( $base, array( 'qty' => $qty, 'movement_type' => 'kommission_raus', 'location' => 'kommission' ) ),
				);

			case 'kommission_retour':
				// Zurück ins Lager. Ebenfalls kein Umsatz.
				return array(
					array_merge( $base, array( 'qty' => -$qty, 'movement_type' => 'kommission_retour', 'location' => 'kommission' ) ),
					array_merge( $base, array( 'qty' => $qty, 'movement_type' => 'kommission_retour', 'location' => 'lager' ) ),
				);

			case 'kommission_abgerechnet':
				// Verlässt den Bestand endgültig – hier entsteht der Umsatz.
				return array(
					array_merge( $base, array( 'qty' => -$qty, 'movement_type' => 'kommission_abgerechnet', 'location' => 'kommission' ) ),
				);
		}

		return array();
	}

	/**
	 * Storno-Gegenbuchung zu einer Bewegung.
	 *
	 * Kein hartes Löschen: Die ursprüngliche Bewegung bleibt stehen, die
	 * Gegenbuchung verweist auf sie.
	 *
	 * @param array  $movement Ursprüngliche Bewegung.
	 * @param string $reason   Begründung.
	 * @return array
	 */
	public static function reversal( array $movement, $reason ) {
		return array(
			'article_id'    => isset( $movement['article_id'] ) ? (int) $movement['article_id'] : 0,
			'color'         => isset( $movement['color'] ) ? (string) $movement['color'] : '',
			'qty'           => -1 * ( isset( $movement['qty'] ) ? (float) $movement['qty'] : 0.0 ),
			'movement_type' => 'korrektur',
			'location'      => isset( $movement['location'] ) ? (string) $movement['location'] : 'lager',
			// Der Lagerplatz muss mit: Eine Gegenbuchung ohne Platz wuerde den
			// Platzbestand dauerhaft schieflegen, obwohl der Gesamtbestand stimmt.
			'bin_id'        => isset( $movement['bin_id'] ) ? $movement['bin_id'] : null,
			'contact_id'    => isset( $movement['contact_id'] ) ? $movement['contact_id'] : null,
			'ref_type'      => isset( $movement['ref_type'] ) ? $movement['ref_type'] : null,
			'ref_id'        => isset( $movement['ref_id'] ) ? $movement['ref_id'] : null,
			'reverses_id'   => isset( $movement['id'] ) ? (int) $movement['id'] : null,
			'void_reason'   => (string) $reason,
		);
	}

	/**
	 * Wie viel einer Bestellung ist noch zu buchen?
	 *
	 * Bricht eine Buchung mittendrin ab, fehlt die Marke „gebucht" an der
	 * Bestellung, der nächste Versuch buchte alles noch einmal. Deshalb wird
	 * vor jeder Position gegengerechnet, was für diese Bestellung schon als
	 * Verkauf im Konto steht (Stornierte zählen nicht mehr).
	 *
	 * @param array $booked Wirksame Verkaufsbewegungen der Bestellung.
	 * @param array $wanted Gewünschte Positionen: article_id, color, qty (positiv).
	 * @return array Noch zu buchende Menge je Position, in der Reihenfolge von $wanted.
	 */
	public static function unbooked( array $booked, array $wanted ) {
		$pool = array();

		foreach ( $booked as $movement ) {
			$movement = (array) $movement;
			if ( 'verkauf' !== ( isset( $movement['movement_type'] ) ? $movement['movement_type'] : '' ) || (float) $movement['qty'] >= 0 ) {
				continue;
			}

			$key          = (int) $movement['article_id'] . '|' . (string) $movement['color'];
			$pool[ $key ] = ( isset( $pool[ $key ] ) ? $pool[ $key ] : 0.0 ) - (float) $movement['qty'];
		}

		$out = array();

		foreach ( $wanted as $i => $line ) {
			$key  = (int) $line['article_id'] . '|' . (string) $line['color'];
			$need = (float) $line['qty'];
			$have = isset( $pool[ $key ] ) ? $pool[ $key ] : 0.0;
			$take = min( $have, $need );

			$pool[ $key ] = $have - $take;
			$out[ $i ]    = max( 0.0, $need - $take );
		}

		return $out;
	}

	/**
	 * Reichweite in Wochen aus Bestand und Verbrauch.
	 *
	 * @param float $stock          Bestand.
	 * @param float $consumption    Verbrauch im Betrachtungszeitraum.
	 * @param int   $period_weeks   Länge des Zeitraums in Wochen.
	 * @return float|null Null, wenn ohne Verbrauch keine Aussage möglich ist.
	 */
	public static function coverage_weeks( $stock, $consumption, $period_weeks = 4 ) {
		$consumption = abs( (float) $consumption );

		if ( $consumption <= 0 || $period_weeks <= 0 ) {
			return null;
		}

		return (float) $stock / ( $consumption / (float) $period_weeks );
	}
}
