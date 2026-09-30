<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Repairs link status rows whose hash no longer derives from their own URL, and merges the URLs that
 * ended up owning more than one row because of it.
 *
 * The hash is written from the URL as normalization leaves it, so a change to that normalization left
 * older rows carrying a hash their URL no longer produces. The scan looks a URL up by its hash, so such
 * a row is invisible to it and a second one gets inserted. Each row is then given a verdict of its own,
 * and the report counts and lists the URL under two tabs at once - the tabs no longer sum to All.
 *
 * The scan itself no longer creates these {@see \AIOSEO\BrokenLinkChecker\Links\Data::adoptByUrl()}. This
 * clears the ones already stored.
 *
 * NOTE: Candidates are found with SQL's own SHA1() so the whole table does not have to be read into PHP,
 * but the replacement hash is derived the way the writers derive it - through
 * aioseo_blc_link_url_before_save. A row the filter accounts for is left alone rather than rewritten.
 *
 * @since 1.3.1
 */
class MergeDuplicateLinkStatuses implements Migration {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function name() {
		return 'merge_duplicate_link_statuses';
	}

	/**
	 * How many candidate rows to hold at once.
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
		$merged      = false;
		$afterId     = 0;

		// Walked in chunks by id. A site with a filter registered on the URL makes every row a
		// candidate, and holding the whole table in memory at once is what that would cost.
		while ( true ) {
			$candidates = $this->candidates( $afterId );
			if ( empty( $candidates ) ) {
				break;
			}

			$last    = end( $candidates );
			$afterId = (int) $last->id;

			foreach ( $candidates as $candidate ) {
				$url  = (string) $candidate->url;
				$hash = sha1( (string) apply_filters( 'aioseo_blc_link_url_before_save', $url ) );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rivalId = (int) $db->db->get_var(
					$db->db->prepare( "SELECT id FROM {$statusTable} WHERE url_hash = %s AND id <> %d LIMIT 1", $hash, $candidate->id )
				);
				// phpcs:enable

				if ( ! $rivalId ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$db->db->query(
						$db->db->prepare( "UPDATE IGNORE {$statusTable} SET url_hash = %s WHERE id = %d", $hash, $candidate->id )
					);
					// phpcs:enable

					continue;
				}

				$pair = $this->survivorOf( (int) $candidate->id, $rivalId );
				if ( empty( $pair ) ) {
					continue;
				}

				$this->merge( $pair, $hash );

				$merged = true;
			}
		}

		if ( $merged ) {
			\AIOSEO\BrokenLinkChecker\Models\LinkStatus::flushCounts();
		}
	}

	/**
	 * Returns the next chunk of rows whose stored hash is not the hash of their URL.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $afterId The highest id the previous chunk reached.
	 * @return array          The rows, each carrying an id and a url.
	 */
	private function candidates( $afterId ) {
		$db          = aioseoBrokenLinkChecker()->core->db;
		$statusTable = $db->prefix . 'aioseo_blc_link_status';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $db->db->get_results(
			$db->db->prepare(
				"SELECT id, url FROM {$statusTable}
				WHERE id > %d AND url_hash <> SHA1( url )
				ORDER BY id ASC
				LIMIT %d",
				$afterId,
				self::CHUNK_SIZE
			)
		);
		// phpcs:enable
	}

	/**
	 * {@inheritdoc}
	 *
	 * Returns true when no URL owns more than one row, which is the whole of what went wrong.
	 *
	 * NOTE: Grouped on a hash of the URL, not on the column, whose collation is case- and
	 * accent-insensitive - it grouped rows up() cannot merge, so the runner retried on every request
	 * forever. Not the stored url_hash either: a stale one there hides a duplicate this has to find.
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		$db = aioseoBrokenLinkChecker()->core->db;

		if ( ! $db->tableExists( 'aioseo_blc_link_status' ) ) {
			return true;
		}

		$statusTable = $db->prefix . 'aioseo_blc_link_status';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$duplicate = $db->db->get_var( "SELECT SHA1( url ) FROM {$statusTable} GROUP BY SHA1( url ) HAVING COUNT(*) > 1 LIMIT 1" );
		// phpcs:enable

		return null === $duplicate;
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
	 * @param  int   $rivalId     The row found by the derived hash.
	 * @return array              The survivor's ID, then the loser's, or empty if one is gone.
	 */
	private function survivorOf( $candidateId, $rivalId ) {
		$db          = aioseoBrokenLinkChecker()->core->db;
		$statusTable = $db->prefix . 'aioseo_blc_link_status';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $db->db->get_results(
			$db->db->prepare(
				"SELECT id, last_scan_date FROM {$statusTable} WHERE id IN ( %d, %d )
				ORDER BY ( last_scan_date IS NOT NULL ) DESC, id ASC",
				$candidateId,
				$rivalId
			)
		);
		// phpcs:enable

		// One of them has already been merged away by an earlier candidate. Guessing which survives
		// repointed every link onto the missing row and deleted the live one.
		if ( 2 !== count( $rows ) ) {
			return [];
		}

		return [ (int) $rows[0]->id, (int) $rows[1]->id ];
	}

	/**
	 * Points every link at the surviving row, drops the other, and leaves the survivor holding the hash.
	 *
	 * @since 1.3.1
	 *
	 * @param  array  $ids  The survivor's ID, then the loser's.
	 * @param  string $hash The hash the URL derives.
	 * @return void
	 */
	private function merge( $ids, $hash ) {
		list( $survivorId, $loserId ) = $ids;

		$db          = aioseoBrokenLinkChecker()->core->db;
		$statusTable = $db->prefix . 'aioseo_blc_link_status';
		$linksTable  = $db->prefix . 'aioseo_blc_links';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$db->db->query(
			$db->db->prepare( "UPDATE {$linksTable} SET blc_link_status_id = %d WHERE blc_link_status_id = %d", $survivorId, $loserId )
		);

		// Dropped before the hash is written, because the hash is unique and the loser may be the row
		// that holds it.
		$db->db->query( $db->db->prepare( "DELETE FROM {$statusTable} WHERE id = %d", $loserId ) );

		$db->db->query(
			$db->db->prepare( "UPDATE IGNORE {$statusTable} SET url_hash = %s WHERE id = %d", $hash, $survivorId )
		);
		// phpcs:enable
	}
}