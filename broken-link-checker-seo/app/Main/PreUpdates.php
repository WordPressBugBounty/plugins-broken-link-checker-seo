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
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$lastActiveVersion = aioseoBrokenLinkChecker()->internalOptions->internal->lastActiveVersion;
		if ( version_compare( $lastActiveVersion, '1.0.0', '<' ) ) {
			$this->createCacheTable();
		}

		if ( version_compare( $lastActiveVersion, '1.2.7', '<' ) ) {
			$this->addIsObjectColumnToCache();
		}

		// This should be executed AFTER the cache table is created.
		if ( aioseoBrokenLinkChecker()->version !== $lastActiveVersion ) {
			// Bust the table/columns cache so that we can start the update migrations with a fresh slate.
			aioseoBrokenLinkChecker()->core->cache->delete( 'db_schema' );
		}
	}

	/**
	 * Creates the cache table.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function createCacheTable() {
		$db             = aioseoBrokenLinkChecker()->core->db->db;
		$charsetCollate = '';

		if ( ! empty( $db->charset ) ) {
			$charsetCollate .= "DEFAULT CHARACTER SET {$db->charset}";
		}
		if ( ! empty( $db->collate ) ) {
			$charsetCollate .= " COLLATE {$db->collate}";
		}

		// Check if the cache table exists with SQL. We don't want to use our own helper method here because
		// it relies on the cache table being created.
		$result = $db->get_var( "SHOW TABLES LIKE '{$db->prefix}aioseo_blc_cache'" );
		if ( empty( $result ) ) {
			$tableName = $db->prefix . 'aioseo_blc_cache';

			aioseoBrokenLinkChecker()->core->db->execute(
				"CREATE TABLE {$tableName} (
					`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					`key` varchar(80) NOT NULL,
					`value` longtext NOT NULL,
					`is_object` TINYINT(1) DEFAULT 0,
					`expiration` datetime NULL,
					`created` datetime NOT NULL,
					`updated` datetime NOT NULL,
					PRIMARY KEY (`id`),
					UNIQUE KEY ndx_aioseo_blc_cache_key (`key`),
					KEY ndx_aioseo_blc_cache_expiration (`expiration`)
				) {$charsetCollate};"
			);
		}
	}

	/**
	 * Adds the is_object column to the cache table.
	 *
	 * @since 1.2.7
	 *
	 * @return void
	 */
	public function addIsObjectColumnToCache() {
		$db = aioseoBrokenLinkChecker()->core->db->db;
		$tableName = $db->prefix . 'aioseo_blc_cache';

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

			// Clear the cache since existing entries won't have the is_object flag.
			aioseoBrokenLinkChecker()->core->cache->clear();

			// Reset the cache for the installed tables.
			aioseoBrokenLinkChecker()->core->cache->delete( 'db_schema' );
		}

		aioseoBrokenLinkChecker()->core->db->releaseLock( 'aioseo_blc_add_is_object_column' );
	}
}