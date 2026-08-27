<?php
namespace WPPE\LCP;

use WPPE\Arbitration\Arbiter;
use WPPE\Core\Settings;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCPPriority {
	private static ?LCPPriority $instance = null;
	private bool $is_hooked = false;

	private function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ), 9998 );
	}

	public static function get_instance(): LCPPriority {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if LCP optimization should run.
	 */
	public function should_optimize(): bool {
		$arbiter = Arbiter::get_instance();
		if ( ! $arbiter->is_authorized_owner( 'lcp_priority' ) ) {
			return false;
		}

		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return false;
		}

		// Skip in Elementor preview/editor.
		$elementor = \WPPE\Core\Plugin::get_instance()->get_service( 'elementor' );
		if ( $elementor && method_exists( $elementor, 'is_editor_or_preview' ) && $elementor->is_editor_or_preview() ) {
			return false;
		}

		return true;
	}

	public function maybe_start_buffer(): void {
		if ( $this->should_optimize() ) {
			$this->is_hooked = true;
			ob_start( array( $this, 'optimize_lcp_in_html' ) );
		}
	}

	/**
	 * Parse HTML and prioritize the best LCP candidate image.
	 */
	public function optimize_lcp_in_html( string $html ): string {
		if ( ! $this->is_hooked || empty( $html ) ) {
			return $html;
		}

		$this->is_hooked = false;

		if ( false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		// Find the first <img> tag that looks like a hero/featured image.
		// We extract image tags in the <body> area.
		$body_start = stripos( $html, '<body' );
		if ( false === $body_start ) {
			return $html;
		}

		$body_html = substr( $html, $body_start );

		// Regex to capture img tags.
		preg_match_all( '/<img\b[^>]*>/is', $body_html, $matches );

		if ( empty( $matches[0] ) ) {
			return $html;
		}

		$lcp_candidate = '';
		$lcp_candidate_index = -1;

		// Class keywords we want to prioritize (standard WordPress post thumbnails).
		$priority_classes = [ 'wp-post-image', 'attachment-post-thumbnail', 'featured-image', 'hero-image' ];

		// Loop through images to find the best candidate.
		foreach ( $matches[0] as $index => $img_tag ) {
			// Skip tracker pixels, small icons or logos.
			if ( preg_match( '/\bwidth\s*=\s*["\']([1-9]|10|11|12|13|14|15|16|24|32|48|64|80|96|120|128|150)["\']/i', $img_tag ) ||
				preg_match( '/\bheight\s*=\s*["\']([1-9]|10|11|12|13|14|15|16|24|32|48|64|80|96|120|128|150)["\']/i', $img_tag ) ) {
				continue;
			}

			if ( preg_match( '/logo|icon|avatar|marker|star|badge|tracker|pixel/i', $img_tag ) ) {
				continue;
			}

			// If it already has high fetchpriority, stop and do nothing.
			if ( false !== stripos( $img_tag, 'fetchpriority="high"' ) || false !== stripos( $img_tag, 'fetchpriority=\'high\'' ) ) {
				return $html; // Already optimized by theme or other source.
			}

			// Check for priority classes.
			foreach ( $priority_classes as $class ) {
				if ( false !== stripos( $img_tag, $class ) ) {
					$lcp_candidate = $img_tag;
					$lcp_candidate_index = $index;
					break 2;
				}
			}

			// Fallback: take the very first non-ignored image in the body.
			if ( empty( $lcp_candidate ) ) {
				$lcp_candidate = $img_tag;
				$lcp_candidate_index = $index;
			}
		}

		if ( ! empty( $lcp_candidate ) ) {
			$optimized_tag = $lcp_candidate;

			// Add fetchpriority="high".
			if ( false === stripos( $optimized_tag, 'fetchpriority' ) ) {
				// Insert fetchpriority="high" right after <img.
				$optimized_tag = str_replace( '<img', '<img fetchpriority="high"', $optimized_tag );
			}

			// Remove loading="lazy" if present.
			$optimized_tag = preg_replace( '/\bloading\s*=\s*["\']lazy["\']/i', '', $optimized_tag );

			// Replace the original image tag in the HTML.
			// We must replace only the specific instance of the tag to avoid duplicate replacements.
			$html = str_replace( $lcp_candidate, $optimized_tag, $html );
			Logger::debug( sprintf( 'LCP candidate optimized: %s -> %s', esc_html( $lcp_candidate ), esc_html( $optimized_tag ) ) );
		}

		return $html;
	}
}
