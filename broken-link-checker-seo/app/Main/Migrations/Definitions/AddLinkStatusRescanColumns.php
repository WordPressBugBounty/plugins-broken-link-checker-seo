<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Adds the client-side re-scan columns to the link status table
 * (needs_additional_scan, client_confirmed_broken, last_success, local_scan_count).
 *
 * Db\Schema defines them for fresh installs; this guarantees they also land on
 * existing installs, where the dbDelta add can silently no-op. verify() is the
 * truth signal, so the runner retries until all the columns exist.
 *
 * @since 1.3.0
 */
class AddLinkStatusRescanColumns implements Migration {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 */
	public function name() {
		return 'add_link_status_rescan_columns';
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
	 * No-ops until the table exists — a fresh install creates it with these columns.
	 *
	 * @since 1.3.0
	 */
	public function up() {
		if ( ! $this->tableExists() ) {
			return;
		}

		$tableName = $this->tableName();
		foreach ( $this->columns() as $column => $definition ) {
			if ( $this->columnExists( $column ) ) {
				continue;
			}

			// $tableName, $column and $definition are all hardcoded below; no user input.
			aioseoBrokenLinkChecker()->core->db->execute( "ALTER TABLE `{$tableName}` ADD COLUMN `{$column}` {$definition}" );
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 */
	public function verify() {
		foreach ( array_keys( $this->columns() ) as $column ) {
			if ( ! $this->columnExists( $column ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The columns this migration adds, keyed by name, with their definitions
	 * mirroring Db\Schema.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string,string>
	 */
	private function columns() {
		return [
			'needs_additional_scan'   => 'tinyint(1) unsigned DEFAULT 0 NOT NULL',
			'client_confirmed_broken' => 'tinyint(1) unsigned DEFAULT 0 NOT NULL',
			'last_success'            => 'datetime DEFAULT NULL',
			'local_scan_count'        => 'int(4) unsigned DEFAULT 0 NOT NULL'
		];
	}

	/**
	 * The full (prefixed) name of the link status table.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	private function tableName() {
		return aioseoBrokenLinkChecker()->core->db->db->prefix . 'aioseo_blc_link_status';
	}

	/**
	 * Whether the link status table currently exists.
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