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
	 * The email type of the connection reminder sequence.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const TYPE_REMINDER = 'reminder';

	/**
	 * The email type of the weekly and monthly reports.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const TYPE_REPORT = 'report';

	/**
	 * The colour of inline links.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const LINK_COLOR = '#2271b1';

	/**
	 * The colour of call-to-action buttons.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const BUTTON_COLOR = '#1da867';

	/**
	 * ConnectReminders class instance.
	 *
	 * @since   1.2.6
	 * @version 1.3.1 Replaces the separate ConnectReminder and ConnectReminderSecond instances.
	 *
	 * @var ConnectReminders
	 */
	public $connectReminders;

	/**
	 * Reports class instance.
	 *
	 * @since 1.3.1
	 *
	 * @var Reports\Reports
	 */
	public $reports;

	/**
	 * Class constructor.
	 *
	 * @since 1.2.6
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'handleUnsubscribe' ] );

		$this->reports = new Reports\Reports();

		if ( ! aioseoBrokenLinkChecker()->license->isActive() ) {
			$this->connectReminders = new ConnectReminders();
		}
	}

	/**
	 * Handles unsubscribe requests from email links.
	 *
	 * @since   1.2.9
	 * @version 1.3.1 Scopes the request to the email type and authenticates it with an HMAC token.
	 *
	 * @return void
	 */
	public function handleUnsubscribe() {
		// The token below is what authorises the request. A nonce cannot be: it is bound to a user ID
		// and a 12 hour tick, and an emailed link is clicked by whoever, whenever.
		// phpcs:disable HM.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['aioseo_blc_action'] ) || 'unsubscribe' !== $_GET['aioseo_blc_action'] ) {
			return;
		}

		$email = isset( $_GET['email'] ) ? rawurldecode( sanitize_email( wp_unslash( $_GET['email'] ) ) ) : '';
		$type  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable HM.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Recommended

		if ( ! $email || ! in_array( $type, [ self::TYPE_REMINDER, self::TYPE_REPORT ], true ) ) {
			$this->dieUnsubscribeFailed();
		}

		if ( ! hash_equals( $this->getUnsubscribeToken( $email, $type ), $token ) ) {
			$this->dieUnsubscribeFailed();
		}

		// Which list is left comes from the link's type, never from which lists the address is on: an
		// address that is both a report recipient and a reminder target must not flip both.
		if ( self::TYPE_REPORT === $type ) {
			$this->removeReportRecipient( $email );

			$this->dieUnsubscribed( $type );
		}

		aioseoBrokenLinkChecker()->internalOptions->internal->emails->emailDisabled = true;

		// Save before dying rather than relying on the shutdown hook, so the state the user was
		// just shown a confirmation page for is already committed.
		aioseoBrokenLinkChecker()->internalOptions->save( true );

		$this->dieUnsubscribed( $type );
	}

	/**
	 * Returns the unsubscribe URL for the given address and email type.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $email The address.
	 * @param  string $type  The email type.
	 * @return string        The URL.
	 */
	public function getUnsubscribeUrl( $email, $type ) {
		return add_query_arg(
			[
				'aioseo_blc_action' => 'unsubscribe',
				'email'             => rawurlencode( $email ),
				'type'              => $type,
				'token'             => $this->getUnsubscribeToken( $email, $type )
			],
			home_url()
		);
	}

	/**
	 * Wraps the given HTML in a styled paragraph.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $html The paragraph HTML.
	 * @return string       The paragraph.
	 */
	public function renderParagraph( $html ) {
		// The background is set alongside the colour: clients that invert an unpaired colour would
		// otherwise render this dark on dark.
		$style = 'margin: 0 0 16px 0; padding: 0; background-color: #ffffff; color: #141b38;'
			. ' font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 22px;';

		return sprintf( '<p style="%1$s">%2$s</p>', esc_attr( $style ), wp_kses_post( $html ) );
	}

	/**
	 * Splits a double-newline separated message into styled paragraphs.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $message The message.
	 * @return string          The paragraphs.
	 */
	public function renderParagraphs( $message ) {
		$output = '';
		foreach ( explode( "\n\n", $message ) as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}

			$output .= $this->renderParagraph( make_clickable( $paragraph ) );
		}

		return $output;
	}

	/**
	 * Returns a styled inline link.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url  The URL.
	 * @param  string $text The link text.
	 * @return string       The link.
	 */
	public function renderLink( $url, $text ) {
		return sprintf(
			'<a href="%1$s" style="color: %2$s; text-decoration: underline;">%3$s</a>',
			esc_url( $url ),
			esc_attr( self::LINK_COLOR ),
			esc_html( $text )
		);
	}

	/**
	 * Returns a styled call-to-action button.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url  The URL.
	 * @param  string $text The button label.
	 * @return string       The button.
	 */
	public function renderButton( $url, $text ) {
		$wrapperStyle = 'width: 100%; border-collapse: collapse; background-color: #ffffff;';
		$wrapperCell  = 'padding: 8px 0 24px 0; background-color: #ffffff; text-align: center;';
		$tableStyle   = 'border-collapse: collapse; background-color: ' . self::BUTTON_COLOR . ';';
		$cellStyle    = 'border-radius: 4px; background-color: ' . self::BUTTON_COLOR . '; color: #ffffff;'
			. ' padding: 12px 24px; text-align: center;';
		$linkStyle    = 'display: inline-block; color: #ffffff; background-color: ' . self::BUTTON_COLOR . ';'
			. ' font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; font-weight: 700;'
			. ' line-height: 20px; text-decoration: none;';

		// Centred by an align="center" cell rather than margin: auto, which Outlook's Word engine
		// ignores on a table.
		return sprintf(
			'<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="%1$s"><tr>'
				. '<td align="center" style="%2$s">'
				. '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="%3$s"><tr>'
				. '<td align="center" bgcolor="%4$s" style="%5$s">'
				. '<a href="%6$s" style="%7$s">%8$s</a>'
				. '</td></tr></table>'
				. '</td></tr></table>',
			esc_attr( $wrapperStyle ),
			esc_attr( $wrapperCell ),
			esc_attr( $tableStyle ),
			esc_attr( self::BUTTON_COLOR ),
			esc_attr( $cellStyle ),
			esc_url( $url ),
			esc_attr( $linkStyle ),
			esc_html( $text )
		);
	}

	/**
	 * Renders an email with the shared shell.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $view           The view file name, without the extension.
	 * @param  string $preHeader      The inbox preview line.
	 * @param  string $recipientEmail The recipient, so the greeting and unsubscribe token are theirs.
	 * @param  string $emailType      The email type the unsubscribe link opts out of.
	 * @param  array  $data           Data the view needs.
	 * @return string                 The rendered email, or an empty string if the view is missing.
	 */
	public function render( $view, $preHeader, $recipientEmail, $emailType, $data = [] ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$contentFile = AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Emails/Views/Content/' . $view . '.php';
		if ( ! file_exists( $contentFile ) ) {
			return '';
		}

		ob_start();
		require AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Emails/Views/Layout.php';

		return ob_get_clean();
	}

	/**
	 * Returns the headers every email is sent with.
	 *
	 * @since 1.3.1
	 *
	 * @return string[] The headers.
	 */
	public function getHeaders() {
		return [
			'Content-Type: text/html; charset=UTF-8',
			'Reply-To: support@aioseo.com'
		];
	}

	/**
	 * Returns the unsubscribe token for the given address and email type.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $email The address.
	 * @param  string $type  The email type.
	 * @return string        The token.
	 */
	private function getUnsubscribeToken( $email, $type ) {
		return hash_hmac( 'sha256', strtolower( $email ) . '|' . $type, wp_salt( 'nonce' ) );
	}

	/**
	 * Renders the unsubscribe confirmation and exits.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $type The email type that was unsubscribed from.
	 * @return void
	 */
	private function dieUnsubscribed( $type ) {
		wp_die(
			self::TYPE_REPORT === $type
				? esc_html__( 'You have been unsubscribed from Broken Link Checker email reports.', 'broken-link-checker-seo' )
				: esc_html__( 'You have been unsubscribed from Broken Link Checker reminder emails.', 'broken-link-checker-seo' ),
			esc_html__( 'Unsubscribed', 'broken-link-checker-seo' ),
			[ 'response' => 200 ]
		);
	}

	/**
	 * Renders the unsubscribe failure page and exits.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	private function dieUnsubscribeFailed() {
		wp_die(
			esc_html__(
				'This unsubscribe link is not valid, so nothing was changed. You can manage these emails in the Broken Link Checker settings in your WordPress dashboard.',
				'broken-link-checker-seo'
			),
			esc_html__( 'Unsubscribe failed', 'broken-link-checker-seo' ),
			[ 'response' => 403 ]
		);
	}

	/**
	 * Drops the given address from the report recipients.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $email The address.
	 * @return void
	 */
	private function removeReportRecipient( $email ) {
		$emailReports = aioseoBrokenLinkChecker()->options->general->emailReports->all();
		$recipients   = ! empty( $emailReports['recipients'] ) ? (array) $emailReports['recipients'] : [];
		if ( empty( $recipients ) ) {
			return;
		}

		$remaining = [];
		foreach ( $recipients as $recipient ) {
			if ( strtolower( (string) $recipient ) === strtolower( $email ) ) {
				continue;
			}

			$remaining[] = $recipient;
		}

		if ( count( $remaining ) === count( $recipients ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->options->general->emailReports->recipients = $remaining;
		aioseoBrokenLinkChecker()->options->save( true );
	}
}