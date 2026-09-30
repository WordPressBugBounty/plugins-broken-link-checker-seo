<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Adds the is_embed column to the links table and fills it in for the rows already stored.
 *
 * Whether a link is embedded or written as an anchor decides what can be done to it: an embed has no
 * anchor to take out, while a link whose destination happens to be a video or a picture is an ordinary
 * link and can be unlinked like one. The extractors always knew which was which and the flag was
 * dropped before the insert, so the report had to guess from is_video and is_image and refused both.
 *
 * NOTE: Backfilled from the phrase, which every embed extractor leaves empty and an anchor fills with
 * the sentence around the link. Not reliable enough to decide on at read time - a video anchor is
 * excused from carrying a phrase - but the only signal the stored rows have, and better than leaving
 * every existing embed looking like an anchor until the next full scan.
 *
 * @since 1.3.1
 */
class AddLinkIsEmbedColumn implements Migration {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function name() {
		return 'add_link_is_embed_column';
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

		if ( ! $db->tableExists( 'aioseo_blc_links' ) ) {
			return;
		}

		$tableName = $db->prefix . 'aioseo_blc_links';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( ! $db->columnExists( 'aioseo_blc_links', 'is_embed' ) ) {
			$db->execute( "ALTER TABLE {$tableName} ADD COLUMN is_embed tinyint(1) DEFAULT 0 NOT NULL AFTER is_image" );
			$db->db->query( "UPDATE {$tableName} SET is_embed = 1 WHERE ( is_video = 1 OR is_image = 1 ) AND phrase = ''" );
		}
		// phpcs:enable

		// Just the schema map, so columnExists() sees the new column rather than the cached shape.
		aioseoBrokenLinkChecker()->core->cache->delete( 'db_schema' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		$db = aioseoBrokenLinkChecker()->core->db;

		if ( ! $db->tableExists( 'aioseo_blc_links' ) ) {
			return true;
		}

		return $db->columnExists( 'aioseo_blc_links', 'is_embed' );
	}
}