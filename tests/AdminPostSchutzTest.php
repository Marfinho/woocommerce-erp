<?php
/**
 * Jeder Formular-Handler prüft Nonce und Recht.
 *
 * `admin_post_*` ist eine offene Tür: Die Adresse ist erratbar, und wer sie
 * aufruft, landet mitten in einer schreibenden Funktion. Ohne Nonce lässt
 * sich ein angemeldeter Betreiber von einer fremden Seite aus dazu bringen,
 * die Aktion auszuführen; ohne Rechteprüfung darf sie jeder Abonnent.
 *
 * Beides fehlt selten von Anfang an. Es fehlt bei dem einen Handler, der
 * später dazukam – und niemand merkt es, weil das Formular ja funktioniert.
 * Deshalb liest dieser Test den Quelltext und nicht ein nachgebautes
 * Verhalten.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Schutz-Tests der Formular-Handler.
 */
class AdminPostSchutzTest extends TestCase {

	/**
	 * Handler, die bewusst ohne Rechteprüfung auskommen.
	 *
	 * Beide gehören Kunden, nicht dem Betrieb: Eine Wartelisten-Anmeldung
	 * steht jedem offen, ein Support-Ticket legt der angemeldete Kunde für
	 * sich selbst an. Eine Capability zu verlangen hiesse, den Kunden
	 * auszusperren. Die Nonce wird trotzdem geprüft.
	 *
	 * @var array
	 */
	const OHNE_RECHT = array(
		'handle_signup',
		'handle_ticket_create',
	);

	/**
	 * Die Rümpfe aller Methoden einer Datei.
	 *
	 * @param string $src Quelltext.
	 * @return array Name => Rumpf.
	 */
	protected function methoden( $src ) {
		$out = array();

		if ( ! preg_match_all( '/function (\w+)\s*\([^)]*\)\s*\{/', $src, $treffer, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $treffer as $m ) {
			$name  = $m[1][0];
			$start = $m[0][1] + strlen( $m[0][0] ) - 1;
			$tiefe = 0;

			for ( $i = $start; $i < strlen( $src ); $i++ ) {
				if ( '{' === $src[ $i ] ) {
					$tiefe++;
				} elseif ( '}' === $src[ $i ] ) {
					$tiefe--;

					if ( 0 === $tiefe ) {
						$out[ $name ] = substr( $src, $start, $i - $start );
						break;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Prüft eine Methode – und die Hilfsmethoden, an die sie weiterreicht.
	 *
	 * Fast alle Module bündeln die beiden Prüfungen in einem `guard()` oder
	 * `require_cap()`. Wer nur den Rumpf des Handlers ansieht, meldet
	 * hundert Fehlalarme.
	 *
	 * @param array  $methoden Alle Methoden.
	 * @param string $name     Methode.
	 * @param int    $tiefe    Rekursionstiefe.
	 * @param array  $gesehen  Bereits besuchte Methoden.
	 * @return array {nonce, recht}
	 */
	protected function prueft( array $methoden, $name, $tiefe = 0, array &$gesehen = array() ) {
		if ( isset( $gesehen[ $name ] ) || $tiefe > 3 || ! isset( $methoden[ $name ] ) ) {
			return array( 'nonce' => false, 'recht' => false );
		}

		$gesehen[ $name ] = true;
		$rumpf            = $methoden[ $name ];

		$nonce = (bool) preg_match( '/check_admin_referer|wp_verify_nonce|check_ajax_referer/', $rumpf );
		$recht = (bool) preg_match( '/current_user_can|can_betrieb/', $rumpf );

		// Weiterreichen an eine Hilfsmethode, egal ob über $this oder self.
		preg_match_all( '/(?:\$this->|self::)(\w+)\s*\(/', $rumpf, $aufrufe );

		foreach ( $aufrufe[1] as $aufruf ) {
			$weiter = $this->prueft( $methoden, $aufruf, $tiefe + 1, $gesehen );

			$nonce = $nonce || $weiter['nonce'];
			$recht = $recht || $weiter['recht'];
		}

		return array( 'nonce' => $nonce, 'recht' => $recht );
	}

	/**
	 * Alle registrierten Formular-Handler.
	 *
	 * @return array Liste aus {datei, methode, nopriv}.
	 */
	protected function handler() {
		$out   = array();
		$basis = dirname( __DIR__ ) . '/includes';
		$dirs  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $basis ) );

		foreach ( $dirs as $datei ) {
			if ( 'php' !== $datei->getExtension() ) {
				continue;
			}

			$src = file_get_contents( $datei->getPathname() );

			preg_match_all(
				"/add_action\(\s*'admin_post(_nopriv)?_\w+'\s*,\s*array\(\s*\\\$this\s*,\s*'(\w+)'\s*\)/",
				$src,
				$treffer,
				PREG_SET_ORDER
			);

			foreach ( $treffer as $m ) {
				$out[] = array(
					'datei'   => str_replace( $basis . '/', '', $datei->getPathname() ),
					'methode' => $m[2],
					'nopriv'  => '' !== $m[1],
					'src'     => $src,
				);
			}
		}

		return $out;
	}

	/**
	 * Der Test findet überhaupt Handler – sonst schweigt er sich grün.
	 */
	public function test_der_test_findet_die_handler() {
		$this->assertGreaterThanOrEqual( 50, count( $this->handler() ) );
	}

	/**
	 * Jeder Handler prüft die Nonce.
	 *
	 * Ohne sie genügt ein Link auf einer fremden Seite, um einen
	 * angemeldeten Betreiber eine Buchung auslösen zu lassen.
	 */
	public function test_jeder_handler_prueft_die_nonce() {
		foreach ( $this->handler() as $h ) {
			$gesehen = array();
			$ergebnis = $this->prueft( $this->methoden( $h['src'] ), $h['methode'], 0, $gesehen );

			$this->assertTrue(
				$ergebnis['nonce'],
				$h['datei'] . '::' . $h['methode'] . '() prüft keine Nonce.'
			);
		}
	}

	/**
	 * Jeder Handler prüft ein Recht – ausser den Kundenformularen.
	 */
	public function test_jeder_handler_prueft_ein_recht() {
		foreach ( $this->handler() as $h ) {
			if ( $h['nopriv'] || in_array( $h['methode'], self::OHNE_RECHT, true ) ) {
				continue;
			}

			$gesehen  = array();
			$ergebnis = $this->prueft( $this->methoden( $h['src'] ), $h['methode'], 0, $gesehen );

			$this->assertTrue(
				$ergebnis['recht'],
				$h['datei'] . '::' . $h['methode'] . '() prüft kein Recht.'
			);
		}
	}

	/**
	 * Die Ausnahmeliste bleibt kurz und aktuell.
	 *
	 * Eine Ausnahme, die keinen Handler mehr meint, ist eine Tür, die
	 * jemand offen gelassen hat, weil sie in der Liste stand.
	 */
	public function test_die_ausnahmeliste_ist_aktuell() {
		$methoden = array();

		foreach ( $this->handler() as $h ) {
			$methoden[] = $h['methode'];
		}

		foreach ( self::OHNE_RECHT as $ausnahme ) {
			$this->assertContains( $ausnahme, $methoden, $ausnahme . ' steht als Ausnahme, ist aber kein Handler mehr.' );
		}
	}
}
