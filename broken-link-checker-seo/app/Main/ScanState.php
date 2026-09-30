<?php
namespace AIOSEO\BrokenLinkChecker\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores how far each scan has got.
 *
 * NOTE: Its own option rather than a group in the internal options. A scan writes a cursor every few
 * seconds where the licence beside it changes once a year, and an options save writes the whole row -
 * so a scan already in flight reverted a licence that was activated while it ran.
 *
 * @since 1.3.1
 */
class ScanState {
	/**
	 * The option the state is stored in.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const OPTION_NAME = 'aioseo_blc_scan_state';

	/**
	 * Returns the ID of the link status scan in flight, if there is one.
	 *
	 * @since 1.3.1
	 *
	 * @return string The scan ID.
	 */
	public function getScanId() {
		$state = $this->read();

		return (string) $state['scanId'];
	}

	/**
	 * Records the ID of the link status scan in flight.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $scanId The scan ID, or an empty string once it has been collected.
	 * @return void
	 */
	public function setScanId( $scanId ) {
		$state           = $this->read();
		$state['scanId'] = (string) $scanId;

		$this->write( $state );
	}

	/**
	 * Returns the date posts have to have been scanned before to be scanned again.
	 *
	 * @since 1.3.1
	 *
	 * @return string The date, or an empty string when nothing has asked for a requeue.
	 */
	public function getMinimumLinkScanDate() {
		$state = $this->read();

		return (string) $state['minimumLinkScanDate'];
	}

	/**
	 * Records the date posts have to have been scanned before to be scanned again.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $date The date, in Y-m-d H:i:s.
	 * @return void
	 */
	public function setMinimumLinkScanDate( $date ) {
		$state                        = $this->read();
		$state['minimumLinkScanDate'] = (string) $date;

		$this->write( $state );
	}

	/**
	 * Returns where the given object sweep got to.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $slug The object type slug.
	 * @return array{cursor: int, swept: int} The state.
	 */
	public function getObjectScan( $slug ) {
		$state = $this->read();
		$scans = $state['objectScans'];

		return [
			'cursor' => isset( $scans[ $slug ]['cursor'] ) ? (int) $scans[ $slug ]['cursor'] : 0,
			'swept'  => isset( $scans[ $slug ]['swept'] ) ? (int) $scans[ $slug ]['swept'] : 0
		];
	}

	/**
	 * Records where the given object sweep got to.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $slug   The object type slug.
	 * @param  int    $cursor The ID the sweep reached.
	 * @param  int    $swept  When the sweep last finished a full pass.
	 * @return void
	 */
	public function setObjectScan( $slug, $cursor, $swept ) {
		$state = $this->read();

		$state['objectScans'][ $slug ] = [
			'cursor' => (int) $cursor,
			'swept'  => (int) $swept
		];

		$this->write( $state );
	}

	/**
	 * Returns the whole stored state, filled in with the defaults.
	 *
	 * @since 1.3.1
	 *
	 * @return array The state.
	 */
	private function read() {
		$stored = get_option( self::OPTION_NAME );

		// Falls back to where this used to live, for the window before the migration has run. Without it a
		// request that skipped the migrations reads no watermark and requeues every post that has a date.
		$state = false === $stored ? $this->legacy() : json_decode( (string) $stored, true );

		return array_merge(
			[
				'scanId'              => '',
				'minimumLinkScanDate' => '',
				'objectScans'         => []
			],
			is_array( $state ) ? $state : []
		);
	}

	/**
	 * Returns the state as the internal options stored it, before it had an option of its own.
	 *
	 * @since 1.3.1
	 *
	 * @return array The state.
	 */
	private function legacy() {
		$stored   = get_option( 'aioseo_blc_options_internal' );
		$decoded  = is_string( $stored ) ? json_decode( $stored, true ) : null;
		$internal = isset( $decoded['internal'] ) && is_array( $decoded['internal'] ) ? $decoded['internal'] : [];

		return [
			'scanId'              => isset( $internal['scanId'] ) ? (string) $internal['scanId'] : '',
			'minimumLinkScanDate' => isset( $internal['minimumLinkScanDate'] ) ? (string) $internal['minimumLinkScanDate'] : '',
			'objectScans'         => isset( $internal['objectScans'] ) && is_array( $internal['objectScans'] ) ? $internal['objectScans'] : []
		];
	}

	/**
	 * Stores the whole state.
	 *
	 * NOTE: Written straight away rather than on shutdown. Sweeps run under a lock, and the settings
	 * save that resets a cursor ends in wp_die().
	 *
	 * @since 1.3.1
	 *
	 * @param  array $state The state to store.
	 * @return void
	 */
	private function write( $state ) {
		update_option( self::OPTION_NAME, wp_json_encode( $state ), false );
	}
}