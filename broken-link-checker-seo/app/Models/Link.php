<?php
namespace AIOSEO\BrokenLinkChecker\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Core\Database;
use AIOSEO\BrokenLinkChecker\Links\Url;
use AIOSEO\BrokenLinkChecker\Objects\ObjectType;

/**
 * The Link DB model class.
 *
 * @since 1.0.0
 */
class Link extends Model {
	/**
	 * What separates the fields inside one packed object reference.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const REF_FIELD_SEPARATOR = "\t";

	/**
	 * What separates one packed object reference from the next.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const REF_SEPARATOR = "\n";

	/**
	 * The most bytes one packed object reference can take.
	 *
	 * NOTE: A `varchar(20)` type and a `varchar(191)` subtype at four bytes to the character, a bigint ID,
	 * and the separators. Widen it along with the columns.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const REF_MAX_LENGTH = 867;

	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_blc_links';

	/**
	 * Fields that should be numeric values.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $integerFields = [ 'id', 'post_id', 'object_id', 'blc_link_status_id' ];

	/**
	 * Fields that are nullable.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $nullFields = [ 'blc_link_status_id' ];

	/**
	 * Fields that are booleans.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $booleanFields = [ 'external', 'is_video', 'is_image', 'is_embed' ];

	/**
	 * Appended as an extra column, but not stored in the DB.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $appends = [ 'context' ];

	/**
	 * Keys the extractor sets to describe a link, which are not columns.
	 *
	 * These say something the stored row cannot: that a URL came from an embed block, so it has no
	 * anchor and no sentence around it and never will. Dropped before the row is built, since the
	 * insert is positional and an extra value would shift every column after it.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const CONTROL_FIELDS = [];

	/**
	 * The columns a report row reads from a link.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const ROW_SELECT = 'al.id, al.post_id, al.object_type, al.object_id, al.object_subtype, p.post_type, al.anchor, al.phrase, al.is_image, al.is_video, al.url as link_url';

	/**
	 * The columns a report row reads, with the embed flag however this install can supply it.
	 *
	 * NOTE: is_embed arrives with a migration, and a migration is not guaranteed to have run - the
	 * runner skips entirely once the version it last recorded matches the current one, which is what an
	 * install upgrading between two builds of the same version does. Selecting the column regardless is
	 * how the report ended up erroring on every admin page. Where it is absent the phrase stands in for
	 * it, which is the same signal the migration backfills from.
	 *
	 * @since 1.3.1
	 *
	 * @return string The select.
	 */
	public static function rowSelect() {
		if ( self::hasEmbedColumn() ) {
			return self::ROW_SELECT . ', al.is_embed';
		}

		return self::ROW_SELECT . ", ( CASE WHEN al.phrase = '' THEN 1 ELSE 0 END ) as is_embed";
	}

	/**
	 * Whether the links table carries the embed flag yet.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the column exists.
	 */
	public static function hasEmbedColumn() {
		static $hasEmbedColumn = null;
		if ( null !== $hasEmbedColumn ) {
			return $hasEmbedColumn;
		}

		$hasEmbedColumn = aioseoBrokenLinkChecker()->core->db->columnExists( 'aioseo_blc_links', 'is_embed' );

		return $hasEmbedColumn;
	}

	/**
	 * The expression that says whether a link row is an ordinary anchor.
	 *
	 * NOTE: Mirrors {@see self::rowSelect()}'s fallback, so it reads the same either side of the
	 * migration that adds is_embed. A bare URL is excluded too: there is no anchor around it to remove.
	 *
	 * @since 1.3.1
	 *
	 * @return string The expression.
	 */
	public static function anchoredSql() {
		$embed = self::hasEmbedColumn() ? 'al.is_embed' : "( CASE WHEN al.phrase = '' THEN 1 ELSE 0 END )";

		// Not al.anchor <> '': an <a> wrapping an image has no anchor text and was reading as unanchored,
		// which took unlink away from it. A bare URL is still excluded, since its anchor is the URL itself.
		return "( CASE WHEN {$embed} = 0 AND al.anchor <> al.url THEN 1 ELSE 0 END )";
	}

	/**
	 * Whether the given row is an embed rather than a link written as an anchor.
	 *
	 * NOTE: Falls back to the phrase for a row read before the column exists - an embed carries none,
	 * an anchor carries the sentence around it.
	 *
	 * @since 1.3.1
	 *
	 * @param  object $row The link row or model.
	 * @return bool        Whether it is an embed.
	 */
	public static function isEmbedRow( $row ) {
		if ( isset( $row->is_embed ) ) {
			return ! empty( $row->is_embed );
		}

		return isset( $row->phrase ) && '' === (string) $row->phrase;
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
	 * Drops the fields that describe a link but are not columns of one.
	 *
	 * Called immediately before the row is built, and not sooner: {@see self::validateLink()} asks
	 * {@see self::optionalFields()} a second time, so a marker removed during sanitisation would leave
	 * validation applying the rules for an ordinary anchor.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $link The sanitised link.
	 * @return array       The link, with only storable fields left.
	 */
	public static function stripControlFields( $link ) {
		foreach ( self::CONTROL_FIELDS as $field ) {
			unset( $link[ $field ] );
		}

		return $link;
	}

	/**
	 * Returns the Link with the given ID.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Leaves out the sources that are switched off.
	 *
	 * @param  int  $linkId The Link ID.
	 * @return Link         The Link.
	 */
	public static function getById( $linkId ) {
		return self::scopeToEnabledSources(
			aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_links' )
				->where( 'id', $linkId )
		)
			->run()
			->model( 'AIOSEO\\BrokenLinkChecker\\Models\\Link' );
	}

	/**
	 * Returns the Links with the given Link Status ID.
	 *
	 * @since   1.1.0
	 * @version 1.3.1 Leaves out the sources that are switched off.
	 *
	 * @param  int   $linkStatusId The Link Status ID.
	 * @return array               The Links.
	 */
	public static function getByLinkStatusId( $linkStatusId ) {
		// The callers all read the object columns off the rows and treat a row that names no object as a
		// stale record to delete, so they must not be handed rows from a table that has no such column.
		if ( ! self::hasObjectColumns() ) {
			return [];
		}

		return self::scopeToEnabledSources(
			aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_links' )
				->where( 'blc_link_status_id', $linkStatusId )
		)
			->run()
			->models( 'AIOSEO\\BrokenLinkChecker\\Models\\Link' );
	}

	/**
	 * Returns the Links for the given Link Status that the report actually lists.
	 *
	 * NOTE: The same scope the report reads through, which {@see self::getByLinkStatusId()} deliberately
	 * does not apply - that one answers the checking service too, in requests with no user to scope by.
	 * A write path wants this one: a location the report leaves out is not one a write may reach, nor one
	 * to tell the reader it skipped. An Elementor page held back by the post-status setting was being
	 * named in the result of an unlink the reader could not see it in.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $linkStatusId The Link Status ID.
	 * @return array               The Links.
	 */
	public static function getReportedByLinkStatusId( $linkStatusId ) {
		if ( ! self::hasObjectColumns() ) {
			return [];
		}

		return self::baseQuery( $linkStatusId )
			->select( 'al.*' )
			->run()
			->models( 'AIOSEO\BrokenLinkChecker\Models\Link' );
	}

	/**
	 * Returns the Links for the given Link Status that are recorded in the given object.
	 *
	 * NOTE: Rewriting a link reindexes its object, which replaces every Link row for that object with
	 * a new one. This resolves the rows that still carry the old URL after such a rewrite.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Renamed from getByLinkStatusIdAndPostId(); takes an object type and ID.
	 * @version 1.3.1 Leaves out the sources that are switched off.
	 *
	 * @param  int    $linkStatusId The Link Status ID.
	 * @param  string $objectType   The object type.
	 * @param  int    $objectId     The object ID.
	 * @param  int[]  $excludedIds  Link IDs to leave out.
	 * @return array                The Links.
	 */
	public static function getByLinkStatusIdAndObject( $linkStatusId, $objectType, $objectId, $excludedIds = [] ) {
		if ( ! self::hasObjectColumns() ) {
			return [];
		}

		$query = self::scopeToEnabledSources(
			aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_links' )
				->where( 'blc_link_status_id', $linkStatusId )
				->where( 'object_type', $objectType )
				->where( 'object_id', $objectId )
		);

		if ( ! empty( $excludedIds ) ) {
			$query->whereNotIn( 'id', $excludedIds );
		}

		return $query->orderBy( 'id ASC' )
			->run()
			->models( 'AIOSEO\\BrokenLinkChecker\\Models\\Link' );
	}

	/**
	 * Deletes all Links recorded in the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $postId The post ID.
	 * @return void
	 */
	public static function deleteLinks( $postId ) {
		self::deleteObjectLinks( 'post', $postId );
	}

	/**
	 * Deletes all Links recorded in the given object.
	 *
	 * NOTE: A null subtype covers the whole object, which is how a post's fields are cleared in one
	 * indexed delete before they are read again.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Takes a subtype, for the kinds whose subtype is part of the address.
	 *
	 * @param  string      $objectType The object type.
	 * @param  int         $objectId   The object ID.
	 * @param  string|null $subtype    The object subtype, or null for every one of them.
	 * @return void
	 */
	public static function deleteObjectLinks( $objectType, $objectId, $subtype = null ) {
		if ( ! self::hasObjectColumns() ) {
			return;
		}

		$query = aioseoBrokenLinkChecker()->core->db->delete( 'aioseo_blc_links' )
			->where( 'object_type', $objectType )
			->where( 'object_id', $objectId );

		if ( null !== $subtype ) {
			$query->where( 'object_subtype', (string) $subtype );
		}

		$query->run();

		// Deleting rows moves the counts as surely as writing them, and a delete does not go through
		// save() — a trashed post is removed and nothing is inserted in its place.
		LinkStatus::flushCounts();
	}

	/**
	 * Deletes the links the given object holds under any of the given types, in one statement.
	 *
	 * NOTE: For the builders. Every one of them has to be cleared for a post whether or not it holds a
	 * layout, since a layout that has been taken off a page leaves rows nothing else would ever revisit
	 * — and asking each in turn was a delete per builder on every post, almost always of nothing.
	 *
	 * @since 1.3.1
	 *
	 * @param  int      $objectId    The object ID.
	 * @param  string[] $objectTypes The object types to clear.
	 * @return void
	 */
	public static function deleteObjectTypesLinks( $objectId, $objectTypes ) {
		$objectTypes = array_values( array_filter( array_map( 'strval', (array) $objectTypes ) ) );
		if ( empty( $objectTypes ) || ! self::hasObjectColumns() ) {
			return;
		}

		aioseoBrokenLinkChecker()->core->db->delete( 'aioseo_blc_links' )
			->where( 'object_id', (int) $objectId )
			->whereIn( 'object_type', $objectTypes )
			->run();

		LinkStatus::flushCounts();
	}

	/**
	 * Returns the distinct link-status IDs referenced by the given post's links.
	 *
	 * @since 1.3.0
	 *
	 * @param  int   $postId The post ID.
	 * @return int[]         The referenced link-status IDs.
	 */
	public static function getLinkStatusIds( $postId ) {
		return self::getObjectLinkStatusIds( 'post', $postId );
	}

	/**
	 * Returns the distinct link-status IDs referenced by the given object's links.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Takes a subtype, for the kinds whose subtype is part of the address.
	 *
	 * @param  string      $objectType The object type.
	 * @param  int         $objectId   The object ID.
	 * @param  string|null $subtype    The object subtype, or null for every one of them.
	 * @return int[]                   The referenced link-status IDs.
	 */
	public static function getObjectLinkStatusIds( $objectType, $objectId, $subtype = null ) {
		if ( ! self::hasObjectColumns() ) {
			return [];
		}

		$query = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_links' )
			->select( 'blc_link_status_id' )
			->where( 'object_type', $objectType )
			->where( 'object_id', $objectId );

		if ( null !== $subtype ) {
			$query->where( 'object_subtype', (string) $subtype );
		}

		$rows = $query->run()->result();

		return array_values( array_filter( array_unique( array_map( function( $row ) {
			return (int) $row->blc_link_status_id;
		}, $rows ) ) ) );
	}

	/**
	 * The reason unlinking cannot apply to an image.
	 *
	 * @since 1.3.1
	 *
	 * @return string The reason.
	 */
	/**
	 * The reason a URL written as plain text cannot be unlinked.
	 *
	 * @since 1.3.1
	 *
	 * @return string The reason.
	 */
	public static function bareUrlUnlinkRefusal() {
		return __( 'This address is written as plain text rather than as a link, so there is no link to remove. Change it, or edit it out where it appears.', 'broken-link-checker-seo' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	}

	/**
	 * Whether the row is a URL written as plain text rather than wrapped in an anchor.
	 *
	 * NOTE: The extractor uses the address as the anchor for these, because the address is what a reader
	 * sees. That equality is what tells them apart from a link whose text happens to be its own URL,
	 * which carries a phrase as well.
	 *
	 * @since 1.3.1
	 *
	 * @param  object $row The link row or model.
	 * @return bool        Whether it is a bare URL.
	 */
	public static function isBareUrlRow( $row ) {
		$url = isset( $row->link_url ) ? $row->link_url : ( isset( $row->url ) ? $row->url : '' );
		if ( empty( $row->anchor ) || empty( $url ) ) {
			return false;
		}

		$phrase = isset( $row->phrase ) ? $row->phrase : '';

		return (string) $row->anchor === (string) $url && (string) $phrase === (string) $url;
	}

	public static function imageUnlinkRefusal() {
		return __( 'This is an image rather than a link, so there is no link text to leave behind. Change its URL, or remove the image in the editor.', 'broken-link-checker-seo' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	}

	/**
	 * The reason a video embed cannot be unlinked.
	 *
	 * @since 1.3.1
	 *
	 * @return string The reason.
	 */
	public static function videoUnlinkRefusal() {
		return __( 'This is a video embed rather than a link, so there is no link text to leave behind. Change its URL, or remove the video in the editor.', 'broken-link-checker-seo' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	}

	/**
	 * The actions the report may offer for one occurrence.
	 *
	 * The type answers for a kind of object; this narrows that to the row, which is the only thing that
	 * knows an occurrence is an image rather than a link.
	 *
	 * @since 1.3.1
	 *
	 * @param  ObjectType $objectType The object type.
	 * @param  object     $linkRow    The link row.
	 * @param  int        $objectId   The object ID.
	 * @param  string     $subtype    The object subtype.
	 * @return string[]               The actions.
	 */
	private static function occurrenceActions( $objectType, $linkRow, $objectId, $subtype ) {
		// is_image is not consulted. It says what the URL points at, not how the URL appears: an anchor
		// around an image points at an image and is still an anchor with something to unwrap. Whether
		// there is anything to unlink is an embed or bare-URL question, and occurrenceRefusals() answers
		// it - which also shows the action disabled with a reason rather than hiding it.
		return $objectType->supportedActions( $objectId, $subtype );
	}

	/**
	 * The actions shown disabled for one occurrence, each with the reason why.
	 *
	 * @since 1.3.1
	 *
	 * @param  ObjectType $objectType The object type.
	 * @param  object     $linkRow    The link row.
	 * @param  int        $objectId   The object ID.
	 * @param  string     $subtype    The object subtype.
	 * @return string[]               The reasons, keyed by action.
	 */
	private static function occurrenceRefusals( $objectType, $linkRow, $objectId, $subtype ) {
		$refusals = $objectType->refusedActions( $objectId, $subtype );

		// Embedded, so there is no anchor for unlinking to leave behind - taking it out would mean
		// deleting the picture or the video itself. A link that merely points at one has text around it
		// and comes out like any other link.
		if ( ! isset( $refusals[ ObjectType::UNLINK ] ) && self::isBareUrlRow( $linkRow ) ) {
			$refusals[ ObjectType::UNLINK ] = self::bareUrlUnlinkRefusal();
		}

		if ( ! isset( $refusals[ ObjectType::UNLINK ] ) && self::isEmbedRow( $linkRow ) ) {
			$refusals[ ObjectType::UNLINK ] = ! empty( $linkRow->is_video )
				? self::videoUnlinkRefusal()
				: self::imageUnlinkRefusal();
		}

		return $refusals;
	}

	/**
	 * The fields a link may legitimately leave empty.
	 *
	 * The context fields are only mandatory for a link found inside rich text. A menu item — or any
	 * other object whose URL is its value — has no anchor to surround and no sentence to quote.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $link The link data.
	 * @return array       The field names.
	 */
	private static function optionalFields( $link ) {
		// Always optional: the subtype only applies to the types that need one to be addressed, and
		// post_id is 0 for every source but a post. object_id is what these rows are validated on.
		$optional = [ 'object_subtype', 'post_id' ];

		$context = [ 'anchor', 'phrase', 'phrase_html', 'paragraph', 'paragraph_html' ];

		// An embed is a URL on its own line, whether or not we can name what it points at.
		if ( ! empty( $link['is_video'] ) || ! empty( $link['is_image'] ) || ! empty( $link['is_embed'] ) ) {
			return array_merge( $optional, $context );
		}

		// An <a> wrapping an image is the same case from the other side: content, but none of it text.
		// Media rows carry is_embed and a bare URL's anchor is the URL itself, so an empty anchor on a
		// non-embed is this and nothing else - the same predicate {@see self::anchoredSql()} uses.
		if ( '' === trim( (string) ( isset( $link['anchor'] ) ? $link['anchor'] : '' ) ) && empty( $link['is_embed'] ) ) {
			return array_merge( $optional, $context );
		}

		$objectType = isset( $link['object_type'] ) ? $link['object_type'] : 'post';
		$objectId   = isset( $link['object_id'] ) ? (int) $link['object_id'] : 0;
		$subtype    = isset( $link['object_subtype'] ) ? (string) $link['object_subtype'] : '';

		if ( ! aioseoBrokenLinkChecker()->objects->get( $objectType )->isRichText( $objectId, $subtype ) ) {
			return array_merge( $optional, $context );
		}

		return $optional;
	}

	/**
	 * Sanitizes the link object.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Sanitizes the object addressing fields.
	 * @version 1.3.1 Resolves the URL through {@see \AIOSEO\BrokenLinkChecker\Links\Url::resolve()}.
	 *
	 * @param  array $link The link data.
	 * @return array       The sanitized link data.
	 */
	public static function sanitizeLink( $link ) {
		$instance = new self();

		$sanitizedLink = [];
		$optional      = self::optionalFields( $link );
		foreach ( $link as $k => $v ) {
			switch ( $k ) {
				case 'post_id':
				case 'object_id':
				case 'blc_link_status_id':
					if ( null === $v && in_array( $k, $instance->nullFields, true ) ) {
						break;
					}
					$v = intval( $v );
					break;
				case 'external':
				case 'is_video':
				case 'is_image':
				case 'is_embed':
					$v = rest_sanitize_boolean( $v );
					break;
				case 'url':
					// Idempotent, so it cannot move the hash urlFields() already derived. sanitize_url()
					// could: it re-escaped a raw `[`, leaving the two tables disagreeing about one URL.
					$v = Url::resolve( $v, get_site_url() );
					break;
				case 'object_type':
				case 'object_subtype':
				case 'url_hash':
				case 'hostname':
				case 'hostname_hash':
				case 'anchor':
				case 'phrase':
				case 'paragraph':
					$v = sanitize_text_field( $v );
					break;
				case 'phrase_html':
				case 'paragraph_html':
					$v = aioseoBrokenLinkChecker()->helpers->wpKsesPhrase( $v );
					break;
				default:
					break;
			}

			if (
				empty( $v ) &&
				! in_array( $k, $instance->booleanFields, true ) &&
				! in_array( $k, $instance->nullFields, true ) &&
				! in_array( $k, $optional, true )
			) {
				return [];
			}

			$sanitizedLink[ $k ] = $v;
		}

		return $sanitizedLink;
	}

	/**
	 * Checks whether the given link object is a valid one in the context of Broken Link Checker.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Treats the context fields as optional for objects whose URL is their value.
	 *
	 * @param  array $link The link data.
	 * @return bool        Whether the link is valid or not.
	 */
	public static function validateLink( $link ) {
		// url and hostname are always required.
		$propsToCheck = [
			'url',
			'hostname',
			'anchor',
			'phrase',
			'phrase_html',
			'paragraph',
			'paragraph_html'
		];

		$optional = self::optionalFields( $link );

		foreach ( $propsToCheck as $prop ) {
			$value = wp_strip_all_tags( isset( $link[ $prop ] ) ? $link[ $prop ] : '' );

			if ( empty( $value ) ) {
				if ( in_array( $prop, $optional, true ) ) {
					continue;
				}

				return false;
			}
		}

		return true;
	}

	/**
	 * Returns link row results based on the given arguments.
	 *
	 * The rows a person cannot be shown at all are excluded in SQL by {@see self::baseQuery()}, so the
	 * page they land on holds the rows the count promised. What's left here is the object that no longer
	 * resolves: it stays listed, because the count includes it and dismissing it is the only way out.
	 *
	 * @since   1.1.0
	 * @version 1.3.1 Lists the rows whose object is gone rather than dropping them after the LIMIT.
	 * @version 1.3.1 The context carries the type's tag label, URL refusal and rich text flag.
	 *
	 * @param  int    $limit       The limit.
	 * @param  int    $offset      The offset.
	 * @param  string $whereClause The WHERE clause.
	 * @return array               List of Link instances.
	 */
	public static function rowQuery( $linkStatusId, $limit = 5, $offset = 0, $whereClause = '' ) {
		if ( ! self::hasObjectColumns() ) {
			return [];
		}

		// Ordered, because this is paged. Without one the set is whatever the storage engine returns, so
		// two pages could repeat a location and miss another - and the export, which walks every page,
		// would keep an arbitrary subset.
		$linkRows = self::baseQuery( $linkStatusId, $whereClause )
			->select( self::rowSelect() )
			->orderBy( 'al.id ASC' )
			->limit( $limit, $offset )
			->run()
			->result();

		return self::decorateLinkRows( $linkRows );
	}

	/**
	 * Returns one link per given status, for the statuses that only have one.
	 *
	 * NOTE: The report asked for these a status at a time. It only asks at all for a status whose
	 * links number one, and a status with one link needs no per-status limiting — so the whole page's
	 * worth is one query rather than one each.
	 *
	 * @since 1.3.1
	 *
	 * @param  int[]  $linkStatusIds The link status IDs, each expected to hold a single link.
	 * @param  string $whereClause   The WHERE clause.
	 * @return array                 The decorated link rows, keyed by link status ID.
	 */
	public static function rowQueryForStatuses( $linkStatusIds, $whereClause = '' ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $linkStatusIds ) ) ) );
		if ( empty( $ids ) || ! self::hasObjectColumns() ) {
			return [];
		}

		$query = self::applyUserScope( self::objectScopedQuery() )
			->whereIn( 'al.blc_link_status_id', $ids );

		if ( ! empty( $whereClause ) ) {
			$query->whereRaw( $whereClause );
		}

		$linkRows = $query
			->select( self::rowSelect() . ', al.blc_link_status_id' )
			->run()
			->result();

		$byStatus = [];
		foreach ( self::decorateLinkRows( $linkRows ) as $linkRow ) {
			$statusId = (int) $linkRow->blc_link_status_id;
			if ( ! isset( $byStatus[ $statusId ] ) ) {
				$byStatus[ $statusId ] = $linkRow;
			}
		}

		return $byStatus;
	}

	/**
	 * Attaches the context a report row needs to each of the given link rows.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $linkRows The link rows.
	 * @return array           The rows the current user may be shown, with their context attached.
	 */
	private static function decorateLinkRows( $linkRows ) {
		if ( empty( $linkRows ) ) {
			return [];
		}

		// One fetch per kind before the loop, not one per row. Describing a location reads the object it
		// sits in, so a page of occurrences issued a query each on a cold cache.
		$idsByType = [];
		foreach ( $linkRows as $linkRow ) {
			$idsByType[ (string) $linkRow->object_type ][] = (int) $linkRow->object_id;
		}

		foreach ( $idsByType as $type => $objectIds ) {
			aioseoBrokenLinkChecker()->objects->get( $type )->primeCaches( $objectIds );
		}

		$rowsWithData = [];
		foreach ( $linkRows as $linkRow ) {
			$objectType = aioseoBrokenLinkChecker()->objects->get( $linkRow->object_type );
			$objectId   = (int) $linkRow->object_id;
			$subtype    = (string) $linkRow->object_subtype;
			$exists     = $objectType->exists( $objectId, $subtype );
			$canEdit    = $objectType->canEdit( $objectId, $subtype );

			// Location data is what a report row leaks, and each kind has its own edit capability. An
			// object that isn't there has no location data to leak, so that row is listed either way.
			if ( $exists && ! $canEdit ) {
				continue;
			}

			$locationLabel = $objectType->locationLabel( $objectId, $subtype );

			$urlRefusal = $objectType->urlRefusal( $objectId, $subtype );

			$linkRow->context = [
				'objectType'     => $objectType->type(),
				'objectId'       => $objectId,
				'objectSubtype'  => $subtype,
				'objectLabel'    => $objectType->itemLabel( $objectId, $subtype ),
				'objectTagLabel' => $objectType->tagLabel( $objectId, $subtype ),
				'sourceLabel'    => $objectType->sourceLabel(),
				'locationLabel'  => $exists ? $locationLabel : __( 'Location no longer available', 'broken-link-checker-seo' ),
				'postType'       => isset( $linkRow->post_type ) ? $linkRow->post_type : '',
				'permalink'      => $objectType->viewUrl( $objectId, $subtype ),
				'editLink'       => $objectType->editUrl( $objectId, $subtype ),
				// Nothing can be written to an object that is gone, so dismissing the URL is all that's left.
				'actions'        => $exists ? self::occurrenceActions( $objectType, $linkRow, $objectId, $subtype ) : [ ObjectType::DISMISS ],
				// The reason lives on the write path; the table reads it to disable the action up front.
				'urlRefusal'     => is_wp_error( $urlRefusal ) ? $urlRefusal->get_error_message() : null,
				// Shown disabled with the reason on them, rather than left out for the reader to wonder about.
				'actionRefusals' => $exists ? self::occurrenceRefusals( $objectType, $linkRow, $objectId, $subtype ) : [],
				// One kind can hold both shapes, so the field decides, not the type.
				'isRichText'     => $objectType->isRichText( $objectId, $subtype ),
				'isImage'        => ! empty( $linkRow->is_image ),
				// Whether the URL is presented as media rather than linked to. is_image says what it points
				// at, which is a different question and not the one the cell's wording turns on.
				'isEmbed'        => self::isEmbedRow( $linkRow ),
				'canEdit'        => $canEdit,
				'canDelete'      => $objectType->canDelete( $objectId, $subtype )
			];

			// The report's post-shaped fields, kept so a consumer written against them still reads.
			$linkRow->context['postTitle'] = $linkRow->context['locationLabel'];

			$rowsWithData[] = $linkRow;
		}

		return $rowsWithData;
	}

	/**
	 * Returns link row count based on the given arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $linkStatusId The link status ID.
	 * @param  string $whereClause  The WHERE clause.
	 * @return int                  The row count.
	 */
	public static function rowQueryCount( $linkStatusId, $whereClause = '' ) {
		if ( ! self::hasObjectColumns() ) {
			return 0;
		}

		return self::baseQuery( $linkStatusId, $whereClause )->count();
	}

	/**
	 * Returns both the total link count and the distinct post count in a single query.
	 *
	 * @since 1.3.0
	 *
	 * The object types come back from the same query rather than a second one, because the report asks
	 * for these per row and a page of it is twenty rows.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Counts distinct objects rather than posts, and reports the types they are.
	 *
	 * @param  int    $linkStatusId The link status ID.
	 * @param  string $whereClause  The WHERE clause.
	 * @return array{total: int, distinctObjects: int, distinctPosts: int, objectTypes: string[]} The counts.
	 */
	public static function rowQueryCounts( $linkStatusId, $whereClause = '' ) {
		if ( ! self::hasObjectColumns() ) {
			return [
				'total'           => 0,
				'distinctObjects' => 0,
				'distinctPosts'   => 0,
				'objectTypes'     => []
			];
		}

		$result = self::baseQuery( $linkStatusId, $whereClause )
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			->select( "COUNT(*) as total, COUNT(DISTINCT CONCAT(al.object_type, ':', al.object_id)) as distinct_objects, GROUP_CONCAT(DISTINCT al.object_type) as object_types" )
			->run()
			->result();

		$row             = ! empty( $result[0] ) ? $result[0] : null;
		$distinctObjects = $row ? (int) $row->distinct_objects : 0;

		return [
			'total'           => $row ? (int) $row->total : 0,
			'distinctObjects' => $distinctObjects,
			// The name from before links could be found anywhere but a post.
			'distinctPosts'   => $distinctObjects,
			'objectTypes'     => self::splitObjectTypes( $row ? $row->object_types : '' )
		];
	}

	/**
	 * Splits a concatenated list of object types, defaulting an empty slug to the post shape.
	 *
	 * @since 1.3.1
	 *
	 * @param  string   $objectTypes The concatenated types.
	 * @return string[]              The slugs.
	 */
	private static function splitObjectTypes( $objectTypes ) {
		// No rows is no types. Reading it as one row without a type reported the post shape for a status
		// whose links are all filtered out of the report.
		if ( '' === (string) $objectTypes ) {
			return [];
		}

		$slugs = [];
		foreach ( explode( ',', (string) $objectTypes ) as $slug ) {
			if ( '' === $slug ) {
				// The column defaults to the post shape, so a row without one is a post row.
				$slug = 'post';
			}

			$slugs[] = $slug;
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Returns how many distinct URLs the report lists per source.
	 *
	 * A URL found in both a post and a menu counts once under each, so the sum can exceed the number
	 * of rows the report shows — one row covers a URL wherever it was found.
	 *
	 * Conditional aggregation would need one expression per registered type, so this groups instead:
	 * the result is one row per type, not one per link.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $filter      The active filter.
	 * @param  string $whereClause The WHERE clause.
	 * @return array<string, int>  The count per object type.
	 */
	public static function getObjectTypeTotals( $filter = 'all', $whereClause = '' ) {
		$totals = [];
		foreach ( aioseoBrokenLinkChecker()->objects->all() as $slug => $objectType ) {
			$totals[ $slug ] = 0;
		}

		if ( ! self::hasObjectColumns() ) {
			return $totals;
		}

		// As SQL up front: the builder is shared, so the cache lookup below would reset a query held
		// across it. {@see LinkStatus::filterCounts()}
		$sql = (string) LinkStatus::objectTypeQuery( $filter, $whereClause )
			->select( 'al.object_type as object_type, COUNT(DISTINCT al.url_hash) as total' )
			->groupBy( 'al.object_type' )
			->query();

		// Cached beside the filter counts and dropped with them, for the same reason and on the same
		// events. Keyed on the SQL so the user scope is part of the key.
		$cacheKey = LinkStatus::COUNTS_CACHE_PREFIX . md5( $sql );
		$cached   = aioseoBrokenLinkChecker()->core->cache->get( $cacheKey );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = aioseoBrokenLinkChecker()->core->db->db->get_results( $sql );

		foreach ( $rows as $row ) {
			$slug            = '' === (string) $row->object_type ? 'post' : (string) $row->object_type;
			$totals[ $slug ] = ( isset( $totals[ $slug ] ) ? $totals[ $slug ] : 0 ) + (int) $row->total;
		}

		aioseoBrokenLinkChecker()->core->cache->update( $cacheKey, $totals, LinkStatus::COUNTS_CACHE_TTL );

		return $totals;
	}

	/**
	 * Returns the actions the report may offer for a row spanning the given kinds of object.
	 *
	 * Only the actions every one of them supports: a row covering both a post and a menu item cannot
	 * offer unlinking, because there is no anchor to strip in the menu item.
	 *
	 * @since 1.3.1
	 *
	 * @param  string[] $objectTypes The object type slugs.
	 * @return string[]              The actions.
	 */
	public static function getSharedActions( $objectTypes, $anchored = true ) {
		$shared     = null;
		$anyUnlinks = false;
		foreach ( (array) $objectTypes as $slug ) {
			$actions = aioseoBrokenLinkChecker()->objects->get( $slug )->supportedActions();
			$shared  = null === $shared ? $actions : array_intersect( $shared, $actions );

			$anyUnlinks = $anyUnlinks || in_array( ObjectType::UNLINK, $actions, true );
		}

		$shared = null === $shared ? [] : array_values( $shared );

		// Unlink is the one action that writes to each location in turn rather than rewriting the URL
		// everywhere at once, so one location that cannot take it is no reason to withhold it from the
		// ones that can - {@see Api\CommonTableActions::unlink()} does the rest and names what it skipped.
		// It still needs somewhere to land: an anchor to remove, in a kind of location that allows it.
		if ( $anyUnlinks && $anchored && ! in_array( ObjectType::UNLINK, $shared, true ) ) {
			$shared[] = ObjectType::UNLINK;
		}

		return $shared;
	}

	/**
	 * Returns why a row spanning the given kinds cannot offer an action, for each it cannot.
	 *
	 * A row that just drops the action reads as though the report has no opinion, when in fact one of the
	 * locations is the reason. The other locations are still fixable one at a time, which is what the row
	 * expands to show, so the reason says so where there are others.
	 *
	 * @since 1.3.1
	 *
	 * @param  string[] $objectTypes The object type slugs.
	 * @return string[]              The reasons, keyed by action.
	 */
	public static function getSharedActionRefusals( $objectTypes, $hasImage = false, $hasVideo = false, $anchored = true ) {
		$objectTypes = (array) $objectTypes;
		$shared      = self::getSharedActions( $objectTypes, $anchored );
		$refusals    = [];

		foreach ( $objectTypes as $slug ) {
			$refused = aioseoBrokenLinkChecker()->objects->get( $slug )->refusedActions();
			foreach ( $refused as $action => $reason ) {
				if ( in_array( $action, $shared, true ) || isset( $refusals[ $action ] ) ) {
					continue;
				}

				$refusals[ $action ] = 1 < count( $objectTypes )
					? $reason . ' ' . __( 'Expand the row to fix its other locations.', 'broken-link-checker-seo' )
					: $reason;
			}
		}

		// The type cannot know this: whether a row is an image or a video embed lives on the link, not on
		// the object it sits in. Only when no occurrence is an anchor, though - a URL embedded in one
		// place and linked in another is still unlinkable where it is linked.
		if ( ! isset( $refusals[ ObjectType::UNLINK ] ) && ! $anchored ) {
			if ( $hasImage ) {
				$refusals[ ObjectType::UNLINK ] = self::imageUnlinkRefusal();
			} elseif ( $hasVideo ) {
				$refusals[ ObjectType::UNLINK ] = self::videoUnlinkRefusal();
			}
		}

		return $refusals;
	}

	/**
	 * Splits an object reference back into its parts.
	 *
	 * Aggregate queries concatenate the three columns so a single GROUP_CONCAT can carry them, with a
	 * tab between the fields and a newline between the references — a subtype carries a meta key, and
	 * neither character can occur in one.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Reads the tab-separated packing.
	 *
	 * @param  string $reference The reference.
	 * @return array{type: string, id: int, subtype: string} The parts.
	 */
	public static function parseObjectRef( $reference ) {
		$parts = explode( self::REF_FIELD_SEPARATOR, (string) $reference );

		return [
			'type'    => isset( $parts[0] ) && '' !== $parts[0] ? $parts[0] : 'post',
			'id'      => isset( $parts[1] ) ? (int) $parts[1] : 0,
			'subtype' => isset( $parts[2] ) ? $parts[2] : ''
		];
	}

	/**
	 * Splits a packed list of object references into the individual references.
	 *
	 * NOTE: GROUP_CONCAT stops at `group_concat_max_len`, so the last reference can be a partial one. Cut
	 * in the type or the ID it comes up short of the three fields a complete reference carries and is
	 * dropped here; cut in the subtype it reads as a shorter subtype. So what this guarantees is that the
	 * type and ID are never wrong, not that every reference it returns is whole.
	 *
	 * @since 1.3.1
	 *
	 * @param  string   $references The packed references.
	 * @return string[]             The references.
	 */
	public static function splitObjectRefs( $references ) {
		$references = (string) $references;
		if ( '' === $references ) {
			return [];
		}

		$references = explode( self::REF_SEPARATOR, $references );
		$lastIndex  = count( $references ) - 1;

		if ( 3 > count( explode( self::REF_FIELD_SEPARATOR, $references[ $lastIndex ] ) ) ) {
			unset( $references[ $lastIndex ] );
		}

		return array_values( $references );
	}

	/**
	 * The SQL that packs the object columns of a group into references, best candidate first.
	 *
	 * A post first and then the lowest ID, which is the location a person is most likely to be able to
	 * do something about. Ordering the packed strings themselves put a menu item ahead of a hundred
	 * posts, because `menu_item` sorts before `post` and the ID sorted as text. Raises
	 * `group_concat_max_len` so the limit asked for is what decides how many come back.
	 *
	 * NOTE: Deliberately not DISTINCT — the engines differ on whether they accept an ordering expression
	 * alongside it. So one object holding the URL several times occupies a slot per occurrence, and a
	 * caller looking for a particular kind of location among these needs its limit well clear of the
	 * number of times a single object might repeat one URL.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $limit How many references to keep.
	 * @return string        The expression.
	 */
	public static function objectRefsSql( $limit = 1 ) {
		self::raiseRefPackingLimit( $limit );

		$field  = self::REF_FIELD_SEPARATOR;
		$record = self::REF_SEPARATOR;

		return "SUBSTRING_INDEX(GROUP_CONCAT(CONCAT_WS('$field', al.object_type, al.object_id, al.object_subtype)"
			. " ORDER BY ('post' = al.object_type) DESC, al.object_id ASC SEPARATOR '$record'), '$record', " . (int) $limit . ')';
	}

	/**
	 * Makes room in `group_concat_max_len` for the given number of packed references.
	 *
	 * The 1024-byte default holds seventeen of them at the width a meta key takes, so raising the limit
	 * would otherwise be defeated by a truncation that reads as a real but different field.
	 *
	 * NOTE: Best effort. {@see Link::splitObjectRefs()} still drops a partial reference, which is what
	 * holds when the statement is refused or something lowers the value again afterwards.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $limit How many references have to fit.
	 * @return void
	 */
	private static function raiseRefPackingLimit( $limit ) {
		static $raisedTo = 0;

		$needed = (int) $limit * self::REF_MAX_LENGTH;
		if ( $raisedTo >= $needed ) {
			return;
		}

		// Fired outside the query builder, which is not re-entrant and has one assembling right now. The
		// GREATEST keeps a value another plugin raised for its own aggregate from being shrunk to ours.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb       = aioseoBrokenLinkChecker()->core->db->db;
		$suppressed = $wpdb->suppress_errors( true );
		$raised     = false !== $wpdb->query(
			$wpdb->prepare( 'SET SESSION group_concat_max_len = CAST(GREATEST(@@SESSION.group_concat_max_len, %d) AS UNSIGNED)', $needed )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->suppress_errors( $suppressed );

		if ( $raised ) {
			$raisedTo = $needed;
		}
	}

	/**
	 * Returns the base query for the rowQuery() and rowCountQuery() methods.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Joins the posts table on the object columns, so links found outside a post are
	 *                 not filtered out by the post-type and post-status rules.
	 * @version 1.3.1 Leaves out the sources the current user has no business seeing.
	 *
	 * @param  int      $linkStatusId The link status ID.
	 * @param  string   $whereClause  The WHERE clause.
	 * @return Database               The query.
	 */
	public static function baseQuery( $linkStatusId, $whereClause = '' ) {
		$query = self::applyUserScope( self::objectScopedQuery() )
			->where( 'al.blc_link_status_id', $linkStatusId );

		if ( ! empty( $whereClause ) ) {
			$query->whereRaw( $whereClause );
		}

		return $query;
	}

	/**
	 * Returns the counts {@see self::rowQueryCounts()} returns, for a page of statuses at once.
	 *
	 * NOTE: Written because the report asked per row. The same three-table join ran once for every
	 * row on the page, so a page cost twenty of them to answer a question one can — and the answer
	 * for twenty statuses is one grouped query.
	 *
	 * @since 1.3.1
	 *
	 * @param  int[]  $linkStatusIds The link status IDs.
	 * @param  string $whereClause   The WHERE clause.
	 * @return array                 The counts, keyed by link status ID.
	 */
	public static function rowQueryCountsBatch( $linkStatusIds, $whereClause = '' ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $linkStatusIds ) ) ) );
		if ( empty( $ids ) || ! self::hasObjectColumns() ) {
			return [];
		}

		$query = self::applyUserScope( self::objectScopedQuery() )
			->whereIn( 'al.blc_link_status_id', $ids );

		if ( ! empty( $whereClause ) ) {
			$query->whereRaw( $whereClause );
		}

		$rows = $query
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			// anchored says whether any occurrence is an ordinary anchor - not an embed, and not a bare URL
			// written as its own text. That is what decides whether unlinking the row can do anything at
			// all, and it is read through the same fallback rowSelect() uses so it works either side of
			// the migration that adds the column.
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			->select( "al.blc_link_status_id as link_status_id, COUNT(*) as total, COUNT(DISTINCT CONCAT(al.object_type, ':', al.object_id)) as distinct_objects, GROUP_CONCAT(DISTINCT al.object_type) as object_types, MAX(al.is_image) as has_image, MAX(al.is_video) as has_video, MAX(" . self::anchoredSql() . ') as anchored' )
			->groupBy( 'al.blc_link_status_id' )
			->run()
			->result();

		$counts = [];
		foreach ( $rows as $row ) {
			$distinctObjects = (int) $row->distinct_objects;

			$counts[ (int) $row->link_status_id ] = [
				'total'           => (int) $row->total,
				'distinctObjects' => $distinctObjects,
				// The name from before links could be found anywhere but a post.
				'distinctPosts'   => $distinctObjects,
				'objectTypes'     => self::splitObjectTypes( $row->object_types ),
				'hasImage'        => ! empty( $row->has_image ),
				'hasVideo'        => ! empty( $row->has_video ),
				'anchored'        => ! empty( $row->anchored )
			];
		}

		return $counts;
	}

	/**
	 * Narrows the given query to the locations the current user may be shown.
	 *
	 * A location is only listed to someone who could act on it, so the rows that fail that test have to
	 * leave before the LIMIT does its work — dropped afterwards, they cost the report a page of nothing
	 * and a header that counts rows it never shows.
	 *
	 * NOTE: Only what a capability can settle for a whole kind at once. A per-post check is still made
	 * once the row is read, so a page can come back shorter than the count for a post nobody but its
	 * author may edit. Deliberately not applied to the scan's own queries, which run with no user.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Passes each condition through `aioseo_blc_user_scope_condition`.
	 * @version 1.3.1 Public, so the report's own rows and counts are scoped the same way.
	 *
	 * @param  Database $query The query, with `aioseo_blc_links` aliased to `al`.
	 * @return Database        The query.
	 */
	public static function applyUserScope( $query ) {
		foreach ( aioseoBrokenLinkChecker()->objects->all() as $slug => $objectType ) {
			/**
			 * Filters the SQL condition that limits a source's rows to the locations the user may be shown.
			 *
			 * A plugin that grants editing rights the capabilities alone don't describe — a co-author or an
			 * editorial workflow — widens the condition here so those locations stay in the report. The
			 * per-row check in {@see Link::rowQuery()} still applies, so this cannot widen past it.
			 *
			 * @since 1.3.1
			 *
			 * @param string $condition The condition, against the links table aliased to `al`. An empty
			 *                          string leaves every row of this kind in, and `0` takes them all out.
			 * @param string $slug      The object type slug.
			 */
			$condition = (string) apply_filters( 'aioseo_blc_user_scope_condition', $objectType->userScopeCondition(), $slug );
			if ( '' === $condition ) {
				continue;
			}

			$query->whereRaw( "( al.object_type <> '" . esc_sql( $slug ) . "' OR ( $condition ) )" );
		}

		return $query;
	}

	/**
	 * Returns a links query scoped to the sources the report covers.
	 *
	 * The post-type, post-status and excluded-post rules only apply to the rows a post is the source
	 * of. Applied unconditionally against a LEFT JOIN they would drop every other source, since those
	 * rows have no post to satisfy them with.
	 *
	 * @since 1.3.1
	 *
	 * @return Database The query.
	 */
	public static function objectScopedQuery() {
		return self::applyObjectScope(
			aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_links as al' )
		);
	}

	/**
	 * Joins the posts table on the object columns and applies the rules the report is scoped by.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Scopes the post rules to every post-backed kind, not just post content.
	 *
	 * @param  Database $query The query, with `aioseo_blc_links` aliased to `al`.
	 * @return Database        The query.
	 */
	public static function applyObjectScope( $query ) {
		$includedPostTypes    = aioseoBrokenLinkChecker()->helpers->getIncludedPostTypes();
		$includedPostStatuses = aioseoBrokenLinkChecker()->helpers->getIncludedPostStatuses();
		$excludedPostIds      = aioseoBrokenLinkChecker()->helpers->getExcludedPostIds();

		$query->leftJoin( 'posts as p', 'p.ID = al.object_id AND ' . self::postBackedTypes() );

		// The join has to match for a post-backed row, whatever the rules below happen to be: against a
		// LEFT JOIN, such a row whose post is gone satisfies every one of them vacuously.
		$query->whereRaw( self::postOnly( 'p.ID IS NOT NULL' ) );

		if ( ! empty( $includedPostStatuses ) ) {
			$query->whereRaw( self::postOnly( 'p.post_status IN (' . self::quoteList( $includedPostStatuses ) . ')' ) );
		}

		if ( ! empty( $includedPostTypes ) ) {
			$query->whereRaw( self::postOnly( 'p.post_type IN (' . self::quoteList( $includedPostTypes ) . ')' ) );
		}

		if ( ! empty( $excludedPostIds ) ) {
			$query->whereRaw( self::postOnly( 'p.ID NOT IN (' . self::quoteList( $excludedPostIds ) . ')' ) );
		}

		// Excluded domains are not filtered here: a link on one is never stored, so there is nothing
		// to filter out. See {@see \AIOSEO\BrokenLinkChecker\Links\Data::storeLinks()}.
		return self::scopeToEnabledSources( $query, 'al.object_type' );
	}

	/**
	 * Narrows the given query to the sources the report covers.
	 *
	 * The rows of a source that was switched off are kept rather than deleted, so every read standing in
	 * for the report has to leave them out: a location the report doesn't list is not one a write may
	 * reach either.
	 *
	 * NOTE: The disabled sources are named rather than the enabled ones filtered to, so rows left behind
	 * by an object type nothing registers any more stay visible instead of silently dropping out.
	 *
	 * @since 1.3.1
	 *
	 * @param  Database $query  The query.
	 * @param  string   $column The `object_type` column, qualified as the query needs.
	 * @return Database         The query.
	 */
	public static function scopeToEnabledSources( $query, $column = 'object_type' ) {
		// Until the columns land there is nothing to name them in a WHERE, and no source has rows of its
		// own to leave out yet either.
		if ( ! self::hasObjectColumns() ) {
			return $query;
		}

		// An allowlist rather than a blocklist: a slug nothing registers any more — a page builder that
		// has since been uninstalled — is not in disabledSlugs() either, and its rows would be reported
		// forever with nothing left to revisit or fix them.
		$enabledSlugs = array_keys( aioseoBrokenLinkChecker()->objects->enabled() );

		// The column defaults to the post shape, so a row that names no type is a post row.
		$enabledSlugs[] = '';

		$query->whereIn( $column, $enabledSlugs );

		return $query;
	}

	/**
	 * Wraps a condition so it only applies to the rows a post is behind.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Covers every post-backed kind rather than post content alone.
	 *
	 * @param  string $condition The condition.
	 * @return string            The wrapped condition.
	 */
	private static function postOnly( $condition ) {
		return '( NOT ' . self::postBackedTypes() . " OR ( $condition ) )";
	}

	/**
	 * The SQL that matches the rows whose object is a post the report covers.
	 *
	 * NOTE: A menu item is a post of its own type, and deliberately not one of these — the report's
	 * post-type and post-status rules are about content, and `nav_menu_item` is in neither list.
	 *
	 * @since 1.3.1
	 *
	 * @return string The condition.
	 */
	private static function postBackedTypes() {
		$slugs = [];
		foreach ( aioseoBrokenLinkChecker()->objects->all() as $slug => $objectType ) {
			if ( $objectType->isPostBacked() ) {
				$slugs[] = $slug;
			}
		}

		// Nothing to join against, so the condition matches no row and every rule below it passes.
		return empty( $slugs ) ? '0' : 'al.object_type IN (' . self::quoteList( $slugs ) . ')';
	}

	/**
	 * Escapes and quotes the given values as a SQL list.
	 *
	 * @since 1.3.1
	 *
	 * @param  array  $values The values.
	 * @return string         The list.
	 */
	private static function quoteList( $values ) {
		return implode( ',', array_map( function( $value ) {
			return is_int( $value ) ? (string) $value : "'" . esc_sql( $value ) . "'";
		}, array_values( $values ) ) );
	}

	/**
	 * Deletes the stored links whose URL is excluded by the patterns now in effect.
	 *
	 * The text patterns go to SQL. A regular expression cannot, so those are matched against the
	 * distinct URLs in the status table — one row per URL rather than one per occurrence — and the
	 * links of whatever matches are deleted by status ID.
	 *
	 * NOTE: Takes the patterns rather than reading the option, so that the settings save can call it
	 * with the list it is about to store instead of the one still in the database.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Host patterns are matched against the stored hostname, as domains were.
	 *
	 * @param  array $patterns The patterns, as {@see \AIOSEO\BrokenLinkChecker\Utils\Helpers::splitUrlPatterns()} returns them.
	 * @param  array $domains  Hosts to match on top of the pattern list's own, for the migration off the old setting.
	 * @return int             The number of link rows deleted.
	 */
	public static function deleteExcluded( $patterns, $domains = [] ) {
		$db       = aioseoBrokenLinkChecker()->core->db;
		$statuses = [];

		// Up front rather than beside each delete below: this runs when the exclusions are saved, so
		// clearing once either way costs nothing and no return path can forget it.
		LinkStatus::flushCounts();

		// The hostname is on the link row itself, so these go by link rather than by status: one URL's
		// host is the same everywhere it appears.
		$hosts = array_unique( array_merge(
			isset( $patterns['host'] ) ? $patterns['host'] : [],
			array_map( [ aioseoBrokenLinkChecker()->helpers, 'normalizeHost' ], (array) $domains )
		) );

		$deletedByDomain = 0;
		if ( ! empty( $hosts ) ) {
			foreach ( array_chunk( array_values( array_filter( $hosts ) ), 200 ) as $chunk ) {
				$db->delete( 'aioseo_blc_links' )
					->whereIn( 'hostname', $chunk )
					->run();

				$deletedByDomain += (int) $db->rowsAffected();
			}
		}

		foreach ( ( isset( $patterns['like'] ) ? $patterns['like'] : [] ) as $pattern ) {
			$rows = $db->start( 'aioseo_blc_link_status' )
				->select( 'id' )
				->whereRaw( "url LIKE '" . esc_sql( $pattern ) . "'" )
				->run()
				->result();

			foreach ( $rows as $row ) {
				$statuses[] = (int) $row->id;
			}
		}

		$regexes = isset( $patterns['regex'] ) ? $patterns['regex'] : [];
		if ( ! empty( $regexes ) ) {
			$rows = $db->start( 'aioseo_blc_link_status' )
				->select( 'id, url' )
				->run()
				->result();

			foreach ( $rows as $row ) {
				foreach ( $regexes as $regex ) {
					if ( 1 === preg_match( $regex, (string) $row->url ) ) {
						$statuses[] = (int) $row->id;

						break;
					}
				}
			}
		}

		$statuses = array_values( array_unique( array_map( 'intval', $statuses ) ) );
		if ( empty( $statuses ) ) {
			return $deletedByDomain;
		}

		$deleted = $deletedByDomain;
		foreach ( array_chunk( $statuses, 500 ) as $chunk ) {
			$db->delete( 'aioseo_blc_links' )
				->whereIn( 'blc_link_status_id', $chunk )
				->run();

			$deleted += (int) $db->rowsAffected();
		}

		return $deleted;
	}

	/**
	 * Narrows a where clause to one source, ignoring a source that is not registered.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $whereClause The clause so far.
	 * @param  string $source      The object type to narrow to.
	 * @return string              The clause, narrowed if the source was one we know.
	 */
	public static function addSourceClause( $whereClause, $source ) {
		$source = (string) $source;
		if ( '' === $source || ! isset( aioseoBrokenLinkChecker()->objects->all()[ $source ] ) ) {
			return $whereClause;
		}

		$clause = "al.object_type = '" . esc_sql( $source ) . "'";

		return $whereClause ? "( $whereClause ) AND $clause" : $clause;
	}

	/**
	 * Narrows a where clause to one kind of link, ignoring a kind we do not know.
	 *
	 * The media kinds are exact rather than guessed from the URL: the flags say where the URL was
	 * found, so a CDN address with no extension is still filed correctly and a link that merely
	 * points at a .jpg is not.
	 *
	 * NOTE: These are two facets sharing one control — what the link points at, and whether it
	 * leaves the site — so only one can be applied at a time. Splitting them into two selects would
	 * let both be, at the cost of a third dropdown.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Renamed from addMediaClause(); also narrows by whether the link is external.
	 * @version 1.3.1 The media filters ask about the URL rather than one of its rows.
	 *
	 * @param  string $type        The kind of link to narrow to.
	 * @param  string $whereClause The clause so far.
	 * @return string              The clause, narrowed if the kind was one we know.
	 */
	public static function addTypeClause( $whereClause, $type ) {
		// The report lists one row per URL, so "is this an image" has to be asked of the URL rather than
		// of a single row. Every row for a URL shares its link status, which is what these correlate on —
		// and the column is indexed, so it stays a lookup rather than a scan.
		$usedAs = function ( $column ) {
			$table = aioseoBrokenLinkChecker()->core->db->db->prefix . 'aioseo_blc_links';

			return "EXISTS ( SELECT 1 FROM `{$table}` lm WHERE lm.blc_link_status_id = al.blc_link_status_id AND lm.{$column} = 1 )";
		};

		$isImage = $usedAs( 'is_image' );
		$isVideo = $usedAs( 'is_video' );

		// Video first, being the narrower claim: a URL embedded as a video is a video however else it is
		// used. Without an order, a URL used two ways satisfied two filters that are meant to divide the
		// report between them, and the three counts came to more than All.
		$clauses = [
			'images'    => "( $isImage AND NOT $isVideo )",
			'videos'    => $isVideo,
			'non-media' => "( NOT $isImage AND NOT $isVideo )",
			'internal'  => 'al.external = 0',
			'external'  => 'al.external = 1'
		];

		$type = (string) $type;
		if ( ! isset( $clauses[ $type ] ) ) {
			return $whereClause;
		}

		return $whereClause ? "( $whereClause ) AND {$clauses[ $type ]}" : $clauses[ $type ];
	}

	/**
	 * Returns what a requested search term actually narrows by.
	 *
	 * NOTE: The term only ever reaches SQL through esc_like() and esc_sql() inside a LIKE, so it does not
	 * need tag stripping — and sanitize_text_field(), which is for output, emptied '<script>x</script>'
	 * to nothing. An emptied term then read as "no search" and listed the whole report.
	 *
	 * @since 1.3.1
	 *
	 * @param  mixed       $searchTerm The requested term.
	 * @return string|null             The term, or null when nothing was searched for.
	 */
	public static function normalizeSearchTerm( $searchTerm ) {
		if ( null === $searchTerm || ! is_scalar( $searchTerm ) ) {
			return null;
		}

		// The string the front end sends for an absent value.
		$searchTerm = trim( (string) $searchTerm );
		if ( '' === $searchTerm || 'null' === $searchTerm ) {
			return null;
		}

		// Five LIKEs against a term of any length is a query nobody needs to be able to ask for.
		return function_exists( 'mb_substr' ) ? mb_substr( $searchTerm, 0, 200 ) : substr( $searchTerm, 0, 200 );
	}

	/**
	 * Get a WHERE clause for the Broken Links report search term.
	 *
	 * NOTE: Post titles and slugs are matched in SQL because they are in the join. A term name, a
	 * display name or a menu item label is not, so searching for one only matches when it also
	 * appears in the anchor text.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Moved from Vue.php to Link model.
	 * @version 1.3.1 Matches the object ID and subtype as well.
	 * @version 1.3.1 Escapes the term for LIKE, so its own wildcards match themselves.
	 * @version 1.3.1 A term that narrows to nothing matches no rows rather than every row.
	 *
	 * @param  string $searchTerm The search term.
	 * @return string             The search where clause.
	 */
	public static function getLinkWhereClause( $searchTerm ) {
		$searchTerm = self::normalizeSearchTerm( $searchTerm );

		// Nothing was searched for, so nothing is narrowed. Strict, because '0' is a search and PHP calls
		// the string falsy — which returned no clause at all and listed the whole report.
		if ( null === $searchTerm ) {
			return '';
		}

		$objectId = (int) $searchTerm;

		// esc_like() before esc_sql(): the _ in a slug and the % in an encoded URL are common enough
		// that leaving them as wildcards makes a search for one match rows that do not contain it.
		$searchTerm = esc_sql( aioseoBrokenLinkChecker()->core->db->db->esc_like( $searchTerm ) );
		if ( '' === $searchTerm ) {
			// A term that survived normalising but escapes to nothing was still a search, and no row
			// matches it. Returning no clause would hand back every row instead.
			return '( 1 = 0 )';
		}

		$where = '';
		if ( $objectId ) {
			$where .= '
				al.object_id = ' . $objectId . ' OR
			';
		}

		$where .= "
			al.url LIKE '%" . $searchTerm . "%' OR
			al.anchor LIKE '%" . $searchTerm . "%' OR
			al.object_subtype LIKE '%" . $searchTerm . "%' OR
			p.post_title LIKE '%" . $searchTerm . "%' OR
			p.post_name LIKE '%" . $searchTerm . "%'
		";

		return "( $where )";
	}

	/**
	 * Extract the keys from the result and add them to the model.
	 *
	 * @since 1.2.1
	 *
	 * @param  array $keys The list of keys and values to add to the model.
	 * @return void
	 */
	protected function applyKeys( $keys ) {
		try {
			parent::applyKeys( $keys );

			foreach ( (array) $keys as $key => $value ) {
				if ( ! property_exists( $this, $key ) ) {
					continue;
				}

				if ( 'url' === $key && is_string( $this->$key ) ) {
					// Only when the decoded form is text. A URL percent-encoded as anything but UTF-8, or
					// one whose escape was cut in half, decodes to bytes that are not a string in any
					// encoding - and those reach the report as replacement characters.
					$decoded = rawurldecode( $this->$key );

					if ( '' !== wp_check_invalid_utf8( $decoded ) ) {
						$this->$key = $decoded;
					}
				}
			}
		} catch ( \Exception $e ) {
			// Do nothing.
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

		// The counts group over this table, so a link changing changes them.
		LinkStatus::flushCounts();
	}
}