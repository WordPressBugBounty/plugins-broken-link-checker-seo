<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Dashboard\Data;

/**
 * Handles the dashboard route.
 *
 * @since 1.3.1
 */
class Dashboard {
	/**
	 * Returns everything the dashboard draws.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function getData( $request ) { // phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return new \WP_REST_Response( [
			'success'   => true,
			'dashboard' => Data::getAll()
		], 200 );
	}
}