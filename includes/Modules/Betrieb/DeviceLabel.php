<?php
/**
 * Geräteaufkleber für die mobile Schnellerfassung.
 *
 * Jeder Drucker bekommt einen Aufkleber mit seiner Kurz-URL. Scannen oder
 * Abtippen öffnet die Erfassung mit vorausgewähltem Gerät.
 *
 * Der QR-Code kommt aus dem eigenen Encoder (siehe QrCode). Die URL steht
 * zusätzlich in Klartext darunter: Wenn ein Aufkleber verschmutzt oder das
 * Handy keinen Scanner öffnet, muss der Zettel trotzdem benutzbar bleiben.
 * Fehlerkorrekturstufe Q, weil der Aufkleber auf einem Drucker in der
 * Werkstatt klebt und nicht in einer Schublade.
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
	 * Erfassungs-URL, die direkt auf einer Pickliste aufsetzt.
	 *
	 * Gedacht für den QR-Code neben einem Fertigungsauftrag: scannen,
	 * und das Handy zeigt genau diese Materialliste – nicht alle offenen.
	 *
	 * @param string $picklist_code Code der Pickliste.
	 * @return string
	 */
	public static function pick_url( $picklist_code ) {
		$code = trim( (string) $picklist_code );

		if ( '' === $code ) {
			return self::url();
		}

		return add_query_arg( 'pick', rawurlencode( $code ), self::url() );
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
		$html .= '<div class="lw16-label__qr">' . self::qr( $url ) . '</div>';
		$html .= '<div class="lw16-label__url">' . esc_html( self::readable( $url ) ) . '</div>';
		$html .= '<div class="lw16-label__hint">' . esc_html__( 'Scannen und Druckjob erfassen', 'lindenweg16' ) . '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * QR-Code als SVG, mit stiller Rückfallebene.
	 *
	 * Schlägt die Erzeugung fehl – etwa weil ein Inhalt unerwartet lang ist –,
	 * bleibt der Aufkleber ohne Bild, aber mit lesbarer URL. Ein halber
	 * Aufkleber ist besser als eine weiße Seite mit Fehlermeldung.
	 *
	 * @param string $url   Inhalt.
	 * @param int    $px    Kantenlänge.
	 * @param string $level Fehlerkorrektur.
	 * @return string
	 */
	public static function qr( $url, $px = 120, $level = 'Q' ) {
		try {
			return QrCode::svg( $url, $px, $level );
		} catch ( \Exception $e ) {
			return '';
		}
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
