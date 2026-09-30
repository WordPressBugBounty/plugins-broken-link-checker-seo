<?php
namespace AIOSEO\BrokenLinkChecker\Links;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;
use AIOSEO\BrokenLinkChecker\Objects;
use AIOSEO\BrokenLinkChecker\Utils\BlcHtmlTagProcessor;

/**
 * Handles the extraction, parsing and storage of links for the links scan.
 *
 * @since 1.0.0
 */
class Data {
	/**
	 * The ignored extensions.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $ignoredExtensions = [];

	/**
	 * The object the scan is currently reading links out of.
	 *
	 * @since 1.3.1
	 *
	 * @var array
	 */
	private $context = [
		'objectType'    => 'post',
		'objectId'      => 0,
		'objectSubtype' => '',
		'baseUrl'       => ''
	];

	/**
	 * What each object this request indexed held at the time, keyed by object.
	 *
	 * @since 1.3.1
	 *
	 * @var array
	 */
	private $indexed = [];

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->setIgnoredExtensions();
	}

	/**
	 * Indexes the links in the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $postId The post ID.
	 * @return void
	 */
	public function indexLinks( $postId ) {
		$this->indexObjectLinks( 'post', $postId );

		// Its own field, addressed by subtype, so this cannot disturb the rows the content just produced.
		$this->indexObjectLinks( 'post', $postId, Objects\PostObject::EXCERPT );
	}

	/**
	 * Indexes the links in the given object, whatever kind it is.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $objectType The object type.
	 * @param  int    $objectId   The object ID.
	 * @param  string $subtype    The object subtype.
	 * @return void
	 */
	public function indexObjectLinks( $objectType, $objectId, $subtype = '' ) {
		if ( ! aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
			return;
		}

		$objectId = (int) $objectId;
		$type     = aioseoBrokenLinkChecker()->objects->get( $objectType );

		// Only the rows of the one subtype for a kind that addresses its objects by it, so reindexing one
		// custom field doesn't drop what the same post's other fields hold.
		$scope = $type->isSubtypeAddressed() ? (string) $subtype : null;

		// Indexed whether or not its source is switched on. Being switched off decides what is monitored
		// and what the report lists, not what is recorded — every read applies that scope already, the
		// metered check included {@see \AIOSEO\BrokenLinkChecker\LinkStatus\Data::linksToCheckQuery()}.
		// Recording it regardless is what makes switching a source back on immediate instead of a reindex
		// of the whole site, and it is why a source being switched off costs no data.

		// An object that is gone has nothing left to report.
		if ( ! $type->exists( $objectId, $subtype ) ) {
			Models\Link::deleteObjectLinks( $objectType, $objectId, $scope );
			$this->forgetIndexed( $type->type(), $objectId, (string) $subtype );

			return;
		}

		$isRichText = $type->isRichText( $objectId, $subtype );
		$content    = $isRichText
			? $type->getContent( $objectId, $subtype )
			: $type->getUrl( $objectId, $subtype );

		// The label is half of what a value-link row holds, so a rename with the URL untouched has to
		// miss the memo — the menu editor renames items after the hook that walks the whole menu.
		$indexed = $isRichText
			? $content
			: $content . "\t" . $type->locationLabel( $objectId, $subtype );

		if ( $this->wasIndexed( $type->type(), $objectId, (string) $subtype, $indexed ) ) {
			return;
		}

		$this->setContext( $type, $objectId, $subtype );

		if ( ! $isRichText ) {
			$links = $this->extractValueLink( $type, $objectId, $subtype, $content );

			// Renaming a menu walks every item in it, whether or not any of them moved. An item whose
			// stored row already says what we would write is left alone rather than rewritten.
			if ( $this->valueLinkUnchanged( $type->type(), $objectId, $scope, $links ) ) {
				return;
			}

			Models\Link::deleteObjectLinks( $type->type(), $objectId, $scope );

			if ( ! empty( $links ) ) {
				$this->storeLinks( $links );
			}

			return;
		}

		// Delete all links first. We have to do this in order to remove old links that no longer exist.
		Models\Link::deleteObjectLinks( $type->type(), $objectId, $scope );

		$links = $this->extractLinks( $content, $type->findsBareUrls() );

		if ( empty( $links ) ) {
			return;
		}

		$this->storeLinks( $links );
	}

	/**
	 * Indexes the links a page builder holds for the given post.
	 *
	 * Kept separate from the post content pass because the content lives somewhere else entirely — a
	 * builder that stores its own tree is a source of its own, not a variation on post content.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $postId The post ID.
	 * @return void
	 */
	public function indexPostBuilderLinks( $postId ) {
		$postId   = (int) $postId;
		$noLayout = [];

		foreach ( aioseoBrokenLinkChecker()->objects->all() as $slug => $type ) {
			if ( ! $type instanceof \AIOSEO\BrokenLinkChecker\Objects\BuilderObject ) {
				continue;
			}

			// A builder holding nothing for this post needs its rows cleared and nothing else, so those are
			// collected and cleared together. Reading the layout costs no query — the post and its meta are
			// already in memory by now — where asking each builder to clear itself cost one each.
			if ( ! $type->hasLayout( $postId ) ) {
				$noLayout[] = $slug;

				continue;
			}

			$this->indexObjectLinks( $slug, $postId );
		}

		Models\Link::deleteObjectTypesLinks( $postId, $noLayout );
	}

	/**
	 * Indexes the links in every custom field of the given post that holds one.
	 *
	 * The post's fields are replaced together, in one indexed delete, so a field whose value or whose
	 * definition is gone leaves nothing behind. Which fields those are comes from what declares them —
	 * see {@see \AIOSEO\BrokenLinkChecker\Objects\CustomFields}.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $postId The post ID.
	 * @return void
	 */
	public function indexPostMetaLinks( $postId ) {
		if ( ! aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
			return;
		}

		$postId = (int) $postId;
		$type   = aioseoBrokenLinkChecker()->objects->get( 'post_meta' );

		if ( ! is_a( get_post( $postId ), 'WP_Post' ) ) {
			Models\Link::deleteObjectLinks( 'post_meta', $postId );
			$this->forgetIndexed( 'post_meta', $postId, '' );

			return;
		}

		$values      = $type->scannableValues( $postId );
		$fingerprint = '';
		foreach ( $values as $metaKey => $value ) {
			$fingerprint .= $metaKey . "\t" . $value . "\n";
		}

		// One memo for the whole post, since its fields are indexed and dropped as a set.
		if ( $this->wasIndexed( 'post_meta', $postId, '', $fingerprint ) ) {
			return;
		}

		Models\Link::deleteObjectLinks( 'post_meta', $postId );

		foreach ( $values as $metaKey => $value ) {
			$this->setContext( $type, $postId, $metaKey );

			$links = $type->isRichText( $postId, $metaKey )
				? $this->extractLinks( $value, $type->findsBareUrls() )
				: $this->extractValueLink( $type, $postId, $metaKey, $value );

			if ( ! empty( $links ) ) {
				$this->storeLinks( $links );
			}
		}
	}

	/**
	 * Points the scan at the object it is about to read links out of.
	 *
	 * @since 1.3.1
	 *
	 * @param  \AIOSEO\BrokenLinkChecker\Objects\ObjectType $type     The object type.
	 * @param  int                                          $objectId The object ID.
	 * @param  string                                       $subtype  The object subtype.
	 * @return void
	 */
	private function setContext( $type, $objectId, $subtype ) {
		$this->context = [
			'objectType'    => $type->type(),
			'objectId'      => (int) $objectId,
			'objectSubtype' => (string) $subtype,
			'baseUrl'       => $type->baseUrl( $objectId, $subtype )
		];
	}

	/**
	 * Whether the stored rows for an object whose URL is its value already say what we would write.
	 *
	 * The URL and the label are the whole of such a row — every other column is derived from one of
	 * them — so matching both means the rewrite would be a no-op.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Takes the subtype the rows are scoped to.
	 *
	 * @param  string      $objectType The object type.
	 * @param  int         $objectId   The object ID.
	 * @param  string|null $subtype    The object subtype, or null for every one of them.
	 * @param  array       $links      The links the object holds now.
	 * @return bool                    Whether they are unchanged.
	 */
	private function valueLinkUnchanged( $objectType, $objectId, $subtype, $links ) {
		$query = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_links' )
			->select( 'url_hash, anchor, blc_link_status_id' )
			->where( 'object_type', $objectType )
			->where( 'object_id', $objectId );

		if ( null !== $subtype ) {
			$query->where( 'object_subtype', (string) $subtype );
		}

		$rows = $query->run()->result();

		$stored = [];
		foreach ( (array) $rows as $row ) {
			$row = (array) $row;

			// A row that never got its link status resolved doesn't match: resolving it is the write owed.
			if ( empty( $row['blc_link_status_id'] ) ) {
				return false;
			}

			$stored[] = $row['url_hash'] . "\t" . $row['anchor'];
		}

		$current = [];
		foreach ( $links as $link ) {
			$current[] = $link['url_hash'] . "\t" . sanitize_text_field( $link['anchor'] );
		}

		if ( count( $stored ) !== count( $current ) ) {
			return false;
		}

		sort( $stored );
		sort( $current );

		return $stored === $current;
	}

	/**
	 * Whether this request has already indexed the given object with the content it holds now.
	 *
	 * Saving a menu fires the per-item hook for every item and then the whole-menu hook, which walks
	 * them all again; two sweep batches in one request overlap the same way. Keyed on what the object
	 * held as well as on the object, so a second write in the same request is still picked up.
	 *
	 * NOTE: Records what it was asked about, so a caller that skips the reindex on a true return keeps
	 * the record honest.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $objectType  The object type.
	 * @param  int    $objectId    The object ID.
	 * @param  string $subtype     The object subtype.
	 * @param  string $fingerprint Everything the stored rows are derived from.
	 * @return bool                Whether it was already indexed.
	 */
	private function wasIndexed( $objectType, $objectId, $subtype, $fingerprint ) {
		$key  = $objectType . ':' . $objectId . ':' . $subtype;
		$hash = md5( (string) $fingerprint );

		if ( isset( $this->indexed[ $key ] ) && $this->indexed[ $key ] === $hash ) {
			return true;
		}

		$this->indexed[ $key ] = $hash;

		return false;
	}

	/**
	 * Drops what the memo says about the given object, for the paths that delete instead of indexing.
	 *
	 * Without this a delete and a reindex in the same request read as the same content twice, and the
	 * second one returns before it puts back what the first took away.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $objectType The object type.
	 * @param  int    $objectId   The object ID.
	 * @param  string $subtype    The object subtype.
	 * @return void
	 */
	private function forgetIndexed( $objectType, $objectId, $subtype ) {
		unset( $this->indexed[ $objectType . ':' . $objectId . ':' . $subtype ] );
	}

	/**
	 * Builds the single link for an object whose URL is its value rather than an anchor in its content.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Takes the stored URL instead of reading it again.
	 *
	 * @param  \AIOSEO\BrokenLinkChecker\Objects\ObjectType $type        The object type.
	 * @param  int                                          $objectId    The object ID.
	 * @param  string                                       $subtype     The object subtype.
	 * @param  string                                       $capturedUrl The stored URL.
	 * @return array                                                     The links.
	 */
	private function extractValueLink( $type, $objectId, $subtype, $capturedUrl ) {
		if ( '' === trim( (string) $capturedUrl ) || '#' === $capturedUrl[0] ) {
			return [];
		}

		if ( aioseoBrokenLinkChecker()->helpers->hasUnscannableScheme( $capturedUrl ) ) {
			return [];
		}

		$parsedUrl = $this->parseUrl( $capturedUrl );
		if ( empty( $parsedUrl['host'] ) ) {
			return [];
		}

		if ( $this->hasIgnoredExtension( $parsedUrl ) ) {
			return [];
		}

		$label = $type->locationLabel( $objectId, $subtype );

		$link = array_merge( $this->urlFields( $parsedUrl ), [
			'anchor'         => $label,
			'phrase'         => $label,
			'phrase_html'    => '',
			'paragraph'      => '',
			'paragraph_html' => '',
			'is_video'       => false,
			'is_image'       => false,
			'is_embed'       => false
		] );

		return [ $link ];
	}

	/**
	 * Returns the addressing and URL fields every stored link carries.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Stores the resolver's own URL rather than rebuilding one from the parts.
	 *
	 * @param  array $parsedUrl The resolved parts, from {@see self::parseUrl()}.
	 * @return array            The fields.
	 */
	private function urlFields( $parsedUrl ) {
		// NOTE: We need to check this here before we strip off the "www" part.
		// Otherwise we will not be able to detect internal links on sites running on "www".
		$isInternal = $parsedUrl['host'] === $this->getHostname();
		$hostname   = Url::host( $parsedUrl['host'] );

		// The resolver's own form, because rebuilding one from the parts lost information the resolver
		// had: buildUrl() drops a `?0` query as empty, and re-parsing dropped the userinfo entirely.
		$url = apply_filters( 'aioseo_blc_link_url_before_save', $parsedUrl['url'] );

		return [
			// Mirrors object_id for a post and stays 0 for every other source. Deprecated: read the
			// object columns instead.
			'post_id'            => 'post' === $this->context['objectType'] ? (int) $this->context['objectId'] : 0,
			'object_type'        => $this->context['objectType'],
			'object_id'          => (int) $this->context['objectId'],
			'object_subtype'     => $this->context['objectSubtype'],
			// Left for storeLinks() to fill in for the whole object at once. Resolving it here meant a
			// query per link, and the answer was worked out a second time in bulk down there anyway.
			// The key stays because the insert is positional — dropping it shifts every column after it.
			'blc_link_status_id' => null,
			'url'                => $url,
			'url_hash'           => sha1( $url ),
			'hostname'           => $hostname,
			'hostname_url'       => sha1( $hostname ),
			'external'           => ! $isInternal
		];
	}

	/**
	 * Whether the given parsed URL points at a file extension the scan leaves alone.
	 *
	 * NOTE: Only the last segment's extension counts. Matching from the first dot instead read
	 * `/dir.v1/file.exe` as an extension of `v1/file.exe` and `/dir.exe/page` as one of `exe/page`, so
	 * a directory with a dot in its name decided the answer for everything under it.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Matches the last path segment's extension, case-insensitively.
	 *
	 * @param  array $parsedUrl The parsed URL.
	 * @return bool             Whether it is ignored.
	 */
	private function hasIgnoredExtension( $parsedUrl ) {
		if ( empty( $parsedUrl['path'] ) ) {
			return false;
		}

		$path    = (string) $parsedUrl['path'];
		$slash   = strrpos( $path, '/' );
		$segment = false === $slash ? $path : substr( $path, $slash + 1 );
		$dot     = strrpos( $segment, '.' );
		if ( false === $dot ) {
			return false;
		}

		$extension = strtolower( substr( $segment, $dot + 1 ) );

		return '' !== $extension && in_array( $extension, $this->ignoredExtensions, true );
	}

	/**
	 * The key the paragraph cache is scoped by, which has to tell two objects of different kinds apart.
	 *
	 * @since 1.3.1
	 *
	 * @return string The key.
	 */
	private function contextKey() {
		return $this->context['objectType'] . ':' . $this->context['objectId'] . ':' . $this->context['objectSubtype'];
	}

	/**
	 * Stores the given links to the DB.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Stores the object addressing columns.
	 *
	 * @param  array $links The links.
	 * @return void
	 */
	private function storeLinks( $links ) {
		$columns = [
			'post_id',
			'object_type',
			'object_id',
			'object_subtype',
			'blc_link_status_id',
			'url',
			'url_hash',
			'hostname',
			'hostname_url',
			'external',
			'anchor',
			'phrase',
			'phrase_html',
			'paragraph',
			'paragraph_html',
			'is_video',
			'is_image',
			'created',
			'updated'
		];

		// Only where the migration that adds it has actually run. Listing a column the table does not
		// have fails the whole insert, so the links would stop being recorded at all.
		if ( Models\Link::hasEmbedColumn() ) {
			array_splice( $columns, array_search( 'is_image', $columns, true ) + 1, 0, [ 'is_embed' ] );
		}
		$currentDate = gmdate( 'Y-m-d H:i:s' );

		$urls     = [];
		$prepared = [];
		foreach ( $links as $linkData ) {
			$data = Models\Link::sanitizeLink( $linkData );
			if ( empty( $data ) ) {
				continue;
			}

			if ( ! Models\Link::validateLink( $data ) ) {
				continue;
			}

			// Excluded here rather than filtered out of the report later: a URL nobody wants followed has
			// no reason to be stored, and leaving it out is what keeps it out of the queue and the counts.
			if ( aioseoBrokenLinkChecker()->helpers->isUrlExcluded( $data['url'] ) ) {
				continue;
			}

			$urls[ $data['url_hash'] ] = [
				'url'      => $data['url'],
				'hostname' => $data['hostname']
			];
			$prepared[]                = $data;
		}

		if ( empty( $prepared ) ) {
			return;
		}

		$idByHash = $this->linkStatusIds( $urls );

		$rows = [];
		foreach ( $prepared as $data ) {
			$hash = $data['url_hash'];

			// Filled before the row is built rather than corrected afterwards, so the links go in already
			// pointing at their status and no second write is needed to join them up.
			$data['blc_link_status_id'] = isset( $idByHash[ $hash ] ) ? $idByHash[ $hash ] : null;

			// Read by column rather than by position: an extractor that does not set one of the flags
			// would otherwise shift every value after it into the wrong column.
			$row = [];
			foreach ( $columns as $column ) {
				if ( 'created' === $column || 'updated' === $column ) {
					$row[] = $currentDate;

					continue;
				}

				$row[] = isset( $data[ $column ] ) ? $data[ $column ] : '';
			}

			$rows[] = $row;
		}

		aioseoBrokenLinkChecker()->core->db->bulkInsert( 'aioseo_blc_links', $columns, $rows );
	}

	/**
	 * Returns the status ID for each of the given URLs, creating the ones that have none.
	 *
	 * NOTE: One query for a whole object's links, where this used to be a query each while the content was
	 * being read. A link-heavy page — a builder layout, a roundup — cost one lookup per link and then had
	 * the same answer worked out again in bulk when the rows were stored.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $urls The URL and hostname of each link, keyed by the URL's hash.
	 * @return array       The status ID for each hash it could resolve.
	 */
	private function linkStatusIds( $urls ) {
		$idByHash = [];
		$existing = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->select( 'id, url_hash' )
			->whereIn( 'url_hash', array_keys( $urls ) )
			->run()
			->result();

		foreach ( $existing as $row ) {
			$idByHash[ $row->url_hash ] = (int) $row->id;
		}

		$missing = array_diff_key( $urls, $idByHash );
		if ( empty( $missing ) ) {
			return $idByHash;
		}

		// A row whose hash no longer derives from its own URL is invisible to the lookup above, so
		// inserting would give the URL a second status row - and the report would then count and list it
		// under two tabs at once. Matching on the URL itself is what finds the row we already have.
		$idByHash = array_merge( $idByHash, $this->adoptByUrl( $missing ) );

		$missing = array_diff_key( $urls, $idByHash );
		if ( empty( $missing ) ) {
			return $idByHash;
		}

		$this->insertLinkStatuses( $missing );

		// resetCache() because when every URL was missing this query is byte-identical to the one above,
		// which the query cache holds as an empty result — and bulkInsert() does not bust that cache. The
		// rows would come back linked to nothing, which is the shape it had before this was noticed.
		$created = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->select( 'id, url_hash' )
			->whereIn( 'url_hash', array_keys( $missing ) )
			->resetCache()
			->run()
			->result();

		foreach ( $created as $row ) {
			$idByHash[ $row->url_hash ] = (int) $row->id;
		}

		return $idByHash;
	}

	/**
	 * Stores a status row for each URL that has none yet.
	 *
	 * NOTE: A URL whose host can never resolve is stored with its verdict already on it. There is nothing
	 * to ask anybody about a name the standard leaves no room for, so it is reported broken here, with a
	 * reason of its own so the reader gets an answer rather than a blank that never fills in. It is not
	 * queued for a local retry either - fetching it from the site cannot settle what DNS will not answer.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $missing The URL and hostname of each link with no status row, keyed by the URL's hash.
	 * @return void
	 */
	private function insertLinkStatuses( $missing ) {
		$now        = aioseoBrokenLinkChecker()->helpers->timeToMysql( time() );
		$hasColumns = Models\LinkStatus::hasCheckUrlColumns();
		$columns    = $hasColumns
			? [ 'url', 'url_hash', 'check_url', 'check_url_hash', 'created', 'updated' ]
			: [ 'url', 'url_hash', 'created', 'updated' ];

		$rows        = [];
		$settledRows = [];
		foreach ( $missing as $hash => $link ) {
			$checkUrl = Models\LinkStatus::deriveCheckUrl( $link['url'] );
			$row      = $hasColumns
				? [ $link['url'], $hash, $checkUrl, sha1( $checkUrl ), $now, $now ]
				: [ $link['url'], $hash, $now, $now ];

			if ( Url::hasUnresolvableHost( $link['url'] ) ) {
				$settledRows[] = array_merge( $row, [
					1,
					$now,
					[
						'error'   => '',
						'headers' => '',
						'reason'  => 'invalid-host'
					],
					0
				] );

				continue;
			}

			$rows[] = $row;
		}

		// IGNORE because `url_hash` is unique: two scans reaching the same new URL at the same moment
		// cannot store it twice, which a read followed by a write could not promise. A row that is already
		// there is left as it is, verdict included.
		aioseoBrokenLinkChecker()->core->db->bulkInsert( 'aioseo_blc_link_status', $columns, $rows, [ 'ignore' => true ] );

		if ( empty( $settledRows ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->core->db->bulkInsert(
			'aioseo_blc_link_status',
			array_merge( $columns, [ 'broken', 'last_scan_date', 'log', 'needs_additional_scan' ] ),
			$settledRows,
			[ 'ignore' => true ]
		);

		// These arrive with a verdict on them, so the report's tabs are wrong the moment they land.
		Models\LinkStatus::flushCounts();
	}

	/**
	 * Returns the status ID of the rows that hold these URLs under a hash that no longer derives from them.
	 *
	 * The hash is written from the URL as normalization leaves it, so a change to that normalization
	 * leaves older rows carrying a hash their own URL no longer produces. Such a row can only be found by
	 * its URL, and its hash is repaired here so the next scan finds it the cheap way.
	 *
	 * NOTE: One query for the whole batch. Asking per URL would undo what {@see self::linkStatusIds()}
	 * exists for, and it would pay that cost on every genuinely new URL, not just the rare stale one.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $missing The URL and hostname of each unresolved link, keyed by the URL's hash.
	 * @return array          The status ID for each hash it resolved.
	 */
	private function adoptByUrl( $missing ) {
		$urls      = [];
		$hostnames = [];
		foreach ( $missing as $link ) {
			$urls[]      = $link['url'];
			$hostnames[] = $link['hostname'];
		}

		$hostnames = array_values( array_filter( array_unique( $hostnames ) ) );
		if ( empty( $hostnames ) ) {
			return [];
		}

		// Narrowed by hostname because it is the only indexed column that can be: the URL is a text
		// column with no index of its own, so comparing it across the whole table is what this avoids.
		$rows = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status as abls' )
			->select( 'abls.id, abls.url' )
			->join( 'aioseo_blc_links as abl', 'abls.id = abl.blc_link_status_id' )
			->whereIn( 'abl.hostname', $hostnames )
			->whereIn( 'abls.url', array_values( array_unique( $urls ) ) )
			->groupBy( 'abls.id, abls.url' )
			->run()
			->result();

		if ( empty( $rows ) ) {
			return [];
		}

		$idByUrl = [];
		foreach ( $rows as $row ) {
			// More than one row for a URL is the corruption this exists to stop spreading. The oldest is
			// the one every link already points at, so it is the one to keep.
			if ( ! isset( $idByUrl[ $row->url ] ) || (int) $row->id < $idByUrl[ $row->url ] ) {
				$idByUrl[ $row->url ] = (int) $row->id;
			}
		}

		$adopted = [];
		foreach ( $missing as $hash => $link ) {
			if ( ! isset( $idByUrl[ $link['url'] ] ) ) {
				continue;
			}

			$adopted[ $hash ] = $idByUrl[ $link['url'] ];
		}

		$this->repairUrlHashes( $adopted );

		return $adopted;
	}

	/**
	 * Writes the derived hash onto the rows that were found by their URL instead.
	 *
	 * NOTE: IGNORE because the hash is unique: a scan running alongside this one may have inserted the
	 * row that owns the hash already, and leaving this row as it is only means it is found by its URL
	 * again rather than losing anything.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $idByHash The status ID for each hash to write.
	 * @return void
	 */
	private function repairUrlHashes( $idByHash ) {
		if ( empty( $idByHash ) ) {
			return;
		}

		$db        = aioseoBrokenLinkChecker()->core->db;
		$tableName = $db->prefix . 'aioseo_blc_link_status';

		foreach ( $idByHash as $hash => $id ) {
			$db->execute(
				$db->db->prepare(
					"UPDATE IGNORE {$tableName} SET url_hash = %s WHERE id = %d",
					$hash,
					$id
				)
			);
		}
	}

	/**
	 * Returns the links that are in the post content.
	 *
	 * Uses WP_HTML_Tag_Processor (WP 6.6+) for reliable HTML parsing that handles
	 * all quote styles, attribute orders and edge cases. Falls back to regex on older WP versions.
	 *
	 * NOTE: Entities are decoded per value rather than over the whole document. Decoding first turned
	 * an escaped `&lt;a href="..."&gt;` in a code sample into real markup and indexed a link nobody can
	 * click, and it decoded an href twice - once here and once in `get_attribute()` - so `&amp;amp;`
	 * came to rest as `&`.
	 *
	 * @since   1.0.0
	 * @since   1.3.0 Rewritten to use WP_HTML_Tag_Processor with regex fallback.
	 * @version 1.3.1 Dropped the $postId parameter; the object is read from the scan context.
	 * @version 1.3.1 Added the $findBareUrls parameter.
	 * @version 1.3.1 Decodes entities per value instead of over the whole document.
	 *
	 * @param  string $postContent  The content.
	 * @param  bool   $findBareUrls Whether a URL written as plain text counts as a link.
	 * @return array                The links.
	 */
	private function extractLinks( $postContent, $findBareUrls = false ) {
		// Strip data URIs to prevent catastrophic backtracking. The payload has to stop at either quote:
		// running to the next '"' left a single-quoted attribute open and cost every link on the post.
		$postContent = preg_replace( '/data:[^;\'"\s]+;base64,[^\'"\s)]+/', '', (string) $postContent ) ?? $postContent;

		// WP 6.6+: next_token() (6.5) and the bookmark length fix (6.6, Trac #61301) are available.
		if ( version_compare( get_bloginfo( 'version' ), '6.6', '>=' ) ) {
			$links = $this->extractLinksHtmlApi( $postContent );

			// Non-null means the HTML API path completed (even if zero links found).
			// Null means bookmark internals failed and we should fall back to regex.
			if ( null !== $links ) {
				// The HTML API path only walks <a> tags, so embed blocks and media are extracted separately.
				$other = array_merge(
					$this->extractEmbeddedUrls( $postContent ),
					$this->extractImages( $postContent ),
					$this->extractVideos( $postContent )
				);

				$links = array_merge( $links, $other );

				// Last, because they drop whatever the passes above already found.
				$links = array_merge( $links, $this->extractBlockAttributeUrls( $postContent, $links ) );

				// Deliberately not $links: an anchored occurrence must not hide a separate plain-text one
				// of the same URL. The anchors themselves are cut from the text the bare pass searches.
				return $findBareUrls
					? array_merge( $links, $this->extractBareUrls( $postContent, $other ) )
					: $links;
			}
		}

		$other = array_merge(
			$this->extractImages( $postContent ),
			$this->extractVideos( $postContent )
		);

		$links = array_merge( $this->extractLinksRegex( $postContent ), $other );

		return $findBareUrls
			? array_merge( $links, $this->extractBareUrls( $postContent, $other ) )
			: $links;
	}

	/**
	 * Returns the links for URLs written as plain text rather than wrapped in a tag.
	 *
	 * Only the kinds that opt in reach here - see {@see \AIOSEO\BrokenLinkChecker\Objects\ObjectType::findsBareUrls()}.
	 *
	 * NOTE: Tags are stripped before the search, so an attribute URL cannot be picked up here. The passes
	 * that read attributes have already run, and one they skipped was skipped on purpose.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $postContent The content.
	 * @param  array  $found       The links whose URL can also appear as text, e.g. an embed's.
	 * @return array               The links.
	 */
	private function extractBareUrls( $postContent, $found ) {
		// Whole anchors go first: stripping tags alone would leave <a href="x">x</a> looking like a bare
		// URL, and deduping that by URL instead cost a genuine plain-text occurrence of an anchored URL.
		$withoutAnchors = preg_replace( '#<a\b[^>]*>.*?</a>#is', '', (string) $postContent );
		$text           = wp_strip_all_tags( null === $withoutAnchors ? (string) $postContent : $withoutAnchors );

		// Escaped markup in a code sample reads as text by here, and a URL inside an attribute is not one
		// the reader can click. Decoding before the second strip removes it the way a real tag was removed.
		$text = wp_strip_all_tags( $this->decodeValue( $text ) );

		if ( false === stripos( $text, 'http' ) ) {
			return [];
		}

		$seen = [];
		foreach ( $found as $link ) {
			if ( isset( $link['url'] ) ) {
				$seen[ $link['url'] ] = true;
			}
		}

		preg_match_all( '#\bhttps?://[^\s<>"\'\]]+#i', $text, $matches );
		if ( empty( $matches[0] ) ) {
			return [];
		}

		$links = [];
		foreach ( array_unique( $matches[0] ) as $capturedUrl ) {
			// Prose punctuation sits against the URL, e.g. "see https://example.com/page." or "(https://x.com)".
			$capturedUrl = rtrim( $capturedUrl, '.,;:!?' );
			if ( substr_count( $capturedUrl, ')' ) > substr_count( $capturedUrl, '(' ) ) {
				$capturedUrl = rtrim( $capturedUrl, ')' );
			}

			$parsedUrl = $this->parseUrl( $capturedUrl );
			if ( empty( $parsedUrl['host'] ) ) {
				continue;
			}

			if ( $this->hasIgnoredExtension( $parsedUrl ) ) {
				continue;
			}

			$urlFields = $this->urlFields( $parsedUrl );
			if ( isset( $seen[ $urlFields['url'] ] ) ) {
				continue;
			}

			$seen[ $urlFields['url'] ] = true;

			// A rich text row must carry its context or it is refused, and these fields are only ever
			// empty for a value short enough to hold nothing but the address - which is its own context.
			$paragraph     = aioseoBrokenLinkChecker()->main->paragraph->get( $this->contextKey(), $postContent, $capturedUrl );
			$paragraphHtml = aioseoBrokenLinkChecker()->main->paragraph->getHtml( $capturedUrl, $paragraph, $postContent );

			$links[] = array_merge( $urlFields, [
				// The address is what the reader sees, so it is also the anchor the report shows.
				'anchor'         => $capturedUrl,
				'phrase'         => $capturedUrl,
				'phrase_html'    => $capturedUrl,
				'paragraph'      => $paragraph ? $this->decodeValue( $paragraph ) : $capturedUrl,
				'paragraph_html' => $paragraphHtml ? $paragraphHtml : $capturedUrl,
				'is_video'       => $this->isVideoUrl( $urlFields['url'] ),
				'is_image'       => false,
				'is_embed'       => false
			] );
		}

		return $links;
	}

	/**
	 * Extracts links from post content using BlcHtmlTagProcessor with bookmarks.
	 *
	 * Single-pass approach: iterates <a> tags, uses bookmarks to get byte offsets
	 * for the opener and closer, then extracts anchor text and surrounding sentence
	 * context directly from the original content string. No markers, no regex for
	 * link identification, no content mutation.
	 *
	 * Returns null if the WP_HTML_Tag_Processor bookmark internals have changed,
	 * signalling the caller to fall back to regex-based extraction.
	 *
	 * Requires WP 6.6+ — the caller gates on this version.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Dropped the $postId parameter; the object is read from the scan context.
	 *
	 * @param  string $postContent The preprocessed content.
	 * @return array|null          The links, or null if bookmark internals failed.
	 */
	private function extractLinksHtmlApi( $postContent ) {
		$processor     = new BlcHtmlTagProcessor( $postContent );
		$links         = [];
		$prevCloserEnd = 0;
		$seekTo        = null;

		while ( true ) {
			if ( $seekTo ) {
				$processor->seek( $seekTo );
				$processor->release_bookmark( $seekTo );
				$seekTo = null;
			} elseif ( ! $processor->next_tag( 'a' ) ) {
				break;
			}

			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) || '' === $href || '#' === $href[0] || aioseoBrokenLinkChecker()->helpers->hasUnscannableScheme( $href ) ) {
				// Advance past the closing </a> so $prevCloserEnd stays accurate.
				// Without this, the next link's phrase boundary search would scan
				// through this filtered link's href attributes (e.g. the "." in "tel:+1.555.1234").
				$prevCloserEnd = $this->skipPastCloser( $processor, $prevCloserEnd );

				continue;
			}

			// Bookmark the opening <a> tag to get its byte offsets.
			$openerBookmark = 'blc_opener';
			$processor->set_bookmark( $openerBookmark );
			$openerStart  = $processor->getBookmarkStart( $openerBookmark );
			$openerLength = $processor->getBookmarkLength( $openerBookmark );
			$processor->release_bookmark( $openerBookmark );

			// If bookmark was set but getters return null, WP core internals have changed.
			if ( null === $openerStart || null === $openerLength ) {
				return null;
			}

			$openerEnd = $openerStart + $openerLength;

			// Advance through tokens until we find the closing </a> tag.
			$closerStart = null;
			$closerEnd   = null;
			while ( $processor->next_token() ) {
				$tokenType = $processor->get_token_type();

				if ( '#tag' !== $tokenType || 'A' !== $processor->get_tag() ) {
					continue;
				}

				// Nested opener (malformed HTML) — bookmark for reprocessing, stop walking.
				if ( ! $processor->is_tag_closer() ) {
					if ( $processor->set_bookmark( 'blc_nested' ) ) {
						$seekTo = 'blc_nested';
					}
					break;
				}

				$processor->set_bookmark( 'blc_closer' );
				$closerStart  = $processor->getBookmarkStart( 'blc_closer' );
				$closerLength = $processor->getBookmarkLength( 'blc_closer' );
				$processor->release_bookmark( 'blc_closer' );

				// If bookmark was set but getters return null, WP core internals have changed.
				if ( null === $closerStart || null === $closerLength ) {
					return null;
				}

				$closerEnd = $closerStart + $closerLength;

				break;
			}

			// Skip malformed HTML with no closing </a>.
			if ( null === $closerEnd ) {
				continue;
			}

			// Extract the inner HTML and anchor text.
			$innerHtml = substr( $postContent, $openerEnd, $closerStart - $openerEnd );
			$anchor    = wp_strip_all_tags( $innerHtml );

			// Save this link's closer position before any early-continue filters below.
			// This ensures the next iteration's phrase boundary search won't scan
			// through this link's href attributes (which contain . ? ! characters).
			$phraseSearchFrom = $prevCloserEnd;
			$prevCloserEnd    = $closerEnd;

			$parsedUrl = $this->parseUrl( $href );
			if ( empty( $parsedUrl['host'] ) ) {
				continue;
			}

			if ( $this->hasIgnoredExtension( $parsedUrl ) ) {
				continue;
			}

			$urlFields = $this->urlFields( $parsedUrl );
			$isVideo   = $this->isVideoUrl( $urlFields['url'] );

			$phraseHtml = $this->extractPhraseHtml( $postContent, $phraseSearchFrom, $openerStart, $closerEnd );

			$phrase = wp_strip_all_tags( $phraseHtml );
			$phrase = trim( $phrase );

			// An <a> around an image or other markup has no anchor text and often no sentence around it,
			// but it is a link the reader can click and the scan can report. Only a genuinely empty
			// <a></a> is nothing.
			$wrapsMarkup = '' === $anchor && '' !== trim( $innerHtml );

			// Don't continue if the anchor or phrase are empty, e.g. blank link tag.
			// If it's a video, we don't mandate an anchor or phrase.
			if ( ( ! $anchor || ! $phrase ) && ! $isVideo && ! $wrapsMarkup ) {
				continue;
			}

			// Only asked for where there is a phrase to find. The paragraph service walks the content per
			// context key, and a link with no sentence around it - an <a> holding only an image - moved that
			// walk on without matching anything, which emptied the paragraph of every link after it.
			$paragraph     = '' !== $phrase
				? aioseoBrokenLinkChecker()->main->paragraph->get( $this->contextKey(), $postContent, $phrase )
				: '';
			$paragraphHtml = '' !== $phrase
				? aioseoBrokenLinkChecker()->main->paragraph->getHtml( $anchor, $paragraph, $postContent )
				: '';

			// The text fields carry what the reader sees, so their entities are decoded - but only here,
			// after the lookups above have matched them against the content as it is stored.
			$links[] = array_merge( $urlFields, [
				'anchor'         => $this->decodeValue( $anchor ),
				'phrase'         => $this->decodeValue( $phrase ),
				'phrase_html'    => $phraseHtml,
				'paragraph'      => $this->decodeValue( $paragraph ),
				'paragraph_html' => $paragraphHtml,
				'is_video'       => $isVideo,
				'is_image'       => $this->isImageFileUrl( $urlFields['url'] ),
				'is_embed'       => false
			] );
		}

		return $links;
	}

	/**
	 * Decodes the HTML entities in a value the report shows as text.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $value The value as it appears in the content.
	 * @return string        The decoded value.
	 */
	private function decodeValue( $value ) {
		return aioseoBrokenLinkChecker()->helpers->decodeHtmlEntities( $value );
	}

	/**
	 * Regex-based link extraction for WP < 6.6.
	 *
	 * @since   1.0.0
	 * @since   1.3.0 Renamed from extractLinks().
	 * @version 1.3.1 Dropped the $postId parameter; the object is read from the scan context.
	 *
	 * @param  string $postContent The preprocessed content.
	 * @return array               The links.
	 */
	private function extractLinksRegex( $postContent ) {
		/**
		 * Regex pattern divided into groups:
		 * 0  - Full phrase with link tag.
		 * 2  - Start of the phrase, before the anchor.
		 * 4  - The URL.
		 * 6  - The anchor.
		 * 7  - The oEmbed URL.
		 * 9  - The end of the phrase, after the anchor.
		 * 10 - The ending punctuation mark.
		 */
		preg_match_all(
			'/(([^\r\n.?!]*)<t?a[^>]*?href=(\"|\')(?!tel:|mailto:)([^\"\']*?)(\"|\')[^>]*?>([\s\w\W]*?)<\/t?a>|<!-- wp:(?:core-embed\/wordpress|embed) [^{]*{"url":"([^"]*?)"[^}]*} -->|(?:>|&nbsp;|\s)((?:(?:http|ftp|https)\:\/\/)(?:[\w_-]+(?:(?:\.[\w_-]+)+))(?:[\w.,@?^=%&:\/~+#-]*[\w@?^=%&\/~+#-]))(?:<|&nbsp;|\s))([^<>.?!\r\n]*)([.?!]?)/i', // phpcs:disable Generic.Files.LineLength.MaxExceeded
			(string) $postContent,
			$matches
		);

		if ( empty( $matches[0] ) ) {
			return [];
		}

		$links = [];
		foreach ( $matches[0] as $k => $v ) {

			if (
				( empty( $matches[4][ $k ] ) || empty( $matches[6][ $k ] ) ) && // Link tag URL or anchor
				empty( $matches[7][ $k ] ) // oEmbedded URL
			) {
				continue;
			}

			// The attribute is read out of the markup rather than by a parser, so its entities are still
			// encoded. A block comment's URL is JSON and holds none.
			$oEmbed      = ! empty( $matches[7][ $k ] ) ? true : false;
			$capturedUrl = $matches[4][ $k ]
				? $this->decodeValue( $matches[4][ $k ] )
				: $matches[7][ $k ];
			$parsedUrl   = $this->parseUrl( $capturedUrl );
			if ( empty( $parsedUrl['host'] ) ) {
				continue;
			}

			if ( $this->hasIgnoredExtension( $parsedUrl ) ) {
				continue;
			}

			$urlFields = $this->urlFields( $parsedUrl );
			$isVideo   = $this->isVideoUrl( $urlFields['url'] );

			$anchor = wp_strip_all_tags( $matches[6][ $k ] );
			// Remove trailing URL tags. The regex isn't sufficient for this.
			$phrase = wp_strip_all_tags( $matches[0][ $k ] );
			$phrase = trim( preg_replace( '/(.*)(<t?a[^<>].*$)/', '', (string) $phrase ) ?? $phrase );

			// As above: an <a> around an image carries no anchor text, and dropping it left the link it
			// points at unchecked.
			$wrapsMarkup = '' === $anchor && '' !== trim( (string) $matches[6][ $k ] );

			// Don't continue if the anchor or phrase are empty, e.g. blank link tag.
			// If it's a video, we don't mandate an anchor or phrase.
			if (
				( ! $anchor || ! $phrase ) &&
				! $isVideo &&
				! $wrapsMarkup
			) {
				continue;
			}

			$phraseHtml = aioseoBrokenLinkChecker()->helpers->stripIncompleteHtmlTags( $matches[0][ $k ] );
			$phraseHtml = aioseoBrokenLinkChecker()->helpers->stripScriptTags( $phraseHtml );
			$phraseHtml = aioseoBrokenLinkChecker()->helpers->trimParagraphTags( $phraseHtml );

			// oEmbed blocks reduce to an empty phrase once the block comment is stripped, so don't skip videos here.
			if ( empty( $phraseHtml ) && ! $isVideo ) {
				continue;
			}

			$paragraph     = '';
			$paragraphHtml = '';
			if ( ! $oEmbed ) {
				// As above: no phrase, no paragraph walk.
				$paragraph     = '' !== $phrase
					? aioseoBrokenLinkChecker()->main->paragraph->get( $this->contextKey(), $postContent, $phrase )
					: '';
				$paragraphHtml = '' !== $phrase
					? aioseoBrokenLinkChecker()->main->paragraph->getHtml( $anchor, $paragraph, $postContent )
					: '';
			}

			$links[] = array_merge( $urlFields, [
				'anchor'         => $this->decodeValue( $anchor ),
				'phrase'         => $this->decodeValue( $phrase ),
				'phrase_html'    => $phraseHtml,
				'paragraph'      => $this->decodeValue( $paragraph ),
				'paragraph_html' => $paragraphHtml,
				'is_video'       => $isVideo,
				'is_image'       => $this->isImageFileUrl( $urlFields['url'] ),
				'is_embed'       => false
			] );
		}

		return $links;
	}

	/**
	 * Checks whether the given URL points to a video based on known provider patterns.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $url The URL to check.
	 * @return bool        Whether the URL is a video.
	 */
	private function isVideoUrl( $url ) {
		if ( $this->isVideoFileUrl( $url ) ) {
			return true;
		}

		$videoPatterns = [
			'/https?:\/\/((m|www)\.)?youtube\.com\/watch.*/i',
			'/https?:\/\/((m|www)\.)?youtube\.com\/playlist.*/i',
			'/https?:\/\/((m|www)\.)?youtube\.com\/shorts\/.*/i',
			'/https?:\/\/((m|www)\.)?youtube\.com\/live\/.*/i',
			// What an <iframe> carries, including the privacy-enhanced host the embed option offers.
			'/https?:\/\/((m|www)\.)?youtube\.com\/embed\/.*/i',
			'/https?:\/\/((m|www)\.)?youtube-nocookie\.com\/embed\/.*/i',
			'/https?:\/\/music\.youtube\.com\/watch.*/i',
			'/https?:\/\/youtu\.be\/.*/i',
			'/https?:\/\/(.+)?(wistia\.com|wi\.st)\/(medias|embed)\/.*/i',
			'/https?:\/\/(www\.)?vimeo\.com\/\d+/i',
			'/https?:\/\/(www\.)?vimeo\.com\/(video|channels\/.+|album\/.+\/video)\/\d+/i',
			'/https?:\/\/(www\.)?vimeo\.com\/(ondemand|showcase)\/.+/i',
			'/https?:\/\/player\.vimeo\.com\/video\/\d+/i',
			'/https?:\/\/(www\.)?dailymotion\.com\/(video|embed\/video)\/.*/i',
			'/https?:\/\/dai\.ly\/.*/i',
			'/https?:\/\/wordpress\.tv\/.*/i',
			'/https?:\/\/videopress\.com\/v\/.*/i'
		];

		foreach ( $videoPatterns as $pattern ) {
			if ( preg_match( $pattern, (string) $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the URL points at an audio file.
	 *
	 * Kept apart from the video check because an audio file is not a video, and the report says which a
	 * URL is. There is no audio flag, so a podcast episode reads as an ordinary link.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL.
	 * @return bool        Whether it is an audio file.
	 */
	private function isAudioFileUrl( $url ) {
		$path      = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, [ 'mp3', 'm4a', 'aac', 'wav', 'flac', 'oga', 'opus', 'wma' ], true );
	}

	/**
	 * Whether the URL points at a video file.
	 *
	 * Only the container formats a browser plays, and only by extension. A platform URL is matched by
	 * pattern instead. {@see self::isVideoUrl()}.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL.
	 * @return bool        Whether it is a video file.
	 */
	private function isVideoFileUrl( $url ) {
		$path      = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, [ 'mp4', 'm4v', 'webm', 'ogv', 'mov', 'avi', 'mkv', 'flv', 'wmv', '3gp' ], true );
	}

	/**
	 * Whether the given URL points at an image file.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL.
	 * @return bool        Whether it points at an image.
	 */
	private function isImageFileUrl( $url ) {
		$path      = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'ico', 'tiff', 'tif' ], true );
	}

	/**
	 * Extracts the videos the content plays.
	 *
	 * A self-hosted video has no oEmbed endpoint behind it, so nothing checks whether it still exists on
	 * a platform — it is checked like any other URL, and flagged so the report can be filtered by it.
	 *
	 * NOTE: A `<source>` is kept only when its URL is a video file, which is what tells one apart from
	 * the `<source>` tags inside a `<picture>` or an `<audio>`.
	 *
	 * NOTE: An `<iframe>` is here because a video pasted as HTML, or written by a page builder, renders
	 * as one - and the block editor's own embed is already covered by the URL in its block comment.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Reads an `<iframe>` as the embed it renders.
	 *
	 * @param  string $postContent The preprocessed content.
	 * @return array               The links.
	 */
	private function extractVideos( $postContent ) {
		$postContent = (string) $postContent;
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return [];
		}

		if (
			false === stripos( $postContent, '<video' ) &&
			false === stripos( $postContent, '<audio' ) &&
			false === stripos( $postContent, '<source' ) &&
			false === stripos( $postContent, '<iframe' )
		) {
			return [];
		}

		$processor = new \WP_HTML_Tag_Processor( $postContent );
		$links     = [];

		while ( $processor->next_token() ) {
			if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
				continue;
			}

			$tag = $processor->get_tag();
			if ( ! in_array( $tag, [ 'VIDEO', 'AUDIO', 'SOURCE', 'IFRAME' ], true ) ) {
				continue;
			}

			$src = $processor->get_attribute( 'src' );
			if ( ! is_string( $src ) || '' === $src || '#' === $src[0] || aioseoBrokenLinkChecker()->helpers->hasUnscannableScheme( $src ) ) {
				continue;
			}

			// A <video> or an <audio> names its own file. A <source> only counts when the file says what
			// it is, which is what tells one apart from the <source> tags inside a <picture>.
			if ( 'SOURCE' === $tag && ! $this->isVideoFileUrl( $src ) && ! $this->isAudioFileUrl( $src ) ) {
				continue;
			}

			$parsedUrl = $this->parseUrl( $src );
			if ( empty( $parsedUrl['host'] ) ) {
				continue;
			}

			if ( $this->hasIgnoredExtension( $parsedUrl ) ) {
				continue;
			}

			$urlFields = $this->urlFields( $parsedUrl );

			$links[] = array_merge( $urlFields, [
				'anchor'         => '',
				'phrase'         => '',
				'phrase_html'    => '',
				'paragraph'      => '',
				'paragraph_html' => '',
				// An <audio> is media but it is not a video, and the report says which a URL is.
				'is_video'       => $this->isVideoUrl( $urlFields['url'] ),
				'is_image'       => false,
				// A media tag has no anchor text or sentence around it, whatever kind of media it is —
				// which the video flag used to stand in for, and cannot now that audio is here too.
				'is_embed'       => true
			] );
		}

		return $links;
	}

	/**
	 * Extracts the URLs core blocks keep in their attributes rather than in a tag.
	 *
	 * A cover block's background is the case that matters: the image is named in the block's attributes
	 * and repeated in an inline style, and never appears as an `<img>`, so nothing that walks tags can
	 * see it. Group and column backgrounds are the same shape, nested under `style.background`.
	 *
	 * NOTE: Whatever is already found as a tag is dropped here, because most core blocks name their
	 * media in the attributes *and* render it — an image block holds the same URL in both places, and
	 * counting it twice would report one picture as two.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $postContent The preprocessed content.
	 * @param  array  $found       The links the tag passes already produced.
	 * @return array               The links.
	 */
	private function extractBlockAttributeUrls( $postContent, $found ) {
		$postContent = (string) $postContent;
		if ( false === strpos( $postContent, '<!-- wp:' ) || ! function_exists( 'parse_blocks' ) ) {
			return [];
		}

		$seen = [];
		foreach ( $found as $link ) {
			if ( isset( $link['url'] ) ) {
				$seen[ $link['url'] ] = true;
			}
		}

		$urls = [];
		$this->collectBlockUrls( parse_blocks( $postContent ), $urls );

		$links = [];
		foreach ( array_unique( $urls ) as $url ) {
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}

			$parsedUrl = $this->parseUrl( $url );
			if ( empty( $parsedUrl['host'] ) ) {
				continue;
			}

			if ( $this->hasIgnoredExtension( $parsedUrl ) ) {
				continue;
			}

			$urlFields = $this->urlFields( $parsedUrl );
			$seen[ $urlFields['url'] ] = true;

			$links[] = array_merge( $urlFields, [
				'anchor'         => '',
				'phrase'         => '',
				'phrase_html'    => '',
				'paragraph'      => '',
				'paragraph_html' => '',
				'is_video'       => $this->isVideoUrl( $urlFields['url'] ),
				// A background is a picture, so the report files it with the images.
				'is_image'       => ! $this->isVideoUrl( $urlFields['url'] ),
				'is_embed'       => true
			] );
		}

		return $links;
	}

	/**
	 * Walks a block tree, collecting the addresses its attributes name.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $blocks The parsed blocks.
	 * @param  array $urls   The URLs found so far, appended to.
	 * @return void
	 */
	private function collectBlockUrls( $blocks, &$urls ) {
		$builderPrefixes = $this->builderBlockPrefixes();

		foreach ( (array) $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			// A builder that keeps its layout in blocks reads these attributes itself, and names the row
			// after its own page. Reading them here as well reported one picture as two locations.
			if ( ! empty( $block['blockName'] ) && $this->ownedByBuilder( (string) $block['blockName'], $builderPrefixes ) ) {
				continue;
			}

			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$this->collectUrlsFromAttrs( $block['attrs'], $urls );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->collectBlockUrls( $block['innerBlocks'], $urls );
			}
		}
	}

	/**
	 * The block prefixes belonging to builder sources that are switched on.
	 *
	 * @since 1.3.1
	 *
	 * @return string[] The prefixes.
	 */
	private function builderBlockPrefixes() {
		$prefixes = [];
		foreach ( aioseoBrokenLinkChecker()->objects->enabled() as $type ) {
			$prefix = (string) $type->blockPrefix();
			if ( '' !== $prefix ) {
				$prefixes[] = $prefix;
			}
		}

		return $prefixes;
	}

	/**
	 * Whether a block belongs to a builder that reads its own attributes.
	 *
	 * @since 1.3.1
	 *
	 * @param  string   $blockName The block name.
	 * @param  string[] $prefixes  The builder prefixes.
	 * @return bool                Whether to leave it alone.
	 */
	private function ownedByBuilder( $blockName, $prefixes ) {
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $blockName, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collects the addresses one block's attributes name, however deeply they nest.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $attrs The block attributes.
	 * @param  array $urls  The URLs found so far, appended to.
	 * @return void
	 */
	private function collectUrlsFromAttrs( $attrs, &$urls ) {
		foreach ( $attrs as $key => $value ) {
			if ( is_array( $value ) ) {
				$this->collectUrlsFromAttrs( $value, $urls );

				continue;
			}

			if ( ! is_string( $value ) || ! preg_match( '#^https?://#i', trim( $value ) ) ) {
				continue;
			}

			// Named for what it points at, so a stray absolute URL in an unrelated attribute is left out.
			if ( ! preg_match( '/(^|_)(url|src|href)$/i', (string) $key ) && ! in_array( (string) $key, [ 'mediaUrl', 'backgroundImage', 'imageUrl' ], true ) ) {
				continue;
			}

			$urls[] = trim( $value );
		}
	}

	/**
	 * Extracts the images the content displays.
	 *
	 * An image is not a link, so it has no anchor text and no surrounding sentence — the alt text is what
	 * the report can name it by. {@see \AIOSEO\BrokenLinkChecker\Models\Link::optionalFields()} lets
	 * those fields be empty for a row marked this way.
	 *
	 * NOTE: `srcset` is deliberately left alone. One attribute holds several URLs with descriptors, and a
	 * row per candidate would report the same picture many times over.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $postContent The preprocessed content.
	 * @return array               The links.
	 */
	private function extractImages( $postContent ) {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || false === stripos( (string) $postContent, '<img' ) ) {
			return [];
		}

		$processor = new \WP_HTML_Tag_Processor( (string) $postContent );
		$links     = [];

		while ( $processor->next_tag( 'img' ) ) {
			$src = $processor->get_attribute( 'src' );
			if ( ! is_string( $src ) || '' === $src || '#' === $src[0] || aioseoBrokenLinkChecker()->helpers->hasUnscannableScheme( $src ) ) {
				continue;
			}

			$parsedUrl = $this->parseUrl( $src );
			if ( empty( $parsedUrl['host'] ) ) {
				continue;
			}

			if ( $this->hasIgnoredExtension( $parsedUrl ) ) {
				continue;
			}

			$alt = $processor->get_attribute( 'alt' );

			$links[] = array_merge( $this->urlFields( $parsedUrl ), [
				'anchor'         => is_string( $alt ) ? wp_strip_all_tags( $alt ) : '',
				'phrase'         => '',
				'phrase_html'    => '',
				'paragraph'      => '',
				'paragraph_html' => '',
				'is_video'       => false,
				'is_image'       => true,
				'is_embed'       => true
			] );
		}

		return $links;
	}

	/**
	 * Extracts the URLs held in Gutenberg embed block comments.
	 *
	 * The HTML API path only walks <a> tags, so embedded videos (which live in
	 * wp:embed block comments, not anchors) must be extracted separately.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Dropped the $postId parameter; the object is read from the scan context.
	 *
	 * @param  string $postContent The preprocessed content.
	 * @return array               The video links.
	 */
	private function extractEmbeddedUrls( $postContent ) {
		preg_match_all(
			'/<!-- wp:(?:core-embed\/\w+|embed) [^{]*{"url":"([^"]+)"[^}]*} -->/i',
			(string) $postContent,
			$matches
		);

		if ( empty( $matches[1] ) ) {
			return [];
		}

		$links = [];
		foreach ( $matches[1] as $capturedUrl ) {
			// Block comment JSON escapes forward slashes, e.g. https:\/\/youtu.be\/x.
			$capturedUrl = stripslashes( $capturedUrl );

			$parsedUrl = $this->parseUrl( $capturedUrl );
			if ( empty( $parsedUrl['host'] ) ) {
				continue;
			}

			$urlFields = $this->urlFields( $parsedUrl );

			if ( $this->hasIgnoredExtension( $parsedUrl ) ) {
				continue;
			}

			$links[] = array_merge( $urlFields, [
				'anchor'         => '',
				'phrase'         => '',
				'phrase_html'    => '',
				'paragraph'      => '',
				'paragraph_html' => '',
				// An embed of something that is not a video is still an embed of something that can break —
				// a track, a post, a map. Only the ones we can name as video are flagged as one.
				'is_video'       => $this->isVideoUrl( $urlFields['url'] ),
				'is_image'       => false,
				'is_embed'       => true
			] );
		}

		return $links;
	}

	/**
	 * Extracts the phrase HTML surrounding a link from the post content.
	 *
	 * Finds sentence boundaries before and after the link using punctuation
	 * delimiters, then strips incomplete HTML tags, script tags and paragraph wrappers.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Never starts the phrase inside a tag or a comment.
	 *
	 * @param  string $postContent     The full post content.
	 * @param  int    $phraseSearchFrom The byte offset to start searching for sentence boundaries.
	 * @param  int    $openerStart     The byte offset of the opening <a> tag.
	 * @param  int    $closerEnd       The byte offset of the end of the closing </a> tag.
	 * @return string                  The phrase HTML.
	 */
	private function extractPhraseHtml( $postContent, $phraseSearchFrom, $openerStart, $closerEnd ) {
		// Find sentence boundary before the link.
		// Only search between the previous link's </a> end and this opener,
		// to avoid finding delimiters inside previous links' href attributes
		// (e.g. the "." in "alpha.com" would produce a garbled phrase).
		$textBefore  = substr( $postContent, $phraseSearchFrom, $openerStart - $phraseSearchFrom );
		$phraseStart = $phraseSearchFrom;
		foreach ( [ '.', '?', '!', "\r", "\n" ] as $delimiter ) {
			$pos = strrpos( $textBefore, $delimiter );
			if ( false !== $pos ) {
				// Start after the delimiter.
				$phraseStart = max( $phraseStart, $phraseSearchFrom + $pos + 1 );
			}
		}

		// A delimiter can sit inside markup rather than in the text - the "!" of a block comment, a "."
		// in an attribute - and a phrase starting mid-tag is one kses then mangles beyond ever matching.
		$untilOpener = substr( $postContent, $phraseStart, $openerStart - $phraseStart );
		$tagCloses   = strpos( $untilOpener, '>' );
		$tagOpens    = strpos( $untilOpener, '<' );
		if ( false !== $tagCloses && ( false === $tagOpens || $tagCloses < $tagOpens ) ) {
			$phraseStart += $tagCloses + 1;
		}

		// Find sentence boundary after the link.
		$textAfter   = substr( $postContent, $closerEnd );
		$boundaryLen = strcspn( $textAfter, '<>.?!' . "\r\n" );
		$phraseEnd   = $closerEnd + $boundaryLen;

		// Include trailing punctuation if present.
		if ( $phraseEnd < strlen( $postContent ) && false !== strpos( '.?!', $postContent[ $phraseEnd ] ) ) {
			$phraseEnd++;
		}

		$phraseHtml = substr( $postContent, $phraseStart, $phraseEnd - $phraseStart );
		$phraseHtml = aioseoBrokenLinkChecker()->helpers->stripIncompleteHtmlTags( $phraseHtml );
		$phraseHtml = aioseoBrokenLinkChecker()->helpers->stripScriptTags( $phraseHtml );
		$phraseHtml = aioseoBrokenLinkChecker()->helpers->trimParagraphTags( $phraseHtml );

		return $phraseHtml;
	}

	/**
	 * Advances the processor past the closing </a> tag for a filtered link.
	 *
	 * Used when a link is skipped (tel:, mailto:, etc.) to keep $prevCloserEnd
	 * accurate so that subsequent phrase boundary searches don't scan through
	 * this link's href attributes.
	 *
	 * @since 1.3.0
	 *
	 * @param  BlcHtmlTagProcessor $processor      The processor, positioned on an <a> opener.
	 * @param  int                 $prevCloserEnd  The current closer-end byte offset.
	 * @return int                                 The updated closer-end byte offset.
	 */
	private function skipPastCloser( $processor, $prevCloserEnd ) {
		while ( $processor->next_token() ) {
			if ( '#tag' !== $processor->get_token_type() || 'A' !== $processor->get_tag() ) {
				continue;
			}

			// Found a closing </a> — bookmark to get its end offset.
			if ( $processor->is_tag_closer() ) {
				$processor->set_bookmark( 'blc_skip_closer' );
				$start  = $processor->getBookmarkStart( 'blc_skip_closer' );
				$length = $processor->getBookmarkLength( 'blc_skip_closer' );
				$processor->release_bookmark( 'blc_skip_closer' );

				if ( null !== $start && null !== $length ) {
					return $start + $length;
				}
			}

			// Nested opener or bookmark failure — stop walking.
			break;
		}

		return $prevCloserEnd;
	}

	/**
	 * Returns the site's hostname, in the form a resolved URL carries it.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Canonicalises the host, so it compares with what resolution produces.
	 *
	 * @return string The hostname.
	 */
	private function getHostname() {
		static $siteHost = null;
		if ( null === $siteHost ) {
			$siteUrl  = get_site_url();
			$parts    = Url::parts( $siteUrl, $siteUrl );
			$siteHost = isset( $parts['host'] ) ? $parts['host'] : '';
		}

		return $siteHost;
	}

	/**
	 * Returns the parsed URL, resolved against the URL of the object it was written on.
	 *
	 * NOTE: Resolution is the browser's {@see \AIOSEO\BrokenLinkChecker\Links\Url::parts()}, which hands
	 * back the parts it computed rather than a string to parse again. Reading its output back with
	 * wp_parse_url() undid it: a non-Latin host lost every 0x80-0x9F byte to an underscore.
	 *
	 * @since   1.0.0
	 * @version 1.1.1 Renamed method.
	 * @version 1.3.0 Added $postId parameter; resolves relative URLs against the post permalink.
	 * @version 1.3.1 Dropped the $postId parameter; the base URL comes from the scan context.
	 * @version 1.3.1 Returns {@see \AIOSEO\BrokenLinkChecker\Links\Url::parts()}, including the URL.
	 *
	 * @param  string $url The URL as it was written.
	 * @return array       The resolved parts, including the URL under `url`.
	 */
	private function parseUrl( $url ) {
		$url = trim( (string) $url );

		// A bare fragment addresses the page it is on, so it is not a link the scan has anything to say
		// about - and every caller that reads an attribute drops it already.
		if ( '' === $url || '#' === $url[0] ) {
			return [];
		}

		$baseUrl = ! empty( $this->context['baseUrl'] ) ? $this->context['baseUrl'] : get_site_url();

		return Url::parts( $url, $baseUrl );
	}

	/**
	 * Returns the posts to scan.
	 *
	 * NOTE: A modification date in the future does not make a post due. WordPress stamps a scheduled
	 * post's post_modified_gmt with its publish date, so one of those would be due on every tick, the
	 * queue would never empty, and {@see Links::scanPosts()} only sweeps the other sources once it
	 * does - which is how a single scheduled post stopped menus, terms, bios, patterns and templates
	 * from ever being indexed. It becomes due again of its own accord when that date arrives.
	 *
	 * @since   1.0.0
	 * @version 1.3.0 Count distinct post IDs so legacy duplicate rows in aioseo_blc_posts don't inflate the count and stall the scan percentage.
	 * @version 1.3.1 A modification date in the future no longer makes a post due.
	 *
	 * @param  bool      $countOnly Whether to return only the count.
	 * @return array|int            The posts to scan or a count.
	 */
	public function getPostsToScan( $countOnly = false ) {
		$postsPerScan        = apply_filters( 'aioseo_blc_links_posts_per_scan', 50 );
		$postTypes           = aioseoBrokenLinkChecker()->helpers->getScannablePostTypes();
		$postStatuses        = aioseoBrokenLinkChecker()->helpers->getPublicPostStatuses( true );
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$minimumLinkScanDate = esc_sql( aioseoBrokenLinkChecker()->scanState->getMinimumLinkScanDate() ?: date( 'Y-m-d H:i:s' ) );

		$query = aioseoBrokenLinkChecker()->core->db->start( 'posts as p' )
			->leftJoin( 'aioseo_blc_posts as abp', 'p.ID = abp.post_id' )
			->whereIn( 'p.post_status', $postStatuses )
			->whereIn( 'p.post_type', $postTypes )
			->whereRaw( "(
				abp.post_id IS NULL OR
				( abp.link_scan_date < p.post_modified_gmt AND p.post_modified_gmt <= UTC_TIMESTAMP() ) OR
				abp.link_scan_date IS NULL OR
				abp.link_scan_date < '$minimumLinkScanDate'
			)" );

		if ( $countOnly ) {
			return $query->count( 'DISTINCT p.ID' );
		}

		// Only the ID is needed — the scan hydrates each post through get_post().
		$postsToScan = $query
			->select( 'DISTINCT p.ID' )
			->limit( $postsPerScan )
			->run()
			->result();

		return $postsToScan;
	}

	/**
	 * Returns the total number of scannable posts.
	 *
	 * @since 1.0.0
	 *
	 * @return int The total number of scannable posts.
	 */
	private function getTotalScannablePosts() {
		$postTypes    = aioseoBrokenLinkChecker()->helpers->getScannablePostTypes();
		$postStatuses = aioseoBrokenLinkChecker()->helpers->getPublicPostStatuses( true );

		$query = aioseoBrokenLinkChecker()->core->db->start( 'posts as p' )
			->whereIn( 'p.post_status', $postStatuses )
			->whereIn( 'p.post_type', $postTypes );

		return $query->count();
	}

	/**
	 * Returns the scan percentage.
	 *
	 * @since 1.0.0
	 *
	 * @return int The scan percentage.
	 */
	public function getScanPercentage() {
		return $this->getScanProgress()['percent'];
	}

	/**
	 * Returns how far the post scan has got, as counts as well as a percentage.
	 *
	 * @since 1.3.1
	 *
	 * @return array{done: int, total: int, percent: int} How far it has got.
	 */
	public function getScanProgress() {
		$postsToScan         = $this->getPostsToScan( true );
		$totalScannablePosts = $this->getTotalScannablePosts();

		// The other sources are swept from a cursor, so how much of them is left isn't a number we have.
		// Holding the bar just short of done is more honest than reporting a finish that hasn't happened.
		$objectsPending = aioseoBrokenLinkChecker()->main->objectScan->isPending();

		if ( 0 === $postsToScan || 0 === $totalScannablePosts ) {
			return [
				'done'    => $totalScannablePosts,
				'total'   => $totalScannablePosts,
				'percent' => $objectsPending ? 99 : 100
			];
		}

		$percentage = ceil( 100 - ( ( $postsToScan / $totalScannablePosts ) * 100 ) );

		return [
			'done'    => max( 0, $totalScannablePosts - $postsToScan ),
			'total'   => $totalScannablePosts,
			'percent' => $objectsPending ? min( 99, $percentage ) : $percentage
		];
	}

	/**
	 * Sets the ignored extensions.
	 *
	 * NOTE: `com` is not on the list. It is an MS-DOS executable extension, effectively extinct on the
	 * web, and it collides with the commonest TLD - so `/whois/example.com` and `/go/wordpress.com`
	 * were never stored, never checked and never counted.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Dropped `com` from the list.
	 *
	 * @return void
	 */
	private function setIgnoredExtensions() {
		$this->ignoredExtensions = apply_filters( 'aioseo_blc_ignored_extensions', [
			// Executable files
			'apk',
			'bat',
			'bin',
			'cgi',
			'exe',
			'gadget',
			'jar',
			'py',
			'wsf',
		] );
	}
}