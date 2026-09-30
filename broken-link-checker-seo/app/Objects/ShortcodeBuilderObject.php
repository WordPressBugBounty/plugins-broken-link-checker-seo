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
 * A builder that keeps its layout as shortcodes in post content.
 *
 * Nothing is claimed from the post source here, and that is the important difference from the
 * builders that store a tree in meta: post content is not a copy of the layout, it *is* the layout.
 * The anchors and images inside a text module are real markup that the post scan already reads, and
 * taking them over would drop links the report currently finds.
 *
 * What the post scan cannot see is a URL held as a shortcode attribute — `button_url` on a Divi
 * button, `link` on a WPBakery heading — because there is no tag around it. That is all these read.
 *
 * @since 1.3.1
 */
abstract class ShortcodeBuilderObject extends BuilderObject {
	/**
	 * The attributes whose value is a URL, beyond the ones the naming pattern already catches.
	 *
	 * @since 1.3.1
	 *
	 * @return string[] The attribute names.
	 */
	abstract protected function urlAttributes();

	/**
	 * Whether this attribute is one that holds an address.
	 *
	 * Matched on the name as well as an explicit list, because a builder has more URL-bearing modules
	 * than anyone can enumerate and keeps adding them — Divi's module-link option is `link_option_url`,
	 * which no list of the obvious names would have caught. Whatever this lets through still has to look
	 * like an address before it is stored, so a wide net costs nothing.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $key The attribute name.
	 * @return bool        Whether it holds a URL.
	 */
	protected function isUrlAttribute( $key ) {
		$key = strtolower( (string) $key );

		if ( in_array( $key, $this->urlAttributes(), true ) ) {
			return true;
		}

		return (bool) preg_match( '/(^|_)(url|src|link|href)$/', $key );
	}

	/**
	 * The prefix every one of this builder's shortcodes starts with.
	 *
	 * @since 1.3.1
	 *
	 * @return string The prefix.
	 */
	abstract protected function tagPrefix();

	/**
	 * {@inheritdoc}
	 *
	 * Post content is the layout rather than a copy of it, so the post source keeps reading it. These
	 * rows only ever add the URLs it has no way to see.
	 *
	 * @since 1.3.1
	 */
	public function ownsPostContent( $objectId ) {
		return false;
	}

	/**
	 * The attributes whose value is the wording a reader would know the link by.
	 *
	 * @since 1.3.1
	 *
	 * @return string[] The attribute names.
	 */
	protected function textAttributes() {
		return [ 'title', 'text', 'button_text', 'heading', 'alt' ];
	}

	/**
	 * The attributes whose URL is the picture itself rather than somewhere to go.
	 *
	 * @since 1.3.1
	 *
	 * @return string[] The attribute names.
	 */
	protected function imageAttributes() {
		return [ 'src', 'image', 'image_url', 'background_image' ];
	}

	/**
	 * Turns one attribute value into the URL and the wording it carries.
	 *
	 * Most builders put a plain URL in the attribute. A builder that packs more than that into one
	 * value overrides this. {@see WpBakeryObject::readAttribute()}.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $value The attribute value.
	 * @return array{url: string, text: string} The URL and its wording.
	 */
	protected function readAttribute( $value ) {
		return [
			'url'  => trim( (string) $value ),
			'text' => ''
		];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function hasLayout( $objectId ) {
		$post = get_post( (int) $objectId );
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return false;
		}

		return false !== strpos( (string) $post->post_content, '[' . $this->tagPrefix() );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Reads the attributes off the shortcode tags themselves. Deliberately not `get_shortcode_regex()`,
	 * which only matches tags WordPress has registered — a builder whose theme is not the active one
	 * registers nothing, and its posts would then read as empty.
	 *
	 * @since 1.3.1
	 */
	protected function collect( $objectId, &$links, &$fragments ) {
		$post = get_post( (int) $objectId );
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		$pattern = '/\[' . preg_quote( $this->tagPrefix(), '/' ) . '[a-z0-9_]*((?:[^\]\[]|\[(?!\/))*?)\]/i';
		if ( ! preg_match_all( $pattern, (string) $post->post_content, $matches ) ) {
			return;
		}

		$imageAttributes = $this->imageAttributes();

		foreach ( $matches[1] as $attributeString ) {
			$attributes = shortcode_parse_atts( $attributeString );
			if ( ! is_array( $attributes ) || empty( $attributes ) ) {
				continue;
			}

			$fallbackText = '';
			foreach ( $this->textAttributes() as $key ) {
				if ( ! empty( $attributes[ $key ] ) && is_string( $attributes[ $key ] ) ) {
					$fallbackText = wp_strip_all_tags( $attributes[ $key ] );

					break;
				}
			}

			foreach ( $attributes as $key => $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}

				$read = $this->readAttribute( $value );
				if ( '' === $read['url'] ) {
					continue;
				}

				// A value that is already an absolute address is a link whatever the attribute is called,
				// which is the only way to reach the ones named after the thing they point at — Avada's
				// social row holds a URL under `facebook`, `tiktok` and thirty more that drift with each
				// release. A relative path is too weak to stand on its own, so that still needs the name.
				$isAbsolute = (bool) preg_match( '#^https?://#i', $read['url'] );

				if ( ! $isAbsolute ) {
					if ( ! $this->isUrlAttribute( $key ) ) {
						continue;
					}

					// An attribute holding a media library ID rather than an address says nothing to check.
					if ( ! preg_match( '#^(https?:)?//|^/#i', $read['url'] ) ) {
						continue;
					}
				}

				$this->addLink(
					$read['url'],
					'' !== $read['text'] ? $read['text'] : $fallbackText,
					$links,
					in_array( (string) $key, $imageAttributes, true ) ? 'src' : $key
				);
			}
		}
	}
}