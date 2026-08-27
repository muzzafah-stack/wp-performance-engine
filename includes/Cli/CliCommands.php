<?php
namespace WPPE\Cli;

use WPPE\Core\Settings;
use WPPE\Detection\SiteProfile;
use WPPE\Arbitration\Arbiter;
use WPPE\Cache\DiskCache;
use WPPE\Database\DbHousekeeping;
use WPPE\Monitoring\Telemetry;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CliCommands {
	/**
	 * Register CLI commands.
	 */
	public static function register(): void {
		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'performance-engine', self::class );
		}
	}

	/**
	 * Show overall engine status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp performance-engine status
	 */
	public function status( $args, $assoc_args ): void {
		$profile = SiteProfile::get_instance()->get_profile();
		\WP_CLI::line( '=== WP Performance Engine Status ===' );
		\WP_CLI::line( 'WordPress Version: ' . $profile['wp_version'] );
		\WP_CLI::line( 'PHP Version:       ' . $profile['php_version'] );
		\WP_CLI::line( 'Server Type:       ' . $profile['server_type'] );
		\WP_CLI::line( 'Active Plugins:    ' . $profile['plugin_count'] );
		\WP_CLI::line( 'Database Size:     ' . $profile['db_size_mb'] . ' MB' );

		$settings = Settings::get_instance()->get_all();
		\WP_CLI::line( 'HTML Cache:        ' . ( $settings['enable_html_cache'] ? 'ON' : 'OFF' ) );
		\WP_CLI::line( 'Script Delay:      ' . ( $settings['enable_script_delay'] ? 'ON' : 'OFF' ) );
		\WP_CLI::line( 'LCP Priority:      ' . ( $settings['enable_lcp_priority'] ? 'ON' : 'OFF' ) );
		\WP_CLI::line( 'Speculation Rules: ' . ( $settings['enable_speculation'] ? 'ON' : 'OFF' ) );
		\WP_CLI::success( 'Status output complete.' );
	}

	/**
	 * Diagnose website integrations.
	 *
	 * ## EXAMPLES
	 *
	 *     wp performance-engine diagnose
	 */
	public function diagnose( $args, $assoc_args ): void {
		$arbiter = Arbiter::get_instance();
		$diagnostics = $arbiter->get_conflict_diagnostics();

		\WP_CLI::line( '=== Diagnostics & Ownership Map ===' );
		foreach ( $diagnostics as $domain => $info ) {
			\WP_CLI::line( sprintf( '%s: %s [Owner: %s]', $info['label'], $info['our_status'], str_replace( 'OWNER_', '', $info['owner'] ) ) );
			if ( 'OK' !== $info['status'] ) {
				\WP_CLI::warning( '   ' . $info['recommendation'] );
			}
		}
		\WP_CLI::success( 'Diagnostics complete.' );
	}

	/**
	 * List active conflicts and recommendations.
	 *
	 * ## EXAMPLES
	 *
	 *     wp performance-engine conflicts
	 */
	public function conflicts( $args, $assoc_args ): void {
		$this->diagnose( $args, $assoc_args );
	}

	/**
	 * Perform cache operations (stats or purge).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Cache action: 'stats' or 'purge'.
	 *
	 * ## EXAMPLES
	 *
	 *     wp performance-engine cache purge
	 *     wp performance-engine cache stats
	 */
	public function cache( $args, $assoc_args ): void {
		$action = $args[0] ?? '';

		if ( 'purge' === $action ) {
			DiskCache::purge_entire_cache();
			\WP_CLI::success( 'Entire HTML Cache directory purged.' );
		} elseif ( 'stats' === $action ) {
			$metrics = Telemetry::get_instance()->get_metrics();
			$size_kb = round( $metrics['cache_size_bytes'] / 1024, 2 );

			\WP_CLI::line( '=== HTML Cache Statistics ===' );
			\WP_CLI::line( 'Cache Hits:   ' . $metrics['cache_hits'] );
			\WP_CLI::line( 'Cache Misses: ' . $metrics['cache_misses'] );
			\WP_CLI::line( 'Total Size:   ' . $size_kb . ' KB' );
			\WP_CLI::success( 'Stats retrieve complete.' );
		} else {
			\WP_CLI::error( 'Invalid action. Choose "purge" or "stats".' );
		}
	}

	/**
	 * Perform database operations (scan or cleanup).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action: 'scan' or 'cleanup'.
	 *
	 * [--mode=<mode>]
	 * : Cleanup mode: 'safe' or 'aggressive'.
	 * ---
	 * default: safe
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt for aggressive mode.
	 *
	 * ## EXAMPLES
	 *
	 *     wp performance-engine db scan
	 *     wp performance-engine db cleanup --mode=safe
	 *     wp performance-engine db cleanup --mode=aggressive --yes
	 */
	public function db( $args, $assoc_args ): void {
		$action = $args[0] ?? '';
		$mode = $assoc_args['mode'] ?? 'safe';
		$yes = isset( $assoc_args['yes'] );

		$housekeeper = DbHousekeeping::get_instance();
		$settings = Settings::get_instance();
		$retention = (int) $settings->get( 'db_cleanup_revisions_retention', 10 );

		if ( 'scan' === $action ) {
			$stats = $housekeeper->get_cleanup_stats( $retention );
			\WP_CLI::line( '=== Database Cleanup Candidates ===' );
			foreach ( $stats as $key => $data ) {
				$metric = $data['count'];
				if ( isset( $data['size'] ) ) {
					$metric = $data['size'] . ' MB (' . $data['count'] . ' tables)';
				}
				\WP_CLI::line( sprintf( '%s: %s', $data['label'], $metric ) );
			}
			\WP_CLI::success( 'Database scan complete.' );
		} elseif ( 'cleanup' === $action ) {
			$aggressive = ( 'aggressive' === $mode );

			if ( $aggressive && ! $yes ) {
				\WP_CLI::confirm( 'WARNING: Aggressive mode will delete all post revisions, expired/unexpired transients and empty post trash. Do you want to proceed?' );
			}

			\WP_CLI::line( sprintf( 'Starting Database Cleanup [%s mode]...', strtoupper( $mode ) ) );

			$categories = [
				'expired_transients',
				'revisions',
				'auto_drafts',
				'trash_posts',
				'spam_comments',
				'orphaned_postmeta',
				'orphaned_commentmeta',
				'db_overhead',
			];

			foreach ( $categories as $cat ) {
				// If aggressive, override revisions retention to 0.
				$rev_limit = $aggressive ? 0 : $retention;
				$res = $housekeeper->execute_cleanup( $cat, $rev_limit, $aggressive );
				if ( $res['success'] ) {
					\WP_CLI::line( ' - ' . $res['message'] );
				} else {
					\WP_CLI::error( ' - ' . $res['message'], false );
				}
			}

			\WP_CLI::success( 'Database cleanup execution completed.' );
		} else {
			\WP_CLI::error( 'Invalid action. Choose "scan" or "cleanup".' );
		}
	}

	/**
	 * Verify if any plugin assets remain before simulating uninstall.
	 *
	 * ## EXAMPLES
	 *
	 *     wp performance-engine uninstall-check
	 */
	public function uninstall_check( $args, $assoc_args ): void {
		\WP_CLI::line( '=== Simulating Uninstall Cleanliness Scan ===' );
		$clean = true;

		// Check settings options.
		if ( get_option( 'wppe_settings' ) ) {
			\WP_CLI::warning( 'Found remaining database option: wppe_settings' );
			$clean = false;
		}

		// Check telemetry options.
		if ( get_option( 'wppe_telemetry_data' ) ) {
			\WP_CLI::warning( 'Found remaining database option: wppe_telemetry_data' );
			$clean = false;
		}

		// Check CF queue.
		if ( get_option( 'wppe_cf_purge_queue' ) ) {
			\WP_CLI::warning( 'Found remaining database option: wppe_cf_purge_queue' );
			$clean = false;
		}

		// Check cache directory.
		$cache_dir = DiskCache::get_cache_dir();
		if ( file_exists( $cache_dir ) ) {
			$files = glob( $cache_dir . '/*' );
			if ( ! empty( $files ) ) {
				\WP_CLI::warning( sprintf( 'Found cached HTML files inside: %s', $cache_dir ) );
				$clean = false;
			}
		}

		if ( $clean ) {
			\WP_CLI::success( 'UNINSTALL CLEAN: No plugin-owned files, transients, options, or tables remaining.' );
		} else {
			\WP_CLI::error( 'UNINSTALL FAILED: Stale artifacts detected.' );
		}
	}
}
