<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;
use AIOSEO\BrokenLinkChecker\Models;

/**
 * Moves the excluded domains into the URL exclusion list, and drops what either now covers.
 *
 * Two settings both meant "leave these alone", so a reader had to know which one a host belonged in.
 * The domains become `host:` lines, which the pattern list matches against the host exactly the way
 * the domain setting did — the wording is all that changes, not what is excluded.
 *
 * Exclusion is decided when a link is stored, so the rows already there have to go too, or they keep
 * being reported and counted. That part was written against the plugin's version-compare updates,
 * where the next-version placeholder is never substituted and so the comparison never came out true.
 *
 * @since 1.3.1
 */
class FoldExcludedDomainsIntoPatterns implements Migration {
	/**
	 * The name this migration is logged under.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const NAME = 'fold_excluded_domains_into_patterns';

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function name() {
		return self::NAME;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function version() {
		return '1.3.1';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function up() {
		$helpers = aioseoBrokenLinkChecker()->helpers;
		$domains = $helpers->getExcludedDomains();

		// Read once as an array: a chained read leaves the accessor pointing at this group, and the next
		// one resolves against it and comes back null.
		$advanced = aioseoBrokenLinkChecker()->options->advanced->all();
		$raw      = isset( $advanced['excludeUrlPatterns'] ) && is_string( $advanced['excludeUrlPatterns'] )
			? $advanced['excludeUrlPatterns']
			: '';

		$lines    = preg_split( '/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$lines    = array_map( 'trim', is_array( $lines ) ? $lines : [] );
		$existing = $helpers->splitUrlPatterns( $raw )['host'];
		$added    = [];
		$changed  = false;

		foreach ( $domains as $domain ) {
			$entry = trim( (string) $domain );
			if ( '' === $entry ) {
				continue;
			}

			// An entry that names more than a site - a path, a query - is carried over exactly as it was
			// typed. Reducing it to its host turned a rule that named one page into one that names the
			// whole site, and the links on that site were deleted from the report on the way. The old
			// field compared entries against a bare host column, so such an entry matched nothing there;
			// keeping it verbatim makes it mean the page it always read as.
			if ( self::namesMoreThanASite( $entry ) ) {
				if ( ! in_array( $entry, $lines, true ) ) {
					$lines[]  = $entry;
					$changed  = true;
				}

				continue;
			}

			$host = $helpers->normalizeHost( $entry );

			// Idempotent: a host the list already covers is not written again, so a re-run is a no-op.
			if ( '' === $host || in_array( $host, $existing, true ) || in_array( $host, $added, true ) ) {
				continue;
			}

			$added[]  = $host;
			$lines[]  = 'host:' . $host;
			$changed  = true;
		}

		// Not just $added: a list of nothing but full URLs adds no host, and guarding on $added alone
		// dropped the entries carried over verbatim without ever saving them.
		if ( $changed ) {
			aioseoBrokenLinkChecker()->options->advanced->excludeUrlPatterns = implode( "\n", array_filter( $lines ) );
			aioseoBrokenLinkChecker()->options->save( true );
		}

		// The domains are passed alongside, because a host that was already in the pattern list is not in
		// $added and its rows would otherwise be left behind.
		Models\Link::deleteExcluded(
			$helpers->splitUrlPatterns( aioseoBrokenLinkChecker()->options->advanced->excludeUrlPatterns ),
			$domains
		);
	}

	/**
	 * Whether an entry names something narrower than a whole site.
	 *
	 * A scheme does not: someone who typed `https://example.com` meant the site, and the host form says
	 * so more plainly. A path or a query does, and that is the specificity worth keeping.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $entry The entry as it was typed.
	 * @return bool          Whether it names more than a site.
	 */
	private static function namesMoreThanASite( $entry ) {
		$withoutScheme = preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', trim( (string) $entry ) );

		// One trailing slash is how people write a bare host, not a path.
		$withoutScheme = rtrim( (string) $withoutScheme, '/' );

		return (bool) preg_match( '#[/?\#]#', (string) $withoutScheme );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Every domain now reads as a host in the pattern list. Deliberately says nothing about the rows
	 * that were deleted: the scan stores no excluded link, so a count of what is left is always zero
	 * whether or not this ran.
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		$helpers  = aioseoBrokenLinkChecker()->helpers;
		$patterns = aioseoBrokenLinkChecker()->options->advanced->excludeUrlPatterns;
		$hosts    = $helpers->splitUrlPatterns( $patterns )['host'];
		$lines    = preg_split( '/\R/', (string) $patterns, -1, PREG_SPLIT_NO_EMPTY );
		$lines    = array_map( 'trim', is_array( $lines ) ? $lines : [] );

		foreach ( $helpers->getExcludedDomains() as $domain ) {
			$entry = trim( (string) $domain );

			// Carried over as typed rather than folded into a host, so that is what to look for. Checking
			// its host instead could never pass, and the runner would re-run this on every load.
			if ( self::namesMoreThanASite( $entry ) ) {
				if ( ! in_array( $entry, $lines, true ) ) {
					return false;
				}

				continue;
			}

			$host = $helpers->normalizeHost( $entry );
			if ( '' !== $host && ! in_array( $host, $hosts, true ) ) {
				return false;
			}
		}

		return true;
	}
}