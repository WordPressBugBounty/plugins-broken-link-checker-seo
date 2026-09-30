<?php
namespace AIOSEO\BrokenLinkChecker\Standalone;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the NPS survey widget.
 *
 * @since 1.3.1
 */
class NpsSurvey {
	/**
	 * The product key this plugin reports NPS under.
	 *
	 * NOTE: The worker rejects submissions from a product key it does not know, so a
	 * new value has to be registered there before it is used here.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const PRODUCT = 'blc';

	/**
	 * User meta key for tracking dismiss/submit state.
	 *
	 * NOTE: Prefixed separately from AIOSEO's key so answering one plugin's survey does
	 * not silently snooze the other's on sites running both.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const META_KEY = '_aioseo_blc_nps_survey_dismissed_until';

	/**
	 * The Action Scheduler hook that dispatches a submission to the worker.
	 *
	 * NOTE: Prefixed separately from AIOSEO's hook. A shared name would leave both
	 * plugins subscribed to it, so one submission would be sent twice.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const ACTION_DISPATCH = 'aioseo_blc_nps_survey_dispatch';

	/**
	 * The Action Scheduler hook that dispatches a review click to the worker.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const ACTION_DISPATCH_REVIEW_CLICK = 'aioseo_blc_nps_survey_review_click_dispatch';

	/**
	 * The Cloudflare worker submit endpoint.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const WORKER_URL = 'https://aioseo-nps-cron.aioseo.workers.dev/submit';

	/**
	 * The Cloudflare worker review-click endpoint.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const WORKER_REVIEW_CLICK_URL = 'https://aioseo-nps-cron.aioseo.workers.dev/review-click';

	/**
	 * Days after first activation before showing the survey.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const FIRST_SHOW_DAYS = 90;

	/**
	 * Days to snooze after dismissal.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const DISMISS_DAYS = 180;

	/**
	 * Days to snooze after submission.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const SUBMISSION_DAYS = 365;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		// Registered unconditionally so they're available during the Action Scheduler request that dispatches the payload.
		add_action( self::ACTION_DISPATCH, [ $this, 'dispatchToWorker' ] );
		add_action( self::ACTION_DISPATCH_REVIEW_CLICK, [ $this, 'dispatchReviewClickToWorker' ] );

		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
		add_action( 'admin_footer', [ $this, 'outputMountPoint' ] );
	}

	/**
	 * Sends a submission payload to the Cloudflare worker.
	 *
	 * Runs out-of-band via Action Scheduler so a slow or unreachable worker never blocks the submit response.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $payload The submission payload.
	 * @return void
	 */
	public function dispatchToWorker( $payload ) {
		$this->postToWorker( self::WORKER_URL, $payload );
	}

	/**
	 * Sends a review-click payload to the Cloudflare worker.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $payload The review-click payload.
	 * @return void
	 */
	public function dispatchReviewClickToWorker( $payload ) {
		$this->postToWorker( self::WORKER_REVIEW_CLICK_URL, $payload );
	}

	/**
	 * Posts a payload to the given worker endpoint.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url     The worker endpoint.
	 * @param  array  $payload The payload to send.
	 * @return void
	 */
	private function postToWorker( $url, $payload ) {
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return;
		}

		$response = aioseoBrokenLinkChecker()->helpers->wpRemotePost(
			$url,
			[
				'body' => wp_json_encode( $payload )
			]
		);

		// Surface failures to Action Scheduler (recorded as a failed action) rather than losing them silently.
		if ( is_wp_error( $response ) ) {
			throw new \Exception( esc_html( $response->get_error_message() ) );
		}
	}

	/**
	 * Enqueues the required assets.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function enqueueAssets() {
		if ( ! $this->shouldShow() ) {
			return;
		}

		aioseoBrokenLinkChecker()->core->assets->load( 'src/vue/standalone/nps-survey/main.js', [], aioseoBrokenLinkChecker()->helpers->getVueData() );
	}

	/**
	 * Outputs the Vue mount point in the admin footer.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function outputMountPoint() {
		if ( ! $this->shouldShow() ) {
			return;
		}

		echo '<div id="aioseo-blc-nps-survey"></div>';
	}

	/**
	 * Checks whether the survey should be shown to the current user.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the survey should be shown.
	 */
	public function shouldShow() {
		if ( ! current_user_can( 'aioseo_blc_broken_links_page' ) ) {
			return false;
		}

		if ( ! aioseoBrokenLinkChecker()->license->isActive() ) {
			return false;
		}

		if ( ! aioseoBrokenLinkChecker()->admin->isBlcScreen() ) {
			return false;
		}

		$firstActivated = (int) aioseoBrokenLinkChecker()->internalOptions->internal->firstActivated;
		if ( ! $firstActivated || $firstActivated > strtotime( '-' . self::FIRST_SHOW_DAYS . ' days' ) ) {
			return false;
		}

		$dismissedUntil = (int) get_user_meta( get_current_user_id(), self::META_KEY, true );
		if ( $dismissedUntil && $dismissedUntil > time() ) {
			return false;
		}

		return true;
	}

	/**
	 * Dismisses the survey for DISMISS_DAYS days.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function dismiss() {
		update_user_meta( get_current_user_id(), self::META_KEY, time() + ( self::DISMISS_DAYS * DAY_IN_SECONDS ) );
	}

	/**
	 * Records a submission and hides the survey for SUBMISSION_DAYS days.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function submit() {
		update_user_meta( get_current_user_id(), self::META_KEY, time() + ( self::SUBMISSION_DAYS * DAY_IN_SECONDS ) );
	}
}