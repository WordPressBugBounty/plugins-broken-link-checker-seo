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
 * Links found in term descriptions.
 *
 * @since 1.3.1
 */
class TermObject extends ObjectType {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function primeCaches( $objectIds ) {
		$objectIds = array_values( array_unique( array_filter( array_map( 'intval', (array) $objectIds ) ) ) );
		if ( empty( $objectIds ) || ! function_exists( '_prime_term_caches' ) ) {
			return;
		}

		_prime_term_caches( $objectIds, false );
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
	public function type() {
		return 'term';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'Term', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'Term Descriptions', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * The taxonomy's own name where we have one, since "Category" and "Tag" say more than "Term" does.
	 *
	 * @since 1.3.1
	 */
	public function itemLabel( $objectId, $subtype = '' ) {
		$taxonomy = $subtype ? get_taxonomy( $subtype ) : null;
		if ( ! $taxonomy ) {
			$term     = $this->getTerm( $objectId, $subtype );
			$taxonomy = $term ? get_taxonomy( $term->taxonomy ) : null;
		}

		return $taxonomy ? $taxonomy->labels->singular_name : $this->label();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function settingKey() {
		return 'terms';
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: A meta capability. WordPress maps it to the taxonomy's own `edit_terms` primitive, which
	 * is `manage_categories` for the core taxonomies.
	 *
	 * @since 1.3.1
	 */
	public function capability() {
		return 'edit_term';
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
	 * The primitive `edit_term` maps to comes from the taxonomy, which the row carries as its subtype,
	 * so which taxonomies are off limits is known without resolving a single term.
	 *
	 * @since 1.3.1
	 */
	public function userScopeCondition() {
		$taxonomies = get_taxonomies( [], 'objects' );
		$editable   = [];
		foreach ( $taxonomies as $taxonomy ) {
			if ( current_user_can( $taxonomy->cap->edit_terms ) ) {
				$editable[] = $taxonomy->name;
			}
		}

		if ( count( $editable ) === count( $taxonomies ) ) {
			return '';
		}

		return empty( $editable ) ? '0' : 'al.object_subtype IN (' . $this->quoteList( $editable ) . ')';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function exists( $objectId, $subtype = '' ) {
		return is_a( $this->getTerm( $objectId, $subtype ), 'WP_Term' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function locationLabel( $objectId, $subtype = '' ) {
		$term = $this->getTerm( $objectId, $subtype );
		if ( ! is_a( $term, 'WP_Term' ) ) {
			return '';
		}

		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy ) {
			return $term->name;
		}

		return sprintf(
			// Translators: 1 - A term name, 2 - A taxonomy's singular name.
			__( '%1$s (%2$s)', 'broken-link-checker-seo' ),
			$term->name,
			$taxonomy->labels->singular_name
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function editUrl( $objectId, $subtype = '' ) {
		$term = $this->getTerm( $objectId, $subtype );
		if ( ! is_a( $term, 'WP_Term' ) ) {
			return null;
		}

		$editUrl = get_edit_term_link( $term->term_id, $term->taxonomy );

		return $editUrl ? $editUrl : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function viewUrl( $objectId, $subtype = '' ) {
		$term = $this->getTerm( $objectId, $subtype );
		if ( ! is_a( $term, 'WP_Term' ) ) {
			return null;
		}

		$termLink = get_term_link( $term );

		return is_wp_error( $termLink ) ? null : $termLink;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function getContent( $objectId, $subtype = '' ) {
		$term = $this->getTerm( $objectId, $subtype );

		return is_a( $term, 'WP_Term' ) ? (string) $term->description : '';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function saveContent( $objectId, $subtype, $content ) {
		$term = $this->getTerm( $objectId, $subtype );
		if ( ! is_a( $term, 'WP_Term' ) ) {
			return false;
		}

		// wp_update_term() runs the description through wp_unslash(), so it has to arrive slashed.
		$result = wp_update_term( $term->term_id, $term->taxonomy, [ 'description' => wp_slash( $content ) ] );

		return ! is_wp_error( $result );
	}

	/**
	 * Resolves the term, preferring the stored taxonomy so a shared term ID can't be mistaken.
	 *
	 * @since 1.3.1
	 *
	 * @param  int           $objectId The term ID.
	 * @param  string        $subtype  The taxonomy.
	 * @return \WP_Term|null           The term.
	 */
	private function getTerm( $objectId, $subtype = '' ) {
		$term = $subtype ? get_term( (int) $objectId, $subtype ) : get_term( (int) $objectId );

		return is_a( $term, 'WP_Term' ) ? $term : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * A URL typed straight into this field is the ordinary way to reference something here, so it is a
	 * link whether or not anybody wrapped it in an anchor.
	 *
	 * @since 1.3.1
	 */
	public function findsBareUrls() {
		return true;
	}
}