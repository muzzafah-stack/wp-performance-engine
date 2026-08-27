<?php
namespace WPPE\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {
	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Autoload class files.
	 *
	 * @param string $class Class name to load.
	 */
	public static function autoload( string $class ): void {
		// Only autoload classes from our namespace.
		if ( strpos( $class, 'WPPE\\' ) !== 0 ) {
			return;
		}

		// Strip namespace prefix.
		$relative_class = substr( $class, 5 );

		// Convert namespace separators to directory separators.
		$file = WPPE_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
