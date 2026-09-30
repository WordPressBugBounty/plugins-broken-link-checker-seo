<?php
namespace AIOSEO\BrokenLinkChecker\Admin\Notices;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Not Connected notice.
 *
 * @since 1.2.1
 */
class NotConnected {
	/**
	 * Class constructor.
	 *
	 * @since 1.2.1
	 */
	public function __construct() {
		add_action( 'wp_ajax_aioseo-blc-dismiss-not-connected', [ $this, 'dismissNotice' ] );
	}

	/**
	 * Go through all the checks to see if we should show the notice.
	 *
	 * @since 1.2.1
	 *
	 * @return void
	 */
	public function maybeShowNotice() {
		// The settings capability, not the page one: connecting is an administrator's action, so asking
		// anyone else to do it is noise they have no way to act on.
		if ( ! current_user_can( 'aioseo_blc_settings' ) ) {
			return;
		}

		if ( aioseoBrokenLinkChecker()->admin->isBlcScreen() ) {
			return;
		}

		// Make sure the user is not connected/licensed.
		if ( aioseoBrokenLinkChecker()->license->isActive() ) {
			return;
		}

		$dismissed = get_user_meta( get_current_user_id(), '_aioseo_blc_not_connected', true );
		if ( ! empty( $dismissed ) && $dismissed > time() ) {
			return;
		}

		$this->showNotice();

		add_action( 'admin_footer', [ $this, 'printScript' ] );
	}

	/**
	 * Actually show the review plugin 2.0.
	 *
	 * @since 1.2.1
	 *
	 * @return void
	 */
	public function showNotice() {
		// A site that connected once and then lapsed needs renewing, not connecting.
		if ( aioseoBrokenLinkChecker()->license->isLapsed() ) {
			$this->printNotice( 'notice-error', $this->getLapsedMessage() );

			return;
		}

		$openingTag     = '<a href="' . esc_url( admin_url( 'admin.php?page=broken-link-checker#/settings' ) ) . '">';
		$uncheckedLinks = $this->getUncheckedLinkCount();

		// The first week gets the generic error notice. After that the same red bar every week
		// just trains people to dismiss it, so switch to the site's own numbers instead.
		if ( ! $this->isPastFirstWeek() || ! $uncheckedLinks ) {
			$type   = 'notice-error';
			$string = sprintf(
				// Translators: 1 - The plugin name ("Broken Link Checker"), 2 - Opening HTML link tag, 3 - Closing HTML link tag.
				__( 'Your site is not connected with %1$s. %2$sConnect now%3$s to start scanning for broken links and fix them to improve your SEO.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'<strong>' . esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME ) . '</strong>',
				$openingTag,
				'</a>'
			);
		} else {
			$type   = 'notice-warning';
			$string = sprintf(
				// Translators: 1 - The number of unchecked links, 2 - The plugin name ("Broken Link Checker"), 3 - Opening HTML link tag, 4 - Closing HTML link tag.
				_n(
					'%1$s link on your site has never been checked for being broken. %3$sConnect %2$s%4$s to start checking it.',
					'%1$s links on your site have never been checked for being broken. %3$sConnect %2$s%4$s to start checking them.',
					$uncheckedLinks,
					'broken-link-checker-seo'
				),
				'<strong>' . esc_html( number_format_i18n( $uncheckedLinks ) ) . '</strong>',
				esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME ),
				$openingTag,
				'</a>'
			);
		}

		$this->printNotice( $type, $string );
	}

	/**
	 * Outputs the notice markup.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $type    The notice type class.
	 * @param  string $message The message.
	 * @return void
	 */
	private function printNotice( $type, $message ) {
		?>
		<div class="notice <?php echo esc_attr( $type ); ?> aioseo-blc-not-connected is-dismissible">
			<div class="step-3">
				<p><?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Returns the message for a site whose license has lapsed.
	 *
	 * @since 1.3.1
	 *
	 * @return string The message.
	 */
	private function getLapsedMessage() {
		$pluginName = '<strong>' . esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME ) . '</strong>';
		$links      = $this->getLinksNoLongerCheckedSentence();

		// An expired license is fixed by renewing. A disabled or invalid one isn't, and sending
		// those users to checkout just means they pay and still end up in support.
		if ( ! aioseoBrokenLinkChecker()->license->isExpired() ) {
			return sprintf(
				// Translators: 1 - The plugin name, 2 - A sentence about links no longer being checked, 3 - Opening link tag, 4 - Closing link tag, 5 - Opening link tag, 6 - Closing link tag.
				__( 'There\'s a problem with your %1$s license. %2$s %3$sCheck your license%4$s or %5$scontact support%6$s.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				$pluginName,
				$links,
				'<a href="' . esc_url( admin_url( 'admin.php?page=broken-link-checker#/settings' ) ) . '">',
				'</a>',
				'<a href="' . esc_url( $this->getSupportUrl() ) . '" target="_blank">',
				'</a>'
			);
		}

		$expires = (int) aioseoBrokenLinkChecker()->internalOptions->internal->license->expires;
		if ( ! $expires ) {
			return sprintf(
				// Translators: 1 - The plugin name, 2 - A sentence about links no longer being checked, 3 - Opening link tag, 4 - Closing link tag.
				__( 'Your %1$s license has expired. %2$s %3$sRenew your license%4$s to resume monitoring.', 'broken-link-checker-seo' ),
				$pluginName,
				$links,
				'<a href="' . esc_url( $this->getRenewUrl() ) . '" target="_blank">',
				'</a>'
			);
		}

		return sprintf(
			// Translators: 1 - The plugin name, 2 - The expiration date, 3 - A sentence about links no longer being checked, 4 - Opening link tag, 5 - Closing link tag.
			__( 'Your %1$s license expired on %2$s. %3$s %4$sRenew your license%5$s to resume monitoring.', 'broken-link-checker-seo' ),
			$pluginName,
			'<strong>' . esc_html( date_i18n( get_option( 'date_format' ), $expires ) ) . '</strong>',
			$links,
			'<a href="' . esc_url( $this->getRenewUrl() ) . '" target="_blank">',
			'</a>'
		);
	}

	/**
	 * Returns the sentence stating that the site's links are no longer being checked.
	 *
	 * @since 1.3.1
	 *
	 * @return string The sentence.
	 */
	private function getLinksNoLongerCheckedSentence() {
		$links = $this->getUncheckedLinkCount();
		if ( ! $links ) {
			return __( 'Your links are no longer being checked.', 'broken-link-checker-seo' );
		}

		return sprintf(
			// Translators: 1 - The number of links on the site.
			_n(
				'The %1$s link on your site is no longer being checked.',
				'The %1$s links on your site are no longer being checked.',
				$links,
				'broken-link-checker-seo'
			),
			'<strong>' . esc_html( number_format_i18n( $links ) ) . '</strong>'
		);
	}

	/**
	 * Returns the URL for renewing a license.
	 *
	 * @since 1.3.1
	 *
	 * @return string The URL.
	 */
	private function getRenewUrl() {
		return aioseoBrokenLinkChecker()->helpers->utmUrl(
			AIOSEO_BROKEN_LINK_CHECKER_MARKETING_URL . 'account/',
			'license-lapsed-notice',
			'renew-license'
		);
	}

	/**
	 * Returns the URL for our support.
	 *
	 * @since 1.3.1
	 *
	 * @return string The URL.
	 */
	private function getSupportUrl() {
		return aioseoBrokenLinkChecker()->helpers->utmUrl(
			AIOSEO_BROKEN_LINK_CHECKER_MARKETING_URL . 'plugin/blc-support',
			'license-lapsed-notice',
			'contact-support'
		);
	}

	/**
	 * Whether it's been more than a week since the plugin was first activated.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether it's been more than a week.
	 */
	private function isPastFirstWeek() {
		$firstActivated = aioseoBrokenLinkChecker()->internalOptions->internal->firstActivated;

		return ! empty( $firstActivated ) && time() > ( $firstActivated + WEEK_IN_SECONDS );
	}

	/**
	 * Returns the number of links that have never been checked.
	 *
	 * Link discovery runs locally, so this is known even though nothing has been checked yet.
	 *
	 * @since 1.3.1
	 *
	 * @return int The number of links.
	 */
	private function getUncheckedLinkCount() {
		return aioseoBrokenLinkChecker()->helpers->getCachedTotalLinks();
	}

	/**
	 * Dismiss the notice.
	 *
	 * @since 1.2.1
	 *
	 * @return void
	 */
	public function dismissNotice() {
		if ( ! isset( $_POST['action'] ) || 'aioseo-blc-dismiss-not-connected' !== $_POST['action'] ) {
			return;
		}

		// Whoever the notice is shown to is who may dismiss it.
		if ( ! current_user_can( 'aioseo_blc_settings' ) ) {
			wp_send_json_error();
		}

		check_ajax_referer( 'aioseo-blc-dismiss-not-connected', 'nonce' );
		update_user_meta( get_current_user_id(), '_aioseo_blc_not_connected', strtotime( '+1 week' ) );

		wp_send_json_success();
	}

	/**
	 * Print the script for dismissing the notice.
	 *
	 * @since 1.2.1
	 *
	 * @return void
	 */
	public function printScript() {
		// Create a nonce.
		$nonce = wp_create_nonce( 'aioseo-blc-dismiss-not-connected' );
		?>
		<script>
			// Delegated from the document: core injects the dismiss button after this markup, so
			// binding to it directly depends on load order we don't control — and if 'load' has
			// already fired by the time this runs, the listener is never attached at all.
			document.addEventListener('click', function (event) {
				if (!event.target.closest || !event.target.closest('.aioseo-blc-not-connected .notice-dismiss')) {
					return
				}

				var httpRequest = new XMLHttpRequest(),
					postData    = ''

				postData += 'action=aioseo-blc-dismiss-not-connected'
				postData += '&nonce=<?php echo esc_html( $nonce ); ?>'

				httpRequest.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>')
				httpRequest.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded')
				httpRequest.send(postData)
			});
		</script>
		<?php
	}
}