<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Dedupes aioseo_blc_posts rows and replaces the non-unique post_id index with a UNIQUE one.
 *
 * Older installs allowed duplicate rows for the same post_id (no UNIQUE constraint), which a
 * race between the Action Scheduler scan and the save_post hook could insert on first scan.
 * Duplicate rows inflate the LEFT JOIN count used by the scan progress query, pinning the
 * percentage and revisiting the same posts indefinitely.
 *
 * dbDelta cannot convert an existing non-unique KEY into a UNIQUE KEY in place, so this
 * migration owns both the dedupe AND the explicit index swap. verify() is the truth signal
 * — the runner retries until both checks pass.
 *
 * @since 1.3.0
 */
class DedupePosts implements Migration {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 */
	public function name() {
		return 'dedupe_blc_posts';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 */
	public function version() {
		return '1.3.0';
	}

	/**
	 * {@inheritdoc}
	 *
	 * Dedupes rows keyed by post_id (keeping the freshest link_scan_date, tie-broken by
	 * highest id) and swaps the non-unique post_id index for a UNIQUE one. Fresh installs
	 * skip the dedupe via the tableExists() guard — dbDelta handles the index for them.
	 *
	 * @since 1.3.0
	 */
	public function up() {
		$db = aioseoBrokenLinkChecker()->core->db;

		if ( ! $db->tableExists( 'aioseo_blc_posts' ) ) {
			return;
		}

		$tableName = $db->prefix . 'aioseo_blc_posts';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$db->execute(
			"DELETE t1 FROM {$tableName} t1
			INNER JOIN {$tableName} t2
				ON t1.post_id = t2.post_id
				AND (
					COALESCE(t1.link_scan_date, '1970-01-01 00:00:00') < COALESCE(t2.link_scan_date, '1970-01-01 00:00:00')
					OR (
						(t1.link_scan_date <=> t2.link_scan_date)
						AND t1.id < t2.id
					)
				)"
		);

		$indexes = $db->db->get_results( "SHOW INDEX FROM {$tableName} WHERE Key_name = 'ndx_aioseo_blc_posts_post_id'" );
		if ( ! empty( $indexes ) && 1 === (int) $indexes[0]->Non_unique ) {
			$db->execute( "ALTER TABLE {$tableName} DROP INDEX ndx_aioseo_blc_posts_post_id" );
			$db->execute( "ALTER TABLE {$tableName} ADD UNIQUE KEY ndx_aioseo_blc_posts_post_id (post_id)" );
		}
		// phpcs:enable
	}

	/**
	 * {@inheritdoc}
	 *
	 * Returns true when no duplicate post_id rows remain AND the post_id index is UNIQUE.
	 *
	 * @since 1.3.0
	 */
	public function verify() {
		$db = aioseoBrokenLinkChecker()->core->db;

		if ( ! $db->tableExists( 'aioseo_blc_posts' ) ) {
			return true;
		}

		$tableName = $db->prefix . 'aioseo_blc_posts';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$duplicate = $db->db->get_var( "SELECT post_id FROM {$tableName} GROUP BY post_id HAVING COUNT(*) > 1 LIMIT 1" );
		if ( null !== $duplicate ) {
			return false;
		}

		$indexes = $db->db->get_results( "SHOW INDEX FROM {$tableName} WHERE Column_name = 'post_id'" );
		// phpcs:enable
		if ( empty( $indexes ) ) {
			return false;
		}

		foreach ( $indexes as $index ) {
			if ( 0 === (int) $index->Non_unique ) {
				return true;
			}
		}

		return false;
	}
}