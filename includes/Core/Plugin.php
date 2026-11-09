<?php
namespace WPPE\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	private static ?Plugin $instance = null;
	private array $services = [];

	private function __construct() {
		$this->bootstrap();
	}

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Main bootstrap routine.
	 */
	private function bootstrap(): void {
		add_filter( 'cron_schedules', array( self::class, 'register_cron_schedules' ) );
		add_action( 'init', array( self::class, 'ensure_cron_events' ), 20 );
		// Initialize failsafe error handlers.
		Failsafe::register();

		// Check if failsafe is triggered.
		$is_failsafe = Failsafe::is_triggered();

		// Initialize Settings.
		$settings = Settings::get_instance();

		// Load core services (Site profiling, Arbiter, Telemetry).
		$this->services['site_profile'] = \WPPE\Detection\SiteProfile::get_instance();
		$this->services['arbiter']      = \WPPE\Arbitration\Arbiter::get_instance();
		$this->services['telemetry']    = \WPPE\Monitoring\Telemetry::get_instance();

		// Only load runtime optimization engines if failsafe is NOT triggered.
		if ( ! $is_failsafe ) {
			// Load optimization engines.
			$this->services['cache']        = \WPPE\Cache\DiskCache::get_instance();
			$this->services['script']       = \WPPE\Script\ScriptDelay::get_instance();
			$this->services['lcp']          = \WPPE\LCP\LCPPriority::get_instance();
			$this->services['speculation']  = \WPPE\Speculation\SpeculationRules::get_instance();
			$this->services['database']     = \WPPE\Database\DbHousekeeping::get_instance();

			// Load integration/compatibility layers.
			$this->services['elementor']    = \WPPE\Elementor\ElementorCompat::get_instance();
			$this->services['cloudflare']   = \WPPE\Cloudflare\CloudflareFree::get_instance();
			$this->services['flyingpress']  = \WPPE\Compatibility\FlyingPressCompat::get_instance();
			$this->services['perfmatters']  = \WPPE\Compatibility\PerfmattersCompat::get_instance();
			$this->services['salesloo']     = \WPPE\Compatibility\SaleslooCompat::get_instance();
		} else {
			Logger::warning( 'WP Performance Engine Failsafe is triggered: frontend optimization engines are temporarily paused. Admin dashboard remains fully operational.' );
		}

		// Initialize WP-CLI commands if running CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WPPE\Cli\CliCommands::register();
		}

		// Initialize Admin Dashboard (ALWAYS in admin context, even in failsafe mode).
		if ( is_admin() ) {
			$this->services['admin'] = \WPPE\Admin\Dashboard::get_instance();
		}
	}

	/**
	 * Plugin activation routine.
	 */
	public static function activate(): void {
		// Activation can run before the normal bootstrap callback; register the interval first.
		add_filter( 'cron_schedules', array( self::class, 'register_cron_schedules' ) );
		// Init settings default options.
		$settings = Settings::get_instance();
		$settings->load();
		$settings->save();

		// Setup custom folder structures.
		$cache_dir = WP_CONTENT_DIR . '/cache/wp-performance-engine';
		if ( ! file_exists( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		// Schedule cron tasks.
		if ( ! wp_next_scheduled( 'wppe_gc_cron' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'wppe_gc_cron' );
		}
		if ( self::is_cloudflare_purge_configured() && ! wp_next_scheduled( 'wppe_cf_queue_cron' ) ) {
			wp_schedule_event( time(), 'every_minute', 'wppe_cf_queue_cron' );
		}

		Logger::info( 'Plugin activated successfully.' );
	}

	/**
	 * Register the custom interval used by the Cloudflare purge queue.
	 */
	public static function register_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['every_minute'] ) ) {
			$schedules['every_minute'] = array(
				'interval' => 60,
				'display'  => function_exists( 'esc_html__' ) ? esc_html__( 'Every Minute', 'wp-performance-engine' ) : 'Every Minute',
			);
		}
		return $schedules;
	}

	/**
	 * Ensure scheduled tasks exist after plugin updates or missed activation scheduling.
	 */
	public static function ensure_cron_events(): void {
		if ( ! wp_next_scheduled( 'wppe_gc_cron' ) ) {
			wp_schedule_event( time() + 60, 'twicedaily', 'wppe_gc_cron' );
		}
		if ( self::is_cloudflare_purge_configured() ) {
			if ( ! wp_next_scheduled( 'wppe_cf_queue_cron' ) ) {
				wp_schedule_event( time() + 60, 'every_minute', 'wppe_cf_queue_cron' );
			}
		} elseif ( wp_next_scheduled( 'wppe_cf_queue_cron' ) ) {
			// Avoid waking WP-Cron every minute when Cloudflare API purging is not configured.
			wp_clear_scheduled_hook( 'wppe_cf_queue_cron' );
		}
	}

	/**
	 * Check whether the Cloudflare purge worker has usable configuration.
	 */
	private static function is_cloudflare_purge_configured(): bool {
		if ( ! class_exists( '\WPPE\Cloudflare\CloudflareFree' ) ) {
			return false;
		}
		return \WPPE\Cloudflare\CloudflareFree::get_instance()->is_configured();
	}

	/**
	 * Plugin deactivation routine.
	 */
	public static function deactivate(): void {
		// Clear cron schedules.
		wp_clear_scheduled_hook( 'wppe_gc_cron' );
		wp_clear_scheduled_hook( 'wppe_cf_queue_cron' );

		// Clear cache directories.
		\WPPE\Cache\DiskCache::purge_entire_cache();

		Logger::info( 'Plugin deactivated successfully.' );
	}

	/**
	 * Retrieve a registered plugin service.
	 *
	 * @param string $key Service key.
	 * @return mixed
	 */
	public function get_service( string $key ): mixed {
		return $this->services[ $key ] ?? null;
	}
}
