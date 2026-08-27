<?php
namespace WPPE\Detection;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteProfile {
	private static ?SiteProfile $instance = null;
	private array $profile_data = [];

	private function __construct() {
		// Enqueue hooks to capture frontend script/style/image/iframe counts.
		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'count_enqueued_assets' ), 9999 );
		}
	}

	public static function get_instance(): SiteProfile {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Main profile analyzer.
	 */
	public function get_profile(): array {
		if ( ! empty( $this->profile_data ) ) {
			return $this->profile_data;
		}

		global $wpdb;

		// 1. Core versions & hosting env.
		$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
		$server_type = 'Unknown';
		if ( false !== stripos( $server, 'nginx' ) ) {
			$server_type = 'Nginx';
		} elseif ( false !== stripos( $server, 'apache' ) ) {
			$server_type = 'Apache';
		} elseif ( false !== stripos( $server, 'litespeed' ) ) {
			$server_type = 'LiteSpeed';
		}

		// 2. Active plugins profile.
		$active_plugins = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, array_keys( get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		$plugins_detected = [
			'flyingpress'     => false,
			'perfmatters'      => false,
			'elementor'        => false,
			'elementor_pro'    => false,
			'woocommerce'      => false,
			'wp_rocket'        => false,
			'litespeed_cache'  => false,
			'autoptimize'      => false,
			'redis_cache'      => false,
			'memcached_cache'  => false,
		];

		foreach ( $active_plugins as $plugin ) {
			if ( false !== strpos( $plugin, 'flying-press' ) ) {
				$plugins_detected['flyingpress'] = true;
			}
			if ( false !== strpos( $plugin, 'perfmatters' ) ) {
				$plugins_detected['perfmatters'] = true;
			}
			if ( false !== strpos( $plugin, 'elementor/elementor.php' ) ) {
				$plugins_detected['elementor'] = true;
			}
			if ( false !== strpos( $plugin, 'elementor-pro/elementor-pro.php' ) ) {
				$plugins_detected['elementor_pro'] = true;
			}
			if ( false !== strpos( $plugin, 'woocommerce/woocommerce.php' ) ) {
				$plugins_detected['woocommerce'] = true;
			}
			if ( false !== strpos( $plugin, 'wp-rocket' ) ) {
				$plugins_detected['wp_rocket'] = true;
			}
			if ( false !== strpos( $plugin, 'litespeed-cache' ) ) {
				$plugins_detected['litespeed_cache'] = true;
			}
			if ( false !== strpos( $plugin, 'autoptimize' ) ) {
				$plugins_detected['autoptimize'] = true;
			}
			if ( false !== strpos( $plugin, 'redis-cache' ) ) {
				$plugins_detected['redis_cache'] = true;
			}
		}

		// Object cache detection.
		$object_cache = wp_using_ext_object_cache();
		$redis_active = false;
		if ( $object_cache ) {
			global $wp_object_cache;
			if ( isset( $wp_object_cache ) && ( method_exists( $wp_object_cache, 'redis' ) || property_exists( $wp_object_cache, 'redis' ) ) ) {
				$redis_active = true;
			}
		}

		// 3. Database metrics (cached for performance).
		$db_metrics = get_transient( 'wppe_db_metrics_cache' );
		if ( false === $db_metrics ) {
			// Database size.
			$db_size_query = $wpdb->get_row( "SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'", ARRAY_A );
			$db_size_bytes = $db_size_query['size'] ?? 0;
			$db_size_mb = round( $db_size_bytes / ( 1024 * 1024 ), 2 );

			// Revisions count.
			$revisions = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM $wpdb->posts WHERE post_type = 'revision'" );

			// Transients count.
			$transients = (int) $wpdb->get_var( "SELECT COUNT(option_id) FROM $wpdb->options WHERE option_name LIKE '_transient_%'" );

			$db_metrics = [
				'db_size_mb'  => $db_size_mb,
				'revisions'   => $revisions,
				'transients'  => $transients,
			];
			set_transient( 'wppe_db_metrics_cache', $db_metrics, HOUR_IN_SECONDS );
		}

		// 4. Cron tasks.
		$crons = _get_cron_array();
		$cron_count = 0;
		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $cron ) {
				$cron_count += count( $cron );
			}
		}

		// 5. Build full profile.
		$this->profile_data = [
			'server_software' => $server,
			'server_type'     => $server_type,
			'php_version'     => PHP_VERSION,
			'wp_version'      => get_bloginfo( 'version' ),
			'plugins'         => $plugins_detected,
			'plugin_count'    => count( $active_plugins ),
			'db_size_mb'      => $db_metrics['db_size_mb'],
			'revisions'       => $db_metrics['revisions'],
			'transients'      => $db_metrics['transients'],
			'cron_load'       => $cron_count,
			'theme'           => get_stylesheet(),
			'script_count'    => get_transient( 'wppe_script_count_frontend' ) ?: 0,
			'style_count'     => get_transient( 'wppe_style_count_frontend' ) ?: 0,
			'html_size_kb'    => get_transient( 'wppe_html_size_frontend' ) ?: 0,
			'image_count'     => get_transient( 'wppe_image_count_frontend' ) ?: 0,
			'iframe_count'    => get_transient( 'wppe_iframe_count_frontend' ) ?: 0,
		];

		return $this->profile_data;
	}

	/**
	 * Hook to count enqueued scripts and stylesheets on the frontend.
	 */
	public function count_enqueued_assets(): void {
		global $wp_scripts, $wp_styles;

		$script_count = is_a( $wp_scripts, 'WP_Dependencies' ) ? count( $wp_scripts->queue ) : 0;
		$style_count = is_a( $wp_styles, 'WP_Dependencies' ) ? count( $wp_styles->queue ) : 0;

		set_transient( 'wppe_script_count_frontend', $script_count, HOUR_IN_SECONDS );
		set_transient( 'wppe_style_count_frontend', $style_count, HOUR_IN_SECONDS );
	}

	/**
	 * Update telemetry data based on parsed output HTML.
	 */
	public static function update_html_profile_metrics( string $html ): void {
		$size_kb = round( strlen( $html ) / 1024, 2 );
		$image_count = substr_count( $html, '<img ' );
		$iframe_count = substr_count( $html, '<iframe ' );

		set_transient( 'wppe_html_size_frontend', $size_kb, HOUR_IN_SECONDS );
		set_transient( 'wppe_image_count_frontend', $image_count, HOUR_IN_SECONDS );
		set_transient( 'wppe_iframe_count_frontend', $iframe_count, HOUR_IN_SECONDS );
	}
}
