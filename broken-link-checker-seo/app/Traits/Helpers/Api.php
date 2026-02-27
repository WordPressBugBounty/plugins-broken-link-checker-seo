<?php
namespace AIOSEO\BrokenLinkChecker\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains API specific helper methods.
 *
 * @since 1.0.0
 */
trait Api {
	/**
	 * Sends a request using wp_remote_post.
	 *
	 * @since 1.0.0
	 *
	 * @param  string          $url  The URL to send the request to.
	 * @param  array           $args The args to use in the request.
	 * @return array|\WP_Error       The response as an array or WP_Error on failure.
	 */
	public function wpRemotePost( $url, $args = [] ) {
		$skipLock = ! empty( $args['aioseo_skip_lock'] );
		unset( $args['aioseo_skip_lock'] );

		$args['method'] = 'POST';

		if ( ! $skipLock ) {
			$lockKey = $this->getCacheKey( $url, $args );
			if ( aioseo()->core->cache->get( $lockKey ) ) {
				return new \WP_Error( 'concurrent_request', 'A request to this URL is already in progress.' );
			}

			aioseo()->core->cache->update( $lockKey, true, MINUTE_IN_SECONDS );
		}

		$response = wp_remote_post( $url, array_replace_recursive( $this->getWpApiRequestDefaults(), $args ) );

		if ( ! $skipLock ) {
			aioseo()->core->cache->delete( $lockKey );
		}

		return $response;
	}

	/**
	 * Sends a request using wp_remote_get.
	 *
	 * @since 1.0.0
	 *
	 * @param  string          $url  The URL to send the request to.
	 * @param  array           $args The args to use in the request.
	 * @return array|\WP_Error       The response as an array or WP_Error on failure.
	 */
	public function wpRemoteGet( $url, $args = [] ) {
		$skipLock = ! empty( $args['aioseo_skip_lock'] );
		unset( $args['aioseo_skip_lock'] );

		if ( ! $skipLock ) {
			$lockKey = $this->getCacheKey( $url, $args );
			if ( aioseo()->core->cache->get( $lockKey ) ) {
				return new \WP_Error( 'concurrent_request', 'A request to this URL is already in progress.' );
			}

			aioseo()->core->cache->update( $lockKey, true, MINUTE_IN_SECONDS );
		}

		$response = wp_remote_get( $url, array_replace_recursive( $this->getWpApiRequestDefaults(), $args ) );

		if ( ! $skipLock ) {
			aioseo()->core->cache->delete( $lockKey );
		}

		return $response;
	}

	/**
	 * Sends a DELETE request using wp_remote_request.
	 *
	 * @since 1.2.9
	 *
	 * @param  string          $url  The URL to send the request to.
	 * @param  array           $args The args to use in the request.
	 * @return array|\WP_Error       The response as an array or WP_Error on failure.
	 */
	public function wpRemoteDelete( $url, $args = [] ) {
		$skipLock = ! empty( $args['aioseo_skip_lock'] );
		unset( $args['aioseo_skip_lock'] );

		$args['method'] = 'DELETE';

		if ( ! $skipLock ) {
			$lockKey = $this->getCacheKey( $url, $args );
			if ( aioseo()->core->cache->get( $lockKey ) ) {
				return new \WP_Error( 'concurrent_request', 'A request to this URL is already in progress.' );
			}

			aioseo()->core->cache->update( $lockKey, true, MINUTE_IN_SECONDS );
		}

		$response = wp_remote_request( $url, array_replace_recursive( $this->getWpApiRequestDefaults(), $args ) );

		if ( ! $skipLock ) {
			aioseo()->core->cache->delete( $lockKey );
		}

		return $response;
	}

	/**
	 * Returns a cache key for a remote request lock based on the HTTP method, URL and body.
	 *
	 * @since 1.2.9
	 *
	 * @param  string $url  The request URL.
	 * @param  array  $args The request arguments.
	 * @return string       The lock cache key.
	 */
	private function getCacheKey( $url, $args = [] ) {
		$method = ! empty( $args['method'] ) ? $args['method'] : 'GET';
		$body   = ! empty( $args['body'] ) ? $args['body'] : '';
		$raw    = is_array( $body ) || is_object( $body ) ? wp_json_encode( $body ) : (string) $body;

		return 'remote_lock_' . md5( $method . $url . $raw );
	}

	/**
	 * Default arguments for wp_remote_get and wp_remote_post.
	 *
	 * @since 1.0.0
	 *
	 * @return array An array of default arguments for the request.
	 */
	private function getWpApiRequestDefaults() {
		return [
			'timeout'    => 10,
			'headers'    => aioseoBrokenLinkChecker()->helpers->getApiHeaders(),
			'user-agent' => aioseoBrokenLinkChecker()->helpers->getApiUserAgent()
		];
	}

	/**
	 * Returns the headers for internal API requests.
	 *
	 * @since 1.0.0
	 *
	 * @return array An array of headers.
	 */
	private function getApiHeaders() {
		return [
			'Content-Type'         => 'application/json',
			'X-AIOSEO-BLC-License' => aioseoBrokenLinkChecker()->internalOptions->internal->license->licenseKey
		];
	}

	/**
	 * Returns the User Agent for internal API requests.
	 *
	 * @since 1.0.0
	 *
	 * @return string The User Agent.
	 */
	private function getApiUserAgent() {
		return 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ) . '; AIOSEO/BrokenLinkChecker/' . AIOSEO_BROKEN_LINK_CHECKER_VERSION;
	}
}