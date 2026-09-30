<?php
namespace AIOSEO\BrokenLinkChecker\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points an options group at the network's main site instead of the current one.
 *
 * NOTE: Network options live in the main site's options table under their own name, not in sitemeta.
 * That is the convention the rest of the portfolio uses, and what NetworkCache already does.
 *
 * @since 1.3.1
 */
trait NetworkOptions {
	/**
	 * Initializes the options.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	protected function init() {
		if ( ! is_multisite() ) {
			return;
		}

		$this->switchToNetwork();

		parent::init();

		restore_current_blog();
	}

	/**
	 * Sanitizes, then saves the options to the database.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $options An array of options to sanitize, then save.
	 * @return void
	 */
	public function sanitizeAndSave( $options ) {
		if ( ! is_multisite() ) {
			return;
		}

		$this->switchToNetwork();

		parent::sanitizeAndSave( $options );

		restore_current_blog();
	}

	/**
	 * Saves the options to the database.
	 *
	 * @since 1.3.1
	 *
	 * @param  bool        $force       Whether to force saving.
	 * @param  string|null $optionsName The options name to save under.
	 * @param  array|null  $defaults    The defaults to filter against.
	 * @return void
	 */
	public function save( $force = false, $optionsName = null, $defaults = null ) {
		if ( ! is_multisite() ) {
			return;
		}

		$this->switchToNetwork();

		parent::save( $force, $optionsName, $defaults );

		restore_current_blog();
	}

	/**
	 * Switches to the network's main site.
	 *
	 * NOTE: Deliberately WordPress's own functions rather than the plugin's switchToBlog() wrapper.
	 * Options are constructed in preLoad() before the helpers exist, so reaching for them here would
	 * fatal on a call this early.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	private function switchToNetwork() {
		switch_to_blog( get_main_site_id() );
	}
}