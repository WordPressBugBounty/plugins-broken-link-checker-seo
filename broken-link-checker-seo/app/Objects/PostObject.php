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
 * Links found in a post's content and in its excerpt.
 *
 * The excerpt is a field of its own, so the subtype says which a row came from and rewriting one
 * cannot touch the other. It matters more than it sounds: WooCommerce stores a product's short
 * description there, which is prominent on the product page and often carries links.
 *
 * @since 1.3.1
 */
class PostObject extends ObjectType {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function primeCaches( $objectIds ) {
		$this->primePostCaches( $objectIds );
	}

	/**
	 * The subtype that marks a row as coming from the excerpt rather than the content.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const EXCERPT = 'excerpt';

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'post';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'Post', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * The post type's own name, so the report names a page a page rather than a post.
	 *
	 * @since 1.3.1
	 */
	public function itemLabel( $objectId, $subtype = '' ) {
		if ( self::EXCERPT === $subtype ) {
			// WooCommerce relabels the field, and a reader looking for the link will be looking for the
			// name the editor gave it.
			return 'product' === get_post_type( (int) $objectId )
				? __( 'Short Description', 'broken-link-checker-seo' )
				: __( 'Excerpt', 'broken-link-checker-seo' );
		}

		$postType = get_post_type_object( (string) get_post_type( (int) $objectId ) );

		return $postType ? $postType->labels->singular_name : $this->label();
	}

	/**
	 * {@inheritdoc}
	 *
	 * The post type's own name, so a page is tagged Page and a product Product rather than both Post.
	 * Falls back to the generic name for a row that aggregates several locations, which knows the kind
	 * but not which posts.
	 *
	 * @since 1.3.1
	 */
	public function tagLabel( $objectId = 0, $subtype = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( empty( $objectId ) ) {
			return $this->label();
		}

		$postType = get_post_type_object( (string) get_post_type( (int) $objectId ) );

		return $postType ? $postType->labels->singular_name : $this->label();
	}

	/**
	 * {@inheritdoc}
	 *
	 * The content and the excerpt are separate fields, so reindexing one must not drop the other's rows.
	 *
	 * @since 1.3.1
	 */
	public function isSubtypeAddressed() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'Post Content', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function capability() {
		return 'edit_post';
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
	 * @since 1.3.1
	 */
	public function isPostBacked() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function supportedActions( $objectId = 0, $subtype = '' ) {
		return [ self::EDIT_URL, self::UNLINK, self::RECHECK, self::DISMISS ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * Each post type has its own capability set, and authorship decides the rest, so the rows nobody but
	 * another author could edit are known from the join alone.
	 *
	 * NOTE: Deliberately wider than {@see self::canEdit()} — it leaves out the published-state
	 * capabilities, which would need the row's status as well. A condition that is too wide costs a
	 * shorter page; one that is too narrow hides a location its owner could have fixed.
	 *
	 * @since 1.3.1
	 */
	public function userScopeCondition() {
		$postTypes = get_post_types( [], 'objects' );
		$others    = [];
		$own       = [];

		foreach ( $postTypes as $postType ) {
			if ( current_user_can( $postType->cap->edit_others_posts ) ) {
				$others[] = $postType->name;

				continue;
			}

			if ( current_user_can( $postType->cap->edit_posts ) ) {
				$own[] = $postType->name;
			}
		}

		if ( count( $others ) === count( $postTypes ) ) {
			return '';
		}

		$conditions = [];
		if ( ! empty( $others ) ) {
			$conditions[] = 'p.post_type IN (' . $this->quoteList( $others ) . ')';
		}

		if ( ! empty( $own ) ) {
			$conditions[] = '( p.post_type IN (' . $this->quoteList( $own ) . ') AND p.post_author = ' . (int) get_current_user_id() . ' )';
		}

		return empty( $conditions ) ? '0' : implode( ' OR ', $conditions );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function exists( $objectId, $subtype = '' ) {
		return is_a( get_post( (int) $objectId ), 'WP_Post' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function locationLabel( $objectId, $subtype = '' ) {
		return $this->exists( $objectId ) ? aioseoBrokenLinkChecker()->helpers->getPostTitle( (int) $objectId ) : '';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function editUrl( $objectId, $subtype = '' ) {
		$editUrl = get_edit_post_link( (int) $objectId, '' );

		return $editUrl ? $editUrl : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function viewUrl( $objectId, $subtype = '' ) {
		$permalink = get_permalink( (int) $objectId );

		return $permalink ? $permalink : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function canDelete( $objectId, $subtype = '' ) {
		return current_user_can( 'delete_post', (int) $objectId );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function getContent( $objectId, $subtype = '' ) {
		$post = get_post( (int) $objectId );
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return '';
		}

		// The excerpt is the post's own whoever owns the layout — a builder writes content, not this.
		if ( self::EXCERPT === $subtype ) {
			return (string) $post->post_excerpt;
		}

		// A builder's rendered copy of its own layout, which that builder reports on instead.
		// {@see \AIOSEO\BrokenLinkChecker\Objects\ObjectType::ownsPostContent()}.
		if ( aioseoBrokenLinkChecker()->objects->postContentOwner( (int) $objectId ) ) {
			return '';
		}

		return (string) $post->post_content;
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: Not the path a rewrite takes — {@see \AIOSEO\BrokenLinkChecker\Api\CommonTableActions} owns
	 * that, because it also has to preserve the modified date and queue the reindex.
	 *
	 * @since 1.3.1
	 */
	public function saveContent( $objectId, $subtype, $content ) {
		$field = self::EXCERPT === $subtype ? 'post_excerpt' : 'post_content';

		$result = wp_update_post( [
			'ID'   => (int) $objectId,
			$field => wp_slash( $content )
		], true );

		return 0 !== $result && ! is_wp_error( $result );
	}
}