<?php
/**
 * Regeln des Prämienprogramms: Einstellfelder, Prüfung, Aktionen, Punkte je Bestellung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

/**
 * Reine Regeln ohne Datenbank, damit sie sich testen lassen. Gespeichert wird
 * in den Plugin-Einstellungen (Settings) und für Aktionen in einer eigenen
 * Option (LoyaltyCampaigns).
 */
class LoyaltyRules {

	/** Höchster Aktionsfaktor – mehr als zehnfache Punkte ist ein Tippfehler. */
	const FAKTOR_MAX = 10.0;

	/**
	 * Einstellbare Werte. Eine 0 schaltet einen Auslöser aus.
	 *
	 * Je Feld: Gruppe, Beschriftung, Erklärung, Typ (int|float|bool),
	 * Untergrenze, Obergrenze, Vorschlag (für die Oberfläche).
	 *
	 * @return array Schlüssel => Definition.
	 */
	public static function fields() {
		$f = function ( $group, $label, $hint, $type, $min, $max, $suggest ) {
			return compact( 'group', 'label', 'hint', 'type', 'min', 'max', 'suggest' );
		};

		return array(
			'punkte_pro_euro'           => $f( 'grund', __( 'Punkte je Euro Einkauf', 'lindenweg16' ), __( 'Gezählt werden volle Euro des Bestellwerts.', 'lindenweg16' ), 'float', 0, 100, 1 ),
			'punkte_einloesewert'       => $f( 'grund', __( 'Punkte für 1 € Rabatt', 'lindenweg16' ), __( 'So viele Punkte sind an der Kasse einen Euro wert.', 'lindenweg16' ), 'int', 1, 100000, 100 ),
			'punkte_wartezeit_tage'     => $f( 'grund', __( 'Wartezeit bis Bestellpunkte frei sind (Tage)', 'lindenweg16' ), __( 'Schützt vor Punkten aus Bestellungen, die zurückgehen.', 'lindenweg16' ), 'int', 0, 365, 14 ),

			'punkte_registrierung'      => $f( 'ausloeser', __( 'Kundenkonto anlegen', 'lindenweg16' ), __( 'Einmal, sofort verfügbar.', 'lindenweg16' ), 'int', 0, 100000, 50 ),
			'punkte_newsletter'         => $f( 'ausloeser', __( 'Newsletter bestätigen', 'lindenweg16' ), __( 'Einmal je Konto. Wer sich vor der Registrierung anmeldet, bekommt die Punkte mit dem Konto.', 'lindenweg16' ), 'int', 0, 100000, 25 ),
			'punkte_erste_bestellung'   => $f( 'ausloeser', __( 'Erste Bestellung (zusätzlich)', 'lindenweg16' ), __( 'Einmal, frei nach der Wartezeit.', 'lindenweg16' ), 'int', 0, 100000, 50 ),
			'punkte_geburtstag'         => $f( 'ausloeser', __( 'Geburtstag', 'lindenweg16' ), __( 'Einmal im Jahr mit Glückwunsch-Mail. Der Geburtstag steht freiwillig in den Kontodetails und zählt erst 30 Tage nach dem Eintragen.', 'lindenweg16' ), 'int', 0, 100000, 100 ),

			'review_bonus_punkte'       => $f( 'bewertung', __( 'Produktbewertung', 'lindenweg16' ), __( 'Einmal je Produkt, sobald die Bewertung freigeschaltet ist.', 'lindenweg16' ), 'int', 0, 100000, 20 ),
			'punkte_bewertung_foto'     => $f( 'bewertung', __( 'Foto in der Bewertung (zusätzlich)', 'lindenweg16' ), __( 'Für mindestens ein Foto, einmal je Produkt.', 'lindenweg16' ), 'int', 0, 100000, 30 ),
			'review_min_zeichen'        => $f( 'bewertung', __( 'Mindestlänge der Bewertung (Zeichen)', 'lindenweg16' ), __( 'Kürzere Bewertungen bekommen keine Punkte.', 'lindenweg16' ), 'int', 0, 2000, 15 ),
			'review_nur_kaeufer'        => $f( 'bewertung', __( 'Punkte nur für Käufer des Produkts', 'lindenweg16' ), __( 'Verhindert Punkte für Bewertungen ohne Kauf.', 'lindenweg16' ), 'bool', 0, 1, 1 ),
			'review_foto_max'           => $f( 'bewertung', __( 'Fotos je Bewertung (höchstens)', 'lindenweg16' ), __( '0 schaltet das Hochladen ab.', 'lindenweg16' ), 'int', 0, 10, 3 ),

			'punkte_empfehlung_werber'  => $f( 'werben', __( 'Punkte für den Werber', 'lindenweg16' ), __( 'Zusätzlich zum Guthaben, sobald die Empfehlung gutgeschrieben ist.', 'lindenweg16' ), 'int', 0, 100000, 100 ),
			'punkte_empfehlung_neukunde' => $f( 'werben', __( 'Willkommenspunkte für den Geworbenen', 'lindenweg16' ), __( 'Bei der ersten Bestellung über einen Empfehlungslink, frei nach der Wartezeit.', 'lindenweg16' ), 'int', 0, 100000, 50 ),
		);
	}

	/**
	 * Gruppen der Felder in Anzeigereihenfolge.
	 *
	 * @return array Kennung => Überschrift.
	 */
	public static function groups() {
		return array(
			'grund'     => __( 'Grundwerte', 'lindenweg16' ),
			'ausloeser' => __( 'Punkte für Aktionen der Kunden', 'lindenweg16' ),
			'bewertung' => __( 'Bewertungen', 'lindenweg16' ),
			'werben'    => __( 'Freunde werben', 'lindenweg16' ),
		);
	}

	/**
	 * Eingaben prüfen und in Grenzen bringen.
	 *
	 * @param array $input Rohwerte (nur bekannte Schlüssel werden übernommen).
	 * @return array{values:array,errors:array}
	 */
	public static function sanitize( array $input ) {
		$values = array();
		$errors = array();

		foreach ( self::fields() as $key => $def ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$raw = $input[ $key ];

			if ( 'bool' === $def['type'] ) {
				$values[ $key ] = ( $raw && 'false' !== $raw ) ? 1 : 0;
				continue;
			}

			$raw = str_replace( ',', '.', trim( (string) $raw ) );
			if ( '' === $raw || ! is_numeric( $raw ) ) {
				/* translators: %s: Feldname */
				$errors[] = sprintf( __( '„%s“ braucht eine Zahl.', 'lindenweg16' ), $def['label'] );
				continue;
			}
			$num = 'int' === $def['type'] ? (int) round( (float) $raw ) : round( (float) $raw, 2 );
			if ( $num < $def['min'] || $num > $def['max'] ) {
				/* translators: 1: Feldname, 2: Untergrenze, 3: Obergrenze */
				$errors[] = sprintf( __( '„%1$s“ muss zwischen %2$s und %3$s liegen.', 'lindenweg16' ), $def['label'], $def['min'], $def['max'] );
				continue;
			}
			$values[ $key ] = $num;
		}

		return array( 'values' => $values, 'errors' => $errors );
	}

	/**
	 * Eine Aktion prüfen.
	 *
	 * @param array $in        Rohwerte: name, von, bis (Y-m-d), faktor, kategorien (int[]).
	 * @param array $known_cat Erlaubte Kategorie-IDs (leer = keine Prüfung).
	 * @return array{campaign:?array,error:string}
	 */
	public static function sanitize_campaign( array $in, array $known_cat = array() ) {
		$name   = trim( (string) ( $in['name'] ?? '' ) );
		$von    = (string) ( $in['von'] ?? '' );
		$bis    = (string) ( $in['bis'] ?? '' );
		$faktor = round( (float) str_replace( ',', '.', (string) ( $in['faktor'] ?? 0 ) ), 2 );
		$cats   = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $in['kategorien'] ?? array() ) ) ) ) );

		if ( '' === $name ) {
			return array( 'campaign' => null, 'error' => __( 'Gib der Aktion einen Namen, den Kunden verstehen – z. B. „Doppelte Punkte im Herbst“.', 'lindenweg16' ) );
		}
		if ( ! self::is_ymd( $von ) || ! self::is_ymd( $bis ) ) {
			return array( 'campaign' => null, 'error' => __( 'Start und Ende brauchen ein gültiges Datum.', 'lindenweg16' ) );
		}
		if ( $bis < $von ) {
			return array( 'campaign' => null, 'error' => __( 'Das Ende liegt vor dem Start.', 'lindenweg16' ) );
		}
		if ( $faktor <= 1 || $faktor > self::FAKTOR_MAX ) {
			/* translators: %s: Höchstfaktor */
			return array( 'campaign' => null, 'error' => sprintf( __( 'Der Faktor muss über 1 und höchstens %s sein – 2 heißt doppelte Punkte.', 'lindenweg16' ), self::FAKTOR_MAX ) );
		}
		if ( $known_cat && array_diff( $cats, $known_cat ) ) {
			return array( 'campaign' => null, 'error' => __( 'Eine der gewählten Kategorien gibt es nicht mehr.', 'lindenweg16' ) );
		}

		return array(
			'campaign' => array(
				'name'       => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80 ) : substr( $name, 0, 80 ),
				'von'        => $von,
				'bis'        => $bis,
				'faktor'     => $faktor,
				'kategorien' => $cats,
			),
			'error'    => '',
		);
	}

	/**
	 * Ist das ein Datum im Format Y-m-d?
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	public static function is_ymd( $s ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $s, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Aktionen, die an einem Tag laufen (Start und Ende eingeschlossen).
	 *
	 * @param array  $campaigns Alle Aktionen.
	 * @param string $ymd       Tag.
	 * @return array
	 */
	public static function active_on( array $campaigns, $ymd ) {
		return array_values(
			array_filter(
				$campaigns,
				function ( $c ) use ( $ymd ) {
					return isset( $c['von'], $c['bis'] ) && $c['von'] <= $ymd && $ymd <= $c['bis'];
				}
			)
		);
	}

	/**
	 * Punkte für eine Bestellung, mit laufenden Aktionen.
	 *
	 * Grundlage ist der Bestellwert. Eine Aktion vervielfacht den Anteil der
	 * Positionen, für die sie gilt (ohne Kategorien: alle). Überschneiden sich
	 * Aktionen, zählt je Position der höchste Faktor – Aktionen stapeln nicht.
	 *
	 * @param float $order_total Bestellwert.
	 * @param array $lines       Positionen: array( 'total' => Betrag, 'cats' => int[] ).
	 * @param float $per_euro    Punkte je Euro.
	 * @param array $campaigns   Am Bestelltag laufende Aktionen.
	 * @return array{points:int,base:int,bonus:int,names:string[]}
	 */
	public static function order_points( $order_total, array $lines, $per_euro, array $campaigns ) {
		$base  = self::points_for_amount( $order_total, $per_euro );
		$extra = 0.0;
		$names = array();

		foreach ( $lines as $line ) {
			$best = 1.0;
			$who  = '';
			foreach ( $campaigns as $c ) {
				$cats = isset( $c['kategorien'] ) ? (array) $c['kategorien'] : array();
				if ( $cats && ! array_intersect( $cats, (array) ( $line['cats'] ?? array() ) ) ) {
					continue;
				}
				if ( (float) $c['faktor'] > $best ) {
					$best = (float) $c['faktor'];
					$who  = (string) $c['name'];
				}
			}
			if ( $best > 1 ) {
				$extra       += max( 0, (float) $line['total'] ) * ( $best - 1 );
				$names[ $who ] = true;
			}
		}

		$total = self::points_for_amount( $order_total + $extra, $per_euro );

		return array(
			'points' => $total,
			'base'   => $base,
			'bonus'  => max( 0, $total - $base ),
			'names'  => array_keys( $names ),
		);
	}

	/**
	 * Punkte für einen Betrag: volle Euro × Faktor (wie Calc::points_for_amount).
	 *
	 * @param float $amount   Betrag.
	 * @param float $per_euro Punkte je Euro.
	 * @return int
	 */
	protected static function points_for_amount( $amount, $per_euro ) {
		return (int) floor( floor( max( 0, (float) $amount ) ) * (float) $per_euro );
	}

	/**
	 * Ist heute der Geburtstag (Monat-Tag)? Wer am 29. Februar Geburtstag hat,
	 * feiert in Nicht-Schaltjahren am 28.
	 *
	 * @param string $birthday Gespeichert als m-d.
	 * @param string $today    Heute als Y-m-d.
	 * @return bool
	 */
	public static function is_birthday( $birthday, $today ) {
		if ( ! preg_match( '/^\d{2}-\d{2}$/', (string) $birthday ) || ! self::is_ymd( $today ) ) {
			return false;
		}
		$md   = substr( $today, 5 );
		$year = (int) substr( $today, 0, 4 );
		if ( '02-29' === $birthday && ! checkdate( 2, 29, $year ) ) {
			return '02-28' === $md;
		}
		return $birthday === $md;
	}
}
