<?php
namespace WPPE\Elementor;

use WPPE\Core\Settings;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorScriptOptimizer {
	private static ?ElementorScriptOptimizer $instance = null;

	private function __construct() {
		add_action( 'wp_footer', array( $this, 'inject_fast_mobile_menu_script' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'optimize_script_attributes' ), 9999 );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_loader_tag' ), 10, 3 );
	}

	public static function get_instance(): ElementorScriptOptimizer {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if smart script optimization is enabled.
	 */
	public function is_enabled(): bool {
		$settings = Settings::get_instance();
		return (bool) $settings->get( 'elementor_smart_script_delay', true );
	}

	/**
	 * Inject instant vanilla JS mobile hamburger menu handler.
	 * Guarantees zero latency on mobile nav menu toggle even before heavy Elementor JS hydrates.
	 */
	public function inject_fast_mobile_menu_script(): void {
		$settings = Settings::get_instance();
		if ( ! $settings->get( 'elementor_instant_mobile_menu', true ) ) {
			return;
		}

		$compat = ElementorCompat::get_instance();
		if ( ! $compat->is_elementor_active() || $compat->is_editor_or_preview() ) {
			return;
		}

		echo $this->get_fast_mobile_menu_script_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Get the fast mobile menu inline script string.
	 * Supports classic .elementor-menu-toggle and modern .e-n-menu-toggle (Nested Menu).
	 */
	public function get_fast_mobile_menu_script_tag(): string {
		return '
<script id="wppe-elementor-fast-menu">
(function() {
	if (window.wppeFastMenuInitialized) return;
	window.wppeFastMenuInitialized = true;
	document.addEventListener("click", function(e) {
		// Classic Elementor Nav Menu
		var toggle = e.target.closest(".elementor-menu-toggle");
		if (toggle) {
			var widget = toggle.closest(".elementor-widget-nav-menu, .elementor-widget");
			if (widget) {
				var dropdown = widget.querySelector(".elementor-nav-menu--dropdown");
				if (dropdown) {
					var isOpen = toggle.classList.contains("elementor-active");
					toggle.classList.toggle("elementor-active", !isOpen);
					toggle.setAttribute("aria-expanded", !isOpen ? "true" : "false");
					dropdown.classList.toggle("elementor-active", !isOpen);
					if (dropdown.style.display === "block" || (!isOpen && dropdown.classList.contains("elementor-active"))) {
						dropdown.style.display = isOpen ? "none" : "block";
					}
				}
			}
			return;
		}

		// Modern Nested Menu Toggle (.e-n-menu-toggle)
		var nestedToggle = e.target.closest(".e-n-menu-toggle");
		if (nestedToggle) {
			var nWidget = nestedToggle.closest(".elementor-widget-nested-menu, .elementor-widget-n-menu, .e-n-menu");
			if (nWidget) {
				var nWrapper = nWidget.querySelector(".e-n-menu-wrapper");
				if (nWrapper) {
					var isNOpen = nestedToggle.getAttribute("aria-expanded") === "true";
					nestedToggle.setAttribute("aria-expanded", isNOpen ? "false" : "true");
					nestedToggle.classList.toggle("e-active", !isNOpen);
					nWrapper.classList.toggle("e-active", !isNOpen);
				}
			}
		}
	}, { passive: true });
})();
</script>
';
	}

	/**
	 * Defer non-critical Elementor scripts to eliminate render-blocking.
	 */
	public function optimize_script_attributes(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$compat = ElementorCompat::get_instance();
		if ( $compat->is_editor_or_preview() ) {
			return;
		}

		// List of handles we can safely defer (exclude synchronous core modules like elementor-frontend-modules and elementor-dialog).
		$defer_handles = [
			'elementor-waypoints',
			'share-link',
			'elementor-pro-notes-frontend',
		];

		foreach ( $defer_handles as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) ) {
				wp_script_add_data( $handle, 'strategy', 'defer' );
			}
		}
	}

	/**
	 * Add defer attribute to script tags where appropriate.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public function filter_script_loader_tag( string $tag, string $handle, string $src ): string {
		if ( ! $this->is_enabled() ) {
			return $tag;
		}

		$compat = ElementorCompat::get_instance();
		if ( $compat->is_editor_or_preview() ) {
			return $tag;
		}

		// Add defer if handle matches and not already deferred.
		if ( in_array( $handle, [ 'elementor-waypoints', 'share-link' ], true ) ) {
			if ( false === strpos( $tag, 'defer' ) && false === strpos( $tag, 'async' ) ) {
				$tag = str_replace( '<script ', '<script defer ', $tag );
			}
		}

		return $tag;
	}
}
