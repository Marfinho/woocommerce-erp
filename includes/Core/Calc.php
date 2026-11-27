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
	 * Belegnummer im Format B-JJJJ-NNNN.
	 *
	 * @param int $jahr       Kalenderjahr.
	 * @param int $laufnummer Laufende Nummer.
	 * @return string
	 */
	public static function belegnummer( $jahr, $laufnummer ) {
		return sprintf( 'B-%d-%04d', (int) $jahr, (int) $laufnummer );
	}

	/**
	 * Buchungsnummer im Format BU-JJJJ-NNNN.
	 *
	 * @param int $jahr       Kalenderjahr.
	 * @param int $laufnummer Laufende Nummer.
	 * @return string
	 */
	public static function buchungsnummer( $jahr, $laufnummer ) {
		return sprintf( 'BU-%d-%04d', (int) $jahr, (int) $laufnummer );
	}

	/**
	 * Kassenbuchnummer im Format KB-JJJJ-NNNN.
	 *
	 * @param int $jahr       Kalenderjahr.
	 * @param int $laufnummer Laufende Nummer.
	 * @return string
	 */
	public static function kassenbuchnummer( $jahr, $laufnummer ) {
		return sprintf( 'KB-%d-%04d', (int) $jahr, (int) $laufnummer );
	}

	/**
	 * Anlagennummer im Format AN-JJJJ-NNNN.
	 *
	 * @param int $jahr       Kalenderjahr.
	 * @param int $laufnummer Laufende Nummer.
	 * @return string
	 */
	public static function anlagennummer( $jahr, $laufnummer ) {
		return sprintf( 'AN-%d-%04d', (int) $jahr, (int) $laufnummer );
	}

	/**
	 * Kostenstellennummer im Format K-JJJJ-NNN.
	 *
	 * Die Nummer ist bewusst ein reiner Identifikator: Weder Kampagnentyp
	 * noch Kostenart stecken in ihr. Beides sind eigene Felder, die sich
	 * ändern dürfen, ohne dass eine bereits vergebene Nummer bricht.
	 *
	 * @param int $jahr       Kalenderjahr.
	 * @param int $laufnummer Laufende Nummer.
	 * @return string
	 */
	public static function kostenstelle( $jahr, $laufnummer ) {
		return sprintf( 'K-%d-%03d', (int) $jahr, (int) $laufnummer );
	}

	/**
	 * Fahrtkosten aus gefahrenen Kilometern und Pauschale.
	 *
	 * @param float $km          Gefahrene Kilometer (Hin- und Rückweg).
	 * @param float $satz_pro_km Pauschale je Kilometer.
	 * @return float Betrag auf Cent gerundet.
	 */
	public static function fahrtkosten( $km, $satz_pro_km = 0.30 ) {
		$km   = max( 0, (float) $km );
		$satz = max( 0, (float) $satz_pro_km );
		return round( $km * $satz, 2 );
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
