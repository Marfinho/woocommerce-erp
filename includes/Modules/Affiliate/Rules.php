<?php
/**
 * Affiliates: reine Regeln ohne Datenbank und ohne WooCommerce.
 *
 * Alles, was sich rechnen und prüfen lässt, steht hier, damit es testbar bleibt:
 * Code und Eingaben bereinigen, Provision aus einer Bestellung, Wartezeit,
 * Summen je Partner.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Affiliate;

defined( 'ABSPATH' ) || exit;

/**
 * Prüfen und Rechnen.
 */
class Rules {

	/**
	 * Zeiträume der Auswertung: Tage => Beschriftung (0 = alles).
	 *
	 * @return array
	 */
	public static function ranges() {
		return array(
			30  => __( 'Letzte 30 Tage', 'lindenweg16' ),
			90  => __( 'Letzte 90 Tage', 'lindenweg16' ),
			365 => __( 'Letzte 12 Monate', 'lindenweg16' ),
			0   => __( 'Gesamter Zeitraum', 'lindenweg16' ),
		);
	}

	/**
	 * Gewählten Zeitraum auf die bekannten begrenzen (Vorgabe 90 Tage).
	 *
	 * @param mixed $days Eingabe.
	 * @return int
	 */
	public static function clean_days( $days ) {
		$days = is_numeric( $days ) ? (int) $days : 90;

		return array_key_exists( $days, self::ranges() ) ? $days : 90;
	}

	/**
	 * Code in die einheitliche Schreibweise bringen: Großbuchstaben, ohne Leerzeichen.
	 *
	 * @param mixed $raw Eingabe.
	 * @return string
	 */
	public static function normalize_code( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		return strtoupper( preg_replace( '/\s+/', '', $raw ) );
	}

	/**
	 * Ist der Code ein brauchbarer Rabattcode? 3 bis 32 Zeichen: A–Z, 0–9, Bindestrich, Unterstrich.
	 *
	 * @param string $code Bereits normalisierter Code.
	 * @return bool
	 */
	public static function is_valid_code( $code ) {
		return 1 === preg_match( '/^[A-Z0-9][A-Z0-9_-]{2,31}$/', (string) $code );
	}

	/**
	 * Zahl aus Eingabe lesen („10“, „7,5“, „7.5“); sonst null.
	 *
	 * @param mixed $raw Eingabe.
	 * @return float|null
	 */
	public static function number( $raw ) {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return (float) $raw;
		}

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$raw = str_replace( ',', '.', trim( $raw ) );

		return is_numeric( $raw ) ? (float) $raw : null;
	}

	/**
	 * Ein Affiliate braucht ein Konto im Shop: Ohne Konto gibt es keinen Ambassador-Bereich.
	 *
	 * @param int  $user_id Vorhandenes Konto (0 = keins).
	 * @param bool $create  Das Konto soll angelegt werden.
	 * @return string[] Meldungen; leer, wenn alles in Ordnung ist.
	 */
	public static function konto_errors( $user_id, $create ) {
		if ( (int) $user_id > 0 || $create ) {
			return array();
		}

		return array( __( 'Ein Konto im Shop ist Pflicht: Es macht den Affiliate zum Ambassador mit eigenem Bereich unter „Mein Konto“. Trage die E-Mail-Adresse ein; gibt es noch kein Konto, setze den Haken „Konto anlegen und Einladung mailen“.', 'lindenweg16' ) );
	}

	/**
	 * Gesamtanteil in Rabatt für Kunden und Provision aufteilen.
	 *
	 * Der Gesamtanteil (zum Beispiel 20 %) ist das, was der Shop für diesen Affiliate hergibt.
	 * Davon bekommen die Kunden den Rabatt, den Rest behält der Affiliate: 10 + 10, 5 + 15 …
	 * Die Summe ist immer der Gesamtanteil.
	 *
	 * @param mixed $gesamt Gesamtanteil in Prozent.
	 * @param mixed $rabatt Rabatt für Kunden in Prozent.
	 * @return array [ 'gesamt', 'rabatt', 'provision', 'errors' ].
	 */
	public static function split( $gesamt, $rabatt ) {
		$errors = array();
		$g      = self::number( $gesamt );
		$r      = self::number( $rabatt );

		if ( null === $g || $g < 0 || $g > 100 ) {
			$errors[] = __( 'Der Gesamtanteil muss eine Zahl zwischen 0 und 100 Prozent sein.', 'lindenweg16' );
			$g        = 0.0;
		}

		$g = round( $g, 2 );

		if ( null === $r || $r < 0 || $r > $g ) {
			if ( ! $errors ) {
				/* translators: %s: Gesamtanteil. */
				$errors[] = sprintf( __( 'Der Rabatt für Kunden muss zwischen 0 und dem Gesamtanteil (%s Prozent) liegen.', 'lindenweg16' ), rtrim( rtrim( number_format( $g, 2, ',', '' ), '0' ), ',' ) );
			}
			$r = 0.0;
		}

		$r = round( $r, 2 );

		return array(
			'gesamt'    => $g,
			'rabatt'    => $r,
			'provision' => round( $g - $r, 2 ),
			'errors'    => $errors,
		);
	}

	/**
	 * Provisionssatz einer Bestellung aus dem Rabatt, den der Kunde tatsächlich bekommen hat.
	 *
	 * So bleibt die Summe aus Rabatt und Provision auch dann der Gesamtanteil, wenn der
	 * Affiliate die Aufteilung ändert, während jemand schon im Warenkorb war.
	 *
	 * @param float $gesamt   Gesamtanteil des Affiliates.
	 * @param float $discount Rabatt in Prozent, der an der Bestellung stand.
	 * @return float
	 */
	public static function rate_from_discount( $gesamt, $discount ) {
		return round( max( 0.0, (float) $gesamt - max( 0.0, (float) $discount ) ), 2 );
	}

	/**
	 * Eingaben eines Partners prüfen und bereinigen.
	 *
	 * Der Code gilt nur für neue Partner: Er steht in Videos und Beschreibungen
	 * und lässt sich danach nicht mehr ändern (nur abschalten).
	 *
	 * @param array $in     Eingaben (name, plattform, email, code, gesamt, rabatt, teilen, aktiv, notiz). Die Provision ergibt sich aus Gesamtanteil minus Rabatt.
	 * @param bool  $is_new Neuer Partner?
	 * @return array [ 'row' => bereinigte Felder, 'errors' => Meldungen ].
	 */
	public static function clean_partner( array $in, $is_new ) {
		$errors = array();
		$row    = array();

		$name = trim( isset( $in['name'] ) && is_string( $in['name'] ) ? $in['name'] : '' );
		if ( '' === $name ) {
			$errors[] = __( 'Bitte einen Namen eintragen.', 'lindenweg16' );
		} elseif ( mb_strlen( $name ) > 80 ) {
			$errors[] = __( 'Der Name darf höchstens 80 Zeichen haben.', 'lindenweg16' );
		}
		$row['name'] = $name;

		$plattform = trim( isset( $in['plattform'] ) && is_string( $in['plattform'] ) ? $in['plattform'] : '' );
		if ( mb_strlen( $plattform ) > 120 ) {
			$errors[] = __( 'Kanal und Profil dürfen zusammen höchstens 120 Zeichen haben.', 'lindenweg16' );
		}
		$row['plattform'] = $plattform;

		$email = trim( isset( $in['email'] ) && is_string( $in['email'] ) ? $in['email'] : '' );
		if ( '' !== $email && false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors[] = __( 'Die E-Mail-Adresse ist nicht gültig.', 'lindenweg16' );
		}
		$row['email'] = $email;

		// Gesamtanteil festlegen, davon Rabatt für Kunden; der Rest ist die Provision.
		$split = self::split( isset( $in['gesamt'] ) ? $in['gesamt'] : null, isset( $in['rabatt'] ) ? $in['rabatt'] : null );
		$errors = array_merge( $errors, $split['errors'] );

		$row['gesamt']    = $split['gesamt'];
		$row['rabatt']    = $split['rabatt'];
		$row['provision'] = $split['provision'];
		$row['teilen']    = ! isset( $in['teilen'] ) || ( ! empty( $in['teilen'] ) && 'false' !== $in['teilen'] && '0' !== $in['teilen'] );

		$notiz = trim( isset( $in['notiz'] ) && is_string( $in['notiz'] ) ? $in['notiz'] : '' );
		if ( mb_strlen( $notiz ) > 500 ) {
			$errors[] = __( 'Die Notiz darf höchstens 500 Zeichen haben.', 'lindenweg16' );
		}
		$row['notiz'] = $notiz;
		$row['aktiv'] = ! empty( $in['aktiv'] ) && 'false' !== $in['aktiv'] && '0' !== $in['aktiv'];

		if ( $is_new ) {
			$code = self::normalize_code( isset( $in['code'] ) ? $in['code'] : '' );
			if ( ! self::is_valid_code( $code ) ) {
				$errors[] = __( 'Der Code braucht 3 bis 32 Zeichen: Buchstaben ohne Umlaute, Ziffern, Bindestrich oder Unterstrich.', 'lindenweg16' );
			}
			$row['code'] = $code;
		}

		return array(
			'row'    => $row,
			'errors' => $errors,
		);
	}

	/**
	 * Vorschlag für einen Code aus dem Namen: „Anna Müller“ wird „ANNAMUELLER“ bzw. gekürzt „ANNA“.
	 *
	 * @param string $name Name oder Profilname.
	 * @return string Leer, wenn nichts Brauchbares übrig bleibt.
	 */
	public static function suggest_code( $name ) {
		$name = strtr(
			(string) $name,
			array(
				'ä' => 'ae',
				'ö' => 'oe',
				'ü' => 'ue',
				'Ä' => 'AE',
				'Ö' => 'OE',
				'Ü' => 'UE',
				'ß' => 'ss',
			)
		);
		$word = preg_split( '/\s+/', trim( $name ) );
		$word = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $word[0] ) );

		return strlen( $word ) >= 3 ? substr( $word, 0, 16 ) : '';
	}

	/**
	 * Provision aus Bemessungsgrundlage und Satz.
	 *
	 * @param float $base    Bemessungsgrundlage in Euro.
	 * @param float $percent Provisionssatz in Prozent.
	 * @return float
	 */
	public static function commission( $base, $percent ) {
		return round( max( 0.0, (float) $base ) * (float) $percent / 100, 2 );
	}

	/**
	 * Warenwert einer Bestellung, auf den die Provision gerechnet wird:
	 * Positionen netto, nach Rabatt (auch dem Affiliate-Rabatt) und nach Erstattungen. Ohne Versand, ohne Steuer.
	 *
	 * @param float $subtotal     Positionssumme netto vor Rabatt.
	 * @param float $discount     Rabatt netto.
	 * @param float $refunded_net Erstatteter Warenwert netto (positiv).
	 * @return float
	 */
	public static function base( $subtotal, $discount, $refunded_net ) {
		return round( max( 0.0, (float) $subtotal - (float) $discount - (float) $refunded_net ), 2 );
	}

	/**
	 * Erstatteten Warenwert aus einer Erstattung: Summe ohne Steuer und ohne Versand.
	 *
	 * WooCommerce speichert Erstattungen mit negativem Vorzeichen; hier zählt der Betrag.
	 *
	 * @param float $total    Gesamtbetrag der Erstattung (brutto).
	 * @param float $tax      Steuer der Erstattung.
	 * @param float $shipping Versand der Erstattung (netto).
	 * @return float Positiv.
	 */
	public static function refund_goods_net( $total, $tax, $shipping ) {
		return round( max( 0.0, abs( (float) $total ) - abs( (float) $tax ) - abs( (float) $shipping ) ), 2 );
	}

	/**
	 * Ist die Provision auszahlbar? Erst nach der Wartezeit (Widerruf und Rücksendungen).
	 *
	 * @param int $order_ts  Zeitpunkt der Bestellung.
	 * @param int $now       Jetzt.
	 * @param int $wait_days Wartezeit in Tagen.
	 * @return bool
	 */
	public static function is_payable( $order_ts, $now, $wait_days ) {
		return (int) $order_ts <= (int) $now - max( 0, (int) $wait_days ) * 86400;
	}

	/**
	 * Summen je Partner.
	 *
	 * @param array $partners  Id => Partner (code, provision).
	 * @param array $sales     Zeilen mit `code` (klein), `ts`, `base`, `total`, optional `rate`.
	 * @param array $payouts   Zeilen mit `partner` und `betrag`.
	 * @param int   $now       Jetzt.
	 * @param int   $wait_days Wartezeit in Tagen.
	 * @param int   $days      Zeitraum in Tagen; 0 = alles.
	 * @return array Id => Kennzahlen.
	 */
	public static function summarize( array $partners, array $sales, array $payouts, $now, $wait_days, $days ) {
		$by_code = array();
		$out     = array();

		foreach ( $partners as $id => $p ) {
			$by_code[ strtolower( (string) $p['code'] ) ] = $id;
			$out[ $id ]                                   = array(
				'anzahl'      => 0,
				'umsatz'      => 0.0,
				'basis'       => 0.0,
				'provision'   => 0.0,
				'gesamt'      => 0.0,
				'wartend'     => 0.0,
				'auszahlbar'  => 0.0,
				'ausgezahlt'  => 0.0,
				'guthaben'    => 0.0,
				'letzte_ts'   => 0,
				'eigen'       => 0,
			);
		}

		$from = (int) $days > 0 ? (int) $now - (int) $days * 86400 : 0;

		foreach ( $sales as $s ) {
			$code = strtolower( (string) $s['code'] );
			if ( ! isset( $by_code[ $code ] ) ) {
				continue;
			}

			$id = $by_code[ $code ];

			// Eigenkäufe des Partners zählen nicht; sie werden nur gemeldet.
			if ( ! empty( $s['eigen'] ) ) {
				if ( (int) $s['ts'] >= $from ) {
					$out[ $id ]['eigen']++;
				}
				continue;
			}

			$rate = isset( $s['rate'] ) && null !== $s['rate'] ? (float) $s['rate'] : (float) $partners[ $id ]['provision'];
			$prov = self::commission( $s['base'], $rate );

			$out[ $id ]['gesamt'] += $prov;
			if ( self::is_payable( $s['ts'], $now, $wait_days ) ) {
				$out[ $id ]['auszahlbar'] += $prov;
			} else {
				$out[ $id ]['wartend'] += $prov;
			}

			if ( (int) $s['ts'] > $out[ $id ]['letzte_ts'] ) {
				$out[ $id ]['letzte_ts'] = (int) $s['ts'];
			}

			if ( (int) $s['ts'] >= $from ) {
				$out[ $id ]['anzahl']++;
				$out[ $id ]['umsatz']    += (float) $s['total'];
				$out[ $id ]['basis']     += (float) $s['base'];
				$out[ $id ]['provision'] += $prov;
			}
		}

		foreach ( $payouts as $pay ) {
			$id = isset( $pay['partner'] ) ? $pay['partner'] : 0;
			if ( isset( $out[ $id ] ) && empty( $pay['storniert'] ) ) {
				$out[ $id ]['ausgezahlt'] += (float) $pay['betrag'];
			}
		}

		foreach ( $out as $id => $r ) {
			foreach ( array( 'umsatz', 'basis', 'provision', 'gesamt', 'wartend', 'auszahlbar', 'ausgezahlt' ) as $k ) {
				$out[ $id ][ $k ] = round( $r[ $k ], 2 );
			}
			$out[ $id ]['guthaben'] = round( $out[ $id ]['auszahlbar'] - $out[ $id ]['ausgezahlt'], 2 );
		}

		return $out;
	}

	/**
	 * Auszahlungsanforderung eines Ambassadors prüfen.
	 *
	 * Angefordert wird immer das ganze auszahlbare Guthaben. Es muss den Mindestbetrag
	 * erreichen, es darf keine Anforderung offen sein, und der Ambassador sagt, wohin das Geld soll.
	 *
	 * @param float  $guthaben  Auszahlbares Guthaben.
	 * @param float  $min       Mindestbetrag.
	 * @param bool   $has_open  Liegt schon eine offene Anforderung vor?
	 * @param string $ziel      Zahlungsangabe (z. B. PayPal-Adresse).
	 * @return array [ 'betrag' => float, 'ziel' => string, 'errors' => Meldungen ].
	 */
	public static function check_request( $guthaben, $min, $has_open, $ziel ) {
		$errors = array();
		$ziel   = trim( (string) $ziel );
		$betrag = round( (float) $guthaben, 2 );
		$min    = max( 0.01, round( (float) $min, 2 ) );

		if ( $has_open ) {
			$errors[] = __( 'Du hast schon eine Auszahlung angefordert. Sobald sie erledigt ist, kannst du die nächste anfordern.', 'lindenweg16' );
		}

		if ( '' === $ziel ) {
			$errors[] = __( 'Bitte angeben, wohin das Geld soll, zum Beispiel deine PayPal-Adresse.', 'lindenweg16' );
		} elseif ( mb_strlen( $ziel ) > 190 ) {
			$errors[] = __( 'Die Zahlungsangabe darf höchstens 190 Zeichen haben.', 'lindenweg16' );
		}

		if ( $betrag < $min ) {
			$errors[] = sprintf(
				/* translators: 1: Mindestbetrag, 2: aktuelles Guthaben. */
				__( 'Eine Auszahlung ist ab %1$s € möglich. Auszahlbar sind im Moment %2$s €.', 'lindenweg16' ),
				number_format( $min, 2, ',', '.' ),
				number_format( max( 0.0, $betrag ), 2, ',', '.' )
			);
		}

		return array(
			'betrag' => $betrag,
			'ziel'   => $ziel,
			'errors' => $errors,
		);
	}

	/**
	 * Auszahlung prüfen.
	 *
	 * Der Betrag muss positiv sein und darf das Guthaben nicht übersteigen. Eine falsche
	 * Auszahlung wird nicht mit einem Minusbetrag berichtigt, sondern storniert.
	 *
	 * @param mixed $betrag   Eingabe.
	 * @param float $guthaben Aktuelles Guthaben des Partners.
	 * @return array [ 'betrag' => float, 'errors' => Meldungen ].
	 */
	public static function check_payout( $betrag, $guthaben ) {
		$value = self::number( $betrag );

		if ( null === $value || abs( $value ) < 0.005 ) {
			return array(
				'betrag' => 0.0,
				'errors' => array( __( 'Bitte einen Betrag in Euro eintragen.', 'lindenweg16' ) ),
			);
		}

		$value  = round( $value, 2 );
		$errors = array();

		if ( $value < 0 ) {
			$errors[] = __( 'Der Betrag muss größer als 0 sein. Eine falsche Auszahlung stornierst du in der Liste der Auszahlungen.', 'lindenweg16' );
		} elseif ( $value > round( (float) $guthaben, 2 ) + 0.001 ) {
			$errors[] = __( 'Mehr als das auszahlbare Guthaben lässt sich nicht auszahlen. Provision wird erst nach der Wartezeit auszahlbar.', 'lindenweg16' );
		}

		return array(
			'betrag' => $value,
			'errors' => $errors,
		);
	}

	/**
	 * Hat der Partner bei sich selbst gekauft?
	 *
	 * Verglichen wird das Konto und jede bekannte E-Mail-Adresse (Groß- und Kleinschreibung egal).
	 *
	 * @param string[] $own_emails Adressen des Partners (Kontaktadresse, Konto).
	 * @param int      $own_user   Konto des Partners; 0 = keins.
	 * @param string[] $emails     Adressen des Käufers (Rechnung, Konto).
	 * @param int      $user       Konto des Käufers; 0 = Gast.
	 * @return bool
	 */
	public static function is_self_purchase( array $own_emails, $own_user, array $emails, $user ) {
		if ( (int) $own_user > 0 && (int) $own_user === (int) $user ) {
			return true;
		}

		$own = array_filter( array_map( 'strtolower', array_map( 'trim', $own_emails ) ) );

		foreach ( $emails as $email ) {
			$email = strtolower( trim( (string) $email ) );
			if ( '' !== $email && in_array( $email, $own, true ) ) {
				return true;
			}
		}

		return false;
	}
}
