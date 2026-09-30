<?php
namespace AIOSEO\BrokenLinkChecker\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Services\BrokenLinksService;

/**
 * Registers Broken Link Checker's abilities with the WordPress Abilities API.
 *
 * Abilities are exposed at /wp-json/wp-abilities/v1/ when the Abilities API is present, and are
 * additionally surfaced as MCP tools when a compatible MCP adapter is installed alongside it.
 *
 * This class is the registration surface only — all logic lives in Services\*. Each callback is a
 * thin delegate that instantiates the relevant service and forwards the input.
 *
 * @since 1.3.1
 */
class Abilities {
	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'registerCategories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'registerAbilities' ] );
	}

	/**
	 * Registers the Broken Link Checker ability categories.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function registerCategories() {
		$categories = [
			'aioseo-blc-links' => __( 'Broken Link Checker — Links', 'broken-link-checker-seo' )
		];

		foreach ( $categories as $slug => $label ) {
			wp_register_ability_category( $slug, [
				'label'       => $label,
				'description' => __( 'Broken link management abilities provided by Broken Link Checker.', 'broken-link-checker-seo' )
			] );
		}
	}

	/**
	 * Registers all abilities.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function registerAbilities() {
		$this->registerLinkAbilities();
		$this->registerScanAbilities();
	}

	/**
	 * Registers the link abilities.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	protected function registerLinkAbilities() {
		wp_register_ability( 'aioseo-blc-links/list', [
			'label'               => __( 'List Links', 'broken-link-checker-seo' ),
			'description'         => __( 'Returns a page of the Broken Links Report: every link found on the site with its check result, filterable to broken links, redirects, working links, links still pending a check, or dismissed ones. Links are found in post content, custom fields, term descriptions, user bios and navigation menus; object_types says which of those a given URL sits in. The location is only included for occurrences the caller may edit, and only when the link was found in exactly one place.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'category'            => 'aioseo-blc-links',
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'filter'   => [
						'type'        => 'string',
						'enum'        => [ 'all', 'broken', 'redirects', 'good', 'not-checked', 'dismissed' ],
						'default'     => 'all',
						'description' => __( 'Which links to return. "all" excludes dismissed links.', 'broken-link-checker-seo' )
					],
					'source'   => [
						'type'        => 'string',
						'enum'        => array_keys( aioseoBrokenLinkChecker()->objects->all() ),
						'description' => __( 'Narrows the results to links found in one kind of thing: post content, custom fields, term descriptions, user bios or navigation menus. Omit for all of them.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					],
					'search'   => [
						'type'        => 'string',
						'description' => __( 'Substring filter applied to the link URL, its anchor text, and the title and slug of the post it was found in. A number also matches that post ID. Occurrences outside post content are matched on the URL and anchor text alone.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					],
					'orderBy'  => [
						'type'    => 'string',
						'enum'    => [ 'id', 'url', 'last_checked', 'http_status_code', 'redirect_count' ],
						'default' => 'id'
					],
					'orderDir' => [
						'type'    => 'string',
						'enum'    => [ 'asc', 'desc' ],
						'default' => 'desc'
					],
					'limit'    => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20
					],
					'offset'   => [
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0
					]
				],
				'additionalProperties' => false,
				// All properties are optional — default the whole input to an empty object so
				// a no-argument call (the natural "list" prompt from an MCP agent) validates.
				'default'              => []
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'links'  => [
						'type'  => 'array',
						'items' => $this->linkSchema( true, true )
					],
					'total'  => [ 'type' => 'integer' ],
					'page'   => [ 'type' => 'integer' ],
					'pages'  => [ 'type' => 'integer' ],
					'filter' => [ 'type' => 'string' ],
					'source' => [
						'type'        => 'string',
						'description' => __( 'The source the results were narrowed to, or empty when they were not. A source that is not registered is ignored rather than rejected, so this is how to tell it was applied.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					]
				]
			],
			'execute_callback'    => [ $this, 'listLinks' ],
			'permission_callback' => [ $this, 'canManageBrokenLinks' ],
			'meta'                => $this->readonlyMeta()
		] );

		wp_register_ability( 'aioseo-blc-links/get', [
			'label'               => __( 'Get Link', 'broken-link-checker-seo' ),
			'description'         => __( 'Returns one link from the Broken Links Report together with every place it was found — post content, custom fields, term descriptions, user bios or navigation menus. Only occurrences the caller may edit are listed, while occurrences_total counts them all.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'category'            => 'aioseo-blc-links',
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'linkStatusId' => $this->linkStatusIdSchema(),
					'limit'        => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 20,
						'description' => __( 'How many occurrences to return.', 'broken-link-checker-seo' )
					],
					'offset'       => [
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0
					]
				],
				'required'             => [ 'linkStatusId' ],
				'additionalProperties' => false
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'link'              => $this->linkSchema( true, false ),
					'occurrences'       => [
						'type'  => 'array',
						'items' => $this->occurrenceSchema()
					],
					'occurrences_total' => [ 'type' => 'integer' ]
				]
			],
			'execute_callback'    => [ $this, 'getLink' ],
			'permission_callback' => [ $this, 'canManageBrokenLinks' ],
			'meta'                => $this->readonlyMeta()
		] );

		wp_register_ability( 'aioseo-blc-links/summary-get', [
			'label'               => __( 'Get Link Summary', 'broken-link-checker-seo' ),
			'description'         => __( 'Returns how many links fall into each state the Broken Links Report reports on, plus how many links are indexed in total. Useful for "how healthy are my links?" prompts.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'category'            => 'aioseo-blc-links',
			'input_schema'        => $this->noInputSchema(),
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'counts'      => [
						'type'        => 'object',
						'description' => __( 'How many links each of the report\'s filters lists, counted per distinct URL and covering only the post types and post statuses the scan includes.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
						'properties'  => [
							'all'         => [
								'type'        => 'integer',
								'description' => __( 'Every link the report lists, dismissed ones excluded.', 'broken-link-checker-seo' )
							],
							'broken'      => [ 'type' => 'integer' ],
							'redirects'   => [ 'type' => 'integer' ],
							'good'        => [ 'type' => 'integer' ],
							'not_checked' => [ 'type' => 'integer' ],
							'dismissed'   => [ 'type' => 'integer' ]
						]
					],
					'total_links' => [
						'type'        => 'integer',
						'description' => __( 'Every link indexed on the site, dismissed ones excluded. Counted per stored link rather than per report row, and without the post type, post status and excluded post filters counts.all applies, so the two do not have to match.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					],
					'sources'     => [
						'type'        => 'object',
						'description' => __( 'How many links were found in each kind of thing, keyed by object type — post, post_meta, term, user, menu_item — for the sources the scan has switched on.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					]
				]
			],
			'execute_callback'    => [ $this, 'getSummary' ],
			'permission_callback' => [ $this, 'canManageBrokenLinks' ],
			'meta'                => $this->readonlyMeta()
		] );

		wp_register_ability( 'aioseo-blc-links/update', [
			'label'               => __( 'Update Link URL', 'broken-link-checker-seo' ),
			'description'         => __( 'Rewrites a link wherever it was found — post content, a custom field, a term description, an author bio or a navigation menu — replacing its URL and optionally its anchor text. Covers every occurrence of the link unless linkId narrows it to one. Anchor text can only be changed for a single occurrence, and only where there is text to rewrite. Rejected without changing anything if the caller cannot edit everything involved, if the replacement URL is one the site may not link to, or if a kind involved will not take a new URL — a menu item pointing at a post takes its URL from that post. available_actions on an occurrence says up front what it will accept.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'category'            => 'aioseo-blc-links',
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'linkStatusId' => $this->linkStatusIdSchema(),
					'linkId'       => $this->linkIdSchema(),
					'url'          => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The replacement URL. May be an absolute URL, or a path such as "/new-page/" or "new-page/", which is stored as given. Schemes a link cannot carry, such as "javascript:", are rejected.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					],
					'anchor'       => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The replacement anchor text. Requires linkId.', 'broken-link-checker-seo' )
					]
				],
				'required'             => [ 'linkStatusId' ],
				'additionalProperties' => false
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'attempted'       => [ 'type' => 'integer' ],
					'updated'         => [
						'type'        => 'integer',
						'description' => __( 'How many occurrences were rewritten, counting only the ones whose post content actually changed.', 'broken-link-checker-seo' )
					],
					'unchanged'       => [
						'type'        => 'integer',
						'description' => __( 'How many occurrences needed no rewrite, because the content already held the requested URL or an earlier rewrite in the same post took them with it.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					],
					'failed'          => [ 'type' => 'integer' ],
					'orphans_removed' => [
						'type'        => 'integer',
						'description' => __( 'Stale records dropped because the post they pointed at no longer exists.', 'broken-link-checker-seo' )
					],
					'url'             => [ 'type' => [ 'string', 'null' ] ],
					'anchor'          => [ 'type' => [ 'string', 'null' ] ],
					'results'         => [
						'type'  => 'array',
						'items' => $this->resultSchema()
					]
				]
			],
			'execute_callback'    => [ $this, 'updateLink' ],
			'permission_callback' => [ $this, 'canManageBrokenLinks' ],
			'meta'                => $this->writeMeta( true )
		] );

		wp_register_ability( 'aioseo-blc-links/unlink', [
			'label'               => __( 'Unlink Link', 'broken-link-checker-seo' ),
			'description'         => __( 'Removes the link wherever it was found, leaving its anchor text behind as plain text. Covers every occurrence of the link, in every plst it appears in, unless linkId narrows it to one. Rejected without changing anything if the caller cannot edit every post involved.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'category'            => 'aioseo-blc-links',
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'linkStatusId' => $this->linkStatusIdSchema(),
					'linkId'       => $this->linkIdSchema()
				],
				'required'             => [ 'linkStatusId' ],
				'additionalProperties' => false
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'attempted'       => [ 'type' => 'integer' ],
					'unlinked'        => [
						'type'        => 'integer',
						'description' => __( 'How many occurrences were removed, counting only the ones whose post content actually changed.', 'broken-link-checker-seo' )
					],
					'unchanged'       => [
						'type'        => 'integer',
						'description' => __( 'How many occurrences needed no change, because the link was already gone from the content or an earlier removal in the same post had taken them with it.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					],
					'failed'          => [ 'type' => 'integer' ],
					'orphans_removed' => [ 'type' => 'integer' ],
					'results'         => [
						'type'  => 'array',
						'items' => $this->resultSchema()
					]
				]
			],
			'execute_callback'    => [ $this, 'unlinkLink' ],
			'permission_callback' => [ $this, 'canManageBrokenLinks' ],
			'meta'                => $this->writeMeta( true )
		] );

		wp_register_ability( 'aioseo-blc-links/dismiss', [
			'label'               => __( 'Dismiss Link', 'broken-link-checker-seo' ),
			'description'         => __( 'Dismisses a link so it stops being reported and stops being checked, or restores a dismissed one by passing dismissed=false. Leaves the content it was found in untouched. The returned "changed" flag is false when the link was already in the requested state.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'category'            => 'aioseo-blc-links',
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'linkStatusId' => $this->linkStatusIdSchema(),
					'dismissed'    => [
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Pass false to restore a dismissed link.', 'broken-link-checker-seo' )
					]
				],
				'required'             => [ 'linkStatusId' ],
				'additionalProperties' => false
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'id'        => [ 'type' => 'integer' ],
					'dismissed' => [ 'type' => 'boolean' ],
					'changed'   => [ 'type' => 'boolean' ]
				]
			],
			'execute_callback'    => [ $this, 'setDismissed' ],
			'permission_callback' => [ $this, 'canManageBrokenLinks' ],
			'meta'                => $this->writeMeta( false, true )
		] );

		wp_register_ability( 'aioseo-blc-links/recheck', [
			'label'               => __( 'Recheck Links', 'broken-link-checker-seo' ),
			'description'         => __( 'Checks the given links again through the Broken Link Checker service and returns their new results. Requires a working license, and every URL checked spends the site\'s monthly quota, so repeating a call is not free.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'category'            => 'aioseo-blc-links',
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'linkStatusIds' => [
						'type'        => 'array',
						'items'       => [
							'type'    => 'integer',
							'minimum' => 1
						],
						'minItems'    => 1,
						'maxItems'    => 50,
						'description' => __( 'The link IDs to check, from aioseo-blc-links/list.', 'broken-link-checker-seo' )
					]
				],
				'required'             => [ 'linkStatusIds' ],
				'additionalProperties' => false
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'requested'       => [ 'type' => 'integer' ],
					'checked'         => [
						'type'        => 'integer',
						'description' => __( 'How many links the Broken Link Checker service returned a result for. Links that no longer exist are not checked.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					],
					'quota_remaining' => [ 'type' => 'integer' ],
					'links'           => [
						'type'  => 'array',
						'items' => $this->linkSchema( false, false )
					]
				]
			],
			'execute_callback'    => [ $this, 'recheck' ],
			'permission_callback' => [ $this, 'canManageBrokenLinks' ],
			'meta'                => $this->writeMeta( false, false )
		] );
	}

	/**
	 * Registers the scan abilities.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	protected function registerScanAbilities() {
	}

	// =========================================================================
	// Permission callbacks
	// =========================================================================

	/**
	 * Permission callback: caller can access the Broken Links Report.
	 *
	 * NOTE: Mirrors {@see \AIOSEO\BrokenLinkChecker\Api\Api::validRequest()} for the routes declaring
	 * `aioseo_blc_broken_links_page`. The services re-check it, so this is the outer of two gates.
	 *
	 * @since 1.3.1
	 *
	 * @return bool
	 */
	public function canManageBrokenLinks() {
		return is_user_logged_in() &&
			(
				aioseoBrokenLinkChecker()->access->isAdmin() ||
				current_user_can( 'aioseo_blc_broken_links_page' )
			);
	}

	// =========================================================================
	// Execute callbacks — thin delegates to Services\*.
	// =========================================================================

	/**
	 * Delegate to BrokenLinksService::listLinks.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function listLinks( $input ) {
		return ( new BrokenLinksService() )->listLinks( is_array( $input ) ? $input : [] );
	}

	/**
	 * Delegate to BrokenLinksService::getLink.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function getLink( $input ) {
		return ( new BrokenLinksService() )->getLink( is_array( $input ) ? $input : [] );
	}

	/**
	 * Delegate to BrokenLinksService::getSummary.
	 *
	 * @since 1.3.1
	 *
	 * @return array|\WP_Error
	 */
	public function getSummary() {
		return ( new BrokenLinksService() )->getSummary();
	}

	/**
	 * Delegate to BrokenLinksService::updateLink.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function updateLink( $input ) {
		return ( new BrokenLinksService() )->updateLink( is_array( $input ) ? $input : [] );
	}

	/**
	 * Delegate to BrokenLinksService::unlinkLink.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function unlinkLink( $input ) {
		return ( new BrokenLinksService() )->unlinkLink( is_array( $input ) ? $input : [] );
	}

	/**
	 * Delegate to BrokenLinksService::setDismissed.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function setDismissed( $input ) {
		return ( new BrokenLinksService() )->setDismissed( is_array( $input ) ? $input : [] );
	}

	/**
	 * Delegate to BrokenLinksService::recheck.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $input The input data.
	 * @return array|\WP_Error
	 */
	public function recheck( $input ) {
		return ( new BrokenLinksService() )->recheck( is_array( $input ) ? $input : [] );
	}

	// =========================================================================
	// Schema helpers (reused across multiple ability registrations).
	// =========================================================================

	/**
	 * Shared meta block for read-only abilities.
	 *
	 * @since 1.3.1
	 *
	 * @return array
	 */
	protected function readonlyMeta() {
		return [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true
			],
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ]
		];
	}

	/**
	 * Shared meta block for abilities that change something.
	 *
	 * @since 1.3.1
	 *
	 * @param  bool $destructive Whether the ability overwrites content rather than only adding to it.
	 * @param  bool $idempotent  Whether repeating the call with the same input has no further effect.
	 * @return array
	 */
	protected function writeMeta( $destructive, $idempotent = false ) {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent
			],
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ]
		];
	}

	/**
	 * Input schema for abilities that take no input.
	 *
	 * Without an input schema the Abilities API rejects any provided input with
	 * `ability_missing_input_schema` — including the empty array MCP clients and WP-CLI pass for a
	 * no-argument call. A permissive empty-object schema with a `default` lets both `null` and `[]`
	 * validate.
	 *
	 * @since 1.3.1
	 *
	 * @return array
	 */
	protected function noInputSchema() {
		// No `properties` key: an empty PHP array serializes to JSON `[]` (an array, not an object
		// `{}`), which stricter Abilities API validators reject as a malformed schema.
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'default'              => []
		];
	}

	/**
	 * Input schema for the link status ID every link ability is addressed by.
	 *
	 * @since 1.3.1
	 *
	 * @return array
	 */
	protected function linkStatusIdSchema() {
		return [
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'The link ID, from aioseo-blc-links/list. One ID covers every occurrence of the same URL.', 'broken-link-checker-seo' )
		];
	}

	/**
	 * Input schema for the ID of a single occurrence of a link.
	 *
	 * @since 1.3.1
	 *
	 * @return array
	 */
	protected function linkIdSchema() {
		return [
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Narrows the change to one occurrence, from the occurrences returned by aioseo-blc-links/get.', 'broken-link-checker-seo' )
		];
	}

	/**
	 * Output schema for a link and its last check result.
	 *
	 * @since 1.3.1
	 *
	 * @param  bool $includeCounts Whether to declare where the link was found and how often.
	 * @param  bool $includePost   Whether to declare the single post the link was found in.
	 * @return array
	 */
	protected function linkSchema( $includeCounts, $includePost ) {
		$properties = [
			'id'               => [ 'type' => 'integer' ],
			'url'              => [ 'type' => 'string' ],
			'status'           => [
				'type'        => 'string',
				'enum'        => [ 'broken', 'redirect', 'ok', 'pending' ],
				'description' => __( '"pending" means the link has not been checked yet, or its failure is awaiting a second check.', 'broken-link-checker-seo' )
			],
			'http_status_code' => [ 'type' => [ 'integer', 'null' ] ],
			'dismissed'        => [ 'type' => 'boolean' ],
			'redirect_count'   => [ 'type' => 'integer' ],
			'final_url'        => [
				'type'        => [ 'string', 'null' ],
				'description' => __( 'Where the redirects ended up, when the link redirects.', 'broken-link-checker-seo' )
			],
			'scan_count'       => [ 'type' => 'integer' ],
			'last_checked'     => [
				'type'        => [ 'string', 'null' ],
				'description' => __( 'UTC datetime the link was last checked, as Y-m-d H:i:s.', 'broken-link-checker-seo' )
			],
			'first_failure'    => [
				'type'        => [ 'string', 'null' ],
				'description' => __( 'UTC datetime the link first failed, as Y-m-d H:i:s.', 'broken-link-checker-seo' )
			],
			'last_error'       => [ 'type' => [ 'string', 'null' ] ]
		];

		if ( $includeCounts ) {
			$properties['external']          = [ 'type' => 'boolean' ];
			$properties['is_video']          = [ 'type' => 'boolean' ];
			$properties['total_occurrences'] = [ 'type' => 'integer' ];
			$properties['object_types']      = [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => __( 'Every kind of thing this URL was found in, e.g. ["post", "menu_item"].', 'broken-link-checker-seo' )
			];
			$properties['post_count']        = [
				'type'        => 'integer',
				'description' => __( 'How many distinct things the URL was found in, whatever their kind. Named for when they could only be posts.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			];
		}

		if ( $includePost ) {
			$properties['post'] = array_merge( $this->occurrenceSchema(), [
				'type'        => [ 'object', 'null' ],
				'description' => __( 'The single place the link was found in. Named for when that could only be a post — read object_type to know what it is. Null when the link was found in more than one place, or when the caller may not edit it.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			] );
		}

		return [
			'type'       => 'object',
			'properties' => $properties
		];
	}

	/**
	 * Output schema for one occurrence of a link in a post.
	 *
	 * @since 1.3.1
	 *
	 * @return array
	 */
	protected function occurrenceSchema() {
		return [
			'type'       => 'object',
			'properties' => [
				'link_id'           => [ 'type' => 'integer' ],
				'object_type'       => [
					'type'        => 'string',
					'description' => __( 'What holds the link: "post" for post content, or "post_meta", "term", "user" or "menu_item".', 'broken-link-checker-seo' )
				],
				'object_id'         => [ 'type' => 'integer' ],
				'object_subtype'    => [
					'type'        => 'string',
					'description' => __( 'Narrows the kind where it has one, such as the taxonomy of a term or the key of a custom field.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				],
				'object_label'      => [
					'type'        => 'string',
					'description' => __( 'What to call this kind of thing to a reader, e.g. "Page" or "Menu Item".', 'broken-link-checker-seo' )
				],
				'source_label'      => [
					'type'        => 'string',
					'description' => __( 'Where links of this kind come from, e.g. "Post Content" or "User Bios".', 'broken-link-checker-seo' )
				],
				'location_label'    => [
					'type'        => 'string',
					'description' => __( 'The name of the specific thing the link sits in, e.g. a post title or a menu item label.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				],
				'available_actions' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Which of editUrl, unlink, removeItem, recheck and dismiss this occurrence supports. A menu item pointing at a post cannot have its URL rewritten, for instance, because the URL follows the post.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				],
				'post_id'           => [
					'type'        => 'integer',
					'description' => __( 'The post the link was found in, or 0 when it was not found in one. Kept from before links could be found outside post content — prefer object_type and object_id.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				],
				'post_title'        => [ 'type' => 'string' ],
				'post_type'         => [ 'type' => 'string' ],
				'permalink'         => [ 'type' => [ 'string', 'null' ] ],
				'edit_link'         => [ 'type' => [ 'string', 'null' ] ],
				'anchor'            => [ 'type' => 'string' ],
				'phrase'            => [
					'type'        => 'string',
					'description' => __( 'The sentence the link was found in.', 'broken-link-checker-seo' )
				],
				'can_edit'          => [ 'type' => 'boolean' ],
				'can_delete'        => [ 'type' => 'boolean' ]
			]
		];
	}

	/**
	 * Output schema for the per-occurrence outcome of a change.
	 *
	 * @since 1.3.1
	 *
	 * @return array
	 */
	protected function resultSchema() {
		return [
			'type'       => 'object',
			'properties' => [
				'link_id' => [ 'type' => 'integer' ],
				'post_id' => [ 'type' => 'integer' ],
				'updated' => [
					'type'        => 'boolean',
					'description' => __( 'True only when the post content changed.', 'broken-link-checker-seo' )
				],
				'reason'  => [
					'type'        => [ 'string', 'null' ],
					'enum'        => [ null, 'no_change', 'no_match', 'already_covered', 'stale_record' ],
					'description' => __( 'Null when the content changed. "no_change" means the content already held what the call asked for, "no_match" that nothing was written and it still does not, "already_covered" that an earlier rewrite in the same post took this occurrence with it, and "stale_record" that the record was replaced while the call ran — read the link again rather than assuming the change is still outstanding.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				]
			]
		];
	}
}