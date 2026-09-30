<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Links\Url;
use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;
use AIOSEO\BrokenLinkChecker\Models;

/**
 * Merges the link status rows that hold the same address written two ways.
 *
 * A host's case and a port the scheme implies anyway carry no meaning - `https://WORDPRESS.ORG/x`,
 * `https://wordpress.org:443/x` and `https://wordpress.org/x` are one address - but each was stored
 * under a hash of its own, so one link ended up owning up to three status rows. Each row was then given
 * a verdict of its own, counted under its own tab, and charged a credit of its own.
 *
 * The scan no longer creates these {@see \AIOSEO\BrokenLinkChecker\Links\Url::resolve()}. This clears
 * the ones already stored, and rewrites the survivor and the links pointing at it into the one form,
 * so the two tables agree about the URL afterwards.
 *
 * NOTE: Candidates are narrowed in SQL to the authority - the only part of a stored URL these two can
 * differ in - and walked in chunks from a cursor. Testing the whole URL instead matched every path
 * holding an upper-case byte or a colon, which on a content-heavy install is most of the table.
 *
 * @since 1.3.1
 */
class MergeNonCanonicalLinkStatuses implements Migration {
	/**
	 * How many candidate rows are read at a time.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const CHUNK_SIZE = 500;

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function name() {
		return 'merge_non_canonical_link_statuses';
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
		$db = aioseoBrokenLinkChecker()->core->db;

		if ( ! $db->tableExists( 'aioseo_blc_link_status' ) || ! $db->tableExists( 'aioseo_blc_links' ) ) {
			return;
		}

		$statusTable = $db->prefix . 'aioseo_blc_link_status';
		$changed     = false;
		$afterId     = 0;

		while ( true ) {
			$candidates = $this->candidates( $afterId );
			if ( empty( $candidates ) ) {
				break;
			}

			$last    = end( $candidates );
			$afterId = (int) $last->id;

			foreach ( $candidates as $candidate ) {
				$url       = (string) $candidate->url;
				$canonical = $this->canonicalOf( $url );
				if ( '' === $canonical ) {
					continue;
				}

				// A candidate can be the row an earlier merge dropped - three ways of writing one
				// address leave two candidates - and merging a row that is gone deletes the survivor.
				if ( ! $this->exists( (int) $candidate->id ) ) {
					continue;
				}

				$hash = sha1( $canonical );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rivalId = (int) $db->db->get_var(
					$db->db->prepare( "SELECT id FROM {$statusTable} WHERE url_hash = %s AND id <> %d LIMIT 1", $hash, $candidate->id )
				);
				// phpcs:enable

				$survivorId = $rivalId
					? $this->merge( $this->survivorOf( (int) $candidate->id, $rivalId ) )
					: (int) $candidate->id;

				$this->canonicalize( $survivorId, $canonical, $hash );

				$changed = true;
			}
		}

		if ( $changed ) {
			Models\LinkStatus::flushCounts();
		}
	}

	/**
	 * Returns the next chunk of rows whose authority may not be the one form of itself.
	 *
	 * NOTE: The cursor is what terminates the walk, not the predicate - it is the primary key and only
	 * ever moves forward. Some authorities match forever: a userinfo and a non-default port both hold a
	 * colon, and {@see self::canonicalOf()} is what decides there is nothing to do about them.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $afterId The ID the last chunk ended on.
	 * @return array          The rows, each carrying an id and a url.
	 */
	private function candidates( $afterId ) {
		$db          = aioseoBrokenLinkChecker()->core->db;
		$statusTable = $db->prefix . 'aioseo_blc_link_status';

		// Everything between `//` and the third `/` - the only part of a stored URL a host's case or a
		// default port can be in. The collation is case-insensitive, so the bytes have to be named.
		$authority = "SUBSTRING_INDEX( SUBSTRING_INDEX( url, '/', 3 ), '//', -1 )";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $db->db->get_results(
			$db->db->prepare(
				"SELECT id, url FROM {$statusTable}
				WHERE id > %d
					AND (
						BINARY {$authority} <> BINARY LOWER( {$authority} )
						OR {$authority} LIKE %s
					)
				ORDER BY id ASC
				LIMIT %d",
				$afterId,
				'%:%',
				self::CHUNK_SIZE
			)
		);
		// phpcs:enable
	}

	/**
	 * Returns the one form of the given URL, or an empty string when the row is already in it.
	 *
	 * NOTE: The save filter is applied here rather than by the caller, so this is the whole of what
	 * gets written. A row the filter accounts for otherwise read as outstanding forever: up() stored
	 * the filtered form and verify() compared the unfiltered one, and the runner retried every request.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url The stored URL.
	 * @return string      The canonical form, or an empty string when there is nothing to do.
	 */
	private function canonicalOf( $url ) {
		$canonical = Url::resolve( $url, $url );
		if ( '' === $canonical ) {
			return '';
		}

		$canonical = (string) apply_filters( 'aioseo_blc_link_url_before_save', $canonical );

		return $canonical === $url ? '' : $canonical;
	}

	/**
	 * Whether the row with the given ID is still there.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $id The row ID.
	 * @return bool     Whether it exists.
	 */
	private function exists( $id ) {
		$db          = aioseoBrokenLinkChecker()->core->db;
		$statusTable = $db->prefix . 'aioseo_blc_link_status';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $db->db->get_var( $db->db->prepare( "SELECT id FROM {$statusTable} WHERE id = %d", $id ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Returns true when no stored row is still holding an address written some other way.
	 *
	 * NOTE: The runner asks this before it calls up(), so it has to be the same question up() answers.
	 * Asking whether a URL owns more than one row instead answered a different one - on a case-insensitive
	 * collation a `:443` row and its plain sibling are two distinct strings, so it passed with the two
	 * still unmerged and had the runner record the migration as landed.
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		$db = aioseoBrokenLinkChecker()->core->db;

		if ( ! $db->tableExists( 'aioseo_blc_link_status' ) ) {
			return true;
		}

		$afterId = 0;
		while ( true ) {
			$candidates = $this->candidates( $afterId );
			if ( empty( $candidates ) ) {
				return true;
			}

			foreach ( $candidates as $candidate ) {
				if ( '' !== $this->canonicalOf( (string) $candidate->url ) ) {
					return false;
				}
			}

			$last    = end( $candidates );
			$afterId = (int) $last->id;
		}
	}

	/**
	 * Returns the two IDs ordered so the row worth keeping comes first.
	 *
	 * A row that has been scanned holds a verdict the other one does not, so it is the one to keep even
	 * when it is the newer of the two. Between two rows that have both been scanned, or neither, the
	 * oldest wins: it is the one the site's links already point at.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $candidateId The row found by its URL.
	 * @param  int   $rivalId     The row already holding the canonical hash.
	 * @return array              The survivor's ID, then the loser's.
	 */
	private function survivorOf( $candidateId, $rivalId ) {
		$db          = aioseoBrokenLinkChecker()->core->db;
		$statusTable = $db->prefix . 'aioseo_blc_link_status';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $db->db->get_results(
			$db->db->prepare(
				"SELECT id FROM {$statusTable} WHERE id IN ( %d, %d )
				ORDER BY ( last_scan_date IS NOT NULL ) DESC, id ASC",
				$candidateId,
				$rivalId
			)
		);
		// phpcs:enable

		if ( 2 !== count( $rows ) ) {
			return [ $candidateId, $rivalId ];
		}

		return [ (int) $rows[0]->id, (int) $rows[1]->id ];
	}

	/**
	 * Points every link at the surviving row and drops the other.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $ids The survivor's ID, then the loser's.
	 * @return int        The survivor's ID.
	 */
	private function merge( $ids ) {
		list( $survivorId, $loserId ) = $ids;

		$db          = aioseoBrokenLinkChecker()->core->db;
		$statusTable = $db->prefix . 'aioseo_blc_link_status';
		$linksTable  = $db->prefix . 'aioseo_blc_links';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$db->db->query(
			$db->db->prepare( "UPDATE {$linksTable} SET blc_link_status_id = %d WHERE blc_link_status_id = %d", $survivorId, $loserId )
		);

		// Dropped before the survivor takes the hash, because the hash is unique and the loser may be
		// the row that holds it.
		$db->db->query( $db->db->prepare( "DELETE FROM {$statusTable} WHERE id = %d", $loserId ) );
		// phpcs:enable

		return $survivorId;
	}

	/**
	 * Writes the one form of the address onto the surviving row and onto every link pointing at it.
	 *
	 * Both tables, because the report joins them and the front-end highlighter reads the links table's
	 * copy - leaving that one alone would have the two disagree about a URL they are meant to share.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $survivorId The surviving status row's ID.
	 * @param  string $canonical  The one form of the address.
	 * @param  string $hash       Its hash.
	 * @return void
	 */
	private function canonicalize( $survivorId, $canonical, $hash ) {
		$db          = aioseoBrokenLinkChecker()->core->db;
		$statusTable = $db->prefix . 'aioseo_blc_link_status';
		$linksTable  = $db->prefix . 'aioseo_blc_links';
		$hostname    = Url::host( $canonical );

		// IGNORE because the hash is unique: a row already holding it means the merge above left this
		// one behind, and being found by its URL is all it loses.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$db->db->query(
			$db->db->prepare(
				"UPDATE IGNORE {$statusTable} SET url = %s, url_hash = %s WHERE id = %d",
				$canonical,
				$hash,
				$survivorId
			)
		);

		$db->db->query(
			$db->db->prepare(
				"UPDATE {$linksTable} SET url = %s, url_hash = %s, hostname = %s, hostname_url = %s
				WHERE blc_link_status_id = %d",
				$canonical,
				$hash,
				$hostname,
				sha1( $hostname ),
				$survivorId
			)
		);
		// phpcs:enable
	}
}