<?php
namespace AIOSEO\BrokenLinkChecker\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Registers our Site Health tests.
 *
 * @since 1.3.1
 */
class SiteHealth {
	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		add_filter( 'site_status_tests', [ $this, 'registerTests' ] );
	}

	/**
	 * Registers the broken links test.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $tests The registered tests.
	 * @return array        The registered tests.
	 */
	public function registerTests( $tests ) {
		$tests['direct']['aioseo_blc_broken_links'] = [
			'label' => __( 'Broken link monitoring', 'broken-link-checker-seo' ),
			'test'  => [ $this, 'runBrokenLinksTest' ]
		];

		return $tests;
	}

	/**
	 * Reports whether links are being monitored and, once they are, whether any are broken.
	 *
	 * @since 1.3.1
	 *
	 * @return array The test result.
	 */
	public function runBrokenLinksTest() {
		$result = [
			'label'       => __( 'Your links are being monitored for breakage', 'broken-link-checker-seo' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'SEO', 'broken-link-checker-seo' ),
				'color' => 'blue'
			],
			'description' => '',
			'actions'     => '',
			'test'        => 'aioseo_blc_broken_links'
		];

		// Lapsed is checked first: it's a site that was protected and silently stopped being so,
		// which is a different (and worse) problem than never having connected.
		if ( aioseoBrokenLinkChecker()->license->isLapsed() ) {
			return array_merge( $result, $this->getLapsedResult() );
		}

		if ( ! aioseoBrokenLinkChecker()->license->isConnected() ) {
			return array_merge( $result, $this->getNotConnectedResult() );
		}

		return array_merge( $result, $this->getBrokenLinksResult() );
	}

	/**
	 * Returns the result for a site whose license has lapsed.
	 *
	 * @since 1.3.1
	 *
	 * @return array The partial test result.
	 */
	private function getLapsedResult() {
		$totalLinks = $this->getTotalLinks();
		$expires    = (int) aioseoBrokenLinkChecker()->internalOptions->internal->license->expires;

		if ( aioseoBrokenLinkChecker()->license->isExpired() && $expires ) {
			$reason = sprintf(
				// Translators: 1 - The expiration date.
				__( 'Your license expired on %1$s.', 'broken-link-checker-seo' ),
				esc_html( date_i18n( get_option( 'date_format' ), $expires ) )
			);
		} elseif ( aioseoBrokenLinkChecker()->license->isExpired() ) {
			$reason = __( 'Your license has expired.', 'broken-link-checker-seo' );
		} else {
			$reason = __( 'There is a problem with your license.', 'broken-link-checker-seo' );
		}

		$consequence = 0 < $totalLinks
			? sprintf(
				// Translators: 1 - The plugin name ("Broken Link Checker"), 2 - The number of links.
				_n(
					'%1$s is no longer checking the %2$s link on your site, so a link that breaks from now on will go unnoticed.',
					'%1$s is no longer checking the %2$s links on your site, so links that break from now on will go unnoticed.',
					$totalLinks,
					'broken-link-checker-seo'
				),
				esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME ),
				esc_html( number_format_i18n( $totalLinks ) )
			)
			: sprintf(
				// Translators: 1 - The plugin name ("Broken Link Checker").
				__( '%1$s is no longer checking your links, so links that break from now on will go unnoticed.', 'broken-link-checker-seo' ),
				esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME )
			);

		return [
			'label'       => __( 'Broken link monitoring has stopped', 'broken-link-checker-seo' ),
			'status'      => 'critical',
			'badge'       => [
				'label' => __( 'SEO', 'broken-link-checker-seo' ),
				'color' => 'red'
			],
			'description' => '<p>' . $reason . ' ' . $consequence . '</p>',
			'actions'     => $this->getLapsedAction()
		];
	}

	/**
	 * Returns the action link for a lapsed license.
	 *
	 * Renewing fixes an expiry but does nothing for an invalid or disabled key, so those point at
	 * the settings screen where the key can be re-entered or re-validated.
	 *
	 * @since 1.3.1
	 *
	 * @return string The action markup.
	 */
	private function getLapsedAction() {
		if ( ! aioseoBrokenLinkChecker()->license->isExpired() ) {
			return sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=broken-link-checker#/settings' ) ),
				esc_html__( 'Check your license', 'broken-link-checker-seo' )
			);
		}

		return sprintf(
			'<p><a href="%1$s" target="_blank">%2$s</a></p>',
			esc_url(
				aioseoBrokenLinkChecker()->helpers->utmUrl(
					AIOSEO_BROKEN_LINK_CHECKER_MARKETING_URL . 'account/',
					'site-health',
					'renew-license'
				)
			),
			esc_html__( 'Renew your license', 'broken-link-checker-seo' )
		);
	}

	/**
	 * Returns the result for a site that hasn't connected its account.
	 *
	 * @since 1.3.1
	 *
	 * @return array The partial test result.
	 */
	private function getNotConnectedResult() {
		$uncheckedLinks = $this->getTotalLinks();

		$description = 0 < $uncheckedLinks
			? sprintf(
				// Translators: 1 - The plugin name ("Broken Link Checker"), 2 - The number of links.
				_n(
					'%1$s has found %2$s link on your site, but it is not being checked. Link checking runs through our external service, which needs a connected account.',
					'%1$s has found %2$s links on your site, but none of them are being checked. Link checking runs through our external service, which needs a connected account.',
					$uncheckedLinks,
					'broken-link-checker-seo'
				),
				esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME ),
				esc_html( number_format_i18n( $uncheckedLinks ) )
			)
			: sprintf(
				// Translators: 1 - The plugin name ("Broken Link Checker").
				__( '%1$s is installed but not connected, so your links are not being checked for breakage. Link checking runs through our external service, which needs a connected account.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME )
			);

		return [
			'label'       => __( 'Your links are not being checked for breakage', 'broken-link-checker-seo' ),
			'status'      => 'recommended',
			'badge'       => [
				'label' => __( 'SEO', 'broken-link-checker-seo' ),
				'color' => 'orange'
			],
			'description' => '<p>' . $description . '</p>',
			'actions'     => sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=broken-link-checker#/settings' ) ),
				esc_html__( 'Connect your account', 'broken-link-checker-seo' )
			)
		];
	}

	/**
	 * Returns the result for a connected site.
	 *
	 * @since 1.3.1
	 *
	 * @return array The partial test result.
	 */
	private function getBrokenLinksResult() {
		$brokenLinks = $this->getBrokenLinkCount();
		if ( ! $brokenLinks ) {
			return [
				'description' => '<p>' . sprintf(
					// Translators: 1 - The plugin name ("Broken Link Checker").
					__( '%1$s is connected and did not find any broken links on your site.', 'broken-link-checker-seo' ),
					esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME )
				) . '</p>'
			];
		}

		return [
			'label'       => sprintf(
				// Translators: 1 - The number of broken links.
				_n( '%1$s broken link was found on your site', '%1$s broken links were found on your site', $brokenLinks, 'broken-link-checker-seo' ),
				esc_html( number_format_i18n( $brokenLinks ) )
			),
			'status'      => 'recommended',
			'badge'       => [
				'label' => __( 'SEO', 'broken-link-checker-seo' ),
				'color' => 'orange'
			],
			'description' => '<p>' . sprintf(
				// Translators: 1 - The number of broken links.
				_n(
					'%1$s link on your site points at a page that could not be reached. Visitors who click it hit a dead end, and search engines read it as a sign the page is not being maintained.',
					'%1$s links on your site point at pages that could not be reached. Visitors who click them hit a dead end, and search engines read this as neglect.',
					$brokenLinks,
					'broken-link-checker-seo'
				),
				esc_html( number_format_i18n( $brokenLinks ) )
			) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=broken-link-checker#/broken-links' ) ),
				esc_html__( 'Review your broken links', 'broken-link-checker-seo' )
			)
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
		if ( empty( aioseoBrokenLinkChecker()->main->linkStatus->data ) ) {
			return 0;
		}

		return (int) aioseoBrokenLinkChecker()->main->linkStatus->data->getTotalLinks();
	}

	/**
	 * Returns the number of broken links found on the site.
	 *
	 * @since 1.3.1
	 *
	 * @return int The number of broken links.
	 */
	private function getBrokenLinkCount() {
		// The report's own count, so this can't disagree with the screen it sends people to. Counting the
		// statuses alone includes ones whose every occurrence is gone, excluded or in a disabled source.
		return (int) Models\LinkStatus::rowCountQuery( 'broken' );
	}
}