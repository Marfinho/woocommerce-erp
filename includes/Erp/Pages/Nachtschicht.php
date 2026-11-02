<?php
/**
 * ERP-Seite „Nachtschicht“: der Vorschlag für heute Abend.
 *
 * Liest die freigegebenen Platten aus dem Workflow und den Live-Zustand der
 * Bambu-Drucker (frei, Platte abgeräumt, AMS-Belegung) und legt mit
 * Erp\NightShift fest, welche Platte auf welchen Drucker gehört. Eine Stunde
 * vor dem Feierabend kommt der Plan als Push-Nachricht.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\NightShift;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Erp\RunFlow;
use Lindenweg16\Erp\RunRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Nachtschicht.
 */
class Nachtschicht {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns    = RestApi::NAMESPACE_V1;
		$needs = $api->needs( array( 'fertigung.nachtschicht' ) );

		register_rest_route( $ns, '/erp/nachtschicht', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/nachtschicht/einstellungen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Drucker mit Zustand für den Plan.
	 *
	 * @param array $window Nachtfenster.
	 * @return array
	 */
	public static function printers( array $window ) {
		$out   = array();
		$start = strtotime( $window['start'] . ' UTC' );

		foreach ( (array) BambuPrinterRepository::all() as $p ) {
			if ( isset( $p->active ) && ! (int) $p->active ) {
				continue;
			}
			$online   = BambuPrinterRepository::is_online( $p );
			$printing = BambuPrinterRepository::is_printing_status( $p->status );
			$trays    = $online ? Produktion::ams_trays( BambuPrinterRepository::ams_units( $p ) ) : array();
			$free     = false;
			$reason   = '';

			if ( ! $online ) {
				$reason = __( 'offline – Bridge oder Drucker prüfen', 'lindenweg16' );
			} elseif ( $printing ) {
				$ends = time() + 60 * (int) $p->remaining_minutes;
				// Endet der Druck vor dem Feierabend, ist der Drucker danach frei – sofern jemand abräumt.
				if ( $ends <= $start ) {
					$free   = true;
					/* translators: %s: Uhrzeit. */
					$reason = sprintf( __( 'druckt noch bis etwa %s – danach abräumen', 'lindenweg16' ), wp_date( 'H:i', $ends ) );
				} else {
					/* translators: %s: Uhrzeit. */
					$reason = sprintf( __( 'druckt bis etwa %s – läuft schon in die Nacht', 'lindenweg16' ), wp_date( 'H:i', $ends ) );
				}
			} elseif ( ! BambuPrinterRepository::bed_is_clear( $p ) ) {
				$free   = true;
				$reason = __( 'Platte noch belegt – vor dem Start abräumen', 'lindenweg16' );
			} else {
				$free = true;
			}

			$out[] = array(
				'id'     => (string) $p->printer_id,
				'name'   => (string) $p->name,
				'free'   => $free,
				'reason' => $reason,
				'trays'  => $trays,
				'model'  => (string) $p->printer_model,
			);
		}

		return $out;
	}

	/**
	 * Freigegebene Platten mit Material, Farbe, Gramm und Dringlichkeit.
	 *
	 * @return array
	 */
	public static function plates() {
		$catalog = array();
		foreach ( (array) \Lindenweg16\Modules\Betrieb\Bambu\BambuFilamentCatalogRepository::all() as $f ) {
			$f = (array) $f;
			if ( '' !== (string) $f['label'] ) {
				$catalog[ strtolower( trim( (string) $f['label'] ) ) ] = (string) $f['color_hex'];
			}
		}

		$balances = \Lindenweg16\Modules\Betrieb\StockRepository::balances();
		$targets  = array();
		foreach ( \Lindenweg16\Modules\Betrieb\StockRepository::targets() as $t ) {
			$targets[ (int) $t['article_id'] . '|' . (string) $t['color'] ] = (float) $t['target_qty'];
		}

		$out = array();
		foreach ( RunRepository::active() as $run ) {
			if ( RunFlow::FREIGEGEBEN !== $run['status'] ) {
				continue;
			}

			$material = '';
			$color    = '';
			$grams    = 0.0;
			$urgency  = 0.0;
			$labels   = array();

			foreach ( $run['items'] as $it ) {
				$rev = \Lindenweg16\Modules\Betrieb\RevisionRepository::active_for_article( (int) $it['article_id'] );
				if ( $rev ) {
					$material = '' !== $material ? $material : (string) $rev['material'];
					$color    = '' !== $color ? $color : (string) $rev['color'];
					$grams   += (float) $rev['weight_g'] * (int) $it['qty'];
				}
				$labels[] = (int) $it['qty'] . '× ' . ( '' !== (string) $it['sku'] ? $it['sku'] : $it['name'] );

				// Dringend: Bestand leer oder unter Soll.
				foreach ( (array) ( isset( $balances[ (int) $it['article_id'] ] ) ? $balances[ (int) $it['article_id'] ] : array() ) as $col => $places ) {
					$have = isset( $places['lager'] ) ? (float) $places['lager'] : 0.0;
					$soll = isset( $targets[ (int) $it['article_id'] . '|' . $col ] ) ? $targets[ (int) $it['article_id'] . '|' . $col ] : null;
					if ( $have <= 0 ) {
						$urgency = max( $urgency, 0.6 );
					} elseif ( null !== $soll && $have < $soll ) {
						$urgency = max( $urgency, 0.4 );
					}
				}
			}

			// Wer lange wartet, wird dringender.
			$age      = max( 0, ( time() - strtotime( (string) $run['released_at'] . ' UTC' ) ) / 86400 );
			$urgency  = min( 1.0, $urgency + min( 0.4, $age * 0.1 ) );
			$hex      = NightShift::hex( $color );
			if ( '' === $hex && isset( $catalog[ strtolower( trim( $color ) ) ] ) ) {
				$hex = NightShift::hex( $catalog[ strtolower( trim( $color ) ) ] );
			}

			$out[] = array(
				'id'        => (int) $run['id'],
				'batch'     => (string) $run['batch'],
				'label'     => implode( ', ', $labels ),
				'minutes'   => (int) $run['est_minutes'],
				'material'  => $material,
				'color'     => $color,
				'color_hex' => $hex,
				'grams'     => round( $grams, 1 ),
				'urgency'   => round( $urgency, 2 ),
				'printer'   => (string) $run['bambu_printer'],
			);
		}

		return $out;
	}

	/**
	 * Der Plan für heute Abend.
	 *
	 * @return array
	 */
	public static function build() {
		$s      = Kit::settings( 'nacht' );
		$window = NightShift::window( current_time( 'Y-m-d H:i' ), (string) $s['feierabend'], (string) $s['aufstehen'] );
		// current_time liefert Ortszeit; das Fenster wird für den Vergleich mit time() in UTC gebraucht.
		$utc         = $window;
		$utc['start'] = get_gmt_from_date( $window['start'] . ':00', 'Y-m-d H:i' );
		$printers    = self::printers( $utc );
		$plates      = self::plates();
		$plan        = NightShift::plan( $printers, $plates, $window['minutes'] );

		return array(
			'window'   => $window,
			'plan'     => $plan,
			'printers' => count( $printers ),
			'plates'   => count( $plates ),
			'settings' => $s,
		);
	}

	/**
	 * Übersicht.
	 *
	 * @return WP_REST_Response
	 */
	public static function overview() {
		return new WP_REST_Response( self::build() );
	}

	/**
	 * Einstellungen speichern.
	 *
	 * @param WP_REST_Request $request Anfrage {feierabend, aufstehen, push}.
	 * @return WP_REST_Response
	 */
	public static function save( WP_REST_Request $request ) {
		$in = Kit::input( $request );

		foreach ( array( 'feierabend', 'aufstehen' ) as $key ) {
			if ( isset( $in[ $key ] ) && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in[ $key ] ) ) {
				return Kit::fail( __( 'Uhrzeit bitte als HH:MM, z. B. 20:00.', 'lindenweg16' ) );
			}
		}
		if ( array_key_exists( 'push', $in ) ) {
			$in['push'] = empty( $in['push'] ) ? 0 : 1;
		}

		Kit::save_settings( 'nacht', $in );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Stündlich: eine Stunde vor dem Feierabend den Plan als Push schicken (einmal am Tag).
	 */
	public static function maybe_push() {
		$s = Kit::settings( 'nacht' );

		if ( ! (int) $s['push'] ) {
			return;
		}

		$hour = (int) substr( (string) $s['feierabend'], 0, 2 );
		$now  = (int) current_time( 'G' );
		$day  = current_time( 'Y-m-d' );

		if ( $now !== ( $hour + 23 ) % 24 || get_option( 'lw16_erp_nacht_push' ) === $day ) {
			return;
		}
		update_option( 'lw16_erp_nacht_push', $day, false );

		$b = self::build();
		$n = count( $b['plan']['assignments'] );

		if ( ! $n ) {
			return;
		}

		$lines = array();
		foreach ( $b['plan']['assignments'] as $a ) {
			$lines[] = $a['printer']['name'] . ': ' . $a['plate']['batch'] . ( $a['refit'] ? ' (' . __( 'umrüsten', 'lindenweg16' ) . ')' : '' );
		}

		\Lindenweg16\Erp\Push::notify(
			'nachtschicht',
			/* translators: %d: Anzahl. */
			sprintf( _n( 'Heute Nacht: %d Platte', 'Heute Nacht: %d Platten', $n, 'lindenweg16' ), $n ),
			implode( ' · ', $lines ),
			'#/seite/nachtschicht'
		);
	}
}
