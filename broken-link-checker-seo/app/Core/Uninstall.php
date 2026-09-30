<?php
namespace AIOSEO\BrokenLinkChecker\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Utils;

/**
 * Handles plugin deinstallation.
 *
 * @since 1.0.0
 */
class Uninstall {
	/**
	 * Removes all our tables and options.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Removes the user meta as well.
	 *
	 * @param  bool $force Whether we should ignore the uninstall option or not. We ignore it when we reset all data via the Debug Panel.
	 * @return void
	 */
	public function dropData( $force = false ) {
		// Confirm that user has decided to remove all data, otherwise stop.
		if (
			! $force &&
			! aioseoBrokenLinkChecker()->options->advanced->uninstall
		) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// Delete all our custom tables.
		global $wpdb;
		foreach ( $this->getDbTables() as $tableName ) {
			$escapedTableName = esc_sql( $tableName );

			$wpdb->query( 'DROP TABLE IF EXISTS ' . $escapedTableName ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Delete all the plugin settings.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'aioseo\_blc\_%'" );

		// Remove any transients we've left behind. WordPress prefixes the option name with
		// _transient_, so our own prefix is no longer what the name starts with.
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_aioseo\_blc\_%'
				OR option_name LIKE '\_transient\_aioseo\_blc\_%'
				OR option_name LIKE '\_transient\_timeout\_aioseo\_blc\_%'
				OR option_name LIKE '\_site\_transient\_aioseo\_blc\_%'
				OR option_name LIKE '\_site\_transient\_timeout\_aioseo\_blc\_%'"
		);

		// The per-person ones: a highlighter switched off, a survey or a notice dismissed. They are
		// prefixed like our options, so the same two shapes cover every one of them.
		$wpdb->query(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'aioseo\_blc\_%'
				OR meta_key LIKE '\_aioseo\_blc\_%'"
		);

		// Delete all entries from the action scheduler table. The slug is compared, not matched, so it
		// carries no LIKE escaping - an escaped underscore would be read as a literal backslash.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE 'aioseo\_blc\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = 'aioseo_blc'" );
		// phpcs:enable

		// Delete all our custom capabilities.
		$this->uninstallCapabilities();
	}

	/**
	 * Returns all the DB tables with their prefix.
	 *
	 * @since   1.0.0
	 * @version 1.3.1 Public, so a deleted subsite's tables can be handed to wpmu_drop_tables.
	 *
	 * @return array List of tables.
	 */
	public function getDbTables() {
		global $wpdb;

		$tables = [];
		foreach ( aioseoBrokenLinkChecker()->core->db->customTables as $tableName ) {
			$tables[] = $wpdb->prefix . $tableName;
		}

		return $tables;
	}

	/**
	 * Removes all our custom capabilities.
	 *
	 * @since 1.2.4
	 *
	 * @return void
	 */
	private function uninstallCapabilities() {
		$access             = new Utils\Access();
		$customCapabilities = $access->getCapabilityList() ?? [];
		$roles              = aioseoBrokenLinkChecker()->helpers->getUserRoles();

		// Loop through roles and remove custom capabilities.
		foreach ( $roles as $roleName => $roleInfo ) {
			$role = get_role( $roleName );

			if ( $role ) {
				foreach ( $customCapabilities as $capability ) {
					$role->remove_cap( $capability );
				}
			}
		}
	}
}