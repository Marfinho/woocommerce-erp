<?php
/**
 * Vorgangstabelle: jeder Formular- und Ajax-Vorgang ist zugeordnet oder
 * bewusst ausgenommen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\ActionMap;
use Lindenweg16\Erp\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * Tests für ActionMap.
 */
class ErpActionMapTest extends TestCase {

	/**
	 * Alle im Quelltext fest benannten Vorgänge.
	 *
	 * @return array Name => Datei.
	 */
	protected function literal_actions() {
		$found    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/includes', FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( preg_match_all( "/add_action\\(\\s*'(?:admin_post_|wp_ajax_)(?:nopriv_)?([a-z0-9_]+)'/", file_get_contents( $file->getPathname() ), $m ) ) {
				foreach ( $m[1] as $name ) {
					// Namen, die auf _ enden, sind Vorsilben dynamisch gebildeter Vorgänge.
					if ( '_' === substr( $name, -1 ) ) {
						continue;
					}
					$found[ $name ] = $file->getFilename();
				}
			}
		}

		return $found;
	}

	public function test_every_rule_names_existing_rights() {
		foreach ( ActionMap::rules() as $action => $perms ) {
			$this->assertNotEmpty( $perms, $action );
			foreach ( $perms as $perm ) {
				$this->assertTrue( Permissions::exists( $perm ), "$action verlangt $perm, das es nicht gibt" );
			}
		}
	}

	public function test_every_literal_action_is_mapped_or_exempt() {
		$rules  = ActionMap::rules();
		$exempt = ActionMap::exempt();

		$this->assertNotEmpty( $this->literal_actions() );

		foreach ( $this->literal_actions() as $action => $where ) {
			$this->assertTrue(
				isset( $rules[ $action ] ) || isset( $exempt[ $action ] ),
				"$action ($where) ist weder zugeordnet noch ausgenommen"
			);
		}
	}

	public function test_nothing_is_both_mapped_and_exempt() {
		$this->assertSame( array(), array_intersect_key( ActionMap::rules(), ActionMap::exempt() ) );
	}

	public function test_exemptions_carry_a_reason() {
		foreach ( ActionMap::exempt() as $action => $reason ) {
			$this->assertNotSame( '', $reason, $action );
		}
	}

	public function test_no_rule_for_actions_that_do_not_exist() {
		// Tippfehler in der Tabelle würden still nichts schützen.
		$literal = $this->literal_actions();
		$dynamic = array( 'article', 'component', 'revision', 'bom', 'sourcing', 'activate', 'bom_delete', 'bundle', 'model_remove' );

		foreach ( array_keys( ActionMap::rules() ) as $action ) {
			$known = isset( $literal[ $action ] );
			foreach ( $dynamic as $what ) {
				$known = $known || 'lw16_betrieb_save_' . $what === $action;
			}
			// SimplePages: lw16_betrieb_{time,contact,…} entstehen aus einer Schleife.
			$known = $known || in_array( $action, array( 'lw16_betrieb_time', 'lw16_betrieb_contact', 'lw16_betrieb_activity', 'lw16_betrieb_term', 'lw16_betrieb_token', 'lw16_betrieb_maintenance', 'lw16_betrieb_freeze', 'lw16_betrieb_commission_batch', 'lw16_betrieb_goals' ), true );
			$this->assertTrue( $known, "$action gibt es im Quelltext nicht" );
		}
	}

	public function test_unlisted_action_has_no_requirements() {
		$this->assertSame( array(), ActionMap::perms_for( 'edit' ) );
	}

	public function test_stock_booking_needs_the_booking_right() {
		$this->assertContains( 'lager.buchen', ActionMap::perms_for( 'lw16_betrieb_stock_move' ) );
		$this->assertContains( 'verkauf.versand.etikett', ActionMap::perms_for( 'lw16_betrieb_dhl_label' ) );
	}
}
