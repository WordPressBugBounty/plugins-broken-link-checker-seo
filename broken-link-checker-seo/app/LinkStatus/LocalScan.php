<?php
namespace AIOSEO\BrokenLinkChecker\LinkStatus;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles client-side re-scanning of links flagged by the server scan.
 *
 * When the server reports a link as broken, it gets flagged with needs_additional_scan.
 * This class fetches those URLs directly from the WordPress site to confirm whether
 * they are genuinely broken or if the server was blocked (bot protection, geo-blocks, etc.).
 *
 * @since 1.3.0
 */
class LocalScan {
	/**
	 * The action name of the local scan.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	public $actionName = 'aioseo_blc_local_scan';

	/**
	 * Number of URLs to process per Action Scheduler run.
	 *
	 * @since 1.3.0
	 *
	 * @var int
	 */
	const BATCH_SIZE = 5;

	/**
	 * Per-URL request timeout, in seconds.
	 *
	 * @since 1.3.0
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 10;

	/**
	 * Local re-scan attempts after which an unreachable link settles instead of re-queuing.
	 *
	 * @since 1.3.0
	 *
	 * @var int
	 */
	const MAX_LOCAL_SCAN_ATTEMPTS = 10;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'maybeScheduleScan' ], 3005 );
		add_action( $this->actionName, [ $this, 'runScan' ] );
	}

	/**
	 * Schedules the local scan if there are flagged links and it's not already scheduled.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function maybeScheduleScan() {
		if ( ! aioseoBrokenLinkChecker()->license->isActive() ) {
			return;
		}

		if ( aioseoBrokenLinkChecker()->actionScheduler->isScheduled( $this->actionName ) ) {
			return;
		}

		// Skip the DB query when the queue is known to be empty.
		if ( aioseoBrokenLinkChecker()->core->cache->get( 'as_blc_local_scan_idle' ) ) {
			return;
		}

		$count = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->where( 'needs_additional_scan', 1 )
			->count();

		if ( $count > 0 ) {
			aioseoBrokenLinkChecker()->actionScheduler->scheduleRecurrent( $this->actionName, 10, 10 * MINUTE_IN_SECONDS );
		} else {
			aioseoBrokenLinkChecker()->core->cache->update( 'as_blc_local_scan_idle', true, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Processes a batch of links flagged for client-side re-scanning.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Rotates the queue instead of re-picking the same rows every run.
	 *
	 * @return void
	 */
	public function runScan() {
		$lockKey = 'as_blc_local_scan_running';
		if ( aioseoBrokenLinkChecker()->core->cache->get( $lockKey ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->core->cache->update( $lockKey, true, 2 * MINUTE_IN_SECONDS );

		if ( ! aioseoBrokenLinkChecker()->license->isActive() ) {
			aioseoBrokenLinkChecker()->actionScheduler->unschedule( $this->actionName );
			aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );

			return;
		}

		$batchSize = max( 1, (int) apply_filters( 'aioseo_blc_local_scan_batch_size', self::BATCH_SIZE ) );

		// Least-tried first, oldest first among equals. Unordered, the same five rows came back every
		// run - a dead host never settles until its tenth attempt - and nothing else was ever reached.
		$linkStatuses = aioseoBrokenLinkChecker()->core->db->start( 'aioseo_blc_link_status' )
			->where( 'needs_additional_scan', 1 )
			->orderBy( 'local_scan_count ASC, updated ASC' )
			->limit( $batchSize )
			->run()
			->models( 'AIOSEO\\BrokenLinkChecker\\Models\\LinkStatus' );

		if ( empty( $linkStatuses ) ) {
			aioseoBrokenLinkChecker()->actionScheduler->unschedule( $this->actionName );
			aioseoBrokenLinkChecker()->core->cache->update( 'as_blc_local_scan_idle', true, 10 * MINUTE_IN_SECONDS );
			aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );

			return;
		}

		foreach ( $linkStatuses as $linkStatus ) {
			$this->scanUrl( $linkStatus );
		}

		aioseoBrokenLinkChecker()->core->cache->delete( $lockKey );
	}

	/**
	 * Fetches one URL now rather than leaving it for the scheduled batch.
	 *
	 * NOTE: For a check a person is waiting on. The scheduled run is what handles everything else, and
	 * this deliberately does not touch its lock - one row settling early is not the batch running.
	 *
	 * @since 1.3.1
	 *
	 * @param  Models\LinkStatus $linkStatus The link status model instance.
	 * @return void
	 */
	public function scanNow( $linkStatus ) {
		$this->scanUrl( $linkStatus );
	}

	/**
	 * Fetches a single URL from the WordPress site and updates its link status.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Fetches the address the row is requested at, not its matching key.
	 * @version 1.3.1 Says the link is broken when the attempts run out, rather than leaving the verdict.
	 * @version 1.3.1 Reports no status code and an unreachable reason once the attempts run out.
	 *
	 * @param  Models\LinkStatus $linkStatus The link status model instance.
	 * @return void
	 */
	private function scanUrl( $linkStatus ) {
		$response = wp_safe_remote_get( $linkStatus->checkUrl(), $this->requestArgs() );

		$linkStatus->scan_count       = $linkStatus->scan_count + 1;
		$linkStatus->local_scan_count = $linkStatus->local_scan_count + 1;

		if ( is_wp_error( $response ) ) {
			if ( $linkStatus->local_scan_count >= self::MAX_LOCAL_SCAN_ATTEMPTS ) {
				$linkStatus->client_confirmed_broken = true;
				$linkStatus->needs_additional_scan   = false;

				// Said outright rather than left to whatever the row held. A proxy failure keeps the
				// previous verdict, which on a first check is "not broken" - so the row read as good.
				$linkStatus->broken = true;

				if ( ! $linkStatus->first_failure ) {
					$linkStatus->first_failure = aioseoBrokenLinkChecker()->helpers->timeToMysql( time() );
				}

				// Settling as broken: drop stale server-measured redirect data so the link
				// doesn't surface under both the Broken and Redirects filters.
				$linkStatus->redirect_count = 0;
				$linkStatus->final_url      = '';

				// The code the service last saw is not this link's any more - ten fetches from here reached
				// nothing - and left in place the row reads "the destination returned a 200 error".
				$log           = (array) $linkStatus->log;
				$log['error']  = $response->get_error_message();
				$log['reason'] = 'unreachable';

				$linkStatus->http_status_code = null;
				$linkStatus->last_scan_date   = aioseoBrokenLinkChecker()->helpers->timeToMysql( time() );
				$linkStatus->log              = $log;
			} else {
				$linkStatus->needs_additional_scan = true;
			}

			$linkStatus->save();

			return;
		}

		$statusCode = (int) wp_remote_retrieve_response_code( $response );
		$success    = $statusCode >= 200 && $statusCode < 400;

		// The local fetch follows redirects and only reports the final status; it never
		// measures the redirect chain, so don't carry stale server-measured redirect data.
		$linkStatus->needs_additional_scan = false;
		$linkStatus->local_scan_count      = 0;
		$linkStatus->last_scan_date        = aioseoBrokenLinkChecker()->helpers->timeToMysql( time() );
		$linkStatus->http_status_code      = $statusCode;
		$linkStatus->broken                = ! $success;
		$linkStatus->redirect_count        = 0;
		$linkStatus->final_url             = '';

		if ( $success ) {
			$linkStatus->client_confirmed_broken = false;
			$linkStatus->first_failure           = null;
		} else {
			$linkStatus->client_confirmed_broken = true;
		}

		$linkStatus->save();
	}

	/**
	 * Request args for the client-side re-fetch. Browser UA dodges naive "WordPress" UA blocks.
	 *
	 * @since 1.3.0
	 *
	 * @return array
	 */
	private function requestArgs() {
		return [
			'timeout'    => self::REQUEST_TIMEOUT,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
		];
	}
}