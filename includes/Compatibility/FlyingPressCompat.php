<?php
namespace WPPE\Compatibility;

use WPPE\Detection\SiteProfile;
use WPPE\Core\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlyingPressCompat {
	private static ?FlyingPressCompat $instance = null;

	private function __construct() {
		// Synchronize our purge events to FlyingPress if it is active.
		add_action( 'wppe_purge_post', array( $this, 'sync_post_purge' ), 10, 2 );
	}

	public static function get_instance(): FlyingPressCompat {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Synchronize URL cache purge to FlyingPress.
	 */
	public function sync_post_purge( int $post_id, string $url ): void {
		if ( ! class_exists( '\FlyingPress\Purge' ) ) {
			return;
		}

		try {
			if ( method_exists( '\FlyingPress\Purge', 'purge_urls' ) ) {
				\FlyingPress\Purge::purge_urls( [ $url ] );
				Logger::info( sprintf( 'Synchronized purge for %s to FlyingPress.', $url ) );
			} elseif ( is_callable( [ '\FlyingPress\Purge', 'purge_url' ] ) ) {
				\FlyingPress\Purge::purge_url( $url );
				Logger::info( sprintf( 'Synchronized purge for %s to FlyingPress.', $url ) );
			} else {
				Logger::warning( 'Could not synchronize purge to FlyingPress: no public purge method available.' );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'Failed calling FlyingPress Purge method: ' . $e->getMessage() );
		}
	}
}
