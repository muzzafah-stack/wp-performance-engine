<?php
namespace WPPE\Cache;

use WPPE\Arbitration\Arbiter;
use WPPE\Core\Settings;
use WPPE\Core\Logger;
use WPPE\Detection\SiteProfile;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiskCache {
	private static ?DiskCache $instance = null;
	private bool $is_buffering = false;

	private function __construct() {
		// Serve cache as early as possible.
		add_action( 'plugins_loaded', array( $this, 'serve_cache' ), 1 );

		// Capture output.
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 9999 );

		// Purge hooks.
		add_action( 'save_post', array( $this, 'purge_post_cache' ), 10, 3 );
		add_action( 'delete_post', array( $this, 'purge_post_cache_on_delete' ) );
		add_action( 'transition_comment_status', array( $this, 'purge_on_comment_change' ), 10, 3 );
		add_action( 'switch_theme', array( $this, 'purge_entire_cache' ) );
		add_action( 'activated_plugin', array( $this, 'purge_entire_cache' ) );
		add_action( 'deactivated_plugin', array( $this, 'purge_entire_cache' ) );

		// Cron hook for garbage collection.
		add_action( 'wppe_gc_cron', array( $this, 'garbage_collection' ) );
	}

	public static function get_instance(): DiskCache {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the absolute path to the cache directory.
	 */
	public static function get_cache_dir(): string {
		return WP_CONTENT_DIR . '/cache/wp-performance-engine';
	}

	/**
	 * Check if cache bypass conditions are met.
	 */
	public function should_bypass(): bool {
		$arbiter = Arbiter::get_instance();
		if ( ! $arbiter->is_authorized_owner( 'page_cache' ) ) {
			return true;
		}

		// Bypass for admin, cron, REST, and AJAX.
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}

		// Bypass for POST requests.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return true;
		}

		// Bypass search queries.
		if ( ! empty( $_GET['s'] ) ) {
			return true;
		}

		// Bypass logged-in users.
		foreach ( $_COOKIE as $cookie_name => $cookie_val ) {
			if ( strpos( $cookie_name, 'wordpress_logged_in_' ) === 0 ) {
				return true;
			}
			// WooCommerce cart/session bypass.
			if ( strpos( $cookie_name, 'wp_woocommerce_session_' ) === 0 || strpos( $cookie_name, 'woocommerce_items_in_cart' ) === 0 ) {
				return true;
			}
		}

		// Bypass WooCommerce cart, checkout, account pages.
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return true;
		}

		// Bypass query parameter preview.
		if ( isset( $_GET['preview'] ) ) {
			return true;
		}

		// Bypass custom settings exclusions.
		$settings = Settings::get_instance();
		$exclusions = $settings->get( 'cache_exclusions', '' );
		if ( ! empty( $exclusions ) ) {
			$request_uri = $_SERVER['REQUEST_URI'] ?? '';
			$rules = array_filter( array_map( 'trim', explode( "\n", $exclusions ) ) );
			foreach ( $rules as $rule ) {
				if ( false !== strpos( $request_uri, $rule ) ) {
					return true;
				}
			}
		}

		// Bypass Salesloo dynamic cookies.
		foreach ( $_COOKIE as $cookie_name => $cookie_val ) {
			if ( strpos( $cookie_name, 'salesloo_' ) === 0 ) {
				return true;
			}
		}

		// Allow plugins to filter cache bypass dynamically.
		if ( apply_filters( 'wppe_cache_should_bypass', false ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize URL and generate deterministic cache key.
	 */
	public function get_cache_key( string $url = '' ): string {
		if ( empty( $url ) ) {
			$schema = is_ssl() ? 'https://' : 'http://';
			$url = $schema . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' );
		}

		$parsed = wp_parse_url( $url );
		$host = $parsed['host'] ?? '';
		$path = $parsed['path'] ?? '/';
		$query = $parsed['query'] ?? '';

		// Strip tracking query parameters.
		if ( ! empty( $query ) ) {
			parse_str( $query, $query_args );
			$ignored_params = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'age-verified' ];
			foreach ( $ignored_params as $param ) {
				unset( $query_args[ $param ] );
			}
			ksort( $query_args );
			$query = http_build_query( $query_args );
		}

		$normalized_url = $host . $path . ( ! empty( $query ) ? '?' . $query : '' );
		return md5( $normalized_url );
	}

	/**
	 * Get the file path for a cache key.
	 */
	public function get_cache_file_path( string $key ): string {
		// Sub-divide directory structure to prevent thousands of files in a single folder.
		$sub1 = substr( $key, 0, 2 );
		$sub2 = substr( $key, 2, 2 );
		return self::get_cache_dir() . '/' . $sub1 . '/' . $sub2 . '/' . $key . '.html';
	}

	/**
	 * Serve the cached HTML file early if it exists.
	 */
	public function serve_cache(): void {
		if ( $this->should_bypass() ) {
			return;
		}

		$key = $this->get_cache_key();
		$file = $this->get_cache_file_path( $key );

		if ( file_exists( $file ) ) {
			// Check file TTL (e.g. 10 hours).
			$ttl = 36000;
			if ( ( time() - filemtime( $file ) ) < $ttl ) {
				$html = file_get_contents( $file );
				if ( ! empty( $html ) ) {
					// Set cache headers.
					header( 'X-WPPE-Cache: HIT' );
					header( 'Content-Type: text/html; charset=UTF-8' );

					// Log hit metric.
					\WPPE\Monitoring\Telemetry::record_cache_hit();

					echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					exit;
				}
			}
		}
	}

	/**
	 * Start buffering page rendering to cache the output.
	 */
	public function start_buffer(): void {
		if ( $this->should_bypass() ) {
			return;
		}

		$this->is_buffering = true;
		ob_start( array( $this, 'cache_output' ) );
	}

	/**
	 * Callback for ob_start. Captures HTML, saves to disk, and returns it.
	 */
	public function cache_output( string $html ): string {
		if ( ! $this->is_buffering || empty( $html ) ) {
			return $html;
		}

		$this->is_buffering = false;

		// Perform light validation on HTML structure.
		if ( false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		// Update Site Profile front-end metrics.
		SiteProfile::update_html_profile_metrics( $html );

		$key = $this->get_cache_key();
		$file = $this->get_cache_file_path( $key );
		$dir = dirname( $file );

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Atomic write operation to prevent race conditions.
		$temp_file = tempnam( $dir, 'wppe_temp_' );
		if ( $temp_file ) {
			$written = file_put_contents( $temp_file, $html . "\n<!-- WPPE-Cache: Cached on " . current_time( 'mysql' ) . " -->" );
			if ( false !== $written ) {
				// Set correct file permissions.
				chmod( $temp_file, 0644 );
				// Rename temp file to target cache file.
				rename( $temp_file, $file );

				// Log size change in telemetry.
				\WPPE\Monitoring\Telemetry::record_cache_size( filesize( $file ) );
			} else {
				unlink( $temp_file );
			}
		}

		header( 'X-WPPE-Cache: MISS' );
		return $html;
	}

	/**
	 * Purge a specific URL cache.
	 */
	public function purge_url( string $url ): void {
		$key = $this->get_cache_key( $url );
		$file = $this->get_cache_file_path( $key );
		if ( file_exists( $file ) ) {
			unlink( $file );
			Logger::info( sprintf( 'Purged cache for URL: %s', $url ) );
		}
	}

	/**
	 * Purge cache when a post is saved or updated.
	 */
	public function purge_post_cache( int $post_id, \WP_Post $post, bool $update ): void {
		// Bypass revisions and autosaves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Avoid double purge on update.
		if ( ! $update ) {
			return;
		}

		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$this->purge_url( $permalink );
			// Purge the homepage.
			$this->purge_url( home_url( '/' ) );

			// Trigger Cloudflare and FlyingPress purge synchronization.
			do_action( 'wppe_purge_post', $post_id, $permalink );
		}
	}

	public function purge_post_cache_on_delete( int $post_id ): void {
		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$this->purge_url( $permalink );
			$this->purge_url( home_url( '/' ) );
			do_action( 'wppe_purge_post', $post_id, $permalink );
		}
	}

	/**
	 * Purge cache when comment changes.
	 */
	public function purge_on_comment_change( string $new_status, string $old_status, \WP_Comment $comment ): void {
		$post_id = $comment->comment_post_ID;
		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$this->purge_url( $permalink );
		}
	}

	/**
	 * Purge the entire disk cache directory.
	 */
	public static function purge_entire_cache(): bool {
		$dir = self::get_cache_dir();
		if ( ! file_exists( $dir ) ) {
			return true;
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

		// Recreate base folder.
		wp_mkdir_p( $dir );
		// Write .htaccess to prevent public PHP execution.
		file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<Files *.php>\nDeny from all\n</Files>\n" );

		Logger::info( 'Entire Disk Cache purged.' );
		return true;
	}

	/**
	 * Garbage collection task to remove expired files.
	 */
	public function garbage_collection(): void {
		$dir = self::get_cache_dir();
		if ( ! file_exists( $dir ) ) {
			return;
		}

		$ttl = 36000; // 10 hours
		$now = time();
		$it = new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS );
		$files = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $files as $file ) {
			if ( ! $file->isDir() && ( $now - $file->getMTime() ) > $ttl ) {
				unlink( $file->getRealPath() );
			}
		}
		Logger::info( 'Cache garbage collection executed.' );
	}
}
