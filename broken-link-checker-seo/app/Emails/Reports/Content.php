<?php
namespace AIOSEO\BrokenLinkChecker\Emails\Reports;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Gathers the data a broken link report needs.
 *
 * @since 1.3.1
 */
class Content {
	/**
	 * The cadence, either 'weekly' or 'monthly'.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	private $type;

	/**
	 * The inclusive start of the period, as a Unix timestamp.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $periodStart;

	/**
	 * The exclusive end of the period, as a Unix timestamp.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $periodEnd;

	/**
	 * The number of links that broke during the period.
	 *
	 * @since 1.3.1
	 *
	 * @var int|null
	 */
	private $changedCount = null;

	/**
	 * The capped list of links that broke during the period.
	 *
	 * @since 1.3.1
	 *
	 * @var array|null
	 */
	private $changedLinks = null;

	/**
	 * The licence's quota figures, as stored.
	 *
	 * @since 1.3.1
	 *
	 * @var array|null
	 */
	private $quota = null;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 *
	 * @param string $type      The cadence, either 'weekly' or 'monthly'.
	 * @param int    $timestamp The moment the report is being sent for.
	 */
	public function __construct( $type, $timestamp ) {
		$this->type = 'monthly' === $type ? 'monthly' : 'weekly';

		// Every boundary comes off the slot timestamp rather than time(), so the window a report covers
		// is the same window the caller stamps as reported.
		if ( 'monthly' === $this->type ) {
			$this->periodStart = $this->getLocalTimestamp( 'first day of last month midnight', $timestamp );
			$this->periodEnd   = $this->getLocalTimestamp( 'first day of this month midnight', $timestamp );

			return;
		}

		$reportSince       = (int) aioseoBrokenLinkChecker()->internalOptions->internal->emails->reportSince;
		$this->periodStart = $reportSince ? $reportSince : $timestamp - WEEK_IN_SECONDS;
		$this->periodEnd   = $timestamp;
	}

	/**
	 * Returns the exclusive end of the period.
	 *
	 * @since 1.3.1
	 *
	 * @return int The timestamp.
	 */
	public function getPeriodEnd() {
		return $this->periodEnd;
	}

	/**
	 * Whether this is the monthly report.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether this is the monthly report.
	 */
	public function isMonthly() {
		return 'monthly' === $this->type;
	}

	/**
	 * Whether nothing broke during the period.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether nothing broke.
	 */
	public function isAllClear() {
		return 0 === $this->getChangedCount();
	}

	/**
	 * Returns how many links broke during the period.
	 *
	 * @since 1.3.1
	 *
	 * @return int The count.
	 */
	public function getChangedCount() {
		if ( null === $this->changedCount ) {
			$this->changedCount = Models\LinkStatus::countBrokenInWindow( $this->periodStart, $this->periodEnd );
		}

		return $this->changedCount;
	}

	/**
	 * Returns the links that broke during the period, capped at the example row limit.
	 *
	 * @since 1.3.1
	 *
	 * @return array The rows.
	 */
	public function getChangedLinks() {
		if ( null === $this->changedLinks ) {
			$rows = Models\LinkStatus::getBrokenInWindow(
				$this->periodStart,
				$this->periodEnd,
				aioseoBrokenLinkChecker()->emails->reports->rowCap
			);

			$this->changedLinks = [];
			foreach ( $rows as $row ) {
				$reference = Models\Link::parseObjectRef( isset( $row->object_ref ) ? $row->object_ref : '' );
				$type      = aioseoBrokenLinkChecker()->objects->get( $reference['type'] );
				$exists    = $reference['id'] && $type->exists( $reference['id'], $reference['subtype'] );

				$this->changedLinks[] = [
					'url'        => $row->url,
					'statusText' => $this->getStatusText( $row->http_status_code ),
					'postTitle'  => $exists ? $type->locationLabel( $reference['id'], $reference['subtype'] ) : '',
					'postUrl'    => $exists ? (string) $type->viewUrl( $reference['id'], $reference['subtype'] ) : '',
					'postCount'  => ! empty( $row->object_count ) ? (int) $row->object_count : 0
				];
			}
		}

		return $this->changedLinks;
	}

	/**
	 * Returns how many links broke beyond the ones the example table shows.
	 *
	 * @since 1.3.1
	 *
	 * @return int The count.
	 */
	public function getOverflowCount() {
		return max( 0, $this->getChangedCount() - count( $this->getChangedLinks() ) );
	}

	/**
	 * Returns the scorecard totals.
	 *
	 * @since 1.3.1
	 *
	 * @return array{checked: int, broken: int, redirects: int, new: int, unchecked: int, unscanned: int} The totals.
	 */
	public function getTotals() {
		$totals = array_merge(
			Models\LinkStatus::getReportTotals(),
			[ 'new' => $this->getChangedCount() ]
		);

		// What explains a broken count that looks lower than it should. Several causes land in this one
		// bucket, and they do not read the same way {@see self::getUncheckedLabel()}.
		$filterTotals        = Models\LinkStatus::getFilterTotals();
		$totals['unchecked'] = (int) $filterTotals['not_checked'];
		$totals['unscanned'] = (int) $filterTotals['not_scanned'];

		return $totals;
	}

	/**
	 * The label for the scorecard row that accounts for the links no other count includes.
	 *
	 * @since 1.3.1
	 *
	 * @param  array  $totals The scorecard totals.
	 * @return string         The label.
	 */
	public function getUncheckedLabel( $totals ) {
		if ( $this->isQuotaSpent() ) {
			return __( 'Not checked — over limit', 'broken-link-checker-seo' );
		}

		// A row the service already answered for is waiting on the local re-check that confirms it,
		// not on a first look - so only a bucket that is entirely unscanned can say "first check".
		if ( (int) $totals['unchecked'] === (int) $totals['unscanned'] ) {
			return __( 'Waiting for first check', 'broken-link-checker-seo' );
		}

		return __( 'Still being checked', 'broken-link-checker-seo' );
	}

	/**
	 * Whether the plan's links for this period are all spent.
	 *
	 * NOTE: A spent quota stops the service checking anything, which makes every count below it in the
	 * report a figure from before it ran out rather than the state of the site now.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the quota is spent.
	 */
	public function isQuotaSpent() {
		$quota = $this->getQuota();

		return 0 < $quota['total'] && 1 > $quota['remaining'];
	}

	/**
	 * Returns the plan's quota figures.
	 *
	 * @since 1.3.1
	 *
	 * @return array{total: int, remaining: int, used: int} The figures.
	 */
	public function getQuota() {
		if ( null === $this->quota ) {
			$license  = aioseoBrokenLinkChecker()->internalOptions->internal->license->all();
			$total    = isset( $license['quota'] ) ? (int) $license['quota'] : 0;
			$remaining = isset( $license['quotaRemaining'] ) ? (int) $license['quotaRemaining'] : 0;

			$this->quota = [
				'total'     => $total,
				'remaining' => $remaining,
				'used'      => max( 0, $total - $remaining )
			];
		}

		return $this->quota;
	}

	/**
	 * Returns the sentence naming when the quota comes back, where we can name it.
	 *
	 * NOTE: The service resets a paid plan's quota when the licence renews, not on the first of the
	 * month, so the renewal date is the only reset we can state truthfully. A free plan's window rolls
	 * thirty days from its own last reset, which the plugin is never told, so that gets no date.
	 *
	 * @since 1.3.1
	 *
	 * @return string The sentence, or an empty string when no date can be named.
	 */
	public function getQuotaResetText() {
		$license = aioseoBrokenLinkChecker()->internalOptions->internal->license->all();
		$level   = isset( $license['level'] ) ? strtolower( (string) $license['level'] ) : '';
		$expires = isset( $license['expires'] ) ? (int) $license['expires'] : 0;

		// A free plan's window rolls thirty days from its own last reset, which is not a renewal and not a
		// date we are given.
		if ( 'free' === $level || $expires <= time() ) {
			return __( 'Your links come back when your allowance next resets.', 'broken-link-checker-seo' );
		}

		return sprintf(
			// Translators: 1 - A date, e.g. "1 September 2026".
			__( 'Your links come back when your plan renews on %1$s.', 'broken-link-checker-seo' ),
			wp_date( get_option( 'date_format' ), $expires )
		);
	}

	/**
	 * Whether a full quota notice has already gone out for the quota this licence period holds.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether to keep the notice short.
	 */
	public function isQuotaNoticeBrief() {
		$stamp = (string) aioseoBrokenLinkChecker()->internalOptions->internal->emails->quotaNoticeStamp;

		return '' !== $stamp && $stamp === $this->getQuotaPeriodStamp();
	}

	/**
	 * Returns what identifies the quota this licence period holds.
	 *
	 * NOTE: The expiry, because that is what the service resets the quota against. A renewal moves it,
	 * which is exactly when a fresh notice is worth sending again.
	 *
	 * @since 1.3.1
	 *
	 * @return string The stamp.
	 */
	public function getQuotaPeriodStamp() {
		$license = aioseoBrokenLinkChecker()->internalOptions->internal->license->all();
		$expires = isset( $license['expires'] ) ? (int) $license['expires'] : 0;

		// A free plan has no expiry to move, and its allowance rolls roughly monthly, so the month keeps
		// the notice recurring instead of it going out once and never again.
		return 0 < $expires ? (string) $expires : wp_date( 'Y-m' );
	}

	/**
	 * Returns the email subject.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 A spent quota takes the subject over.
	 *
	 * @return string The subject.
	 */
	public function getSubject() {
		if ( $this->isMonthly() ) {
			return sprintf(
				// Translators: 1 - A month and year, e.g. "July 2026".
				__( 'Your Broken Link Checker report for %1$s', 'broken-link-checker-seo' ),
				$this->getPeriodLabel()
			);
		}

		// Checking having stopped outranks the count, and the weekly's whole claim is what changed.
		if ( $this->isQuotaSpent() ) {
			return sprintf(
				// Translators: 1 - The site domain.
				__( 'Link checking paused on %1$s — plan limit reached', 'broken-link-checker-seo' ),
				$this->getSiteDomain()
			);
		}

		// The subject has to carry the whole message — most recipients never open the email.
		return sprintf(
			// Translators: 1 - A number of links, 2 - The site domain.
			_n(
				'%1$s link broke on %2$s this week',
				'%1$s links broke on %2$s this week',
				$this->getChangedCount(),
				'broken-link-checker-seo'
			),
			number_format_i18n( $this->getChangedCount() ),
			$this->getSiteDomain()
		);
	}

	/**
	 * Returns the hidden inbox preview line.
	 *
	 * @since 1.3.1
	 *
	 * @return string The preheader.
	 */
	public function getPreHeader() {
		if ( $this->isQuotaSpent() ) {
			return $this->isMonthly()
				? __( 'Your monthly scorecard — and your plan\'s links ran out before the month did.', 'broken-link-checker-seo' )
				: __( 'Nothing on your site is being checked until your plan renews.', 'broken-link-checker-seo' );
		}

		if ( ! $this->isMonthly() ) {
			return __( 'Here are the links that stopped working since your last report.', 'broken-link-checker-seo' );
		}

		if ( $this->isAllClear() ) {
			return __( 'Not one link broke last month. Here is the full picture anyway.', 'broken-link-checker-seo' );
		}

		return __( 'Your monthly scorecard: what was checked, what broke and what redirects.', 'broken-link-checker-seo' );
	}

	/**
	 * Returns the period label, e.g. "July 2026".
	 *
	 * @since 1.3.1
	 *
	 * @return string The label.
	 */
	public function getPeriodLabel() {
		return wp_date( 'F Y', $this->periodStart );
	}

	/**
	 * Returns the site domain.
	 *
	 * @since 1.3.1
	 *
	 * @return string The domain.
	 */
	public function getSiteDomain() {
		$domain = aioseoBrokenLinkChecker()->helpers->getSiteDomain();

		return $domain ? $domain : home_url();
	}

	/**
	 * Returns the URL of the Broken Links report.
	 *
	 * @since 1.3.1
	 *
	 * @return string The URL.
	 */
	public function getReportUrl() {
		return admin_url( 'admin.php?page=broken-link-checker#/broken-links' );
	}

	/**
	 * Returns the "and X more links" line, or an empty string when nothing was truncated.
	 *
	 * @since 1.3.1
	 *
	 * @return string The line.
	 */
	public function getOverflowText() {
		$overflow = $this->getOverflowCount();
		if ( ! $overflow ) {
			return '';
		}

		return sprintf(
			// Translators: 1 - A number of links.
			_n(
				'… and %1$s more link',
				'… and %1$s more links',
				$overflow,
				'broken-link-checker-seo'
			),
			number_format_i18n( $overflow )
		);
	}

	/**
	 * Returns a human readable status for the given HTTP status code.
	 *
	 * @since 1.3.1
	 *
	 * @param  int|null $statusCode The HTTP status code.
	 * @return string               The status text.
	 */
	private function getStatusText( $statusCode ) {
		$statusCode = (int) $statusCode;

		return $statusCode
			? (string) $statusCode
			: __( 'No response', 'broken-link-checker-seo' );
	}

	/**
	 * Resolves a relative date string in the site timezone to a Unix timestamp.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $relative Any string DateTime accepts.
	 * @param  int    $from     The moment the string is relative to.
	 * @return int              The timestamp.
	 */
	private function getLocalTimestamp( $relative, $from ) {
		try {
			$date = new \DateTime( '@' . (int) $from );
			$date->setTimezone( wp_timezone() );
			$date->modify( $relative );

			return $date->getTimestamp();
		} catch ( \Exception $e ) {
			return (int) strtotime( $relative, (int) $from );
		}
	}
}