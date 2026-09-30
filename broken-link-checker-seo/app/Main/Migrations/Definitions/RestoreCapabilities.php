<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;
use AIOSEO\BrokenLinkChecker\Utils\Access;

/**
 * Grants the plugin's capabilities to the roles that should hold them.
 *
 * Granting used to depend on the current user being able to edit posts, so every context without a
 * logged-in user - cron, WP-CLI, and an activation that ran through one of them - silently granted
 * nothing. Editors and authors were left with no way into the report, and administrators kept
 * reaching it only through the isAdmin() bypass, which is why it went unnoticed.
 *
 * @since 1.3.1
 */
class RestoreCapabilities implements Migration {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function name() {
		return 'restore_capabilities';
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
		// Forced, because a migration has no user to check. Its own instance, because the runner goes
		// in preLoad(), before load() sets up aioseoBrokenLinkChecker()->access.
		( new Access() )->addCapabilities( true );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		$editor = get_role( 'editor' );
		if ( ! is_a( $editor, 'WP_Role' ) ) {
			// Nothing to grant it to, so nothing is outstanding.
			return true;
		}

		return $editor->has_cap( 'aioseo_blc_broken_links_page' );
	}
}