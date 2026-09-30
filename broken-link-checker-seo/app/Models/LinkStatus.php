<?php
namespace AIOSEO\BrokenLinkChecker\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Core\Database;
use AIOSEO\BrokenLinkChecker\Links\Url;

/**
 * The LinkStatus DB model class.
 *
 * @since 1.0.0
 */
class LinkStatus extends Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_blc_link_status';

	/**
	 * The prefix every cached report count is stored under, so they can be dropped together.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const COUNTS_CACHE_PREFIX = 'report_counts_';

	/**
	 * How long a cached report count stands without anything invalidating it.
	 *
	 * Short enough that a scan's progress shows up while it runs, long enough that paging through
	 * the report does not recompute it on every click.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const COUNTS_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Fields that should be numeric values.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $integerFields = [ 'id', 'broken', 'dismissed', 'needs_additional_scan', 'client_confirmed_broken', 'scan_count', 'local_scan_count', 'redirect_count', 'http_status_code' ];

	/**
	 * Fields that are nullable.
	 *
	 * NOTE: http_status_code among them, because a check that got no answer has no code - and
	 * {@see Model::applyKeys()} casts an integer field's null to 0 unless it is listed here, which wrote
	 * a status code no server ever returned onto every row the service could not reach.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Added http_status_code, check_url and check_url_hash.
	 *
	 * @var array
	 */
	protected $nullFields = [ 'last_scan_date', 'final_url', 'http_status_code', 'check_url', 'check_url_hash' ];

	/**
	 * Fields that should be boolean values.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $booleanFields = [
		'broken',
		'dismissed',
		'needs_additional_scan',
		'client_confirmed_broken'
	];

	/**
	 * Fields that contain a JSON string.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $jsonFields = [ 'log' ];

	/**
	 * Whether the table carries the columns holding the form a link is requested at yet.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the columns exist.
	 */
	public static function hasCheckUrlColumns() {
		static $hasCheckUrlColumns = null;
		if ( null !== $hasCheckUrlColumns ) {
			return $hasCheckUrlColumns;
		}

		$hasCheckUrlColumns = aioseoBrokenLinkChecker()->core->db->columnExists( 'aioseo_blc_link_status', 'check_url_hash' );

		return $hasCheckUrlColumns;
	}

	/**
	 * Derives the form a stored URL is requested at.
	 *
	 * NOTE: Resolved before it is escaped, so a row written under older normalization - a dot segment,
	 * an upper-case host, a default port, a raw non-ASCII byte - is requested at the address it actually
	 * means. The stored URL itself is never touched by this; it is the matching key and stays put.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The stored URL.
	 * @return string      The URL to request.
	 */
	public static function deriveCheckUrl( $url ) {
		$url      = (string) $url;
		$resolved = Url::resolve( $url, $url );

		return Url::wire( '' === $resolved ? $url : $resolved );
	}

	/**
	 * Returns the form this row is requested at.
	 *
	 * NOTE: Derived rather than read off the column. The column is a key results are matched back by,
	 * and until the send-time pass has reached a row it holds the stored URL exactly as it was found.
	 *
	 * @since 1.3.1
	 *
	 * @return string The URL to request.
	 */
	public function checkUrl() {
		return self::deriveCheckUrl( $this->url );
	}

	/**
	 * Writes the form each of the given rows is requested at, where the stored one is missing or stale.
	 *
	 * This is what lets an install converge as its queue drains rather than through a migration over a
	 * table that can hold millions of rows: the batch about to be sent is the batch that gets corrected.
	 * Only ever writes the two check columns, so the URL, its hash, and the hostname columns cannot move.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $rows The rows, each carrying an id, a url, and a check_url.
	 * @return array       The URL to request for each row ID.
	 */
	public static function convergeCheckUrls( $rows ) {
		$byId = [];
		if ( empty( $rows ) ) {
			return $byId;
		}

		$db        = aioseoBrokenLinkChecker()->core->db;
		$tableName = $db->prefix . 'aioseo_blc_link_status';
		$converge  = self::hasCheckUrlColumns();

		foreach ( $rows as $row ) {
			$id             = (int) $row->id;
			$checkUrl       = self::deriveCheckUrl( $row->url );
			$storedCheckUrl = isset( $row->check_url ) ? (string) $row->check_url : '';
			$byId[ $id ]    = $checkUrl;

			if ( ! $converge || $checkUrl === $storedCheckUrl ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$db->db->query(
				$db->db->prepare(
					"UPDATE {$tableName} SET check_url = %s, check_url_hash = %s WHERE id = %d",
					$checkUrl,
					sha1( $checkUrl ),
					$id
				)
			);
		}

		return $byId;
	}

	/**
	 * Whether the links table carries the object columns.
	 *
	 * NOTE: Guarded rather than read straight off the helper. An upgrade swaps the files mid-request,
	 * so this class can be autoloaded from the new build while the old Helpers is still in memory.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the object columns exist.
	 */
	private static function hasObjectColumns() {
		$helpers = aioseoBrokenLinkChecker()->helpers;

		return method_exists( $helpers, 'hasObjectColumns' ) && $helpers->hasObjectColumns();
	}

	/**
	 * Returns the Link Status with the given ID.
	 *
	 * @since 1.0.0
	 *
	 * @param  int        $linkStatusId The Link Status ID.
	 * @return LinkStatus               The Link Status instance.
	 */
	public static function getById( $linkStatusId ) {
		return aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->where( 'id', $linkStatusId )
			->run()
			->model( 'AIOSEO\\BrokenLinkChecker\\Models\\LinkStatus' );
	}

	/**
	 * Returns a list of Link Status rows with the given IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $linkStatusIds List of Link Status IDs.
	 * @return array                List of Link Status instances.
	 */
	public static function getByIds( $linkStatusIds ) {
		return aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->whereIn( 'id', $linkStatusIds )
			->run()
			->models( 'AIOSEO\\BrokenLinkChecker\\Models\\LinkStatus' );
	}

	/**
	 * Deletes the given link-status rows that are no longer referenced by any link.
	 *
	 * @since 1.3.0
	 *
	 * @param  int[] $linkStatusIds The candidate link-status IDs to prune.
	 * @return void
	 */
	public static function deleteOrphaned( $linkStatusIds ) {
		$linkStatusIds = array_filter( array_map( 'intval', (array) $linkStatusIds ) );
		if ( empty( $linkStatusIds ) ) {
			return;
		}

		$prefix      = aioseoBrokenLinkChecker()->core->db->prefix;
		$statusTable = $prefix . 'aioseo_blc_link_status';
		$linksTable  = $prefix . 'aioseo_blc_links';
		$idList      = implode( ',', $linkStatusIds );

		aioseoBrokenLinkChecker()->core->db->execute(
			"DELETE FROM $statusTable
			WHERE id IN ($idList)
				AND NOT EXISTS (
					SELECT 1 FROM $linksTable
					WHERE $linksTable.blc_link_status_id = $statusTable.id
				)"
		);

		// The scan prunes statuses as it goes, and a pruned status is one fewer row the report lists.
		self::flushCounts();
	}

	/**
	 * Returns the Link Status with the given URL.
	 *
	 * NOTE: The requested form is tried first, because that is the string a result comes back as - the
	 * service echoes exactly what it was sent. The stored form and the join below it are what find a row
	 * written before there was a column for the requested form, and they are only reached for those.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Looks the row up by the form it was requested at first.
	 *
	 * @param  string     $url The URL (unhashed!).
	 * @return LinkStatus      The Link Status instance.
	 */
	public static function getByUrl( $url ) {
		$hash = sha1( $url );

		if ( self::hasCheckUrlColumns() ) {
			$linkStatus = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
				->where( 'check_url_hash', $hash )
				->orderBy( 'id ASC' )
				->limit( 1 )
				->run()
				->model( 'AIOSEO\\BrokenLinkChecker\\Models\\LinkStatus' );

			if ( $linkStatus->exists() ) {
				return $linkStatus;
			}
		}

		$linkStatus = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->where( 'url_hash', $hash )
			->run()
			->model( 'AIOSEO\\BrokenLinkChecker\\Models\\LinkStatus' );

		if ( ! $linkStatus->exists() ) {
			// Updates to the plugin can cause hash mismatches. Let's do another attempt using the URL.
			// We do a join to improve performance since the URL isn't indexed.
			$hostname = aioseoBrokenLinkChecker()->helpers->getStoredHostname( $url );
			$result   = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status as abls' )
				->select( 'abls.id' )
				->join( 'aioseo_blc_links as abl', 'abls.id = abl.blc_link_status_id' )
				->where( 'abl.hostname', $hostname )
				->where( 'abls.url', $url )
				->groupBy( 'abls.id' )
				->limit( 1 )
				->run()
				->result();

			if ( ! empty( $result[0]->id ) ) {
				$linkStatus = self::getById( $result[0]->id );

				if ( $linkStatus->exists() ) {
					// Reset the URL hash to prevent future mismatches. save() derives it from the URL, so
					// this is only ever the same value - assigning it would be the lie if it were not.
					$linkStatus->save();
				}
			}
		}

		return $linkStatus;
	}

	/**
	 * Returns every row requested at the same address as the given one, the row itself included.
	 *
	 * A credit is metered per unique link, so one address is sent once and one result comes back for it.
	 * Anything else sharing that address has to be settled from the same result, or it stays unchecked,
	 * is queued again on the next cycle, and can be metered a second time.
	 *
	 * @since 1.3.1
	 *
	 * @param  LinkStatus $linkStatus The row the result was matched to.
	 * @return array                  The rows, the given one first.
	 */
	public static function getSiblings( $linkStatus ) {
		$hash = isset( $linkStatus->check_url_hash ) ? (string) $linkStatus->check_url_hash : '';
		if ( ! self::hasCheckUrlColumns() || '' === $hash ) {
			return [ $linkStatus ];
		}

		$siblings = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->where( 'check_url_hash', $hash )
			->where( 'id !=', $linkStatus->id )
			->run()
			->models( 'AIOSEO\\BrokenLinkChecker\\Models\\LinkStatus' );

		return array_merge( [ $linkStatus ], $siblings );
	}

	/**
	 * Returns all broken links for a given post ID.
	 *
	 * @since   1.2.0
	 * @version 1.3.0 Exclude links pending local-scan confirmation.
	 *
	 * @param int    $postId The post ID.
	 * @return array         The list of broken links.
	 */
	public static function getBrokenByPostId( $postId ) {
		if ( ! self::hasObjectColumns() ) {
			return [];
		}

		$query = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status as als' )
			->join( 'aioseo_blc_links as al', 'als.id = al.blc_link_status_id' )
			->where( 'al.object_type', 'post' )
			->where( 'al.object_id', $postId )
			->where( 'als.broken', true )
			->where( 'als.needs_additional_scan', false )
			->where( 'als.dismissed', false );

		return $query->run()
			->result();
	}

	/**
	 * Returns the count of broken links for a given post ID.
	 *
	 * @since   1.2.7
	 * @version 1.3.0 Exclude links pending local-scan confirmation.
	 *
	 * @param int    $postId The post ID.
	 * @return int           The count of broken links.
	 */
	public static function getBrokenCountByPostId( $postId ) {
		if ( ! self::hasObjectColumns() ) {
			return 0;
		}

		return aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status as als' )
			->join( 'aioseo_blc_links as al', 'als.id = al.blc_link_status_id' )
			->where( 'al.object_type', 'post' )
			->where( 'al.object_id', $postId )
			->where( 'als.broken', true )
			->where( 'als.needs_additional_scan', false )
			->where( 'als.dismissed', false )
			->count();
	}

	/**
	 * Returns how many posts link to each of the given URLs, keyed by URL.
	 *
	 * NOTE: For a caller outside this plugin that has a set of URLs and wants to know which of the
	 * site's content points at them — the 404 log being the case it was written for. Takes the whole
	 * set rather than one URL because the caller has a page of rows, and asking per row would be a
	 * query per row.
	 *
	 * Distinct posts rather than links: a post that carries the same URL three times is one place to
	 * go and fix, not three.
	 *
	 * The URLs must be absolute, because that is how they are stored — a relative href in content is
	 * resolved against the post's permalink before it is saved. They do not have to arrive in the
	 * stored form though: each one is resolved before it is hashed.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Resolves each URL before hashing it.
	 *
	 * @param  string[]         $urls The URLs.
	 * @return array<string,int>      The count of posts per URL, absent where nothing links to it.
	 */
	public static function getPostCountsByUrls( $urls ) {
		if ( ! self::hasObjectColumns() ) {
			return [];
		}

		$byHash = [];
		foreach ( (array) $urls as $url ) {
			$url = (string) $url;
			if ( '' === $url ) {
				continue;
			}

			foreach ( self::schemeVariants( $url ) as $variant ) {
				// Resolved first, because the hash is of the stored form: a caller handing over a URL
				// that is not already in it - a dot segment, a default port, a non-Latin host - hashed
				// to something no row carries and silently got a count of zero.
				$resolved = Url::resolve( $variant, $variant );
				if ( '' === $resolved ) {
					continue;
				}

				// A set of inputs, not one: two the caller handed over that differ only by something
				// resolution drops - a fragment, a dot segment - share a hash, and one replaced the other.
				$byHash[ sha1( $resolved ) ][ $url ] = true;
			}
		}

		if ( empty( $byHash ) ) {
			return [];
		}

		// The hostname is the only indexed column that narrows this, and every URL here is one of
		// ours, so it prunes the scan to the site's own links before the hashes are compared.
		$rows = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_links as al' )
			->select( 'al.url_hash, COUNT( DISTINCT al.object_id ) as posts' )
			->where( 'al.object_type', 'post' )
			->where( 'al.hostname', aioseoBrokenLinkChecker()->helpers->getSiteDomain() )
			->whereIn( 'al.url_hash', array_keys( $byHash ) )
			->groupBy( 'al.url_hash' )
			->run()
			->result();

		$counts = [];
		foreach ( $rows as $row ) {
			if ( ! isset( $byHash[ $row->url_hash ] ) ) {
				continue;
			}

			// The highest of the schemes rather than their sum: a post that links to both would
			// otherwise be counted twice, and a number that overstates is worse than one that does
			// not, since the reader clicks through to the actual rows.
			foreach ( array_keys( $byHash[ $row->url_hash ] ) as $url ) {
				$counts[ $url ] = max( isset( $counts[ $url ] ) ? $counts[ $url ] : 0, (int) $row->posts );
			}
		}

		return $counts;
	}

	/**
	 * Returns the given URL under both schemes, so a match does not depend on which one it carries.
	 *
	 * A site indexed over http and later served over https — the usual direction — stores its links
	 * under the old scheme while home_url() reports the new one, and comparing them directly finds
	 * nothing. The failure is silent and looks exactly like having no links at all.
	 *
	 * @since 1.3.1
	 *
	 * @param  string   $url The URL.
	 * @return string[]      The URL under each scheme, or just the URL if it carries none.
	 */
	private static function schemeVariants( $url ) {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return [ $url ];
		}

		$withoutScheme = preg_replace( '#^https?://#i', '', $url );

		return [ 'http://' . $withoutScheme, 'https://' . $withoutScheme ];
	}

	/**
	 * Returns the URL of the report, narrowed to the links pointing at a given URL.
	 *
	 * NOTE: Every status rather than only the broken ones. A URL that is 404ing now may have been
	 * checked while it still worked, or not yet been checked at all, so narrowing to broken can show
	 * an empty report for a URL the site definitely links to.
	 *
	 * The scheme is left off the search term for the same reason {@see self::schemeVariants()}
	 * exists: the term is matched with LIKE, so dropping it matches the link whichever scheme it was
	 * stored under, instead of landing the reader on an empty report.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL.
	 * @return string      The URL of the report.
	 */
	public static function getReportUrlForUrl( $url ) {
		$searchTerm = preg_replace( '#^https?://#i', '', (string) $url );

		return aioseoBrokenLinkChecker()->admin->getPageUrl(
			'/broken-links?filter=all&searchTerm=' . rawurlencode( $searchTerm )
		);
	}

	/**
	 * Returns the URL of the broken links report, narrowed to a given post.
	 *
	 * NOTE: The counterpart of {@see self::getBrokenCountByPostId()}, so a caller outside this plugin
	 * can send someone to the rows behind a count without knowing our page slug or route.
	 *
	 * The post's title rather than its ID, because the report seeds its search box with whatever it
	 * was sent: a title tells the visitor what they are looking at and can be cleared, where an ID
	 * would read as a stray number. The trade-off is that the search matches titles loosely, so a
	 * post whose title is a substring of another's can bring both along.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Seeds the search with the stored title rather than the displayed one.
	 *
	 * @param  int    $postId The post ID.
	 * @return string         The URL.
	 */
	public static function getBrokenReportUrl( $postId ) {
		// The stored title, not get_the_title(): the search runs against the post_title column, and the
		// display filters turn an "&" into "&#038;", which matches nothing.
		$post  = get_post( (int) $postId );
		$title = $post ? trim( wp_strip_all_tags( $post->post_title ) ) : '';
		$route = '/broken-links?filter=broken';

		if ( '' !== $title ) {
			$route .= '&searchTerm=' . rawurlencode( $title );
		}

		return aioseoBrokenLinkChecker()->admin->getPageUrl( $route );
	}

	/**
	 * Returns link status row results based on the given arguments.
	 * This is basically a wrapper/query builder that we use to fetch all the data we need for the Broken Links Report.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Moved from Links model to Link Status model.
	 * @version 1.3.1 Rows carry the actions their object types share.
	 *
	 * @param  string $filter      The active filter.
	 * @param  int    $limit       The limit.
	 * @param  int    $offset      The offset.
	 * @param  string $whereClause The WHERE clause.
	 * @param  string $orderBy     The order by.
	 * @param  string $orderDir    The order direction.
	 * @return array               List of Link Status rows with related Link rows embedded.
	 */
	public static function rowQuery( $filter = 'all', $limit = 20, $offset = 0, $whereClause = '', $orderBy = '', $orderDir = 'DESC' ) {
		if ( ! self::hasObjectColumns() ) {
			return [];
		}

		$query = self::baseQuery( $filter, $whereClause )
			->select( 'als.*, al.external, al.is_video, al.is_image' )
			->limit( $limit, $offset );

		if ( $orderBy && $orderDir ) {
			// A row with no recorded first failure has no duration to rank, and MySQL sorts nulls first
			// when ascending - which would fill the first page with them. Pushed to the end either way,
			// which orderBy() cannot express because it strips everything but a column name.
			if ( 'als.first_failure' === $orderBy ) {
				$direction = 'ASC' === strtoupper( (string) $orderDir ) ? 'ASC' : 'DESC';

				$query->orderByRaw( "als.first_failure IS NULL ASC, als.first_failure $direction" );
			} else {
				$query->orderBy( "$orderBy $orderDir" );
			}
		} else {
			$query->orderBy( 'als.id DESC' );
		}

		$linkStatusRows = $query->run()
			->result();

		if ( empty( $linkStatusRows ) ) {
			return [];
		}

		// The counts for the whole page, and then the single links for the whole page — two queries
		// rather than two per row.
		$statusIds = array_map( function( $row ) {
			return (int) $row->id;
		}, $linkStatusRows );
		// Narrowed the same way the rows were. Without the clause the counts describe every occurrence
		// the URL has, so a row filtered to one source still labelled itself with the others and named a
		// location that expanding the row - which does apply the filter - would not list.
		$countsById = Link::rowQueryCountsBatch( $statusIds, $whereClause );

		$singleLinkIds = [];
		foreach ( $linkStatusRows as $linkStatusRow ) {
			$counts                         = isset( $countsById[ (int) $linkStatusRow->id ] )
				? $countsById[ (int) $linkStatusRow->id ]
				: [
					'total'           => 0,
					'distinctObjects' => 0,
					'objectTypes'     => []
				];
			$linkStatusRow->totalLinks      = $counts['total'];
			$linkStatusRow->distinctObjects = $counts['distinctObjects'];
			$linkStatusRow->distinctPosts   = $counts['distinctObjects'];
			$linkStatusRow->objectTypes     = $counts['objectTypes'];
			$anchored                       = ! isset( $counts['anchored'] ) || ! empty( $counts['anchored'] );
			$linkStatusRow->actions         = Link::getSharedActions( $counts['objectTypes'], $anchored );
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			$linkStatusRow->actionRefusals  = Link::getSharedActionRefusals( $counts['objectTypes'], ! empty( $counts['hasImage'] ), ! empty( $counts['hasVideo'] ), $anchored );
			$linkStatusRow->reason          = self::reasonFromLog( $linkStatusRow->log );

			// Dropped once the reason has been read off it. Nothing renders the log, and it is both the
			// largest field in the response and a description of the checking service's own
			// infrastructure — the proxy host it crawls through and that proxy's internal error codes.
			unset( $linkStatusRow->log );

			// The address the link is requested at is ours to send, not the reader's to see. It is the
			// stored URL on nearly every row, and a second URL on screen that nothing renders otherwise.
			unset( $linkStatusRow->check_url, $linkStatusRow->check_url_hash );

			if ( 1 === (int) $linkStatusRow->totalLinks ) {
				$singleLinkIds[] = (int) $linkStatusRow->id;
			}
		}

		// A status holding several is left alone here; the links table fetches those when it opens.
		// Narrowed like the counts above, so the one location a row names is one the filter left in.
		$linksByStatus = Link::rowQueryForStatuses( $singleLinkIds, $whereClause );

		$rowsWithData = [];
		foreach ( $linkStatusRows as $linkStatusRow ) {
			if ( isset( $linksByStatus[ (int) $linkStatusRow->id ] ) ) {
				$linkStatusRow->link = $linksByStatus[ (int) $linkStatusRow->id ];
			}

			$rowsWithData[] = $linkStatusRow;
		}

		return $rowsWithData;
	}

	/**
	 * Reads the failure reason out of a stored log.
	 *
	 * NOTE: Lifted onto the row so the report reads one field instead of parsing JSON. Report rows come
	 * off the query builder rather than the model, so nothing has decoded the log for them.
	 *
	 * Only the slugs the report has wording for are handed back, so a value from a newer version writing
	 * into an older one's report cannot reach the screen unrecognised.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Recognises invalid-host.
	 * @version 1.3.1 Recognises invalid-certificate.
	 * @version 1.3.1 Recognises login-required.
	 *
	 * @param  string|array|null $log The stored log, as JSON or already decoded.
	 * @return string                 The reason slug, or an empty string.
	 */
	public static function reasonFromLog( $log ) {
		$decoded = is_string( $log ) ? json_decode( $log, true ) : $log;
		if ( ! is_array( $decoded ) || empty( $decoded['reason'] ) ) {
			return '';
		}

		$reason = (string) $decoded['reason'];

		// phpcs:ignore Generic.Files.LineLength.MaxExceeded
		return in_array( $reason, [ 'blocked', 'timeout', 'unreachable', 'invalid-host', 'invalid-certificate', 'login-required' ], true ) ? $reason : '';
	}

	/**
	 * Returns a page of rows for the CSV export.
	 *
	 * NOTE: Paging is on `al.url_hash`, which is what the rows are grouped by - one value per URL by
	 * construction, so it is a total order on its own and needs no tiebreak. Not `als.id`, which
	 * repeats per group, and not `al.url`: the collation folds case, so two spellings compare equal
	 * and would step over one another at a page boundary.
	 *
	 * Ordering on the grouping key is also the whole cost of the export. Any other order has to group
	 * the matching rows into a temporary table and sort it before it can take a page, so every page
	 * pays for the whole table; on this one MySQL walks the index and stops at the limit.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Pages on the hash alone, in the order the rows are grouped.
	 *
	 * @param  string      $filter      The active filter.
	 * @param  string      $whereClause The WHERE clause.
	 * @param  string|null $afterHash   The hash to continue after, or null to start at the first row.
	 * @param  int         $limit       The maximum number of rows.
	 * @return array                    The rows.
	 */
	public static function exportRows( $filter = 'all', $whereClause = '', $afterHash = null, $limit = 500 ) {
		if ( ! self::hasObjectColumns() ) {
			return [];
		}

		$query = self::baseQuery( $filter, $whereClause );

		if ( null !== $afterHash ) {
			$hash = esc_sql( (string) $afterHash );

			$query->whereRaw( "al.url_hash > '$hash'" );
		}

		$select = [
			'al.url as url',
			'al.url_hash as url_hash',
			'als.id as link_status_id',
			'als.broken',
			'als.http_status_code',
			'als.needs_additional_scan',
			'als.redirect_count',
			'als.last_scan_date',
			// Read for its reason and dropped again. A row can be broken behind a 200 - a certificate no
			// browser accepts is one - and the status column alone leaves that looking like a mistake.
			'als.log',
			'COUNT(*) as total_links',
			"COUNT(DISTINCT CONCAT(al.object_type, ':', al.object_id)) as object_count",
			// Enough objects for the export to find one the user may edit, with room for the ones that
			// hold the URL several times over and take a slot per occurrence.
			Link::objectRefsSql( 20 ) . ' as object_refs',
			'MIN(al.anchor) as anchor'
		];

		$rows = $query->select( implode( ', ', $select ) )
			->orderBy( 'al.url_hash ASC' )
			->limit( $limit )
			->run()
			->result();

		return $rows ? $rows : [];
	}

	/**
	 * Returns link status row count based on the given arguments.
	 * This is basically a wrapper/query builder that we use to fetch all the counts we need for the Broken Links Report.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Moved from Links model to Link Status model.
	 *
	 * @param  string $filter      The active filter.
	 * @param  string $whereClause The WHERE clause.
	 * @return int                 The row count.
	 */
	public static function rowCountQuery( $filter = 'all', $whereClause = '' ) {
		if ( ! self::hasObjectColumns() ) {
			return 0;
		}

		$query = self::baseQuery( $filter, $whereClause );

		return $query->count();
	}

	/**
	 * Returns the links that broke in the given window, newest failure first.
	 *
	 * NOTE: Deliberately does not use {@see Link::rowQuery()} for the post context — that filters on
	 * `current_user_can()`, which discards every row when there is no user, as in cron.
	 *
	 * @since 1.3.1
	 *
	 * @param  int      $start The inclusive start timestamp.
	 * @param  int|null $end   The exclusive end timestamp, or null for "up to now".
	 * @param  int      $limit The maximum number of rows.
	 * @return array           The rows.
	 */
	public static function getBrokenInWindow( $start, $end = null, $limit = 5 ) {
		if ( ! self::hasObjectColumns() ) {
			return [];
		}

		$select = 'als.id, als.url, als.http_status_code, als.first_failure, '
			. Link::objectRefsSql() . " as object_ref, COUNT(DISTINCT CONCAT(al.object_type, ':', al.object_id)) as object_count";

		$rows = self::reportQuery( $start, $end )
			->select( $select )
			->groupBy( 'als.id' )
			->orderBy( 'als.first_failure DESC, als.id DESC' )
			->limit( $limit )
			->run()
			->result();

		return $rows ? $rows : [];
	}

	/**
	 * Returns how many links broke in the given window.
	 *
	 * @since 1.3.1
	 *
	 * @param  int      $start The inclusive start timestamp.
	 * @param  int|null $end   The exclusive end timestamp, or null for "up to now".
	 * @return int             The count.
	 */
	public static function countBrokenInWindow( $start, $end = null ) {
		if ( ! self::hasObjectColumns() ) {
			return 0;
		}

		$result = self::reportQuery( $start, $end )
			->select( 'COUNT(DISTINCT als.id) as count' )
			->run()
			->result();

		return ! empty( $result[0]->count ) ? (int) $result[0]->count : 0;
	}

	/**
	 * Returns the totals the monthly scorecard reports.
	 *
	 * Conditional aggregation keeps this to a single query — a count per metric, as {@see
	 * self::rowCountQuery()} does per filter, would re-run the same three-table join for each one.
	 *
	 * @since 1.3.1
	 *
	 * @return array{checked: int, broken: int, redirects: int} The totals.
	 */
	public static function getReportTotals() {
		if ( ! self::hasObjectColumns() ) {
			return [
				'checked'   => 0,
				'broken'    => 0,
				'redirects' => 0
			];
		}

		// The tab's own rules rather than a second set: the email counted a link that redirected and
		// then died as both broken and a redirect, where the report counts it as broken only.
		$select = 'COUNT(DISTINCT CASE WHEN als.last_scan_date IS NOT NULL THEN als.id END) as checked'
			. ', COUNT(DISTINCT CASE WHEN ' . self::bucketCondition( 'broken' ) . ' THEN als.id END) as broken'
			. ', COUNT(DISTINCT CASE WHEN ' . self::bucketCondition( 'redirects' ) . ' THEN als.id END) as redirects';

		$result = self::reportQuery()
			->select( $select )
			->run()
			->result();

		$row = ! empty( $result[0] ) ? $result[0] : null;

		return [
			'checked'   => $row ? (int) $row->checked : 0,
			'broken'    => $row ? (int) $row->broken : 0,
			'redirects' => $row ? (int) $row->redirects : 0
		];
	}

	/**
	 * Returns how many links each of the report's filters lists.
	 *
	 * Conditional aggregation keeps this to a single query — a count per filter, as {@see
	 * self::rowCountQuery()} does, re-runs the same three-table join for each one and, because that
	 * query groups by URL, transfers one row per distinct URL for every filter.
	 *
	 * @since 1.3.1
	 *
	 * @return array<string, int> The count per filter.
	 */
	public static function getFilterTotals() {
		$empty = [
			'all'         => 0,
			'broken'      => 0,
			'redirects'   => 0,
			'good'        => 0,
			'not_checked' => 0,
			'not_scanned' => 0,
			'broken_any'  => 0,
			'dismissed'   => 0
		];

		if ( ! self::hasObjectColumns() ) {
			return $empty;
		}

		// Counted on the hash, not the URL: the url column's collation folds case, so two links whose
		// paths differ only in case collapse into one - and the buckets then add up to more than All.
		$select = [
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 THEN al.url_hash END) as all_links',
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 AND ' . self::bucketCondition( 'broken' ) . ' THEN al.url_hash END) as broken',
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 AND ' . self::bucketCondition( 'redirects' ) . ' THEN al.url_hash END) as redirects',
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 AND ' . self::bucketCondition( 'good' ) . ' THEN al.url_hash END) as good',
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 AND ' . self::bucketCondition( 'not-checked' ) . ' THEN al.url_hash END) as not_checked',
			'COUNT(DISTINCT CASE WHEN als.dismissed = 1 THEN al.url_hash END) as dismissed',
			// Not the same question as the not-checked tab, which also holds the rows waiting on a local
			// retry. Those have been checked - the answer just wasn't usable - so anything measuring
			// whether the scan has been round reads this instead.
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 AND als.last_scan_date IS NULL THEN al.url_hash END) as not_scanned',
			// Broken whether or not the verdict has settled. The broken tab only counts a settled one, so
			// a site whose every broken link is mid-retry would otherwise read as having none.
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 AND als.broken = 1 THEN al.url_hash END) as broken_any'
		];

		$result = self::reportQuery( null, null, true )
			->select( implode( ', ', $select ) )
			->run()
			->result();

		$row = ! empty( $result[0] ) ? $result[0] : null;
		if ( ! $row ) {
			return $empty;
		}

		return [
			'all'         => (int) $row->all_links,
			'broken'      => (int) $row->broken,
			'redirects'   => (int) $row->redirects,
			'good'        => (int) $row->good,
			'not_checked' => (int) $row->not_checked,
			'not_scanned' => (int) $row->not_scanned,
			'broken_any'  => (int) $row->broken_any,
			'dismissed'   => (int) $row->dismissed
		];
	}

	/**
	 * Returns an ungrouped query scoped to the links the report covers.
	 *
	 * Mirrors the inclusion and exclusion rules of {@see self::baseQuery()} but leaves out its
	 * `GROUP BY`, so callers can aggregate or group as they need - the dashboard's figures included.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Public, for the dashboard's aggregations.
	 *
	 * @param  int|null $start            The inclusive `first_failure` start timestamp, or null for no window.
	 * @param  int|null $end              The exclusive `first_failure` end timestamp, or null for "up to now".
	 * @param  bool     $includeDismissed Whether to leave dismissed links in, for callers counting them.
	 * @return Database                   The query.
	 */
	public static function reportQuery( $start = null, $end = null, $includeDismissed = false ) {
		$query = self::scopedQuery();

		if ( ! $includeDismissed ) {
			$query->where( 'als.dismissed', false );
		}

		if ( null !== $start ) {
			$startMysql = esc_sql( aioseoBrokenLinkChecker()->helpers->timeToMysql( (int) $start ) );

			$query->where( 'als.broken', true )
				->where( 'als.needs_additional_scan', false )
				->whereRaw( "als.first_failure IS NOT NULL AND als.first_failure >= '$startMysql'" );
		}

		if ( null !== $end ) {
			$endMysql = esc_sql( aioseoBrokenLinkChecker()->helpers->timeToMysql( (int) $end ) );

			$query->whereRaw( "als.first_failure < '$endMysql'" );
		}

		return $query;
	}

	/**
	 * Returns the base query for the rowQuery() and rowCountQuery() methods.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Moved from Links model to Link Status model.
	 * @version 1.3.0 Exclude needs_additional_scan rows from broken and redirects filters.
	 * @version 1.3.1 Groups case-sensitively, so every counted URL is also listed.
	 *
	 * @param  string   $filter      The active filter.
	 * @param  string   $whereClause The WHERE clause.
	 * @return Database              The query.
	 */
	private static function baseQuery( $filter = 'all', $whereClause = '' ) {
		// Grouped on the hash, like the counts: on al.url the collation folds two links whose paths
		// differ only in case into one row, so one of them is counted but never listed.
		$query = self::scopedQuery()
			->groupBy( 'al.url_hash' );

		if ( ! empty( $whereClause ) ) {
			$query->whereRaw( $whereClause );
		}

		return self::applyFilter( $query, $filter );
	}

	/**
	 * Returns an ungrouped query over the links of one filter, for counting them per source.
	 *
	 * @since 1.3.1
	 *
	 * @param  string   $filter      The active filter.
	 * @param  string   $whereClause The WHERE clause.
	 * @return Database              The query.
	 */
	public static function objectTypeQuery( $filter = 'all', $whereClause = '' ) {
		$query = self::scopedQuery();

		if ( ! empty( $whereClause ) ) {
			$query->whereRaw( $whereClause );
		}

		return self::applyFilter( $query, $filter );
	}

	/**
	 * Returns the three-table query the report is built on, scoped to the sources it covers.
	 *
	 * The posts table is joined on the object columns rather than on the deprecated `post_id`, and the
	 * post-shaped rules only apply to the rows a post is the source of — applied unconditionally
	 * against a LEFT JOIN they would drop every other source.
	 *
	 * @since 1.3.1
	 *
	 * @return Database The query.
	 */
	private static function scopedQuery() {
		// The user scope as well as the report's own. Without it the rows and the counts were the site's
		// rather than the reader's: the locations were withheld from someone who may not see them, but the
		// URL was still listed — and a URL on an unpublished post is the thing worth withholding. The
		// object scope runs first, because it is what joins the posts table these conditions read.
		return Link::applyUserScope(
			Link::applyObjectScope(
				aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status as als' )
					->join( 'aioseo_blc_links as al', 'als.id = al.blc_link_status_id' )
			)
		);
	}

	/**
	 * Returns the count for every one of the report's filters, in one query.
	 *
	 * NOTE: Built on {@see self::scopedQuery()} — the same joins, scope and WHERE the rows come
	 * from — with the filters expressed as conditional aggregates rather than as six separate
	 * queries. Sharing the base is the point: a count from a differently-built query can disagree
	 * with the list beneath it, and a tab that says 25 above 22 rows is worse than a slow tab.
	 *
	 * The conditions mirror {@see self::applyFilter()} exactly. Both have to change together.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $whereClause The WHERE clause the report is using.
	 * @return array<string,int>   The count per filter slug.
	 */
	public static function filterCounts( $whereClause = '' ) {
		$empty = [
			'all'         => 0,
			'broken'      => 0,
			'redirects'   => 0,
			'good'        => 0,
			'not-checked' => 0,
			'dismissed'   => 0
		];

		if ( ! self::hasObjectColumns() ) {
			return $empty;
		}

		$query = self::scopedQuery();
		if ( ! empty( $whereClause ) ) {
			$query->whereRaw( $whereClause );
		}

		// Counted on the hash, not the URL: the url column's collation folds case, so two links whose
		// paths differ only in case collapse into one - and the buckets then add up to more than All.
		$select = [
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 THEN al.url_hash END) as all_links',
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 AND ' . self::bucketCondition( 'broken' ) . ' THEN al.url_hash END) as broken',
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 AND ' . self::bucketCondition( 'redirects' ) . ' THEN al.url_hash END) as redirects',
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 AND ' . self::bucketCondition( 'good' ) . ' THEN al.url_hash END) as good',
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'COUNT(DISTINCT CASE WHEN als.dismissed = 0 AND ' . self::bucketCondition( 'not-checked' ) . ' THEN al.url_hash END) as not_checked',
			'COUNT(DISTINCT CASE WHEN als.dismissed = 1 THEN al.url_hash END) as dismissed'
		];

		// Taken as SQL before anything else runs a query. The builder is shared, so the cache lookup
		// below — itself a query — resets it, and a query held across that call comes back empty.
		$sql = (string) $query->select( implode( ', ', $select ) )
			->query();

		// Keyed on the SQL rather than on the arguments, so the user scope the query carries is part
		// of the key without having to be described twice. Two users who may see different rows ask
		// different questions and get different answers.
		$cacheKey = self::COUNTS_CACHE_PREFIX . md5( $sql );
		$cached   = aioseoBrokenLinkChecker()->core->cache->get( $cacheKey );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = aioseoBrokenLinkChecker()->core->db->db->get_row( $sql );
		if ( ! $row ) {
			return $empty;
		}

		$counts = [
			'all'         => (int) $row->all_links,
			'broken'      => (int) $row->broken,
			'redirects'   => (int) $row->redirects,
			'good'        => (int) $row->good,
			'not-checked' => (int) $row->not_checked,
			'dismissed'   => (int) $row->dismissed
		];

		aioseoBrokenLinkChecker()->core->cache->update( $cacheKey, $counts, self::COUNTS_CACHE_TTL );

		return $counts;
	}

	/**
	 * Drops the cached report counts.
	 *
	 * NOTE: Called from the actions that change what the counts say, so the numbers move as soon as
	 * the reader does something. The scan is left to the expiry instead — it rewrites statuses in
	 * batches for as long as it runs, and a scan that clears the cache on every batch would mean the
	 * counts are never cached during the one period they are read most.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public static function flushCounts() {
		static $scheduled = false;
		if ( $scheduled ) {
			return;
		}

		$scheduled = true;

		// On shutdown rather than now, so a request that writes many rows clears once, and so the
		// clear lands after the last of those writes rather than between two of them.
		add_action( 'shutdown', function() {
			aioseoBrokenLinkChecker()->core->cache->clearPrefix( self::COUNTS_CACHE_PREFIX );
		} );
	}

	/**
	 * Narrows the given query to the links one of the report's filters lists.
	 *
	 * @since 1.3.1
	 *
	 * @param  Database $query  The query.
	 * @param  string   $filter The active filter.
	 * @return Database         The query.
	 */
	private static function applyFilter( $query, $filter ) {
		if ( ! empty( $filter ) ) {
			switch ( $filter ) {
				case 'good':
				case 'broken':
				case 'redirects':
					$query->where( 'als.dismissed', false );
					$query->whereRaw( self::bucketCondition( $filter ) );
					break;
				case 'dismissed':
					$query->where( 'als.dismissed', true );
					break;
				case 'not-checked':
					$query->where( 'als.dismissed', false );
					$query->whereRaw( self::bucketCondition( 'not-checked' ) );
					break;
				case 'all':
				default:
					$query->where( 'als.dismissed', false );
					break;
			}
		}

		return $query;
	}

	/**
	 * The SQL condition selecting one status bucket.
	 *
	 * NOTE: The buckets are mutually exclusive and follow the precedence the Status column renders in -
	 * a row waiting on a scan is pending whatever else is true of it, and a broken row is broken even
	 * when it also redirects. Without that ordering a URL lands in two buckets and the tab counts add
	 * up to more than All.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $bucket The bucket.
	 * @return string         The condition, or an empty string for one that does not narrow.
	 */
	private static function bucketCondition( $bucket ) {
		// Anything still queued for a scan reads as pending, so the other three only describe rows that
		// have been checked and are not waiting to be checked again.
		$settled = 'als.last_scan_date IS NOT NULL AND als.needs_additional_scan = 0';

		switch ( $bucket ) {
			case 'broken':
				return "$settled AND als.broken = 1";
			case 'redirects':
				return "$settled AND als.broken = 0 AND als.redirect_count > 0";
			case 'good':
				return "$settled AND als.broken = 0 AND als.redirect_count = 0";
			case 'not-checked':
				return '( als.last_scan_date IS NULL OR als.needs_additional_scan = 1 )';
			default:
				return '';
		}
	}

	/**
	 * Apply filter before saving.
	 *
	 * @since 1.2.6
	 *
	 * @return void
	 */
	public function save() {
		$fields = $this->transform( $this->filter( (array) get_object_vars( $this ) ) );

		$fields['url']      = apply_filters( 'aioseo_blc_link_url_before_save', $fields['url'] );
		$fields['url_hash'] = sha1( $fields['url'] );

		$this->applyKeys( $this->transform( $this->filter( $fields ) ) );

		parent::save();

		// Any write here can move a report count, so they are dropped rather than left to expire.
		self::flushCounts();
	}
}