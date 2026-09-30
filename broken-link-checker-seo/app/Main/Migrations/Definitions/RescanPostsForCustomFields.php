<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Queues every post to be scanned again, so custom fields are covered on an existing site.
 *
 * A post's fields are read when its content is scanned, and an existing install has already scanned
 * everything. Without this the source is on and finds nothing until each post happens to be saved.
 *
 * @since 1.3.1
 */
class RescanPostsForCustomFields implements Migration {
	/**
	 * The name this migration is logged under.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const NAME = 'rescan_posts_for_custom_fields';

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
	 * @since   1.3.1
	 * @version 1.3.1 Skips the requeue when the table it updates is not there yet.
	 */
	public function up() {
		$db = aioseoBrokenLinkChecker()->core->db;

		// A fresh install has nothing to requeue - it has scanned nothing yet, and the table this would
		// update is not created until activation gets to it, which is after the migrations run.
		if ( $db->tableExists( 'aioseo_blc_posts' ) ) {
			$table = $db->prefix . 'aioseo_blc_posts';

			// One statement against an indexed column, and the scan then works through it at its own pace.
			$db->execute( "UPDATE {$table} SET link_scan_date = NULL" );

			aioseoBrokenLinkChecker()->core->cache->delete( 'as_blc_links_scan_idle' );
		}

		// Saved immediately: this is the only record that the requeue happened, and migrations can run in
		// a request that never reaches the options' own shutdown save.
		aioseoBrokenLinkChecker()->internalOptions->internal->customFieldsRequeued = true;
		aioseoBrokenLinkChecker()->internalOptions->save( true );
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: The runner asks this before it calls up(), to decide whether the work is outstanding - so a
	 * migration that answers yes unconditionally is never run at all, which is what happened here. There
	 * is nothing in the data to check either: the scan drains the queue this fills, so a site that has
	 * since finished scanning looks exactly like one where it never ran. The flag up() sets is therefore
	 * the only honest answer.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Answers whether the requeue has happened, rather than always yes.
	 */
	public function verify() {
		return (bool) aioseoBrokenLinkChecker()->internalOptions->internal->customFieldsRequeued;
	}
}