<?php
/**
 * Die drei Gutschein-Vorlagen im Stil von lindenweg16.de.
 *
 * Gerendert wird mit Tabellen und Inline-Stilen, denn dasselbe Markup geht
 * als E-Mail raus und dient auf der Produktseite als Vorschau. Die Farben
 * sind die des Block-Themes (theme.json): Tinte, Cognac, Gold, Taubenblau
 * und Rosé auf dem warmen Seitenhintergrund.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Gutschein;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Vorlagen-Katalog und HTML-Ausgabe.
 */
class Vorlagen {

	/**
	 * Kennung für „nur als E-Mail“, ohne gestaltete Karte.
	 */
	const NUR_MAIL = 'mail';

	/**
	 * Alle gestalteten Vorlagen.
	 *
	 * @return array Kennung => Bezeichnung und Farben.
	 */
	public static function alle() {
		return array(
			'klassik' => array(
				'label'   => __( 'Klassik – Tinte & Gold', 'lindenweg16' ),
				'kurz'    => __( 'Klassik', 'lindenweg16' ),
				'outer'   => '#f4f2ee',
				'card'    => '#2b323a',
				'text'    => '#f4f2ee',
				'muted'   => '#c8d2d7',
				'accent'  => '#e7c78f',
				'line'    => '#59626c',
				'box'     => '#3a424c',
				'on_box'  => '#ffffff',
			),
			'leicht'  => array(
				'label'   => __( 'Leicht – Taubenblau', 'lindenweg16' ),
				'kurz'    => __( 'Leicht', 'lindenweg16' ),
				'outer'   => '#f4f2ee',
				'card'    => '#c8d2d7',
				'text'    => '#22282f',
				'muted'   => '#3a424c',
				'accent'  => '#a86f38',
				'line'    => '#a9b6bd',
				'box'     => '#ffffff',
				'on_box'  => '#22282f',
			),
			'warm'    => array(
				'label'   => __( 'Warm – Cognac & Rosé', 'lindenweg16' ),
				'kurz'    => __( 'Warm', 'lindenweg16' ),
				'outer'   => '#f4f2ee',
				'card'    => '#e8b9a1',
				'text'    => '#22282f',
				'muted'   => '#3a424c',
				'accent'  => '#7a4a1f',
				'line'    => '#d29c80',
				'box'     => '#f4f2ee',
				'on_box'  => '#22282f',
			),
		);
	}

	/**
	 * Alle wählbaren Kennungen, „nur E-Mail“ eingeschlossen.
	 *
	 * @return string[]
	 */
	public static function kennungen() {
		return array_merge( array_keys( self::alle() ), array( self::NUR_MAIL ) );
	}

	/**
	 * Eine Auswahl auf eine gültige Kennung zurückführen.
	 *
	 * @param mixed $wert Eingabe.
	 * @return string Kennung oder leer.
	 */
	public static function normalisiere( $wert ) {
		$wert = sanitize_key( (string) $wert );

		return in_array( $wert, self::kennungen(), true ) ? $wert : '';
	}

	/**
	 * Bezeichnung einer Auswahl.
	 *
	 * @param string $kennung Kennung.
	 * @return string
	 */
	public static function label( $kennung ) {
		$alle = self::alle();

		if ( isset( $alle[ $kennung ] ) ) {
			return $alle[ $kennung ]['label'];
		}

		return __( 'Nur als E-Mail (ohne Vorlage)', 'lindenweg16' );
	}

	/**
	 * Eine Vorlage als HTML.
	 *
	 * @param string $kennung  Kennung aus alle().
	 * @param array  $daten    von, an, text, betrag, code, gueltig_bis.
	 * @param bool   $vorschau true: Felder tragen data-Attribute für die Live-Vorschau.
	 * @return string
	 */
	public static function render( $kennung, array $daten, $vorschau = false ) {
		$alle = self::alle();

		if ( ! isset( $alle[ $kennung ] ) ) {
			return '';
		}

		$p     = $alle[ $kennung ];
		$daten = wp_parse_args(
			$daten,
			array(
				'von'         => '',
				'an'          => '',
				'text'        => '',
				'betrag'      => '',
				'code'        => 'LW16-XXXX-XXXX',
				'gueltig_bis' => '',
			)
		);

		$firma = (string) Settings::get( 'firma_name', 'Lindenweg16' );
		$serif = "Georgia,'Times New Roman',serif";
		$sans  = "'Inter',-apple-system,'Segoe UI',Helvetica,Arial,sans-serif";
		$mono  = "'DM Mono',ui-monospace,Menlo,Consolas,monospace";

		// Feld mit Platzhalter für die Vorschau.
		$feld = function ( $name, $wert, $leer ) use ( $vorschau ) {
			$anzeige = '' !== trim( (string) $wert ) ? (string) $wert : ( $vorschau ? $leer : '' );
			$attr    = $vorschau ? ' data-lw16-feld="' . esc_attr( $name ) . '" data-leer="' . esc_attr( $leer ) . '"' : '';

			return '<span' . $attr . '>' . nl2br( esc_html( $anzeige ) ) . '</span>';
		};

		$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $p['outer'] . '" style="background:' . $p['outer'] . ';font-family:' . $sans . '"><tr><td align="center" style="padding:24px 12px">';
		$html .= '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $p['card'] . '" style="width:100%;max-width:560px;background:' . $p['card'] . ';border-radius:18px;border:1px solid ' . $p['line'] . '">';

		// Kopf.
		$html .= '<tr><td style="padding:30px 36px 0;font-size:12px;letter-spacing:.22em;text-transform:uppercase;color:' . $p['accent'] . ';font-weight:600">' . esc_html( $firma ) . '</td></tr>';
		$html .= '<tr><td style="padding:6px 36px 0;font-family:' . $serif . ';font-size:38px;line-height:1.1;color:' . $p['text'] . '">' . esc_html__( 'Gutschein', 'lindenweg16' ) . '</td></tr>';
		$html .= '<tr><td style="padding:14px 36px 0"><table role="presentation" width="48" cellpadding="0" cellspacing="0" border="0"><tr><td height="3" bgcolor="' . $p['accent'] . '" style="background:' . $p['accent'] . ';font-size:0;line-height:0">&nbsp;</td></tr></table></td></tr>';

		// Betrag.
		$html .= '<tr><td style="padding:22px 36px 0;font-family:' . $serif . ';font-size:44px;line-height:1;color:' . $p['accent'] . '">' . $feld( 'betrag', $daten['betrag'], __( 'Dein Betrag', 'lindenweg16' ) ) . '</td></tr>';

		// Für / Von.
		$html .= '<tr><td style="padding:26px 36px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
		$html .= '<td width="50%" valign="top" style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:' . $p['muted'] . '">' . esc_html__( 'Für', 'lindenweg16' ) . '<div style="margin-top:4px;font-size:18px;letter-spacing:0;text-transform:none;color:' . $p['text'] . ';font-weight:600">' . $feld( 'an', $daten['an'], __( 'Name des Beschenkten', 'lindenweg16' ) ) . '</div></td>';
		$html .= '<td width="50%" valign="top" style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:' . $p['muted'] . '">' . esc_html__( 'Von', 'lindenweg16' ) . '<div style="margin-top:4px;font-size:18px;letter-spacing:0;text-transform:none;color:' . $p['text'] . ';font-weight:600">' . $feld( 'von', $daten['von'], __( 'Dein Name', 'lindenweg16' ) ) . '</div></td>';
		$html .= '</tr></table></td></tr>';

		// Persönlicher Text.
		$html .= '<tr><td style="padding:22px 36px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
		$html .= '<td width="3" bgcolor="' . $p['accent'] . '" style="background:' . $p['accent'] . '">&nbsp;</td>';
		$html .= '<td style="padding:4px 0 4px 16px;font-family:' . $serif . ';font-style:italic;font-size:17px;line-height:1.55;color:' . $p['text'] . '">' . $feld( 'text', $daten['text'], __( 'Hier steht Deine persönliche Nachricht.', 'lindenweg16' ) ) . '</td>';
		$html .= '</tr></table></td></tr>';

		// Code.
		$html .= '<tr><td style="padding:28px 36px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $p['box'] . '" style="background:' . $p['box'] . ';border-radius:12px;border:1px dashed ' . $p['accent'] . '"><tr><td align="center" style="padding:16px 12px">';
		$html .= '<div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:' . $p['accent'] . '">' . esc_html__( 'Gutscheincode', 'lindenweg16' ) . '</div>';
		$html .= '<div style="margin-top:6px;font-family:' . $mono . ';font-size:22px;letter-spacing:.08em;color:' . $p['on_box'] . '">' . esc_html( $daten['code'] ) . '</div>';
		$html .= '</td></tr></table></td></tr>';

		// Fuß.
		$fuss = '' !== (string) $daten['gueltig_bis']
			/* translators: 1: Shop-Adresse, 2: Datum. */
			? sprintf( __( 'Einlösbar im Shop auf %1$s bis zum %2$s.', 'lindenweg16' ), self::shop_host(), $daten['gueltig_bis'] )
			/* translators: %s: Shop-Adresse. */
			: sprintf( __( 'Einlösbar im Shop auf %s.', 'lindenweg16' ), self::shop_host() );

		$html .= '<tr><td align="center" style="padding:22px 36px 30px;font-size:12px;line-height:1.6;color:' . $p['muted'] . '">' . esc_html( $fuss ) . '<br>' . esc_html__( 'Code im Warenkorb unter „Gutschein“ eingeben.', 'lindenweg16' ) . '</td></tr>';

		$html .= '</table></td></tr></table>';

		return $html;
	}

	/**
	 * Adresse des Shops ohne Schema.
	 *
	 * @return string
	 */
	protected static function shop_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return $host ? (string) $host : 'lindenweg16.de';
	}
}
