<?php
/**
 * „Heute“: die Aufgaben dieser Person, quer über alle Bereiche.
 *
 * Jede Aufgabe ist eine Frage, die jemand beantworten muss: eine Platte
 * melden, ein Paket packen, Filament bestellen, eine Mail beantworten. Wer
 * seine Aufgaben abarbeitet, braucht das Menü kaum. Es erscheint nur, was die
 * Person laut Rechten auch erledigen darf.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Mailbox\MailboxAccess;
use Lindenweg16\Modules\Mailbox\MailRepository;

/**
 * Sammelt die Aufgaben.
 */
class Tasks {

	/**
	 * Wichtigkeit je Art – kleiner heißt weiter oben. Reine Regel.
	 *
	 * Fertig Gedrucktes zuerst: Die Platte blockiert den Drucker und der
	 * Bestand stimmt erst nach der Meldung.
	 *
	 * @return array Art => Rang.
	 */
	public static function ranks() {
		return array(
			'melden'   => 1,
			'ruesten'  => 2,
			'packen'   => 3,
			'bestellen' => 4,
			'mail'     => 5,
		);
	}

	/**
	 * Aufgaben nach Wichtigkeit ordnen – reine Regel.
	 *
	 * @param array $tasks Aufgaben mit kind.
	 * @return array
	 */
	public static function sort( array $tasks ) {
		$ranks = self::ranks();

		usort(
			$tasks,
			function ( $a, $b ) use ( $ranks ) {
				$ra = isset( $ranks[ $a['kind'] ] ) ? $ranks[ $a['kind'] ] : 99;
				$rb = isset( $ranks[ $b['kind'] ] ) ? $ranks[ $b['kind'] ] : 99;

				return $ra <=> $rb;
			}
		);

		return $tasks;
	}

	/**
	 * Text für eine Zahl: „1 Bestellung wartet“ / „3 Bestellungen warten“ – reine Regel.
	 *
	 * @param int    $n        Anzahl.
	 * @param string $singular Einzahl mit %d.
	 * @param string $plural   Mehrzahl mit %d.
	 * @return string
	 */
	public static function count_text( $n, $singular, $plural ) {
		return sprintf( 1 === (int) $n ? $singular : $plural, (int) $n );
	}

	/**
	 * Alle Aufgaben dieser Person.
	 *
	 * @return array
	 */
	public static function collect() {
		$tasks = array();

		if ( Access::can( 'fertigung.workflow' ) ) {
			foreach ( RunRepository::active() as $run ) {
				if ( ! in_array( $run['status'], array( RunFlow::FREIGEGEBEN, RunFlow::ZU_MELDEN ), true ) ) {
					continue;
				}

				$lines = array();
				foreach ( $run['items'] as $item ) {
					$lines[] = (int) $item['qty'] . '× ' . ( isset( $item['name'] ) ? $item['name'] : '' );
				}

				$melden  = RunFlow::ZU_MELDEN === $run['status'];
				$tasks[] = array(
					'kind'  => $melden ? 'melden' : 'ruesten',
					'title' => sprintf( /* translators: 1: Nummer. */ $melden ? __( 'Platte %d melden', 'lindenweg16' ) : __( 'Platte %d rüsten', 'lindenweg16' ), (int) $run['id'] ),
					'sub'   => implode( ', ', $lines ),
					'cta'   => $melden ? __( 'Menge melden', 'lindenweg16' ) : __( 'Rüsten starten', 'lindenweg16' ),
					'href'  => '#/platte/' . (int) $run['id'],
				);
			}
		}

		if ( Access::can( 'verkauf.versand' ) && function_exists( 'wc_orders_count' ) ) {
			$n = (int) wc_orders_count( 'processing' ) + (int) wc_orders_count( 'on-hold' );

			if ( $n > 0 ) {
				$tasks[] = array(
					'kind'  => 'packen',
					'title' => self::count_text( $n, __( '%d Bestellung versenden', 'lindenweg16' ), __( '%d Bestellungen versenden', 'lindenweg16' ) ),
					'sub'   => __( 'Packen, Label, Versendet', 'lindenweg16' ),
					'cta'   => __( 'Zum Versand', 'lindenweg16' ),
					'href'  => '#/seite/versand',
				);
			}
		}

		if ( Access::can( 'lager.einkauf' ) ) {
			$board = Procurement::board();
			$n     = count( $board['order'] );

			if ( $n > 0 ) {
				$names = array();
				foreach ( array_slice( $board['order'], 0, 2 ) as $row ) {
					$names[] = $row['label'];
				}

				$tasks[] = array(
					'kind'  => 'bestellen',
					'title' => self::count_text( $n, __( '%d Position nachbestellen', 'lindenweg16' ), __( '%d Positionen nachbestellen', 'lindenweg16' ) ),
					'sub'   => implode( ', ', $names ),
					'cta'   => __( 'Zum Einkauf', 'lindenweg16' ),
					'href'  => '#/seite/einkauf',
				);
			}
		}

		$mail = self::unread_mail();

		if ( $mail > 0 ) {
			$tasks[] = array(
				'kind'  => 'mail',
				'title' => self::count_text( $mail, __( '%d ungelesene Mail', 'lindenweg16' ), __( '%d ungelesene Mails', 'lindenweg16' ) ),
				'sub'   => __( 'Posteingang', 'lindenweg16' ),
				'cta'   => __( 'Zur Mailbox', 'lindenweg16' ),
				'href'  => '#/seite/mailbox',
			);
		}

		return self::sort( $tasks );
	}

	/**
	 * Ungelesene Mails, wenn die Person die Mailbox nutzen darf.
	 *
	 * @return int
	 */
	public static function unread_mail() {
		if ( ! Access::can( 'verwaltung.mailbox' ) || ! class_exists( MailRepository::class ) ) {
			return 0;
		}

		$ids = array();

		foreach ( MailRepository::accounts_for_user( get_current_user_id(), MailboxAccess::is_manager() ) as $account ) {
			if ( ! empty( $account['active'] ) ) {
				$ids[] = (int) $account['id'];
			}
		}

		return $ids ? (int) MailRepository::unread_inbox_total( $ids ) : 0;
	}
}
