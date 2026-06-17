<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;
use AIOSEO\BrokenLinkChecker\Utils\BlcHtmlTagProcessor;

/**
 * Handles all common table action handlers.
 *
 * @since 1.1.0
 */
abstract class CommonTableActions {
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
			$links = Models\Link::getByLinkStatusId( $linkStatusId );
			foreach ( $links as $link ) {
				$post = get_post( $link->post_id );

				// Skip orphaned links whose post no longer exists.
				if ( ! is_a( $post, 'WP_Post' ) ) {
					$link->delete();

					continue;
				}

				// Confirm user has permission to edit the post.
				if ( ! current_user_can( 'edit_post', $link->post_id ) ) {
					return new \WP_REST_Response( [
						'success' => false,
						'message' => 'User does not have permission to edit this post.'
					], 403 );
				}

				self::removeLink( $link->id );
			}

			return new \WP_REST_Response( [
				'success' => true
			], 200 );
		}

		$success = self::removeLink( $linkId );
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
	 * Rechecks the given links.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Moved from BrokenLinks to TableActions and add support for bulk-checking rows.
	 *
	 * @param  array       $linkStatusRows The Link Status rows.
	 * @return object|bool                 The response or false if the links could not be checked.
	 */
	protected static function recheckLinks( $linkStatusRows ) {
		$linkStatusIds = array_map( function( $linkStatusRow ) {
			return $linkStatusRow['id'];
		}, $linkStatusRows );

		$linkStatuses = Models\LinkStatus::getByIds( $linkStatusIds );
		if ( empty( $linkStatuses ) ) {
			return false;
		}

		$rows = [];
		foreach ( $linkStatuses as $linkStatus ) {
			$rows[ $linkStatus->id ] = $linkStatus->url;
		}

		$requestBody = array_merge(
			aioseoBrokenLinkChecker()->main->linkStatus->data->getBaseData(),
			[ 'rows' => $rows ]
		);

		$response     = aioseoBrokenLinkChecker()->main->linkStatus->doPostRequest( 'recheck-bulk', $requestBody );
		$responseCode = (int) wp_remote_retrieve_response_code( $response );
		$responseBody = json_decode( wp_remote_retrieve_body( $response ) );
		if ( is_wp_error( $response ) && 200 !== $responseCode || empty( $responseBody->success ) || empty( $responseBody->rows ) ) {
			return false;
		}

		foreach ( $responseBody->rows as $row ) {
			// Parse the data into a useable format and then save the updated results.
			aioseoBrokenLinkChecker()->main->linkStatus->parseResultsHelper( $row );
		}

		return $responseBody;
	}

	/**
	 * Updates a given link with a new anchor and/or URL.
	 *
	 * Routes to the HTML API path (WP 6.7+) or falls back to the regex path.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Refactored into junction between HtmlApi and Regex methods.
	 *
	 * @param  int    $linkId    The Link ID.
	 * @param  string $newAnchor The new anchor.
	 * @param  string $newUrl    The new URL.
	 * @return bool              Whether the Link was updated.
	 */
	protected static function updateLink( $linkId, $newAnchor = '', $newUrl = '' ) {
		$link = Models\Link::getById( $linkId );
		if ( ! $link->exists() ) {
			return false;
		}

		$post = get_post( $link->post_id );
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return false;
		}

		// Confirm user has permission to edit the post.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		if ( empty( $newAnchor ) && empty( $newUrl ) ) {
			return false;
		}

		// WP 6.7+: set_modifiable_text() is available for anchor text replacement.
		if ( version_compare( get_bloginfo( 'version' ), '6.7', '>=' ) ) {
			$result = self::updateLinkHtmlApi( $post, $link, $newAnchor, $newUrl );
			if ( true === $result ) {
				return true;
			}
		}

		return self::updateLinkRegex( $post, $link, $newAnchor, $newUrl );
	}

	/**
	 * Updates a given link using WP_HTML_Tag_Processor directly on post_content.
	 *
	 * Matches the specific link instance by both href and anchor text, then updates
	 * only the first matching occurrence. Uses a bookmark to seek back after
	 * verifying the anchor text, so only one processor pass is needed.
	 *
	 * @since 1.3.0
	 *
	 * @param  \WP_Post $post      The post object.
	 * @param  object   $link      The link object.
	 * @param  string   $newAnchor The new anchor.
	 * @param  string   $newUrl    The new URL.
	 * @return bool                Whether the Link was updated.
	 */
	private static function updateLinkHtmlApi( $post, $link, $newAnchor, $newUrl ) {
		$content     = (string) $post->post_content;
		$processor   = new \WP_HTML_Tag_Processor( $content );
		$relativeUrl = self::makeUrlRelative( $link->url );
		$seekTo      = null;

		while ( true ) {
			if ( $seekTo ) {
				$processor->seek( $seekTo );
				$processor->release_bookmark( $seekTo );
				$seekTo = null;
			} elseif ( ! $processor->next_tag( 'a' ) ) {
				break;
			}

			if ( ! self::hrefMatchesUrl( $processor->get_attribute( 'href' ), $link->url, $relativeUrl ) ) {
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
				$processor->set_attribute( 'href', $newUrl );
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

			return self::persistContent( $post, $link, $newContent );
		}

		return false;
	}

	/**
	 * Updates a given link using regex.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Extracted from updateLink().
	 *
	 * @param  \WP_Post $post      The post object.
	 * @param  object   $link      The link object.
	 * @param  string   $newAnchor The new anchor.
	 * @param  string   $newUrl    The new URL.
	 * @return bool                Whether the Link was updated.
	 */
	private static function updateLinkRegex( $post, $link, $newAnchor, $newUrl ) {
		$oldAnchor     = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->anchor );
		$oldUrl        = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->url );
		$escapedAnchor = aioseoBrokenLinkChecker()->helpers->escapeRegexReplacement( $newAnchor ?: $link->anchor );
		$escapedUrl    = aioseoBrokenLinkChecker()->helpers->escapeRegexReplacement( $newUrl ?: $link->url );

		$newPhraseHtml = preg_replace( "/(<a.*?href=\")($oldUrl)(\".*?>[\s\w]*?)(<[^>]+>)?($oldAnchor)(<\/[^>]+>)?([\s\w]*?<\/a>)/is", "$1$escapedUrl$3$4$escapedAnchor$6$7", $link->phrase_html ) ?? $link->phrase_html; // phpcs:ignore Generic.Files.LineLength.MaxExceeded

		$success = self::updateLinkInContent( $post, $link, $newPhraseHtml );
		if ( ! $success ) {
			// It's possible that the update failed because the original/old URL is relative in the phrase HTML.
			// In that case, make the old URL relative to match it.
			// This is needed because we make URLs absolute before storing them in the DB.
			$relativeUrl = self::makeUrlRelative( $link->url );
			if ( $relativeUrl !== $link->url ) {
				$oldUrl        = aioseoBrokenLinkChecker()->helpers->escapeRegex( $relativeUrl );
				$newPhraseHtml = preg_replace( "/(<a.*?href=\")($oldUrl)(\".*?>[\s\w]*?)(<[^>]+>)?($oldAnchor)(<\/[^>]+>)?([\s\w]*?<\/a>)/is", "$1$escapedUrl$3$4$escapedAnchor$6$7", $link->phrase_html ) ?? $link->phrase_html; // phpcs:ignore Generic.Files.LineLength.MaxExceeded

				$success = self::updateLinkInContent( $post, $link, $newPhraseHtml );
			}
		}

		return $success;
	}

	/**
	 * Removes a given link.
	 *
	 * Routes to the HTML API path (WP 6.6+) or falls back to the regex path.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Moved from BrokenLinks to TableActions.
	 * @since   1.3.0 Refactored into junction between HtmlApi and Regex methods.
	 *
	 * @param  int  $linkId The Link ID.
	 * @return bool         Whether the Link was unlinked.
	 */
	protected static function removeLink( $linkId ) {
		$link = Models\Link::getById( $linkId );
		if ( ! $link->exists() ) {
			return false;
		}

		$post = get_post( $link->post_id );
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return false;
		}

		// Confirm user has permission to edit the post.
		if ( ! current_user_can( 'edit_post', $link->post_id ) ) {
			return false;
		}

		// WP 6.6+: next_token() (6.5) and the bookmark length fix (6.6, Trac #61301) are available.
		if ( version_compare( get_bloginfo( 'version' ), '6.6', '>=' ) ) {
			$result = self::removeLinkHtmlApi( $post, $link );

			// Only fall through to regex on null (bookmark internals failed).
			// true/false are definitive results from the HTML API path.
			if ( null !== $result ) {
				return $result;
			}
		}

		return self::removeLinkRegex( $post, $link );
	}

	/**
	 * Removes a given link using BlcHtmlTagProcessor directly on post_content.
	 *
	 * Matches the specific link instance by both href and anchor text,
	 * extracts the inner HTML via byte offsets, and replaces the full
	 * <a>...</a> tag with just the inner content.
	 *
	 * @since 1.3.0
	 *
	 * @param  \WP_Post  $post The post object.
	 * @param  object    $link The link object.
	 * @return bool|null       True on success, null if no match or bookmark internals failed.
	 */
	private static function removeLinkHtmlApi( $post, $link ) {
		$content     = (string) $post->post_content;
		$processor   = new BlcHtmlTagProcessor( $content );
		$relativeUrl = self::makeUrlRelative( $link->url );
		$seekTo      = null;

		while ( true ) {
			if ( $seekTo ) {
				$processor->seek( $seekTo );
				$processor->release_bookmark( $seekTo );
				$seekTo = null;
			} elseif ( ! $processor->next_tag( 'a' ) ) {
				break;
			}

			if ( ! self::hrefMatchesUrl( $processor->get_attribute( 'href' ), $link->url, $relativeUrl ) ) {
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

			return self::persistContent( $post, $link, $newContent );
		}

		// No matching link found — fall through to regex.
		return null;
	}

	/**
	 * Removes a given link using regex.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Extracted from removeLink().
	 *
	 * @param  \WP_Post $post The post object.
	 * @param  object   $link The link object.
	 * @return bool           Whether the Link was unlinked.
	 */
	private static function removeLinkRegex( $post, $link ) {
		$escapedAnchor = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->anchor );
		$phraseHtml    = (string) $link->phrase_html;
		$newPhraseHtml = preg_replace( "/<a.*?>([\s\w<>]*?{$escapedAnchor}[\s\w<>\/]*?)<\/a>/is", '$1', $phraseHtml ) ?? $phraseHtml;

		if ( self::checkIsRelativeUrl( $link->url ) ) {
			$escapedUrl              = aioseoBrokenLinkChecker()->helpers->escapeRegex( $link->url );
			$escapedAnchorForReplace = aioseoBrokenLinkChecker()->helpers->escapeRegexReplacement( $link->anchor );
			$newPhraseHtml           = preg_replace( "/<a.*?href=\"{$escapedUrl}\".*?>[\s\w<>]*?{$escapedAnchor}[\s\w<>\/]*?<\/a>/is", $escapedAnchorForReplace, $newPhraseHtml ) ?? $newPhraseHtml; // phpcs:ignore Generic.Files.LineLength.MaxExceeded
		}

		return self::updateLinkInContent( $post, $link, $newPhraseHtml, true );
	}

	/**
	 * Adds, updates or removes a link in the content.
	 *
	 * @since 1.2.3
	 *
	 * @param  \WP_Post $post          The post object.
	 * @param  object   $link          The link object.
	 * @param  string   $newPhraseHtml The new phrase HTML.
	 * @param  bool     $isDeletion    Whether the link is being deleted.
	 * @return bool                    Whether the link was updated/deleted.
	 */
	private static function updateLinkInContent( $post, $link, $newPhraseHtml, $isDeletion = false ) {
		// Confirm user has permission to edit the post.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		$postContent   = str_replace( '&nbsp;', ' ', (string) $post->post_content );
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

		return self::persistContent( $post, $link, $postContent );
	}

	/**
	 * Persists modified post content to the database.
	 *
	 * Handles the limitModifiedDate option, wp_update_post call,
	 * rescan scheduling, and link record deletion.
	 *
	 * @since 1.3.0
	 *
	 * @param  \WP_Post $post       The post object.
	 * @param  object   $link       The link object.
	 * @param  string   $newContent The new post content.
	 * @return bool                 Whether the content was saved.
	 */
	private static function persistContent( $post, $link, $newContent ) {
		// Reset modified date when the post is updated if the option is enabled.
		$limitModifiedDate = aioseoBrokenLinkChecker()->options->general->linkTweaks->limitModifiedDate;
		$preserveDateCallback = null;
		if ( $limitModifiedDate ) {
			$preserveDateCallback = function ( $data ) use ( $post ) {
				$data['post_modified']     = $post->post_modified;
				$data['post_modified_gmt'] = $post->post_modified_gmt;

				return $data;
			};
			add_filter( 'wp_insert_post_data', $preserveDateCallback, 99999, 1 );
		}

		// wp_update_post() → wp_insert_post() expects slashed data and calls wp_unslash()
		// internally, so the content must be pre-slashed to preserve literal backslashes.
		$error = wp_update_post( [
			'ID'           => $link->post_id,
			'post_content' => wp_slash( $newContent )
		], true );

		// Remove the one-shot filter to prevent it from affecting subsequent saves in the same request.
		if ( $preserveDateCallback ) {
			remove_filter( 'wp_insert_post_data', $preserveDateCallback, 99999 );
		}

		if ( 0 === $error || is_a( $error, 'WP_Error' ) ) {
			return false;
		}

		// Indicate that the post needs to be rescanned.
		aioseoBrokenLinkChecker()->main->links->postsToRescan[] = $link->post_id;

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
	 * Checks if an href attribute value matches a stored URL.
	 *
	 * Performs a scheme-agnostic, trailing-slash-tolerant comparison.
	 *
	 * @since 1.3.0
	 *
	 * @param  string|null $href        The href attribute value from the HTML.
	 * @param  string      $storedUrl   The absolute URL stored in the DB.
	 * @param  string      $relativeUrl The relative version of the stored URL.
	 * @return bool                     Whether the href matches.
	 */
	private static function hrefMatchesUrl( $href, $storedUrl, $relativeUrl ) {
		if ( ! is_string( $href ) || '' === $href ) {
			return false;
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