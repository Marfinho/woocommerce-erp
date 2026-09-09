<?php
/**
 * Jede Bridge-Schnittstelle prüft das Token, bevor sie sperrt.
 *
 * Die Geräte-Schnittstellen (Etikettendrucker, Bambu-Drucker,
 * Slicing-Worker) haben kein WordPress-Konto, sondern ein Bearer-Token,
 * und schützen sich gegen Token-Raten mit einem Ratenlimit je IP. Die
 * Reihenfolge der beiden Prüfungen entscheidet dabei über mehr als den
 * Stil:
 *
 * Hinter einem Reverse-Proxy oder CDN tragen alle Anfragen dieselbe
 * REMOTE_ADDR – auch die des eigenen Geräts. Kommt die Sperre zuerst,
 * genügen einem Fremden 30 falsche Token je Minute, um das eigene Gerät
 * dauerhaft auf 429 laufen zu lassen: kein Etikett, kein Druck, kein
 * geslictes Modell. Ohne Anmeldung, von überall her, und weil jeder
 * Fehlversuch die Frist neu setzt, endet es nicht von selbst.
 *
 * Wird das Token zuerst geprüft, ändert sich am Schutz gegen Token-Raten
 * nichts – es kommen weiterhin nur 30 Fehlversuche je Minute und IP
 * durch –, aber ein gültiges Token wird nie gesperrt.
 *
 * Dieser Fehler ist in diesem Projekt dreimal entstanden, jedes Mal beim
 * Abschreiben von der vorigen Schnittstelle. Deshalb liest der Test den
 * Quelltext: Die vierte Schnittstelle soll nicht wieder von Hand
 * gefunden werden müssen.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Reihenfolge-Tests der Token-Authentifizierung.
 */
class BridgeAuthReihenfolgeTest extends TestCase {

	/**
	 * Alle Schnittstellen mit Token-Authentifizierung und Ratenlimit.
	 *
	 * Gesucht wird nach dem Muster, nicht nach einer Liste von Dateinamen –
	 * eine neue Schnittstelle wird dadurch automatisch mitgeprüft.
	 *
	 * @return array Liste aus {datei, rumpf}.
	 */
	protected function schnittstellen() {
		$out   = array();
		$basis = dirname( __DIR__ ) . '/includes';
		$dirs  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $basis ) );

		foreach ( $dirs as $datei ) {
			if ( 'php' !== $datei->getExtension() ) {
				continue;
			}

			$src = file_get_contents( $datei->getPathname() );

			if ( false === strpos( $src, 'rate_limited' ) || ! preg_match( '/function authenticate\s*\(/', $src ) ) {
				continue;
			}

			$rumpf = $this->rumpf( $src, 'authenticate' );

			if ( '' === $rumpf ) {
				continue;
			}

			$out[] = array(
				'datei' => str_replace( $basis . '/', '', $datei->getPathname() ),
				'rumpf' => $rumpf,
			);
		}

		return $out;
	}

	/**
	 * Rumpf einer Methode.
	 *
	 * @param string $src  Quelltext.
	 * @param string $name Methodenname.
	 * @return string
	 */
	protected function rumpf( $src, $name ) {
		if ( ! preg_match( '/function ' . preg_quote( $name, '/' ) . '\s*\([^)]*\)\s*\{/', $src, $treffer, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}

		$start = $treffer[0][1] + strlen( $treffer[0][0] ) - 1;
		$tiefe = 0;

		for ( $i = $start, $len = strlen( $src ); $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$tiefe++;
			} elseif ( '}' === $src[ $i ] ) {
				$tiefe--;

				if ( 0 === $tiefe ) {
					return substr( $src, $start, $i - $start );
				}
			}
		}

		return '';
	}

	/**
	 * Der Test findet überhaupt Schnittstellen – sonst schweigt er sich grün.
	 */
	public function test_der_test_findet_die_schnittstellen() {
		$this->assertGreaterThanOrEqual( 3, count( $this->schnittstellen() ) );
	}

	/**
	 * Das Token wird aufgelöst, bevor das Ratenlimit greift.
	 */
	public function test_token_wird_vor_dem_ratenlimit_geprueft() {
		foreach ( $this->schnittstellen() as $s ) {
			// Der Griff zum Token: eine Suche über die Drucker oder ein
			// Vergleich gegen das gespeicherte Geheimnis.
			$this->assertTrue(
				(bool) preg_match( '/find_by_token\s*\(|::verify\s*\(|token_matches\s*\(/', $s['rumpf'], $token, PREG_OFFSET_CAPTURE ),
				$s['datei'] . '::authenticate() löst gar kein Token auf.'
			);

			$this->assertTrue(
				(bool) preg_match( '/rate_limited\s*\(/', $s['rumpf'], $limit, PREG_OFFSET_CAPTURE ),
				$s['datei'] . '::authenticate() hat kein Ratenlimit.'
			);

			$this->assertLessThan(
				$limit[0][1],
				$token[0][1],
				$s['datei'] . '::authenticate() sperrt, bevor es das Token prüft. '
					. 'Hinter einem CDN lässt sich damit das eigene Gerät ohne Anmeldung dauerhaft aussperren.'
			);
		}
	}

	/**
	 * Gezählt wird weiterhin – sonst wäre die Sperre wirkungslos.
	 */
	public function test_fehlversuche_werden_weiterhin_gezaehlt() {
		foreach ( $this->schnittstellen() as $s ) {
			$this->assertMatchesRegularExpression(
				'/register_auth_failure\s*\(/',
				$s['rumpf'],
				$s['datei'] . '::authenticate() zählt keine Fehlversuche – das Ratenlimit greift dann nie.'
			);
		}
	}

	/**
	 * Das vorgelegte Token taucht in keiner Fehlermeldung auf.
	 *
	 * Eine Meldung landet im Log und womöglich beim Angreifer selbst; ein
	 * Token gehört in keines von beiden.
	 */
	public function test_das_token_steht_in_keiner_meldung() {
		// Gesucht wird das Einsetzen in einen Text – Verkettung, Einbettung
		// in eine Zeichenkette oder sprintf. Ein blosser Vergleich
		// ('' === $token) ist keine Ausgabe und darf nicht anschlagen.
		$muster = array(
			'/\.\s*\$token\b/',      // 'Text ' . $token
			'/\$token\s*\./',        // $token . ' Text'
			'/"[^"]*\$token[^"]*"/',  // "... $token ..."
			'/sprintf\([^;]*\$token/', // sprintf( '%s', $token )
		);

		foreach ( $this->schnittstellen() as $s ) {
			foreach ( $muster as $m ) {
				$this->assertDoesNotMatchRegularExpression(
					$m,
					$s['rumpf'],
					$s['datei'] . '::authenticate() setzt das vorgelegte Token in einen Text ein. '
						. 'Eine Meldung landet im Log und womöglich beim Angreifer – ein Token gehört in keines von beiden.'
				);
			}
		}
	}
}
