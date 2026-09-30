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
 * Links in a reusable block, which WordPress calls a synced pattern.
 *
 * Embedded by reference wherever it is used, so a link inside one lives only here — the posts that
 * show it hold nothing but a reference to it. That makes it the one kind where the report finding
 * nothing is most misleading: the link is on many pages and in none of their content.
 *
 * @since 1.3.1
 */
class ReusableBlockObject extends BlockContentObject {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'reusable_block';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function postType() {
		return 'wp_block';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'Synced Pattern', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'Synced Patterns', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function settingKey() {
		return 'patterns';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function tagLabel( $objectId = 0, $subtype = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return __( 'Pattern', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: The meta capability, not `edit_posts`. A pattern is a post owned by whoever made it, and
	 * core maps editing someone else's onto `edit_others_posts`, which an author does not have.
	 *
	 * @since 1.3.1
	 */
	public function capability() {
		return 'edit_post';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function capabilityTakesObjectId() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Without this the base returns an empty condition for any kind whose capability takes an object ID,
	 * which leaves every row of that kind in and defers to the per-row check. That check withholds the
	 * location but not the URL, so a reader was shown addresses out of patterns only an administrator can
	 * open - and a URL is the thing worth withholding.
	 *
	 * NOTE: A subquery rather than the joined posts table, because this kind is not post-backed: `p` is
	 * only joined for the kinds that are, so it is NULL on these rows.
	 *
	 * @since 1.3.1
	 */
	public function userScopeCondition() {
		$postType = get_post_type_object( $this->postType() );
		if ( empty( $postType ) ) {
			return '0';
		}

		if ( current_user_can( $postType->cap->edit_others_posts ) ) {
			return '';
		}

		if ( ! current_user_can( $postType->cap->edit_posts ) ) {
			return '0';
		}

		global $wpdb;

		return 'al.object_id IN ( SELECT ID FROM ' . $wpdb->posts . " WHERE post_type = '" . esc_sql( $this->postType() ) . "' AND post_author = " . (int) get_current_user_id() . ' )';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function editUrl( $objectId, $subtype = '' ) {
		if ( ! $this->exists( $objectId ) ) {
			return null;
		}

		return admin_url( 'site-editor.php?postType=wp_block&postId=' . (int) $objectId . '&canvas=edit' );
	}
}