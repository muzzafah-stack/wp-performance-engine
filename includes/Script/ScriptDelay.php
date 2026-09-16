<?php
namespace WPPE\Script;

use WPPE\Arbitration\Arbiter;
use WPPE\Core\Settings;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScriptDelay {
	private static ?ScriptDelay $instance = null;
	private bool $is_hooked = false;

	// Script classifications.
	public const CLASSIFICATION_CRITICAL    = 'CRITICAL';
	public const CLASSIFICATION_INTERACTIVE = 'INTERACTIVE';
	public const CLASSIFICATION_NON_CRITICAL = 'NON_CRITICAL';
	public const CLASSIFICATION_THIRD_PARTY  = 'THIRD_PARTY';

	private function __construct() {
		// Hook late to capture frontend output.
		add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ), 9999 );
	}

	public static function get_instance(): ScriptDelay {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Determine if JS delay should execute.
	 */
	public function should_delay(): bool {
		$arbiter = Arbiter::get_instance();
		if ( ! $arbiter->is_authorized_owner( 'js_delay' ) ) {
			return false;
		}

		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return false;
		}

		// Don't delay inside Elementor preview/editor.
		$elementor = \WPPE\Core\Plugin::get_instance()->get_service( 'elementor' );
		if ( $elementor && method_exists( $elementor, 'is_editor_or_preview' ) && $elementor->is_editor_or_preview() ) {
			return false;
		}

		return true;
	}

	/**
	 * Start output buffer if conditions are met.
	 */
	public function maybe_start_buffer(): void {
		if ( $this->should_delay() ) {
			$this->is_hooked = true;
			ob_start( array( $this, 'delay_scripts_in_html' ) );
		}
	}

	/**
	 * Classification helper to check if script is critical and should be excluded.
	 */
	public function classify_script( string $src_or_content ): string {
		$settings = Settings::get_instance();
		$elementor_smart_delay = (bool) $settings->get( 'elementor_smart_script_delay', true );

		// Auto exclusions list (navigation-critical, accessibility, forms, WooCommerce, cookies).
		$critical_keywords = [
			'jquery.min.js', 'jquery.js',
			'woocommerce', 'wc-cart', 'wc-add-to-cart',
			'cookiebot', 'cookie-law-info', 'onetrust', 'complianz', 'consent',
			'recaptcha', 'hcaptcha', 'wp-polyfill', 'wp-i18n',
			'navigation', 'menu', 'search',
		];

		// If smart Elementor delay is disabled, treat Elementor frontend as critical.
		if ( ! $elementor_smart_delay ) {
			$critical_keywords[] = 'elementor-frontend';
			$critical_keywords[] = 'elementor-pro';
			$critical_keywords[] = 'pro-elements';
			$critical_keywords[] = 'elementor-webpack';
		}

		foreach ( $critical_keywords as $keyword ) {
			if ( false !== stripos( $src_or_content, $keyword ) ) {
				return self::CLASSIFICATION_CRITICAL;
			}
		}

		// Third party scripts.
		$third_party_keywords = [
			'google-analytics.com', 'googletagmanager.com', 'facebook.net',
			'adsbygoogle', 'doubleclick.net', 'hotjar.com', 'crazyegg.com',
		];

		foreach ( $third_party_keywords as $keyword ) {
			if ( false !== stripos( $src_or_content, $keyword ) ) {
				return self::CLASSIFICATION_THIRD_PARTY;
			}
		}

		return self::CLASSIFICATION_NON_CRITICAL;
	}

	/**
	 * Parse HTML and delay scripts.
	 */
	public function delay_scripts_in_html( string $html ): string {
		if ( ! $this->is_hooked || empty( $html ) ) {
			return $html;
		}

		$this->is_hooked = false;

		// Skip if HTML is not complete.
		if ( false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		$settings = Settings::get_instance();
		$user_exclusions = array_filter( array_map( 'trim', explode( "\n", $settings->get( 'delay_exclusions', '' ) ) ) );
		$user_allowlist = array_filter( array_map( 'trim', explode( "\n", $settings->get( 'delay_allowlist', '' ) ) ) );

		// Parse script tags.
		// Regex to find script tags matching src or inline.
		$html = preg_replace_callback( '/<script\b[^>]*>(.*?)<\/script>/is', function( $matches ) use ( $user_exclusions, $user_allowlist ) {
			$tag = $matches[0];
			$content = $matches[1];

			// Extract src if exists.
			$src = '';
			if ( preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $src_matches ) ) {
				$src = $src_matches[1];
			}

			// Check user allowlist/exclusions.
			$check_str = ! empty( $src ) ? $src : $content;

			// Check user exclusions.
			foreach ( $user_exclusions as $exclusion ) {
				if ( false !== strpos( $check_str, $exclusion ) ) {
					return $tag;
				}
			}

			// Perform classification.
			$classification = $this->classify_script( $check_str );

			// Determine if we delay.
			$should_delay = false;

			// If script is in user allowlist, delay it.
			if ( ! empty( $user_allowlist ) ) {
				foreach ( $user_allowlist as $allowed ) {
					if ( false !== strpos( $check_str, $allowed ) ) {
						$should_delay = true;
						break;
					}
				}
			} else {
				// Otherwise use classification rule.
				if ( self::CLASSIFICATION_THIRD_PARTY === $classification || self::CLASSIFICATION_NON_CRITICAL === $classification ) {
					$should_delay = true;
				}
			}

			if ( ! $should_delay ) {
				return $tag;
			}

			// Transform tag to delayed type.
			if ( ! empty( $src ) ) {
				// Remove src from tag to prevent auto execution, add data-wppe-src and type.
				$tag_replaced = preg_replace( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', '', $tag );
				// Also strip type if exists.
				$tag_replaced = preg_replace( '/\btype\s*=\s*["\']([^"\']+)["\']/i', '', $tag_replaced );
				// Set custom type and data-wppe-src.
				$tag_replaced = str_replace( '<script', '<script type="text/javascript" data-wppe-delay="true" data-wppe-src="' . esc_url( $src ) . '"', $tag_replaced );
				return $tag_replaced;
			} else {
				// Inline script. Change type.
				$tag_replaced = preg_replace( '/\btype\s*=\s*["\']([^"\']+)["\']/i', '', $tag );
				$tag_replaced = str_replace( '<script', '<script type="wppe-delayed"', $tag_replaced );
				return $tag_replaced;
			}

		}, $html );

		// Inject Client-Side Runner Script before </body>.
		$runner = $this->get_client_runner_html();
		$html = str_replace( '</body>', $runner . '</body>', $html );

		return $html;
	}

	/**
	 * Load client side JavaScript delay runner.
	 */
	private function get_client_runner_html(): string {
		$settings = Settings::get_instance();
		$timeout = (int) $settings->get( 'delay_timeout', 5000 );

		return '
<script id="wppe-js-delay-runner">
(function() {
	var triggered = false;
	var timeoutId = null;
	var interactionEvents = ["keydown", "mousedown", "mousemove", "touchstart", "scroll"];

	function triggerDelay() {
		if (triggered) return;
		triggered = true;
		clearTimeout(timeoutId);

		// Remove interaction listeners.
		interactionEvents.forEach(function(event) {
			window.removeEventListener(event, triggerDelay, { passive: true });
		});

		loadDelayedScripts();
	}

	function loadDelayedScripts() {
		var delayScripts = document.querySelectorAll(\'script[data-wppe-delay="true"]\');
		var inlineScripts = document.querySelectorAll(\'script[type="wppe-delayed"]\');
		var index = 0;

		function loadNext() {
			if (index < delayScripts.length) {
				var oldScript = delayScripts[index];
				index++;
				var newScript = document.createElement("script");
				// Copy attributes.
				Array.from(oldScript.attributes).forEach(function(attr) {
					if (attr.name !== "type" && attr.name !== "data-wppe-src" && attr.name !== "data-wppe-delay") {
						newScript.setAttribute(attr.name, attr.value);
					}
				});
				newScript.src = oldScript.getAttribute("data-wppe-src");
				newScript.onload = loadNext;
				newScript.onerror = loadNext;
				oldScript.parentNode.replaceChild(newScript, oldScript);
			} else {
				loadInlineScripts();
			}
		}

		function loadInlineScripts() {
			inlineScripts.forEach(function(script) {
				var newScript = document.createElement("script");
				Array.from(script.attributes).forEach(function(attr) {
					if (attr.name !== "type") {
						newScript.setAttribute(attr.name, attr.value);
					}
				});
				newScript.textContent = script.textContent;
				script.parentNode.replaceChild(newScript, script);
			});
		}

		loadNext();
	}

	// Schedule timeout.
	timeoutId = setTimeout(triggerDelay, ' . $timeout . ');

	// Add event listeners.
	interactionEvents.forEach(function(event) {
		window.addEventListener(event, triggerDelay, { passive: true });
	});
})();
</script>
';
	}
}
