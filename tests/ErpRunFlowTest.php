<?php
/**
 * Ablauf einer Platte: Übergänge, Zeiten, Ist-Meldung, Etikett.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\RunFlow;
use PHPUnit\Framework\TestCase;

/**
 * Tests für RunFlow.
 */
class ErpRunFlowTest extends TestCase {

	public function test_the_normal_way_goes_through_every_step() {
		$status = RunFlow::FREIGEGEBEN;

		foreach ( array( 'ruesten' => 'ruesten', 'druckstart' => 'druckt', 'druckende' => 'zu_melden', 'melden' => 'erledigt' ) as $action => $expected ) {
			$result = RunFlow::apply( $status, $action );
			$this->assertTrue( $result['ok'], $action );
			$this->assertFalse( $result['noop'], $action );
			$status = $result['to'];
			$this->assertSame( $expected, $status );
		}
	}

	public function test_primary_action_leads_to_the_next_step() {
		foreach ( array( RunFlow::FREIGEGEBEN, RunFlow::RUESTEN, RunFlow::DRUCKT, RunFlow::ZU_MELDEN ) as $status ) {
			$primary = RunFlow::primary( $status );
			$this->assertNotEmpty( $primary, $status );
			$this->assertTrue( RunFlow::apply( $status, $primary['action'] )['ok'], $status );
		}

		$this->assertSame( array(), RunFlow::primary( RunFlow::ERLEDIGT ) );
		$this->assertSame( array(), RunFlow::primary( RunFlow::ABGEBROCHEN ) );
	}

	public function test_repeating_an_action_is_a_noop_not_an_error() {
		$result = RunFlow::apply( RunFlow::DRUCKT, 'druckstart' );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['noop'] );
		$this->assertSame( RunFlow::DRUCKT, $result['to'] );

		$this->assertTrue( RunFlow::apply( RunFlow::ERLEDIGT, 'melden' )['noop'] );
	}

	public function test_nothing_leaves_a_finished_or_aborted_plate() {
		foreach ( array( 'ruesten', 'druckstart', 'druckende', 'abbrechen' ) as $action ) {
			$this->assertFalse( RunFlow::apply( RunFlow::ERLEDIGT, $action )['ok'], $action );
		}
		$this->assertFalse( RunFlow::apply( RunFlow::ABGEBROCHEN, 'ruesten' )['ok'] );
	}

	public function test_steps_cannot_be_skipped_backwards_or_unknown() {
		$this->assertFalse( RunFlow::apply( RunFlow::FREIGEGEBEN, 'druckende' )['ok'] );
		$this->assertFalse( RunFlow::apply( RunFlow::FREIGEGEBEN, 'melden' )['ok'] );
		$this->assertFalse( RunFlow::apply( RunFlow::ZU_MELDEN, 'ruesten' )['ok'] );
		$this->assertFalse( RunFlow::apply( RunFlow::FREIGEGEBEN, 'gibtsnicht' )['ok'] );
	}

	public function test_setup_may_be_skipped_and_report_may_follow_directly() {
		$this->assertSame( RunFlow::DRUCKT, RunFlow::apply( RunFlow::FREIGEGEBEN, 'druckstart' )['to'] );
		$this->assertSame( RunFlow::ERLEDIGT, RunFlow::apply( RunFlow::DRUCKT, 'melden' )['to'] );
	}

	public function test_every_status_maps_to_a_known_column() {
		$this->assertSame( 'freigegeben', RunFlow::column( RunFlow::FREIGEGEBEN ) );
		$this->assertSame( 'im_druck', RunFlow::column( RunFlow::RUESTEN ) );
		$this->assertSame( 'im_druck', RunFlow::column( RunFlow::DRUCKT ) );
		$this->assertSame( 'zu_melden', RunFlow::column( RunFlow::ZU_MELDEN ) );
		$this->assertSame( 'erledigt', RunFlow::column( RunFlow::ERLEDIGT ) );
		$this->assertSame( '', RunFlow::column( RunFlow::ABGEBROCHEN ) );
	}

	public function test_hours_between() {
		$this->assertSame( 1.5, RunFlow::hours_between( '2026-10-19 08:00:00', '2026-10-19 09:30:00' ) );
		$this->assertSame( 0.0, RunFlow::hours_between( '2026-10-19 09:00:00', '2026-10-19 08:00:00' ), 'nie negativ' );
		$this->assertSame( 0.0, RunFlow::hours_between( null, '2026-10-19 08:00:00' ) );
		$this->assertSame( 0.233, RunFlow::hours_between( '2026-10-19 08:00:00', '2026-10-19 08:14:00' ) );
	}

	public function test_split_hours_sums_exactly_to_the_total() {
		$parts = RunFlow::split_hours( 3.2, array( 'a' => 22, 'b' => 7, 'c' => 5 ) );

		$this->assertEqualsWithDelta( 3.2, array_sum( $parts ), 0.0001 );
		$this->assertSame( array( 'a', 'b', 'c' ), array_keys( $parts ) );
		$this->assertGreaterThan( $parts['b'], $parts['a'] );
	}

	public function test_split_hours_without_quantities_gives_zero() {
		$this->assertSame( array( 'a' => 0.0 ), RunFlow::split_hours( 2.0, array( 'a' => 0 ) ) );
		$this->assertSame( array(), RunFlow::split_hours( 2.0, array() ) );
	}

	protected function items() {
		return array(
			array( 'article_id' => 5, 'color' => 'schwarz', 'qty' => 24 ),
			array( 'article_id' => 6, 'color' => 'weiss', 'qty' => 10 ),
		);
	}

	public function test_report_all_good_needs_no_reason() {
		$r = RunFlow::validate_report(
			$this->items(),
			array( array( 'article_id' => 5, 'color' => 'schwarz', 'ok' => 24 ), array( 'article_id' => 6, 'color' => 'weiss', 'ok' => 10 ) ),
			'keine',
			array( 'keine', 'warping' )
		);

		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( 0.0, $r['items'][0]['scrap'] );
	}

	public function test_report_shortfall_requires_a_real_reason() {
		$reported = array( array( 'article_id' => 5, 'color' => 'schwarz', 'ok' => 22 ), array( 'article_id' => 6, 'color' => 'weiss', 'ok' => 10 ) );

		$without = RunFlow::validate_report( $this->items(), $reported, 'keine', array( 'keine', 'warping' ) );
		$this->assertNotEmpty( $without['errors'] );

		$unknown = RunFlow::validate_report( $this->items(), $reported, 'quatsch', array( 'keine', 'warping' ) );
		$this->assertNotEmpty( $unknown['errors'] );

		$with = RunFlow::validate_report( $this->items(), $reported, 'warping', array( 'keine', 'warping' ) );
		$this->assertSame( array(), $with['errors'] );
		$this->assertSame( 2.0, $with['items'][0]['scrap'] );
	}

	public function test_report_rejects_more_than_planned_negative_and_missing_positions() {
		$over = RunFlow::validate_report( $this->items(), array( array( 'article_id' => 5, 'color' => 'schwarz', 'ok' => 25 ), array( 'article_id' => 6, 'color' => 'weiss', 'ok' => 10 ) ), 'keine', array( 'keine' ) );
		$this->assertNotEmpty( $over['errors'] );

		$neg = RunFlow::validate_report( $this->items(), array( array( 'article_id' => 5, 'color' => 'schwarz', 'ok' => -1 ), array( 'article_id' => 6, 'color' => 'weiss', 'ok' => 10 ) ), 'keine', array( 'keine' ) );
		$this->assertNotEmpty( $neg['errors'] );

		$missing = RunFlow::validate_report( $this->items(), array( array( 'article_id' => 5, 'color' => 'schwarz', 'ok' => 24 ) ), 'keine', array( 'keine' ) );
		$this->assertNotEmpty( $missing['errors'] );
	}

	public function test_label_text_follows_the_chosen_fields() {
		$run = array(
			'id'          => 3,
			'printer'     => 'P1S',
			'est_minutes' => 190,
			'order_ref'   => 'A-0143',
			'items'       => array( array( 'name' => 'Haken S', 'qty' => 24, 'color' => 'schwarz' ) ),
		);

		$all = RunFlow::label_text( $run, array( 'platte', 'artikel', 'material', 'drucker', 'auftrag' ) );
		$this->assertSame( 'Platte 3', $all['code'] );
		$this->assertSame( 'P1S · 3 h 10', $all['kind'] );
		$this->assertSame( '24× Haken S · schwarz · A-0143', $all['name'] );

		$few = RunFlow::label_text( $run, array( 'platte' ) );
		$this->assertSame( 'Platte 3', $few['code'] );
		$this->assertSame( 'Druckauftrag', $few['kind'] );
		$this->assertSame( '', $few['name'] );

		$none = RunFlow::label_text( $run, array() );
		$this->assertSame( '#3', $none['code'], 'ohne Plattennummer bleibt eine Kennung, sonst wäre das Etikett leer' );
	}

	public function test_duration_and_quantity_formatting() {
		$this->assertSame( '45 min', RunFlow::duration( 45 ) );
		$this->assertSame( '3 h 05', RunFlow::duration( 185 ) );
		$this->assertSame( '24', RunFlow::trim_qty( 24.0 ) );
		$this->assertSame( '2,5', RunFlow::trim_qty( 2.5 ) );
	}
}
