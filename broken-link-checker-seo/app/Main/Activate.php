<?php
namespace AIOSEO\BrokenLinkChecker\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin (de)activation.
 *
 * @since 1.0.0
 */
class Activate {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		register_activation_hook( AIOSEO_BROKEN_LINK_CHECKER_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( AIOSEO_BROKEN_LINK_CHECKER_FILE, [ $this, 'deactivate' ] );
	}

	/**
	 * Runs on activation.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Added $networkWide; capabilities reach every subsite on a network activation.
	 *
	 * @param  bool $networkWide Whether this is a network-wide activation.
	 * @return void
	 */
	public function activate( $networkWide = false ) {
		if ( is_multisite() && $networkWide ) {
			$this->activateNetworkWide();
		}

		// Forced: roles are site configuration, so granting them must not depend on who is making the
		// request. Anyone who can activate a plugin is already an administrator.
		aioseoBrokenLinkChecker()->access->addCapabilities( true );

		// On a fresh install the cache table doesn't exist yet during activation (the plugin
		// is loaded after the 'init' hook that normally creates it). Without it, the
		// activation_redirect is written to the transient fallback but read back from the
		// later-created table on the next request — missing it and never triggering the Setup
		// Wizard. checkIfTableExists() creates the table only when missing and resets the
		// cache's transient fallback, so the redirect is written to (and later read from) it.
		aioseoBrokenLinkChecker()->core->cache->checkIfTableExists();

		// Set the activation timestamps.
		$time = time();
		aioseoBrokenLinkChecker()->internalOptions->internal->activated = $time;

		if ( ! aioseoBrokenLinkChecker()->internalOptions->internal->firstActivated ) {
			$this->showSetupWizard();

			aioseoBrokenLinkChecker()->internalOptions->internal->firstActivated = $time;

			// The admin email is often a shared or unattended inbox, so the reminder emails also
			// go to whoever actually installed the plugin.
			$activatingUserId = get_current_user_id();

			aioseoBrokenLinkChecker()->internalOptions->internal->activatingUserId = $activatingUserId;

			aioseoBrokenLinkChecker()->options->seedEmailReports( $activatingUserId );
		}

		aioseoBrokenLinkChecker()->core->cache->clear();
	}

	/**
	 * Builds the tables and capabilities on every site of the network.
	 *
	 * Roles are stored per site, so activating for the network does not reach a subsite's roles on its
	 * own - without this, an administrator on a subsite would find the plugin's menu missing until
	 * something else wrote them.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	private function activateNetworkWide() {
		$sites = get_sites( [
			'network_id' => get_current_network_id(),
			'number'     => 0,
			'fields'     => 'ids'
		] );

		foreach ( $sites as $blogId ) {
			// The network's main site is handled by the caller, on the current request.
			if ( get_current_blog_id() === (int) $blogId ) {
				continue;
			}

			aioseoBrokenLinkChecker()->helpers->switchToBlog( (int) $blogId );

			aioseoBrokenLinkChecker()->updates->updateDbSchema();
			// Forced: nothing is logged in on the subsite we just switched into.
			aioseoBrokenLinkChecker()->access->addCapabilities( true );

			aioseoBrokenLinkChecker()->helpers->restoreCurrentBlog();
		}
	}

	/**
	 * Show the setup wizard if this is the first time the user activates the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function showSetupWizard() {
		if ( aioseoBrokenLinkChecker()->internalOptions->internal->firstActivated ) {
			return;
		}

		if ( is_network_admin() ) {
			return;
		}

		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore HM.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Sets 30 second transient for welcome screen redirect on activation.
		aioseoBrokenLinkChecker()->core->cache->update( 'activation_redirect', true, 30 );
	}

	/**
	 * Runs on deactivation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function deactivate() {
		aioseoBrokenLinkChecker()->access->removeCapabilities();
	}
}