<?php
namespace AIOSEO\BrokenLinkChecker\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Highlighter;
use AIOSEO\BrokenLinkChecker\Links;
use AIOSEO\BrokenLinkChecker\LinkStatus;
use AIOSEO\BrokenLinkChecker\Models;

/**
 * Main class where core features are handled/registered.
 *
 * @since 1.0.0
 */
class Main {
	/**
	 * Paragraph class.
	 *
	 * @since 1.0.0
	 *
	 * @var Paragraph
	 */
	public $paragraph = null;

	/**
	 * Links class.
	 *
	 * @since 1.0.0
	 *
	 * @var Links\Links
	 */
	public $links = null;

	/**
	 * LinkStatus class.
	 *
	 * @since 1.0.0
	 *
	 * @var LinkStatus\LinkStatus
	 */
	public $linkStatus = null;

	/**
	 * LocalScan class.
	 *
	 * @since 1.3.0
	 *
	 * @var LinkStatus\LocalScan
	 */
	public $localScan = null;

	/**
	 * The action name for the recurring orphan-row cleanup scan.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	private $cleanupActionName = 'aioseo_blc_links_cleanup';

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if (
			! aioseoBrokenLinkChecker()->core->db->tableExists( 'aioseo_blc_links' ) ||
			! aioseoBrokenLinkChecker()->core->db->tableExists( 'aioseo_blc_link_status' ) ||
			! aioseoBrokenLinkChecker()->core->db->tableExists( 'aioseo_blc_cache' )
		) {
			// Don't call updateDbSchema() here — it will be called by runUpdates()
			// on the init hook later in this same request.
			// Calling it here AND in runUpdates() causes duplicate dbDelta() calls
			// which triggers "table already exists" errors.

			// Don't return here; otherwise the Setup Wizard won't show on the first activation, but on the second.
		}

		new Activate();

		$this->paragraph  = new Paragraph();
		$this->links      = new Links\Links();
		$this->linkStatus = new LinkStatus\LinkStatus();
		$this->localScan  = new LinkStatus\LocalScan();

		add_filter( 'the_content', [ $this, 'filterLinks' ], 999 ); // High prio to make sure other plugins get a chance to render their content, parse their blocks, etc..

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueStandaloneApp' ] );
		add_action( 'admin_footer', [ $this, 'adminFooter' ] );

		// Real-time orphan cleanup. Hooks fire in admin, REST, CLI, and cron contexts.
		add_action( 'delete_post', [ $this, 'removeOrphanedData' ] );
		add_action( 'wp_trash_post', [ $this, 'removeOrphanedData' ] );

		// Recurring sweeper for orphan rows that pre-date the real-time hooks.
		add_action( 'admin_init', [ $this, 'scheduleCleanupScan' ] );
		add_action( $this->cleanupActionName, [ $this, 'runCleanupScan' ] );
	}

	/**
	 * Removes Broken Link Checker data for a deleted/trashed post.
	 *
	 * On permanent delete, {@see \AIOSEO\BrokenLinkChecker\Links\Links::deletePostLinks()} already
	 * removes the link rows (and prunes any link statuses they leave orphaned), so here we only
	 * drop the post's scan-metadata row. On trash that handler does not run, so we delete the
	 * links too — but keep link statuses, since a trashed post may be restored.
	 *
	 * @since 1.3.0
	 *
	 * @param  int  $postId The post ID.
	 * @return void
	 */
	public function removeOrphanedData( $postId ) {
		if ( ! doing_action( 'delete_post' ) ) {
			Models\Link::deleteLinks( $postId );
		}

		Models\Post::deleteByPostId( $postId );
	}

	/**
	 * Schedules the recurring orphan-row cleanup scan.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function scheduleCleanupScan() {
		aioseoBrokenLinkChecker()->actionScheduler->scheduleRecurrent( $this->cleanupActionName, 0, DAY_IN_SECONDS );
	}

	/**
	 * Sweeps Broken Link Checker rows whose post is gone, trashed, or private, plus
	 * link-status rows no longer referenced by any link.
	 *
	 * Runs in batches of 10,000 until every table is clean or 30 seconds elapse.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function runCleanupScan() {
		$lockKey = 'as_blc_links_cleanup_running';
		if ( aioseoBrokenLinkChecker()->core->cache->get( $lockKey ) ) {
			return;
		}

		// Timeout in case the action fails mid-execution.
		aioseoBrokenLinkChecker()->core->cache->update( $lockKey, true, 5 * MINUTE_IN_SECONDS );

		$deadline = microtime( true ) + 30;
		$this->sweepOrphanRows( 'aioseo_blc_links', $deadline, [ 'trash', 'private' ] );
		$this->sweepOrphanRows( 'aioseo_blc_posts', $deadline, [ 'trash', 'private' ] );
		$this->sweepOrphanLinkStatuses( $deadline );

		aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );
	}

	/**
	 * Deletes rows from the given table whose `post_id` is gone or has an excluded status.
	 *
	 * Uses a single-table DELETE with `NOT EXISTS` so MySQL accepts `LIMIT` (which it
	 * rejects on multi-table DELETE statements).
	 *
	 * @since 1.3.0
	 *
	 * @param  string   $tableName        The unprefixed table name to sweep (must have a `post_id` column).
	 * @param  float    $deadline         Unix timestamp (with microseconds) at which to stop.
	 * @param  string[] $excludeStatuses  Post statuses to treat as orphan-equivalent.
	 * @return void
	 */
	private function sweepOrphanRows( $tableName, $deadline, $excludeStatuses = [ 'trash' ] ) {
		$batchSize  = 10000;
		$prefix     = aioseoBrokenLinkChecker()->core->db->prefix;
		$table      = $prefix . $tableName;
		$wpPosts    = $prefix . 'posts';
		$statusList = implode( ',', array_map( function( $status ) {
			return "'" . esc_sql( $status ) . "'";
		}, $excludeStatuses ) );

		while ( microtime( true ) < $deadline ) {
			aioseoBrokenLinkChecker()->core->db->execute(
				"DELETE FROM $table
				WHERE NOT EXISTS (
					SELECT 1 FROM $wpPosts AS p
					WHERE p.ID = $table.post_id
						AND p.post_status NOT IN ($statusList)
				)
				LIMIT $batchSize"
			);

			if ( (int) aioseoBrokenLinkChecker()->core->db->rowsAffected() < $batchSize ) {
				return;
			}
		}
	}

	/**
	 * Deletes link-status rows no longer referenced by any link.
	 *
	 * Uses the same single-table `DELETE … NOT EXISTS … LIMIT N` shape as sweepOrphanRows().
	 * Skips rows created within the last hour so an in-progress scan — which inserts a status
	 * row shortly before linking it (see {@see \AIOSEO\BrokenLinkChecker\Links\Data::indexLinks()})
	 * — is never raced.
	 *
	 * @since 1.3.0
	 *
	 * @param  float $deadline Unix timestamp (with microseconds) at which to stop.
	 * @return void
	 */
	private function sweepOrphanLinkStatuses( $deadline ) {
		$batchSize   = 10000;
		$prefix      = aioseoBrokenLinkChecker()->core->db->prefix;
		$statusTable = $prefix . 'aioseo_blc_link_status';
		$linksTable  = $prefix . 'aioseo_blc_links';
		$cutoff      = esc_sql( aioseoBrokenLinkChecker()->helpers->timeToMysql( time() - HOUR_IN_SECONDS ) );

		while ( microtime( true ) < $deadline ) {
			aioseoBrokenLinkChecker()->core->db->execute(
				"DELETE FROM $statusTable
				WHERE created < '$cutoff'
					AND NOT EXISTS (
						SELECT 1 FROM $linksTable
						WHERE $linksTable.blc_link_status_id = $statusTable.id
					)
				LIMIT $batchSize"
			);

			if ( (int) aioseoBrokenLinkChecker()->core->db->rowsAffected() < $batchSize ) {
				return;
			}
		}
	}

	/**
	 * Enqueues the standalone app for admin menu styles.
	 *
	 * @since 1.2.6
	 *
	 * @return void
	 */
	public function enqueueStandaloneApp() {
		aioseoBrokenLinkChecker()->core->assets->load( 'src/vue/standalone/app/main.js', [], [], 'aioseoBrokenLinkCheckerApp' );
	}

	/**
	 * Enqueue the footer div to let Vue attach.
	 *
	 * @since 1.2.6
	 *
	 * @return void
	 */
	public function adminFooter() {
		echo '<div id="aioseo-blc-admin"></div>';
	}

	/**
	 * Filters links in the post content.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $postContent The post content.
	 * @return string              The post content.
	 */
	public function filterLinks( $postContent ) {
		if ( aioseoBrokenLinkChecker()->options->general->linkTweaks->nofollowBroken ) {
			$postContent = $this->nofollowBrokenLinks( $postContent );
		}

		return $postContent;
	}

	/**
	 * Adds rel="nofollow" to links in the post content that we know are broken.
	 *
	 * Routes to the HTML API path (WP 6.2+) or falls back to the regex path.
	 *
	 * @since   1.0.0
	 * @version 1.3.0 Refactored into junction between HtmlApi and Regex methods.
	 *
	 * @param  string $postContent The post content.
	 * @return string              The post content.
	 */
	private function nofollowBrokenLinks( $postContent ) {
		if ( version_compare( get_bloginfo( 'version' ), '6.2', '>=' ) ) {
			return $this->nofollowBrokenLinksHtmlApi( $postContent );
		}

		return $this->nofollowBrokenLinksRegex( $postContent );
	}

	/**
	 * Adds rel="nofollow" to broken links using WP_HTML_Tag_Processor.
	 *
	 * Two-pass approach: first collects all hrefs and batch-loads broken
	 * link statuses in a single DB query, then applies nofollow attributes.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $postContent The post content.
	 * @return string              The post content.
	 */
	private function nofollowBrokenLinksHtmlApi( $postContent ) {
		$processor = new \WP_HTML_Tag_Processor( $postContent );
		$siteUrl   = rtrim( get_site_url(), '/' );

		// First pass: collect all unique hrefs.
		$allHrefs = [];
		while ( $processor->next_tag( 'a' ) ) {
			$href = $processor->get_attribute( 'href' );
			if ( is_string( $href ) && '' !== $href ) {
				$allHrefs[ $href ] = true;
			}
		}

		if ( empty( $allHrefs ) ) {
			return $postContent;
		}

		// Batch-load broken URL hashes in a single query.
		$brokenHashes = $this->loadBrokenUrlHashes( array_keys( $allHrefs ), $siteUrl );

		if ( empty( $brokenHashes ) ) {
			return $postContent;
		}

		// Second pass: apply nofollow to broken links.
		$processor = new \WP_HTML_Tag_Processor( $postContent );

		while ( $processor->next_tag( 'a' ) ) {
			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) || '' === $href || ! $this->isHrefBroken( $href, $siteUrl, $brokenHashes ) ) {
				continue;
			}

			$rel = $processor->get_attribute( 'rel' );
			if ( is_string( $rel ) && '' !== $rel ) {
				$relParts = array_filter( preg_split( '/\s+/', $rel ) ?: [] );
				if ( in_array( 'nofollow', $relParts, true ) ) {
					continue;
				}
				$relParts[] = 'nofollow';
				$newRel = implode( ' ', $relParts );
			} else {
				$newRel = 'nofollow';
			}

			$processor->set_attribute( 'rel', $newRel );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Batch-loads broken URL hashes for the given hrefs.
	 *
	 * Generates all URL variants (exact, trailing-slash, absolute for relative hrefs)
	 * and queries the link_status table in a single query.
	 *
	 * @since 1.3.0
	 *
	 * @param  array  $hrefs   The unique href values.
	 * @param  string $siteUrl The site URL (no trailing slash).
	 * @return array           Map of broken URL hashes (hash => true).
	 */
	private function loadBrokenUrlHashes( $hrefs, $siteUrl ) {
		$hashes = [];
		foreach ( $hrefs as $href ) {
			foreach ( $this->getUrlVariants( $href, $siteUrl ) as $variant ) {
				$hashes[] = sha1( $variant );
			}
		}

		$hashes = array_unique( $hashes );

		if ( empty( $hashes ) ) {
			return [];
		}

		$results = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->select( 'url_hash' )
			->whereIn( 'url_hash', $hashes )
			->where( 'broken', 1 )
			->where( 'needs_additional_scan', 0 )
			->where( 'dismissed', 0 )
			->run()
			->result();

		$brokenHashes = [];
		foreach ( $results as $row ) {
			$brokenHashes[ $row->url_hash ] = true;
		}

		return $brokenHashes;
	}

	/**
	 * Checks if an href has a broken link status using pre-loaded hashes.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $href         The href attribute value.
	 * @param  string $siteUrl      The site URL (no trailing slash).
	 * @param  array  $brokenHashes Map of broken URL hashes.
	 * @return bool                 Whether the href is broken.
	 */
	private function isHrefBroken( $href, $siteUrl, $brokenHashes ) {
		foreach ( $this->getUrlVariants( $href, $siteUrl ) as $variant ) {
			if ( isset( $brokenHashes[ sha1( $variant ) ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns all URL variants for matching against stored URL hashes.
	 *
	 * Generates trailing-slash, scheme-agnostic (http <-> https), and
	 * relative-to-absolute variants of the given href.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $href    The href attribute value.
	 * @param  string $siteUrl The site URL (no trailing slash).
	 * @return array           The URL variants (including the original).
	 */
	private function getUrlVariants( $href, $siteUrl ) {
		// Strip URL fragment — stored URLs have fragments removed during extraction.
		$fragmentPos = strpos( $href, '#' );
		if ( false !== $fragmentPos ) {
			$href = substr( $href, 0, $fragmentPos );
			if ( '' === $href ) {
				return [];
			}
		}

		$variants = [ $href ];

		// Trailing-slash variant.
		$variants[] = '/' === substr( $href, -1 ) ? rtrim( $href, '/' ) : $href . '/';

		// Scheme-agnostic variants (http: <-> https:).
		$schemeless = preg_replace( '#^https?:#i', '', $href ) ?? $href;
		if ( $schemeless !== $href ) {
			$altScheme  = 0 === strpos( $href, 'https:' ) ? 'http:' . $schemeless : 'https:' . $schemeless;
			$variants[] = $altScheme;
			$variants[] = '/' === substr( $altScheme, -1 ) ? rtrim( $altScheme, '/' ) : $altScheme . '/';
		}

		// Protocol-relative URLs (//example.com/path): add both scheme variants.
		if ( 0 === strpos( $href, '//' ) && $schemeless === $href ) {
			$httpsVariant = 'https:' . $href;
			$httpVariant  = 'http:' . $href;
			$variants[]   = $httpsVariant;
			$variants[]   = $httpVariant;
			$variants[]   = '/' === substr( $httpsVariant, -1 ) ? rtrim( $httpsVariant, '/' ) : $httpsVariant . '/';
			$variants[]   = '/' === substr( $httpVariant, -1 ) ? rtrim( $httpVariant, '/' ) : $httpVariant . '/';
		}

		// If the href is relative, add absolute variants.
		if ( ! wp_parse_url( $href, PHP_URL_HOST ) ) {
			$absoluteUrl = trim( sanitize_url( $siteUrl . '/' . ltrim( $href, '/' ) ) );
			$variants[]  = $absoluteUrl;
			$variants[]  = '/' === substr( $absoluteUrl, -1 ) ? rtrim( $absoluteUrl, '/' ) : $absoluteUrl . '/';
		}

		// RFC 3986 §3.2.2: host is case-insensitive. The href in content and the stored URL can
		// independently have different host casing, so check both forms.
		$lowercasedHref = preg_replace_callback( '#^(https?:)?//[^/]+#i', static function ( $m ) {
			return strtolower( $m[0] );
		}, $href ) ?? $href;

		if ( $lowercasedHref !== $href ) {
			$variants[] = $lowercasedHref;
			$variants[] = '/' === substr( $lowercasedHref, -1 ) ? rtrim( $lowercasedHref, '/' ) : $lowercasedHref . '/';
		}

		// Normalize each variant through sanitize_url() + trim() to match the
		// storage pipeline (Data.php applies buildUrl, sanitize_url, trim before hashing).
		$normalized = [];
		foreach ( $variants as $variant ) {
			$normalized[] = trim( sanitize_url( $variant ) );
		}

		return array_unique( $normalized );
	}

	/**
	 * Regex-based nofollow injection for WP < 6.2 (without WP_HTML_Tag_Processor).
	 *
	 * @since   1.0.0
	 * @version 1.3.0 Renamed from nofollowBrokenLinks().
	 *
	 * @param  string $postContent The post content.
	 * @return string              The post content.
	 */
	private function nofollowBrokenLinksRegex( $postContent ) {
		// First, capture all link tags.
		preg_match_all( '/<a.*href="(.*?").*>(.*?)<\/a>/i', (string) $postContent, $linkTags );

		if ( empty( $linkTags[0] ) ) {
			return $postContent;
		}

		foreach ( $linkTags[0] as $linkTag ) {
			preg_match( '/href="(.*?)"/i', (string) $linkTag, $url );
			if ( empty( $url[1] ) ) {
				continue;
			}

			// Now check if we've indexed the link. If so, check if it's broken and act accordingly.
			$linkStatus = Models\LinkStatus::getByUrl( $url[1] );
			if ( ! $linkStatus->exists() || ! $linkStatus->broken || $linkStatus->needs_additional_scan ) {
				continue;
			}

			preg_match( '/rel="(.*?)"/i', (string) $linkTag, $relAttributes );
			if ( ! empty( $relAttributes[0] ) ) {
				$relAttributes = explode( ' ', $relAttributes[1] );
				if ( ! in_array( 'nofollow', $relAttributes, true ) ) {
					$relAttributes[] = 'nofollow';
				}
				$relAttributes = implode( ' ', $relAttributes );
			} else {
				$relAttributes = 'nofollow';
			}

			$newLinkTag = $this->insertAttribute( $linkTag, 'rel', $relAttributes );

			$oldLinkTag = aioseoBrokenLinkChecker()->helpers->escapeRegex( $linkTag );
			$newLinkTag = aioseoBrokenLinkChecker()->helpers->escapeRegexReplacement( $newLinkTag );

			$postContent = preg_replace( "/{$oldLinkTag}/i", $newLinkTag, (string) $postContent ) ?? $postContent;
		}

		return $postContent;
	}

	/**
	 * Inserts a given value for a given image attribute.
	 * Used by the regex nofollow method for WP < 6.2.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $linkTag       The HTML tag.
	 * @param  string $attributeName The attribute name.
	 * @param  string $value         The attribute value.
	 * @return string                The modified tag.
	 */
	private function insertAttribute( $linkTag, $attributeName, $value ) {
		if ( empty( $value ) ) {
			return $linkTag;
		}

		$value   = esc_attr( $value );
		$linkTag = preg_replace( $this->attributeRegex( $attributeName, true ), '${1}' . $value . '${2}', (string) $linkTag, 1, $count ) ?? $linkTag;

		// Attribute does not exist. Let's append it at the beginning of the tag.
		if ( ! $count ) {
			$linkTag = str_replace( '<a ', '<a ' . $this->attributeToHtml( $attributeName, $value ) . ' ', (string) $linkTag );
		}

		return $linkTag;
	}

	/**
	 * Returns a regex string to match an attribute.
	 * Used by the regex nofollow method for WP < 6.2.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $attributeName      The attribute name.
	 * @param  bool   $groupReplaceValue  Regex groupings without the value.
	 * @return string                     The regex string.
	 */
	private function attributeRegex( $attributeName, $groupReplaceValue = false ) {
		$regex = $groupReplaceValue ? "/(\s%s=['\"]).*?(['\"])/" : "/\s%s=['\"](.*?)['\"]/";

		return sprintf( $regex, trim( $attributeName ) );
	}

	/**
	 * Returns an attribute as HTML.
	 * Used by the regex nofollow method for WP < 6.2.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $attributeName The attribute name.
	 * @param  string $value         The attribute value.
	 * @return string                The HTML formatted attribute.
	 */
	private function attributeToHtml( $attributeName, $value ) {
		return sprintf( '%s="%s"', $attributeName, esc_attr( $value ) );
	}
}