<?php
namespace WPPE\Compatibility;

use WPPE\Detection\SiteProfile;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerfmattersCompat {
	private static ?PerfmattersCompat $instance = null;

	private function __construct() {}

	public static function get_instance(): PerfmattersCompat {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Retrieve Perfmatters settings if available.
	 */
	public function get_perfmatters_settings(): array {
		$pm_options = is_string( get_option( 'perfmatters_options' ) ) ? unserialize( get_option( 'perfmatters_options' ) ) : get_option( 'perfmatters_options', [] );
		return is_array( $pm_options ) ? $pm_options : [];
	}

	/**
	 * Get compatibility diagnostic data for Perfmatters.
	 */
	public function get_diagnostics(): array {
		$profile = SiteProfile::get_instance()->get_profile();
		if ( ! $profile['plugins']['perfmatters'] ) {
			return [
				'active' => false,
				'conflicts' => [],
			];
		}

		$options = $this->get_perfmatters_settings();
		$conflicts = [];

		if ( isset( $options['delay_js'] ) && '1' === (string) $options['delay_js'] ) {
			$conflicts[] = 'JavaScript Delay';
		}
		if ( isset( $options['defer_js'] ) && '1' === (string) $options['defer_js'] ) {
			$conflicts[] = 'JavaScript Defer';
		}
		if ( isset( $options['lazy_loading'] ) && '1' === (string) $options['lazy_loading'] ) {
			$conflicts[] = 'Image Lazy Load';
		}
		if ( isset( $options['instant_page'] ) && '1' === (string) $options['instant_page'] ) {
			$conflicts[] = 'Instant Page (Speculation)';
		}

		return [
			'active'    => true,
			'conflicts' => $conflicts,
		];
	}
}
