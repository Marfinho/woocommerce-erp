<?php
/**
 * Klassisches Widget für Widget-Bereiche.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Dünne Hülle um EventsWidget::render() – die Ausgabe selbst steht dort.
 */
class EventsSidebarWidget extends \WP_Widget {

	/**
	 * Widget anmelden.
	 */
	public function __construct() {
		parent::__construct(
			'lw16_events',
			__( 'Lindenweg16: Nächste Events', 'lindenweg16' ),
			array(
				'description' => __( 'Die nächsten Termine als seitlich scrollbare Reihe, ein Event je Seite.', 'lindenweg16' ),
				'classname'   => 'lw16-events-widget-wrap',
			)
		);
	}

	/**
	 * Ausgabe im Frontend.
	 *
	 * @param array $args     Bereichsargumente.
	 * @param array $instance Einstellungen.
	 */
	public function widget( $args, $instance ) {
		$html = EventsWidget::render( array(
			'anzahl'    => isset( $instance['anzahl'] ) ? (int) $instance['anzahl'] : 6,
			'typ'       => isset( $instance['typ'] ) ? sanitize_key( $instance['typ'] ) : '',
			'titel'     => isset( $instance['titel'] ) ? $instance['titel'] : '',
			'alle_link' => ! empty( $instance['alle_link'] ),
			'autoplay'  => ! empty( $instance['autoplay'] ),
			'intervall' => isset( $instance['intervall'] ) ? (int) $instance['intervall'] : EventsWidget::INTERVALL_STANDARD,
		) );

		// Steht nichts an, erscheint auch der Rahmen des Bereichs nicht.
		if ( '' === $html ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Einstellungsformular.
	 *
	 * @param array $instance Einstellungen.
	 */
	public function form( $instance ) {
		$titel     = isset( $instance['titel'] ) ? $instance['titel'] : __( 'Nächste Termine', 'lindenweg16' );
		$anzahl    = isset( $instance['anzahl'] ) ? (int) $instance['anzahl'] : 6;
		$typ       = isset( $instance['typ'] ) ? $instance['typ'] : '';
		$alle_link = ! empty( $instance['alle_link'] );
		$autoplay  = ! empty( $instance['autoplay'] );
		$intervall = isset( $instance['intervall'] ) ? (int) $instance['intervall'] : EventsWidget::INTERVALL_STANDARD;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'titel' ) ); ?>"><?php esc_html_e( 'Überschrift', 'lindenweg16' ); ?></label>
			<input class="widefat" type="text" id="<?php echo esc_attr( $this->get_field_id( 'titel' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'titel' ) ); ?>" value="<?php echo esc_attr( $titel ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'anzahl' ) ); ?>"><?php esc_html_e( 'Wie viele Events höchstens', 'lindenweg16' ); ?></label>
			<input class="tiny-text" type="number" min="1" max="20" step="1"
				id="<?php echo esc_attr( $this->get_field_id( 'anzahl' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'anzahl' ) ); ?>" value="<?php echo esc_attr( (string) $anzahl ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'typ' ) ); ?>"><?php esc_html_e( 'Nur ein Eventtyp', 'lindenweg16' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'typ' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'typ' ) ); ?>">
				<option value=""><?php esc_html_e( '– alle Typen –', 'lindenweg16' ); ?></option>
				<?php
				$terms = get_terms( array( 'taxonomy' => EventPostType::TAXONOMY, 'hide_empty' => false ) );
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						printf(
							'<option value="%s" %s>%s</option>',
							esc_attr( $term->slug ),
							selected( $typ, $term->slug, false ),
							esc_html( $term->name )
						);
					}
				}
				?>
			</select>
		</p>
		<p>
			<label>
				<input type="checkbox" <?php checked( $alle_link ); ?>
					name="<?php echo esc_attr( $this->get_field_name( 'alle_link' ) ); ?>" value="1">
				<?php esc_html_e( 'Link auf alle Termine zeigen', 'lindenweg16' ); ?>
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" <?php checked( $autoplay ); ?>
					name="<?php echo esc_attr( $this->get_field_name( 'autoplay' ) ); ?>" value="1">
				<?php esc_html_e( 'Von allein weiterblättern', 'lindenweg16' ); ?>
			</label>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'intervall' ) ); ?>"><?php esc_html_e( 'Wartezeit je Event (Sekunden)', 'lindenweg16' ); ?></label>
			<input class="tiny-text" type="number"
				min="<?php echo esc_attr( (string) EventsWidget::INTERVALL_MIN ); ?>"
				max="<?php echo esc_attr( (string) EventsWidget::INTERVALL_MAX ); ?>" step="1"
				id="<?php echo esc_attr( $this->get_field_id( 'intervall' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'intervall' ) ); ?>"
				value="<?php echo esc_attr( (string) $intervall ); ?>">
			<span class="description"><?php esc_html_e( 'Besucher können jederzeit pausieren.', 'lindenweg16' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Einstellungen speichern.
	 *
	 * @param array $new Neue Werte.
	 * @param array $old Bisherige Werte.
	 * @return array
	 */
	public function update( $new, $old ) {
		return array(
			'titel'     => sanitize_text_field( $new['titel'] ?? '' ),
			'anzahl'    => min( 20, max( 1, (int) ( $new['anzahl'] ?? 6 ) ) ),
			'typ'       => sanitize_key( $new['typ'] ?? '' ),
			'alle_link' => empty( $new['alle_link'] ) ? 0 : 1,
			'autoplay'  => empty( $new['autoplay'] ) ? 0 : 1,
			'intervall' => (int) ( $new['intervall'] ?? EventsWidget::INTERVALL_STANDARD ),
		);
	}
}
