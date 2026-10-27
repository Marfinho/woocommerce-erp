<?php
/**
 * Wie der Konfigurator sein Skript ausliefert.
 *
 * Hier steht ein Fehler fest, der eine Runde gekostet hat: Das Skript
 * wurde über den Filter `script_loader_tag` zum Modul umgeschrieben. Der
 * Filter bekommt den Tag aber **samt** der Zeile, die WordPress davor
 * setzt – und die trägt den Katalog. Wer den Tag durch eigenes Markup
 * ersetzt, wirft die Daten weg: Im Browser gab es keinen Katalog, und die
 * Ansicht blieb ohne Fehlermeldung beim Ladehinweis stehen.
 *
 * Beide Prüfungen unten hätten das gefunden. Sie lesen den Quelltext, weil
 * die betroffenen Klassen ohne WordPress nicht laufen – grob, aber
 * genau an der Stelle, an der es schiefging.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Prüft die Auslieferung von Skript und Daten.
 */
class KonfiguratorSkriptTest extends TestCase {

	/**
	 * Quelltext des Frontends.
	 *
	 * @return string
	 */
	protected function frontend() {
		return (string) file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Configurator/Frontend.php' );
	}

	/**
	 * Quelltext des Skripts.
	 *
	 * @return string
	 */
	protected function skript() {
		return (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/configurator.js' );
	}

	/**
	 * Der Tag des Skripts wird nicht umgeschrieben.
	 */
	public function test_kein_eingriff_in_den_skript_tag() {
		$this->assertStringNotContainsString(
			"add_filter( 'script_loader_tag'",
			$this->frontend(),
			'Der Filter bekommt den Tag samt der vorangestellten Datenzeile – wer ihn ersetzt, liefert das Skript ohne seinen Katalog aus.'
		);
	}

	/**
	 * Die Daten werden vor dem Skript gesetzt.
	 */
	public function test_daten_stehen_vor_dem_skript() {
		$quelltext = $this->frontend();

		$this->assertStringContainsString( 'wp_add_inline_script', $quelltext );
		$this->assertStringContainsString( "'before'", $quelltext );
		$this->assertStringContainsString( 'window.LW16_HAKEN', $quelltext );
	}

	/**
	 * Das Skript kommt ohne feste Modul-Einbindungen aus.
	 *
	 * Ein `import ... from` am Dateianfang verlangt `type="module"` – und
	 * genau das lässt sich nicht setzen, ohne den Tag anzufassen. three.js
	 * wird deshalb über `import()` nachgeladen; nackte Namen löst die
	 * Zuordnung im Seitenkopf auch aus einem klassischen Skript auf.
	 */
	public function test_skript_laedt_dynamisch() {
		$this->assertSame(
			0,
			preg_match( '/^\s*import\s+[^(]/m', $this->skript() ),
			'Feste Einbindungen machen aus der Datei ein Modul – dann braucht der Tag ein type="module".'
		);

		$this->assertStringContainsString( "import( 'three' )", $this->skript() );
	}

	/**
	 * Das Skript trägt die Zuordnung für den Namen „three" bei.
	 */
	public function test_zuordnung_wird_ausgegeben() {
		$quelltext = $this->frontend();

		$this->assertStringContainsString( 'importmap', $quelltext );
		$this->assertStringContainsString( 'three/addons/', $quelltext );
	}

	/**
	 * Jede Methode, die die Ansicht aufruft, gibt es auch.
	 *
	 * Ein Fehler hat eine Runde gekostet: Beim Aufräumen verschwand die
	 * Methode `holzstange`, ihr Aufruf blieb. Der Aufruf steht in einem
	 * try, dessen catch auf den Ersatzkörper ausweicht – im Browser kam
	 * deshalb keine Fehlermeldung, nur eine flache weiße Platte statt der
	 * Stange. Diese Prüfung hätte es gefunden.
	 */
	public function test_aufgerufene_methoden_der_ansicht_sind_definiert() {
		$js = $this->skript();

		preg_match_all( '/this\.(holzstange|lageModul|ersatzkoerper|vorlage|richteKameraAus|baueRaum|textur)\(/', $js, $calls );

		foreach ( array_unique( $calls[1] ) as $name ) {
			$this->assertMatchesRegularExpression(
				'/^\t(async )?' . $name . '\([ )]/m',
				$js,
				'Die Methode ' . $name . ' wird aufgerufen, aber nicht definiert.'
			);
		}
	}
}
