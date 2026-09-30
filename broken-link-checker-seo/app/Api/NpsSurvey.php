<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Standalone;

/**
 * Route class for the NPS survey API.
 *
 * @since 1.3.1
 */
class NpsSurvey {
	/**
	 * Dismisses the NPS survey for the current user.
	 *
	 * @since 1.3.1
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function dismiss() {
		( new Standalone\NpsSurvey() )->dismiss();

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Submits NPS survey feedback for the current user.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response         The response.
	 */
	public static function submit( $request ) {
		$body  = $request->get_json_params();
		$score = isset( $body['score'] ) ? (int) $body['score'] : -1;

		// The score must be within the 0-10 range.
		if ( 0 > $score || 10 < $score ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => __( 'Invalid score.', 'broken-link-checker-seo' )
			], 400 );
		}

		$feedback = isset( $body['feedback'] ) ? sanitize_textarea_field( $body['feedback'] ) : '';

		// The worker resolves the account from the licence key, so no user data leaves the site.
		$payload = [
			'product'        => Standalone\NpsSurvey::PRODUCT,
			'plugin_version' => aioseoBrokenLinkChecker()->version,
			'score'          => $score,
			'feedback'       => $feedback,
			'site_url'       => get_site_url(),
			'license_key'    => aioseoBrokenLinkChecker()->sensitiveOptions->get( 'licenseKey' )
		];

		// Dispatch to the external worker out-of-band; a slow or unreachable worker must not block this response.
		aioseoBrokenLinkChecker()->actionScheduler->scheduleSingle( Standalone\NpsSurvey::ACTION_DISPATCH, 0, [ 'payload' => $payload ], true );

		( new Standalone\NpsSurvey() )->submit();

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Records that the current user followed the review prompt.
	 *
	 * @since 1.3.1
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function reviewClick() {
		$payload = [
			'product'        => Standalone\NpsSurvey::PRODUCT,
			'plugin_version' => aioseoBrokenLinkChecker()->version,
			'site_url'       => get_site_url(),
			'license_key'    => aioseoBrokenLinkChecker()->sensitiveOptions->get( 'licenseKey' )
		];

		aioseoBrokenLinkChecker()->actionScheduler->scheduleSingle( Standalone\NpsSurvey::ACTION_DISPATCH_REVIEW_CLICK, 0, [ 'payload' => $payload ], true );

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}
}