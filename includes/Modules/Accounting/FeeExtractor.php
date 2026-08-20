<?php
/**
 * Zahlungsgebühren aus Bestellungen herauslesen.
 *
 * Zahlungsdienstleister schreiben ihre Gebühr fast immer in eine
 * Bestellnotiz – „PayPal-Gebühr: 1,23 EUR", „Stripe fee: 0.55 EUR". Diese
 * Beträge gehören in die Buchhaltung, werden aber sonst von Hand abgetippt
 * oder vergessen. Beides ist teuer: vergessen kostet Gewinn in der EÜR,
 * abtippen kostet Zeit und Tippfehler.
 *
 * Die Erkennung ist bewusst zurückhaltend. Sie greift nur, wenn in einer
 * Zeile sowohl ein Gebührenwort als auch ein Betrag steht, und sie nimmt
 * niemals die größte Zahl einer Bestellung für eine Gebühr. Im Zweifel wird
 * nichts erkannt – eine falsche Buchung ist schlimmer als eine fehlende, weil
 * sie niemandem auffällt.
 *
 * Der gesamte Erkennungsteil kommt ohne Datenbank und ohne WooCommerce aus.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

/**
 * Findet Gebührenbeträge in Notizen und Metadaten.
 */
class FeeExtractor {

	/**
	 * Wörter, die eine Zeile als Gebührenzeile ausweisen.
	 */
	const KEYWORDS = array(
		'gebühr',
		'gebuehr',
		'fee',
		'entgelt',
		'provision',
		'disagio',
	);

	/**
	 * Wörter, die eine Zeile ausschließen, obwohl ein Gebührenwort vorkommt.
	 *
	 * „Gebühr erstattet" oder „keine Gebühr" darf keine Ausgabe erzeugen.
	 */
	const NEGATIONS = array(
		'keine',
		'ohne',
		'erstattet',
		'refund',
		'zurückerstattet',
		'zurueckerstattet',
		'storniert',
		'gutgeschrieben',
		'entfällt',
		'entfaellt',
	);

	/**
	 * Metaschlüssel, unter denen Zahlungsdienstleister ihre Gebühr ablegen.
	 *
	 * Werden bevorzugt gelesen: Ein Zahlenfeld ist verlässlicher als ein Satz.
	 * WooPayments steht vorn, weil es der hier eingesetzte Weg ist – es legt
	 * die Gebühr als fertige Dezimalzahl unter _wcpay_transaction_fee ab,
	 * geschrieben vom Webhook nach der Abrechnung.
	 */
	const META_KEYS = array(
		'_wcpay_transaction_fee',
		'_stripe_fee',
		'_paypal_transaction_fee',
		'_ppcp_paypal_fees',
		'_mollie_fee',
		'_wc_paypal_fee',
		'_transaction_fee',
		'_payment_fee',
	);

	/**
	 * Metaschlüssel mit der Währung, in der die Gebühr steht.
	 *
	 * WooPayments rechnet in der Währung der Zahlung, nicht zwingend in der
	 * des Shops. Weicht sie ab, wird nichts gebucht: Ein Dollarbetrag als
	 * Euro in der EÜR wäre schlimmer als eine fehlende Zeile.
	 */
	const CURRENCY_KEYS = array(
		'_wcpay_transaction_fee' => '_wcpay_intent_currency',
	);

	/**
	 * Obergrenze für einen glaubwürdigen Gebührenanteil am Bestellwert.
	 *
	 * Kein Zahlungsdienstleister nimmt die Hälfte. Was darüber liegt, ist
	 * mit hoher Wahrscheinlichkeit der Bestellbetrag selbst, der versehentlich
	 * in derselben Zeile stand.
	 */
	const MAX_SHARE = 0.5;

	/**
	 * Betrag aus einer Zeichenkette lesen – deutsche und englische Schreibweise.
	 *
	 * @param string $text Zeile.
	 * @return float|null Null, wenn kein Betrag erkennbar ist.
	 */
	public static function parse_amount( $text ) {
		$text = (string) $text;

		// Zahlen mit optionalen Tausendertrennern, Komma oder Punkt als
		// Dezimaltrenner. Bewusst zwei Nachkommastellen verlangt: Eine
		// Gebühr ohne Nachkommastellen kommt praktisch nicht vor, eine
		// Bestellnummer dagegen schon.
		if ( ! preg_match_all( '/(\d{1,3}(?:[.\s]\d{3})*|\d+)[,.](\d{2})(?!\d)/', $text, $matches, PREG_SET_ORDER ) ) {
			return null;
		}

		$best = null;

		foreach ( $matches as $match ) {
			$whole = preg_replace( '/[^\d]/', '', $match[1] );
			$value = (float) ( $whole . '.' . $match[2] );

			// Die kleinste plausible Zahl der Zeile gewinnt: Steht dort
			// „Gebühr 1,23 EUR von 45,60 EUR", ist die Gebühr die kleinere.
			if ( null === $best || $value < $best ) {
				$best = $value;
			}
		}

		return $best;
	}

	/**
	 * Ist das eine Gebührenzeile?
	 *
	 * @param string $text Zeile.
	 * @return bool
	 */
	public static function is_fee_line( $text ) {
		$text = mb_strtolower( (string) $text );

		if ( '' === trim( $text ) ) {
			return false;
		}

		$hit = false;

		foreach ( self::KEYWORDS as $word ) {
			if ( false !== mb_strpos( $text, $word ) ) {
				$hit = true;
				break;
			}
		}

		if ( ! $hit ) {
			return false;
		}

		foreach ( self::NEGATIONS as $word ) {
			if ( false !== mb_strpos( $text, $word ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Gebühr aus mehreren Notiztexten bestimmen – reine Logik.
	 *
	 * @param array $notes       Notiztexte.
	 * @param float $order_total Bestellwert zur Plausibilitätsprüfung, 0 = keine.
	 * @return array {amount, source} – amount null, wenn nichts erkannt wurde.
	 */
	public static function from_notes( array $notes, $order_total = 0 ) {
		$order_total = (float) $order_total;

		foreach ( $notes as $note ) {
			// Zeilenweise prüfen: In einer langen Notiz steht die Gebühr oft
			// neben Angaben, die selbst Beträge enthalten.
			foreach ( preg_split( '/\R/', (string) $note ) as $line ) {
				if ( ! self::is_fee_line( $line ) ) {
					continue;
				}

				$amount = self::parse_amount( $line );

				if ( null === $amount || $amount <= 0 ) {
					continue;
				}

				if ( ! self::is_plausible( $amount, $order_total ) ) {
					continue;
				}

				return array(
					'amount' => round( $amount, 2 ),
					'source' => trim( $line ),
				);
			}
		}

		return array(
			'amount' => null,
			'source' => '',
		);
	}

	/**
	 * Passt der Betrag zu einer Gebühr auf diesen Bestellwert?
	 *
	 * @param float $amount      Betrag.
	 * @param float $order_total Bestellwert, 0 = unbekannt.
	 * @return bool
	 */
	public static function is_plausible( $amount, $order_total ) {
		$amount      = (float) $amount;
		$order_total = (float) $order_total;

		if ( $amount <= 0 ) {
			return false;
		}

		if ( $order_total <= 0 ) {
			return true;
		}

		return $amount <= $order_total * self::MAX_SHARE;
	}

	/**
	 * Gebühr einer Bestellung bestimmen: erst Metadaten, dann Notizen.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array {amount, source}
	 */
	public static function for_order( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return array( 'amount' => null, 'source' => '' );
		}

		$total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;

		foreach ( self::META_KEYS as $key ) {
			$raw = $order->get_meta( $key );

			if ( '' === $raw || null === $raw || is_array( $raw ) ) {
				continue;
			}

			// Ein numerisches Feld ist eindeutig; nur wenn es keines ist,
			// wird der Text durchsucht. Andersherum würde "1.2" zu 12.
			$amount = is_numeric( $raw ) ? (float) $raw : self::parse_amount( (string) $raw );

			if ( null === $amount || ! self::is_plausible( $amount, $total ) ) {
				continue;
			}

			if ( ! self::currency_matches( $order, $key ) ) {
				continue;
			}

			return array(
				'amount' => round( $amount, 2 ),
				'source' => $key,
			);
		}

		return self::from_notes( self::order_notes( $order ), $total );
	}

	/**
	 * Steht die Gebühr in der Währung des Shops?
	 *
	 * @param \WC_Order $order Bestellung.
	 * @param string    $key   Metaschlüssel der Gebühr.
	 * @return bool
	 */
	protected static function currency_matches( $order, $key ) {
		if ( ! isset( self::CURRENCY_KEYS[ $key ] ) ) {
			return true;
		}

		$fee_currency = (string) $order->get_meta( self::CURRENCY_KEYS[ $key ] );

		if ( '' === $fee_currency ) {
			return true;
		}

		$order_currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '';

		if ( '' === $order_currency ) {
			return true;
		}

		return 0 === strcasecmp( $fee_currency, $order_currency );
	}

	/**
	 * Notiztexte einer Bestellung einsammeln.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array
	 */
	protected static function order_notes( $order ) {
		$texts = array();

		if ( method_exists( $order, 'get_customer_note' ) && $order->get_customer_note() ) {
			$texts[] = (string) $order->get_customer_note();
		}

		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return $texts;
		}

		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'limit'    => 100,
			)
		);

		foreach ( (array) $notes as $note ) {
			if ( isset( $note->content ) ) {
				$texts[] = (string) $note->content;
			}
		}

		return $texts;
	}
}
