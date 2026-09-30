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
 * Links found in a SeedProd page.
 *
 * The one builder here that keeps its layout in a post column rather than post meta: the document is
 * JSON in `post_content_filtered`, under a `document` key, and nests section, row and column nodes
 * down to the blocks that carry the links. A block's fields are its `settings`, the same shape
 * Elementor uses, so the walk in {@see BuilderObject} reads it unchanged.
 *
 * @since 1.3.1
 */
class SeedProdObject extends BuilderObject {
	/**
	 * The meta key marking a standalone SeedProd landing page.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const LANDING_PAGE_KEY = '_seedprod_page';

	/**
	 * The meta key marking an ordinary post or page whose content SeedProd took over.
	 *
	 * SeedProd sets one flag or the other, never both: taking over an existing page deletes the landing
	 * page marker. Either one means the layout is what the visitor sees.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const EDITED_KEY = '_seedprod_edited_with_seedprod';

	/**
	 * {@inheritdoc}
	 *
	 * Adds the key a section, row or column keeps its background picture under. Without it the walk has
	 * no reason to read the value at all, so a broken background would go unreported.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const LINK_KEYS = [
		'url',
		'link',
		'href',
		'src',
		'video',
		'website_link',
		'button_link',
		'link_url',
		'linkUrl',
		'imageUrl',
		'videoUrl',
		'backgroundImage',
		'bgImage'
	];

	/**
	 * {@inheritdoc}
	 *
	 * Adds SeedProd's own spelling of alternative text, which is the only wording an image block holds -
	 * without it the report shows the picture's URL and nothing a reader would recognise it by.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const TEXT_KEYS = [ 'text', 'title', 'title_text', 'heading', 'button_text', 'alt', 'altTxt' ];

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const IMAGE_KEYS = [ 'src', 'image', 'image_url', 'background_image', 'imageUrl', 'backgroundImage', 'bgImage' ];

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'seedprod';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'SeedProd Page', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'SeedProd', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function tagLabel( $objectId = 0, $subtype = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return __( 'SeedProd', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * SeedProd renders the layout into post content as well, so a page it built holds every link twice
	 * until the post source stands down.
	 *
	 * @since 1.3.1
	 */
	public function ownsPostContent( $objectId ) {
		$objectId = (int) $objectId;

		return '1' === (string) get_post_meta( $objectId, self::LANDING_PAGE_KEY, true )
			|| '1' === (string) get_post_meta( $objectId, self::EDITED_KEY, true );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function hasLayout( $objectId ) {
		return ! empty( $this->layout( $objectId ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	protected function collect( $objectId, &$links, &$fragments ) {
		$this->collectLinks( $this->layout( $objectId ), $links, $fragments );
	}

	/**
	 * {@inheritdoc}
	 *
	 * A block's fields hang off its own `settings` key; everything else on the node is structure.
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

	/**
	 * {@inheritdoc}
	 *
	 * A node's fields are read once as fields and again by the walk beneath it, so the same link can be
	 * reached twice.
	 *
	 * @since 1.3.1
	 */
	protected function readingRepeats() {
		return true;
	}

	/**
	 * The layout, decoded, or an empty array when there is none to read.
	 *
	 * NOTE: Read off the post rather than out of post meta, which is where every other builder keeps it.
	 * The column holds the whole SeedProd payload - page settings, scripts, redirect rules - and only its
	 * `document` is the layout, so the walk is handed that rather than the lot.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $objectId The post ID.
	 * @return array           The layout.
	 */
	private function layout( $objectId ) {
		$post = get_post( (int) $objectId );
		if ( ! is_a( $post, 'WP_Post' ) || '' === (string) $post->post_content_filtered ) {
			return [];
		}

		$payload = json_decode( (string) $post->post_content_filtered, true );

		return isset( $payload['document'] ) && is_array( $payload['document'] ) ? $payload['document'] : [];
	}
}