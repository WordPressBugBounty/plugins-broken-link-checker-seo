<?php
namespace AIOSEO\BrokenLinkChecker\Newsroom;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the Broken Link Checker newsroom feed that aioseo.com publishes to the CDN.
 *
 * NOTE: This is BLC's own feed, not AIOSEO's. What it carries is decided when it's built —
 * the product config on the marketing site composes BLC releases together with shared SEO
 * news, so everything in the document is meant for this plugin's drawer.
 *
 * @since 1.3.1
 */
class Newsroom {
	/**
	 * Where BLC's feed is published.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	private $url = 'https://blc-plugin-cdn.aioseo.com/newsroom.json';

	/**
	 * The payload schema this understands.
	 *
	 * NOTE: A document declaring anything else is ignored rather than half-read.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const SCHEMA = 1;

	/**
	 * This plugin's product slug in the feed.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const PRODUCT = 'broken-link-checker';

	/**
	 * Cache key for the fetched items.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	private $cacheKey = 'newsroom_feed';

	/**
	 * Lock key for the fetch.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	private $lockKey = 'newsroom_feed_fetch_lock';

	/**
	 * Returns the URL of this product's newsroom archive.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $medium The UTM medium naming the surface.
	 * @return string         The tagged URL.
	 */
	public function getArchiveUrl( $medium ) {
		return aioseoBrokenLinkChecker()->helpers->utmUrl(
			AIOSEO_BROKEN_LINK_CHECKER_MARKETING_URL . 'newsroom/product/' . self::PRODUCT . '/',
			$medium,
			'view-all',
			false
		);
	}

	/**
	 * Formats a feed date in the site's own date format.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $date An ISO 8601 date from the feed.
	 * @return string       The formatted date, or empty.
	 */
	public function formatDate( $date ) {
		$timestamp = strtotime( (string) $date );

		return $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : '';
	}

	/**
	 * Returns every item in the feed, newest first.
	 *
	 * @since 1.3.1
	 *
	 * @return array The items, or an empty array if the feed is unavailable.
	 */
	public function getItems() {
		$items = aioseoBrokenLinkChecker()->core->networkCache->get( $this->cacheKey );
		if ( is_array( $items ) ) {
			return $items;
		}

		return $this->fetch();
	}

	/**
	 * Fetches and caches the feed.
	 *
	 * @since 1.3.1
	 *
	 * @return array The items.
	 */
	private function fetch() {
		// A cold cache on a busy site would otherwise fire one request per visitor.
		if ( null !== aioseoBrokenLinkChecker()->core->cache->get( $this->lockKey ) ) {
			return [];
		}

		aioseoBrokenLinkChecker()->core->cache->update( $this->lockKey, true, MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			$this->url,
			[
				'timeout'    => 10,
				'user-agent' => 'AIOSEO/' . AIOSEO_BROKEN_LINK_CHECKER_VERSION . '; ' . home_url()
			]
		);

		if ( is_wp_error( $response ) ) {
			// Short retry window: a transport failure is usually transient.
			return $this->cacheAndReturn( [], 10 * MINUTE_IN_SECONDS );
		}

		// An HTTP error comes back as a successful response with an error body, so the
		// status has to be checked separately from is_wp_error().
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $this->cacheAndReturn( [], HOUR_IN_SECONDS );
		}

		$body    = wp_remote_retrieve_body( $response );
		$payload = ! empty( $body ) ? json_decode( $body, true ) : null;

		if ( ! is_array( $payload ) || self::SCHEMA !== ( isset( $payload['schema'] ) ? $payload['schema'] : null ) ) {
			return $this->cacheAndReturn( [], HOUR_IN_SECONDS );
		}

		$items = [];
		foreach ( (array) ( isset( $payload['items'] ) ? $payload['items'] : [] ) as $item ) {
			$clean = $this->sanitizeItem( $item );
			if ( ! empty( $clean ) ) {
				$items[] = $clean;
			}
		}

		return $this->cacheAndReturn( $items, DAY_IN_SECONDS );
	}

	/**
	 * Caches a result, releases the lock and hands it back.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $items      The items to cache.
	 * @param  int   $expiration How long to keep them.
	 * @return array             The items.
	 */
	private function cacheAndReturn( $items, $expiration ) {
		aioseoBrokenLinkChecker()->core->networkCache->update( $this->cacheKey, $items, $expiration );
		aioseoBrokenLinkChecker()->core->cache->delete( $this->lockKey );

		return $items;
	}

	/**
	 * Sanitizes one item from the feed.
	 *
	 * NOTE: This arrives over the network, so nothing in it is trusted.
	 *
	 * @since 1.3.1
	 *
	 * @param  mixed $item The raw item.
	 * @return array       The sanitized item, or empty if unusable.
	 */
	private function sanitizeItem( $item ) {
		if ( ! is_array( $item ) ) {
			return [];
		}

		$title = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
		$url   = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';

		// Without a title and somewhere to send people, an item can't be rendered.
		if ( '' === $title || '' === $url ) {
			return [];
		}

		return [
			'id'      => isset( $item['id'] ) ? (int) $item['id'] : 0,
			'title'   => $title,
			'excerpt' => isset( $item['excerpt'] ) ? sanitize_text_field( (string) $item['excerpt'] ) : '',
			'url'     => $url,
			'date'    => isset( $item['date'] ) ? sanitize_text_field( (string) $item['date'] ) : '',
			'product' => isset( $item['product'] ) ? sanitize_key( $item['product'] ) : '',
			'label'   => isset( $item['label'] ) ? sanitize_text_field( (string) $item['label'] ) : '',
			'badge'   => isset( $item['badge'] ) ? sanitize_key( $item['badge'] ) : 'news',
			'version' => isset( $item['version'] ) ? preg_replace( '/[^0-9.]/', '', (string) $item['version'] ) : '',
			'image'   => isset( $item['image'] ) ? esc_url_raw( (string) $item['image'] ) : ''
		];
	}
}