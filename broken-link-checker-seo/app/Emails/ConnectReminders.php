<?php
namespace AIOSEO\BrokenLinkChecker\Emails;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the account connection reminder sequence.
 *
 * @since 1.3.1
 */
class ConnectReminders {
	/**
	 * The action hook that sends the next due reminder.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	public $actionHook = 'aioseo_blc_connection_reminder';

	/**
	 * Hooks from the previous one-class-per-email implementation.
	 * Still handled so actions scheduled before the update resolve instead of failing.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	private $legacyActionHooks = [ 'aioseo_blc_connection_reminder_second' ];

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		add_action( $this->actionHook, [ $this, 'sendReminderEmail' ] );

		foreach ( $this->legacyActionHooks as $legacyActionHook ) {
			add_action( $legacyActionHook, [ $this, 'sendReminderEmail' ] );
		}

		add_action( 'init', [ $this, 'maybeScheduleReminder' ] );
	}

	/**
	 * Returns the reminder sequence, ordered by how long after the first activation each one is due.
	 *
	 * NOTE: The option keys are slots that record what a site has been sent, so each one stays
	 * bound to its own content even when the order changes. 'connectReminderSecond' therefore
	 * sends third — renaming it would re-send the offer email to sites that already had it.
	 *
	 * @since 1.3.1
	 *
	 * @return array[] The steps.
	 */
	private function getSteps() {
		$siteName = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : site_url();

		return [
			[
				'option'    => 'connectReminder',
				'days'      => 7,
				'view'      => 'ConnectReminder',
				'subject'   => sprintf(
					// Translators: 1 - The site name.
					__( 'Warning: Broken Link Checker has not been connected on %1$s', 'broken-link-checker-seo' ),
					$siteName
				),
				'preheader' => __( 'Your free account is still waiting to be connected.', 'broken-link-checker-seo' )
			],
			[
				'option'    => 'connectReminderThird',
				'days'      => 14,
				'view'      => 'ConnectReminderThird',
				'subject'   => sprintf(
					// Translators: 1 - The site name.
					__( 'None of the links on %1$s are being checked', 'broken-link-checker-seo' ),
					$siteName
				),
				'preheader' => __( 'A broken link looks exactly like a working one until someone clicks it.', 'broken-link-checker-seo' )
			],
			[
				'option'    => 'connectReminderSecond',
				'days'      => 30,
				'view'      => 'ConnectReminderSecond',
				'subject'   => __( 'Avoid losing traffic from broken links — connect to Broken Link Checker', 'broken-link-checker-seo' ),
				'preheader' => __( 'Plus a coupon for your first month on us.', 'broken-link-checker-seo' )
			],
			[
				'option'    => 'connectReminderFourth',
				'days'      => 60,
				'view'      => 'ConnectReminderFourth',
				'subject'   => __( 'Broken links are costing you traffic — here\'s how to find them', 'broken-link-checker-seo' ),
				'preheader' => __( 'Two months in and not a single link has been checked yet.', 'broken-link-checker-seo' )
			],
			[
				'option'    => 'connectReminderFifth',
				'days'      => 90,
				'view'      => 'ConnectReminderFifth',
				'subject'   => __( 'Last reminder about Broken Link Checker', 'broken-link-checker-seo' ),
				'preheader' => __( 'The last email you\'ll get from us about this.', 'broken-link-checker-seo' )
			]
		];
	}

	/**
	 * Schedules the next due reminder.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function maybeScheduleReminder() {
		if ( ! $this->shouldSend() ) {
			return;
		}

		if ( null === $this->getDueStepIndex() ) {
			return;
		}

		if ( aioseoBrokenLinkChecker()->actionScheduler->isScheduled( $this->actionHook ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->actionScheduler->scheduleSingle(
			$this->actionHook,
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Sends the next due reminder.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function sendReminderEmail() {
		if ( ! $this->shouldSend() ) {
			return;
		}

		$dueIndex = $this->getDueStepIndex();
		if ( null === $dueIndex ) {
			return;
		}

		$steps = $this->getSteps();

		// Mark every step up to this one as sent. A site whose cron stalled for months is due
		// several at once and should only ever receive the most recent one.
		for ( $index = 0; $index <= $dueIndex; $index++ ) {
			aioseoBrokenLinkChecker()->internalOptions->internal->emails->{$steps[ $index ]['option']} = time();
		}

		$this->send( $steps[ $dueIndex ] );
	}

	/**
	 * Whether the sequence should still run.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the sequence should still run.
	 */
	private function shouldSend() {
		$emailDisabled = aioseoBrokenLinkChecker()->internalOptions->internal->emails->emailDisabled;
		if ( ! empty( $emailDisabled ) ) {
			return false;
		}

		// Also check for a stored key, so an expired or invalid license still ends the sequence.
		if (
			aioseoBrokenLinkChecker()->license->isActive() ||
			aioseoBrokenLinkChecker()->sensitiveOptions->hasValue( 'licenseKey' )
		) {
			$this->markSequenceComplete();

			return false;
		}

		return true;
	}

	/**
	 * Marks every step as sent so the sequence never resumes.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	private function markSequenceComplete() {
		$emailState = aioseoBrokenLinkChecker()->internalOptions->internal->emails->all();

		foreach ( $this->getSteps() as $step ) {
			if ( ! empty( $emailState[ $step['option'] ] ) ) {
				continue;
			}

			aioseoBrokenLinkChecker()->internalOptions->internal->emails->{$step['option']} = time();
		}
	}

	/**
	 * Returns the index of the most recent unsent step that is due.
	 *
	 * @since 1.3.1
	 *
	 * @return int|null The step index, or null when nothing is due.
	 */
	private function getDueStepIndex() {
		$firstActivated = aioseoBrokenLinkChecker()->internalOptions->internal->firstActivated;
		if ( ! $firstActivated ) {
			return null;
		}

		// Read as an array: empty() on a chained option resolves through __isset(), which resets the
		// group state before __get() runs and so always reports the option as empty.
		$emailState = aioseoBrokenLinkChecker()->internalOptions->internal->emails->all();

		$dueIndex = null;
		foreach ( $this->getSteps() as $index => $step ) {
			if ( ! empty( $emailState[ $step['option'] ] ) ) {
				continue;
			}

			if ( time() < ( $firstActivated + ( $step['days'] * DAY_IN_SECONDS ) ) ) {
				break;
			}

			$dueIndex = $index;
		}

		return $dueIndex;
	}

	/**
	 * Returns the addresses the reminders go to.
	 *
	 * @since 1.3.1
	 *
	 * @return string[] The addresses.
	 */
	private function getRecipients() {
		$candidates = [ get_option( 'admin_email' ) ];

		$activatingUserId = aioseoBrokenLinkChecker()->internalOptions->internal->activatingUserId;
		if ( $activatingUserId ) {
			$activatingUser = get_userdata( $activatingUserId );
			if ( $activatingUser ) {
				$candidates[] = $activatingUser->user_email;
			}
		}

		$recipients = [];
		foreach ( $candidates as $candidate ) {
			if ( ! $candidate || ! is_email( $candidate ) ) {
				continue;
			}

			// Keep the original casing but don't mail the same person twice.
			$recipients[ strtolower( $candidate ) ] = $candidate;
		}

		return array_values( $recipients );
	}

	/**
	 * Sends a step to every recipient.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Renders through {@see \AIOSEO\BrokenLinkChecker\Emails\Emails::render()}.
	 *
	 * @param  array $step The step.
	 * @return void
	 */
	private function send( $step ) {
		foreach ( $this->getRecipients() as $recipientEmail ) {
			// Both the greeting and the unsubscribe token are per recipient, so each address
			// gets its own render instead of one mail with several addresses on it.
			$message = aioseoBrokenLinkChecker()->emails->render(
				$step['view'],
				$step['preheader'],
				$recipientEmail,
				Emails::TYPE_REMINDER
			);

			if ( ! $message ) {
				continue;
			}

			wp_mail(
				$recipientEmail,
				$step['subject'],
				$message,
				aioseoBrokenLinkChecker()->emails->getHeaders()
			);
		}
	}
}