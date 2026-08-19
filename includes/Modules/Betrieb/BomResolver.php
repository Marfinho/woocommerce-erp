<?php
/**
 * Rekursive Auflösung der Stückliste.
 *
 * Ein Stücklisteneintrag verweist entweder auf eine Komponente (Zukauf-
 * Kleinteil) oder auf einen anderen Artikel (Unterbaugruppe). Damit lassen
 * sich Baugruppen abbilden, die selbst gedruckte Teile enthalten – etwa die
 * Stange aus zugekauftem Holz plus gedruckten Abstandshaltern und Wandankern.
 *
 * Bewusst ohne Datenbankzugriff: Der Aufrufer lädt die Daten einmal und
 * übergibt sie als Kontext. Das macht die Auflösung testbar und verhindert
 * eine Abfrage je Stücklistenzeile.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Auflösung, Zyklenprüfung und Tiefenbegrenzung von Stücklisten.
 */
class BomResolver {

	/**
	 * Maximale Auflösungstiefe. Tiefer wird abgebrochen, nicht stillschweigend
	 * abgeschnitten – eine zu tiefe Stückliste ist ein Konstruktionsfehler.
	 */
	const MAX_DEPTH = 5;

	/**
	 * Stückliste eines Artikels flach auflösen.
	 *
	 * @param int   $article_id Wurzelartikel.
	 * @param array $ctx        Kontext mit 'bom' (parent_id => Zeilen),
	 *                          'articles' (id => Zeile) und
	 *                          'components' (id => Zeile).
	 * @param float $qty        Menge des Wurzelartikels.
	 * @param int   $max_depth  Maximale Tiefe.
	 * @return array {lines, errors} – lines mit depth, child_type, child_id,
	 *               qty (kumuliert), path.
	 */
	public static function resolve( $article_id, array $ctx, $qty = 1.0, $max_depth = self::MAX_DEPTH ) {
		$lines  = array();
		$errors = array();

		self::walk( (int) $article_id, $ctx, (float) $qty, 0, (int) $max_depth, array( (int) $article_id ), $lines, $errors );

		return array(
			'lines'  => $lines,
			'errors' => $errors,
		);
	}

	/**
	 * Prüft, ob das Einhängen eines Kindes einen Zyklus erzeugen würde.
	 *
	 * Aufzurufen vor dem Speichern einer Stücklistenzeile: Eine Stückliste
	 * darf sich weder selbst noch über Umwege enthalten.
	 *
	 * @param int   $parent_id Elternartikel.
	 * @param int   $child_id  Einzuhängender Artikel.
	 * @param array $ctx       Kontext mit 'bom'.
	 * @return bool True, wenn ein Zyklus entstünde.
	 */
	public static function would_create_cycle( $parent_id, $child_id, array $ctx ) {
		$parent_id = (int) $parent_id;
		$child_id  = (int) $child_id;

		if ( $parent_id === $child_id ) {
			return true;
		}

		// Enthält das Kind (irgendwo tief) bereits den Elternartikel?
		$result = self::resolve( $child_id, $ctx, 1.0, 64 );
		foreach ( $result['lines'] as $line ) {
			if ( 'artikel' === $line['child_type'] && (int) $line['child_id'] === $parent_id ) {
				return true;
			}
		}

		// Ein Zyklus unterhalb des Kindes zählt ebenfalls als Hindernis.
		foreach ( $result['errors'] as $error ) {
			if ( 'cycle' === $error['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Alle Artikel-IDs, die in der Stückliste vorkommen (inklusive Wurzel).
	 *
	 * @param int   $article_id Wurzel.
	 * @param array $ctx        Kontext.
	 * @return int[]
	 */
	public static function article_ids( $article_id, array $ctx ) {
		$ids    = array( (int) $article_id );
		$result = self::resolve( $article_id, $ctx );
		foreach ( $result['lines'] as $line ) {
			if ( 'artikel' === $line['child_type'] ) {
				$ids[] = (int) $line['child_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Rekursiver Abstieg.
	 *
	 * @param int   $article_id Aktueller Artikel.
	 * @param array $ctx        Kontext.
	 * @param float $factor     Kumulierte Menge auf diesem Pfad.
	 * @param int   $depth      Aktuelle Tiefe.
	 * @param int   $max_depth  Grenze.
	 * @param array $path       Bisheriger Pfad (Artikel-IDs) für die Zyklenprüfung.
	 * @param array $lines      Ergebnisliste (per Referenz).
	 * @param array $errors     Fehlerliste (per Referenz).
	 */
	protected static function walk( $article_id, array $ctx, $factor, $depth, $max_depth, array $path, array &$lines, array &$errors ) {
		$rows = isset( $ctx['bom'][ $article_id ] ) ? $ctx['bom'][ $article_id ] : array();

		if ( ! $rows ) {
			return;
		}

		// Erst prüfen, wenn es tatsächlich etwas aufzulösen gibt: Eine
		// Stückliste, die auf der letzten erlaubten Ebene endet, ist gültig.
		if ( $depth >= $max_depth ) {
			$errors[] = array(
				'type'       => 'depth',
				'article_id' => $article_id,
				'message'    => sprintf(
					/* translators: 1: Tiefe, 2: Artikel-ID. */
					__( 'Stückliste tiefer als %1$d Ebenen (bei Artikel %2$d) – Auflösung abgebrochen.', 'lindenweg16' ),
					(int) $max_depth,
					(int) $article_id
				),
			);
			return;
		}

		foreach ( $rows as $row ) {
			$row        = (array) $row;
			$child_type = isset( $row['child_type'] ) ? (string) $row['child_type'] : 'komponente';
			$child_id   = isset( $row['child_id'] ) ? (int) $row['child_id'] : 0;
			$qty        = isset( $row['qty'] ) ? (float) $row['qty'] : 0.0;
			$total      = $factor * $qty;

			if ( ! $child_id ) {
				continue;
			}

			if ( 'artikel' === $child_type && in_array( $child_id, $path, true ) ) {
				$errors[] = array(
					'type'       => 'cycle',
					'article_id' => $child_id,
					'message'    => sprintf(
						/* translators: %s: Pfad der Artikel-IDs. */
						__( 'Stückliste enthält sich selbst: %s', 'lindenweg16' ),
						implode( ' → ', array_merge( $path, array( $child_id ) ) )
					),
				);
				continue;
			}

			$lines[] = array(
				'depth'      => $depth,
				'child_type' => $child_type,
				'child_id'   => $child_id,
				'qty'        => $total,
				'qty_each'   => $qty,
				'position'   => isset( $row['position'] ) ? (int) $row['position'] : 0,
				'note'       => isset( $row['note'] ) ? (string) $row['note'] : '',
				'path'       => $path,
			);

			if ( 'artikel' === $child_type ) {
				self::walk(
					$child_id,
					$ctx,
					$total,
					$depth + 1,
					$max_depth,
					array_merge( $path, array( $child_id ) ),
					$lines,
					$errors
				);
			}
		}
	}
}
