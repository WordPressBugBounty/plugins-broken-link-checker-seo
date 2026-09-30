<?php
namespace AIOSEO\BrokenLinkChecker\Links;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;
use AIOSEO\BrokenLinkChecker\Objects;

/**
 * Keeps the links found outside post content up to date.
 *
 * Terms, users and menu items have no `post_modified` to compare a scan date against, so freshness
 * comes from the hooks that fire when one changes. The periodic sweep is the safety net for the
 * writes those hooks miss, and it walks IDs forward from a stored cursor rather than re-reading the
 * oldest rows: nothing can hold the head of the queue and starve the rest.
 *
 * @since 1.3.1
 */
class ObjectScan {
	/**
	 * How many objects one sweep batch covers.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $batchSize = 100;

	/**
	 * How long a completed sweep stands before the source is read again.
	 *
	 * Deliberately not the scan frequency setting, which budgets how often links are sent out to be
	 * checked. Re-reading a term, a bio or a menu is a local query, so it runs at its own steady rate
	 * and a site on the monthly setting does not wait a month to notice a link someone added.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $sweepInterval = WEEK_IN_SECONDS;

	/**
	 * The posts whose custom fields a write in this request left to be reindexed.
	 *
	 * @since 1.3.1
	 *
	 * @var array<int, bool>
	 */
	private $postsWithMetaWrites = [];

	/**
	 * How many posts one request answers for itself before it defers to the scan.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $metaWriteLimit = 100;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		add_action( 'created_term', [ $this, 'scanTerm' ], 10, 3 );
		add_action( 'edited_term', [ $this, 'scanTerm' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'deleteTerm' ], 10, 1 );

		add_action( 'user_register', [ $this, 'scanUser' ] );
		add_action( 'profile_update', [ $this, 'scanUser' ] );
		// profile_update only fires through wp_update_user(), which an importer or a membership plugin
		// writing the bio straight to meta never reaches.
		add_action( 'added_user_meta', [ $this, 'scanUserMeta' ], 10, 3 );
		add_action( 'updated_user_meta', [ $this, 'scanUserMeta' ], 10, 3 );
		add_action( 'deleted_user', [ $this, 'deleteUser' ] );
		// Which role a profile holds decides whether it is scanned, so a change either brings it into
		// scope or takes what was recorded for it back out.
		add_action( 'set_user_role', [ $this, 'scanUser' ] );

		add_action( 'wp_update_nav_menu_item', [ $this, 'scanMenuItem' ], 10, 2 );
		add_action( 'wp_update_nav_menu', [ $this, 'scanMenu' ] );

		// A pattern, template or navigation block is a post, but not one the post types setting covers,
		// so the post scan skips it and only the weekly sweep would ever see the change. The editor
		// writes all of them through the post API, so the same four hooks cover every case.
		add_action( 'save_post', [ $this, 'scanBlockContent' ], 21, 2 );
		add_action( 'trashed_post', [ $this, 'scanBlockContent' ] );
		add_action( 'untrashed_post', [ $this, 'scanBlockContent' ] );
		add_action( 'deleted_post', [ $this, 'scanBlockContent' ] );

		// A post's custom fields are indexed with the post, but only a change to its content puts it back
		// in that queue. So a field written on its own — by an importer, a bulk edit, or code — is caught
		// here instead.
		add_action( 'added_post_meta', [ $this, 'queuePostMeta' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'queuePostMeta' ], 10, 3 );
		add_action( 'deleted_post_meta', [ $this, 'queuePostMeta' ], 10, 3 );
		add_action( 'shutdown', [ $this, 'scanQueuedPostMeta' ] );
	}

	/**
	 * Notes that the given post's custom fields need reading again.
	 *
	 * NOTE: Collapsed to one reindex per post on shutdown. Saving a post behind a page builder or an ACF
	 * group writes dozens of keys, and reindexing on each would read the same post that many times over.
	 *
	 * @since 1.3.1
	 *
	 * @param  int|int[] $metaId  The meta ID, or IDs for a delete.
	 * @param  int       $postId  The post ID.
	 * @param  string    $metaKey The meta key.
	 * @return void
	 */
	public function queuePostMeta( $metaId, $postId, $metaKey ) {
		// An upgrade may already have replaced our files in this request, so anything that reads our own
		// classes would be reading a different build. The rows outlive the request; the sweep collects them.
		if ( aioseoBrokenLinkChecker()->helpers->filesReplacedThisRequest() ) {
			return;
		}

		if ( ! aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
			return;
		}

		// Deciding for certain costs a group lookup per write, which is more than this hook is worth. A
		// protected key is never one of the fields the source reads, and it is most of what WordPress and
		// its plugins write, so rejecting those keeps the queue to the writes that might matter.
		if ( 0 === strpos( (string) $metaKey, '_' ) ) {
			return;
		}

		// A bulk write is not something to answer post by post at the end of one request. Past the cap the
		// posts held so far go back into the scan's own queue, which keeps memory flat without widening
		// what gets rescanned to the whole site.
		if ( $this->metaWriteLimit <= count( $this->postsWithMetaWrites ) ) {
			$this->requeuePosts( array_keys( $this->postsWithMetaWrites ) );

			$this->postsWithMetaWrites = [];
		}

		$this->postsWithMetaWrites[ (int) $postId ] = true;
	}

	/**
	 * Puts the given posts back into the link scan's queue.
	 *
	 * @since 1.3.1
	 *
	 * @param  int[] $postIds The post IDs.
	 * @return void
	 */
	private function requeuePosts( $postIds ) {
		$postIds = array_filter( array_map( 'intval', (array) $postIds ) );
		if ( empty( $postIds ) ) {
			return;
		}

		$table = aioseoBrokenLinkChecker()->core->db->prefix . 'aioseo_blc_posts';
		foreach ( array_chunk( $postIds, 500 ) as $chunk ) {
			$ids = implode( ', ', $chunk );

			aioseoBrokenLinkChecker()->core->db->execute(
				"UPDATE {$table} SET link_scan_date = NULL WHERE post_id IN ( {$ids} )"
			);
		}

		aioseoBrokenLinkChecker()->core->cache->delete( 'as_blc_links_scan_idle' );
	}

	/**
	 * Reindexes the custom fields of every post this request wrote one of.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function scanQueuedPostMeta() {
		$postIds                   = array_keys( $this->postsWithMetaWrites );
		$this->postsWithMetaWrites = [];

		// A meta write on the front end is somebody else's page view — a view counter, a rating. Reading
		// the fields back there would charge every one of those views for work the scan can do instead.
		if ( ! is_admin() && ! wp_doing_cron() && ! aioseoBrokenLinkChecker()->helpers->isRestApiRequest() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->requeuePosts( $postIds );

			return;
		}

		foreach ( $postIds as $postId ) {
			$post = get_post( $postId );

			// A menu item's meta is the item, and an ACF field definition is a post carrying its own
			// settings — neither is content whose fields the report reads.
			if ( ! is_a( $post, 'WP_Post' ) || ! aioseoBrokenLinkChecker()->helpers->isScannablePost( $post ) ) {
				continue;
			}

			aioseoBrokenLinkChecker()->main->links->data->indexPostMetaLinks( $postId );
		}
	}

	/**
	 * Reindexes the given term.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $termId   The term ID.
	 * @param  int    $ttId     The term taxonomy ID.
	 * @param  string $taxonomy The taxonomy.
	 * @return void
	 */
	public function scanTerm( $termId, $ttId = 0, $taxonomy = '' ) {
		// The same taxonomies the sweep covers, and no others: a nav_menu's own description is not a term
		// description as far as the report is concerned, and nothing would ever revisit it.
		if ( ! in_array( (string) $taxonomy, $this->sweptTaxonomies(), true ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->main->links->data->indexObjectLinks( 'term', $termId, $taxonomy );
	}

	/**
	 * Drops the links a deleted term held.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $termId The term ID.
	 * @return void
	 */
	public function deleteTerm( $termId ) {
		$this->forget( 'term', $termId );
	}

	/**
	 * Reindexes the given user's bio.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Only the roles the sweep covers.
	 *
	 * @param  int  $userId The user ID.
	 * @return void
	 */
	public function scanUser( $userId ) {
		$userObject = aioseoBrokenLinkChecker()->objects->get( 'user' );

		// A role change can take a profile out of scope, so anything already recorded for it goes.
		if ( $userObject instanceof Objects\UserObject && ! $userObject->hasScannedRole( $userId ) ) {
			$this->forget( 'user', $userId );

			return;
		}

		aioseoBrokenLinkChecker()->main->links->data->indexObjectLinks( 'user', $userId );

		// A profile save can change either field, and the website is not meta so no meta hook fires for it.
		aioseoBrokenLinkChecker()->main->links->data->indexObjectLinks( 'user', $userId, Objects\UserObject::WEBSITE );
	}

	/**
	 * Reindexes the given user's bio when it's the bio that was written.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $metaId  The meta ID.
	 * @param  int    $userId  The user ID.
	 * @param  string $metaKey The meta key.
	 * @return void
	 */
	public function scanUserMeta( $metaId, $userId, $metaKey ) {
		if ( 'description' !== $metaKey ) {
			return;
		}

		$this->scanUser( $userId );
	}

	/**
	 * Drops the links a deleted user's bio held.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $userId The user ID.
	 * @return void
	 */
	public function deleteUser( $userId ) {
		$this->forget( 'user', $userId );
	}

	/**
	 * Reindexes the given menu item.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $menuId     The menu ID.
	 * @param  int  $menuItemId The menu item ID.
	 * @return void
	 */
	public function scanMenuItem( $menuId, $menuItemId = 0 ) {
		if ( empty( $menuItemId ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->main->links->data->indexObjectLinks( 'menu_item', $menuItemId );
	}

	/**
	 * Reindexes every item in the given menu.
	 *
	 * NOTE: `wp_update_nav_menu_item` doesn't fire for an item the menu editor only reordered or
	 * renamed, and the customizer's own save path bypasses it altogether, so the whole menu is walked.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $menuId The menu ID.
	 * @return void
	 */
	public function scanMenu( $menuId ) {
		$items = wp_get_nav_menu_items( (int) $menuId, [ 'update_post_term_cache' => false ] );
		if ( empty( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			aioseoBrokenLinkChecker()->main->links->data->indexObjectLinks( 'menu_item', $item->ID );
		}
	}

	/**
	 * Reindexes a pattern, template or navigation block when it is written, and drops its links when
	 * it stops being readable.
	 *
	 * NOTE: Also the trash and delete handler, since whether the object still exists is the whole of
	 * the difference between the two outcomes.
	 *
	 * @since 1.3.1
	 *
	 * @param  int          $postId The post ID.
	 * @param  \WP_Post|null $post  The post, when the hook carries it.
	 * @return void
	 */
	public function scanBlockContent( $postId, $post = null ) {
		// An upgrade may already have replaced our files in this request, so anything that reads our own
		// classes would be reading a different build. The rows outlive the request; the sweep collects them.
		if ( aioseoBrokenLinkChecker()->helpers->filesReplacedThisRequest() ) {
			return;
		}

		$postId = (int) $postId;
		if ( ! $postId || wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}

		// deleted_post fires after the row is gone, so the type comes from the post the hook passes
		// where there is one, and from the stored links where there isn't.
		$post     = is_a( $post, 'WP_Post' ) ? $post : get_post( $postId );
		$postType = is_a( $post, 'WP_Post' ) ? $post->post_type : '';
		$slug     = $postType ? $this->blockContentSlug( $postType ) : $this->storedBlockContentSlug( $postId );
		if ( ! $slug ) {
			return;
		}

		if ( aioseoBrokenLinkChecker()->objects->get( $slug )->exists( $postId ) ) {
			aioseoBrokenLinkChecker()->main->links->data->indexObjectLinks( $slug, $postId );

			return;
		}

		Models\Link::deleteObjectLinks( $slug, $postId );
	}

	/**
	 * The registered slug for the given post type, or an empty string when no kind reads it.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $postType The post type.
	 * @return string           The slug.
	 */
	private function blockContentSlug( $postType ) {
		static $map = null;

		if ( null === $map ) {
			$map = [];
			foreach ( aioseoBrokenLinkChecker()->objects->all() as $slug => $type ) {
				if ( $type instanceof Objects\BlockContentObject ) {
					$map[ $type->postType() ] = $slug;
				}
			}
		}

		return isset( $map[ $postType ] ) ? $map[ $postType ] : '';
	}

	/**
	 * The block content slug the given object already has links stored under, if any.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $postId The post ID.
	 * @return string         The slug.
	 */
	private function storedBlockContentSlug( $postId ) {
		$slugs = [];
		foreach ( aioseoBrokenLinkChecker()->objects->all() as $slug => $type ) {
			if ( $type instanceof Objects\BlockContentObject ) {
				$slugs[] = $slug;
			}
		}

		if ( empty( $slugs ) ) {
			return '';
		}

		$stored = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_links' )
			->select( 'object_type' )
			->whereIn( 'object_type', $slugs )
			->where( 'object_id', $postId )
			->limit( 1 )
			->run()
			->result();

		return ! empty( $stored[0]->object_type ) ? (string) $stored[0]->object_type : '';
	}

	/**
	 * Runs one batch of whichever sweep is due.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether there was anything to do.
	 */
	public function run() {
		// Otherwise a pass walks every object, indexes nothing, and records itself as done for a month.
		if ( ! aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
			return false;
		}

		foreach ( $this->sweptSlugs() as $slug ) {
			if ( ! $this->isDue( $slug ) ) {
				continue;
			}

			$this->sweep( $slug );

			return true;
		}

		return false;
	}

	/**
	 * The kinds the sweep visits, as the registry reports them.
	 *
	 * @since 1.3.1
	 *
	 * @return string[] The slugs.
	 */
	private function sweptSlugs() {
		$slugs = [];
		foreach ( aioseoBrokenLinkChecker()->objects->all() as $slug => $type ) {
			if ( $type->isSwept() ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Whether any sweep still has work left.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether work is pending.
	 */
	public function isPending() {
		if ( ! aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
			return false;
		}

		foreach ( $this->sweptSlugs() as $slug ) {
			if ( $this->isDue( $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the given sweep is mid-pass or due to start another.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $slug The object type slug.
	 * @return bool         Whether it is due.
	 */
	private function isDue( $slug ) {
		$state    = $this->getState( $slug );
		$interval = (int) apply_filters( 'aioseo_blc_object_sweep_interval', $this->sweepInterval, $slug );

		// A cursor past zero means the last pass didn't finish, so it resumes regardless of the interval.
		return 0 < $state['cursor'] || ( time() - $state['swept'] ) > $interval;
	}

	/**
	 * The taxonomies the sweep covers, which are the only ones a term row is recorded for.
	 *
	 * @since 1.3.1
	 *
	 * @return string[] The taxonomy names.
	 */
	private function sweptTaxonomies() {
		// nav_menu, product_visibility, wp_theme and the like are not public, so their descriptions are
		// not content the report has anything to say about.
		return array_values( get_taxonomies( [ 'public' => true ], 'names' ) );
	}

	/**
	 * Reindexes the next batch of the given type, advancing or completing the pass.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $slug The object type slug.
	 * @return void
	 */
	private function sweep( $slug ) {
		$state    = $this->getState( $slug );
		$objects  = $this->getBatch( $slug, $state['cursor'] );
		$lastSeen = $state['cursor'];

		foreach ( $objects as $object ) {
			aioseoBrokenLinkChecker()->main->links->data->indexObjectLinks( $slug, $object['id'], $object['subtype'] );
			$lastSeen = (int) $object['id'];
		}

		// A short batch is the end of the pass: the cursor resets and the interval starts counting.
		if ( count( $objects ) < $this->batchSize ) {
			$this->setState( $slug, 0, time() );

			return;
		}

		$this->setState( $slug, $lastSeen, $state['swept'] );
	}

	/**
	 * Returns the next batch of objects of the given type, in ID order.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $slug   The object type slug.
	 * @param  int    $cursor The ID the last batch reached.
	 * @return array          The objects, each with an `id` and a `subtype`.
	 */
	private function getBatch( $slug, $cursor ) {
		switch ( $slug ) {
			case 'term':
				return $this->getTermBatch( $cursor );
			case 'user':
				return $this->getUserBatch( $cursor );
			case 'menu_item':
				return $this->getMenuItemBatch( $cursor );
			default:
				return aioseoBrokenLinkChecker()->objects->get( $slug )->sweepBatch( $cursor, $this->batchSize );
		}
	}

	/**
	 * Returns the next batch of terms that have a description, across the publicly queryable taxonomies.
	 *
	 * NOTE: Driven off the description the same way the user sweep is off the bio, so the pass is
	 * proportional to the terms that have one rather than to every term on the site. A description
	 * emptied by a write that skips the term hooks therefore keeps its rows until one fires.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Skips the terms that have no description.
	 *
	 * @param  int   $cursor The term ID the last batch reached.
	 * @return array         The terms.
	 */
	private function getTermBatch( $cursor ) {
		$taxonomies = $this->sweptTaxonomies();
		if ( empty( $taxonomies ) ) {
			return [];
		}

		// nav_menu is not public, so menu items are never reached through their taxonomy here.
		$rows = aioseoBrokenLinkChecker()->core->db->start( 'term_taxonomy as tt' )
			->select( 'tt.term_id, tt.taxonomy' )
			->whereIn( 'tt.taxonomy', $taxonomies )
			->whereRaw( "tt.description <> ''" )
			->whereRaw( 'tt.term_id > ' . (int) $cursor )
			->orderBy( 'tt.term_id ASC' )
			->limit( $this->batchSize )
			->run()
			->result();

		$batch = [];
		foreach ( $rows as $row ) {
			$batch[] = [
				'id'      => (int) $row->term_id,
				'subtype' => (string) $row->taxonomy
			];
		}

		return $batch;
	}

	/**
	 * Returns the next batch of users that have a bio.
	 *
	 * NOTE: Driven off the `description` meta rather than the users table, so the sweep is proportional
	 * to the users who filled a bio in rather than to the whole membership. A bio emptied by a write
	 * that goes around the meta API therefore keeps its rows until something else touches the profile.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $cursor The user ID the last batch reached.
	 * @return array         The users.
	 */
	private function getUserBatch( $cursor ) {
		global $wpdb;

		// Named from $wpdb rather than built from the query builder's prefix: users and usermeta are
		// shared by the whole network, so a subsite's own prefix points at a table that does not exist.
		$usersTable    = $wpdb->users;
		$userMetaTable = $wpdb->usermeta;

		// A profile qualifies on either field, and the website is a column on the users table rather than
		// meta — so the bio's meta row can no longer decide for both.
		$query = aioseoBrokenLinkChecker()->core->db->start( "$usersTable as u", true )
			->select( 'DISTINCT u.ID' )
			->leftJoin( "$userMetaTable as um", "um.user_id = u.ID AND um.meta_key = 'description'", true );

		// Those tables being shared means every subsite would otherwise sweep the whole network's
		// profiles and report other sites' authors as its own. A role on this site is what makes a
		// profile this site's to scan - and which role decides whether it is scanned at all.
		$capabilitiesKey = esc_sql( $wpdb->get_blog_prefix() . 'capabilities' );

		$query->join( "$userMetaTable as cap", "cap.user_id = u.ID AND cap.meta_key = '$capabilitiesKey'", 'INNER', true );

		// Matched against the serialized role map the way WP_User_Query does it. The quotes are part of
		// the pattern, so a custom role ending in one of these names cannot match.
		$userObject  = aioseoBrokenLinkChecker()->objects->get( 'user' );
		$roles       = $userObject instanceof Objects\UserObject ? $userObject->scannedRoles() : [];
		$roleClauses = [];
		foreach ( $roles as $role ) {
			$roleClauses[] = "cap.meta_value LIKE '%\"" . esc_sql( $role ) . "\"%'";
		}

		if ( empty( $roleClauses ) ) {
			return [];
		}

		$query->whereRaw( '( ' . implode( ' OR ', $roleClauses ) . ' )' );

		$rows = $query
			->whereRaw( "( ( um.meta_value IS NOT NULL AND um.meta_value <> '' ) OR ( u.user_url IS NOT NULL AND u.user_url <> '' ) )" )
			->whereRaw( 'u.ID > ' . (int) $cursor )
			->orderBy( 'u.ID ASC' )
			->limit( $this->batchSize )
			->run()
			->result();

		$batch = [];
		foreach ( $rows as $row ) {
			// Both fields of the same profile, so a bio emptied without its own hook firing is still
			// revisited, and so is a website field the meta API never sees.
			$batch[] = [
				'id'      => (int) $row->ID,
				'subtype' => ''
			];

			$batch[] = [
				'id'      => (int) $row->ID,
				'subtype' => Objects\UserObject::WEBSITE
			];
		}

		return $batch;
	}

	/**
	 * Returns the next batch of menu items.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $cursor The item ID the last batch reached.
	 * @return array         The menu items.
	 */
	private function getMenuItemBatch( $cursor ) {
		$rows = aioseoBrokenLinkChecker()->core->db->start( 'posts as p' )
			->select( 'p.ID' )
			->where( 'p.post_type', 'nav_menu_item' )
			->where( 'p.post_status', 'publish' )
			->whereRaw( 'p.ID > ' . (int) $cursor )
			->orderBy( 'p.ID ASC' )
			->limit( $this->batchSize )
			->run()
			->result();

		$batch = [];
		foreach ( $rows as $row ) {
			$batch[] = [
				'id'      => (int) $row->ID,
				'subtype' => ''
			];
		}

		return $batch;
	}

	/**
	 * Deletes the links the given object held, and prunes the statuses that leaves unreferenced.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $slug     The object type slug.
	 * @param  int    $objectId The object ID.
	 * @return void
	 */
	private function forget( $slug, $objectId ) {
		// An upgrade may already have replaced our files in this request, so anything that reads our own
		// classes would be reading a different build. The rows outlive the request; the sweep collects them.
		if ( aioseoBrokenLinkChecker()->helpers->filesReplacedThisRequest() ) {
			return;
		}

		$linkStatusIds = Models\Link::getObjectLinkStatusIds( $slug, $objectId );

		Models\Link::deleteObjectLinks( $slug, $objectId );

		if ( $linkStatusIds ) {
			Models\LinkStatus::deleteOrphaned( $linkStatusIds );
		}
	}

	/**
	 * Returns where the given sweep got to.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $slug The object type slug.
	 * @return array{cursor: int, swept: int} The state.
	 */
	private function getState( $slug ) {
		return aioseoBrokenLinkChecker()->scanState->getObjectScan( $slug );
	}

	/**
	 * Records where the given sweep got to.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $slug   The object type slug.
	 * @param  int    $cursor The ID the sweep reached.
	 * @param  int    $swept  When the sweep last finished a full pass.
	 * @return void
	 */
	private function setState( $slug, $cursor, $swept ) {
		aioseoBrokenLinkChecker()->scanState->setObjectScan( $slug, $cursor, $swept );
	}

	/**
	 * Queues everything for reindexing, so what the indexer now decides is applied to the whole site.
	 *
	 * NOTE: Written straight to the table rather than deferred, because the settings save that calls
	 * this ends in wp_die().
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function requeueEverything() {
		$db    = aioseoBrokenLinkChecker()->core->db;
		$table = $db->prefix . 'aioseo_blc_posts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$db->db->query( "UPDATE {$table} SET link_scan_date = NULL" );

		// The other kinds are swept on an interval rather than per row, so clearing the marker is
		// the whole of it. Taken from the registry rather than named here, so a kind that starts
		// being swept is requeued along with the rest instead of keeping its old marker.
		foreach ( $this->sweptSlugs() as $slug ) {
			$this->setState( $slug, 0, 0 );
		}

		aioseoBrokenLinkChecker()->internalOptions->save( true );
		aioseoBrokenLinkChecker()->core->cache->delete( 'as_blc_link_status_idle' );
	}
}