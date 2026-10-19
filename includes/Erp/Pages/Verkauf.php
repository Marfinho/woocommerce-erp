<?php
/**
 * Verkauf im ERP: Kontakte-Akte, Shop-Bestellungen, Loyalty, Marketing.
 *
 * Native Fassungen der bisher eingebetteten wp-admin-Seiten. Sie rufen
 * dieselben Repositories auf wie die alten Seiten (Prüfungen, Rechte und
 * Protokolleinträge bleiben dort) und bringen nur die Oberfläche neu.
 * Reine Regeln stehen als statische Methoden oben und sind getestet
 * (tests/ErpVerkaufTest.php); die Abfragen darunter brauchen WordPress.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\CommissionBatch;
use Lindenweg16\Modules\Betrieb\CommissionRepository;
use Lindenweg16\Modules\Betrieb\ContactRepository;
use Lindenweg16\Modules\Betrieb\DeviceLabel;
use Lindenweg16\Modules\Betrieb\DiscountResolver;
use Lindenweg16\Modules\Betrieb\OrderOpsRepository;
use Lindenweg16\Modules\Betrieb\ReorderPage;
use Lindenweg16\Modules\Betrieb\ReorderToken;
use Lindenweg16\Modules\Betrieb\StockLedger;
use Lindenweg16\Modules\Betrieb\StockRepository;
use Lindenweg16\Modules\Betrieb\Reporting;
use Lindenweg16\Modules\Loyalty\LoyaltyRepository;
use Lindenweg16\Modules\Marketing\KampagnenReport;
use Lindenweg16\Modules\Marketing\KampagnenRepository;
use Lindenweg16\Modules\Marketing\Kampagnenrechnung;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Seiten der Navigation „Verkauf“.
 */
class Verkauf {

	/**
	 * Bestellungen je Seite in der Shop-Liste.
	 */
	const SHOP_PAGE = 40;

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank, getestet)
	 * ================================================================== */

	/**
	 * Zeilen einer Kommissionsliste aus der Anfrage bereinigen – reine Regel.
	 *
	 * Nur Zeilen mit Artikel und einer Eingabe bleiben; Farbe und Menge werden
	 * zu Text, damit CommissionBatch::validate() dieselben Wege geht wie die
	 * alte Seite. Mehr als MAX_LINES Zeilen werden nicht abgeschnitten, sondern
	 * unverändert weitergegeben, damit der Server sie ablehnen kann.
	 *
	 * @param mixed $raw Zeilen aus dem Anfragekörper.
	 * @return array Liste aus article_id, color, qty.
	 */
	public static function commission_rows( $raw ) {
		$out = array();

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$article = isset( $row['article_id'] ) ? (int) $row['article_id'] : 0;
			$qty     = isset( $row['qty'] ) && is_scalar( $row['qty'] ) ? trim( (string) $row['qty'] ) : '';

			if ( $article <= 0 || '' === $qty ) {
				continue;
			}

			$out[] = array(
				'article_id' => $article,
				'color'      => isset( $row['color'] ) && is_scalar( $row['color'] ) ? (string) $row['color'] : '',
				'qty'        => $qty,
			);
		}

		return $out;
	}

	/**
	 * Auswahl für drei Felder des Händlerprofils.
	 *
	 * @return array Feld => [Schlüssel => Text].
	 */
	public static function profile_options() {
		return array(
			'preferred_channel'    => array(
				'telefon'    => __( 'Telefon', 'lindenweg16' ),
				'mail'       => __( 'E-Mail', 'lindenweg16' ),
				'persoenlich' => __( 'persönlich', 'lindenweg16' ),
				'shop'       => __( 'im Shop', 'lindenweg16' ),
			),
			'order_frequency'      => array(
				'woechentlich'   => __( 'wöchentlich', 'lindenweg16' ),
				'monatlich'      => __( 'monatlich', 'lindenweg16' ),
				'quartalsweise'  => __( 'quartalsweise', 'lindenweg16' ),
				'saisonal'       => __( 'saisonal', 'lindenweg16' ),
				'unregelmaessig' => __( 'unregelmäßig', 'lindenweg16' ),
			),
			'packaging_preference' => array(
				'einzeln'  => __( 'einzeln verpackt', 'lindenweg16' ),
				'umkarton' => __( 'im Umkarton', 'lindenweg16' ),
				'display'  => __( 'im Display', 'lindenweg16' ),
			),
		);
	}

	/**
	 * Felder des Händlerprofils prüfen – reine Regel.
	 *
	 * @param array $in Eingaben.
	 * @return string[] Fehlertexte (leer = in Ordnung).
	 */
	public static function profile_errors( array $in ) {
		$errors = array();

		if ( isset( $in['vat_id'] ) && '' !== trim( (string) $in['vat_id'] ) ) {
			$vat = strtoupper( preg_replace( '/\s+/', '', (string) $in['vat_id'] ) );

			if ( ! preg_match( '/^[A-Z]{2}[A-Z0-9]{2,12}$/', $vat ) ) {
				$errors[] = __( 'Die USt-IdNr. sieht nicht richtig aus. Sie beginnt mit dem Ländercode, zum Beispiel DE123456789.', 'lindenweg16' );
			}
		}

		if ( isset( $in['credit_limit'] ) && '' !== trim( (string) $in['credit_limit'] ) ) {
			$limit = self::number( $in['credit_limit'] );

			if ( null === $limit || $limit < 0 ) {
				$errors[] = __( 'Das Kreditlimit muss eine Zahl ab 0 sein.', 'lindenweg16' );
			}
		}

		if ( isset( $in['next_expected_order'] ) && '' !== trim( (string) $in['next_expected_order'] ) && ! self::is_date( $in['next_expected_order'] ) ) {
			$errors[] = __( 'Die nächste erwartete Bestellung ist kein gültiges Datum.', 'lindenweg16' );
		}

		foreach ( self::profile_options() as $field => $options ) {
			if ( isset( $in[ $field ] ) && '' !== (string) $in[ $field ] && ! isset( $options[ $in[ $field ] ] ) ) {
				$errors[] = __( 'Eine Auswahl im Profil gibt es nicht.', 'lindenweg16' );
			}
		}

		return $errors;
	}

	/**
	 * Bisheriges Profil und neue Eingaben zusammenführen – reine Regel.
	 *
	 * ContactRepository::save_profile() setzt jedes nicht mitgeschickte Feld
	 * auf leer, auch die Artikellisten, die diese Oberfläche gar nicht zeigt.
	 * Hier bleibt deshalb alles, was nicht geändert wird, wie es war.
	 *
	 * @param array|null $before Bisheriges Profil (Spalten) oder null.
	 * @param array      $in     Eingaben.
	 * @return array Eingaben für save_profile().
	 */
	public static function profile_merge( $before, array $in ) {
		$text = array(
			'billing_company', 'billing_address', 'billing_email', 'vat_id', 'shipping_address', 'delivery_notes',
			'decision_maker', 'goods_receiver', 'preferred_channel', 'best_time', 'preferred_colors', 'season_peak',
			'order_frequency', 'packaging_preference', 'shelf_situation', 'competitor_products', 'next_expected_order', 'credit_limit',
		);
		$out  = array();

		foreach ( $text as $field ) {
			if ( array_key_exists( $field, $in ) ) {
				$out[ $field ] = is_scalar( $in[ $field ] ) ? trim( (string) $in[ $field ] ) : '';
			} elseif ( $before && isset( $before[ $field ] ) ) {
				$out[ $field ] = (string) $before[ $field ];
			}
		}

		if ( isset( $out['vat_id'] ) ) {
			$out['vat_id'] = strtoupper( preg_replace( '/\s+/', '', $out['vat_id'] ) );
		}

		if ( isset( $out['credit_limit'] ) && '' !== $out['credit_limit'] ) {
			$limit = self::number( $out['credit_limit'] );
			$out['credit_limit'] = null === $limit ? '' : (string) $limit;
		}

		foreach ( array( 'display_provided', 'commission_allowed' ) as $flag ) {
			if ( array_key_exists( $flag, $in ) ) {
				$out[ $flag ] = ! empty( $in[ $flag ] ) && 'false' !== $in[ $flag ] && '0' !== (string) $in[ $flag ] ? 1 : 0;
			} elseif ( $before && isset( $before[ $flag ] ) ) {
				$out[ $flag ] = (int) $before[ $flag ];
			}
		}

		foreach ( array( 'preferred_articles', 'excluded_articles' ) as $list ) {
			if ( $before && ! empty( $before[ $list ] ) ) {
				$decoded = json_decode( (string) $before[ $list ], true );

				if ( is_array( $decoded ) ) {
					$out[ $list ] = $decoded;
				}
			}
		}

		return $out;
	}

	/**
	 * Zahl aus einer Eingabe lesen, auch mit deutschem Komma.
	 *
	 * @param mixed $raw Eingabe.
	 * @return float|null Null bei leer oder keine Zahl.
	 */
	public static function number( $raw ) {
		$raw = trim( (string) $raw );

		// „1.500,00“: Punkt als Tausender, Komma als Dezimalzeichen.
		if ( false !== strpos( $raw, '.' ) && false !== strpos( $raw, ',' ) ) {
			$raw = str_replace( '.', '', $raw );
		}

		$raw = str_replace( ',', '.', $raw );

		return '' !== $raw && is_numeric( $raw ) ? (float) $raw : null;
	}

	/**
	 * Ist das ein Datum in der Form JJJJ-MM-TT, das es gibt?
	 *
	 * @param string $raw Eingabe.
	 * @return bool
	 */
	public static function is_date( $raw ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $raw, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Eingaben eines Kontakts prüfen.
	 *
	 * @param array $in Felder: company, email, follow_up_on, type, status.
	 * @return string[] Fehlertexte, leer wenn alles stimmt.
	 */
	public static function contact_errors( array $in ) {
		$errors = array();

		if ( '' === trim( isset( $in['company'] ) ? (string) $in['company'] : '' ) ) {
			$errors[] = __( 'Ein Firmen- oder Personenname ist Pflicht.', 'lindenweg16' );
		}

		$email = trim( isset( $in['email'] ) ? (string) $in['email'] : '' );
		if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors[] = __( 'Die E-Mail-Adresse sieht nicht richtig aus. Bitte prüfen oder das Feld leer lassen.', 'lindenweg16' );
		}

		$follow = trim( isset( $in['follow_up_on'] ) ? (string) $in['follow_up_on'] : '' );
		if ( '' !== $follow && ! self::is_date( $follow ) ) {
			$errors[] = __( 'Die Wiedervorlage ist kein gültiges Datum.', 'lindenweg16' );
		}

		if ( isset( $in['type'] ) && '' !== $in['type'] && ! in_array( $in['type'], ContactRepository::TYPES, true ) ) {
			$errors[] = __( 'Diesen Kontakttyp gibt es nicht.', 'lindenweg16' );
		}

		if ( isset( $in['status'] ) && '' !== $in['status'] && ! in_array( $in['status'], ContactRepository::STATUS, true ) ) {
			$errors[] = __( 'Diesen Status gibt es nicht.', 'lindenweg16' );
		}

		return $errors;
	}

	/**
	 * Eingaben einer Konditionsstufe prüfen.
	 *
	 * @param array $in Felder: min_order_value, discount_pct, payment_terms_days, valid_from.
	 * @return string[]
	 */
	public static function term_errors( array $in ) {
		$errors = array();

		foreach ( array(
			'min_order_value'    => __( 'Der Bestellwert', 'lindenweg16' ),
			'discount_pct'       => __( 'Der Rabatt', 'lindenweg16' ),
			'payment_terms_days' => __( 'Das Zahlungsziel', 'lindenweg16' ),
		) as $key => $label ) {
			if ( isset( $in[ $key ] ) && '' !== trim( (string) $in[ $key ] ) && null === self::number( $in[ $key ] ) ) {
				/* translators: %s: Feldname, z. B. „Der Rabatt“. */
				$errors[] = sprintf( __( '%s muss eine Zahl sein.', 'lindenweg16' ), $label );
			}
		}

		$min = self::number( isset( $in['min_order_value'] ) ? $in['min_order_value'] : '' );
		if ( null !== $min && $min < 0 ) {
			$errors[] = __( 'Ein negativer Bestellwert ergibt keinen Sinn.', 'lindenweg16' );
		}

		$pct = self::number( isset( $in['discount_pct'] ) ? $in['discount_pct'] : '' );
		if ( null !== $pct && ( $pct < 0 || $pct >= 100 ) ) {
			$errors[] = __( 'Der Rabatt muss zwischen 0 und unter 100 Prozent liegen.', 'lindenweg16' );
		}

		$days = self::number( isset( $in['payment_terms_days'] ) ? $in['payment_terms_days'] : '' );
		if ( null !== $days && ( $days < 0 || $days > 365 ) ) {
			$errors[] = __( 'Das Zahlungsziel liegt zwischen 0 und 365 Tagen.', 'lindenweg16' );
		}

		$from = trim( isset( $in['valid_from'] ) ? (string) $in['valid_from'] : '' );
		if ( '' !== $from && ! self::is_date( $from ) ) {
			$errors[] = __( 'Das Datum „gültig ab“ ist kein gültiges Datum.', 'lindenweg16' );
		}

		return $errors;
	}

	/**
	 * Welche Rabattstufe gilt für einen Kontakt?
	 *
	 * @param array $terms Gültige Sonderkonditionen (min_order_value, discount_pct).
	 * @return array {kind: standard|sonder, label, max}
	 */
	public static function contact_tier( array $terms ) {
		if ( ! $terms ) {
			return array(
				'kind'  => 'standard',
				'label' => __( 'Standardstaffel', 'lindenweg16' ),
				'max'   => 0.0,
			);
		}

		$pcts = array();
		foreach ( $terms as $term ) {
			$pcts[] = (float) $term['discount_pct'];
		}

		$lo = min( $pcts );
		$hi = max( $pcts );
		$f  = static function ( $n ) {
			return rtrim( rtrim( number_format( (float) $n, 1, ',', '' ), '0' ), ',' );
		};

		return array(
			'kind'  => 'sonder',
			/* translators: %s: Rabatt, z. B. „10 %“ oder „10–15 %“. */
			'label' => sprintf( __( 'Sonderkondition %s', 'lindenweg16' ), $lo === $hi ? $f( $hi ) . ' %' : $f( $lo ) . '–' . $f( $hi ) . ' %' ),
			'max'   => $hi,
		);
	}

	/**
	 * Kontaktliste filtern: Suche (Firma, Ort, Ansprechpartner, Mail …), Typ, Rabattstufe.
	 *
	 * @param array  $rows  Kontakte mit company, city, zip, contact_person, email, phone, type, tier (kind).
	 * @param string $q     Suchtext.
	 * @param string $type  Typ oder leer.
	 * @param string $stufe standard, sonder oder leer.
	 * @return array
	 */
	public static function filter_contacts( array $rows, $q, $type, $stufe ) {
		$q = self::fold( $q );

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $q, $type, $stufe ) {
					if ( '' !== $type && $row['type'] !== $type ) {
						return false;
					}

					if ( '' !== $stufe && $row['tier']['kind'] !== $stufe ) {
						return false;
					}

					if ( '' === $q ) {
						return true;
					}

					$hay = '';
					foreach ( array( 'company', 'city', 'zip', 'contact_person', 'email', 'phone' ) as $key ) {
						$hay .= ' ' . ( isset( $row[ $key ] ) ? (string) $row[ $key ] : '' );
					}

					return false !== strpos( self::fold( $hay ), $q );
				}
			)
		);
	}

	/**
	 * Text für die Suche vereinfachen: klein, ohne Umlaut-Unterschiede.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function fold( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $text ), 'UTF-8' ) : strtolower( trim( (string) $text ) );

		return strtr( $text, array( 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss' ) );
	}

	/**
	 * Kennzahlen je Kontakt aus seinen Bestellungen.
	 *
	 * @param array $orders Zeilen: contact_id, status (order_status), invoice (invoice_status), value.
	 * @return array Kontakt-ID => {revenue, orders, open}
	 */
	public static function stats_by_contact( array $orders ) {
		$out = array();

		foreach ( $orders as $order ) {
			$cid = (int) $order['contact_id'];

			if ( ! $cid ) {
				continue;
			}

			if ( ! isset( $out[ $cid ] ) ) {
				$out[ $cid ] = array( 'revenue' => 0.0, 'orders' => 0, 'open' => 0.0 );
			}

			$out[ $cid ]['orders']++;

			if ( 'bestaetigt' === $order['status'] ) {
				$out[ $cid ]['revenue'] += (float) $order['value'];

				if ( self::is_open_invoice( $order['status'], $order['invoice'] ) ) {
					$out[ $cid ]['open'] += (float) $order['value'];
				}
			}
		}

		return $out;
	}

	/**
	 * Ist das ein offener Posten? Bestätigte Bestellung, Rechnung noch nicht bezahlt.
	 *
	 * @param string $order_status   Auftragsstatus.
	 * @param string $invoice_status Rechnungsstatus.
	 * @return bool
	 */
	public static function is_open_invoice( $order_status, $invoice_status ) {
		return 'bestaetigt' === $order_status && in_array( $invoice_status, array( 'offen', 'berechnet' ), true );
	}

	/**
	 * Fälligkeitsdatum einer Rechnung.
	 *
	 * @param string $invoice_date Rechnungsdatum (JJJJ-MM-TT) oder leer.
	 * @param int    $days         Zahlungsziel in Tagen.
	 * @return string JJJJ-MM-TT oder leer, wenn es noch keine Rechnung gibt.
	 */
	public static function due_date( $invoice_date, $days ) {
		if ( ! self::is_date( substr( (string) $invoice_date, 0, 10 ) ) ) {
			return '';
		}

		return gmdate( 'Y-m-d', strtotime( substr( (string) $invoice_date, 0, 10 ) . ' 12:00:00 UTC' ) + max( 0, (int) $days ) * 86400 );
	}

	/**
	 * Ampel für das Alter von Kommissionsware.
	 *
	 * @param int $days Tage.
	 * @return string rot, gelb oder gruen.
	 */
	public static function commission_age( $days ) {
		return $days > 180 ? 'rot' : ( $days > 90 ? 'gelb' : 'gruen' );
	}

	/**
	 * Bezeichnung eines Auftragsstatus.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function order_status_label( $status ) {
		$labels = array(
			'entwurf'    => __( 'Entwurf', 'lindenweg16' ),
			'bestaetigt' => __( 'bestätigt', 'lindenweg16' ),
			'storniert'  => __( 'storniert', 'lindenweg16' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Bezeichnung eines Rechnungsstatus.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function invoice_label( $status ) {
		$labels = array(
			'offen'     => __( 'noch keine Rechnung', 'lindenweg16' ),
			'berechnet' => __( 'Rechnung offen', 'lindenweg16' ),
			'bezahlt'   => __( 'bezahlt', 'lindenweg16' ),
			'storniert' => __( 'storniert', 'lindenweg16' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Zahlstatus einer Shop-Bestellung – aus Tatsachen, nicht aus einem Merker.
	 *
	 * @param string $status   WooCommerce-Status ohne „wc-“.
	 * @param bool   $paid     Zahldatum vorhanden.
	 * @param float  $total    Summe.
	 * @param float  $refunded Erstattet.
	 * @return array {key, label}
	 */
	public static function pay_state( $status, $paid, $total, $refunded ) {
		$status = preg_replace( '/^wc-/', '', (string) $status );

		if ( in_array( $status, array( 'cancelled' ), true ) ) {
			return array( 'key' => 'cancelled', 'label' => __( 'storniert', 'lindenweg16' ) );
		}

		if ( 'failed' === $status ) {
			return array( 'key' => 'failed', 'label' => __( 'Zahlung fehlgeschlagen', 'lindenweg16' ) );
		}

		if ( in_array( $status, array( 'checkout-draft', 'auto-draft', 'draft' ), true ) ) {
			return array( 'key' => 'draft', 'label' => __( 'Entwurf', 'lindenweg16' ) );
		}

		if ( 'refunded' === $status || ( $total > 0 && $refunded >= $total ) ) {
			return array( 'key' => 'refunded', 'label' => __( 'erstattet', 'lindenweg16' ) );
		}

		if ( $refunded > 0 ) {
			return array( 'key' => 'part_refunded', 'label' => __( 'teilweise erstattet', 'lindenweg16' ) );
		}

		if ( $paid || in_array( $status, array( 'processing', 'completed' ), true ) ) {
			return array( 'key' => 'paid', 'label' => __( 'bezahlt', 'lindenweg16' ) );
		}

		return array( 'key' => 'open', 'label' => __( 'Zahlung offen', 'lindenweg16' ) );
	}

	/**
	 * Statusfilter der Shop-Liste bereinigen.
	 *
	 * @param string   $raw   Eingabe (mit oder ohne „wc-“).
	 * @param string[] $known Bekannte Schlüssel (mit „wc-“).
	 * @return string Schlüssel mit „wc-“ oder leer für alle.
	 */
	public static function shop_status( $raw, array $known ) {
		$raw = strtolower( trim( (string) $raw ) );

		if ( '' === $raw ) {
			return '';
		}

		$raw = 0 === strpos( $raw, 'wc-' ) ? $raw : 'wc-' . $raw;

		return in_array( $raw, $known, true ) ? $raw : '';
	}

	/**
	 * Darf eine Shop-Bestellung hier mit dem Versand weiterlaufen?
	 *
	 * Ein Statuswechsel gehört bewusst nicht in diese Liste: „in Bearbeitung →
	 * abgeschlossen“ passiert beim Versenden (Lagerabgang, Etikett), alles
	 * andere ist Sache von WooCommerce.
	 *
	 * @param string $status Status ohne „wc-“.
	 * @return bool
	 */
	public static function awaits_shipping( $status ) {
		return in_array( preg_replace( '/^wc-/', '', (string) $status ), array( 'processing', 'on-hold' ), true );
	}

	/**
	 * Entscheidung über einen Prüffall bei Empfehlungen.
	 *
	 * @param string $decision approve oder reject.
	 * @param string $status   Aktueller Status des Falls.
	 * @return array {ok, to, action, message}
	 */
	public static function referral_decision( $decision, $status ) {
		if ( 'review' !== $status ) {
			return array(
				'ok'      => false,
				'message' => __( 'Dieser Fall ist nicht mehr in Prüfung – bitte die Liste neu laden.', 'lindenweg16' ),
			);
		}

		if ( 'approve' === $decision ) {
			return array(
				'ok'      => true,
				'to'      => 'pending',
				'action'  => 'referral_freigegeben',
				'message' => __( 'Empfehlung freigegeben.', 'lindenweg16' ),
			);
		}

		if ( 'reject' === $decision ) {
			return array(
				'ok'      => true,
				'to'      => 'reversed',
				'action'  => 'referral_abgelehnt',
				'message' => __( 'Empfehlung abgelehnt.', 'lindenweg16' ),
			);
		}

		return array(
			'ok'      => false,
			'message' => __( 'Bitte „Freigeben“ oder „Ablehnen“ wählen.', 'lindenweg16' ),
		);
	}

	/**
	 * Bezeichnung einer Empfehlung nach Status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function referral_label( $status ) {
		$labels = array(
			'review'    => __( 'in Prüfung', 'lindenweg16' ),
			'pending'   => __( 'wartet auf die Wartezeit', 'lindenweg16' ),
			'completed' => __( 'gutgeschrieben', 'lindenweg16' ),
			'reversed'  => __( 'zurückgenommen', 'lindenweg16' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Bezeichnung eines Punkte-Eintrags.
	 *
	 * @param string $type   Art.
	 * @param string $status Status.
	 * @return string
	 */
	public static function ledger_label( $type, $status ) {
		$types    = array(
			'earned'   => __( 'Punkte gesammelt', 'lindenweg16' ),
			'redeemed' => __( 'Punkte eingelöst', 'lindenweg16' ),
			'reversed' => __( 'Punkte zurückgebucht', 'lindenweg16' ),
		);
		$statuses = array(
			'pending'   => __( 'noch nicht verfügbar', 'lindenweg16' ),
			'available' => __( 'verfügbar', 'lindenweg16' ),
			'reversed'  => __( 'zurückgenommen', 'lindenweg16' ),
		);

		return ( isset( $types[ $type ] ) ? $types[ $type ] : (string) $type ) . ' · ' . ( isset( $statuses[ $status ] ) ? $statuses[ $status ] : (string) $status );
	}

	/**
	 * Bezeichnung eines Kampagnentyps.
	 *
	 * @param string $typ Typ.
	 * @return string
	 */
	public static function campaign_type_label( $typ ) {
		$labels = array(
			'event'      => __( 'Event / Markt', 'lindenweg16' ),
			'online_ads' => __( 'Online-Anzeigen', 'lindenweg16' ),
			'print'      => __( 'Print', 'lindenweg16' ),
			'sonstiges'  => __( 'Sonstiges', 'lindenweg16' ),
		);

		return isset( $labels[ $typ ] ) ? $labels[ $typ ] : (string) $typ;
	}

	/**
	 * Zeitraum einer Kampagne als Text.
	 *
	 * @param array $k Felder: event_datum, start_datum, ende_datum.
	 * @return string
	 */
	public static function campaign_period( array $k ) {
		if ( ! empty( $k['event_datum'] ) ) {
			return (string) $k['event_datum'];
		}

		if ( ! empty( $k['ende_datum'] ) ) {
			return $k['start_datum'] . ' – ' . $k['ende_datum'];
		}

		return isset( $k['start_datum'] ) ? (string) $k['start_datum'] : '';
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns = RestApi::NAMESPACE_V1;

		// Kontakte.
		$kontakte = $api->needs( array( 'verkauf.kontakte' ) );
		register_rest_route( $ns, '/erp/kontakte', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'contacts_list' ), 'permission_callback' => $kontakte ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'contact_create' ), 'permission_callback' => $kontakte ),
		) );
		register_rest_route( $ns, '/erp/kontakte/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'contact_file' ), 'permission_callback' => $kontakte ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'contact_update' ), 'permission_callback' => $kontakte ),
		) );
		register_rest_route( $ns, '/erp/kontakte/(?P<id>\d+)/aktivitaet', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'contact_activity' ),
			'permission_callback' => $kontakte,
		) );
		register_rest_route( $ns, '/erp/kontakte/(?P<id>\d+)/kondition', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'contact_term' ),
			'permission_callback' => $kontakte,
		) );
		register_rest_route( $ns, '/erp/kontakte/(?P<id>\d+)/profil', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'contact_profile' ),
			'permission_callback' => $api->needs( array( 'verkauf.kontakte', 'fertigung.stammdaten.edit' ) ),
		) );
		register_rest_route( $ns, '/erp/kontakte/(?P<id>\d+)/kommission', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'commission_options' ), 'permission_callback' => $kontakte ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'commission_book' ), 'permission_callback' => $kontakte ),
		) );
		register_rest_route( $ns, '/erp/kontakte/(?P<id>\d+)/link', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'contact_link' ),
			'permission_callback' => $kontakte,
		) );

		// Shop-Bestellungen: ERP-Recht und WordPress-Rolle wie beim Menüpunkt.
		register_rest_route( $ns, '/erp/shop-bestellungen', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'shop_orders' ),
			'permission_callback' => function () use ( $api ) {
				$perm = $api->needs( array( 'verkauf.shop_orders' ) );

				if ( ! $perm() ) {
					return false;
				}

				if ( ! current_user_can( 'edit_shop_orders' ) ) {
					return new WP_Error( 'lw16_erp_wp_role', __( 'Dafür braucht dein WordPress-Konto die Rolle „Shop-Manager“.', 'lindenweg16' ), array( 'status' => 403 ) );
				}

				return true;
			},
		) );

		// Loyalty.
		$loyalty = $api->needs( array( 'verkauf.loyalty' ) );
		register_rest_route( $ns, '/erp/loyalty', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'loyalty' ),
			'permission_callback' => $loyalty,
		) );
		register_rest_route( $ns, '/erp/loyalty/pruefung/(?P<id>\d+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'loyalty_decide' ),
			'permission_callback' => $loyalty,
		) );
		register_rest_route( $ns, '/erp/loyalty/kunden', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'loyalty_members' ),
			'permission_callback' => $loyalty,
		) );
		register_rest_route( $ns, '/erp/loyalty/kunden/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'loyalty_member' ),
			'permission_callback' => $loyalty,
		) );

		// Marketing (nur lesen; Anlegen, Belege zuordnen, Kilometer bleiben in der klassischen Ansicht).
		$marketing = $api->needs( array( 'verkauf.marketing' ) );
		register_rest_route( $ns, '/erp/marketing', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'marketing' ),
			'permission_callback' => $marketing,
		) );
		register_rest_route( $ns, '/erp/marketing/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'campaign' ),
			'permission_callback' => $marketing,
		) );
	}

	/**
	 * Fehlerantwort mit lesbarem Text.
	 *
	 * @param string $message Text.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Eingaben aus dem Anfragekörper: nur einfache Werte.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array
	 */
	protected static function input( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		return array_filter( (array) $params, 'is_scalar' );
	}

	/* ====================================================================
	 * Kontakte
	 * ================================================================== */

	/**
	 * Alle Bestellungen als schlanke Zeilen für die Kennzahlen.
	 *
	 * @param array $args Filter für OrderOpsRepository::all().
	 * @return array
	 */
	protected static function order_rows( array $args ) {
		$rows = array();

		foreach ( OrderOpsRepository::all( $args ) as $order ) {
			$rows[] = array(
				'contact_id' => (int) $order['contact_id'],
				'status'     => (string) $order['order_status'],
				'invoice'    => (string) $order['invoice_status'],
				'value'      => OrderOpsRepository::order_value( $order ),
				'raw'        => $order,
			);
		}

		return $rows;
	}

	/**
	 * Heute geltende Sonderkonditionen aller Kontakte, nach Kontakt gruppiert.
	 *
	 * @return array
	 */
	protected static function active_terms() {
		global $wpdb;

		$table = $wpdb->prefix . 'lw16_contact_terms';
		$today = gmdate( 'Y-m-d' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT contact_id, min_order_value, discount_pct FROM {$table} WHERE valid_from <= %s AND ( valid_until IS NULL OR valid_until >= %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today,
				$today
			),
			ARRAY_A
		);
		$out   = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['contact_id'] ][] = $row;
		}

		return $out;
	}

	/**
	 * Kontaktliste mit Suche, Typ und Rabattstufe.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function contacts_list( WP_REST_Request $request ) {
		$stats = self::stats_by_contact( self::order_rows( array( 'limit' => 5000 ) ) );
		$terms = self::active_terms();
		$due   = array();

		foreach ( ContactRepository::due_follow_ups() as $row ) {
			$due[ (int) $row['id'] ] = true;
		}

		$rows = array();

		foreach ( ContactRepository::all() as $c ) {
			$id     = (int) $c['id'];
			$stat   = isset( $stats[ $id ] ) ? $stats[ $id ] : array( 'revenue' => 0.0, 'orders' => 0, 'open' => 0.0 );
			$rows[] = array(
				'id'             => $id,
				'company'        => (string) $c['company'],
				'type'           => (string) $c['type'],
				'city'           => (string) $c['city'],
				'zip'            => (string) $c['zip'],
				'contact_person' => (string) $c['contact_person'],
				'email'          => (string) $c['email'],
				'phone'          => (string) $c['phone'],
				'status'         => (string) $c['status'],
				'follow_up_on'   => (string) $c['follow_up_on'],
				'due'            => isset( $due[ $id ] ),
				'tier'           => self::contact_tier( isset( $terms[ $id ] ) ? $terms[ $id ] : array() ),
				'revenue'        => round( $stat['revenue'], 2 ),
				'orders'         => $stat['orders'],
				'open'           => round( $stat['open'], 2 ),
			);
		}

		$type  = sanitize_key( (string) $request->get_param( 'typ' ) );
		$stufe = sanitize_key( (string) $request->get_param( 'stufe' ) );
		$total = count( $rows );
		$rows  = self::filter_contacts( $rows, (string) $request->get_param( 'q' ), $type, in_array( $stufe, array( 'standard', 'sonder' ), true ) ? $stufe : '' );

		$tiers = array();
		foreach ( DiscountResolver::tiers_for_contact( 0, gmdate( 'Y-m-d' ) )['tiers'] as $t ) {
			$tiers[] = array(
				'name' => isset( $t['tier_name'] ) ? (string) $t['tier_name'] : '',
				'min'  => (float) $t['min_order_value'],
				'pct'  => (float) $t['discount_pct'],
			);
		}

		$report = array();
		foreach ( Reporting::b2b() as $row ) {
			$report[] = array(
				'id'            => (int) $row['contact']['id'],
				'company'       => (string) $row['contact']['company'],
				'orders'        => (int) $row['orders'],
				'revenue'       => round( (float) $row['revenue'], 2 ),
				'reorder'       => (bool) $row['reorder'],
				'days_to_first' => $row['days_to_first'],
			);
		}

		return new WP_REST_Response(
			array(
				'contacts' => $rows,
				'total'    => $total,
				'types'    => ContactRepository::type_labels(),
				'status'   => ContactRepository::status_labels(),
				'standard' => $tiers,
				'b2b'      => $report,
				'rate'     => $report ? Reporting::reorder_rate() : null,
			)
		);
	}

	/**
	 * Eingaben für ContactRepository::save() zusammenstellen.
	 *
	 * @param array $in       Eingaben.
	 * @param array $existing Bisheriger Kontakt oder leer.
	 * @return array
	 */
	protected static function contact_input( array $in, array $existing = array() ) {
		$fields = array( 'type', 'company', 'street', 'zip', 'city', 'contact_person', 'phone', 'email', 'source', 'status', 'follow_up_on', 'note' );
		$data   = array();

		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $in ) ) {
				$data[ $field ] = (string) $in[ $field ];
			} elseif ( isset( $existing[ $field ] ) ) {
				// Was nicht mitgeschickt wird, bleibt, wie es ist.
				$data[ $field ] = (string) $existing[ $field ];
			}
		}

		return $data;
	}

	/**
	 * Kontakt anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function contact_create( WP_REST_Request $request ) {
		$data   = self::contact_input( self::input( $request ) );
		$errors = self::contact_errors( $data );

		if ( $errors ) {
			return self::fail( implode( ' ', $errors ) );
		}

		$result = ContactRepository::save( $data );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $result['id'], 'message' => __( 'Kontakt angelegt.', 'lindenweg16' ) ), 201 );
	}

	/**
	 * Stammdaten eines Kontakts ändern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function contact_update( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$contact = ContactRepository::find( $id );

		if ( ! $contact ) {
			return self::fail( __( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$data   = self::contact_input( self::input( $request ), $contact );
		$errors = self::contact_errors( $data );

		if ( $errors ) {
			return self::fail( implode( ' ', $errors ) );
		}

		$data['contact_id'] = $id;
		$result             = ContactRepository::save( $data );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		return new WP_REST_Response( array( 'ok' => true, 'id' => $id, 'message' => __( 'Kontakt gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Die Akte: Stammdaten, Kennzahlen, Konditionen, Bestellungen, offene Posten, Aktivitäten, Nachbestell-Link.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function contact_file( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$contact = ContactRepository::find( $id );

		if ( ! $contact ) {
			return self::fail( __( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$rows   = self::order_rows( array( 'contact_id' => $id, 'limit' => 1000 ) );
		$stats  = self::stats_by_contact( $rows );
		$stat   = isset( $stats[ $id ] ) ? $stats[ $id ] : array( 'revenue' => 0.0, 'orders' => 0, 'open' => 0.0 );
		$orders = array();
		$open   = array();

		foreach ( $rows as $row ) {
			$o       = $row['raw'];
			$due     = self::due_date( (string) $o['invoice_date'], (int) $o['payment_terms_days'] );
			$overdue = OrderOpsRepository::is_overdue( $o );
			$entry   = array(
				'id'      => (int) $o['id'],
				'wc'      => (int) $o['wc_order_id'],
				'date'    => OrderOpsRepository::order_date( $o ),
				'value'   => round( $row['value'], 2 ),
				'status'  => (string) $o['order_status'],
				'label'   => self::order_status_label( $o['order_status'] ),
				'invoice' => (string) $o['invoice_status'],
				'ilabel'  => self::invoice_label( $o['invoice_status'] ),
				'due'     => $due,
				'overdue' => $overdue,
			);

			if ( count( $orders ) < 100 ) {
				$orders[] = $entry;
			}

			if ( self::is_open_invoice( $row['status'], $row['invoice'] ) ) {
				$open[] = $entry;
			}
		}

		$resolved = DiscountResolver::tiers_for_contact( $id, gmdate( 'Y-m-d' ) );
		$tiers    = array();

		foreach ( $resolved['tiers'] as $t ) {
			$tiers[] = array( 'min' => (float) $t['min_order_value'], 'pct' => (float) $t['discount_pct'] );
		}

		$terms = array();

		foreach ( ContactRepository::terms( $id ) as $t ) {
			$terms[] = array(
				'min'    => (float) $t['min_order_value'],
				'pct'    => (float) $t['discount_pct'],
				'days'   => (int) $t['payment_terms_days'],
				'from'   => (string) $t['valid_from'],
				'until'  => (string) $t['valid_until'],
				'by'     => (string) $t['agreed_by'],
			);
		}

		$articles   = ArticleRepository::all_by_id();
		$commission = array();

		foreach ( ContactRepository::commission_stock( $id ) as $c ) {
			$commission[] = array(
				'sku'   => isset( $articles[ (int) $c['article_id'] ] ) ? (string) $articles[ (int) $c['article_id'] ]['sku'] : '',
				'color' => ColorRegistry::label( $c['color'] ),
				'qty'   => (float) $c['qty'],
				'days'  => (int) $c['age_days'],
				'age'   => self::commission_age( (int) $c['age_days'] ),
			);
		}

		$activities = array();

		foreach ( ContactRepository::activities( $id ) as $a ) {
			$activities[] = array(
				'date'   => (string) $a['activity_date'],
				'type'   => (string) $a['activity_type'],
				'note'   => (string) $a['summary'],
				'next'   => (string) $a['next_step'],
				'follow' => (string) $a['follow_up_on'],
			);
		}

		$link = array( 'state' => 'none', 'expires' => '', 'uses' => 0 );

		foreach ( ReorderToken::for_contact( $id ) as $token ) {
			$check = ReorderToken::check( $token );

			if ( $check['valid'] ) {
				$link = array( 'state' => 'active', 'expires' => substr( (string) $token['expires_at'], 0, 10 ), 'uses' => (int) $token['use_count'] );
				break;
			}
		}

		$unlinked = ReorderPage::unlinked_products();

		return new WP_REST_Response(
			array(
				'contact'    => array(
					'id'             => $id,
					'type'           => (string) $contact['type'],
					'company'        => (string) $contact['company'],
					'street'         => (string) $contact['street'],
					'zip'            => (string) $contact['zip'],
					'city'           => (string) $contact['city'],
					'contact_person' => (string) $contact['contact_person'],
					'phone'          => (string) $contact['phone'],
					'email'          => (string) $contact['email'],
					'source'         => (string) $contact['source'],
					'status'         => (string) $contact['status'],
					'follow_up_on'   => (string) $contact['follow_up_on'],
					'note'           => (string) $contact['note'],
				),
				'tiles'      => array(
					'revenue' => round( $stat['revenue'], 2 ),
					'orders'  => $stat['orders'],
					'open'    => round( $stat['open'], 2 ),
				),
				'tier'       => array(
					'source' => (string) $resolved['source'],
					'tiers'  => $tiers,
					'days'   => (int) $resolved['payment_terms_days'],
				),
				'terms'      => $terms,
				'orders'     => $orders,
				'open'       => $open,
				'commission' => $commission,
				'activities' => $activities,
				'link'       => $link,
				'unlinked'   => array(
					'count' => count( $unlinked ),
					'names' => array_slice( array_map( 'strval', $unlinked ), 0, 8 ),
				),
				'profile'    => self::profile_view( ContactRepository::profile( $id ) ),
				'profile_options' => self::profile_options(),
				'can_profile' => Access::can( 'fertigung.stammdaten.edit' ),
				'types'      => ContactRepository::type_labels(),
				'status'     => ContactRepository::status_labels(),
				'activity_types' => ContactRepository::activity_labels(),
				'can_orders' => Access::can( 'verkauf.versand' ),
			)
		);
	}

	/**
	 * Profil für die Oberfläche: alle Felder, leere als leerer Text.
	 *
	 * @param array|null $row Profil aus der Datenbank.
	 * @return array
	 */
	protected static function profile_view( $row ) {
		$fields = array(
			'billing_company', 'billing_address', 'billing_email', 'vat_id', 'shipping_address', 'delivery_notes',
			'decision_maker', 'goods_receiver', 'preferred_channel', 'best_time', 'preferred_colors', 'season_peak',
			'order_frequency', 'packaging_preference', 'shelf_situation', 'competitor_products', 'next_expected_order', 'credit_limit',
		);
		$out    = array();

		foreach ( $fields as $field ) {
			$out[ $field ] = $row && isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
		}

		$out['display_provided']   = $row ? (bool) (int) $row['display_provided'] : false;
		$out['commission_allowed'] = $row ? (bool) (int) $row['commission_allowed'] : false;
		$out['exists']             = (bool) $row;

		return $out;
	}

	/**
	 * Händlerprofil speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function contact_profile( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! ContactRepository::find( $id ) ) {
			return self::fail( __( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$in     = (array) $request->get_json_params();
		$errors = self::profile_errors( $in );

		if ( $errors ) {
			return self::fail( implode( ' ', $errors ) );
		}

		ContactRepository::save_profile( $id, self::profile_merge( ContactRepository::profile( $id ), $in ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Profil gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Aktivität erfassen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function contact_activity( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! ContactRepository::find( $id ) ) {
			return self::fail( __( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$in     = self::input( $request );
		$follow = isset( $in['follow_up_on'] ) ? trim( (string) $in['follow_up_on'] ) : '';

		if ( '' !== $follow && ! self::is_date( $follow ) ) {
			return self::fail( __( 'Die Wiedervorlage ist kein gültiges Datum.', 'lindenweg16' ) );
		}

		$in['contact_id'] = $id;
		$result           = ContactRepository::add_activity( $in );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Aktivität erfasst.', 'lindenweg16' ) ), 201 );
	}

	/**
	 * Konditionsstufe festschreiben.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function contact_term( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! ContactRepository::find( $id ) ) {
			return self::fail( __( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$in     = self::input( $request );
		$errors = self::term_errors( $in );

		if ( $errors ) {
			return self::fail( implode( ' ', $errors ) );
		}

		$in['contact_id'] = $id;

		if ( ! ContactRepository::add_term( $in ) ) {
			return self::fail( __( 'Die Kondition konnte nicht festgeschrieben werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Kondition festgeschrieben.', 'lindenweg16' ) ), 201 );
	}

	/**
	 * Was sich in eine Kommissionsliste eintragen lässt: Lagerware zum
	 * Ausliefern, Kommissionsbestand zum Abrechnen oder Zurücknehmen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function commission_options( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! ContactRepository::find( $id ) ) {
			return self::fail( __( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$articles = ArticleRepository::all_by_id();
		$stock    = array();

		foreach ( StockLedger::balances( StockRepository::all() ) as $article_id => $colors ) {
			if ( ! isset( $articles[ $article_id ] ) || ! ArticleRepository::is_active( $articles[ $article_id ] ) ) {
				continue;
			}

			foreach ( $colors as $color => $locations ) {
				$qty = isset( $locations['lager'] ) ? (float) $locations['lager'] : 0.0;

				if ( $qty > 0 ) {
					$stock[] = array(
						'article_id' => (int) $article_id,
						'sku'        => (string) $articles[ $article_id ]['sku'],
						'name'       => (string) $articles[ $article_id ]['name'],
						'color'      => (string) $color,
						'label'      => ColorRegistry::label( $color ),
						'qty'        => $qty,
					);
				}
			}
		}

		$held = array();

		foreach ( ContactRepository::commission_stock( $id ) as $c ) {
			$a      = isset( $articles[ (int) $c['article_id'] ] ) ? $articles[ (int) $c['article_id'] ] : array();
			$held[] = array(
				'article_id' => (int) $c['article_id'],
				'sku'        => isset( $a['sku'] ) ? (string) $a['sku'] : '',
				'name'       => isset( $a['name'] ) ? (string) $a['name'] : '',
				'color'      => (string) $c['color'],
				'label'      => ColorRegistry::label( $c['color'] ),
				'qty'        => (float) $c['qty'],
			);
		}

		usort(
			$stock,
			static function ( $a, $b ) {
				return strcmp( $a['sku'] . $a['color'], $b['sku'] . $b['color'] );
			}
		);

		$profile = ContactRepository::profile( $id );

		return new WP_REST_Response(
			array(
				'types'   => CommissionBatch::types(),
				'stock'   => $stock,
				'held'    => $held,
				// Kein Profil heißt: nichts vereinbart, nichts verboten.
				'allowed' => $profile ? (bool) (int) $profile['commission_allowed'] : null,
			)
		);
	}

	/**
	 * Kommission buchen: ausliefern, abrechnen oder zurücknehmen.
	 *
	 * Wie handle_commission_batch der alten Seite: Prüfung über
	 * CommissionBatch, Buchung (und bei „abrechnen“ der offene Posten) über
	 * CommissionRepository.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function commission_book( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! Roles::current_user_can_betrieb( 'lw16_capture' ) || ! Access::can( 'lager.buchen' ) ) {
			return self::fail( __( 'Dafür fehlt dir das Recht zum Buchen.', 'lindenweg16' ), 403 );
		}

		if ( ! ContactRepository::find( $id ) ) {
			return self::fail( __( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$params = (array) $request->get_json_params();
		$type   = isset( $params['type'] ) ? sanitize_key( (string) $params['type'] ) : '';
		$rows   = self::commission_rows( isset( $params['rows'] ) ? $params['rows'] : array() );

		if ( CommissionBatch::too_long( $rows ) ) {
			return self::fail( __( 'Die Liste ist zu lang für eine Lieferung.', 'lindenweg16' ) );
		}

		$result = CommissionBatch::validate( $rows, $type, $id );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', array_unique( $result['errors'] ) ) );
		}

		// Doppelklick und Wiederholung nach Verbindungsabbruch: dieselbe Liste
		// nicht binnen Sekunden ein zweites Mal buchen.
		$lock = 'lw16_erp_kom_' . md5( get_current_user_id() . '|' . $id . '|' . $type . '|' . wp_json_encode( $result['lines'] ) );

		if ( get_transient( $lock ) ) {
			return self::fail( __( 'Diese Liste wurde gerade eben schon gebucht. Bitte kurz warten und den Bestand prüfen, bevor du sie noch einmal buchst.', 'lindenweg16' ), 409 );
		}

		set_transient( $lock, 1, 30 );

		$message = CommissionRepository::book_batch( $result['lines'], $type, $id );

		return new WP_REST_Response( array( 'ok' => true, 'message' => $message ), 201 );
	}

	/**
	 * Nachbestell-Link erzeugen oder widerrufen.
	 *
	 * Der Link steht nur in dieser einen Antwort; gespeichert wird allein sein
	 * Hash (ReorderToken). Die Antwort darf nicht zwischengespeichert werden.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function contact_link( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$contact = ContactRepository::find( $id );

		if ( ! $contact ) {
			return self::fail( __( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$in = self::input( $request );

		if ( ! empty( $in['revoke'] ) ) {
			$n = ReorderToken::revoke_all( $id, __( 'Manuell widerrufen.', 'lindenweg16' ) );

			return new WP_REST_Response(
				array(
					'ok'      => true,
					'message' => $n ? __( 'Link widerrufen.', 'lindenweg16' ) : __( 'Es gab keinen aktiven Link.', 'lindenweg16' ),
				)
			);
		}

		$token = ReorderToken::issue( $id );

		if ( empty( $token['url'] ) ) {
			return self::fail( __( 'Der Link konnte nicht erzeugt werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		$response = new WP_REST_Response(
			array(
				'ok'       => true,
				'message'  => __( 'Neuer Link erzeugt – er wird nur dieses eine Mal angezeigt.', 'lindenweg16' ),
				'url'      => $token['url'],
				'readable' => DeviceLabel::readable( $token['url'] ),
				'qr'       => DeviceLabel::qr( $token['url'], 150, 'M' ),
				'name'     => (string) $contact['company'],
				'expires'  => gmdate( 'Y-m-d', time() + ReorderToken::DEFAULT_LIFETIME_DAYS * DAY_IN_SECONDS ),
			),
			201
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/* ====================================================================
	 * Shop-Bestellungen
	 * ================================================================== */

	/**
	 * Liste der WooCommerce-Bestellungen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function shop_orders( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return self::fail( __( 'WooCommerce ist nicht aktiv – dann gibt es hier keine Shop-Bestellungen.', 'lindenweg16' ), 409 );
		}

		$known  = array_keys( wc_get_order_statuses() );
		$status = self::shop_status( (string) $request->get_param( 'status' ), $known );
		$q      = trim( (string) $request->get_param( 'q' ) );
		$page   = max( 1, (int) $request->get_param( 'seite' ) );
		$orders = array();
		$total  = 0;
		$pages  = 1;

		if ( '' !== $q ) {
			$ids = array();

			if ( ctype_digit( ltrim( $q, '#' ) ) && wc_get_order( (int) ltrim( $q, '#' ) ) ) {
				$ids[] = (int) ltrim( $q, '#' );
			}

			$store = \WC_Data_Store::load( 'shop_order' );
			if ( is_object( $store ) && method_exists( $store, 'search_orders' ) ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) $store->search_orders( $q ) ) );
			}

			$found = array();
			foreach ( array_slice( array_values( array_unique( $ids ) ), 0, 100 ) as $oid ) {
				$order = wc_get_order( $oid );

				if ( $order && 'shop_order' === $order->get_type() && ( '' === $status || 'wc-' . $order->get_status() === $status ) ) {
					$found[] = $order;
				}
			}

			usort(
				$found,
				static function ( $a, $b ) {
					return $b->get_id() <=> $a->get_id();
				}
			);

			$total  = count( $found );
			$pages  = max( 1, (int) ceil( $total / self::SHOP_PAGE ) );
			$orders = array_slice( $found, ( $page - 1 ) * self::SHOP_PAGE, self::SHOP_PAGE );
		} else {
			$args = array(
				'type'     => 'shop_order',
				'limit'    => self::SHOP_PAGE,
				'paged'    => $page,
				'paginate' => true,
				'orderby'  => 'date',
				'order'    => 'DESC',
			);

			if ( '' !== $status ) {
				$args['status'] = array( $status );
			}

			$res    = wc_get_orders( $args );
			$orders = $res->orders;
			$total  = (int) $res->total;
			$pages  = max( 1, (int) $res->max_num_pages );
		}

		$rows = array();

		foreach ( $orders as $order ) {
			$slug     = $order->get_status();
			$total_v  = (float) $order->get_total();
			$refunded = (float) $order->get_total_refunded();
			$pay      = self::pay_state( $slug, (bool) $order->get_date_paid(), $total_v, $refunded );
			$name     = trim( $order->get_formatted_billing_full_name() );
			$company  = trim( (string) $order->get_billing_company() );

			$rows[] = array(
				'id'       => $order->get_id(),
				'number'   => (string) $order->get_order_number(),
				'ts'       => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
				'status'   => $slug,
				'label'    => wc_get_order_status_name( $slug ),
				'customer' => '' !== $name ? $name : $company,
				'company'  => '' !== $name ? $company : '',
				'email'    => (string) $order->get_billing_email(),
				'total'    => $total_v,
				'refunded' => $refunded,
				'currency' => (string) $order->get_currency(),
				'items'    => (int) $order->get_item_count(),
				'method'   => (string) $order->get_payment_method_title(),
				'pay'      => $pay['key'],
				'paylabel' => $pay['label'],
				'ship'     => self::awaits_shipping( $slug ),
			);
		}

		$counts = array();
		$labels = array();

		foreach ( wc_get_order_statuses() as $key => $label ) {
			$labels[] = array( 'key' => $key, 'label' => $label );

			if ( function_exists( 'wc_orders_count' ) ) {
				$n = (int) wc_orders_count( preg_replace( '/^wc-/', '', $key ) );

				if ( $n ) {
					$counts[ $key ] = $n;
				}
			}
		}

		return new WP_REST_Response(
			array(
				'orders'   => $rows,
				'total'    => $total,
				'page'     => $page,
				'pages'    => $pages,
				'status'   => $status,
				'statuses' => $labels,
				'counts'   => $counts,
				'can_ship' => Access::can( 'verkauf.versand' ),
			)
		);
	}

	/* ====================================================================
	 * Loyalty
	 * ================================================================== */

	/**
	 * Name eines Nutzers für die Anzeige.
	 *
	 * @param int $user_id Nutzer.
	 * @return string
	 */
	protected static function user_name( $user_id ) {
		$user = get_userdata( (int) $user_id );

		/* translators: %d: Nutzernummer. */
		return $user ? (string) $user->display_name : sprintf( __( 'Gelöschtes Konto (%d)', 'lindenweg16' ), (int) $user_id );
	}

	/**
	 * Loyalty-Übersicht: Prüf-Warteschlange und Summen.
	 *
	 * @return WP_REST_Response
	 */
	public static function loyalty() {
		$queue = array();

		foreach ( LoyaltyRepository::referrals_by_status( 'review' ) as $r ) {
			$queue[] = array(
				'id'       => (int) $r->id,
				'date'     => (string) $r->created_at,
				'referrer' => self::user_name( $r->referrer_user_id ),
				'order'    => (int) $r->referred_order_id,
				'reward'   => (float) $r->reward_amount,
				'reason'   => (string) $r->review_grund,
			);
		}

		$sums = array();

		foreach ( array( 'pending', 'completed', 'reversed' ) as $status ) {
			$list  = LoyaltyRepository::referrals_by_status( $status );
			$total = 0.0;

			foreach ( $list as $r ) {
				$total += (float) $r->reward_amount;
			}

			$sums[ $status ] = array( 'count' => count( $list ), 'sum' => round( $total, 2 ), 'label' => self::referral_label( $status ) );
		}

		return new WP_REST_Response( array( 'queue' => $queue, 'sums' => $sums ) );
	}

	/**
	 * Prüffall freigeben oder ablehnen (wie die alte Seite, mit Protokoll).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function loyalty_decide( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$in  = self::input( $request );
		$ref = LoyaltyRepository::get_referral( $id );

		if ( ! $ref ) {
			return self::fail( __( 'Diese Empfehlung gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$result = self::referral_decision( isset( $in['decision'] ) ? sanitize_key( (string) $in['decision'] ) : '', (string) $ref->status );

		if ( ! $result['ok'] ) {
			return self::fail( $result['message'], 409 );
		}

		LoyaltyRepository::set_referral_status( $id, $result['to'] );
		AuditLog::record( $result['action'], 'referral', $id, array( 'reward' => $ref->reward_amount ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => $result['message'] ) );
	}

	/**
	 * Kundensuche: Guthaben und Punkte.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function loyalty_members( WP_REST_Request $request ) {
		$q = trim( (string) $request->get_param( 'q' ) );

		if ( strlen( $q ) < 2 ) {
			return new WP_REST_Response( array( 'members' => array() ) );
		}

		$users = get_users(
			array(
				'search'         => '*' . $q . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'number'         => 12,
				'fields'         => array( 'ID', 'display_name', 'user_email' ),
			)
		);
		$out   = array();

		foreach ( $users as $user ) {
			$out[] = array(
				'id'     => (int) $user->ID,
				'name'   => (string) $user->display_name,
				'email'  => (string) $user->user_email,
				'credit' => LoyaltyRepository::get_credit( $user->ID ),
				'points' => LoyaltyRepository::get_points( $user->ID ),
			);
		}

		return new WP_REST_Response( array( 'members' => $out ) );
	}

	/**
	 * Ein Kundenkonto: Salden, Punkteverlauf, Empfehlungen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function loyalty_member( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$user = get_userdata( $id );

		if ( ! $user ) {
			return self::fail( __( 'Dieses Kundenkonto gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$ledger = array();

		foreach ( LoyaltyRepository::ledger_of( $id, 25, 1 ) as $l ) {
			$ledger[] = array(
				'date'   => (string) $l->created_at,
				'points' => (int) $l->points,
				'label'  => self::ledger_label( (string) $l->type, (string) $l->status ),
				'note'   => (string) $l->notiz,
				'order'  => (int) $l->order_id,
			);
		}

		$refs = array();

		foreach ( LoyaltyRepository::referrals_of( $id ) as $r ) {
			$refs[] = array(
				'date'   => (string) $r->created_at,
				'order'  => (int) $r->referred_order_id,
				'reward' => (float) $r->reward_amount,
				'label'  => self::referral_label( (string) $r->status ),
			);
		}

		return new WP_REST_Response(
			array(
				'id'         => $id,
				'name'       => (string) $user->display_name,
				'email'      => (string) $user->user_email,
				'credit'     => LoyaltyRepository::get_credit( $id ),
				'points'     => LoyaltyRepository::get_points( $id ),
				'code'       => (string) get_user_meta( $id, LoyaltyRepository::META_CODE, true ),
				'ledger'     => $ledger,
				'ledger_all' => LoyaltyRepository::ledger_count( $id ),
				'referrals'  => $refs,
			)
		);
	}

	/* ====================================================================
	 * Marketing
	 * ================================================================== */

	/**
	 * Kampagnen mit Budgetstand.
	 *
	 * @return WP_REST_Response
	 */
	public static function marketing() {
		$out = array();

		foreach ( KampagnenRepository::all() as $k ) {
			$kosten = KampagnenRepository::kosten( (int) $k->id );
			$budget = Kampagnenrechnung::budget_status( $k->budget_geplant, $kosten['summe'] );
			$out[]  = array(
				'id'      => (int) $k->id,
				'kst'     => (string) $k->kostenstelle,
				'name'    => (string) $k->name,
				'type'    => self::campaign_type_label( (string) $k->typ ),
				'period'  => self::campaign_period( (array) $k ),
				'status'  => (string) $k->status,
				'budget'  => (float) $k->budget_geplant,
				'costs'   => (float) $kosten['summe'],
				'ampel'   => (string) $budget['ampel'],
				'usage'   => (float) $budget['auslastung'],
				'rest'    => (float) $budget['rest'],
			);
		}

		return new WP_REST_Response( array( 'campaigns' => $out ) );
	}

	/**
	 * Auswertung einer Kampagne.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function campaign( WP_REST_Request $request ) {
		$k = KampagnenRepository::get( (int) $request['id'] );

		if ( ! $k ) {
			return self::fail( __( 'Diese Kampagne gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$rep = KampagnenReport::report( $k );

		return new WP_REST_Response(
			array(
				'id'      => (int) $k->id,
				'kst'     => (string) $k->kostenstelle,
				'name'    => (string) $k->name,
				'type'    => self::campaign_type_label( (string) $k->typ ),
				'period'  => self::campaign_period( (array) $k ),
				'status'  => (string) $k->status,
				'from'    => (string) $rep['zeitraum']['von'],
				'to'      => (string) $rep['zeitraum']['bis'],
				'costs'   => (float) $rep['kosten'],
				'rebate'  => (float) $rep['rabatt'],
				'effort'  => (float) $rep['einsatz'],
				'budget'  => array(
					'plan'  => (float) $rep['budget']['budget'],
					'ampel' => (string) $rep['budget']['ampel'],
					'usage' => (float) $rep['budget']['auslastung'],
					'rest'  => (float) $rep['budget']['rest'],
				),
				'market'  => (float) $rep['markt']['summe'],
				'shop'    => array(
					'sum'     => (float) $rep['shop']['summe'],
					'count'   => (int) $rep['shop']['anzahl'],
					'utm'     => (int) $rep['shop']['per_utm'],
					'voucher' => (int) $rep['shop']['per_gutschein'],
				),
				'revenue' => (float) $rep['umsatz'],
				'result'  => (float) $rep['ergebnis'],
				'roas'    => $rep['roas'],
				'cpo'     => $rep['cpo'],
			)
		);
	}
}
