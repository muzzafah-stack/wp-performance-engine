<?php
namespace WPPE\Arbitration;

use WPPE\Detection\SiteProfile;
use WPPE\Core\Settings;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arbiter {
	private static ?Arbiter $instance = null;
	private array $ownership_matrix = [];

	// Owner status flags.
	public const OWNER_SELF           = 'OWNER_SELF';
	public const OWNER_FLYINGPRESS     = 'OWNER_FLYINGPRESS';
	public const OWNER_PERFMATTERS      = 'OWNER_PERFMATTERS';
	public const OWNER_OTHER           = 'OWNER_OTHER';
	public const OWNER_WORDPRESS_CORE  = 'OWNER_WORDPRESS_CORE';
	public const OWNER_DISABLED        = 'DISABLED';
	public const OWNER_UNKNOWN         = 'UNKNOWN';
	public const OWNER_CONFLICT        = 'CONFLICT';

	private function __construct() {}

	public static function get_instance(): Arbiter {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the owner of a specific performance optimization domain.
	 *
	 * @param string $domain Domain code name.
	 * @return string One of the OWNER_* status constants.
	 */
	public function get_domain_owner( string $domain ): string {
		$profile = SiteProfile::get_instance()->get_profile();
		$settings = Settings::get_instance();

		$fp_active = $profile['plugins']['flyingpress'];
		$pm_active = $profile['plugins']['perfmatters'];
		$wprock_active = $profile['plugins']['wp_rocket'];
		$litespeed_active = $profile['plugins']['litespeed_cache'];

		// Read Perfmatters settings.
		$pm_options = is_string( get_option( 'perfmatters_options' ) ) ? unserialize( get_option( 'perfmatters_options' ) ) : get_option( 'perfmatters_options', [] );
		if ( ! is_array( $pm_options ) ) {
			$pm_options = [];
		}

		switch ( $domain ) {
			case 'page_cache':
				if ( $fp_active ) {
					return self::OWNER_FLYINGPRESS;
				}
				if ( $wprock_active || $litespeed_active ) {
					return self::OWNER_OTHER;
				}
				if ( $settings->get( 'enable_html_cache', false ) ) {
					return self::OWNER_SELF;
				}
				return self::OWNER_DISABLED;

			case 'js_delay':
				if ( $fp_active ) {
					return self::OWNER_FLYINGPRESS;
				}
				if ( $pm_active && isset( $pm_options['delay_js'] ) && '1' === (string) $pm_options['delay_js'] ) {
					return self::OWNER_PERFMATTERS;
				}
				if ( $wprock_active ) {
					return self::OWNER_OTHER;
				}
				if ( $settings->get( 'enable_script_delay', false ) ) {
					return self::OWNER_SELF;
				}
				return self::OWNER_DISABLED;

			case 'speculation_rules':
				// If WordPress 6.5+ speculation rules features exist or are enqueued natively.
				if ( class_exists( 'WP_Speculation_Rules' ) || function_exists( 'wp_enqueue_speculation_rules' ) ) {
					return self::OWNER_WORDPRESS_CORE;
				}
				if ( $fp_active ) {
					return self::OWNER_FLYINGPRESS;
				}
				if ( $pm_active && isset( $pm_options['instant_page'] ) && '1' === (string) $pm_options['instant_page'] ) {
					return self::OWNER_PERFMATTERS;
				}
				if ( $settings->get( 'enable_speculation', false ) ) {
					return self::OWNER_SELF;
				}
				return self::OWNER_DISABLED;

			case 'lcp_priority':
				if ( $fp_active ) {
					// FlyingPress natively optimizes critical LCP images.
					return self::OWNER_FLYINGPRESS;
				}
				if ( $settings->get( 'enable_lcp_priority', false ) ) {
					return self::OWNER_SELF;
				}
				return self::OWNER_DISABLED;

			case 'lazy_load_image':
				if ( $fp_active ) {
					return self::OWNER_FLYINGPRESS;
				}
				if ( $pm_active && isset( $pm_options['lazy_loading'] ) && '1' === (string) $pm_options['lazy_loading'] ) {
					return self::OWNER_PERFMATTERS;
				}
				// WordPress core handles loading="lazy" natively since 5.5.
				return self::OWNER_WORDPRESS_CORE;

			case 'database_cleanup':
				if ( $fp_active ) {
					return self::OWNER_FLYINGPRESS;
				}
				if ( $pm_active && isset( $pm_options['database_optimization'] ) ) {
					return self::OWNER_PERFMATTERS;
				}
				return self::OWNER_SELF; // We always expose our DB cleanup tools as self.

			default:
				return self::OWNER_UNKNOWN;
		}
	}

	/**
	 * Determine if our plugin is the authorized owner to run an optimization feature.
	 *
	 * @param string $domain Optimization domain name.
	 * @return bool True if we are the designated owner.
	 */
	public function is_authorized_owner( string $domain ): bool {
		return self::OWNER_SELF === $this->get_domain_owner( $domain );
	}

	/**
	 * Assess conflicts and overlaps.
	 * Returns structured arrays detailing optimization overlaps.
	 */
	public function get_conflict_diagnostics(): array {
		$domains = [
			'page_cache'         => [ 'label' => 'HTML Page Caching', 'our_setting' => 'enable_html_cache' ],
			'js_delay'           => [ 'label' => 'JavaScript Delay Execution', 'our_setting' => 'enable_script_delay' ],
			'speculation_rules'  => [ 'label' => 'Speculation Rules Prerendering', 'our_setting' => 'enable_speculation' ],
			'lcp_priority'       => [ 'label' => 'LCP Image Priority (fetchpriority)', 'our_setting' => 'enable_lcp_priority' ],
		];

		$diagnostics = [];

		foreach ( $domains as $domain => $data ) {
			$owner = $this->get_domain_owner( $domain );
			$settings = Settings::get_instance();
			$our_enabled = (bool) $settings->get( $data['our_setting'], false );

			$status = 'OK';
			$severity = 'INFO';
			$recommendation = '';

			if ( $our_enabled && self::OWNER_SELF !== $owner ) {
				$status = 'OVERLAP';
				$severity = 'HIGH';
				$recommendation = sprintf( 'Disable our feature. Existing owner [%s] is already handling this domain.', str_replace( 'OWNER_', '', $owner ) );
			} elseif ( ! $our_enabled && self::OWNER_SELF !== $owner && self::OWNER_DISABLED !== $owner ) {
				$status = 'MANAGED_EXTERNALLY';
				$severity = 'LOW';
				$recommendation = sprintf( 'Handled by [%s]. Keep our feature disabled.', str_replace( 'OWNER_', '', $owner ) );
			}

			$diagnostics[ $domain ] = [
				'label'          => $data['label'],
				'our_status'     => $our_enabled ? 'ON' : 'OFF',
				'owner'          => $owner,
				'status'         => $status,
				'severity'       => $severity,
				'recommendation' => $recommendation,
			];
		}

		return $diagnostics;
	}
}
