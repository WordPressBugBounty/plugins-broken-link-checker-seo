<?php
namespace AIOSEO\BrokenLinkChecker\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles update migrations.
 *
 * @since 1.0.0
 */
class Updates {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		add_action( 'init', [ $this, 'runUpdates' ], 1002 );
		add_action( 'init', [ $this, 'updateLatestVersion' ], 3000 );
	}

	/**
	 * Runs our migrations.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function runUpdates() {
		$lastActiveVersion = aioseoBrokenLinkChecker()->internalOptions->internal->lastActiveVersion;
		// Don't run updates if the last active version is the same as the current version.
		if ( aioseoBrokenLinkChecker()->version === $lastActiveVersion ) {
			return;
		}

		// Try to acquire the lock.
		if ( ! aioseoBrokenLinkChecker()->core->db->acquireLock( 'aioseo_blc_run_updates_lock', 0 ) ) {
			// If we couldn't acquire the lock, exit early without doing anything.
			// This means another process is already running updates.
			return;
		}

		// Sync database schema with dbDelta - this will create tables and add missing columns automatically
		$this->updateDbSchema();

		if ( version_compare( $lastActiveVersion, '1.0.0', '<' ) ) {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			aioseoBrokenLinkChecker()->internalOptions->internal->minimumLinkScanDate = date( 'Y-m-d H:i:s', time() );
		}

		if ( version_compare( $lastActiveVersion, '1.2.0', '<' ) ) {
			$this->dropInvalidMediaLinks();
			$this->dropLinksWithInvalidHash();
		}

		if ( version_compare( $lastActiveVersion, '1.2.6', '<' ) ) {
			aioseoBrokenLinkChecker()->access->addCapabilities();
		}

		if ( version_compare( $lastActiveVersion, '1.2.7', '<' ) ) {
			$this->changeParagraphColumnType();
		}
	}

	/**
	 * Updates the latest version after all migrations and updates have run.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function updateLatestVersion() {
		if ( aioseoBrokenLinkChecker()->internalOptions->internal->lastActiveVersion === aioseoBrokenLinkChecker()->version ) {
			return;
		}

		aioseoBrokenLinkChecker()->internalOptions->internal->lastActiveVersion = aioseoBrokenLinkChecker()->version;

		aioseoBrokenLinkChecker()->core->db->bustCache();
		aioseoBrokenLinkChecker()->core->cache->delete( 'db_schema' );
	}

	/**
	 * Syncs the database schema using dbDelta.
	 *
	 * This replaces manual table creation. dbDelta automatically creates
	 * missing tables and adds missing columns.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function updateDbSchema() {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// Get all schema definitions and run dbDelta
		$schemas = aioseoBrokenLinkChecker()->dbSchema->getSchema();
		dbDelta( $schemas );

		// Only clear the cache table when upgrading from the old schema that has the 'key' column.
		// On fresh installs, the table may contain important entries like 'activation_redirect'.
		$db        = aioseoBrokenLinkChecker()->core->db->db;
		$tableName = $db->prefix . 'aioseo_blc_cache';

		$keyColumnExists = $db->get_var(
			$db->prepare(
				"SELECT COLUMN_NAME
				FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'key'",
				$tableName
			)
		);

		if ( ! empty( $keyColumnExists ) ) {
			aioseoBrokenLinkChecker()->core->db->execute( "DELETE FROM {$tableName}" );
		}

		// Clear schema cache so columnExists/tableExists work correctly
		aioseoBrokenLinkChecker()->core->cache->delete( 'db_schema' );
	}

	/**
	 * Removes all mailto and tel links from the database.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	private function dropInvalidMediaLinks() {
		$tableName = aioseoBrokenLinkChecker()->core->db->prefix . 'aioseo_blc_links';

		aioseoBrokenLinkChecker()->core->db->execute(
			"DELETE FROM {$tableName} WHERE url LIKE 'mailto:%' OR url LIKE 'tel:%'"
		);

		$tableName = aioseoBrokenLinkChecker()->core->db->prefix . 'aioseo_blc_link_status';

		aioseoBrokenLinkChecker()->core->db->execute(
			"DELETE FROM {$tableName} WHERE url LIKE 'mailto:%' OR url LIKE 'tel:%'"
		);
	}

	/**
	 * Removes all links with percentage signs from the database as these had invalid hashes.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function dropLinksWithInvalidHash() {
		$blcPosts = aioseoBrokenLinkChecker()->core->db->prefix . 'aioseo_blc_posts';
		$blcLinks = aioseoBrokenLinkChecker()->core->db->prefix . 'aioseo_blc_links';

		aioseoBrokenLinkChecker()->core->db->execute(
			"UPDATE {$blcPosts} SET link_scan_date = NULL
			WHERE post_id IN (
				SELECT post_id FROM {$blcLinks} WHERE url LIKE '%\\%%'
			)"
		);

		$blcLinkStatus = aioseoBrokenLinkChecker()->core->db->prefix . 'aioseo_blc_link_status';

		aioseoBrokenLinkChecker()->core->db->execute(
			"DELETE FROM {$blcLinkStatus} WHERE url LIKE '%\\%%'"
		);
	}

	/**
	 * Change the paragraph column type to mediumtext.
	 *
	 * @since 1.2.7
	 *
	 * @return void
	 */
	private function changeParagraphColumnType() {
		if ( aioseoBrokenLinkChecker()->core->db->tableExists( 'aioseo_blc_links' ) ) {
			$tableName = aioseoBrokenLinkChecker()->core->db->prefix . 'aioseo_blc_links';

			aioseoBrokenLinkChecker()->core->db->execute( "ALTER TABLE $tableName CHANGE paragraph paragraph mediumtext NOT NULL" );
			aioseoBrokenLinkChecker()->core->db->execute( "ALTER TABLE $tableName CHANGE paragraph_html paragraph_html mediumtext NOT NULL" );
		}
	}
}