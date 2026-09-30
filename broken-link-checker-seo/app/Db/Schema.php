<?php
namespace AIOSEO\BrokenLinkChecker\Db;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database Schema for Broken Link Checker tables.
 *
 * @since 1.3.0
 */
class Schema {
	/**
	 * Get all table schemas for Broken Link Checker tables.
	 *
	 * @since 1.3.0
	 *
	 * @return array Array of SQL CREATE TABLE statements.
	 */
	public function getSchema() {
		return [
			$this->getLinkStatusTableSchema(),
			$this->getLinksTableSchema(),
			$this->getNotificationsTableSchema(),
			$this->getPostsTableSchema(),
			$this->getCacheTableSchema()
		];
	}

	/**
	 * Get the schema for aioseo_blc_link_status table.
	 *
	 * Column history:
	 * - 1.3.0: needs_additional_scan, client_confirmed_broken, last_success, local_scan_count
	 * - 1.3.1: check_url, check_url_hash
	 *
	 * @since 1.3.0
	 *
	 * @return string SQL CREATE TABLE statement.
	 */
	private function getLinkStatusTableSchema() {
		$tableName      = aioseoBrokenLinkChecker()->core->db->db->prefix . 'aioseo_blc_link_status';
		$charsetCollate = aioseoBrokenLinkChecker()->core->db->db->get_charset_collate();

		return "CREATE TABLE {$tableName} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url text NOT NULL,
			url_hash varchar(40) NOT NULL,
			check_url text DEFAULT NULL,
			check_url_hash varchar(40) DEFAULT NULL,
			http_status_code smallint(6) DEFAULT NULL,
			broken tinyint(1) unsigned DEFAULT 0 NOT NULL,
			dismissed tinyint(1) DEFAULT 0 NOT NULL,
			needs_additional_scan tinyint(1) unsigned DEFAULT 0 NOT NULL,
			client_confirmed_broken tinyint(1) unsigned DEFAULT 0 NOT NULL,
			request_duration float DEFAULT NULL,
			scan_count int(4) unsigned DEFAULT 0 NOT NULL,
			local_scan_count int(4) unsigned DEFAULT 0 NOT NULL,
			redirect_count smallint(5) unsigned DEFAULT 0 NOT NULL,
			final_url text DEFAULT NULL,
			first_failure datetime DEFAULT NULL,
			last_success datetime DEFAULT NULL,
			log text DEFAULT NULL,
			last_scan_date datetime DEFAULT NULL,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ndx_aioseo_blc_link_status_url_hash (url_hash),
			KEY ndx_aioseo_blc_link_status_check_url_hash (check_url_hash),
			KEY ndx_aioseo_blc_link_status_report (dismissed, broken, needs_additional_scan, last_scan_date),
			KEY ndx_aioseo_blc_link_status_queue (needs_additional_scan, local_scan_count, updated)
		) {$charsetCollate};";
	}

	/**
	 * Get the schema for aioseo_blc_links table.
	 *
	 * Column history:
	 * - 1.3.1: object_type, object_id, object_subtype
	 * - 1.3.1: index on blc_link_status_id
	 *
	 * @since 1.3.0
	 *
	 * @return string SQL CREATE TABLE statement.
	 */
	private function getLinksTableSchema() {
		$tableName      = aioseoBrokenLinkChecker()->core->db->db->prefix . 'aioseo_blc_links';
		$charsetCollate = aioseoBrokenLinkChecker()->core->db->db->get_charset_collate();

		return "CREATE TABLE {$tableName} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			object_type varchar(20) NOT NULL DEFAULT 'post',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_subtype varchar(191) NOT NULL DEFAULT '',
			blc_link_status_id bigint(20) unsigned DEFAULT NULL,
			url text NOT NULL,
			url_hash varchar(40) NOT NULL,
			hostname text NOT NULL,
			hostname_url varchar(40) NOT NULL,
			external tinyint(1) DEFAULT 0 NOT NULL,
			anchor text NOT NULL,
			phrase text NOT NULL,
			phrase_html text NOT NULL,
			paragraph text NOT NULL,
			paragraph_html text NOT NULL,
			is_video tinyint(1) DEFAULT 0 NOT NULL,
			is_image tinyint(1) DEFAULT 0 NOT NULL,
			is_embed tinyint(1) DEFAULT 0 NOT NULL,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ndx_aioseo_blc_links_post_id (post_id),
			KEY ndx_aioseo_blc_links_object (object_type, object_id),
			KEY ndx_aioseo_blc_links_hostname (hostname(10)),
			KEY ndx_aioseo_blc_links_link_status_id (blc_link_status_id),
			KEY ndx_aioseo_blc_links_url_hash (url_hash)
		) {$charsetCollate};";
	}

	/**
	 * Get the schema for aioseo_blc_notifications table.
	 *
	 * @since 1.3.0
	 *
	 * @return string SQL CREATE TABLE statement.
	 */
	private function getNotificationsTableSchema() {
		$tableName      = aioseoBrokenLinkChecker()->core->db->db->prefix . 'aioseo_blc_notifications';
		$charsetCollate = aioseoBrokenLinkChecker()->core->db->db->get_charset_collate();

		return "CREATE TABLE {$tableName} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			notification_id bigint(20) unsigned DEFAULT NULL,
			notification_name varchar(255) DEFAULT NULL,
			slug varchar(13) NOT NULL,
			title text NOT NULL,
			content longtext NOT NULL,
			type varchar(64) NOT NULL,
			level text NOT NULL,
			start datetime DEFAULT NULL,
			end datetime DEFAULT NULL,
			button1_label varchar(255) DEFAULT NULL,
			button1_action varchar(255) DEFAULT NULL,
			button2_label varchar(255) DEFAULT NULL,
			button2_action varchar(255) DEFAULT NULL,
			dismissed tinyint(1) NOT NULL DEFAULT 0,
			new tinyint(1) NOT NULL DEFAULT 1,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ndx__aioseo_blc_notifications_slug (slug),
			KEY ndx__aioseo_blc_notifications_dates (start, end),
			KEY ndx__aioseo_blc_notifications_type (type),
			KEY ndx__aioseo_blc_notifications_dismissed (dismissed)
		) {$charsetCollate};";
	}

	/**
	 * Get the schema for aioseo_blc_posts table.
	 *
	 * @since 1.3.0
	 *
	 * @return string SQL CREATE TABLE statement.
	 */
	private function getPostsTableSchema() {
		$tableName      = aioseoBrokenLinkChecker()->core->db->db->prefix . 'aioseo_blc_posts';
		$charsetCollate = aioseoBrokenLinkChecker()->core->db->db->get_charset_collate();

		return "CREATE TABLE {$tableName} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			link_scan_date datetime DEFAULT NULL,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ndx_aioseo_blc_posts_post_id (post_id)
		) {$charsetCollate};";
	}

	/**
	 * Get the schema for aioseo_blc_cache table.
	 *
	 * Column history:
	 * - 1.0.0: Initial table creation
	 * - 1.3.0: is_object
	 *
	 * @since 1.3.0
	 *
	 * @return string SQL CREATE TABLE statement.
	 */
	public function getCacheTableSchema() {
		$tableName      = aioseoBrokenLinkChecker()->core->db->db->prefix . 'aioseo_blc_cache';
		$charsetCollate = aioseoBrokenLinkChecker()->core->db->db->get_charset_collate();

		return "CREATE TABLE {$tableName} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(80) NOT NULL,
			value longtext NOT NULL,
			is_object TINYINT(1) DEFAULT 0,
			expiration datetime DEFAULT NULL,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ndx_aioseo_blc_cache_name (name),
			KEY ndx_aioseo_blc_cache_expiration (expiration)
		) {$charsetCollate};";
	}
}