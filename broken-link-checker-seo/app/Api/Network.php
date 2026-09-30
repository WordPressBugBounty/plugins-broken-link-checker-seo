<?php
namespace AIOSEO\BrokenLinkChecker\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the network's site list and the activations made against it.
 *
 * @since 1.3.1
 */
class Network {
	/**
	 * Whether the caller may act on the whole network.
	 *
	 * NOTE: Checked here rather than relying on the route's access entry. validateAccess() returns early
	 * for anyone Access::isAdmin() accepts, and on a multisite that includes a subsite administrator -
	 * who must not be able to license or unlicense other people's sites.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the caller is a network administrator.
	 */
	private static function canManageNetwork() {
		return is_multisite() && current_user_can( 'manage_network_options' );
	}

	/**
	 * Returns the response sent to a caller without network permissions.
	 *
	 * @since 1.3.1
	 *
	 * @return \WP_REST_Response The response.
	 */
	private static function forbidden() {
		return new \WP_REST_Response( [
			'success' => false,
			'message' => 'You do not have permission to manage this network.'
		], 403 );
	}

	/**
	 * Returns the network's sites, with whether the licence covers each one.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchSites( $request ) {
		if ( ! self::canManageNetwork() ) {
			return self::forbidden();
		}

		$body       = $request->get_json_params();
		$filter     = ! empty( $body['filter'] ) ? sanitize_text_field( $body['filter'] ) : 'all';
		$orderBy    = ! empty( $body['orderBy'] ) ? sanitize_text_field( $body['orderBy'] ) : 'domain';
		$orderDir   = ! empty( $body['orderDir'] ) ? strtoupper( sanitize_text_field( $body['orderDir'] ) ) : 'ASC';
		$limit      = isset( $body['limit'] ) ? (int) $body['limit'] : 20;
		$offset     = isset( $body['offset'] ) ? (int) $body['offset'] : 0;
		$searchTerm = ! empty( $body['searchTerm'] ) ? sanitize_text_field( $body['searchTerm'] ) : null;

		if ( ! in_array( $filter, [ 'all', 'activated', 'deactivated' ], true ) ) {
			$filter = 'all';
		}

		if ( ! in_array( $orderBy, [ 'domain', 'path' ], true ) ) {
			$orderBy = 'domain';
		}

		$results = aioseoBrokenLinkChecker()->helpers->getSites( $limit, $offset, $searchTerm, $filter, $orderBy, $orderDir );
		// The page being drawn, not the network: the licensing server answers only for the domains it is
		// asked about, so the rows on screen are what it has to be asked about.
		$activations = aioseoBrokenLinkChecker()->networkLicense->getActivations( $results['sites'] );

		return new \WP_REST_Response( [
			'success' => true,
			'rows'    => array_map( function ( $site ) use ( $activations ) {
				return self::formatSite( $site, $activations );
			}, $results['sites'] ),
			'totals'  => self::getTotals( $results['total'], $limit, $offset )
		], 200 );
	}

	/**
	 * Activates and deactivates the licence on the given sites.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function updateSites( $request ) {
		if ( ! self::canManageNetwork() ) {
			return self::forbidden();
		}

		$body           = $request->get_json_params();
		$networkLicense = aioseoBrokenLinkChecker()->networkLicense;

		if ( ! $networkLicense->isConnected() ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No network license key is stored.'
			], 400 );
		}

		$activate   = self::sanitizeDomains( isset( $body['activate'] ) ? $body['activate'] : [] );
		$deactivate = self::sanitizeDomains( isset( $body['deactivate'] ) ? $body['deactivate'] : [] );

		if ( empty( $activate ) && empty( $deactivate ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No sites given.'
			], 400 );
		}

		// Only the half being asked for. An empty key is not the same as an absent one to the licensing
		// server: it walks whichever keys are present, and an empty list falls through its own guard to a
		// single null domain - so sending "deactivate": [] alongside an activation ran a real deactivation
		// against nothing and answered with that leg's result.
		$domains = [];
		if ( ! empty( $activate ) ) {
			$domains['activate'] = $activate;
		}

		if ( ! empty( $deactivate ) ) {
			$domains['deactivate'] = $deactivate;
		}

		$succeeded   = $networkLicense->multisite( $domains );
		$licenseData = aioseoBrokenLinkChecker()->internalNetworkOptions->internal->license->all();
		unset( $licenseData['licenseKey'] );

		if ( ! $succeeded ) {
			return new \WP_REST_Response( [
				'success'     => false,
				'licenseData' => $licenseData
			], 400 );
		}

		return new \WP_REST_Response( [
			'success'     => true,
			'licenseData' => $licenseData
		], 200 );
	}

	/**
	 * Keeps only the domain, path and blog ID of each given site.
	 *
	 * NOTE: The blog ID is carried through so a deactivation can stop that site's scans, but it is
	 * never trusted as the source of the domain - that is read back from the site itself.
	 *
	 * @since 1.3.1
	 *
	 * @param  mixed $sites The sites as given.
	 * @return array        The sanitized sites.
	 */
	private static function sanitizeDomains( $sites ) {
		if ( ! is_array( $sites ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $sites as $site ) {
			if ( empty( $site['domain'] ) ) {
				continue;
			}

			$entry = [
				'domain' => sanitize_text_field( $site['domain'] ),
				'path'   => ! empty( $site['path'] ) ? sanitize_text_field( $site['path'] ) : '/'
			];

			if ( ! empty( $site['blog_id'] ) ) {
				$entry['blog_id'] = (int) $site['blog_id'];
			}

			$sanitized[] = $entry;
		}

		return $sanitized;
	}

	/**
	 * Shapes one site for the table.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_Site|object $site        The site.
	 * @param  array           $activations The licence's activations.
	 * @return array                        The row.
	 */
	private static function formatSite( $site, $activations ) {
		$domain = isset( $site->domain ) ? $site->domain : '';
		$path   = isset( $site->path ) ? $site->path : '/';

		return [
			// Domain and path, not the blog ID: an alias row shares its parent's blog ID, and the table
			// identifies a selected row by this value alone.
			'rowIndex'          => 'uniqueId',
			'uniqueId'          => $domain . $path,
			'blog_id'           => isset( $site->blog_id ) ? (int) $site->blog_id : 0,
			'domain'            => $domain,
			'path'              => $path,
			'primaryDomain'     => ! empty( $site->parentDomain ) ? $site->parentDomain : '',
			'homeUrl'           => isset( $site->homeUrl ) ? $site->homeUrl : '',
			'adminUrl'          => isset( $site->adminUrl ) ? $site->adminUrl : '',
			'isMain'            => ! empty( $site->isMain ),
			// The licence is anchored to the primary site, so it gets no checkbox to be swept up by a
			// bulk deactivation.
			'preventBulkAction' => ! empty( $site->isMain ),
			'activated'         => aioseoBrokenLinkChecker()->networkLicense->matchesActivation( $site, $activations )
		];
	}

	/**
	 * Builds the paging totals the table expects.
	 *
	 * @since 1.3.1
	 *
	 * @param  int $total  The total number of sites.
	 * @param  int $limit  The page size.
	 * @param  int $offset Where the current page starts.
	 * @return array       The totals.
	 */
	private static function getTotals( $total, $limit, $offset ) {
		$limit = max( 1, (int) $limit );

		return [
			'total' => (int) $total,
			'pages' => (int) ceil( $total / $limit ),
			'page'  => (int) floor( $offset / $limit ) + 1
		];
	}
}