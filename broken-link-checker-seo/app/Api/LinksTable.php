<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles links table related routes.
 *
 * @since 1.1.0
 */
class LinksTable extends CommonTableActions {
	/**
	 * Returns the data for the links table.
	 *
	 * @since 1.1.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchData( $request ) {
		$body         = $request->get_json_params();
		$linkStatusId = ! empty( $body['linkStatusId'] ) ? intval( $body['linkStatusId'] ) : null;
		// intval() turns any non-numeric string into 0, which is not a limit and divides the pager by zero.
		$limit        = self::normalizeLimit( isset( $body['limit'] ) ? $body['limit'] : null );
		$offset       = isset( $body['offset'] ) ? max( 0, intval( $body['offset'] ) ) : 0;
		// Normalised by the model, which is what decides whether a term narrows anything at all.
		$searchTerm   = isset( $body['searchTerm'] ) ? $body['searchTerm'] : null;
		$source       = isset( $body['source'] ) ? sanitize_text_field( $body['source'] ) : '';
		$type         = isset( $body['media'] ) ? sanitize_text_field( $body['media'] ) : '';

		// The same narrowing the report and the export apply, so an expanded row lists the locations
		// the filters left rather than every location the URL has.
		$whereClause = Models\Link::getLinkWhereClause( $searchTerm );
		$whereClause = Models\Link::addSourceClause( $whereClause, $source );
		$whereClause = Models\Link::addTypeClause( $whereClause, $type );

		if ( empty( $linkStatusId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No link status ID was provided.'
			], 400 );
		}

		$totalRows = Models\Link::rowQueryCount( $linkStatusId, $whereClause );
		$page      = 0 === $offset ? 1 : ( $offset / $limit ) + 1;

		return new \WP_REST_Response( [
			'success' => true,
			'links'   => [
				'rows'   => Models\Link::rowQuery( $linkStatusId, $limit, $offset, $whereClause ),
				'totals' => [
					'page'  => $page,
					'pages' => ceil( $totalRows / $limit ),
					'total' => $totalRows
				]
			]
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

		$refused = [];
		$skipped = [];
		$acted   = 0;
		switch ( $action ) {
			case 'unlink':
				// Read before anything is written. Removing one link rewrites the object it sits in, which
				// reindexes that object and gives its remaining rows new ids - so every id after the first
				// went stale, and a selection of several occurrences inside one post unlinked one of them.
				$targets = [];
				foreach ( $rowIds as $rowId ) {
					$link = Models\Link::getById( (int) $rowId );
					if ( $link->exists() ) {
						$targets[] = $link;
					}
				}

				$handled = [];
				foreach ( $targets as $target ) {
					// The same re-resolution the parent row's unlink uses: where the id is gone, another
					// occurrence of this URL in the same object stands in for it, and one already written is
					// never written twice.
					$link = self::resolveTargetForWrite( (int) $target->blc_link_status_id, $target, $handled );
					if ( ! $link ) {
						$skipped[] = self::skippedLocation( $target, null );

						continue;
					}

					$handled[] = (int) $link->id;

					$result = self::removeLink( $link->id );

					// A refusal is reported rather than swallowed: the caller asked for something this kind
					// of object cannot do, and a silent success would say otherwise. Named per location as
					// well as collected by code, so the reader is told which one and why.
					if ( is_wp_error( $result ) ) {
						$refused[ $result->get_error_code() ] = $result->get_error_message();
						$skipped[]                            = self::skippedLocation( $target, $result );

						continue;
					}

					if ( ! $result ) {
						$skipped[] = self::skippedLocation( $target, null );

						continue;
					}

					$acted++;
				}
				break;
			default:
				break;
		}

		// Nothing was touched, so this did not succeed - whatever the reasons say. The sibling endpoint
		// answers a wholly refused request the same way, and a caller branching on success alone would
		// otherwise read this one as done.
		if ( ! $acted && ! empty( $refused ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'code'    => array_key_first( $refused ),
				'message' => reset( $refused ),
				'refused' => array_values( $refused )
			], 403 );
		}

		return new \WP_REST_Response( [
			'success'          => true,
			'refused'          => array_values( $refused ),
			// The shape the sibling endpoint answers with, so the table renders a partial refusal the same
			// way whichever action produced it.
			'skipped'          => count( $skipped ),
			'skippedLocations' => $skipped
		], 200 );
	}
}