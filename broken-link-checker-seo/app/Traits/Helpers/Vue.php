<?php
namespace AIOSEO\BrokenLinkChecker\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Generates the data we need for Vue.
 *
 * @since 1.0.0
 */
trait Vue {
	/**
	 * The data to pass to Vue.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $vueData = [];

	/**
	 * Returns the data for Vue.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $currentPage The current page.
	 * @return array               The data.
	 */
	public function getVueData( $currentPage = null ) {
		global $wp_version; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		static $showNotificationsDrawer = null;
		if ( null === $showNotificationsDrawer ) {
			$showNotificationsDrawer = aioseoBrokenLinkChecker()->core->cache->get( 'show_notifications_drawer' ) ? true : false;

			// IF this is set to true, let's disable it now so it doesn't pop up again.
			if ( $showNotificationsDrawer ) {
				aioseoBrokenLinkChecker()->core->cache->delete( 'show_notifications_drawer' );
			}
		}

		$this->vueData = [
			// The following data is needed on all screens.
			'wpVersion'           => $wp_version, // phpcs:ignore Squiz.NamingConventions.ValidVariableName
			'page'                => $currentPage,
			'screen'              => aioseoBrokenLinkChecker()->helpers->getCurrentScreen(),
			'internalOptions'     => aioseoBrokenLinkChecker()->internalOptions->all(),
			'options'             => aioseoBrokenLinkChecker()->options->all(),
			'settings'            => aioseoBrokenLinkChecker()->vueSettings->all(),
			'notifications'       => array_merge( Models\Notification::getNotifications( false ), [ 'force' => $showNotificationsDrawer ] ),
			'helpPanel'           => [],
			'urls'                => [
				'domain'        => $this->getSiteDomain(),
				'mainSiteUrl'   => $this->getSiteUrl(),
				'home'          => home_url(),
				'restUrl'       => rest_url(),
				'editScreen'    => admin_url( 'edit.php' ),
				'publicPath'    => aioseoBrokenLinkChecker()->core->assets->normalizeAssetsHost( plugin_dir_url( AIOSEO_BROKEN_LINK_CHECKER_FILE ) ),
				'assetsPath'    => aioseoBrokenLinkChecker()->core->assets->getAssetsPath(),
				'marketingSite' => $this->getMarketingSiteUrl(),
				'connect'       => admin_url( 'index.php?page=broken-link-checker-connect' ),
				'blc'           => [
					'links' => admin_url( 'admin.php?page=broken-link-checker' )
				]
			],
			'isDev'               => $this->isDev(),
			'isSsl'               => is_ssl(),
			'isMultisite'         => is_multisite(),
			'isNetworkAdmin'      => is_network_admin(),
			'mainSite'            => is_main_site(),
			'hasUrlTrailingSlash' => '/' === user_trailingslashit( '' ),
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'translations'        => $this->getJedLocaleData( 'broken-link-checker-seo' )
		];

		// In multisite, super admins may not have explicit roles on subsites.
		// Ensure they have administrator role and capabilities for proper access.
		$userData     = wp_get_current_user();
		$roles        = $userData->roles;
		$capabilities = $userData->allcaps;

		// If the user is a network admin, and doesn't have a user on the subsite, give him admin role/caps.
		if ( is_multisite() && is_super_admin() && empty( $roles ) ) {
			$roles     = [ 'administrator' ];
			$adminRole = get_role( 'administrator' );
			if ( is_a( $adminRole, 'WP_Role' ) ) {
				$capabilities = $adminRole->capabilities;
			}
		}

		$this->vueData['user'] = [
			'roles'        => $roles,
			'capabilities' => $capabilities,
			'locale'       => function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale()
		];

		switch ( $currentPage ) {
			case 'about':
				$this->addAboutData();
				break;
			case 'dashboard':
				$this->addDashboardData();
				break;
			case 'highlighter':
				$this->addHighlighterData();
				break;
			case 'links':
				$this->addBrokenLinksReportData();
				break;
			case 'seo-settings':
				$this->addSeoSettingsData();
				break;
			case 'setup-wizard':
				$this->addSetupWizardSettingsData();
				break;
			default:
				break;
		}

		$this->cleanSensitiveData();

		return $this->vueData;
	}

	/**
	 * Adds the data for the About Us screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function addAboutData() {
		$this->vueData['plugins'] = $this->getPluginData();
	}

	/**
	 * Adds the data for the Dashboard widget.
	 *
	 * @since 1.2.6
	 *
	 * @return void
	 */
	private function addDashboardData() {
		$this->vueData['linkStatusOverview'] = $this->getLinkStatusDistribution();
	}

	/**
	 * Adds the data for the Highlighter screen.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function addHighlighterData() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$this->vueData['brokenLinks'] = Models\LinkStatus::getBrokenByPostId( get_the_ID() );
	}

	/**
	 * Adds the data for the SEO Settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function addSeoSettingsData() {
		$this->vueData['plugins'] = $this->getPluginData();
	}

	/**
	 * Adds the data for the Setup Wizard screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function addSetupWizardSettingsData() {}

	/**
	 * Adds the data for the Broken Links Report screen.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Renamed to make it more specific.
	 *
	 * @return void
	 */
	private function addBrokenLinksReportData() {
		$limit = aioseoBrokenLinkChecker()->vueSettings->tablePagination['brokenLinks'];

		$this->vueData += [
			'linkStatuses' => $this->getLinkStatusesData( $limit ),
			'plugins'      => [
				'isAioseoActive'          => function_exists( 'aioseo' ),
				'isAioseoRedirectsActive' => function_exists( 'aioseo' ) && ! empty( aioseo()->redirects )
			],
			'postTypes'    => $this->getPublicPostTypes( false, false, true ),
			'postStatuses' => $this->getPublicPostStatuses(),
			'scans'        => [
				'percentages' => [
					'links'        => aioseoBrokenLinkChecker()->main->links->data->getScanPercentage(),
					'linkStatuses' => aioseoBrokenLinkChecker()->main->linkStatus->data->getScanPercentage()
				]
			],
			'totalLinks'   => aioseoBrokenLinkChecker()->main->linkStatus->data->getTotalLinks()
		];
	}

	/**
	 * Returns the Broken Links Report data.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $limit      The limit.
	 * @param  int    $offset     The offset.
	 * @param  string $searchTerm The search term.
	 * @param  string $filter     The active filter.
	 * @param  string $orderBy    The order by.
	 * @param  string $orderDir   The order direction.
	 * @return array              The data.
	 */
	public function getLinkStatusesData( $limit = 20, $offset = 0, $searchTerm = '', $filter = 'all', $orderBy = '', $orderDir = 'DESC' ) {
		$whereClause = Models\Link::getLinkWhereClause( $searchTerm );

		$rows      = [];
		$totalRows = [];
		switch ( $filter ) {
			case 'good':
				$rows      = Models\LinkStatus::rowQuery( 'good', $limit, $offset, $whereClause, $orderBy, $orderDir );
				$totalRows = Models\LinkStatus::rowCountQuery( 'good', $whereClause );
				break;
			case 'broken':
				$rows      = Models\LinkStatus::rowQuery( 'broken', $limit, $offset, $whereClause, $orderBy, $orderDir );
				$totalRows = Models\LinkStatus::rowCountQuery( 'broken', $whereClause );
				break;
			case 'redirects':
				$rows      = Models\LinkStatus::rowQuery( 'redirects', $limit, $offset, $whereClause, $orderBy, $orderDir );
				$totalRows = Models\LinkStatus::rowCountQuery( 'redirects', $whereClause );
				break;
			case 'dismissed':
				$rows      = Models\LinkStatus::rowQuery( 'dismissed', $limit, $offset, $whereClause, $orderBy, $orderDir );
				$totalRows = Models\LinkStatus::rowCountQuery( 'dismissed', $whereClause );
				break;
			case 'not-checked':
				$rows      = Models\LinkStatus::rowQuery( 'not-checked', $limit, $offset, $whereClause, $orderBy, $orderDir );
				$totalRows = Models\LinkStatus::rowCountQuery( 'not-checked', $whereClause );
				break;
			case 'all':
				$rows      = Models\LinkStatus::rowQuery( 'all', $limit, $offset, $whereClause, $orderBy, $orderDir );
				$totalRows = Models\LinkStatus::rowCountQuery( 'all', $whereClause );
				break;
			default:
				break;
		}

		$page = 0 === $offset ? 1 : ( $offset / $limit ) + 1;

		return [
			'rows'    => $rows,
			'totals'  => [
				'page'  => $page,
				'pages' => ceil( $totalRows / $limit ),
				'total' => $totalRows
			],
			'filters' => [
				[
					'slug'   => 'all',
					'name'   => __( 'All', 'broken-link-checker-seo' ),
					'count'  => Models\LinkStatus::rowCountQuery( 'all', $whereClause ),
					'active' => ( ! $filter || 'all' === $filter ) && ! $searchTerm ? true : false
				],
				[
					'slug'   => 'broken',
					'name'   => __( 'Broken', 'broken-link-checker-seo' ),
					'count'  => Models\LinkStatus::rowCountQuery( 'broken', $whereClause ),
					'active' => 'broken' === $filter ? true : false
				],
				[
					'slug'   => 'redirects',
					'name'   => __( 'Redirects', 'broken-link-checker-seo' ),
					'count'  => Models\LinkStatus::rowCountQuery( 'redirects', $whereClause ),
					'active' => 'redirects' === $filter ? true : false
				],
				[
					'slug'   => 'good',
					'name'   => __( 'Good', 'broken-link-checker-seo' ),
					'count'  => Models\LinkStatus::rowCountQuery( 'good', $whereClause ),
					'active' => 'good' === $filter ? true : false
				],
				[
					'slug'   => 'not-checked',
					'name'   => __( 'Pending', 'broken-link-checker-seo' ),
					'count'  => Models\LinkStatus::rowCountQuery( 'not-checked', $whereClause ),
					'active' => 'not-checked' === $filter ? true : false
				],
				[
					'slug'   => 'dismissed',
					'name'   => __( 'Dismissed', 'broken-link-checker-seo' ),
					'count'  => Models\LinkStatus::rowCountQuery( 'dismissed', $whereClause ),
					'active' => 'dismissed' === $filter ? true : false
				]
			]
		];
	}

	/**
	 * Returns Jed-formatted localization data.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $domain The text domain.
	 * @return array          The information of the locale.
	 */
	private function getJedLocaleData( $domain ) {
		$translations = get_translations_for_domain( $domain );

		$locale = [
			'' => [
				'domain' => $domain,
				'lang'   => is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale(),
			],
		];

		if ( ! empty( $translations->headers['Plural-Forms'] ) ) {
			$locale['']['plural_forms'] = $translations->headers['Plural-Forms'];
		}

		foreach ( $translations->entries as $msgid => $entry ) {
			if ( empty( $entry->translations ) || ! is_array( $entry->translations ) ) {
				continue;
			}

			$locale[ $msgid ] = $entry->translations;
		}

		return $locale;
	}

	/**
	 * Returns the marketing site URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string The marketing site URL.
	 */
	private function getMarketingSiteUrl() {
		if ( defined( 'AIOSEO_MARKETING_SITE_URL' ) && AIOSEO_MARKETING_SITE_URL ) {
			return AIOSEO_MARKETING_SITE_URL;
		}

		return 'https://aioseo.com/';
	}

	/**
	 * Returns the link status distribution data.
	 *
	 * @since 1.2.6
	 *
	 * @return array The link status distribution.
	 */
	private function getLinkStatusDistribution() {
		$distribution = aioseoBrokenLinkChecker()->core->cache->get( 'link_status_distribution' );
		if ( ! empty( $distribution ) ) {
			return $distribution;
		}

		// Get counts for each link status type.
		$good      = Models\LinkStatus::rowCountQuery( 'good' );
		$broken    = Models\LinkStatus::rowCountQuery( 'broken' );
		$redirects = Models\LinkStatus::rowCountQuery( 'redirects' );

		$distribution = [
			'total'     => $good + $broken + $redirects,
			'good'      => $good,
			'broken'    => $broken,
			'redirects' => $redirects
		];

		aioseoBrokenLinkChecker()->core->cache->update( 'link_status_distribution', $distribution );

		return $distribution;
	}

	/**
	 * Clean sensitive data.
	 *
	 * @since 1.2.6
	 *
	 * @return void
	 */
	private function cleanSensitiveData() {
		// If the user is an admin, don't hide the following data.
		if ( aioseoBrokenLinkChecker()->access->isAdmin() ) {
			return;
		}

		if ( ! empty( $this->vueData['internalOptions']['internal']['license']['licenseKey'] ) ) {
			$this->vueData['internalOptions']['internal']['license']['licenseKey'] = '*****************';
		}
	}
}