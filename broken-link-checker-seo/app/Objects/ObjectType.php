<?php
namespace AIOSEO\BrokenLinkChecker\Objects;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The object subtype is part of the contract every object type implements, so a type that addresses
// its objects by ID alone still has to accept it.
// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

/**
 * What one kind of scanned object can tell the rest of the plugin about itself.
 *
 * A subclass answers three separate questions the links table cannot: which capability governs
 * writes to this kind of object, how to describe and reach one, and which of the report's actions
 * make sense for it.
 *
 * @since 1.3.1
 */
abstract class ObjectType {
	/**
	 * Replacing the link's URL.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const EDIT_URL = 'editUrl';

	/**
	 * Stripping the anchor and leaving its text behind.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const UNLINK = 'unlink';

	/**
	 * Deleting the object the link is, rather than a link inside it.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const REMOVE_ITEM = 'removeItem';

	/**
	 * Checking the URL again through the service.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const RECHECK = 'recheck';

	/**
	 * Taking the link out of the report.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const DISMISS = 'dismiss';

	/**
	 * The slug stored in the links table's `object_type` column.
	 *
	 * @since 1.3.1
	 *
	 * @return string The slug.
	 */
	/**
	 * Warms whatever the given objects of this kind will be read from.
	 *
	 * Called once with every ID a batch is about to describe, so a kind backed by posts, terms or users
	 * fetches them together instead of one per row.
	 *
	 * @since 1.3.1
	 *
	 * @param  int[] $objectIds The object IDs.
	 * @return void
	 */
	public function primeCaches( $objectIds ) {}

	/**
	 * Warms the post cache for the given IDs, for the kinds that are addressed by post ID.
	 *
	 * @since 1.3.1
	 *
	 * @param  int[] $objectIds The object IDs.
	 * @return void
	 */
	protected function primePostCaches( $objectIds ) {
		$objectIds = array_values( array_unique( array_filter( array_map( 'intval', (array) $objectIds ) ) ) );
		if ( empty( $objectIds ) || ! function_exists( '_prime_post_caches' ) ) {
			return;
		}

		// Meta, because a builder reads its layout out of it. Not terms - nothing here asks for them.
		_prime_post_caches( $objectIds, false, true );
	}

	abstract public function type();

	/**
	 * The singular name of one object of this kind.
	 *
	 * @since 1.3.1
	 *
	 * @return string The name.
	 */
	abstract public function label();

	/**
	 * The name of one object of this kind, as specific as that object allows.
	 *
	 * Where a kind covers several sorts of object, this names the one at hand: a page rather than a
	 * post. Anything the report says about a single object reads better for it.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return string           The name.
	 */
	public function itemLabel( $objectId, $subtype = '' ) {
		return $this->label();
	}

	/**
	 * The name of this kind as a source the report can be filtered to.
	 *
	 * @since 1.3.1
	 *
	 * @return string The name.
	 */
	abstract public function sourceLabel();

	/**
	 * The shortest name that still identifies this kind, for the report's type tag.
	 *
	 * One name per kind where the object is not known: a row covering several locations knows only
	 * their types, so a tag naming one of them would label the same object differently in two places of
	 * one table. Given an object, a kind may name it more precisely - a page rather than a post.
	 *
	 * NOTE: Rendered inside the location cell of a column that already competes for width, so a name
	 * longer than one word pushes the location label onto a second line.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Added $objectId and $subtype, so a known object can be named precisely.
	 *
	 * @param  int    $objectId The object, where one is known.
	 * @param  string $subtype  The subtype, where one is known.
	 * @return string           The name.
	 */
	public function tagLabel( $objectId = 0, $subtype = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return $this->label();
	}

	/**
	 * The capability that governs writing to an object of this kind.
	 *
	 * @since 1.3.1
	 *
	 * @return string The capability.
	 */
	abstract public function capability();

	/**
	 * Whether {@see self::capability()} is a meta capability that takes the object's ID.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether it takes the ID.
	 */
	abstract public function capabilityTakesObjectId();

	/**
	 * Whether the object still exists.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return bool             Whether it exists.
	 */
	abstract public function exists( $objectId, $subtype = '' );

	/**
	 * How to name the place the link was found, for a person reading the report.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return string           The label.
	 */
	abstract public function locationLabel( $objectId, $subtype = '' );

	/**
	 * The admin URL that opens the object for editing.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return string|null      The URL.
	 */
	abstract public function editUrl( $objectId, $subtype = '' );

	/**
	 * The key in the per-source settings group, or null when the source cannot be turned off.
	 *
	 * @since 1.3.1
	 *
	 * @return string|null The key.
	 */
	public function settingKey() {
		return null;
	}

	/**
	 * The actions the report may offer for this kind.
	 *
	 * @since 1.3.1
	 *
	 * @param  int      $objectId The object ID.
	 * @param  string   $subtype  The object subtype.
	 * @return string[]           The actions.
	 */
	public function supportedActions( $objectId = 0, $subtype = '' ) {
		return [ self::EDIT_URL, self::UNLINK, self::RECHECK, self::DISMISS ];
	}

	/**
	 * Whether links of this kind live inside rich text, as opposed to the URL being the value itself.
	 *
	 * NOTE: Takes the object because a kind can hold both shapes. A custom field's is decided by the
	 * definition behind the meta key, not by the source it belongs to.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return bool             Whether it is rich text.
	 */
	public function isRichText( $objectId = 0, $subtype = '' ) {
		return true;
	}

	/**
	 * Whether a URL written as plain text in this kind's value counts as a link.
	 *
	 * NOTE: Off by default, and deliberately not on for post content. An editor makes real links, so a
	 * bare URL there is usually meant as text - and turning them all into indexed links would spend a
	 * site's quota on strings nobody can click.
	 *
	 * It is on for the short, plain fields where typing the address *is* how you reference something: a
	 * term description, a custom field. Those had no way of being seen at all.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether a bare URL is a link here.
	 */
	public function findsBareUrls() {
		return false;
	}

	/**
	 * Whether an object of this kind holds a separate set of links per subtype.
	 *
	 * NOTE: A term's subtype names its taxonomy, and one term ID has one of those. A meta key names one
	 * field out of many on the same post, so those rows have to be addressed and dropped per key.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether the subtype is part of the address.
	 */
	public function isSubtypeAddressed() {
		return false;
	}

	/**
	 * Whether the object is a post the report already covers.
	 *
	 * The post-type, post-status and excluded-post rules apply to such a kind, and the post scan is what
	 * revisits it, so it needs no sweep of its own.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether it is post-backed.
	 */
	public function isPostBacked() {
		return false;
	}

	/**
	 * The block name prefix this kind reads its own layout out of, where it has one.
	 *
	 * A builder that keeps its layout in blocks shares post content with the post source, so both read
	 * the same attributes. The post source leaves these blocks' attributes to whoever owns them, or one
	 * picture is reported twice — once as a page of the builder's and once as a post.
	 *
	 * @since 1.3.1
	 *
	 * @return string The prefix, e.g. 'divi/', or an empty string.
	 */
	public function blockPrefix() {
		return '';
	}

	/**
	 * Whether this kind is where the given post's content really lives.
	 *
	 * A builder keeps the layout it owns in its own store and writes a rendered copy of it to post
	 * content, so a post it owns holds each of its links twice. Claiming it here leaves the post source
	 * out of that post, which both stops the double count and stops the report offering an edit to the
	 * copy — a write Elementor overwrites the next time anyone saves the page.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $objectId The post ID.
	 * @return bool           Whether this kind owns that post's content.
	 */
	public function ownsPostContent( $objectId ) {
		return false;
	}

	/**
	 * The next batch of this kind's objects for the sweep to reindex, in ID order.
	 *
	 * A kind the post scan already revisits needs none of this. A kind nothing else revisits — a term, a
	 * template, a reusable block — returns its own batch, so the sweep does not have to know what any of
	 * them are. {@see \AIOSEO\BrokenLinkChecker\Links\ObjectScan}.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $cursor The ID the last batch reached.
	 * @param  int   $limit  How many to return.
	 * @return array         The objects, each with an `id` and a `subtype`.
	 */
	public function sweepBatch( $cursor, $limit ) {
		return [];
	}

	/**
	 * Whether the sweep should visit this kind at all.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether it is swept.
	 */
	public function isSwept() {
		return false;
	}

	/**
	 * The actions the report shows but cannot carry out, each with the reason why.
	 *
	 * Kept apart from {@see self::supportedActions()} on purpose: that one is what the write path asks
	 * before it touches anything, so listing an action there to get it rendered would make it answer yes
	 * to a write it then drops. These are rendered disabled with the reason on them, because an action
	 * that is simply absent leaves the reader guessing whether the report can do it at all.
	 *
	 * @since 1.3.1
	 *
	 * @param  int      $objectId The object ID.
	 * @param  string   $subtype  The object subtype.
	 * @return string[]           The reasons, keyed by action.
	 */
	public function refusedActions( $objectId = 0, $subtype = '' ) {
		return [];
	}

	/**
	 * The reason unlinking cannot apply to this kind, for the kinds it cannot apply to.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return string           The reason.
	 */
	public function unlinkRefusalMessage( $objectId = 0, $subtype = '' ) {
		return sprintf(
			// Translators: 1 - The name of a kind of object, e.g. "Menu Item".
			__( 'The URL is the whole of what this %1$s is, so there is no anchor to remove. Change the URL or delete the item instead.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			$this->label()
		);
	}

	/**
	 * The public URL of the object, when it has one.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return string|null      The URL.
	 */
	public function viewUrl( $objectId, $subtype = '' ) {
		return null;
	}

	/**
	 * The URL a relative link inside this object resolves against.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return string           The URL.
	 */
	public function baseUrl( $objectId, $subtype = '' ) {
		$viewUrl = $this->viewUrl( $objectId, $subtype );

		return $viewUrl ? $viewUrl : get_site_url();
	}

	/**
	 * The rich text the scan reads links out of.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return string           The content.
	 */
	public function getContent( $objectId, $subtype = '' ) {
		return '';
	}

	/**
	 * The value behind each of the given object's subtypes, for the kinds addressed by one.
	 *
	 * Only the subtypes that hold something the scan can read a link out of, so a kind with many of them
	 * costs the scan the ones that do rather than all of them.
	 *
	 * @since 1.3.1
	 *
	 * @param  int                   $objectId The object ID.
	 * @return array<string, string>           The values, keyed by subtype.
	 */
	public function scannableValues( $objectId ) {
		return [];
	}

	/**
	 * Writes the rich text back.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @param  string $content  The content.
	 * @return bool             Whether it was written.
	 */
	public function saveContent( $objectId, $subtype, $content ) {
		return false;
	}

	/**
	 * Whether the source is switched on in the settings.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether it is enabled.
	 */
	public function isEnabled() {
		$settingKey = $this->settingKey();
		if ( ! $settingKey ) {
			return true;
		}

		$sources = aioseoBrokenLinkChecker()->options->general->scanSources->all();

		return ! empty( $sources[ $settingKey ] );
	}

	/**
	 * The SQL condition that limits this kind's rows to the locations the current user may be shown.
	 *
	 * Evaluated against the links table aliased to `al`. An empty string leaves every row of this kind
	 * in, and `0` takes them all out. Anything a capability can only settle once the object is resolved
	 * belongs in {@see self::canEdit()} instead.
	 *
	 * @since 1.3.1
	 *
	 * @return string The condition.
	 */
	public function userScopeCondition() {
		if ( $this->capabilityTakesObjectId() ) {
			return '';
		}

		return current_user_can( $this->capability() ) ? '' : '0';
	}

	/**
	 * Escapes and quotes the given values as a SQL list.
	 *
	 * @since 1.3.1
	 *
	 * @param  array  $values The values.
	 * @return string         The list.
	 */
	protected function quoteList( $values ) {
		return implode( ',', array_map( function( $value ) {
			return "'" . esc_sql( $value ) . "'";
		}, array_values( $values ) ) );
	}

	/**
	 * Whether the report may offer the given action for this kind.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $action   The action.
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return bool             Whether it is supported.
	 */
	public function supports( $action, $objectId = 0, $subtype = '' ) {
		return in_array( $action, $this->supportedActions( $objectId, $subtype ), true );
	}

	/**
	 * Whether the current user may write to the given object.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return bool             Whether they may.
	 */
	public function canEdit( $objectId, $subtype = '' ) {
		if ( ! $this->exists( $objectId, $subtype ) ) {
			return false;
		}

		return $this->capabilityTakesObjectId()
			? current_user_can( $this->capability(), $objectId )
			: current_user_can( $this->capability() );
	}

	/**
	 * Whether the current user may delete the object outright.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return bool             Whether they may.
	 */
	public function canDelete( $objectId, $subtype = '' ) {
		return $this->supports( self::REMOVE_ITEM, $objectId, $subtype ) && $this->canEdit( $objectId, $subtype );
	}

	/**
	 * Deletes the object outright, for the kinds where the link is the object.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return bool             Whether it was deleted.
	 */
	public function removeItem( $objectId, $subtype = '' ) {
		return false;
	}

	/**
	 * The stored URL, for the kinds whose URL is the value rather than an anchor inside content.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @return string           The URL.
	 */
	public function getUrl( $objectId, $subtype = '' ) {
		return '';
	}

	/**
	 * Writes the URL back, for the kinds whose URL is the value.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $objectId The object ID.
	 * @param  string $subtype  The object subtype.
	 * @param  string $url      The URL.
	 * @return bool|\WP_Error   True, or the reason it was refused.
	 */
	public function setUrl( $objectId, $subtype, $url ) {
		return false;
	}

	/**
	 * The reason the given object's URL cannot be replaced, when there is one.
	 *
	 * Asked before anything is written, so a call that spans several objects can refuse the whole of it
	 * rather than rewrite the ones it reached first.
	 *
	 * @since 1.3.1
	 *
	 * @param  int            $objectId The object ID.
	 * @param  string         $subtype  The object subtype.
	 * @return \WP_Error|null           The refusal.
	 */
	public function urlRefusal( $objectId, $subtype = '' ) {
		return null;
	}
}