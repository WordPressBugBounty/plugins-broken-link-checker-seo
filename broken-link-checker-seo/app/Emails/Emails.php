<?php
namespace AIOSEO\BrokenLinkChecker\Emails;

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
		add_action( 'init', [ $this, 'handleUnsubscribe' ] );

		if ( ! aioseoBrokenLinkChecker()->license->isActive() ) {
			$this->connectReminder       = new ConnectReminder();
			$this->connectReminderSecond = new ConnectReminderSecond();
		}
	}

	/**
	 * Handles unsubscribe requests from email links.
	 *
	 * @since 1.2.9
	 *
	 * @return void
	 */
	public function handleUnsubscribe() {
		// Check if this is an unsubscribe request.
		if ( ! isset( $_GET['aioseo_blc_action'] ) || 'unsubscribe' !== $_GET['aioseo_blc_action'] ) {
			return;
		}

		// Validate email and token.
		$email = isset( $_GET['email'] ) ? rawurldecode( sanitize_email( wp_unslash( $_GET['email'] ) ) ) : '';
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( ! $email || ! $token ) {
			return;
		}

		// Validate the email matches the admin email.
		$adminEmail = get_option( 'admin_email' );
		if ( $email !== $adminEmail ) {
			return;
		}

		// Validate the nonce token.
		if ( ! wp_verify_nonce( $token, 'aioseo_blc_unsubscribe_' . $email ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->internalOptions->internal->emails->emailDisabled = true;

		// Show confirmation message.
		wp_die(
			esc_html__( 'You have been unsubscribed from Broken Link Checker reminder emails.', 'broken-link-checker-seo' ),
			esc_html__( 'Unsubscribed', 'broken-link-checker-seo' ),
			[ 'response' => 200 ]
		);
	}
}