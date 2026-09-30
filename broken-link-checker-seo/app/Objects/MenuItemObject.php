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
 * Nav menu items, whose URL is the value rather than an anchor inside content.
 *
 * @since 1.3.1
 */
class MenuItemObject extends ObjectType {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function primeCaches( $objectIds ) {
		$this->primePostCaches( $objectIds );
	}

	/**
	 * The resolved items, keyed by ID.
	 *
	 * @since 1.3.1
	 *
	 * @var array
	 */
	private static $items = [];

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
		return 'menu_item';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'Menu Item', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'Navigation Menus', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function tagLabel( $objectId = 0, $subtype = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return __( 'Menu', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function settingKey() {
		return 'navMenus';
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: Menus are a theme concern, not a content one — there is no per-item capability to check.
	 *
	 * @since 1.3.1
	 */
	public function capability() {
		return 'edit_theme_options';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function capabilityTakesObjectId() {
		return false;
	}

	/**
	 * {@inheritdoc}
	 *
	 * A menu item's URL is its value, so there is no anchor to strip and no surrounding text to
	 * leave behind. Deleting the item is the equivalent, and it is offered as its own action.
	 *
	 * @since 1.3.1
	 */
	public function supportedActions( $objectId = 0, $subtype = '' ) {
		return [ self::EDIT_URL, self::REMOVE_ITEM, self::RECHECK, self::DISMISS ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function refusedActions( $objectId = 0, $subtype = '' ) {
		return [ self::UNLINK => $this->unlinkRefusalMessage( $objectId, $subtype ) ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function isRichText( $objectId = 0, $subtype = '' ) {
		return false;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function exists( $objectId, $subtype = '' ) {
		return null !== $this->getItem( $objectId );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function locationLabel( $objectId, $subtype = '' ) {
		$item = $this->getItem( $objectId );
		if ( ! $item ) {
			return '';
		}

		$title = $item->title ? $item->title : __( '(no label)', 'broken-link-checker-seo' );
		$menu  = $this->getMenu( $objectId );
		if ( ! $menu ) {
			return $title;
		}

		return sprintf(
			// Translators: 1 - A menu item's label, 2 - The name of the menu it belongs to.
			__( '%1$s (%2$s)', 'broken-link-checker-seo' ),
			$title,
			$menu->name
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: nav-menus.php has no anchor per item, so this opens the menu the item belongs to.
	 *
	 * @since 1.3.1
	 */
	public function editUrl( $objectId, $subtype = '' ) {
		if ( ! $this->exists( $objectId ) ) {
			return null;
		}

		$menu = $this->getMenu( $objectId );

		return $menu
			? admin_url( 'nav-menus.php?action=edit&menu=' . (int) $menu->term_id )
			: admin_url( 'nav-menus.php' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function getUrl( $objectId, $subtype = '' ) {
		$item = $this->getItem( $objectId );

		return $item && ! empty( $item->url ) ? (string) $item->url : '';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function setUrl( $objectId, $subtype, $url ) {
		$item = $this->getItem( $objectId );
		if ( ! $item ) {
			return false;
		}

		$refusal = $this->urlRefusal( $objectId, $subtype );
		if ( $refusal ) {
			return $refusal;
		}

		// update_post_meta() runs the value through wp_unslash(), so it has to arrive slashed.
		$updated = (bool) update_post_meta( (int) $objectId, '_menu_item_url', wp_slash( $url ) );
		if ( $updated ) {
			// The reindex that follows reads the item back, so the memo cannot outlive the write.
			$this->forget( $objectId );
		}

		return $updated;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only a custom link carries a URL of its own. Every other kind derives it from the post, term or
	 * archive it points at, so there is nothing to rewrite without changing what the item points at.
	 *
	 * @since 1.3.1
	 */
	public function urlRefusal( $objectId, $subtype = '' ) {
		$item = $this->getItem( $objectId );
		if ( ! $item || 'custom' === $item->type ) {
			return null;
		}

		return new \WP_Error(
			'blc_menu_item_url_derived',
			__( 'This menu item points at a post, term or archive, so its URL follows that instead of being set on the item. Change what the item points at in the menu editor.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			[ 'status' => 409 ]
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function removeItem( $objectId, $subtype = '' ) {
		if ( ! $this->exists( $objectId ) ) {
			return false;
		}

		$deleted = is_a( wp_delete_post( (int) $objectId, true ), 'WP_Post' );
		if ( $deleted ) {
			$this->forget( $objectId );
		}

		return $deleted;
	}

	/**
	 * Drops the memoised item, for a caller that has just changed it.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $objectId The menu item ID.
	 * @return void
	 */
	private function forget( $objectId ) {
		unset( self::$items[ (int) $objectId ] );
	}

	/**
	 * Resolves the menu item, with its URL and title filled in the way the front end renders them.
	 *
	 * NOTE: Returned as a plain object. wp_setup_nav_menu_item() decorates the post with fields WP_Post
	 * doesn't declare, so keeping the WP_Post type would make every read of one an undefined property.
	 *
	 * Only a published item resolves, which is the rule the sweep and {@see wp_get_nav_menu_items()}
	 * both apply: a draft item is one the customizer has not saved yet, and the front end never renders
	 * it, so the report has nothing to say about it.
	 *
	 * NOTE: Memoised because a single report row asks half a dozen questions that each need the item,
	 * and wp_setup_nav_menu_item() resolves whatever the item points at to answer any of them.
	 *
	 * @since 1.3.1
	 *
	 * @param  int         $objectId The menu item ID.
	 * @return object|null           The menu item.
	 */
	private function getItem( $objectId ) {
		$objectId = (int) $objectId;
		if ( array_key_exists( $objectId, self::$items ) ) {
			return self::$items[ $objectId ];
		}

		self::$items[ $objectId ] = null;

		$post = get_post( $objectId );
		if ( ! is_a( $post, 'WP_Post' ) || 'nav_menu_item' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$item = wp_setup_nav_menu_item( $post );
		if ( is_object( $item ) ) {
			self::$items[ $objectId ] = (object) get_object_vars( $item );
		}

		return self::$items[ $objectId ];
	}

	/**
	 * Returns the menu the given item belongs to.
	 *
	 * @since 1.3.1
	 *
	 * @param  int           $objectId The menu item ID.
	 * @return \WP_Term|null           The menu.
	 */
	private function getMenu( $objectId ) {
		$menus = wp_get_post_terms( (int) $objectId, 'nav_menu' );
		if ( is_wp_error( $menus ) || empty( $menus[0] ) ) {
			return null;
		}

		return $menus[0];
	}
}