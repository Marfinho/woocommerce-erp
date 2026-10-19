<?php
/**
 * Bestellung und Geschäftskunde zusammenbringen.
 *
 * Eine Shop-Bestellung von „Café Linde“ soll zur Akte von Café Linde führen.
 * Der Betrieb kennt dafür das Feld contact_id an der Betriebszeile der
 * Bestellung; gesetzt wurde es bisher nur von Hand in der alten Seite. Hier
 * stehen die Regeln, nach denen ein Kontakt vorgeschlagen und die Kundenart
 * der Bestellung gewählt wird – ohne Datenbank, damit sie sich testen lassen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Regeln zur Zuordnung.
 */
class OrderContact {

	/**
	 * Kundenart der Bestellung zu einer Kontaktart.
	 *
	 * Die Betriebszeile kennt B2C, B2B, Verein und Presse; ein Geschäftskunde
	 * ohne Kontakt ist nicht erlaubt (OrderOpsRepository::update_fields),
	 * deshalb geht mit dem Kontakt immer eine Geschäftskundenart einher.
	 *
	 * @param string $contact_type Art aus ContactRepository (haendler, verein, …).
	 * @return string
	 */
	public static function customer_type( $contact_type ) {
		$map = array(
			'verein' => 'Verein',
			'presse' => 'Presse',
		);

		return isset( $map[ $contact_type ] ) ? $map[ $contact_type ] : 'B2B';
	}

	/**
	 * Text zum Vergleichen: klein, ohne Leerraum am Rand und ohne Umlaut-Unterschiede.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function fold( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $text ), 'UTF-8' ) : strtolower( trim( (string) $text ) );
		$text = strtr( $text, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );

		return (string) preg_replace( '/[^a-z0-9]+/', ' ', $text );
	}

	/**
	 * Passende Kontakte zu einer Bestellung, die besten zuerst.
	 *
	 * Gleiche E-Mail-Adresse zählt am meisten (eindeutig), gleiche Firma
	 * zählt weniger (kann zwei Standorte meinen).
	 *
	 * @param array  $contacts Kontakte mit id, company, email.
	 * @param string $email    E-Mail der Bestellung.
	 * @param string $company  Firma der Bestellung.
	 * @param int    $limit    Wie viele.
	 * @return array Liste aus id, company, why (email|firma).
	 */
	public static function suggest( array $contacts, $email, $company, $limit = 3 ) {
		$email   = strtolower( trim( (string) $email ) );
		$company = self::fold( $company );
		$found   = array();

		foreach ( $contacts as $contact ) {
			$score = 0;
			$why   = '';

			if ( '' !== $email && strtolower( trim( (string) $contact['email'] ) ) === $email ) {
				$score = 2;
				$why   = 'email';
			} elseif ( '' !== $company && self::fold( $contact['company'] ) === $company ) {
				$score = 1;
				$why   = 'firma';
			}

			if ( $score ) {
				$found[] = array( 'id' => (int) $contact['id'], 'company' => (string) $contact['company'], 'why' => $why, 'score' => $score );
			}
		}

		usort(
			$found,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_map(
			static function ( $row ) {
				unset( $row['score'] );
				return $row;
			},
			array_slice( $found, 0, max( 1, (int) $limit ) )
		);
	}
}
