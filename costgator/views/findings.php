<?php
/**
 * Detailed findings of the last scan, included from admin-page.php.
 *
 * @package CostGator
 */

use CostGator\Admin;

defined( 'ABSPATH' ) || exit;

// Every category is a native disclosure that works without JavaScript. A category starts open when it holds a loss,
// and categories without findings are collected and listed last. Without a known cost nothing
// could be assessed, so empty categories are then shown as neutral, never as passed.
$costgator_passed = '';
$costgator_open   = static function ( string $id, string $class, string $title, int $count, bool $open ) use ( $costgator_known ) {
	if ( ! $count && ! $class ) {
		$class = $costgator_known ? 'is-ok' : 'is-none';
	}
	$icons = array(
		'is-critical' => 'dismiss',
		'is-warning'  => 'warning',
		'is-info'     => 'info',
		'is-ok'       => 'yes-alt',
		'is-none'     => 'minus',
	);
	printf(
		'<details class="costgator-section costgator-check %1$s" id="%2$s"%3$s><summary><span class="dashicons dashicons-%4$s" aria-hidden="true"></span><h2>%5$s</h2> <span class="costgator-count">%6$s</span></summary>',
		esc_attr( $class ),
		esc_attr( $id ),
		$open ? ' open' : '',
		esc_attr( $icons[ $class ] ),
		esc_html( $title ),
		esc_html( number_format_i18n( $count ) )
	);
	if ( 'is-ok' === $class ) {
		echo '<p>' . esc_html__( 'No issues found', 'costgator' ) . '</p>';
	} elseif ( 'is-none' === $class ) {
		echo '<p>' . esc_html__( 'No product has a known cost, so profitability could not be checked.', 'costgator' ) . '</p>';
	}
};
$costgator_close  = static function () {
	echo '</details>';
};
// Renders an empty category into the list of passed checks.
$costgator_pass   = static function ( string $id, string $title ) use ( &$costgator_passed, $costgator_open, $costgator_close ) {
	ob_start();
	$costgator_open( $id, '', $title, 0, false );
	$costgator_close();
	$costgator_passed .= ob_get_clean();
};
?>

<?php if ( ! $costgator_below && ! $costgator_low && ! $costgator_future && ! $costgator_coupons && ! $costgator_missing && ! $scan['unsupported_coupons'] ) : ?>
	<?php if ( $scan['items'] ) : ?>
		<div class="costgator-section is-ok">
			<h2><?php esc_html_e( 'No issues found', 'costgator' ); ?></h2>
			<p><?php esc_html_e( 'Every checked product has a cost and sells above your margin threshold.', 'costgator' ); ?></p>
		</div>
	<?php else : ?>
		<div class="costgator-section">
			<h2><?php esc_html_e( 'No products could be checked', 'costgator' ); ?></h2>
			<p><?php esc_html_e( 'The scan found no published products with a price.', 'costgator' ); ?></p>
		</div>
	<?php endif; ?>
<?php endif; ?>

<div class="costgator-checklist">
<?php
$costgator_sections = array(
	array(
		'id'    => 'costgator-below',
		'title' => __( 'Selling below cost', 'costgator' ),
		'desc'  => __( 'The current price is lower than the cost. Every sale loses money on the product itself.', 'costgator' ),
		'rows'  => $costgator_below,
		'class' => 'is-critical',
	),
	array(
		'id'    => 'costgator-future',
		'title' => __( 'Scheduled sales at risk', 'costgator' ),
		'desc'  => __( 'A sale that has not started yet will push the price below cost or below your margin threshold.', 'costgator' ),
		'rows'  => $costgator_future,
		'class' => 'is-warning',
	),
	array(
		'id'    => 'costgator-low',
		'title' => __( 'Low margin', 'costgator' ),
		'desc'  => __( 'The current price is above cost, but the margin is below your threshold. Review before running more promotions.', 'costgator' ),
		'rows'  => $costgator_low,
		'class' => 'is-warning',
	),
);
foreach ( $costgator_sections as $costgator_section ) :
	if ( empty( $costgator_section['rows'] ) ) {
		$costgator_pass( $costgator_section['id'], $costgator_section['title'] );
		continue;
	}
	$costgator_loss = in_array( 'future_below_cost', array_column( $costgator_section['rows'], 'type' ), true );
	$costgator_open( $costgator_section['id'], $costgator_section['class'], $costgator_section['title'], count( $costgator_section['rows'] ), 'is-critical' === $costgator_section['class'] || $costgator_loss );
	?>
		<p class="description"><?php echo esc_html( $costgator_section['desc'] ); ?></p>
		<table class="widefat striped costgator-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'costgator' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Net price', 'costgator' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Cost', 'costgator' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Margin', 'costgator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'costgator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $costgator_section['rows'], 0, Admin::ROW_LIMIT ) as $costgator_row ) : ?>
					<tr>
						<td><?php echo Admin::product_link( $costgator_row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in product_link(). ?></td>
						<td class="num" data-label="<?php esc_attr_e( 'Net price', 'costgator' ); ?>"><?php echo wp_kses_post( Admin::money( $costgator_row['price'], $scan ) ); ?></td>
						<td class="num" data-label="<?php esc_attr_e( 'Cost', 'costgator' ); ?>"><?php echo wp_kses_post( Admin::money( $costgator_row['cost'], $scan ) ); ?></td>
						<td class="num" data-label="<?php esc_attr_e( 'Margin', 'costgator' ); ?>"><?php echo esc_html( Admin::format_margin( $costgator_row['margin'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Details', 'costgator' ); ?>">
							<?php
							$costgator_details = array();
							if ( 'below_cost' === $costgator_row['type'] ) {
								/* translators: %s: loss amount per unit */
								$costgator_details[] = sprintf( __( 'Loss: %s per unit', 'costgator' ), wp_strip_all_tags( Admin::money( $costgator_row['cost'] - $costgator_row['price'], $scan ) ) );
							}
							if ( ! empty( $costgator_row['on_sale'] ) ) {
								$costgator_details[] = __( 'On sale', 'costgator' );
							}
							if ( ! empty( $costgator_row['from'] ) ) {
								/* translators: %s: sale start date */
								$costgator_details[] = sprintf( __( 'Sale starts %s', 'costgator' ), wp_date( get_option( 'date_format' ), $costgator_row['from'] ) );
								$costgator_details[] = 'future_below_cost' === $costgator_row['type'] ? __( 'Below cost', 'costgator' ) : __( 'Low margin', 'costgator' );
							}
							/* translators: %s: name of the cost data source */
							$costgator_details[] = sprintf( __( 'Cost from %s', 'costgator' ), Admin::source_label( $costgator_row['source'] ) );
							echo esc_html( implode( ' · ', $costgator_details ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( count( $costgator_section['rows'] ) > Admin::ROW_LIMIT ) : ?>
			<p class="description">
				<?php
				/* translators: %s: number of rows not shown */
				printf( esc_html__( 'Not shown: %s more.', 'costgator' ), esc_html( number_format_i18n( count( $costgator_section['rows'] ) - Admin::ROW_LIMIT ) ) );
				?>
			</p>
		<?php endif; ?>
	<?php $costgator_close(); ?>
<?php endforeach; ?>

<?php
$costgator_unsupported = '';
if ( $scan['unsupported_coupons'] ) {
	$costgator_unsupported = '<p class="description">' . sprintf(
		/* translators: %s: number of coupons */
		esc_html__( 'Fixed amount coupons not analyzed: %s. This version checks percentage coupons only.', 'costgator' ),
		esc_html( number_format_i18n( $scan['unsupported_coupons'] ) )
	) . '</p>';
}
if ( ! $costgator_coupons && ! $costgator_unsupported ) {
	$costgator_pass( 'costgator-coupons', __( 'Coupons that could cause losses', 'costgator' ) );
}
?>
<?php if ( ! $costgator_coupons && $costgator_unsupported ) : ?>
	<?php // Coupons that were not analyzed must not look like a passed check. ?>
	<?php $costgator_open( 'costgator-coupons', 'is-info', __( 'Coupons that could cause losses', 'costgator' ), 0, false ); ?>
	<?php echo $costgator_unsupported; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
	<?php $costgator_close(); ?>
<?php endif; ?>
<?php if ( $costgator_coupons ) : ?>
	<?php $costgator_open( 'costgator-coupons', 'is-warning', __( 'Coupons that could cause losses', 'costgator' ), count( $costgator_coupons ), (bool) array_filter( array_column( $costgator_coupons, 'below_cost' ) ) ); ?>
		<p class="description"><?php esc_html_e( 'Potential risk: applied to the current price of eligible products, this coupon would leave them below cost or below your threshold. Spend limits, customer restrictions and coupon combinations are not evaluated.', 'costgator' ); ?></p>
		<table class="widefat striped costgator-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Coupon', 'costgator' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Discount', 'costgator' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Below cost', 'costgator' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Low margin', 'costgator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Examples', 'costgator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $costgator_coupons as $costgator_coupon_id => $costgator_coupon ) : ?>
					<?php $costgator_coupon_url = get_edit_post_link( $costgator_coupon_id ); ?>
					<tr>
						<td><code><?php echo $costgator_coupon_url ? '<a href="' . esc_url( $costgator_coupon_url ) . '">' . esc_html( $costgator_coupon['code'] ) . '</a>' : esc_html( $costgator_coupon['code'] ); ?></code></td>
						<td class="num" data-label="<?php esc_attr_e( 'Discount', 'costgator' ); ?>"><?php echo esc_html( Admin::percent_label( (float) $costgator_coupon['percent'] ) ); ?></td>
						<td class="num" data-label="<?php esc_attr_e( 'Below cost', 'costgator' ); ?>"><?php echo esc_html( number_format_i18n( $costgator_coupon['below_cost'] ) ); ?></td>
						<td class="num" data-label="<?php esc_attr_e( 'Low margin', 'costgator' ); ?>"><?php echo esc_html( number_format_i18n( $costgator_coupon['low_margin'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Examples', 'costgator' ); ?>"><?php echo esc_html( implode( ', ', array_slice( wp_list_pluck( $costgator_coupon['examples'], 'name' ), 0, 5 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $costgator_unsupported; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
	<?php $costgator_close(); ?>
<?php endif; ?>

<?php
if ( ! $costgator_missing ) {
	$costgator_pass( 'costgator-missing', __( 'Missing cost information', 'costgator' ) );
}
?>
<?php if ( $costgator_missing ) : ?>
	<?php
	// Without any known cost this is the only finding that can be acted on, so it starts open.
	$costgator_open( 'costgator-missing', 'is-info', __( 'Missing cost information', 'costgator' ), count( $costgator_missing ), ! $costgator_known );
	?>
		<p class="description"><?php esc_html_e( 'These products have no usable cost, so their profitability cannot be checked. WooCommerce stores a zero cost as empty, so products with a cost of 0 also appear here.', 'costgator' ); ?></p>
		<ul class="costgator-missing">
			<?php foreach ( array_slice( $costgator_missing, 0, Admin::ROW_LIMIT ) as $costgator_row ) : ?>
				<li><?php echo Admin::product_link( $costgator_row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in product_link(). ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( count( $costgator_missing ) > Admin::ROW_LIMIT ) : ?>
			<p class="description">
				<?php
				/* translators: %s: number of rows not shown */
				printf( esc_html__( 'Not shown: %s more.', 'costgator' ), esc_html( number_format_i18n( count( $costgator_missing ) - Admin::ROW_LIMIT ) ) );
				?>
			</p>
		<?php endif; ?>
	<?php $costgator_close(); ?>
<?php endif; ?>

<?php echo $costgator_passed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when rendered. ?>
</div>

<p class="costgator-assumptions">
	<?php
	if ( $scan['prices_include_tax'] ) {
		esc_html_e( 'Prices include tax in this store. They are converted to net using your store base tax rates before comparison.', 'costgator' );
	} else {
		esc_html_e( 'Prices are entered without tax in this store and are compared as entered.', 'costgator' );
	}
	echo ' ';
	printf(
		/* translators: %s: currency code */
		esc_html__( 'Costs are assumed to be net of tax, in %s. Only product price and cost are compared. Shipping, payment fees, marketing and other costs are not included.', 'costgator' ),
		esc_html( $scan['currency'] )
	);
	echo ' ';
	esc_html_e( 'Prices are read as stored on your products. Prices that dynamic pricing or currency switcher plugins calculate on the fly are not included.', 'costgator' );
	?>
</p>



