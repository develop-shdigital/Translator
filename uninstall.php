<?php
/**
 * Uninstall: remove data only when the site owner asked for it.
 *
 * @package SHDT
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove data of the current site.
 */
function shdt_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'shdt_settings', array() );
	if ( empty( $settings['delete_data'] ) ) {
		wp_clear_scheduled_hook( 'shdt_process_queue' );
		return;
	}

	$table = $wpdb->prefix . 'shdt_strings';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

	foreach ( array( 'shdt_settings', 'shdt_db_version', 'shdt_last_error' ) as $option ) {
		delete_option( $option );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_shdt\_%' OR option_name LIKE '\_transient\_timeout\_shdt\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_clear_scheduled_hook( 'shdt_process_queue' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 1000 ) ) as $shdt_site ) {
		switch_to_blog( $shdt_site );
		shdt_uninstall_site();
		restore_current_blog();
	}
} else {
	shdt_uninstall_site();
}
