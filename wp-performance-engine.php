<?php
/**
 * Plugin Name: WP Performance Engine
 * Plugin URI: https://member.hipnolink.com
 * Description: Adaptive WordPress Performance & Optimization Engine with Elementor & Pro Elements optimizer, intelligent arbitration, HTML caching, script delay, speculation rules, and Cloudflare integration.
 * Version: 1.3.0
 * Author: Hipnolink Digital Team
 * Author URI: https://hipnolink.com
 * License: GPL2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-performance-engine
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Composer Autoloader if it exists.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Initialize GitHub Updater.
if ( class_exists( 'Soderlind\WordPress\GitHubUpdater' ) ) {
	\Soderlind\WordPress\GitHubUpdater::init(
		github_url:   'https://github.com/muzzafah-stack/wp-performance-engine', // Ganti 'username' dengan username GitHub Anda
		plugin_file:  __FILE__,
		plugin_slug:  'wp-performance-engine',
		branch:       'main',
	);
}


// Define plugin constants.
define( 'WPPE_VERSION', '1.3.0' );
define( 'WPPE_PLUGIN_FILE', __FILE__ );
define( 'WPPE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPPE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Autoloader.
require_once WPPE_PLUGIN_DIR . 'includes/Core/Autoloader.php';

// Initialize Autoloader.
\WPPE\Core\Autoloader::register();

// Register activation and deactivation hooks.
register_activation_hook( __FILE__, array( '\WPPE\Core\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\WPPE\Core\Plugin', 'deactivate' ) );

// Bootstrap the plugin.
add_action( 'plugins_loaded', array( '\WPPE\Core\Plugin', 'get_instance' ) );
