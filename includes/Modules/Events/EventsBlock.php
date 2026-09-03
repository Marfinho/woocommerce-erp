<?php
/**
 * Block für den Seiten-Editor.
 *
 * Ein dynamischer Block: Gespeichert werden nur die Einstellungen, die
 * Ausgabe erzeugt beim Aufruf dieselbe Methode wie Shortcode und Widget.
 * Ein Block, der sein HTML in den Beitrag schreibt, hätte veraltete
 * Teilnehmerzahlen im Inhalt stehen – und die ändern sich täglich.
 *
 * Das Editor-Skript ist bewusst ohne Übersetzungsschritt geschrieben: Das
 * Plugin kommt ohne Build-Werkzeug aus, und ein Block wäre kein guter
 * Grund, damit anzufangen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Events;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;

/**
 * Meldet den Block und sein Editor-Skript an.
 */
class EventsBlock {

	/**
	 * Blockname.
	 */
	const NAME = 'lindenweg16/events';

	/**
	 * Skript-Handle des Editors.
	 */
	const HANDLE = 'lw16-events-block';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_block' ), 20 );
	}

	/**
	 * Skript und Block anmelden.
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Ohne Build-Schritt gibt es keine erzeugte Abhängigkeitsliste –
		// deshalb steht sie hier von Hand. Das Skript wird als fertiges
		// Handle in block.json eingetragen.
		wp_register_script(
			self::HANDLE,
			LW16_PLUGIN_URL . 'assets/js/events-block.js',
			array(
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-i18n',
				'wp-server-side-render',
			),
			Assets::version( 'assets/js/events-block.js' ),
			true
		);

		// Die Eventtypen kommen aus der Taxonomie, nicht aus einer festen
		// Liste: Wer einen Typ anlegt, findet ihn im Editor wieder.
		wp_localize_script(
			self::HANDLE,
			'LW16EventsBlock',
			array(
				'typen'     => self::typ_optionen(),
				'intervall' => array(
					'min'      => EventsWidget::INTERVALL_MIN,
					'max'      => EventsWidget::INTERVALL_MAX,
					'standard' => EventsWidget::INTERVALL_STANDARD,
				),
			)
		);

		register_block_type(
			LW16_PLUGIN_DIR . 'assets/blocks/events',
			array( 'render_callback' => array( __CLASS__, 'render' ) )
		);
	}

	/**
	 * Auswahlliste der Eventtypen für den Editor.
	 *
	 * @return array Liste aus {label, value}.
	 */
	protected static function typ_optionen() {
		$optionen = array(
			array(
				'label' => __( 'Alle Typen', 'lindenweg16' ),
				'value' => '',
			),
		);

		$terms = get_terms( array(
			'taxonomy'   => EventPostType::TAXONOMY,
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) ) {
			return $optionen;
		}

		foreach ( $terms as $term ) {
			$optionen[] = array(
				'label' => $term->name,
				'value' => $term->slug,
			);
		}

		return $optionen;
	}

	/**
	 * Block ausgeben.
	 *
	 * @param array $attributes Einstellungen aus dem Editor.
	 * @return string
	 */
	public static function render( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		$html = EventsWidget::render( array(
			'anzahl'    => isset( $attributes['anzahl'] ) ? (int) $attributes['anzahl'] : 6,
			'typ'       => isset( $attributes['typ'] ) ? sanitize_key( $attributes['typ'] ) : '',
			'titel'     => isset( $attributes['titel'] ) ? sanitize_text_field( $attributes['titel'] ) : '',
			'alle_link' => ! empty( $attributes['alleLink'] ),
			'autoplay'  => ! empty( $attributes['autoplay'] ),
			'intervall' => isset( $attributes['intervall'] ) ? (int) $attributes['intervall'] : EventsWidget::INTERVALL_STANDARD,
		) );

		// Im Editor ist eine leere Ausgabe verwirrend: Der Block sieht dann
		// kaputt aus, dabei steht schlicht kein Termin an.
		if ( '' === $html && self::im_editor() ) {
			return '<div class="lw16-events-widget__leer">'
				. esc_html__( 'Zurzeit steht kein Termin an. Auf der Seite selbst erscheint dann nichts – dieser Hinweis nur hier im Editor.', 'lindenweg16' )
				. '</div>';
		}

		if ( '' === $html ) {
			return '';
		}

		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * Läuft die Ausgabe gerade für den Editor?
	 *
	 * @return bool
	 */
	protected static function im_editor() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}
}
