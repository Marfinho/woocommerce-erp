<?php
/**
 * Schnittstelle für die anonymen Zähler.
 *
 * Zwei Routen, beide ohne Anmeldung (die Besucher sind anonym):
 *
 * - `GET  lw16/v1/ar-nonce`  liefert eine frische Nonce, nie aus dem Cache.
 * - `POST lw16/v1/ar-event`  zählt ein Ereignis, wenn die Nonce stimmt.
 *
 * Warum die Nonce nicht ins HTML geschrieben wird: Seiten-Caches liefern
 * dieselbe Datei tagelang aus, eine eingebettete Nonce wäre längst
 * abgelaufen. Das Skript holt sie deshalb erst, wenn es sie braucht.
 *
 * Ehrlich zur Reichweite: Die Nonce gehört zu keinem Benutzer (die
 * Besucher sind abgemeldet), sie begrenzt nur die Gültigkeitsdauer und
 * hält Aufrufe ohne vorherigen Seitenbesuch ab. Wer bewusst zählen will,
 * kann zählen – deshalb sind es reine Kennzahlen ohne Folgen. Gespeichert
 * wird weder IP-Adresse noch Benutzerkennung noch Gerätekennung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\ArView;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\QrCode;
use Lindenweg16\Modules\Configurator\ArLink;

/**
 * REST-Routen der AR-Ansicht.
 */
class RestApi {

	const REST_NS = 'lw16/v1';
	const ACTION  = 'lw16_ar_event';
	const HEADER  = 'X-LW16-Nonce';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Routen anmelden.
	 */
	public function routes() {
		register_rest_route(
			self::REST_NS,
			'/ar-nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'nonce' ),
				'permission_callback' => '__return_true',
			)
		);

		// QR-Code für die AR-Ansicht aus dem Konfigurator: Am Rechner lässt
		// sich nichts an die Wand stellen, der Code öffnet dieselbe
		// Konfiguration am Telefon.
		register_rest_route(
			self::REST_NS,
			'/ar-qr',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'qr' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code'  => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'seite' => array(
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/ar-event',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'event' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'product' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'event'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( Stats::class, 'is_event' ),
					),
				),
			)
		);
	}

	/**
	 * Antwort, die kein Cache behalten darf.
	 *
	 * @param array $data   Inhalt.
	 * @param int   $status Statuscode.
	 * @return \WP_REST_Response
	 */
	protected function uncached( array $data, $status = 200 ) {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * QR-Code zu einer Konfiguration.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response
	 */
	public function qr( $request ) {
		if ( ! Options::enabled() ) {
			return $this->uncached( array( 'ok' => false ), 404 );
		}

		$link = ArLink::build( (string) $request->get_param( 'seite' ), (string) $request->get_param( 'code' ), home_url( '/' ) );

		if ( '' === $link ) {
			return $this->uncached( array( 'ok' => false ), 400 );
		}

		return $this->uncached(
			array(
				'ok'  => true,
				'url' => $link,
				'svg' => QrCode::svg( $link, 220, 'M' ),
			)
		);
	}

	/**
	 * Frische Nonce ausgeben.
	 *
	 * @return \WP_REST_Response
	 */
	public function nonce() {
		return $this->uncached( array( 'nonce' => wp_create_nonce( self::ACTION ) ) );
	}

	/**
	 * Nonce im Kopf der Anfrage prüfen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return true|\WP_Error
	 */
	public function check_nonce( $request ) {
		$nonce = (string) $request->get_header( self::HEADER );

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return new \WP_Error( 'lw16_ar_nonce', __( 'Ungültige oder abgelaufene Anfrage.', 'lindenweg16' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Ein Ereignis zählen.
	 *
	 * Gezählt wird nur für veröffentlichte Produkte mit Modell – für alles
	 * andere wäre jede Zahl erfunden.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response
	 */
	public function event( $request ) {
		$product_id = (int) $request->get_param( 'product' );
		$event      = (string) $request->get_param( 'event' );

		if ( 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) || ! ProductMeta::has_model( $product_id ) ) {
			return $this->uncached( array( 'ok' => false ), 404 );
		}

		$ok = Stats::record( $product_id, $event );

		return $this->uncached( array( 'ok' => (bool) $ok ), $ok ? 200 : 500 );
	}
}
