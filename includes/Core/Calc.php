<?php
/**
 * Reine, seiteneffektfreie Rechenregeln – zentral und einzeln testbar.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Sammelt die mathematischen Kernregeln des Plugins ohne WordPress-Bezug,
 * damit sie mit reinem PHPUnit (ohne WP-Testsuite) geprüft werden können.
 */
class Calc {

	/**
	 * Punkte für einen Bestellbetrag: 1 Punkt pro vollem Euro × Faktor.
	 *
	 * @param float $betrag         Bestellbetrag.
	 * @param float $punkte_pro_euro Faktor.
	 * @return int
	 */
	public static function points_for_amount( $betrag, $punkte_pro_euro = 1.0 ) {
		// „pro vollem Euro": erst auf ganze Euro abrunden, dann Faktor anwenden.
		$volle_euro = floor( max( 0, (float) $betrag ) );
		return (int) floor( $volle_euro * (float) $punkte_pro_euro );
	}

	/**
	 * Punkte in Euro umrechnen (abgerundet auf Cent).
	 *
	 * @param int $punkte       Anzahl Punkte.
	 * @param int $einloesewert Punkte für 1 €.
	 * @return float
	 */
	public static function points_to_euro( $punkte, $einloesewert ) {
		$einloesewert = max( 1, (int) $einloesewert );
		return floor( (int) $punkte / $einloesewert * 100 ) / 100;
	}

	/**
	 * Referral-Reward einer Position: Positionssumme × Marge × Referral-%.
	 *
	 * @param float $positionssumme  Positionssumme (Netto/Brutto je nach Setup).
	 * @param float $marge_prozent   Marge in Prozent (z. B. 40 für 40 %).
	 * @param float $referral_prozent Referral-Anteil in Prozent (z. B. 5).
	 * @return float Auf 2 Nachkommastellen gerundet.
	 */
	public static function referral_reward( $positionssumme, $marge_prozent, $referral_prozent ) {
		$reward = (float) $positionssumme * ( (float) $marge_prozent / 100 ) * ( (float) $referral_prozent / 100 );
		return round( $reward, 2 );
	}

	/**
	 * Einlösung auf die Bestellsumme deckeln (nie unter 0 €).
	 *
	 * @param float $wunsch_rabatt Gewünschter Rabatt (Guthaben + Punkte-Euro).
	 * @param float $bestellsumme  Bestellsumme.
	 * @return float
	 */
	public static function cap_redemption( $wunsch_rabatt, $bestellsumme ) {
		return (float) max( 0, min( (float) $wunsch_rabatt, (float) $bestellsumme ) );
	}

	/**
	 * Druckerkosten pro Stunde.
	 *
	 * @param float $anschaffung   Anschaffungspreis.
	 * @param float $lebensdauer_h Lebensdauer in Stunden.
	 * @param float $ersatzteile_h Ersatzteilkosten pro Stunde.
	 * @param float $leistung_kw   Leistung in kW.
	 * @param float $strompreis    Strompreis pro kWh.
	 * @return float
	 */
	public static function drucker_kosten_pro_stunde( $anschaffung, $lebensdauer_h, $ersatzteile_h, $leistung_kw, $strompreis ) {
		$abschreibung = (float) $lebensdauer_h > 0 ? (float) $anschaffung / (float) $lebensdauer_h : 0;
		return $abschreibung + (float) $ersatzteile_h + (float) $leistung_kw * (float) $strompreis;
	}

	/**
	 * Modulkosten inkl. Ausschuss-Puffer.
	 *
	 * @param array $args Kostenbestandteile und Sätze.
	 * @return float Auf 2 Nachkommastellen gerundet.
	 */
	public static function modul_kosten( array $args ) {
		$filament_kosten = ( (float) $args['filament_gramm'] / 1000 ) * (float) $args['filamentpreis_pro_kg'];
		$drucker_kosten  = (float) $args['drucker_kosten_h'] * (float) $args['druckzeit_stunden'];
		$arbeit_kosten   = ( (float) $args['arbeitszeit_minuten'] / 60 ) * (float) $args['stundenlohn'];

		$zwischensumme = $filament_kosten + $drucker_kosten + $arbeit_kosten
			+ (float) $args['verpackung_euro'] + (float) $args['zusatzmaterial_euro'];

		$kosten = $zwischensumme * ( 1 + (float) $args['ausschuss_prozent'] / 100 );
		return round( $kosten, 2 );
	}

	/**
	 * Vorgeschlagener Preis aus Kosten und Zielmarge, gerundet auf ganze Euro.
	 *
	 * @param float $kosten             Kosten.
	 * @param float $zielmarge_prozent  Zielmarge in Prozent.
	 * @return float
	 */
	public static function preis_aus_marge( $kosten, $zielmarge_prozent ) {
		$zielmarge = (float) $zielmarge_prozent / 100;
		$preis     = $zielmarge < 1 ? (float) $kosten / ( 1 - $zielmarge ) : (float) $kosten;
		return round( $preis );
	}

	/**
	 * Set-Preis aus Einzelsumme und Rabatt, gerundet auf ganze Euro.
	 *
	 * @param float $einzelsumme    Summe der Modulpreise.
	 * @param float $rabatt_prozent Rabatt in Prozent.
	 * @return float
	 */
	public static function set_preis( $einzelsumme, $rabatt_prozent ) {
		return round( (float) $einzelsumme * ( 1 - (float) $rabatt_prozent / 100 ) );
	}

	/**
	 * Tatsächliche Marge in Prozent.
	 *
	 * @param float $kosten Kosten.
	 * @param float $preis  Verkaufspreis.
	 * @return float
	 */
	public static function marge_prozent( $kosten, $preis ) {
		if ( (float) $preis <= 0 ) {
			return 0.0;
		}
		return round( ( (float) $preis - (float) $kosten ) / (float) $preis * 100, 2 );
	}

	/**
	 * Rechnungsnummer nach Konvention bilden.
	 *
	 * @param string $modus      jahr|durchlaufend.
	 * @param int    $jahr       Kalenderjahr.
	 * @param int    $laufnummer Laufende Nummer.
	 * @return string
	 */
	public static function rechnungsnummer( $modus, $jahr, $laufnummer ) {
		if ( 'durchlaufend' === $modus ) {
			return sprintf( 'RE-%05d', (int) $laufnummer );
		}
		return sprintf( '%d-%04d', (int) $jahr, (int) $laufnummer );
	}

	/**
	 * Selbstwerbung erkennen (harte Blockregel): gleiche User-ID oder
	 * gleiche E-Mail-Adresse (case-insensitiv).
	 *
	 * @param int    $referrer_id    Werber-ID.
	 * @param int    $referred_id    Geworbener-ID (0 = Gast).
	 * @param string $referrer_email Werber-E-Mail.
	 * @param string $referred_email Geworbener-E-Mail.
	 * @return bool
	 */
	public static function is_self_referral( $referrer_id, $referred_id, $referrer_email, $referred_email ) {
		if ( $referred_id && (int) $referrer_id === (int) $referred_id ) {
			return true;
		}
		if ( $referrer_email && $referred_email
			&& strtolower( trim( $referrer_email ) ) === strtolower( trim( $referred_email ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Kaskadierte Bestandsabzüge für ein Set berechnen.
	 * Gibt ein Array von [ produkt_id => abzug_menge ] zurück.
	 *
	 * @param float $menge       Verkaufte Menge.
	 * @param array $stueckliste Array von [ 'modul_id' => int, 'menge' => float ].
	 * @param array $rezepturen  Map modul_id => [ [ 'rohmaterial_id' => int, 'menge' => float ], ... ].
	 * @return array produkt_id => total_abzug (negative Werte).
	 */
	public static function cascade_deductions( $menge, array $stueckliste, array $rezepturen ) {
		$result = array();
		$menge  = abs( (float) $menge );

		foreach ( $stueckliste as $pos ) {
			$modul_id    = (int) $pos['modul_id'];
			$modul_menge = $menge * (float) $pos['menge'];

			if ( ! isset( $result[ $modul_id ] ) ) {
				$result[ $modul_id ] = 0.0;
			}
			$result[ $modul_id ] -= $modul_menge;

			if ( isset( $rezepturen[ $modul_id ] ) ) {
				foreach ( $rezepturen[ $modul_id ] as $rez ) {
					$roh_id    = (int) $rez['rohmaterial_id'];
					$roh_menge = $modul_menge * (float) $rez['menge'];
					if ( ! isset( $result[ $roh_id ] ) ) {
						$result[ $roh_id ] = 0.0;
					}
					$result[ $roh_id ] -= $roh_menge;
				}
			}
		}

		return $result;
	}

	/**
	 * Prüfen, ob ein Review-Bonus vergeben werden darf.
	 *
	 * @param string $post_type      Post-Type des Kommentar-Ziels.
	 * @param int    $content_length Zeichenlänge des Kommentartexts.
	 * @param int    $min_length     Mindestlänge.
	 * @param int    $user_id        Benutzer-ID (0 = Gast).
	 * @param bool   $already_awarded Ob bereits ein Bonus für dieses Produkt vergeben wurde.
	 * @return bool
	 */
	public static function review_bonus_eligible( $post_type, $content_length, $min_length, $user_id, $already_awarded ) {
		if ( 'product' !== $post_type ) {
			return false;
		}
		if ( $content_length < $min_length ) {
			return false;
		}
		if ( ! $user_id ) {
			return false;
		}
		if ( $already_awarded ) {
			return false;
		}
		return true;
	}

	/**
	 * Prüfen, ob eine Wartelisten-Benachrichtigung gesendet werden soll.
	 *
	 * @param bool $already_notified Ob bereits benachrichtigt wurde.
	 * @return bool
	 */
	public static function waitlist_should_notify( $already_notified ) {
		return ! $already_notified;
	}

	/**
	 * Prüfen, ob ein Warenkorb getrackt werden darf (nur mit bekannter E-Mail).
	 *
	 * @param string $email      Kunden-E-Mail.
	 * @param bool   $cart_empty Ob der Warenkorb leer ist.
	 * @param bool   $is_admin   Ob es ein Admin-Request ist.
	 * @return bool
	 */
	public static function cart_should_track( $email, $cart_empty, $is_admin ) {
		if ( $is_admin ) {
			return false;
		}
		if ( $cart_empty ) {
			return false;
		}
		if ( ! $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Steuer-Rücklage-Empfehlung.
	 *
	 * @param float $gewinn             Gewinn.
	 * @param float $steuersatz_prozent Geschätzter Steuersatz.
	 * @return float
	 */
	public static function ruecklage( $gewinn, $steuersatz_prozent ) {
		return round( max( 0, (float) $gewinn ) * ( (float) $steuersatz_prozent / 100 ), 2 );
	}
}
