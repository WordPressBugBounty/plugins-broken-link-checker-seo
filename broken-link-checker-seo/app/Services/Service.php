<?php
namespace AIOSEO\BrokenLinkChecker\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for the services the Abilities layer delegates to.
 *
 * Holds the guards every service shares, so an ability can never reach further than the REST
 * route covering the same data does.
 *
 * @internal Not a public extension surface.
 *
 * @since 1.3.1
 */
abstract class Service {
	/**
	 * Returns an error for a caller without the capability the report's REST routes require.
	 *
	 * NOTE: Mirrors {@see \AIOSEO\BrokenLinkChecker\Api\Api::validRequest()} for the routes declaring
	 * `aioseo_blc_broken_links_page`.
	 *
	 * @since 1.3.1
	 *
	 * @return \WP_Error|null The error, or null when the caller has access.
	 */
	protected function checkAccess() {
		if (
			is_user_logged_in() &&
			(
				aioseoBrokenLinkChecker()->access->isAdmin() ||
				current_user_can( 'aioseo_blc_broken_links_page' )
			)
		) {
			return null;
		}

		return new \WP_Error(
			'blc_forbidden',
			__( 'You do not have permission to manage broken links.', 'broken-link-checker-seo' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Returns an error when the site's license can't check links.
	 *
	 * @since 1.3.1
	 *
	 * @return \WP_Error|null The error, or null when the license is active.
	 */
	protected function checkLicense() {
		if ( aioseoBrokenLinkChecker()->license->isActive() ) {
			return null;
		}

		if ( aioseoBrokenLinkChecker()->license->isLapsed() ) {
			return new \WP_Error(
				'blc_license_lapsed',
				__( 'This site\'s license no longer works, so links cannot be checked until it is renewed.', 'broken-link-checker-seo' ),
				[ 'status' => 409 ]
			);
		}

		return new \WP_Error(
			'blc_not_connected',
			__( 'This site has no connected account, so links cannot be checked.', 'broken-link-checker-seo' ),
			[ 'status' => 409 ]
		);
	}
}