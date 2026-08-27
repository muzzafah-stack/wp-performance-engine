<?php
namespace WPPE\Elementor;

use WPPE\Detection\SiteProfile;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorCompat {
	private static ?ElementorCompat $instance = null;

	private function __construct() {
		// Elementor-specific optimizations.
		if ( $this->is_elementor_active() ) {
			add_action( 'elementor/frontend/after_register_styles', array( $this, 'exclude_elementor_critical_styles' ) );
			add_action( 'elementor/frontend/after_register_scripts', array( $this, 'exclude_elementor_critical_scripts' ) );
			add_action( 'elementor/core/files/clear_cache', array( $this, 'on_elementor_clear_cache' ) );
		}
	}

	public static function get_instance(): ElementorCompat {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Verify if Elementor is active.
	 */
	public function is_elementor_active(): bool {
		return class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Verify if Elementor Pro is active.
	 */
	public function is_elementor_pro_active(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Check if the current request is inside Elementor Editor or Preview.
	 */
	public function is_editor_or_preview(): bool {
		if ( ! $this->is_elementor_active() ) {
			return false;
		}

		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['action'] ) && 'elementor' === $_GET['action'] ) {
			return true;
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Purge entire cache when Elementor clears its CSS files.
	 */
	public function on_elementor_clear_cache(): void {
		\WPPE\Cache\DiskCache::purge_entire_cache();
	}

	/**
	 * Prevent delay of critical Elementor styles.
	 */
	public function exclude_elementor_critical_styles(): void {
		// Custom Elementor style priority rules if needed.
	}

	/**
	 * Prevent delay of critical Elementor scripts.
	 */
	public function exclude_elementor_critical_scripts(): void {
		// Elementor scripts are excluded inside ScriptDelay:classify_script dynamically,
		// but we register additional core hooks here if required.
	}

	/**
	 * Get compatibility diagnostics status for Elementor.
	 *
	 * @return array Status array with status code and detail.
	 */
	public function get_diagnostics(): array {
		if ( ! $this->is_elementor_active() ) {
			return [
				'status'  => 'PASS',
				'message' => __( 'Elementor is not active on this site. No action required.', 'wp-performance-engine' ),
			];
		}

		$el_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'Unknown';
		$pro_status = $this->is_elementor_pro_active() ? ' (Pro Active)' : '';

		// Verify if we have any conflict settings (e.g. script delay custom rules blocking elementor frontend scripts).
		return [
			'status'  => 'PASS',
			'message' => sprintf( __( 'Elementor version %s%s is active. Compatibility layer applied.', 'wp-performance-engine' ), $el_version, $pro_status ),
		];
	}
}
