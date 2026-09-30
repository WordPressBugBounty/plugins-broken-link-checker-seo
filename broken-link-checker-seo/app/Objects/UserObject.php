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
 * Links found on a user's profile: the bio, and the website field beside it.
 *
 * Both are the same person to a reader, so they are one source and the subtype says which field a row
 * came from. They are not the same shape, though — a bio is rich text with anchors in it, and the
 * website is a bare URL that is the whole of its field — so each is read and written its own way.
 *
 * @since 1.3.1
 */
class UserObject extends ObjectType {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function primeCaches( $objectIds ) {
		$objectIds = array_values( array_unique( array_filter( array_map( 'intval', (array) $objectIds ) ) ) );
		if ( empty( $objectIds ) || ! function_exists( 'cache_users' ) ) {
			return;
		}

		cache_users( $objectIds );
	}

	/**
	 * The subtype that marks a row as coming from the website field rather than the bio.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const WEBSITE = 'user_url';

	/**
	 * The roles whose profiles are scanned.
	 *
	 * Every registered user has a bio and a website field, but on a site with open registration or a
	 * membership plugin most of them belong to people who never write anything - and this source
	 * presents them as the site's own contributors. Narrowed to the roles that write, so a site with
	 * thousands of subscribers isn't swept for profiles nobody publishes under.
	 *
	 * @since 1.3.1
	 *
	 * @return array The role names.
	 */
	public function scannedRoles() {
		return (array) apply_filters( 'aioseo_blc_user_scan_roles', [ 'administrator', 'editor', 'author' ] );
	}

	/**
	 * Whether the given user holds a role whose profile is scanned.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $objectId The user ID.
	 * @return bool           Whether the profile is scanned.
	 */
	public function hasScannedRole( $objectId ) {
		$user = get_userdata( (int) $objectId );
		if ( ! is_a( $user, 'WP_User' ) ) {
			return false;
		}

		return (bool) array_intersect( (array) $user->roles, $this->scannedRoles() );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Nothing else revisits these, so the sweep is what keeps them current.
	 *
	 * @since 1.3.1
	 */
	public function isSwept() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'user';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'User Bio', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'User Bios & Websites', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function tagLabel( $objectId = 0, $subtype = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return __( 'User', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * A bio is prose someone types, and pasting an address into it is the ordinary way to point at
	 * something - the profile has no editor to wrap it in an anchor with. So it counts as a link whether
	 * or not anybody did, the same as a term description or a custom field.
	 *
	 * @since 1.3.1
	 */
	public function findsBareUrls() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function settingKey() {
		return 'authorBios';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function capability() {
		return 'edit_user';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function capabilityTakesObjectId() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Without `edit_users`, `edit_user` still maps to true for the current user's own profile — which is
	 * the one bio an author can be shown.
	 *
	 * @since 1.3.1
	 */
	public function userScopeCondition() {
		if ( current_user_can( 'edit_users' ) ) {
			return '';
		}

		return 'al.object_id = ' . (int) get_current_user_id();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function exists( $objectId, $subtype = '' ) {
		return is_a( get_userdata( (int) $objectId ), 'WP_User' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * A profile holds two fields that can each carry a link, so reindexing one must not drop the other's
	 * rows.
	 *
	 * @since 1.3.1
	 */
	public function isSubtypeAddressed() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * The website field is a bare URL rather than prose with an anchor in it.
	 *
	 * @since 1.3.1
	 */
	public function isRichText( $objectId = 0, $subtype = '' ) {
		return self::WEBSITE !== $subtype;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function itemLabel( $objectId, $subtype = '' ) {
		return self::WEBSITE === $subtype
			? __( 'User Website', 'broken-link-checker-seo' )
			: __( 'User Bio', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * The website field is the URL, so there is no anchor to strip.
	 *
	 * @since 1.3.1
	 */
	public function supportedActions( $objectId = 0, $subtype = '' ) {
		if ( self::WEBSITE === $subtype ) {
			return [ self::EDIT_URL, self::RECHECK, self::DISMISS ];
		}

		return [ self::EDIT_URL, self::UNLINK, self::RECHECK, self::DISMISS ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function refusedActions( $objectId = 0, $subtype = '' ) {
		if ( self::WEBSITE !== $subtype ) {
			return [];
		}

		return [ self::UNLINK => $this->unlinkRefusalMessage( $objectId, $subtype ) ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function unlinkRefusalMessage( $objectId = 0, $subtype = '' ) {
		return __( 'The URL is the whole of the user\'s website field, so there is no link text to leave behind. Change the URL instead, or clear the field on the profile.', 'broken-link-checker-seo' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function getUrl( $objectId, $subtype = '' ) {
		if ( self::WEBSITE !== $subtype || ! $this->exists( $objectId ) ) {
			return '';
		}

		$user = get_userdata( (int) $objectId );

		return isset( $user->user_url ) ? (string) $user->user_url : '';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function setUrl( $objectId, $subtype, $url ) {
		if ( self::WEBSITE !== $subtype || ! $this->exists( $objectId ) ) {
			return false;
		}

		$result = wp_update_user( [
			'ID'       => (int) $objectId,
			'user_url' => $url
		] );

		return ! is_wp_error( $result );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function locationLabel( $objectId, $subtype = '' ) {
		$user = get_userdata( (int) $objectId );

		return is_a( $user, 'WP_User' ) ? $user->display_name : '';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function editUrl( $objectId, $subtype = '' ) {
		if ( ! $this->exists( $objectId ) ) {
			return null;
		}

		$editUrl = get_edit_user_link( (int) $objectId );

		return $editUrl ? $editUrl : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function viewUrl( $objectId, $subtype = '' ) {
		if ( ! $this->exists( $objectId ) ) {
			return null;
		}

		$authorUrl = get_author_posts_url( (int) $objectId );

		return $authorUrl ? $authorUrl : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function getContent( $objectId, $subtype = '' ) {
		if ( self::WEBSITE === $subtype || ! $this->exists( $objectId ) ) {
			return '';
		}

		return (string) get_user_meta( (int) $objectId, 'description', true );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function saveContent( $objectId, $subtype, $content ) {
		if ( self::WEBSITE === $subtype || ! $this->exists( $objectId ) ) {
			return false;
		}

		// update_user_meta() runs the value through wp_unslash(), so it has to arrive slashed.
		return false !== update_user_meta( (int) $objectId, 'description', wp_slash( $content ) );
	}
}