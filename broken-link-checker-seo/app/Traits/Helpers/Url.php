<?php
namespace AIOSEO\BrokenLinkChecker\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Links\Url as LinkUrl;

/**
 * Contains URL specific helper methods.
 *
 * @since 1.0.0
 */
trait Url {
	/**
	 * Whether a URL carries a scheme the scanner has no business following.
	 *
	 * NOTE: An allowlist, rather than the handful of schemes worth skipping. Anything unrecognised fell
	 * through to sanitize_url(), which drops the scheme it does not know and leaves text that is then
	 * resolved against the site — so "sms:+15551234" was indexed as a link to /+15551234 that exists
	 * nowhere, and was re-checked against the quota every cycle. A relative or protocol-relative URL has
	 * no scheme to judge and stays scannable.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL as it was written.
	 * @return bool        Whether the scanner should skip it.
	 */
	public function hasUnscannableScheme( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return true;
		}

		if ( ! preg_match( '/^([a-z][a-z0-9+.\-]*):/i', trim( $url ), $scheme ) ) {
			return false;
		}

		return ! in_array( strtolower( $scheme[1] ), [ 'http', 'https' ], true );
	}

	/**
	 * Filters a URL a link is about to be pointed at, returning an empty string when it can't be one.
	 *
	 * NOTE: Only the schemes KSES allows survive, since the regex rewrite path writes the value into
	 * post content as-is and nothing filters it afterwards.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL.
	 * @return string      The filtered URL, or an empty string.
	 */
	public function sanitizeLinkUrl( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		// esc_url() presumes http:// on anything without a scheme, so "about-us/" would come back as a
		// URL of its own. Those are filtered under a placeholder origin that is stripped back off.
		if ( ! $this->isRelativePathUrl( $url ) ) {
			return esc_url_raw( $url );
		}

		// The placeholder makes esc_url() skip its own scheme check, and that check is the only one
		// that catches an entity-encoded "javascript:".
		if ( strtolower( wp_kses_bad_protocol( $url, wp_allowed_protocols() ) ) !== strtolower( $url ) ) {
			return '';
		}

		$placeholder = 'https://a/';
		$filtered    = esc_url_raw( $placeholder . $url );

		return 0 === strpos( $filtered, $placeholder ) ? substr( $filtered, strlen( $placeholder ) ) : '';
	}

	/**
	 * Returns whether the URL is a path with neither a scheme nor a leading slash, hash or question mark.
	 *
	 * NOTE: Both esc_url() and, through it, WP_HTML_Tag_Processor::set_attribute() turn such a path
	 * into an absolute URL of its own, so it needs handling wherever either of them is in the way.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL.
	 * @return bool        Whether the URL is a scheme-less relative path.
	 */
	public function isRelativePathUrl( $url ) {
		$url = (string) $url;

		return '' !== $url && false === strpos( $url, ':' ) && ! in_array( $url[0], [ '/', '#', '?' ], true );
	}

	/**
	 * Returns the hostname of the given URL in the shape a link row stores it.
	 *
	 * NOTE: The same {@see \AIOSEO\BrokenLinkChecker\Links\Url::host()} that
	 * {@see \AIOSEO\BrokenLinkChecker\Links\Data::urlFields()} writes with, so the two agree by
	 * construction. Anything matching a stored row on its hostname has to ask for it the same way, or a
	 * site served on www never matches its own links.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL.
	 * @return string      The hostname, or an empty string when the URL carries none.
	 */
	public function getStoredHostname( $url ) {
		return LinkUrl::host( $url );
	}

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