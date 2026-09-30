<?php
namespace AIOSEO\BrokenLinkChecker\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains methods related to multisites.
 *
 * @since 1.0.0
 */
trait WpMultisite {
	/**
	 * Returns the current site.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_Site|Object A WP_Site instance of the current site or an object representing the same.
	 */
	public function getSite() {
		if ( is_multisite() ) {
			return get_site();
		}

		return (object) [
			'domain' => $this->getSiteDomain( true ),
			'path'   => $this->getHomePath( true )
		];
	}

	/**
	 * Returns the network ID.
	 *
	 * @since 1.0.0
	 *
	 * @return int The integer of the blog/site id.
	 */
	public function getNetworkId() {
		if ( is_multisite() ) {
			return get_network()->site_id;
		}

		return get_current_blog_id();
	}

	/**
	 * Returns every site on the network, with the data the activation table needs.
	 *
	 * NOTE: Deliberately not filtered by `public`. That flag is switched off by the subsite's own
	 * "discourage search engines" setting, which has nothing to do with whether it should be licensed.
	 *
	 * @since 1.3.1
	 *
	 * @param  int|string  $limit      How many sites to return, or 'all'.
	 * @param  int         $offset     Where to start.
	 * @param  string|null $searchTerm A term to match against the domain and path.
	 * @param  string      $filter     One of 'all', 'activated' or 'deactivated'.
	 * @param  string|null $orderBy    The property to order by.
	 * @param  string      $orderDir   'ASC' or 'DESC'.
	 * @return array                   The total, the limit and the sites themselves.
	 */
	public function getSites( $limit = 'all', $offset = 0, $searchTerm = null, $filter = 'all', $orderBy = null, $orderDir = 'DESC' ) {
		if ( ! is_multisite() ) {
			return [
				'total' => 0,
				'limit' => $limit,
				'sites' => []
			];
		}

		$sites = get_sites( [
			'network_id' => get_current_network_id(),
			'number'     => 0
		] );

		$allSites = [];
		foreach ( $sites as $site ) {
			$aliases = $this->getSiteAliases( $site );

			// A plain object rather than the WP_Site itself: the table needs properties WP_Site does not
			// declare, and writing those onto it is deprecated from PHP 8.2.
			$row = (object) [
				'blog_id'      => (int) $site->blog_id,
				'domain'       => $site->domain,
				'path'         => $site->path,
				'adminUrl'     => get_admin_url( $site->blog_id ),
				'homeUrl'      => get_home_url( $site->blog_id ),
				'isMain'       => get_main_site_id() === (int) $site->blog_id,
				'aliases'      => $aliases,
				'alias'        => [],
				'parentDomain' => '',
				'parentPath'   => ''
			];

			if ( $this->includeSite( $row, $filter ) ) {
				$allSites[] = $row;
			}

			// An alias gets a row of its own, carrying the alias domain as the one to license.
			foreach ( $aliases as $alias ) {
				$aliasRow               = clone $row;
				$aliasRow->domain       = $alias['domain'];
				$aliasRow->path         = '/';
				$aliasRow->isMain       = false;
				$aliasRow->aliases      = [];
				$aliasRow->alias        = $alias;
				$aliasRow->parentDomain = $site->domain;
				$aliasRow->parentPath   = $site->path;

				if ( $this->includeSite( $aliasRow, $filter ) ) {
					$allSites[] = $aliasRow;
				}
			}
		}

		if ( ! empty( $searchTerm ) ) {
			$allSites = array_values( array_filter( $allSites, function ( $site ) use ( $searchTerm ) {
				foreach ( [ 'domain', 'path', 'parentDomain', 'parentPath' ] as $property ) {
					if ( ! empty( $site->{$property} ) && false !== stripos( $site->{$property}, $searchTerm ) ) {
						return true;
					}
				}

				return false;
			} ) );
		}

		if ( ! empty( $orderBy ) ) {
			usort( $allSites, function ( $site1, $site2 ) use ( $orderBy, $orderDir ) {
				$value1 = isset( $site1->{$orderBy} ) ? (string) $site1->{$orderBy} : '';
				$value2 = isset( $site2->{$orderBy} ) ? (string) $site2->{$orderBy} : '';

				return 'ASC' === strtoupper( (string) $orderDir )
					? strnatcasecmp( $value1, $value2 )
					: strnatcasecmp( $value2, $value1 );
			} );
		}

		return [
			'total' => count( $allSites ),
			'limit' => $limit,
			'sites' => 'all' === $limit ? $allSites : array_slice( $allSites, $offset, (int) $limit )
		];
	}

	/**
	 * Whether a site belongs in the given filter.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_Site|object $site   The site.
	 * @param  string          $filter One of 'all', 'activated' or 'deactivated'.
	 * @return bool                    Whether to include it.
	 */
	private function includeSite( $site, $filter ) {
		if ( 'all' === $filter ) {
			return true;
		}

		// The shared activation list rather than isSiteActive(), so filtering a large network is one
		// lookup instead of a cache write per row.
		$activations  = aioseoBrokenLinkChecker()->networkLicense->getActivations();
		$siteIsActive = aioseoBrokenLinkChecker()->networkLicense->matchesActivation( $site, $activations );

		return ( 'activated' === $filter && $siteIsActive ) || ( 'deactivated' === $filter && ! $siteIsActive );
	}

	/**
	 * Returns a site's domain aliases, where Mercator provides them.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_Site $site The site.
	 * @return array          The aliases.
	 */
	public function getSiteAliases( $site ) {
		if ( ! class_exists( '\Mercator\Mapping' ) ) {
			return [];
		}

		$aliases = \Mercator\Mapping::get_by_site( $site->blog_id );
		if ( empty( $aliases ) ) {
			return [];
		}

		$aliasData = [];
		foreach ( $aliases as $alias ) {
			$aliasData[] = [
				'alias_id' => $alias->get_id(),
				'domain'   => $alias->get_domain(),
				'active'   => $alias->is_active()
			];
		}

		return $aliasData;
	}

	/**
	 * Wrapper for switch_to_blog especially for non-multisite setups.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $blogId The blog ID to switch to.
	 * @return bool         True in all cases.
	 */
	public function switchToBlog( $blogId ) {
		if ( ! is_multisite() ) {
			return true;
		}

		return switch_to_blog( $blogId );
	}

	/**
	 * Wrapper for restore_current_blog especially for non-multisite setups.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether we're already on the current blog or not in a multisite environment.
	 */
	public function restoreCurrentBlog() {
		if ( ! is_multisite() ) {
			return false;
		}

		return restore_current_blog();
	}
}