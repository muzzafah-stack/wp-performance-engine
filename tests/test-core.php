<?php
namespace WPPE\Core {
	class Plugin {
		private static ?Plugin $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function get_service( $key ) {
			if ( 'elementor' === $key ) {
				return \WPPE\Elementor\ElementorCompat::get_instance();
			}
			return null;
		}
	}
}

namespace WPPE\Elementor {
	class ElementorCompat {
		private static ?ElementorCompat $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function is_editor_or_preview(): bool {
			return false;
		}
	}
}

namespace {
	// Define standard mocks.
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'WP_CONTENT_DIR', dirname( __DIR__ ) . '/wp-content' );
	define( 'HOUR_IN_SECONDS', 3600 );

	function wp_mkdir_p( $target ) {
		if ( file_exists( $target ) ) {
			return true;
		}
		return mkdir( $target, 0777, true );
	}

	function get_option( $option, $default = false ) {
		return $default;
	}
	function update_option( $option, $value, $autoload = null ) {
		return true;
	}
	function esc_url( $url ) {
		return $url;
	}
	function esc_html( $text ) {
		return $text;
	}
	function wp_json_encode( $data, $options = 0 ) {
		return json_encode( $data, $options );
	}
	function is_ssl() {
		return true;
	}
	function is_admin() {
		return false;
	}
	function current_time( $format ) {
		return date( 'Y-m-d H:i:s' );
	}
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
	function wp_parse_url( $url ) {
		return parse_url( $url );
	}
	function sanitize_text_field( $str ) {
		return $str;
	}
	function sanitize_textarea_field( $str ) {
		return $str;
	}
	function get_transient( $transient ) {
		if ( 'wppe_db_metrics_cache' === $transient ) {
			return [
				'db_size_mb'  => 4.2,
				'revisions'   => 12,
				'transients'  => 5,
			];
		}
		return false;
	}

	function set_transient( $transient, $value, $expiration = 0 ) {
		return true;
	}
	function is_multisite() {
		return false;
	}
	function wp_using_ext_object_cache() {
		return false;
	}
	function _get_cron_array() {
		return [];
	}
	function get_bloginfo( $show = '' ) {
		return '6.5';
	}
	function get_stylesheet() {
		return 'twentytwentyfour';
	}

	// Load classes manually.
	require_once dirname( __DIR__ ) . '/includes/Security/SecurityHelper.php';
require_once dirname( __DIR__ ) . '/includes/Core/Settings.php';
require_once dirname( __DIR__ ) . '/includes/Core/Logger.php';
require_once dirname( __DIR__ ) . '/includes/Core/Failsafe.php';
require_once dirname( __DIR__ ) . '/includes/Detection/SiteProfile.php';
require_once dirname( __DIR__ ) . '/includes/Arbitration/Arbiter.php';
require_once dirname( __DIR__ ) . '/includes/Cache/DiskCache.php';
require_once dirname( __DIR__ ) . '/includes/Script/ScriptDelay.php';
require_once dirname( __DIR__ ) . '/includes/LCP/LCPPriority.php';

use WPPE\Security\SecurityHelper;
use WPPE\Cache\DiskCache;
use WPPE\Script\ScriptDelay;
use WPPE\LCP\LCPPriority;

// Simple testing helper.
function assert_test( $name, $assertion ) {
	if ( $assertion ) {
		echo "\033[32m[PASS]\033[0m " . $name . "\n";
	} else {
		echo "\033[31m[FAIL]\033[0m " . $name . "\n";
		exit( 1 );
	}
}

echo "=== Running WPPE Core Logic Tests ===\n";

// Test 1: SecurityHelper Encryption
$original = "my-cloudflare-token-12345";
$encrypted = SecurityHelper::encrypt( $original );
$decrypted = SecurityHelper::decrypt( $encrypted );
assert_test( 'Security Encryption/Decryption Integrity', $decrypted === $original );

// Test 2: URL normalization & Cache Keys
$cache = DiskCache::get_instance();
$key1 = $cache->get_cache_key( 'https://example.com/blog/?utm_source=fb&fbclid=abc123' );
$key2 = $cache->get_cache_key( 'https://example.com/blog/' );
assert_test( 'Cache Key URL Normalization (Filtering track parameters)', $key1 === $key2 );

// Test 3: Script Classification
$script_delay = ScriptDelay::get_instance();
assert_test( 'Script Classification (Critical jQuery)', $script_delay->classify_script( 'jquery.min.js' ) === ScriptDelay::CLASSIFICATION_CRITICAL );
assert_test( 'Script Classification (Analytics)', $script_delay->classify_script( 'google-analytics.com/analytics.js' ) === ScriptDelay::CLASSIFICATION_THIRD_PARTY );

// Test 4: LCP Priority Injection
$lcp = LCPPriority::get_instance();
\WPPE\Core\Settings::get_instance()->set( 'enable_lcp_priority', true );
$lcp->maybe_start_buffer();
$html_input = '<html><body><h1>My Hero</h1><img src="hero.jpg" class="hero-image" loading="lazy" /></body></html>';
$html_output = $lcp->optimize_lcp_in_html( $html_input );
assert_test( 'LCP Priority (fetchpriority="high" injection)', false !== strpos( $html_output, 'fetchpriority="high"' ) );
assert_test( 'LCP Priority (loading="lazy" stripping)', false === strpos( $html_output, 'loading="lazy"' ) );
// Test 5: Logger Rotation/Pruning
$log_dir = WP_CONTENT_DIR . '/uploads/wp-performance-engine/logs';
if ( ! file_exists( $log_dir ) ) {
	wp_mkdir_p( $log_dir );
}
$log_file = $log_dir . '/debug.log';

// Setup mock log content with some old and new lines
$old_time = date( 'Y-m-d H:i:s', time() - 30 * 3600 ); // 30 hours ago
$recent_time = date( 'Y-m-d H:i:s', time() - 5 * 3600 ); // 5 hours ago

$mock_content = sprintf(
	"[%s] [INFO] Old entry to be pruned\n[%s] [WARNING] Recent entry to keep\n",
	$old_time,
	$recent_time
);
file_put_contents( $log_file, $mock_content );

// Trigger Logger log, which should prune because get_transient returns false in mocks
\WPPE\Core\Logger::info( 'New test log entry' );

$pruned_content = file_get_contents( $log_file );
assert_test( 'Logger Pruning: Old entries (>24h) removed', false === strpos( $pruned_content, 'Old entry to be pruned' ) );
assert_test( 'Logger Pruning: Recent entries (<24h) kept', false !== strpos( $pruned_content, 'Recent entry to keep' ) );
assert_test( 'Logger Pruning: New entry appended', false !== strpos( $pruned_content, 'New test log entry' ) );

// Cleanup
unlink( $log_file );
rmdir( $log_dir );
rmdir( dirname( $log_dir ) );
rmdir( dirname( dirname( $log_dir ) ) );


echo "=== All Tests Passed Successfully ===\n";
exit( 0 );
}
