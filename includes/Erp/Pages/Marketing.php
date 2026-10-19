<?php
/**
 * Marketing im ERP: Kampagnen anlegen und ändern, Belege zuordnen und lösen,
 * Kilometer erfassen, Kampagne beenden.
 *
 * Die Lese-Routen `erp/marketing` und `erp/marketing/<id>` liegen in
 * `Verkauf.php`; hier stehen nur die schreibenden Routen und die Daten, die
 * die Formulare dazu brauchen. Gebucht, zugeordnet und protokolliert wird über
 * dieselben Repositories wie auf der alten Seite (`MarketingModule`):
 * `KampagnenRepository` (Protokoll `kampagne_*`), `BelegRepository`, `AuditLog`.
 * Reine Regeln stehen oben als statische Methoden (tests/ErpMarketingTest.php).
 *
 * Rechte: wie die alten `admin-post`-Aktionen (ActionMap) braucht jede Route
 * `verkauf.marketing`. Die alte Seite verlangte für Kilometerbeleg und
 * Belegauswahl kein eigenes Buchhaltungsrecht – das bleibt so.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Calc;
use Lindenweg16\Core\Settings;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Accounting\Kontenrahmen;
use Lindenweg16\Modules\Marketing\KampagnenRepository;
use Lindenweg16\Modules\Marketing\Kampagnenrechnung;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen und Regeln der schreibenden Marketing-Funktionen.
 */
class Marketing {

	/**
	 * Obergrenzen gegen Tippfehler (Nullen zu viel).
	 */
	const MAX_BUDGET = 10000000;
	const MAX_KM     = 100000;

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank, getestet)
	 * ================================================================== */

	/**
	 * Zahl aus deutscher oder englischer Schreibweise lesen.
	 *
	 * @param mixed $raw Eingabe („12,50“, „1.250,5“, „12.5“).
	 * @return float|null null bei leer oder keiner Zahl.
	 */
	public static function parse_number( $raw ) {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return (float) $raw;
		}
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$s = trim( str_replace( array( ' ', '€', '%' ), '', $raw ) );
		if ( '' === $s || ! preg_match( '/^-?[\d.,]+$/', $s ) ) {
			return null;
		}
		$komma = strrpos( $s, ',' );
		$punkt = strrpos( $s, '.' );
		if ( false !== $komma && false !== $punkt ) {
			// Das hintere Zeichen ist das Dezimalzeichen.
			if ( $komma > $punkt ) {
				$s = str_replace( '.', '', $s );
				$s = str_replace( ',', '.', $s );
			} else {
				$s = str_replace( ',', '', $s );
			}
		} elseif ( false !== $komma ) {
			$s = str_replace( ',', '.', $s );
		} elseif ( false !== $punkt && 1 === preg_match( '/^-?\d{1,3}(\.\d{3})+$/', $s ) ) {
			// „1.250“ meint hier 1250.
			$s = str_replace( '.', '', $s );
		}
		return is_numeric( $s ) ? (float) $s : null;
	}

	/**
	 * Gültiges Datum JJJJ-MM-TT?
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	public static function is_date( $s ) {
		if ( ! is_string( $s ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Eingaben einer Kampagne prüfen und in Spaltenwerte bringen.
	 *
	 * Wie auf der alten Seite: Name Pflicht, leerer Beginn wird heute, leere
	 * Daten bleiben NULL, Budget nicht negativ. Neu gegenüber früher: falsche
	 * Werte werden mit einem Satz abgelehnt, statt sie still zu ändern.
	 *
	 * @param array  $in    Felder (Texte bereits bereinigt): name, typ, kanal, start_datum,
	 *                      ende_datum, event_datum, budget_geplant, utm_campaign,
	 *                      gutschein_code, status, notiz.
	 * @param string $heute Heutiges Datum (JJJJ-MM-TT).
	 * @return array {errors: string[], data: array}
	 */
	public static function validate_campaign( array $in, $heute ) {
		$errors = array();
		$text   = function ( $k ) use ( $in ) {
			return isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? trim( (string) $in[ $k ] ) : '';
		};

		$data = array(
			'name'           => $text( 'name' ),
			'typ'            => '' === $text( 'typ' ) ? 'event' : $text( 'typ' ),
			'kanal'          => $text( 'kanal' ),
			'start_datum'    => $text( 'start_datum' ),
			'ende_datum'     => $text( 'ende_datum' ),
			'event_datum'    => $text( 'event_datum' ),
			'budget_geplant' => 0.0,
			'utm_campaign'   => $text( 'utm_campaign' ),
			'gutschein_code' => $text( 'gutschein_code' ),
			'status'         => '' === $text( 'status' ) ? 'geplant' : $text( 'status' ),
			'notiz'          => $text( 'notiz' ),
		);

		if ( '' === $data['name'] ) {
			$errors[] = __( 'Die Kampagne braucht einen Namen.', 'lindenweg16' );
		}
		if ( ! in_array( $data['typ'], KampagnenRepository::TYPEN, true ) ) {
			$errors[] = __( 'Diese Art von Kampagne gibt es nicht. Bitte eine aus der Liste wählen.', 'lindenweg16' );
		}
		if ( ! in_array( $data['status'], KampagnenRepository::STATUS, true ) ) {
			$errors[] = __( 'Diesen Status gibt es nicht. Bitte geplant, aktiv oder abgeschlossen wählen.', 'lindenweg16' );
		}

		$labels = array(
			'start_datum' => __( 'Beginn', 'lindenweg16' ),
			'ende_datum'  => __( 'Ende', 'lindenweg16' ),
			'event_datum' => __( 'Veranstaltungstag', 'lindenweg16' ),
		);
		foreach ( $labels as $feld => $label ) {
			if ( '' === $data[ $feld ] ) {
				$data[ $feld ] = null;
			} elseif ( ! self::is_date( $data[ $feld ] ) ) {
				/* translators: %s: Feldname. */
				$errors[] = sprintf( __( '%s ist kein gültiges Datum (erwartet: Tag, Monat, Jahr).', 'lindenweg16' ), $label );
			}
		}
		if ( null === $data['start_datum'] ) {
			$data['start_datum'] = $heute;
		}
		if ( ! $errors && $data['ende_datum'] && $data['ende_datum'] < $data['start_datum'] ) {
			$errors[] = __( 'Das Ende liegt vor dem Beginn. Bitte die Daten prüfen.', 'lindenweg16' );
		}

		$budget_raw = isset( $in['budget_geplant'] ) ? $in['budget_geplant'] : '';
		if ( '' !== trim( (string) ( is_scalar( $budget_raw ) ? $budget_raw : '' ) ) ) {
			$budget = self::parse_number( $budget_raw );
			if ( null === $budget ) {
				$errors[] = __( 'Das Budget ist keine Zahl. Bitte einen Betrag in Euro eintragen, zum Beispiel 250,00.', 'lindenweg16' );
			} elseif ( $budget > self::MAX_BUDGET ) {
				$errors[] = __( 'Das Budget ist unrealistisch hoch. Bitte den Betrag prüfen.', 'lindenweg16' );
			} else {
				$data['budget_geplant'] = round( max( 0, $budget ), 2 );
			}
		}

		return array(
			'errors' => $errors,
			'data'   => $data,
		);
	}

	/**
	 * Kilometer prüfen. 0 nimmt eine gebuchte Fahrt zurück.
	 *
	 * @param mixed $raw Eingabe.
	 * @return array {error: string, km: float}
	 */
	public static function validate_km( $raw ) {
		$km = self::parse_number( $raw );
		if ( null === $km ) {
			return array( 'error' => __( 'Bitte die gefahrenen Kilometer als Zahl eintragen, zum Beispiel 42,5. Mit 0 wird die Fahrt zurückgenommen.', 'lindenweg16' ), 'km' => 0.0 );
		}
		if ( $km < 0 ) {
			return array( 'error' => __( 'Kilometer können nicht negativ sein. Mit 0 wird die Fahrt zurückgenommen.', 'lindenweg16' ), 'km' => 0.0 );
		}
		if ( $km > self::MAX_KM ) {
			return array( 'error' => __( 'Das sind mehr Kilometer als plausibel. Bitte die Angabe prüfen.', 'lindenweg16' ), 'km' => 0.0 );
		}
		return array( 'error' => '', 'km' => round( $km, 1 ) );
	}

	/**
	 * Anteil in Prozent prüfen (0 < Anteil <= 100).
	 *
	 * @param mixed $raw Eingabe; leer heißt 100.
	 * @return array {error: string, anteil: float}
	 */
	public static function validate_share( $raw ) {
		if ( null === $raw || '' === trim( (string) ( is_scalar( $raw ) ? $raw : '' ) ) ) {
			return array( 'error' => '', 'anteil' => 100.0 );
		}
		$p = self::parse_number( $raw );
		if ( null === $p || $p <= 0 || $p > 100 ) {
			return array( 'error' => __( 'Der Anteil muss zwischen 0 und 100 Prozent liegen.', 'lindenweg16' ), 'anteil' => 0.0 );
		}
		return array( 'error' => '', 'anteil' => $p );
	}

	/**
	 * Darf der Beleg einer Kampagne zugeordnet werden?
	 *
	 * Stornierte Belege und Stornobelege zählen nicht (wie die Auswahl der alten Seite).
	 *
	 * @param object|array|null $beleg Beleg.
	 * @return string Leer, wenn erlaubt, sonst der Grund.
	 */
	public static function beleg_blocker( $beleg ) {
		$b = (array) $beleg;
		if ( ! $b ) {
			return __( 'Diesen Beleg gibt es nicht (mehr).', 'lindenweg16' );
		}
		if ( ! empty( $b['storniert'] ) || ( isset( $b['quelle'] ) && 'storno' === $b['quelle'] ) ) {
			return __( 'Ein stornierter Beleg lässt sich keiner Kampagne zuordnen.', 'lindenweg16' );
		}
		return '';
	}

	/**
	 * Datum des Kilometerbelegs: Veranstaltungstag, sonst Beginn, sonst heute.
	 *
	 * @param array  $k     Kampagne (event_datum, start_datum).
	 * @param string $heute Heute.
	 * @return string
	 */
	public static function km_datum( array $k, $heute ) {
		if ( ! empty( $k['event_datum'] ) ) {
			return (string) $k['event_datum'];
		}
		if ( ! empty( $k['start_datum'] ) ) {
			return (string) $k['start_datum'];
		}
		return (string) $heute;
	}

	/**
	 * Darf eine Zuordnung gelöst werden? Der Kilometerbeleg nur über „Kilometer“.
	 *
	 * @param int $beleg_id    Beleg.
	 * @param int $km_beleg_id Kilometerbeleg der Kampagne (0 = keiner).
	 * @return string Leer, wenn erlaubt, sonst der Grund.
	 */
	public static function loesen_blocker( $beleg_id, $km_beleg_id ) {
		if ( (int) $km_beleg_id > 0 && (int) $beleg_id === (int) $km_beleg_id ) {
			return __( 'Das ist der Beleg der Fahrtkosten. Er lässt sich nur über „Kilometer“ ändern oder mit 0 Kilometern zurücknehmen.', 'lindenweg16' );
		}
		return '';
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
		$ns   = RestApi::NAMESPACE_V1;
		$perm = $api->needs( array( 'verkauf.marketing' ) );

		$route = function ( $path, $methods, $cb ) use ( $ns, $perm ) {
			register_rest_route( $ns, $path, array(
				'methods'             => $methods,
				'callback'            => array( __CLASS__, $cb ),
				'permission_callback' => $perm,
			) );
		};

		$route( '/erp/marketing/optionen', 'GET', 'options' );
		$route( '/erp/marketing/speichern', 'POST', 'save' );
		$route( '/erp/marketing/(?P<id>\d+)/stamm', 'GET', 'master' );
		$route( '/erp/marketing/(?P<id>\d+)/zuordnen', 'POST', 'assign' );
		$route( '/erp/marketing/(?P<id>\d+)/loesen', 'POST', 'release' );
		$route( '/erp/marketing/(?P<id>\d+)/km', 'POST', 'mileage' );
		$route( '/erp/marketing/(?P<id>\d+)/beenden', 'POST', 'finish' );
	}

	/**
	 * Antwort mit Fehlertext.
	 *
	 * @param string $message Satz für die Person am Bildschirm.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Euro-Text für Meldungen.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	protected static function euro( $n ) {
		return number_format( (float) $n, 2, ',', '.' ) . ' €';
	}

	/**
	 * Kampagne holen oder null.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return object|null
	 */
	protected static function campaign_of( WP_REST_Request $request ) {
		return KampagnenRepository::get( (int) $request['id'] );
	}

	/**
	 * Antwort „Kampagne nicht gefunden“.
	 *
	 * @return WP_REST_Response
	 */
	protected static function not_found() {
		return self::fail( __( 'Diese Kampagne gibt es nicht (mehr).', 'lindenweg16' ), 404 );
	}

	/**
	 * Eingaben der Anfrage als Array.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array
	 */
	protected static function input( WP_REST_Request $request ) {
		return (array) $request->get_json_params();
	}

	/**
	 * Auswahllisten und Sätze für die Formulare.
	 *
	 * @return WP_REST_Response
	 */
	public static function options() {
		$types = array();
		foreach ( KampagnenRepository::TYPEN as $t ) {
			$types[] = array( 'value' => $t, 'label' => Verkauf::campaign_type_label( $t ) );
		}
		$labels = array(
			'geplant'       => __( 'geplant', 'lindenweg16' ),
			'aktiv'         => __( 'aktiv', 'lindenweg16' ),
			'abgeschlossen' => __( 'abgeschlossen', 'lindenweg16' ),
		);
		$status = array();
		foreach ( KampagnenRepository::STATUS as $s ) {
			$status[] = array( 'value' => $s, 'label' => $labels[ $s ] );
		}

		return new WP_REST_Response( array(
			'types'      => $types,
			'status'     => $status,
			'km_rate'    => (float) Settings::get( 'km_pauschale', 0.30 ),
			'km_account' => Kontenrahmen::label( Kontenrahmen::KONTO_FAHRTKOSTEN ),
			'today'      => gmdate( 'Y-m-d' ),
		) );
	}

	/**
	 * Felder einer Kampagne für das Bearbeiten-Formular, dazu Belege und Fahrtkosten.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function master( WP_REST_Request $request ) {
		$k = self::campaign_of( $request );
		if ( ! $k ) {
			return self::not_found();
		}

		$linked = array();
		foreach ( KampagnenRepository::belege( (int) $k->id ) as $b ) {
			$konto = (string) $b->konto;
			if ( '' === $konto ) {
				$konto = Kontenrahmen::konto_fuer_kategorie( $b->eur_kategorie );
			}
			$linked[] = array(
				'id'      => (int) $b->id,
				'number'  => $b->belegnummer ? (string) $b->belegnummer : '#' . (int) $b->id,
				'date'    => (string) $b->datum,
				'vendor'  => (string) $b->haendler,
				'account' => $konto ? Kontenrahmen::label( $konto ) : (string) $b->eur_kategorie,
				'amount'  => (float) $b->betrag,
				'share'   => (float) $b->anteil_prozent,
				'part'    => (float) $b->anteil_betrag,
				'is_km'   => (int) $k->km_beleg_id === (int) $b->id,
			);
		}

		// Auswahl wie auf der alten Seite: die 100 neuesten Belege mit freiem Rest.
		$open  = BelegRepository::query( array( 'per_page' => 100 ) );
		$ids   = array();
		foreach ( $open as $b ) {
			$ids[] = (int) $b->id;
		}
		$given = KampagnenRepository::vergebene_anteile( $ids );
		$pick  = array();
		foreach ( $open as $b ) {
			if ( '' !== self::beleg_blocker( $b ) ) {
				continue;
			}
			$free = Kampagnenrechnung::freier_anteil( isset( $given[ (int) $b->id ] ) ? $given[ (int) $b->id ] : 0 );
			if ( $free <= 0 ) {
				continue;
			}
			$pick[] = array(
				'id'     => (int) $b->id,
				'number' => $b->belegnummer ? (string) $b->belegnummer : '#' . (int) $b->id,
				'date'   => (string) $b->datum,
				'vendor' => (string) $b->haendler,
				'amount' => (float) $b->betrag,
				'free'   => (float) $free,
			);
		}

		$rate = (float) Settings::get( 'km_pauschale', 0.30 );
		$km   = (float) $k->gefahrene_km;

		return new WP_REST_Response( array(
			'id'       => (int) $k->id,
			'kst'      => (string) $k->kostenstelle,
			'fields'   => array(
				'name'           => (string) $k->name,
				'typ'            => (string) $k->typ,
				'kanal'          => (string) $k->kanal,
				'start_datum'    => (string) $k->start_datum,
				'ende_datum'     => (string) $k->ende_datum,
				'event_datum'    => (string) $k->event_datum,
				'budget_geplant' => (float) $k->budget_geplant,
				'utm_campaign'   => (string) $k->utm_campaign,
				'gutschein_code' => (string) $k->gutschein_code,
				'status'         => (string) $k->status,
				'notiz'          => (string) $k->notiz,
			),
			'belege'   => $linked,
			'pickable' => $pick,
			'km'       => array(
				'km'     => $km,
				'rate'   => $rate,
				'amount' => Calc::fahrtkosten( $km, $rate ),
				'booked' => (int) $k->km_beleg_id > 0,
			),
		) );
	}

	/**
	 * Kampagne anlegen (ohne `id`) oder ändern (mit `id`).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save( WP_REST_Request $request ) {
		$in = self::input( $request );
		$id = isset( $in['id'] ) ? (int) $in['id'] : 0;

		$existing = null;
		if ( $id ) {
			$existing = KampagnenRepository::get( $id );
			if ( ! $existing ) {
				return self::not_found();
			}
		}

		$clean = array();
		foreach ( array( 'name', 'kanal', 'utm_campaign', 'gutschein_code' ) as $f ) {
			$clean[ $f ] = sanitize_text_field( isset( $in[ $f ] ) && is_scalar( $in[ $f ] ) ? (string) $in[ $f ] : '' );
		}
		foreach ( array( 'typ', 'status' ) as $f ) {
			$clean[ $f ] = sanitize_key( isset( $in[ $f ] ) && is_scalar( $in[ $f ] ) ? (string) $in[ $f ] : '' );
		}
		foreach ( array( 'start_datum', 'ende_datum', 'event_datum' ) as $f ) {
			$clean[ $f ] = sanitize_text_field( isset( $in[ $f ] ) && is_scalar( $in[ $f ] ) ? (string) $in[ $f ] : '' );
		}
		$clean['notiz']          = sanitize_textarea_field( isset( $in['notiz'] ) && is_scalar( $in['notiz'] ) ? (string) $in['notiz'] : '' );
		$clean['budget_geplant'] = isset( $in['budget_geplant'] ) && is_scalar( $in['budget_geplant'] ) ? (string) $in['budget_geplant'] : '';

		$check = self::validate_campaign( $clean, gmdate( 'Y-m-d' ) );
		if ( $check['errors'] ) {
			return self::fail( implode( ' ', $check['errors'] ) );
		}

		if ( $existing ) {
			KampagnenRepository::update( $id, $check['data'] );
			return new WP_REST_Response( array(
				'ok'      => true,
				'id'      => $id,
				'message' => __( 'Kampagne gespeichert.', 'lindenweg16' ),
			) );
		}

		$new = KampagnenRepository::create( $check['data'] );
		if ( ! $new ) {
			return self::fail( __( 'Die Kampagne konnte nicht angelegt werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}
		return new WP_REST_Response( array(
			'ok'      => true,
			'id'      => $new,
			'message' => __( 'Kampagne angelegt.', 'lindenweg16' ),
		) );
	}

	/**
	 * Beleg einer Kampagne zuordnen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function assign( WP_REST_Request $request ) {
		$k = self::campaign_of( $request );
		if ( ! $k ) {
			return self::not_found();
		}

		$in       = self::input( $request );
		$beleg_id = isset( $in['beleg_id'] ) ? (int) $in['beleg_id'] : 0;
		if ( ! $beleg_id ) {
			return self::fail( __( 'Kein Beleg gewählt.', 'lindenweg16' ) );
		}

		$block = self::beleg_blocker( BelegRepository::get( $beleg_id ) );
		if ( '' !== $block ) {
			return self::fail( $block );
		}

		$share = self::validate_share( isset( $in['anteil'] ) ? $in['anteil'] : null );
		if ( '' !== $share['error'] ) {
			return self::fail( $share['error'] );
		}

		$done = KampagnenRepository::zuordnen( (int) $k->id, $beleg_id, $share['anteil'] );
		if ( $done <= 0 ) {
			return self::fail( __( 'Der Beleg ist bereits vollständig auf andere Kampagnen verteilt.', 'lindenweg16' ), 409 );
		}

		return new WP_REST_Response( array(
			'ok'      => true,
			'share'   => $done,
			/* translators: %s: Anteil in Prozent. */
			'message' => sprintf( __( 'Beleg mit %s zugeordnet.', 'lindenweg16' ), number_format( $done, 1, ',', '.' ) . ' %' ),
		) );
	}

	/**
	 * Zuordnung eines Belegs lösen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function release( WP_REST_Request $request ) {
		$k = self::campaign_of( $request );
		if ( ! $k ) {
			return self::not_found();
		}

		$in       = self::input( $request );
		$beleg_id = isset( $in['beleg_id'] ) ? (int) $in['beleg_id'] : 0;
		if ( ! $beleg_id ) {
			return self::fail( __( 'Kein Beleg gewählt.', 'lindenweg16' ) );
		}

		$block = self::loesen_blocker( $beleg_id, (int) $k->km_beleg_id );
		if ( '' !== $block ) {
			return self::fail( $block, 409 );
		}

		if ( ! KampagnenRepository::loesen( (int) $k->id, $beleg_id ) ) {
			return self::fail( __( 'Dieser Beleg ist der Kampagne gar nicht zugeordnet – vielleicht wurde er schon gelöst. Bitte die Seite neu laden.', 'lindenweg16' ), 409 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Zuordnung gelöst.', 'lindenweg16' ) ) );
	}

	/**
	 * Kilometergeld als Eigenbeleg buchen.
	 *
	 * Ein bereits gebuchter Kilometerbeleg wird nicht überschrieben, sondern
	 * storniert und neu angelegt – der alte ist festgeschrieben.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function mileage( WP_REST_Request $request ) {
		$k = self::campaign_of( $request );
		if ( ! $k ) {
			return self::not_found();
		}
		$id = (int) $k->id;

		$in    = self::input( $request );
		$check = self::validate_km( isset( $in['gefahrene_km'] ) ? $in['gefahrene_km'] : null );
		if ( '' !== $check['error'] ) {
			return self::fail( $check['error'] );
		}
		$km     = $check['km'];
		$satz   = (float) Settings::get( 'km_pauschale', 0.30 );
		$betrag = Calc::fahrtkosten( $km, $satz );

		// Alten Kilometerbeleg zurücknehmen, damit nichts doppelt steht.
		if ( $k->km_beleg_id ) {
			KampagnenRepository::loesen( $id, (int) $k->km_beleg_id );
			BelegRepository::stornieren(
				(int) $k->km_beleg_id,
				__( 'Kilometerangabe der Kampagne korrigiert', 'lindenweg16' )
			);
		}

		$beleg_id = null;
		if ( $betrag > 0 ) {
			$beleg_id = BelegRepository::create( array(
				'betrag'        => $betrag,
				'datum'         => self::km_datum( (array) $k, gmdate( 'Y-m-d' ) ),
				'haendler'      => __( 'Kilometerpauschale (Eigenbeleg)', 'lindenweg16' ),
				'konto'         => Kontenrahmen::KONTO_FAHRTKOSTEN,
				'eur_kategorie' => 'Fahrtkosten',
				'notiz'         => sprintf(
					/* translators: 1: Kilometer, 2: Satz je km, 3: Kostenstelle, 4: Name. */
					__( '%1$s km × %2$s – Fahrt für %3$s %4$s', 'lindenweg16' ),
					number_format( $km, 1, ',', '.' ),
					number_format( $satz, 2, ',', '.' ) . ' €',
					$k->kostenstelle,
					$k->name
				),
				'quelle'        => 'kilometergeld',
			) );

			if ( $beleg_id ) {
				BelegRepository::festschreiben( $beleg_id );
				KampagnenRepository::zuordnen( $id, $beleg_id, 100 );
			}
		}

		KampagnenRepository::update( $id, array(
			'gefahrene_km' => $km,
			'km_beleg_id'  => $beleg_id,
		) );

		AuditLog::record( 'kampagne_km_gebucht', 'kampagne', $id, array(
			'km'       => $km,
			'satz'     => $satz,
			'betrag'   => $betrag,
			'beleg_id' => $beleg_id,
		) );

		return new WP_REST_Response( array(
			'ok'      => true,
			'amount'  => $betrag,
			'message' => $betrag > 0
				/* translators: %s: Betrag. */
				? sprintf( __( 'Fahrtkosten gebucht: %s.', 'lindenweg16' ), self::euro( $betrag ) )
				: __( 'Fahrtkosten zurückgenommen.', 'lindenweg16' ),
		) );
	}

	/**
	 * Kampagne beenden (Status „abgeschlossen“).
	 *
	 * Die alte Seite kannte kein Löschen: Eine Kampagne mit Belegzuordnung und
	 * Fahrtkostenbeleg bleibt für die Auswertung erhalten. Beenden setzt nur
	 * den Status; über „Ändern“ lässt sich die Kampagne wieder öffnen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function finish( WP_REST_Request $request ) {
		$k = self::campaign_of( $request );
		if ( ! $k ) {
			return self::not_found();
		}
		if ( 'abgeschlossen' === $k->status ) {
			return self::fail( __( 'Diese Kampagne ist schon abgeschlossen.', 'lindenweg16' ), 409 );
		}

		KampagnenRepository::update( (int) $k->id, array( 'status' => 'abgeschlossen' ) );
		AuditLog::record( 'kampagne_beendet', 'kampagne', (int) $k->id, array( 'vorher' => (string) $k->status ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Kampagne abgeschlossen.', 'lindenweg16' ) ) );
	}
}
