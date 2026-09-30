<?php
namespace AIOSEO\BrokenLinkChecker\Options;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the network's licence key.
 *
 * Stored once on the network's main site, so a subsite never holds a copy of the key it is licensed by.
 *
 * @since 1.3.1
 */
class NetworkSensitiveOptions extends SensitiveOptions {
	/**
	 * The option name used for DB storage.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	protected $optionsName = 'aioseo_blc_sensitive_options_network';

	/**
	 * Initializes the options from the database.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	protected function init() {
		if ( ! is_multisite() ) {
			return;
		}

		// WordPress's own functions rather than the plugin's wrapper: this runs in preLoad(), before
		// the helpers exist. See Traits\NetworkOptions for the same note.
		switch_to_blog( get_main_site_id() );

		parent::init();

		restore_current_blog();
	}

	/**
	 * Saves the options to the database.
	 *
	 * @since 1.3.1
	 *
	 * @param  bool $force Whether to force saving.
	 * @return void
	 */
	public function save( $force = false ) {
		if ( ! is_multisite() ) {
			return;
		}

		switch_to_blog( get_main_site_id() );

		parent::save( $force );

		restore_current_blog();
	}
}