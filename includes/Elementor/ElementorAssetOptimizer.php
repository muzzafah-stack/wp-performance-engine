<?php
namespace WPPE\Elementor;

use WPPE\Core\Settings;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorAssetOptimizer {
	private static ?ElementorAssetOptimizer $instance = null;

	private function __construct() {
		// Hook late on frontend to optimize and prune enqueued assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'optimize_enqueued_assets' ), 9999 );
		add_action( 'wp_head', array( $this, 'inject_resource_hints' ), 1 );
		add_filter( 'style_loader_src', array( $this, 'optimize_google_font_url' ), 10, 2 );
		add_filter( 'style_loader_tag', array( $this, 'optimize_style_tags' ), 10, 4 );

		// Block telemetry.
		add_filter( 'pre_http_request', array( $this, 'block_elementor_telemetry' ), 10, 3 );
	}

	public static function get_instance(): ElementorAssetOptimizer {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if asset optimization is enabled and active.
	 */
	public function is_enabled(): bool {
		$settings = Settings::get_instance();
		return (bool) $settings->get( 'elementor_optimize_assets', true );
	}

	/**
	 * Inject preconnect hints for Google Fonts and CDNs in wp_head.
	 */
	public function inject_resource_hints(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$compat = ElementorCompat::get_instance();
		if ( $compat->is_editor_or_preview() ) {
			return;
		}

		$settings = Settings::get_instance();
		if ( $settings->get( 'elementor_optimize_google_fonts', true ) ) {
			echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
			echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
		}
	}

	/**
	 * Optimize Google Fonts URL with display=swap.
	 *
	 * @param string $src    Stylesheet source URL.
	 * @param string $handle Style handle.
	 * @return string
	 */
	public function optimize_google_font_url( string $src, string $handle ): string {
		if ( ! $this->is_enabled() ) {
			return $src;
		}

		$settings = Settings::get_instance();
		if ( ! $settings->get( 'elementor_optimize_google_fonts', true ) ) {
			return $src;
		}

		if ( false !== strpos( $src, 'fonts.googleapis.com/css' ) ) {
			if ( false === strpos( $src, 'display=' ) ) {
				$src = add_query_arg( 'display', 'swap', $src );
			}
		}

		return $src;
	}

	/**
	 * Optimize style loader tags (e.g. eicons, font-display swap).
	 *
	 * @param string $html   Tag HTML.
	 * @param string $handle Style handle.
	 * @param string $href   Style href.
	 * @param string $media  Style media.
	 * @return string
	 */
	public function optimize_style_tags( string $html, string $handle, string $href, string $media ): string {
		if ( ! $this->is_enabled() ) {
			return $html;
		}

		$compat = ElementorCompat::get_instance();
		if ( $compat->is_editor_or_preview() ) {
			return $html;
		}

		// Ensure Google Font links include display=swap even if hardcoded.
		if ( false !== strpos( $href, 'fonts.googleapis.com' ) && false === strpos( $html, 'display=' ) ) {
			$html = str_replace( 'fonts.googleapis.com/css?', 'fonts.googleapis.com/css?display=swap&', $html );
		}

		return $html;
	}

	/**
	 * Main asset pruning and optimization routine.
	 */
	public function optimize_enqueued_assets(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$compat = ElementorCompat::get_instance();
		if ( $compat->is_editor_or_preview() ) {
			return;
		}

		$settings = Settings::get_instance();

		// 1. Remove obsolete Font Awesome 4 Shim if enabled.
		if ( $settings->get( 'elementor_remove_fa4_shim', true ) ) {
			wp_dequeue_style( 'font-awesome-4-shim' );
			wp_deregister_style( 'font-awesome-4-shim' );
			wp_dequeue_script( 'font-awesome-4-shim' );
			wp_deregister_script( 'font-awesome-4-shim' );
		}

		// 2. Prune unused Pro Elements / Elementor Pro Widget Assets on singular pages.
		$this->prune_unused_pro_widget_assets();
	}

	/**
	 * Inspect page widget usage and dequeue unused Pro Elements / Elementor Pro scripts and styles.
	 */
	private function prune_unused_pro_widget_assets(): void {
		$compat = ElementorCompat::get_instance();
		if ( $compat->is_editor_or_preview() ) {
			return;
		}

		// Never prune assets when editing or viewing Theme Builder templates (Header, Footer, Single Post, Archive, etc.).
		if ( ( function_exists( 'is_singular' ) && is_singular( 'elementor_library' ) ) ||
		     ( function_exists( 'get_post_type' ) && 'elementor_library' === get_post_type() ) ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		// Get Elementor data.
		$raw_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw_data ) ) {
			return;
		}

		$data_str = is_string( $raw_data ) ? $raw_data : wp_json_encode( $raw_data );

		// Check widget presence by widgetType identifier.
		$has_form         = ( false !== strpos( $data_str, '"widgetType":"form"' ) );
		$has_lottie       = ( false !== strpos( $data_str, '"widgetType":"lottie"' ) );
		$has_share_btn    = ( false !== strpos( $data_str, '"widgetType":"share-buttons"' ) );
		$has_nav_menu     = ( false !== strpos( $data_str, '"widgetType":"nav-menu"' ) );

		// Prune Form Datepicker & Flatpickr if no form widget.
		if ( ! $has_form ) {
			wp_dequeue_script( 'flatpickr' );
			wp_dequeue_style( 'flatpickr' );
		}

		// Prune Lottie player if no lottie widget.
		if ( ! $has_lottie ) {
			wp_dequeue_script( 'e-lottie' );
			wp_dequeue_script( 'lottie-player' );
		}

		// When Pro Elements or Elementor Pro Theme Builder is active, headers and footers are injected
		// from separate template posts. Only prune if Pro Elements Theme Builder is not active.
		$is_pro_active = $compat->is_pro_elements_active() || $compat->is_elementor_pro_active();
		if ( ! $is_pro_active ) {
			if ( ! $has_share_btn ) {
				wp_dequeue_script( 'share-link' );
			}
			if ( ! $has_nav_menu ) {
				wp_dequeue_script( 'smartmenus' );
			}
		}
	}

	/**
	 * Block remote telemetry / analytics calls to tracker.elementor.com.
	 *
	 * @param false|array|\WP_Error $preempt
	 * @param array                  $parsed_args
	 * @param string                 $url
	 * @return false|array|\WP_Error
	 */
	public function block_elementor_telemetry( $preempt, array $parsed_args, string $url ) {
		$settings = Settings::get_instance();
		if ( ! $settings->get( 'elementor_disable_telemetry', true ) ) {
			return $preempt;
		}

		if ( false !== strpos( $url, 'tracker.elementor.com' ) || false !== strpos( $url, 'elementor.com/api/v1/telemetry' ) ) {
			return [
				'response' => [
					'code'    => 200,
					'message' => 'Blocked by WP Performance Engine',
				],
				'body'     => wp_json_encode( [ 'status' => 'blocked' ] ),
			];
		}

		return $preempt;
	}
}
