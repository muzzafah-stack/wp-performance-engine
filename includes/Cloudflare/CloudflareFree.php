<?php
namespace WPPE\Cloudflare;

use WPPE\Core\Settings;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CloudflareFree {
	private static ?CloudflareFree $instance = null;
	private const QUEUE_OPTION = 'wppe_cf_purge_queue';
	private const BATCH_SIZE = 30;

	private function __construct() {
		// Hook cache purge events to Cloudflare.
		add_action( 'wppe_purge_post', array( $this, 'enqueue_purge' ), 10, 2 );

		// Scheduled cron to process purge queue.
		add_action( 'wppe_cf_queue_cron', array( $this, 'process_queue' ) );
	}

	public static function get_instance(): CloudflareFree {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Clean and normalize API Token (removes "Bearer ", quotes, or curl syntax).
	 */
	public static function sanitize_token( string $token ): string {
		$token = trim( $token );
		if ( preg_match( '/Bearer\s+([A-Za-z0-9_\-]+)/i', $token, $matches ) ) {
			$token = $matches[1];
		}
		$token = preg_replace( '/^Bearer\s+/i', '', $token );
		return trim( $token, "\"' \t\n\r\0\x0B" );
	}

	/**
	 * Clean and normalize Zone ID (removes quotes and whitespace).
	 */
	public static function sanitize_zone_id( string $zone_id ): string {
		$zone_id = trim( $zone_id );
		return trim( $zone_id, "\"' \t\n\r\0\x0B" );
	}

	/**
	 * Check if Cloudflare connection settings are set.
	 */
	public function is_configured(): bool {
		$settings = Settings::get_instance();
		$token = self::sanitize_token( (string) $settings->get( 'cloudflare_api_token', '' ) );
		$zone_id = self::sanitize_zone_id( (string) $settings->get( 'cloudflare_zone_id', '' ) );
		return ! empty( $token ) && ! empty( $zone_id );
	}

	/**
	 * Perform a connection test with the API token and Zone ID.
	 * Includes 2-step verification: Token validation & Zone permission test.
	 */
	public function test_connection(): array {
		$settings = Settings::get_instance();
		$token = self::sanitize_token( (string) $settings->get( 'cloudflare_api_token', '' ) );
		$zone_id = self::sanitize_zone_id( (string) $settings->get( 'cloudflare_zone_id', '' ) );

		if ( empty( $token ) || empty( $zone_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Missing Cloudflare API Token or Zone ID. Please enter both fields.', 'wp-performance-engine' ),
			];
		}

		// Validate Zone ID format (32-character hexadecimal string).
		if ( false !== strpos( $zone_id, '.' ) ) {
			return [
				'success' => false,
				'message' => __( 'Invalid Zone ID format: You entered a domain name. Please enter the 32-character hex Zone ID from Cloudflare Overview.', 'wp-performance-engine' ),
			];
		}

		if ( ! preg_match( '/^[a-f0-9]{32}$/i', $zone_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Invalid Zone ID format: Cloudflare Zone ID must be a 32-character hexadecimal string (e.g. 1a2b3c4d...). Ensure you did not enter an Account ID or Global API Key.', 'wp-performance-engine' ),
			];
		}

		// Step 1: Verify token validity via Cloudflare verify endpoint.
		$verify_url = 'https://api.cloudflare.com/client/v4/user/tokens/verify';
		$verify_res = wp_remote_get( $verify_url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'timeout' => 10,
		]);

		if ( ! is_wp_error( $verify_res ) ) {
			$verify_code = wp_remote_retrieve_response_code( $verify_res );
			$verify_body = json_decode( wp_remote_retrieve_body( $verify_res ), true );

			if ( 401 === $verify_code || ( isset( $verify_body['success'] ) && ! $verify_body['success'] && 1000 === ( $verify_body['errors'][0]['code'] ?? 0 ) ) ) {
				return [
					'success' => false,
					'message' => __( 'Authentication Failed: API Token is invalid or expired. Ensure you created a Cloudflare "API Token" (Bearer Token) and not a "Global API Key".', 'wp-performance-engine' ),
				];
			}
		}

		// Step 2: Query the specific Zone ID with the token.
		$zone_url = sprintf( 'https://api.cloudflare.com/client/v4/zones/%s', $zone_id );
		$response = wp_remote_get( $zone_url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'timeout' => 10,
		]);

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => sprintf( __( 'HTTP Request Error: %s', 'wp-performance-engine' ), $response->get_error_message() ),
			];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code && isset( $body['success'] ) && $body['success'] ) {
			$zone_name   = $body['result']['name'] ?? 'Domain';
			$zone_status = $body['result']['status'] ?? 'active';
			$zone_plan   = $body['result']['plan']['name'] ?? 'Free';

			return [
				'success' => true,
				'message' => sprintf(
					__( 'Connected successfully to Cloudflare! Zone: %s (Status: %s, Plan: %s).', 'wp-performance-engine' ),
					$zone_name,
					ucfirst( $zone_status ),
					$zone_plan
				),
			];
		}

		// Parse error codes for actionable guidance.
		$error_item = $body['errors'][0] ?? [];
		$cf_err_code = $error_item['code'] ?? 0;
		$cf_err_msg  = $error_item['message'] ?? __( 'Unknown error connecting to Cloudflare.', 'wp-performance-engine' );

		if ( 7003 === $cf_err_code || 7000 === $cf_err_code || 1001 === $cf_err_code ) {
			return [
				'success' => false,
				'message' => sprintf(
					__( 'Cloudflare Error %d: Zone ID not found. Verify you copied the Zone ID from your domain Overview (not the Account ID).', 'wp-performance-engine' ),
					$cf_err_code
				),
			];
		}

		if ( 9109 === $cf_err_code || 10000 === $cf_err_code ) {
			return [
				'success' => false,
				'message' => sprintf(
					__( 'Cloudflare Error %d: Unauthorized. The token is valid but lacks permissions for this Zone or is blocked by Client IP Filtering. Check that Zone Resources includes your domain and permissions have "Zone: Read" and "Cache Purge: Purge".', 'wp-performance-engine' ),
					$cf_err_code
				),
			];
		}

		if ( 6003 === $cf_err_code ) {
			return [
				'success' => false,
				'message' => __( 'Cloudflare Error 6003: Invalid request headers. Ensure you entered an API Token with Bearer format.', 'wp-performance-engine' ),
			];
		}

		return [
			'success' => false,
			'message' => sprintf( __( 'Cloudflare Error (%d): %s', 'wp-performance-engine' ), $cf_err_code, $cf_err_msg ),
		];
	}

	/**
	 * Enqueue a URL for Cloudflare purge.
	 */
	public function enqueue_purge( int $post_id, string $url ): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, [] );
		if ( ! is_array( $queue ) ) {
			$queue = [];
		}

		// Add URL to queue.
		$queue[] = $url;
		// Dedup queue.
		$queue = array_unique( $queue );

		update_option( self::QUEUE_OPTION, $queue );
		Logger::debug( sprintf( 'Enqueued Cloudflare purge URL: %s', $url ) );
	}

	/**
	 * Process the queue and send batch purge requests to Cloudflare.
	 */
	public function process_queue(): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, [] );
		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		// Extract a batch.
		$batch = array_slice( $queue, 0, self::BATCH_SIZE );
		$remaining = array_slice( $queue, self::BATCH_SIZE );

		// Call Cloudflare API for the batch.
		$success = $this->send_purge_request( [ 'files' => array_values( $batch ) ] );

		if ( $success ) {
			// Update the queue option.
			update_option( self::QUEUE_OPTION, $remaining );
			Logger::info( sprintf( 'Cloudflare successfully purged batch of %d URLs.', count( $batch ) ) );
		} else {
			// Schedule a retry with backoff.
			Logger::warning( 'Cloudflare batch purge failed. Will retry on next cron execution.' );
		}
	}

	/**
	 * Immediately purge everything in the Zone (manual emergency option).
	 */
	public function purge_everything(): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}
		$success = $this->send_purge_request( [ 'purge_everything' => true ] );
		if ( $success ) {
			Logger::info( 'Cloudflare Purge Everything executed.' );
		}
		return $success;
	}

	/**
	 * Helper to send the POST request to Cloudflare.
	 */
	private function send_purge_request( array $payload ): bool {
		$settings = Settings::get_instance();
		$token = self::sanitize_token( (string) $settings->get( 'cloudflare_api_token', '' ) );
		$zone_id = self::sanitize_zone_id( (string) $settings->get( 'cloudflare_zone_id', '' ) );

		if ( empty( $token ) || empty( $zone_id ) ) {
			return false;
		}

		$url = sprintf( 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache', $zone_id );
		$response = wp_remote_post( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		]);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'Cloudflare purge HTTP request failed: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code && isset( $body['success'] ) && $body['success'] ) {
			return true;
		}

		$error_message = $body['errors'][0]['message'] ?? 'Unknown error response';
		$error_code    = $body['errors'][0]['code'] ?? $code;
		Logger::error( sprintf( 'Cloudflare purge API returned error (code %s): %s', $error_code, $error_message ) );
		return false;
	}
}
