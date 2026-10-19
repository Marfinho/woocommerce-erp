<?php
/**
 * ERP-Seite „Anleitungen“: die Betriebsanleitung aus docs/ lesen.
 *
 * Die Teile und die Datei kommen aus `HandbuchPage` (eine Quelle, keine
 * zweite Fassung). Die Anleitung ist ein eigenes HTML-Dokument des Plugins
 * mit eigenem Stylesheet und bleibt deshalb in einem Rahmen; Reiter und
 * Rahmen gehören der neuen Oberfläche. Die Kennung der Reiter ist auf die
 * bekannten Teile begrenzt, ein beliebiger Pfad lässt sich nicht laden.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Admin\HandbuchPage;
use Lindenweg16\Erp\RestApi;
use WP_REST_Response;

/**
 * Lesende Anleitungsseite.
 */
class Hilfe {

	/**
	 * Route anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/anleitungen',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'manuals' ),
				'permission_callback' => $api->needs( array( 'verwaltung.anleitungen' ) ),
			)
		);
	}

	/**
	 * Adresse eines Teils im Dokument – nur für bekannte Teile.
	 *
	 * @param array  $manuals Kennung => [Beschriftung, Sprungmarke, Kurzbeschreibung].
	 * @param string $key     Kennung.
	 * @param string $base    Adresse der Datei.
	 * @return string Leer, wenn die Kennung unbekannt ist.
	 */
	public static function part_url( array $manuals, $key, $base ) {
		if ( ! isset( $manuals[ $key ] ) ) {
			return '';
		}

		return $base . '#' . $manuals[ $key ][1];
	}

	/**
	 * Teile der Anleitung.
	 *
	 * @return WP_REST_Response
	 */
	public static function manuals() {
		if ( ! file_exists( LW16_PLUGIN_DIR . HandbuchPage::DIR . HandbuchPage::FILE ) ) {
			return new WP_REST_Response(
				array(
					'missing' => sprintf(
						/* translators: %s: Dateiname der Anleitung. */
						__( 'Die Datei %s fehlt. Die Anleitungen liegen im Ordner docs/ des Plugins – wurde er beim Hochladen ausgelassen, lässt er sich einfach nachladen.', 'lindenweg16' ),
						HandbuchPage::DIR . HandbuchPage::FILE
					),
				)
			);
		}

		$manuals = HandbuchPage::manuals();
		$base    = LW16_PLUGIN_URL . HandbuchPage::DIR . HandbuchPage::FILE;
		$parts   = array();

		foreach ( $manuals as $key => $manual ) {
			$parts[] = array(
				'id'    => $key,
				'label' => $manual[0],
				'hint'  => $manual[2],
				'url'   => self::part_url( $manuals, $key, $base ),
			);
		}

		return new WP_REST_Response( array( 'parts' => $parts ) );
	}
}
