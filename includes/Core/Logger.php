<?php
namespace WPPE\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {
	/**
	 * Log levels.
	 */
	public const DEBUG    = 'DEBUG';
	public const INFO     = 'INFO';
	public const WARNING  = 'WARNING';
	public const ERROR    = 'ERROR';
	public const CRITICAL = 'CRITICAL';

	/**
	 * Log message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public static function log( string $level, string $message, array $context = [] ): void {
		$settings = Settings::get_instance();
		$debug_mode = $settings->get( 'debug_mode', false );

		// Only log debug messages if debug mode is active.
		if ( self::DEBUG === $level && ! $debug_mode ) {
			return;
		}

		// Redact sensitive credentials.
		$message = self::redact( $message );
		if ( ! empty( $context ) ) {
			$context = self::redact_array( $context );
		}

		$log_dir = WP_CONTENT_DIR . '/uploads/wp-performance-engine/logs';
		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
			// Write .htaccess to prevent public log access.
			file_put_contents( $log_dir . '/.htaccess', "Deny from all\n" );
			file_put_contents( $log_dir . '/index.html', '' );
		}

		$log_file = $log_dir . '/debug.log';
		$timestamp = current_time( 'mysql' );
		$context_str = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';
		$formatted_message = sprintf( "[%s] [%s] %s%s\n", $timestamp, $level, $message, $context_str );

		// Write to file safely.
		error_log( $formatted_message, 3, $log_file );

		// Prune log file safely once per hour.
		if ( ! get_transient( 'wppe_last_log_prune' ) ) {
			self::prune_log_file( $log_file );
			set_transient( 'wppe_last_log_prune', time(), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Prune log file by removing entries older than 24 hours.
	 * Also enforces a maximum of 1000 lines and 1MB size limit.
	 *
	 * @param string $log_file Path to log file.
	 */
	private static function prune_log_file( string $log_file ): void {
		if ( ! file_exists( $log_file ) ) {
			return;
		}

		clearstatcache( true, $log_file );
		$lines = file( $log_file );
		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return;
		}

		// Ponytail: Pruning by age (24 hours) is the developer standard. If users require longer history, upgrade to a database-backed log system.
		$now = strtotime( current_time( 'mysql' ) );
		$cutoff_time = $now - ( 24 * HOUR_IN_SECONDS );
		$new_lines = [];
		$changed = false;

		foreach ( $lines as $line ) {
			if ( preg_match( '/^\[([^\]]+)\]/', $line, $matches ) ) {
				$line_time = strtotime( $matches[1] );
				if ( $line_time && $line_time < $cutoff_time ) {
					$changed = true;
					continue;
				}
			}
			$new_lines[] = $line;
		}

		if ( count( $new_lines ) > 1000 ) {
			$new_lines = array_slice( $new_lines, -1000 );
			$changed = true;
		}

		if ( $changed ) {
			file_put_contents( $log_file, implode( '', $new_lines ) );
		}
	}

	public static function debug( string $message, array $context = [] ): void {
		self::log( self::DEBUG, $message, $context );
	}

	public static function info( string $message, array $context = [] ): void {
		self::log( self::INFO, $message, $context );
	}

	public static function warning( string $message, array $context = [] ): void {
		self::log( self::WARNING, $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::log( self::ERROR, $message, $context );
	}

	public static function critical( string $message, array $context = [] ): void {
		self::log( self::CRITICAL, $message, $context );
	}

	/**
	 * Redact credentials in string.
	 */
	private static function redact( string $text ): string {
		return preg_replace( '/(token|password|key|auth|cookie|pwd)="?[a-zA-Z0-9_\-\.]{12,}"?/i', '$1="[REDACTED]"', $text );
	}

	/**
	 * Redact credentials in array recursively.
	 */
	private static function redact_array( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::redact_array( $value );
			} elseif ( is_string( $value ) ) {
				if ( preg_match( '/token|password|key|auth|cookie|pwd/i', $key ) ) {
					$data[ $key ] = '[REDACTED]';
				} else {
					$data[ $key ] = self::redact( $value );
				}
			}
		}
		return $data;
	}
}
