<?php
/**
 * WP Performance Engine Uninstall Script
 *
 * This file is run automatically when the user uninstalls the plugin.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Clear scheduled cron tasks.
wp_clear_scheduled_hook( 'wppe_gc_cron' );
wp_clear_scheduled_hook( 'wppe_cf_queue_cron' );

// Helper to remove directory recursively.
function wppe_rmdir_recursive( string $dir ): void {
	if ( ! file_exists( $dir ) ) {
		return;
	}

	$it = new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS );
	$files = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getRealPath() );
		} else {
			unlink( $file->getRealPath() );
		}
	}
	rmdir( $dir );
}

// 2. Remove all cached HTML files and asset directories.
$cache_dir = WP_CONTENT_DIR . '/cache/wp-performance-engine';
wppe_rmdir_recursive( $cache_dir );

// 3. Remove plugin logs directory.
$log_dir = WP_CONTENT_DIR . '/uploads/wp-performance-engine';
wppe_rmdir_recursive( $log_dir );

// 4. Remove all database options, transients, and variables.
if ( is_multisite() ) {
	global $wpdb;
	// Get all network blogs.
	$blogs = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
	foreach ( $blogs as $blog_id ) {
		switch_to_blog( $blog_id );

		// Delete options.
		delete_option( 'wppe_settings' );
		delete_option( 'wppe_telemetry_data' );
		delete_option( 'wppe_cf_purge_queue' );

		// Delete transients.
		delete_transient( 'wppe_failsafe_triggered' );
		delete_transient( 'wppe_error_count' );
		delete_transient( 'wppe_db_metrics_cache' );
		delete_transient( 'wppe_script_count_frontend' );
		delete_transient( 'wppe_style_count_frontend' );
		delete_transient( 'wppe_html_size_frontend' );
		delete_transient( 'wppe_image_count_frontend' );
		delete_transient( 'wppe_iframe_count_frontend' );

		restore_current_blog();
	}
} else {
	// Delete options.
	delete_option( 'wppe_settings' );
	delete_option( 'wppe_telemetry_data' );
	delete_option( 'wppe_cf_purge_queue' );

	// Delete transients.
	delete_transient( 'wppe_failsafe_triggered' );
	delete_transient( 'wppe_error_count' );
	delete_transient( 'wppe_db_metrics_cache' );
	delete_transient( 'wppe_script_count_frontend' );
	delete_transient( 'wppe_style_count_frontend' );
	delete_transient( 'wppe_html_size_frontend' );
	delete_transient( 'wppe_image_count_frontend' );
	delete_transient( 'wppe_iframe_count_frontend' );
}
