<?php
namespace AIOSEO\BrokenLinkChecker\Emails\Reports;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Emails\Emails;

/**
 * Handles the weekly and monthly broken link reports.
 *
 * @since 1.3.1
 */
class Reports {
	/**
	 * The action hook that evaluates and sends whatever report is due.
	 *
	 * NOTE: One daily action decides between the two cadences rather than one recurring action per
	 * cadence. Two actions on separate schedules have no defined order inside a queue pass, so the
	 * monthly could not reliably suppress the weekly.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	public $actionHook = 'aioseo_blc_email_report';

	/**
	 * How many rows an example table shows before it collapses into a "and X more" line.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	public $rowCap = 5;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		// Registered unconditionally so an already queued action always resolves.
		add_action( $this->actionHook, [ $this, 'maybeSendReport' ] );

		add_action( 'admin_init', [ $this, 'maybeSchedule' ], 20 );
	}

	/**
	 * Schedules or unschedules the daily evaluation.
	 * Hooked into `admin_init` action hook.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function maybeSchedule() {
		if ( ! $this->isEnabled() ) {
			aioseoBrokenLinkChecker()->actionScheduler->unschedule( $this->actionHook );

			return;
		}

		if ( aioseoBrokenLinkChecker()->actionScheduler->isScheduled( $this->actionHook ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->actionScheduler->scheduleRecurrent(
			$this->actionHook,
			$this->getSecondsUntilNextRun(),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Sends whichever report is due today, if any.
	 * Hooked into `{@see self::$actionHook}` action hook.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function maybeSendReport() {
		$this->evaluateSlot( time() );
	}

	/**
	 * Sends whichever report the given moment is due for, if any.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $timestamp The moment to evaluate.
	 * @return string            The cadence that was sent, or an empty string when nothing was.
	 */
	public function evaluateSlot( $timestamp ) {
		if ( ! $this->isEnabled() || empty( $this->getRecipients() ) ) {
			return '';
		}

		// Only the site's own anchor weekday ever sends, so the monthly and the weekly can never
		// land in the same ISO week.
		if ( (int) wp_date( 'N', $timestamp ) !== $this->getAnchorWeekday() ) {
			return '';
		}

		$state = aioseoBrokenLinkChecker()->internalOptions->internal->emails->all();

		// Driven off the stamp rather than the day of the month, so a missed anchor weekday still
		// sends the month's report on the next one instead of skipping the month entirely.
		$monthKey = wp_date( 'Y-m', $timestamp );
		if ( $monthKey !== $state['reportMonth'] ) {
			$content = new Content( 'monthly', $timestamp );

			// Stamped before sending so a failed send can't retry daily. The watermark takes the
			// monthly's own window end, not now: the days between belong to the next weekly.
			$this->stampSlot( [
				'reportMonth' => $monthKey,
				'reportWeek'  => wp_date( 'o-W', $timestamp ),
				'reportSince' => $content->getPeriodEnd()
			] );

			$this->sendMonthly( $content );

			return 'monthly';
		}

		// Nothing is rechecked between monthly scans, so a weekly "what changed" report has nothing to
		// report on three weeks out of four. The scorecard above still goes out.
		if ( aioseoBrokenLinkChecker()->helpers->isMonthlyScan() ) {
			return '';
		}

		$weekKey = wp_date( 'o-W', $timestamp );
		if ( $weekKey === $state['reportWeek'] ) {
			return '';
		}

		// The week is marked handled even when nothing broke — silence is the message. The watermark
		// only moves when a report actually goes out, so the next one still covers this period.
		$this->stampSlot( [ 'reportWeek' => $weekKey ] );

		$content = new Content( 'weekly', $timestamp );
		if ( ! $this->sendWeekly( $content ) ) {
			return '';
		}

		$this->stampSlot( [ 'reportSince' => $content->getPeriodEnd() ] );

		return 'weekly';
	}

	/**
	 * Sends the weekly "what changed" report.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Send on a spent quota even when nothing changed.
	 *
	 * @param  Content $content The report content.
	 * @return bool             Whether a report was sent.
	 */
	public function sendWeekly( $content ) {
		$recipients = $this->getRecipients();
		if ( empty( $recipients ) ) {
			return false;
		}

		// Weekly silence means nothing changed. A week where nothing changed because nothing was checked
		// is the opposite of that, and is the week most worth the email.
		if ( ! $content->getChangedCount() && ! $content->isQuotaSpent() ) {
			return false;
		}

		return $this->sendReport( $recipients, 'ReportWeekly', $content );
	}

	/**
	 * Sends the monthly scorecard report.
	 *
	 * @since 1.3.1
	 *
	 * @param  Content $content The report content.
	 * @return bool             Whether a report was sent.
	 */
	public function sendMonthly( $content ) {
		$recipients = $this->getRecipients();
		if ( empty( $recipients ) ) {
			return false;
		}

		return $this->sendReport( $recipients, 'ReportMonthly', $content );
	}

	/**
	 * Sends one report to a single address, so the setting can be proved to work.
	 *
	 * NOTE: The monthly scorecard, because it always has something to show — the weekly lists what
	 * changed and would arrive empty on a quiet week, which proves nothing. Sent through the same render
	 * and the same mailer as a real one, or a test that passes would say nothing about a report that
	 * does not.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $recipient The address to send to.
	 * @return bool              Whether it was sent.
	 */
	public function sendTest( $recipient ) {
		$recipient = sanitize_email( (string) $recipient );
		if ( ! $recipient || ! is_email( $recipient ) ) {
			return false;
		}

		// Deliberately not sendReport(): that records the out-of-quota notice as delivered, and a test
		// would then suppress the full notice on the next real report.
		return $this->send( [ $recipient ], 'ReportMonthly', new Content( 'monthly', time() ) );
	}

	/**
	 * Sends a report and records that its quota notice went out.
	 *
	 * NOTE: A quota stays spent for the rest of the licence period, so the full notice is recorded and
	 * the reports that follow carry one line instead of repeating it.
	 *
	 * @since 1.3.1
	 *
	 * @param  string[] $recipients The addresses.
	 * @param  string   $view       The view file name, without the extension.
	 * @param  Content  $content    The report content.
	 * @return bool                 Whether a report was sent.
	 */
	private function sendReport( $recipients, $view, $content ) {
		$brief = $content->isQuotaNoticeBrief();
		$sent  = $this->send( $recipients, $view, $content );

		if ( $sent && ! $brief && $content->isQuotaSpent() ) {
			$this->stampSlot( [ 'quotaNoticeStamp' => $content->getQuotaPeriodStamp() ] );
		}

		return $sent;
	}

	/**
	 * Returns the valid, deduplicated report recipients.
	 *
	 * @since 1.3.1
	 *
	 * @return string[] The addresses.
	 */
	public function getRecipients() {
		$emailReports = aioseoBrokenLinkChecker()->options->general->emailReports->all();
		$candidates   = ! empty( $emailReports['recipients'] ) ? (array) $emailReports['recipients'] : [];

		$recipients = [];
		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) || ! is_email( $candidate ) ) {
				continue;
			}

			$recipients[ strtolower( $candidate ) ] = $candidate;
		}

		return array_values( $recipients );
	}

	/**
	 * Returns the weekday reports go out on, as an ISO-8601 day number.
	 *
	 * @since 1.3.1
	 *
	 * @return int The weekday, 1 (Monday) through 7 (Sunday).
	 */
	public function getAnchorWeekday() {
		$domain = aioseoBrokenLinkChecker()->helpers->getSiteDomain( true );

		return aioseoBrokenLinkChecker()->helpers->generateRandomTimeOffset( $domain . '-weekday', 7 ) + 1;
	}

	/**
	 * Whether reports should run at all.
	 *
	 * A lapsed licence deliberately gets nothing: its links are no longer being checked, so any figure
	 * we could report would be stale. The connection reminders cover that case instead.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether reports should run.
	 */
	private function isEnabled() {
		if ( ! aioseoBrokenLinkChecker()->license->isActive() ) {
			return false;
		}

		$emailReports = aioseoBrokenLinkChecker()->options->general->emailReports->all();

		return ! empty( $emailReports['enable'] );
	}

	/**
	 * Returns the seconds until the site's own send time, so installs on one host don't all fire together.
	 *
	 * @since 1.3.1
	 *
	 * @return int The seconds.
	 */
	private function getSecondsUntilNextRun() {
		$offsetMinutes = aioseoBrokenLinkChecker()->helpers->generateRandomTimeOffset(
			aioseoBrokenLinkChecker()->helpers->getSiteDomain( true ),
			180
		);

		// 06:00 local, plus the site's own offset across the following three hours.
		$sendTime = strtotime( 'today 06:00' ) - aioseoBrokenLinkChecker()->helpers->getTimeZoneOffset();
		$sendTime += $offsetMinutes * MINUTE_IN_SECONDS;

		$seconds = $sendTime - time();

		return 0 < $seconds ? $seconds : $seconds + DAY_IN_SECONDS;
	}

	/**
	 * Records that a report slot has been handled.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $values The internal option keys to set.
	 * @return void
	 */
	private function stampSlot( $values ) {
		foreach ( $values as $key => $value ) {
			aioseoBrokenLinkChecker()->internalOptions->internal->emails->$key = $value;
		}

		// Persisted immediately so a fatal further down the send can't hand us a second attempt.
		aioseoBrokenLinkChecker()->internalOptions->save( true );
	}

	/**
	 * Renders and sends a report to every recipient.
	 *
	 * @since 1.3.1
	 *
	 * @param  string[] $recipients The addresses.
	 * @param  string   $view       The view file name, without the extension.
	 * @param  Content  $content    The report content.
	 * @return bool                 Whether a report was sent.
	 */
	private function send( $recipients, $view, $content ) {
		$sent = false;
		foreach ( $recipients as $recipientEmail ) {
			// The unsubscribe token is per recipient, so each address gets its own render.
			$message = aioseoBrokenLinkChecker()->emails->render(
				$view,
				$content->getPreHeader(),
				$recipientEmail,
				Emails::TYPE_REPORT,
				[ 'content' => $content ]
			);

			if ( ! $message ) {
				continue;
			}

			$sent = wp_mail(
				$recipientEmail,
				$content->getSubject(),
				$message,
				aioseoBrokenLinkChecker()->emails->getHeaders()
			) || $sent;
		}

		return $sent;
	}
}