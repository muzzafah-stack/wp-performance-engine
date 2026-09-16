<?php
namespace WPPE\Compatibility;

use WPPE\Core\Settings;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SaleslooCompat {
	private static ?SaleslooCompat $instance = null;

	private function __construct() {
		if ( $this->is_salesloo_active() ) {
			// Hook payment gateway resource hints.
			add_action( 'wp_head', array( $this, 'inject_payment_hints' ), 2 );
			// Cache bypass filter.
			add_filter( 'wppe_cache_should_bypass', array( $this, 'filter_cache_bypass' ) );
			// Script delay filter.
			add_filter( 'wppe_critical_script_keywords', array( $this, 'filter_critical_scripts' ) );
		}
	}

	public static function get_instance(): SaleslooCompat {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Detect if Salesloo or Custom Salesloo is active.
	 */
	public function is_salesloo_active(): bool {
		return defined( 'SALESLOO_VERSION' )
			|| defined( 'CUSTOM_SALESLOO_VERSION' )
			|| class_exists( 'Salesloo' )
			|| class_exists( 'Custom_Salesloo' )
			|| class_exists( 'Salesloo_Core' )
			|| ( function_exists( 'is_plugin_active' ) && ( is_plugin_active( 'custom-salesloo/custom-salesloo.php' ) || is_plugin_active( 'salesloo/salesloo.php' ) ) );
	}

	/**
	 * Check if current page is Salesloo checkout, invoice, or member area.
	 */
	public function is_salesloo_dynamic_page(): bool {
		$uri = $_SERVER['REQUEST_URI'] ?? '';

		$dynamic_patterns = [
			'/checkout',
			'/salesloo',
			'/pesanan',
			'/pembayaran',
			'/invoice',
			'/konfirmasi',
			'/member',
			'/order-received',
			'salesloo_action',
			'sl_action',
			'order_id',
		];

		foreach ( $dynamic_patterns as $pattern ) {
			if ( false !== stripos( $uri, $pattern ) ) {
				return true;
			}
		}

		// Check for dynamic cookies.
		foreach ( $_COOKIE as $cookie => $val ) {
			if ( 0 === strpos( $cookie, 'salesloo_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Ensure Salesloo checkout and dynamic order pages bypass HTML disk cache.
	 */
	public function filter_cache_bypass( bool $bypass ): bool {
		if ( $bypass ) {
			return true;
		}

		return $this->is_salesloo_dynamic_page();
	}

	/**
	 * Add Salesloo and Indonesian payment gateways to critical scripts list.
	 */
	public function filter_critical_scripts( array $keywords ): array {
		$salesloo_keywords = [
			'salesloo',
			'custom-salesloo',
			'midtrans',
			'snap.js',
			'snap.min.js',
			'tripay',
			'moota',
			'xendit',
			'duitku',
		];

		return array_merge( $keywords, $salesloo_keywords );
	}

	/**
	 * Preconnect to payment gateways to speed up checkout popups.
	 */
	public function inject_payment_hints(): void {
		if ( ! is_admin() ) {
			echo '<link rel="preconnect" href="https://app.midtrans.com">' . "\n";
			echo '<link rel="preconnect" href="https://tripay.co.id">' . "\n";
			echo '<link rel="preconnect" href="https://app.moota.co">' . "\n";
		}
	}
}
