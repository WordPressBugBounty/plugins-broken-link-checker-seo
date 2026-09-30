<?php
namespace AIOSEO\BrokenLinkChecker\Links;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;
use AIOSEO\BrokenLinkChecker\Objects;

/**
 * Handles the Links scan.
 *
 * @since 1.0.0
 */
class Links {
	/**
	 * The action name of the scan.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Made public so the Abilities layer can report on the scan.
	 *
	 * @var string
	 */
	public $scanActionName = 'aioseo_blc_links_scan';

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
		add_action( 'added_post_meta', [ $this, 'queueBuilderMeta' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'queueBuilderMeta' ], 10, 3 );
		add_action( 'delete_post', [ $this, 'deletePostLinks' ], 10, 2 );
		add_action( 'shutdown', [ $this, 'rescanPosts' ] );
	}

	/**
	 * Deletes all link records for a given post when it is permanently deleted, plus any
	 * link-status rows left unreferenced once those links are gone.
	 *
	 * Statuses are captured before the links are deleted; only fires on permanent delete,
	 * so a link status whose post is merely trashed (and may be restored) is kept.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Covers menu items, which are posts of their own type.
	 * @version 1.3.1 Covers the post's custom fields.
	 *
	 * NOTE: Wrapped, because of when this can fire. Installing a plugin from a ZIP deletes the upload at
	 * the end of the same request, which reaches this hook after our own files have been replaced on
	 * disk - so the class already in memory and the one autoloaded next can be different versions of
	 * the plugin, and a method the caller expects need not exist in the other. Nothing is lost by
	 * giving up here: the rows belong to a post that is gone, and the sweep collects them.
	 *
	 * @param  int           $postId The post ID.
	 * @param  \WP_Post|null $post   The post, when the hook supplies it.
	 * @return void
	 */
	public function deletePostLinks( $postId, $post = null ) {
		// An upgrade may already have replaced our files in this request, so anything that reads our own
		// classes would be reading a different build. The rows outlive the request; the sweep collects them.
		if ( aioseoBrokenLinkChecker()->helpers->filesReplacedThisRequest() ) {
			return;
		}

		if ( ! aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
			return;
		}

		$post       = is_a( $post, 'WP_Post' ) ? $post : get_post( $postId );
		$isMenuItem = is_a( $post, 'WP_Post' ) && 'nav_menu_item' === $post->post_type;

		// The post's custom fields go with it. They are addressed by the same ID, and a menu item's own
		// meta is the item, so only a content post has fields of its own to drop.
		$objectTypes = $isMenuItem ? [ 'menu_item' ] : [ 'post', 'post_meta' ];

		try {
			$linkStatusIds = [];
			foreach ( $objectTypes as $objectType ) {
				$linkStatusIds = array_merge( $linkStatusIds, Models\Link::getObjectLinkStatusIds( $objectType, $postId ) );

				Models\Link::deleteObjectLinks( $objectType, $postId );
			}

			if ( $linkStatusIds ) {
				Models\LinkStatus::deleteOrphaned( array_values( array_unique( $linkStatusIds ) ) );
			}
		} catch ( \Throwable $e ) {
			// A half-replaced plugin, not something the caller can act on. Left for the sweep.
			return;
		}
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
	 * @version 1.3.1 The other sources share the budget instead of taking a batch and returning.
	 *
	 * @return void
	 */
	public function scanPosts() {
		// Never idles or stamps anything: the scan has to be able to pick up where it left off once the
		// object columns land, and it stays scheduled so it does.
		if ( ! aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
			return;
		}

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
			// The other sources have no post_modified to compare against, so they take their turn once the
			// posts are drained. They share the budget below rather than getting one batch per tick, which
			// is minutes per thousand objects on a site where the scan is otherwise idle.
			if ( ! aioseoBrokenLinkChecker()->main->objectScan->run() ) {
				// Nothing left in any source - enter idle mode and unschedule the recurring action.
				aioseoBrokenLinkChecker()->core->cache->update( 'as_blc_links_scan_idle', true, HOUR_IN_SECONDS );
				aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );

				return;
			}
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
	 * @since   1.0.0
	 * @version 1.3.1 Bails while the object columns are missing.
	 * @version 1.3.1 Indexes the post's custom fields along with its content.
	 *
	 * @param  Object|int $post The post object or ID (if called on "save_post").
	 * @return void
	 */
	public function scanPost( $post ) {
		// Stamping the scan date is what takes a post out of the queue, and nothing puts it back: the
		// queue is post_modified against link_scan_date. So a scan that can't store links can't stamp.
		if ( ! aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
			return;
		}

		if ( doing_action( 'save_post' ) && ! empty( $this->postsToRescan ) ) {
			// If posts need to be reindexed manually, bail.
			return;
		}

		// The bulk scan hands us raw stdClass rows, so hydrate anything that isn't already a WP_Post.
		// The ID is resolved first because get_post() falls back to the global post for empty input.
		if ( ! is_a( $post, 'WP_Post' ) ) {
			$postId = is_object( $post ) ? ( $post->ID ?? 0 ) : $post;
			$post   = $postId ? get_post( (int) $postId ) : null;
		}

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		// Check if we didn't scan this post in the last 3 seconds. This is to prevent a second, subsequent request from scanning the same post.
		if ( aioseoBrokenLinkChecker()->core->cache->get( 'aioseo_blc_scan_post_' . $post->ID ) ) {
			return;
		}

		if ( ! aioseoBrokenLinkChecker()->helpers->isScannablePost( $post ) ) {
			return;
		}

		$this->data->indexLinks( $post->ID );
		$this->data->indexPostMetaLinks( $post->ID );
		$this->data->indexPostBuilderLinks( $post->ID );

		$aioseoPost                 = Models\Post::getPost( $post->ID );
		$aioseoPost->link_scan_date = gmdate( 'Y-m-d H:i:s' );
		$aioseoPost->save();

		// Set a transient to prevent scanning the same post again in the next 3 seconds.
		aioseoBrokenLinkChecker()->core->cache->update( 'aioseo_blc_scan_post_' . $post->ID, true, 3 );
	}

	/**
	 * Reindexes a post's builder content when the builder writes it.
	 *
	 * The Elementor editor saves through the meta API, and the save_post that would otherwise carry the
	 * reindex does not always come with it, so the write itself is the signal.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $metaId  The meta ID.
	 * @param  int    $postId  The post ID.
	 * @param  string $metaKey The meta key.
	 * @return void
	 */
	public function queueBuilderMeta( $metaId, $postId, $metaKey ) {
		// Whichever builder owns this key; a site can run more than one, and only the one whose layout
		// just changed needs reading again.
		$builder = null;
		foreach ( aioseoBrokenLinkChecker()->objects->all() as $type ) {
			// Only the builders that keep a layout in meta have a key to watch; the shortcode ones are
			// reindexed by the post save itself, and have no metaKey() to ask for.
			if ( $type instanceof Objects\MetaBuilderObject && $type->metaKey() === $metaKey ) {
				$builder = $type;

				break;
			}
		}

		if ( ! $builder ) {
			return;
		}

		$this->data->indexObjectLinks( $builder->type(), (int) $postId );
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