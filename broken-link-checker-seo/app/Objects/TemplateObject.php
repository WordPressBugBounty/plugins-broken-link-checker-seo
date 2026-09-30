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
 * Links in a block theme's templates and template parts.
 *
 * Only a customised one is a post: until someone edits it, a template lives as a file in the theme
 * and there is nothing in the database to report on. What is here is therefore what a person changed,
 * which is where a hand-written link would be.
 *
 * A part is a header or a footer, so a link inside one is on every page that includes it — the widest
 * reach of anything the report covers, from the fewest rows.
 *
 * @since 1.3.1
 */
class TemplateObject extends BlockContentObject {
	/**
	 * Whether this covers parts rather than whole templates.
	 *
	 * @since 1.3.1
	 *
	 * @var bool
	 */
	protected $isPart = false;

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return $this->isPart ? 'template_part' : 'template';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function postType() {
		return $this->isPart ? 'wp_template_part' : 'wp_template';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return $this->isPart
			? __( 'Template Part', 'broken-link-checker-seo' )
			: __( 'Template', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return $this->isPart
			? __( 'Template Parts', 'broken-link-checker-seo' )
			: __( 'Templates', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Shared with template parts, which subclass this: they are the same thing to a reader, and nobody
	 * wants two switches for one idea.
	 *
	 * @since 1.3.1
	 */
	public function settingKey() {
		return 'templates';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function tagLabel( $objectId = 0, $subtype = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return $this->isPart
			? __( 'Part', 'broken-link-checker-seo' )
			: __( 'Template', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Editing a template is a theme change, not a content one.
	 *
	 * @since 1.3.1
	 */
	public function capability() {
		return 'edit_theme_options';
	}

	/**
	 * {@inheritdoc}
	 *
	 * The site editor addresses these by theme and slug rather than by ID, so the ID has to be resolved
	 * back into that form.
	 *
	 * @since 1.3.1
	 */
	public function editUrl( $objectId, $subtype = '' ) {
		if ( ! $this->exists( $objectId ) ) {
			return null;
		}

		$post  = get_post( (int) $objectId );
		$terms = get_the_terms( (int) $objectId, 'wp_theme' );
		$theme = ! is_wp_error( $terms ) && ! empty( $terms ) ? $terms[0]->name : get_stylesheet();

		return admin_url( sprintf(
			'site-editor.php?postType=%1$s&postId=%2$s&canvas=edit',
			$this->postType(),
			rawurlencode( $theme . '//' . $post->post_name )
		) );
	}
}