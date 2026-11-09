<?php
namespace WPPE\Cloudflare {
	class CloudflareFree {
		private static $instance;
		public static function get_instance() { return self::$instance ?? ( self::$instance = new self() ); }
		public static bool $configured = true;
		public function is_configured(): bool { return self::$configured; }
	}
}
namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['scheduled_events'] = [];
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
	function wp_next_scheduled( $hook ) { return $GLOBALS['scheduled_events'][ $hook ] ?? false; }
	function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['scheduled_events'][ $hook ] ); return true; }
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['scheduled_events'][ $hook ] = true;
		$GLOBALS['scheduled_event_details'][ $hook ] = [ $timestamp, $recurrence ];
		return true;
	}
	require_once dirname( __DIR__ ) . '/includes/Core/Plugin.php';

	$schedules = \WPPE\Core\Plugin::register_cron_schedules( [] );
	if ( ! isset( $schedules['every_minute'] ) || 60 !== $schedules['every_minute']['interval'] ) {
		echo "[FAIL] every_minute cron interval was not registered\n";
		exit( 1 );
	}

	\WPPE\Core\Plugin::ensure_cron_events();
	$details = $GLOBALS['scheduled_event_details'] ?? [];
	if ( ! isset( $details['wppe_gc_cron'], $details['wppe_cf_queue_cron'] ) || 'every_minute' !== $details['wppe_cf_queue_cron'][1] ) {
		echo "[FAIL] WPPE cron events were not scheduled with expected recurrence\n";
		exit( 1 );
	}

	echo "[PASS] Cron interval is registered before event scheduling\n";
	echo "[PASS] Missing WPPE cron events are repaired\n";
	\WPPE\Cloudflare\CloudflareFree::$configured = false;
	\WPPE\Core\Plugin::ensure_cron_events();
	if ( isset( $GLOBALS['scheduled_events']['wppe_cf_queue_cron'] ) ) {
		echo "[FAIL] Cloudflare queue cron remained scheduled without API configuration\n";
		exit( 1 );
	}
	echo "[PASS] Cloudflare queue cron is removed when API configuration is absent\n";
}
