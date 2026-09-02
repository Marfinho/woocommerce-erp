<?php
/**
 * Ausgabe der nächsten Events als seitlich scrollbare Reihe.
 *
 * Dieselbe Ausgabe auf drei Wegen: als Shortcode [lw16_events], als
 * klassisches Widget für Widget-Bereiche und als Funktion für die
 * Theme-Vorlagen. Der Aufbau steckt an einer Stelle – drei Kopien davon
 * gingen beim ersten Umbau auseinander.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Baut die Eventreihe und meldet Shortcode und Widget an.
 */
class EventsWidget {

	/**
	 * Shortcode-Name.
	 */
	const SHORTCODE = 'lw16_events';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'widgets_init', array( __CLASS__, 'widget_anmelden' ) );
	}

	/**
	 * Klassisches Widget anmelden.
	 *
	 * Bewusst nicht `register_widget` genannt: Die Methode ruft die
	 * gleichnamige WordPress-Funktion auf, und zwei Dinge mit demselben
	 * Namen an derselben Stelle liest niemand gern zweimal.
	 */
	public static function widget_anmelden() {
		register_widget( EventsSidebarWidget::class );
	}

	/**
	 * Shortcode-Rückruf.
	 *
	 * @param array $atts Attribute.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'anzahl'    => 6,
				'typ'       => '',
				'titel'     => '',
				'alle_link' => '1',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		return self::render( array(
			'anzahl'    => (int) $atts['anzahl'],
			'typ'       => sanitize_key( $atts['typ'] ),
			'titel'     => sanitize_text_field( $atts['titel'] ),
			'alle_link' => '0' !== (string) $atts['alle_link'],
		) );
	}

	/**
	 * Die nächsten Events holen.
	 *
	 * Sortiert nach Beginn. Ein Event ohne Beginn taucht nicht auf – ohne
	 * Datum lässt sich weder sortieren noch sagen, ob es noch bevorsteht.
	 *
	 * @param int    $anzahl Höchstzahl.
	 * @param string $typ    Slug der Typ-Taxonomie, leer = alle.
	 * @return \WP_Post[]
	 */
	public static function kommende( $anzahl = 6, $typ = '' ) {
		// Ab Mitternacht: Ein Event, das heute stattfindet, verschwindet nicht
		// mittags von der Startseite.
		$ab = current_time( 'Y-m-d' ) . ' 00:00';

		$args = array(
			'post_type'      => EventPostType::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, (int) $anzahl ),
			'meta_key'       => EventPostType::META['beginn'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => EventPostType::META['beginn'],
					'value'   => $ab,
					'compare' => '>=',
					'type'    => 'CHAR',
				),
			),
		);

		if ( $typ ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => EventPostType::TAXONOMY,
					'field'    => 'slug',
					'terms'    => $typ,
				),
			);
		}

		return get_posts( $args );
	}

	/**
	 * Die Reihe ausgeben.
	 *
	 * @param array $args anzahl, typ, titel, alle_link.
	 * @return string HTML, leer wenn nichts ansteht.
	 */
	public static function render( array $args = array() ) {
		$args = wp_parse_args( $args, array(
			'anzahl'    => 6,
			'typ'       => '',
			'titel'     => __( 'Nächste Termine', 'lindenweg16' ),
			'alle_link' => true,
		) );

		$events = self::kommende( $args['anzahl'], $args['typ'] );

		// Kein anstehendes Event: lieber nichts als eine leere Überschrift.
		if ( ! $events ) {
			return '';
		}

		$karten = '';
		foreach ( $events as $event ) {
			$karten .= self::karte( $event );
		}

		$mehrere = count( $events ) > 1;

		$out  = '<section class="lw16-events-widget">';

		if ( $args['titel'] || $args['alle_link'] ) {
			$out .= '<div class="lw16-events-widget__kopf">';
			if ( $args['titel'] ) {
				$out .= '<h2 class="lw16-events-widget__titel">' . esc_html( $args['titel'] ) . '</h2>';
			}
			if ( $args['alle_link'] ) {
				$archiv = get_post_type_archive_link( EventPostType::POST_TYPE );
				if ( $archiv ) {
					$out .= '<a class="lw16-events-widget__alle" href="' . esc_url( $archiv ) . '">'
						. esc_html__( 'Alle Termine', 'lindenweg16' ) . ' &rarr;</a>';
				}
			}
			$out .= '</div>';
		}

		$out .= '<div class="lw16-events-widget__rahmen">';

		if ( $mehrere ) {
			$out .= '<button type="button" class="lw16-events-widget__pfeil lw16-events-widget__pfeil--zurueck" '
				. 'aria-label="' . esc_attr__( 'Vorheriges Event', 'lindenweg16' ) . '" hidden>&#8249;</button>';
		}

		// Ohne JavaScript bleibt die Reihe seitlich scrollbar – die Pfeile
		// sind Bequemlichkeit, nicht Voraussetzung.
		$out .= '<div class="lw16-events-widget__spur" tabindex="0" role="group" '
			. 'aria-label="' . esc_attr__( 'Kommende Events', 'lindenweg16' ) . '">';
		$out .= $karten;
		$out .= '</div>';

		if ( $mehrere ) {
			$out .= '<button type="button" class="lw16-events-widget__pfeil lw16-events-widget__pfeil--vor" '
				. 'aria-label="' . esc_attr__( 'Nächstes Event', 'lindenweg16' ) . '" hidden>&#8250;</button>';
		}

		$out .= '</div></section>';

		return $out;
	}

	/**
	 * Eine Eventkarte.
	 *
	 * @param \WP_Post $event Event.
	 * @return string
	 */
	protected static function karte( $event ) {
		$daten = EventPostType::daten( $event->ID );
		$link  = get_permalink( $event );

		$out = '<article class="lw16-events-widget__karte">';
		$out .= '<a class="lw16-events-widget__link" href="' . esc_url( $link ) . '">';

		// Datum als eigener Block: Es ist beim Überfliegen die wichtigste
		// Angabe, wichtiger als der Titel.
		$out .= '<div class="lw16-events-widget__datum">' . self::datum_html( $daten ) . '</div>';

		$out .= '<div class="lw16-events-widget__inhalt">';

		if ( has_post_thumbnail( $event ) ) {
			$out .= '<div class="lw16-events-widget__bild">'
				. get_the_post_thumbnail( $event, 'thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) )
				. '</div>';
		}

		$out .= '<div class="lw16-events-widget__text">';

		$typ = self::typ_label( $event->ID );
		if ( $typ ) {
			$out .= '<div class="lw16-events-widget__typ">' . esc_html( $typ ) . '</div>';
		}

		$out .= '<h3 class="lw16-events-widget__name">' . esc_html( get_the_title( $event ) ) . '</h3>';

		if ( $daten['ort'] ) {
			$out .= '<div class="lw16-events-widget__ort">' . esc_html( $daten['ort'] ) . '</div>';
		}

		$auszug = self::auszug( $event );
		if ( $auszug ) {
			$out .= '<p class="lw16-events-widget__auszug">' . esc_html( $auszug ) . '</p>';
		}

		$out .= '</div></div>';
		$out .= self::plaetze_html( $event->ID, $daten );
		$out .= '</a></article>';

		return $out;
	}

	/**
	 * Datum als Tag, Monat und Uhrzeit.
	 *
	 * @param array $daten Eckdaten.
	 * @return string
	 */
	protected static function datum_html( array $daten ) {
		$zeit = $daten['beginn'] ? strtotime( $daten['beginn'] ) : 0;
		if ( ! $zeit ) {
			return '';
		}

		return '<span class="lw16-events-widget__tag">' . esc_html( date_i18n( 'j', $zeit ) ) . '</span>'
			. '<span class="lw16-events-widget__monat">' . esc_html( date_i18n( 'M', $zeit ) ) . '</span>'
			. '<span class="lw16-events-widget__uhrzeit">' . esc_html( date_i18n( get_option( 'time_format' ), $zeit ) ) . '</span>';
	}

	/**
	 * Balken der freien Plätze.
	 *
	 * @param int   $event_id Event.
	 * @param array $daten    Eckdaten.
	 * @return string
	 */
	protected static function plaetze_html( $event_id, array $daten ) {
		if ( ! $daten['rsvp_aktiv'] ) {
			return '';
		}

		$belegt = RsvpRepository::plaetze( $event_id, RsvpRechnung::ZUGESAGT );
		$text   = RsvpRechnung::freie_plaetze_text( $daten['max_teilnehmer'], $belegt );
		$anteil = RsvpRechnung::auslastung_prozent( $daten['max_teilnehmer'], $belegt );

		$out = '<div class="lw16-events-widget__plaetze">';

		// Ohne Begrenzung gibt es keinen sinnvollen Balken – dann steht dort
		// nur, dass die Anmeldung offen ist.
		if ( null !== $anteil ) {
			$voll = RsvpRechnung::ist_ausgebucht( $daten['max_teilnehmer'], $belegt );

			$out .= '<div class="lw16-events-widget__balken' . ( $voll ? ' is-voll' : '' ) . '"'
				. ' role="progressbar" aria-valuemin="0" aria-valuemax="100"'
				. ' aria-valuenow="' . esc_attr( (string) (int) $anteil ) . '"'
				. ' aria-label="' . esc_attr__( 'Belegung', 'lindenweg16' ) . '">'
				. '<span style="width:' . esc_attr( (string) $anteil ) . '%"></span></div>';
		}

		$out .= '<div class="lw16-events-widget__frei">' . esc_html( $text ) . '</div>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * Kurzer Auszug.
	 *
	 * @param \WP_Post $event Event.
	 * @return string
	 */
	protected static function auszug( $event ) {
		$roh = $event->post_excerpt ? $event->post_excerpt : $event->post_content;
		$roh = wp_strip_all_tags( strip_shortcodes( (string) $roh ) );

		return wp_trim_words( $roh, 22, '…' );
	}

	/**
	 * Beschriftung des Eventtyps.
	 *
	 * @param int $event_id Event.
	 * @return string
	 */
	protected static function typ_label( $event_id ) {
		$terms = get_the_terms( $event_id, EventPostType::TAXONOMY );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		$erster = reset( $terms );
		return (string) $erster->name;
	}
}
