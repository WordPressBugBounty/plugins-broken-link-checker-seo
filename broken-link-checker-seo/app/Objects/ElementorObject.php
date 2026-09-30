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
 * Links found in Elementor page content.
 *
 * Elementor keeps a page as a JSON tree in `_elementor_data`, and a widget's fields hang off its own
 * `settings` key. {@see BuilderObject} for how the tree is read and why the report cannot rewrite it.
 *
 * @since 1.3.1
 */
class ElementorObject extends MetaBuilderObject {
	/**
	 * The meta key Elementor stores a page's tree in.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const META_KEY = '_elementor_data';

	/**
	 * The meta key holding which editor owns a post, and the value meaning Elementor does.
	 *
	 * A page can carry a stale tree from a layout that was later rebuilt in the block editor, so the tree
	 * alone does not say who owns the post.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const EDIT_MODE_KEY = '_elementor_edit_mode';
	const EDIT_MODE     = 'builder';

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'elementor';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'Elementor Page', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'Elementor', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function metaKey() {
		return self::META_KEY;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Elementor writes a rendered copy of the layout to post content, so a page it built holds every link
	 * twice until the post source stands down.
	 *
	 * @since 1.3.1
	 */
	public function ownsPostContent( $objectId ) {
		return self::EDIT_MODE === get_post_meta( (int) $objectId, self::EDIT_MODE_KEY, true );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Elementor's own editor rather than the post editor, which shows none of this.
	 *
	 * @since 1.3.1
	 */
	public function editUrl( $objectId, $subtype = '' ) {
		if ( ! $this->exists( $objectId ) ) {
			return null;
		}

		return admin_url( 'post.php?post=' . (int) $objectId . '&action=elementor' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * A widget's fields are its `settings`; everything else on the node is structure.
	 *
	 * @since 1.3.1
	 */
	protected function fieldsFor( $node ) {
		return isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	protected function isFieldsKey( $key ) {
		return 'settings' === $key;
	}
}