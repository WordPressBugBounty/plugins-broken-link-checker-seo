<?php
namespace AIOSEO\BrokenLinkChecker\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Builds the dashboard's figures.
 *
 * NOTE: Every widget is derived from one scan of the broken placements rather than a query each. The
 * report's own counts are the exception - they are already cached and already answer that question.
 *
 * @since 1.3.1
 */
class Data {
	/**
	 * How many placements are read before the fix list stops claiming to be complete.
	 *
	 * NOTE: Ranking needs the sets themselves, not counts, so the rows have to be in memory. A site
	 * with more broken placements than this still gets a ranking - it is drawn from the oldest
	 * failures, which is the half worth acting on - and `truncated` says so rather than quietly
	 * pretending the list covers everything.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const MAX_PLACEMENTS = 5000;

	/**
	 * How many rows the fix list offers.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const FIX_ROWS = 5;

	/**
	 * The exposure weight per kind of fix.
	 *
	 * NOTE: What lifts a sitewide surface above a bigger pile on one page. A broken item in a menu is
	 * on every page of the site, so five of them outrank nine in a single post.
	 *
	 * @since 1.3.1
	 *
	 * @var array<string, float>
	 */
	private static $weights = [
		'url'      => 1.0,
		'host'     => 1.0,
		'blocked'  => 1.0,
		'sitewide' => 3.0,
		'location' => 1.0,
		'image'    => 1.5,
		'video'    => 1.5
	];

	/**
	 * How much a kind's count can be trusted to fall to a single action.
	 *
	 * @since 1.3.1
	 *
	 * @var array<string, float>
	 */
	private static $confidence = [
		'url'      => 0.95,
		'host'     => 0.9,
		'blocked'  => 0.9,
		'sitewide' => 0.9,
		'location' => 0.8,
		'image'    => 0.85,
		'video'    => 0.85
	];

	/**
	 * The object types whose links appear across the whole site rather than on one page.
	 *
	 * NOTE: The test is reach, because reach is what the weight prices. A menu, a navigation block, a
	 * template, a template part and a synced pattern are all drawn into page after page, so one fix
	 * lands everywhere at once.
	 *
	 * Terms and users are deliberately absent. A tag description shows on that tag's archive and an
	 * author bio on that author's archive - one page each, no further than a post reaches - so pricing
	 * them as though they appeared everywhere floated anything in a tag or a bio above pages carrying
	 * several times the breakage.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Dropped term and user, which reach one page each.
	 *
	 * @var string[]
	 */
	private static $sitewide = [
		'menu_item',
		'navigation',
		'template',
		'template_part',
		'reusable_block'
	];

	/**
	 * Returns everything the dashboard draws, in one payload.
	 *
	 * @since 1.3.1
	 *
	 * @return array The data.
	 */
	public static function getAll() {
		$totals = Models\LinkStatus::getFilterTotals();
		$state  = self::getState( $totals );

		// Nothing has been looked at, so there is nothing to aggregate and every widget is standing
		// down anyway. Saves the placement scan on exactly the sites least able to afford it.
		if ( 'unscanned' === $state ) {
			return [
				'state'          => $state,
				'scanInProgress' => 0 < (int) $totals['not_scanned'],
				'summary'        => self::getSummary( $totals, $state ),
				'fixList'        => [],
				'causes'         => [],
				'hosts'          => [],
				'ages'           => [],
				'places'         => [],
				'scan'           => self::getScanInfo()
			];
		}

		$placements = self::getPlacements();

		return [
			'state'          => $state,
			'scanInProgress' => 0 < (int) $totals['not_scanned'],
			'summary'        => self::getSummary( $totals, $state, $placements ),
			'fixList'        => self::getFixList( $placements ),
			'causes'         => self::getCauses( $placements ),
			'hosts'          => self::getHosts( $placements ),
			'ages'           => self::getAges( $placements ),
			'places'         => self::getPlaces( $placements ),
			'scan'           => self::getScanInfo()
		];
	}

	/**
	 * The lean payload the WP dashboard widget renders.
	 *
	 * NOTE: The widget's job is to route someone, not to inform them - so it carries the counts and the
	 * single strongest action, and nothing it would need a chart for.
	 *
	 * @since 1.3.1
	 *
	 * @return array The widget data.
	 */
	public static function getWidget() {
		$totals = Models\LinkStatus::getFilterTotals();
		$state  = self::getState( $totals );

		if ( 'unscanned' === $state ) {
			return [
				'state'          => $state,
				'scanInProgress' => 0 < (int) $totals['not_scanned'],
				'summary'        => self::getSummary( $totals, $state ),
				'top'            => null,
				'scan'           => self::getScanInfo()
			];
		}

		// Reuses the same cached read the dashboard page does, so opening both costs one scan.
		$placements = self::getPlacements();
		$fixList    = 'issues' === $state ? self::getFixList( $placements ) : [];

		return [
			'state'          => $state,
			'scanInProgress' => 0 < (int) $totals['not_scanned'],
			'summary'        => self::getSummary( $totals, $state, $placements ),
			'top'            => empty( $fixList ) ? null : $fixList[0],
			'scan'           => self::getScanInfo()
		];
	}

	/**
	 * Which of the three shapes the dashboard is in.
	 *
	 * NOTE: "Nothing checked yet" is not "nothing broken". A fresh install and a spotless site both
	 * report zero broken links, and telling the first one their links are fine is a lie - so the
	 * check is whether anything has been scanned at all, never whether the broken count is zero.
	 *
	 * NOTE: Reads not_scanned rather than the not-checked tab's count. A link waiting on a local retry
	 * sits in that tab but has been checked, and counting it as unchecked held the page on "still
	 * checking" while the progress figure beside it read as finished.
	 *
	 * @since 1.3.1
	 *
	 * @param  array  $totals The report's counts.
	 * @return string         One of `unscanned`, `scanning`, `clear` or `issues`.
	 */
	private static function getState( $totals ) {
		$checked = (int) $totals['all'] - (int) $totals['not_scanned'];

		if ( 1 > $checked ) {
			return 'unscanned';
		}

		// Held back only until the ranking means something. Waiting for the last link put the page
		// behind a long scan, and the order of the fix list stops moving well before the end of one.
		if ( 0 < (int) $totals['not_scanned'] && ! self::hasEnoughToRank( $totals ) ) {
			return 'scanning';
		}

		// broken_any, not the tab's count: a link whose breakage is still being confirmed is not a
		// reason to tell somebody their links are fine.
		return 0 < (int) $totals['broken_any'] ? 'issues' : 'clear';
	}

	/**
	 * Whether enough of the site has been checked for the ranking to be worth showing.
	 *
	 * NOTE: A count or a share, whichever arrives first. Neither works alone: a share leaves a large
	 * site behind its own scan for hours, and a count leaves a small site waiting for links it does
	 * not have.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $totals The report's counts.
	 * @return bool          Whether to rank what has been checked so far.
	 */
	private static function hasEnoughToRank( $totals ) {
		$monitored = (int) $totals['all'];
		$checked   = $monitored - (int) $totals['not_scanned'];

		/**
		 * Filters how many links have to be checked before the dashboard ranks them.
		 *
		 * @since 1.3.1
		 *
		 * @param int $checked The number of links.
		 */
		$minChecked = (int) apply_filters( 'aioseo_blc_dashboard_min_checked', 500 );

		/**
		 * Filters what share of the site has to be checked before the dashboard ranks it.
		 *
		 * @since 1.3.1
		 *
		 * @param int $percent The percentage.
		 */
		$minPercent = (int) apply_filters( 'aioseo_blc_dashboard_min_percent', 60 );

		if ( 0 < $minChecked && $checked >= $minChecked ) {
			return true;
		}

		if ( 1 > $monitored ) {
			return false;
		}

		return 0 < $minPercent && ( $checked / $monitored ) * 100 >= $minPercent;
	}

	/**
	 * The figures along the top.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Reports how many verdicts are still waiting on the local re-check.
	 *
	 * @param  array      $totals     The report's counts.
	 * @param  string     $state      The dashboard's state.
	 * @param  array|null $placements The broken placements, where they have been read.
	 * @return array                  The summary.
	 */
	private static function getSummary( $totals, $state, $placements = null ) {
		$monitored = (int) $totals['all'];
		$checked   = $monitored - (int) $totals['not_scanned'];

		return [
			// Both units, because they answer different questions and one alone misleads. The rows of
			// the fix list count placements, so a reader adding them up needs this number to compare
			// against - see the `places` figure rather than `broken`.
			'brokenUrls' => (int) $totals['broken'],
			'places'     => null === $placements ? 0 : count( $placements ),
			'monitored'  => $monitored,
			'checked'    => $checked,
			// What explains a Broken count that reads lower than the day before: an inherited verdict
			// is not counted until the local re-check confirms it, which takes a while after an upgrade.
			'pending'    => (int) $totals['not_checked'],
			'redirects'  => (int) $totals['redirects'],
			'dismissed'  => (int) $totals['dismissed'],
			'external'   => null === $placements ? 0 : self::countExternal( $placements ),
			'unscanned'  => 'unscanned' === $state
		];
	}

	/**
	 * How many of the broken URLs point off-site.
	 *
	 * NOTE: Counted over every placement, not over the hosts the dashboard lists - that list is a top
	 * five, so summing it would describe a fraction as if it were the whole.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placements The placements.
	 * @return int              The count.
	 */
	private static function countExternal( $placements ) {
		$ids = [];
		foreach ( $placements as $placement ) {
			if ( $placement['external'] ) {
				$ids[ $placement['statusId'] ] = true;
			}
		}

		return count( $ids );
	}

	/**
	 * When the last scan finished, and how far a running one has got.
	 *
	 * @since 1.3.1
	 *
	 * @return array The scan info.
	 */
	private static function getScanInfo() {
		$sql = (string) Models\LinkStatus::reportQuery()
			->select( 'MAX(als.last_scan_date) as last_scan' )
			->query();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row      = aioseoBrokenLinkChecker()->core->db->db->get_row( $sql );
		$lastScan = $row && ! empty( $row->last_scan ) ? self::toTimestamp( $row->last_scan ) : null;

		return [
			'lastScan'    => $lastScan,
			// Formatted here so the figure carries the site's own wording for the interval.
			'lastScanAgo' => $lastScan ? human_time_diff( $lastScan ) : ''
		];
	}

	/**
	 * Reads the broken placements the reader is allowed to see.
	 *
	 * @since 1.3.1
	 *
	 * @return array The placements.
	 */
	private static function getPlacements() {
		$query = Models\LinkStatus::reportQuery()
			->whereRaw( 'als.last_scan_date IS NOT NULL AND als.needs_additional_scan = 0 AND als.broken = 1' )
			->select(
				'al.id as placement_id, als.id as status_id, als.url, als.http_status_code,'
				. ' als.first_failure, als.log, al.hostname, al.external, al.object_type,'
				. ' al.object_id, al.object_subtype, al.is_image, al.is_video'
			)
			// Oldest failures first, so a site over the cap keeps the half that has been wrong longest.
			->orderBy( 'als.first_failure ASC, als.id ASC' )
			->limit( self::MAX_PLACEMENTS );

		// The SQL has to be taken before the cache lookup, which is itself a query and resets the
		// shared builder. Same ordering as the report's own cached counts, for the same reason.
		$sql = (string) $query->query();

		$cacheKey = Models\LinkStatus::COUNTS_CACHE_PREFIX . 'dash_' . md5( $sql );
		$cached   = aioseoBrokenLinkChecker()->core->cache->get( $cacheKey );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = aioseoBrokenLinkChecker()->core->db->db->get_results( $sql );
		$rows = is_array( $rows ) ? $rows : [];

		$placements = [];
		foreach ( $rows as $row ) {
			$placements[] = [
				'id'         => (int) $row->placement_id,
				'statusId'   => (int) $row->status_id,
				'url'        => (string) $row->url,
				'statusCode' => empty( $row->http_status_code ) ? 0 : (int) $row->http_status_code,
				'reason'     => Models\LinkStatus::reasonFromLog( isset( $row->log ) ? $row->log : '' ),
				'failedAt'   => self::toTimestamp( isset( $row->first_failure ) ? $row->first_failure : '' ),
				'hostname'   => (string) $row->hostname,
				'external'   => ! empty( $row->external ),
				'objectType' => (string) $row->object_type,
				'objectId'   => (int) $row->object_id,
				'subtype'    => (string) $row->object_subtype,
				'isMedia'    => ! empty( $row->is_image ) || ! empty( $row->is_video ),
				'isImage'    => ! empty( $row->is_image )
			];
		}

		aioseoBrokenLinkChecker()->core->cache->update( $cacheKey, $placements, 15 * MINUTE_IN_SECONDS );

		return $placements;
	}

	/**
	 * The fewest actions that clear the most broken links.
	 *
	 * NOTE: A greedy set cover. Every candidate is scored, the best is taken, the placements it clears
	 * leave the pool and the rest are scored again - which is what stops one link being counted under
	 * its host and its page and its menu all at once.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placements The broken placements.
	 * @return array             The ranked rows.
	 */
	private static function getFixList( $placements ) {
		if ( empty( $placements ) ) {
			return [];
		}

		$remaining = $placements;
		$rows      = [];

		for ( $i = 0; $i < self::FIX_ROWS; $i++ ) {
			$best = self::bestCandidate( $remaining );
			if ( null === $best ) {
				break;
			}

			$rows[] = $best['row'];

			$taken     = $best['keys'];
			$remaining = array_values( array_filter(
				$remaining,
				function ( $placement ) use ( $taken ) {
					return ! isset( $taken[ self::placementKey( $placement ) ] );
				}
			) );

			if ( empty( $remaining ) ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Scores every candidate over what is left and returns the strongest.
	 *
	 * @since 1.3.1
	 *
	 * @param  array      $placements What is still unassigned.
	 * @return array|null             The winner and the placements it covers, or null when nothing clusters.
	 */
	private static function bestCandidate( $placements ) {
		$candidates = array_merge(
			self::urlCandidates( $placements ),
			self::hostCandidates( $placements ),
			self::locationCandidates( $placements ),
			self::mediaCandidates( $placements )
		);

		$best = null;
		foreach ( $candidates as $candidate ) {
			$count = count( $candidate['placements'] );

			// A row offering to clear one link is not triage - it is the report with extra steps.
			if ( 2 > $count && 'sitewide' !== $candidate['kind'] ) {
				continue;
			}

			$weight = isset( self::$weights[ $candidate['kind'] ] ) ? self::$weights[ $candidate['kind'] ] : 1.0;
			$conf   = isset( self::$confidence[ $candidate['kind'] ] ) ? self::$confidence[ $candidate['kind'] ] : 0.8;
			$score  = $count * $weight * $conf;

			if ( null === $best || $score > $best['score'] ) {
				$keys = [];
				foreach ( $candidate['placements'] as $placement ) {
					$keys[ self::placementKey( $placement ) ] = true;
				}

				$best = [
					'score' => $score,
					'keys'  => $keys,
					'row'   => self::describe( $candidate, $count )
				];
			}
		}

		return $best;
	}

	/**
	 * One dead URL sitting in several places.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placements The placements.
	 * @return array             The candidates.
	 */
	private static function urlCandidates( $placements ) {
		$byUrl = [];
		foreach ( $placements as $placement ) {
			$byUrl[ $placement['url'] ][] = $placement;
		}

		$candidates = [];
		foreach ( $byUrl as $url => $group ) {
			$candidates[] = [
				'kind'       => 'url',
				'key'        => (string) $url,
				'placements' => $group
			];
		}

		return $candidates;
	}

	/**
	 * A whole host that is failing, split by whether it is refusing us or genuinely gone.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placements The placements.
	 * @return array             The candidates.
	 */
	private static function hostCandidates( $placements ) {
		$byHost = [];
		foreach ( $placements as $placement ) {
			if ( ! $placement['external'] || '' === $placement['hostname'] ) {
				continue;
			}

			$byHost[ $placement['hostname'] ][] = $placement;
		}

		$candidates = [];
		foreach ( $byHost as $host => $group ) {
			// A host answering us with a refusal on every URL is not a host with dead pages - the
			// links very likely work in a browser. Offered as something to dismiss, not to fix.
			$candidates[] = [
				'kind'       => self::allBlocked( $group ) ? 'blocked' : 'host',
				'key'        => (string) $host,
				'placements' => $group
			];
		}

		return $candidates;
	}

	/**
	 * A single page, menu or template carrying several.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placements The placements.
	 * @return array             The candidates.
	 */
	private static function locationCandidates( $placements ) {
		$byObject = [];
		foreach ( $placements as $placement ) {
			// The same per-object check the report's rows get. The type-level scope has already run,
			// but that does not know whether this reader may edit this particular post.
			$objectType = aioseoBrokenLinkChecker()->objects->get( $placement['objectType'] );
			if ( ! $objectType->exists( $placement['objectId'], $placement['subtype'] ) ) {
				continue;
			}

			if ( ! $objectType->canEdit( $placement['objectId'], $placement['subtype'] ) ) {
				continue;
			}

			$sitewide = in_array( $placement['objectType'], self::$sitewide, true );

			// A menu's broken items are one job, not one job each, and the links table does not record
			// which menu an item belongs to - so the type is the coarsest honest grouping available.
			$key = $sitewide
				? $placement['objectType']
				: $placement['objectType'] . ':' . $placement['objectId'] . ':' . $placement['subtype'];

			if ( ! isset( $byObject[ $key ] ) ) {
				$byObject[ $key ] = [
					'kind'       => $sitewide ? 'sitewide' : 'location',
					'key'        => (string) $key,
					'placements' => []
				];
			}

			$byObject[ $key ]['placements'][] = $placement;
		}

		return array_values( $byObject );
	}

	/**
	 * Broken images and videos, which are visibly wrong rather than wrong on click.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placements The placements.
	 * @return array             The candidates.
	 */
	private static function mediaCandidates( $placements ) {
		// Split rather than lumped together: replacing missing images and replacing dead videos are two
		// jobs, and each lands on a filter the report already has.
		$images = [];
		$videos = [];

		foreach ( $placements as $placement ) {
			if ( ! $placement['isMedia'] ) {
				continue;
			}

			if ( $placement['isImage'] ) {
				$images[] = $placement;

				continue;
			}

			$videos[] = $placement;
		}

		$candidates = [];

		if ( ! empty( $images ) ) {
			$candidates[] = [
				'kind'       => 'image',
				'key'        => 'image',
				'placements' => $images
			];
		}

		if ( ! empty( $videos ) ) {
			$candidates[] = [
				'kind'       => 'video',
				'key'        => 'video',
				'placements' => $videos
			];
		}

		return $candidates;
	}

	/**
	 * Whether every placement on a host came back as a refusal rather than a missing page.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $group The host's placements.
	 * @return bool         Whether all of them were refused.
	 */
	private static function allBlocked( $group ) {
		foreach ( $group as $placement ) {
			if ( ! in_array( $placement['statusCode'], [ 401, 403, 429, 451 ], true ) ) {
				return false;
			}
		}

		return ! empty( $group );
	}

	/**
	 * Turns a winning candidate into the row the dashboard renders.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $candidate The candidate.
	 * @param  int   $count     How many placements it clears.
	 * @return array            The row.
	 */
	private static function describe( $candidate, $count ) {
		$first     = $candidate['placements'][0];
		$statusIds = [];
		foreach ( $candidate['placements'] as $placement ) {
			$statusIds[ $placement['statusId'] ] = true;
		}

		// Every row declares how the report should be narrowed when it is followed, so none of them can
		// land on an unfiltered table with nothing selected.
		$row = [
			'kind'        => $candidate['kind'],
			'count'       => $count,
			'urlCount'    => count( $statusIds ),
			'statusIds'   => array_values( array_map( 'intval', array_keys( $statusIds ) ) ),
			'search'      => '',
			'editUrl'     => '',
			'objectType'  => '',
			'mediaFilter' => '',
			'label'       => '',
			'detail'      => ''
		];

		switch ( $candidate['kind'] ) {
			case 'url':
				$row['label']  = $first['url'];
				$row['search'] = $first['url'];
				$row['detail'] = sprintf(
					// Translators: 1 - A number of locations a link appears in.
					_n(
						'One dead address, linked from %1$s location.',
						'One dead address, linked from %1$s locations. Fix it once.',
						$count,
						'broken-link-checker-seo'
					),
					number_format_i18n( $count )
				);
				break;

			case 'host':
				$row['label']  = $candidate['key'];
				$row['search'] = $candidate['key'];
				$row['detail'] = sprintf(
					// Translators: 1 - A number of URLs.
					_n(
						'%1$s URL failing on this host.',
						'%1$s URLs failing on this host.',
						$row['urlCount'],
						'broken-link-checker-seo'
					),
					number_format_i18n( $row['urlCount'] )
				);
				break;

			case 'blocked':
				$row['label']  = $candidate['key'];
				$row['search'] = $candidate['key'];
				$row['detail'] = sprintf(
					// Translators: 1 - A number of URLs.
					_n(
						'%1$s URL here was refused rather than missing, so it most likely works. Safe to dismiss.',
						'All %1$s URLs here were refused rather than missing, so they most likely work. Safe to dismiss.',
						$row['urlCount'],
						'broken-link-checker-seo'
					),
					number_format_i18n( $row['urlCount'] )
				);
				break;

			case 'sitewide':
			case 'location':
				$objectType    = aioseoBrokenLinkChecker()->objects->get( $first['objectType'] );
				$row['source'] = $objectType->sourceLabel();

				$objects = [];
				foreach ( $candidate['placements'] as $placement ) {
					$objects[ $placement['objectId'] . ':' . $placement['subtype'] ] = true;
				}

				// One object can be named and linked to directly; several of them can only be named by
				// what they are, and the report is where the individual edit links live.
				if ( 1 === count( $objects ) ) {
					$row['label']   = $objectType->locationLabel( $first['objectId'], $first['subtype'] );
					$row['editUrl'] = (string) $objectType->editUrl( $first['objectId'], $first['subtype'] );
				} else {
					$row['label']      = $objectType->sourceLabel();
					$row['objectType'] = $first['objectType'];
				}

				$row['detail'] = 'sitewide' === $candidate['kind']
					? sprintf(
						// Translators: 1 - A number of broken links.
						_n(
							'%1$s broken link here, and it appears across the whole site.',
							'%1$s broken links here, and they appear across the whole site.',
							$count,
							'broken-link-checker-seo'
						),
						number_format_i18n( $count )
					)
					: sprintf(
						// Translators: 1 - A number of broken links.
						_n(
							'%1$s broken link in one place.',
							'%1$s broken links in one place - a single edit clears them.',
							$count,
							'broken-link-checker-seo'
						),
						number_format_i18n( $count )
					);
				break;

			case 'image':
				$row['label'] = sprintf(
					// Translators: 1 - A number of broken images.
					_n( '%1$s broken image', '%1$s broken images', $count, 'broken-link-checker-seo' ),
					number_format_i18n( $count )
				);
				$row['mediaFilter'] = 'images';
				$row['detail']      = __(
					'These render as broken on the page itself, so every visitor can see them.',
					'broken-link-checker-seo'
				);
				break;

			case 'video':
				$row['label'] = sprintf(
					// Translators: 1 - A number of broken videos.
					_n( '%1$s broken video', '%1$s broken videos', $count, 'broken-link-checker-seo' ),
					number_format_i18n( $count )
				);
				$row['mediaFilter'] = 'videos';
				$row['detail']      = __(
					'These fail to play where they are embedded, so every visitor sees the gap.',
					'broken-link-checker-seo'
				);
				break;
		}

		return $row;
	}

	/**
	 * Why the broken links are broken, as causes rather than status codes.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placements The placements.
	 * @return array             The causes.
	 */
	private static function getCauses( $placements ) {
		$buckets = [
			'notFound'    => 0,
			'noResponse'  => 0,
			'blocked'     => 0,
			'serverError' => 0
		];

		$seen = [];
		foreach ( $placements as $placement ) {
			// Counted per URL, so one dead address in thirty places is one cause, not thirty.
			if ( isset( $seen[ $placement['statusId'] ] ) ) {
				continue;
			}

			$seen[ $placement['statusId'] ] = true;

			$buckets[ self::causeOf( $placement ) ]++;
		}

		return $buckets;
	}

	/**
	 * Which cause a placement falls under.
	 *
	 * @since 1.3.1
	 *
	 * @param  array  $placement The placement.
	 * @return string            The cause.
	 */
	private static function causeOf( $placement ) {
		$code = (int) $placement['statusCode'];

		if ( in_array( $code, [ 404, 410 ], true ) ) {
			return 'notFound';
		}

		if ( in_array( $code, [ 401, 403, 429, 451 ], true ) ) {
			return 'blocked';
		}

		if ( 500 <= $code ) {
			return 'serverError';
		}

		// No code at all means the request never completed - a timeout, or a name that would not resolve.
		return 'noResponse';
	}

	/**
	 * The hosts carrying the most broken links.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placements The placements.
	 * @return array             The hosts.
	 */
	private static function getHosts( $placements ) {
		$hosts    = [];
		$internal = [];

		foreach ( $placements as $placement ) {
			if ( ! $placement['external'] ) {
				$internal[ $placement['statusId'] ] = true;

				continue;
			}

			if ( '' === $placement['hostname'] ) {
				continue;
			}

			if ( ! isset( $hosts[ $placement['hostname'] ] ) ) {
				$hosts[ $placement['hostname'] ] = [];
			}

			$hosts[ $placement['hostname'] ][ $placement['statusId'] ] = true;
		}

		$rows = [];
		foreach ( $hosts as $host => $ids ) {
			$rows[] = [
				'host'     => (string) $host,
				'count'    => count( $ids ),
				'internal' => false,
				// The term the report is searched for when the row is followed. Given here rather than
				// derived in the browser, because the site's own row is a label and not a hostname.
				'search'   => (string) $host
			];
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		$rows = array_slice( $rows, 0, 5 );

		// Kept as a row rather than its own widget - it is one number, and it belongs next to the
		// hosts it is being compared with.
		if ( ! empty( $internal ) ) {
			$rows[] = [
				'host'     => __( 'Your own site', 'broken-link-checker-seo' ),
				'count'    => count( $internal ),
				'internal' => true,
				'search'   => (string) wp_parse_url( home_url(), PHP_URL_HOST )
			];
		}

		return $rows;
	}

	/**
	 * How long the broken links have been broken.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placements The placements.
	 * @return array             The cohorts.
	 */
	private static function getAges( $placements ) {
		$now  = time();
		$week = WEEK_IN_SECONDS;

		$cohorts = [
			'week'    => 0,
			'month'   => 0,
			'quarter' => 0,
			'older'   => 0,
			'unknown' => 0
		];

		$seen = [];
		foreach ( $placements as $placement ) {
			if ( isset( $seen[ $placement['statusId'] ] ) ) {
				continue;
			}

			$seen[ $placement['statusId'] ] = true;

			$failedAt = $placement['failedAt'];

			// Links that broke before the column existed have no date to place them by. Shown as its
			// own bucket rather than folded into the oldest, which would invent a fact.
			if ( ! $failedAt ) {
				$cohorts['unknown']++;

				continue;
			}

			$age = $now - $failedAt;

			if ( $age < $week ) {
				$cohorts['week']++;
			} elseif ( $age < ( 4 * $week ) ) {
				$cohorts['month']++;
			} elseif ( $age < ( 13 * $week ) ) {
				$cohorts['quarter']++;
			} else {
				$cohorts['older']++;
			}
		}

		return $cohorts;
	}

	/**
	 * Where the broken links live.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placements The placements.
	 * @return array             The sources.
	 */
	private static function getPlaces( $placements ) {
		$groups = [];
		foreach ( $placements as $placement ) {
			$slug = '' === $placement['objectType'] ? 'post' : $placement['objectType'];

			if ( ! isset( $groups[ $slug ] ) ) {
				$groups[ $slug ] = [];
			}

			$groups[ $slug ][ $placement['statusId'] ] = true;
		}

		$rows = [];
		foreach ( $groups as $slug => $ids ) {
			$rows[] = [
				'slug'  => (string) $slug,
				'label' => aioseoBrokenLinkChecker()->objects->get( $slug )->sourceLabel(),
				'count' => count( $ids )
			];
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return array_slice( $rows, 0, 6 );
	}

	/**
	 * A placement's own identity.
	 *
	 * NOTE: The row's id, not its URL and location - the same dead address can sit in one post twice,
	 * and treating those as one would undercount what an edit clears.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $placement The placement.
	 * @return int              The key.
	 */
	private static function placementKey( $placement ) {
		return (int) $placement['id'];
	}

	/**
	 * A MySQL date as a timestamp, treating the zero date as absent.
	 *
	 * @since 1.3.1
	 *
	 * @param  string   $date The date.
	 * @return int|null       The timestamp, or null.
	 */
	private static function toTimestamp( $date ) {
		$date = (string) $date;

		if ( '' === $date || '0000-00-00 00:00:00' === $date ) {
			return null;
		}

		$timestamp = strtotime( $date );

		return $timestamp ? (int) $timestamp : null;
	}
}