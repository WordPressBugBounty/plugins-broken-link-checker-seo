<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Drops the legacy `key` column (and its surviving unique index
 * ndx_aioseo_blc_cache_key) left on aioseo_blc_cache after 1.3.0 renamed the
 * column to `name`.
 *
 * dbDelta cannot drop columns or indexes, so the rename adds `name` alongside
 * the old `key` column and leaves ndx_aioseo_blc_cache_key in place. The
 * version-gated PreUpdates path tried to neuter it with a MODIFY ... DEFAULT
 * NULL, but on some MariaDB builds the column stays NOT NULL DEFAULT '' — every
 * INSERT then collides on the legacy unique index, fires ON DUPLICATE KEY
 * UPDATE against an unrelated row, and corrupts cache data. And where the
 * column kept NOT NULL with no usable default, inserts fail outright under
 * strict SQL mode.
 *
 * This owns the repair through the runner, where verify() is the truth signal
 * and the runner keeps retrying until the column is confirmed gone — closing
 * the concurrent-request race that let some sites bump lastActiveVersion past
 * 1.3.0 while the legacy column was still in place.
 *
 * @since 1.3.0
 */
class DropLegacyCacheKeyColumn implements Migration {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 */
	public function name() {
		return 'drop_legacy_cache_key_column';
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
	 * Drops the `key` column — which removes its single-column unique index
	 * automatically. Held back until `name` exists so we never strip the
	 * table down to a state with neither column. Clears the cache once the
	 * column is gone, since collided writes may have overwritten unrelated rows.
	 *
	 * @since 1.3.0
	 */
	public function up() {
		if ( ! $this->tableExists() || ! $this->columnExists( 'key' ) ) {
			return;
		}

		// Don't drop `key` before the schema migration has added `name`, or the
		// table would be left with neither usable column.
		if ( ! $this->columnExists( 'name' ) ) {
			return;
		}

		$tableName = $this->tableName();

		// $tableName is hardcoded; no user input.
		aioseoBrokenLinkChecker()->core->db->execute( "ALTER TABLE `{$tableName}` DROP COLUMN `key`" );

		if ( ! $this->verify() ) {
			return;
		}

		aioseoBrokenLinkChecker()->core->cache->clear();
	}

	/**
	 * {@inheritdoc}
	 *
	 * Returns true when the legacy `key` column is gone. Fresh installs (table
	 * absent, or never had the column) pass without running up().
	 *
	 * @since 1.3.0
	 */
	public function verify() {
		if ( ! $this->tableExists() ) {
			return true;
		}

		return ! $this->columnExists( 'key' );
	}

	/**
	 * The full (prefixed) name of the cache table.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	private function tableName() {
		return aioseoBrokenLinkChecker()->core->db->db->prefix . 'aioseo_blc_cache';
	}

	/**
	 * Whether the cache table currently exists.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	private function tableExists() {
		$db = aioseoBrokenLinkChecker()->core->db->db;

		$result = $db->get_var(
			$db->prepare(
				'SELECT TABLE_NAME
				FROM INFORMATION_SCHEMA.TABLES
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s',
				$this->tableName()
			)
		);

		return ! empty( $result );
	}

	/**
	 * Whether the column exists. Queries INFORMATION_SCHEMA directly to avoid a stale cached schema map.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $column The column name.
	 * @return bool
	 */
	private function columnExists( $column ) {
		$db = aioseoBrokenLinkChecker()->core->db->db;

		$result = $db->get_var(
			$db->prepare(
				'SELECT COLUMN_NAME
				FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s
				AND COLUMN_NAME = %s',
				$this->tableName(),
				$column
			)
		);

		return ! empty( $result );
	}
}