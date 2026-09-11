<?php
/**
 * Untermenü „Anleitungen": die Betriebsanleitung aus docs/ im Backend lesen.
 *
 * Die Anleitung ist ein vollständiges HTML-Dokument mit eigenem Stylesheet,
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
	 * Die eine Datei, in der alle Teile stehen.
	 */
	const FILE = 'betriebsanleitung.html';

	/**
	 * Die Teile der Anleitung: Kennung => [Beschriftung, Sprungmarke, Kurzbeschreibung].
	 *
	 * Seit der Zusammenführung gibt es nur noch eine Datei; die Reiter
	 * springen per Sprungmarke an den jeweiligen Teil. Die Kennung steht
	 * in der Adresse, die Sprungmarke nicht – so lässt sich kein
	 * beliebiger Pfad in den Rahmen laden.
	 *
	 * @return array
	 */
	public static function manuals() {
		return array(
			'start'      => array(
				__( 'Einstieg', 'lindenweg16' ),
				'einstieg',
				__( 'Wer liest was, die fünf Menüs, fünf Grundregeln.', 'lindenweg16' ),
			),
			'produktion' => array(
				__( 'A · Produktion', 'lindenweg16' ),
				'teil-produktion',
				__( 'Werkstatt: drucken, montieren, buchen, Platten anordnen.', 'lindenweg16' ),
			),
			'versand'    => array(
				__( 'B · Versand', 'lindenweg16' ),
				'teil-versand',
				__( 'Packtisch: picken, packen, versenden.', 'lindenweg16' ),
			),
			'personal'   => array(
				__( 'C · Personal', 'lindenweg16' ),
				'teil-personal',
				__( 'Zeiterfassung, Stundenlöhne, Rechteprofile.', 'lindenweg16' ),
			),
			'admin'      => array(
				__( 'D · Administration', 'lindenweg16' ),
				'teil-admin',
				__( 'Einrichtung, Kalkulation, Buchhaltung, Drucker im Netz, Livegang.', 'lindenweg16' ),
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
		$file    = self::FILE;
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

		$url = LW16_PLUGIN_URL . self::DIR . $file . '#' . $manuals[ $current ][1];

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
