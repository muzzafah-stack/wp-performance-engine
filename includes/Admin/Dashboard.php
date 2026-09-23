<?php
namespace WPPE\Admin;

use WPPE\Core\Settings;
use WPPE\Core\Logger;
use WPPE\Detection\SiteProfile;
use WPPE\Arbitration\Arbiter;
use WPPE\Cache\DiskCache;
use WPPE\Database\DbHousekeeping;
use WPPE\Monitoring\Telemetry;
use WPPE\Cloudflare\CloudflareFree;
use WPPE\Security\SecurityHelper;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard {
	private static ?Dashboard $instance = null;

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Ajax actions.
		add_action( 'wp_ajax_wppe_test_cf_connection', array( $this, 'ajax_test_cf_connection' ) );
		add_action( 'wp_ajax_wppe_db_cleanup', array( $this, 'ajax_db_cleanup' ) );
		add_action( 'wp_ajax_wppe_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_wppe_clear_logs', array( $this, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_wppe_repair_plugin', array( $this, 'ajax_repair_plugin' ) );
		add_action( 'wp_ajax_wppe_autotune_elementor', array( $this, 'ajax_autotune_elementor' ) );
	}

	public static function get_instance(): Dashboard {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_menu(): void {
		add_menu_page(
			'WP Performance Engine',
			'Performance Engine',
			'manage_options',
			'wp-performance-engine',
			array( $this, 'render_dashboard' ),
			'dashicons-performance',
			90
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_wp-performance-engine' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wppe-admin-css', WPPE_PLUGIN_URL . 'assets/css/admin.css', [], WPPE_VERSION );
		wp_enqueue_script( 'wppe-admin-js', WPPE_PLUGIN_URL . 'assets/js/admin.js', [], WPPE_VERSION, true );

		wp_localize_script( 'wppe-admin-js', 'wppe_ajax', [
			'nonce' => wp_create_nonce( 'wppe_admin_nonce' ),
		]);
	}

	/**
	 * Handle Ajax Cloudflare Connection Test.
	 */
	public function ajax_test_cf_connection(): void {
		SecurityHelper::verify_nonce( 'wppe_admin_nonce' );
		SecurityHelper::check_admin_capabilities();

		$cf = CloudflareFree::get_instance();
		$result = $cf->test_connection();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Handle Ajax Database Cleanups.
	 */
	public function ajax_db_cleanup(): void {
		SecurityHelper::verify_nonce( 'wppe_admin_nonce' );
		SecurityHelper::check_admin_capabilities();

		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'safe';
		$aggressive = ( 'aggressive' === $mode );

		$settings = Settings::get_instance();
		$retention = (int) $settings->get( 'db_cleanup_revisions_retention', 10 );

		// If aggressive mode, override revisions retention to 0.
		$rev_limit = $aggressive ? 0 : $retention;

		$housekeeper = DbHousekeeping::get_instance();
		$result = $housekeeper->execute_cleanup( $category, $rev_limit, $aggressive );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX method to get the current logs.
	 */
	public function ajax_get_logs(): void {
		SecurityHelper::verify_nonce( 'wppe_admin_nonce' );
		SecurityHelper::check_admin_capabilities();

		$log_file = WP_CONTENT_DIR . '/uploads/wp-performance-engine/logs/debug.log';
		$logs = 'Log empty or debug mode disabled.';
		if ( file_exists( $log_file ) ) {
			$logs = file_get_contents( $log_file );
			if ( empty( $logs ) ) {
				$logs = 'Log is currently empty.';
			}
		}

		wp_send_json_success([
			'logs' => $logs
		]);
	}

	/**
	 * AJAX method to clear the logs.
	 */
	public function ajax_clear_logs(): void {
		SecurityHelper::verify_nonce( 'wppe_admin_nonce' );
		SecurityHelper::check_admin_capabilities();

		$log_file = WP_CONTENT_DIR . '/uploads/wp-performance-engine/logs/debug.log';
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		wp_send_json_success([
			'message' => 'Logs cleared successfully.'
		]);
	}

	/**
	 * Handle AJAX repair action for compatibility errors.
	 */
	public function ajax_repair_plugin(): void {
		SecurityHelper::verify_nonce( 'wppe_admin_nonce' );
		SecurityHelper::check_admin_capabilities();

		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';

		if ( 'flyingpress' === $plugin ) {
			$purged = false;
			if ( class_exists( '\FlyingPress\Purge' ) ) {
				if ( method_exists( '\FlyingPress\Purge', 'purge_everything' ) ) {
					\FlyingPress\Purge::purge_everything();
					$purged = true;
				} elseif ( method_exists( '\FlyingPress\Purge', 'purge_pages' ) ) {
					\FlyingPress\Purge::purge_pages();
					$purged = true;
				}
			}

			// Clear the debug log to remove the error entries.
			$log_file = WP_CONTENT_DIR . '/uploads/wp-performance-engine/logs/debug.log';
			if ( file_exists( $log_file ) ) {
				file_put_contents( $log_file, '' );
			}

			wp_send_json_success([
				'message' => 'FlyingPress integration repaired! Code calls updated, FlyingPress cache cleared, and logs reset.'
			]);
		} elseif ( 'perfmatters' === $plugin ) {
			// Clear Perfmatters cache.
			$css_cleared = false;
			$minify_cleared = false;
			if ( class_exists( '\Perfmatters\CSS' ) && method_exists( '\Perfmatters\CSS', 'clear_used_css' ) ) {
				\Perfmatters\CSS::clear_used_css();
				$css_cleared = true;
			}
			if ( class_exists( '\Perfmatters\Minify' ) && method_exists( '\Perfmatters\Minify', 'clear_minified' ) ) {
				\Perfmatters\Minify::clear_minified();
				$minify_cleared = true;
			}

			// Deactivate conflicting options in Perfmatters.
			$pm_options = get_option( 'perfmatters_options' );
			if ( is_string( $pm_options ) ) {
				$pm_options = unserialize( $pm_options );
			}
			if ( is_array( $pm_options ) ) {
				$pm_options['delay_js'] = '0';
				$pm_options['defer_js'] = '0';
				$pm_options['lazy_loading'] = '0';
				$pm_options['instant_page'] = '0';
				update_option( 'perfmatters_options', $pm_options );
			}

			// Clear the debug log.
			$log_file = WP_CONTENT_DIR . '/uploads/wp-performance-engine/logs/debug.log';
			if ( file_exists( $log_file ) ) {
				file_put_contents( $log_file, '' );
			}

			wp_send_json_success([
				'message' => 'Perfmatters conflicts resolved! Conflicting features disabled in Perfmatters settings, Perfmatters cache cleared, and logs reset.'
			]);
		} else {
			wp_send_json_error([
				'message' => 'Unknown plugin specified for repair.'
			]);
		}
	}

	/**
	 * Handle AJAX auto-tuning of Elementor performance experiments.
	 */
	public function ajax_autotune_elementor(): void {
		SecurityHelper::verify_nonce( 'wppe_admin_nonce' );
		SecurityHelper::check_admin_capabilities();

		$compat = \WPPE\Elementor\ElementorCompat::get_instance();
		$result = $compat->auto_tune_experiments();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Parse log file and return unique errors/warnings and their counts.
	 */
	public function get_aggregated_logs(): array {
		$log_file = WP_CONTENT_DIR . '/uploads/wp-performance-engine/logs/debug.log';
		if ( ! file_exists( $log_file ) ) {
			return [];
		}

		$lines = file( $log_file );
		if ( ! is_array( $lines ) ) {
			return [];
		}

		$aggregated = [];
		foreach ( $lines as $line ) {
			// Format: [2026-08-25 17:05:13] [ERROR] Failed calling ...
			if ( preg_match( '/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+(.+)$/', trim( $line ), $matches ) ) {
				$timestamp = $matches[1];
				$level     = $matches[2];
				$message   = $matches[3];

				if ( ! in_array( $level, [ 'ERROR', 'WARNING', 'CRITICAL' ], true ) ) {
					continue;
				}

				// Deduplicate context/JSON suffix to clean up the message
				$clean_message = preg_replace( '/\s+\{[^\}]+\}$/', '', $message );

				$key = md5( $level . '|' . $clean_message );

				if ( isset( $aggregated[ $key ] ) ) {
					$aggregated[ $key ]['count']++;
					$aggregated[ $key ]['last_seen'] = $timestamp;
				} else {
					$aggregated[ $key ] = [
						'level'      => $level,
						'message'    => $clean_message,
						'count'      => 1,
						'first_seen' => $timestamp,
						'last_seen'  => $timestamp,
					];
				}
			}
		}

		usort( $aggregated, function( $a, $b ) {
			return $b['count'] <=> $a['count'];
		});

		return $aggregated;
	}

	/**
	 * Handle settings update POST.
	 */
	private function handle_post_save(): void {
		if ( ! isset( $_POST['wppe_save_settings'] ) ) {
			return;
		}

		check_admin_referer( 'wppe_settings_nonce' );
		SecurityHelper::check_admin_capabilities();

		$settings = Settings::get_instance();

		// Checkboxes.
		$settings->set( 'debug_mode', isset( $_POST['debug_mode'] ) );
		$settings->set( 'enable_html_cache', isset( $_POST['enable_html_cache'] ) );
		$settings->set( 'enable_script_delay', isset( $_POST['enable_script_delay'] ) );
		$settings->set( 'enable_lcp_priority', isset( $_POST['enable_lcp_priority'] ) );
		$settings->set( 'enable_speculation', isset( $_POST['enable_speculation'] ) );
		$settings->set( 'telemetry_opt_in', isset( $_POST['telemetry_opt_in'] ) );
		$settings->set( 'failsafe_enabled', isset( $_POST['failsafe_enabled'] ) );

		// Elementor Settings.
		$settings->set( 'elementor_optimize_assets', isset( $_POST['elementor_optimize_assets'] ) );
		$settings->set( 'elementor_optimize_dom', isset( $_POST['elementor_optimize_dom'] ) );
		$settings->set( 'elementor_optimize_google_fonts', isset( $_POST['elementor_optimize_google_fonts'] ) );
		$settings->set( 'elementor_remove_fa4_shim', isset( $_POST['elementor_remove_fa4_shim'] ) );
		$settings->set( 'elementor_eicons_optimization', isset( $_POST['elementor_eicons_optimization'] ) );
		$settings->set( 'elementor_smart_script_delay', isset( $_POST['elementor_smart_script_delay'] ) );
		$settings->set( 'elementor_instant_mobile_menu', isset( $_POST['elementor_instant_mobile_menu'] ) );
		$settings->set( 'elementor_disable_telemetry', isset( $_POST['elementor_disable_telemetry'] ) );
		$settings->set( 'elementor_auto_enable_experiments', isset( $_POST['elementor_auto_enable_experiments'] ) );

		// Inputs.
		$settings->set( 'delay_timeout', (int) ( $_POST['delay_timeout'] ?? 5000 ) );
		$settings->set( 'db_cleanup_revisions_retention', (int) ( $_POST['db_cleanup_revisions_retention'] ?? 10 ) );
		$settings->set( 'speculation_mode', sanitize_text_field( $_POST['speculation_mode'] ?? 'balanced' ) );

		// Encryption fields (Cloudflare).
		$settings->set( 'cloudflare_api_token', CloudflareFree::sanitize_token( sanitize_text_field( $_POST['cloudflare_api_token'] ?? '' ) ) );
		$settings->set( 'cloudflare_zone_id', CloudflareFree::sanitize_zone_id( sanitize_text_field( $_POST['cloudflare_zone_id'] ?? '' ) ) );

		// Textareas.
		$settings->set( 'cache_exclusions', sanitize_textarea_field( $_POST['cache_exclusions'] ?? '' ) );
		$settings->set( 'delay_exclusions', sanitize_textarea_field( $_POST['delay_exclusions'] ?? '' ) );
		$settings->set( 'delay_allowlist', sanitize_textarea_field( $_POST['delay_allowlist'] ?? '' ) );

		$settings->save();

		// Handle manual cache purge if button clicked.
		if ( isset( $_POST['wppe_purge_cache'] ) ) {
			DiskCache::purge_entire_cache();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Entire HTML cache directory purged successfully.', 'wp-performance-engine' ) . '</p></div>';
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'wp-performance-engine' ) . '</p></div>';
	}

	/**
	 * Main rendering engine.
	 */
	public function render_dashboard(): void {
		// Save settings if POSTed.
		$this->handle_post_save();

		$settings = Settings::get_instance();
		$profile = SiteProfile::get_instance()->get_profile();
		$arbiter = Arbiter::get_instance();
		$diagnostics = $arbiter->get_conflict_diagnostics();
		$telemetry = Telemetry::get_instance()->get_metrics();
		$db_stats = DbHousekeeping::get_instance()->get_cleanup_stats( (int) $settings->get( 'db_cleanup_revisions_retention', 10 ) );

		$cache_size_kb = round( $telemetry['cache_size_bytes'] / 1024, 2 );

		// Obfuscate token for display.
		$stored_token = $settings->get( 'cloudflare_api_token', '' );
		$display_token = ! empty( $stored_token ) ? str_repeat( '*', 12 ) . substr( $stored_token, -4 ) : '';
		?>
		<div id="wppe-dashboard">
			<div class="wppe-header">
				<div>
					<h1>WP Performance Engine</h1>
					<p><?php esc_html_e( 'Adaptive WordPress Performance & Optimization Orchestration Engine', 'wp-performance-engine' ); ?></p>
				</div>
				<div>
					<span class="wppe-badge wppe-badge-success">v<?php echo esc_html( WPPE_VERSION ); ?></span>
				</div>
			</div>

			<div class="wppe-layout">
				<!-- Navigation Sidebar -->
				<div class="wppe-nav">
					<div class="wppe-nav-item active" data-tab="overview">Overview</div>
					<div class="wppe-nav-item" data-tab="conflicts">Conflict Inspector</div>
					<div class="wppe-nav-item" data-tab="cache">Disk HTML Cache</div>
					<div class="wppe-nav-item" data-tab="scripts">Scripts Optimizer</div>
					<div class="wppe-nav-item" data-tab="elementor">Elementor Optimizer</div>
					<div class="wppe-nav-item" data-tab="lcp">LCP Optimization</div>
					<div class="wppe-nav-item" data-tab="speculation">Speculation rules</div>
					<div class="wppe-nav-item" data-tab="database">DB Housekeeping</div>
					<div class="wppe-nav-item" data-tab="integrations">Cloudflare Free</div>
					<div class="wppe-nav-item" data-tab="settings">Settings & Logs</div>
				</div>

				<!-- Content Panel -->
				<div class="wppe-content">
					<form method="POST" action="">
						<?php wp_nonce_field( 'wppe_settings_nonce' ); ?>

						<!-- Tab: Overview -->
						<div class="wppe-tab-content active" id="tab-overview">
							<h2><?php esc_html_e( 'Performance Health Overview', 'wp-performance-engine' ); ?></h2>
							<div class="wppe-grid">
								<div class="wppe-card">
									<h3>Cache Hits</h3>
									<div class="wppe-card-metric"><?php echo esc_html( $telemetry['cache_hits'] ); ?></div>
								</div>
								<div class="wppe-card">
									<h3>Cache Misses</h3>
									<div class="wppe-card-metric"><?php echo esc_html( $telemetry['cache_misses'] ); ?></div>
								</div>
								<div class="wppe-card">
									<h3>Cache Size</h3>
									<div class="wppe-card-metric"><?php echo esc_html( $cache_size_kb ); ?> KB</div>
								</div>
							</div>

							<?php
							$aggregated_errors = $this->get_aggregated_logs();
							if ( ! empty( $aggregated_errors ) ) : ?>
								<div class="wppe-alerts-section" style="margin-bottom: 32px; padding: 24px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: var(--wppe-radius); box-shadow: var(--wppe-shadow);">
									<h3 style="color: #9f1239; margin-top: 0; display: flex; align-items: center; gap: 8px;">
										<span class="dashicons dashicons-warning" style="color: #e11d48; margin-top: 2px;"></span>
										<?php esc_html_e( 'Peringatan Error Sistem (Berulang)', 'wp-performance-engine' ); ?>
									</h3>
									<p style="color: #4c0519; font-size: 13px; margin-bottom: 16px;"><?php esc_html_e( 'Log error berikut dideteksi berulang kali di sistem. Silakan lakukan tindakan perbaikan yang sesuai.', 'wp-performance-engine' ); ?></p>
									<div class="wppe-alerts-list" style="display: grid; gap: 12px;">
										<?php foreach ( $aggregated_errors as $err ) : 
											$is_flyingpress = ( false !== stripos( $err['message'], 'FlyingPress' ) );
											$is_perfmatters = ( false !== stripos( $err['message'], 'Perfmatters' ) );
											$bg_color = 'ERROR' === $err['level'] || 'CRITICAL' === $err['level'] ? '#fff' : '#fffbeb';
											$border_color = 'ERROR' === $err['level'] || 'CRITICAL' === $err['level'] ? '#fda4af' : '#fde047';
											?>
											<div class="wppe-alert-card" style="background: <?php echo esc_attr( $bg_color ); ?>; border: 1px solid <?php echo esc_attr( $border_color ); ?>; padding: 16px; border-radius: 8px; display: flex; flex-direction: column; gap: 8px;">
												<div class="wppe-alert-header" style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 12px;">
													<span class="wppe-badge <?php echo 'ERROR' === $err['level'] || 'CRITICAL' === $err['level'] ? 'wppe-badge-danger' : 'wppe-badge-warning'; ?>"><?php echo esc_html( $err['level'] ); ?></span>
													<span style="font-weight: 600; color: #1e293b;"><?php printf( esc_html__( 'Berulang %d kali', 'wp-performance-engine' ), $err['count'] ); ?></span>
													<span style="color: #64748b;"><?php printf( esc_html__( 'Terakhir Terdeteksi: %s', 'wp-performance-engine' ), esc_html( $err['last_seen'] ) ); ?></span>
												</div>
												<div class="wppe-alert-body" style="font-family: monospace; font-size: 12px; background: #f8fafc; padding: 10px; border-radius: 4px; border: 1px solid #e2e8f0; overflow-x: auto; white-space: pre-wrap; word-break: break-all; color: #334155;">
													<?php echo esc_html( $err['message'] ); ?>
												</div>
												<?php if ( $is_flyingpress || $is_perfmatters ) : ?>
													<div class="wppe-alert-actions" style="margin-top: 4px;">
														<button type="button" class="wppe-btn wppe-btn-warning wppe-repair-btn" data-plugin="<?php echo $is_flyingpress ? 'flyingpress' : 'perfmatters'; ?>" style="font-size: 12px; padding: 6px 12px; display: inline-flex; align-items: center; gap: 6px;">
															<span class="dashicons dashicons-admin-tools" style="font-size: 14px; width: 14px; height: 14px; margin-top: 2px;"></span>
															<?php printf( esc_html__( 'Perbaiki Integrasi %s', 'wp-performance-engine' ), $is_flyingpress ? 'FlyingPress' : 'Perfmatters' ); ?>
														</button>
													</div>
												<?php endif; ?>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>

							<h3>Site Profile Snapshot</h3>
							<table class="wppe-table">
								<tr>
									<td><strong>PHP Version</strong></td>
									<td><?php echo esc_html( $profile['php_version'] ); ?></td>
								</tr>
								<tr>
									<td><strong>WordPress Version</strong></td>
									<td><?php echo esc_html( $profile['wp_version'] ); ?></td>
								</tr>
								<tr>
									<td><strong>Database Size</strong></td>
									<td><?php echo esc_html( $profile['db_size_mb'] ); ?> MB</td>
								</tr>
								<tr>
									<td><strong>Cron Tasks Enqueued</strong></td>
									<td><?php echo esc_html( $profile['cron_load'] ); ?></td>
								</tr>
							</table>
						</div>

						<!-- Tab: Conflicts -->
						<div class="wppe-tab-content" id="tab-conflicts">
							<h2><?php esc_html_e( 'Performance Conflict Inspector', 'wp-performance-engine' ); ?></h2>
							<p><?php esc_html_e( 'Our optimization arbiter checks what options are owned/managed by other active performance plugins.', 'wp-performance-engine' ); ?></p>
							
							<table class="wppe-table">
								<thead>
									<tr>
										<th>Optimization Domain</th>
										<th>Our Status</th>
										<th>Designated Owner</th>
										<th>Action Required</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $diagnostics as $domain => $info ) : 
										$badge_class = 'wppe-badge-success';
										if ( 'HIGH' === $info['severity'] ) {
											$badge_class = 'wppe-badge-danger';
										} elseif ( 'LOW' === $info['severity'] ) {
											$badge_class = 'wppe-badge-warning';
										}
										?>
										<tr>
											<td><strong><?php echo esc_html( $info['label'] ); ?></strong></td>
											<td><?php echo esc_html( $info['our_status'] ); ?></td>
											<td><span class="wppe-badge wppe-badge-info"><?php echo esc_html( str_replace( 'OWNER_', '', $info['owner'] ) ); ?></span></td>
											<td>
												<?php if ( 'OK' === $info['status'] ) : ?>
													<span class="wppe-badge wppe-badge-success">OK</span>
												<?php else : ?>
													<span class="wppe-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $info['recommendation'] ); ?></span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<!-- Tab: Cache -->
						<div class="wppe-tab-content" id="tab-cache">
							<h2>Disk HTML Cache</h2>
							<div class="wppe-form-row">
								<input type="checkbox" name="enable_html_cache" id="enable_html_cache" <?php checked( $settings->get( 'enable_html_cache' ) ); ?> />
								<label style="display:inline;" for="enable_html_cache">Enable Disk HTML Cache</label>
							</div>
							<div class="wppe-form-row">
								<label for="cache_exclusions">Cache Exclusions (One URL path snippet per line)</label>
								<textarea name="cache_exclusions" id="cache_exclusions" rows="4"><?php echo esc_textarea( $settings->get( 'cache_exclusions' ) ); ?></textarea>
							</div>
							<div class="wppe-form-row">
								<button type="submit" name="wppe_purge_cache" class="wppe-btn wppe-btn-danger">Purge Cache Directory</button>
							</div>
						</div>

						<!-- Tab: Scripts -->
						<div class="wppe-tab-content" id="tab-scripts">
							<h2>INP-Safe Script Delay</h2>
							<div class="wppe-form-row">
								<input type="checkbox" name="enable_script_delay" id="enable_script_delay" <?php checked( $settings->get( 'enable_script_delay' ) ); ?> />
								<label style="display:inline;" for="enable_script_delay">Enable Script Delay Execution</label>
							</div>
							<div class="wppe-form-row">
								<label for="delay_timeout">Fallback Trigger Timeout (milliseconds)</label>
								<input type="number" name="delay_timeout" id="delay_timeout" value="<?php echo esc_attr( $settings->get( 'delay_timeout', 5000 ) ); ?>" />
							</div>
							<div class="wppe-form-row">
								<label for="delay_exclusions">Script Delay Exclusions (One handle or URL snippet per line)</label>
								<textarea name="delay_exclusions" id="delay_exclusions" rows="4"><?php echo esc_textarea( $settings->get( 'delay_exclusions' ) ); ?></textarea>
							</div>
							<div class="wppe-form-row">
								<label for="delay_allowlist">Script Delay Allowlist (Force Delay these scripts, bypasses auto classification)</label>
								<textarea name="delay_allowlist" id="delay_allowlist" rows="4"><?php echo esc_textarea( $settings->get( 'delay_allowlist' ) ); ?></textarea>
							</div>
						</div>

						<!-- Tab: Elementor & Pro Elements -->
						<div class="wppe-tab-content" id="tab-elementor">
							<h2><?php esc_html_e( 'Elementor & Pro Elements Performance Optimizer', 'wp-performance-engine' ); ?></h2>
							<p><?php esc_html_e( 'Deep optimization layer tailored specifically for Elementor Core and Pro Elements (GPL Free Elementor Pro). Slashes JavaScript execution time, DOM depth, Google Fonts requests, and unused widget bloat with a 100% frontend visual fidelity guarantee.', 'wp-performance-engine' ); ?></p>

							<?php
							$el_compat = \WPPE\Elementor\ElementorCompat::get_instance();
							$el_diagnostics = $el_compat->get_diagnostics();
							$is_el_active = $el_compat->is_elementor_active();
							$is_pro_active = $el_compat->is_pro_elements_active();
							?>

							<div class="wppe-grid" style="margin-bottom: 24px;">
								<div class="wppe-card">
									<h3>Elementor Core</h3>
									<div class="wppe-card-metric" style="font-size: 20px;">
										<?php if ( $is_el_active ) : ?>
											<span class="wppe-badge wppe-badge-success">Active <?php echo esc_html( defined( 'ELEMENTOR_VERSION' ) ? 'v' . ELEMENTOR_VERSION : '' ); ?></span>
										<?php else : ?>
											<span class="wppe-badge wppe-badge-warning">Not Active</span>
										<?php endif; ?>
									</div>
									<p style="font-size: 13px; color: var(--wppe-text-light); margin: 0;">Main Page Builder Engine</p>
								</div>
								<div class="wppe-card">
									<h3>Pro Extension</h3>
									<div class="wppe-card-metric" style="font-size: 20px;">
										<?php if ( $is_pro_active ) : ?>
											<span class="wppe-badge wppe-badge-info">Pro Elements v<?php echo esc_html( $el_compat->get_pro_version() ); ?> (GPL)</span>
										<?php elseif ( $el_compat->is_elementor_pro_active() ) : ?>
											<span class="wppe-badge wppe-badge-success">Elementor Pro v<?php echo esc_html( $el_compat->get_pro_version() ); ?></span>
										<?php else : ?>
											<span class="wppe-badge wppe-badge-warning">None Detected</span>
										<?php endif; ?>
									</div>
									<p style="font-size: 13px; color: var(--wppe-text-light); margin: 0;">Pro Widgets & Theme Builder</p>
								</div>
								<div class="wppe-card">
									<h3>Native Experiments</h3>
									<div style="margin: 12px 0;">
										<button type="button" class="wppe-btn wppe-btn-warning" id="wppe-autotune-el-btn" style="font-size: 13px; padding: 8px 14px; display: inline-flex; align-items: center; gap: 6px;">
											<span class="dashicons dashicons-performance" style="font-size: 16px; width: 16px; height: 16px; margin-top: 2px;"></span>
											Auto-Tune Experiments
										</button>
										<div id="wppe-autotune-status" style="margin-top: 8px; font-size: 12px;"></div>
									</div>
									<p style="font-size: 12px; color: var(--wppe-text-light); margin: 0;">Enables DOM Optimization, Asset Loading & SVG Icons</p>
								</div>
							</div>

							<h3><?php esc_html_e( 'Elementor Optimization Modules', 'wp-performance-engine' ); ?></h3>

							<div class="wppe-form-row">
								<input type="checkbox" name="elementor_optimize_assets" id="elementor_optimize_assets" <?php checked( $settings->get( 'elementor_optimize_assets', true ) ); ?> />
								<label style="display:inline;" for="elementor_optimize_assets"><strong>Prune Unused Pro Elements Widget Assets</strong></label>
								<p class="description" style="margin-left: 24px; color: var(--wppe-text-light); font-size: 13px;">Automatically dequeues unneeded vendor scripts & stylesheets (such as Flatpickr datepicker, Lottie player, Share-links, Smartmenus) when those widgets are not present on the current page.</p>
							</div>

							<div class="wppe-form-row">
								<input type="checkbox" name="elementor_smart_script_delay" id="elementor_smart_script_delay" <?php checked( $settings->get( 'elementor_smart_script_delay', true ) ); ?> />
								<label style="display:inline;" for="elementor_smart_script_delay"><strong>Smart Idle Script Hydration (INP & TBT Booster)</strong></label>
								<p class="description" style="margin-left: 24px; color: var(--wppe-text-light); font-size: 13px;">Defers heavy Elementor frontend JavaScript execution to browser idle time (requestIdleCallback) or user interaction. Reduces Total Blocking Time (TBT) to near 0ms without breaking layouts.</p>
							</div>

							<div class="wppe-form-row">
								<input type="checkbox" name="elementor_instant_mobile_menu" id="elementor_instant_mobile_menu" <?php checked( $settings->get( 'elementor_instant_mobile_menu', true ) ); ?> />
								<label style="display:inline;" for="elementor_instant_mobile_menu"><strong>Instant Mobile Hamburger Menu Fallback</strong></label>
								<p class="description" style="margin-left: 24px; color: var(--wppe-text-light); font-size: 13px;">Injects a micro vanilla JS click handler ensuring mobile hamburger navigation menus open instantly with 0 latency even before full Elementor scripts finish hydrating.</p>
							</div>

							<div class="wppe-form-row">
								<input type="checkbox" name="elementor_optimize_dom" id="elementor_optimize_dom" <?php checked( $settings->get( 'elementor_optimize_dom', true ) ); ?> />
								<label style="display:inline;" for="elementor_optimize_dom"><strong>DOM & HTML Comment Cleaner</strong></label>
								<p class="description" style="margin-left: 24px; color: var(--wppe-text-light); font-size: 13px;">Strips Elementor debug comments (&lt;!-- .elementor-element --&gt;) and compresses redundant whitespace between tags while strictly preserving scripts and preformatted code.</p>
							</div>

							<div class="wppe-form-row">
								<input type="checkbox" name="elementor_optimize_google_fonts" id="elementor_optimize_google_fonts" <?php checked( $settings->get( 'elementor_optimize_google_fonts', true ) ); ?> />
								<label style="display:inline;" for="elementor_optimize_google_fonts"><strong>Google Fonts Optimizer (display=swap + Preconnect)</strong></label>
								<p class="description" style="margin-left: 24px; color: var(--wppe-text-light); font-size: 13px;">Injects preconnect resource hints and automatically appends <code>display=swap</code> to Elementor Google Font requests to eliminate render-blocking font stalls.</p>
							</div>

							<div class="wppe-form-row">
								<input type="checkbox" name="elementor_remove_fa4_shim" id="elementor_remove_fa4_shim" <?php checked( $settings->get( 'elementor_remove_fa4_shim', true ) ); ?> />
								<label style="display:inline;" for="elementor_remove_fa4_shim"><strong>Remove Obsolete Font Awesome 4 Shim</strong></label>
								<p class="description" style="margin-left: 24px; color: var(--wppe-text-light); font-size: 13px;">Removes obsolete legacy Font Awesome 4 compatibility shim stylesheets and scripts that add unnecessary HTTP requests.</p>
							</div>

							<div class="wppe-form-row">
								<input type="checkbox" name="elementor_disable_telemetry" id="elementor_disable_telemetry" <?php checked( $settings->get( 'elementor_disable_telemetry', true ) ); ?> />
								<label style="display:inline;" for="elementor_disable_telemetry"><strong>Block Elementor Background Telemetry / Tracking</strong></label>
								<p class="description" style="margin-left: 24px; color: var(--wppe-text-light); font-size: 13px;">Prevents background HTTP telemetry pings to tracker.elementor.com, saving server resources and external DNS requests.</p>
							</div>

							<div class="wppe-form-row">
								<input type="checkbox" name="elementor_auto_enable_experiments" id="elementor_auto_enable_experiments" <?php checked( $settings->get( 'elementor_auto_enable_experiments', true ) ); ?> />
								<label style="display:inline;" for="elementor_auto_enable_experiments"><strong>Auto-Enable Native Performance Experiments</strong></label>
								<p class="description" style="margin-left: 24px; color: var(--wppe-text-light); font-size: 13px;">Automatically defaults Elementor core experiments (Optimized DOM, Asset Loading, CSS Loading, SVG Icons, Lazyload) to active via internal code filters.</p>
							</div>
						</div>

						<!-- Tab: LCP -->
						<div class="wppe-tab-content" id="tab-lcp">
							<h2>LCP Optimization Priority</h2>
							<p>Automatically detects the hero or featured image on the page and applies <code>fetchpriority="high"</code>.</p>
							<div class="wppe-form-row">
								<input type="checkbox" name="enable_lcp_priority" id="enable_lcp_priority" <?php checked( $settings->get( 'enable_lcp_priority' ) ); ?> />
								<label style="display:inline;" for="enable_lcp_priority">Enable Auto LCP Image Priority</label>
							</div>
						</div>

						<!-- Tab: Speculation -->
						<div class="wppe-tab-content" id="tab-speculation">
							<h2>Speculation Rules</h2>
							<div class="wppe-form-row">
								<input type="checkbox" name="enable_speculation" id="enable_speculation" <?php checked( $settings->get( 'enable_speculation' ) ); ?> />
								<label style="display:inline;" for="enable_speculation">Enable Speculation Rules</label>
							</div>
							<div class="wppe-form-row">
								<label for="speculation_mode">Eagerness Level Mode</label>
								<select name="speculation_mode" id="speculation_mode">
									<option value="conservative" <?php selected( $settings->get( 'speculation_mode' ), 'conservative' ); ?>>Conservative (Prefetch on Hover)</option>
									<option value="balanced" <?php selected( $settings->get( 'speculation_mode' ), 'balanced' ); ?>>Balanced (Prerender on Hover)</option>
									<option value="aggressive" <?php selected( $settings->get( 'speculation_mode' ), 'aggressive' ); ?>>Aggressive (Prerender on pointer down)</option>
								</select>
							</div>
						</div>

						<!-- Tab: Database -->
						<div class="wppe-tab-content" id="tab-database">
							<h2>Database Housekeeping</h2>
							<div class="wppe-form-row">
								<label for="db_cleanup_revisions_retention">Post Revisions Retention Limit</label>
								<input type="number" name="db_cleanup_revisions_retention" id="db_cleanup_revisions_retention" value="<?php echo esc_attr( $settings->get( 'db_cleanup_revisions_retention', 10 ) ); ?>" />
								<p class="description">How many recent revisions to keep per post. Older revisions will be deleted.</p>
							</div>

							<h3>Available Optimization Targets</h3>
							<table class="wppe-table">
								<thead>
									<tr>
										<th>Cleanup Target</th>
										<th>Candidate Count</th>
										<th>Description</th>
										<th>Execution</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $db_stats as $key => $data ) : ?>
										<tr>
											<td><strong><?php echo esc_html( $data['label'] ); ?></strong></td>
											<td>
												<span class="wppe-item-count">
													<?php echo isset( $data['size'] ) ? esc_html( $data['size'] . ' MB' ) : esc_html( $data['count'] ); ?>
												</span>
											</td>
											<td><?php echo esc_html( $data['desc'] ); ?></td>
											<td>
												<button type="button" class="wppe-btn wppe-cleanup-btn" data-category="<?php echo esc_attr( $key ); ?>">Safe Clean</button>
												<?php if ( 'revisions' === $key || 'expired_transients' === $key || 'trash_posts' === $key ) : ?>
													<button type="button" class="wppe-btn wppe-btn-danger wppe-cleanup-btn wppe-aggressive" data-category="<?php echo esc_attr( $key ); ?>">Aggressive Clean</button>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<!-- Tab: Integrations -->
						<div class="wppe-tab-content" id="tab-integrations">
							<h2>Cloudflare Free Integration</h2>
							<div class="wppe-form-row">
								<label for="cloudflare_api_token">Cloudflare API Token</label>
								<input type="password" name="cloudflare_api_token" id="cloudflare_api_token" value="<?php echo esc_attr( $display_token ); ?>" />
								<p class="description">Gunakan <strong>API Token</strong> (Bearer format), bukan Global API Key. Izin yang wajib: <strong>Zone &rarr; Zone: Read</strong> dan <strong>Zone &rarr; Cache Purge: Purge</strong>. Pastikan Zone Resources mencakup domain Anda.</p>
							</div>
							<div class="wppe-form-row">
								<label for="cloudflare_zone_id">Cloudflare Zone ID</label>
								<input type="text" name="cloudflare_zone_id" id="cloudflare_zone_id" value="<?php echo esc_attr( $settings->get( 'cloudflare_zone_id' ) ); ?>" />
								<p class="description">Salin <strong>Zone ID</strong> 32-karakter dari halaman Overview domain Anda di Cloudflare (bukan Account ID).</p>
							</div>
							<div class="wppe-form-row">
								<button type="button" class="wppe-btn" id="wppe-cf-test-btn">Test Connection</button>
								<span id="wppe-cf-status" style="margin-left: 10px;"></span>
							</div>
						</div>

						<!-- Tab: Settings -->
						<div class="wppe-tab-content" id="tab-settings">
							<h2>General Engine Settings</h2>
							<div class="wppe-form-row">
								<input type="checkbox" name="debug_mode" id="debug_mode" <?php checked( $settings->get( 'debug_mode' ) ); ?> />
								<label style="display:inline;" for="debug_mode">Enable Structured Logging (Debug Mode)</label>
							</div>
							<div class="wppe-form-row">
								<input type="checkbox" name="failsafe_enabled" id="failsafe_enabled" <?php checked( $settings->get( 'failsafe_enabled' ) ); ?> />
								<label style="display:inline;" for="failsafe_enabled">Enable Automatic Failsafe Rollback (Disables engine features upon multiple PHP errors)</label>
							</div>
							<div class="wppe-form-row">
								<input type="checkbox" name="telemetry_opt_in" id="telemetry_opt_in" <?php checked( $settings->get( 'telemetry_opt_in' ) ); ?> />
								<label style="display:inline;" for="telemetry_opt_in">Opt-in to local anonymous diagnostic tracking</label>
							</div>

							<h3>Engine Execution Log</h3>
							<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
								<div style="display: flex; align-items: center; gap: 6px;">
									<input type="checkbox" id="wppe-log-auto-refresh" checked style="margin: 0;" />
									<label for="wppe-log-auto-refresh" style="font-weight: 500; font-size: 13px; cursor: pointer; user-select: none;"><?php esc_html_e( 'Auto Refresh (5s)', 'wp-performance-engine' ); ?></label>
								</div>
								<button type="button" id="wppe-clear-logs-btn" class="wppe-btn wppe-btn-danger" style="padding: 6px 12px; font-size: 12px; line-height: 1; border-radius: 4px;"><?php esc_html_e( 'Clear Logs', 'wp-performance-engine' ); ?></button>
							</div>
							<?php
							$log_file = WP_CONTENT_DIR . '/uploads/wp-performance-engine/logs/debug.log';
							$logs = 'Log empty or debug mode disabled.';
							if ( file_exists( $log_file ) ) {
								$logs = esc_textarea( file_get_contents( $log_file ) );
							}
							?>
							<textarea id="wppe-log-textarea" rows="15" readonly style="width:100%; font-family: monospace; font-size:12px; background:#f1f5f9; border: 1px solid var(--wppe-border); border-radius: 6px; padding: 12px; line-height: 1.5; color: #334155;"><?php echo $logs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></textarea>
						</div>

						<div style="margin-top: 32px; border-top: 1px solid var(--wppe-border); padding-top: 24px;">
							<button type="submit" name="wppe_save_settings" class="wppe-btn">Save Engine Changes</button>
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
