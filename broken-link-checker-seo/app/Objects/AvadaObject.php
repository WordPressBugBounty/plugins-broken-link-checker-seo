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
 * Links found in Avada Builder shortcode attributes.
 *
 * Avada writes plain URLs into its module attributes — a button's `link`, an image frame's `src`. The
 * anchors and images inside a text module are ordinary markup that the post scan already reads, so
 * these rows only add what has no tag around it. {@see ShortcodeBuilderObject} for the rest.
 *
 * NOTE: `fusion_social_links` is why the base accepts an absolute URL under any attribute name: it
 * holds one per network, named after the network — `facebook`, `linkedin`, `tiktok` and around thirty
 * more that change with each release. No list of names would keep up with it.
 *
 * The builder is a plugin (Avada Builder, formerly Fusion Builder) and Avada is the theme, so either
 * being present means a page may hold this markup — and content outlives a deactivated plugin.
 *
 * @since 1.3.1
 */
class AvadaObject extends ShortcodeBuilderObject {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'avada';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'Avada Page', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'Avada', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	protected function tagPrefix() {
		return 'fusion_';
	}

	/**
	 * {@inheritdoc}
	 *
	 * The names taken from Avada's own markup. A relative path needs one of these; an absolute URL is
	 * taken whatever it sits under.
	 *
	 * @since 1.3.1
	 */
	protected function urlAttributes() {
		return [
			'link',
			'src',
			'image',
			'video_url',
			'button_url',
			'video_mp4',
			'video_webm',
			'video_ogv'
		];
	}
}