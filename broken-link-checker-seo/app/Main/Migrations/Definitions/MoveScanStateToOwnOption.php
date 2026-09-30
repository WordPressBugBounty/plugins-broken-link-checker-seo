<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;
use AIOSEO\BrokenLinkChecker\Main\ScanState;

/**
 * Copies the scan's own state out of the internal options and into its own option.
 *
 * @since 1.3.1
 */
class MoveScanStateToOwnOption implements Migration {
	/**
	 * The name this migration is logged under.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const NAME = 'move_scan_state_to_own_option';

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function name() {
		return self::NAME;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function version() {
		return '1.3.1';
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: Read from the row rather than through the options accessor, which no longer declares these
	 * keys and so would answer null for all three.
	 *
	 * @since 1.3.1
	 */
	public function up() {
		$stored   = get_option( 'aioseo_blc_options_internal' );
		$decoded  = is_string( $stored ) ? json_decode( $stored, true ) : null;
		$internal = isset( $decoded['internal'] ) && is_array( $decoded['internal'] ) ? $decoded['internal'] : [];

		// The watermark is the one value that cannot be defaulted: an empty one reads as "now", which puts
		// every post that has ever been scanned back in the queue and spends the site's credits again.
		$state = [
			'scanId'              => isset( $internal['scanId'] ) ? (string) $internal['scanId'] : '',
			'minimumLinkScanDate' => isset( $internal['minimumLinkScanDate'] ) ? (string) $internal['minimumLinkScanDate'] : '',
			'objectScans'         => isset( $internal['objectScans'] ) && is_array( $internal['objectScans'] ) ? $internal['objectScans'] : []
		];

		update_option( ScanState::OPTION_NAME, wp_json_encode( $state ), false );
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: The runner asks this before it calls up(), so it has to answer whether the move has already
	 * happened - not whether the state is worth moving. A fresh install has nothing to copy and still
	 * gets the option written, which is what makes the answer the same either way.
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		return false !== get_option( ScanState::OPTION_NAME );
	}
}