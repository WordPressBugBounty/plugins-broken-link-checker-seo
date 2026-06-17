<?php
namespace AIOSEO\BrokenLinkChecker\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This class contains pre-updates necessary for the main Updates class to run.
 *
 * @since 1.0.0
 */
class PreUpdates {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// We don't want an AJAX request check here since the plugin might be installed/activated for the first time via AJAX.
		// If that's the case, the cache table needs to be created before the cron job runs.
		if ( wp_doing_cron() ) {
			return;
		}

		$lastActiveVersion = aioseoBrokenLinkChecker()->internalOptions->internal->lastActiveVersion;

		if ( version_compare( $lastActiveVersion, '1.3.0', '<' ) ) {
			// Delete the cache table transient BEFORE the lock check so that ALL concurrent
			// requests (including those that can't acquire the lock) will re-verify the table
			// structure in isCacheTableAvailable(). This prevents concurrent requests from
			// using the stale transient and querying with column names that don't exist yet.
			delete_transient( 'aioseo_blc_cache_table_exists' );
		}

		// Acquire a database lock to prevent race conditions when multiple concurrent
		// requests (e.g., page load + AJAX) trigger the migration simultaneously.
		// Without this lock, parallel requests can pass the column existence checks
		// before either has added the column, causing "Duplicate column name" errors.
		if ( ! aioseoBrokenLinkChecker()->core->db->acquireLock( 'aioseo_blc_pre_updates', 0 ) ) {
			return;
		}

		if ( version_compare( $lastActiveVersion, '1.3.0', '<' ) ) {
			$this->addIsObjectColumnToCache();
			$this->cleanCacheTable(); // Clean duplicate entries before schema changes
			$this->updateCacheTable(); // update the cache table to use the new name column
			$this->createCacheTable(); // Run dbDelta first to add the 'name' column

			aioseoBrokenLinkChecker()->core->cache->delete( 'db_schema' );
		}
	}

	/**
	 * Cleans the cache table before schema changes.
	 *
	 * Removes duplicate and empty entries that would prevent adding a UNIQUE KEY constraint.
	 *
	 * NOTE: This method uses raw SQL queries to check table/column existence instead of
	 * the Database helper methods (tableExists/columnExists) because those methods rely on
	 * the cache system, which is exactly what we're trying to migrate here.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function cleanCacheTable() {
		$db        = aioseoBrokenLinkChecker()->core->db->db;
		$tableName = $db->prefix . 'aioseo_blc_cache';

		// Check if table exists using raw SQL (bypass cache to avoid circular dependency)
		$tableExists = $db->get_var(
			$db->prepare(
				'SELECT TABLE_NAME
				FROM INFORMATION_SCHEMA.TABLES
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s',
				$tableName
			)
		);

		if ( empty( $tableExists ) ) {
			return;
		}

		// Only truncate if the table still has the old 'key' column, meaning this is an
		// upgrade that needs cleaning before adding the UNIQUE KEY on 'name'.
		// Fresh installs and already-migrated tables don't need cleaning and may contain
		// important cache entries like 'activation_redirect' for the setup wizard.
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

		if ( empty( $keyColumnExists ) ) {
			return;
		}

		// Try to acquire a lock to prevent race conditions
		if ( ! aioseoBrokenLinkChecker()->core->db->acquireLock( 'aioseo_blc_clean_cache_table', 0 ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->core->db->execute( "TRUNCATE TABLE {$tableName}" );

		aioseoBrokenLinkChecker()->core->db->releaseLock( 'aioseo_blc_clean_cache_table' );
	}

	/**
	 * Creates the cache table.
	 *
	 * Now uses dbDelta to update DB schema instead of manual table creation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function createCacheTable() {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// Use dbDelta to create the cache table based on schema definition
		$schema = aioseoBrokenLinkChecker()->dbSchema->getCacheTableSchema();
		dbDelta( $schema );

		aioseoBrokenLinkChecker()->core->cache->delete( 'db_schema' );
	}

	/**
	 * Updates the cache table to use the new name column.
	 *
	 * This runs AFTER createCacheTable() which uses dbDelta to add the 'name' column.
	 * At this point, the table has both 'key' and 'name' columns.
	 * We drop the old 'key' column (data was already cleared by cleanCacheTable).
	 *
	 * NOTE: This method uses raw SQL queries to check table/column existence instead of
	 * the Database helper methods (tableExists/columnExists) because those methods rely on
	 * the cache system, which is exactly what we're trying to migrate here.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function updateCacheTable() {
		$db        = aioseoBrokenLinkChecker()->core->db->db;
		$tableName = $db->prefix . 'aioseo_blc_cache';

		// Check if table exists using raw SQL (bypass cache to avoid circular dependency)
		$tableExists = $db->get_var(
			$db->prepare(
				'SELECT TABLE_NAME
				FROM INFORMATION_SCHEMA.TABLES
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s',
				$tableName
			)
		);

		if ( empty( $tableExists ) ) {
			return;
		}

		// Check if 'key' column exists using raw SQL (bypass cache)
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
			// set key column as nullable to avoid retro compatibility issues
			aioseoBrokenLinkChecker()->core->db->execute( "ALTER TABLE {$tableName} MODIFY COLUMN `key` varchar(80) DEFAULT NULL" );
		}

		// Clear the transient so isCacheTableAvailable() re-checks on the next call.
		delete_transient( 'aioseo_blc_cache_table_exists' );
	}

	/**
	 * Adds the is_object column to the cache table.
	 *
	 * NOTE: This method uses raw SQL queries instead of the Cache class methods
	 * (clear/delete) because those methods rely on the 'name' column which may
	 * not exist yet during the migration from 'key' to 'name'.
	 *
	 * @since 1.2.7
	 *
	 * @return void
	 */
	public function addIsObjectColumnToCache() {
		$db        = aioseoBrokenLinkChecker()->core->db->db;
		$tableName = $db->prefix . 'aioseo_blc_cache';

		// Check if table exists using raw SQL (on fresh installs, the table won't exist yet).
		$tableExists = $db->get_var(
			$db->prepare(
				'SELECT TABLE_NAME
				FROM INFORMATION_SCHEMA.TABLES
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s',
				$tableName
			)
		);

		if ( empty( $tableExists ) ) {
			return;
		}

		// Try to acquire a lock to prevent race conditions (0 timeout = don't wait)
		if ( ! aioseoBrokenLinkChecker()->core->db->acquireLock( 'aioseo_blc_add_is_object_column', 0 ) ) {
			return;
		}

		// Check if column exists using raw SQL (bypass cache completely), otherwise we will get errors
		$columnExists = $db->get_var(
			$db->prepare(
				"SELECT COLUMN_NAME
				FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'is_object'",
				$tableName
			)
		);

		if ( empty( $columnExists ) ) {
			aioseoBrokenLinkChecker()->core->db->execute(
				"ALTER TABLE {$tableName}
				ADD `is_object` TINYINT(1) DEFAULT 0 AFTER `value`"
			);

			// Clear the cache using raw SQL since existing entries won't have the is_object flag.
			// We use raw SQL because the Cache class methods rely on the 'name' column which
			// may not exist yet during the migration from 'key' to 'name'.
			aioseoBrokenLinkChecker()->core->db->execute( "TRUNCATE TABLE {$tableName}" );
		}

		aioseoBrokenLinkChecker()->core->db->releaseLock( 'aioseo_blc_add_is_object_column' );
	}
}