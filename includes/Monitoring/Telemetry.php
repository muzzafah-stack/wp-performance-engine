<?php
namespace WPPE\Monitoring;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telemetry {
	private static ?Telemetry $instance = null;
	private const OPTION_NAME = 'wppe_telemetry_data';

	private function __construct() {}

	public static function get_instance(): Telemetry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Increment a telemetry key.
	 */
	public static function increment( string $key, int $by = 1 ): void {
		$data = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $data ) ) {
			$data = [];
		}
		$data[ $key ] = ( $data[ $key ] ?? 0 ) + $by;
		update_option( self::OPTION_NAME, $data, false ); // autoload = false
	}

	public static function record_cache_hit(): void {
		self::increment( 'cache_hits' );
	}

	public static function record_cache_miss(): void {
		self::increment( 'cache_misses' );
	}

	public static function record_cache_size( int $bytes ): void {
		$data = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $data ) ) {
			$data = [];
		}
		$data['cache_size_bytes'] = $bytes;
		update_option( self::OPTION_NAME, $data, false );
	}

	public static function record_purge(): void {
		self::increment( 'purge_count' );
	}

	public static function record_conflict(): void {
		self::increment( 'conflict_count' );
	}

	public static function record_error(): void {
		self::increment( 'error_count' );
	}

	/**
	 * Retrieve all accumulated telemetry metrics.
	 */
	public function get_metrics(): array {
		$defaults = [
			'cache_hits'       => 0,
			'cache_misses'     => 0,
			'cache_size_bytes' => 0,
			'purge_count'      => 0,
			'conflict_count'   => 0,
			'error_count'      => 0,
		];
		$stored = get_option( self::OPTION_NAME, [] );
		return array_merge( $defaults, is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Reset all local telemetry statistics.
	 */
	public function reset_metrics(): void {
		delete_option( self::OPTION_NAME );
	}
}
