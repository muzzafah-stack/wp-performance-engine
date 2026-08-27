<?php
/**
 * GitHub Updater for WordPress Plugins
 *
 * @package Soderlind\WordPress
 * @link    https://github.com/soderlind/wordpress-plugin-github-updater
 * @version 2.0.1
 * @author  Per Soderlind
 * @license GPL-2.0-or-later
 */

namespace Soderlind\WordPress;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

// Prevent conflicts when multiple plugins bundle this library.
if ( class_exists( GitHubUpdater::class) ) {
	return;
}

/**
 * Generic WordPress Plugin GitHub Updater
 *
 * A reusable class for handling WordPress plugin updates from GitHub repositories
 * using the plugin-update-checker library. Includes built-in rate-limit mitigation
 * via configurable check intervals, throttling, and optional token authentication.
 */
class GitHubUpdater {

	/**
	 * Initialize the GitHub update checker.
	 *
	 * @param string $github_url   Full GitHub repository URL.
	 * @param string $plugin_file  Absolute path to the main plugin file.
	 * @param string $plugin_slug  Plugin slug used by WordPress.
	 * @param string $name_regex   Optional regex to filter release assets.
	 * @param string $branch       Branch to track.
	 * @param int    $check_period Hours between update checks (default 6). Set to 0 to disable automatic checks.
	 * @param string $auth_token   Optional GitHub personal access token for private repos or higher rate limits.
	 */
	public static function init(
		string $github_url,
		string $plugin_file,
		string $plugin_slug,
		string $name_regex = '',
		string $branch = 'main',
		int $check_period = 6,
		string $auth_token = ''
	): void {
		add_action(
			'init',
			static function () use ($github_url, $plugin_file, $plugin_slug, $name_regex, $branch, $check_period, $auth_token): void {
				try {
					if ( ! class_exists( PucFactory::class) ) {
						throw new \RuntimeException( 'Missing dependency yahnis-elsts/plugin-update-checker. Run composer install --no-dev.' );
					}

					// Build the update checker with custom check period.
					$checker = PucFactory::buildUpdateChecker(
						$github_url,
						$plugin_file,
						$plugin_slug,
						$check_period // Hours between checks.
					);

					$checker->setBranch( $branch );

					// Enable throttling: check less frequently when an update is already known.
					// This reduces GitHub API calls significantly for plugins with known updates.
					if ( isset( $checker->scheduler ) ) {
						$checker->scheduler->throttleRedundantChecks = true;
						$checker->scheduler->throttledCheckPeriod    = 72; // Hours when update is known.
					}

					// Set GitHub authentication token if provided.
					// Useful for private repos or to increase rate limit from 60 to 5000 requests/hour.
					if ( '' !== $auth_token ) {
						$checker->setAuthentication( $auth_token );
					}

					// Enable release assets if regex provided.
					if ( '' !== $name_regex ) {
						$checker->getVcsApi()->enableReleaseAssets( $name_regex );
					}

					// Log API errors when WP_DEBUG is enabled.
					add_action(
						'puc_api_error',
						static function ( $error, $response, $url, $slug ) use ( $plugin_slug ): void {
						if ( $slug !== $plugin_slug ) {
							return;
						}
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							$message = is_wp_error( $error ) ? $error->get_error_message() : 'Unknown API error';
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							error_log( sprintf( 'GitHubUpdater (%s): API error - %s (URL: %s)', $plugin_slug, $message, $url ) );
						}
					},
						10,
						4
					);
				} catch (\Throwable $e) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'GitHubUpdater (' . $plugin_slug . '): ' . $e->getMessage() );
					}
				}
			}
		);
	}
}
