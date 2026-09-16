<?php
namespace WPPE\Elementor;

use WPPE\Core\Settings;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorDomOptimizer {
	private static ?ElementorDomOptimizer $instance = null;
	private bool $is_hooked = false;

	private function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ), 9997 );
	}

	public static function get_instance(): ElementorDomOptimizer {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if DOM optimization should run.
	 */
	public function should_optimize(): bool {
		$settings = Settings::get_instance();
		if ( ! $settings->get( 'elementor_optimize_dom', true ) ) {
			return false;
		}

		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return false;
		}

		$compat = ElementorCompat::get_instance();
		if ( ! $compat->is_elementor_active() || $compat->is_editor_or_preview() ) {
			return false;
		}

		return true;
	}

	/**
	 * Start output buffer if conditions are met.
	 */
	public function maybe_start_buffer(): void {
		if ( $this->should_optimize() ) {
			$this->is_hooked = true;
			ob_start( array( $this, 'optimize_html_dom' ) );
		}
	}

	/**
	 * Optimize and clean Elementor DOM and HTML comments.
	 *
	 * @param string $html Output HTML string.
	 * @return string Optimized HTML.
	 */
	public function optimize_html_dom( string $html ): string {
		if ( ! $this->is_hooked || empty( $html ) ) {
			return $html;
		}

		$this->is_hooked = false;

		// Skip incomplete HTML.
		if ( false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		// 1. Strip Elementor and WordPress debug HTML comments safely.
		// Protect conditional comments like <!--[if ...]> and schema scripts.
		$html = preg_replace( '/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html );

		// 2. Safely compress excess whitespace between tags outside <pre>, <textarea>, <script>, <style>.
		$html = $this->compress_html_whitespace( $html );

		return $html;
	}

	/**
	 * Safely compress whitespace outside preformatted blocks.
	 *
	 * @param string $html HTML string.
	 * @return string
	 */
	public function compress_html_whitespace( string $html ): string {
		// Extract pre, textarea, script, style blocks into placeholders.
		$placeholders = [];
		$token_index  = 0;

		$patterns = [
			'/<pre\b[^>]*>.*?<\/pre>/is',
			'/<textarea\b[^>]*>.*?<\/textarea>/is',
			'/<script\b[^>]*>.*?<\/script>/is',
			'/<style\b[^>]*>.*?<\/style>/is',
		];

		foreach ( $patterns as $pattern ) {
			$html = preg_replace_callback( $pattern, function( $matches ) use ( &$placeholders, &$token_index ) {
				$token = '<!--WPPE_DOM_PRESERVE_' . ( ++$token_index ) . '-->';
				$placeholders[ $token ] = $matches[0];
				return $token;
			}, $html );
		}

		// Compress multiple consecutive spaces, tabs, and newlines between tags.
		$html = preg_replace( '/>\s{2,}</', '> <', $html );
		$html = preg_replace( '/\n\s*\n/', "\n", $html );

		// Restore protected blocks.
		if ( ! empty( $placeholders ) ) {
			$html = strtr( $html, $placeholders );
		}

		return $html;
	}
}
