<?php
namespace WPPE\Database;

use WPPE\Core\Logger;
use WPPE\Core\Settings;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DbHousekeeping {
	private static ?DbHousekeeping $instance = null;

	private function __construct() {}

	public static function get_instance(): DbHousekeeping {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Perform a dry-run to analyze database items available for cleanup.
	 *
	 * @param int $revisions_retention Count of post revisions to retain.
	 * @return array Structured details of cleanup candidates.
	 */
	public function get_cleanup_stats( int $revisions_retention = 10 ): array {
		global $wpdb;
		$stats = [];

		// 1. Expired Transients.
		$now = time();
		$stats['expired_transients'] = [
			'label' => __( 'Expired Transients', 'wp-performance-engine' ),
			'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_%%' AND option_value < %d", $now ) ),
			'desc'  => __( 'Temporary cached options that have already expired.', 'wp-performance-engine' ),
		];

		// 2. Revisions under retention policy.
		$revision_count = 0;
		$parents = $wpdb->get_col( "SELECT DISTINCT post_parent FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent > 0" );
		foreach ( $parents as $parent_id ) {
			$revisions = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_modified DESC", $parent_id ) );
			if ( count( $revisions ) > $revisions_retention ) {
				$revision_count += ( count( $revisions ) - $revisions_retention );
			}
		}
		$stats['revisions'] = [
			'label' => __( 'Old Post Revisions', 'wp-performance-engine' ),
			'count' => $revision_count,
			'desc'  => sprintf( __( 'Post revisions exceeding the retention policy of retaining the latest %d.', 'wp-performance-engine' ), $revisions_retention ),
		];

		// 3. Old Auto Drafts.
		$stats['auto_drafts'] = [
			'label' => __( 'Old Auto-Drafts', 'wp-performance-engine' ),
			'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft' AND post_modified < DATE_SUB(NOW(), INTERVAL 7 DAY)" ),
			'desc'  => __( 'Auto-drafts older than 7 days.', 'wp-performance-engine' ),
		];

		// 4. Trash Posts.
		$stats['trash_posts'] = [
			'label' => __( 'Trash Posts', 'wp-performance-engine' ),
			'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'trash'" ),
			'desc'  => __( 'Posts, pages, and media inside the Trash.', 'wp-performance-engine' ),
		];

		// 5. Spam Comments.
		$stats['spam_comments'] = [
			'label' => __( 'Spam Comments', 'wp-performance-engine' ),
			'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'" ),
			'desc'  => __( 'Comments marked as spam.', 'wp-performance-engine' ),
		];

		// 6. Orphaned Postmeta.
		$stats['orphaned_postmeta'] = [
			'label' => __( 'Orphaned Post Meta', 'wp-performance-engine' ),
			'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts p ON pm.post_id = p.ID WHERE p.ID IS NULL" ),
			'desc'  => __( 'Metadata belonging to posts that no longer exist.', 'wp-performance-engine' ),
		];

		// 7. Orphaned Commentmeta.
		$stats['orphaned_commentmeta'] = [
			'label' => __( 'Orphaned Comment Meta', 'wp-performance-engine' ),
			'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->commentmeta cm LEFT JOIN $wpdb->comments c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" ),
			'desc'  => __( 'Metadata belonging to comments that no longer exist.', 'wp-performance-engine' ),
		];

		// 8. Database overhead table count and size.
		$overhead_bytes = 0;
		$overhead_tables = 0;
		$tables = $wpdb->get_results( "SHOW TABLE STATUS WHERE Data_free > 0", ARRAY_A );
		if ( ! empty( $tables ) ) {
			foreach ( $tables as $table ) {
				$overhead_bytes += (int) $table['Data_free'];
				$overhead_tables++;
			}
		}

		$stats['db_overhead'] = [
			'label' => __( 'Database Overhead', 'wp-performance-engine' ),
			'count' => $overhead_tables,
			'size'  => round( $overhead_bytes / ( 1024 * 1024 ), 2 ), // Size in MB.
			'desc'  => sprintf( __( '%d tables with overhead fragmentation ready to be optimized.', 'wp-performance-engine' ), $overhead_tables ),
		];

		return $stats;
	}

	/**
	 * Execute cleanup operations.
	 */
	public function execute_cleanup( string $category, int $revisions_retention = 10, bool $aggressive = false ): array {
		global $wpdb;
		$items_deleted = 0;
		$errors = [];

		// Strict security check.
		if ( ! current_user_can( 'manage_options' ) ) {
			return [ 'success' => false, 'message' => __( 'Security verification failed.', 'wp-performance-engine' ) ];
		}

		// Verify aggressive mode confirmation.
		if ( $aggressive && ! isset( $_POST['confirm_aggressive'] ) && ! defined( 'WP_CLI' ) ) {
			return [ 'success' => false, 'message' => __( 'Aggressive mode requires explicit confirmation.', 'wp-performance-engine' ) ];
		}

		switch ( $category ) {
			case 'expired_transients':
				$now = time();
				$transient_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_%%' AND option_value < %d", $now ) );
				foreach ( $transient_names as $name ) {
					$transient_key = str_replace( '_transient_timeout_', '', $name );
					delete_transient( $transient_key );
					$items_deleted++;
				}
				Logger::info( sprintf( 'Database cleanup: Deleted %d expired transients.', $items_deleted ) );
				break;

			case 'revisions':
				$parents = $wpdb->get_col( "SELECT DISTINCT post_parent FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent > 0" );
				foreach ( $parents as $parent_id ) {
					$revisions = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_modified DESC", $parent_id ) );
					if ( count( $revisions ) > $revisions_retention ) {
						$to_delete = array_slice( $revisions, $revisions_retention );
						foreach ( $to_delete as $rev_id ) {
							wp_delete_post_revision( $rev_id );
							$items_deleted++;
						}
					}
				}
				Logger::info( sprintf( 'Database cleanup: Deleted %d post revisions exceeding retention limit (%d).', $items_deleted, $revisions_retention ) );
				break;

			case 'auto_drafts':
				$draft_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_status = 'auto-draft' AND post_modified < DATE_SUB(NOW(), INTERVAL 7 DAY)" );
				foreach ( $draft_ids as $id ) {
					wp_delete_post( $id, true );
					$items_deleted++;
				}
				Logger::info( sprintf( 'Database cleanup: Cleaned %d old auto-draft posts.', $items_deleted ) );
				break;

			case 'trash_posts':
				$trash_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_status = 'trash'" );
				foreach ( $trash_ids as $id ) {
					wp_delete_post( $id, true );
					$items_deleted++;
				}
				Logger::info( sprintf( 'Database cleanup: Emptied %d items from post Trash.', $items_deleted ) );
				break;

			case 'spam_comments':
				$spam_ids = $wpdb->get_col( "SELECT comment_ID FROM $wpdb->comments WHERE comment_approved = 'spam'" );
				foreach ( $spam_ids as $id ) {
					wp_delete_comment( $id, true );
					$items_deleted++;
				}
				Logger::info( sprintf( 'Database cleanup: Deleted %d spam comments.', $items_deleted ) );
				break;

			case 'orphaned_postmeta':
				// SQL delete for orphan meta.
				$result = $wpdb->query( "DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
				if ( false !== $result ) {
					$items_deleted = $result;
				}
				Logger::info( sprintf( 'Database cleanup: Removed %d orphaned postmeta entries.', $items_deleted ) );
				break;

			case 'orphaned_commentmeta':
				$result = $wpdb->query( "DELETE cm FROM $wpdb->commentmeta cm LEFT JOIN $wpdb->comments c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" );
				if ( false !== $result ) {
					$items_deleted = $result;
				}
				Logger::info( sprintf( 'Database cleanup: Removed %d orphaned commentmeta entries.', $items_deleted ) );
				break;

			case 'db_overhead':
				$tables = $wpdb->get_results( "SHOW TABLE STATUS WHERE Data_free > 0", ARRAY_A );
				foreach ( $tables as $table ) {
					$wpdb->query( "OPTIMIZE TABLE " . esc_sql( $table['Name'] ) );
					$items_deleted++;
				}
				Logger::info( sprintf( 'Database cleanup: Optimized %d fragmented tables.', $items_deleted ) );
				break;

			default:
				return [ 'success' => false, 'message' => __( 'Invalid category selection.', 'wp-performance-engine' ) ];
		}

		// Clear cached db metrics.
		delete_transient( 'wppe_db_metrics_cache' );

		return [
			'success' => true,
			'message' => sprintf( __( 'Cleanup successful! Cleared/Optimized %d items.', 'wp-performance-engine' ), $items_deleted ),
		];
	}
}
