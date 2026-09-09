<?php
/**
 * Kategorievorschlag aus den benutzten Seiten.
 *
 * Die Frage war, ob das System selbst erkennen kann, in welchem Abschnitt
 * jemand unterwegs war. Vollautomatisch wäre das Raten: Wer eine Stunde im
 * Druckjournal steht, kann Teile gebucht oder nur nachgesehen haben, und
 * eine falsch einsortierte Stunde ist schlimmer als eine ungefragte.
 *
 * Was das System dagegen wirklich weiss, ist, wo jemand während der
 * laufenden Uhr war und wie lange. Daraus wird ein **Vorschlag**, der beim
 * Stoppen im Formular steht und mit einem Klick bestätigt oder geändert
 * wird. Der Mensch entscheidet, das System spart ihm das Tippen.
 *
 * Der Vorschlag kommt nur, wenn er etwas taugt: Wer die Zeit über fünf
 * Bereiche verteilt hat, bekommt keine erfundene Mehrheit vorgesetzt,
 * sondern die ehrliche Auskunft, dass es gemischt war.
 *
 * Frei von Datenbank und WordPress-Umgebung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Ordnet Seiten Tätigkeiten zu und schlägt eine Kategorie vor.
 */
class TimeCategoryGuess {

	/**
	 * Ab diesem Anteil an der beobachteten Zeit gilt eine Kategorie als
	 * die Tätigkeit des Zeitraums.
	 *
	 * Die Hälfte reicht nicht: Bei zwei Bereichen wäre 51 % schon eine
	 * „Mehrheit", die nichts aussagt. Zwei Drittel heisst, der Rest ist
	 * Beiwerk.
	 */
	const CONFIDENT_SHARE = 0.66;

	/**
	 * Unter dieser beobachteten Zeit wird nichts vorgeschlagen.
	 *
	 * Wer die Uhr startet und den Rechner zuklappt, hat eine einzige
	 * Seitenansicht hinterlassen. Daraus eine Tätigkeit abzuleiten wäre
	 * Zufall.
	 */
	const MIN_OBSERVED_SECONDS = 300;

	/**
	 * Seiten und die Tätigkeit, für die sie stehen.
	 *
	 * Verglichen wird von oben nach unten mit dem Seitenanfang, damit
	 * `lw16-betrieb-lagerplaetze` nicht an `lw16-betrieb-lager` hängen
	 * bleibt. Die Reihenfolge ist deshalb Teil der Aussage.
	 *
	 * @return array Seitenanfang => Kategorie.
	 */
	public static function page_categories() {
		return array(
			'lw16-betrieb-druckjournal'  => 'produktion',
			'lw16-betrieb-lagerplaetze'  => 'produktion',
			'lw16-betrieb-qualitaet'     => 'produktion',
			'lw16-betrieb-bedarf'        => 'produktion',
			'lw16-betrieb-kapazitaet'    => 'produktion',
			'lw16-betrieb-lager'         => 'versand',
			'lw16-betrieb-bestellungen'  => 'versand',
			'lw16-betrieb-artikel'       => 'konstruktion',
			'lw16-betrieb-konfigurator'  => 'konstruktion',
			'lw16-betrieb-material'      => 'konstruktion',
			'lw16-betrieb-drucker'       => 'wartung',
			'lw16-betrieb-kontakte'      => 'vertrieb',
			'lw16-marketing'             => 'marketing',
			'lw16-loyalty'               => 'marketing',
			'lw16-buchhaltung'           => 'verwaltung',
			'lw16-betrieb-berichte'      => 'verwaltung',
			'lw16-betrieb-benutzer'      => 'verwaltung',
			'lw16-betrieb-einstellungen' => 'verwaltung',
			'lw16-betrieb-import'        => 'verwaltung',
			'lw16-reporting'             => 'verwaltung',
			'lw16-kasse'                 => 'vertrieb',
			'lw16-support'               => 'vertrieb',
		);
	}

	/**
	 * Die Tätigkeit hinter einer Seite.
	 *
	 * @param string $page Seitenkennung.
	 * @return string Kategorie, 'sonstiges' wenn unbekannt.
	 */
	public static function category_for_page( $page ) {
		$page = (string) $page;

		if ( '' === $page ) {
			return 'sonstiges';
		}

		foreach ( self::page_categories() as $prefix => $category ) {
			if ( 0 === strpos( $page, $prefix ) ) {
				return $category;
			}
		}

		return 'sonstiges';
	}

	/**
	 * Beobachtete Zeit einer Seite zuschlagen.
	 *
	 * Ohne Obergrenze: Wer eine Seite offen lässt und in drei Stunden
	 * wiederkommt, hätte drei Stunden „Produktion" gesammelt. Deshalb
	 * zählt je Sprung höchstens, was ein Mensch am Stück auf einer Seite
	 * verbringt, ohne etwas anzuklicken.
	 *
	 * @param array  $seen    Bisher beobachtet, Kategorie => Sekunden.
	 * @param string $page    Seite.
	 * @param int    $seconds Sekunden seit der letzten Beobachtung.
	 * @param int    $cap     Obergrenze je Sprung.
	 * @return array
	 */
	public static function accumulate( array $seen, $page, $seconds, $cap = 900 ) {
		$seconds = min( max( 0, (int) $seconds ), max( 0, (int) $cap ) );

		if ( $seconds <= 0 ) {
			return $seen;
		}

		$category = self::category_for_page( $page );

		$seen[ $category ] = ( isset( $seen[ $category ] ) ? (int) $seen[ $category ] : 0 ) + $seconds;

		return $seen;
	}

	/**
	 * Der Vorschlag.
	 *
	 * @param array $seen Kategorie => Sekunden.
	 * @return array {category, share, seconds, confident}
	 */
	public static function suggest( array $seen ) {
		$gesamt = 0;

		foreach ( $seen as $sekunden ) {
			$gesamt += max( 0, (int) $sekunden );
		}

		$leer = array(
			'category'  => '',
			'share'     => 0.0,
			'seconds'   => $gesamt,
			'confident' => false,
		);

		if ( $gesamt < self::MIN_OBSERVED_SECONDS ) {
			return $leer;
		}

		$beste = '';
		$max   = 0;

		foreach ( $seen as $category => $sekunden ) {
			$sekunden = max( 0, (int) $sekunden );

			// Bei Gleichstand gewinnt keiner: Ein Los zu werfen und das
			// Ergebnis als Erkenntnis auszugeben wäre das Schlechteste.
			if ( $sekunden > $max ) {
				$max   = $sekunden;
				$beste = (string) $category;
			} elseif ( $sekunden === $max && (string) $category !== $beste ) {
				$beste = '';
			}
		}

		if ( '' === $beste ) {
			return $leer;
		}

		$anteil = $max / $gesamt;

		return array(
			'category'  => $beste,
			'share'     => $anteil,
			'seconds'   => $gesamt,
			'confident' => $anteil >= self::CONFIDENT_SHARE,
		);
	}

	/**
	 * Was beobachtet wurde, als Satz.
	 *
	 * Der Vorschlag soll nachvollziehbar sein. „Produktion" allein ist eine
	 * Behauptung; „38 Min. Produktion, 12 Min. Verwaltung" ist die
	 * Begründung dazu.
	 *
	 * @param array $seen Kategorie => Sekunden.
	 * @param int   $max  Höchstens so viele Bereiche nennen.
	 * @return string
	 */
	public static function explain( array $seen, $max = 3 ) {
		$sortiert = array();

		foreach ( $seen as $category => $sekunden ) {
			$sekunden = max( 0, (int) $sekunden );

			if ( $sekunden > 0 ) {
				$sortiert[ (string) $category ] = $sekunden;
			}
		}

		if ( ! $sortiert ) {
			return '';
		}

		arsort( $sortiert );

		$labels = TimeRepository::category_labels();
		$teile  = array();

		foreach ( array_slice( $sortiert, 0, max( 1, (int) $max ), true ) as $category => $sekunden ) {
			$teile[] = sprintf(
				/* translators: 1: Minuten, 2: Tätigkeit. */
				__( '%1$d Min. %2$s', 'lindenweg16' ),
				(int) round( $sekunden / 60 ),
				isset( $labels[ $category ] ) ? $labels[ $category ] : $category
			);
		}

		return implode( ', ', $teile );
	}

	/**
	 * Jede vorgeschlagene Kategorie muss es auch in der Erfassung geben.
	 *
	 * @return bool
	 */
	public static function categories_are_known() {
		foreach ( self::page_categories() as $category ) {
			if ( ! in_array( $category, TimeRepository::CATEGORIES, true ) ) {
				return false;
			}
		}

		return true;
	}
}
