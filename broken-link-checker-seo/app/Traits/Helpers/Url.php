<?php
namespace AIOSEO\BrokenLinkChecker\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains URL specific helper methods.
 *
 * @since 1.0.0
 */
trait Url {
	/**
	 * Builds a URL from a parse_url array.
	 *
	 * @since 1.2.6
	 *
	 * @param  array  $params  The params array.
	 * @param  array  $include The keys to include [scheme, user, pass, host, port, path, query, fragment].
	 * @param  array  $exclude The keys to exclude [scheme, user, pass, host, port, path, query, fragment].
	 * @return string          The built url.
	 */
	public function buildUrl( $params, $include = [], $exclude = [] ) {
		if ( ! is_array( $params ) ) {
			return $params;
		}

		if ( ! empty( $include ) ) {
			foreach ( array_keys( $params ) as $includeKey ) {
				if ( ! in_array( $includeKey, $include, true ) ) {
					unset( $params[ $includeKey ] );
				}
			}
		}

		if ( ! empty( $exclude ) ) {
			foreach ( array_keys( $params ) as $excludeKey ) {
				if ( in_array( $excludeKey, $exclude, true ) ) {
					unset( $params[ $excludeKey ] );
				}
			}
		}

		$url = '';
		if ( ! empty( $params['scheme'] ) ) {
			$url .= $params['scheme'] . '://';
		}
		if ( ! empty( $params['user'] ) ) {
			$url .= $params['user'];

			if ( isset( $params['pass'] ) ) {
				$url .= ':' . $params['pass'];
			}

			$url .= '@';
		}

		if ( ! empty( $params['host'] ) ) {
			$url .= $params['host'];
		}

		if ( ! empty( $params['port'] ) ) {
			$url .= ':' . $params['port'];
		}

		if ( ! empty( $params['path'] ) ) {
			// Insert a '/' between host and path when the path lacks one — otherwise a relative
			// link like `who-we-are` resolves to `host.comwho-we-are` instead of `host.com/who-we-are`.
			if ( ! empty( $params['host'] ) && '/' !== substr( $params['path'], 0, 1 ) ) {
				$url .= '/';
			}
			$url .= $params['path'];
		}

		if ( ! empty( $params['query'] ) ) {
			$url .= '?' . $params['query'];
		}

		if ( ! empty( $params['fragment'] ) ) {
			$url .= '#' . $params['fragment'];
		}

		return $url;
	}
}