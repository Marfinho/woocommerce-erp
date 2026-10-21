<?php
/**
 * Ablauf einer Platte: Zustände, Übergänge, Zeiten, Ist-Meldung.
 *
 * Eine „Platte“ ist die Arbeitseinheit der Werkstatt: ein Druckbett mit
 * einem oder mehreren Artikeln, einer Datei und einem Etikett am Drucker.
 * Jeder Schritt hat genau eine Haupt-Aktion; Zeit und Bestand ergeben sich
 * aus den Übergängen, nicht aus zusätzlichen Eingaben.
 *
 * Reine Logik ohne Datenbank und ohne WordPress, damit die Regeln prüfbar
 * sind. Zeitpunkte sind MySQL-Zeitstempel in GMT („Y-m-d H:i:s“).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Zustandsmaschine und Rechenregeln.
 */
class RunFlow {

	const FREIGEGEBEN = 'freigegeben';
	const RUESTEN     = 'ruesten';
	const DRUCKT      = 'druckt';
	const ZU_MELDEN   = 'zu_melden';
	const ERLEDIGT    = 'erledigt';
	const ABGEBROCHEN = 'abgebrochen';

	/**
	 * Zustände mit Beschriftung.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			self::FREIGEGEBEN => __( 'Freigegeben', 'lindenweg16' ),
			self::RUESTEN     => __( 'Rüsten', 'lindenweg16' ),
			self::DRUCKT      => __( 'Druckt', 'lindenweg16' ),
			self::ZU_MELDEN   => __( 'Ist melden', 'lindenweg16' ),
			self::ERLEDIGT    => __( 'Erledigt', 'lindenweg16' ),
			self::ABGEBROCHEN => __( 'Abgebrochen', 'lindenweg16' ),
		);
	}

	/**
	 * Erlaubte Übergänge: Aktion => [von => nach].
	 *
	 * „druckstart“ darf das Rüsten überspringen (Rüstzeit 0), „melden“ das
	 * Druckende: Wer am Handy direkt „Ist melden“ drückt, hat den Druck
	 * offensichtlich beendet.
	 *
	 * @return array
	 */
	public static function transitions() {
		return array(
			'ruesten'    => array( self::FREIGEGEBEN => self::RUESTEN ),
			'druckstart' => array(
				self::RUESTEN     => self::DRUCKT,
				self::FREIGEGEBEN => self::DRUCKT,
			),
			'druckende'  => array( self::DRUCKT => self::ZU_MELDEN ),
			'melden'     => array(
				self::ZU_MELDEN => self::ERLEDIGT,
				self::DRUCKT    => self::ERLEDIGT,
			),
			'abbrechen'  => array(
				self::FREIGEGEBEN => self::ABGEBROCHEN,
				self::RUESTEN     => self::ABGEBROCHEN,
				self::DRUCKT      => self::ABGEBROCHEN,
				self::ZU_MELDEN   => self::ABGEBROCHEN,
			),
		);
	}

	/**
	 * Ziel einer Aktion in einem Zustand.
	 *
	 * Wiederholt jemand eine Aktion (zweimal getippt, Warteschlange ohne
	 * Verbindung sendet doppelt), ist das kein Fehler: Steht die Platte
	 * schon im Ziel, meldet das Ergebnis „noop“.
	 *
	 * @param string $status Aktueller Zustand.
	 * @param string $action Aktion.
	 * @return array {ok, to, noop}
	 */
	public static function apply( $status, $action ) {
		$map = self::transitions();

		if ( ! isset( $map[ $action ] ) ) {
			return array( 'ok' => false, 'to' => $status, 'noop' => false );
		}

		if ( isset( $map[ $action ][ $status ] ) ) {
			return array( 'ok' => true, 'to' => $map[ $action ][ $status ], 'noop' => false );
		}

		// Schon im Ziel: wiederholte Aktion.
		$targets = array_unique( array_values( $map[ $action ] ) );
		if ( in_array( $status, $targets, true ) ) {
			return array( 'ok' => true, 'to' => $status, 'noop' => true );
		}

		// „melden“ nach „zu_melden→erledigt“: auch der Zustand dahinter zählt.
		return array( 'ok' => false, 'to' => $status, 'noop' => false );
	}

	/**
	 * Dateiname auf den Kern bringen, damit Platte und Druckermeldung vergleichbar sind.
	 *
	 * Der Drucker meldet den Projektnamen ohne oder mit Endung; über die
	 * Druck-Warteschlange der Bridge kommt das Präfix „lw16-<Job>-“ dazu.
	 *
	 * @param string $name Dateiname.
	 * @return string Kleingeschrieben, ohne Pfad, Endung und Präfix.
	 */
	public static function normalize_file( $name ) {
		$name = strtolower( trim( basename( str_replace( '\\', '/', (string) $name ) ) ) );
		$name = preg_replace( '/^lw16-\d+-/', '', $name );

		do {
			$before = $name;
			$name   = preg_replace( '/\.(gcode|3mf)$/', '', $name );
		} while ( $name !== $before );

		return $name;
	}

	/**
	 * Gehört der gemeldete Dateiname des Druckers zur Plattendatei?
	 *
	 * Gleich oder mit angehängtem Suffix (Bambu Studio ergänzt gern
	 * „_plate_1“) – aber nur an einer Wortgrenze, sonst träfe „platte-a-1“
	 * auch „platte-a-10“.
	 *
	 * @param string $reported Name laut Drucker.
	 * @param string $plate    Dateiname der Platte.
	 * @return bool
	 */
	public static function file_matches( $reported, $plate ) {
		$reported = self::normalize_file( $reported );
		$plate    = self::normalize_file( $plate );

		if ( '' === $reported || '' === $plate ) {
			return false;
		}

		if ( $reported === $plate ) {
			return true;
		}

		return 0 === strpos( $reported, $plate ) && ! ctype_alnum( $reported[ strlen( $plate ) ] );
	}

	/**
	 * Spalte im Workflow für einen Zustand.
	 *
	 * @param string $status Zustand.
	 * @return string freigegeben, im_druck, zu_melden, erledigt oder leer.
	 */
	public static function column( $status ) {
		switch ( $status ) {
			case self::FREIGEGEBEN:
				return 'freigegeben';
			case self::RUESTEN:
			case self::DRUCKT:
				return 'im_druck';
			case self::ZU_MELDEN:
				return 'zu_melden';
			case self::ERLEDIGT:
				return 'erledigt';
		}

		return '';
	}

	/**
	 * Beschriftung der Haupt-Aktion in einem Zustand.
	 *
	 * @param string $status Zustand.
	 * @return array {action, label} oder leer.
	 */
	public static function primary( $status ) {
		switch ( $status ) {
			case self::FREIGEGEBEN:
				return array( 'action' => 'ruesten', 'label' => __( 'Rüsten starten', 'lindenweg16' ) );
			case self::RUESTEN:
				return array( 'action' => 'druckstart', 'label' => __( 'Druck gestartet', 'lindenweg16' ) );
			case self::DRUCKT:
				return array( 'action' => 'druckende', 'label' => __( 'Druck fertig', 'lindenweg16' ) );
			case self::ZU_MELDEN:
				return array( 'action' => 'melden', 'label' => __( 'Ist-Menge melden', 'lindenweg16' ) );
		}

		return array();
	}

	/**
	 * Stunden zwischen zwei Zeitpunkten (GMT), nie negativ.
	 *
	 * @param string|null $from Beginn.
	 * @param string|null $to   Ende.
	 * @return float Drei Nachkommastellen.
	 */
	public static function hours_between( $from, $to ) {
		if ( ! $from || ! $to ) {
			return 0.0;
		}

		$a = strtotime( $from . ' UTC' );
		$b = strtotime( $to . ' UTC' );

		if ( ! $a || ! $b || $b <= $a ) {
			return 0.0;
		}

		return round( ( $b - $a ) / 3600, 3 );
	}

	/**
	 * Eine Zeit anteilig nach Mengen auf Positionen verteilen.
	 *
	 * Die Summe der Teile ist immer genau die Gesamtzeit: Der Rest der
	 * Rundung landet bei der ersten Position.
	 *
	 * @param float $hours Gesamtzeit.
	 * @param array $qtys  Mengen je Position.
	 * @return float[] Gleiche Schlüssel wie $qtys.
	 */
	public static function split_hours( $hours, array $qtys ) {
		$total = array_sum( array_map( 'floatval', $qtys ) );
		$out   = array();

		if ( $total <= 0 || ! $qtys ) {
			return array_fill_keys( array_keys( $qtys ), 0.0 );
		}

		$given = 0.0;
		foreach ( $qtys as $key => $qty ) {
			$part         = round( (float) $hours * ( (float) $qty / $total ), 3 );
			$out[ $key ]  = $part;
			$given       += $part;
		}

		$first         = array_key_first( $qtys );
		$out[ $first ] = round( $out[ $first ] + ( (float) $hours - $given ), 3 );

		return $out;
	}

	/**
	 * Ist-Meldung prüfen.
	 *
	 * Pro Position: gut ≥ 0, gut ≤ Soll; was fehlt, ist Ausschuss. Fehlt
	 * etwas, ist die Ursache Pflicht – dieselbe Regel wie im Druckjournal,
	 * sonst ließe sich die Ausschussquote später nicht auswerten.
	 *
	 * @param array  $items   Positionen der Platte: article_id, color, qty.
	 * @param array  $reported Gemeldet: Liste von {article_id, color, ok}.
	 * @param string $reason  Fehlerursache.
	 * @param array  $allowed Erlaubte Ursachen.
	 * @return array {errors, items} – items: article_id, color, qty, ok, scrap.
	 */
	public static function validate_report( array $items, array $reported, $reason, array $allowed ) {
		$errors = array();
		$out    = array();

		$by_key = array();
		foreach ( $reported as $row ) {
			$key            = (int) ( isset( $row['article_id'] ) ? $row['article_id'] : 0 ) . '|' . (string) ( isset( $row['color'] ) ? $row['color'] : '' );
			$by_key[ $key ] = isset( $row['ok'] ) ? (float) $row['ok'] : 0.0;
		}

		$missing = false;

		foreach ( $items as $item ) {
			$key = (int) $item['article_id'] . '|' . (string) $item['color'];
			$qty = (float) $item['qty'];

			if ( ! array_key_exists( $key, $by_key ) ) {
				$errors[] = __( 'Für jede Position fehlt die Ist-Menge.', 'lindenweg16' );
				continue;
			}

			$ok = $by_key[ $key ];

			if ( $ok < 0 ) {
				$errors[] = __( 'Gute Teile können nicht negativ sein.', 'lindenweg16' );
				continue;
			}

			if ( $ok > $qty ) {
				$errors[] = __( 'Es können nicht mehr Teile gut sein als geplant waren.', 'lindenweg16' );
				continue;
			}

			if ( $ok < $qty ) {
				$missing = true;
			}

			$out[] = array(
				'article_id' => (int) $item['article_id'],
				'color'      => (string) $item['color'],
				'qty'        => $qty,
				'ok'         => $ok,
				'scrap'      => round( $qty - $ok, 3 ),
			);
		}

		if ( $missing && ( ! in_array( $reason, $allowed, true ) || 'keine' === $reason ) ) {
			$errors[] = __( 'Bei Ausschuss ist eine Fehlerursache Pflicht – sonst lässt sich die Ausschussquote später nicht auswerten.', 'lindenweg16' );
		}

		return array( 'errors' => array_values( array_unique( $errors ) ), 'items' => $out );
	}

	/**
	 * Texte für das Etikett einer Platte.
	 *
	 * Das vorhandene Etikett kennt drei Zeilen: Art (klein), Code (groß),
	 * Name (klein). Was davon wie gefüllt wird, bestimmen die Einstellungen.
	 *
	 * @param array $run    Platte: id, items (name, qty, color), printer, est_minutes, order_ref.
	 * @param array $fields Erlaubte Inhalte: platte, artikel, material, drucker, auftrag.
	 * @return array {code, kind, name}
	 */
	public static function label_text( array $run, array $fields ) {
		$has = static function ( $key ) use ( $fields ) {
			return in_array( $key, $fields, true );
		};

		$code = $has( 'platte' ) ? sprintf( /* translators: %d: Plattennummer. */ __( 'Platte %d', 'lindenweg16' ), (int) $run['id'] ) : '#' . (int) $run['id'];

		$kind = array();
		if ( $has( 'drucker' ) ) {
			if ( ! empty( $run['printer'] ) ) {
				$kind[] = (string) $run['printer'];
			}
			if ( ! empty( $run['est_minutes'] ) ) {
				$kind[] = self::duration( (int) $run['est_minutes'] );
			}
		}

		$name = array();
		if ( $has( 'artikel' ) ) {
			foreach ( (array) $run['items'] as $item ) {
				$name[] = self::trim_qty( $item['qty'] ) . '× ' . $item['name'];
			}
		}
		if ( $has( 'material' ) ) {
			$colors = array();
			foreach ( (array) $run['items'] as $item ) {
				if ( '' !== (string) $item['color'] ) {
					$colors[ $item['color'] ] = true;
				}
			}
			if ( $colors ) {
				$name[] = implode( '/', array_keys( $colors ) );
			}
		}
		if ( $has( 'auftrag' ) && ! empty( $run['order_ref'] ) ) {
			$name[] = (string) $run['order_ref'];
		}

		return array(
			'code' => $code,
			'kind' => $kind ? implode( ' · ', $kind ) : __( 'Druckauftrag', 'lindenweg16' ),
			'name' => implode( ' · ', $name ),
		);
	}

	/**
	 * Dauer als „3 h 10“ oder „45 min“.
	 *
	 * @param int $minutes Minuten.
	 * @return string
	 */
	public static function duration( $minutes ) {
		$minutes = max( 0, (int) $minutes );

		if ( $minutes < 60 ) {
			return $minutes . ' min';
		}

		return intdiv( $minutes, 60 ) . ' h ' . str_pad( (string) ( $minutes % 60 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Menge ohne überflüssige Nachkommastellen.
	 *
	 * @param float $qty Menge.
	 * @return string
	 */
	public static function trim_qty( $qty ) {
		$qty = (float) $qty;

		return abs( $qty - round( $qty ) ) < 0.0005 ? (string) (int) round( $qty ) : rtrim( rtrim( number_format( $qty, 3, ',', '' ), '0' ), ',' );
	}
}
