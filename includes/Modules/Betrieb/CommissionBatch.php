<?php
/**
 * Kommissionsware als Liste statt Zeile für Zeile.
 *
 * Eine Auslieferung an einen Händler umfasst selten einen Artikel. Sie
 * umfasst acht Artikel in drei Farben, und bis hierher war das achtzehnmal
 * Auswahlfeld, Menge, Buchen – mit achtzehn Seitenladungen dazwischen. Wer
 * dabei im Laden steht und den Karton auspackt, verzählt sich.
 *
 * Diese Klasse nimmt die ganze Liste entgegen und macht daraus einen
 * Vorgang. Drei Dinge macht sie dabei ausdrücklich:
 *
 * - **Leere Zeilen sind keine Buchung.** In einer Liste über alle Artikel
 *   stehen die meisten Felder auf null. Sie fallen weg, statt als Buchung
 *   über null Stück in der Bewegungstabelle zu landen.
 * - **Dieselbe Ware zweimal wird zusammengezählt**, nicht zweimal gebucht.
 *   Die Oberfläche zeigt jede Kombination nur einmal; kommt sie trotzdem
 *   doppelt an, ist die Summe die einzige Lesart, die nicht überrascht.
 * - **Entweder alles oder nichts.** Steht in einer Zeile Unsinn, wird gar
 *   nichts gebucht. Eine halb gebuchte Lieferung ist schlimmer als eine
 *   nicht gebuchte: Sie sieht aus wie eine fertige.
 *
 * Frei von Datenbank und WordPress-Umgebung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Prüfung und Aufbereitung einer Kommissionsliste.
 */
class CommissionBatch {

	/**
	 * Vorgänge, die eine Liste erlaubt.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'kommission_raus'        => __( 'ausliefern', 'lindenweg16' ),
			'kommission_abgerechnet' => __( 'abrechnen', 'lindenweg16' ),
			'kommission_retour'      => __( 'zurücknehmen', 'lindenweg16' ),
		);
	}

	/**
	 * Gibt es diesen Vorgang?
	 *
	 * @param string $type Vorgang.
	 * @return bool
	 */
	public static function has_type( $type ) {
		return array_key_exists( (string) $type, self::types() );
	}

	/**
	 * Die Liste prüfen und verdichten.
	 *
	 * @param array  $rows       Zeilen mit article_id, color, qty.
	 * @param string $type       Vorgang.
	 * @param int    $contact_id Händler.
	 * @return array {lines, errors, pieces, positions}
	 */
	public static function validate( array $rows, $type, $contact_id ) {
		$errors = array();

		if ( ! self::has_type( $type ) ) {
			$errors[] = __( 'Unbekannter Vorgang.', 'lindenweg16' );
		}

		if ( (int) $contact_id <= 0 ) {
			$errors[] = __( 'Ohne Händler lässt sich keine Kommission buchen.', 'lindenweg16' );
		}

		$lines = array();

		foreach ( $rows as $row ) {
			$row     = (array) $row;
			$article = isset( $row['article_id'] ) ? (int) $row['article_id'] : 0;
			$color   = isset( $row['color'] ) ? (string) $row['color'] : '';
			$roh     = isset( $row['qty'] ) ? trim( (string) $row['qty'] ) : '';

			// Ein leeres Feld ist keine Angabe. Es ist die Antwort „davon
			// nichts" und darf keine Zeile erzeugen.
			if ( '' === $roh ) {
				continue;
			}

			$qty = (float) str_replace( ',', '.', $roh );

			if ( 0.0 === $qty ) {
				continue;
			}

			if ( $qty < 0 ) {
				$errors[] = __( 'Negative Mengen gibt es nicht – für die Gegenrichtung den Vorgang wechseln.', 'lindenweg16' );
				continue;
			}

			if ( $article <= 0 ) {
				$errors[] = __( 'Eine Zeile nennt keinen Artikel.', 'lindenweg16' );
				continue;
			}

			$key = $article . '|' . $color;

			if ( isset( $lines[ $key ] ) ) {
				$lines[ $key ]['qty'] += $qty;
				continue;
			}

			$lines[ $key ] = array(
				'article_id' => $article,
				'color'      => $color,
				'qty'        => $qty,
			);
		}

		if ( ! $lines && ! $errors ) {
			$errors[] = __( 'Es steht keine Menge in der Liste.', 'lindenweg16' );
		}

		$lines = array_values( $lines );

		return array(
			'lines'     => $errors ? array() : $lines,
			'errors'    => $errors,
			'positions' => count( $lines ),
			'pieces'    => self::pieces( $lines ),
		);
	}

	/**
	 * Stückzahl über alle Zeilen.
	 *
	 * @param array $lines Zeilen.
	 * @return float
	 */
	public static function pieces( array $lines ) {
		$summe = 0.0;

		foreach ( $lines as $line ) {
			$summe += (float) $line['qty'];
		}

		return $summe;
	}

	/**
	 * Die Lagerbewegungen der ganzen Liste.
	 *
	 * Je Zeile dasselbe Paar wie bei der Einzelbuchung – die Regel, was eine
	 * Kommissionsbewegung ist, steht weiterhin nur an einer Stelle.
	 *
	 * @param array  $lines      Geprüfte Zeilen.
	 * @param string $type       Vorgang.
	 * @param int    $contact_id Händler.
	 * @return array
	 */
	public static function movements( array $lines, $type, $contact_id ) {
		$out = array();

		foreach ( $lines as $line ) {
			$out = array_merge(
				$out,
				StockLedger::commission_pair(
					$type,
					(int) $line['article_id'],
					(string) $line['color'],
					(float) $line['qty'],
					(int) $contact_id
				)
			);
		}

		return $out;
	}

	/**
	 * Zeilen mehr, als eine Lieferung je haben wird?
	 *
	 * Eine Obergrenze, damit ein zusammengesetzter Aufruf nicht tausend
	 * Buchungen auslöst. Wer wirklich mehr liefert, macht zwei Lieferungen –
	 * und hat dann auch zwei Lieferscheine.
	 */
	const MAX_LINES = 200;

	/**
	 * Ist die Liste unverhältnismässig lang?
	 *
	 * @param array $rows Zeilen.
	 * @return bool
	 */
	public static function too_long( array $rows ) {
		return count( $rows ) > self::MAX_LINES;
	}

	/**
	 * Meldung nach dem Buchen.
	 *
	 * @param string $type      Vorgang.
	 * @param int    $positions Positionen.
	 * @param float  $pieces    Stück.
	 * @return string
	 */
	public static function message( $type, $positions, $pieces ) {
		$typen = self::types();
		$name  = isset( $typen[ $type ] ) ? $typen[ $type ] : $type;

		return sprintf(
			/* translators: 1: Vorgang, 2: Anzahl Positionen, 3: Stückzahl. */
			__( 'Kommission %1$s: %2$d Positionen, %3$s Stück gebucht.', 'lindenweg16' ),
			$name,
			(int) $positions,
			number_format_i18n( (float) $pieces, 0 )
		);
	}
}
