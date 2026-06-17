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
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function activate() {
		aioseoBrokenLinkChecker()->access->addCapabilities();

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
		}

		aioseoBrokenLinkChecker()->core->cache->clear();
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