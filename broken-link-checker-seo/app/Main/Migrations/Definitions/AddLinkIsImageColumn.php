<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Adds the column that marks a link as one found in an image tag.
 *
 * No backfill: every row that predates image scanning came from an anchor, which is what the column's
 * default already says.
 *
 * @since 1.3.1
 */
class AddLinkIsImageColumn implements Migration {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 */
	public function name() {
		return 'add_link_is_image_column';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 */
	public function version() {
		return '1.3.1';
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
			'is_image' => 'tinyint(1) DEFAULT 0 NOT NULL'
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
		return aioseoBrokenLinkChecker()->core->db->db->prefix . 'aioseo_blc_links';
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