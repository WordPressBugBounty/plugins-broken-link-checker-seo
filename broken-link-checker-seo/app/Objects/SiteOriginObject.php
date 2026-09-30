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
 * Links found in a SiteOrigin Page Builder layout.
 *
 * SiteOrigin stores the layout as an array in `panels_data`, which WordPress unserialises for us, so
 * unlike Elementor there is no JSON to decode. A widget's fields are the widget itself rather than a
 * key hanging off it, and `panels_info` is what marks one. {@see BuilderObject} for the rest.
 *
 * @since 1.3.1
 */
class SiteOriginObject extends MetaBuilderObject {
	/**
	 * The meta key SiteOrigin stores a layout in.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const META_KEY = 'panels_data';

	/**
	 * The key every widget in a layout carries, whatever else it holds.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const WIDGET_MARKER = 'panels_info';

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'siteorigin';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'SiteOrigin Page', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'SiteOrigin', 'broken-link-checker-seo' );
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
	 * SiteOrigin's `copy-content` setting mirrors the rendered layout into post content, which holds
	 * every link a second time. With the setting off there is no mirror, and whatever post content still
	 * holds is what the page was before the builder took it over — which the page no longer renders.
	 * Either way the layout is the only copy worth reporting, so the post source stands down.
	 *
	 * @since 1.3.1
	 */
	public function ownsPostContent( $objectId ) {
		return ! empty( $this->layout( $objectId ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * A widget's fields are the widget, so the marker is what says a node has any.
	 *
	 * @since 1.3.1
	 */
	protected function fieldsFor( $node ) {
		return isset( $node[ self::WIDGET_MARKER ] ) ? $node : null;
	}
}