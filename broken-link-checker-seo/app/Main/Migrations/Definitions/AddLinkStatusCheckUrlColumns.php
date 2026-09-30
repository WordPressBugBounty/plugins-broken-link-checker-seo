<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Adds the columns that hold the form a link is requested at, and fills them in for the rows already stored.
 *
 * The stored URL had to be both the key the front-end highlighter matches a raw `href` against and the
 * string the checking service is sent, and those two cannot be one value: a browser keeps `[` and `]`
 * raw in an `href`, while a request carrying them raw is malformed. Splitting them needs somewhere to
 * put the second form, and it has to be a column rather than something derived at send time - the
 * service echoes back the string it was given, in a later request, and the row is found by hashing it.
 *
 * NOTE: Backfilled straight from the stored URL, which is already right for every row the resolver
 * wrote. A row carrying older damage - a dot segment, a default port, an upper-case host - is corrected
 * by the send-time pass instead, which derives the form afresh and writes it when it differs. Doing it
 * there rather than here is what keeps this off a table that can hold millions of rows.
 *
 * @since 1.3.1
 */
class AddLinkStatusCheckUrlColumns implements Migration {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function name() {
		return 'add_link_status_check_url_columns';
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
		$db = aioseoBrokenLinkChecker()->core->db;

		if ( ! $db->tableExists( 'aioseo_blc_link_status' ) ) {
			return;
		}

		$tableName = $db->prefix . 'aioseo_blc_link_status';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( ! $db->columnExists( 'aioseo_blc_link_status', 'check_url' ) ) {
			$db->execute( "ALTER TABLE {$tableName} ADD COLUMN check_url text DEFAULT NULL AFTER url_hash" );
		}

		if ( ! $db->columnExists( 'aioseo_blc_link_status', 'check_url_hash' ) ) {
			$db->execute( "ALTER TABLE {$tableName} ADD COLUMN check_url_hash varchar(40) DEFAULT NULL AFTER check_url" );
			$db->execute( "ALTER TABLE {$tableName} ADD KEY ndx_aioseo_blc_link_status_check_url_hash (check_url_hash)" );
			$db->db->query( "UPDATE {$tableName} SET check_url = url, check_url_hash = SHA1( url ) WHERE check_url_hash IS NULL" );
		}
		// phpcs:enable

		// Just the schema map, so columnExists() sees the new columns rather than the cached shape.
		aioseoBrokenLinkChecker()->core->cache->delete( 'db_schema' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		$db = aioseoBrokenLinkChecker()->core->db;

		if ( ! $db->tableExists( 'aioseo_blc_link_status' ) ) {
			return true;
		}

		return $db->columnExists( 'aioseo_blc_link_status', 'check_url' ) &&
			$db->columnExists( 'aioseo_blc_link_status', 'check_url_hash' );
	}
}