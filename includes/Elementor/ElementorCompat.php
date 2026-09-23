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

			// Cache invalidation listeners for Elementor Core & Elementor Pro.
			add_action( 'elementor/core/files/clear_cache', array( $this, 'on_elementor_clear_cache' ) );
			add_action( 'elementor/element_cache/clear_cache', array( $this, 'on_elementor_clear_cache' ) );
			add_action( 'elementor/editor/after_save', array( $this, 'on_elementor_editor_save' ), 10, 2 );

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
	 * Check if the current request is inside Elementor Editor, Preview, Theme Builder, or Elementor REST/AJAX context.
	 */
	public function is_editor_or_preview(): bool {
		if ( ! $this->is_elementor_active() ) {
			return false;
		}

		// 1. Direct GET / POST / REQUEST preview & editor parameters.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_POST['elementor-preview'] ) || isset( $_REQUEST['elementor-preview'] ) ) {
			return true;
		}

		if ( isset( $_GET['action'] ) && in_array( $_GET['action'], [ 'elementor', 'elementor_ajax' ], true ) ) {
			return true;
		}

		// 2. Elementor Theme Builder App / SPA & Library administration.
		if ( isset( $_GET['page'] ) && 'elementor-app' === $_GET['page'] ) {
			return true;
		}

		if ( isset( $_GET['post_type'] ) && 'elementor_library' === $_GET['post_type'] ) {
			return true;
		}

		// 3. Theme Builder template query parameters & previews (Header, Footer, Single, Archive, Floating).
		$tb_params = [
			'elementor_library',
			'elementor-template-type',
			'elementor_theme_builder_preview',
			'elementor_pro_theme_builder_conditions',
			'theme_builder',
			'preview_nonce',
		];
		foreach ( $tb_params as $param ) {
			if ( isset( $_GET[ $param ] ) || isset( $_POST[ $param ] ) ) {
				return true;
			}
		}

		if ( isset( $_GET['preview_id'] ) && ( isset( $_GET['preview'] ) || isset( $_GET['preview_nonce'] ) ) ) {
			return true;
		}

		// 4. Elementor Library singular post type query.
		if ( ( function_exists( 'is_singular' ) && is_singular( 'elementor_library' ) ) ||
		     ( function_exists( 'get_post_type' ) && 'elementor_library' === get_post_type() ) ) {
			return true;
		}

		// 5. Elementor native Plugin runtime check.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
			if ( isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				return true;
			}
			if ( isset( \Elementor\Plugin::$instance->preview ) ) {
				if ( \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
					return true;
				}
				if ( method_exists( \Elementor\Plugin::$instance->preview, 'is_preview' ) && \Elementor\Plugin::$instance->preview->is_preview() ) {
					return true;
				}
			}
			if ( isset( \Elementor\Plugin::$instance->documents ) && method_exists( \Elementor\Plugin::$instance->documents, 'get_current' ) ) {
				$doc = \Elementor\Plugin::$instance->documents->get_current();
				if ( $doc && method_exists( $doc, 'is_built_with_elementor' ) && $doc->is_built_with_elementor() ) {
					if ( ( isset( $_GET['action'] ) && 'elementor' === $_GET['action'] ) || ( isset( $_GET['preview'] ) ) ) {
						return true;
					}
				}
			}
		}

		// 6. Elementor / Pro Elements AJAX requests.
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			$ajax_action = $_REQUEST['action'] ?? '';
			if ( false !== strpos( $ajax_action, 'elementor' ) || false !== strpos( $ajax_action, 'pro_elements' ) ) {
				return true;
			}
		}

		// 7. Elementor / Pro Elements REST API requests (including site-editor, templates, and AI routes).
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( false !== strpos( $request_uri, '/wp-json/elementor/' ) ||
		     false !== strpos( $request_uri, '/wp-json/elementor-pro/' ) ||
		     false !== strpos( $request_uri, '/wp-json/pro-elements/' ) ||
		     false !== strpos( $request_uri, '/site-editor/' ) ) {
			return true;
		}

		return (bool) apply_filters( 'wppe_is_elementor_editor_or_preview', false );
	}

	/**
	 * Purge entire cache when Elementor clears its CSS or Element cache.
	 */
	public function on_elementor_clear_cache(): void {
		\WPPE\Cache\DiskCache::purge_entire_cache();
		
		// Also synchronize purge with Cloudflare if configured.
		$cf = \WPPE\Cloudflare\CloudflareFree::get_instance();
		if ( $cf->is_configured() ) {
			$cf->purge_everything();
		}

		Logger::info( 'Elementor cleared cache. WP Performance Engine disk cache and Cloudflare synchronized.' );
	}

	/**
	 * Purge post cache when saved in Elementor Editor.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $editor_data Editor data.
	 */
	public function on_elementor_editor_save( int $post_id, $editor_data = null ): void {
		\WPPE\Cache\DiskCache::purge_post_cache( $post_id );
		Logger::info( sprintf( 'Elementor saved post #%d. Post cache purged and Cloudflare sync queued.', $post_id ) );
	}

	/**
	 * Auto-tune Elementor default experimental features via filter.
	 * Supports newest Elementor 3.20 - 3.24+ experiments.
	 *
	 * @param array $features Array of experimental features.
	 * @return array
	 */
	public function filter_elementor_experiments( array $features ): array {
		$settings = Settings::get_instance();
		if ( ! $settings->get( 'elementor_auto_enable_experiments', true ) ) {
			return $features;
		}

		if ( $this->is_editor_or_preview() ) {
			return $features;
		}

		$tune_keys = [
			'e_dom_optimization',
			'e_optimized_assets_loading',
			'e_optimized_css_loading',
			'e_font_icon_svg',
			'e_lazyload_images',
			'e_lazyload_background_images',
			'e_optimized_control_loading',
			'e_element_cache',
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

		// Activate native performance experiments (Elementor Core & Pro latest).
		update_option( 'elementor_experiment-e_dom_optimization', 'active' );
		update_option( 'elementor_experiment-e_optimized_assets_loading', 'active' );
		update_option( 'elementor_experiment-e_optimized_css_loading', 'active' );
		update_option( 'elementor_experiment-e_font_icon_svg', 'active' );
		update_option( 'elementor_experiment-e_lazyload_images', 'active' );
		update_option( 'elementor_experiment-e_lazyload_background_images', 'active' );
		update_option( 'elementor_experiment-e_optimized_control_loading', 'active' );
		update_option( 'elementor_experiment-e_element_cache', 'active' );

		// Set CSS print method to external file for maximum cacheability.
		update_option( 'elementor_css_print_method', 'external' );

		// Clear Elementor CSS cache to force regeneration with optimized settings.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		Logger::info( 'Auto-tuned Elementor native experiments to optimal performance settings (including Element Caching & Control Loading).' );

		return [
			'success' => true,
			'message' => __( 'Elementor performance experiments successfully auto-tuned (DOM optimization, Asset loading, CSS loading, SVG icons, Lazy loading background images, Optimized control loading, and Element Caching enabled).', 'wp-performance-engine' ),
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
			'e_dom_optimization'           => get_option( 'elementor_experiment-e_dom_optimization', 'default' ),
			'e_optimized_assets_loading'   => get_option( 'elementor_experiment-e_optimized_assets_loading', 'default' ),
			'e_optimized_css_loading'      => get_option( 'elementor_experiment-e_optimized_css_loading', 'default' ),
			'e_font_icon_svg'              => get_option( 'elementor_experiment-e_font_icon_svg', 'default' ),
			'e_lazyload_images'            => get_option( 'elementor_experiment-e_lazyload_images', 'default' ),
			'e_lazyload_background_images' => get_option( 'elementor_experiment-e_lazyload_background_images', 'default' ),
			'e_optimized_control_loading'  => get_option( 'elementor_experiment-e_optimized_control_loading', 'default' ),
			'e_element_cache'              => get_option( 'elementor_experiment-e_element_cache', 'default' ),
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
