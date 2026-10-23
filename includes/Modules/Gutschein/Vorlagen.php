<?php
/**
 * Die drei Gutschein-Vorlagen im Stil des Haster-Haken-Flyers.
 *
 * Gerendert wird mit Tabellen und Inline-Stilen, denn dasselbe Markup geht
 * als E-Mail raus und dient auf der Produktseite als Vorschau. Die Vorlage
 * ist der Flyer: warmes Hellgrau, fette enge Grotesk in Schwarz, ein
 * einziger pinkroter Akzent (#EE1C4F), Sperrschrift in Großbuchstaben und
 * dünne Linien – keine Rundungen, keine Verläufe.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Gutschein;

defined( 'ABSPATH' ) || exit;

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
			'hell'   => array(
				'label'   => __( 'Hell – Grau & Pink', 'lindenweg16' ),
				'kurz'    => __( 'Hell', 'lindenweg16' ),
				'bg'      => '#EDEBE9',
				'fg'      => '#161616',
				'accent'  => '#EE1C4F',
				'sixteen' => '#EE1C4F',
				'muted'   => '#6B6B6B',
				'line'    => '#D4D1CE',
				'box'     => '#FFFFFF',
				'box_fg'  => '#161616',
				'box_ln'  => '#D4D1CE',
			),
			'pink'   => array(
				'label'   => __( 'Pink – volle Farbe', 'lindenweg16' ),
				'kurz'    => __( 'Pink', 'lindenweg16' ),
				'bg'      => '#EE1C4F',
				'fg'      => '#FFFFFF',
				'accent'  => '#161616',
				'sixteen' => '#161616',
				'muted'   => '#FFE3E9',
				'line'    => '#F46B8C',
				'box'     => '#FFFFFF',
				'box_fg'  => '#161616',
				'box_ln'  => '#FFFFFF',
			),
			'dunkel' => array(
				'label'   => __( 'Dunkel – Schwarz & Pink', 'lindenweg16' ),
				'kurz'    => __( 'Dunkel', 'lindenweg16' ),
				'bg'      => '#161616',
				'fg'      => '#FFFFFF',
				'accent'  => '#EE1C4F',
				'sixteen' => '#EE1C4F',
				'muted'   => '#A8A8A8',
				'line'    => '#3A3A3A',
				'box'     => '#262626',
				'box_fg'  => '#FFFFFF',
				'box_ln'  => '#3A3A3A',
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

		$font = "'Bricolage Grotesque','Helvetica Neue',Helvetica,Arial,sans-serif";
		$fg   = $p['fg'];
		$acc  = $p['accent'];
		$mut  = $p['muted'];
		$ln   = $p['line'];

		// Feld mit Platzhalter für die Vorschau.
		$feld = function ( $name, $wert, $leer ) use ( $vorschau ) {
			$anzeige = '' !== trim( (string) $wert ) ? (string) $wert : ( $vorschau ? $leer : '' );
			$attr    = $vorschau ? ' data-lw16-feld="' . esc_attr( $name ) . '" data-leer="' . esc_attr( $leer ) . '"' : '';

			return '<span' . $attr . '>' . nl2br( esc_html( $anzeige ) ) . '</span>';
		};

		// Kleine gesperrte Beschriftung in Großbuchstaben.
		$label = function ( $text, $farbe ) {
			return '<div style="font-size:11px;font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:' . $farbe . '">' . esc_html( $text ) . '</div>';
		};

		// Dünne Trennlinie über die ganze Breite.
		$linie = '<tr><td style="padding:0 40px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td height="1" bgcolor="' . $ln . '" style="background:' . $ln . ';font-size:0;line-height:0">&nbsp;</td></tr></table></td></tr>';
		$balken = function ( $breite ) use ( $acc ) {
			return '<table role="presentation" width="' . (int) $breite . '" cellpadding="0" cellspacing="0" border="0"><tr><td height="4" bgcolor="' . $acc . '" style="background:' . $acc . ';font-size:0;line-height:0">&nbsp;</td></tr></table>';
		};

		$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $p['bg'] . '" style="background:' . $p['bg'] . ';font-family:' . $font . '"><tr><td align="center" style="padding:0">';
		$html .= '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $p['bg'] . '" style="width:100%;max-width:560px;background:' . $p['bg'] . '">';

		// Wortmarke und Tagline wie im Flyer.
		$html .= '<tr><td style="padding:40px 40px 0"><div style="font-size:30px;font-weight:800;letter-spacing:-.045em;line-height:1;color:' . $fg . '">Lindenweg<span style="color:' . $p['sixteen'] . '">16</span></div>';
		$html .= '<div style="margin-top:8px;font-size:10px;font-weight:600;letter-spacing:.3em;text-transform:uppercase;color:' . $mut . '">' . esc_html__( 'Handgemacht in Haste', 'lindenweg16' ) . '</div></td></tr>';

		// Titel und Balken.
		$html .= '<tr><td style="padding:52px 40px 0;font-size:64px;font-weight:800;letter-spacing:-.05em;line-height:.95;color:' . $fg . '">' . esc_html__( 'Gutschein.', 'lindenweg16' ) . '</td></tr>';
		$html .= '<tr><td style="padding:20px 40px 0">' . $balken( 44 ) . '</td></tr>';

		// Betrag.
		$html .= '<tr><td style="padding:26px 40px 30px;font-size:40px;font-weight:800;letter-spacing:-.03em;line-height:1;color:' . $fg . '">' . $feld( 'betrag', $daten['betrag'], __( 'Dein Betrag', 'lindenweg16' ) ) . '</td></tr>';

		// Für / Von.
		$html .= $linie;
		$html .= '<tr><td style="padding:18px 40px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
		$html .= '<td width="50%" valign="top">' . $label( __( 'Für', 'lindenweg16' ), $acc ) . '<div style="margin-top:6px;font-size:20px;font-weight:700;letter-spacing:-.02em;color:' . $fg . '">' . $feld( 'an', $daten['an'], __( 'Name des Beschenkten', 'lindenweg16' ) ) . '</div></td>';
		$html .= '<td width="50%" valign="top">' . $label( __( 'Von', 'lindenweg16' ), $acc ) . '<div style="margin-top:6px;font-size:20px;font-weight:700;letter-spacing:-.02em;color:' . $fg . '">' . $feld( 'von', $daten['von'], __( 'Dein Name', 'lindenweg16' ) ) . '</div></td>';
		$html .= '</tr></table></td></tr>';

		// Text.
		$html .= $linie;
		$html .= '<tr><td style="padding:18px 40px;font-size:16px;line-height:1.6;color:' . $fg . '">' . $feld( 'text', $daten['text'], __( 'Hier steht Deine persönliche Nachricht.', 'lindenweg16' ) ) . '</td></tr>';
		$html .= $linie;

		// Code.
		$html .= '<tr><td style="padding:26px 40px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $p['box'] . '" style="background:' . $p['box'] . ';border:1px solid ' . $p['box_ln'] . '"><tr><td style="padding:16px 20px">';
		$html .= $label( __( 'Gutscheincode', 'lindenweg16' ), '#EE1C4F' );
		$html .= '<div style="margin-top:6px;font-size:26px;font-weight:800;letter-spacing:.06em;color:' . $p['box_fg'] . '">' . esc_html( $daten['code'] ) . '</div>';
		$html .= '</td></tr></table></td></tr>';

		// Fuß wie die Rückseite des Flyers.
		$html .= '<tr><td style="padding:32px 40px 0;font-size:13px;color:' . $mut . '">' . esc_html__( 'Jetzt einlösen', 'lindenweg16' ) . '</td></tr>';
		$html .= '<tr><td style="padding:4px 40px 0;font-size:24px;font-weight:800;letter-spacing:-.04em;color:' . $fg . '">' . esc_html( self::shop_host() ) . '</td></tr>';
		$html .= '<tr><td style="padding:12px 40px 0">' . $balken( 44 ) . '</td></tr>';

		$fuss = esc_html__( 'Code im Warenkorb unter „Gutschein“ eingeben.', 'lindenweg16' );
		if ( '' !== (string) $daten['gueltig_bis'] ) {
			/* translators: %s: Datum. */
			$fuss .= '<br>' . esc_html( sprintf( __( 'Gültig bis zum %s.', 'lindenweg16' ), $daten['gueltig_bis'] ) );
		}
		$html .= '<tr><td style="padding:12px 40px 44px;font-size:13px;line-height:1.6;color:' . $mut . '">' . $fuss . '</td></tr>';

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
