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
 * Links in the block content the editor keeps outside a post.
 *
 * A reusable block, a template, a template part and a block navigation are all posts, but of types
 * registered `public => false` — so the post scan, which walks the public types, has never visited
 * any of them. That is the whole of why they go unreported.
 *
 * They matter out of proportion to their number. A reusable block is embedded by reference, so a link
 * inside one exists nowhere else; a template part is a footer or a header, so a link inside one is on
 * every page of the site at once.
 *
 * Unlike a page builder's layout, post content here is the content and is writable, so these support
 * a real Edit URL and a real Unlink rather than refusing them.
 *
 * @since 1.3.1
 */
abstract class BlockContentObject extends ObjectType {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function primeCaches( $objectIds ) {
		$this->primePostCaches( $objectIds );
	}

	/**
	 * The post type these objects are.
	 *
	 * @since 1.3.1
	 *
	 * @return string The post type.
	 */
	abstract public function postType();

	/**
	 * {@inheritdoc}
	 *
	 * Not post-backed in the sense the post rules mean: these are not the site's posts and pages, so the
	 * post-type and post-status settings have nothing to say about them.
	 *
	 * @since 1.3.1
	 */
	public function isPostBacked() {
		return false;
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
	public function sweepBatch( $cursor, $limit ) {
		// The cursor belongs in the query, not in a filter afterwards. get_posts() has no "ID greater
		// than" argument, so this reads the rows directly the way the term and user sweeps do. Asking for
		// the first $limit rows and then dropping the ones already seen meant the second pass discarded
		// everything it had just read, which the caller reads as a short batch and so as a finished pass:
		// nothing past the first batch was ever swept, and those rows were re-read on every cycle.
		$rows = aioseoBrokenLinkChecker()->core->db->start( 'posts as p' )
			->select( 'p.ID' )
			->where( 'p.post_type', $this->postType() )
			->whereIn( 'p.post_status', [ 'publish', 'draft' ] )
			->whereRaw( 'p.ID > ' . (int) $cursor )
			->orderBy( 'p.ID ASC' )
			->limit( (int) $limit )
			->run()
			->result();

		$batch = [];
		foreach ( (array) $rows as $row ) {
			$batch[] = [
				'id'      => (int) $row->ID,
				'subtype' => ''
			];
		}

		return $batch;
	}

	/**
	 * {@inheritdoc}
	 *
	 * These are structural content rather than an extra place someone would think to switch off, so
	 * there is nothing to turn them off with.
	 *
	 * @since 1.3.1
	 */
	public function settingKey() {
		return null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function isRichText( $objectId = 0, $subtype = '' ) {
		return true;
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
	 * @since 1.3.1
	 */
	public function exists( $objectId, $subtype = '' ) {
		$post = get_post( (int) $objectId );

		return is_a( $post, 'WP_Post' ) && $this->postType() === $post->post_type && 'trash' !== $post->post_status;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function locationLabel( $objectId, $subtype = '' ) {
		if ( ! $this->exists( $objectId ) ) {
			return '';
		}

		// Decoded, as the post source's titles are. get_the_title() hands back the entities WordPress
		// stores, and the column renders text — so an apostrophe or an ampersand arrived as &#8217; and
		// &amp; and was read as those characters.
		$title = aioseoBrokenLinkChecker()->helpers->decodeHtmlEntities( get_the_title( (int) $objectId ) );

		return '' !== $title ? $title : __( '(no title)', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function getContent( $objectId, $subtype = '' ) {
		$post = get_post( (int) $objectId );

		return is_a( $post, 'WP_Post' ) ? (string) $post->post_content : '';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function saveContent( $objectId, $subtype, $content ) {
		$result = wp_update_post( [
			'ID'           => (int) $objectId,
			'post_content' => wp_slash( $content )
		], true );

		return 0 !== $result && ! is_wp_error( $result );
	}

	/**
	 * {@inheritdoc}
	 *
	 * These are not a place anyone views on its own — a template part is seen through the pages that
	 * include it — so there is nothing to link to.
	 *
	 * @since 1.3.1
	 */
	public function viewUrl( $objectId, $subtype = '' ) {
		return null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function canDelete( $objectId, $subtype = '' ) {
		return false;
	}
}