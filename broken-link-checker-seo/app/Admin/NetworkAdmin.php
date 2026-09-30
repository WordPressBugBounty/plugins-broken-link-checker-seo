<?php
namespace AIOSEO\BrokenLinkChecker\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Network Admin screen and keeps subsites in step with the network.
 *
 * Deliberately one screen. A network admin licenses sites from here; everything else a site does with
 * its links stays on that site, where the person looking after it works.
 *
 * @since 1.3.1
 */
class NetworkAdmin {
	/**
	 * The page slug.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	private $pageSlug = 'broken-link-checker';

	/**
	 * The Vue page whose assets this screen loads.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	private $vuePage = 'network';

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		if ( ! is_multisite() ) {
			return;
		}

		// Dropping a deleted site's tables and provisioning a new one's have to happen wherever the
		// site is created or deleted from, so they are registered before the admin-only bail below.
		add_filter( 'wpmu_drop_tables', [ $this, 'dropTables' ] );
		add_action( 'wp_initialize_site', [ $this, 'initializeSite' ], 1000 );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'network_admin_menu', [ $this, 'registerMenu' ] );
		add_action( 'network_admin_menu', [ $this, 'addLicenseMenuLink' ], 999 );
	}

	/**
	 * Whether the plugin is active for the whole network.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether it is network active.
	 */
	private function isNetworkActive() {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_BASENAME );
	}

	/**
	 * Registers the network menu.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function registerMenu() {
		if ( ! $this->isNetworkActive() ) {
			return;
		}

		$hook = add_menu_page(
			__( 'Broken Links', 'broken-link-checker-seo' ),
			__( 'Broken Links', 'broken-link-checker-seo' ),
			'manage_network_options',
			$this->pageSlug,
			[ $this, 'renderMenuPage' ],
			'data:image/svg+xml;base64,' . base64_encode( aioseoBrokenLinkChecker()->helpers->icon() ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);

		add_action( "load-{$hook}", [ $this, 'loadPage' ] );

		// Registered against the parent's own slug, which renames the row WordPress generates for the
		// top-level page rather than leaving "Broken Links" listed twice.
		add_submenu_page(
			$this->pageSlug,
			__( 'Site Activations', 'broken-link-checker-seo' ),
			__( 'Site Activations', 'broken-link-checker-seo' ),
			'manage_network_options',
			$this->pageSlug,
			[ $this, 'renderMenuPage' ]
		);

		// No menu item: this is only ever opened by the account connection popup.
		$connectHook = add_submenu_page(
			'',
			__( 'Connect Success', 'broken-link-checker-seo' ),
			__( 'Connect Success', 'broken-link-checker-seo' ),
			'manage_network_options',
			$this->pageSlug . '-connect',
			[ aioseoBrokenLinkChecker()->admin, 'renderConnectSuccessPage' ]
		);

		add_action( "load-{$connectHook}", [ aioseoBrokenLinkChecker()->admin, 'hideAdminNoticesOnConnectPage' ] );
	}

	/**
	 * Adds the connect or renew highlight to the network menu.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function addLicenseMenuLink() {
		if ( ! $this->isNetworkActive() || ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$networkLicense = aioseoBrokenLinkChecker()->networkLicense;
		if ( empty( $networkLicense ) ) {
			return;
		}

		$label = '';
		if ( ! $networkLicense->isConnected() ) {
			$label = __( 'Connect Now', 'broken-link-checker-seo' );
		} elseif ( $networkLicense->isLapsed() ) {
			// Renewing fixes an expiry. It does nothing for a key that is invalid or disabled, which
			// needs re-entering instead.
			$label = $networkLicense->isExpired()
				// Translators: This is a link users can click to renew their license.
				? __( 'Renew License', 'broken-link-checker-seo' )
				// Translators: This is a link users can click to check a license that isn't working.
				: __( 'Check License', 'broken-link-checker-seo' );
		}

		if ( empty( $label ) ) {
			return;
		}

		global $submenu;

		$submenu[ $this->pageSlug ][] = [
			'<span class="aioseo-blc-menu-highlight">' . esc_html( $label ) . '</span>',
			'manage_network_options',
			network_admin_url( 'admin.php?page=' . $this->pageSlug )
		];
	}

	/**
	 * Renders the element the Vue app mounts on.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function renderMenuPage() {
		echo '<div id="aioseo-blc-app"></div>';
	}

	/**
	 * Hooks the assets in once we know the screen is ours.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function loadPage() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ], 11 );
		add_action( 'admin_print_styles', [ aioseoBrokenLinkChecker()->main, 'printAdminMenuStyles' ] );
	}

	/**
	 * Enqueues the network page's assets.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function enqueueAssets() {
		$vueData = aioseoBrokenLinkChecker()->helpers->getVueData( $this->vuePage );

		// The account card is the settings screen's, unchanged. Pointing the licence it reads at the
		// network's is what makes it describe the network rather than the main site.
		$networkData = aioseoBrokenLinkChecker()->helpers->getNetworkVueData();
		if ( ! empty( $networkData ) ) {
			$vueData['internalOptions']['internal']['license'] = $networkData['license'];
			$vueData['sensitiveOptions']                       = $networkData['sensitiveOptions'];
		}

		// The router builds its own base from the page name, which only holds for the site screens.
		$vueData['routerBase'] = 'wp-admin/network/admin.php?page=' . $this->pageSlug;

		aioseoBrokenLinkChecker()->core->assets->load(
			'src/vue/pages/' . $this->vuePage . '/main.js',
			[],
			$vueData
		);
	}

	/**
	 * Adds our tables to the list dropped when a site is deleted.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $tables The tables WordPress is about to drop.
	 * @return array         The tables, with ours added.
	 */
	public function dropTables( $tables ) {
		if ( ! is_array( $tables ) ) {
			return $tables;
		}

		return array_merge( aioseoBrokenLinkChecker()->core->uninstall->getDbTables(), $tables );
	}

	/**
	 * Builds our tables and capabilities on a newly created site.
	 *
	 * NOTE: Without this the site provisions itself on its first admin load anyway, but anything that
	 * touches the tables before then - a cron pass, a REST call - would find them missing.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_Site $site The new site.
	 * @return void
	 */
	public function initializeSite( $site ) {
		if ( empty( $site->blog_id ) ) {
			return;
		}

		if ( ! $this->isNetworkActive() ) {
			return;
		}

		aioseoBrokenLinkChecker()->helpers->switchToBlog( (int) $site->blog_id );

		aioseoBrokenLinkChecker()->updates->updateDbSchema();
		aioseoBrokenLinkChecker()->access->addCapabilities( true );

		aioseoBrokenLinkChecker()->helpers->restoreCurrentBlog();
	}
}