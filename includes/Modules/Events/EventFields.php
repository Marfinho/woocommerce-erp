<?php
/**
 * Variable Zusatzfelder eines Events.
 *
 * Jedes Event kann beliebig viele eigene Felder tragen – beim Social Ride
 * eine Komoot-Tour und die Distanz, beim Markt die Standnummer, beim Rennen
 * die Startzeit. Die Felder werden je Event angelegt, nicht global
 * definiert; für die geläufigen Eventtypen gibt es Vorlagen als Startpunkt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Definiert die Feldtypen, prüft Eingaben und erzeugt die Ausgabe.
 */
class EventFields {

	/**
	 * Feldtypen mit Beschriftung.
	 *
	 * Bewusst kein freies HTML- oder iframe-Feld: Eingebettet wird nur, was
	 * hier einen eigenen Typ hat. Ein Feld, in das man beliebiges Markup
	 * kippen kann, ist eine offene Tür – auch wenn heute nur man selbst
	 * hineinschreibt.
	 *
	 * @return array Typ => Bezeichnung.
	 */
	public static function typen() {
		return array(
			'text'     => __( 'Text (einzeilig)', 'lindenweg16' ),
			'textarea' => __( 'Text (mehrzeilig)', 'lindenweg16' ),
			'zahl'     => __( 'Zahl', 'lindenweg16' ),
			'datum'    => __( 'Datum', 'lindenweg16' ),
			'zeit'     => __( 'Uhrzeit', 'lindenweg16' ),
			'url'      => __( 'Link', 'lindenweg16' ),
			'komoot'   => __( 'Komoot-Tour (eingebettete Karte)', 'lindenweg16' ),
		);
	}

	/**
	 * Darstellungen, die Komoots Einbettung tatsächlich kennt.
	 *
	 * Komoot steuert seine Einbettung über genau einen Parameter: `profile`.
	 * Die Grundkarte (Karte, Satellit, Gelände) lässt sich von außen nicht
	 * vorgeben – sie wird im eingebetteten Kartenfenster selbst umgeschaltet.
	 * Hier stehen deshalb nur die beiden Darstellungen, die auch wirken; ein
	 * Auswahlfeld mit Werten, die nichts tun, wäre schlimmer als keines.
	 *
	 * @return array Schlüssel => Bezeichnung.
	 */
	public static function komoot_darstellungen() {
		return array(
			'profil' => __( 'Karte mit Höhenprofil', 'lindenweg16' ),
			'karte'  => __( 'Nur Karte, ohne Höhenprofil', 'lindenweg16' ),
		);
	}

	/**
	 * Höhe der Einbettung je Darstellung.
	 *
	 * Ohne Höhenprofil braucht der Rahmen weniger Platz – sonst stünde unter
	 * der Karte ein leerer Streifen.
	 *
	 * @param string $darstellung Schlüssel.
	 * @return int Höhe in Pixeln.
	 */
	public static function komoot_hoehe( $darstellung ) {
		return 'karte' === $darstellung ? 340 : 420;
	}

	/**
	 * Feldvorlagen je Eventtyp – der Startpunkt beim Anlegen.
	 *
	 * @return array Typ-Slug => Liste von Feldern.
	 */
	public static function vorlagen() {
		return array(
			'social-ride' => array(
				array( 'label' => __( 'Komoot-Tour', 'lindenweg16' ), 'typ' => 'komoot' ),
				array( 'label' => __( 'Distanz (km)', 'lindenweg16' ), 'typ' => 'zahl' ),
				array( 'label' => __( 'Höhenmeter', 'lindenweg16' ), 'typ' => 'zahl' ),
				array( 'label' => __( 'Tempo', 'lindenweg16' ), 'typ' => 'text' ),
				array( 'label' => __( 'Einkehr', 'lindenweg16' ), 'typ' => 'text' ),
			),
			'markt'       => array(
				array( 'label' => __( 'Standnummer', 'lindenweg16' ), 'typ' => 'text' ),
				array( 'label' => __( 'Öffnungszeiten', 'lindenweg16' ), 'typ' => 'text' ),
				array( 'label' => __( 'Anfahrt', 'lindenweg16' ), 'typ' => 'url' ),
			),
			'rennen'      => array(
				array( 'label' => __( 'Startzeit', 'lindenweg16' ), 'typ' => 'zeit' ),
				array( 'label' => __( 'Strecke', 'lindenweg16' ), 'typ' => 'komoot' ),
				array( 'label' => __( 'Distanz (km)', 'lindenweg16' ), 'typ' => 'zahl' ),
				array( 'label' => __( 'Startgebühr', 'lindenweg16' ), 'typ' => 'text' ),
				array( 'label' => __( 'Ausschreibung', 'lindenweg16' ), 'typ' => 'url' ),
			),
		);
	}

	/**
	 * Gespeicherte Felder eines Events.
	 *
	 * @param int $post_id Event.
	 * @return array Liste von {schluessel, label, typ, wert}.
	 */
	public static function get( $post_id ) {
		$roh = get_post_meta( (int) $post_id, EventPostType::META['felder'], true );
		return is_array( $roh ) ? $roh : array();
	}

	/**
	 * Felder eines Events speichern.
	 *
	 * @param int   $post_id Event.
	 * @param array $felder  Rohe Feldliste.
	 */
	public static function save( $post_id, array $felder ) {
		update_post_meta( (int) $post_id, EventPostType::META['felder'], self::sanitize( $felder ) );
	}

	/**
	 * Feldliste prüfen und bereinigen.
	 *
	 * Felder ohne Beschriftung fallen heraus – ein namenloses Feld ist auf
	 * der Seite nicht zu deuten. Unbekannte Feldtypen werden zu Text, nicht
	 * verworfen: Der eingegebene Inhalt soll nicht verloren gehen.
	 *
	 * @param array $felder Rohe Feldliste.
	 * @return array
	 */
	public static function sanitize( array $felder ) {
		$typen = self::typen();
		$out   = array();
		$seen  = array();

		foreach ( $felder as $feld ) {
			if ( ! is_array( $feld ) ) {
				continue;
			}

			$label = isset( $feld['label'] ) ? trim( sanitize_text_field( $feld['label'] ) ) : '';
			if ( '' === $label ) {
				continue;
			}

			$typ = isset( $feld['typ'] ) ? (string) $feld['typ'] : 'text';
			if ( ! isset( $typen[ $typ ] ) ) {
				$typ = 'text';
			}

			$schluessel = self::schluessel( $label, $seen );
			$seen[]     = $schluessel;

			$out[] = array(
				'schluessel' => $schluessel,
				'label'      => $label,
				'typ'        => $typ,
				'wert'       => self::sanitize_wert( $typ, isset( $feld['wert'] ) ? $feld['wert'] : '' ),
				'option'     => self::sanitize_option( $typ, isset( $feld['option'] ) ? $feld['option'] : '' ),
			);
		}

		return $out;
	}

	/**
	 * Eindeutigen Schlüssel aus der Beschriftung ableiten.
	 *
	 * @param string $label Beschriftung.
	 * @param array  $seen  Bereits vergebene Schlüssel.
	 * @return string
	 */
	public static function schluessel( $label, array $seen = array() ) {
		$basis = sanitize_key( remove_accents( $label ) );
		if ( '' === $basis ) {
			$basis = 'feld';
		}

		$kandidat = $basis;
		$n        = 2;
		while ( in_array( $kandidat, $seen, true ) ) {
			$kandidat = $basis . '-' . $n;
			$n++;
		}

		return $kandidat;
	}

	/**
	 * Zusatzeinstellung eines Feldes prüfen.
	 *
	 * Bisher hat nur der Komoot-Typ eine. Die Prüfung läuft gegen eine
	 * Positivliste – was nicht darin steht, wird zum Standard, nicht
	 * durchgereicht.
	 *
	 * @param string $typ  Feldtyp.
	 * @param mixed  $roh  Rohwert.
	 * @return string
	 */
	public static function sanitize_option( $typ, $roh ) {
		if ( 'komoot' !== $typ ) {
			return '';
		}

		$roh = is_scalar( $roh ) ? (string) $roh : '';
		return isset( self::komoot_darstellungen()[ $roh ] ) ? $roh : 'profil';
	}

	/**
	 * Wert je nach Feldtyp bereinigen.
	 *
	 * @param string $typ  Feldtyp.
	 * @param mixed  $wert Rohwert.
	 * @return string
	 */
	public static function sanitize_wert( $typ, $wert ) {
		$wert = is_scalar( $wert ) ? (string) $wert : '';

		switch ( $typ ) {
			case 'textarea':
				return sanitize_textarea_field( $wert );
			case 'zahl':
				$wert = str_replace( ',', '.', trim( $wert ) );
				return is_numeric( $wert ) ? (string) ( 0 + $wert ) : '';
			case 'datum':
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $wert ) ) ? trim( $wert ) : '';
			case 'zeit':
				return preg_match( '/^\d{2}:\d{2}$/', trim( $wert ) ) ? trim( $wert ) : '';
			case 'url':
			case 'komoot':
				return esc_url_raw( trim( $wert ) );
			default:
				return sanitize_text_field( $wert );
		}
	}

	/**
	 * Einbettungsadresse einer Komoot-Tour aus einer beliebigen Tour-URL.
	 *
	 * Die Adresse wird selbst zusammengesetzt, statt vom Nutzer eingefügtes
	 * Markup zu übernehmen: Aus der eingegebenen URL werden nur die Tour-ID
	 * und ein etwaiges Freigabe-Token gelesen, alles andere verworfen. Damit
	 * kann in dem Feld nichts landen, was auf eine fremde Seite zeigt.
	 *
	 * @param string $roh         Eingegebene Komoot-URL.
	 * @param string $darstellung 'profil' (mit Höhenprofil) oder 'karte'.
	 * @return string Einbettungsadresse oder leerer String.
	 */
	public static function komoot_embed_url( $roh, $darstellung = 'profil' ) {
		$roh = trim( (string) $roh );
		if ( '' === $roh ) {
			return '';
		}

		$teile = wp_parse_url( $roh );
		if ( empty( $teile['host'] ) || empty( $teile['path'] ) ) {
			return '';
		}

		// Nur echte Komoot-Adressen. komoot.de, komoot.com und die
		// Sprachpfade darunter; ein „komoot.beispiel.de" fällt durch.
		$host = strtolower( $teile['host'] );
		if ( ! preg_match( '/(^|\.)komoot\.(de|com)$/', $host ) ) {
			return '';
		}

		if ( ! preg_match( '#/tour/(\d+)#', $teile['path'], $treffer ) ) {
			return '';
		}
		$tour_id = $treffer[1];

		$token = '';
		if ( ! empty( $teile['query'] ) ) {
			parse_str( $teile['query'], $query );
			if ( ! empty( $query['share_token'] ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', (string) $query['share_token'] ) ) {
				$token = (string) $query['share_token'];
			}
		}

		$args = array( 'profile' => 'karte' === $darstellung ? '0' : '1' );
		if ( $token ) {
			$args['share_token'] = $token;
		}

		return add_query_arg( $args, 'https://www.komoot.de/tour/' . $tour_id . '/embed' );
	}

	/**
	 * Felder eines Events als HTML-Definitionsliste ausgeben.
	 *
	 * @param int $post_id Event.
	 * @return string
	 */
	public static function render( $post_id ) {
		$felder = self::get( $post_id );
		if ( ! $felder ) {
			return '';
		}

		$zeilen   = '';
		$anhaenge = '';

		foreach ( $felder as $feld ) {
			$wert = (string) $feld['wert'];
			if ( '' === $wert ) {
				continue;
			}

			if ( 'komoot' === $feld['typ'] ) {
				$darstellung = isset( $feld['option'] ) ? (string) $feld['option'] : 'profil';
				$embed       = self::komoot_embed_url( $wert, $darstellung );
				if ( $embed ) {
					$anhaenge .= sprintf(
						'<figure class="lw16-event-karte"><figcaption>%s</figcaption>'
						. '<iframe src="%s" width="100%%" height="%d" frameborder="0" scrolling="no" loading="lazy" title="%s"></iframe></figure>',
						esc_html( $feld['label'] ),
						esc_url( $embed ),
						self::komoot_hoehe( $darstellung ),
						esc_attr( $feld['label'] )
					);
				} else {
					// Nicht lesbare Tour-Adresse: lieber als Link zeigen als
					// stillschweigend verschlucken.
					$zeilen .= self::zeile( $feld['label'], self::link_html( $wert ) );
				}
				continue;
			}

			$zeilen .= self::zeile( $feld['label'], self::wert_html( $feld['typ'], $wert ) );
		}

		$out = '';
		if ( $zeilen ) {
			$out .= '<dl class="lw16-event-felder">' . $zeilen . '</dl>';
		}

		return $out . $anhaenge;
	}

	/**
	 * Eine Zeile der Definitionsliste.
	 *
	 * @param string $label Beschriftung.
	 * @param string $html  Fertiges Wert-HTML.
	 * @return string
	 */
	protected static function zeile( $label, $html ) {
		return '<dt>' . esc_html( $label ) . '</dt><dd>' . $html . '</dd>';
	}

	/**
	 * Wert je Feldtyp als HTML.
	 *
	 * @param string $typ  Feldtyp.
	 * @param string $wert Wert.
	 * @return string
	 */
	protected static function wert_html( $typ, $wert ) {
		switch ( $typ ) {
			case 'url':
				return self::link_html( $wert );
			case 'textarea':
				return nl2br( esc_html( $wert ) );
			case 'datum':
				$zeit = strtotime( $wert );
				return esc_html( $zeit ? date_i18n( get_option( 'date_format' ), $zeit ) : $wert );
			default:
				return esc_html( $wert );
		}
	}

	/**
	 * Link mit gekürzter Beschriftung.
	 *
	 * @param string $url Adresse.
	 * @return string
	 */
	protected static function link_html( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return sprintf(
			'<a href="%s" rel="noopener nofollow" target="_blank">%s</a>',
			esc_url( $url ),
			esc_html( $host ? $host : $url )
		);
	}
}
