<?php
namespace AIOSEO\BrokenLinkChecker\Links;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;
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
		$post = get_post( $postId );
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		// Delete all links first. We have to do this in order to remove old links that no longer exist.
		Models\Link::deleteLinks( $postId );

		$links = $this->extractLinks( $postId, $post->post_content );
		if ( empty( $links ) ) {
			return;
		}

		$this->storeLinks( $links );
	}

	/**
	 * Stores the given links to the DB.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $links The links.
	 * @return void
	 */
	private function storeLinks( $links ) {
		$columns    = [
			'post_id',
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
			'created',
			'updated'
		];
		$currentDate = gmdate( 'Y-m-d H:i:s' );

		$urls = [];
		$rows = [];
		foreach ( $links as $linkData ) {
			$data = Models\Link::sanitizeLink( $linkData );
			if ( empty( $data ) ) {
				continue;
			}

			if ( ! Models\Link::validateLink( $data ) ) {
				continue;
			}

			$urls[ $data['url_hash'] ] = $data['url'];

			$rows[] = array_merge( array_values( $data ), [ $currentDate, $currentDate ] );
		}

		aioseoBrokenLinkChecker()->core->db->bulkInsert( 'aioseo_blc_links', $columns, $rows );

		$existing = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->select( 'url_hash' )
			->whereIn( 'url_hash', array_keys( $urls ) )
			->run()
			->result();

		foreach ( $existing as $row ) {
			unset( $urls[ $row->url_hash ] );
		}

		if ( empty( $urls ) ) {
			return;
		}

		foreach ( $urls as $hash => $url ) {
			$statusId = aioseoBrokenLinkChecker()->core->db->insert( 'aioseo_blc_link_status' )
				->set( [
					'url'      => $url,
					'url_hash' => $hash,
					'created'  => aioseoBrokenLinkChecker()->helpers->timeToMysql( time() ),
					'updated'  => aioseoBrokenLinkChecker()->helpers->timeToMysql( time() )
				] )
				->run()
				->insertId();

			aioseoBrokenLinkChecker()->core->db->update( 'aioseo_blc_links' )
				->where( 'url', $url )
				->set( [
					'blc_link_status_id' => $statusId
				] )
				->run();
		}
	}

	/**
	 * Returns the links that are in the post content.
	 *
	 * Uses WP_HTML_Tag_Processor (WP 6.6+) for reliable HTML parsing that handles
	 * all quote styles, attribute orders and edge cases. Falls back to regex on older WP versions.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Rewritten to use WP_HTML_Tag_Processor with regex fallback.
	 *
	 * @param  int    $postId      The post ID.
	 * @param  string $postContent The post content.
	 * @return array               The links.
	 */
	private function extractLinks( $postId, $postContent ) {
		$postContent = aioseoBrokenLinkChecker()->helpers->decodeHtmlEntities( $postContent );

		// Strip data URIs to prevent catastrophic backtracking.
		$postContent = preg_replace( '/data:[^;]+;base64,[^"]+/', '', (string) $postContent ) ?? $postContent;

		// WP 6.6+: next_token() (6.5) and the bookmark length fix (6.6, Trac #61301) are available.
		if ( version_compare( get_bloginfo( 'version' ), '6.6', '>=' ) ) {
			$links = $this->extractLinksHtmlApi( $postId, $postContent );

			// Non-null means the HTML API path completed (even if zero links found).
			// Null means bookmark internals failed and we should fall back to regex.
			if ( null !== $links ) {
				// The HTML API path only walks <a> tags, so oEmbed video blocks are extracted separately.
				return array_merge( $links, $this->extractEmbeddedVideos( $postId, $postContent ) );
			}
		}

		return $this->extractLinksRegex( $postId, $postContent );
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
	 * @since 1.3.0
	 *
	 * @param  int    $postId      The post ID.
	 * @param  string $postContent The preprocessed post content.
	 * @return array|null          The links, or null if bookmark internals failed.
	 */
	private function extractLinksHtmlApi( $postId, $postContent ) {
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
			if ( ! is_string( $href ) || '' === $href || '#' === $href[0] || preg_match( '/^(tel:|mailto:|javascript:|data:)/i', $href ) ) {
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

			$parsedUrl = $this->parseUrl( $href, $postId );
			if ( empty( $parsedUrl['host'] ) ) {
				continue;
			}

			if (
				! empty( $parsedUrl['path'] ) &&
				preg_match( '/\.(.*)$/i', $parsedUrl['path'], $extension ) &&
				! empty( $extension[1] ) &&
				in_array( $extension[1], $this->ignoredExtensions, true )
			) {
				continue;
			}

			// NOTE: We need to check this here before we strip off the "www" part.
			// Otherwise we will not be able to detect internal links on sites running on "www".
			$isInternal = $parsedUrl['host'] === $this->getHostname();

			$hostname = aioseoBrokenLinkChecker()->helpers->pregReplace( '/www\./i', '', $parsedUrl['host'] );

			// Reformat the URL to get rid of params and fragments.
			$url = aioseoBrokenLinkChecker()->helpers->buildUrl( $parsedUrl, [], [ 'fragment' ] );

			// We need to sanitize the URL here so the hash is calculated based on the escaped version.
			$url = trim( sanitize_url( $url ) );
			$url = apply_filters( 'aioseo_blc_link_url_before_save', $url );

			$isVideo = $this->isVideoUrl( $url );

			$phraseHtml = $this->extractPhraseHtml( $postContent, $phraseSearchFrom, $openerStart, $closerEnd );

			$phrase = wp_strip_all_tags( $phraseHtml );
			$phrase = trim( $phrase );

			// Don't continue if the anchor or phrase are empty, e.g. blank link tag.
			// If it's a video, we don't mandate an anchor or phrase.
			if ( ( ! $anchor || ! $phrase ) && ! $isVideo ) {
				continue;
			}

			$paragraph     = aioseoBrokenLinkChecker()->main->paragraph->get( $postId, $postContent, $phrase );
			$paragraphHtml = aioseoBrokenLinkChecker()->main->paragraph->getHtml( $anchor, $paragraph, $postContent );

			$linkData = [
				'post_id'            => (int) $postId,
				'blc_link_status_id' => $this->getLinkStatusId( $url ),
				'url'                => $url,
				'url_hash'           => sha1( $url ),
				'hostname'           => $hostname,
				'hostname_url'       => sha1( $hostname ),
				'external'           => ! $isInternal,
				'anchor'             => $anchor,
				'phrase'             => $phrase,
				'phrase_html'        => $phraseHtml,
				'paragraph'          => $paragraph,
				'paragraph_html'     => $paragraphHtml,
				'is_video'           => $isVideo
			];

			$links[] = $linkData;
		}

		return $links;
	}

	/**
	 * Regex-based link extraction for WP < 6.6.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Renamed from extractLinks().
	 *
	 * @param  int    $postId      The post ID.
	 * @param  string $postContent The preprocessed post content.
	 * @return array               The links.
	 */
	private function extractLinksRegex( $postId, $postContent ) {
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

			$oEmbed      = ! empty( $matches[7][ $k ] ) ? true : false;
			$capturedUrl = $matches[4][ $k ] ? $matches[4][ $k ] : $matches[7][ $k ];
			$parsedUrl   = $this->parseUrl( $capturedUrl, $postId );
			if ( empty( $parsedUrl['host'] ) ) {
				continue;
			}

			if (
				! empty( $parsedUrl['path'] ) &&
				preg_match( '/\.(.*)$/i', $parsedUrl['path'], $extension ) &&
				! empty( $extension[1] ) &&
				in_array( $extension[1], $this->ignoredExtensions, true )
			) {
				continue;
			}

			// NOTE: We need to check this here before we strip off the "www" part.
			// Otherwise we will not be able to detect internal links on sites running on "www".
			$isInternal = $parsedUrl['host'] === $this->getHostname();
			$hostname = aioseoBrokenLinkChecker()->helpers->pregReplace( '/www\./i', '', $parsedUrl['host'] );

			// Reformat the URL to strip fragments but preserve query params (e.g. YouTube ?v=).
			$url = aioseoBrokenLinkChecker()->helpers->buildUrl( $parsedUrl, [], [ 'fragment' ] );

			$isVideo = $this->isVideoUrl( $url );

			$anchor = wp_strip_all_tags( $matches[6][ $k ] );
			// Remove trailing URL tags. The regex isn't sufficient for this.
			$phrase = wp_strip_all_tags( $matches[0][ $k ] );
			$phrase = trim( preg_replace( '/(.*)(<t?a[^<>].*$)/', '', (string) $phrase ) ?? $phrase );

			// Don't continue if the anchor or phrase are empty, e.g. blank link tag.
			// If it's a video, we don't mandate an anchor or phrase.
			if (
				( ! $anchor || ! $phrase ) &&
				! $isVideo
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
				$paragraph     = aioseoBrokenLinkChecker()->main->paragraph->get( $postId, $postContent, $phrase );
				$paragraphHtml = aioseoBrokenLinkChecker()->main->paragraph->getHtml( $anchor, $paragraph, $postContent );
			}

			// We need to sanitize the URL here so the hash is calculated based on the escaped version.
			$url = trim( sanitize_url( $url ) );
			$url = apply_filters( 'aioseo_blc_link_url_before_save', $url );

			$linkData = [
				'post_id'            => (int) $postId,
				'blc_link_status_id' => $this->getLinkStatusId( $url ),
				'url'                => $url,
				'url_hash'           => sha1( $url ),
				'hostname'           => $hostname,
				'hostname_url'       => sha1( $hostname ),
				'external'           => ! $isInternal,
				'anchor'             => $anchor,
				'phrase'             => $phrase,
				'phrase_html'        => $phraseHtml,
				'paragraph'          => $paragraph,
				'paragraph_html'     => $paragraphHtml,
				'is_video'           => $isVideo
			];

			$links[] = $linkData;
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
		$videoPatterns = [
			'/https?:\/\/((m|www)\.)?youtube\.com\/watch.*/i',
			'/https?:\/\/((m|www)\.)?youtube\.com\/playlist.*/i',
			'/https?:\/\/((m|www)\.)?youtube\.com\/shorts\/.*/i',
			'/https?:\/\/((m|www)\.)?youtube\.com\/live\/.*/i',
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
	 * Extracts oEmbed video links from Gutenberg embed block comments.
	 *
	 * The HTML API path only walks <a> tags, so embedded videos (which live in
	 * wp:embed block comments, not anchors) must be extracted separately.
	 *
	 * @since 1.3.0
	 *
	 * @param  int    $postId      The post ID.
	 * @param  string $postContent The preprocessed post content.
	 * @return array               The video links.
	 */
	private function extractEmbeddedVideos( $postId, $postContent ) {
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

			// Reformat the URL to strip fragments but preserve query params (e.g. YouTube ?v=).
			$url = aioseoBrokenLinkChecker()->helpers->buildUrl( $parsedUrl, [], [ 'fragment' ] );

			if ( ! $this->isVideoUrl( $url ) ) {
				continue;
			}

			// NOTE: We need to check this here before we strip off the "www" part.
			// Otherwise we will not be able to detect internal links on sites running on "www".
			$isInternal = $parsedUrl['host'] === $this->getHostname();
			$hostname   = aioseoBrokenLinkChecker()->helpers->pregReplace( '/www\./i', '', $parsedUrl['host'] );

			// We need to sanitize the URL here so the hash is calculated based on the escaped version.
			$url = trim( sanitize_url( $url ) );
			$url = apply_filters( 'aioseo_blc_link_url_before_save', $url );

			$links[] = [
				'post_id'            => (int) $postId,
				'blc_link_status_id' => $this->getLinkStatusId( $url ),
				'url'                => $url,
				'url_hash'           => sha1( $url ),
				'hostname'           => $hostname,
				'hostname_url'       => sha1( $hostname ),
				'external'           => ! $isInternal,
				'anchor'             => '',
				'phrase'             => '',
				'phrase_html'        => '',
				'paragraph'          => '',
				'paragraph_html'     => '',
				'is_video'           => true
			];
		}

		return $links;
	}

	/**
	 * Extracts the phrase HTML surrounding a link from the post content.
	 *
	 * Finds sentence boundaries before and after the link using punctuation
	 * delimiters, then strips incomplete HTML tags, script tags and paragraph wrappers.
	 *
	 * @since 1.3.0
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
	 * Return the link status ID.
	 *
	 * @since 1.0.0
	 *
	 * @param  string   $url The URL to look up.
	 * @return int|null      The link status ID.
	 */
	private function getLinkStatusId( $url ) {
		static $linkStatusId = [];

		$hash = sha1( $url );
		if ( isset( $linkStatusId[ $hash ] ) ) {
			return $linkStatusId[ $hash ];
		}

		$possibleLinkStatusId = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->where( 'url_hash', $hash )
			->run()
			->result();

		$linkStatusId[ $hash ] = ! empty( $possibleLinkStatusId ) ? $possibleLinkStatusId[0]->id : null;

		return $linkStatusId[ $hash ];
	}

	/**
	 * Returns the site's hostname.
	 *
	 * @since 1.0.0
	 *
	 * @return string The hostname.
	 */
	private function getHostname() {
		static $siteUrl = null;
		if ( null === $siteUrl ) {
			$siteUrl = wp_parse_url( get_site_url(), PHP_URL_HOST );
		}

		return $siteUrl;
	}

	/**
	 * Returns the parsed URL.
	 *
	 * Relative URLs (e.g. `../foo`, `./foo`, `foo`, `/foo`) are resolved against
	 * the post's permalink per RFC 3986 §5.2 before parsing, so that `..` and `.`
	 * segments are collapsed and missing path separators are inserted. This
	 * prevents malformed values like `https://example.com../foo` from being stored.
	 *
	 * @since   1.0.0
	 * @version 1.1.1 Renamed method.
	 * @version 1.3.0 Added $postId parameter; resolves relative URLs against the post permalink.
	 *
	 * @param  string $url    The URL.
	 * @param  int    $postId The ID of the post the URL was extracted from.
	 * @return array          The parsed URL.
	 */
	private function parseUrl( $url, $postId = 0 ) {
		$parsedUrl = wp_parse_url( $url );
		if ( empty( $parsedUrl ) ) {
			return [];
		}

		// If the URL is relative, resolve it against the post's permalink so that
		// `..` and `.` segments are collapsed and a proper path separator is inserted.
		if ( empty( $parsedUrl['host'] ) ) {
			$baseUrl = $postId ? get_permalink( $postId ) : '';
			if ( ! $baseUrl ) {
				$baseUrl = get_site_url();
			}

			$absoluteUrl = \WP_Http::make_absolute_url( $url, $baseUrl );
			$parsedUrl   = wp_parse_url( $absoluteUrl );
			if ( empty( $parsedUrl ) ) {
				return [];
			}

			// make_absolute_url() resolves `..` during the merge but leaves `.`
			// segments behind, so collapse those to keep stored URLs canonical.
			if ( ! empty( $parsedUrl['path'] ) ) {
				$path              = preg_replace( '#/\.(?=/)#', '', $parsedUrl['path'] ); // /./ -> /
				$parsedUrl['path'] = preg_replace( '#/\.$#', '/', $path );                 // trailing /. -> /
			}

			// Fallback in case the base URL itself lacked a host (shouldn't happen, but be safe).
			if ( empty( $parsedUrl['host'] ) ) {
				$parsedUrl['host']   = $this->getHostname();
				$parsedUrl['scheme'] = wp_parse_url( get_site_url(), PHP_URL_SCHEME );
			}
		}

		return $parsedUrl;
	}

	/**
	 * Returns the posts to scan.
	 *
	 * @since   1.0.0
	 * @version 1.3.0 Count distinct post IDs so legacy duplicate rows in aioseo_blc_posts don't inflate the count and stall the scan percentage.
	 *
	 * @param  bool      $countOnly Whether to return only the count.
	 * @return array|int            The posts to scan or a count.
	 */
	public function getPostsToScan( $countOnly = false ) {
		$postsPerScan        = apply_filters( 'aioseo_blc_links_posts_per_scan', 50 );
		$postTypes           = aioseoBrokenLinkChecker()->helpers->getScannablePostTypes();
		$postStatuses        = aioseoBrokenLinkChecker()->helpers->getPublicPostStatuses( true );
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$minimumLinkScanDate = esc_sql( aioseoBrokenLinkChecker()->internalOptions->internal->minimumLinkScanDate ?: date( 'Y-m-d H:i:s' ) );

		$query = aioseoBrokenLinkChecker()->core->db->start( 'posts as p' )
			->leftJoin( 'aioseo_blc_posts as abp', 'p.ID = abp.post_id' )
			->whereIn( 'p.post_status', $postStatuses )
			->whereIn( 'p.post_type', $postTypes )
			->whereRaw( "(
				abp.post_id IS NULL OR
				abp.link_scan_date < p.post_modified_gmt OR
				abp.link_scan_date IS NULL OR
				abp.link_scan_date < '$minimumLinkScanDate'
			)" );

		if ( $countOnly ) {
			return $query->count( 'DISTINCT p.ID' );
		}

		$postsToScan = $query
			->select( 'DISTINCT p.ID, p.post_content, p.post_type, p.post_status' )
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
		$postsToScan         = $this->getPostsToScan( true );
		$totalScannablePosts = $this->getTotalScannablePosts();
		if ( 0 === $postsToScan || 0 === $totalScannablePosts ) {
			return 100;
		}

		return ceil( 100 - ( ( $postsToScan / $totalScannablePosts ) * 100 ) );
	}

	/**
	 * Sets the ignored extensions.
	 *
	 * @since 1.0.0
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
			'com',
			'exe',
			'gadget',
			'jar',
			'py',
			'wsf',
		] );
	}
}