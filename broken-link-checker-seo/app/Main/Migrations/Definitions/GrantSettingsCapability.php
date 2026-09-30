<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Grants the settings capability to administrators, and takes it off every other role.
 *
 * The settings decide what the whole site scans, who receives its reports and whether the account
 * stays connected, so they are administrator work. Editors and Authors keep the report itself, which
 * they are given on purpose.
 *
 * Roles are stored, so a new capability reaches an existing site only when something writes it. The
 * version-compare updates only re-add capabilities for sites coming from before 1.2.6, which is why
 * this cannot rely on them: without it, an administrator upgrading would find the Settings tab gone.
 *
 * @since 1.3.1
 */
class GrantSettingsCapability implements Migration {
	/**
	 * The name this migration is logged under.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const NAME = 'grant_settings_capability';

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function name() {
		return self::NAME;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function version() {
		return '1.3.1';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function up() {
		// Written here rather than through Access::addCapabilities(), which gates its role loop on
		// current_user_can( 'edit_posts' ) — so it does nothing in any context without a logged-in user,
		// which includes cron and WP-CLI, and this would retry for ever.
		$administrator = get_role( 'administrator' );
		if ( is_a( $administrator, 'WP_Role' ) ) {
			$administrator->add_cap( 'aioseo_blc_settings' );
		}

		foreach ( [ 'editor', 'author', 'contributor', 'subscriber' ] as $name ) {
			$role = get_role( $name );
			if ( is_a( $role, 'WP_Role' ) ) {
				$role->remove_cap( 'aioseo_blc_settings' );
			}
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		$administrator = get_role( 'administrator' );
		if ( ! is_a( $administrator, 'WP_Role' ) ) {
			// Nothing to grant it to, so nothing is outstanding.
			return true;
		}

		if ( ! $administrator->has_cap( 'aioseo_blc_settings' ) ) {
			return false;
		}

		// And it must not have been left on a role that should never have had it.
		foreach ( [ 'editor', 'author', 'contributor', 'subscriber' ] as $name ) {
			$role = get_role( $name );
			if ( is_a( $role, 'WP_Role' ) && $role->has_cap( 'aioseo_blc_settings' ) ) {
				return false;
			}
		}

		return true;
	}
}