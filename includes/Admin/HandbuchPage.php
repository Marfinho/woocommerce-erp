<?php
/**
 * Untermenü „Anleitungen": die Betriebsanleitungen aus docs/ im Backend lesen.
 *
 * Die Anleitungen sind vollständige HTML-Dokumente mit eigenem Stylesheet,
 * eigener Typografie, Hell-/Dunkelvariante und Druckausgabe. Sie werden
 * deshalb in einem Rahmen eingebettet statt in die Adminseite hineinkopiert:
 * Ihr Stylesheet definiert `body`, `h2`, `table` und Ähnliches: in wp-admin
 * eingespielt, würde es das Backend mitgestalten. Umgekehrt jede Regel auf
 * einen Container umzuschreiben hiesse, dasselbe Dokument zweimal zu pflegen.
 *
 * Der Rahmen zeigt genau die Datei, die auch im Browser geöffnet wird: Es
 * gibt keine zweite, aus der ersten abgeleitete Fassung, die veralten könnte.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Rendert die Betriebsanleitungen im Adminbereich.
 */
class HandbuchPage {

	/**
	 * Menü-Slug der Seite.
	 */
	const SLUG = 'lw16-anleitungen';

	/**
	 * Verzeichnis der Anleitungen, relativ zum Plugin.
	 */
	const DIR = 'docs/';

	/**
	 * Die Anleitungen: Kennung => [Beschriftung, Datei, Kurzbeschreibung].
	 *
	 * Die Kennung steht in der Adresse, der Dateiname nicht – so lässt sich
	 * kein beliebiger Pfad in den Rahmen laden.
	 *
	 * @return array
	 */
	public static function manuals() {
		return array(
			'start'      => array(
				__( 'Übersicht', 'lindenweg16' ),
				'index.html',
				__( 'Wer liest was, die zwei Menüs, fünf Grundregeln.', 'lindenweg16' ),
			),
			'produktion' => array(
				__( 'Produktion', 'lindenweg16' ),
				'ba-produktion.html',
				__( 'Werkstatt: drucken, montieren, buchen.', 'lindenweg16' ),
			),
			'versand'    => array(
				__( 'Versand', 'lindenweg16' ),
				'ba-versand.html',
				__( 'Packtisch: picken, packen, versenden.', 'lindenweg16' ),
			),
			'personal'   => array(
				__( 'Personal', 'lindenweg16' ),
				'ba-personal.html',
				__( 'Zeiterfassung, Stundenlöhne, Rechteprofile.', 'lindenweg16' ),
			),
			'admin'      => array(
				__( 'Administration', 'lindenweg16' ),
				'ba-admin.html',
				__( 'Einrichtung, Kalkulation, Buchhaltung, Livegang.', 'lindenweg16' ),
			),
		);
	}

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_page_lw16_anleitungen', array( $this, 'render' ) );
	}

	/**
	 * Gewählte Anleitung aus der Adresse, auf die bekannten Kennungen begrenzt.
	 *
	 * @return string
	 */
	protected function current() {
		// phpcs:ignore WordPress.Security.NonceVerification -- reine Ansicht.
		$wanted = isset( $_GET['ba'] ) ? sanitize_key( wp_unslash( $_GET['ba'] ) ) : 'start';

		return array_key_exists( $wanted, self::manuals() ) ? $wanted : 'start';
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$manuals = self::manuals();
		$current = $this->current();
		$file    = $manuals[ $current ][1];
		$base    = admin_url( 'admin.php?page=' . self::SLUG );

		echo '<div class="wrap lw16-wrap lw16-handbuch"><h1>' . esc_html__( 'Anleitungen', 'lindenweg16' ) . '</h1>';

		echo '<nav class="lw16-tabs">';
		foreach ( $manuals as $key => $manual ) {
			printf(
				'<a href="%s" class="%s">%s</a> ',
				esc_url( add_query_arg( 'ba', $key, $base ) ),
				$key === $current ? 'active' : '',
				esc_html( $manual[0] )
			);
		}
		echo '</nav>';

		if ( ! file_exists( LW16_PLUGIN_DIR . self::DIR . $file ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div></div>',
				esc_html(
					sprintf(
						/* translators: %s: Dateiname der Anleitung. */
						__( 'Die Datei %s fehlt. Die Anleitungen liegen im Ordner docs/ des Plugins – wurde er beim Hochladen ausgelassen, lädt man ihn einfach nach.', 'lindenweg16' ),
						self::DIR . $file
					)
				)
			);
			return;
		}

		$url = LW16_PLUGIN_URL . self::DIR . $file;

		printf(
			'<p class="lw16-handbuch-kopf">%s <a href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_html( $manuals[ $current ][2] ),
			esc_url( $url ),
			esc_html__( 'In einem eigenen Tab öffnen (zum Drucken)', 'lindenweg16' )
		);

		printf(
			'<iframe class="lw16-handbuch-rahmen" src="%s" title="%s" loading="lazy"></iframe>',
			esc_url( $url ),
			esc_attr( $manuals[ $current ][0] )
		);

		echo '</div>';
	}
}
