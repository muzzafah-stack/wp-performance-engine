<?php
namespace WPPE\Speculation;

use WPPE\Arbitration\Arbiter;
use WPPE\Core\Settings;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpeculationRules {
	private static ?SpeculationRules $instance = null;

	private function __construct() {
		// Output the speculation rules block in the footer.
		add_action( 'wp_footer', array( $this, 'inject_speculation_rules' ), 100 );
	}

	public static function get_instance(): SpeculationRules {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if Speculation Rules should be injected.
	 */
	public function should_inject(): bool {
		$arbiter = Arbiter::get_instance();
		if ( ! $arbiter->is_authorized_owner( 'speculation_rules' ) ) {
			return false;
		}

		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return false;
		}

		// Don't inject in preview/editor contexts.
		if ( is_user_logged_in() || isset( $_GET['preview'] ) ) {
			return false;
		}

		$elementor = \WPPE\Core\Plugin::get_instance()->get_service( 'elementor' );
		if ( $elementor && method_exists( $elementor, 'is_editor_or_preview' ) && $elementor->is_editor_or_preview() ) {
			return false;
		}

		return true;
	}

	/**
	 * Inject the script type="speculationrules" JSON block.
	 */
	public function inject_speculation_rules(): void {
		if ( ! $this->should_inject() ) {
			return;
		}

		$settings = Settings::get_instance();
		$mode = $settings->get( 'speculation_mode', 'balanced' );

		// Define exclusions patterns.
		$exclusions = [
			'/wp-admin/*',
			'/wp-login.php*',
			'/wp-signup.php*',
			'/cart/*',
			'/checkout/*',
			'/my-account/*',
			'/*\\?*action=*', // logout, delete, edit links
			'/*\\?*add-to-cart=*',
			'/*\\?*wc-ajax=*',
			'/*\\?*elementor-preview=*',
			'/*\\?*elementor_library=*',
			'/*\\?*elementor-template-type=*',
			'/*\\.xml',
			'/*\\.pdf',
			'/*\\.zip',
		];

		$eagerness = 'moderate';
		$type = 'prerender'; // Default to prerender for balanced & aggressive.

		if ( 'conservative' === $mode ) {
			$type = 'prefetch';
			$eagerness = 'moderate';
		} elseif ( 'aggressive' === $mode ) {
			$type = 'prerender';
			$eagerness = 'eager';
		}

		$rules = [
			$type => [
				[
					'source' => 'document',
					'where'  => [
						'and' => [
							[ 'href_matches' => '/*' ],
							[
								'not' => [
									'href_matches' => $exclusions,
								],
							],
						],
					],
					'eagerness' => $eagerness,
				],
			],
		];

		// Format output JSON.
		$json_rules = wp_json_encode( $rules, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		echo "\n<!-- WPPE Speculation Rules Engine -->\n";
		echo '<script type="speculationrules">' . $json_rules . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "\n";
	}
}
