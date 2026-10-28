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
			'einloesen_min_bestellwert' => $f( 'grund', __( 'Mindestbestellwert zum Einlösen (€)', 'lindenweg16' ), __( 'Punkte und Guthaben lassen sich erst ab diesem Warenwert (ohne Steuer und Versand) einlösen.', 'lindenweg16' ), 'float', 0, 100000, 30 ),

			'punkte_registrierung'      => $f( 'ausloeser', __( 'Kundenkonto anlegen', 'lindenweg16' ), __( 'Einmal, sofort verfügbar.', 'lindenweg16' ), 'int', 0, 100000, 50 ),
			'punkte_newsletter'         => $f( 'ausloeser', __( 'Newsletter bestätigen', 'lindenweg16' ), __( 'Einmal je Konto. Wer sich vor der Registrierung anmeldet, bekommt die Punkte mit dem Konto.', 'lindenweg16' ), 'int', 0, 100000, 25 ),
			'punkte_erste_bestellung'   => $f( 'ausloeser', __( 'Erste Bestellung (zusätzlich)', 'lindenweg16' ), __( 'Einmal, frei nach der Wartezeit.', 'lindenweg16' ), 'int', 0, 100000, 50 ),
			'punkte_profil'             => $f( 'ausloeser', __( 'Profil vollständig', 'lindenweg16' ), __( 'Einmal, sobald Name, Rechnungsadresse und Telefon eingetragen sind.', 'lindenweg16' ), 'int', 0, 100000, 30 ),
			'punkte_jahrestag'          => $f( 'ausloeser', __( 'Jahrestag der ersten Bestellung', 'lindenweg16' ), __( 'Jedes Jahr am Tag der ersten Bestellung, mit Mail.', 'lindenweg16' ), 'int', 0, 100000, 50 ),
			'punkte_geburtstag'         => $f( 'ausloeser', __( 'Geburtstag', 'lindenweg16' ), __( 'Einmal im Jahr mit Glückwunsch-Mail. Der Geburtstag steht freiwillig in den Kontodetails und zählt erst 30 Tage nach dem Eintragen.', 'lindenweg16' ), 'int', 0, 100000, 100 ),

			'review_bonus_punkte'       => $f( 'bewertung', __( 'Produktbewertung', 'lindenweg16' ), __( 'Einmal je Produkt, sobald die Bewertung freigeschaltet ist.', 'lindenweg16' ), 'int', 0, 100000, 20 ),
			'punkte_bewertung_foto'     => $f( 'bewertung', __( 'Foto in der Bewertung (zusätzlich)', 'lindenweg16' ), __( 'Für mindestens ein Foto, einmal je Produkt.', 'lindenweg16' ), 'int', 0, 100000, 30 ),
			'review_min_zeichen'        => $f( 'bewertung', __( 'Mindestlänge der Bewertung (Zeichen)', 'lindenweg16' ), __( 'Kürzere Bewertungen bekommen keine Punkte.', 'lindenweg16' ), 'int', 0, 2000, 15 ),
			'review_nur_kaeufer'        => $f( 'bewertung', __( 'Punkte nur für Käufer des Produkts', 'lindenweg16' ), __( 'Verhindert Punkte für Bewertungen ohne Kauf.', 'lindenweg16' ), 'bool', 0, 1, 1 ),
			'review_foto_max'           => $f( 'bewertung', __( 'Fotos je Bewertung (höchstens)', 'lindenweg16' ), __( '0 schaltet das Hochladen ab.', 'lindenweg16' ), 'int', 0, 10, 3 ),

			'punkte_empfehlung_werber'  => $f( 'werben', __( 'Punkte für den Werber', 'lindenweg16' ), __( 'Zusätzlich zum Guthaben, sobald die Empfehlung gutgeschrieben ist.', 'lindenweg16' ), 'int', 0, 100000, 100 ),
			'punkte_empfehlung_neukunde' => $f( 'werben', __( 'Willkommenspunkte für den Geworbenen', 'lindenweg16' ), __( 'Bei der ersten Bestellung über einen Empfehlungslink, frei nach der Wartezeit.', 'lindenweg16' ), 'int', 0, 100000, 50 ),

			'punkte_verfall_monate'     => $f( 'verfall', __( 'Punkte verfallen nach (Monaten ohne Aktivität)', 'lindenweg16' ), __( 'Jede Bestellung, jede Gutschrift und jedes Einlösen setzt die Frist neu. Beim Einschalten beginnt die Frist für alle erst ab heute. 0 = Punkte verfallen nie.', 'lindenweg16' ), 'int', 0, 60, 12 ),
			'punkte_verfall_erinnerung_tage' => $f( 'verfall', __( 'Erinnerung vor dem Verfall (Tage)', 'lindenweg16' ), __( 'Mail an Kunden mit Punkten, so viele Tage vor dem Verfall. 0 = keine Erinnerung.', 'lindenweg16' ), 'int', 0, 180, 30 ),

			'stufen_aktiv'              => $f( 'stufen', __( 'Stufen einschalten', 'lindenweg16' ), __( 'Wer in den letzten 12 Monaten mehr bestellt hat, sammelt schneller. Die Stufen stehen darunter.', 'lindenweg16' ), 'bool', 0, 1, 1 ),
		);
	}

	/**
	 * Darf bei diesem Warenwert eingelöst werden?
	 *
	 * @param float $subtotal Warenwert.
	 * @param float $min      Mindestbestellwert (0 = kein Mindestwert).
	 * @return bool
	 */
	public static function redeem_allowed( $subtotal, $min ) {
		return (float) $min <= 0 || (float) $subtotal >= (float) $min;
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
			'stufen'    => __( 'Stufen', 'lindenweg16' ),
			'verfall'   => __( 'Verfall', 'lindenweg16' ),
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
	 * Einen Pre-Sale prüfen.
	 *
	 * @param array $in        Rohwerte: name, von, frei_ab (Y-m-d), stufe (Index ab 1), produkte (int[]),
	 *                         kategorien (int[]), merkmale (Liste "taxonomie:slug").
	 * @param int   $max_stufe Höchster Stufen-Index.
	 * @return array{presale:?array,error:string}
	 */
	public static function sanitize_presale( array $in, $max_stufe ) {
		$name   = trim( (string) ( $in['name'] ?? '' ) );
		$von    = (string) ( $in['von'] ?? '' );
		$frei   = (string) ( $in['frei_ab'] ?? '' );
		$stufe  = (int) ( $in['stufe'] ?? 0 );
		$ids    = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $in['produkte'] ?? array() ) ) ) ) );
		$cats   = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $in['kategorien'] ?? array() ) ) ) ) );
		$merk   = array();
		foreach ( (array) ( $in['merkmale'] ?? array() ) as $m ) {
			$m = strtolower( trim( (string) $m ) );
			if ( preg_match( '/^[a-z0-9_\-]+:[a-z0-9_\-%]+$/', $m ) ) {
				$merk[ $m ] = $m;
			}
		}
		$merk = array_values( $merk );

		if ( '' === $name ) {
			return array( 'presale' => null, 'error' => __( 'Gib dem Pre-Sale einen Namen, z. B. „Herbst-Drop“.', 'lindenweg16' ) );
		}
		if ( ! self::is_ymd( $von ) || ! self::is_ymd( $frei ) ) {
			return array( 'presale' => null, 'error' => __( 'Start und „für alle ab“ brauchen ein gültiges Datum.', 'lindenweg16' ) );
		}
		if ( $frei <= $von ) {
			return array( 'presale' => null, 'error' => __( '„Für alle ab“ muss nach dem Start liegen.', 'lindenweg16' ) );
		}
		if ( $stufe < 1 || $stufe > (int) $max_stufe ) {
			return array( 'presale' => null, 'error' => __( 'Wähle eine Stufe, ab der man vorab kaufen darf (nicht die unterste).', 'lindenweg16' ) );
		}
		if ( ! $ids && ! $cats && ! $merk ) {
			return array( 'presale' => null, 'error' => __( 'Wähle mindestens ein Produkt, eine Kategorie oder ein Merkmal.', 'lindenweg16' ) );
		}

		return array(
			'presale' => array(
				'name'       => $name,
				'von'        => $von,
				'frei_ab'    => $frei,
				'stufe'      => $stufe,
				'produkte'   => $ids,
				'kategorien' => $cats,
				'merkmale'   => $merk,
			),
			'error'   => '',
		);
	}

	/**
	 * Betrifft der Pre-Sale diesen Artikel?
	 *
	 * @param array $presale Pre-Sale.
	 * @param array $subject Artikel: ids (Produkt und Elternprodukt), cats (Kategorien samt übergeordneten),
	 *                       terms (Liste "taxonomie:slug").
	 * @return bool
	 */
	public static function presale_matches( array $presale, array $subject ) {
		if ( array_intersect( array_map( 'intval', (array) ( $presale['produkte'] ?? array() ) ), array_map( 'intval', (array) ( $subject['ids'] ?? array() ) ) ) ) {
			return true;
		}
		if ( array_intersect( array_map( 'intval', (array) ( $presale['kategorien'] ?? array() ) ), array_map( 'intval', (array) ( $subject['cats'] ?? array() ) ) ) ) {
			return true;
		}
		return (bool) array_intersect( (array) ( $presale['merkmale'] ?? array() ), (array) ( $subject['terms'] ?? array() ) );
	}

	/**
	 * Läuft der Pre-Sale an diesem Tag? Vorab ab Start, für alle ab „frei_ab“.
	 *
	 * @param array  $presale Pre-Sale.
	 * @param string $ymd     Tag.
	 * @return bool
	 */
	public static function presale_running( array $presale, $ymd ) {
		return isset( $presale['von'], $presale['frei_ab'] ) && $presale['von'] <= $ymd && $ymd < $presale['frei_ab'];
	}

	/**
	 * Welcher Pre-Sale sperrt den Artikel für diesen Kunden?
	 *
	 * @param array  $presales   Alle Pre-Sales.
	 * @param string $ymd        Heute.
	 * @param int    $tier_index Stufen-Index des Kunden (-1 = keine Stufe, z. B. nicht angemeldet).
	 * @param array  $subject    Artikel, siehe presale_matches().
	 * @return array|null Der sperrende Pre-Sale oder null.
	 */
	public static function presale_blocking( array $presales, $ymd, $tier_index, array $subject ) {
		foreach ( $presales as $p ) {
			if ( self::presale_running( $p, $ymd ) && (int) $tier_index < (int) $p['stufe'] && self::presale_matches( $p, $subject ) ) {
				return $p;
			}
		}
		return null;
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
	 * Vorgeschlagene Stufen – benannt nach dem Weg eines Haster Hakens durch
	 * die Werkstatt, vom rohen Brett bis zum Meisterstück.
	 *
	 * @return array Liste mit name, ab (Euro in 12 Monaten), faktor.
	 */
	public static function default_tiers() {
		return array(
			array( 'name' => __( 'Rohling', 'lindenweg16' ), 'ab' => 0, 'faktor' => 1.0 ),
			array( 'name' => __( 'Gehobelt', 'lindenweg16' ), 'ab' => 100, 'faktor' => 1.1 ),
			array( 'name' => __( 'Geölt', 'lindenweg16' ), 'ab' => 250, 'faktor' => 1.25 ),
			array( 'name' => __( 'Meisterstück', 'lindenweg16' ), 'ab' => 500, 'faktor' => 1.5 ),
		);
	}

	/**
	 * Stufen prüfen: 2 bis 6, die erste ab 0 €, Schwellen steigend,
	 * Faktoren von 1 bis 3 und nicht fallend.
	 *
	 * @param array $rows Rohwerte.
	 * @return array{tiers:?array,error:string}
	 */
	public static function sanitize_tiers( array $rows ) {
		$tiers = array();
		foreach ( array_values( $rows ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = trim( (string) ( $row['name'] ?? '' ) );
			$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 30 ) : substr( $name, 0, 30 );
			$ab   = round( (float) str_replace( ',', '.', (string) ( $row['ab'] ?? 0 ) ), 2 );
			$fak  = round( (float) str_replace( ',', '.', (string) ( $row['faktor'] ?? 1 ) ), 2 );
			if ( '' === $name ) {
				return array( 'tiers' => null, 'error' => __( 'Jede Stufe braucht einen Namen.', 'lindenweg16' ) );
			}
			$tiers[] = array( 'name' => $name, 'ab' => $ab, 'faktor' => $fak );
		}

		if ( count( $tiers ) < 2 || count( $tiers ) > 6 ) {
			return array( 'tiers' => null, 'error' => __( 'Es braucht 2 bis 6 Stufen.', 'lindenweg16' ) );
		}
		if ( 0.0 !== (float) $tiers[0]['ab'] ) {
			return array( 'tiers' => null, 'error' => __( 'Die erste Stufe gilt ab 0 € – dort startet jeder.', 'lindenweg16' ) );
		}
		foreach ( $tiers as $i => $t ) {
			if ( $t['faktor'] < 1 || $t['faktor'] > 3 ) {
				/* translators: %s: Stufenname */
				return array( 'tiers' => null, 'error' => sprintf( __( 'Der Faktor von „%s“ muss zwischen 1 und 3 liegen.', 'lindenweg16' ), $t['name'] ) );
			}
			if ( $i > 0 && ( $t['ab'] <= $tiers[ $i - 1 ]['ab'] || $t['faktor'] < $tiers[ $i - 1 ]['faktor'] ) ) {
				/* translators: %s: Stufenname */
				return array( 'tiers' => null, 'error' => sprintf( __( '„%s“ muss mehr Umsatz verlangen als die Stufe davor und darf nicht weniger Punkte geben.', 'lindenweg16' ), $t['name'] ) );
			}
		}
		return array( 'tiers' => $tiers, 'error' => '' );
	}

	/**
	 * Stufe zu einem Umsatz, dazu die nächste und was bis dahin fehlt.
	 *
	 * @param float $revenue Umsatz der letzten 12 Monate.
	 * @param array $tiers   Geprüfte Stufen (aufsteigend).
	 * @return array{index:int,tier:array,next:?array,missing:float}
	 */
	public static function tier_for( $revenue, array $tiers ) {
		$index = 0;
		foreach ( $tiers as $i => $t ) {
			if ( (float) $revenue >= (float) $t['ab'] ) {
				$index = $i;
			}
		}
		$next = isset( $tiers[ $index + 1 ] ) ? $tiers[ $index + 1 ] : null;
		return array(
			'index'   => $index,
			'tier'    => $tiers[ $index ],
			'next'    => $next,
			'missing' => $next ? round( max( 0, (float) $next['ab'] - (float) $revenue ), 2 ) : 0.0,
		);
	}

	/**
	 * Punkte mit dem Faktor der Stufe (abgerundet).
	 *
	 * @param int   $points Punkte.
	 * @param float $factor Faktor der Stufe.
	 * @return int
	 */
	public static function apply_tier( $points, $factor ) {
		return (int) floor( max( 0, (int) $points ) * max( 1, (float) $factor ) + 1e-9 );
	}

	/**
	 * Verfall: Wann laufen die Punkte ab, und ist heute zu erinnern oder zu
	 * verfallen? Die Frist beginnt mit der letzten Aktivität, frühestens mit
	 * dem Tag, an dem der Verfall eingeschaltet wurde.
	 *
	 * @param int $last_activity Letzte Aktivität (Unix-Zeit, 0 = keine bekannt).
	 * @param int $start         Verfall eingeschaltet seit (Unix-Zeit).
	 * @param int $months        Monate ohne Aktivität bis zum Verfall (0 = nie).
	 * @param int $remind_days   Tage vorher erinnern (0 = nie).
	 * @param int $now           Jetzt (Unix-Zeit).
	 * @return array{expires:int,state:string} state: never|ok|remind|expire.
	 */
	public static function expiry_state( $last_activity, $start, $months, $remind_days, $now ) {
		if ( $months <= 0 ) {
			return array( 'expires' => 0, 'state' => 'never' );
		}
		$from    = max( (int) $last_activity, (int) $start );
		$expires = ( new \DateTimeImmutable( '@' . $from ) )->modify( '+' . (int) $months . ' months' )->getTimestamp();

		if ( $now >= $expires ) {
			return array( 'expires' => $expires, 'state' => 'expire' );
		}
		if ( $remind_days > 0 && $now >= $expires - (int) $remind_days * 86400 ) {
			return array( 'expires' => $expires, 'state' => 'remind' );
		}
		return array( 'expires' => $expires, 'state' => 'ok' );
	}

	/**
	 * Was fehlt zum vollständigen Profil?
	 *
	 * @param array $data Kundendaten: first_name, last_name, address_1, postcode, city, phone.
	 * @return string[] Bezeichnungen der fehlenden Angaben (leer = vollständig).
	 */
	public static function profile_missing( array $data ) {
		$need    = array(
			'first_name' => __( 'Vorname', 'lindenweg16' ),
			'last_name'  => __( 'Nachname', 'lindenweg16' ),
			'address_1'  => __( 'Straße', 'lindenweg16' ),
			'postcode'   => __( 'PLZ', 'lindenweg16' ),
			'city'       => __( 'Ort', 'lindenweg16' ),
			'phone'      => __( 'Telefon', 'lindenweg16' ),
		);
		$missing = array();
		foreach ( $need as $key => $label ) {
			if ( '' === trim( (string) ( $data[ $key ] ?? '' ) ) ) {
				$missing[] = $label;
			}
		}
		return $missing;
	}

	/**
	 * Wie viele Jahre seit der ersten Bestellung sind heute voll – 0, wenn
	 * heute nicht der Jahrestag ist.
	 *
	 * @param string $first Erste Bestellung als Y-m-d.
	 * @param string $today Heute als Y-m-d.
	 * @return int
	 */
	public static function anniversary_years( $first, $today ) {
		if ( ! self::is_ymd( $first ) || ! self::is_birthday( substr( $first, 5 ), $today ) ) {
			return 0;
		}
		return max( 0, (int) substr( $today, 0, 4 ) - (int) substr( $first, 0, 4 ) );
	}

	/**
	 * Einlösewunsch begrenzen: Guthaben ganz oder gar nicht, Punkte zwischen
	 * 0 und dem Kontostand.
	 *
	 * @param bool  $want_credit    Guthaben einsetzen?
	 * @param mixed $want_points    Gewünschte Punkte.
	 * @param float $credit_balance Verfügbares Guthaben.
	 * @param int   $points_balance Verfügbare Punkte.
	 * @return array{credit:float,points:int}
	 */
	public static function redeem_request( $want_credit, $want_points, $credit_balance, $points_balance ) {
		return array(
			'credit' => $want_credit ? round( max( 0, (float) $credit_balance ), 2 ) : 0.0,
			'points' => max( 0, min( (int) $points_balance, (int) $want_points ) ),
		);
	}

	/**
	 * Was tatsächlich abgebucht wird, wenn der Rabatt auf die Bestellsumme
	 * gedeckelt ist: zuerst Guthaben, dann Punkte für den Rest. Ohne Deckel
	 * bleibt alles, wie gewünscht.
	 *
	 * @param float $credit Eingesetztes Guthaben.
	 * @param int   $points Eingesetzte Punkte.
	 * @param int   $step   Punkte für 1 €.
	 * @param float $cap    Höchster Rabatt (Zwischensumme).
	 * @return array{credit:float,points:int,euro:float}
	 */
	public static function redeem_split( $credit, $points, $step, $cap ) {
		$step   = max( 1, (int) $step );
		$credit = max( 0, (float) $credit );
		$points = max( 0, (int) $points );
		$cap    = max( 0, (float) $cap );
		$euro   = $credit + floor( $points / $step * 100 ) / 100;

		if ( $euro <= $cap ) {
			return array( 'credit' => round( $credit, 2 ), 'points' => $points, 'euro' => round( $euro, 2 ) );
		}
		$use_credit = min( $credit, $cap );
		$rest       = round( $cap - $use_credit, 2 );
		$use_points = min( $points, (int) ceil( round( $rest * $step, 6 ) ) );
		return array( 'credit' => round( $use_credit, 2 ), 'points' => $use_points, 'euro' => round( $cap, 2 ) );
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
