<?php
namespace AIOSEO\BrokenLinkChecker\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles license update/removal and related notices.
 *
 * @since 1.0.0
 */
class License {
	/**
	 * The base URL for the licensing API.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $baseUrl = 'https://blc-licensing.aioseo.com/v1/';

	/**
	 * The action name for the periodic license check.
	 *
	 * @since 1.2.9
	 *
	 * @var string
	 */
	public $actionName = 'aioseo_blc_license_check';

	/**
	 * Options class instance.
	 *
	 * @since 1.0.0
	 *
	 * @var \AIOSEO\BrokenLinkChecker\Options\Options
	 */
	protected $options = null;

	/**
	 * InternalOptions class instance.
	 *
	 * @since 1.0.0
	 *t
	 * @var \AIOSEO\BrokenLinkChecker\Options\InternalOptions
	 */
	protected $internalOptions = null;

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->internalOptions = aioseoBrokenLinkChecker()->internalOptions;

		add_action( 'init', [ $this, 'scheduleLicenseCheck' ], 3 );
		add_action( $this->actionName, [ $this, 'checkLicense' ] );
	}

	/**
	 * Schedules the license check as a recurring Action Scheduler action.
	 *
	 * @since 1.2.9
	 *
	 * @return void
	 */
	public function scheduleLicenseCheck() {
		if ( ! aioseoBrokenLinkChecker()->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->actionScheduler->scheduleRecurrent( $this->actionName, DAY_IN_SECONDS, DAY_IN_SECONDS );
	}

	/**
	 * Validates the stored license and resets license data if no key is present.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function checkLicense() {
		if ( ! aioseoBrokenLinkChecker()->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			if ( $this->needsReset() ) {
				$this->internalOptions->internal->license->reset(
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
			}

			return;
		}

		$this->activateProgrammatic();
	}

	/**
	 * Activates the license in response to a direct user action, e.g. connecting their account in the UI.
	 * Unlike activateProgrammatic(), this is never rate-limited so the user gets immediate feedback.
	 *
	 * @since 1.2.9
	 *
	 * @return bool Whether or not the license was activated.
	 */
	public function activateManual() {
		return $this->activate();
	}

	/**
	 * Activates the license from an automated/background context, e.g. a scheduled check or a quota-change triggered update.
	 * Rate-limited to one request per hour to prevent flooding the licensing server.
	 *
	 * @since 1.2.9
	 *
	 * @return bool Whether or not the license was activated.
	 */
	public function activateProgrammatic() {
		if ( aioseoBrokenLinkChecker()->core->cache->get( 'license_programmatic_activation' ) ) {
			return false;
		}

		aioseoBrokenLinkChecker()->core->cache->update( 'license_programmatic_activation', true, HOUR_IN_SECONDS );

		return $this->activate();
	}

	/**
	 * Activate/validate the license.
	 * This method should never be called directly. Use activateManual() or activateProgrammatic() instead.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether or not it was activated.
	 */
	private function activate() {
		$this->internalOptions->internal->license->reset(
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

		$licenseKey = aioseoBrokenLinkChecker()->sensitiveOptions->get( 'licenseKey' );
		if ( empty( $licenseKey ) ) {
			return false;
		}

		$site    = aioseoBrokenLinkChecker()->helpers->getSite();
		$domains = [
			'domain' => $site->domain,
			'path'   => $site->path
		];

		$response = $this->sendLicenseRequest( 'activate', $licenseKey, [ $domains ] );

		if ( empty( $response ) ) {
			// Something bad happened, error unknown.
			$this->internalOptions->internal->license->connectionError = true;

			return false;
		}

		if ( ! empty( $response->error ) ) {
			if ( 'missing-key-or-domain' === $response->error ) {
				$this->internalOptions->internal->license->requestError = true;

				return false;
			}

			if ( 'missing-license' === $response->error ) {
				$this->internalOptions->internal->license->invalid = true;

				return false;
			}

			if ( 'disabled' === $response->error ) {
				$this->internalOptions->internal->license->disabled = true;

				return false;
			}

			if ( 'activations' === $response->error ) {
				$this->internalOptions->internal->license->activationsError = true;

				return false;
			}

			if ( 'expired' === $response->error ) {
				$this->internalOptions->internal->license->expires = strtotime( $response->expires );
				$this->internalOptions->internal->license->expired = true;

				return false;
			}
		}

		// Something bad happened, error unknown.
		if ( empty( $response->success ) || empty( $response->level ) || ! isset( $response->broken_links_count ) ) {
			return false;
		}

		$oldQuota = $this->internalOptions->internal->license->quota;

		$this->internalOptions->internal->license->level   = $response->level;
		$this->internalOptions->internal->license->expires = strtotime( $response->expires );
		$this->internalOptions->internal->license->quota   = intval( $response->broken_links_count );

		// Set the remaining quota if it's never been set or if the user's plan has changed.
		if (
			! $this->internalOptions->internal->license->quotaRemaining ||
			( intval( $response->broken_links_count ) !== (int) $oldQuota )
		) {
			$this->internalOptions->internal->license->quotaRemaining = intval( $response->broken_links_count );
		}

		// Store activation counts if provided.
		if ( ! empty( $response->counts ) ) {
			$this->internalOptions->internal->license->counts = wp_json_encode( $response->counts );
		}

		return true;
	}

	/**
	 * Deactivate the license key.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether or not it was deactivated.
	 */
	public function deactivate() {
		$licenseKey = aioseoBrokenLinkChecker()->sensitiveOptions->get( 'licenseKey' );
		if ( empty( $licenseKey ) ) {
			return false;
		}

		$site    = aioseoBrokenLinkChecker()->helpers->getSite();
		$domains = [
			'domain' => $site->domain,
			'path'   => $site->path
		];

		$response = $this->sendLicenseRequest( 'deactivate', $licenseKey, [ $domains ] );

		if ( empty( $response ) ) {
			// Something bad happened, error unknown.
			$this->internalOptions->internal->license->connectionError = true;

			return false;
		}

		if ( ! empty( $response->error ) ) {
			if ( 'missing-key-or-domain' === $response->error || 'not-activated' === $response->error ) {
				$this->internalOptions->internal->license->requestError = true;

				return false;
			}

			if ( 'missing-license' === $response->error ) {
				$this->internalOptions->internal->license->invalid = true;

				return false;
			}

			if ( 'disabled' === $response->error ) {
				$this->internalOptions->internal->license->disabled = true;

				return false;
			}
		}

		$this->internalOptions->internal->license->reset(
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

		// Cancel all Link Status scans.
		as_unschedule_all_actions( aioseoBrokenLinkChecker()->main->linkStatus->actionName );
		as_unschedule_all_actions( aioseoBrokenLinkChecker()->main->localScan->actionName );

		return true;
	}

	/**
	 * Returns the URL to check licenses.
	 *
	 * @since 1.0.0
	 *
	 * @return string The URL.
	 */
	public function getUrl() {
		if ( defined( 'AIOSEO_BROKEN_LINK_CHECKER_LICENSING_URL' ) ) {
			return AIOSEO_BROKEN_LINK_CHECKER_LICENSING_URL;
		}

		return $this->baseUrl;
	}

	/**
	 * Checks to see if the current license is expired.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the license is expired.
	 */
	public function isExpired() {
		$networkIsExpired = false;
		if ( ! aioseoBrokenLinkChecker()->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return $networkIsExpired;
		}

		if ( $this->internalOptions->internal->license->expired ) {
			return true;
		}

		$expires = $this->internalOptions->internal->license->expires;

		return 0 !== $expires && $expires < time();
	}

	/**
	 * Checks to see if the current license is disabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the license is disabled.
	 */
	public function isDisabled() {
		$networkIsDisabled = false;
		if ( ! aioseoBrokenLinkChecker()->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return $networkIsDisabled;
		}

		return $this->internalOptions->internal->license->disabled;
	}

	/**
	 * Checks to see if the current license is invalid.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the license is invalid.
	 */
	public function isInvalid() {
		$networkIsInvalid = false;
		if ( ! aioseoBrokenLinkChecker()->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return $networkIsInvalid;
		}

		return $this->internalOptions->internal->license->invalid;
	}

	/**
	 * Checks to see if the current license is active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the license is active.
	 */
	public function isActive() {
		$networkIsActive = false;
		if ( ! aioseoBrokenLinkChecker()->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return $networkIsActive;
		}

		return ! $this->isExpired() && ! $this->isDisabled() && ! $this->isInvalid();
	}

	/**
	 * Get the license level for the activated license.
	 *
	 * @since 1.0.0
	 *
	 * @return string The license level.
	 */
	public function getLicenseLevel() {
		return $this->internalOptions->internal->license->level;
	}

	/**
	 * Checks if the license data needs to be reset.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the license data needs to be reet.
	 */
	private function needsReset() {
		if ( aioseoBrokenLinkChecker()->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return false;
		}

		if ( $this->internalOptions->internal->license->level ) {
			return true;
		}

		if ( $this->internalOptions->internal->license->invalid ) {
			return true;
		}

		if ( $this->internalOptions->internal->license->disabled ) {
			return true;
		}

		$expired = $this->internalOptions->internal->license->expired;
		if ( $expired ) {
			return true;
		}

		$expires = $this->internalOptions->internal->license->expires;

		return 0 !== $expires;
	}

	/**
	 * Sends the license request.
	 *
	 * @since 1.0.0
	 *
	 * @param  string      $type       The type of request, either activate or deactivate.
	 * @param  string      $licenseKey The license key we are using for this request.
	 * @param  array       $domains    List of domains to activate or deactivate.
	 * @return Object|null             The JSON response as an object.
	 */
	public function sendLicenseRequest( $type, $licenseKey, $domains ) {
		$payload = [
			'sku'         => 'aioseo-broken-link-checker',
			'version'     => AIOSEO_BROKEN_LINK_CHECKER_VERSION,
			'php_version' => PHP_VERSION,
			'license'     => $licenseKey,
			'domains'     => $domains,
			'wp_version'  => get_bloginfo( 'version' )
		];

		$response     = aioseoBrokenLinkChecker()->helpers->wpRemotePost( $this->getUrl() . $type . '/', [
			'timeout' => 20,
			'body'    => wp_json_encode( $payload )
		] );
		$responseBody = wp_remote_retrieve_body( $response );

		return ! empty( $responseBody ) ? json_decode( $responseBody ) : null;
	}

	/**
	 * Checks if the current site is licensed at the network level.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the site is licensed at the network level.
	 */
	public function isNetworkLicensed() {
		if ( ! property_exists( aioseoBrokenLinkChecker(), 'networkLicense' ) ) {
			return false;
		}

		return aioseoBrokenLinkChecker()->networkLicense->isActive();
	}

	/**
	 * Whether the current license plan is the free plan.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isFree() {
		return 'free' === strtolower( (string) $this->getLicenseLevel() );
	}
}