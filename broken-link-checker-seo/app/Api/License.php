<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles license update/removal.
 *
 * @since 1.0.0
 */
class License {
	/**
	 * Activates the license key.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function activate( $request ) {
		$body    = $request->get_json_params();
		$network = self::isNetworkRequest( $body );

		$internalOptions  = $network ? aioseoBrokenLinkChecker()->internalNetworkOptions : aioseoBrokenLinkChecker()->internalOptions;
		$license          = $network ? aioseoBrokenLinkChecker()->networkLicense : aioseoBrokenLinkChecker()->license;
		$sensitiveOptions = $network ? aioseoBrokenLinkChecker()->networkSensitiveOptions : aioseoBrokenLinkChecker()->sensitiveOptions;

		$licenseKey = ! empty( $body['licenseKey'] ) ? sanitize_text_field( $body['licenseKey'] ) : null;
		if ( empty( $licenseKey ) ) {
			// Fall back to the existing stored key (e.g. for re-validation after upgrade).
			$licenseKey = $sensitiveOptions->get( 'licenseKey' );
		}

		if ( empty( $licenseKey ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No license key given.'
			], 400 );
		}

		$sensitiveOptions->set( 'licenseKey', $licenseKey );
		$sensitiveOptions->save( true );

		$activated = $license->activateManual();

		// A free plan allows a single activation, so it cannot license a network. Disconnected again
		// rather than left in place, so nobody is looking at an account screen that cannot work.
		if ( $activated && $network && $license->isFree() ) {
			$license->deactivate();

			$sensitiveOptions->set( 'licenseKey', '' );
			$sensitiveOptions->save( true );

			$internalOptions->internal->license->reset(
				[
					'expires',
					'expired',
					'invalid',
					'disabled',
					'activationsError',
					'connectionError',
					'requestError',
					'level',
					'counts'
				]
			);
			$internalOptions->save( true );

			$licenseData = $internalOptions->internal->license->all();
			unset( $licenseData['licenseKey'] );

			return new \WP_REST_Response( [
				'error'            => true,
				'freeNotSupported' => true,
				'licenseData'      => $licenseData
			], 400 );
		}

		if ( $activated ) {
			// Persisted before the scan is queued, not left to shutdown: the async action fires a loopback
			// request that loads these options, and its own save would write back the copy it read.
			$internalOptions->save( true );

			// Force WordPress to check for updates.
			delete_site_transient( 'update_plugins' );

			// Scan for some posts to fill the report.
			aioseoBrokenLinkChecker()->actionScheduler->scheduleAsync( 'aioseo_blc_links_scan' );
		} else {
			$sensitiveOptions->set( 'licenseKey', '' );
			$sensitiveOptions->save( true );

			return new \WP_REST_Response( [
				'error'       => true,
				'licenseData' => $internalOptions->internal->license->all()
			], 400 );
		}

		aioseoBrokenLinkChecker()->notifications->init();

		$licenseData = $internalOptions->internal->license->all();
		unset( $licenseData['licenseKey'] );

		return new \WP_REST_Response( [
			'success'       => true,
			'hasLicenseKey' => true,
			'licenseData'   => $licenseData,
			'notifications' => Models\Notification::getNotifications()
		], 200 );
	}

	/**
	 * Whether the request is acting on the network's licence rather than this site's.
	 *
	 * NOTE: The capability is checked as well as the flag. The route is reachable from a subsite, and a
	 * site administrator must not be able to rewrite the licence for the whole network.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $body The request body.
	 * @return bool        Whether to act on the network licence.
	 */
	private static function isNetworkRequest( $body ) {
		if ( ! is_multisite() || empty( $body['network'] ) ) {
			return false;
		}

		if ( empty( aioseoBrokenLinkChecker()->networkLicense ) ) {
			return false;
		}

		return current_user_can( 'manage_network_options' );
	}

	/**
	 * Deactivates the license key.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function deactivate( $request ) {
		$body    = $request->get_json_params();
		$network = self::isNetworkRequest( $body );

		$internalOptions  = $network ? aioseoBrokenLinkChecker()->internalNetworkOptions : aioseoBrokenLinkChecker()->internalOptions;
		$license          = $network ? aioseoBrokenLinkChecker()->networkLicense : aioseoBrokenLinkChecker()->license;
		$sensitiveOptions = $network ? aioseoBrokenLinkChecker()->networkSensitiveOptions : aioseoBrokenLinkChecker()->sensitiveOptions;

		// Before the key is cleared, because the request to the licensing server needs it.
		$deactivated = $license->deactivate();

		$sensitiveOptions->set( 'licenseKey', '' );
		$sensitiveOptions->save( true );

		if ( $deactivated ) {
			// Force WordPress to check for updates.
			delete_site_transient( 'update_plugins' );

			$internalOptions->internal->license->reset(
				[
					'expires',
					'expired',
					'invalid',
					'disabled',
					'activationsError',
					'connectionError',
					'requestError',
					'level'
				]
			);
		} else {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		aioseoBrokenLinkChecker()->notifications->init();

		$licenseData = $internalOptions->internal->license->all();
		unset( $licenseData['licenseKey'] );

		return new \WP_REST_Response( [
			'success'       => true,
			'hasLicenseKey' => false,
			'licenseData'   => $licenseData,
			'notifications' => Models\Notification::getNotifications()
		], 200 );
	}
}