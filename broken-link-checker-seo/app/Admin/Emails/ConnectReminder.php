<?php
namespace AIOSEO\BrokenLinkChecker\Admin\Emails;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles account connection reminder emails.
 *
 * @since 1.2.6
 */
class ConnectReminder {
	/**
	 * The action hook for the reminder.
	 *
	 * @since 1.2.6
	 *
	 * @var string
	 */
	public $actionHook = 'aioseo_blc_connection_reminder';

	/**
	 * Class constructor.
	 *
	 * @since 1.2.6
	 */
	public function __construct() {
		add_action( $this->actionHook, [ $this, 'sendReminderEmail' ] );

		add_action( 'init', [ $this, 'maybeScheduleReminder' ] );
	}

	/**
	 * Schedules the reminder email if the plugin was recently activated,
	 * the user has not connected their license, and no reminder is scheduled.
	 *
	 * @since 1.2.6
	 *
	 * @return void
	 */
	public function maybeScheduleReminder() {
		// Check if the reminder email has already been sent.
		$hasBeenSent = aioseoBrokenLinkChecker()->internalOptions->internal->emails->connectReminder;
		if ( ! empty( $hasBeenSent ) ) {
			return;
		}

		// Check if the user has already connected their license.
		// Also check if a license is set, even if expired/invalid.
		$license = aioseoBrokenLinkChecker()->internalOptions->internal->license->licenseKey;
		if ( aioseoBrokenLinkChecker()->license->isActive() || ! empty( $license ) ) {
			aioseoBrokenLinkChecker()->internalOptions->internal->emails->connectReminder = time();

			return;
		}

		// Check if it's been less than a week since first activation.
		$firstActivated = aioseoBrokenLinkChecker()->internalOptions->internal->firstActivated;
		if ( ! $firstActivated || time() < ( $firstActivated + WEEK_IN_SECONDS ) ) {
			return;
		}

		// Check if a reminder is already scheduled.
		if ( aioseoBrokenLinkChecker()->actionScheduler->isScheduled( $this->actionHook ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->actionScheduler->scheduleSingle(
			$this->actionHook,
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Sends a reminder email to the admin if they haven't connected their account.
	 *
	 * @since 1.2.6
	 *
	 * @return void
	 */
	public function sendReminderEmail() {
		// Check if the reminder email has already been sent.
		$hasBeenSent = aioseoBrokenLinkChecker()->internalOptions->internal->emails->connectReminder;
		if ( ! empty( $hasBeenSent ) ) {
			return;
		}

		// Check if the user has already connected their license.
		// Also check if a license is set, even if expired/invalid.
		$license = aioseoBrokenLinkChecker()->internalOptions->internal->license->licenseKey;
		if ( aioseoBrokenLinkChecker()->license->isActive() || ! empty( $license ) ) {
			aioseoBrokenLinkChecker()->internalOptions->internal->emails->connectReminder = time();

			return;
		}

		// Get the admin email.
		$adminEmail = get_option( 'admin_email' );
		if ( ! $adminEmail ) {
			return;
		}

		// Mark the reminder as sent (we assume it will be sent successfully).
		aioseoBrokenLinkChecker()->internalOptions->internal->emails->connectReminder = time();

		$siteName = get_bloginfo( 'name' ) ?? site_url();
		$subject  = sprintf(
			// Translators: 1 - The site name.
			__( 'Warning: Broken Link Checker has not been connected on %1$s', 'broken-link-checker-seo' ),
			$siteName
		);

		ob_start();
		include AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Admin/Emails/Views/ConnectReminder.php';
		$message = ob_get_clean();

		$headers = [
			'Content-Type: text/plain; charset=UTF-8',
			'Reply-To: support@aioseo.com'
		];

		wp_mail(
			$adminEmail,
			$subject,
			$message,
			$headers
		);
	}
}