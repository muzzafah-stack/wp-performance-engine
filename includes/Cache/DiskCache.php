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
		// Serve cache after the main query is available but before the theme renders.
		add_action( 'template_redirect', array( $this, 'serve_cache' ), 9995 );

		// Capture output.
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 9996 );

		// Purge hooks.
		add_action( 'save_post', array( $this, 'purge_post_cache' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'purge_old_post_permalink' ), 10, 3 );
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

		// Bypass query parameter preview or Elementor Theme Builder & previews.
		if ( isset( $_GET['preview'] ) || isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) || isset( $_GET['elementor-template-type'] ) || isset( $_GET['preview_id'] ) ) {
			return true;
		}

		// Bypass Elementor library templates (Theme Builder headers, footers, singles, archives).
		if ( ( function_exists( 'is_singular' ) && is_singular( 'elementor_library' ) ) ||
		     ( function_exists( 'get_post_type' ) && 'elementor_library' === get_post_type() ) ) {
			return true;
		}

		// Bypass when inside Elementor editor or preview mode.
		$elementor = \WPPE\Core\Plugin::get_instance()->get_service( 'elementor' );
		if ( $elementor && method_exists( $elementor, 'is_editor_or_preview' ) && $elementor->is_editor_or_preview() ) {
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
		header( 'X-WPPE-Cache: MISS' );
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

		return $html;
	}

	/**
	 * Purge a specific URL cache statically or via instance.
	 */
	public static function purge_url_static( string $url ): void {
		$instance = self::get_instance();
		$key = $instance->get_cache_key( $url );
		$file = $instance->get_cache_file_path( $key );
		if ( file_exists( $file ) ) {
			@unlink( $file );
			Logger::info( sprintf( 'Purged cache for URL: %s', $url ) );
		}
	}

	/**
	 * Purge a specific URL cache.
	 */
	public function purge_url( string $url ): void {
		self::purge_url_static( $url );
	}

	/**
	 * Purge cache when a post is saved or updated.
	 * Supports call with 1, 2, or 3 arguments (save_post hook or programmatic/Elementor calls).
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object (optional).
	 * @param bool          $update  Whether existing post is updated (optional, defaults to true).
	 */
	public static function purge_post_cache( int $post_id, ?\WP_Post $post = null, bool $update = true ): void {
		// Bypass revisions and autosaves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return;
		}

		$post_object = $post instanceof \WP_Post ? $post : ( function_exists( 'get_post' ) ? get_post( $post_id ) : null );
		$urls = [ $permalink, home_url( '/' ) ];

		if ( $post_object ) {
			$post_type = get_post_type( $post_id );

			// Post type archives, author archives, and date archives can also contain this post.
			if ( function_exists( 'get_post_type_archive_link' ) ) {
				$archive_url = get_post_type_archive_link( $post_type );
				if ( $archive_url ) {
					$urls[] = $archive_url;
				}
			}

			if ( function_exists( 'get_author_posts_url' ) && ! empty( $post_object->post_author ) ) {
				$urls[] = get_author_posts_url( (int) $post_object->post_author );
			}

			if ( 'post' === $post_type && ! empty( $post_object->post_date ) ) {
				$date_parts = explode( '-', substr( (string) $post_object->post_date, 0, 10 ) );
				if ( 3 === count( $date_parts ) && ctype_digit( $date_parts[0] ) && ctype_digit( $date_parts[1] ) && ctype_digit( $date_parts[2] ) ) {
					$year  = (int) $date_parts[0];
					$month = (int) $date_parts[1];
					$day   = (int) $date_parts[2];
					if ( function_exists( 'get_year_link' ) ) {
						$urls[] = get_year_link( $year );
					}
					if ( function_exists( 'get_month_link' ) ) {
						$urls[] = get_month_link( $year, $month );
					}
					if ( function_exists( 'get_day_link' ) ) {
						$urls[] = get_day_link( $year, $month, $day );
					}
				}
			}

			// Invalidate taxonomy archives that list the updated post.
			if ( function_exists( 'get_object_taxonomies' ) && function_exists( 'wp_get_object_terms' ) && function_exists( 'get_term_link' ) ) {
				$taxonomies = get_object_taxonomies( $post_type, 'names' );
				if ( ! empty( $taxonomies ) ) {
					$terms = wp_get_object_terms( $post_id, $taxonomies );
					if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
						foreach ( $terms as $term ) {
							$term_url = get_term_link( $term );
							if ( ! is_wp_error( $term_url ) ) {
								$urls[] = $term_url;
							}
						}
					}
				}
			}
		}

		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			self::purge_url_static( $url );
			// Synchronize every affected URL with configured external cache providers.
			do_action( 'wppe_purge_post', $post_id, $url );
		}
	}

	/**
	 * Purge the previous permalink when a post slug or permalink structure changes.
	 */
	public function purge_old_post_permalink( int $post_id, $post_after, $post_before ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post_before ) {
			return;
		}

		$old_url = get_permalink( $post_before );
		$new_url = $post_after ? get_permalink( $post_after ) : '';
		if ( $old_url && $old_url !== $new_url ) {
			self::purge_url_static( $old_url );
			do_action( 'wppe_purge_post', $post_id, $old_url );
		}
	}

	public static function purge_post_cache_on_delete( int $post_id ): void {
		self::purge_post_cache( $post_id );
	}

	/**
	 * Purge cache when comment changes.
	 */
	public function purge_on_comment_change( string $new_status, string $old_status, \WP_Comment $comment ): void {
		$post_id = $comment->comment_post_ID ?? 0;
		if ( $post_id > 0 ) {
			self::purge_post_cache( (int) $post_id );
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
