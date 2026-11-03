<?php
use PHPUnit\Framework\TestCase;
use CostGator\Admin;

final class WorstFirstTest extends TestCase {

	private static function names( array $rows ): array {
		return array_column( $rows, 'name' );
	}

	public function test_largest_loss_per_unit_first_not_lowest_margin() {
		// The small loss has the worse margin, the amount must still win.
		$rows = array(
			array( 'name' => 'small', 'price' => 1.0, 'cost' => 2.0, 'margin' => -100.0 ),
			array( 'name' => 'big', 'price' => 50.0, 'cost' => 60.0, 'margin' => -20.0 ),
		);
		$this->assertSame( array( 'big', 'small' ), self::names( Admin::worst_first( $rows ) ) );
	}

	public function test_equal_losses_are_ordered_by_margin() {
		$rows = array(
			array( 'name' => 'expensive', 'price' => 99.0, 'cost' => 100.0, 'margin' => -1.0 ),
			array( 'name' => 'cheap', 'price' => 9.0, 'cost' => 10.0, 'margin' => -11.1 ),
		);
		$this->assertSame( array( 'cheap', 'expensive' ), self::names( Admin::worst_first( $rows ) ) );
	}

	public function test_zero_price_loss_sorts_before_break_even() {
		// A zero price has no margin, it must not tie with a break-even row.
		$rows = array(
			array( 'name' => 'even', 'price' => 10.0, 'cost' => 10.0, 'margin' => 0.0 ),
			array( 'name' => 'free', 'price' => 0.0, 'cost' => 3.0, 'margin' => null ),
			array( 'name' => 'low', 'price' => 10.5, 'cost' => 10.0, 'margin' => 4.8 ),
		);
		$this->assertSame( array( 'free', 'even', 'low' ), self::names( Admin::worst_first( $rows ) ) );
	}

	public function test_custom_keys_for_preflight_rows() {
		$rows = array(
			array( 'name' => 'a', 'new_price' => 8.0, 'cost' => 9.0, 'new_margin' => -12.5 ),
			array( 'name' => 'b', 'new_price' => 5.0, 'cost' => 9.0, 'new_margin' => -80.0 ),
		);
		$this->assertSame( array( 'b', 'a' ), self::names( Admin::worst_first( $rows, 'new_price', 'new_margin' ) ) );
	}
}
