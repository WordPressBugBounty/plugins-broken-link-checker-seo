<?php
namespace AIOSEO\BrokenLinkChecker\Options;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;
use AIOSEO\BrokenLinkChecker\Traits;

/**
 * Handles the main options.
 *
 * @since 1.0.0
 */
class Options {
	use Traits\Options;

	/**
	 * How many URL exclusion patterns we keep.
	 *
	 * NOTE: Every text pattern is a NOT LIKE term on the query that picks the links to check, which
	 * runs while a scan is polled. A list into the thousands costs that query tens of seconds.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $maxUrlPatterns = 100;

	/**
	 * How long a single URL exclusion pattern can be.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $maxUrlPatternLength = 500;

	/**
	 * All the default options.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $defaults = [
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		'general'  => [
			'linkTweaks'           => [
				'nofollowBroken'    => [ 'type' => 'boolean', 'default' => false ],
				'limitModifiedDate' => [ 'type' => 'boolean', 'default' => false ]
			],
			'highlightBrokenLinks' => [ 'type' => 'boolean', 'default' => true ],
			'scanFrequency'        => [ 'type' => 'string', 'default' => 'weekly' ],
			'scanSources'          => [
				'customFields' => [ 'type' => 'boolean', 'default' => true ],
				'navMenus'     => [ 'type' => 'boolean', 'default' => true ],
				'terms'        => [ 'type' => 'boolean', 'default' => true ],
				'authorBios'   => [ 'type' => 'boolean', 'default' => true ],
				'patterns'     => [ 'type' => 'boolean', 'default' => true ],
				'templates'    => [ 'type' => 'boolean', 'default' => true ]
			],
			'emailReports'         => [
				'enable'     => [ 'type' => 'boolean', 'default' => true ],
				'recipients' => [ 'type' => 'array', 'default' => [] ]
			]
		],
		'advanced' => [
			'postTypes'          => [
				'all'      => [ 'type' => 'boolean', 'default' => true ],
				'included' => [ 'type' => 'array', 'default' => [ 'post', 'page' ] ]
			],
			'postStatuses'       => [
				'all'      => [ 'type' => 'boolean', 'default' => false ],
				'included' => [ 'type' => 'array', 'default' => [ 'publish', 'draft', 'pending', 'future', 'private' ] ]
			],
			'excludePosts'       => [ 'type' => 'array', 'default' => [] ],
			'excludeDomains'     => [ 'type' => 'html', 'default' => '' ],
			'excludeUrlPatterns' => [ 'type' => 'html', 'default' => '' ],
			'uninstall'          => [ 'type' => 'boolean', 'default' => false ]
		]
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	];

	/**
	 * The Construct method.
	 *
	 * @since 1.0.0
	 *
	 * @param string $optionsName An array of options.
	 */
	public function __construct( $optionsName = 'aioseo_blc_options' ) {
		$this->optionsName = $optionsName;

		$this->init();

		add_action( 'shutdown', [ $this, 'save' ] );
	}

	/**
	 * Initializes the options.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function init() {
		$options = $this->getBrokenLinkCheckerDbOptions();

		aioseoBrokenLinkChecker()->core->optionsCache->setOptions( $this->optionsName, apply_filters( 'aioseo_blc_get_options', $options ) );
	}

	/**
	 * Get the DB options.
	 *
	 * @since 1.0.0
	 *
	 * @return array An array of options.
	 */
	public function getBrokenLinkCheckerDbOptions() {
		// Options from the DB.
		$dbOptions = $this->getDbOptions( $this->optionsName );

		// Refactor options.
		$this->defaultsMerged = array_replace_recursive( $this->defaults, $this->defaultsMerged );

		return array_replace_recursive(
			$this->defaultsMerged,
			$this->addValueToValuesArray( $this->defaultsMerged, $dbOptions )
		);
	}

	/**
	 * Sanitizes, then saves the options to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $newOptions An array of options to sanitize, then save.
	 * @return void
	 */
	public function sanitizeAndSave( $newOptions ) {
		$this->init();

		if ( ! is_array( $newOptions ) ) {
			return;
		}

		// First, recursively replace the new options into the cached state.
		// It's important we use the helper method since we want to replace populated arrays with empty ones if needed (when a setting was cleared out).
		$cachedOptions = aioseoBrokenLinkChecker()->core->optionsCache->getOptions( $this->optionsName );
		$dbOptions     = aioseoBrokenLinkChecker()->helpers->arrayReplaceRecursive(
			$cachedOptions,
			$this->addValueToValuesArray( $cachedOptions, $newOptions, [], true )
		);

		// Now, we must also intersect both arrays to delete any individual keys that were unset.
		// We must do this because, while arrayReplaceRecursive will update the values for keys or empty them out,
		// it will keys that aren't present in the replacement array unaffected in the target array.
		$dbOptions = aioseoBrokenLinkChecker()->helpers->arrayIntersectRecursive(
			$dbOptions,
			$this->addValueToValuesArray( $cachedOptions, $newOptions, [], true ),
			'value'
		);

		if ( isset( $newOptions['advanced']['excludeDomains'] ) ) {
			$dbOptions['advanced']['excludeDomains'] = preg_replace( '/\h/', "\n", (string) $newOptions['advanced']['excludeDomains'] );
		}

		if ( isset( $newOptions['advanced']['excludeUrlPatterns'] ) ) {
			$previousPatterns = ! empty( $cachedOptions['advanced']['excludeUrlPatterns']['value'] )
				? $cachedOptions['advanced']['excludeUrlPatterns']['value']
				: '';

			$patterns = $this->sanitizeUrlPatterns( $newOptions['advanced']['excludeUrlPatterns'] );

			$dbOptions['advanced']['excludeUrlPatterns']['value'] = $patterns;

			if ( $patterns !== $previousPatterns ) {
				// The recurring scan unschedules itself and idles when nothing is due, so the idle
				// state has to go for a changed list to take effect before it expires.
				aioseoBrokenLinkChecker()->core->cache->delete( 'as_blc_link_status_idle' );
			}
		}

		// Exclusion is decided when a link is stored, so a changed list has to be applied to what is
		// already there: the links it now covers are deleted outright, which is what takes them out of
		// the report, the counts and the check queue at once. Narrowing or dropping a pattern instead
		// means links have to come back, and only a reindex can find them again.
		$patternsBefore = $this->excludedUrlPatterns( $cachedOptions );
		$patternsAfter  = $this->excludedUrlPatterns( $dbOptions );

		if ( $patternsBefore !== $patternsAfter ) {
			$raw = isset( $dbOptions['advanced']['excludeUrlPatterns']['value'] )
				? $dbOptions['advanced']['excludeUrlPatterns']['value']
				: '';

			Models\Link::deleteExcluded( aioseoBrokenLinkChecker()->helpers->splitUrlPatterns( $raw ) );

			// Only something that was narrowed or dropped has to come back, and only a reindex finds it.
			if ( array_diff( $patternsBefore, $patternsAfter ) ) {
				aioseoBrokenLinkChecker()->main->objectScan->requeueEverything();
			}
		}

		if ( isset( $newOptions['general']['scanFrequency'] ) ) {
			$previousFrequency = ! empty( $cachedOptions['general']['scanFrequency']['value'] )
				? $cachedOptions['general']['scanFrequency']['value']
				: 'weekly';

			$scanFrequency = 'monthly' === $newOptions['general']['scanFrequency'] ? 'monthly' : 'weekly';

			$dbOptions['general']['scanFrequency']['value'] = $scanFrequency;

			if ( $scanFrequency !== $previousFrequency ) {
				// The recurring scan unschedules itself and idles when nothing is due, so the idle
				// state has to go for a shortened window to take effect before it expires.
				aioseoBrokenLinkChecker()->core->cache->delete( 'as_blc_link_status_idle' );
			}
		}

		if ( isset( $newOptions['general']['scanSources'] ) ) {
			$this->applyScanSources( $cachedOptions, $dbOptions );
		}

		if ( isset( $newOptions['general']['emailReports']['recipients'] ) ) {
			// Assigned to the 'value' key rather than the node: filterRecursively() drops the members
			// of a bare list because they have no counterpart in the defaults.
			$dbOptions['general']['emailReports']['recipients']['value'] = $this->sanitizeRecipients(
				$newOptions['general']['emailReports']['recipients']
			);
		}

		// Update the cache state.
		aioseoBrokenLinkChecker()->core->optionsCache->setOptions( $this->optionsName, $dbOptions );

		// Finally, save the new values to the DB.
		$this->save( true );
	}

	/**
	 * Reacts to a source being switched on or off.
	 *
	 * The links a source recorded are kept either way and filtered out of the report by
	 * {@see \AIOSEO\BrokenLinkChecker\Models\Link::applyObjectScope()} while it is off, which is what takes them out of the counts.
	 * Deleting them would throw away the dismissals the user made against those URLs and spend quota on
	 * them again if the source ever came back.
	 *
	 * Only where the scan got to moves: the source that changed is walked again from the beginning,
	 * whichever way it was switched.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Keeps the links a disabled source recorded.
	 * @version 1.3.1 Re-queues the posts for a source that is scanned along with them.
	 *
	 * @param  array $cachedOptions The options as they were, in their stored shape.
	 * @param  array $newOptions    The options as they will be, in their stored shape.
	 * @return void
	 */
	private function applyScanSources( $cachedOptions, $newOptions ) {
		$changed = false;

		foreach ( aioseoBrokenLinkChecker()->objects->all() as $slug => $objectType ) {
			$settingKey = $objectType->settingKey();
			if ( ! $settingKey ) {
				continue;
			}

			$was = $this->scanSourceEnabled( $cachedOptions, $settingKey );
			$is  = $this->scanSourceEnabled( $newOptions, $settingKey );
			if ( $was === $is ) {
				continue;
			}

			$changed = true;

			// A post-backed source has no sweep of its own — the post scan is what revisits it, and only
			// a post whose content changed is in that queue. The watermark puts every post back in it.
			if ( $objectType->isPostBacked() ) {
				// UTC, which is what the scan date it is compared against is stored in.
				aioseoBrokenLinkChecker()->scanState->setMinimumLinkScanDate( gmdate( 'Y-m-d H:i:s' ) );

				continue;
			}

			aioseoBrokenLinkChecker()->scanState->setObjectScan( $slug, 0, 0 );
		}

		if ( $changed ) {
			// The links scan unschedules itself and idles when nothing is due, so the idle state has to
			// go for a source that just came back to be picked up before it expires.
			aioseoBrokenLinkChecker()->core->cache->delete( 'as_blc_links_scan_idle' );
		}
	}

	/**
	 * Whether the given source is switched on in the given options.
	 *
	 * NOTE: A source only gains a `value` once the settings have been saved, so the one that was never
	 * submitted has to be read from its default. Reading it as absent instead made the first save look
	 * like every source had just been switched on.
	 *
	 * @since 1.3.1
	 *
	 * @param  array  $options    The options, in their stored shape.
	 * @param  string $settingKey The key in the per-source settings group.
	 * @return bool               Whether it is on.
	 */
	private function scanSourceEnabled( $options, $settingKey ) {
		$source = isset( $options['general']['scanSources'][ $settingKey ] )
			? $options['general']['scanSources'][ $settingKey ]
			: [];

		return array_key_exists( 'value', $source )
			? ! empty( $source['value'] )
			: ! empty( $source['default'] );
	}

	/**
	 * Returns the URL exclusion patterns the given options hold, of both kinds.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $options The options, in their stored shape.
	 * @return array          The patterns, sorted so that the list's order isn't a difference.
	 */
	private function excludedUrlPatterns( $options ) {
		$raw = isset( $options['advanced']['excludeUrlPatterns']['value'] )
			? $options['advanced']['excludeUrlPatterns']['value']
			: '';

		$patterns = aioseoBrokenLinkChecker()->helpers->splitUrlPatterns( $raw );

		// Every bucket, host included. Leaving host out meant a host-only edit produced two identical
		// snapshots, so the guard that prunes and requeues never opened - the one form the field's own
		// help text uses as its example.
		//
		// Tagged with its bucket rather than merged bare, so the same string written two ways counts as a
		// change from one to the other.
		$all = [];
		foreach ( [ 'text', 'regex', 'host' ] as $bucket ) {
			foreach ( (array) ( isset( $patterns[ $bucket ] ) ? $patterns[ $bucket ] : [] ) as $pattern ) {
				$all[] = $bucket . ':' . $pattern;
			}
		}

		sort( $all );

		return $all;
	}

	/**
	 * Normalizes the submitted URL exclusion patterns to one trimmed, unique pattern per line.
	 *
	 * NOTE: Deliberately skips the generic textarea sanitising, which strips percent-encoded octets
	 * and tag-like sequences - both legitimate in a URL pattern, the latter in a lookbehind. An
	 * over-long line is dropped rather than cut short, which would change what it matches.
	 *
	 * @since 1.3.1
	 *
	 * @param  mixed  $patterns The submitted patterns.
	 * @return string           The normalized patterns, capped.
	 */
	private function sanitizeUrlPatterns( $patterns ) {
		if ( ! is_string( $patterns ) ) {
			return '';
		}

		$sanitized = [];
		foreach ( preg_split( '/\R/', $patterns ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || $this->maxUrlPatternLength < strlen( $line ) ) {
				continue;
			}

			$sanitized[ $line ] = $line;

			if ( $this->maxUrlPatterns <= count( $sanitized ) ) {
				break;
			}
		}

		return implode( "\n", $sanitized );
	}

	/**
	 * Turns the report emails on, addressed to whoever installed the plugin.
	 *
	 * NOTE: For a fresh install only. Running it against an existing site would start mailing people who
	 * never asked for it. The site admin address is left out deliberately - it is often a shared or
	 * unattended inbox, and every other administrator is offered the reports by {@see Admin\ActivationNotice}.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Addresses the activating user only.
	 *
	 * @param  int  $userId The user who activated the plugin.
	 * @return void
	 */
	public function seedEmailReports( $userId ) {
		$user = get_userdata( (int) $userId );
		if ( ! is_a( $user, 'WP_User' ) ) {
			return;
		}

		$recipients = $this->sanitizeRecipients( [ $user->user_email ] );
		if ( empty( $recipients ) ) {
			return;
		}

		$this->general->emailReports->recipients = $recipients;
		$this->general->emailReports->enable     = true;

		$this->save( true );
	}

	/**
	 * Drops invalid and duplicate addresses from a report recipients list.
	 *
	 * NOTE: Judged as submitted, not as sanitize_email() leaves it. That function strips whatever an
	 * address may not hold, which turns a typo into a different address that is perfectly valid - two
	 * addresses pasted with a comma became one that belonged to nobody, and reports went there.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Validates the address as submitted, so a mangled one is dropped not stored.
	 *
	 * @param  mixed    $recipients The submitted recipients.
	 * @return string[]             The valid, deduplicated addresses.
	 */
	public function sanitizeRecipients( $recipients ) {
		$sanitized = [];
		foreach ( (array) $recipients as $recipient ) {
			if ( ! is_string( $recipient ) ) {
				continue;
			}

			if ( ! is_email( trim( $recipient ) ) ) {
				continue;
			}

			$recipient = sanitize_email( $recipient );
			if ( ! $recipient || ! is_email( $recipient ) ) {
				continue;
			}

			// Addresses that differ only in case are one person, so the last spelling submitted wins.
			$sanitized[ strtolower( $recipient ) ] = $recipient;
		}

		return array_values( $sanitized );
	}
}