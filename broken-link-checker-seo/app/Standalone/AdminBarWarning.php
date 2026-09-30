<?php
namespace AIOSEO\BrokenLinkChecker\Standalone;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the admin bar warning shown while links aren't being checked.
 *
 * NOTE: The admin bar is the only surface that reaches a site owner on the front end, where they
 * spend most of their time, and it can't be dismissed into silence like the notices can.
 *
 * @since 1.3.1
 */
class AdminBarWarning {
	/**
	 * The admin bar node ID.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const NODE_ID = 'aioseo-blc-admin-bar-warning';

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Initializes the standalone.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function init() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( ! $this->shouldShow() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScript' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueScript' ] );

		add_action( 'admin_bar_menu', [ $this, 'addAdminBarElement' ], 99999 );
	}

	/**
	 * Whether the warning should be shown.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the warning should be shown.
	 */
	public function shouldShow() {
		// The settings capability, not the page one: the warning's only action is to connect an account,
		// which is an administrator's to take.
		if ( ! current_user_can( 'aioseo_blc_settings' ) ) {
			return false;
		}

		return ! aioseoBrokenLinkChecker()->license->isActive();
	}

	/**
	 * Enqueues the script.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function enqueueScript() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		aioseoBrokenLinkChecker()->core->assets->load(
			'src/vue/standalone/admin-bar-warning/main.js',
			[],
			$this->getData(),
			'aioseoBlcAdminBarWarning'
		);
	}

	/**
	 * Adds the admin bar element.
	 *
	 * NOTE: The title doubles as the pre-mount fallback, so it must stand on its own for the
	 * moment before the Vue app replaces the node's contents.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_Admin_Bar $wpAdminBar The admin bar object.
	 * @return void
	 */
	public function addAdminBarElement( $wpAdminBar ) {
		$data = $this->getData();

		$wpAdminBar->add_node(
			[
				'id'    => self::NODE_ID,
				'title' => $data['title'],
				'href'  => $data['url']
			]
		);
	}

	/**
	 * Returns the strings and URL for the current state.
	 *
	 * @since 1.3.1
	 *
	 * @return array The data.
	 */
	private function getData() {
		return aioseoBrokenLinkChecker()->license->isLapsed()
			? $this->getLapsedData()
			: $this->getNotConnectedData();
	}

	/**
	 * Returns the data for a site that has never connected.
	 *
	 * @since 1.3.1
	 *
	 * @return array The data.
	 */
	private function getNotConnectedData() {
		$totalLinks = $this->getTotalLinks();

		if ( ! $totalLinks ) {
			// Never "0 links unchecked" — a fresh install simply hasn't scanned anything yet.
			$title = __( 'Link checking off', 'broken-link-checker-seo' );
			$body  = sprintf(
				// Translators: 1 - The plugin name ("Broken Link Checker").
				__( '%1$s isn\'t checking your links. Link checking runs through our external service, which needs a connected account.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME )
			);
		} else {
			$title = sprintf(
				// Translators: 1 - The number of links on the site.
				_n( '%1$s link unchecked', '%1$s links unchecked', $totalLinks, 'broken-link-checker-seo' ),
				esc_html( number_format_i18n( $totalLinks ) )
			);
			$body = sprintf(
				// Translators: 1 - The plugin name ("Broken Link Checker"), 2 - The number of links.
				_n(
					'%1$s has found %2$s link on your site but isn\'t checking it. Link checking runs through our external service, which needs a connected account.',
					'%1$s has found %2$s links on your site but isn\'t checking any of them. Link checking runs through our external service, which needs a connected account.',
					$totalLinks,
					'broken-link-checker-seo'
				),
				esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME ),
				esc_html( number_format_i18n( $totalLinks ) )
			);
		}

		return [
			'title'    => $title,
			'body'     => $body,
			'linkText' => __( 'Connect your account', 'broken-link-checker-seo' ),
			'url'      => admin_url( 'admin.php?page=broken-link-checker#/settings' ),
			'external' => false
		];
	}

	/**
	 * Returns the data for a site whose license has lapsed.
	 *
	 * @since 1.3.1
	 *
	 * @return array The data.
	 */
	private function getLapsedData() {
		$totalLinks = $this->getTotalLinks();
		$expires    = (int) aioseoBrokenLinkChecker()->internalOptions->internal->license->expires;
		$isExpired  = aioseoBrokenLinkChecker()->license->isExpired();

		if ( $isExpired && $expires ) {
			$reason = sprintf(
				// Translators: 1 - The plugin name ("Broken Link Checker"), 2 - The expiration date.
				__( 'Your %1$s license expired on %2$s.', 'broken-link-checker-seo' ),
				esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME ),
				esc_html( date_i18n( get_option( 'date_format' ), $expires ) )
			);
		} elseif ( $isExpired ) {
			$reason = sprintf(
				// Translators: 1 - The plugin name ("Broken Link Checker").
				__( 'Your %1$s license has expired.', 'broken-link-checker-seo' ),
				esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME )
			);
		} else {
			$reason = sprintf(
				// Translators: 1 - The plugin name ("Broken Link Checker").
				__( 'There\'s a problem with your %1$s license.', 'broken-link-checker-seo' ),
				esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME )
			);
		}

		$consequence = 0 < $totalLinks
			? sprintf(
				// Translators: 1 - The number of links on the site.
				_n(
					'The %1$s link on your site is no longer being checked.',
					'The %1$s links on your site are no longer being checked.',
					$totalLinks,
					'broken-link-checker-seo'
				),
				esc_html( number_format_i18n( $totalLinks ) )
			)
			: __( 'Your links are no longer being checked.', 'broken-link-checker-seo' );

		// Renewing fixes an expiry; it does nothing for an invalid or disabled key, so those go to
		// the settings screen where the key can be re-entered instead.
		return [
			'title'    => __( 'Link monitoring stopped', 'broken-link-checker-seo' ),
			'body'     => $reason . ' ' . $consequence,
			'linkText' => $isExpired
				? __( 'Renew your license', 'broken-link-checker-seo' )
				: __( 'Check your license', 'broken-link-checker-seo' ),
			'url'      => $isExpired
				? aioseoBrokenLinkChecker()->helpers->utmUrl( AIOSEO_BROKEN_LINK_CHECKER_MARKETING_URL . 'account/', 'admin-bar', 'renew-license' )
				: admin_url( 'admin.php?page=broken-link-checker#/settings' ),
			'external' => (bool) $isExpired
		];
	}

	/**
	 * Returns the number of links found on the site.
	 *
	 * @since 1.3.1
	 *
	 * @return int The number of links.
	 */
	private function getTotalLinks() {
		return aioseoBrokenLinkChecker()->helpers->getCachedTotalLinks();
	}
}