<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;
use AIOSEO\BrokenLinkChecker\Objects\ObjectType;

/**
 * Handles link status/link row edit updates.
 *
 * @since 1.1.0
 */
class EditRow extends CommonTableActions {
	/**
	 * Edits the given link status/link row.
	 *
	 * @since   1.1.0
	 * @version 1.3.1 Filter the new URL through {@see Utils\Helpers::sanitizeLinkUrl()}.
	 *
	 * @param  \WP_REST_Request  $request The request
	 * @return \WP_REST_Response          The response.
	 */
	public static function update( $request ) {
		$body         = $request->get_json_params();
		$linkStatusId = ! empty( $body['linkStatusId'] ) ? intval( $body['linkStatusId'] ) : null;
		$linkId       = ! empty( $body['linkId'] ) ? intval( $body['linkId'] ) : null;
		if ( empty( $linkStatusId ) && empty( $linkId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No link status or link ID ID was provided.'
			], 400 );
		}

		$newUrl    = ! empty( $body['url'] ) ? aioseoBrokenLinkChecker()->helpers->sanitizeLinkUrl( $body['url'] ) : '';
		$newAnchor = ! empty( $body['anchor'] ) ? sanitize_text_field( $body['anchor'] ) : '';
		if ( empty( $newUrl ) && empty( $newAnchor ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => __( 'That is not a URL we can save. Use http:// or https://, or a path like /about-us/.', 'broken-link-checker-seo' )
			], 400 );
		}

		// If a link status ID was provided, then we need to update the URL for each link related to this link status.
		if ( $linkStatusId ) {
			// Resolved in full before the first write: a URL can span kinds, and the one that refuses must
			// not be discovered after the others have already been rewritten.
			$targets = self::resolveTargets( $linkStatusId, ObjectType::EDIT_URL, '', $newUrl );
			if ( is_wp_error( $targets ) ) {
				return self::refusalResponse( $targets );
			}

			$wrote   = false;
			$handled = [];
			foreach ( $targets as $target ) {
				// Re-resolved per write: the previous one reindexed its object and replaced these rows.
				$link = self::resolveTargetForWrite( $linkStatusId, $target, $handled );
				if ( ! $link ) {
					continue;
				}

				$handled[] = (int) $link->id;

				$result = self::updateLink( $link->id, '', $newUrl );
				if ( is_wp_error( $result ) ) {
					return self::refusalResponse( $result );
				}

				// A write that simply didn't happen. Reported rather than passed over, so the panel can't
				// close on a URL the content still carries.
				if ( ! $result ) {
					return self::writeFailedResponse();
				}

				$wrote = true;
			}

			// The row resolved to nothing this caller can act on, so there was never a write to report.
			// 404 rather than 403, so it does not confirm what the row holds for somebody else - the
			// answer unlink already gives for the same state.
			if ( ! $wrote ) {
				return self::refusalResponse( new \WP_Error(
					'blc_action_nothing_to_do',
					__( 'This link is not recorded in anything you can edit.', 'broken-link-checker-seo' ),
					[ 'status' => 404 ]
				) );
			}
		}

		if ( $linkId ) {
			$link = Models\Link::getById( $linkId );
			if ( ! $link->exists() ) {
				return new \WP_REST_Response( [
					'success' => false,
					'message' => __( 'This link is no longer in your report. Refresh the page and try again.', 'broken-link-checker-seo' )
				], 404 );
			}

			$type = aioseoBrokenLinkChecker()->objects->get( $link->object_type );

			// Each kind has its own edit capability.
			if ( ! $type->canEdit( $link->object_id, $link->object_subtype ) ) {
				return new \WP_REST_Response( [
					'success' => false,
					'code'    => 'blc_cannot_edit_object',
					'message' => __( 'You do not have permission to edit this location.', 'broken-link-checker-seo' )
				], 403 );
			}

			// Asked before the write, the way the linkStatusId branch asks. Without it a kind that cannot
			// be rewritten at all - a builder layout - reached updateLink(), came back false, and was
			// answered with "refresh and try again", which can never help.
			if ( ! $type->supports( ObjectType::EDIT_URL, $link->object_id, $link->object_subtype ) ) {
				$refused = $type->refusedActions( $link->object_id, $link->object_subtype );

				return self::refusalResponse( new \WP_Error(
					'blc_action_refused',
					isset( $refused[ ObjectType::EDIT_URL ] ) ? $refused[ ObjectType::EDIT_URL ] : self::actionRefusal( $type ),
					[ 'status' => 409 ]
				) );
			}

			$result = self::updateLink( $linkId, $newAnchor, $newUrl );
			if ( is_wp_error( $result ) ) {
				return self::refusalResponse( $result );
			}

			if ( ! $result ) {
				return self::writeFailedResponse();
			}
		}

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * The response for a write that was allowed but did not happen.
	 *
	 * @since 1.3.1
	 *
	 * @return \WP_REST_Response The response.
	 */
	private static function writeFailedResponse() {
		return new \WP_REST_Response( [
			'success' => false,
			'code'    => 'blc_write_failed',
			'message' => __(
				'This link could not be updated. It may have changed since the report last looked at it, so refresh the page and try again.',
				'broken-link-checker-seo'
			)
		], 400 );
	}

	/**
	 * Deletes the object a link is, for the kinds where the two are the same thing.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function remove( $request ) {
		$body   = $request->get_json_params();
		$linkId = ! empty( $body['linkId'] ) ? intval( $body['linkId'] ) : null;
		if ( empty( $linkId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No link ID was provided.'
			], 400 );
		}

		$result = self::removeItem( $linkId );
		if ( is_wp_error( $result ) ) {
			return self::refusalResponse( $result );
		}

		if ( ! $result ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'The item could not be deleted.'
			], 400 );
		}

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}
}