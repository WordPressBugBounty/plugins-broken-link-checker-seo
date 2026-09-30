<?php
namespace AIOSEO\BrokenLinkChecker\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;
use AIOSEO\BrokenLinkChecker\Standalone;

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
	 * Returns the network licence data for Vue.
	 *
	 * Empty on a single site, which is what the network screen's absence is keyed off in the frontend.
	 *
	 * @since 1.3.1
	 *
	 * @return array The data.
	 */
	public function getNetworkVueData() {
		if ( ! is_multisite() || empty( aioseoBrokenLinkChecker()->networkLicense ) ) {
			return [];
		}

		// The licence record belongs to whoever manages the network, and the only screen that reads it
		// takes that capability to reach. A subsite reader was handed the network's plan, quota and
		// expiry through every page - on a subsite the licence does not even cover, which the report
		// tells them about through isNetworkLicensed alone. Absent reads the same as a single site does,
		// which is how the frontend already decides the network screen does not exist.
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return [];
		}

		$licenseData = aioseoBrokenLinkChecker()->internalNetworkOptions->internal->license->all();
		unset( $licenseData['licenseKey'] );

		return [
			'license'          => $licenseData,
			'sensitiveOptions' => aioseoBrokenLinkChecker()->networkSensitiveOptions->allHas(),
			'isConnected'      => aioseoBrokenLinkChecker()->networkLicense->isConnected(),
			'isValid'          => aioseoBrokenLinkChecker()->networkLicense->isValid(),
			'isLapsed'         => aioseoBrokenLinkChecker()->networkLicense->isLapsed()
		];
	}

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
			'dateFormat'          => get_option( 'date_format' ),
			'timeFormat'          => get_option( 'time_format' ),
			'page'                => $currentPage,
			'screen'              => aioseoBrokenLinkChecker()->helpers->getCurrentScreen(),
			'internalOptions'     => aioseoBrokenLinkChecker()->internalOptions->all(),
			'sensitiveOptions'    => aioseoBrokenLinkChecker()->sensitiveOptions->allHas(),
			'options'             => $this->readableOptions(),
			'settings'            => aioseoBrokenLinkChecker()->vueSettings->all(),
			'notifications'       => array_merge( Models\Notification::getNotifications( false ), [ 'force' => $showNotificationsDrawer ] ),
			'helpPanel'           => [],
			'newsroom'            => [
				'items'      => array_map(
					function ( $item ) {
						// Tagged here rather than in the feed: the medium names the surface.
						$item['url'] = aioseoBrokenLinkChecker()->helpers->utmUrl( $item['url'], 'newsroom-drawer', null, false );
						// Formatted here so the drawer shows the site's date format without
						// reimplementing PHP's format tokens in JS.
						$item['dateFormatted'] = aioseoBrokenLinkChecker()->newsroom->formatDate( $item['date'] );

						return $item;
					},
					array_slice( aioseoBrokenLinkChecker()->newsroom->getItems(), 0, 6 )
				),
				'archiveUrl' => aioseoBrokenLinkChecker()->newsroom->getArchiveUrl( 'newsroom-drawer' )
			],
			'urls'                => [
				'domain'        => $this->getSiteDomain(),
				'mainSiteUrl'   => $this->getSiteUrl(),
				'home'          => home_url(),
				'restUrl'       => rest_url(),
				'editScreen'    => admin_url( 'edit.php' ),
				'publicPath'    => aioseoBrokenLinkChecker()->core->assets->normalizeAssetsHost( plugin_dir_url( AIOSEO_BROKEN_LINK_CHECKER_FILE ) ),
				'assetsPath'    => aioseoBrokenLinkChecker()->core->assets->getAssetsPath(),
				'marketingSite' => $this->getMarketingSiteUrl(),
				// The popup hands the token back by loading this URL, so in network admin it has to be the
				// network's own screen or the handshake lands somewhere the network licence isn't saved.
				'connect'       => is_network_admin()
					? network_admin_url( 'admin.php?page=broken-link-checker-connect' )
					: admin_url( 'index.php?page=broken-link-checker-connect' ),
				'blc'           => [
					'links' => admin_url( 'admin.php?page=broken-link-checker' )
				]
			],
			'isDev'               => $this->isDev(),
			'isSsl'               => is_ssl(),
			'isMultisite'         => is_multisite(),
			'isNetworkAdmin'      => is_network_admin(),
			// Whether this site is covered by the network's licence rather than one of its own, which is
			// what the settings screen says instead of asking it to connect.
			'isNetworkLicensed'   => aioseoBrokenLinkChecker()->license->isNetworkLicensed(),
			'network'             => $this->getNetworkVueData(),
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
			// So the email settings can offer to add the reader's own address, and only when it is missing.
			'email'        => sanitize_email( (string) $userData->user_email ),
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

		return $this->vueData;
	}

	/**
	 * The settings the current reader may be handed.
	 *
	 * Managing them takes the settings capability, but the whole tree was going into the page for anyone
	 * the report or the dashboard widget renders for - editors and authors among them. That handed over
	 * values they cannot change and have no use for, the addresses the emailed reports go to among them.
	 *
	 * An allowlist rather than a redaction list, so a setting added later is private until someone says
	 * otherwise. These two are the ones the report itself reads.
	 *
	 * @since 1.3.1
	 *
	 * @return array The settings.
	 */
	private function readableOptions() {
		$options = aioseoBrokenLinkChecker()->options->all();
		if ( current_user_can( 'aioseo_blc_settings' ) ) {
			return $options;
		}

		$general = isset( $options['general'] ) ? $options['general'] : [];

		return [
			'general' => [
				// The Last Checked column says when the next scan is due.
				'scanFrequency' => isset( $general['scanFrequency'] ) ? $general['scanFrequency'] : null,
				// Editing a row warns that the change will not move the modified date.
				'linkTweaks'    => [
					'limitModifiedDate' => ! empty( $general['linkTweaks']['limitModifiedDate'] )
				]
			]
		];
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
	 * @since   1.2.6
	 * @version 1.3.1 Adds the site's link count.
	 *
	 * @return void
	 */
	private function addDashboardData() {
		$this->vueData['linkStatusOverview'] = $this->getLinkStatusDistribution();

		// The distribution only counts links we've checked, which is zero until the site connects.
		$this->vueData['totalLinks'] = aioseoBrokenLinkChecker()->main->linkStatus->data->getTotalLinks();

		$this->vueData['dashboardWidget'] = \AIOSEO\BrokenLinkChecker\Dashboard\Data::getWidget();
	}

	/**
	 * Adds the data for the Highlighter screen.
	 *
	 * @since   1.2.0
	 * @version 1.3.1 Shape the rows the popover reads instead of handing over whole status rows.
	 *
	 * @return void
	 */
	private function addHighlighterData() {
		// Which links are broken on a post is the post's data, so the reader has to be one who may see it.
		if ( ! Standalone\Highlighter::canHighlightCurrentRequest() ) {
			return;
		}

		$brokenLinks = [];
		foreach ( Models\LinkStatus::getBrokenByPostId( get_the_ID() ) as $row ) {
			$brokenLinks[] = [
				'url'            => (string) $row->url,
				// The column's NULL casts to 0, which is not a status code any link ever returned.
				'httpStatusCode' => empty( $row->http_status_code ) ? null : (int) $row->http_status_code,
				'reason'         => Models\LinkStatus::reasonFromLog( $row->log )
			];
		}

		$this->vueData['brokenLinks'] = $brokenLinks;
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
	 * @since   1.0.0
	 * @version 1.3.1 Adds the site's link count.
	 *
	 * @return void
	 */
	private function addSetupWizardSettingsData() {
		$this->vueData['totalLinks']  = aioseoBrokenLinkChecker()->main->linkStatus->data->getTotalLinks();
		$this->vueData['scanSources'] = $this->getScanSources();
	}

	/**
	 * Adds the data for the Broken Links Report screen.
	 *
	 * @since   1.0.0
	 * @version 1.1.0 Renamed to make it more specific.
	 * @version 1.3.1 Adds the CSV export URL.
	 *
	 * @return void
	 */
	private function addBrokenLinksReportData() {
		$limit = aioseoBrokenLinkChecker()->vueSettings->tablePagination['brokenLinks'];

		$this->vueData += [
			'exportUrl'    => aioseoBrokenLinkChecker()->export->getUrl(),
			'linkStatuses' => $this->getLinkStatusesData( $limit ),
			// Merged, not replaced. This key already carries the installed-plugin states the About screen
			// and the SMTP recommendation read, and assigning over it left them undefined here - so the
			// recommendation could not tell that WP Mail SMTP was installed, let alone active, and offered
			// itself to every site regardless.
			'plugins'      => array_merge(
				$this->getPluginData(),
				[
					'isAioseoActive'          => function_exists( 'aioseo' ),
					'isAioseoRedirectsActive' => function_exists( 'aioseo' ) && ! empty( aioseo()->redirects )
				]
			),
			'postTypes'    => $this->getPublicPostTypes( false, false, true ),
			'postStatuses' => $this->getPublicPostStatuses(),
			'scanSources'  => $this->getScanSources(),
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
	 * Returns the sources the settings screen offers a switch for, one per registered object type that
	 * has one.
	 *
	 * NOTE: Read from the registry rather than listed in the settings screen, so a type added through
	 * `aioseo_blc_object_types` gets its switch along with its rows in the report.
	 *
	 * @since 1.3.1
	 *
	 * @return array The sources.
	 */
	private function getScanSources() {
		// Where a switch covers more than one kind, the first one to claim it names it: a block theme's
		// navigation shares the menu switch, and template parts share the template one.
		$sources = [];
		foreach ( aioseoBrokenLinkChecker()->objects->all() as $objectType ) {
			$settingKey = $objectType->settingKey();
			if ( ! $settingKey || isset( $sources[ $settingKey ] ) ) {
				continue;
			}

			$sources[ $settingKey ] = [
				'key'   => $settingKey,
				'label' => $objectType->sourceLabel()
			];
		}

		// Ordered for a reader rather than by the registry, which is ordered for the scan. Anything not
		// named here still follows, so a new source cannot go missing by being forgotten.
		$order  = [ 'navMenus', 'customFields', 'terms', 'authorBios', 'patterns', 'templates' ];
		$sorted = [];
		foreach ( $order as $key ) {
			if ( isset( $sources[ $key ] ) ) {
				$sorted[] = $sources[ $key ];

				unset( $sources[ $key ] );
			}
		}

		return array_merge( $sorted, array_values( $sources ) );
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
	 * @param  string $source     The object type to narrow the rows to, or an empty string for all.
	 * @return array              The data.
	 */
	// phpcs:ignore Generic.Files.LineLength.MaxExceeded
	public function getLinkStatusesData( $limit = 20, $offset = 0, $searchTerm = '', $filter = 'all', $orderBy = '', $orderDir = 'DESC', $source = '', $media = '' ) {
		// Guarded here as well as at the request boundary: the stored page size reaches this too, and a
		// zero would both query for nothing and divide the pager by it.
		$limit  = 0 < (int) $limit ? (int) $limit : 20;
		$offset = max( 0, (int) $offset );

		$whereClause = Models\Link::getLinkWhereClause( $searchTerm );
		$whereClause = Models\Link::addSourceClause( $whereClause, $source );
		$whereClause = Models\Link::addTypeClause( $whereClause, $media );

		// Every tab's count in one query rather than one query each.
		$filterCounts = Models\LinkStatus::filterCounts( $whereClause );

		$rows = [];
		$validFilters = [ 'good', 'broken', 'redirects', 'dismissed', 'not-checked', 'all' ];
		$totalRows    = 0;
		if ( in_array( $filter, $validFilters, true ) ) {
			$rows = Models\LinkStatus::rowQuery( $filter, $limit, $offset, $whereClause, $orderBy, $orderDir );

			// The tab counts already hold this number, so asking for it again is a second trip for an
			// answer we have — and it is the same query the tab count runs.
			$totalRows = isset( $filterCounts[ $filter ] ) ? $filterCounts[ $filter ] : 0;
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
					'count'  => $filterCounts['all'],
					'active' => ( ! $filter || 'all' === $filter ) && ! $searchTerm ? true : false
				],
				[
					'slug'   => 'broken',
					'name'   => __( 'Broken', 'broken-link-checker-seo' ),
					'count'  => $filterCounts['broken'],
					'active' => 'broken' === $filter ? true : false
				],
				[
					'slug'   => 'redirects',
					'name'   => __( 'Redirects', 'broken-link-checker-seo' ),
					'count'  => $filterCounts['redirects'],
					'active' => 'redirects' === $filter ? true : false
				],
				[
					'slug'   => 'good',
					'name'   => __( 'Good', 'broken-link-checker-seo' ),
					'count'  => $filterCounts['good'],
					'active' => 'good' === $filter ? true : false
				],
				[
					'slug'   => 'not-checked',
					'name'   => __( 'Pending', 'broken-link-checker-seo' ),
					'count'  => $filterCounts['not-checked'],
					'active' => 'not-checked' === $filter ? true : false
				],
				[
					'slug'   => 'dismissed',
					'name'   => __( 'Dismissed', 'broken-link-checker-seo' ),
					'count'  => $filterCounts['dismissed'],
					'active' => 'dismissed' === $filter ? true : false
				]
			],
			'sources' => $this->getSourceFilters( $filter, $searchTerm, $source )
		];
	}

	/**
	 * Returns the source filter the report offers, one entry per registered object type.
	 *
	 * A URL found in more than one kind of place is counted under each, so the counts overlap and do
	 * not have to add up to the active filter's total.
	 *
	 * NOTE: A source whose locations the current user could never be shown is left out rather than
	 * offered with a count of zero.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Leaves out the sources the current user cannot see.
	 *
	 * @param  string $filter     The active filter.
	 * @param  string $searchTerm The search term.
	 * @param  string $source     The active source.
	 * @return array              The source filters.
	 */
	private function getSourceFilters( $filter, $searchTerm, $source ) {
		$activeFilter = $filter ? $filter : 'all';
		$whereClause  = Models\Link::getLinkWhereClause( $searchTerm );
		$totals       = Models\Link::getObjectTypeTotals( $activeFilter, $whereClause );

		// The combined counts already answer this, and they are cached — a count of their own would
		// be a second trip for a number we have.
		$filterCounts = Models\LinkStatus::filterCounts( $whereClause );

		$sources = [
			[
				'slug'     => '',
				'name'     => __( 'All Sources', 'broken-link-checker-seo' ),
				'tagLabel' => '',
				'count'    => isset( $filterCounts[ $activeFilter ] ) ? $filterCounts[ $activeFilter ] : 0,
				'enabled'  => true,
				'active'   => '' === (string) $source
			]
		];

		foreach ( aioseoBrokenLinkChecker()->objects->all() as $slug => $objectType ) {
			if ( '0' === $objectType->userScopeCondition() ) {
				continue;
			}

			$sources[] = [
				'slug'     => $slug,
				'name'     => $objectType->sourceLabel(),
				// So the report can tag a row it only knows the type slugs of, as a multi-location one.
				'tagLabel' => $objectType->tagLabel(),
				'count'    => isset( $totals[ $slug ] ) ? (int) $totals[ $slug ] : 0,
				'enabled'  => $objectType->isEnabled(),
				'active'   => $slug === (string) $source
			];
		}

		return $sources;
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

		// NOTE: Iterate without the key. Since WP 6.5, WP_Translations::__get( 'entries' ) returns a
		// list, so keying on it would index the payload numerically and translate nothing.
		foreach ( $translations->entries as $entry ) {
			if ( empty( $entry->translations ) || ! is_array( $entry->translations ) ) {
				continue;
			}

			foreach ( $entry->translations as $translation ) {
				// A translation containing an HTML line break breaks the admin when inlined.
				if ( preg_match( '/<br[\s\/\\\\]*>/', (string) $translation ) ) {
					continue 2;
				}
			}

			// Jed expects the singular as the index, even for plurals.
			$locale[ $entry->singular ] = $entry->translations;
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
	 * Clears the cached link distribution the dashboard widget reads.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function clearLinkStatusDistribution() {
		aioseoBrokenLinkChecker()->core->cache->delete( 'link_status_distribution' );
	}

	/**
	 * Returns the link status distribution data.
	 *
	 * @since   1.2.6
	 * @version 1.3.1 Counts come from the report's own totals, and the cache is short-lived.
	 *
	 * @return array The link status distribution.
	 */
	private function getLinkStatusDistribution() {
		$distribution = aioseoBrokenLinkChecker()->core->cache->get( 'link_status_distribution' );
		if ( ! empty( $distribution ) ) {
			return $distribution;
		}

		// The report's own counts, so the widget cannot describe the same links differently. One query
		// rather than three, and the buckets are mutually exclusive here.
		$totals = Models\LinkStatus::getFilterTotals();

		$good      = (int) $totals['good'];
		$broken    = (int) $totals['broken'];
		$redirects = (int) $totals['redirects'];

		$distribution = [
			// The denominator for the widget's ring, which shows how the checked links divide up.
			'total'     => $good + $broken + $redirects,
			'good'      => $good,
			'broken'    => $broken,
			'redirects' => $redirects
		];

		// Short, and cleared whenever a scan records a result. It used to hold for a day with nothing
		// clearing it, so the widget could contradict the report for that long.
		aioseoBrokenLinkChecker()->core->cache->update( 'link_status_distribution', $distribution, 15 * MINUTE_IN_SECONDS );

		return $distribution;
	}
}