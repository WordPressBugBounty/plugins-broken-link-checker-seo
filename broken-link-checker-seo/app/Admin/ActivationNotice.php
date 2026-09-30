<?php
namespace AIOSEO\BrokenLinkChecker\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells the administrators who did not install the plugin that it is running, and offers them the reports.
 *
 * NOTE: Report emails are addressed to the activating user alone, so without this the rest of the team
 * would have no way of knowing the site is being monitored at all.
 *
 * @since 1.3.1
 */
class ActivationNotice {
	/**
	 * The user meta key recording that a user has dismissed the notice.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const DISMISSED_META_KEY = 'aioseo_blc_activation_notice_dismissed';

	/**
	 * The action the notice's own controls post back to.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const ACTION = 'aioseo_blc_activation_notice';

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		add_action( 'admin_notices', [ $this, 'maybeShow' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * Whether the current user should be shown the notice.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Requires the settings capability.
	 *
	 * @return bool Whether to show it.
	 */
	private function shouldShow() {
		// Everything the notice offers is a settings change — who receives the reports — so it goes to
		// whoever may make one. Editors and Authors keep the report itself.
		if ( ! current_user_can( 'aioseo_blc_settings' ) ) {
			return false;
		}

		$userId = get_current_user_id();
		if ( ! $userId ) {
			return false;
		}

		// The person who set it up already knows, and is already receiving the reports.
		if ( (int) aioseoBrokenLinkChecker()->internalOptions->internal->activatingUserId === $userId ) {
			return false;
		}

		if ( get_user_meta( $userId, self::DISMISSED_META_KEY, true ) ) {
			return false;
		}

		// Active, not merely connected: a lapsed site already shows a notice saying checking has stopped,
		// and claiming to monitor the site directly underneath it would contradict it.
		return aioseoBrokenLinkChecker()->license->isActive();
	}

	/**
	 * Prints the notice.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function maybeShow() {
		if ( ! $this->shouldShow() ) {
			return;
		}

		$settingsUrl = admin_url( 'admin.php?page=broken-link-checker#/settings' );
		?>
		<style>
			/*
			 * The dismiss control is positioned against its notice, and core only makes a notice the
			 * offset parent for the `is-dismissible` class — which cannot be used here, because core's
			 * script appends a second dismiss button to those and that one only hides the notice for the
			 * rest of the page load instead of recording it.
			 */
			.aioseo-blc-activation-notice { position: relative; padding-right: 38px; }
			/*
			 * Core styles this control as a button, so it never had to say this; ours is a link, and the
			 * admin underlines those - which draws a line under the dashicon cross.
			 */
			.aioseo-blc-activation-notice .notice-dismiss,
			.aioseo-blc-activation-notice .notice-dismiss:hover,
			.aioseo-blc-activation-notice .notice-dismiss:focus { text-decoration: none; }
		</style>
		<div class="notice notice-info aioseo-blc-activation-notice">
			<p><strong>
				<?php
					// Translators: 1 - The plugin name ("Broken Link Checker").
					echo esc_html( sprintf( __( '%1$s is now monitoring this site for broken links.', 'broken-link-checker-seo' ), AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME ) );
				?>
			</strong></p>

			<p>
				<?php
					printf(
						// Translators: 1 - Opening HTML link tag, 2 - Closing HTML link tag.
						esc_html__( 'Reports go to the person who set it up. You can have them too, or change who receives them in %1$sBroken Link Checker settings%2$s.', 'broken-link-checker-seo' ),
						'<a href="' . esc_url( $settingsUrl ) . '">',
						'</a>'
					);
				?>
			</p>

			<p>
				<a
					href="<?php echo esc_url( $this->actionUrl( 'subscribe' ) ); ?>"
					class="button button-primary"
				><?php esc_html_e( 'Email these reports to me', 'broken-link-checker-seo' ); ?></a>

				<a
					href="<?php echo esc_url( $this->actionUrl( 'dismiss' ) ); ?>"
					class="button"
				><?php esc_html_e( 'No thanks', 'broken-link-checker-seo' ); ?></a>
			</p>

			<a
				href="<?php echo esc_url( $this->actionUrl( 'dismiss' ) ); ?>"
				class="notice-dismiss"
			><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'broken-link-checker-seo' ); ?></span></a>
		</div>
		<?php
	}

	/**
	 * Returns a nonced URL for one of the notice's controls.
	 *
	 * NOTE: A link rather than an AJAX call, so dismissing survives a page load with no JavaScript on
	 * screens that do not load ours.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $type The control.
	 * @return string       The URL.
	 */
	private function actionUrl( $type ) {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => self::ACTION,
					'blc_type' => $type,
					'redirect' => rawurlencode( $this->currentPath() )
				],
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	/**
	 * The screen to return to once the notice has been acted on.
	 *
	 * NOTE: Passed on as the root-relative path, which wp_safe_redirect() resolves against this site.
	 * Re-rooting it under admin_url() put the subdirectory back in twice and returned a 404.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Returns the request path rather than an absolute URL; renamed from currentUrl().
	 *
	 * @return string The path.
	 */
	private function currentPath() {
		$requestUri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return '/' === substr( $requestUri, 0, 1 ) ? $requestUri : '';
	}

	/**
	 * Acts on the notice, then returns the user to where they were.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function handle() {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'aioseo_blc_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'broken-link-checker-seo' ), '', [ 'response' => 403 ] );
		}

		$userId = get_current_user_id();
		$type   = isset( $_GET['blc_type'] ) ? sanitize_key( wp_unslash( $_GET['blc_type'] ) ) : '';

		if ( 'subscribe' === $type ) {
			$this->subscribe( $userId );
		}

		// Both controls settle the notice for good, so it does not come back on the next load.
		update_user_meta( $userId, self::DISMISSED_META_KEY, true );

		$redirect = isset( $_GET['redirect'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['redirect'] ) ) ) : '';

		wp_safe_redirect( $redirect ? $redirect : admin_url() );
		exit;
	}

	/**
	 * Adds the given user to the report recipients.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $userId The user.
	 * @return void
	 */
	private function subscribe( $userId ) {
		$user = get_userdata( $userId );
		if ( ! is_a( $user, 'WP_User' ) || ! $user->user_email ) {
			return;
		}

		// Read once: a chained read resolves against the previous group and comes back null.
		$options    = aioseoBrokenLinkChecker()->options->noConflict();
		$recipients = (array) $options->general->emailReports->recipients;

		$recipients[] = $user->user_email;

		$options->general->emailReports->recipients = aioseoBrokenLinkChecker()->options->sanitizeRecipients( $recipients );
		$options->general->emailReports->enable     = true;

		$options->save( true );
	}
}