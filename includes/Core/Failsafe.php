<?php
namespace WPPE\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Failsafe {
	private const ERROR_LIMIT = 3;
	private const TIME_WINDOW = 600; // 10 minutes

	/**
	 * Register failsafe error handlers.
	 */
	public static function register(): void {
		// Register shutdown function to capture fatals.
		register_shutdown_function( array( self::class, 'handle_shutdown' ) );
		// Register error handler for notices/warnings.
		set_error_handler( array( self::class, 'handle_error' ) );
	}

	/**
	 * Check if the failsafe has been triggered (optimizations should be bypassed).
	 */
	public static function is_triggered(): bool {
		$settings = Settings::get_instance();
		if ( ! $settings->get( 'failsafe_enabled', true ) ) {
			return false;
		}
		return (bool) get_transient( 'wppe_failsafe_triggered' );
	}

	/**
	 * Reset the failsafe state.
	 */
	public static function reset(): void {
		delete_transient( 'wppe_failsafe_triggered' );
		delete_transient( 'wppe_error_count' );
		Logger::info( 'Failsafe state manually reset.' );
	}

	/**
	 * Record a plugin-related error and trigger failsafe if limit exceeded.
	 *
	 * @param string $error_message Error description.
	 * @param string $file          File where error occurred.
	 * @param int    $line          Line number of the error.
	 */
	public static function record_error( string $error_message, string $file, int $line ): void {
		// Only track errors originating from our plugin.
		if ( false === strpos( $file, 'wp-performance-engine' ) ) {
			return;
		}

		$errors = get_transient( 'wppe_error_count' );
		if ( ! is_array( $errors ) ) {
			$errors = [];
		}

		// Filter out old errors.
		$now = time();
		$errors = array_filter( $errors, function( $timestamp ) use ( $now ) {
			return ( $now - $timestamp ) < self::TIME_WINDOW;
		});

		$errors[] = $now;
		set_transient( 'wppe_error_count', $errors, self::TIME_WINDOW );

		Logger::error( sprintf( 'Failsafe caught plugin error in %s:%d - %s', $file, $line, $error_message ) );

		if ( count( $errors ) >= self::ERROR_LIMIT ) {
			set_transient( 'wppe_failsafe_triggered', true, DAY_IN_SECONDS );
			Logger::critical( 'Failsafe triggered! All WPPE performance optimizations are disabled for 24 hours to prevent site instability.' );
		}
	}

	/**
	 * Handle PHP execution shutdown to check for fatal errors.
	 */
	public static function handle_shutdown(): void {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			self::record_error( $error['message'], $error['file'], $error['line'] );
		}
	}

	/**
	 * Custom PHP error handler.
	 */
	public static function handle_error( int $errno, string $errstr, string $errfile, int $errline ): bool {
		if ( $errno === E_USER_ERROR || $errno === E_RECOVERABLE_ERROR ) {
			self::record_error( $errstr, $errfile, $errline );
		}
		// Return false to let standard PHP error handling continue.
		return false;
	}
}
