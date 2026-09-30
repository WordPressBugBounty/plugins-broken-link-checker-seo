<?php
namespace AIOSEO\BrokenLinkChecker\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the licence that covers a whole network.
 *
 * The key is stored once on the network's main site. Which subsites it actually covers is the
 * licensing server's answer, not ours - a subsite is licensed when its domain and path are among the
 * licence's activations.
 *
 * @since 1.3.1
 */
class NetworkLicense extends License {
	/**
	 * The action name for the periodic license check.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	public $actionName = 'aioseo_blc_network_license_check';

	/**
	 * How long a site's activation state is trusted for.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const CACHE_LIFETIME = DAY_IN_SECONDS;

	/**
	 * Whether each site is activated, for this request.
	 *
	 * @since 1.3.1
	 *
	 * @var array
	 */
	private static $siteActive = [];

	/**
	 * How many of the network's sites one activation lookup asks about.
	 *
	 * Every site costs two hashes in the request. A network past this many is one whose table pages
	 * through them anyway, and Api\Network::fetchSites() asks about the page it is drawing.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const ACTIVATION_LOOKUP_LIMIT = 200;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		$this->internalOptions  = aioseoBrokenLinkChecker()->internalNetworkOptions;
		$this->sensitiveOptions = aioseoBrokenLinkChecker()->networkSensitiveOptions;

		add_action( 'init', [ $this, 'scheduleLicenseCheck' ], 3 );
		add_action( $this->actionName, [ $this, 'checkLicense' ] );
	}

	/**
	 * Validates the stored license within the network's main site context.
	 *
	 * NOTE: Action Scheduler can run this from any subsite, and getSite() would then report that
	 * subsite's domain as the one the licence is anchored to.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function checkLicense() {
		aioseoBrokenLinkChecker()->helpers->switchToBlog( aioseoBrokenLinkChecker()->helpers->getNetworkId() );

		parent::checkLicense();

		aioseoBrokenLinkChecker()->helpers->restoreCurrentBlog();
	}

	/**
	 * Activates the licence, optionally against a specific set of domains.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $newDomains Domains to activate. Defaults to the network's main site.
	 * @return bool              Whether it was activated.
	 */
	protected function activate( $newDomains = [] ) {
		return $this->sendDomainsRequest( 'activate', $newDomains );
	}

	/**
	 * Activates the licence in response to a direct user action.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $newDomains Domains to activate.
	 * @return bool              Whether it was activated.
	 */
	public function activateManual( $newDomains = [] ) {
		return $this->activate( $newDomains );
	}

	/**
	 * Deactivates the licence, optionally against a specific set of domains.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $domains Domains to deactivate. Defaults to the network's main site.
	 * @return bool           Whether it was deactivated.
	 */
	public function deactivate( $domains = [] ) {
		$deactivated = $this->sendDomainsRequest( 'deactivate', $domains );

		if ( $deactivated && empty( $domains ) ) {
			// The whole network is being disconnected, so nothing should keep scanning on the strength
			// of this licence.
			$this->unscheduleNetworkScans();
		}

		return $deactivated;
	}

	/**
	 * Activates and deactivates a set of domains in one request.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $domains An array keyed by 'activate' and/or 'deactivate'.
	 * @return bool           Whether the request succeeded.
	 */
	public function multisite( $domains ) {
		// Empty halves dropped rather than sent: see Api\Network::updateSites() for what the licensing
		// server does with an empty list.
		$domains = array_filter( [
			'activate'   => isset( $domains['activate'] ) ? $domains['activate'] : [],
			'deactivate' => isset( $domains['deactivate'] ) ? $domains['deactivate'] : []
		] );

		if ( empty( $domains ) ) {
			return false;
		}

		$succeeded = $this->sendDomainsRequest( 'multisite', $domains );

		if ( $succeeded && ! empty( $domains['deactivate'] ) ) {
			foreach ( $domains['deactivate'] as $domain ) {
				if ( empty( $domain['blog_id'] ) ) {
					continue;
				}

				$this->unscheduleSiteScans( (int) $domain['blog_id'] );
			}
		}

		return $succeeded;
	}

	/**
	 * Sends an activate, deactivate or multisite request and stores what comes back.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $type    The request type.
	 * @param  array  $domains The domains, or an empty array for the network's main site.
	 * @return bool            Whether the licence data was stored.
	 */
	private function sendDomainsRequest( $type, $domains = [] ) {
		aioseoBrokenLinkChecker()->helpers->switchToBlog( aioseoBrokenLinkChecker()->helpers->getNetworkId() );

		$this->resetLicenseState();

		$licenseKey = $this->sensitiveOptions->get( 'licenseKey' );
		if ( empty( $licenseKey ) ) {
			aioseoBrokenLinkChecker()->helpers->restoreCurrentBlog();

			return false;
		}

		$site = aioseoBrokenLinkChecker()->helpers->getSite();
		if ( empty( $domains ) ) {
			$domains = [
				[
					'domain' => $site->domain,
					'path'   => $site->path
				]
			];
		}

		$response = $this->sendLicenseRequest( $type, $licenseKey, $domains );
		$stored   = $this->storeResponse( $response );

		aioseoBrokenLinkChecker()->helpers->restoreCurrentBlog();

		$this->clearSitesActiveCache();

		return $stored;
	}

	/**
	 * Records the licence state carried by a response.
	 *
	 * @since 1.3.1
	 *
	 * @param  object|null $response The decoded response.
	 * @return bool                  Whether the response described a working licence.
	 */
	private function storeResponse( $response ) {
		if ( empty( $response ) ) {
			$this->internalOptions->internal->license->connectionError = true;

			return false;
		}

		if ( ! empty( $response->error ) ) {
			$errorFlags = [
				'missing-key-or-domain' => 'requestError',
				'not-activated'         => 'requestError',
				'missing-license'       => 'invalid',
				'disabled'              => 'disabled',
				'activations'           => 'activationsError'
			];

			if ( isset( $errorFlags[ $response->error ] ) ) {
				$this->internalOptions->internal->license->{$errorFlags[ $response->error ]} = true;

				return false;
			}

			if ( 'expired' === $response->error ) {
				if ( isset( $response->expires ) ) {
					$this->internalOptions->internal->license->expires = strtotime( (string) $response->expires );
				}

				$this->internalOptions->internal->license->expired = true;

				return false;
			}
		}

		if ( empty( $response->success ) || empty( $response->level ) ) {
			return false;
		}

		$oldQuota = $this->internalOptions->internal->license->quota;
		$newQuota = isset( $response->broken_links_count ) ? intval( $response->broken_links_count ) : (int) $oldQuota;

		$this->internalOptions->internal->license->level = $response->level;
		$this->internalOptions->internal->license->quota = $newQuota;

		if ( isset( $response->expires ) ) {
			$this->internalOptions->internal->license->expires = strtotime( (string) $response->expires );
		}

		$quotaRemaining = $this->internalOptions->internal->license->quotaRemaining;
		if ( ! $quotaRemaining || $newQuota !== (int) $oldQuota ) {
			$this->internalOptions->internal->license->quotaRemaining = $newQuota;
		}

		if ( ! empty( $response->counts ) ) {
			$this->internalOptions->internal->license->counts = wp_json_encode( $response->counts );
		}

		// The network options only persist on shutdown, and an API request ends before that.
		$this->internalOptions->save( true );

		return true;
	}

	/**
	 * Clears the error and plan fields before a request writes fresh ones.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	private function resetLicenseState() {
		$this->internalOptions->internal->license->reset(
			[
				'expires',
				'expired',
				'invalid',
				'disabled',
				'activationsError',
				'connectionError',
				'requestError',
				'level',
				'counts'
			]
		);
	}

	/**
	 * Whether a given site is among the licence's activations.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_Site|object|null $site The site, or null for the current one.
	 * @return bool                       Whether the site is activated.
	 */
	public function isSiteActive( $site = null ) {
		if ( ! is_multisite() || ! $this->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return false;
		}

		if ( empty( $site ) ) {
			$site = \WP_Site::get_instance( get_current_blog_id() );
		}

		if ( empty( $site->domain ) ) {
			return false;
		}

		$path = isset( $site->path ) ? $site->path : '/';
		$key  = $this->normalizeDomain( $site->domain ) . '|' . $this->normalizePath( $path );

		// Held for the request only. This is matchesActivation() over getActivations(), and that lookup is
		// already cached - so a second copy with a lifetime of its own bought nothing and gave the two a
		// way to disagree. A negative was kept for a day, which is the whole bug: a subsite activated in
		// the meantime read as uncovered, and both scanners return early on that, so it scanned nothing
		// until the entry expired. Nothing but the network activation flow ever cleared it.
		if ( isset( self::$siteActive[ $key ] ) ) {
			return self::$siteActive[ $key ];
		}

		self::$siteActive[ $key ] = $this->matchesActivation( $site, $this->getActivations() );

		return self::$siteActive[ $key ];
	}

	/**
	 * Returns which of the given sites are activated.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $sites The sites to check.
	 * @return array        The blog IDs that are activated, keyed by blog ID.
	 */
	public function areSitesActive( $sites ) {
		if ( ! is_multisite() || ! $this->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return [];
		}

		$activations = $this->getActivations();

		$active = [];
		foreach ( $sites as $site ) {
			if ( $this->matchesActivation( $site, $activations ) ) {
				$active[] = $site;
			}
		}

		return $active;
	}

	/**
	 * Returns which of the network's domains and paths the licence is activated on.
	 *
	 * NOTE: The licensing server answers only for the domains it is asked about - its
	 * `all_activations_and_paths` is the activations among those, not the licence's whole list. Asking
	 * about the main site alone therefore reported every subsite as unactivated however many of them
	 * were activated, and an activation that had just succeeded still drew the row as off.
	 *
	 * NOTE: One request answers for the whole network, so the table doesn't make a request per row.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Asks about the network's sites rather than only the one the licence is anchored to.
	 *
	 * @param  array $sites The sites to ask about. Defaults to the network's own, up to the cap.
	 * @return array        The activations.
	 */
	public function getActivations( $sites = [] ) {
		$domains = $this->activationLookupDomains( $sites );
		if ( empty( $domains ) ) {
			return [];
		}

		// Keyed by what was asked about: two callers asking about different sites are not each other's
		// answer.
		$cacheKey = 'license_activations_' . md5( (string) wp_json_encode( $domains ) );

		$cached = aioseoBrokenLinkChecker()->core->networkCache->get( $cacheKey );
		if ( null !== $cached ) {
			return is_array( $cached ) ? $cached : [];
		}

		aioseoBrokenLinkChecker()->helpers->switchToBlog( aioseoBrokenLinkChecker()->helpers->getNetworkId() );

		$licenseKey = $this->sensitiveOptions->get( 'licenseKey' );
		$response   = $this->sendLicenseRequest( 'activated', $licenseKey, $domains );

		aioseoBrokenLinkChecker()->helpers->restoreCurrentBlog();

		// A refusal is not an empty network. Recorded so the screen can say the licence is the problem
		// rather than drawing every site as unactivated and leaving the reader to guess why nothing
		// they do sticks.
		if ( ! empty( $response->error ) ) {
			if ( 'missing-license' === $response->error ) {
				$this->internalOptions->internal->license->invalid = true;
			} else {
				$this->internalOptions->internal->license->requestError = true;
			}
		} elseif ( empty( $response ) ) {
			$this->internalOptions->internal->license->connectionError = true;
		}

		$activations = [];
		if ( ! empty( $response->all_activations_and_paths ) ) {
			foreach ( $response->all_activations_and_paths as $activation ) {
				if ( empty( $activation->domain ) ) {
					continue;
				}

				$activations[] = [
					'domain' => $activation->domain,
					'path'   => isset( $activation->path ) ? $activation->path : '/'
				];
			}
		}

		// Cached even when empty, so a licensing outage doesn't turn into a request per page load. A
		// shorter life than a good answer gets, so it recovers on its own.
		aioseoBrokenLinkChecker()->core->networkCache->update(
			$cacheKey,
			$activations,
			empty( $activations ) ? HOUR_IN_SECONDS : self::CACHE_LIFETIME
		);

		return $activations;
	}

	/**
	 * Returns the domains to ask the licensing server about.
	 *
	 * The network's main site is always among them, since the licence is anchored to it. Each site's
	 * path goes in both shapes it can have been activated with - the server matches on a hash of the
	 * domain and path exactly as given, so a trailing slash is the difference between a match and none.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $sites The sites to ask about, or an empty array for the network's own.
	 * @return array        The domains.
	 */
	private function activationLookupDomains( $sites = [] ) {
		if ( ! is_multisite() ) {
			return [];
		}

		if ( empty( $sites ) ) {
			$sites = get_sites( [
				'network_id' => get_current_network_id(),
				'number'     => self::ACTIVATION_LOOKUP_LIMIT
			] );
		}

		$mainSite = get_site( aioseoBrokenLinkChecker()->helpers->getNetworkId() );
		if ( is_a( $mainSite, 'WP_Site' ) ) {
			$sites = array_merge( [ $mainSite ], (array) $sites );
		}

		$domains = [];
		foreach ( (array) $sites as $site ) {
			$domain = isset( $site->domain ) ? (string) $site->domain : '';
			if ( '' === $domain ) {
				continue;
			}

			$path    = isset( $site->path ) ? (string) $site->path : '/';
			$aliases = [ $domain ];

			foreach ( (array) ( isset( $site->aliases ) ? $site->aliases : [] ) as $alias ) {
				if ( ! empty( $alias['domain'] ) ) {
					$aliases[] = (string) $alias['domain'];
				}
			}

			foreach ( $aliases as $aliasDomain ) {
				foreach ( array_unique( [ $path, '/' === $path ? '' : untrailingslashit( $path ) ] ) as $pathShape ) {
					$domains[ $aliasDomain . '|' . $pathShape ] = [
						'domain' => $aliasDomain,
						'path'   => $pathShape
					];
				}
			}
		}

		return array_values( $domains );
	}

	/**
	 * Whether a site's domain and path, or one of its aliases, is among the activations.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_Site|object $site        The site.
	 * @param  array           $activations The activations.
	 * @return bool                         Whether it matches.
	 */
	public function matchesActivation( $site, $activations ) {
		$domain = $this->normalizeDomain( isset( $site->domain ) ? $site->domain : '' );
		$path   = $this->normalizePath( isset( $site->path ) ? $site->path : '/' );

		foreach ( $activations as $activation ) {
			$activationDomain = $this->normalizeDomain( isset( $activation['domain'] ) ? $activation['domain'] : '' );

			if (
				$domain === $activationDomain &&
				$path === $this->normalizePath( isset( $activation['path'] ) ? $activation['path'] : '/' )
			) {
				return true;
			}

			if ( empty( $site->aliases ) ) {
				continue;
			}

			foreach ( $site->aliases as $alias ) {
				if ( ! empty( $alias['domain'] ) && $this->normalizeDomain( $alias['domain'] ) === $activationDomain ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Returns a site path in the one shape both sides of a comparison can be held to.
	 *
	 * NOTE: WP_Site carries a trailing slash and the licensing server does not have to. Compared as
	 * written, /subdir and /subdir/ are two different sites, and a subsite whose activation came back in
	 * the other shape reads as never activated at all.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $path The path.
	 * @return string       The comparable path.
	 */
	private function normalizePath( $path ) {
		$path = trim( (string) $path );
		$path = '' === $path ? '/' : $path;

		return '/' === $path ? '/' : '/' . trim( $path, '/' );
	}

	/**
	 * Returns a domain in the one shape both sides of a comparison can be held to.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $domain The domain.
	 * @return string         The comparable domain.
	 */
	private function normalizeDomain( $domain ) {
		return strtolower( trim( (string) $domain ) );
	}

	/**
	 * Clears the cached activation state for the whole network.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function clearSitesActiveCache() {
		self::$siteActive = [];

		aioseoBrokenLinkChecker()->core->networkCache->clearPrefix( 'license_activations' );

		// Nothing writes these any more. Cleared so the rows an earlier version stored, which is where the
		// stale negatives live, go with the activation that would have been wrong about them.
		aioseoBrokenLinkChecker()->core->networkCache->clearPrefix( 'site_active_' );
	}

	/**
	 * Stops the scans on every site of the network.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	private function unscheduleNetworkScans() {
		$sites = get_sites( [
			'network_id' => get_current_network_id(),
			'number'     => 0,
			'fields'     => 'ids'
		] );

		foreach ( $sites as $blogId ) {
			$this->unscheduleSiteScans( (int) $blogId );
		}
	}

	/**
	 * Stops the scans on one site.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $blogId The blog ID.
	 * @return void
	 */
	private function unscheduleSiteScans( $blogId ) {
		aioseoBrokenLinkChecker()->helpers->switchToBlog( $blogId );

		// A site that holds its own licence keeps scanning under it.
		if ( ! aioseoBrokenLinkChecker()->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			as_unschedule_all_actions( aioseoBrokenLinkChecker()->main->linkStatus->actionName );
			as_unschedule_all_actions( aioseoBrokenLinkChecker()->main->localScan->actionName );
		}

		aioseoBrokenLinkChecker()->helpers->restoreCurrentBlog();
	}

	/**
	 * Checks whether the licence is expired.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether it is expired.
	 */
	public function isExpired() {
		if ( ! $this->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return false;
		}

		if ( $this->internalOptions->internal->license->expired ) {
			return true;
		}

		$expires = $this->internalOptions->internal->license->expires;

		return 0 !== $expires && $expires < time();
	}

	/**
	 * Checks whether the licence is disabled.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether it is disabled.
	 */
	public function isDisabled() {
		if ( ! $this->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return false;
		}

		return (bool) $this->internalOptions->internal->license->disabled;
	}

	/**
	 * Checks whether the licence is invalid.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether it is invalid.
	 */
	public function isInvalid() {
		if ( ! $this->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return false;
		}

		return (bool) $this->internalOptions->internal->license->invalid;
	}

	/**
	 * Checks whether the licence covers the given site.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_Site|object|null $site The site, or null for the current one.
	 * @return bool                       Whether the licence is active for it.
	 */
	public function isActive( $site = null ) {
		if ( ! $this->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return false;
		}

		if ( ! $this->isSiteActive( $site ) ) {
			return false;
		}

		return ! $this->isExpired() && ! $this->isDisabled() && ! $this->isInvalid();
	}

	/**
	 * Checks whether the licence works at all, regardless of which sites it covers.
	 *
	 * Separated from isActive() because the network admin screen has to distinguish "no licence" from
	 * "a working licence that this subsite is not on".
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the licence itself is in good standing.
	 */
	public function isValid() {
		if ( ! $this->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return false;
		}

		return ! $this->isExpired() && ! $this->isDisabled() && ! $this->isInvalid();
	}

	/**
	 * Checks whether a network licence key is stored, whatever state it is in.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether a key is stored.
	 */
	public function isConnected() {
		return (bool) $this->sensitiveOptions->hasValue( 'licenseKey' );
	}

	/**
	 * Checks whether the network connected but its licence no longer works.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the licence has lapsed.
	 */
	public function isLapsed() {
		return $this->isConnected() && ! $this->isValid();
	}

	/**
	 * Returns the licence level.
	 *
	 * @since 1.3.1
	 *
	 * @return string The licence level.
	 */
	public function getLicenseLevel() {
		if ( ! $this->sensitiveOptions->hasValue( 'licenseKey' ) ) {
			return '';
		}

		return $this->internalOptions->internal->license->level;
	}

	/**
	 * Never falls back to itself.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Always false.
	 */
	public function isNetworkLicensed() {
		return false;
	}
}