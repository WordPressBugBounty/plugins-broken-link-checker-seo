<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles all general Broken Links report related routes.
 *
 * @since 1.1.0
 */
class BrokenLinks {
	/**
	 * Returns the scan percent completed.
	 *
	 * @since 1.1.0
	 *
	 * @param  \WP_REST_Request  $request The request
	 * @return \WP_REST_Response          The response.
	 */
	public static function getScanPercent( $request ) {
		$body   = $request->get_json_params();
		$scan = ! empty( $body['scan'] ) ? sanitize_text_field( $body['scan'] ) : '';
		if ( empty( $scan ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No scan name given.'
			], 400 );
		}

		$progress = [
			'done'    => 0,
			'total'   => 0,
			'percent' => 0
		];
		switch ( $scan ) {
			case 'links':
				$progress = aioseoBrokenLinkChecker()->main->links->data->getScanProgress();
				break;
			case 'linkStatuses':
				$progress = aioseoBrokenLinkChecker()->main->linkStatus->data->getScanProgress();
				break;
			default:
				break;
		}

		return new \WP_REST_Response( array_merge( [ 'success' => true ], $progress ), 200 );
	}
}