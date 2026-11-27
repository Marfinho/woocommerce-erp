<?php
/**
 * Bestellabfragen schließen Erstattungen aus.
 *
 * `wc_get_orders()` liefert ohne `'type'` auch Erstattungen
 * (shop_order_refund) – und die tragen in WooCommerce den Status
 * „abgeschlossen“. Sie haben weder Bestellnummer noch Rechnungsadresse:
 * Dashboard, Tagesplan, Rückblick, Nachkauf und EÜR brachen daran mit
 * „Call to undefined method OrderRefund::…“ ab, andere Auswertungen zählten
 * Erstattungen still als Bestellungen mit.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Dhl\DhlLabelRepository;

class OrderQueryTypeTest extends TestCase {

	/**
	 * Dateien, die bewusst alle Bestellarten holen.
	 */
	const AUSNAHMEN = array(
		'includes/Core/ResetTool.php' => 'Zurücksetzen löscht alles, Erstattungen eingeschlossen.',
	);

	/**
	 * Argumente aller wc_get_orders()-Aufrufe einer Datei.
	 *
	 * @param string $code Quelltext.
	 * @return string[] Quelltext des ersten Arguments.
	 */
	protected function aufrufe( $code ) {
		$tokens = token_get_all( $code );
		$out    = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || 'wc_get_orders' !== $tokens[ $i ][1] ) {
				continue;
			}

			$depth = 0;
			$arg   = '';
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
				if ( '(' === $text ) {
					$depth++;
					if ( 1 === $depth ) {
						continue;
					}
				} elseif ( ')' === $text ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
				}
				if ( $depth >= 1 ) {
					$arg .= $text;
				}
			}
			$out[] = trim( $arg );
		}

		return $out;
	}

	public function test_jede_bestellabfrage_nennt_den_typ() {
		$root  = dirname( __DIR__ );
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
		$fehlt = array();
		$seen  = 0;

		foreach ( $it as $file ) {
			if ( '.php' !== substr( (string) $file, -4 ) ) {
				continue;
			}

			$rel  = substr( (string) $file, strlen( $root ) + 1 );
			$code = (string) file_get_contents( (string) $file );

			if ( isset( self::AUSNAHMEN[ $rel ] ) ) {
				continue;
			}

			foreach ( $this->aufrufe( $code ) as $arg ) {
				// Erwähnung im Kommentar oder als Text, kein Aufruf.
				if ( '' === $arg ) {
					continue;
				}
				$seen++;

				if ( 0 === strpos( $arg, 'array' ) || 0 === strpos( $arg, '[' ) ) {
					$ok = (bool) preg_match( "/'type'\s*=>\s*'shop_order'/", $arg );
				} else {
					// Argumente aus einer Variablen: Die Datei muss den Typ setzen.
					$ok = (bool) preg_match( "/'type'\s*=>\s*'shop_order'/", $code );
				}

				if ( ! $ok ) {
					$fehlt[] = $rel . ': wc_get_orders( ' . substr( preg_replace( '/\s+/', ' ', $arg ), 0, 80 ) . ' )';
				}
			}
		}

		$this->assertGreaterThan( 30, $seen, 'Der Test findet die Aufrufe nicht mehr.' );
		$this->assertSame( array(), $fehlt, "Ohne 'type' => 'shop_order' kommen Erstattungen mit." );
	}

	public function test_porto_einer_labelliste() {
		$label = static function ( $kosten ) {
			return (object) array( 'kosten_netto' => $kosten );
		};

		$this->assertNull( DhlLabelRepository::cost_of( array() ) );
		$this->assertNull( DhlLabelRepository::cost_of( array( $label( null ) ) ), 'Unbekanntes Porto ist nicht null Euro.' );
		$this->assertSame( 9.11, DhlLabelRepository::cost_of( array( $label( '4.555' ), $label( null ), $label( 4.555 ) ) ) );
	}
}
