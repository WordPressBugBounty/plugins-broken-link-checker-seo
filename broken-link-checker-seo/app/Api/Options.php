<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles all options related routes.
 *
 * @since 1.2.6
 */
class Options {
	/**
	 * Returns the settings.
	 *
	 * @since   1.0.0
	 * @version 1.2.6 Moved to dedicated class.
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function getOptions() {
		return new \WP_REST_Response( [
			'success'         => true,
			'options'         => aioseoBrokenLinkChecker()->options->all(),
			'internalOptions' => aioseoBrokenLinkChecker()->internalOptions->all(),
			'settings'        => aioseoBrokenLinkChecker()->vueSettings->all()
		], 200 );
	}

	/**
	 * Save options from the frontend.
	 *
	 * @since   1.0.0
	 * @version 1.2.6 Moved to dedicated class.
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function saveChanges( $request ) {
		$body    = $request->get_json_params();
		$options = ! empty( $body['options'] ) ? $body['options'] : []; // The options class will sanitize them.

		aioseoBrokenLinkChecker()->options->sanitizeAndSave( $options );

		// Re-initialize the notices.
		aioseoBrokenLinkChecker()->notifications->init();

		return new \WP_REST_Response( [
			'success'       => true,
			'notifications' => Models\Notification::getNotifications()
		], 200 );
	}
}