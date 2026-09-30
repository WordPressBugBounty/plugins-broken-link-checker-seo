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
 * Links found in Divi shortcode attributes.
 *
 * Divi writes plain URLs into its module attributes — a button's `button_url`, an image's `src`. The
 * anchors and images inside a text module are ordinary markup that the post scan already reads, so
 * these rows only add what has no tag around it. {@see ShortcodeBuilderObject} for the rest.
 *
 * Divi 5 moved to Gutenberg blocks — `<!-- wp:divi/button {"button":{"innerContent":...}} -->` — and a
 * site that upgrades keeps its old shortcode pages working, so both shapes exist side by side and one
 * source reads both. The block attributes are a tree like any other builder's, so the same walk reads
 * them; only where they come from differs.
 *
 * NOTE: Divi ships as a theme (and as Extra, and as the Divi Builder plugin), so what says it is in
 * use is the builder's own constant rather than an active plugin. Divi 5 defines the same one.
 *
 * @since 1.3.1
 */
class DiviObject extends ShortcodeBuilderObject {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'divi';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'Divi Page', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'Divi', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function blockPrefix() {
		return self::BLOCK_PREFIX;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	protected function tagPrefix() {
		return 'et_pb_';
	}

	/**
	 * The prefix Divi 5's blocks are named with.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const BLOCK_PREFIX = 'divi/';

	/**
	 * {@inheritdoc}
	 *
	 * Either shape counts: a legacy shortcode page or a Divi 5 block one.
	 *
	 * @since 1.3.1
	 */
	public function hasLayout( $objectId ) {
		if ( parent::hasLayout( $objectId ) ) {
			return true;
		}

		$post = get_post( (int) $objectId );

		return is_a( $post, 'WP_Post' ) && false !== strpos( (string) $post->post_content, '<!-- wp:' . self::BLOCK_PREFIX );
	}

	/**
	 * {@inheritdoc}
	 *
	 * The shortcode attributes first, then the block ones. A page that has been converted holds only
	 * blocks, one that has not holds only shortcodes, and one mid-conversion can hold both.
	 *
	 * @since 1.3.1
	 */
	protected function collect( $objectId, &$links, &$fragments ) {
		parent::collect( $objectId, $links, $fragments );

		$post = get_post( (int) $objectId );
		if ( ! is_a( $post, 'WP_Post' ) || false === strpos( (string) $post->post_content, '<!-- wp:' . self::BLOCK_PREFIX ) ) {
			return;
		}

		// parse_blocks() is a parser rather than a renderer, so it reads these whether or not anything
		// registered them — which matters, because Divi registers its modules through its own builder.
		// Only Divi's own blocks: the tree holds core blocks too, and reading their attributes here
		// reported a core block's background as a Divi page as well as a post.
		$this->collectLinks( $this->ownBlocks( parse_blocks( (string) $post->post_content ) ), $links, $fragments );
	}

	/**
	 * Returns the Divi blocks out of a parsed tree, wherever they sit in it.
	 *
	 * A Divi block's own subtree comes with it; anything else is walked into, so a Divi module nested
	 * inside a core block is still found.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $blocks The parsed blocks.
	 * @return array         The blocks belonging to Divi.
	 */
	private function ownBlocks( $blocks ) {
		$own = [];
		foreach ( (array) $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( 0 === strpos( $name, self::BLOCK_PREFIX ) ) {
				$own[] = $block;

				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$own = array_merge( $own, $this->ownBlocks( $block['innerBlocks'] ) );
			}
		}

		return $own;
	}

	/**
	 * {@inheritdoc}
	 *
	 * A block's fields are its attributes; the rest of the node is structure and inner markup. The inner
	 * markup is deliberately left alone — it is already in post content, where the post scan reads it.
	 *
	 * @since 1.3.1
	 */
	protected function fieldsFor( $node ) {
		return isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	protected function isFieldsKey( $key ) {
		return 'attrs' === $key;
	}

	/**
	 * {@inheritdoc}
	 *
	 * A block tree repeats a field where a layout nests, the way a meta-stored one does.
	 *
	 * @since 1.3.1
	 */
	protected function readingRepeats() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	protected function urlAttributes() {
		return [
			'button_url',
			'url',
			'src',
			'image_url',
			'video_url',
			'logo_image_url',
			'portrait_url',
			'background_image',
			'video_webm',
			'video_mp4'
		];
	}
}