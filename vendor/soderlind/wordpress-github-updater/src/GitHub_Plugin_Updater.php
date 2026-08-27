<?php
/**
 * Backward-compatible wrapper for GitHubUpdater
 *
 * @package Soderlind\WordPress
 * @link    https://github.com/soderlind/wordpress-plugin-github-updater
 * @version 2.0.1
 * @author  Per Soderlind
 * @license GPL-2.0-or-later
 */

namespace Soderlind\WordPress;

defined( 'ABSPATH' ) || exit;

// Prevent conflicts when multiple plugins bundle this library.
if ( class_exists( GitHub_Plugin_Updater::class) ) {
	return;
}

/**
 * Backwards-compatible wrapper around GitHubUpdater::init().
 *
 * @deprecated Use GitHubUpdater::init() directly.
 */
class GitHub_Plugin_Updater {

	/**
	 * GitHub repository URL.
	 *
	 * @var string
	 */
	private $github_url;

	/**
	 * Branch to check for updates.
	 *
	 * @var string
	 */
	private $branch;

	/**
	 * Regex pattern to match the plugin zip file name.
	 *
	 * @var string
	 */
	private $name_regex;

	/**
	 * The plugin slug.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * The main plugin file path.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Whether to enable release assets.
	 *
	 * @var bool
	 */
	private $enable_release_assets;

	/**
	 * Hours between update checks.
	 *
	 * @var int
	 */
	private $check_period;

	/**
	 * GitHub authentication token.
	 *
	 * @var string
	 */
	private $auth_token;

	/**
	 * Constructor
	 *
	 * @param array $config Configuration array with the following keys:
	 *                      - github_url: GitHub repository URL (required)
	 *                      - plugin_file: Main plugin file path (required)
	 *                      - plugin_slug: Plugin slug for updates (required)
	 *                      - branch: Branch to check for updates (default: 'main')
	 *                      - name_regex: Regex pattern for zip file name (optional)
	 *                      - enable_release_assets: Whether to enable release assets (default: true if name_regex provided)
	 *                      - check_period: Hours between update checks (default: 6)
	 *                      - auth_token: GitHub personal access token (optional)
	 *
	 * @throws \InvalidArgumentException If required parameters are missing.
	 */
	public function __construct( $config = array() ) {
		// Validate required parameters.
		$required = array( 'github_url', 'plugin_file', 'plugin_slug' );
		foreach ( $required as $key ) {
			if ( empty( $config[ $key ] ) ) {
				throw new \InvalidArgumentException( "Required parameter '{$key}' is missing or empty." );
			}
		}

		$this->github_url            = (string) $config[ 'github_url' ];
		$this->plugin_file           = (string) $config[ 'plugin_file' ];
		$this->plugin_slug           = (string) $config[ 'plugin_slug' ];
		$this->branch                = isset( $config[ 'branch' ] ) ? (string) $config[ 'branch' ] : 'main';
		$this->name_regex            = isset( $config[ 'name_regex' ] ) ? (string) $config[ 'name_regex' ] : '';
		$this->check_period          = isset( $config[ 'check_period' ] ) ? (int) $config[ 'check_period' ] : 6;
		$this->auth_token            = isset( $config[ 'auth_token' ] ) ? (string) $config[ 'auth_token' ] : '';
		$this->enable_release_assets = isset( $config[ 'enable_release_assets' ] )
			? (bool) $config[ 'enable_release_assets' ]
			: ! empty( $this->name_regex );

		if ( ! $this->enable_release_assets ) {
			$this->name_regex = '';
		}

		GitHubUpdater::init(
			$this->github_url,
			$this->plugin_file,
			$this->plugin_slug,
			$this->name_regex,
			$this->branch,
			$this->check_period,
			$this->auth_token
		);
	}

	/**
	 * Initialize the update checker via the canonical static API.
	 *
	 * @deprecated Use GitHubUpdater::init() directly.
	 *
	 * @param string $github_url   Full GitHub repository URL.
	 * @param string $plugin_file  Absolute path to the main plugin file.
	 * @param string $plugin_slug  Plugin slug used by WordPress.
	 * @param string $name_regex   Optional regex to filter release assets.
	 * @param string $branch       Branch to track.
	 * @param int    $check_period Hours between update checks.
	 * @param string $auth_token   Optional GitHub authentication token.
	 */
	public static function init(
		$github_url,
		$plugin_file,
		$plugin_slug,
		$name_regex = '',
		$branch = 'main',
		$check_period = 6,
		$auth_token = ''
	) {
		GitHubUpdater::init(
			(string) $github_url,
			(string) $plugin_file,
			(string) $plugin_slug,
			(string) $name_regex,
			(string) $branch,
			(int) $check_period,
			(string) $auth_token
		);
	}

	/**
	 * Create updater instance with minimal configuration
	 *
	 * @param string $github_url   GitHub repository URL.
	 * @param string $plugin_file  Main plugin file path.
	 * @param string $plugin_slug  Plugin slug.
	 * @param string $branch       Branch name (default: 'main').
	 * @param int    $check_period Hours between checks (default: 6).
	 * @param string $auth_token   Optional GitHub token.
	 *
	 * @return GitHub_Plugin_Updater
	 */
	public static function create(
		$github_url,
		$plugin_file,
		$plugin_slug,
		$branch = 'main',
		$check_period = 6,
		$auth_token = ''
	) {
		return new self(
			array(
				'github_url'   => $github_url,
				'plugin_file'  => $plugin_file,
				'plugin_slug'  => $plugin_slug,
				'branch'       => $branch,
				'check_period' => $check_period,
				'auth_token'   => $auth_token,
			)
		);
	}

	/**
	 * Create updater instance for plugins with release assets
	 *
	 * @param string $github_url   GitHub repository URL.
	 * @param string $plugin_file  Main plugin file path.
	 * @param string $plugin_slug  Plugin slug.
	 * @param string $name_regex   Regex pattern for release assets.
	 * @param string $branch       Branch name (default: 'main').
	 * @param int    $check_period Hours between checks (default: 6).
	 * @param string $auth_token   Optional GitHub token.
	 *
	 * @return GitHub_Plugin_Updater
	 */
	public static function create_with_assets(
		$github_url,
		$plugin_file,
		$plugin_slug,
		$name_regex,
		$branch = 'main',
		$check_period = 6,
		$auth_token = ''
	) {
		return new self(
			array(
				'github_url'   => $github_url,
				'plugin_file'  => $plugin_file,
				'plugin_slug'  => $plugin_slug,
				'branch'       => $branch,
				'name_regex'   => $name_regex,
				'check_period' => $check_period,
				'auth_token'   => $auth_token,
			)
		);
	}
}
