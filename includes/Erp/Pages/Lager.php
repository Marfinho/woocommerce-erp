<?php
/**
 * Lager im ERP: Bestand, Lagerplätze (mit Fertigungsaufträgen) und Bedarf.
 *
 * Eine Datei für drei Seiten der Navigation (`lager`, `lagerplaetze`,
 * `bedarf`), die zusammengehören: Sie lesen dasselbe Bewegungskonto. Die
 * Regeln der alten Seiten (StockPage, BinsPage, DemandPage) gelten
 * unverändert – gebucht, storniert und protokolliert wird über dieselben
 * Repositories (`StockRepository`, `BinRepository`, `WorkOrderRepository`,
 * `BetriebAudit` darin), hier kommt nur eine Schnittstelle für die native
 * Oberfläche dazu. Reine Rechenregeln stehen als statische Methoden oben
 * und sind getestet (tests/ErpLagerTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\ActionMap;
use Lindenweg16\Erp\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen und Regeln der Lager-Seiten.
 */
class Lager {

	/**
	 * Obergrenze für eine einzelne Buchung – ein Tippfehler (Nullen zu viel)
	 * soll nicht den Bestand sprengen.
	 */
	const MAX_QTY = 1000000;

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank, getestet)
	 * ================================================================== */

	/**
	 * Arten einer Buchung auf der Seite „Lager".
	 *
	 * @return array Kennung => Bezeichnung.
	 */
	public static function kinds() {
		return array(
			'zugang'     => __( 'Zugang', 'lindenweg16' ),
			'abgang'     => __( 'Abgang', 'lindenweg16' ),
			'korrektur'  => __( 'Korrektur (Inventur)', 'lindenweg16' ),
			'umlagerung' => __( 'Umlagern', 'lindenweg16' ),
			'kommission' => __( 'Kommission', 'lindenweg16' ),
		);
	}

	/**
	 * Gründe je Art: Kennung der Bewegungsart (StockLedger) => Bezeichnung.
	 *
	 * @param string $kind Art.
	 * @return array
	 */
	public static function reasons( $kind ) {
		switch ( $kind ) {
			case 'zugang':
				return array(
					'produktion' => __( 'Aus der Produktion', 'lindenweg16' ),
					'retoure'    => __( 'Retoure', 'lindenweg16' ),
				);
			case 'abgang':
				return array(
					'ausschuss' => __( 'Ausschuss', 'lindenweg16' ),
					'muster'    => __( 'Muster abgegeben', 'lindenweg16' ),
				);
			case 'korrektur':
				return array( 'korrektur' => __( 'Korrektur', 'lindenweg16' ) );
			case 'umlagerung':
				return array( 'umlagerung' => __( 'Umlagerung', 'lindenweg16' ) );
		}

		return array();
	}

	/**
	 * Eine Mengenangabe lesen – deutsch („2,5“, „1.250“) oder englisch.
	 *
	 * @param mixed $raw Eingabe.
	 * @return float|null Null, wenn es keine Zahl ist.
	 */
	public static function parse_qty( $raw ) {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return is_finite( (float) $raw ) ? (float) $raw : null;
		}

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$text = str_replace( array( ' ', "\xc2\xa0" ), '', trim( $raw ) );

		if ( '' === $text ) {
			return null;
		}

		if ( false !== strpos( $text, ',' ) ) {
			// Komma ist das Dezimalzeichen, Punkte davor sind Tausendertrenner.
			$text = str_replace( '.', '', $text );
			$text = str_replace( ',', '.', $text );
		} elseif ( preg_match( '/^[+-]?\d{1,3}(\.\d{3})+$/', $text ) ) {
			// „1.250“ ohne Komma: Tausendertrenner, nicht 1,25.
			$text = str_replace( '.', '', $text );
		}

		if ( ! preg_match( '/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $text ) ) {
			return null;
		}

		return (float) $text;
	}

	/**
	 * Das Vorzeichen, das die Art vorgibt.
	 *
	 * Zugang und Umlagerung zählen positiv, Abgang negativ – die Person
	 * tippt nur die Stückzahl. Nur die Korrektur darf beides.
	 *
	 * @param string $kind Art.
	 * @param float  $qty  Eingetippte Menge.
	 * @return float
	 */
	public static function signed_qty( $kind, $qty ) {
		$qty = (float) $qty;

		if ( 'abgang' === $kind ) {
			return -1 * abs( $qty );
		}

		if ( 'korrektur' === $kind ) {
			return $qty;
		}

		return abs( $qty );
	}

	/**
	 * Eine Buchung prüfen.
	 *
	 * @param array $in  Eingaben: kind, reason, article_id, color, qty, note, bin_id, to_bin_id.
	 * @param array $ctx Umfeld: article (Datensatz oder null), colors (Kennung => Name),
	 *                   bins (id => Platz mit code und is_active).
	 * @return array {errors, data}; data enthält die Zeilen (`lines`) zum Buchen.
	 */
	public static function validate_movement( array $in, array $ctx ) {
		$errors = array();
		$kind   = isset( $in['kind'] ) ? (string) $in['kind'] : '';
		$bins   = isset( $ctx['bins'] ) ? (array) $ctx['bins'] : array();
		$colors = isset( $ctx['colors'] ) ? (array) $ctx['colors'] : array();

		if ( ! in_array( $kind, array( 'zugang', 'abgang', 'korrektur', 'umlagerung' ), true ) ) {
			return array(
				'errors' => array( __( 'Bitte wählen, was gebucht werden soll: Zugang, Abgang, Korrektur oder Umlagerung.', 'lindenweg16' ) ),
				'data'   => array(),
			);
		}

		if ( empty( $ctx['article'] ) ) {
			$errors[] = __( 'Bitte einen Artikel wählen.', 'lindenweg16' );
		}

		$color = isset( $in['color'] ) ? trim( (string) $in['color'] ) : '';
		if ( '' === $color || ! array_key_exists( $color, $colors ) ) {
			$errors[] = __( 'Bitte eine Farbe wählen, die das Lager kennt.', 'lindenweg16' );
		}

		$qty = self::parse_qty( isset( $in['qty'] ) ? $in['qty'] : '' );
		if ( null === $qty ) {
			$errors[] = __( 'Bitte die Menge als Zahl eintragen, zum Beispiel 5 oder 2,5.', 'lindenweg16' );
		} elseif ( abs( $qty ) < 0.0005 ) {
			$errors[] = __( 'Bitte eine Menge ungleich 0 angeben.', 'lindenweg16' );
		} elseif ( abs( $qty ) > self::MAX_QTY ) {
			$errors[] = __( 'Diese Menge ist zu groß für eine einzelne Buchung – vermutlich ein Tippfehler.', 'lindenweg16' );
		}

		$note = isset( $in['note'] ) ? trim( (string) $in['note'] ) : '';
		if ( 'korrektur' === $kind && '' === $note ) {
			$errors[] = __( 'Eine Korrekturbuchung braucht eine Begründung.', 'lindenweg16' );
		}

		$reasons = self::reasons( $kind );
		$reason  = isset( $in['reason'] ) && '' !== (string) $in['reason'] ? (string) $in['reason'] : (string) key( $reasons );
		if ( ! array_key_exists( $reason, $reasons ) ) {
			$errors[] = __( 'Dieser Grund passt nicht zur gewählten Buchung.', 'lindenweg16' );
		}

		$bin_id    = isset( $in['bin_id'] ) ? (int) $in['bin_id'] : 0;
		$to_bin_id = isset( $in['to_bin_id'] ) ? (int) $in['to_bin_id'] : 0;

		if ( 'umlagerung' === $kind ) {
			if ( ! $bin_id || ! $to_bin_id ) {
				$errors[] = __( 'Für eine Umlagerung bitte Von-Platz und Nach-Platz wählen.', 'lindenweg16' );
			} elseif ( $bin_id === $to_bin_id ) {
				$errors[] = __( 'Von-Platz und Nach-Platz sind derselbe Platz – da gibt es nichts umzulagern.', 'lindenweg16' );
			}
		}

		foreach ( array_filter( array_unique( array( $bin_id, $to_bin_id ) ) ) as $id ) {
			if ( ! isset( $bins[ $id ] ) ) {
				$errors[] = __( 'Diesen Lagerplatz gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' );
			}
		}

		// Einlagern in einen gesperrten Platz: nicht. Ausräumen daraus: ja.
		if ( ! $errors && null !== $qty ) {
			$signed = self::signed_qty( $kind, $qty );
			$into   = 'umlagerung' === $kind ? $to_bin_id : ( $signed > 0 ? $bin_id : 0 );

			if ( $into && isset( $bins[ $into ] ) && empty( $bins[ $into ]['is_active'] ) ) {
				$errors[] = sprintf(
					/* translators: %s: Platzcode. */
					__( 'Der Lagerplatz %s ist gesperrt – dort lässt sich nichts mehr einlagern.', 'lindenweg16' ),
					(string) $bins[ $into ]['code']
				);
			}
		}

		if ( $errors ) {
			return array(
				'errors' => $errors,
				'data'   => array(),
			);
		}

		$article_id = (int) $ctx['article']['id'];
		$signed     = self::signed_qty( $kind, $qty );
		$common     = array(
			'article_id'    => $article_id,
			'color'         => $color,
			'movement_type' => $reason,
			'note'          => $note,
			'ref_type'      => 'manual',
		);

		if ( 'umlagerung' === $kind ) {
			$lines = array(
				array(
					'qty'    => -1 * abs( $signed ),
					'bin_id' => $bin_id,
				),
				array(
					'qty'    => abs( $signed ),
					'bin_id' => $to_bin_id,
				),
			);
		} else {
			$lines = array(
				array(
					'qty'    => $signed,
					'bin_id' => $bin_id,
				),
			);
		}

		return array(
			'errors' => array(),
			'data'   => array(
				'common' => $common,
				'lines'  => $lines,
				'kind'   => $kind,
			),
		);
	}

	/**
	 * Hinweise zu einer Buchung, die nicht verboten, aber auffällig ist.
	 *
	 * @param array $lines  Zeilen (qty, bin_id).
	 * @param float $lager  Bestand im Lager vor der Buchung (Artikel und Farbe).
	 * @param array $in_bin Bestand je Platz vor der Buchung (bin_id => Menge).
	 * @param array $bins   Plätze (id => code).
	 * @return string[]
	 */
	public static function warnings( array $lines, $lager, array $in_bin, array $bins ) {
		$out   = array();
		$delta = 0.0;
		$after = array();

		foreach ( $lines as $line ) {
			$delta += (float) $line['qty'];
			$bin    = (int) $line['bin_id'];

			if ( $bin ) {
				if ( ! isset( $after[ $bin ] ) ) {
					$after[ $bin ] = isset( $in_bin[ $bin ] ) ? (float) $in_bin[ $bin ] : 0.0;
				}
				$after[ $bin ] += (float) $line['qty'];
			}
		}

		if ( (float) $lager + $delta < -0.0005 ) {
			$out[] = __( 'Achtung: Der Bestand im Lager ist damit negativ. Stimmt die Menge?', 'lindenweg16' );
		}

		foreach ( $after as $bin => $qty ) {
			if ( $qty < -0.0005 ) {
				$out[] = sprintf(
					/* translators: %s: Platzcode. */
					__( 'Achtung: Am Platz %s liegt damit weniger als nichts. Vielleicht war die Ware dort nie eingelagert.', 'lindenweg16' ),
					isset( $bins[ $bin ] ) ? (string) $bins[ $bin ] : '?'
				);
			}
		}

		return $out;
	}

	/**
	 * Kennung eines Vorgangs für die Doppelbuchungssperre bereinigen.
	 *
	 * Dieselbe Regel wie in der Betrieb-Schnittstelle (Zwischenspeicher
	 * `lw16_cref_…`): Das Nachsenden vorgemerkter Buchungen darf nie
	 * doppelt buchen.
	 *
	 * @param mixed $raw Rohwert.
	 * @return string Leer, wenn keine Kennung mitkam.
	 */
	public static function client_ref( $raw ) {
		return preg_replace( '/[^A-Za-z0-9\-]/', '', is_scalar( $raw ) ? (string) $raw : '' );
	}

	/**
	 * Den Platzbestand, der in einem Artikel/Farbe-Bestand „ohne Platz“ liegt.
	 *
	 * @param float $lager   Bestand im Lager.
	 * @param float $located Summe der Mengen an Plätzen.
	 * @return float Nie negativ.
	 */
	public static function unplaced( $lager, $located ) {
		return max( 0.0, round( (float) $lager - (float) $located, 3 ) );
	}

	/**
	 * Kennzeichen einer Bestandszeile.
	 *
	 * @param array $row Zeile mit gesamt, lager, soll (oder null), unplaced.
	 * @return array {unter_soll, ohne_platz, leer}
	 */
	public static function flags( array $row ) {
		$soll = isset( $row['soll'] ) ? $row['soll'] : null;

		return array(
			'unter_soll' => null !== $soll && (float) $row['gesamt'] < (float) $soll - 0.0005,
			'ohne_platz' => (float) $row['unplaced'] > 0.0005,
			'leer'       => (float) $row['gesamt'] <= 0.0005,
		);
	}

	/**
	 * Bestandszeilen zusammenstellen: Bewegungen und Sollbestände vereinigt.
	 *
	 * Ein Sollbestand ohne Bewegung bleibt sichtbar (genau der häufige Fall:
	 * Man setzt ihn für etwas, das gerade nicht da ist).
	 *
	 * @param array $balances Ergebnis von StockLedger::balances().
	 * @param array $targets  article_id => color => Sollmenge.
	 * @param array $located  article_id => color => Menge an Plätzen.
	 * @param array $articles article_id => Artikel.
	 * @param array $usage    article_id => Verbrauch der letzten Wochen.
	 * @param int   $weeks    Länge des Zeitraums.
	 * @return array
	 */
	public static function build_rows( array $balances, array $targets, array $located, array $articles, array $usage, $weeks = 4 ) {
		$keys = array();

		foreach ( array( $balances, $targets ) as $source ) {
			foreach ( $source as $article_id => $colors ) {
				foreach ( $colors as $color => $unused ) {
					$keys[ (int) $article_id ][ (string) $color ] = true;
				}
			}
		}

		$rows = array();

		foreach ( $keys as $article_id => $colors ) {
			foreach ( array_keys( $colors ) as $color ) {
				$sums = array(
					'lager'      => 0.0,
					'kommission' => 0.0,
					'unterwegs'  => 0.0,
				);
				$cell = isset( $balances[ $article_id ][ $color ] ) ? $balances[ $article_id ][ $color ] : array();

				foreach ( array_keys( $sums ) as $place ) {
					$sums[ $place ] = isset( $cell[ $place ] ) ? (float) $cell[ $place ] : 0.0;
				}

				$gesamt = array_sum( $sums );
				$soll   = isset( $targets[ $article_id ][ $color ] ) ? (float) $targets[ $article_id ][ $color ] : null;
				$use    = isset( $usage[ $article_id ] ) ? (float) $usage[ $article_id ] : 0.0;
				$reach  = $use > 0 && $weeks > 0 ? $sums['lager'] / ( $use / $weeks ) : null;
				$here   = isset( $located[ $article_id ][ $color ] ) ? (float) $located[ $article_id ][ $color ] : 0.0;

				$row = array(
					'article_id' => (int) $article_id,
					'sku'        => isset( $articles[ $article_id ]['sku'] ) ? (string) $articles[ $article_id ]['sku'] : '#' . $article_id,
					'name'       => isset( $articles[ $article_id ]['name'] ) ? (string) $articles[ $article_id ]['name'] : '',
					'color'      => (string) $color,
					'lager'      => round( $sums['lager'], 3 ),
					'kommission' => round( $sums['kommission'], 3 ),
					'unterwegs'  => round( $sums['unterwegs'], 3 ),
					'gesamt'     => round( $gesamt, 3 ),
					'soll'       => $soll,
					'diff'       => null === $soll ? null : round( $gesamt - $soll, 3 ),
					'reach'      => null === $reach ? null : round( $reach, 1 ),
					'unplaced'   => self::unplaced( $sums['lager'], $here ),
				);

				$row['flags'] = self::flags( $row );
				$rows[]       = $row;
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				$cmp = strcmp( $a['sku'], $b['sku'] );

				return 0 !== $cmp ? $cmp : strcmp( $a['color'], $b['color'] );
			}
		);

		return $rows;
	}

	/**
	 * Gibt es zu dieser Bewegung eine Gegenbuchung, ist sie eine, oder lässt sie sich stornieren?
	 *
	 * @param array $row     Bewegung.
	 * @param array $voided  Menge der Kennungen, die schon storniert sind (id => true).
	 * @return string offen, storniert oder gegenbuchung.
	 */
	public static function journal_state( array $row, array $voided ) {
		if ( ! empty( $row['reverses_id'] ) ) {
			return 'gegenbuchung';
		}

		return isset( $voided[ (int) $row['id'] ] ) ? 'storniert' : 'offen';
	}

	/**
	 * Herkunft einer Bewegung in Klartext.
	 *
	 * @param string $type Herkunftsart.
	 * @param int    $id   Herkunft.
	 * @return string
	 */
	public static function ref_label( $type, $id ) {
		$type  = (string) $type;
		$names = array(
			'manual'       => __( 'von Hand gebucht', 'lindenweg16' ),
			'mobil'        => __( 'am Handy gebucht', 'lindenweg16' ),
			'kasse'        => __( 'Kasse', 'lindenweg16' ),
			'wc_order'     => __( 'Shop-Bestellung', 'lindenweg16' ),
			'order_ops'    => __( 'Auftrag', 'lindenweg16' ),
			'print_job'    => __( 'Druckjob', 'lindenweg16' ),
			'purchase'     => __( 'Wareneingang', 'lindenweg16' ),
			'work_order'   => __( 'Fertigungsauftrag', 'lindenweg16' ),
			'altbestand'   => __( 'Übernahme Altbestand', 'lindenweg16' ),
		);

		if ( '' === $type ) {
			return '';
		}

		$name = isset( $names[ $type ] ) ? $names[ $type ] : $type;

		return (int) $id > 0 && ! in_array( $type, array( 'manual', 'mobil' ), true ) ? $name . ' #' . (int) $id : $name;
	}

	/**
	 * Eine Korrektur an Komponente oder Karton prüfen.
	 *
	 * @param array $in Eingaben: part_type, part_id, qty, note.
	 * @return array {errors, data}
	 */
	public static function validate_part( array $in ) {
		$errors = array();
		$type   = isset( $in['part_type'] ) && 'supply' === $in['part_type'] ? 'supply' : 'component';
		$id     = isset( $in['part_id'] ) ? (int) $in['part_id'] : 0;
		$qty    = self::parse_qty( isset( $in['qty'] ) ? $in['qty'] : '' );
		$note   = isset( $in['note'] ) ? trim( (string) $in['note'] ) : '';

		if ( $id <= 0 ) {
			$errors[] = __( 'Bitte eine Position wählen.', 'lindenweg16' );
		}

		if ( null === $qty ) {
			$errors[] = __( 'Bitte die Menge als Zahl eintragen, zum Beispiel 5 oder -2,5.', 'lindenweg16' );
		} elseif ( abs( $qty ) < 0.0005 ) {
			$errors[] = __( 'Bitte eine Menge ungleich 0 angeben.', 'lindenweg16' );
		} elseif ( abs( $qty ) > self::MAX_QTY ) {
			$errors[] = __( 'Diese Menge ist zu groß für eine einzelne Buchung – vermutlich ein Tippfehler.', 'lindenweg16' );
		}

		if ( '' === $note ) {
			$errors[] = __( 'Eine Korrekturbuchung braucht eine Begründung.', 'lindenweg16' );
		}

		return array(
			'errors' => $errors,
			'data'   => array(
				'type' => $type,
				'id'   => $id,
				'qty'  => (float) $qty,
				'note' => $note,
			),
		);
	}

	/**
	 * Einen Sollbestand prüfen.
	 *
	 * Entfernen ist etwas anderes als „Soll 0“: Bei 0 meldet die Bedarfsplanung
	 * jeden Bestand darunter, ein entfernter Sollbestand meldet gar nicht mehr.
	 *
	 * @param array $in  Eingaben: article_id, color, target_qty, remove.
	 * @param array $ctx Umfeld: article (oder null), colors.
	 * @return array {errors, data}
	 */
	public static function validate_target( array $in, array $ctx ) {
		$errors = array();
		$colors = isset( $ctx['colors'] ) ? (array) $ctx['colors'] : array();
		$color  = isset( $in['color'] ) ? trim( (string) $in['color'] ) : '';
		$remove = ! empty( $in['remove'] );
		$qty    = self::parse_qty( isset( $in['target_qty'] ) ? $in['target_qty'] : '' );

		if ( empty( $ctx['article'] ) ) {
			$errors[] = __( 'Bitte einen Artikel wählen.', 'lindenweg16' );
		}

		if ( '' === $color || ! array_key_exists( $color, $colors ) ) {
			$errors[] = __( 'Bitte eine Farbe wählen, die das Lager kennt.', 'lindenweg16' );
		}

		if ( ! $remove ) {
			if ( null === $qty ) {
				$errors[] = __( 'Bitte die Sollmenge als Zahl eintragen, zum Beispiel 20.', 'lindenweg16' );
			} elseif ( $qty < 0 ) {
				$errors[] = __( 'Ein Sollbestand kann nicht negativ sein. Zum Aufheben „Soll entfernen“ wählen.', 'lindenweg16' );
			} elseif ( $qty > self::MAX_QTY ) {
				$errors[] = __( 'Diese Sollmenge ist zu groß – vermutlich ein Tippfehler.', 'lindenweg16' );
			}
		}

		return array(
			'errors' => $errors,
			'data'   => array(
				'color'  => $color,
				'qty'    => (float) $qty,
				'remove' => $remove,
			),
		);
	}

	/**
	 * Hinweis beim Sperren eines Lagerplatzes, auf dem noch Ware liegt.
	 *
	 * @param array $stock Inhalt (Zeilen mit qty).
	 * @return string Leer, wenn nichts zu sagen ist.
	 */
	public static function lock_warning( array $stock ) {
		$pieces = 0.0;

		foreach ( $stock as $line ) {
			$pieces += max( 0.0, (float) $line['qty'] );
		}

		if ( $pieces <= 0.0005 ) {
			return '';
		}

		return sprintf(
			/* translators: %s: Stückzahl. */
			__( 'Hinweis: Auf diesem Platz liegen noch %s Stück. Sie bleiben dort gebucht; Ausräumen geht weiter über „Umlagern“ oder „Abgang“.', 'lindenweg16' ),
			number_format_i18n( $pieces, 0 )
		);
	}

	/**
	 * Eingaben eines Lagerplatzes aus der Anfrage – Namen wie in BinRepository::validate().
	 *
	 * @param array $in Anfrage.
	 * @return array
	 */
	public static function bin_input( array $in ) {
		return array(
			'code'       => isset( $in['code'] ) ? (string) $in['code'] : '',
			'name'       => isset( $in['name'] ) ? (string) $in['name'] : '',
			'kind'       => isset( $in['kind'] ) ? (string) $in['kind'] : 'regal',
			'note'       => isset( $in['note'] ) ? (string) $in['note'] : '',
			'sort_order' => isset( $in['sort_order'] ) ? (string) (int) $in['sort_order'] : '0',
			'is_active'  => ! empty( $in['is_active'] ) ? '1' : '',
		);
	}

	/**
	 * Wer an die Bedarfsvorschläge darf, welche Spalte ist „Wofür“ – Quelltext einer Zeile.
	 *
	 * @param array $sources Quellen einer Bedarfszeile (Schlüssel => qty, labels).
	 * @param array $names   Bezeichnungen der Quellen.
	 * @return string[]
	 */
	public static function demand_reasons( array $sources, array $names ) {
		$out = array();

		foreach ( $sources as $key => $source ) {
			$text = sprintf(
				'%s %s',
				number_format_i18n( (float) $source['qty'], 0 ),
				isset( $names[ $key ] ) ? $names[ $key ] : $key
			);

			if ( ! empty( $source['labels'] ) ) {
				$text .= ' (' . implode( ', ', array_slice( (array) $source['labels'], 0, 4 ) ) . ')';
			}

			$out[] = $text;
		}

		return $out;
	}

	/**
	 * Wohin führt eine Bedarfszeile?
	 *
	 * Die Planlogik bleibt im Workflow (Plan und Board) und im Einkauf; die
	 * Bedarfsansicht zeigt nur, was fehlt, und verweist dorthin.
	 *
	 * @param string $action drucken, montieren oder bestellen.
	 * @return string Seitenkennung.
	 */
	public static function demand_target( $action ) {
		return 'bestellen' === $action ? 'einkauf' : 'workflow';
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle der Oberfläche.
	 */
	public static function register( RestApi $api ) {
		$ns    = RestApi::NAMESPACE_V1;
		$rules = ActionMap::rules();
		$werk  = $rules['lw16_betrieb_work_order'];

		// Lager.
		self::route( $ns, '/erp/lager', 'GET', 'bestand', $api->needs( array( 'lager.bestand' ) ) );
		self::route( $ns, '/erp/lager/journal', 'GET', 'journal', $api->needs( array( 'lager.bestand' ) ) );
		self::route( $ns, '/erp/lager/buchen', 'POST', 'buchen', $api->needs( $rules['lw16_betrieb_stock_move'] ) );
		self::route( $ns, '/erp/lager/kommission', 'POST', 'kommission', $api->needs( array( 'lager.bestand', 'lager.buchen' ) ) );
		self::route( $ns, '/erp/lager/storno', 'POST', 'storno', $api->needs( $rules['lw16_betrieb_stock_void'] ) );
		self::route( $ns, '/erp/lager/soll', 'POST', 'soll', $api->needs( $rules['lw16_betrieb_stock_target'] ) );
		self::route( $ns, '/erp/lager/teil', 'POST', 'teil', $api->needs( $rules['lw16_betrieb_stock_part'] ) );
		self::route( $ns, '/erp/lager/teil/speichern', 'POST', 'teil_speichern', $api->needs( array( 'lager.bestand' ) ) );

		// Lagerplätze und Fertigung.
		self::route( $ns, '/erp/lagerplaetze', 'GET', 'plaetze', $api->needs( array( 'lager.lagerplaetze' ) ) );
		self::route( $ns, '/erp/lagerplaetze/speichern', 'POST', 'platz_speichern', $api->needs( $rules['lw16_betrieb_save_bin'] ) );
		self::route( $ns, '/erp/fertigung', 'GET', 'fertigung', $api->needs( array( 'lager.lagerplaetze' ) ) );
		self::route( $ns, '/erp/fertigung/anlegen', 'POST', 'fertigung_anlegen', $api->needs( $werk ) );
		self::route( $ns, '/erp/fertigung/uhr', 'POST', 'fertigung_uhr', $api->needs( $werk ) );
		self::route( $ns, '/erp/fertigung/fertig', 'POST', 'fertigung_fertig', $api->needs( $werk ) );

		// Picklisten aus offenen Bestellungen.
		self::route( $ns, '/erp/picklisten/bestellungen', 'GET', 'pickliste_vorschau', $api->needs( $rules['lw16_betrieb_make_picklist'] ) );
		self::route( $ns, '/erp/picklisten/erzeugen', 'POST', 'pickliste_erzeugen', $api->needs( $rules['lw16_betrieb_make_picklist'] ) );

		// Bedarf.
		self::route( $ns, '/erp/bedarf', 'GET', 'bedarf', $api->needs( array( 'lager.bedarf' ) ) );
	}

	/**
	 * Eine Route anmelden.
	 *
	 * @param string   $ns       Namensraum.
	 * @param string   $path     Pfad.
	 * @param string   $method   GET oder POST.
	 * @param string   $callback Name der Methode.
	 * @param callable $perm     Rechteprüfung.
	 */
	protected static function route( $ns, $path, $method, $callback, $perm ) {
		register_rest_route(
			$ns,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * Antwort mit Fehlertext.
	 *
	 * @param string $message Satz für die Person am Bildschirm.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Alten Betrieb-Rechten nach darf die Person Stammdaten und Stornos verantworten.
	 *
	 * Die alten Seiten verlangten für Storno, Sollbestand und Lagerplätze
	 * `lw16_manage_production`; das feine Recht allein deckt das nicht ab.
	 *
	 * @return bool
	 */
	protected static function is_lead() {
		return Roles::current_user_can_betrieb( 'lw16_manage_production' );
	}

	/**
	 * Antwort „Dafür fehlt dir das Recht“.
	 *
	 * @return WP_REST_Response
	 */
	protected static function not_lead() {
		return self::fail( __( 'Das darf nur die Betriebsleitung (Recht „Produktion verwalten“). Bitte eine Leitung darum bitten.', 'lindenweg16' ), 403 );
	}

	/**
	 * Eine Eingabe aus der Anfrage als Text.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @param string          $key     Name.
	 * @return string
	 */
	protected static function text( WP_REST_Request $request, $key ) {
		$value = $request->get_param( $key );

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Wurde dieser Vorgang schon verbucht?
	 *
	 * @param string $ref Kennung des Vorgangs.
	 * @return bool
	 */
	protected static function already_done( $ref ) {
		return '' !== $ref && false !== get_transient( 'lw16_cref_' . md5( get_current_user_id() . '|' . $ref ) );
	}

	/**
	 * Vorgang als verbucht merken (einen Tag lang).
	 *
	 * @param string $ref Kennung.
	 */
	protected static function remember_done( $ref ) {
		if ( '' !== $ref ) {
			set_transient( 'lw16_cref_' . md5( get_current_user_id() . '|' . $ref ), 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Farben, die das Lager kennt (Kennung => Name), samt „ungeklärt“.
	 *
	 * @return array
	 */
	protected static function colors() {
		return \Lindenweg16\Modules\Betrieb\ColorRegistry::options( true );
	}

	/* ---------- Lager: Bestand ---------- */

	/**
	 * Bestandsliste mit allem, was die Seite braucht.
	 *
	 * @return WP_REST_Response
	 */
	public static function bestand() {
		$stock    = '\Lindenweg16\Modules\Betrieb\StockRepository';
		$movs     = $stock::all();
		$balances = \Lindenweg16\Modules\Betrieb\StockLedger::balances( $movs );
		$by_bin   = \Lindenweg16\Modules\Betrieb\BinRepository::balances( $movs );
		$articles = \Lindenweg16\Modules\Betrieb\ArticleRepository::all_by_id();
		$bins     = array();
		$located  = array();
		$places   = array();

		foreach ( \Lindenweg16\Modules\Betrieb\BinRepository::all() as $bin ) {
			$bins[ (int) $bin['id'] ] = $bin;
		}

		foreach ( $by_bin as $bin_id => $per_article ) {
			foreach ( $per_article as $article_id => $per_color ) {
				foreach ( $per_color as $color => $qty ) {
					$located[ (int) $article_id ][ (string) $color ] = ( isset( $located[ (int) $article_id ][ (string) $color ] ) ? $located[ (int) $article_id ][ (string) $color ] : 0.0 ) + (float) $qty;

					if ( abs( (float) $qty ) > 0.0005 && isset( $bins[ (int) $bin_id ] ) ) {
						$places[ (int) $article_id ][ (string) $color ][] = array(
							'id'   => (int) $bin_id,
							'code' => (string) $bins[ (int) $bin_id ]['code'],
							'qty'  => round( (float) $qty, 3 ),
						);
					}
				}
			}
		}

		$targets = array();
		foreach ( $stock::targets() as $target ) {
			$targets[ (int) $target['article_id'] ][ (string) $target['color'] ] = (float) $target['target_qty'];
		}

		$usage = array();
		$ids   = array_unique( array_merge( array_keys( $balances ), array_keys( $targets ) ) );
		foreach ( $ids as $article_id ) {
			$usage[ (int) $article_id ] = $stock::consumption( (int) $article_id, 4 );
		}

		$rows = self::build_rows( $balances, $targets, $located, $articles, $usage, 4 );

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['color_label'] = \Lindenweg16\Modules\Betrieb\ColorRegistry::label( $row['color'] );
			$rows[ $i ]['bins']        = isset( $places[ $row['article_id'] ][ $row['color'] ] ) ? $places[ $row['article_id'] ][ $row['color'] ] : array();
		}

		$can_book = Access::can( 'lager.buchen' );
		$lead     = self::is_lead();

		$out = array(
			'rows'       => $rows,
			'unresolved' => count( $stock::unresolved() ),
			'can_book'   => $can_book,
			'can_void'   => $can_book && $lead,
			'can_target' => Access::can( 'fertigung.stammdaten.edit' ) && $lead,
			'part_edit'  => array(
				'component' => self::can_edit_part( 'component' ),
				'supply'    => self::can_edit_part( 'supply' ),
			),
			'kinds'      => self::kinds(),
			'weeks'      => 4,
		);

		if ( $can_book || $out['can_target'] || $out['part_edit']['component'] || $out['part_edit']['supply'] ) {
			$out['parts'] = self::parts();
		}

		if ( $out['part_edit']['component'] || $out['part_edit']['supply'] ) {
			$out['part_bins'] = array();
			foreach ( $bins as $bin ) {
				if ( ! empty( $bin['is_active'] ) ) {
					$out['part_bins'][] = array( 'id' => (int) $bin['id'], 'label' => trim( $bin['code'] . ' ' . (string) $bin['name'] ) );
				}
			}
			$out['part_categories'] = array();
			foreach ( \Lindenweg16\Modules\Betrieb\ComponentRepository::category_labels() as $slug => $label ) {
				$out['part_categories'][] = array( 'slug' => (string) $slug, 'label' => (string) $label );
			}
		}

		if ( $can_book || $out['can_target'] ) {
			$list = array();
			foreach ( $articles as $article ) {
				$list[] = array(
					'id'     => (int) $article['id'],
					'sku'    => (string) $article['sku'],
					'name'   => (string) $article['name'],
					'active' => \Lindenweg16\Modules\Betrieb\ArticleRepository::is_active( $article ),
				);
			}
			usort(
				$list,
				static function ( $a, $b ) {
					return strcmp( $a['sku'], $b['sku'] );
				}
			);

			$bin_list = array();
			foreach ( $bins as $bin ) {
				$bin_list[] = array(
					'id'     => (int) $bin['id'],
					'code'   => (string) $bin['code'],
					'name'   => (string) $bin['name'],
					'active' => (bool) $bin['is_active'],
				);
			}

			$colors = array();
			foreach ( self::colors() as $slug => $label ) {
				$colors[] = array( 'slug' => (string) $slug, 'label' => (string) $label );
			}

			$reasons = array();
			foreach ( array( 'zugang', 'abgang', 'korrektur', 'umlagerung' ) as $kind ) {
				$reasons[ $kind ] = self::reasons( $kind );
			}

			$dealers = array();
			foreach ( \Lindenweg16\Modules\Betrieb\ContactRepository::all() as $contact ) {
				if ( 'haendler' === $contact['type'] ) {
					$dealers[] = array( 'id' => (int) $contact['id'], 'name' => (string) $contact['company'] );
				}
			}

			$out['articles'] = $list;
			$out['bins']     = $bin_list;
			$out['colors']   = $colors;
			$out['reasons']  = $reasons;
			$out['dealers']  = $dealers;
			$out['commission_types'] = \Lindenweg16\Modules\Betrieb\CommissionBatch::types();
		}

		return new WP_REST_Response( $out );
	}

	/**
	 * Kleinteile und Verbrauchsmaterial mit Bestand.
	 *
	 * @return array
	 */
	protected static function parts() {
		$labels = \Lindenweg16\Modules\Betrieb\ComponentRepository::category_labels();
		$out    = array();

		foreach ( \Lindenweg16\Modules\Betrieb\ComponentRepository::all() as $row ) {
			$out[] = array(
				'type'  => 'component',
				'id'    => (int) $row['id'],
				'title' => (string) $row['name'],
				'sub'   => trim( (string) $row['code'] . ' · ' . ( isset( $labels[ $row['category'] ] ) ? $labels[ $row['category'] ] : (string) $row['category'] ), ' ·' ),
				'qty'   => (float) $row['stock_qty'],
				'form'  => array(
					'code'           => (string) $row['code'],
					'name'           => (string) $row['name'],
					'barcode'        => (string) $row['barcode'],
					'category'       => (string) $row['category'],
					'supplier'       => (string) $row['supplier'],
					'pack_qty'       => (float) $row['pack_qty'],
					'pack_price_net' => (float) $row['pack_price_net'],
					'order_url'      => (string) $row['order_url'],
					'lead_time_days' => (int) $row['lead_time_days'],
					'min_order_qty'  => (float) $row['min_order_qty'],
					'safety_days'    => (int) $row['safety_days'],
					'default_bin_id' => (int) $row['default_bin_id'],
					'note'           => (string) $row['note'],
				),
			);
		}

		foreach ( \Lindenweg16\Modules\Betrieb\SupplyRepository::all() as $row ) {
			$out[] = array(
				'type'  => 'supply',
				'id'    => (int) $row['id'],
				'title' => (string) $row['name'],
				'sub'   => trim( (string) $row['unit'] . ' · ' . number_format_i18n( (float) $row['consumption_per_order'], 2 ) . ' ' . __( 'je Bestellung', 'lindenweg16' ), ' ·' ),
				'qty'   => (float) $row['stock_qty'],
				'form'  => array(
					'name'                  => (string) $row['name'],
					'unit'                  => (string) $row['unit'],
					'supplier'              => (string) $row['supplier'],
					'price_per_unit'        => (float) $row['price_per_unit'],
					'consumption_per_order' => (float) $row['consumption_per_order'],
					'min_order_qty'         => (float) $row['min_order_qty'],
					'lead_time_days'        => (int) $row['lead_time_days'],
					'safety_days'           => (int) $row['safety_days'],
				),
			);
		}

		return $out;
	}

	/**
	 * Letzte Bewegungen.
	 *
	 * @param WP_REST_Request $request Anfrage (limit).
	 * @return WP_REST_Response
	 */
	public static function journal( WP_REST_Request $request ) {
		$limit    = max( 20, min( 200, (int) $request->get_param( 'limit' ) ) );
		$rows     = \Lindenweg16\Modules\Betrieb\StockRepository::recent( $limit );
		$articles = \Lindenweg16\Modules\Betrieb\ArticleRepository::all_by_id();
		$types    = \Lindenweg16\Modules\Betrieb\StockLedger::movement_labels();
		$places   = \Lindenweg16\Modules\Betrieb\StockLedger::location_labels();
		$bins     = array();
		$voided   = array();

		foreach ( \Lindenweg16\Modules\Betrieb\BinRepository::all() as $bin ) {
			$bins[ (int) $bin['id'] ] = (string) $bin['code'];
		}

		// Eine Gegenbuchung ist immer neuer als ihr Original – steht das
		// Original im Ausschnitt, steht auch seine Gegenbuchung darin.
		foreach ( $rows as $row ) {
			if ( ! empty( $row['reverses_id'] ) ) {
				$voided[ (int) $row['reverses_id'] ] = true;
			}
		}

		$can_void = Access::can( 'lager.buchen' ) && self::is_lead();
		$out      = array();

		foreach ( $rows as $row ) {
			$state = self::journal_state( $row, $voided );
			$qty   = (float) $row['qty'];
			$time  = strtotime( (string) $row['moved_at'] . ' UTC' );

			$out[] = array(
				'id'          => (int) $row['id'],
				'ts'          => $time ? (int) $time : 0,
				'sku'         => isset( $articles[ (int) $row['article_id'] ] ) ? (string) $articles[ (int) $row['article_id'] ]['sku'] : '#' . (int) $row['article_id'],
				'name'        => isset( $articles[ (int) $row['article_id'] ] ) ? (string) $articles[ (int) $row['article_id'] ]['name'] : '',
				'color_label' => \Lindenweg16\Modules\Betrieb\ColorRegistry::label( $row['color'] ),
				'qty'         => $qty,
				'type'        => isset( $types[ $row['movement_type'] ] ) ? $types[ $row['movement_type'] ] : (string) $row['movement_type'],
				'place'       => isset( $places[ $row['location'] ] ) ? $places[ $row['location'] ] : (string) $row['location'],
				'bin'         => $row['bin_id'] && isset( $bins[ (int) $row['bin_id'] ] ) ? $bins[ (int) $row['bin_id'] ] : '',
				'origin'      => self::ref_label( (string) $row['ref_type'], (int) $row['ref_id'] ),
				'note'        => trim( (string) $row['note'] . ( $row['void_reason'] ? ' · ' . $row['void_reason'] : '' ), ' ·' ),
				'state'       => $state,
				'can_void'    => $can_void && 'offen' === $state,
			);
		}

		return new WP_REST_Response( array( 'rows' => $out, 'limit' => $limit ) );
	}

	/**
	 * Bestand zu Artikel und Farbe vor einer Buchung (für Hinweise).
	 *
	 * @param int    $article_id Artikel.
	 * @param string $color      Farbe.
	 * @return array {lager, bins}
	 */
	protected static function stock_of( $article_id, $color ) {
		$movs     = \Lindenweg16\Modules\Betrieb\StockRepository::all( (int) $article_id );
		$balances = \Lindenweg16\Modules\Betrieb\StockLedger::pick( \Lindenweg16\Modules\Betrieb\StockLedger::balances( $movs ), $article_id, $color );
		$in_bin   = array();

		foreach ( \Lindenweg16\Modules\Betrieb\BinRepository::balances( $movs ) as $bin_id => $per_article ) {
			if ( isset( $per_article[ (int) $article_id ][ $color ] ) ) {
				$in_bin[ (int) $bin_id ] = (float) $per_article[ (int) $article_id ][ $color ];
			}
		}

		return array(
			'lager' => (float) $balances['lager'],
			'bins'  => $in_bin,
		);
	}

	/**
	 * Zugang, Abgang, Korrektur oder Umlagerung buchen.
	 *
	 * Gebucht wird über StockRepository::book (Protokoll BetriebAudit darin).
	 * Mit `client_ref` doppelt gesendete Vorgänge (Nachsenden nach
	 * Verbindungsabbruch) buchen nur einmal.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function buchen( WP_REST_Request $request ) {
		$ref = self::client_ref( $request->get_param( 'client_ref' ) );

		if ( self::already_done( $ref ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true, 'message' => __( 'Das war schon gebucht.', 'lindenweg16' ) ) );
		}

		$article_id = (int) $request->get_param( 'article_id' );
		$bins       = array();

		foreach ( \Lindenweg16\Modules\Betrieb\BinRepository::all() as $bin ) {
			$bins[ (int) $bin['id'] ] = array( 'code' => (string) $bin['code'], 'is_active' => (int) $bin['is_active'] );
		}

		$input = array(
			'kind'      => self::text( $request, 'kind' ),
			'reason'    => self::text( $request, 'reason' ),
			'article_id' => $article_id,
			'color'     => self::text( $request, 'color' ),
			'qty'       => self::text( $request, 'qty' ),
			'note'      => self::text( $request, 'note' ),
			'bin_id'    => (int) $request->get_param( 'bin_id' ),
			'to_bin_id' => (int) $request->get_param( 'to_bin_id' ),
		);

		$check = self::validate_movement(
			$input,
			array(
				'article' => $article_id ? \Lindenweg16\Modules\Betrieb\ArticleRepository::find( $article_id ) : null,
				'colors'  => self::colors(),
				'bins'    => $bins,
			)
		);

		if ( $check['errors'] ) {
			return self::fail( implode( ' ', $check['errors'] ) );
		}

		$data    = $check['data'];
		$before  = self::stock_of( $article_id, $data['common']['color'] );
		$codes   = array();
		foreach ( $bins as $id => $bin ) {
			$codes[ $id ] = $bin['code'];
		}
		$warning = self::warnings( $data['lines'], $before['lager'], $before['bins'], $codes );

		$ids = \Lindenweg16\Modules\Betrieb\StockRepository::book_many( $data['lines'], $data['common'] );

		if ( count( array_filter( $ids ) ) !== count( $ids ) ) {
			return self::fail( __( 'Die Buchung konnte nicht gespeichert werden. Bitte die Liste prüfen, ob etwas davon schon angekommen ist, und es dann noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		self::remember_done( $ref );

		$labels = self::kinds();

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'message'  => sprintf(
					/* translators: %s: Art der Buchung. */
					__( '%s gebucht.', 'lindenweg16' ),
					$labels[ $data['kind'] ]
				),
				'warnings' => $warning,
				'ids'      => $ids,
			),
			201
		);
	}

	/**
	 * Kommission: ausliefern, abrechnen oder zurücknehmen.
	 *
	 * Geprüft und gebucht über dieselben Klassen wie auf der Kontaktseite
	 * (CommissionBatch, CommissionRepository) – eine Regel an einer Stelle.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function kommission( WP_REST_Request $request ) {
		$ref = self::client_ref( $request->get_param( 'client_ref' ) );

		if ( self::already_done( $ref ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true, 'message' => __( 'Das war schon gebucht.', 'lindenweg16' ) ) );
		}

		$contact_id = (int) $request->get_param( 'contact_id' );
		$type       = sanitize_key( (string) $request->get_param( 'movement_type' ) );
		$color      = self::text( $request, 'color' );

		if ( $contact_id && ! \Lindenweg16\Modules\Betrieb\ContactRepository::find( $contact_id ) ) {
			return self::fail( __( 'Diesen Händler gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ) );
		}

		if ( ! array_key_exists( $color, self::colors() ) ) {
			return self::fail( __( 'Bitte eine Farbe wählen, die das Lager kennt.', 'lindenweg16' ) );
		}

		$article_id = (int) $request->get_param( 'article_id' );
		if ( ! $article_id || ! \Lindenweg16\Modules\Betrieb\ArticleRepository::find( $article_id ) ) {
			return self::fail( __( 'Bitte einen Artikel wählen.', 'lindenweg16' ) );
		}

		$result = \Lindenweg16\Modules\Betrieb\CommissionBatch::validate(
			array( array( 'article_id' => $article_id, 'color' => $color, 'qty' => self::text( $request, 'qty' ) ) ),
			$type,
			$contact_id
		);

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		$message = \Lindenweg16\Modules\Betrieb\CommissionRepository::book_batch( $result['lines'], $type, $contact_id );

		self::remember_done( $ref );

		return new WP_REST_Response( array( 'ok' => true, 'message' => $message ), 201 );
	}

	/**
	 * Eine Bewegung stornieren – durch Gegenbuchung, nie durch Löschen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function storno( WP_REST_Request $request ) {
		if ( ! self::is_lead() ) {
			return self::not_lead();
		}

		global $wpdb;

		$id     = (int) $request->get_param( 'movement_id' );
		$reason = self::text( $request, 'reason' );
		$table  = \Lindenweg16\Modules\Betrieb\StockRepository::table();
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT reverses_id FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		// Eine Gegenbuchung zu stornieren hieße, den Storno rückgängig zu
		// machen – das geht nur mit einer neuen Buchung, nicht hier.
		if ( $row && ! empty( $row['reverses_id'] ) ) {
			return self::fail( __( 'Das ist selbst schon eine Storno-Gegenbuchung. Wenn der Storno falsch war, bitte neu buchen.', 'lindenweg16' ) );
		}

		$result = \Lindenweg16\Modules\Betrieb\StockRepository::void( $id, $reason );

		return new WP_REST_Response( array( 'ok' => (bool) $result['success'], 'message' => $result['message'] ), $result['success'] ? 200 : 422 );
	}

	/**
	 * Sollbestand setzen oder entfernen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function soll( WP_REST_Request $request ) {
		if ( ! self::is_lead() ) {
			return self::not_lead();
		}

		$article_id = (int) $request->get_param( 'article_id' );
		$check      = self::validate_target(
			array(
				'article_id' => $article_id,
				'color'      => self::text( $request, 'color' ),
				'target_qty' => self::text( $request, 'target_qty' ),
				'remove'     => (bool) $request->get_param( 'remove' ),
			),
			array(
				'article' => $article_id ? \Lindenweg16\Modules\Betrieb\ArticleRepository::find( $article_id ) : null,
				'colors'  => self::colors(),
			)
		);

		if ( $check['errors'] ) {
			return self::fail( implode( ' ', $check['errors'] ) );
		}

		$data = $check['data'];

		if ( $data['remove'] ) {
			\Lindenweg16\Modules\Betrieb\StockRepository::remove_target( $article_id, $data['color'] );

			return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Sollbestand entfernt.', 'lindenweg16' ) ) );
		}

		\Lindenweg16\Modules\Betrieb\StockRepository::set_target( $article_id, $data['color'], $data['qty'] );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Sollbestand gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Bestand einer Komponente oder eines Kartons korrigieren.
	 *
	 * Diese Bestände sind ein Feld, kein Bewegungskonto: Die Korrektur landet
	 * mit Begründung sowie Stand vorher und nachher im Änderungsprotokoll.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function teil( WP_REST_Request $request ) {
		$ref = self::client_ref( $request->get_param( 'client_ref' ) );

		if ( self::already_done( $ref ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true, 'message' => __( 'Das war schon gebucht.', 'lindenweg16' ) ) );
		}

		$check = self::validate_part(
			array(
				'part_type' => self::text( $request, 'part_type' ),
				'part_id'   => (int) $request->get_param( 'part_id' ),
				'qty'       => self::text( $request, 'qty' ),
				'note'      => self::text( $request, 'note' ),
			)
		);

		if ( $check['errors'] ) {
			return self::fail( implode( ' ', $check['errors'] ) );
		}

		$d  = $check['data'];
		$ok = 'supply' === $d['type']
			? \Lindenweg16\Modules\Betrieb\SupplyRepository::adjust_stock( $d['id'], $d['qty'], $d['note'] )
			: \Lindenweg16\Modules\Betrieb\ComponentRepository::adjust_stock( $d['id'], $d['qty'], $d['note'] );

		if ( ! $ok ) {
			return self::fail( __( 'Diese Position gibt es nicht mehr. Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		self::remember_done( $ref );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Bestand korrigiert.', 'lindenweg16' ) ), 201 );
	}

	/**
	 * Darf die Person Komponenten oder Verbrauchsmaterial anlegen und ändern?
	 *
	 * @param string $type component oder supply.
	 * @return bool
	 */
	protected static function can_edit_part( $type ) {
		$rules = ActionMap::rules();
		$need  = 'supply' === $type ? $rules['lw16_betrieb_save_supply'] : $rules['lw16_betrieb_save_component'];

		foreach ( $need as $perm ) {
			if ( ! Access::can( $perm ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Komponente oder Karton anlegen beziehungsweise ändern.
	 *
	 * Der Bestand wird nur beim Anlegen gesetzt; danach ändert ihn allein die
	 * Korrektur mit Begründung.
	 *
	 * @param WP_REST_Request $request Anfrage: part_type, part_id (0 = neu) und die Stammdatenfelder.
	 * @return WP_REST_Response
	 */
	public static function teil_speichern( WP_REST_Request $request ) {
		$type = 'supply' === self::text( $request, 'part_type' ) ? 'supply' : 'component';
		$id   = (int) $request->get_param( 'part_id' );

		if ( ! self::can_edit_part( $type ) ) {
			return self::fail( __( 'Dafür fehlt dir die Berechtigung.', 'lindenweg16' ), 403 );
		}

		$input = array();
		foreach ( array( 'code', 'name', 'barcode', 'category', 'supplier', 'pack_qty', 'pack_price_net', 'order_url', 'lead_time_days', 'min_order_qty', 'stock_qty', 'safety_days', 'default_bin_id', 'unit', 'price_per_unit', 'consumption_per_order' ) as $key ) {
			$input[ $key ] = self::text( $request, $key );
		}
		$note          = $request->get_param( 'note' );
		$input['note'] = is_scalar( $note ) ? (string) $note : '';
		$reason        = self::text( $request, 'reason' );

		if ( 'supply' === $type ) {
			$before = $id ? \Lindenweg16\Modules\Betrieb\SupplyRepository::find( $id ) : null;

			if ( $id && ! $before ) {
				return self::fail( __( 'Diese Position gibt es nicht mehr. Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
			}

			if ( $before ) {
				$input['stock_qty'] = $before['stock_qty'];
			}

			$input['supply_id'] = $id;
			$result             = \Lindenweg16\Modules\Betrieb\SupplyRepository::save( $input );

			if ( $result['errors'] ) {
				return self::fail( implode( ' ', $result['errors'] ) );
			}

			return new WP_REST_Response( array( 'ok' => true, 'message' => $id ? __( 'Karton gespeichert.', 'lindenweg16' ) : __( 'Karton angelegt.', 'lindenweg16' ) ), $id ? 200 : 201 );
		}

		$result = \Lindenweg16\Modules\Betrieb\ComponentRepository::validate( $input, $id ? $id : null );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		if ( $id ) {
			if ( ! \Lindenweg16\Modules\Betrieb\ComponentRepository::find( $id ) ) {
				return self::fail( __( 'Diese Position gibt es nicht mehr. Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
			}

			unset( $result['data']['stock_qty'] );
			\Lindenweg16\Modules\Betrieb\ComponentRepository::update( $id, $result['data'], $reason );

			return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Kleinteil gespeichert.', 'lindenweg16' ) ) );
		}

		if ( ! \Lindenweg16\Modules\Betrieb\ComponentRepository::insert( $result['data'] ) ) {
			return self::fail( __( 'Kleinteil konnte nicht angelegt werden.', 'lindenweg16' ), 500 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Kleinteil angelegt.', 'lindenweg16' ) ), 201 );
	}

	/* ---------- Lagerplätze ---------- */

	/**
	 * Lagerplätze mit Inhalt, Etikett-Link und Arten.
	 *
	 * @return WP_REST_Response
	 */
	public static function plaetze() {
		$bins     = \Lindenweg16\Modules\Betrieb\BinRepository::all();
		$balances = \Lindenweg16\Modules\Betrieb\BinRepository::balances( \Lindenweg16\Modules\Betrieb\StockRepository::all() );
		$articles = \Lindenweg16\Modules\Betrieb\ArticleRepository::all_by_id();
		$labels   = Access::can( 'verwaltung.etiketten' );
		$out      = array();

		foreach ( $bins as $bin ) {
			$content = array();
			$pieces  = 0.0;

			foreach ( \Lindenweg16\Modules\Betrieb\BinRepository::stock_of( $balances, (int) $bin['id'] ) as $article_id => $colors ) {
				foreach ( $colors as $color => $qty ) {
					$content[] = array(
						'article_id'  => (int) $article_id,
						'sku'         => isset( $articles[ $article_id ] ) ? (string) $articles[ $article_id ]['sku'] : '?',
						'name'        => isset( $articles[ $article_id ] ) ? (string) $articles[ $article_id ]['name'] : '',
						'color_label' => \Lindenweg16\Modules\Betrieb\ColorRegistry::label( $color ),
						'qty'         => round( (float) $qty, 3 ),
					);
					$pieces += (float) $qty;
				}
			}

			$out[] = array(
				'id'         => (int) $bin['id'],
				'code'       => (string) $bin['code'],
				'name'       => (string) $bin['name'],
				'kind'       => (string) $bin['kind'],
				'note'       => (string) $bin['note'],
				'sort_order' => (int) $bin['sort_order'],
				'active'     => (bool) $bin['is_active'],
				'content'    => $content,
				'pieces'     => round( $pieces, 3 ),
				'label_url'  => $labels ? \Lindenweg16\Modules\Betrieb\Admin\BinsPage::label_link( 'bins', $bin['code'] ) : '',
			);
		}

		$sheet = '';
		if ( $labels ) {
			$sheet = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'lw16_betrieb_labels',
						'was[]'  => 'bins',
						'format' => \Lindenweg16\Modules\Betrieb\LabelSheet::DEFAULT_ROLL,
					),
					admin_url( 'admin-post.php' )
				),
				'lw16_betrieb_export'
			);
		}

		return new WP_REST_Response(
			array(
				'bins'      => $out,
				'kinds'     => array_values( \Lindenweg16\Modules\Betrieb\BinRepository::KINDS ),
				'can_edit'  => self::is_lead(),
				'sheet_url' => $sheet,
			)
		);
	}

	/**
	 * Lagerplatz anlegen oder ändern (auch sperren und freigeben).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function platz_speichern( WP_REST_Request $request ) {
		if ( ! self::is_lead() ) {
			return self::not_lead();
		}

		$repo   = '\Lindenweg16\Modules\Betrieb\BinRepository';
		$id     = (int) $request->get_param( 'id' );
		$reason = self::text( $request, 'reason' );
		$input  = self::bin_input(
			array(
				'code'       => self::text( $request, 'code' ),
				'name'       => self::text( $request, 'name' ),
				'kind'       => self::text( $request, 'kind' ),
				'note'       => (string) $request->get_param( 'note' ),
				'sort_order' => (int) $request->get_param( 'sort_order' ),
				'is_active'  => (bool) $request->get_param( 'active' ),
			)
		);

		if ( $id && ! $repo::find( $id ) ) {
			return self::fail( __( 'Diesen Lagerplatz gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$result = $repo::validate( $input, $id ? $id : null );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		if ( $id ) {
			$before  = $repo::find( $id );
			$warning = '';

			if ( ! empty( $before['is_active'] ) && empty( $result['data']['is_active'] ) ) {
				$balances = $repo::balances( \Lindenweg16\Modules\Betrieb\StockRepository::all() );
				$lines    = array();

				foreach ( $repo::stock_of( $balances, $id ) as $colors ) {
					foreach ( $colors as $qty ) {
						$lines[] = array( 'qty' => $qty );
					}
				}

				$warning = self::lock_warning( $lines );
			}

			if ( ! \Lindenweg16\Modules\Betrieb\ComponentRepository::update( $id, $result['data'], $reason ) ) {
				return self::fail( __( 'Der Lagerplatz konnte nicht gespeichert werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
			}

			return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Lagerplatz gespeichert.', 'lindenweg16' ), 'warnings' => $warning ? array( $warning ) : array() ) );
		}

		if ( ! \Lindenweg16\Modules\Betrieb\ComponentRepository::insert( $result['data'] ) ) {
			return self::fail( __( 'Der Lagerplatz konnte nicht angelegt werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Lagerplatz angelegt. Das Etikett nicht vergessen.', 'lindenweg16' ) ), 201 );
	}

	/* ---------- Fertigungsaufträge (Reiter der Seite Lagerplätze) ---------- */

	/**
	 * Fertigungs- und Druckaufträge.
	 *
	 * @return WP_REST_Response
	 */
	public static function fertigung() {
		$wo       = '\Lindenweg16\Modules\Betrieb\WorkOrderRepository';
		$articles = \Lindenweg16\Modules\Betrieb\ArticleRepository::all_by_id();
		$kinds    = $wo::kinds();
		$now      = current_time( 'mysql', true );
		$out      = array();

		$labels = array(
			'offen'     => __( 'offen', 'lindenweg16' ),
			'laufend'   => __( 'läuft', 'lindenweg16' ),
			'erledigt'  => __( 'erledigt', 'lindenweg16' ),
			'storniert' => __( 'storniert', 'lindenweg16' ),
		);

		$components = array();
		foreach ( \Lindenweg16\Modules\Betrieb\ComponentRepository::all() as $component ) {
			$components[ (int) $component['id'] ] = $component;
		}

		foreach ( $wo::all() as $order ) {
			$article = isset( $articles[ (int) $order['article_id'] ] ) ? $articles[ (int) $order['article_id'] ] : null;
			$open    = in_array( $order['status'], $wo::OPEN_STATES, true );
			$seconds = $wo::elapsed( $order, $now );
			$parts   = array();

			foreach ( $wo::material_need( \Lindenweg16\Modules\Betrieb\BomRepository::for_article( (int) $order['article_id'] ), (float) $order['qty_planned'] ) as $need ) {
				if ( 'komponente' === $need['child_type'] ) {
					$name = isset( $components[ $need['child_id'] ] ) ? $components[ $need['child_id'] ]['code'] : '?';
				} else {
					$name = isset( $articles[ $need['child_id'] ] ) ? $articles[ $need['child_id'] ]['sku'] : '?';
				}
				$parts[] = number_format_i18n( $need['qty'], 0 ) . '× ' . $name;
			}

			$picklist = ! empty( $order['picklist_id'] ) ? \Lindenweg16\Modules\Betrieb\PicklistRepository::find( (int) $order['picklist_id'] ) : null;

			$out[] = array(
				'id'          => (int) $order['id'],
				'code'        => (string) $order['code'],
				'kind'        => (string) $order['kind'],
				'kind_label'  => isset( $kinds[ $order['kind'] ] ) ? $kinds[ $order['kind'] ] : (string) $order['kind'],
				'article'     => $article ? $article['sku'] . ' – ' . $article['name'] : '?',
				'color_label' => \Lindenweg16\Modules\Betrieb\ColorRegistry::label( $order['color'] ),
				'planned'     => (float) $order['qty_planned'],
				'done'        => (float) $order['qty_done'],
				'status'      => (string) $order['status'],
				'status_text' => isset( $labels[ $order['status'] ] ) ? $labels[ $order['status'] ] : (string) $order['status'],
				'open'        => $open,
				'running'     => $wo::is_running( $order ),
				'time'        => $seconds > 0 ? $wo::format_duration( $seconds ) : '',
				'material'    => $parts,
				'picklist'    => $picklist ? (string) $picklist['code'] : '',
			);
		}

		$options = array();
		foreach ( \Lindenweg16\Modules\Betrieb\ArticleRepository::selectable() as $article ) {
			$fits = array();
			foreach ( array( 'montage', 'druck' ) as $kind ) {
				if ( $wo::fits_kind( $article, $kind ) ) {
					$fits[] = $kind;
				}
			}
			if ( $fits ) {
				$options[] = array( 'id' => (int) $article['id'], 'label' => $article['sku'] . ' – ' . $article['name'], 'kinds' => $fits );
			}
		}

		$colors = array();
		foreach ( \Lindenweg16\Modules\Betrieb\ColorRegistry::options() as $slug => $label ) {
			$colors[] = array( 'slug' => (string) $slug, 'label' => (string) $label );
		}

		return new WP_REST_Response(
			array(
				'orders'   => $out,
				'kinds'    => $kinds,
				'articles' => $options,
				'colors'   => $colors,
				'can_make' => self::is_lead(),
			)
		);
	}

	/**
	 * Auftrag anlegen und Pickliste dazu erzeugen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function fertigung_anlegen( WP_REST_Request $request ) {
		if ( ! self::is_lead() ) {
			return self::not_lead();
		}

		$wo     = '\Lindenweg16\Modules\Betrieb\WorkOrderRepository';
		$result = $wo::validate(
			array(
				'kind'        => self::text( $request, 'kind' ),
				'article_id'  => (int) $request->get_param( 'article_id' ),
				'qty_planned' => self::text( $request, 'qty_planned' ),
				'color'       => self::text( $request, 'color' ),
			)
		);

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		$id = $wo::insert( $result['data'] );

		if ( ! $id ) {
			return self::fail( __( 'Der Auftrag konnte nicht angelegt werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		$order       = $wo::find( $id );
		$picklist_id = \Lindenweg16\Modules\Betrieb\PicklistRepository::from_work_order(
			$order,
			\Lindenweg16\Modules\Betrieb\BinRepository::article_hints(),
			\Lindenweg16\Modules\Betrieb\BinRepository::component_hints()
		);

		if ( $picklist_id ) {
			$wo::attach_picklist( $id, $picklist_id );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %s: Auftragscode. */
					'druck' === $order['kind']
						? __( 'Druckauftrag %s angelegt. Gebucht wird er im Druckjournal.', 'lindenweg16' )
						: __( 'Fertigungsauftrag %s angelegt. Die Materialliste steht am Handy unter „Picken“ bereit.', 'lindenweg16' ),
					$order['code']
				),
			),
			201
		);
	}

	/**
	 * Was eine Pickliste aus den offenen Bestellungen enthielte.
	 *
	 * @return WP_REST_Response
	 */
	public static function pickliste_vorschau() {
		$lines  = \Lindenweg16\Modules\Betrieb\WooIntegration::open_order_lines();
		$orders = array();
		$qty    = 0.0;

		foreach ( $lines as $line ) {
			$orders[ (int) $line['wc_order_id'] ] = true;
			$qty                                  += (float) $line['qty'];
		}

		$open = 0;
		foreach ( \Lindenweg16\Modules\Betrieb\PicklistRepository::all( 'offen' ) as $list ) {
			if ( 'bestellungen' === $list['source'] ) {
				++$open;
			}
		}

		return new WP_REST_Response(
			array(
				'orders'     => count( $orders ),
				'lines'      => count( $lines ),
				'qty'        => $qty,
				'open_lists' => $open,
			)
		);
	}

	/**
	 * Pickliste aus allen offenen Bestellungen erzeugen.
	 *
	 * @return WP_REST_Response
	 */
	public static function pickliste_erzeugen() {
		$repo  = '\Lindenweg16\Modules\Betrieb\PicklistRepository';
		$lines = \Lindenweg16\Modules\Betrieb\WooIntegration::open_order_lines();

		if ( ! $lines ) {
			return self::fail( __( 'Keine offenen Bestellungen – es gibt nichts zu picken.', 'lindenweg16' ), 422 );
		}

		$rows = $repo::condense(
			$lines,
			\Lindenweg16\Modules\Betrieb\BinRepository::article_hints(),
			\Lindenweg16\Modules\Betrieb\BinRepository::component_hints()
		);
		$id   = $repo::create( $rows );

		if ( ! $id ) {
			return self::fail( __( 'Die Pickliste konnte nicht angelegt werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		$list = $repo::find( $id );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'id'      => (int) $id,
				'code'    => $list ? (string) $list['code'] : '',
				'message' => sprintf(
					/* translators: %d: Anzahl Positionen. */
					__( 'Pickliste mit %d Positionen angelegt. Sie steht jetzt unter „Picklisten“ bereit.', 'lindenweg16' ),
					count( $rows )
				),
			),
			201
		);
	}

	/**
	 * Uhr an einem Auftrag starten oder anhalten.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function fertigung_uhr( WP_REST_Request $request ) {
		$wo     = '\Lindenweg16\Modules\Betrieb\WorkOrderRepository';
		$id     = (int) $request->get_param( 'id' );
		$result = $request->get_param( 'pause' ) ? $wo::pause( $id ) : $wo::start( $id );

		return new WP_REST_Response( array( 'ok' => (bool) $result['success'], 'message' => $result['message'] ), $result['success'] ? 200 : 422 );
	}

	/**
	 * Auftrag fertigmelden oder stornieren.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function fertigung_fertig( WP_REST_Request $request ) {
		$wo     = '\Lindenweg16\Modules\Betrieb\WorkOrderRepository';
		$id     = (int) $request->get_param( 'id' );
		$cancel = (bool) $request->get_param( 'cancel' );

		if ( $cancel ) {
			$result = $wo::cancel( $id, self::text( $request, 'reason' ) );
		} else {
			$qty = self::parse_qty( self::text( $request, 'qty_done' ) );

			if ( null === $qty || $qty < 0 || $qty > self::MAX_QTY ) {
				return self::fail( __( 'Bitte die fertige Stückzahl als Zahl eintragen, zum Beispiel 12.', 'lindenweg16' ) );
			}

			$result = $wo::complete( $id, $qty );
		}

		return new WP_REST_Response( array( 'ok' => (bool) $result['success'], 'message' => $result['message'] ), $result['success'] ? 200 : 422 );
	}

	/* ---------- Bedarf ---------- */

	/**
	 * Was fehlt, woher der Bedarf kommt – gerechnet vom DemandPlanner.
	 *
	 * Keine zweite Planlogik: Die Ansicht liest `DemandPlanner::plan()` und
	 * zeigt das Ergebnis; Aufträge entstehen im Workflow, Bestellungen im Einkauf.
	 *
	 * @param WP_REST_Request $request Anfrage (nur_fest).
	 * @return WP_REST_Response
	 */
	public static function bedarf( WP_REST_Request $request ) {
		$planner   = '\Lindenweg16\Modules\Betrieb\DemandPlanner';
		$with_soft = ! $request->get_param( 'nur_fest' );
		$plan      = $planner::plan( $planner::collect( $with_soft ), $planner::context() );
		$actions   = $planner::action_labels();
		$sources   = $planner::source_labels();
		$todo      = array();
		$covered   = array();

		foreach ( $plan['articles'] as $row ) {
			$entry = array(
				'article_id' => (int) $row['article_id'],
				'sku'        => (string) $row['sku'],
				'name'       => (string) $row['name'],
				'action'     => (string) $row['action'],
				'action_text' => isset( $actions[ $row['action'] ] ) ? $actions[ $row['action'] ] : (string) $row['action'],
				'target'     => self::demand_target( (string) $row['action'] ),
				'gross'      => (float) $row['gross'],
				'stock'      => (float) $row['stock'],
				'in_work'    => (float) $row['in_work'],
				'net'        => (float) $row['net'],
				'why'        => self::demand_reasons( (array) $row['sources'], $sources ),
			);

			if ( $row['net'] > 0.0005 ) {
				$todo[] = $entry;
			} elseif ( $row['gross'] > 0 ) {
				$covered[] = $entry;
			}
		}

		$parts = array();
		foreach ( $plan['components'] as $row ) {
			$parts[] = array(
				'code'  => (string) $row['code'],
				'name'  => (string) $row['name'],
				'gross' => (float) $row['gross'],
				'stock' => (float) $row['stock'],
				'net'   => (float) $row['net'],
			);
		}

		return new WP_REST_Response(
			array(
				'todo'       => $todo,
				'covered'    => $covered,
				'components' => $parts,
				'warnings'   => array_map( 'strval', (array) $plan['warnings'] ),
				'with_soft'  => $with_soft,
			)
		);
	}
}
