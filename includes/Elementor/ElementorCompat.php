<?php
namespace WPPE\Elementor;

use WPPE\Detection\SiteProfile;
use WPPE\Core\Settings;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorCompat {
	private static ?ElementorCompat $instance = null;

	private function __construct() {
		if ( $this->is_elementor_active() ) {
			// Initialize child optimization engines.
			ElementorAssetOptimizer::get_instance();
			ElementorDomOptimizer::get_instance();
			ElementorScriptOptimizer::get_instance();

			// Cache invalidation listener.
			add_action( 'elementor/core/files/clear_cache', array( $this, 'on_elementor_clear_cache' ) );

			// Auto-tune experimental features filter if enabled.
			add_filter( 'elementor/experiments/default_features', array( $this, 'filter_elementor_experiments' ), 10, 1 );
		}
	}

	public static function get_instance(): ElementorCompat {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Verify if Elementor Core is active.
	 */
	public function is_elementor_active(): bool {
		return class_exists( '\Elementor\Plugin' ) || defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Verify if Pro Elements is active.
	 */
	public function is_pro_elements_active(): bool {
		return defined( 'PRO_ELEMENTS_VERSION' ) || defined( 'PRO_ELEMENTS__FILE__' ) || class_exists( '\ProElements\Plugin' );
	}

	/**
	 * Verify if Elementor Pro or Pro Elements is active.
	 */
	public function is_elementor_pro_active(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\ElementorPro\Plugin' ) || $this->is_pro_elements_active();
	}

	/**
	 * Get Pro Elements or Elementor Pro version.
	 */
	public function get_pro_version(): string {
		if ( defined( 'PRO_ELEMENTS_VERSION' ) ) {
			return PRO_ELEMENTS_VERSION;
		}
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return ELEMENTOR_PRO_VERSION;
		}
		return 'N/A';
	}

	/**
	 * Check if the current request is inside Elementor Editor or Preview.
	 */
	public function is_editor_or_preview(): bool {
		if ( ! $this->is_elementor_active() ) {
			return false;
		}

		if ( isset( $_GET['elementor-preview'] ) || ( isset( $_GET['action'] ) && 'elementor' === $_GET['action'] ) ) {
			return true;
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) && isset( \Elementor\Plugin::$instance->editor ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() || ( isset( \Elementor\Plugin::$instance->preview ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) ) {
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
		Logger::info( 'Elementor cleared CSS cache. WP Performance Engine disk cache purged.' );
	}

	/**
	 * Auto-tune Elementor default experimental features via filter.
	 *
	 * @param array $features Array of experimental features.
	 * @return array
	 */
	public function filter_elementor_experiments( array $features ): array {
		$settings = Settings::get_instance();
		if ( ! $settings->get( 'elementor_auto_enable_experiments', true ) ) {
			return $features;
		}

		$tune_keys = [
			'e_dom_optimization',
			'e_optimized_assets_loading',
			'e_optimized_css_loading',
			'e_font_icon_svg',
			'e_lazyload_images',
		];

		foreach ( $tune_keys as $key ) {
			if ( isset( $features[ $key ] ) && is_array( $features[ $key ] ) ) {
				$features[ $key ]['default'] = 'active';
			}
		}

		return $features;
	}

	/**
	 * Write recommended Elementor performance settings to DB options.
	 *
	 * @return array Result message and status.
	 */
	public function auto_tune_experiments(): array {
		if ( ! $this->is_elementor_active() ) {
			return [
				'success' => false,
				'message' => __( 'Elementor is not active.', 'wp-performance-engine' ),
			];
		}

		// Activate native performance experiments.
		update_option( 'elementor_experiment-e_dom_optimization', 'active' );
		update_option( 'elementor_experiment-e_optimized_assets_loading', 'active' );
		update_option( 'elementor_experiment-e_optimized_css_loading', 'active' );
		update_option( 'elementor_experiment-e_font_icon_svg', 'active' );
		update_option( 'elementor_experiment-e_lazyload_images', 'active' );

		// Set CSS print method to external file for maximum cacheability.
		update_option( 'elementor_css_print_method', 'external' );

		// Clear Elementor CSS cache to force regeneration with optimized settings.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		Logger::info( 'Auto-tuned Elementor native experiments to optimal performance settings.' );

		return [
			'success' => true,
			'message' => __( 'Elementor performance experiments successfully auto-tuned (DOM optimization, Asset loading, CSS loading, SVG icons, and Lazy loading enabled).', 'wp-performance-engine' ),
		];
	}

	/**
	 * Get compatibility diagnostics status for Elementor and Pro Elements.
	 *
	 * @return array Status array with status code and detail.
	 */
	public function get_diagnostics(): array {
		if ( ! $this->is_elementor_active() ) {
			return [
				'status'  => 'PASS',
				'message' => __( 'Elementor is not active on this site. No action required.', 'wp-performance-engine' ),
				'details' => [],
			];
		}

		$el_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'Active';
		$pro_label  = 'None';
		if ( $this->is_pro_elements_active() ) {
			$pro_label = 'Pro Elements v' . $this->get_pro_version() . ' (GPL Free Pro)';
		} elseif ( $this->is_elementor_pro_active() ) {
			$pro_label = 'Elementor Pro v' . $this->get_pro_version();
		}

		$experiments = [
			'e_dom_optimization'         => get_option( 'elementor_experiment-e_dom_optimization', 'default' ),
			'e_optimized_assets_loading' => get_option( 'elementor_experiment-e_optimized_assets_loading', 'default' ),
			'e_optimized_css_loading'    => get_option( 'elementor_experiment-e_optimized_css_loading', 'default' ),
			'e_font_icon_svg'            => get_option( 'elementor_experiment-e_font_icon_svg', 'default' ),
			'e_lazyload_images'          => get_option( 'elementor_experiment-e_lazyload_images', 'default' ),
		];

		return [
			'status'      => 'PASS',
			'elementor'   => $el_version,
			'pro'         => $pro_label,
			'experiments' => $experiments,
			'message'     => sprintf( __( 'Elementor %s with %s detected. Optimization suite active.', 'wp-performance-engine' ), $el_version, $pro_label ),
		];
	}
}
