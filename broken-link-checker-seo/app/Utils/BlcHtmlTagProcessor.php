<?php
namespace AIOSEO\BrokenLinkChecker\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends WP_HTML_Tag_Processor to expose bookmark byte offsets.
 *
 * Used for link extraction and unlinking, where we need byte positions
 * to extract phrase context or determine tag boundaries via substr().
 *
 * Requires WP 6.6+ — all callers gate on this version before
 * instantiating this class.
 *
 * NOTE: This relies on WP_HTML_Tag_Processor internals ($this->bookmarks
 * storing WP_HTML_Span objects with public $start and $length).
 * A runtime check guards against future WP core changes — if the
 * internal structure changes, the getters return null and the caller
 * falls back to regex-based extraction.
 *
 * @since 1.3.0
 */
class BlcHtmlTagProcessor extends \WP_HTML_Tag_Processor {
	/**
	 * Returns the byte offset of a bookmark in the HTML string.
	 *
	 * @since 1.3.0
	 *
	 * @param  string   $name The bookmark name.
	 * @return int|null       The byte offset, or null if the bookmark does not exist
	 *                        or the internal structure is unexpected.
	 */
	public function getBookmarkStart( $name ) {
		$span = $this->getBookmarkSpan( $name );

		return $span ? $span->start : null;
	}

	/**
	 * Returns the byte length of a bookmark in the HTML string.
	 *
	 * @since 1.3.0
	 *
	 * @param  string   $name The bookmark name.
	 * @return int|null       The byte length, or null if the bookmark does not exist
	 *                        or the internal structure is unexpected.
	 */
	public function getBookmarkLength( $name ) {
		$span = $this->getBookmarkSpan( $name );

		return $span ? $span->length : null;
	}

	/**
	 * Returns the bookmark span object, or null if it doesn't exist or has an unexpected structure.
	 *
	 * @since 1.3.0
	 *
	 * @param  string      $name The bookmark name.
	 * @return object|null       The span object with start and length properties, or null.
	 */
	private function getBookmarkSpan( $name ) {
		if ( ! isset( $this->bookmarks[ $name ] ) ) {
			return null;
		}

		$span = $this->bookmarks[ $name ];

		// Guard against WP core changing the bookmark internal structure.
		if ( ! is_object( $span ) || ! property_exists( $span, 'start' ) || ! property_exists( $span, 'length' ) ) {
			return null;
		}

		return $span;
	}
}