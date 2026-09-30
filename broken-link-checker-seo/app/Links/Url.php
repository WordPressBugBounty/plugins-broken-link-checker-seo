<?php
namespace AIOSEO\BrokenLinkChecker\Links;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a URL the way a browser does, against the page it was written on.
 *
 * NOTE: The stored form is the key the front-end highlighter matches a raw `href` against, and it
 * applies `new URL()` to both sides. So this has to agree with the WHATWG parser rather than with any
 * notion of what the author meant: where resolution lands somewhere that does not exist, that is what
 * the visitor gets, and reporting it broken is the right answer.
 *
 * Measured against `new URL()` over a fixture table under both base schemes. Eight deliberate
 * divergences, and no others:
 *
 * 1. An empty or whitespace-only href resolves to nothing, where a browser resolves it to the page
 *    itself. A link to the page it is written on is not one the scan has anything to report.
 * 2. A scheme the scanner does not follow resolves to nothing, where a browser keeps it. There is no
 *    request to make and no status to report.
 * 3. Without the intl extension an internationalised host keeps its Unicode name instead of becoming
 *    punycode. Not addressable everywhere, but it is still the name, and the row survives.
 * 4. A label `idn_to_ascii()` rejects keeps its Unicode name, where a browser throws. The row is
 *    stored and reported broken rather than disappearing.
 * 5. An IPv6 literal keeps the form it was written in, where a browser rewrites it to its shortest
 *    one - `[::ffff:1.2.3.4]` stays as it is rather than becoming `[::ffff:102:304]`. The brackets are
 *    gated on a character class rather than parsed, so a few shapes a browser rejects are accepted -
 *    `[abcdef]`, `[1.2.3.4]`. No raw byte reaches the column through them, which is the part that
 *    matters.
 * 6. A fragment and an empty query are dropped, where a browser keeps both - `page#section` and
 *    `page?` both resolve to `page`. The stored form has never carried either, and `matchKey()` drops
 *    both on the href side too, so the two sides still agree. A query that carries something is kept.
 * * 7. A host written as a number keeps the spelling it was written in, where a browser rewrites it to
 *    dotted quad - `http://2130706433/`, `http://0x7f000001/` and `http://127.1/` all stay as they
 *    are rather than becoming `http://127.0.0.1/`. Parsing the decimal, hex and short forms is a lot
 *    of surface for an address nobody writes on purpose, and `wp_http_validate_url()` refuses every
 *    loopback spelling anyway, so none of them is ever fetched.
 * 8. A port above the addressable range is kept and checked, where a browser refuses to parse the URL
 *    at all - `https://a.test:99999/x` is reported by whatever the fetch says rather than being
 *    settled here. The highlighter still agrees, because the href goes through `new URL()` on that
 *    side and fails there too, so the link is simply never marked.
 *
 * @since 1.3.1
 */
class Url {
	/**
	 * The schemes resolved by the WHATWG rules for a "special" scheme, mapped to their default port.
	 *
	 * NOTE: Deliberately only the two the scanner follows
	 * {@see \AIOSEO\BrokenLinkChecker\Traits\Helpers\Url::hasUnscannableScheme()}. Anything else has no
	 * authority to resolve and no request to make, so it resolves to nothing.
	 *
	 * @since 1.3.1
	 *
	 * @var array<string, int>
	 */
	const SPECIAL_SCHEMES = [
		'http'  => 80,
		'https' => 443
	];

	/**
	 * The bytes percent-encoded in a path on top of the C0 control set.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const PATH_ENCODE_SET = '"#<>?^`{}';

	/**
	 * The bytes percent-encoded in a query on top of the C0 control set.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const QUERY_ENCODE_SET = '"#<>\'';

	/**
	 * The bytes the wire form encodes in a path on top of {@see self::PATH_ENCODE_SET}.
	 *
	 * NOTE: What RFC 3986 forbids in a path and the WHATWG rules leave raw. The two forms differ here
	 * on purpose: a browser keeps these in an `href`, so the matching key has to as well, while a
	 * request carrying them raw is malformed.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const WIRE_PATH_ENCODE_SET = '[]|';

	/**
	 * The bytes the wire form encodes in a query on top of {@see self::QUERY_ENCODE_SET}.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const WIRE_QUERY_ENCODE_SET = '[]^`{|}\\';

	/**
	 * The bytes percent-encoded in a user or a password on top of the C0 control set.
	 *
	 * NOTE: `/`, `\`, `?` and `#` cannot reach here - they end the authority before the `@` is looked
	 * for.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const USERINFO_ENCODE_SET = '":;<=>@[]^`{|}';

	/**
	 * The bytes a host may not hold once it has been percent-decoded.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const FORBIDDEN_HOST_BYTES = '/[\x00-\x20\x7F #\/:<>?@\[\]\\\\^|%]/';

	/**
	 * Resolves the given href against the URL of the page it was written on.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $href    The href as it was written.
	 * @param  string $baseUrl The URL of the page holding it.
	 * @return string          The resolved URL, or an empty string when it resolves to nothing.
	 */
	public static function resolve( $href, $baseUrl ) {
		$parts = self::parts( $href, $baseUrl );

		return isset( $parts['url'] ) ? $parts['url'] : '';
	}

	/**
	 * Resolves the given href and returns the parts resolution produced.
	 *
	 * NOTE: `url` is the authoritative form and the only one carrying the userinfo. Handing the parts
	 * to `wp_parse_url()` instead read them back with a lossier parser: every 0x80-0x9F byte of a
	 * non-Latin host became an underscore, which left the host unusable and the link unreported.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $href    The href as it was written.
	 * @param  string $baseUrl The URL of the page holding it.
	 * @return array           The parts, or an empty array when it resolves to nothing.
	 */
	public static function parts( $href, $baseUrl ) {
		$href = self::clean( $href );
		if ( '' === $href ) {
			return [];
		}

		$base = self::parseBase( self::clean( $baseUrl ) );

		if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $href, $scheme ) ) {
			$hrefScheme = strtolower( $scheme[1] );
			if ( ! isset( self::SPECIAL_SCHEMES[ $hrefScheme ] ) ) {
				return [];
			}

			$remainder = substr( $href, strlen( $scheme[0] ) );

			// The measured rule: a scheme matching the page's own leaves a relative reference behind,
			// where a differing one parses as absolute however few slashes follow it.
			$sameScheme = null !== $base && $base['scheme'] === $hrefScheme;
			if ( ! $sameScheme || preg_match( '#^[/\\\\]{2}#', $remainder ) ) {
				return self::fromAuthority( $hrefScheme, ltrim( $remainder, '/\\' ) );
			}

			return self::fromReference( $base, $remainder );
		}

		if ( null === $base ) {
			return [];
		}

		// The authority is given but the scheme is not, so the page's own is inherited.
		if ( preg_match( '#^[/\\\\]{2}#', $href ) ) {
			return self::fromAuthority( $base['scheme'], ltrim( $href, '/\\' ) );
		}

		return self::fromReference( $base, $href );
	}

	/**
	 * Reduces a host to the form a link row stores it in, so the two can be compared.
	 *
	 * Accepts a whole URL as readily as a host, which is an easy thing to paste into a setting.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $host The host, which may arrive as a whole URL.
	 * @return string       The host, or an empty string when it holds none.
	 */
	public static function host( $host ) {
		$host = self::clean( $host );
		if ( '' === $host ) {
			return '';
		}

		// Only where a slash pair follows, or the port of a bare `example.com:8080` would read as a scheme.
		$host = (string) preg_replace( '#^[a-zA-Z][a-zA-Z0-9+.\-]*:(?=[/\\\\]{2})#', '', $host );
		$host = ltrim( $host, '/\\' );
		$host = substr( $host, 0, strcspn( $host, '/\\?#' ) );

		$at = strrpos( $host, '@' );
		if ( false !== $at ) {
			$host = substr( $host, $at + 1 );
		}

		$hostPort = self::splitHostPort( $host );
		$host     = self::canonicalHost( $hostPort['host'] );

		// Anchored, so a name that merely contains it - `cdn.www.example.com`, `newww.invalid` - is left
		// as it is. The stored hostname has this taken off it, so a host given with it still has to match.
		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Rewrites a stored URL into the form a request for it goes out as.
	 *
	 * The stored form is the key the highlighter matches a raw `href` against, so it keeps the bytes a
	 * browser keeps. A request cannot: the same bytes make the request line malformed. So the two forms
	 * are allowed to differ, and this is the only place that difference is made.
	 *
	 * NOTE: Escaping and nothing else. The host is left exactly as it is, brackets included, because an
	 * IPv6 literal is addressed by them. `%` is never encoded, so this is idempotent.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $storedUrl The stored URL.
	 * @return string            The URL to send.
	 */
	public static function wire( $storedUrl ) {
		$storedUrl = (string) $storedUrl;

		$mark = strpos( $storedUrl, '://' );
		if ( false === $mark ) {
			return $storedUrl;
		}

		$origin = substr( $storedUrl, 0, $mark + 3 );
		$rest   = substr( $storedUrl, $mark + 3 );

		// Everything up to the first `/` or `?` is the authority. Neither byte can reach a stored
		// userinfo {@see self::USERINFO_ENCODE_SET}, so this cannot cut one in half.
		$length    = strcspn( $rest, '/?' );
		$authority = substr( $rest, 0, $length );
		$tail      = substr( $rest, $length );

		$question = strpos( $tail, '?' );
		$path     = false === $question ? $tail : substr( $tail, 0, $question );
		$query    = false === $question ? null : substr( $tail, $question + 1 );

		return $origin . $authority . self::encode( $path, self::WIRE_PATH_ENCODE_SET ) .
			( null === $query ? '' : '?' . self::encode( $query, self::WIRE_QUERY_ENCODE_SET ) );
	}

	/**
	 * Whether the host of the given URL is one no resolver can ever answer for.
	 *
	 * Deliberately narrow. A host that merely looks unlikely may well be served - a wildcard record, a
	 * private resolver - so this only names what the standard leaves no room for: an empty label, a
	 * label the hyphen rules forbid, a label or a name over length, or a byte a name cannot hold.
	 *
	 * NOTE: An explicit test rather than a parse failure. `new URL()` accepts `https://a..test/x` and
	 * `https://-a.test/x` without complaint, so catching a parse error would find neither.
	 *
	 * NOTE: An underscore is not a legal host-name character, but DNS carries any byte in a label and
	 * both browsers and cURL resolve `my_host.test` - so those pages load and are not ours to refuse.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The URL, or a bare host.
	 * @return bool        Whether the host can never resolve.
	 */
	public static function hasUnresolvableHost( $url ) {
		$host = self::host( $url );

		// An IPv6 literal is already validated on its way in {@see self::canonicalHost()}.
		if ( '' === $host || '[' === $host[0] ) {
			return false;
		}

		// A single trailing dot is a fully qualified name, not an empty label.
		$host = rtrim( $host, '.' );
		if ( '' === $host || 253 < strlen( $host ) ) {
			return true;
		}

		foreach ( explode( '.', $host ) as $label ) {
			if ( '' === $label || 63 < strlen( $label ) ) {
				return true;
			}

			if ( '-' === $label[0] || '-' === substr( $label, -1 ) ) {
				return true;
			}

			// A label still holding non-ASCII was never converted, so there is no ASCII name to judge
			// - divergences 3 and 4. It is reported broken on those installs rather than refused here.
			if ( ! preg_match( '/[\x80-\xFF]/', $label ) && preg_match( '/[^a-z0-9_-]/', $label ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves a reference that carries its own authority.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $scheme    The scheme the URL resolves under.
	 * @param  string $remainder Everything from the start of the authority on.
	 * @return array             The resolved parts, or an empty array.
	 */
	private static function fromAuthority( $scheme, $remainder ) {
		$remainder = self::stripFragment( $remainder );
		$length    = strcspn( $remainder, '/\\?' );
		$authority = self::canonicalAuthority( substr( $remainder, 0, $length ), $scheme );
		if ( empty( $authority ) ) {
			return [];
		}

		$tail  = substr( $remainder, $length );
		$mark  = strpos( $tail, '?' );
		$path  = false === $mark ? $tail : substr( $tail, 0, $mark );
		$query = false === $mark ? null : substr( $tail, $mark + 1 );

		return self::serialize( $scheme, $authority, $path, $query );
	}

	/**
	 * Resolves a relative reference against the page URL, per RFC 3986 section 5.2.
	 *
	 * @since 1.3.1
	 *
	 * @param  array  $base      The parsed page URL.
	 * @param  string $reference The relative reference.
	 * @return array             The resolved parts, or an empty array.
	 */
	private static function fromReference( $base, $reference ) {
		$reference = self::stripFragment( $reference );
		$mark      = strpos( $reference, '?' );
		$path      = false === $mark ? $reference : substr( $reference, 0, $mark );
		$query     = false === $mark ? null : substr( $reference, $mark + 1 );

		if ( '' === $path ) {
			return self::serialize(
				$base['scheme'],
				$base['authority'],
				$base['path'],
				null === $query ? $base['query'] : $query
			);
		}

		$path = str_replace( '\\', '/', $path );
		if ( '/' !== $path[0] ) {
			$path = self::mergePath( $base['path'], $path );
		}

		return self::serialize( $base['scheme'], $base['authority'], $path, $query );
	}

	/**
	 * Writes the resolved parts out, alongside the URL they serialize to.
	 *
	 * NOTE: The fragment is gone by here and an empty query is dropped, which is what the highlighter's
	 * own key does to both sides as well.
	 *
	 * @since 1.3.1
	 *
	 * @param  string      $scheme    The scheme.
	 * @param  array       $authority The canonical authority parts.
	 * @param  string      $path      The merged path.
	 * @param  string|null $query     The query, without its leading question mark.
	 * @return array                  The parts.
	 */
	private static function serialize( $scheme, $authority, $path, $query ) {
		$path  = self::encode( self::removeDotSegments( str_replace( '\\', '/', (string) $path ) ), self::PATH_ENCODE_SET );
		$query = null === $query || '' === $query ? null : self::encode( $query, self::QUERY_ENCODE_SET );
		$port  = '' !== $authority['port'] ? ':' . $authority['port'] : '';

		return [
			'url'    => $scheme . '://' . $authority['userinfo'] . $authority['host'] . $port . $path .
				( null === $query ? '' : '?' . $query ),
			'scheme' => $scheme,
			'host'   => $authority['host'],
			'port'   => $authority['port'],
			'path'   => $path,
			'query'  => $query
		];
	}

	/**
	 * Parses the page URL into the parts a relative reference is resolved against.
	 *
	 * @since 1.3.1
	 *
	 * @param  string     $baseUrl The page URL.
	 * @return array|null          The parts, or null when it is no base for anything.
	 */
	private static function parseBase( $baseUrl ) {
		if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $baseUrl, $scheme ) ) {
			return null;
		}

		$baseScheme = strtolower( $scheme[1] );
		if ( ! isset( self::SPECIAL_SCHEMES[ $baseScheme ] ) ) {
			return null;
		}

		$remainder = self::stripFragment( ltrim( substr( $baseUrl, strlen( $scheme[0] ) ), '/\\' ) );
		$length    = strcspn( $remainder, '/\\?' );
		$authority = self::canonicalAuthority( substr( $remainder, 0, $length ), $baseScheme );
		if ( empty( $authority ) ) {
			return null;
		}

		$tail = substr( $remainder, $length );
		$mark = strpos( $tail, '?' );
		$path = str_replace( '\\', '/', false === $mark ? $tail : substr( $tail, 0, $mark ) );

		return [
			'scheme'    => $baseScheme,
			'authority' => $authority,
			'path'      => '' === $path ? '/' : $path,
			'query'     => false === $mark ? null : substr( $tail, $mark + 1 )
		];
	}

	/**
	 * Reduces an authority to its canonical form, dropping a port the scheme implies anyway.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $authority The authority as it was written.
	 * @param  string $scheme    The scheme it belongs to.
	 * @return array             The authority parts, or an empty array when it cannot be one.
	 */
	private static function canonicalAuthority( $authority, $scheme ) {
		$userInfo = '';
		$at       = strrpos( $authority, '@' );
		if ( false !== $at ) {
			$userInfo  = self::canonicalUserInfo( substr( $authority, 0, $at ) );
			$authority = substr( $authority, $at + 1 );
		}

		$hostPort = self::splitHostPort( $authority );
		$host     = self::canonicalHost( $hostPort['host'] );
		if ( '' === $host ) {
			return [];
		}

		$port = $hostPort['port'];
		if ( '' !== $port && ! ctype_digit( $port ) ) {
			return [];
		}

		if ( '' !== $port && self::SPECIAL_SCHEMES[ $scheme ] === (int) $port ) {
			$port = '';
		}

		return [
			'userinfo' => $userInfo,
			'host'     => $host,
			'port'     => $port
		];
	}

	/**
	 * Reduces the userinfo to its canonical form and percent-encodes it.
	 *
	 * NOTE: Encoded because this is still the string the request goes out as - a raw space made the
	 * outgoing request malformed rather than merely reading oddly.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $userInfo The userinfo as it was written, without its at sign.
	 * @return string           The userinfo with its at sign, or an empty string when it holds nothing.
	 */
	private static function canonicalUserInfo( $userInfo ) {
		// Only the first colon separates the two; a later one is part of the password.
		$colon    = strpos( $userInfo, ':' );
		$user     = self::encode( false === $colon ? $userInfo : substr( $userInfo, 0, $colon ), self::USERINFO_ENCODE_SET );
		$password = self::encode( false === $colon ? '' : substr( $userInfo, $colon + 1 ), self::USERINFO_ENCODE_SET );

		if ( '' === $user && '' === $password ) {
			return '';
		}

		return $user . ( '' !== $password ? ':' . $password : '' ) . '@';
	}

	/**
	 * Splits a host from its port, keeping a bracketed IPv6 literal in one piece.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $hostPort The host, possibly with a port.
	 * @return array            The host and the port, each possibly empty.
	 */
	private static function splitHostPort( $hostPort ) {
		$hostPort = (string) $hostPort;
		$empty    = [
			'host' => '',
			'port' => ''
		];

		if ( '' === $hostPort ) {
			return $empty;
		}

		if ( '[' === $hostPort[0] ) {
			$close = strpos( $hostPort, ']' );
			if ( false === $close ) {
				return $empty;
			}

			$after = substr( $hostPort, $close + 1 );

			return [
				'host' => substr( $hostPort, 0, $close + 1 ),
				'port' => 0 === strpos( $after, ':' ) ? substr( $after, 1 ) : ''
			];
		}

		$parts = explode( ':', $hostPort, 2 );

		return [
			'host' => $parts[0],
			'port' => isset( $parts[1] ) ? $parts[1] : ''
		];
	}

	/**
	 * Reduces a host to the form it is addressed by.
	 *
	 * A host cannot carry percent-encoding, so an internationalised one becomes punycode - which is what
	 * a request for it goes out as anyway. Divergences 3 and 4 in the class docblock are here.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $host The host as it was written.
	 * @return string       The host, or an empty string when it cannot be one.
	 */
	private static function canonicalHost( $host ) {
		$host = (string) $host;
		if ( '' === $host ) {
			return '';
		}

		if ( '[' === $host[0] ) {
			// An IPv6 literal or nothing. The byte filter below never runs on this branch, so a
			// bracketed `[ ]` or `[<script>]` otherwise reached the column with its bytes raw.
			$host = strtolower( $host );

			return preg_match( '/^\[[0-9a-f:.]+\]$/', $host ) ? $host : '';
		}

		$host = self::lowercase( rawurldecode( $host ) );

		// Encoded rather than refused. The address is dead either way - Chrome asks after a host
		// that cannot exist, Firefox and Safari will not parse it - but a row that never gets stored
		// can be neither reported nor highlighted. hasUnresolvableHost() then reads the encoded
		// label as unresolvable and the row is reported broken with the invalid-host reason, which
		// is how an empty or dash-edged label is already handled.
		if ( preg_match( self::FORBIDDEN_HOST_BYTES, $host ) ) {
			return rawurlencode( $host );
		}

		if ( preg_match( '/[\x80-\xFF]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$host = self::punycode( $host );
		}

		return $host;
	}

	/**
	 * Converts the internationalised labels of a host to punycode.
	 *
	 * NOTE: Label by label, because idn_to_ascii() refuses a whole host over one label the standard
	 * forbids, and a browser converts the rest of it regardless - `a..日本.test` punycodes its one
	 * non-Latin label and keeps the empty one.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $host The lowercased, percent-decoded host.
	 * @return string       The host with every convertible label converted.
	 */
	private static function punycode( $host ) {
		$labels = explode( '.', $host );

		foreach ( $labels as $index => $label ) {
			// An empty label holds nothing to convert, which is also what keeps idn_to_ascii() from
			// being handed the empty string it raises on.
			if ( ! preg_match( '/[\x80-\xFF]/', $label ) ) {
				continue;
			}

			$converted = idn_to_ascii( $label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( ! empty( $converted ) ) {
				$labels[ $index ] = strtolower( $converted );
			}
		}

		return implode( '.', $labels );
	}

	/**
	 * Merges a relative path onto the page's own, per RFC 3986 section 5.2.3.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $basePath  The page's path.
	 * @param  string $reference The relative path.
	 * @return string            The merged path.
	 */
	private static function mergePath( $basePath, $reference ) {
		$basePath = '' === $basePath ? '/' : $basePath;
		$slash    = strrpos( $basePath, '/' );

		return ( false === $slash ? '/' : substr( $basePath, 0, $slash + 1 ) ) . $reference;
	}

	/**
	 * Collapses the `.` and `..` segments of a path.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $path The path.
	 * @return string       The path, always with a leading slash and never with a dot segment.
	 */
	private static function removeDotSegments( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return '/';
		}

		// Only the one slash the leading `/` below puts back: trimming every one of them collapsed
		// `//wp-content/x` to `/wp-content/x`, which no browser does and which a second pass moved again.
		$segments = explode( '/', '/' === $path[0] ? substr( $path, 1 ) : $path );
		$last     = count( $segments ) - 1;
		$output   = [];

		foreach ( $segments as $index => $segment ) {
			// A browser decodes the segment before it decides, so `/%2e%2e/x` walks up as `/../x` does.
			$dotted = str_ireplace( '%2e', '.', $segment );

			if ( '.' === $dotted || '..' === $dotted ) {
				if ( '..' === $dotted ) {
					array_pop( $output );
				}

				// A path ending in a dot segment still ends in a slash, the way `/a/b/..` lands on `/a/`.
				if ( $index === $last ) {
					$output[] = '';
				}

				continue;
			}

			$output[] = $segment;
		}

		return '/' . implode( '/', $output );
	}

	/**
	 * Percent-encodes the bytes the given component may not hold raw.
	 *
	 * NOTE: `%` is never encoded, so an escape the author wrote is left exactly as it is - re-encoding
	 * it would make `%2F` and `/` collapse into each other and misattribute a result.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $value The component.
	 * @param  string $set   The bytes to encode on top of the C0 control set.
	 * @return string        The encoded component.
	 */
	private static function encode( $value, $set ) {
		$value   = (string) $value;
		$encoded = '';
		$length  = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$byte = $value[ $i ];
			$code = ord( $byte );

			if ( 0x20 >= $code || 0x7F <= $code || false !== strpos( $set, $byte ) ) {
				$encoded .= '%' . strtoupper( bin2hex( $byte ) );

				continue;
			}

			$encoded .= $byte;
		}

		return $encoded;
	}

	/**
	 * Drops the fragment, which the stored form never carries.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $value The reference.
	 * @return string        The reference without its fragment.
	 */
	private static function stripFragment( $value ) {
		$hash = strpos( (string) $value, '#' );

		return false === $hash ? (string) $value : substr( (string) $value, 0, $hash );
	}

	/**
	 * Strips what a browser strips before it parses: the surrounding whitespace and every tab and line break.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $value The value as it was written.
	 * @return string        The value.
	 */
	private static function clean( $value ) {
		$value = trim( (string) $value, "\x00..\x20" );

		return (string) preg_replace( '/[\x09\x0A\x0D]/', '', $value );
	}

	/**
	 * Lowercases a value that may hold multibyte characters.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $value The value.
	 * @return string        The lowercased value.
	 */
	private static function lowercase( $value ) {
		if ( preg_match( '/[\x80-\xFF]/', $value ) && function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $value, 'UTF-8' );
		}

		return strtolower( $value );
	}
}