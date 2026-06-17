<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Moves the license key out of the internal options blob into the dedicated
 * sensitive options store, then clears it from the old location.
 *
 * Older installs persisted the key inside aioseo_blc_options_internal; this
 * relocates it so it's no longer stored alongside non-sensitive internal state.
 * verify() is the truth signal — the runner retries until the old location no
 * longer holds a key.
 *
 * @since 1.3.0
 */
class MigrateSensitiveOptions implements Migration {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 */
	public function name() {
		return 'migrate_sensitive_options';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 */
	public function version() {
		return '1.3.0';
	}

	/**
	 * {@inheritdoc}
	 *
	 * Idempotent — once the old location is cleared, the empty-key guard makes
	 * subsequent runs no-op.
	 *
	 * @since 1.3.0
	 */
	public function up() {
		$optionName = $this->optionName();
		$dbOptions  = json_decode( (string) get_option( $optionName, '' ), true );
		if ( empty( $dbOptions ) || ! is_array( $dbOptions ) ) {
			return;
		}

		$licenseKey = $dbOptions['internal']['license']['licenseKey'] ?? '';
		if ( empty( $licenseKey ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->sensitiveOptions->set( 'licenseKey', $licenseKey );
		aioseoBrokenLinkChecker()->sensitiveOptions->save( true );

		// Clear the old value.
		$dbOptions['internal']['license']['licenseKey'] = '';
		update_option( $optionName, wp_json_encode( $dbOptions ), false );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Returns true when the old location no longer holds a license key. Fresh
	 * installs and already-migrated sites pass without running up().
	 *
	 * @since 1.3.0
	 */
	public function verify() {
		$dbOptions = json_decode( (string) get_option( $this->optionName(), '' ), true );
		if ( empty( $dbOptions ) || ! is_array( $dbOptions ) ) {
			return true;
		}

		return empty( $dbOptions['internal']['license']['licenseKey'] );
	}

	/**
	 * The internal options option name, network-aware.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	private function optionName() {
		return is_network_admin() ? 'aioseo_blc_options_internal_network' : 'aioseo_blc_options_internal';
	}
}