<?php
/**
 * Promotion Preflight result table, included from admin-page.php.
 *
 * @package CostGator
 *
 * @var array[] $costgator_pf_rows Affected products, worst first.
 */

use CostGator\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! $costgator_pf_rows ) {
	return;
}
?>
<table class="widefat striped costgator-table">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Product', 'costgator' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Promo net price', 'costgator' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Cost', 'costgator' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Margin', 'costgator' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( array_slice( $costgator_pf_rows, 0, Admin::ROW_LIMIT ) as $costgator_row ) : ?>
			<?php $costgator_row['item_id'] = $costgator_row['id']; ?>
			<tr class="<?php echo $costgator_row['new_price'] < $costgator_row['cost'] ? 'costgator-row-critical' : ''; ?>">
				<td><?php echo Admin::product_link( $costgator_row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in product_link(). ?></td>
				<td class="num" data-label="<?php esc_attr_e( 'Promo net price', 'costgator' ); ?>"><?php echo wp_kses_post( Admin::money( $costgator_row['new_price'], $scan ) ); ?></td>
				<td class="num" data-label="<?php esc_attr_e( 'Cost', 'costgator' ); ?>"><?php echo wp_kses_post( Admin::money( $costgator_row['cost'], $scan ) ); ?></td>
				<td class="num" data-label="<?php esc_attr_e( 'Margin', 'costgator' ); ?>"><?php echo esc_html( Admin::format_margin( $costgator_row['new_margin'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php if ( count( $costgator_pf_rows ) > Admin::ROW_LIMIT ) : ?>
	<p class="description">
		<?php
		/* translators: %s: number of rows not shown */
		printf( esc_html__( 'Not shown: %s more.', 'costgator' ), esc_html( number_format_i18n( count( $costgator_pf_rows ) - Admin::ROW_LIMIT ) ) );
		?>
	</p>
<?php endif; ?>
