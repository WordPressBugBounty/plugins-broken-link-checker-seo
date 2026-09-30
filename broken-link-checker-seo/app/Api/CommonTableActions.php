<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Links\Url;
use AIOSEO\BrokenLinkChecker\Models;
use AIOSEO\BrokenLinkChecker\Objects\ObjectType;
use AIOSEO\BrokenLinkChecker\Utils\BlcHtmlTagProcessor;

/**
 * Handles all common table action handlers.
 *
 * @since 1.1.0
 */
abstract class CommonTableActions {
	/**
	 * The largest page a table request may ask for.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const MAX_LIMIT = 200;

	/**
	 * Returns the link status ids a bulk request is asking about.
	 *
	 * NOTE: A row arrives as `{ id: N }` from the table, but a bare id is accepted too — the shape used
	 * to be assumed, so a bare one reached `$row['id']`, warned, and evaluated to null. Every action then
	 * ran against nothing and the response still reported success.
	 *
	 * @since 1.3.1
	 *
	 * @param  mixed $rows The rows as sent.
	 * @return int[]       The ids, deduplicated.
	 */
	protected static function normalizeRowIds( $rows ) {
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$ids = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$row = isset( $row['id'] ) ? $row['id'] : null;
			}

			if ( ! is_scalar( $row ) ) {
				continue;
			}

			$id = (int) $row;
			if ( 0 < $id ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Returns a usable page size for a table request.
	 *
	 * NOTE: intval() turns any non-numeric string into 0, and `! empty()` on the raw value lets it
	 * through, so "abc" arrived as a limit of zero and divided the pager by it. An upper bound because
	 * the page size decides how much a single request asks the database for.
	 *
	 * @since 1.3.1
	 *
	 * @param  mixed $limit The requested limit.
	 * @return int          The limit to use.
	 */
	protected static function normalizeLimit( $limit ) {
		$limit = is_numeric( $limit ) ? (int) $limit : 0;

		if ( 1 > $limit ) {
			return 20;
		}

		return min( $limit, self::MAX_LIMIT );
	}

	/**
	 * Unlinks the given link.
	 *
	 * @since 1.1.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function unlink( $request ) {
		$body         = $request->get_json_params();
		$linkStatusId = ! empty( $body['linkStatusId'] ) ? intval( $body['linkStatusId'] ) : null;
		$linkId       = ! empty( $body['linkId'] ) ? intval( $body['linkId'] ) : null;
		if ( empty( $linkStatusId ) && empty( $linkId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No link status ID or link ID given.'
			], 400 );
		}

		if ( ! empty( $linkStatusId ) ) {
			$wrote   = 0;
			$skipped = self::unlinkLinkStatus( $linkStatusId, $wrote );

			// Nothing written and every location accounted for: refused outright, which is the 409 this
			// has always answered with. Decided here, because a bulk action wants the list instead.
			if ( ! $wrote && ! empty( $skipped ) ) {
				return self::refusalResponse(
					new \WP_Error( 'blc_action_refused', $skipped[0]['reason'], [ 'status' => 409 ] )
				);
			}

			// Nothing written and nothing to name: the link is recorded nowhere this reader may edit, and
			// answering success would report a rewrite that never happened. 404 rather than 403, so it
			// does not confirm what the row holds for somebody else.
			if ( ! $wrote ) {
				return self::refusalResponse( new \WP_Error(
					'blc_action_nothing_to_do',
					__( 'This link is not recorded in anything you can edit.', 'broken-link-checker-seo' ),
					[ 'status' => 404 ]
				) );
			}

			// Named rather than counted. "One location still has this link" left the reader with nothing
			// to act on: not which of them, and not whether the others had been rewritten.
			return new \WP_REST_Response( [
				'success'          => true,
				'skipped'          => count( $skipped ),
				'skippedLocations' => $skipped
			], 200 );
		}

		$link = Models\Link::getById( $linkId );
		if ( $link->exists() && Models\Link::isBareUrlRow( $link ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'code'    => 'blc_action_refused',
				'message' => Models\Link::bareUrlUnlinkRefusal()
			], 409 );
		}

		if ( $link->exists() && Models\Link::isEmbedRow( $link ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'code'    => 'blc_action_refused',
				'message' => ! empty( $link->is_video ) ? Models\Link::videoUnlinkRefusal() : Models\Link::imageUnlinkRefusal()
			], 409 );
		}

		$type = $link->exists() ? aioseoBrokenLinkChecker()->objects->get( $link->object_type ) : null;
		if ( $type && ! $type->supports( ObjectType::UNLINK, $link->object_id, $link->object_subtype ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'code'    => 'blc_unlink_unsupported',
				'message' => self::unlinkRefusal( $type, $link->object_id, $link->object_subtype )
			], 409 );
		}

		$success = self::removeLink( $linkId );
		if ( is_wp_error( $success ) ) {
			return self::refusalResponse( $success );
		}

		if ( empty( $success ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Link could not be removed.'
			], 400 );
		}

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Resolves every occurrence of the given link status, refusing the call outright when one of them
	 * cannot take the given action.
	 *
	 * A URL can span several objects of different kinds, so a refusal discovered mid-loop has already
	 * rewritten the ones before it and then reports failure. Everything that can be settled without
	 * writing is settled here first, as
	 * {@see \AIOSEO\BrokenLinkChecker\Services\BrokenLinksService::getTargets()} does.
	 *
	 * NOTE: A record whose object is gone is dropped rather than refused — there is nothing left to
	 * write to, and the record is stale.
	 *
	 * @since 1.3.1
	 *
	 * @param  int              $linkStatusId The Link Status ID.
	 * @param  string           $action       The action about to be taken.
	 * @param  string           $newAnchor    The new anchor, for a rewrite.
	 * @param  string           $newUrl       The new URL, for a rewrite.
	 * @return array|\WP_Error                The links to write to, or the reason nothing was written.
	 */
	/**
	 * Unlinks every reported occurrence of one URL, and names the ones it could not.
	 *
	 * Shared with the bulk action, which used to call removeLink() per row and so applied none of the
	 * refusals this collects: an embedded URL has no anchor to unwrap, so the write quietly did nothing
	 * and the caller was told nothing. One implementation is the only way the two agree.
	 *
	 * @since 1.3.1
	 *
	 * @param  int                 $linkStatusId The link status ID.
	 * @return array|\WP_Error                   The skipped locations, or a refusal of the whole request.
	 */
	protected static function unlinkLinkStatus( $linkStatusId, &$wrote = 0 ) {
		$wrote    = 0;
		$refusals = [];
		$targets  = self::resolveTargets( $linkStatusId, ObjectType::UNLINK, '', '', $refusals );

		// Refused outright: every occurrence has a reason and there is nothing to write. The reasons are
		// the answer, so they come back named like any other skipped location. resolveTargets() reports
		// this as an error carrying the first reason alone, which a caller acting on several URLs cannot
		// use - it has as many things to say as it had URLs.
		if ( is_wp_error( $targets ) ) {
			return $refusals;
		}

		$handled = [];
		// The occurrences that can never be unlinked, named alongside the ones a write could not reach -
		// to the reader they are the same thing: places the link is still in.
		$skipped = $refusals;
		foreach ( $targets as $target ) {
			$link = self::resolveTargetForWrite( $linkStatusId, $target, $handled );
			if ( ! $link ) {
				$skipped[] = self::skippedLocation( $target, null );

				continue;
			}

			$handled[] = (int) $link->id;

			$removed = self::removeLink( $link->id );
			if ( is_wp_error( $removed ) || ! $removed ) {
				$skipped[] = self::skippedLocation( $target, is_wp_error( $removed ) ? $removed : null );

				continue;
			}

			$wrote++;
		}

		return $skipped;
	}

	protected static function resolveTargets( $linkStatusId, $action, $newAnchor = '', $newUrl = '', &$refusals = [] ) {
		$orphans  = [];
		$targets  = [];
		$refusals = [];

		foreach ( Models\Link::getReportedByLinkStatusId( $linkStatusId ) as $link ) {
			$type     = aioseoBrokenLinkChecker()->objects->get( $link->object_type );
			$objectId = (int) $link->object_id;
			$subtype  = (string) $link->object_subtype;

			if ( ! $type->exists( $objectId, $subtype ) ) {
				$orphans[] = $link;

				continue;
			}

			// The type cannot answer this one: only the row knows how the occurrence got there. An embed
			// has no anchor to take out, so unlinking it would mean deleting the thing itself - but a
			// link that happens to point at a video or a picture is an ordinary link and comes out like
			// one.
			//
			// One occurrence refusing is not the whole URL refusing. A URL written as plain text in a term
			// description and as an ordinary link in a post used to abort both, leaving the link in the
			// post it could have come out of. Refusals are collected per occurrence and reported by
			// {@see self::unlink()}; nothing actionable at all is what makes the refusal the answer.
			$refusal = null;
			if ( ObjectType::UNLINK === $action && Models\Link::isBareUrlRow( $link ) ) {
				$refusal = new \WP_Error( 'blc_action_refused', Models\Link::bareUrlUnlinkRefusal(), [ 'status' => 409 ] );
			} elseif ( ObjectType::UNLINK === $action && Models\Link::isEmbedRow( $link ) ) {
				$refusal = new \WP_Error(
					'blc_action_refused',
					! empty( $link->is_video ) ? Models\Link::videoUnlinkRefusal() : Models\Link::imageUnlinkRefusal(),
					[ 'status' => 409 ]
				);
			} elseif ( ! $type->supports( $action, $objectId, $subtype ) ) {
				// The same reason the table shows on the disabled action, so a caller reaching past the
				// table is told what the reader was already told.
				$refused = $type->refusedActions( $objectId, $subtype );
				if ( isset( $refused[ $action ] ) ) {
					$refusal = new \WP_Error( 'blc_action_refused', $refused[ $action ], [ 'status' => 409 ] );
				} else {
					$refusal = ObjectType::UNLINK === $action
						? new \WP_Error( 'blc_unlink_unsupported', self::unlinkRefusal( $type, $objectId, $subtype ), [ 'status' => 409 ] )
						: new \WP_Error( 'blc_action_unsupported', self::actionRefusal( $type ), [ 'status' => 409 ] );
				}
			}

			if ( $refusal ) {
				// Only unlink writes to each occurrence in turn. Every other action rewrites the URL
				// everywhere at once, so a single refusal is still the answer for the whole URL.
				if ( ObjectType::UNLINK !== $action ) {
					return $refusal;
				}

				$refusals[] = self::skippedLocation( $link, $refusal );

				continue;
			}

			if ( ! $type->canEdit( $objectId, $subtype ) ) {
				return new \WP_Error(
					'blc_cannot_edit_object',
					__( 'You cannot edit everything this link appears in, so nothing was changed.', 'broken-link-checker-seo' ),
					[ 'status' => 403 ]
				);
			}

			if ( ObjectType::EDIT_URL === $action ) {
				$refusal = self::urlChangeRefusal( $type, $objectId, $subtype, $newAnchor, $newUrl );
				if ( $refusal ) {
					return $refusal;
				}
			}

			$targets[] = $link;
		}

		foreach ( $orphans as $orphan ) {
			$orphan->delete();
		}

		// Nothing left to write to, so the refusal is the whole answer rather than a footnote to it.
		if ( empty( $targets ) && ! empty( $refusals ) ) {
			return new \WP_Error( 'blc_action_refused', $refusals[0]['reason'], [ 'status' => 409 ] );
		}

		return $targets;
	}

	/**
	 * Returns the row to write to for a target resolved before the first write, or null when it is gone.
	 *
	 * NOTE: Writing one object reindexes it, which replaces the rows resolved up front. The occurrences
	 * still holding the old URL keep the link status, so those are what is left to write to.
	 *
	 * @since 1.3.1
	 *
	 * @param  int                 $linkStatusId The link status ID.
	 * @param  object              $target       The target resolved before the writes began.
	 * @param  int[]               $handled      The link IDs already written to.
	 * @return Models\Link|null                  The row, or null when nothing is left to write.
	 */
	protected static function resolveTargetForWrite( $linkStatusId, $target, $handled = [] ) {
		$link = Models\Link::getById( (int) $target->id );
		if ( $link->exists() ) {
			return $link;
		}

		$links = Models\Link::getByLinkStatusIdAndObject(
			$linkStatusId,
			(string) $target->object_type,
			(int) $target->object_id,
			$handled
		);

		return empty( $links ) ? null : reset( $links );
	}

	/**
	 * The reason the URL of the given object cannot be rewritten, when there is one.
	 *
	 * @since 1.3.1
	 *
	 * @param  ObjectType     $type      The object type.
	 * @param  int            $objectId  The object ID.
	 * @param  string         $subtype   The object subtype.
	 * @param  string         $newAnchor The new anchor.
	 * @param  string         $newUrl    The new URL.
	 * @return \WP_Error|null            The refusal.
	 */
	private static function urlChangeRefusal( $type, $objectId, $subtype, $newAnchor, $newUrl ) {
		if ( $type->isRichText( $objectId, $subtype ) ) {
			return null;
		}

		if ( ! empty( $newAnchor ) ) {
			return new \WP_Error(
				'blc_anchor_unsupported',
				sprintf(
					// Translators: 1 - The name of a kind of object, e.g. "Menu Item".
					__( 'This %1$s has no anchor text the report can rewrite. Change its label in the editor instead.', 'broken-link-checker-seo' ),
					$type->label()
				),
				[ 'status' => 409 ]
			);
		}

		return empty( $newUrl ) ? null : $type->urlRefusal( $objectId, $subtype );
	}

	/**
	 * Turns a typed refusal into the response the report reads it from.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Moved from {@see EditRow} so every write path answers refusals the same way.
	 *
	 * @param  \WP_Error         $error The refusal.
	 * @return \WP_REST_Response        The response.
	 */
	protected static function refusalResponse( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 409;

		return new \WP_REST_Response( [
			'success' => false,
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message()
		], $status );
	}

	/**
	 * The reason the requested action cannot apply to the given kind of object.
	 *
	 * @since 1.3.1
	 *
	 * @param  ObjectType $type The object type.
	 * @return string           The reason.
	 */
	public static function actionRefusal( $type ) {
		return sprintf(
			// Translators: 1 - The name of a source, e.g. "Navigation Menus".
			__( 'That action isn\'t available for links found in %1$s. Nothing was changed.', 'broken-link-checker-seo' ),
			$type->sourceLabel()
		);
	}

	/**
	 * The reason unlinking cannot apply to the given kind of object.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Takes the object, so a kind that holds both shapes can word its own refusal.
	 *
	 * @param  ObjectType $type     The object type.
	 * @param  int        $objectId The object ID.
	 * @param  string     $subtype  The object subtype.
	 * @return string               The reason.
	 */
	public static function unlinkRefusal( $type, $objectId = 0, $subtype = '' ) {
		return $type->unlinkRefusalMessage( $objectId, $subtype );
	}

	/**
	 * Rechecks the given links.
	 *
	 * NOTE: Keyed by address rather than by row, so a recheck of two rows pointing at the same one asks
	 * once and is charged once. The single result settles both
	 * {@see \AIOSEO\BrokenLinkChecker\LinkStatus\LinkStatus::parseResultsHelper()}.
	 *
	 * NOTE: Carries skipFetch like the scheduled scan does, so a recheck of a host that can never
	 * resolve costs the same credit and the same nothing in fetching as the batch would have.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Moved from BrokenLinks to TableActions and add support for bulk-checking rows.
	 * @version 1.3.1 Made public so the Abilities layer can reach it.
	 * @version 1.3.1 Sends the address each row is requested at, once per address.
	 * @version 1.3.1 Asks for a host that can never resolve to be metered rather than fetched.
	 *
	 * @param  array       $linkStatusRows The Link Status rows.
	 * @return object|bool                 The response or false if the links could not be checked.
	 */
	public static function recheckLinks( $linkStatusRows ) {
		// Ids, whether the caller passed rows or ids.
		$linkStatusIds = self::normalizeRowIds( $linkStatusRows );

		$linkStatuses = Models\LinkStatus::getByIds( $linkStatusIds );
		if ( empty( $linkStatuses ) ) {
			return false;
		}

		$checkUrls = Models\LinkStatus::convergeCheckUrls( $linkStatuses );

		$rows = [];
		$sent = [];
		foreach ( $linkStatuses as $linkStatus ) {
			$checkUrl = isset( $checkUrls[ (int) $linkStatus->id ] ) ? $checkUrls[ (int) $linkStatus->id ] : $linkStatus->url;
			if ( isset( $sent[ $checkUrl ] ) ) {
				continue;
			}

			$sent[ $checkUrl ]       = true;
			$rows[ $linkStatus->id ] = [
				'url'       => $checkUrl,
				'skipFetch' => Url::hasUnresolvableHost( $checkUrl )
			];
		}

		$requestBody = array_merge(
			aioseoBrokenLinkChecker()->main->linkStatus->data->getBaseData(),
			[ 'rows' => $rows ]
		);

		$response     = aioseoBrokenLinkChecker()->main->linkStatus->doPostRequest( 'recheck-bulk', $requestBody );
		$responseCode = (int) wp_remote_retrieve_response_code( $response );
		$responseBody = json_decode( wp_remote_retrieve_body( $response ) );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'blc_service_unreachable',
				__( 'The link checking service could not be reached, so nothing was checked. This is usually temporary.', 'broken-link-checker-seo' ),
				[ 'status' => 503 ]
			);
		}

		// The service says why it refused, and the reason decides whether trying again can ever work.
		// Collapsing every one of them to false is what produced "please try again" for a bad license.
		if ( 200 !== $responseCode || empty( $responseBody->success ) || empty( $responseBody->rows ) ) {
			return self::serviceRefusal( $responseBody );
		}

		foreach ( $responseBody->rows as $row ) {
			// Parse the data into a useable format and then save the updated results.
			aioseoBrokenLinkChecker()->main->linkStatus->parseResultsHelper( $row );
		}

		self::settleInconclusive( $linkStatusIds );

		return $responseBody;
	}

	/**
	 * Runs the local fetch now for the rechecked rows the service could not settle.
	 *
	 * Somebody asked for this check and is waiting on the answer, so a row left for the scheduled batch
	 * would report back as still needing to be checked - which is what the recheck was meant to resolve.
	 *
	 * NOTE: Bounded by wall clock rather than by row count, because each fetch can take the full request
	 * timeout. The first row always runs, so rechecking one link always settles it; past the budget the
	 * rest stay queued for the batch, exactly as they were before.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $linkStatusIds The IDs that were rechecked.
	 * @return void
	 */
	private static function settleInconclusive( $linkStatusIds ) {
		$budget  = (int) apply_filters( 'aioseo_blc_inline_local_scan_budget', 15 );
		$started = microtime( true );
		$first   = true;

		foreach ( Models\LinkStatus::getByIds( $linkStatusIds ) as $linkStatus ) {
			if ( empty( $linkStatus->needs_additional_scan ) ) {
				continue;
			}

			if ( ! $first && microtime( true ) - $started >= $budget ) {
				return;
			}

			aioseoBrokenLinkChecker()->main->localScan->scanNow( $linkStatus );

			$first = false;
		}
	}

	/**
	 * Describes a location the unlink could not be applied to.
	 *
	 * NOTE: The reason is carried where the write gave one. Where it did not - the occurrence was gone
	 * by the time we reached it - the label alone is what there is to say.
	 *
	 * @since 1.3.1
	 *
	 * @param  object         $target The target the unlink was attempted on.
	 * @param  \WP_Error|null $error  The refusal, where the write gave one.
	 * @return array                  The label and reason.
	 */
	protected static function skippedLocation( $target, $error = null ) {
		$objectType = isset( $target->object_type ) ? (string) $target->object_type : '';
		$objectId   = isset( $target->object_id ) ? (int) $target->object_id : 0;
		$subtype    = isset( $target->object_subtype ) ? (string) $target->object_subtype : '';
		$type       = aioseoBrokenLinkChecker()->objects->get( $objectType );

		return [
			// The location itself, not just the row: a write to one occurrence reindexes the object and
			// renumbers the rest, so a table whose rows are the locations matches on what survives that.
			'linkId'     => isset( $target->id ) ? (int) $target->id : 0,
			'objectType' => $objectType,
			'objectId'   => $objectId,
			'label'      => $type->locationLabel( $objectId, $subtype ),
			'source'     => $type->label(),
			'reason'     => $error ? $error->get_error_message() : ''
		];
	}

	/**
	 * Turns the service's refusal into one a reader can act on.
	 *
	 * NOTE: The slugs are the service's own. An unrecognised one falls back to its message, and then to
	 * a generic line, so a reason we have no wording for still reaches the reader instead of being
	 * replaced by advice to try again.
	 *
	 * @since 1.3.1
	 *
	 * @param  object|null $responseBody The decoded response.
	 * @return \WP_Error                 The refusal.
	 */
	private static function serviceRefusal( $responseBody ) {
		$error = ! empty( $responseBody->error ) ? (string) $responseBody->error : '';

		switch ( $error ) {
			case 'no-license':
			case 'invalid-token':
			case 'invalid-license':
				$message = __( 'Your license could not be verified, so nothing was checked. Reconnect your account in the Broken Link Checker settings.', 'broken-link-checker-seo' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				break;
			case 'quota-exceeded':
			case 'no-quota':
				$message = __( 'You have used all of this month\'s link checks, so nothing was checked. Your allowance resets at the start of your billing month.', 'broken-link-checker-seo' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				break;
			default:
				$message = ! empty( $responseBody->message )
					? (string) $responseBody->message
					: __( 'The link checking service could not check these links, and did not say why.', 'broken-link-checker-seo' );
		}

		return new \WP_Error( 'blc_recheck_refused', $message, [ 'status' => 409 ] );
	}

	/**
	 * Stores the quota a recheck response reports, reactivating the license when the plan changed.
	 *
	 * NOTE: The internal options persist through their own `shutdown` save, which a `wp_die()` reaches
	 * as well since it is registered through `register_shutdown_function()`.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Ignores a refusal, which carries no quota.
	 *
	 * @param  object|\WP_Error|bool $responseBody The recheck response body.
	 * @return void
	 */
	public static function applyRecheckQuota( $responseBody ) {
		if ( empty( $responseBody ) || is_wp_error( $responseBody ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->license->applyQuotaFromResponse( $responseBody );
	}

	/**
	 * Sets the dismissed value for a given Link Status object.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Moved from BrokenLinks to TableActions.
	 * @version 1.3.1 Moved from {@see LinkStatusTable} and made public for the Abilities layer.
	 *
	 * @param  int  $linkStatusId The Link Status ID.
	 * @param  bool $value        The new value.
	 * @return bool               Whether the record was updated.
	 */
	public static function setLinkStatusDismissed( $linkStatusId, $value = true ) {
		$linkStatus = Models\LinkStatus::getById( $linkStatusId );
		if ( ! $linkStatus->exists() ) {
			return false;
		}

		$linkStatus->dismissed = $value;
		$linkStatus->save();

		return true;
	}

	/**
	 * Updates a given link with a new anchor and/or URL.
	 *
	 * Routes to the HTML API path (WP 6.7+) or falls back to the regex path.
	 *
	 * @since   1.1.0
	 * @since   1.3.0 Refactored into junction between HtmlApi and Regex methods.
	 * @version 1.3.1 Made public so the Abilities layer can reach it.
	 * @version 1.3.1 Route a relative replacement path to the regex path.
	 * @version 1.3.1 Handles every object type, and may return a WP_Error refusal.
	 *
	 * @param  int            $linkId    The Link ID.
	 * @param  string         $newAnchor The new anchor.
	 * @param  string         $newUrl    The new URL.
	 * @return bool|\WP_Error            Whether the Link was updated, or the reason it was refused.
	 */
	public static function updateLink( $linkId, $newAnchor = '', $newUrl = '' ) {
		$link = Models\Link::getById( $linkId );
		if ( ! $link->exists() ) {
			return false;
		}

		$type     = aioseoBrokenLinkChecker()->objects->get( $link->object_type );
		$objectId = (int) $link->object_id;
		$subtype  = (string) $link->object_subtype;

		if ( ! $type->exists( $objectId, $subtype ) ) {
			return false;
		}

		// Each kind has its own edit capability, and this is the gate every write goes through.
		if ( ! $type->canEdit( $objectId, $subtype ) ) {
			return false;
		}

		if ( empty( $newAnchor ) && empty( $newUrl ) ) {
			return false;
		}

		if ( ! $type->isRichText( $objectId, $subtype ) ) {
			return self::updateValueUrl( $type, $link, $newAnchor, $newUrl );
		}

		$content = $type->getContent( $objectId, $subtype );

		// An embedded image's URL is a src on a void element, so neither anchor walk would ever find it.
		// A link that merely points at one is an anchor and is rewritten as one.
		if ( ! empty( $link->is_image ) && Models\Link::isEmbedRow( $link ) ) {
			// The row's "link text" is the image's alt attribute, which this path cannot write. Refused
			// rather than dropped, so a rewrite that would only have changed the URL doesn't report the
			// alt text as changed too.
			if ( ! empty( $newAnchor ) ) {
				return new \WP_Error(
					'blc_image_anchor_unsupported',
					__( 'This link is an image, and its alternative text cannot be rewritten from the report. Change it in the editor instead.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					[ 'status' => 409 ]
				);
			}

			return self::updateEmbedSrc( $type, $content, $link, $newUrl, [ 'IMG' ] );
		}

		// A media tag's URL is a src too, and no anchor walk visits one either, so an embed was refused
		// with "it may have changed since the report" while the report went on listing it.
		if ( empty( $newAnchor ) && empty( $link->is_image ) && Models\Link::isEmbedRow( $link ) ) {
			// Tried ahead of the anchor paths and allowed to fall through to them: a row that turns out
			// not to sit on one of these tags is then no worse off than it was.
			if ( self::updateEmbedSrc( $type, $content, $link, $newUrl, [ 'IFRAME', 'VIDEO', 'AUDIO', 'SOURCE' ] ) ) {
				return true;
			}
		}

		// WP 6.7+: set_modifiable_text() is available for anchor text replacement. The processor runs
		// esc_url() on an href, which absolutizes a relative path, so those go to the regex path.
		if (
			version_compare( get_bloginfo( 'version' ), '6.7', '>=' ) &&
			! aioseoBrokenLinkChecker()->helpers->isRelativePathUrl( $newUrl )
		) {
			$result = self::updateLinkHtmlApi( $type, $content, $link, $newAnchor, $newUrl );
			if ( true === $result ) {
				return true;
			}
		}

		return self::updateLinkRegex( $type, $content, $link, $newAnchor, $newUrl );
	}

	/**
	 * Replaces the URL of an object whose URL is its value rather than an anchor inside content.
	 *
	 * @since 1.3.1
	 *
	 * @param  ObjectType     $type      The object type.
	 * @param  object         $link      The link object.
	 * @param  string         $newAnchor The new anchor.
	 * @param  string         $newUrl    The new URL.
	 * @return bool|\WP_Error            Whether it was updated, or the reason it was refused.
	 */
	private static function updateValueUrl( $type, $link, $newAnchor, $newUrl ) {
		$objectId = (int) $link->object_id;
		$subtype  = (string) $link->object_subtype;

		$refusal = self::urlChangeRefusal( $type, $objectId, $subtype, $newAnchor, $newUrl );
		if ( $refusal ) {
			return $refusal;
		}

		if ( empty( $newUrl ) ) {
			return false;
		}

		$result = $type->setUrl( $objectId, $subtype, $newUrl );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return false;
		}

		// No save hook to ride on, so the object is reindexed here rather than deferred.
		aioseoBrokenLinkChecker()->main->links->data->indexObjectLinks( $type->type(), $objectId, $subtype );

		return true;
	}

	/**
	 * Deletes the object a link is, for the kinds where the two are the same thing.
	 *
	 * @since 1.3.1
	 *
	 * @param  int            $linkId The Link ID.
	 * @return bool|\WP_Error         Whether it was deleted, or the reason it was refused.
	 */
	public static function removeItem( $linkId ) {
		$link = Models\Link::getById( $linkId );
		if ( ! $link->exists() ) {
			return false;
		}

		$type     = aioseoBrokenLinkChecker()->objects->get( $link->object_type );
		$objectId = (int) $link->object_id;
		$subtype  = (string) $link->object_subtype;

		if ( ! $type->supports( ObjectType::REMOVE_ITEM, $objectId, $subtype ) ) {
			return new \WP_Error(
				'blc_remove_item_unsupported',
				sprintf(
					// Translators: 1 - The name of a source, e.g. "Post Content".
					__( 'This link was found in %1$s, where the report cannot delete the item it is in. Remove the link from the content instead.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					$type->sourceLabel()
				),
				[ 'status' => 409 ]
			);
		}

		if ( ! $type->canEdit( $objectId, $subtype ) ) {
			return new \WP_Error(
				'blc_cannot_edit_object',
				__( 'You do not have permission to delete this item.', 'broken-link-checker-seo' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! $type->removeItem( $objectId, $subtype ) ) {
			return false;
		}

		Models\Link::deleteObjectLinks( $type->type(), $objectId );

		return true;
	}

	/**
	 * Points an embedded image or media tag at a new URL.
	 *
	 * Matched on the src and the alt text, the same pair the anchor path matches on, so the same picture
	 * used twice on a page with different alt text is told apart. A media tag carries no alt and its row
	 * stores no anchor, so for those the pair is satisfied by both sides being empty.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Writes a media tag's src as well as an image's.
	 *
	 * @param  ObjectType $type    The object type.
	 * @param  string     $content The object's content.
	 * @param  object     $link    The link object.
	 * @param  string     $newUrl  The new URL.
	 * @param  array      $tags    The upper-case tag names whose src may hold the URL.
	 * @return bool                Whether the embed was updated.
	 */
	private static function updateEmbedSrc( $type, $content, $link, $newUrl, $tags ) {
		if ( empty( $newUrl ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return false;
		}

		$content     = (string) $content;
		$processor   = new \WP_HTML_Tag_Processor( $content );
		$relativeUrl = self::makeUrlRelative( $link->url );
		$baseUrl     = self::baseUrlForLink( $link );

		while ( $processor->next_token() ) {
			if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
				continue;
			}

			if ( ! in_array( $processor->get_tag(), $tags, true ) ) {
				continue;
			}

			if ( ! self::hrefMatchesUrl( $processor->get_attribute( 'src' ), $link->url, $relativeUrl, $baseUrl ) ) {
				continue;
			}

			$alt = $processor->get_attribute( 'alt' );
			if ( ! self::anchorMatches( is_string( $alt ) ? $alt : '', (string) $link->anchor ) ) {
				continue;
			}

			$processor->set_attribute( 'src', $newUrl );

			return self::persistContent( $type, $link, $processor->get_updated_html() );
		}

		return false;
	}

	/**
	 * Updates a given link using WP_HTML_Tag_Processor directly on post_content.
	 *
	 * Matches the specific link instance by both href and anchor text, then updates
	 * only the first matching occurrence. Uses a bookmark to seek back after
	 * verifying the anchor text, so only one processor pass is needed.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Takes the object type and its content instead of a post.
	 *
	 * @param  ObjectType $type      The object type.
	 * @param  string     $content   The object's content.
	 * @param  object     $link      The link object.
	 * @param  string     $newAnchor The new anchor.
	 * @param  string     $newUrl    The new URL.
	 * @return bool                  Whether the Link was updated.
	 */
	private static function updateLinkHtmlApi( $type, $content, $link, $newAnchor, $newUrl ) {
		$content     = (string) $content;
		$processor   = new \WP_HTML_Tag_Processor( $content );
		$relativeUrl = self::makeUrlRelative( $link->url );
		$baseUrl     = self::baseUrlForLink( $link );
		$seekTo      = null;

		while ( true ) {
			if ( $seekTo ) {
				$processor->seek( $seekTo );
				$processor->release_bookmark( $seekTo );
				$seekTo = null;
			} elseif ( ! $processor->next_tag( 'a' ) ) {
				break;
			}

			if ( ! self::hrefMatchesUrl( $processor->get_attribute( 'href' ), $link->url, $relativeUrl, $baseUrl ) ) {
				continue;
			}

			$processor->set_bookmark( 'target-opener' );

			$walk = self::walkToAnchorCloser( $processor );

			if ( $walk['nestedOpenerBookmark'] ) {
				$seekTo = $walk['nestedOpenerBookmark'];
			}

			if ( ! $walk['foundCloser'] || ! self::anchorMatches( $walk['anchorText'], $link->anchor ) ) {
				$processor->release_bookmark( 'target-opener' );
				continue;
			}

			// Seek back to the opening <a> tag to apply modifications.
			$processor->seek( 'target-opener' );
			$processor->release_bookmark( 'target-opener' );

			if ( $newUrl ) {
				$processor->set_attribute( 'href', self::withHrefFragment( $processor->get_attribute( 'href' ), $newUrl ) );
			}

			// set_modifiable_text() operates on individual text nodes, so if the
			// anchor spans multiple nodes (e.g. "click <em>here</em>"), this won't find
			// the full anchor in any single node. Return false to discard all processor
			// changes (including the href update above) and fall through to regex.
			if ( ! empty( $newAnchor ) && ! self::replaceAnchorText( $processor, $link->anchor, $newAnchor ) ) {
				return false;
			}

			$newContent = $processor->get_updated_html();

			// If nothing changed, the link already has the desired URL/anchor — treat as success.
			if ( $newContent === $content ) {
				return true;
			}

			return self::persistContent( $type, $link, $newContent );
		}

		return false;
	}

	/**
	 * Carries the fragment of the href being replaced over onto its replacement.
	 *
	 * The stored URL never has one - urlFields() drops it - so a single row stands for every anchor on
	 * the page that differs only by its fragment. Writing the new URL over them sent #one and #two to
	 * the same place, which is not where either of them pointed.
	 *
	 * @since 1.3.1
	 *
	 * @param  string|null $href   The href being replaced.
	 * @param  string      $newUrl The new URL.
	 * @return string              The new URL, with the old fragment if it had one and the new does not.
	 */
	private static function withHrefFragment( $href, $newUrl ) {
		// A fragment the person typed is the one they meant.
		if ( false !== strpos( $newUrl, '#' ) ) {
			return $newUrl;
		}

		$fragment = strpos( (string) $href, '#' );

		return false === $fragment ? $newUrl : $newUrl . substr( (string) $href, $fragment );
	}

	/**
	 * Updates a given link using regex.
	 *
	 * @since   1.1.0
	 * @since   1.3.0 Extracted from updateLink().
	 * @version 1.3.1 Takes the object type and its content instead of a post.
	 * @version 1.3.1 Falls back to the anchor when the stored phrase no longer matches.
	 *
	 * @param  ObjectType $type      The object type.
	 * @param  string     $content   The object's content.
	 * @param  object     $link      The link object.
	 * @param  string     $newAnchor The new anchor.
	 * @param  string     $newUrl    The new URL.
	 * @return bool                  Whether the Link was updated.
	 */
	private static function updateLinkRegex( $type, $content, $link, $newAnchor, $newUrl ) {
		$oldAnchor     = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->anchor );
		$oldUrl        = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->url );
		$escapedAnchor = aioseoBrokenLinkChecker()->helpers->escapeRegexReplacement( $newAnchor ?: $link->anchor );
		$escapedUrl    = aioseoBrokenLinkChecker()->helpers->escapeRegexReplacement( $newUrl ?: $link->url );

		$newPhraseHtml = preg_replace( "/(<a.*?href=\")($oldUrl)(\".*?>[\s\w]*?)(<[^>]+>)?($oldAnchor)(<\/[^>]+>)?([\s\w]*?<\/a>)/is", "\${1}$escapedUrl\${3}\${4}$escapedAnchor\${6}\${7}", $link->phrase_html ) ?? $link->phrase_html; // phpcs:ignore Generic.Files.LineLength.MaxExceeded

		$success = self::updateLinkInContent( $type, $content, $link, $newPhraseHtml );
		if ( ! $success ) {
			// It's possible that the update failed because the original/old URL is relative in the phrase HTML.
			// In that case, make the old URL relative to match it.
			// This is needed because we make URLs absolute before storing them in the DB.
			$relativeUrl = self::makeUrlRelative( $link->url );
			if ( $relativeUrl !== $link->url ) {
				$oldUrl        = aioseoBrokenLinkChecker()->helpers->escapeRegex( $relativeUrl );
				$newPhraseHtml = preg_replace( "/(<a.*?href=\")($oldUrl)(\".*?>[\s\w]*?)(<[^>]+>)?($oldAnchor)(<\/[^>]+>)?([\s\w]*?<\/a>)/is", "\${1}$escapedUrl\${3}\${4}$escapedAnchor\${6}\${7}", $link->phrase_html ) ?? $link->phrase_html; // phpcs:ignore Generic.Files.LineLength.MaxExceeded

				$success = self::updateLinkInContent( $type, $content, $link, $newPhraseHtml );
			}
		}

		// The phrase is a quote of the content, and a quote can stop matching. Rewrite the anchor on
		// its own rather than refuse a link whose address is still sitting there.
		if ( ! $success ) {
			$success = self::updateAnchorInContent( $type, $content, $link, $newAnchor, $newUrl );
		}

		return $success;
	}

	/**
	 * Rewrites the anchor on its own, for a row whose stored phrase no longer matches the content.
	 *
	 * The phrase was quoted from the content when the link was indexed, and it can stop matching it:
	 * one quoted from markup is escaped by kses on the way into the database and then never matches
	 * again, and content gets edited. Neither means the address is gone, so neither is a reason to
	 * refuse the write. Acted on only when the anchor occurs exactly once, because then there is no
	 * question which one the report meant - the same test the deletion path makes for the same reason.
	 *
	 * NOTE: Each href is resolved and compared the way {@see self::updateLinkHtmlApi()} compares one,
	 * so this path matches the addresses the resolver rewrote rather than only literal stored ones.
	 *
	 * @since 1.3.1
	 *
	 * @param  ObjectType $type      The object type.
	 * @param  string     $content   The object's content.
	 * @param  object     $link      The link object.
	 * @param  string     $newAnchor The new anchor.
	 * @param  string     $newUrl    The new URL.
	 * @return bool                  Whether the link was updated.
	 */
	private static function updateAnchorInContent( $type, $content, $link, $newAnchor, $newUrl ) {
		if ( ! $type->canEdit( (int) $link->object_id, (string) $link->object_subtype ) ) {
			return false;
		}

		// The anchor is all this path has to tell one link from another. Without one - an embed row
		// carries none - it would match any <a> at this address, including another row's.
		$anchor = trim( html_entity_decode( (string) $link->anchor, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $anchor ) {
			return false;
		}

		$postContent = str_replace( '&nbsp;', ' ', (string) $content );
		$relativeUrl = self::makeUrlRelative( $link->url );
		$baseUrl     = self::baseUrlForLink( $link );

		if ( ! preg_match_all( '/<a\s[^>]*?>.*?<\/a>/is', $postContent, $matches, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}

		// Every href goes to the resolver rather than being compared with the stored address. The scan
		// stored whatever it made of the href, so a space, a default port or a dot segment never
		// matches it raw - which is every shape a broken link is most likely to have been written in.
		$found = null;
		foreach ( $matches[0] as $match ) {
			if ( ! preg_match( '/\shref\s*=\s*(["\'])(.*?)\1/is', $match[0], $attr ) ) {
				continue;
			}

			if ( ! self::hrefMatchesUrl( html_entity_decode( $attr[2], ENT_QUOTES, 'UTF-8' ), $link->url, $relativeUrl, $baseUrl ) ) {
				continue;
			}

			if ( trim( html_entity_decode( wp_strip_all_tags( $match[0] ), ENT_QUOTES, 'UTF-8' ) ) !== $anchor ) {
				continue;
			}

			// A second one, and the report cannot say which of them it meant.
			if ( null !== $found ) {
				return false;
			}

			$found = [ $match[0], $match[1], $attr[0], $attr[1], $attr[2] ];
		}

		if ( null === $found || ! preg_match( '/^(<a\s[^>]*?>)(.*)(<\/a>)$/is', $found[0], $parts ) ) {
			return false;
		}

		list( $html, $offset, $hrefAttr, $quote, $href ) = $found;
		$open  = $parts[1];
		$inner = $parts[2];

		// The opening tag's href and the text inside it are rewritten separately, so the anchor text is
		// never mistaken for part of the address sitting next to it.
		if ( ! empty( $newUrl ) ) {
			$open = str_replace(
				$hrefAttr,
				' href=' . $quote . esc_attr( self::withHrefFragment( $href, $newUrl ) ) . $quote,
				$open
			);
		}

		if ( ! empty( $newAnchor ) ) {
			// Refused rather than guessed at when there is markup around the text. This path is the last
			// resort for a row whose phrase stopped matching, and a half-rewritten anchor is worse.
			if ( wp_strip_all_tags( $inner ) !== $inner ) {
				return false;
			}

			$inner = $newAnchor;
		}

		$rewritten = $open . $inner . $parts[3];
		if ( $rewritten === $html ) {
			return false;
		}

		return self::persistContent( $type, $link, substr_replace( $postContent, $rewritten, $offset, strlen( $html ) ) );
	}

	/**
	 * Removes a given link.
	 *
	 * Routes to the HTML API path (WP 6.6+) or falls back to the regex path.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Moved from BrokenLinks to TableActions.
	 * @since   1.3.0 Refactored into junction between HtmlApi and Regex methods.
	 * @version 1.3.1 Made public so the Abilities layer can reach it.
	 * @version 1.3.1 Handles every object type, and may return a WP_Error refusal.
	 *
	 * @param  int            $linkId The Link ID.
	 * @return bool|\WP_Error         Whether the Link was unlinked, or the reason it was refused.
	 */
	public static function removeLink( $linkId ) {
		$link = Models\Link::getById( $linkId );
		if ( ! $link->exists() ) {
			return false;
		}

		$type     = aioseoBrokenLinkChecker()->objects->get( $link->object_type );
		$objectId = (int) $link->object_id;
		$subtype  = (string) $link->object_subtype;

		if ( ! $type->exists( $objectId, $subtype ) ) {
			return false;
		}

		// Each kind has its own edit capability, and this is the gate every write goes through. A
		// refusal rather than false, so the bulk callers report it instead of counting it as done.
		if ( ! $type->canEdit( $objectId, $subtype ) ) {
			return new \WP_Error(
				'blc_cannot_edit_object',
				__( 'You do not have permission to edit this location.', 'broken-link-checker-seo' ),
				[ 'status' => 403 ]
			);
		}

		// The URL is the whole of what these objects are, so there is no anchor to leave behind.
		if ( ! $type->supports( ObjectType::UNLINK, $objectId, $subtype ) ) {
			return new \WP_Error(
				'blc_unlink_unsupported',
				self::unlinkRefusal( $type, $objectId, $subtype ),
				[ 'status' => 409 ]
			);
		}

		$content = $type->getContent( $objectId, $subtype );

		// WP 6.6+: next_token() (6.5) and the bookmark length fix (6.6, Trac #61301) are available.
		if ( version_compare( get_bloginfo( 'version' ), '6.6', '>=' ) ) {
			$result = self::removeLinkHtmlApi( $type, $content, $link );

			// Only fall through to regex on null (bookmark internals failed).
			// true/false are definitive results from the HTML API path.
			if ( null !== $result ) {
				return $result;
			}
		}

		return self::removeLinkRegex( $type, $content, $link );
	}

	/**
	 * Removes a given link using BlcHtmlTagProcessor directly on post_content.
	 *
	 * Matches the specific link instance by both href and anchor text,
	 * extracts the inner HTML via byte offsets, and replaces the full
	 * <a>...</a> tag with just the inner content.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Takes the object type and its content instead of a post.
	 *
	 * @param  ObjectType $type    The object type.
	 * @param  string     $content The object's content.
	 * @param  object     $link    The link object.
	 * @return bool|null           True on success, null if no match or bookmark internals failed.
	 */
	private static function removeLinkHtmlApi( $type, $content, $link ) {
		$content     = (string) $content;
		$processor   = new BlcHtmlTagProcessor( $content );
		$relativeUrl = self::makeUrlRelative( $link->url );
		$baseUrl     = self::baseUrlForLink( $link );
		$seekTo      = null;

		while ( true ) {
			if ( $seekTo ) {
				$processor->seek( $seekTo );
				$processor->release_bookmark( $seekTo );
				$seekTo = null;
			} elseif ( ! $processor->next_tag( 'a' ) ) {
				break;
			}

			if ( ! self::hrefMatchesUrl( $processor->get_attribute( 'href' ), $link->url, $relativeUrl, $baseUrl ) ) {
				continue;
			}

			// Bookmark the opener to get its byte offsets.
			$openerBookmark = 'blc_opener';
			$processor->set_bookmark( $openerBookmark );
			$openerStart  = $processor->getBookmarkStart( $openerBookmark );
			$openerLength = $processor->getBookmarkLength( $openerBookmark );
			$processor->release_bookmark( $openerBookmark );

			// If bookmark internals changed, signal caller to fall back to regex.
			if ( null === $openerStart || null === $openerLength ) {
				return null;
			}

			$openerEnd = $openerStart + $openerLength;

			// Walk tokens to find the closing </a> and collect anchor text.
			$walk = self::walkToAnchorCloser( $processor, 'blc_closer' );

			if ( $walk['nestedOpenerBookmark'] ) {
				$seekTo = $walk['nestedOpenerBookmark'];
			}

			// Skip malformed HTML with no closing </a>.
			if ( ! $walk['foundCloser'] ) {
				continue;
			}

			$closerStart  = $processor->getBookmarkStart( 'blc_closer' );
			$closerLength = $processor->getBookmarkLength( 'blc_closer' );
			$processor->release_bookmark( 'blc_closer' );

			// If bookmark internals changed, signal caller to fall back to regex.
			if ( null === $closerStart || null === $closerLength ) {
				return null;
			}

			$closerEnd = $closerStart + $closerLength;

			if ( ! self::anchorMatches( $walk['anchorText'], $link->anchor ) ) {
				continue;
			}

			// Extract inner HTML and replace the full <a>...</a> with just the inner content.
			$innerHtml  = substr( $content, $openerEnd, $closerStart - $openerEnd );
			$newContent = substr_replace( $content, $innerHtml, $openerStart, $closerEnd - $openerStart );

			if ( $newContent === $content ) {
				return null;
			}

			return self::persistContent( $type, $link, $newContent );
		}

		// No matching link found — fall through to regex.
		return null;
	}

	/**
	 * Removes a given link using regex.
	 *
	 * @since   1.0.0
	 * @since   1.3.0 Extracted from removeLink().
	 * @version 1.3.1 Takes the object type and its content instead of a post.
	 *
	 * @param  ObjectType $type    The object type.
	 * @param  string     $content The object's content.
	 * @param  object     $link    The link object.
	 * @return bool                Whether the Link was unlinked.
	 */
	private static function removeLinkRegex( $type, $content, $link ) {
		$escapedAnchor = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->anchor );
		$phraseHtml    = (string) $link->phrase_html;
		$newPhraseHtml = preg_replace( "/<a.*?>([\s\w<>]*?{$escapedAnchor}[\s\w<>\/]*?)<\/a>/is", '$1', $phraseHtml ) ?? $phraseHtml;

		if ( self::checkIsRelativeUrl( $link->url ) ) {
			$escapedUrl              = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->url );
			$escapedAnchorForReplace = aioseoBrokenLinkChecker()->helpers->escapeRegexReplacement( $link->anchor );
			$newPhraseHtml           = preg_replace( "/<a.*?href=\"{$escapedUrl}\".*?>[\s\w<>]*?{$escapedAnchor}[\s\w<>\/]*?<\/a>/is", $escapedAnchorForReplace, $newPhraseHtml ) ?? $newPhraseHtml; // phpcs:ignore Generic.Files.LineLength.MaxExceeded
		}

		return self::updateLinkInContent( $type, $content, $link, $newPhraseHtml, true );
	}

	/**
	 * Adds, updates or removes a link in the content.
	 *
	 * @since   1.2.3
	 * @version 1.3.1 Takes the object type and its content instead of a post.
	 *
	 * @param  ObjectType $type          The object type.
	 * @param  string     $content       The object's content.
	 * @param  object     $link          The link object.
	 * @param  string     $newPhraseHtml The new phrase HTML.
	 * @param  bool       $isDeletion    Whether the link is being deleted.
	 * @return bool                      Whether the link was updated/deleted.
	 */
	private static function updateLinkInContent( $type, $content, $link, $newPhraseHtml, $isDeletion = false ) {
		if ( ! $type->canEdit( (int) $link->object_id, (string) $link->object_subtype ) ) {
			return false;
		}

		$postContent   = str_replace( '&nbsp;', ' ', (string) $content );
		$original      = $postContent;
		$oldPhraseHtml = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->phrase_html );
		$pattern       = "/$oldPhraseHtml/i";

		$postContent = preg_replace( $pattern, aioseoBrokenLinkChecker()->helpers->escapeRegexReplacement( $newPhraseHtml ), (string) $postContent ) ?? $postContent;

		// If the phrase is still there and we're deleting, attempt to remove it without the phrase if it occurs just once.
		if ( $isDeletion && preg_match( $pattern, $postContent ) ) {
			// Check if the post has just one occurence of this link.
			$escapedAnchor = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->anchor );
			$escapedUrl    = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->url );
			$pattern2      = "/<a.*?href=\"{$escapedUrl}\".*?>[\s\w<>]*?{$escapedAnchor}[\s\w<>\/]*?<\/a>/is";
			preg_match_all( $pattern2, $postContent, $matches );

			// If there's just one match, remove it without the phrase.
			if ( isset( $matches[0] ) && 1 === count( $matches[0] ) ) {
				$escapedAnchorReplacement = aioseoBrokenLinkChecker()->helpers->escapeRegexReplacement( $link->anchor );
				$postContent              = preg_replace( $pattern2, $escapedAnchorReplacement, $postContent ) ?? $postContent;
			}
		}

		// Check again. If the phrase is still the same, bail.
		if ( preg_match( $pattern, $postContent ) ) {
			return false;
		}

		// The phrase being absent is not the same as having replaced it: the content may have moved on
		// since it was indexed, in which case nothing matched and there is nothing to persist. Saving
		// here would report a rewrite the content never took.
		if ( $postContent === $original ) {
			return false;
		}

		return self::persistContent( $type, $link, $postContent );
	}

	/**
	 * Persists modified content back to the object it came from.
	 *
	 * Handles the limitModifiedDate option, the write itself, rescan scheduling, and link record
	 * deletion.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Takes the object type instead of a post, and writes through it.
	 *
	 * @param  ObjectType $type       The object type.
	 * @param  object     $link       The link object.
	 * @param  string     $newContent The new content.
	 * @return bool                   Whether the content was saved.
	 */
	private static function persistContent( $type, $link, $newContent ) {
		$objectId = (int) $link->object_id;
		$subtype  = (string) $link->object_subtype;
		$isPost   = 'post' === $type->type();

		// Reset modified date when the post is updated if the option is enabled. Only a post has one.
		$preserveDateCallback = null;
		if ( $isPost && aioseoBrokenLinkChecker()->options->general->linkTweaks->limitModifiedDate ) {
			$post = get_post( $objectId );

			$preserveDateCallback = function ( $data ) use ( $post ) {
				$data['post_modified']     = $post->post_modified;
				$data['post_modified_gmt'] = $post->post_modified_gmt;

				return $data;
			};
			add_filter( 'wp_insert_post_data', $preserveDateCallback, 99999, 1 );
		}

		$saved = $type->saveContent( $objectId, $subtype, $newContent );

		// Remove the one-shot filter to prevent it from affecting subsequent saves in the same request.
		if ( $preserveDateCallback ) {
			remove_filter( 'wp_insert_post_data', $preserveDateCallback, 99999 );
		}

		if ( ! $saved ) {
			return false;
		}

		if ( ! $isPost ) {
			// No "save_post" equivalent to ride on, so the reindex happens here. It replaces every link
			// record the object holds, this one included.
			aioseoBrokenLinkChecker()->main->links->data->indexObjectLinks( $type->type(), $objectId, $subtype );

			return true;
		}

		// Indicate that the post needs to be rescanned.
		aioseoBrokenLinkChecker()->main->links->postsToRescan[] = $objectId;

		// The "save_post" callback will trigger a rescan of the post, so we can delete the existing Link record.
		$link->delete();

		return true;
	}

	/**
	 * Checks if the given URL is relative.
	 *
	 * @since 1.2.3
	 *
	 * @param  string $url The URL to check.
	 * @return bool        Whether the URL is relative.
	 */
	private static function checkIsRelativeUrl( $url ) {
		$parsedUrl = wp_parse_url( $url );
		if ( ! $parsedUrl ) {
			return false;
		}

		return empty( $parsedUrl['scheme'] ) && empty( $parsedUrl['host'] );
	}

	/**
	 * Makes the given URL relative.
	 *
	 * @since 1.2.3
	 *
	 * @param  string $url The URL to make relative.
	 * @return string      The relative URL.
	 */
	private static function makeUrlRelative( $url ) {
		$parsedUrl = wp_parse_url( $url );
		if ( ! $parsedUrl || empty( $parsedUrl['path'] ) ) {
			return $url;
		}

		$relative = $parsedUrl['path'];
		if ( ! empty( $parsedUrl['query'] ) ) {
			$relative .= '?' . $parsedUrl['query'];
		}

		return $relative;
	}

	/**
	 * The URL a link's hrefs were written against, which is the one the scan resolved them with.
	 *
	 * @since 1.3.1
	 *
	 * @param  object $link The link row.
	 * @return string       The base URL, or an empty string when the object cannot supply one.
	 */
	private static function baseUrlForLink( $link ) {
		// Read as an array: the callers hand over a model on one path and a query row on another, and
		// the object columns are only present on the ones that carry them.
		$fields = (array) $link;
		if ( empty( $fields['object_type'] ) ) {
			return '';
		}

		$type = aioseoBrokenLinkChecker()->objects->get( (string) $fields['object_type'] );
		if ( ! $type ) {
			return '';
		}

		$objectId = isset( $fields['object_id'] ) ? (int) $fields['object_id'] : 0;
		$subtype  = isset( $fields['object_subtype'] ) ? (string) $fields['object_subtype'] : '';

		return (string) $type->baseUrl( $objectId, $subtype );
	}

	/**
	 * Checks if an href attribute value matches a stored URL.
	 *
	 * NOTE: The resolver answers first, because it is what wrote the stored URL - trimming, a
	 * backslash, a default port, a bare query, dot segments and a non-ASCII path all included. The
	 * comparisons below it stay for a row an older resolver stored, whose form it would not produce.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Resolves the href the way the scan did, ahead of comparing strings.
	 *
	 * @param  string|null $href        The href attribute value from the HTML.
	 * @param  string      $storedUrl   The absolute URL stored in the DB.
	 * @param  string      $relativeUrl The relative version of the stored URL.
	 * @param  string      $baseUrl     The URL of the object the link was written on.
	 * @return bool                     Whether the href matches.
	 */
	private static function hrefMatchesUrl( $href, $storedUrl, $relativeUrl, $baseUrl = '' ) {
		if ( ! is_string( $href ) || '' === $href ) {
			return false;
		}

		// Asked of the resolver rather than worked out again here. The scan stored whatever it made of
		// this href, so resolving it the same way is the one comparison that cannot drift from it.
		if ( '' !== $baseUrl ) {
			$resolved = Url::resolve( $href, $baseUrl );

			// Compared against both forms, because Link::applyKeys() decodes the column on load when it
			// decodes to text - so an escaped path arrives here decoded and never equals what we resolved.
			$decoded = rawurldecode( $resolved );
			$asModel = '' !== wp_check_invalid_utf8( $decoded ) ? $decoded : $resolved;

			if ( '' !== $resolved && ( $resolved === $storedUrl || $asModel === $storedUrl ) ) {
				return true;
			}
		}

		// Strip URL fragment — stored URLs have fragments removed during extraction.
		$fragmentPos = strpos( $href, '#' );
		if ( false !== $fragmentPos ) {
			$href = substr( $href, 0, $fragmentPos );
			if ( '' === $href ) {
				return false;
			}
		}

		// Decode percent-encoding to match the stored URL, which is decoded
		// by rawurldecode() in Link::applyKeys() on every model load.
		$href = rawurldecode( $href );

		// Exact match (most common case).
		if ( $href === $storedUrl || $href === $relativeUrl ) {
			return true;
		}

		// Trailing-slash-tolerant match.
		$hrefNormalized = untrailingslashit( $href );
		if ( untrailingslashit( $storedUrl ) === $hrefNormalized || untrailingslashit( $relativeUrl ) === $hrefNormalized ) {
			return true;
		}

		// Scheme-agnostic match: strip http:/https: prefix and compare.
		// Only the host is case-insensitive per RFC 3986; the path is case-sensitive.
		$hrefSchemeless   = preg_replace( '#^https?:#i', '', $hrefNormalized ) ?? $hrefNormalized;
		$storedSchemeless = preg_replace( '#^https?:#i', '', untrailingslashit( $storedUrl ) ) ?? untrailingslashit( $storedUrl );

		// Normalize hosts to lowercase for comparison while keeping paths case-sensitive.
		$hrefSchemeless   = preg_replace_callback( '#^//[^/]+#', function ( $m ) {
			return strtolower( $m[0] );
		}, $hrefSchemeless ) ?? $hrefSchemeless;
		$storedSchemeless = preg_replace_callback( '#^//[^/]+#', function ( $m ) {
			return strtolower( $m[0] );
		}, $storedSchemeless ) ?? $storedSchemeless;

		if ( $hrefSchemeless === $storedSchemeless ) {
			return true;
		}

		return false;
	}

	/**
	 * Walks tokens from the current position inside an <a> tag to its closing </a>.
	 *
	 * Collects the anchor text from text nodes. Optionally sets a bookmark
	 * on the closing </a> tag (the caller is responsible for releasing it).
	 *
	 * @since 1.3.0
	 *
	 * @param  \WP_HTML_Tag_Processor $processor       The processor, positioned on an <a> opener.
	 * @param  string|null            $closerBookmark  Optional bookmark name to set on the </a> closer.
	 * @return array{anchorText: string, foundCloser: bool, nestedOpenerBookmark: string|null}
	 */
	private static function walkToAnchorCloser( $processor, $closerBookmark = null ) {
		$anchorText           = '';
		$foundCloser          = false;
		$nestedOpenerBookmark = null;

		while ( $processor->next_token() ) {
			$tokenType = $processor->get_token_type();

			if ( '#text' === $tokenType ) {
				$anchorText .= $processor->get_modifiable_text();

				continue;
			}

			if ( '#tag' !== $tokenType || 'A' !== $processor->get_tag() ) {
				continue;
			}

			// Nested opener (malformed HTML) — bookmark for reprocessing, stop walking.
			if ( ! $processor->is_tag_closer() ) {
				if ( $processor->set_bookmark( 'blc_nested_opener' ) ) {
					$nestedOpenerBookmark = 'blc_nested_opener';
				}
				break;
			}

			$foundCloser = true;
			if ( $closerBookmark ) {
				$processor->set_bookmark( $closerBookmark );
			}

			break;
		}

		return [
			'anchorText'           => trim( $anchorText ),
			'foundCloser'          => $foundCloser,
			'nestedOpenerBookmark' => $nestedOpenerBookmark
		];
	}

	/**
	 * Replaces anchor text inside the current <a> tag.
	 *
	 * Walks tokens from the current position, looking for the stored anchor
	 * in text nodes. Uses html_entity_decode on the stored anchor so it matches
	 * the decoded text returned by get_modifiable_text().
	 *
	 * Returns false if the anchor spans multiple text nodes (e.g. "click <em>here</em>"),
	 * signalling the caller to fall through to regex.
	 *
	 * @since 1.3.0
	 *
	 * @param  \WP_HTML_Tag_Processor $processor    The processor, positioned on an <a> opener.
	 * @param  string                 $storedAnchor The anchor text stored in the DB.
	 * @param  string                 $newAnchor    The new anchor text.
	 * @return bool                                 Whether the anchor was replaced.
	 */
	private static function replaceAnchorText( $processor, $storedAnchor, $newAnchor ) {
		if ( '' === $storedAnchor ) {
			return false;
		}

		$decodedAnchor = html_entity_decode( $storedAnchor, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		while ( $processor->next_token() ) {
			$tokenType = $processor->get_token_type();

			// Stop at the closing </a> or a nested opener (malformed HTML).
			if ( '#tag' === $tokenType && 'A' === $processor->get_tag() ) {
				break;
			}

			if ( '#text' !== $tokenType ) {
				continue;
			}

			$text = $processor->get_modifiable_text();

			// Try exact decoded match first (preserves byte offsets).
			$anchorPos    = strpos( $text, $decodedAnchor );
			$anchorLength = strlen( $decodedAnchor );

			// Falls through to regex to avoid corrupting surrounding nbsp/whitespace.
			if ( false === $anchorPos ) {
				return false;
			}

			$processor->set_modifiable_text(
				substr_replace( $text, $newAnchor, $anchorPos, $anchorLength )
			);

			return true;
		}

		return false;
	}

	/**
	 * Normalizes anchor text for comparison.
	 *
	 * Decodes HTML entities, normalizes non-breaking spaces (U+00A0) to regular
	 * spaces, and collapses whitespace. This ensures that anchor text from
	 * get_modifiable_text() (which returns decoded text) can be compared with
	 * stored anchor text (which may contain &nbsp; entities).
	 *
	 * @since 1.3.0
	 *
	 * @param  string $text The anchor text to normalize.
	 * @return string       The normalized text.
	 */
	private static function normalizeAnchorText( $text ) {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? $text );

		return $text;
	}

	/**
	 * Checks if the collected anchor text matches the stored anchor.
	 *
	 * Performs exact comparison after normalizing HTML entities,
	 * non-breaking spaces, and whitespace on both sides.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $anchorText   The anchor text collected from the HTML.
	 * @param  string $storedAnchor The anchor text stored in the DB.
	 * @return bool                 Whether the anchor matches.
	 */
	private static function anchorMatches( $anchorText, $storedAnchor ) {
		// Empty stored anchor (e.g. image-only link) — match only if collected text is also empty.
		if ( empty( $storedAnchor ) ) {
			return '' === trim( $anchorText );
		}

		$normalizedText   = self::normalizeAnchorText( $anchorText );
		$normalizedAnchor = self::normalizeAnchorText( $storedAnchor );

		return $normalizedText === $normalizedAnchor;
	}
}