<?php
namespace AIOSEO\BrokenLinkChecker\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Access {
	/**
	 * Capabilities for our users.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $capabilities = [
		'aioseo_blc_about_us_page',
		'aioseo_blc_broken_links_page',
		'aioseo_blc_setup_wizard_page',
		'aioseo_blc_settings'
	];

	/**
	 * Capabilities only an administrator gets.
	 *
	 * NOTE: Editors and Authors are given the report on purpose, but the settings decide what the whole
	 * site scans, who receives its reports and whether the account stays connected — none of which
	 * belongs to a role that cannot manage the site.
	 *
	 * @since 1.3.1
	 *
	 * @var array
	 */
	private $adminOnlyCapabilities = [
		'aioseo_blc_settings',
		// The wizard exists to connect an account and choose what the whole site scans, so it belongs to
		// the same role the settings do - and its last step would otherwise end in a refused request.
		'aioseo_blc_setup_wizard_page'
	];

	/**
	 * Roles we check capabilities against.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $roles = [
		'superadmin'    => 'superadmin',
		'administrator' => 'administrator',
		'editor'        => 'editor',
		'author'        => 'author'
	];

	/**
	 * Roles that we don't add the capabilities to.
	 *
	 * @since 1.2.6
	 *
	 * @var array
	 */
	private $excludedRoles = [
		'subscriber',
		'contributor'
	];

	/**
	 * Whether or not we are updating roles.
	 *
	 * @since 1.1.0
	 *
	 * @var bool
	 */
	private $isUpdatingRoles = false;

	/**
	 * Adds capabilities into WordPress for the current user.
	 *
	 * @since 1.0.0
	 *
	 * @param  bool $force Whether to grant regardless of who is making the request. Needed wherever no
	 *                     user is on the blog being written to - a network activation switched into a
	 *                     subsite, a newly created site, WP-CLI or cron.
	 * @return void
	 */
	public function addCapabilities( $force = false ) {
		foreach ( $this->roles as $wpRole => $role ) {
			$roleObject = get_role( $wpRole );
			if ( ! is_object( $roleObject ) ) {
				continue;
			}

			// Don't add the cap to the subscriber or contributor roles.
			if ( in_array( $role, $this->excludedRoles, true ) ) {
				continue;
			}

			if ( $force || current_user_can( 'edit_posts' ) || $this->isAdmin() ) {
				foreach ( $this->capabilities as $cap ) {
					// Granted to administrators only, and the role loop reaches every role.
					if ( in_array( $cap, $this->adminOnlyCapabilities, true ) && ! $this->isAdmin( $role ) ) {
						$roleObject->remove_cap( $cap );

						continue;
					}

					$roleObject->add_cap( $cap );
				}
			}
		}

		$this->removeCapabilities();
	}

	/**
	 * Removes capabilities for any unknown role.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function removeCapabilities() {
		$this->isUpdatingRoles = true;

		// Clear out capabilities for unknown roles.
		$wpRoles  = wp_roles();
		$allRoles = $wpRoles->roles;
		foreach ( $allRoles as $key => $wpRole ) {
			$checkRole = is_multisite() ? 'superadmin' : 'administrator';
			if ( $checkRole === $key ) {
				continue;
			}

			if ( in_array( $key, $this->roles, true ) ) {
				continue;
			}

			$role = get_role( $key );
			if ( empty( $role ) ) {
				continue;
			}

			if ( $this->isAdmin( $key ) ) {
				continue;
			}

			foreach ( $this->capabilities as $capability ) {
				if (
					$role->has_cap( $capability ) ||
					in_array( $key, $this->excludedRoles, true )
				) {
					$role->remove_cap( $capability );
				}
			}
		}
	}

	/**
	 * Checks if the current user has the capability.
	 *
	 * @since 1.0.0
	 *
	 * @param  string      $capability The capability to check against.
	 * @param  string|null $checkRole  A role to check against.
	 * @return bool                    Whether or not the user has this capability.
	 */
	public function hasCapability( $capability, $checkRole = null ) {
		// Only admins have access.
		if ( $this->isAdmin( $checkRole ) ) {
			return true;
		}

		if ( in_array( $capability, $this->adminOnlyCapabilities, true ) ) {
			return false;
		}

		// The capability itself, not a proxy for it. Contributors can `edit_posts`, so standing in for the
		// check that way handed them capabilities addCapabilities() deliberately never granted — which is
		// how the dashboard widget reached them, with links to a page that answers 403.
		return $this->can( $capability, $checkRole );
	}

	/**
	 * Gets all the capabilities for the current user.
	 *
	 * @since 1.0.0
	 *
	 * @param  string|null $role A role to check against.
	 * @return array             An array of capabilities.
	 */
	public function getAllCapabilities( $role = null ) {
		$capabilities = [];
		foreach ( $this->capabilities as $cap ) {
			$capabilities[ $cap ] = $this->hasCapability( $cap, $role );
		}

		return $capabilities;
	}

	/**
	 * If the current user is an admin, or superadmin, they have access to all caps regardless.
	 *
	 * @since 1.0.0
	 *
	 * @param  string|null $role The role to check admin privileges if we have one.
	 * @return bool              Whether not the user/role is an admin.
	 */
	public function isAdmin( $role = null ) {
		if ( $role ) {
			if ( ( is_multisite() && 'superadmin' === $role ) || 'administrator' === $role ) {
				return true;
			}

			return false;
		}

		if ( ( is_multisite() && current_user_can( 'superadmin' ) ) || current_user_can( 'administrator' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the passed in role can publish posts.
	 *
	 * @since 1.0.0
	 *
	 * @param  string  $capability The capability to check against.
	 * @param  string  $role       The role to check.
	 * @return boolean             True if the role can publish.
	 */
	protected function can( $capability, $role ) {
		if ( empty( $role ) ) {
			return current_user_can( $capability );
		}

		$wpRoles  = wp_roles();
		$allRoles = $wpRoles->roles;
		foreach ( $allRoles as $key => $wpRole ) {
			if ( $key === $role ) {
				$r = get_role( $key );
				if ( $r->has_cap( $capability ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Returns the capability list.
	 *
	 * @since 1.2.4
	 *
	 * @return array An array of capabilities.
	 */
	public function getCapabilityList() {
		return $this->capabilities;
	}
}