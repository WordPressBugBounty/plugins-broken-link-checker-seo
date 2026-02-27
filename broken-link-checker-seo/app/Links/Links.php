<?php
namespace AIOSEO\BrokenLinkChecker\Links;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles the Links scan.
 *
 * @since 1.0.0
 */
class Links {
	/**
	 * The action name of the scan.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $scanActionName = 'aioseo_blc_links_scan';

	/**
	 * Data class instance.
	 *
	 * @since 1.1.0
	 *
	 * @var Data
	 */
	public $data = null;

	/**
	 * Holds the IDs of posts that need to be rescanned.
	 * We have to rescan these on shutdown instead of through the "save_post" hook since that hook is triggered right after a post is updated.
	 * That in turn can cause subsequent link updatss/deletions during REST API requests to fail because all links are deleted in the callback.
	 *
	 * @since 1.1.0
	 *
	 * @var array
	 */
	public $postsToRescan = [];

	/**
	 * Class constructor.
	 *
	 * @since   1.0.0
	 * @version 1.2.9 Remove is_admin() check to allow frontend scheduling.
	 */
	public function __construct() {
		$this->data = new Data();

		add_action( 'admin_init', [ $this, 'scheduleScan' ], 3003 );
		add_action( $this->scanActionName, [ $this, 'scanPosts' ], 11, 1 );

		add_action( 'save_post', [ $this, 'scanPost' ], 21, 1 );
		add_action( 'shutdown', [ $this, 'rescanPosts' ] );
	}

	/**
	 * Schedules the links scan as a recurring action.
	 *
	 * @since   1.0.0
	 * @version 1.2.9 Switch to recurring action with cache-based idle state.
	 *
	 * @return void
	 */
	public function scheduleScan() {
		// If we're in idle mode (no posts to scan), unschedule and don't reschedule yet.
		if ( aioseoBrokenLinkChecker()->core->cache->get( 'as_blc_links_scan_idle' ) ) {
			aioseoBrokenLinkChecker()->actionScheduler->unschedule( $this->scanActionName );

			return;
		}

		if ( aioseoBrokenLinkChecker()->actionScheduler->isScheduled( $this->scanActionName ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->actionScheduler->scheduleRecurrent( $this->scanActionName, 10, MINUTE_IN_SECONDS );
	}

	/**
	 * Scans posts for links and stores them in the DB.
	 *
	 * @since   1.0.0
	 * @version 1.2.9 Use recurring action with runtime lock and idle state.
	 *
	 * @return void
	 */
	public function scanPosts() {
		// Runtime lock: Prevent concurrent execution of this action.
		$lockKey = 'as_blc_links_scan_running';
		if ( aioseoBrokenLinkChecker()->core->cache->get( $lockKey ) ) {
			return;
		}

		// Set lock with a safety timeout in case the action fails mid-execution.
		aioseoBrokenLinkChecker()->core->cache->update( $lockKey, true, 2 * MINUTE_IN_SECONDS );

		static $iterations = 0;
		$iterations++;

		aioseoBrokenLinkChecker()->helpers->timeElapsed();

		$postsToScan = $this->data->getPostsToScan();

		if ( empty( $postsToScan ) ) {
			// No more posts to scan - enter idle mode and unschedule the recurring action.
			aioseoBrokenLinkChecker()->core->cache->update( 'as_blc_links_scan_idle', true, HOUR_IN_SECONDS );
			aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );

			return;
		}

		foreach ( $postsToScan as $postToScan ) {
			$this->scanPost( $postToScan );
		}

		$timeElapsed = aioseoBrokenLinkChecker()->helpers->timeElapsed();
		if ( 10 > $timeElapsed && 200 > $iterations ) {
			// Release the lock before recursing so the recursive call doesn't bail.
			aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );
			// If we still have time, do another scan.
			$this->scanPosts();

			return;
		}

		aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );
	}

	/**
	 * Scans the given individual post for links.
	 *
	 * @since 1.0.0
	 *
	 * @param  Object|int $post The post object or ID (if called on "save_post").
	 * @return void
	 */
	public function scanPost( $post ) {
		if ( doing_action( 'save_post' ) && ! empty( $this->postsToRescan ) ) {
			// If posts need to be reindexed manually, bail.
			return;
		}

		if ( ! is_object( $post ) ) {
			$post = get_post( $post );
		}

		// Check if we didn't scan this post in the last 3 seconds. This is to prevent a second, subsequent request from scanning the same post.
		if ( aioseoBrokenLinkChecker()->core->cache->get( 'aioseo_blc_scan_post_' . $post->ID ) ) {
			return;
		}

		if ( ! aioseoBrokenLinkChecker()->helpers->isScannablePost( $post ) ) {
			return;
		}

		$this->data->indexLinks( $post->ID );

		$aioseoPost                 = Models\Post::getPost( $post->ID );
		$aioseoPost->link_scan_date = gmdate( 'Y-m-d H:i:s' );
		$aioseoPost->save();

		// Set a transient to prevent scanning the same post again in the next 3 seconds.
		aioseoBrokenLinkChecker()->core->cache->update( 'aioseo_blc_scan_post_' . $post->ID, true, 3 );
	}

	/**
	 * Reindexes posts on shutdown.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function rescanPosts() {
		if ( empty( $this->postsToRescan ) ) {
			return;
		}

		foreach ( $this->postsToRescan as $postId ) {
			$this->scanPost( $postId );
		}
	}
}