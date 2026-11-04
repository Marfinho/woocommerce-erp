<?php
/**
 * Affiliates: Willkommensmail bei der Aufnahme ins Ambassador-Programm.
 *
 * Ein Dank, ein paar Sätze, was es bedeutet, und eine kurze Anleitung: Code, Link, Bereich unter
 * „Mein Konto“, Auszahlung. Der Text ist die Vorlage `ambassador_willkommen` und lässt sich wie die
 * anderen Mailvorlagen ändern.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Affiliate;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Core\Settings;

/**
 * Mail zusammenstellen und senden.
 */
class Welcome {

	/**
	 * Empfängeradresse: das Konto, sonst die Kontaktadresse.
	 *
	 * @param array $p Partner.
	 * @return string Leer, wenn keine Adresse bekannt ist.
	 */
	public static function recipient( array $p ) {
		if ( ! empty( $p['user_id'] ) ) {
			$u = get_userdata( (int) $p['user_id'] );
			if ( $u && is_email( $u->user_email ) ) {
				return $u->user_email;
			}
		}

		return is_email( $p['email'] ) ? $p['email'] : '';
	}

	/**
	 * Zahl ohne überflüssige Nullen („10“, „7,5“).
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	protected static function pct( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, ',', '' ), '0' ), ',' );
	}

	/**
	 * Die Platzhalter der Vorlage.
	 *
	 * @param array $p         Partner.
	 * @param bool  $konto_neu Das Konto wurde gerade angelegt (WordPress schickt eine eigene Mail).
	 * @return array {platzhalter} => Wert.
	 */
	public static function placeholders( array $p, $konto_neu = false ) {
		$wait     = max( 0, (int) Settings::get( 'affiliate_wartezeit_tage', 30 ) );
		$min      = number_format( (float) Settings::get( 'affiliate_min_auszahlung', 20 ), 2, ',', '.' );
		$has_user = ! empty( $p['user_id'] );
		$account  = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( Account::ENDPOINT ) : home_url( '/' );
		$parts    = preg_split( '/\s+/', trim( (string) $p['name'] ) );
		$first    = $parts && '' !== $parts[0] ? $parts[0] : (string) $p['name'];

		if ( $has_user ) {
			$bereich  = __( "3. DEIN BEREICH: ", 'lindenweg16' ) . $account . "\n" . __( 'Melde Dich an, und Du siehst Bestellungen, Warenwert und Provision zu Deinem Code.', 'lindenweg16' );
			if ( ! empty( $p['teilen'] ) && $p['gesamt'] > 0 ) {
				/* translators: %s: Gesamtanteil in Prozent. */
				$bereich .= ' ' . sprintf( __( 'Und das Beste: Wie viel Rabatt Deine Follower bekommen und wie viel Du behältst, entscheidest Du selbst, zum Beispiel 10 und 10 oder 5 und 15. Die Summe bleibt %s %%.', 'lindenweg16' ), self::pct( $p['gesamt'] ) );
			}

			/* translators: 1: Tage, 2: Mindestbetrag. */
			$auszahlung = sprintf( __( "4. DEINE AUSZAHLUNG\nDeine Provision wird %1\$s Tage nach der Bestellung auszahlbar, so lange kann noch etwas zurückgeschickt werden. Ab %2\$s € forderst Du sie in Deinem Bereich mit einem Klick an und sagst uns, wohin das Geld soll. Wir überweisen und tragen es ein, Du siehst alles dort.", 'lindenweg16' ), $wait, $min );
		} else {
			$bereich = __( "3. DEIN BEREICH\nEin Konto im Shop mit Deinen Zahlen richten wir Dir noch ein. Bis dahin sagen wir Dir Bescheid, wie es läuft.", 'lindenweg16' );
			/* translators: %s: Tage. */
			$auszahlung = sprintf( __( "4. DEINE AUSZAHLUNG\nDeine Provision wird %s Tage nach der Bestellung auszahlbar, so lange kann noch etwas zurückgeschickt werden. Schreib uns einfach, wenn Du sie ausgezahlt haben möchtest.", 'lindenweg16' ), $wait );
		}

		return array(
			'{vorname}'       => $first,
			'{code}'          => $p['code'],
			'{link}'          => AutoApply::link( $p['code'] ),
			'{rabatt}'        => self::pct( $p['rabatt'] ),
			'{provision}'     => self::pct( $p['provision'] ),
			'{gesamt}'        => self::pct( $p['gesamt'] ),
			'{bereich}'       => $bereich,
			'{auszahlung}'    => $auszahlung,
			'{konto_hinweis}' => $konto_neu ? __( "Dein Konto haben wir gerade angelegt. Du bekommst dazu noch eine eigene Mail mit dem Link, Dein Passwort zu setzen.\n\n", 'lindenweg16' ) : '',
			'{konto_url}'     => $account,
			'{wartezeit}'     => (string) $wait,
			'{mindestbetrag}' => $min,
		);
	}

	/**
	 * Willkommensmail senden und festhalten, wann.
	 *
	 * @param array $p         Partner.
	 * @param bool  $konto_neu Konto wurde gerade angelegt.
	 * @return bool Ob die Mail rausging.
	 */
	public static function send( array $p, $konto_neu = false ) {
		$to = self::recipient( $p );

		if ( '' === $to ) {
			return false;
		}

		$ok = MailTemplate::send( $to, 'ambassador_willkommen', self::placeholders( $p, $konto_neu ) );

		if ( $ok ) {
			Partners::mark_welcomed( (int) $p['id'] );
		}

		return (bool) $ok;
	}
}
