<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Indexes the columns the report is read through.
 *
 * `blc_link_status_id` is how every link row is tied to the status of the URL it points at, so it is
 * in the join behind the whole report and behind each of the report's counts — and it had no index.
 *
 * The media filters need it in particular: they ask whether a URL is used as an image or a video
 * anywhere on the site, which is a question about all the rows sharing this id.
 *
 * The status table needed one too. Every listing narrows on `dismissed`, and the buckets add `broken`,
 * `needs_additional_scan` and `last_scan_date` — with nothing but the primary key and `url_hash` on the
 * table, the count behind the pager was a full scan on every page load. Measured over 50,000 statuses:
 * 11.0ms to 0.69ms for the broken count, and 11.4ms to 1.26ms for a page deep in the list.
 *
 * `url_hash` on the links table is what the report groups by, so the export reads every page through
 * it. Ordering a page on the same column then walks the index and stops at the limit instead of
 * grouping the whole table into a temporary one and sorting it. Measured over 25,000 URLs in 33,000
 * link rows, one export page went from 389ms to 6ms — a full export from ~23s of SQL to under one.
 * It does nothing for the listing, whose order the reader chooses, so that still sorts.
 *
 * The queue index is for the local rescan, which takes the least-tried rows first: `WHERE
 * needs_additional_scan = 1 ORDER BY local_scan_count, updated` could use no index for either half,
 * since `needs_additional_scan` sits third in the report index. 6.4ms to 0.1ms per batch.
 *
 * @since 1.3.1
 */
class AddLinkStatusIdIndex implements Migration {
	/**
	 * The name this migration is logged under.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const NAME = 'add_link_status_id_index';

	/**
	 * The indexes to add, mirroring Db\Schema: table, then index name, then its columns.
	 *
	 * @since 1.3.1
	 *
	 * @var array
	 */
	const INDEXES = [
		'aioseo_blc_links'       => [
			'ndx_aioseo_blc_links_link_status_id' => 'blc_link_status_id',
			'ndx_aioseo_blc_links_url_hash'       => 'url_hash'
		],
		'aioseo_blc_link_status' => [
			'ndx_aioseo_blc_link_status_report' => 'dismissed, broken, needs_additional_scan, last_scan_date',
			'ndx_aioseo_blc_link_status_queue'  => 'needs_additional_scan, local_scan_count, updated'
		]
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
	 * @since 1.3.1
	 */
	public function up() {
		foreach ( self::INDEXES as $table => $indexes ) {
			if ( ! $this->tableExists( $table ) ) {
				continue;
			}

			foreach ( $indexes as $index => $columns ) {
				if ( $this->indexExists( $table, $index ) ) {
					continue;
				}

				$tableName = $this->tableName( $table );

				// The table, index and column names are hardcoded; no user input reaches this.
				aioseoBrokenLinkChecker()->core->db->execute(
					"ALTER TABLE `{$tableName}` ADD INDEX `{$index}` ({$columns})"
				);
			}
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		foreach ( self::INDEXES as $table => $indexes ) {
			if ( ! $this->tableExists( $table ) ) {
				continue;
			}

			foreach ( array_keys( $indexes ) as $index ) {
				if ( ! $this->indexExists( $table, $index ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Whether the given index is already on the given table.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $table The unprefixed table name.
	 * @param  string $index The index name.
	 * @return bool          Whether it exists.
	 */
	private function indexExists( $table, $index ) {
		$db = aioseoBrokenLinkChecker()->core->db->db;

		$result = $db->get_var(
			$db->prepare(
				'SELECT INDEX_NAME
				FROM INFORMATION_SCHEMA.STATISTICS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s
				AND INDEX_NAME = %s',
				$this->tableName( $table ),
				$index
			)
		);

		return ! empty( $result );
	}

	/**
	 * The prefixed name of the given table.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $table The unprefixed table name.
	 * @return string        The prefixed table name.
	 */
	private function tableName( $table ) {
		return aioseoBrokenLinkChecker()->core->db->db->prefix . $table;
	}

	/**
	 * Whether the given table currently exists.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $table The unprefixed table name.
	 * @return bool          Whether it exists.
	 */
	private function tableExists( $table ) {
		$db = aioseoBrokenLinkChecker()->core->db->db;

		$result = $db->get_var(
			$db->prepare(
				'SELECT TABLE_NAME
				FROM INFORMATION_SCHEMA.TABLES
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s',
				$this->tableName( $table )
			)
		);

		return ! empty( $result );
	}
}