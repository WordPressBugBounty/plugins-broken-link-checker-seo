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
 * Links found in WPBakery shortcode attributes.
 *
 * WPBakery packs a link, its wording and its target into one attribute, url-encoded and separated by
 * pipes: `link="url:https%3A%2F%2Fexample.com%2F|title:Read%20more|target:_blank"`. Nothing that reads
 * markup can see that, which is what these rows add. {@see ShortcodeBuilderObject} for the rest.
 *
 * @since 1.3.1
 */
class WpBakeryObject extends ShortcodeBuilderObject {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'wpbakery';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'WPBakery Page', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'WPBakery', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	protected function tagPrefix() {
		return 'vc_';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	protected function urlAttributes() {
		return [ 'link', 'url', 'source', 'video_link', 'onclick_url', 'image', 'custom_src', 'i_link' ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * Reads the url and title out of the packed form, falling back to a plain value for the attributes
	 * that hold one. The parts are url-encoded, so a link with a query string survives the split.
	 *
	 * @since 1.3.1
	 */
	protected function readAttribute( $value ) {
		$value = trim( (string) $value );

		if ( false === strpos( $value, 'url:' ) ) {
			return [
				'url'  => $value,
				'text' => ''
			];
		}

		$url  = '';
		$text = '';
		foreach ( explode( '|', $value ) as $part ) {
			if ( 0 === strpos( $part, 'url:' ) ) {
				$url = rawurldecode( substr( $part, 4 ) );

				continue;
			}

			if ( 0 === strpos( $part, 'title:' ) ) {
				$text = rawurldecode( substr( $part, 6 ) );
			}
		}

		return [
			'url'  => trim( $url ),
			'text' => trim( $text )
		];
	}
}