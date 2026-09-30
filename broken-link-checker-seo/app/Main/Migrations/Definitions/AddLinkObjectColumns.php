<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Adds the object addressing columns and their index to the links table.
 *
 * The columns default to the post shape, so existing rows need no backfill for `object_type`.
 * `object_id` does get one, in bounded batches, because the default can't copy `post_id`.
 *
 * @since 1.3.1
 */
class AddLinkObjectColumns implements Migration {
	/**
	 * The name this migration is logged under.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const NAME = 'add_link_object_columns';

	/**
	 * The name of the index the object columns are read through.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const INDEX = 'ndx_aioseo_blc_links_object';

	/**
	 * How many rows one backfill statement covers.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $batchSize = 20000;

	/**
	 * How long the backfill may run in one request, in seconds.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $backfillBudget = 20;

	/**
	 * The columns this migration adds, mapped to their definition.
	 *
	 * @since 1.3.1
	 *
	 * @var array<string, string>
	 */
	private $columns = [
		'object_type'    => "varchar(20) NOT NULL DEFAULT 'post'",
		'object_id'      => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'object_subtype' => "varchar(191) NOT NULL DEFAULT ''"
	];

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function name() {
		return self::NAME;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function version() {
		return '1.3.1';
	}

	/**
	 * {@inheritdoc}
	 *
	 * No-ops until the table exists — a fresh install creates it with these columns.
	 *
	 * @since 1.3.1
	 */
	public function up() {
		if ( ! $this->tableExists() ) {
			return;
		}

		$tableName = $this->tableName();
		$db        = aioseoBrokenLinkChecker()->core->db;

		// $tableName is built from the WordPress prefix; the columns and their definitions are hardcoded.
		foreach ( $this->columns as $column => $definition ) {
			if ( $this->columnExists( $column ) ) {
				continue;
			}

			$db->execute( "ALTER TABLE `{$tableName}` ADD COLUMN `{$column}` {$definition}" );
		}

		// The cached schema map is what the rest of the plugin decides on, and it holds for a day. Left
		// alone it would keep every object-addressed path switched off after this succeeded.
		aioseoBrokenLinkChecker()->core->cache->delete( 'db_schema' );

		if ( ! $this->indexExists() ) {
			$this->addIndex();
		}

		$this->backfill();
	}

	/**
	 * Adds the index the object columns are read through.
	 *
	 * NOTE: The online hints are an optimisation, not a requirement — MyISAM rejects them outright and
	 * some MariaDB builds refuse them on a table they would otherwise rebuild. So a refusal falls back
	 * to the plain statement rather than leaving the table without the index.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	private function addIndex() {
		$tableName = $this->tableName();
		$db        = aioseoBrokenLinkChecker()->core->db;
		$addKey    = "ALTER TABLE `{$tableName}` ADD KEY `" . self::INDEX . '` (`object_type`, `object_id`)';

		$db->execute( $addKey . ', ALGORITHM=INPLACE, LOCK=NONE' );

		if ( $this->indexExists() ) {
			return;
		}

		$db->execute( $addKey );
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: Deliberately not gated on the index. An engine that refuses to create it would otherwise
	 * fail verification on every request, and each retry re-attempts the DDL, the full-table backfill
	 * and the scan for unfilled rows. The index makes the reads fast; it doesn't make them correct.
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		if ( ! $this->tableExists() ) {
			return true;
		}

		foreach ( array_keys( $this->columns ) as $column ) {
			if ( ! $this->columnExists( $column ) ) {
				return false;
			}
		}

		return ! $this->hasUnfilledRows();
	}

	/**
	 * Copies `post_id` into `object_id` for the rows that pre-date the column.
	 *
	 * Batched with a time budget so a large table is finished across requests rather than in one
	 * that times out. The runner retries until verify() finds nothing left.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	private function backfill() {
		$tableName = $this->tableName();
		$deadline  = microtime( true ) + $this->backfillBudget;

		while ( microtime( true ) < $deadline ) {
			aioseoBrokenLinkChecker()->core->db->execute(
				"UPDATE `{$tableName}`
				SET object_id = post_id
				WHERE object_id = 0 AND post_id > 0
				LIMIT {$this->batchSize}"
			);

			if ( (int) aioseoBrokenLinkChecker()->core->db->rowsAffected() < $this->batchSize ) {
				return;
			}
		}
	}

	/**
	 * Whether any row still carries a `post_id` that never reached `object_id`.
	 *
	 * @since 1.3.1
	 *
	 * @return bool
	 */
	private function hasUnfilledRows() {
		$tableName = $this->tableName();

		$result = aioseoBrokenLinkChecker()->core->db->db->get_var(
			"SELECT id FROM `{$tableName}` WHERE object_id = 0 AND post_id > 0 LIMIT 1"
		);

		return ! empty( $result );
	}

	/**
	 * The full (prefixed) name of the links table.
	 *
	 * @since 1.3.1
	 *
	 * @return string
	 */
	private function tableName() {
		return aioseoBrokenLinkChecker()->core->db->db->prefix . 'aioseo_blc_links';
	}

	/**
	 * Whether the links table currently exists.
	 *
	 * @since 1.3.1
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
	 * Whether the given column exists. Queries INFORMATION_SCHEMA directly to avoid a stale cached schema map.
	 *
	 * @since 1.3.1
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

	/**
	 * Whether the object index exists.
	 *
	 * @since 1.3.1
	 *
	 * @return bool
	 */
	private function indexExists() {
		$db = aioseoBrokenLinkChecker()->core->db->db;

		$result = $db->get_var(
			$db->prepare(
				'SELECT INDEX_NAME
				FROM INFORMATION_SCHEMA.STATISTICS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s
				AND INDEX_NAME = %s',
				$this->tableName(),
				self::INDEX
			)
		);

		return ! empty( $result );
	}
}