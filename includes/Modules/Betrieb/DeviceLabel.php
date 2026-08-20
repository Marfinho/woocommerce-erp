<?php
/**
 * Geräteaufkleber für die mobile Schnellerfassung.
 *
 * Jeder Drucker bekommt einen Aufkleber mit seiner Kurz-URL. Scannen oder
 * Abtippen öffnet die Erfassung mit vorausgewähltem Gerät.
 *
 * Hinweis zur QR-Darstellung: Ein QR-Code wird bewusst **nicht** selbst
 * erzeugt. Ein handgeschriebener Encoder war ohne Referenzbibliothek nicht
 * verlässlich zu verifizieren, und ein Code, den der Scanner nicht liest, ist
 * schlimmer als keiner. Stattdessen steht die URL groß und tippbar auf dem
 * Aufkleber. Wer QR will, hinterlegt in den Einstellungen die URL-Vorlage
 * eines Diensts oder es wird später eine geprüfte Bibliothek mitgeliefert.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Baut die Erfassungs-URLs und das Druckbild des Aufklebers.
 */
class DeviceLabel {

	/**
	 * Pfad der mobilen Erfassung.
	 */
	const ROUTE = 'betrieb-erfassen';

	/**
	 * Erfassungs-URL, optional mit vorausgewähltem Drucker.
	 *
	 * @param string $printer_code Gerätekürzel.
	 * @return string
	 */
	public static function url( $printer_code = '' ) {
		$url = self::pretty_permalinks()
			? home_url( '/' . self::ROUTE . '/' )
			: add_query_arg( 'lw16_erfassung', '1', home_url( '/' ) );

		if ( $printer_code ) {
			$url = add_query_arg( 'p', rawurlencode( $printer_code ), $url );
		}

		return $url;
	}

	/**
	 * Nutzt die Seite sprechende Permalinks?
	 *
	 * Ohne sie greift keine Rewrite-Regel. Die Kurzadresse waere dann nicht
	 * nur unschoen, sondern gar nicht erreichbar – deshalb wird in dem Fall
	 * auf die Abfragevariable ausgewichen.
	 *
	 * @return bool
	 */
	public static function pretty_permalinks() {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * Aufkleber als druckbares HTML.
	 *
	 * @param array $printer Gerät.
	 * @return string
	 */
	public static function render( array $printer ) {
		$url = self::url( $printer['code'] );

		$html  = '<div class="lw16-label">';
		$html .= '<div class="lw16-label__code">' . esc_html( $printer['code'] ) . '</div>';
		$html .= '<div class="lw16-label__model">' . esc_html( trim( (string) $printer['manufacturer'] . ' ' . (string) $printer['model'] ) ) . '</div>';
		$html .= '<div class="lw16-label__url">' . esc_html( self::readable( $url ) ) . '</div>';
		$html .= '<div class="lw16-label__hint">' . esc_html__( 'Öffnen und Druckjob erfassen', 'lindenweg16' ) . '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * URL ohne Schema, damit sie auf dem Aufkleber kurz bleibt.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function readable( $url ) {
		return preg_replace( '#^https?://#', '', (string) $url );
	}
}
