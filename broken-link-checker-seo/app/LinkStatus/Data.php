<?php
namespace AIOSEO\BrokenLinkChecker\LinkStatus;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Core\Database;
use AIOSEO\BrokenLinkChecker\Links\Url;
use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles fetching of data required for Link Status scan requests.
 *
 * @since 1.0.0
 */
class Data {
	/**
	 * Returns the base data we need to include in our requests to the server.
	 *
	 * @since 1.0.0
	 *
	 * @return array The base data.
	 */
	public function getBaseData() {
		return [
			'domain'          => aioseoBrokenLinkChecker()->helpers->getSiteDomain(),
			'internalOptions' => aioseoBrokenLinkChecker()->internalOptions->all(),
			// What the report lists rather than every row ever stored: a link in a disabled source, a
			// dismissed one, or one the scan rules leave out is not being monitored and should not count.
			'indexedLinks'    => (int) Models\LinkStatus::rowCountQuery( 'all' ),
			// The same total broken down by what holds the links, keyed by object type. Every registered
			// type is present at zero, so a key going missing means the type is gone, not that it is empty.
			'linksBySource'   => Models\Link::getObjectTypeTotals(),
			'isSsl'           => is_ssl(),
			'options'         => aioseoBrokenLinkChecker()->options->all(),
			'version'         => AIOSEO_BROKEN_LINK_CHECKER_VERSION
		];
	}

	/**
	 * The number of links we send off per scan.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $linksPerScan = 200;

	/**
	 * Returns links that still need to be checked.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 The recheck window follows the scan frequency setting.
	 *
	 * @param  bool      $countOnly          Whether to return the count instead of all the rows.
	 * @param  bool      $ignoreStaleResults Whether to ignore stale results.
	 * @return array|int                     The links to check the status for.
	 */
	public function getLinksToCheck( $countOnly = false, $ignoreStaleResults = false ) {
		static $linksToScan = null;

		// The queue is scoped by the object columns, so there is no queue to speak of until they exist.
		if ( ! aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
			return $countOnly ? 0 : [];
		}

		// Checked before the memoised rows: they are not a count, and returning them as one is how
		// getScanPercentage() ended up comparing a row count of 1 against its threshold and reporting 100.
		if ( $countOnly ) {
			return $this->countLinksToCheck( $ignoreStaleResults );
		}

		if ( null !== $linksToScan ) {
			return $linksToScan;
		}

		$linksToScan = $this->fetchLinksToCheck( $ignoreStaleResults );

		foreach ( $linksToScan as $link ) {
			$link->isFirstScan = empty( $link->last_scan_date );
		}

		return $linksToScan;
	}

	/**
	 * Returns the next batch of links to check, each as the address it is requested at.
	 *
	 * NOTE: The batch is a batch of addresses, not of rows. A credit is metered per unique link, so two
	 * rows differing only in something the address does not carry are one link to check, one slot of the
	 * batch, and one credit - and the single result settles both of them
	 * {@see \AIOSEO\BrokenLinkChecker\Models\LinkStatus::getSiblings()}.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Sends the address each link is requested at, one row per address.
	 *
	 * @param  bool  $ignoreStaleResults Whether to ignore stale results.
	 * @return array                     The links to check.
	 */
	private function fetchLinksToCheck( $ignoreStaleResults ) {
		$rows = $this->linksToCheckQuery( $ignoreStaleResults )
			->select( $this->linksToCheckSelect() )
			->limit( $this->linksPerScan )
			->run()
			->result();

		if ( empty( $rows ) ) {
			return [];
		}

		// Corrected before it is sent, and written back where it had not been worked out yet, so an
		// install converges as its queue drains instead of through a migration over the whole table.
		$checkUrls = Models\LinkStatus::convergeCheckUrls( $rows );

		$links = [];
		foreach ( $rows as $row ) {
			$id  = (int) $row->id;
			$url = isset( $checkUrls[ $id ] ) ? $checkUrls[ $id ] : (string) $row->url;

			// The last word on what goes out, because the grouping reads the column this pass is still
			// filling in - so it cannot see two rows arriving at one address on the cycle that converges them.
			if ( isset( $links[ $url ] ) ) {
				continue;
			}

			$links[ $url ] = (object) [
				'id'             => $id,
				'url'            => $url,
				'last_scan_date' => $row->last_scan_date,
				// Sent for the metering rather than for the fetch: a host that can never resolve has
				// already been reported broken here, and the service is told to charge without fetching.
				'skipFetch'      => Url::hasUnresolvableHost( $url )
			];
		}

		return array_values( $links );
	}

	/**
	 * The columns a batch of links to check is read from.
	 *
	 * @since 1.3.1
	 *
	 * @return string The select clause.
	 */
	private function linksToCheckSelect() {
		// Aggregated because the rows are grouped by address: a group holds one row on every install that
		// has converged, and the representative is all the sender needs off the ones that have not.
		$select = 'MIN(als.id) as id, MIN(als.url) as url, MIN(als.last_scan_date) as last_scan_date';

		return Models\LinkStatus::hasCheckUrlColumns() ? $select . ', MIN(als.check_url) as check_url' : $select;
	}

	/**
	 * Returns the query for the links that are due a check.
	 *
	 * @since 1.3.1
	 *
	 * @param  bool     $ignoreStaleResults Whether to ignore stale results.
	 * @param  bool     $paged              Whether the query returns a page of rows; a count needs
	 *                                      neither the grouping nor the order, and ONLY_FULL_GROUP_BY
	 *                                      rejects ordering an aggregate by a column it doesn't select.
	 * @return Database                     The query.
	 */
	private function linksToCheckQuery( $ignoreStaleResults, $paged = true ) {
		$time = esc_sql( aioseoBrokenLinkChecker()->helpers->timeToMysql( time() - aioseoBrokenLinkChecker()->helpers->getScanInterval() ) );

		$query = Models\Link::applyObjectScope(
			aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status as als' )
				->join( 'aioseo_blc_links as al', 'al.blc_link_status_id = als.id' )
		)->where( 'als.dismissed', 0 );

		if ( $paged ) {
			// The ID breaks ties so that paging over the batches can't skip or repeat a row. Ordered by
			// the aggregates rather than the columns, because the grouping below is not on a key.
			$query->orderBy( 'last_scan_date ASC, id ASC' )
				->groupBy( $this->wireKeyExpression() );
		}

		if ( $ignoreStaleResults ) {
			$query->where( 'als.last_scan_date', null );
		} else {
			$query->whereRaw( "(
				als.last_scan_date IS NULL
				OR als.last_scan_date < '$time'
			)" );
		}

		return $query;
	}

	/**
	 * The expression that identifies the address a row is requested at.
	 *
	 * NOTE: Falls back to the row's own hash rather than grouping on a NULL. Every row that has not
	 * converged yet carries NULL, and NULLs are one group - which would collapse a whole queue into a
	 * single link to check.
	 *
	 * @since 1.3.1
	 *
	 * @return string The expression.
	 */
	private function wireKeyExpression() {
		return Models\LinkStatus::hasCheckUrlColumns()
			? 'COALESCE( als.check_url_hash, als.url_hash )'
			: 'als.url_hash';
	}

	/**
	 * Returns how many links are due a check.
	 *
	 * NOTE: Stays a single query, so links a regex pattern excludes are counted until a batch has
	 * walked them and stamped them. The figure only drives the progress bubble.
	 *
	 * Counted with an aggregate rather than by grouping: Database::count() falls back to the row
	 * count when the query groups, which builds an object per link only to be told how many there are.
	 *
	 * @since 1.3.1
	 *
	 * @param  bool $ignoreStaleResults Whether to ignore stale results.
	 * @return int                      The number of links.
	 */
	private function countLinksToCheck( $ignoreStaleResults ) {
		$rows = $this->linksToCheckQuery( $ignoreStaleResults, false )
			->select( 'COUNT(DISTINCT als.id) as count' )
			->run()
			->result();

		return empty( $rows ) ? 0 : (int) $rows[0]->count;
	}

	/**
	 * Returns the total number of indexed links.
	 *
	 * @since   1.1.0
	 * @version 1.3.1 Scoped to the sources the report covers, like every other figure on screen.
	 *
	 * @return int The total number of indexed links.
	 */
	public function getTotalLinks() {
		if ( ! aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
			return 0;
		}

		// The scope brings the excluded domains with it, along with the disabled sources and the post
		// rules this count used to ignore — it was the one read that disagreed with the report.
		$query = Models\Link::applyObjectScope(
			aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status as als' )
				->join( 'aioseo_blc_links as al', 'al.blc_link_status_id = als.id' )
		)
			->select( 'als.id' )
			->where( 'als.dismissed', 0 )
			->groupBy( 'als.id' );

		return $query->count();
	}

	/**
	 * Returns the scan percentage.
	 *
	 * @since 1.1.0
	 *
	 * @return int The scan percentage.
	 */
	public function getScanPercentage() {
		return $this->getScanProgress()['percent'];
	}

	/**
	 * Returns how far the link check has got, as counts as well as a percentage.
	 *
	 * @since 1.3.1
	 *
	 * @return array{done: int, total: int, percent: int} How far it has got.
	 */
	public function getScanProgress() {
		$linksToCheck = $this->getLinksToCheck( true, true );
		$totalLinks   = $this->getTotalLinks();

		// Counted separately because these two measure different stages. Everything above is about links
		// already found; a post still waiting to be read for links has contributed none, so it cannot
		// lower the figure. Reporting 100 while discovery is still running is what made posts missing from
		// the report look like a finished scan.
		$postsToIndex = (int) aioseoBrokenLinkChecker()->main->links->data->getPostsToScan( true );

		if ( 0 === $postsToIndex && ( 0 === $linksToCheck || 0 === $totalLinks ) ) {
			return [
				'done'    => $totalLinks,
				'total'   => $totalLinks,
				'percent' => 100
			];
		}

		$percent = 0 < $totalLinks
			? ceil( 100 - ( ( $linksToCheck / $totalLinks ) * 100 ) )
			: 0;

		return [
			'done'    => max( 0, $totalLinks - $linksToCheck ),
			'total'   => $totalLinks,
			// Held below complete while any post has still to be read, however few links are outstanding.
			'percent' => 0 < $postsToIndex ? min( 99, max( 0, $percent ) ) : $percent
		];
	}
}