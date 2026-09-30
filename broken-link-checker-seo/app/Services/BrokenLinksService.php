<?php
namespace AIOSEO\BrokenLinkChecker\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Api;
use AIOSEO\BrokenLinkChecker\Models;
use AIOSEO\BrokenLinkChecker\Objects\ObjectType;

/**
 * Service for reading and acting on the links the Broken Links Report covers.
 *
 * Every method re-checks the capability the matching REST route enforces, and post context is
 * withheld for posts the caller can't edit, exactly as the report and the CSV export do.
 *
 * @internal Not a public extension surface.
 *
 * @since 1.3.1
 */
class BrokenLinksService extends Service {
	/**
	 * The filters the report offers.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const FILTERS = [ 'all', 'broken', 'redirects', 'good', 'not-checked', 'dismissed' ];

	/**
	 * The columns callers may order the list by, mapped to their qualified column name.
	 *
	 * @since 1.3.1
	 *
	 * @var array<string, string>
	 */
	const ORDER_COLUMNS = [
		'id'               => 'als.id',
		'url'              => 'als.url',
		'last_checked'     => 'als.last_scan_date',
		'http_status_code' => 'als.http_status_code',
		'redirect_count'   => 'als.redirect_count'
	];

	/**
	 * The maximum number of links one recheck call may cover.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const MAX_RECHECK = 50;

	/**
	 * Returns a page of the Broken Links Report.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function listLinks( $input ) {
		$denied = $this->checkAccess();
		if ( $denied ) {
			return $denied;
		}

		$input  = is_array( $input ) ? $input : [];
		$filter = isset( $input['filter'] ) && in_array( $input['filter'], self::FILTERS, true ) ? $input['filter'] : 'all';
		$limit  = isset( $input['limit'] ) ? min( 100, max( 1, (int) $input['limit'] ) ) : 20;
		$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$search = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';

		$orderKey = isset( $input['orderBy'] ) ? (string) $input['orderBy'] : 'id';
		$orderBy  = isset( self::ORDER_COLUMNS[ $orderKey ] ) ? self::ORDER_COLUMNS[ $orderKey ] : self::ORDER_COLUMNS['id'];
		$orderDir = isset( $input['orderDir'] ) && 'asc' === strtolower( (string) $input['orderDir'] ) ? 'ASC' : 'DESC';

		// Ignored rather than rejected when it names something that is not a registered source, which
		// keeps a caller working when a source it knew about is filtered out of the registry.
		$source = isset( $input['source'] ) ? sanitize_text_field( (string) $input['source'] ) : '';
		$source = isset( aioseoBrokenLinkChecker()->objects->all()[ $source ] ) ? $source : '';

		$whereClause = Models\Link::getLinkWhereClause( $search );
		$whereClause = Models\Link::addSourceClause( $whereClause, $source );
		$rows        = Models\LinkStatus::rowQuery( $filter, $limit, $offset, $whereClause, $orderBy, $orderDir );
		$total       = (int) Models\LinkStatus::rowCountQuery( $filter, $whereClause );

		$links = [];
		foreach ( $rows as $row ) {
			$links[] = $this->linkStatusRow( $row, [
				'external'          => ! empty( $row->external ),
				'is_video'          => ! empty( $row->is_video ),
				'total_occurrences' => isset( $row->totalLinks ) ? (int) $row->totalLinks : 0,
				'object_count'      => isset( $row->distinctObjects ) ? (int) $row->distinctObjects : 0,
				'object_types'      => isset( $row->objectTypes ) ? array_values( (array) $row->objectTypes ) : [],
				// The name from before links could be found anywhere but a post.
				'post_count'        => isset( $row->distinctObjects ) ? (int) $row->distinctObjects : 0,
				'location'          => empty( $row->link ) ? null : $this->occurrence( $row->link ),
				'post'              => empty( $row->link ) ? null : $this->occurrence( $row->link )
			] );
		}

		return [
			'links'  => $links,
			'total'  => $total,
			'page'   => 0 === $offset ? 1 : (int) floor( $offset / $limit ) + 1,
			'pages'  => (int) ceil( $total / $limit ),
			'filter' => $filter,
			'source' => $source
		];
	}

	/**
	 * Returns a single link plus the posts it was found in.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function getLink( $input ) {
		$denied = $this->checkAccess();
		if ( $denied ) {
			return $denied;
		}

		$input        = is_array( $input ) ? $input : [];
		$linkStatusId = isset( $input['linkStatusId'] ) ? (int) $input['linkStatusId'] : 0;
		if ( 1 > $linkStatusId ) {
			return $this->invalidLinkStatusId();
		}

		$linkStatus = Models\LinkStatus::getById( $linkStatusId );
		if ( ! $linkStatus->exists() ) {
			return $this->linkStatusNotFound();
		}

		$limit  = isset( $input['limit'] ) ? min( 100, max( 1, (int) $input['limit'] ) ) : 20;
		$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$counts = Models\Link::rowQueryCounts( $linkStatusId );
		$flags  = $this->getLinkFlags( $linkStatusId );

		$occurrences = [];
		foreach ( Models\Link::rowQuery( $linkStatusId, $limit, $offset ) as $row ) {
			$occurrences[] = $this->occurrence( $row );
		}

		return [
			'link'              => $this->linkStatusRow( $linkStatus, [
				'external'          => $flags['external'],
				'is_video'          => $flags['is_video'],
				'total_occurrences' => $counts['total'],
				'object_count'      => $counts['distinctObjects'],
				'object_types'      => $counts['objectTypes'],
				// The name from before links could be found anywhere but a post.
				'post_count'        => $counts['distinctObjects']
			] ),
			'occurrences'       => $occurrences,
			'occurrences_total' => $counts['total']
		];
	}

	/**
	 * Returns how many links fall into each of the report's filters.
	 *
	 * @since 1.3.1
	 *
	 * @return array|\WP_Error
	 */
	public function getSummary() {
		$denied = $this->checkAccess();
		if ( $denied ) {
			return $denied;
		}

		return [
			'counts'      => Models\LinkStatus::getFilterTotals(),
			'sources'     => Models\Link::getObjectTypeTotals(),
			'total_links' => (int) aioseoBrokenLinkChecker()->main->linkStatus->data->getTotalLinks()
		];
	}

	/**
	 * Rewrites a link's URL, and optionally its anchor text, in the post content it was found in.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function updateLink( $input ) {
		$denied = $this->checkAccess();
		if ( $denied ) {
			return $denied;
		}

		$input        = is_array( $input ) ? $input : [];
		$linkStatusId = isset( $input['linkStatusId'] ) ? (int) $input['linkStatusId'] : 0;
		$linkId       = isset( $input['linkId'] ) ? (int) $input['linkId'] : 0;
		$anchor       = isset( $input['anchor'] ) ? sanitize_text_field( (string) $input['anchor'] ) : '';

		$requestedUrl = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
		$url          = aioseoBrokenLinkChecker()->helpers->sanitizeLinkUrl( $requestedUrl );

		if ( '' !== $requestedUrl && '' === $url ) {
			return new \WP_Error(
				'blc_invalid_url',
				__( 'That is not a URL a link can point at, so nothing was changed.', 'broken-link-checker-seo' ),
				[ 'status' => 400 ]
			);
		}

		if ( '' === $url && '' === $anchor ) {
			return new \WP_Error(
				'blc_nothing_to_update',
				__( 'Provide a new URL, a new anchor text, or both.', 'broken-link-checker-seo' ),
				[ 'status' => 400 ]
			);
		}

		// The anchor belongs to one occurrence of the link, so there is no single anchor to rewrite
		// when the call covers every occurrence.
		if ( '' !== $anchor && 1 > $linkId ) {
			return new \WP_Error(
				'blc_anchor_requires_link_id',
				__( 'Anchor text can only be changed for a single occurrence. Pass linkId as well.', 'broken-link-checker-seo' ),
				[ 'status' => 400 ]
			);
		}

		$targets = $this->getTargets( $input );
		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		$outcome = $this->applyToTargets(
			$targets,
			$linkStatusId,
			function( $id ) use ( $anchor, $url ) {
				return Api\CommonTableActions::updateLink( $id, $anchor, $url );
			},
			function( $link, $content ) use ( $anchor, $url ) {
				return $this->rewriteSatisfied( $content, $url, $anchor );
			}
		);

		return [
			'attempted'       => count( $targets['links'] ),
			'updated'         => $outcome['changed'],
			'unchanged'       => $outcome['unchanged'],
			'failed'          => $outcome['failed'],
			'orphans_removed' => $targets['orphans'],
			'url'             => '' === $url ? null : $url,
			'anchor'          => '' === $anchor ? null : $anchor,
			'results'         => $outcome['results']
		];
	}

	/**
	 * Removes the link from the post content it was found in, leaving its anchor text behind.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function unlinkLink( $input ) {
		$denied = $this->checkAccess();
		if ( $denied ) {
			return $denied;
		}

		$input        = is_array( $input ) ? $input : [];
		$linkStatusId = isset( $input['linkStatusId'] ) ? (int) $input['linkStatusId'] : 0;

		$targets = $this->getTargets( array_merge( $input, [ 'requiredAction' => ObjectType::UNLINK ] ) );
		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		$outcome = $this->applyToTargets(
			$targets,
			$linkStatusId,
			function( $id ) {
				return Api\CommonTableActions::removeLink( $id );
			},
			function( $link, $content ) {
				return ! $this->urlInContent( $link->url, $content );
			}
		);

		return [
			'attempted'       => count( $targets['links'] ),
			'unlinked'        => $outcome['changed'],
			'unchanged'       => $outcome['unchanged'],
			'failed'          => $outcome['failed'],
			'orphans_removed' => $targets['orphans'],
			'results'         => $outcome['results']
		];
	}

	/**
	 * Dismisses or restores a link, which is what takes it in and out of the report's counts.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function setDismissed( $input ) {
		$denied = $this->checkAccess();
		if ( $denied ) {
			return $denied;
		}

		$input        = is_array( $input ) ? $input : [];
		$linkStatusId = isset( $input['linkStatusId'] ) ? (int) $input['linkStatusId'] : 0;
		if ( 1 > $linkStatusId ) {
			return $this->invalidLinkStatusId();
		}

		$dismissed  = ! isset( $input['dismissed'] ) || (bool) $input['dismissed'];
		$linkStatus = Models\LinkStatus::getById( $linkStatusId );
		if ( ! $linkStatus->exists() ) {
			return $this->linkStatusNotFound();
		}

		$changed = (bool) $linkStatus->dismissed !== $dismissed;
		if ( $changed ) {
			Api\CommonTableActions::setLinkStatusDismissed( $linkStatusId, $dismissed );
		}

		return [
			'id'        => $linkStatusId,
			'dismissed' => $dismissed,
			'changed'   => $changed
		];
	}

	/**
	 * Checks the given links again through the Broken Link Checker service.
	 *
	 * NOTE: Every URL checked spends the site's quota, so repeating a call is not free.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function recheck( $input ) {
		$denied = $this->checkAccess();
		if ( $denied ) {
			return $denied;
		}

		$denied = $this->checkLicense();
		if ( $denied ) {
			return $denied;
		}

		$input = is_array( $input ) ? $input : [];
		$ids   = isset( $input['linkStatusIds'] ) && is_array( $input['linkStatusIds'] ) ? $input['linkStatusIds'] : [];
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return new \WP_Error(
				'blc_invalid_link_status_ids',
				__( 'Provide at least one link ID to check.', 'broken-link-checker-seo' ),
				[ 'status' => 400 ]
			);
		}

		if ( self::MAX_RECHECK < count( $ids ) ) {
			return new \WP_Error(
				'blc_too_many_link_status_ids',
				sprintf(
					// Translators: 1 - The maximum number of links.
					__( 'A single check can cover at most %1$s links.', 'broken-link-checker-seo' ),
					number_format_i18n( self::MAX_RECHECK )
				),
				[ 'status' => 400 ]
			);
		}

		$rows = [];
		foreach ( Models\LinkStatus::getByIds( $ids ) as $linkStatus ) {
			$rows[] = [ 'id' => (int) $linkStatus->id ];
		}

		if ( empty( $rows ) ) {
			return $this->linkStatusNotFound();
		}

		$responseBody = Api\CommonTableActions::recheckLinks( $rows );

		// Passed through rather than replaced: the service said why, and that reason is the whole value
		// of the answer to a caller that cannot see the report.
		if ( is_wp_error( $responseBody ) ) {
			return $responseBody;
		}

		if ( ! is_object( $responseBody ) || empty( $responseBody->rows ) ) {
			return new \WP_Error(
				'blc_recheck_failed',
				__( 'The links could not be checked. The Broken Link Checker service did not return a result.', 'broken-link-checker-seo' ),
				[ 'status' => 502 ]
			);
		}

		Api\CommonTableActions::applyRecheckQuota( $responseBody );

		$links = [];
		foreach ( Models\LinkStatus::getByIds( array_column( $rows, 'id' ) ) as $linkStatus ) {
			$links[] = $this->linkStatusRow( $linkStatus );
		}

		return [
			'requested'       => count( $ids ),
			'checked'         => count( (array) $responseBody->rows ),
			'quota_remaining' => (int) aioseoBrokenLinkChecker()->internalOptions->internal->license->quotaRemaining,
			'links'           => $links
		];
	}

	/**
	 * Resolves the links a mutation covers, rejecting the whole call if any of their posts is off limits.
	 *
	 * NOTE: The permission scan runs to completion before anything is written, so a caller who may
	 * edit only some of the posts a link appears in changes none of them.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error The resolved links and how many orphaned records were dropped.
	 */
	private function getTargets( $input ) {
		$linkStatusId = isset( $input['linkStatusId'] ) ? (int) $input['linkStatusId'] : 0;
		$linkId       = isset( $input['linkId'] ) ? (int) $input['linkId'] : 0;
		if ( 1 > $linkStatusId ) {
			return $this->invalidLinkStatusId();
		}

		$linkStatus = Models\LinkStatus::getById( $linkStatusId );
		if ( ! $linkStatus->exists() ) {
			return $this->linkStatusNotFound();
		}

		$links = Models\Link::getByLinkStatusId( $linkStatusId );
		if ( $linkId ) {
			$links = array_values( array_filter( $links, function( $link ) use ( $linkId ) {
				return (int) $link->id === $linkId;
			} ) );

			if ( empty( $links ) ) {
				return new \WP_Error(
					'blc_link_not_found',
					__( 'That occurrence does not belong to the given link.', 'broken-link-checker-seo' ),
					[ 'status' => 404 ]
				);
			}
		}

		$requiredAction = isset( $input['requiredAction'] ) ? (string) $input['requiredAction'] : '';

		$orphans  = [];
		$editable = [];
		foreach ( $links as $link ) {
			$type = aioseoBrokenLinkChecker()->objects->get( $link->object_type );

			if ( ! $type->exists( $link->object_id, $link->object_subtype ) ) {
				$orphans[] = $link;

				continue;
			}

			if ( $requiredAction && ! $type->supports( $requiredAction, $link->object_id, $link->object_subtype ) ) {
				return new \WP_Error(
					'blc_action_unsupported',
					sprintf(
						// Translators: 1 - The name of a source, e.g. "Navigation Menus".
						__( 'This link was found in %1$s, where that is not something this call can do. Nothing was changed.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
						$type->sourceLabel()
					),
					[ 'status' => 409 ]
				);
			}

			// Each kind has its own edit capability, and one it fails takes the whole call with it.
			if ( ! $type->canEdit( $link->object_id, $link->object_subtype ) ) {
				return new \WP_Error(
					'blc_cannot_edit_object',
					__( 'You cannot edit everything this link appears in, so nothing was changed.', 'broken-link-checker-seo' ),
					[ 'status' => 403 ]
				);
			}

			$editable[] = $link;
		}

		// Mirrors the REST routes: a link whose object is gone is a stale record, not content to rewrite.
		foreach ( $orphans as $orphan ) {
			$orphan->delete();
		}

		if ( empty( $editable ) ) {
			return new \WP_Error(
				'blc_no_occurrences',
				__( 'This link is no longer anywhere on the site, so there was nothing to change.', 'broken-link-checker-seo' ),
				[ 'status' => 409 ]
			);
		}

		return [
			'links'   => $editable,
			'orphans' => count( $orphans ),
			'pinned'  => (bool) $linkId
		];
	}

	/**
	 * Runs the given write against every target and reports what each one actually changed.
	 *
	 * NOTE: Writing to a post reindexes it, which replaces every link record it holds. So each target
	 * is re-resolved right before its own write, and a write is only counted once the post content it
	 * touched has actually changed.
	 *
	 * @since 1.3.1
	 *
	 * @param  array    $targets      The targets from {@see self::getTargets()}.
	 * @param  int      $linkStatusId The link status the targets belong to.
	 * @param  callable $write        Receives a link ID and performs the write.
	 * @param  callable $satisfied    Receives a record and the content, returns whether the content
	 *                                already holds what the call asked for.
	 * @return array                  The counts and the per-occurrence results.
	 */
	private function applyToTargets( $targets, $linkStatusId, $write, $satisfied ) {
		$results = [];
		$counts  = [
			'changed'   => 0,
			'unchanged' => 0,
			'failed'    => 0
		];

		$handled     = [];
		$rewrittenIn = [];
		$pinned      = ! empty( $targets['pinned'] );

		foreach ( $targets['links'] as $target ) {
			$objectKey = $target->object_type . ':' . (int) $target->object_id;
			$link      = $pinned ? $this->existingLink( $target ) : $this->resolveTarget( $target, $linkStatusId, $handled );

			if ( ! $link ) {
				// One rewrite covers every identical occurrence in the content it matched, so an earlier
				// write to the same object is what took this one.
				$reason = in_array( $objectKey, $rewrittenIn, true ) ? 'already_covered' : 'stale_record';

				$counts[ 'already_covered' === $reason ? 'unchanged' : 'failed' ]++;

				$results[] = array_merge( $this->resultLocation( $target ), [
					'updated' => false,
					'reason'  => $reason
				] );

				continue;
			}

			$handled[] = (int) $link->id;

			$before  = $this->objectContent( $link );
			$outcome = call_user_func( $write, (int) $link->id );

			// A typed refusal is the writer telling us this kind of object cannot do what was asked.
			if ( is_wp_error( $outcome ) ) {
				$counts['failed']++;

				$results[] = array_merge( $this->resultLocation( $link ), [
					'updated' => false,
					'reason'  => 'unsupported'
				] );

				continue;
			}

			$changed = $before !== $this->objectContent( $link );

			// The two writers disagree on what they return for a write that does nothing, so the content
			// decides: either it already holds what was asked for, or the record no longer describes it.
			$reason = $changed ? null : ( call_user_func( $satisfied, $link, $before ) ? 'no_change' : 'no_match' );

			if ( null === $reason ) {
				$counts['changed']++;
				$rewrittenIn[] = $objectKey;
			} else {
				$counts[ 'no_change' === $reason ? 'unchanged' : 'failed' ]++;
			}

			$results[] = array_merge( $this->resultLocation( $link ), [
				'updated' => $changed,
				'reason'  => $reason
			] );
		}

		return array_merge( $counts, [ 'results' => $results ] );
	}

	/**
	 * Returns whether the content already holds the URL and anchor text a rewrite asked for.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $content The post content.
	 * @param  string $url     The requested URL, or an empty string.
	 * @param  string $anchor  The requested anchor text, or an empty string.
	 * @return bool            Whether the content already reads as requested.
	 */
	private function rewriteSatisfied( $content, $url, $anchor ) {
		if ( '' !== $url && ! $this->urlInContent( $url, $content ) ) {
			return false;
		}

		if ( '' === $anchor ) {
			return '' !== $url;
		}

		return false !== strpos( html_entity_decode( $content, ENT_QUOTES, 'UTF-8' ), $anchor );
	}

	/**
	 * Returns whether the given URL is in the content, in either form it may take there.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url     The URL.
	 * @param  string $content The post content.
	 * @return bool            Whether the URL is there.
	 */
	private function urlInContent( $url, $content ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}

		// An href the HTML API wrote holds entity-encoded ampersands, which no stored URL has.
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		if ( false !== strpos( $content, $url ) ) {
			return true;
		}

		// Stored URLs are made absolute on the way in, so the content may hold the relative form.
		$parsedUrl = wp_parse_url( $url );
		if ( empty( $parsedUrl['path'] ) ) {
			return false;
		}

		$relative = $parsedUrl['path'] . ( empty( $parsedUrl['query'] ) ? '' : '?' . $parsedUrl['query'] );

		return false !== strpos( $content, $relative );
	}

	/**
	 * Returns the link record when it is still there, and nothing when it isn't.
	 *
	 * @since 1.3.1
	 *
	 * @param  object      $target The target resolved before any write ran.
	 * @return object|null         The record.
	 */
	private function existingLink( $target ) {
		$link = Models\Link::getById( (int) $target->id );

		return $link->exists() ? $link : null;
	}

	/**
	 * Returns the link record to write to, which is a new one once its post has been reindexed.
	 *
	 * NOTE: Never used for a caller-named occurrence — the replacement would be a different one, and
	 * rewriting the wrong occurrence's anchor text is worse than reporting the record as stale.
	 *
	 * @since 1.3.1
	 *
	 * @param  object     $target       The target resolved before any write ran.
	 * @param  int        $linkStatusId The link status the target belongs to.
	 * @param  int[]      $handled      The link IDs already written to.
	 * @return object|null              The record, or null when the target no longer resolves.
	 */
	private function resolveTarget( $target, $linkStatusId, $handled ) {
		$link = $this->existingLink( $target );
		if ( $link ) {
			return $link;
		}

		// The reindex keeps the link status of every occurrence that still holds the old URL, so those
		// rows are what is left to rewrite in this object.
		$links = Models\Link::getByLinkStatusIdAndObject(
			$linkStatusId,
			(string) $target->object_type,
			(int) $target->object_id,
			$handled
		);

		return empty( $links ) ? null : reset( $links );
	}

	/**
	 * Returns the content behind the given record, which is how a rewrite is told apart from a no-op.
	 *
	 * NOTE: For an object whose URL is its value there is no content, so the URL stands in for it.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Renamed from postContent(); reads through the object type.
	 *
	 * @param  object $link The link record.
	 * @return string       The content.
	 */
	private function objectContent( $link ) {
		$type     = aioseoBrokenLinkChecker()->objects->get( $link->object_type );
		$objectId = (int) $link->object_id;
		$subtype  = (string) $link->object_subtype;

		return $type->isRichText( $objectId, $subtype )
			? (string) $type->getContent( $objectId, $subtype )
			: (string) $type->getUrl( $objectId, $subtype );
	}

	/**
	 * The location fields every per-occurrence result carries.
	 *
	 * @since 1.3.1
	 *
	 * @param  object $link The link record.
	 * @return array        The fields.
	 */
	private function resultLocation( $link ) {
		return [
			'link_id'     => (int) $link->id,
			'object_type' => (string) $link->object_type,
			'object_id'   => (int) $link->object_id,
			// The name from before links could be found anywhere but a post.
			'post_id'     => (int) $link->post_id
		];
	}

	/**
	 * Builds the agent-facing shape of a link status row.
	 *
	 * @since 1.3.1
	 *
	 * @param  object $row   A link status row or model.
	 * @param  array  $extra Fields the caller resolved itself.
	 * @return array         The row.
	 */
	private function linkStatusRow( $row, $extra = [] ) {
		// The model casts the column's NULL to 0, which isn't a status code any link ever returned.
		$httpStatusCode = empty( $row->http_status_code ) ? null : (int) $row->http_status_code;

		return array_merge( [
			'id'               => (int) $row->id,
			'url'              => (string) $row->url,
			'status'           => $this->getStatus( $row ),
			'http_status_code' => $httpStatusCode,
			'dismissed'        => ! empty( $row->dismissed ),
			'redirect_count'   => isset( $row->redirect_count ) ? (int) $row->redirect_count : 0,
			'final_url'        => empty( $row->final_url ) ? null : (string) $row->final_url,
			'scan_count'       => isset( $row->scan_count ) ? (int) $row->scan_count : 0,
			'last_checked'     => $this->normalizeDate( isset( $row->last_scan_date ) ? $row->last_scan_date : null ),
			'first_failure'    => $this->normalizeDate( isset( $row->first_failure ) ? $row->first_failure : null ),
			'last_error'       => $this->getLastError( isset( $row->log ) ? $row->log : null )
		], $extra );
	}

	/**
	 * Builds the agent-facing shape of one occurrence of a link.
	 *
	 * @since 1.3.1
	 *
	 * @param  object $link A link row from {@see Models\Link::rowQuery()}.
	 * @return array        The occurrence.
	 */
	private function occurrence( $link ) {
		$context = isset( $link->context ) && is_array( $link->context ) ? $link->context : [];

		return [
			'link_id'           => (int) $link->id,
			'object_type'       => isset( $context['objectType'] ) ? (string) $context['objectType'] : 'post',
			'object_id'         => isset( $context['objectId'] ) ? (int) $context['objectId'] : 0,
			'object_subtype'    => isset( $context['objectSubtype'] ) ? (string) $context['objectSubtype'] : '',
			'object_label'      => isset( $context['objectLabel'] ) ? (string) $context['objectLabel'] : '',
			'source_label'      => isset( $context['sourceLabel'] ) ? (string) $context['sourceLabel'] : '',
			'location_label'    => isset( $context['locationLabel'] ) ? (string) $context['locationLabel'] : '',
			'available_actions' => isset( $context['actions'] ) ? array_values( (array) $context['actions'] ) : [],
			// The names from before links could be found anywhere but a post.
			'post_id'           => (int) $link->post_id,
			'post_title'        => isset( $context['postTitle'] ) ? (string) $context['postTitle'] : '',
			'post_type'         => isset( $context['postType'] ) ? (string) $context['postType'] : '',
			'permalink'         => empty( $context['permalink'] ) ? null : (string) $context['permalink'],
			'edit_link'         => empty( $context['editLink'] ) ? null : (string) $context['editLink'],
			'anchor'            => isset( $link->anchor ) ? (string) $link->anchor : '',
			'phrase'            => isset( $link->phrase ) ? (string) $link->phrase : '',
			'can_edit'          => ! empty( $context['canEdit'] ),
			'can_delete'        => ! empty( $context['canDelete'] )
		];
	}

	/**
	 * Returns the state the report's status column renders for the given row.
	 *
	 * @since 1.3.1
	 *
	 * @param  object $row A link status row or model.
	 * @return string      One of `pending`, `broken`, `redirect`, `ok`.
	 */
	private function getStatus( $row ) {
		if ( empty( $row->last_scan_date ) || ! empty( $row->needs_additional_scan ) ) {
			return 'pending';
		}

		if ( ! empty( $row->broken ) ) {
			return 'broken';
		}

		if ( ! empty( $row->redirect_count ) ) {
			return 'redirect';
		}

		return 'ok';
	}

	/**
	 * Returns the error the last check recorded, if any.
	 *
	 * NOTE: The column arrives as raw JSON from a row query and as a decoded object from a model.
	 *
	 * @since 1.3.1
	 *
	 * @param  mixed $log The log column.
	 * @return string|null The error.
	 */
	private function getLastError( $log ) {
		if ( is_string( $log ) && '' !== $log ) {
			$log = json_decode( $log );
		}

		if ( ! is_object( $log ) || empty( $log->error ) || ! is_scalar( $log->error ) ) {
			return null;
		}

		return (string) $log->error;
	}

	/**
	 * Returns a datetime column as a string, or null when it holds no date.
	 *
	 * NOTE: `first_failure` isn't one of the model's null fields, so clearing it stores MySQL's zero
	 * date rather than NULL. Reporting that verbatim would hand out a datetime that isn't one.
	 *
	 * @since 1.3.1
	 *
	 * @param  mixed $date The datetime column.
	 * @return string|null The datetime.
	 */
	private function normalizeDate( $date ) {
		if ( empty( $date ) || ! is_scalar( $date ) || 0 === strpos( (string) $date, '0000-00-00' ) ) {
			return null;
		}

		return (string) $date;
	}

	/**
	 * Returns whether the link is external and whether it came from a video.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $linkStatusId The link status ID.
	 * @return array{external: bool, is_video: bool} The flags.
	 */
	private function getLinkFlags( $linkStatusId ) {
		$rows = Models\Link::scopeToEnabledSources(
			aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_links' )
				->select( 'external, is_video' )
				->where( 'blc_link_status_id', $linkStatusId )
		)
			->limit( 1 )
			->run()
			->result();

		$row = empty( $rows[0] ) ? null : $rows[0];

		return [
			'external' => $row ? ! empty( $row->external ) : false,
			'is_video' => $row ? ! empty( $row->is_video ) : false
		];
	}

	/**
	 * Returns the error for a link status ID that isn't usable.
	 *
	 * @since 1.3.1
	 *
	 * @return \WP_Error The error.
	 */
	private function invalidLinkStatusId() {
		return new \WP_Error(
			'blc_invalid_link_status_id',
			__( 'Provide the ID of a link from the Broken Links Report.', 'broken-link-checker-seo' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Returns the error for a link status ID that doesn't exist.
	 *
	 * @since 1.3.1
	 *
	 * @return \WP_Error The error.
	 */
	private function linkStatusNotFound() {
		return new \WP_Error(
			'blc_link_status_not_found',
			__( 'No link with that ID was found.', 'broken-link-checker-seo' ),
			[ 'status' => 404 ]
		);
	}
}