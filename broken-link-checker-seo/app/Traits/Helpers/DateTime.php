<?php
namespace AIOSEO\BrokenLinkChecker\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains date/time specific helper methods.
 *
 * @since 1.0.0
 */
trait DateTime {
	/**
	 * Returns a MySQL formatted date.
	 *
	 * @since 1.0.0
	 *
	 * @param  int|string   $time Any format accepted by strtotime.
	 * @return false|string       The MySQL formatted string.
	 */
	public function timeToMysql( $time ) {
		$time = is_string( $time ) ? strtotime( $time ) : $time;

		return date( 'Y-m-d H:i:s', $time ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	/**
	 * Returns the site's timezone offset from UTC, in seconds.
	 *
	 * @since 1.3.1
	 *
	 * @return int The offset.
	 */
	public function getTimeZoneOffset() {
		try {
			$timezone = get_option( 'timezone_string' );
			if ( $timezone ) {
				$timezoneObject = new \DateTimeZone( $timezone );

				return $timezoneObject->getOffset( new \DateTime( 'now' ) );
			}
		} catch ( \Exception $e ) {
			// Do nothing.
		}

		return intval( get_option( 'gmt_offset', 0 ) ) * HOUR_IN_SECONDS;
	}

	/**
	 * Returns a stable offset in minutes for the given identifier.
	 *
	 * Lets every install stagger a scheduled action by a different amount, so a shared host doesn't
	 * fire all of them in the same minute. Same identifier always yields the same offset.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $identifier       The identifier, e.g. the site domain.
	 * @param  int    $maxOffsetMinutes The exclusive upper bound.
	 * @return int                      The offset in minutes.
	 */
	public function generateRandomTimeOffset( $identifier, $maxOffsetMinutes ) {
		if ( 1 > (int) $maxOffsetMinutes ) {
			return 0;
		}

		$hashInteger = hexdec( substr( md5( strval( $identifier ) ), 0, 8 ) );

		return (int) ( $hashInteger % $maxOffsetMinutes );
	}
}