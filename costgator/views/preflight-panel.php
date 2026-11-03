<?php
/**
 * Promotion Preflight panel, included from admin-page.php.
 *
 * @package CostGator
 */

use CostGator\Admin;

defined( 'ABSPATH' ) || exit;
?>

<div class="costgator-preflight" id="costgator-preflight">
	<h2><?php esc_html_e( 'Promotion Preflight', 'costgator' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Planning a store-wide sale? See which products would drop below cost or below your threshold before you launch it. Uses the data of the last scan.', 'costgator' ); ?></p>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>#costgator-preflight" class="costgator-inline-form">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_SLUG ); ?>">
		<label>
			<?php esc_html_e( 'Discount', 'costgator' ); ?>
			<input type="number" name="preflight" min="1" max="100" step="0.1" value="<?php echo esc_attr( null !== $costgator_preflight_percent ? $costgator_preflight_percent : 20 ); ?>" class="small-text"> %
		</label>
		<label>
			<?php esc_html_e( 'applied to', 'costgator' ); ?>
			<select name="basis">
				<option value="current" <?php selected( $costgator_preflight_basis, 'current' ); ?>><?php esc_html_e( 'current price (on top of running sales)', 'costgator' ); ?></option>
				<option value="regular" <?php selected( $costgator_preflight_basis, 'regular' ); ?>><?php esc_html_e( 'regular price (replacing running sales)', 'costgator' ); ?></option>
			</select>
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Check promotion', 'costgator' ); ?></button>
	</form>

	<?php if ( $costgator_pf ) : ?>
		<p class="costgator-preflight-summary">
			<?php
			printf(
				/* translators: 1: discount percent, 2: products below cost, 3: products with low margin, 4: products checked */
				esc_html__( 'With %1$s off: %2$s below cost, %3$s below threshold, out of %4$s products with a known cost.', 'costgator' ),
				esc_html( number_format_i18n( $costgator_preflight_percent, 1 ) . '%' ),
				'<strong class="costgator-critical-text">' . esc_html( number_format_i18n( count( $costgator_pf['below_cost'] ) ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( count( $costgator_pf['low_margin'] ) ) ) . '</strong>',
				esc_html( number_format_i18n( $costgator_pf['checked'] ) )
			);
			if ( $costgator_pf['no_cost'] ) {
				echo ' ';
				/* translators: %s: number of products */
				printf( esc_html__( 'Skipped without cost: %s.', 'costgator' ), esc_html( number_format_i18n( $costgator_pf['no_cost'] ) ) );
			}
			?>
		</p>
		<?php if ( $costgator_pf['below_cost'] ) : ?>
			<p class="costgator-overview-loss"><?php $costgator_largest_loss( $costgator_pf_rows[0], 'new_price' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<p class="costgator-caveat"><?php esc_html_e( 'Per-unit estimate using your last scan. Cart discounts, coupon combinations and checkout rounding are not simulated.', 'costgator' ); ?></p>

	<?php require __DIR__ . '/preflight-table.php'; ?>
</div>
