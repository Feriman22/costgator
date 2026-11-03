<?php
/**
 * Summary of the last scan, included from admin-page.php.
 *
 * @package CostGator
 */

defined( 'ABSPATH' ) || exit;
?>
<?php
if ( $costgator_below ) {
	$costgator_state = 'is-critical';
} else {
	// Without a known cost nothing could be checked, so the summary must not look like a clean result.
	$costgator_state = $costgator_known ? 'is-ok' : '';
}
?>
<div class="costgator-overview <?php echo esc_attr( $costgator_state ); ?>">
	<p class="costgator-overview-head">
		<span class="costgator-overview-value"><?php echo esc_html( number_format_i18n( count( $costgator_below ) ) ); ?></span>
		<?php if ( $costgator_below ) : ?>
			<a class="costgator-overview-label" href="#costgator-below"><?php esc_html_e( 'Selling below cost', 'costgator' ); ?></a>
		<?php else : ?>
			<span class="costgator-overview-label"><?php esc_html_e( 'Selling below cost', 'costgator' ); ?></span>
		<?php endif; ?>
	</p>
	<p class="costgator-overview-loss">
		<?php
		if ( $costgator_below ) {
			$costgator_largest_loss( $costgator_below[0], 'price' );
		} elseif ( $costgator_known ) {
			esc_html_e( 'No product with a known cost is selling below cost at its current price.', 'costgator' );
		} else {
			esc_html_e( 'No product has a known cost, so profitability could not be checked.', 'costgator' );
		}
		?>
	</p>
	<p class="costgator-overview-meta">
		<?php
		printf(
			/* translators: 1: number of products checked, 2: number of products with a known cost */
			esc_html( _n( '%1$s product checked, %2$s with a known cost.', '%1$s products checked, %2$s with a known cost.', count( $scan['items'] ), 'costgator' ) ),
			esc_html( number_format_i18n( count( $scan['items'] ) ) ),
			esc_html( number_format_i18n( $costgator_known ) )
		);
		if ( ! empty( $scan['skipped_no_price'] ) ) {
			echo ' ';
			/* translators: %s: number of products and variations without a price */
			printf( esc_html__( '%s products or variations without a price were skipped.', 'costgator' ), esc_html( number_format_i18n( $scan['skipped_no_price'] ) ) );
		}
		?>
	</p>
	<?php if ( $costgator_known ) : ?>
		<a class="button" href="#costgator-preflight"><?php esc_html_e( 'Test a sale before launch', 'costgator' ); ?></a>
	<?php endif; ?>
</div>
