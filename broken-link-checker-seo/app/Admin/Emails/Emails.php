<?php
namespace AIOSEO\BrokenLinkChecker\Admin\Emails;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles sending emails.
 *
 * @since 1.2.6
 */
class Emails {
	/**
	 * ConnectReminder class instance.
	 *
	 * @since 1.2.6
	 *
	 * @var ConnectReminder
	 */
	public $connectReminder;

	/**
	 * ConnectReminderSecond class instance.
	 *
	 * @since 1.2.6
	 *
	 * @var ConnectReminderSecond
	 */
	public $connectReminderSecond;

	/**
	 * Class constructor.
	 *
	 * @since 1.2.6
	 */
	public function __construct() {
		$this->connectReminder       = new ConnectReminder();
		$this->connectReminderSecond = new ConnectReminderSecond();
	}
}