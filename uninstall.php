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

	// Always: engine pauses, error records and caches. A reinstall starts clean
	// instead of inheriting a pause or an old error.
	foreach ( array( 'shdt_last_error', 'shdt_engine_health', 'shdt_openai_format' ) as $option ) {
		delete_option( $option );
	}
	$settings = get_option( 'shdt_settings', array() );
	// Named deletes too: with a persistent object cache transients are not in the options table.
	$langs = array( '' );
	foreach ( isset( $settings['languages'] ) && is_array( $settings['languages'] ) ? $settings['languages'] : array() as $language ) {
		if ( is_array( $language ) && ! empty( $language['code'] ) ) {
			$langs[] = '_' . strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $language['code'] ) );
		}
	}
	foreach ( array( 'google', 'anthropic', 'deepl', 'openai', 'libretranslate', 'mymemory' ) as $engine ) {
		foreach ( $langs as $suffix ) {
			delete_transient( 'shdt_pause_' . $engine . $suffix );
		}
	}
	foreach ( array( 'shdt_queue_lock', 'shdt_fallback_counts', 'shdt_queue_check', 'shdt_key_dropped', 'shdt_invalid_selectors', 'shdt_activation_redirect', 'shdt_engine_switch', 'shdt_base_invalid' ) as $transient ) {
		delete_transient( $transient );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_shdt\_%' OR option_name LIKE '\_transient\_timeout\_shdt\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_clear_scheduled_hook( 'shdt_process_queue' );

	// Translations and settings only when the site owner asked for it.
	if ( empty( $settings['delete_data'] ) ) {
		return;
	}

	$table = $wpdb->prefix . 'shdt_strings';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

	foreach ( array( 'shdt_settings', 'shdt_db_version', 'shdt_settings_saved', 'shdt_retranslate_dismissed' ) as $option ) {
		delete_option( $option );
	}
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
