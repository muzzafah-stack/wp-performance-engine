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

namespace {
	// Define standard mocks.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		define( 'WP_CONTENT_DIR', dirname( __DIR__ ) . '/wp-content' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! defined( 'PRO_ELEMENTS_VERSION' ) ) {
		define( 'PRO_ELEMENTS_VERSION', '3.19.0' );
	}
	if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
		define( 'ELEMENTOR_VERSION', '3.20.0' );
	}

	function wp_mkdir_p( $target ) {
		if ( file_exists( $target ) ) {
			return true;
		}
		return mkdir( $target, 0777, true );
	}

	function get_option( $option, $default = false ) {
		if ( 'active_plugins' === $option ) {
			return [ 'elementor/elementor.php', 'pro-elements/pro-elements.php' ];
		}
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
	function __( $text, $domain = 'default' ) {
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
	function add_query_arg( $key, $val, $url ) {
		$sep = ( false !== strpos( $url, '?' ) ) ? '&' : '?';
		return $url . $sep . $key . '=' . $val;
	}
	function is_singular() {
		return true;
	}
	function get_the_ID() {
		return 42;
	}
	function get_post_meta( $post_id, $key, $single = false ) {
		if ( '_elementor_data' === $key ) {
			return '[{"id":"123","elType":"widget","widgetType":"heading"},{"id":"456","elType":"widget","widgetType":"nav-menu"}]';
		}
		return '';
	}
	function wp_script_is( $handle, $list = 'enqueued' ) {
		return true;
	}
	function wp_script_add_data( $handle, $key, $val ) {
		return true;
	}
	function wp_dequeue_script( $handle ) {
		return true;
	}
	function wp_deregister_script( $handle ) {
		return true;
	}
	function wp_dequeue_style( $handle ) {
		return true;
	}
	function wp_deregister_style( $handle ) {
		return true;
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

	// Load classes.
	require_once dirname( __DIR__ ) . '/includes/Security/SecurityHelper.php';
	require_once dirname( __DIR__ ) . '/includes/Core/Settings.php';
	require_once dirname( __DIR__ ) . '/includes/Core/Logger.php';
	require_once dirname( __DIR__ ) . '/includes/Core/Failsafe.php';
	require_once dirname( __DIR__ ) . '/includes/Detection/SiteProfile.php';
	require_once dirname( __DIR__ ) . '/includes/Arbitration/Arbiter.php';
	require_once dirname( __DIR__ ) . '/includes/Cache/DiskCache.php';
	require_once dirname( __DIR__ ) . '/includes/Script/ScriptDelay.php';
	require_once dirname( __DIR__ ) . '/includes/LCP/LCPPriority.php';
	require_once dirname( __DIR__ ) . '/includes/Elementor/ElementorAssetOptimizer.php';
	require_once dirname( __DIR__ ) . '/includes/Elementor/ElementorDomOptimizer.php';
	require_once dirname( __DIR__ ) . '/includes/Elementor/ElementorScriptOptimizer.php';
	require_once dirname( __DIR__ ) . '/includes/Elementor/ElementorCompat.php';

	use WPPE\Security\SecurityHelper;
	use WPPE\Cache\DiskCache;
	use WPPE\Script\ScriptDelay;
	use WPPE\LCP\LCPPriority;
	use WPPE\Detection\SiteProfile;
	use WPPE\Elementor\ElementorCompat;
	use WPPE\Elementor\ElementorAssetOptimizer;
	use WPPE\Elementor\ElementorDomOptimizer;
	use WPPE\Elementor\ElementorScriptOptimizer;

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

	$old_time = date( 'Y-m-d H:i:s', time() - 30 * 3600 );
	$recent_time = date( 'Y-m-d H:i:s', time() - 5 * 3600 );

	$mock_content = sprintf(
		"[%s] [INFO] Old entry to be pruned\n[%s] [WARNING] Recent entry to keep\n",
		$old_time,
		$recent_time
	);
	file_put_contents( $log_file, $mock_content );

	\WPPE\Core\Logger::info( 'New test log entry' );

	$pruned_content = file_get_contents( $log_file );
	assert_test( 'Logger Pruning: Old entries (>24h) removed', false === strpos( $pruned_content, 'Old entry to be pruned' ) );
	assert_test( 'Logger Pruning: Recent entries (<24h) kept', false !== strpos( $pruned_content, 'Recent entry to keep' ) );
	assert_test( 'Logger Pruning: New entry appended', false !== strpos( $pruned_content, 'New test log entry' ) );

	if ( file_exists( $log_file ) ) {
		@unlink( $log_file );
	}
	if ( file_exists( $log_dir ) && is_dir( $log_dir ) ) {
		@rmdir( $log_dir );
	}

	// Test 6: Elementor & Pro Elements Detection
	$site_profile = SiteProfile::get_instance()->get_profile();
	assert_test( 'Detection: Elementor Detected', true === $site_profile['plugins']['elementor'] );
	assert_test( 'Detection: Pro Elements Detected', true === $site_profile['plugins']['pro_elements'] );
	assert_test( 'Detection: Elementor Pro Flag Active', true === $site_profile['plugins']['elementor_pro'] );

	$el_compat = ElementorCompat::get_instance();
	assert_test( 'ElementorCompat: Is Elementor Active', $el_compat->is_elementor_active() );
	assert_test( 'ElementorCompat: Is Pro Elements Active', $el_compat->is_pro_elements_active() );
	assert_test( 'ElementorCompat: Get Pro Version', '3.19.0' === $el_compat->get_pro_version() );

	// Test 7: Elementor Google Fonts Optimization
	$asset_opt = ElementorAssetOptimizer::get_instance();
	$raw_font_url = 'https://fonts.googleapis.com/css?family=Roboto:400,700';
	$opt_font_url = $asset_opt->optimize_google_font_url( $raw_font_url, 'google-fonts-1' );
	assert_test( 'ElementorAssetOptimizer: Google Fonts display=swap added', false !== strpos( $opt_font_url, 'display=swap' ) );

	// Test 8: Elementor DOM Cleaner (Comments Stripping & Whitespace Compression)
	$dom_opt = ElementorDomOptimizer::get_instance();
	$raw_elementor_html = '<html><head><title>Elementor Test</title></head><body><!-- .elementor-section --><div class="elementor-section">   <!-- .elementor-container -->  <div class="elementor-container"><h1>Hello World</h1></div></div><pre>  keep  spaces  </pre></body></html>';
	
	// Use reflection or invoke optimize_html_dom directly
	$dom_opt->maybe_start_buffer();
	$cleaned_html = $dom_opt->optimize_html_dom( $raw_elementor_html );
	assert_test( 'ElementorDomOptimizer: Comments stripped', false === strpos( $cleaned_html, '<!-- .elementor-section -->' ) && false === strpos( $cleaned_html, '<!-- .elementor-container -->' ) );
	assert_test( 'ElementorDomOptimizer: Pre block spaces preserved', false !== strpos( $cleaned_html, '<pre>  keep  spaces  </pre>' ) );

	// Test 9: Elementor Instant Mobile Menu Script
	$script_opt = ElementorScriptOptimizer::get_instance();
	$fast_menu_tag = $script_opt->get_fast_mobile_menu_script_tag();
	assert_test( 'ElementorScriptOptimizer: Fast mobile menu script tag exists', false !== strpos( $fast_menu_tag, 'wppe-elementor-fast-menu' ) && false !== strpos( $fast_menu_tag, '.elementor-menu-toggle' ) );

	// Test 10: Auto-Tune Experiments
	$autotune_res = $el_compat->auto_tune_experiments();
	assert_test( 'ElementorCompat: Auto-Tune Experiments returns success', true === $autotune_res['success'] );

	echo "=== All Tests Passed Successfully (10/10) ===\n";
	exit( 0 );
}
