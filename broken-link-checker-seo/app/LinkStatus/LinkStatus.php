<?php
namespace AIOSEO\BrokenLinkChecker\LinkStatus;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Links\Url;
use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles the Link Status scan.
 *
 * @since 1.0.0
 */
class LinkStatus {
	/**
	 * The failure reasons that say nothing about the link itself, so a fetch from the site can still settle them.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const INCONCLUSIVE_FAILURES = [ 'blocked', 'timeout', 'unreachable' ];

	/**
	 * The base URL for the broken link checker server.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $baseUrl = 'https://check-links.aioseo.com/v1/';

	/**
	 * The action name of the scan.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $actionName = 'aioseo_blc_link_status_scan';

	/**
	 * Data class instance.
	 *
	 * @since 1.1.0
	 *
	 * @var Data
	 */
	public $data = null;

	/**
	 * Class constructor.
	 *
	 * @since   1.0.0
	 * @version 1.2.9 Remove is_admin() check to allow frontend scheduling.
	 */
	public function __construct() {
		$this->data = new Data();

		add_action( 'admin_init', [ $this, 'scheduleScan' ], 3003 );
		add_action( $this->actionName, [ $this, 'checkLinkStatuses' ], 11, 1 );
	}

	/**
	 * Schedules the link status scan as a recurring action.
	 *
	 * @since   1.0.0
	 * @version 1.2.9 Switch to recurring action with cache-based idle state.
	 * @version 1.3.1 A backoff no longer holds while links are due.
	 *
	 * @return void
	 */
	public function scheduleScan() {
		if ( ! aioseoBrokenLinkChecker()->license->isActive() ) {
			return;
		}

		// If we're in idle/backoff mode, unschedule and don't reschedule yet.
		if ( aioseoBrokenLinkChecker()->core->cache->get( 'as_blc_link_status_idle' ) ) {
			// The backoff is a timer, and a timer cannot know that links became due inside its window -
			// a post saved, an import, a plugin update whose one empty read parked the scan for an hour
			// with a queue waiting. Checked only while backing off, so an ordinary load pays nothing.
			if ( ! $this->data->getLinksToCheck( true ) ) {
				aioseoBrokenLinkChecker()->actionScheduler->unschedule( $this->actionName );

				return;
			}

			aioseoBrokenLinkChecker()->core->cache->delete( 'as_blc_link_status_idle' );
		}

		if ( aioseoBrokenLinkChecker()->actionScheduler->isScheduled( $this->actionName ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->actionScheduler->scheduleRecurrent( $this->actionName, 10, MINUTE_IN_SECONDS );
	}

	/**
	 * Sends links to the server to check their status.
	 *
	 * @since   1.0.0
	 * @version 1.2.9 Use recurring action with runtime lock and idle state.
	 *
	 * @return void
	 */
	public function checkLinkStatuses() {
		// Runtime lock: Prevent concurrent execution of this action.
		$lockKey = 'as_blc_link_status_running';
		if ( aioseoBrokenLinkChecker()->core->cache->get( $lockKey ) ) {
			return;
		}

		// Set lock with a safety timeout in case the action fails mid-execution.
		aioseoBrokenLinkChecker()->core->cache->update( $lockKey, true, 2 * MINUTE_IN_SECONDS );

		if ( ! aioseoBrokenLinkChecker()->license->isActive() ) {
			aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );

			return;
		}

		$scanId = aioseoBrokenLinkChecker()->scanState->getScanId();
		if ( ! empty( $scanId ) ) {
			// If we have a scan ID, check if the results are ready.
			$this->checkForScanResults();
			aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );

			return;
		}

		// If we don't have a scan ID, first check if there are links that need to be checked.
		$linksToCheck = $this->data->getlinksToCheck();
		if ( empty( $linksToCheck ) ) {
			// Backed off only when the queue could actually be read. Without the object columns there is
			// no queue to read {@see \AIOSEO\BrokenLinkChecker\LinkStatus\Data::getLinksToCheck()}, and
			// the schema they are read from is cached - so an update that flushes that cache answers
			// "nothing due" when it means "cannot tell", and an hour of scanning is lost to it.
			if ( aioseoBrokenLinkChecker()->helpers->hasObjectColumns() ) {
				// Nothing is due. The schedule method on the next init will unschedule.
				aioseoBrokenLinkChecker()->core->cache->update( 'as_blc_link_status_idle', true, HOUR_IN_SECONDS );
			}

			aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );

			return;
		}

		// If there are links to check, start a new scan.
		$this->startScan();

		aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );
	}

	/**
	 * Start a scan and store the scan ID.
	 *
	 * @since   1.0.0
	 * @version 1.2.9 Remove self-scheduling; recurring action handles next tick. Unschedule + idle on API errors.
	 *
	 * @return void
	 */
	private function startScan() {
		$requestBody = array_merge(
			$this->data->getBaseData(),
			[
				'links'        => $this->data->getlinksToCheck(),
				// The whole queue, not this batch of it. The service forwards it to the marketing
				// site, which sizes the plan it offers a reader who has run out of credits.
				'linksToCheck' => (int) $this->data->getLinksToCheck( true )
			]
		);

		$response     = $this->doPostRequest( 'scan/start/', $requestBody );
		$responseCode = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $responseCode ) {
			// Set idle cache. The schedule method on the next init will unschedule.
			aioseoBrokenLinkChecker()->core->cache->update( 'as_blc_link_status_idle', true, DAY_IN_SECONDS + wp_rand( 60, 600 ) );

			return;
		}

		if ( 418 === $responseCode ) {
			aioseoBrokenLinkChecker()->core->cache->update( 'as_blc_link_status_idle', true, HOUR_IN_SECONDS + wp_rand( 60, 600 ) );

			return;
		}

		$responseBody = json_decode( wp_remote_retrieve_body( $response ) );
		if ( $this->setIdleIfError( $responseBody ) ) {
			return;
		}

		if (
			is_wp_error( $response ) ||
			200 !== $responseCode ||
			empty( $responseBody->success ) ||
			empty( $responseBody->scanId ) ||
			! isset( $responseBody->quotaRemaining )
		) {
			// Return and let the next recurring action give it another go.
			return;
		}

		aioseoBrokenLinkChecker()->scanState->setScanId( $responseBody->scanId );

		aioseoBrokenLinkChecker()->license->applyQuotaFromResponse( $responseBody );
	}

	/**
	 * Checks if the scan has been completed. If so, parses and stores the results.
	 *
	 * @since   1.0.0
	 * @version 1.2.9 Remove self-scheduling; recurring action handles next tick. Idle on API errors.
	 *
	 * @return void
	 */
	private function checkForScanResults() {
		$scanId = aioseoBrokenLinkChecker()->scanState->getScanId();
		if ( empty( $scanId ) ) {
			return;
		}

		$response     = $this->doPostRequest( "scan/{$scanId}/" );
		$responseCode = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $responseCode ) {
			aioseoBrokenLinkChecker()->core->cache->update( 'as_blc_link_status_idle', true, DAY_IN_SECONDS + wp_rand( 60, 600 ) );

			return;
		}

		if ( 418 === $responseCode ) {
			aioseoBrokenLinkChecker()->core->cache->update( 'as_blc_link_status_idle', true, HOUR_IN_SECONDS + wp_rand( 60, 600 ) );

			return;
		}

		$responseBody = json_decode( wp_remote_retrieve_body( $response ) );
		if ( $this->setIdleIfError( $responseBody ) ) {
			return;
		}

		if ( is_wp_error( $response ) || 200 !== $responseCode || empty( $responseBody->success ) ) {
			// If the scan data cannot be found on the server, wipe the scan ID so the scan restarts.
			if ( ! empty( $responseBody->error ) && 'missing-scan-data' === strtolower( $responseBody->error ) ) {
				aioseoBrokenLinkChecker()->scanState->setScanId( '' );
			}

			// Return and let the next recurring action give it another go.
			return;
		}

		$this->parseResults( $responseBody );

		aioseoBrokenLinkChecker()->license->applyQuotaFromResponse( $responseBody );

		// Once the request is successful, we know the scan has been completed and we can go ahead and reset it.
		$this->doDeleteRequest( "scan/{$scanId}/" );
		aioseoBrokenLinkChecker()->scanState->setScanId( '' );
	}

	/**
	 * Checks the response body for error codes and sets the idle cache if found.
	 *
	 * @since 1.3.0
	 *
	 * @param  object|null $responseBody The decoded response body.
	 * @return bool                      Whether an error was found and idle was set.
	 */
	private function setIdleIfError( $responseBody ) {
		if ( empty( $responseBody->error ) ) {
			return false;
		}

		$errors = [ 'no-license', 'invalid-license', 'invalid-token', 'quota-exceeded', 'out-of-quota' ];
		if ( in_array( strtolower( $responseBody->error ), $errors, true ) ) {
			aioseoBrokenLinkChecker()->core->cache->update( 'as_blc_link_status_idle', true, DAY_IN_SECONDS + wp_rand( 60, 600 ) );

			return true;
		}

		return false;
	}

	/**
	 * Parse the results that came back from the server.
	 *
	 * @since 1.0.0
	 *
	 * @param  Object $responseBody The response body object.
	 * @return void
	 */
	private function parseResults( $responseBody ) {
		$scanData = json_decode( $responseBody->scanData );
		if ( empty( $scanData ) || empty( $scanData->urls ) ) {
			return;
		}

		foreach ( $scanData->urls as $url ) {
			$this->parseResultsHelper( $url );
		}

		// The dashboard widget counts these, and it cannot be left describing the site as it was before
		// this batch landed.
		aioseoBrokenLinkChecker()->helpers->clearLinkStatusDistribution();
	}

	/**
	 * Whether two URLs differ by nothing more than a trailing slash.
	 *
	 * NOTE: The address the check was made at, not the row's own. One result settles every row sharing
	 * that address, and the destination redirected from it - so any other row's copy answers nothing.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Takes the address the check was made at.
	 *
	 * @param  string $requestedUrl The address the check was made at.
	 * @param  string $finalUrl     The URL it ended up at.
	 * @return bool                 Whether the two are the same but for a trailing slash.
	 */
	private function isTrailingSlashOnly( $requestedUrl, $finalUrl ) {
		if ( empty( $finalUrl ) ) {
			return false;
		}

		return untrailingslashit( (string) $requestedUrl ) === untrailingslashit( (string) $finalUrl );
	}

	/**
	 * Names why a check failed for a given row.
	 *
	 * NOTE: A host the standard leaves no room for keeps its own reason whatever came back, on every
	 * path a row can be settled by. Without this a recheck of one reads as merely unreachable, which
	 * queues a local retry - and no fetch from anywhere can settle what DNS will not answer.
	 *
	 * NOTE: The address that was requested, not the row's own. One result settles every row sharing
	 * that address, and the host that was asked after is the one the answer is about.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $requestedUrl The address the check was made at.
	 * @param  object $data         The scan data the service returned.
	 * @return string               The reason slug, or an empty string when the status says enough.
	 */
	private function reasonFor( $requestedUrl, $data ) {
		if ( Url::hasUnresolvableHost( $requestedUrl ) ) {
			return 'invalid-host';
		}

		return $this->failureReason( $data );
	}

	/**
	 * Names why a check failed, as a slug the report turns into a sentence.
	 *
	 * NOTE: Stored because the status code alone cannot say it. A 403 from a firewall and a 403 from a
	 * page that is genuinely gone are the same number, and a check that never got a status at all could
	 * have timed out or found nothing there.
	 *
	 * The wording lives in the report rather than here, so it stays translatable.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Recognises an address that was metered rather than fetched.
	 * @version 1.3.1 Recognises a certificate a browser would refuse.
	 *
	 * @param  object $data The scan data the service returned.
	 * @return string       The reason slug, or an empty string when the status says enough.
	 */
	private function failureReason( $data ) {
		// The service knows a block when it sees one, and a blocked check never established anything
		// about the link. It is the one reason that means "we do not know" rather than "it is broken".
		if ( ! empty( $data->blockedByWaf ) ) {
			return 'blocked';
		}

		// Ahead of the status: these answer 200 over a certificate a browser refuses, so the code
		// says the page is fine while no visitor can reach it.
		if ( ! empty( $data->certError ) ) {
			return 'invalid-certificate';
		}

		$error = '';
		if ( ! empty( $data->error ) ) {
			$error = is_scalar( $data->error ) ? strtolower( (string) $data->error ) : strtolower( wp_json_encode( $data->error ) );
		}

		// Established by the sender, not by the check: the row was reported broken before anything went
		// out, and the service is only asked to meter the address {@see Data::fetchLinksToCheck()}.
		if ( false !== strpos( $error, 'invalid-host' ) ) {
			return 'invalid-host';
		}

		if ( false !== strpos( $error, 'timeout' ) || false !== strpos( $error, 'timed out' ) ) {
			return 'timeout';
		}

		if ( empty( $data->status ) ) {
			return 'unreachable';
		}

		// The page is there and wants a login. Worth saying, because a reader who is told a link is
		// simply broken goes looking for a fault that isn't one - the address is fine.
		if ( in_array( (int) $data->status, [ 401, 407 ], true ) ) {
			return 'login-required';
		}

		return '';
	}

	/**
	 * Helper function for parseResults().
	 *
	 * NOTE: One result settles every row requested at that address. Only one of them was sent, because
	 * one is all a credit buys; leaving the rest untouched would keep them unchecked forever and put them
	 * back in the next batch.
	 *
	 * @since   1.0.0
	 * @version 1.3.0 Set needs_additional_scan and clear idle cache on broken results.
	 * @version 1.3.1 A trailing-slash-only redirect is recorded as no redirect.
	 * @version 1.3.1 Records why a check failed alongside the status.
	 * @version 1.3.1 Settles every row requested at the same address.
	 *
	 * @param  Object $url The URL object.
	 * @return void
	 */
	public function parseResultsHelper( $url ) {
		// The address the check was made at, which the service echoes back exactly as it was sent.
		$requestedUrl = (string) $url->url;

		$linkStatus = Models\LinkStatus::getByUrl( $requestedUrl );
		if ( ! $linkStatus->exists() || empty( $url->data ) ) {
			return;
		}

		foreach ( Models\LinkStatus::getSiblings( $linkStatus ) as $sibling ) {
			$this->settleRow( $sibling, $requestedUrl, $url );
		}
	}

	/**
	 * Writes one result onto one row.
	 *
	 * @since 1.3.1
	 *
	 * @param  Models\LinkStatus $linkStatus   The row to settle.
	 * @param  string            $requestedUrl The address the check was made at.
	 * @param  Object            $url          The URL object.
	 * @return void
	 */
	private function settleRow( $linkStatus, $requestedUrl, $url ) {
		// Our scanning proxy failing establishes nothing about this link, so the previous verdict stands.
		// Left to the branch below, an unset status reads as broken and a working link is reported as
		// dead. first_failure is deliberately untouched: a check that never happened is not a failure.
		if ( ! empty( $url->data->proxyFailed ) ) {
			$linkStatus->scanning       = false;
			$linkStatus->scan_count     = $linkStatus->scan_count + 1;
			$linkStatus->last_scan_date = aioseoBrokenLinkChecker()->helpers->timeToMysql( time() );
			$linkStatus->log            = [
				'error'   => ! empty( $url->data->error ) ? $url->data->error : '',
				'headers' => ! empty( $url->data->headers ) ? $url->data->headers : ''
			];

			// Rescanned from this site instead, where our proxy is not in the path. The reason is passed
			// because the default queues nothing, which left these rows re-metered every interval forever.
			$this->maybeQueueLocalScan( $linkStatus, 'unreachable' );

			$linkStatus->save();

			return;
		}

		if ( empty( $url->data->status ) ) {
			$linkStatus->scanning         = false;
			$linkStatus->broken           = true;
			$linkStatus->http_status_code = null;
			$linkStatus->request_duration = 0;
			$linkStatus->final_url        = '';
			$linkStatus->scan_count       = $linkStatus->scan_count + 1;
			$linkStatus->last_scan_date   = aioseoBrokenLinkChecker()->helpers->timeToMysql( time() );
			$reason                       = $this->reasonFor( $requestedUrl, $url->data );
			$linkStatus->log              = [
				'error'   => ! empty( $url->data->error ) ? $url->data->error : '',
				'headers' => ! empty( $url->data->headers ) ? $url->data->headers : '',
				'reason'  => $reason
			];

			if ( ! $linkStatus->first_failure ) {
				$linkStatus->first_failure = aioseoBrokenLinkChecker()->helpers->timeToMysql( time() );
			}

			$this->maybeQueueLocalScan( $linkStatus, $reason );

			$linkStatus->save();

			return;
		}

		// A certificate a browser refuses is a broken link whatever the status says: the server answers
		// 200 happily, and the visitor never gets past the interstitial to see it.
		$success       = (int) $url->data->status < 400 && empty( $url->data->certError );
		$redirectCount = count( $url->data->redirects );
		$finalUrl      = $redirectCount ? $url->data->redirects[ $redirectCount - 1 ] : '';

		// Landing on the same URL with a trailing slash added or dropped is the destination normalising
		// itself, not somewhere else to point the link, so this counts as no redirect at all.
		if ( $redirectCount && $this->isTrailingSlashOnly( $requestedUrl, $finalUrl ) ) {
			$redirectCount = 0;
			$finalUrl      = '';
		}

		$linkStatus->scanning         = false;
		$linkStatus->broken           = ! $success;
		$linkStatus->http_status_code = (int) $url->data->status;
		$linkStatus->redirect_count   = $redirectCount;
		$linkStatus->final_url        = $finalUrl;
		$linkStatus->request_duration = ! empty( $url->data->stats->loadTime ) ? abs( $url->data->stats->loadTime ) : 0;
		$linkStatus->scan_count       = $linkStatus->scan_count + 1;
		$linkStatus->last_scan_date   = aioseoBrokenLinkChecker()->helpers->timeToMysql( time() );
		$reason                       = $success ? '' : $this->reasonFor( $requestedUrl, $url->data );
		$linkStatus->log              = [
			'error'   => ! empty( $url->data->error ) ? $url->data->error : '',
			'headers' => ! empty( $url->data->headers ) ? $url->data->headers : '',
			'reason'  => $reason
		];

		if ( $success ) {
			$linkStatus->last_success             = aioseoBrokenLinkChecker()->helpers->timeToMysql( time() );
			$linkStatus->first_failure            = null;
			$linkStatus->needs_additional_scan    = false;
			$linkStatus->client_confirmed_broken  = false;
			$linkStatus->local_scan_count         = 0;
		} else {
			if ( ! $linkStatus->first_failure ) {
				$linkStatus->first_failure = aioseoBrokenLinkChecker()->helpers->timeToMysql( time() );
			}

			$this->maybeQueueLocalScan( $linkStatus, $reason );
		}

		$linkStatus->save();
	}

	/**
	 * Queues a local re-scan for the failures that did not establish anything about the link.
	 *
	 * NOTE: Only for a reason that means "we could not tell" - a WAF that turned the service away, a
	 * destination that never answered, one that could not be reached at all. Fetching from the site
	 * itself can only settle those. An answer the service did get - a 404, a 410, a 500 - is the
	 * destination's own, and asking again from a different address cannot change it.
	 *
	 * NOTE: The default reason queues nothing. A caller that means "inconclusive" has to say which
	 * kind, or its rows settle as though the answer were the destination's own.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Added the $reason parameter; only queues for an inconclusive failure.
	 *
	 * @param  Models\LinkStatus $linkStatus The link status model instance.
	 * @param  string            $reason     The failure reason {@see self::failureReason()}.
	 * @return void
	 */
	private function maybeQueueLocalScan( Models\LinkStatus $linkStatus, $reason = '' ) {
		if ( ! in_array( $reason, self::INCONCLUSIVE_FAILURES, true ) || $linkStatus->client_confirmed_broken ) {
			$linkStatus->needs_additional_scan = false;

			return;
		}

		$linkStatus->needs_additional_scan = true;
		aioseoBrokenLinkChecker()->core->cache->delete( 'as_blc_local_scan_idle' );
	}

	/**
	 * Returns the URL for the Broken Link Checker server.
	 *
	 * @since 1.0.0
	 *
	 * @return string The URL.
	 */
	public function getUrl() {
		if ( defined( 'AIOSEO_BROKEN_LINK_CHECKER_SCAN_URL' ) ) {
			return AIOSEO_BROKEN_LINK_CHECKER_SCAN_URL;
		}

		return $this->baseUrl;
	}

	/**
	 * Sends a POST request to the server.
	 *
	 * @since 1.0.0
	 *
	 * @param  string          $path        The path.
	 * @param  array           $requestBody The request body.
	 * @return array|\WP_Error              The response or WP_Error on failure.
	 */
	public function doPostRequest( $path, $requestBody = [] ) {
		$requestData = [
			'timeout' => 60
		];

		if ( ! empty( $requestBody ) ) {
			$requestData['body'] = wp_json_encode( $requestBody );
		}

		return aioseoBrokenLinkChecker()->helpers->wpRemotePost( $this->getUrl() . $path, $requestData );
	}

	/**
	 * Sends a DELETE request to the server.
	 *
	 * @since 1.0.0
	 *
	 * @param  string          $path The path.
	 * @return array|\WP_Error       The response or WP_Error on failure.
	 */
	public function doDeleteRequest( $path ) {
		return aioseoBrokenLinkChecker()->helpers->wpRemoteDelete( $this->getUrl() . $path, [
			'timeout' => 60
		] );
	}
}