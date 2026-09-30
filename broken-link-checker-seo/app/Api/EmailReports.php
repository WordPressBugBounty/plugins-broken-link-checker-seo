<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the email report routes.
 *
 * @since 1.3.1
 */
class EmailReports {
	/**
	 * Sends a test report, so the setting can be proved to work without waiting for a cadence.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function sendTest( $request ) {
		$body  = $request->get_json_params();
		$email = ! empty( $body['email'] ) ? sanitize_email( $body['email'] ) : '';

		// The address of whoever asked, when none was given.
		if ( ! $email ) {
			$user  = wp_get_current_user();
			$email = is_a( $user, 'WP_User' ) ? sanitize_email( $user->user_email ) : '';
		}

		if ( ! $email || ! is_email( $email ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => __( 'Please provide a valid email address.', 'broken-link-checker-seo' )
			], 400 );
		}

		if ( ! aioseoBrokenLinkChecker()->emails->reports->sendTest( $email ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				// The mailer is the site's, so what went wrong is not ours to report.
				'message' => __( 'We couldn\'t send the email. Check how your site sends mail and try again.', 'broken-link-checker-seo' )
			], 500 );
		}

		return new \WP_REST_Response( [
			'success' => true,
			'email'   => $email,
			'message' => sprintf(
				// Translators: 1 - An email address.
				__( 'Test report sent to %1$s.', 'broken-link-checker-seo' ),
				$email
			)
		], 200 );
	}
}