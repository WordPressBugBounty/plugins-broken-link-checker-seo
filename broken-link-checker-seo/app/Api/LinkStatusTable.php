<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles link related routes.
 *
 * @since 1.1.0
 */
class LinkStatusTable extends CommonTableActions {
	/**
	 * Returns the data for the Broken Links Report.
	 *
	 * @since 1.1.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchData( $request ) {
		$body = $request->get_json_params();
		// intval() turns any non-numeric string into 0, which is not a limit and divides the pager by zero.
		$limit      = self::normalizeLimit( isset( $body['limit'] ) ? $body['limit'] : null );
		$offset     = isset( $body['offset'] ) ? max( 0, intval( $body['offset'] ) ) : 0;
		// Passed through as sent: the model normalises it, and stripping tags here emptied a term like
		// '<script>x</script>' into "no search", which listed the whole report.
		$searchTerm = isset( $body['searchTerm'] ) ? $body['searchTerm'] : null;
		$filter     = ! empty( $body['filter'] ) ? sanitize_text_field( $body['filter'] ) : 'all';
		// The table sorts by column slug, which is not always the column's name, so the map is both the
		// translation and the list of what may be sorted by at all.
		$sortable = [
			'url'          => 'url',
			'last-checked' => 'als.last_scan_date',
			'broken-for'   => 'als.first_failure'
		];

		$requestedSort = ! empty( $body['orderBy'] ) ? sanitize_text_field( $body['orderBy'] ) : '';
		$orderBy       = isset( $sortable[ $requestedSort ] ) ? $sortable[ $requestedSort ] : 'id';
		$orderDir      = 'ASC' === strtoupper( (string) ( $body['orderDir'] ?? '' ) ) && isset( $sortable[ $requestedSort ] ) ? 'ASC' : 'DESC';

		// "Broken for" reads as a duration but the column behind it holds a date, and the two run in
		// opposite directions: the longest-broken link is the one with the oldest first failure.
		if ( 'broken-for' === $requestedSort ) {
			$orderDir = 'ASC' === $orderDir ? 'DESC' : 'ASC';

		}
		// Not sanitize_key(), which lowercases: a slug a filter registered can carry capitals, and the
		// value is checked against the registry before it reaches the query.
		$source = ! empty( $body['source'] ) ? sanitize_text_field( $body['source'] ) : '';
		$media  = ! empty( $body['media'] ) ? sanitize_text_field( $body['media'] ) : '';

		return new \WP_REST_Response( [
			'success'      => true,
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'linkStatuses' => aioseoBrokenLinkChecker()->helpers->getLinkStatusesData( $limit, $offset, $searchTerm, $filter, $orderBy, $orderDir, $source, $media )
		], 200 );
	}

	/**
	 * Executes the given bulk action on the given rows.
	 *
	 * @since 1.1.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function bulk( $request ) {
		$body   = $request->get_json_params();
		$action = ! empty( $body['action'] ) ? sanitize_text_field( $body['action'] ) : null;
		// Read as ids up front, so nothing below has to guess at the shape it was sent.
		$rowIds = self::normalizeRowIds( isset( $body['rows'] ) ? $body['rows'] : null );
		if ( empty( $action ) || empty( $rowIds ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No action or rows given.'
			], 400 );
		}

		// Same rule as the single-row paths: a row this caller cannot edit the whole of is dropped from
		// the batch rather than carried into it, and the response says so.
		$allowed = array_values( array_filter( $rowIds, function ( $rowId ) {
			return self::canActOnLinkStatus( $rowId );
		} ) );

		$refused = [];
		$skipped = [];
		if ( count( $allowed ) !== count( $rowIds ) ) {
			$refused['blc_cannot_edit_object'] = __( 'Some of these links appear in things you cannot edit, so those were left alone.', 'broken-link-checker-seo' );
		}

		if ( empty( $allowed ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'code'    => 'blc_cannot_edit_object',
				'message' => __( 'You cannot edit everything these links appear in, so nothing was changed.', 'broken-link-checker-seo' )
			], 403 );
		}

		$rowIds = $allowed;

		switch ( $action ) {
			case 'recheck':
				$rechecked = self::recheckLinks( $rowIds );
				if ( is_wp_error( $rechecked ) ) {
					$refused[ $rechecked->get_error_code() ] = $rechecked->get_error_message();

					break;
				}

				// False means nothing was found to recheck, which is not a response body to read a quota from.
				if ( $rechecked ) {
					self::applyRecheckQuota( $rechecked );
				}
				break;
			case 'dismiss':
				foreach ( $rowIds as $rowId ) {
					self::setLinkStatusDismissed( $rowId );
				}
				break;
			case 'undismiss':
				foreach ( $rowIds as $rowId ) {
					self::setLinkStatusDismissed( $rowId, false );
				}
				break;
			case 'unlink':
				// The same implementation the single-row unlink uses. Calling removeLink() per row applied
				// none of its refusals - an embedded URL has no anchor to unwrap, so the write quietly did
				// nothing and this action reported nothing, while the single-row one named the location.
				foreach ( $rowIds as $rowId ) {
					$skipped = array_merge( $skipped, self::unlinkLinkStatus( (int) $rowId ) );
				}
				break;
			default:
				break;
		}

		return new \WP_REST_Response( [
			'success'          => true,
			'refused'          => array_values( $refused ),
			// The shape the single-row unlink answers with, so the table names the locations it could not
			// rewrite whichever action was used.
			'skipped'          => count( $skipped ),
			'skippedLocations' => $skipped
		], 200 );
	}

	/**
	 * Whether the current user may act on the whole of the given link status row.
	 *
	 * Dismissing, undismissing and rechecking are status-only writes: they change nothing inside a post,
	 * which is why they carried no object check. But the report is one view shared by the whole site, so
	 * they are not private actions - a dismissal hides the URL from everybody, an undismissal un-hides
	 * what somebody else chose to hide, and a recheck spends the licence's quota.
	 *
	 * Every location is tested, not the ones this reader can see, and an administrator short-circuits so
	 * the common case costs no queries. A row naming no object left to edit is administrators' only.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $linkStatusId The link status ID.
	 * @return bool               Whether they may.
	 */
	private static function canActOnLinkStatus( $linkStatusId ) {
		if ( aioseoBrokenLinkChecker()->access->isAdmin() ) {
			return true;
		}

		$links = Models\Link::getByLinkStatusId( (int) $linkStatusId );
		if ( empty( $links ) ) {
			return false;
		}

		foreach ( $links as $link ) {
			// An unrecognised slug resolves to UnknownObject, whose exists() is false, so canEdit() refuses
			// it - no separate guard needed and nothing falls open.
			$objectType = aioseoBrokenLinkChecker()->objects->get( (string) $link->object_type );
			if ( ! $objectType->canEdit( (int) $link->object_id, (string) $link->object_subtype ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The response for a caller who may not act on the row they named.
	 *
	 * @since 1.3.1
	 *
	 * @return \WP_REST_Response The response.
	 */
	private static function cannotActResponse() {
		return new \WP_REST_Response( [
			'success' => false,
			'code'    => 'blc_cannot_edit_object',
			'message' => __( 'You cannot edit everything this link appears in, so nothing was changed.', 'broken-link-checker-seo' )
		], 403 );
	}

	/**
	 * Rechecks the given link.
	 *
	 * @since 1.1.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function recheck( $request ) {
		$body         = $request->get_json_params();
		$linkStatusId = ! empty( $body['linkStatusId'] ) ? intval( $body['linkStatusId'] ) : null;
		if ( empty( $linkStatusId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No link status ID given.'
			], 400 );
		}

		if ( ! self::canActOnLinkStatus( $linkStatusId ) ) {
			return self::cannotActResponse();
		}

		// Construct a list with a single row so that we can pass it into the bulk recheck handler.
		$linkStatusRows = [
			[
				'id' => $linkStatusId,
			]
		];

		$response = self::recheckLinks( $linkStatusRows );

		// The service's own reason, where it gave one. Retrying a bad license or an exhausted quota can
		// never work, so telling the reader to try again is worse than telling them nothing.
		if ( is_wp_error( $response ) ) {
			return self::refusalResponse( $response );
		}

		if ( ! $response ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => __( 'This link could not be checked. Refresh the page and try again.', 'broken-link-checker-seo' )
			], 400 );
		}

		self::applyRecheckQuota( $response );

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Dismisses the given link.
	 *
	 * @since 1.1.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function dismiss( $request ) {
		$body         = $request->get_json_params();
		$linkStatusId = ! empty( $body['linkStatusId'] ) ? intval( $body['linkStatusId'] ) : null;
		if ( empty( $linkStatusId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No link status ID given.'
			], 400 );
		}

		if ( ! self::canActOnLinkStatus( $linkStatusId ) ) {
			return self::cannotActResponse();
		}

		$success = self::setLinkStatusDismissed( $linkStatusId );
		if ( ! $success ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No link status found.'
			], 400 );
		}

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Undismisses the given link.
	 *
	 * @since 1.1.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function undismiss( $request ) {
		$body         = $request->get_json_params();
		$linkStatusId = ! empty( $body['linkStatusId'] ) ? intval( $body['linkStatusId'] ) : null;
		if ( empty( $linkStatusId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No link status ID given.'
			], 400 );
		}

		if ( ! self::canActOnLinkStatus( $linkStatusId ) ) {
			return self::cannotActResponse();
		}

		$success = self::setLinkStatusDismissed( $linkStatusId, false );
		if ( ! $success ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No link status found.'
			], 400 );
		}

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}
}