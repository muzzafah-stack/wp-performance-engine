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
	 * Check if Cloudflare connection settings are set.
	 */
	public function is_configured(): bool {
		$settings = Settings::get_instance();
		$token = $settings->get( 'cloudflare_api_token', '' );
		$zone_id = $settings->get( 'cloudflare_zone_id', '' );
		return ! empty( $token ) && ! empty( $zone_id );
	}

	/**
	 * Perform a connection test with the API token and Zone ID.
	 */
	public function test_connection(): array {
		$settings = Settings::get_instance();
		$token = $settings->get( 'cloudflare_api_token', '' );
		$zone_id = $settings->get( 'cloudflare_zone_id', '' );

		if ( empty( $token ) || empty( $zone_id ) ) {
			return [ 'success' => false, 'message' => __( 'Missing token or Zone ID.', 'wp-performance-engine' ) ];
		}

		$url = sprintf( 'https://api.cloudflare.com/client/v4/zones/%s', $zone_id );
		$response = wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'timeout' => 10,
		]);

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'message' => $response->get_error_message() ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code && isset( $body['success'] ) && $body['success'] ) {
			return [ 'success' => true, 'message' => __( 'Connected successfully to Cloudflare!', 'wp-performance-engine' ) ];
		}

		$error_message = $body['errors'][0]['message'] ?? __( 'Unknown error connecting to Cloudflare.', 'wp-performance-engine' );
		return [ 'success' => false, 'message' => $error_message ];
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
		$token = $settings->get( 'cloudflare_api_token', '' );
		$zone_id = $settings->get( 'cloudflare_zone_id', '' );

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
		Logger::error( sprintf( 'Cloudflare purge API returned error (code %d): %s', $code, $error_message ) );
		return false;
	}
}
