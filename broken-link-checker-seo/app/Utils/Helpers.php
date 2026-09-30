<?php
namespace AIOSEO\BrokenLinkChecker\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Links\Url as LinkUrl;
use AIOSEO\BrokenLinkChecker\Traits\Helpers as TraitHelpers;

/**
 * Contains helper functions
 *
 * @since 1.0.0
 */
class Helpers {
	use TraitHelpers\Api;
	use TraitHelpers\Arrays;
	use TraitHelpers\Constants;
	use TraitHelpers\DateTime;
	use TraitHelpers\Strings;
	use TraitHelpers\ThirdParty;
	use TraitHelpers\Url;
	use TraitHelpers\Vue;
	use TraitHelpers\Wp;
	use TraitHelpers\WpContext;
	use TraitHelpers\WpMultisite;
	use TraitHelpers\WpUri;

	/**
	 * Checks if we are in a dev environment or not.
	 *
	 * @since 1.0.0
	 *
	 * @return boolean True if we are, false if not.
	 */
	public function isDev() {
		return aioseoBrokenLinkChecker()->isDev || isset( $_REQUEST['aioseo-dev'] ); // phpcs:ignore HM.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Applies wp_kses_post on the given string, but also allows some other tags we support.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $string The string.
	 * @return string         The sanitized string.
	 */
	public function wpKsesPhrase( $string ) {
		$allowedHtmlTags = wp_kses_allowed_html( 'post' );

		$customTags = [
			'ta' => [
				'linkid' => [],
				'href'   => []
			]
		];

		$allowedHtmlTags = array_merge( $allowedHtmlTags, $customTags );

		return wp_kses( $string, $allowedHtmlTags );
	}

	/**
	 * Returns the scannable post types.
	 *
	 * @since 1.0.0
	 *
	 * @return array The scannable post types.
	 */
	public function getScannablePostTypes() {
		static $scannablePostTypes = null;
		if ( null !== $scannablePostTypes ) {
			return $scannablePostTypes;
		}

		// We exclude these post types to optimize performance.
		$nonSupportedPostTypes = [ 'attachment' ];
		$scannablePostTypes    = array_diff(
			$this->getPublicPostTypes( true ),
			$nonSupportedPostTypes
		);

		return $scannablePostTypes;
	}

	/**
	 * Returns the time that elapsed since the initial call to this function.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null The time that has elapsed.
	 */
	public function timeElapsed() {
		static $last = null;

		$now    = microtime( true );
		$return = null !== $last ? $now - $last : null;

		if ( null === $last ) {
			$last = $now;
		}

		return $return;
	}

	/**
	 * Checks whether the current post can be scanned.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_Post $post The post object.
	 * @return bool           Whether the post is scannable.
	 */
	public function isScannablePost( $post ) {
		if ( ! is_object( $post ) ) {
			return false;
		}

		$postTypes = array_diff( $this->getPublicPostTypes( true ), [ 'attachment' ] );
		if ( ! in_array( $post->post_type, $postTypes, true ) ) {
			return false;
		}

		if ( ! aioseoBrokenLinkChecker()->helpers->isValidPost( $post, $this->getPublicPostStatuses( true ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * The post titles we've already looked up.
	 *
	 * @since 1.3.1
	 *
	 * @var array
	 */
	private $postTitles = [];

	/**
	 * Returns the post title or a placeholder if there isn't one.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $postId The post ID.
	 * @return string         The post title.
	 */
	public function getPostTitle( $postId ) {
		if ( isset( $this->postTitles[ $postId ] ) ) {
			return $this->postTitles[ $postId ];
		}

		$post  = get_post( $postId );
		$title = $post->post_title;
		$title = $title ? $title : __( '(no title)' ); // phpcs:ignore AIOSEO.Wp.I18n.MissingArgDomain, WordPress.WP.I18n.MissingArgDomain

		$this->postTitles[ $postId ] = $this->decodeHtmlEntities( $title );

		return $this->postTitles[ $postId ];
	}

	/**
	 * Forgets the post titles we've looked up.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function resetPostTitles() {
		$this->postTitles = [];
	}


	/**
	 * Checks if the given post is excluded from Broken Link Checker.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $postId The post ID.
	 * @return bool         Whether the post is excluded.
	 */
	public function isExcludedPost( $postId ) {
		$excludedPostIds      = $this->getExcludedPostIds();
		$includedPostTypes    = $this->getIncludedPostTypes();
		// We include auto-drafts here because all new posts are otherwise excluded before they are saved.
		$includedPostStatuses = array_merge( $this->getIncludedPostStatuses(), [ 'auto-draft' ] );
		$post                 = get_post( $postId );

		return in_array( (int) $postId, $excludedPostIds, true ) ||
			! in_array( $post->post_type, $includedPostTypes, true ) ||
			! in_array( $post->post_status, $includedPostStatuses, true );
	}

	/**
	 * Returns the IDs of posts that are excluded from Broken Link Checker.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Reads an entry the settings screen did not write without fataling.
	 *
	 * @return array The post IDs.
	 */
	public function getExcludedPostIds() {
		static $excludedPostIds = null;
		if ( null === $excludedPostIds ) {
			$excludedPostIds = [];
			$excludedPosts   = aioseoBrokenLinkChecker()->options->advanced->excludePosts;
			foreach ( (array) $excludedPosts as $excludedPost ) {
				// The settings screen writes each entry as a JSON string, but the option can be set
				// directly too - and json_decode() on an entry that is already structured is a fatal.
				$excludedPost = is_string( $excludedPost ) ? json_decode( $excludedPost, true ) : $excludedPost;
				$excludedPost = is_object( $excludedPost ) ? (array) $excludedPost : $excludedPost;

				if ( is_array( $excludedPost ) && ! empty( $excludedPost['value'] ) ) {
					$excludedPostIds[] = (int) $excludedPost['value'];
				}
			}
		}

		return $excludedPostIds;
	}

	/**
	 * Returns the post types that Broken Link Checker is enabled for.
	 *
	 * @since 1.0.0
	 *
	 * @return array The included post types.
	 */
	public function getIncludedPostTypes() {
		static $includedPostTypes = null;
		if ( null !== $includedPostTypes ) {
			return $includedPostTypes;
		}

		$includedPostTypes = [];
		$postTypes         = aioseoBrokenLinkChecker()->options->advanced->postTypes->all();
		if ( ! empty( $postTypes['all'] ) ) {
			$includedPostTypes = $this->getScannablePostTypes();
		} else {
			// Determine the intersection to make sure that we only consider post types that are currently registered.
			$includedPostTypes = array_intersect(
				$postTypes['included'],
				$this->getScannablePostTypes()
			);
		}

		foreach ( $includedPostTypes as $k => $postType ) {
			if ( ! $this->canEditPostType( $postType ) ) {
				unset( $includedPostTypes[ $k ] );
			}
		}

		return $includedPostTypes;
	}

	/**
	 * Returns the post statuses that Broken Link Checker is enabled for.
	 *
	 * @since 1.0.0
	 *
	 * @return array The included post statuses.
	 */
	public function getIncludedPostStatuses() {
		static $includedPostStatuses = null;
		if ( null !== $includedPostStatuses ) {
			return $includedPostStatuses;
		}

		$includedPostStatuses = [];
		$postStatuses         = aioseoBrokenLinkChecker()->options->advanced->postStatuses->all();
		if ( ! empty( $postStatuses['all'] ) ) {
			$includedPostStatuses = $this->getPublicPostStatuses( true );
		} else {
			// Determine the intersection to make sure that we only consider post statuses that are currently registered.
			$includedPostStatuses = array_intersect(
				$postStatuses['included'],
				$this->getPublicPostStatuses( true )
			);
		}

		return $includedPostStatuses;
	}

	/**
	 * Generates a UTM URL from the URL and medium/content that are passed in.
	 *
	 * @since 1.0.0
	 *
	 * @param  string      $url     The URL to parse.
	 * @param  string      $medium  The UTM medium parameter.
	 * @param  string|null $content The UTM content parameter or null.
	 * @param  boolean     $esc     Whether or not to escape the URL.
	 * @return string               The new URL.
	 */
	public function utmUrl( $url, $medium, $content = null, $esc = true ) {
		// First, remove any existing utm parameters on the URL.
		$url = remove_query_arg( [
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_content'
		], $url );

		// Generate the new arguments.
		$args = [
			'utm_source'   => 'WordPress',
			'utm_campaign' => 'plugin',
			'utm_medium'   => $medium
		];

		// Content is not used by default.
		if ( $content ) {
			$args['utm_content'] = $content;
		}

		// Return the new URL.
		$url = add_query_arg( $args, $url );

		return $esc ? esc_url( $url ) : $url;
	}

	/**
	 * Returns the excluded domains.
	 *
	 * @since 1.1.1
	 *
	 * @return array The excluded domains.
	 */
	public function getExcludedDomains() {
		// Read as an array: a chained read leaves the accessor's group state pointing at this group, and
		// the next one resolves against it and comes back null.
		$advanced        = aioseoBrokenLinkChecker()->options->advanced->all();
		$excludedDomains = isset( $advanced['excludeDomains'] ) ? $advanced['excludeDomains'] : '';
		if ( ! is_string( $excludedDomains ) ) {
			return [];
		}

		$pattern = '/([\.?!][\r\n\s]+|\r|\n|\s{2,})/u';

		return array_map( 'trim', preg_split( $pattern, (string) $excludedDomains, -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/**
	 * Returns the URL exclusion patterns that are in effect, split by how they have to be matched.
	 *
	 * @since 1.3.1
	 *
	 * @return array The patterns, keyed `like` and `regex`.
	 */
	private function getExcludedUrlPatterns() {
		static $cache = [];

		$advanced = aioseoBrokenLinkChecker()->options->advanced->all();
		$raw      = ! empty( $advanced['excludeUrlPatterns'] ) && is_string( $advanced['excludeUrlPatterns'] )
			? $advanced['excludeUrlPatterns']
			: '';

		// Keyed on the input so that a settings save mid-request doesn't leave us with a stale list.
		$cacheKey = md5( $raw );
		if ( ! isset( $cache[ $cacheKey ] ) ) {
			$cache[ $cacheKey ] = $this->splitUrlPatterns( $raw );
		}

		return $cache[ $cacheKey ];
	}

	/**
	 * Reduces a host to the form the links table stores, so the two can be compared.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Defers to {@see \AIOSEO\BrokenLinkChecker\Links\Url::host()}.
	 *
	 * @param  string $host The host, which may arrive as a whole URL.
	 * @return string       The host, or an empty string when it holds none.
	 */
	public function normalizeHost( $host ) {
		return LinkUrl::host( $host );
	}

	/**
	 * Splits the given URL exclusion patterns by how they have to be matched.
	 *
	 * NOTE: A slash-wrapped line that compiles is a regular expression, a `host:` line is a hostname,
	 * and everything else - including a slash-wrapped line that doesn't compile - is text, so a mistake
	 * in one costs no more than that line.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Recognises `host:` lines.
	 *
	 * @param  mixed $rawPatterns The patterns, one per line.
	 * @return array              The patterns, keyed `like`, `regex`, `text` and `host`.
	 */
	public function splitUrlPatterns( $rawPatterns ) {
		$patterns = [
			'like'  => [],
			'regex' => [],
			'text'  => [],
			'host'  => []
		];

		if ( ! is_string( $rawPatterns ) || '' === $rawPatterns ) {
			return $patterns;
		}

		foreach ( preg_split( '/\R/', $rawPatterns, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '#^/.*/[a-zA-Z]*$#', $line ) && $this->patternCompiles( $line ) ) {
				$patterns['regex'][] = $line;

				continue;
			}

			// Matched against the host alone, so it cannot catch a subdomain, a longer name ending in it, or
			// a URL that merely mentions it — none of which an unanchored pattern could keep apart. Marked
			// rather than inferred, because a bare `bit.ly` and a bare `image.png` have the same shape and
			// guessing between them would quietly change what an existing pattern matches.
			if ( preg_match( '/^host:\s*(\S+)$/i', $line, $hostMatch ) ) {
				$host = $this->normalizeHost( $hostMatch[1] );
				if ( '' !== $host ) {
					$patterns['host'][] = $host;

					continue;
				}
			}

			$escaped = [];
			foreach ( explode( '*', $line ) as $part ) {
				$escaped[] = aioseoBrokenLinkChecker()->core->db->db->esc_like( $part );
			}

			$patterns['like'][] = '%' . implode( '%', $escaped ) . '%';
			$patterns['text'][] = $line;
		}

		return $patterns;
	}

	/**
	 * Returns the text URL exclusion patterns as MySQL LIKE values.
	 *
	 * NOTE: `*` is the wildcard, so the LIKE metacharacters `%` and `_` are escaped to match
	 * literally. The URL columns collate case-insensitively, which is what makes the match one too.
	 *
	 * @since 1.3.1
	 *
	 * @return array The LIKE values.
	 */
	public function getExcludedUrlLikePatterns() {
		$patterns = $this->getExcludedUrlPatterns();

		return $patterns['like'];
	}

	/**
	 * Whether the given URL is excluded, by either kind of pattern.
	 *
	 * NOTE: The text patterns are matched here rather than in SQL, so that one answer covers both
	 * kinds. {@see self::getExcludedUrlLikePatterns()} is for the callers that are already in a query.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL.
	 * @return bool        Whether the URL is excluded.
	 */
	public function isUrlExcluded( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$hosts = $this->getExcludedUrlPatterns()['host'];
		if ( ! empty( $hosts ) && in_array( $this->normalizeHost( $url ), $hosts, true ) ) {
			return true;
		}

		foreach ( $this->getExcludedUrlPatterns()['text'] as $pattern ) {
			// Built to mean what the LIKE value means: `*` is the wildcard, everything else is literal,
			// unanchored so it matches anywhere, and case-insensitive like the column's collation.
			$asRegex = '#' . str_replace( '\\*', '.*', preg_quote( $pattern, '#' ) ) . '#i';
			if ( 1 === preg_match( $asRegex, $url ) ) {
				return true;
			}
		}

		return $this->isUrlExcludedByRegex( $url );
	}

	/**
	 * Returns the URL exclusion patterns that are regular expressions.
	 *
	 * @since 1.3.1
	 *
	 * @return array The PCRE patterns.
	 */
	public function getExcludedUrlRegexPatterns() {
		$patterns = $this->getExcludedUrlPatterns();

		return $patterns['regex'];
	}

	/**
	 * Checks whether the given URL matches one of the regular expression exclusion patterns.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL.
	 * @return bool        Whether the URL is excluded.
	 */
	public function isUrlExcludedByRegex( $url ) {
		$patterns = $this->getExcludedUrlRegexPatterns();
		if ( empty( $patterns ) || ! is_string( $url ) || '' === $url ) {
			return false;
		}

		// A user's pattern can backtrack catastrophically. The lower ceiling caps what one costs us;
		// preg_match() then returns false rather than 0 or 1, which we treat as no match.
		$backtrackLimit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '100000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged

		try {
			foreach ( $patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $url ) ) {
					return true;
				}
			}

			return false;
		} finally {
			if ( false !== $backtrackLimit ) {
				ini_set( 'pcre.backtrack_limit', $backtrackLimit ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged
			}
		}
	}

	/**
	 * Checks whether the given PCRE pattern compiles.
	 *
	 * NOTE: The error handler is swapped out because a pattern comes from user input, so a compile
	 * failure is expected and must not surface as a PHP warning.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $pattern The PCRE pattern.
	 * @return bool            Whether the pattern compiles.
	 */
	private function patternCompiles( $pattern ) {
		set_error_handler( '__return_true' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler

		try {
			return false !== preg_match( $pattern, '' );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Whether the links table has the columns that address a link by the object it was found in.
	 *
	 * NOTE: The migration that adds them is not guaranteed to have run: a request that loses its lock
	 * carries on against the old shape, and the dbDelta fallback is skipped in the AJAX and cron
	 * contexts the scan runs in. So everything that reads or writes those columns is gated on this, and
	 * the scan in particular has to bail rather than record a post as scanned with no links.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the columns are there.
	 */
	public function hasObjectColumns() {
		static $hasObjectColumns = null;
		if ( null !== $hasObjectColumns ) {
			return $hasObjectColumns;
		}

		// Reads the cached schema map the migration busts, so this costs no query of its own.
		$hasObjectColumns = aioseoBrokenLinkChecker()->core->db->columnExists( 'aioseo_blc_links', 'object_type' );

		return $hasObjectColumns;
	}

	/**
	 * How long a checked link stands before it is sent out to be checked again.
	 *
	 * NOTE: This budgets outbound checking, which is what the setting it reads describes. It is not
	 * how often content is re-read looking for links — see {@see \AIOSEO\BrokenLinkChecker\Links\ObjectScan}.
	 *
	 * @since 1.3.1
	 *
	 * @return int The interval in seconds.
	 */
	public function getScanInterval() {
		return $this->isMonthlyScan() ? MONTH_IN_SECONDS : WEEK_IN_SECONDS;
	}

	/**
	 * Whether links are rechecked monthly rather than weekly.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the scan frequency is monthly.
	 */
	public function isMonthlyScan() {
		$general = aioseoBrokenLinkChecker()->options->general->all();

		return ! empty( $general['scanFrequency'] ) && 'monthly' === $general['scanFrequency'];
	}

	/**
	 * Returns the total link count, cached.
	 *
	 * NOTE: The underlying query joins and groups, so it's too heavy for the notices and admin bar
	 * item that run on ordinary page loads. They only report the figure, so staleness is harmless.
	 *
	 * @since 1.3.1
	 *
	 * @return int The total link count.
	 */
	public function getCachedTotalLinks() {
		$cached = aioseoBrokenLinkChecker()->core->cache->get( 'total_links' );
		if ( null !== $cached ) {
			return (int) $cached;
		}

		if ( empty( aioseoBrokenLinkChecker()->main->linkStatus->data ) ) {
			return 0;
		}

		$totalLinks = (int) aioseoBrokenLinkChecker()->main->linkStatus->data->getTotalLinks();

		aioseoBrokenLinkChecker()->core->cache->update( 'total_links', $totalLinks, HOUR_IN_SECONDS );

		return $totalLinks;
	}

	/**
	 * Checks if the given string is serialized, and if so, unserializes it.
	 * If the serialized string contains an object, we abort to prevent PHP object injection.
	 *
	 * @since 1.2.0
	 *
	 * @param  string       $string The string.
	 * @return string|array         The string or unserialized data.
	 */
	public function maybeUnserialize( $string ) {
		if ( ! is_string( $string ) ) {
			return $string;
		}

		$string = trim( $string );
		if ( is_serialized( $string ) && ! $this->stringContains( $string, 'O:' ) ) {
			return @unserialize( $string, [ 'allowed_classes' => false ] ); // phpcs:disable PHPCompatibility.FunctionUse.NewFunctionParameters.unserialize_optionsFound
		}

		return $string;
	}

	/**
	 * Returns user roles in the current WP install.
	 *
	 * @since 1.2.4
	 *
	 * @return array An array of user roles.
	 */
	public function getUserRoles() {
		global $wp_roles; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		$wpRoles = $wp_roles; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		if ( ! is_object( $wpRoles ) ) {
			// Don't assign this to the global because otherwise WordPress won't override it.
			$wpRoles = new \WP_Roles();
		}

		$roleNames = $wpRoles->get_names();
		asort( $roleNames );

		return $roleNames;
	}

	/**
	 * Check if the current request is uninstalling (deleting) Broken Link Checker.
	 *
	 * @since 1.2.4
	 *
	 * @return bool Whether Broken Link Checker is being uninstalled/deleted or not.
	 */
	public function isUninstalling() {
		if (
			defined( 'AIOSEO_BROKEN_LINK_CHECKER_FILE' ) &&
			defined( 'WP_UNINSTALL_PLUGIN' )
		) {
			// Make sure `plugin_basename()` exists.
			include_once ABSPATH . 'wp-admin/includes/plugin.php';

			return WP_UNINSTALL_PLUGIN === plugin_basename( AIOSEO_BROKEN_LINK_CHECKER_FILE );
		}

		return false;
	}
}