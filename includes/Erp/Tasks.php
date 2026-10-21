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

use Lindenweg16\Modules\Betrieb\ContactRepository;
use Lindenweg16\Modules\Betrieb\MaintenanceRepository;
use Lindenweg16\Modules\Betrieb\OrderOpsRepository;
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
			'melden'    => 1,
			'ruesten'   => 2,
			'montage'   => 3,
			'packen'    => 4,
			'wartung'   => 5,
			'bestellen' => 6,
			'lieferung' => 7,
			'rechnung'  => 8,
			'nachfassen' => 9,
			'mail'      => 10,
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

		if ( Access::can( 'fertigung.workflow' ) ) {
			foreach ( Assembly::board()['open'] as $order ) {
				$tasks[] = array(
					'kind'  => 'montage',
					/* translators: 1: Stückzahl, 2: Artikel. */
					'title' => sprintf( __( 'Montage: %1$s× %2$s', 'lindenweg16' ), (int) $order['qty'], $order['name'] ),
					'sub'   => $order['code'] . ( $order['running'] ? ' · ' . __( 'läuft', 'lindenweg16' ) : '' ),
					'cta'   => $order['running'] ? __( 'Weiterarbeiten', 'lindenweg16' ) : __( 'Montage starten', 'lindenweg16' ),
					'href'  => '#/montage/' . (int) $order['id'],
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

		// Das Board ist teuer (Alarm für alle Teile): einmal berechnen, zweimal nutzen.
		$board = Access::can( 'lager.einkauf' ) ? Procurement::board() : array( 'order' => array(), 'open' => array() );

		if ( Access::can( 'lager.einkauf' ) ) {
			$n = count( $board['order'] );

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

		if ( Access::can( 'fertigung.drucker' ) ) {
			$n = 0;

			foreach ( MaintenanceRepository::due() as $row ) {
				if ( $row['reasons'] ) {
					++$n;
				}
			}

			if ( $n > 0 ) {
				$tasks[] = array(
					'kind'  => 'wartung',
					'title' => self::count_text( $n, __( '%d Drucker braucht Wartung', 'lindenweg16' ), __( '%d Drucker brauchen Wartung', 'lindenweg16' ) ),
					'sub'   => __( 'Wartung fällig oder noch nie erfasst', 'lindenweg16' ),
					'cta'   => __( 'Zur Wartung', 'lindenweg16' ),
					'href'  => '#/seite/drucker',
				);
			}
		}

		if ( Access::can( 'lager.einkauf' ) ) {
			$late = array();

			foreach ( $board['open'] as $purchase ) {
				if ( $purchase['late'] > 0 ) {
					$late[] = $purchase['label'];
				}
			}

			if ( $late ) {
				$tasks[] = array(
					'kind'  => 'lieferung',
					'title' => self::count_text( count( $late ), __( '%d Lieferung überfällig', 'lindenweg16' ), __( '%d Lieferungen überfällig', 'lindenweg16' ) ),
					'sub'   => implode( ', ', array_slice( $late, 0, 2 ) ),
					'cta'   => __( 'Zum Einkauf', 'lindenweg16' ),
					'href'  => '#/seite/einkauf',
				);
			}
		}

		if ( Access::can( 'verkauf.kontakte' ) ) {
			$overdue = 0;

			foreach ( OrderOpsRepository::open_invoices() as $order ) {
				if ( OrderOpsRepository::is_overdue( $order ) ) {
					++$overdue;
				}
			}

			if ( $overdue > 0 ) {
				$tasks[] = array(
					'kind'  => 'rechnung',
					'title' => self::count_text( $overdue, __( '%d Rechnung überfällig', 'lindenweg16' ), __( '%d Rechnungen überfällig', 'lindenweg16' ) ),
					'sub'   => __( 'Zahlungserinnerung in der Kundenakte', 'lindenweg16' ),
					'cta'   => __( 'Zu den Kunden', 'lindenweg16' ),
					'href'  => '#/seite/kontakte',
				);
			}

			$follow = ContactRepository::due_follow_ups();

			if ( $follow ) {
				$names = array();
				foreach ( array_slice( $follow, 0, 2 ) as $c ) {
					$names[] = (string) $c['company'];
				}

				$tasks[] = array(
					'kind'  => 'nachfassen',
					'title' => self::count_text( count( $follow ), __( '%d Wiedervorlage fällig', 'lindenweg16' ), __( '%d Wiedervorlagen fällig', 'lindenweg16' ) ),
					'sub'   => implode( ', ', $names ),
					'cta'   => __( 'Nachfassen', 'lindenweg16' ),
					'href'  => 1 === count( $follow ) ? '#/kontakt/' . (int) $follow[0]['id'] : '#/seite/kontakte',
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
