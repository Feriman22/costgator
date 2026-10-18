<?php
/**
 * Removes plugin data on uninstall.
 *
 * @package CostGator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin options of the current site.
 */
function costgator_delete_site_data() {
	delete_option( 'costgator_settings' );
	delete_option( 'costgator_last_scan' );
	delete_option( 'costgator_scan_running' );

	delete_option( 'costgator_scan_ids' );

	// Delete result chunks through delete_option() so persistent object caches are cleared too.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options have no API to list names by prefix.
	$chunks = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'costgator_chunk_' ) . '%' ) );
	foreach ( $chunks as $chunk ) {
		delete_option( $chunk );
	}
}

// On multisite the plugin may have been used on any site of the network, so every site is cleaned.
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $costgator_site_id ) {
		switch_to_blog( $costgator_site_id );
		costgator_delete_site_data();
		restore_current_blog();
	}
} else {
	costgator_delete_site_data();
}
