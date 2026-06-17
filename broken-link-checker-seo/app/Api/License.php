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
		$body       = $request->get_json_params();
		$network    = is_multisite() && ! empty( $body['network'] ) ? (bool) $body['network'] : false;
		$licenseKey = ! empty( $body['licenseKey'] ) ? sanitize_text_field( $body['licenseKey'] ) : null;
		if ( empty( $licenseKey ) ) {
			// Fall back to the existing stored key (e.g. for re-validation after upgrade).
			$licenseKey = aioseoBrokenLinkChecker()->sensitiveOptions->get( 'licenseKey' );
		}

		if ( empty( $licenseKey ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No license key given.'
			], 400 );
		}

		$internalOptions = aioseoBrokenLinkChecker()->internalOptions;
		$license         = aioseoBrokenLinkChecker()->license;
		if ( $network ) {
			$internalOptions = aioseoBrokenLinkChecker()->internalNetworkOptions;
			$license         = aioseoBrokenLinkChecker()->networkLicense;
		}

		aioseoBrokenLinkChecker()->sensitiveOptions->set( 'licenseKey', $licenseKey );
		$activated = $license->activateManual();

		if ( $activated ) {
			// Force WordPress to check for updates.
			delete_site_transient( 'update_plugins' );

			// Scan for some posts to fill the report.
			aioseoBrokenLinkChecker()->actionScheduler->scheduleAsync( 'aioseo_blc_links_scan' );
		} else {
			aioseoBrokenLinkChecker()->sensitiveOptions->set( 'licenseKey', '' );

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
	 * Deactivates the license key.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function deactivate( $request ) {
		$body    = $request->get_json_params();
		$network = is_multisite() && ! empty( $body['network'] ) ? (bool) $body['network'] : false;

		$internalOptions = aioseoBrokenLinkChecker()->internalOptions;
		$license         = aioseoBrokenLinkChecker()->license;
		if ( $network ) {
			$internalOptions = aioseoBrokenLinkChecker()->internalNetworkOptions;
			$license         = aioseoBrokenLinkChecker()->networkLicense;
		}

		$deactivated = $license->deactivate();
		aioseoBrokenLinkChecker()->sensitiveOptions->set( 'licenseKey', '' );

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