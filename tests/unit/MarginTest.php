<?php
use PHPUnit\Framework\TestCase;
use CostGator\Margin;

final class MarginTest extends TestCase {

	public function test_percent_is_gross_margin_not_markup() {
		$this->assertEqualsWithDelta( 25.0, Margin::percent( 100.0, 75.0 ), 1e-9 );
	}

	public function test_percent_is_null_for_non_positive_price() {
		$this->assertNull( Margin::percent( 0.0, 10.0 ) );
		$this->assertNull( Margin::percent( -5.0, 10.0 ) );
	}

	public function test_below_cost() {
		$this->assertSame( Margin::STATUS_BELOW_COST, Margin::classify( 75.0, 80.0, 10.0 ) );
	}

	public function test_break_even_is_low_margin_not_loss() {
		$this->assertSame( Margin::STATUS_LOW_MARGIN, Margin::classify( 80.0, 80.0, 10.0 ) );
	}

	public function test_rounding_noise_is_break_even() {
		// 0.1 + 0.2 style float noise must not turn break-even into a loss.
		$this->assertSame( Margin::STATUS_LOW_MARGIN, Margin::classify( 0.3, 0.1 + 0.2, 10.0 ) );
	}

	public function test_sub_unit_loss_rounds_to_break_even_in_zero_decimal_currency() {
		$this->assertSame( Margin::STATUS_LOW_MARGIN, Margin::classify( 9999.6, 10000.0, 10.0, 0 ) );
		$this->assertSame( Margin::STATUS_BELOW_COST, Margin::classify( 9999.4, 10000.0, 10.0, 0 ) );
	}

	public function test_threshold_boundary_is_ok() {
		$this->assertSame( Margin::STATUS_OK, Margin::classify( 100.0, 90.0, 10.0 ) );
		$this->assertSame( Margin::STATUS_LOW_MARGIN, Margin::classify( 100.0, 90.01, 10.0 ) );
	}

	public function test_zero_threshold_only_flags_losses() {
		$this->assertSame( Margin::STATUS_OK, Margin::classify( 100.0, 99.0, 0.0 ) );
	}

	public function test_discounted_clamps_percent() {
		$this->assertEqualsWithDelta( 75.0, Margin::discounted( 100.0, 25.0 ), 1e-9 );
		$this->assertEqualsWithDelta( 0.0, Margin::discounted( 100.0, 150.0 ), 1e-9 );
		$this->assertEqualsWithDelta( 100.0, Margin::discounted( 100.0, -5.0 ), 1e-9 );
	}
}
