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
 * A builder that keeps its layout as a structure in post meta.
 *
 * Post content is at best a rendered copy of it, so these claim it and the post source stands down.
 * The walk keys off the shape rather than the widget's name: a URL is a string under a link-ish key,
 * or a `url` inside an object, and markup is anything holding an anchor.
 *
 * @since 1.3.1
 */
abstract class MetaBuilderObject extends BuilderObject {

	/**
	 * The meta key the layout is stored under.
	 *
	 * @since 1.3.1
	 *
	 * @return string The meta key.
	 */
	abstract public function metaKey();

	/**
	 * The layout, decoded, or an empty array when there is none to read.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $objectId The post ID.
	 * @return array           The layout.
	 */
	protected function layout( $objectId ) {
		$raw = get_post_meta( (int) $objectId, $this->metaKey(), true );

		// One builder stores JSON, another stores an array that WordPress unserialises for us.
		if ( is_string( $raw ) ) {
			$raw = '' === $raw ? [] : json_decode( $raw, true );
		}

		return is_array( $raw ) ? $raw : [];
	}





	/**
	 * {@inheritdoc}
	 *
	 * A builder whose fields are the widget itself has them read once as fields and again by the walk
	 * beneath, and a nested layout can be reached from more than one direction.
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
}