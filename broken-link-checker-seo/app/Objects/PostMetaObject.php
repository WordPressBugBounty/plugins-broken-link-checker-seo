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
 * Links found in a post's custom fields, addressed by the meta key they are stored under.
 *
 * Extends the post shape rather than repeating it: the field belongs to a post, so the capability
 * that governs writing to it is the post's, and the report's post-type, post-status and excluded-post
 * rules apply to it for the same reason.
 *
 * @since 1.3.1
 */
class PostMetaObject extends PostObject {
	/**
	 * Which of a post's fields hold links, and how to read and write one.
	 *
	 * @since 1.3.1
	 *
	 * @var CustomFields
	 */
	private $fields = null;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		$this->fields = new CustomFields();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'post_meta';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'Custom Field', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'Custom Fields', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function settingKey() {
		return 'customFields';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function isSubtypeAddressed() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * The URL is the whole of what a url or link field holds, so there is no anchor to strip and no
	 * surrounding text to leave behind. Deleting the field's value is not the report's to do — the field
	 * is part of the post's structure, not a line in its content.
	 *
	 * @since 1.3.1
	 */
	public function supportedActions( $objectId = 0, $subtype = '' ) {
		if ( $this->isRichText( $objectId, $subtype ) ) {
			return [ self::EDIT_URL, self::UNLINK, self::RECHECK, self::DISMISS ];
		}

		return [ self::EDIT_URL, self::RECHECK, self::DISMISS ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function refusedActions( $objectId = 0, $subtype = '' ) {
		if ( $this->isRichText( $objectId, $subtype ) ) {
			return [];
		}

		return [ self::UNLINK => $this->unlinkRefusalMessage( $objectId, $subtype ) ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function isRichText( $objectId = 0, $subtype = '' ) {
		return $this->fields->isRichText( (int) $objectId, (string) $subtype );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function unlinkRefusalMessage( $objectId = 0, $subtype = '' ) {
		return __( 'The URL is the whole of what this field holds, so there is no anchor to remove. Change the URL instead, or clear the field in the post editor.', 'broken-link-checker-seo' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	}

	/**
	 * {@inheritdoc}
	 *
	 * A field whose definition is gone is a location that no longer exists, even though its post does:
	 * nothing on the site reads it any more, and the report has no shape to rewrite it in.
	 *
	 * @since 1.3.1
	 */
	public function exists( $objectId, $subtype = '' ) {
		if ( ! parent::exists( $objectId ) ) {
			return false;
		}

		if ( ! $this->fields->isScannable( (int) $objectId, (string) $subtype ) ) {
			return false;
		}

		return metadata_exists( 'post', (int) $objectId, (string) $subtype );
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: Names the meta key rather than the field's label. The key is what identifies one row of a
	 * repeater, and it is what a person searching the post's fields for the link will match on.
	 *
	 * @since 1.3.1
	 */
	public function locationLabel( $objectId, $subtype = '' ) {
		if ( ! parent::exists( $objectId ) ) {
			return '';
		}

		$postTitle = aioseoBrokenLinkChecker()->helpers->getPostTitle( (int) $objectId );
		$fieldName = $this->fieldName( (int) $objectId, (string) $subtype );

		// Nothing worth showing in brackets: the post's own name says as much as the reader can use.
		if ( '' === $fieldName ) {
			return $postTitle;
		}

		return sprintf(
			// Translators: 1 - A post title, 2 - The name of a custom field.
			__( '%1$s (%2$s)', 'broken-link-checker-seo' ),
			$postTitle,
			$fieldName
		);
	}

	/**
	 * What to call the field a link was found in.
	 *
	 * A key somebody chose reads as itself - an ACF field name says something to whoever named it. A key
	 * we declare ourselves gets the name its own editor uses. A protected key we have no name for is
	 * dropped rather than shown raw: "_product_url" in brackets after a product name is the storage
	 * talking, not the report.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $metaKey The meta key.
	 * @return string          The name, or an empty string when there is nothing worth showing.
	 */
	private function fieldName( $objectId, $metaKey ) {
		if ( '' === $metaKey ) {
			return '';
		}

		// What the editor calls it, where the field declares a name. "(custom_link)" is the plumbing;
		// nobody filling the field in ever saw that.
		$label = $this->fields->fieldLabel( $objectId, $metaKey );
		if ( '' !== $label ) {
			return $label;
		}

		return is_protected_meta( $metaKey, 'post' ) ? '' : $metaKey;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function canDelete( $objectId, $subtype = '' ) {
		return false;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function getContent( $objectId, $subtype = '' ) {
		return $this->fields->value( (int) $objectId, (string) $subtype );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function saveContent( $objectId, $subtype, $content ) {
		return $this->fields->writeContent( (int) $objectId, (string) $subtype, $content );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function getUrl( $objectId, $subtype = '' ) {
		return $this->fields->value( (int) $objectId, (string) $subtype );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function setUrl( $objectId, $subtype, $url ) {
		$refusal = $this->urlRefusal( $objectId, $subtype );
		if ( $refusal ) {
			return $refusal;
		}

		return $this->fields->writeUrl( (int) $objectId, (string) $subtype, $url );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function urlRefusal( $objectId, $subtype = '' ) {
		if ( $this->fields->isScannable( (int) $objectId, (string) $subtype ) ) {
			return null;
		}

		return new \WP_Error(
			'blc_custom_field_undeclared',
			__( 'Nothing on this site declares that field any more, so the report cannot tell what writing to it would mean.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			[ 'status' => 409 ]
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function scannableValues( $objectId ) {
		return $this->fields->scannableValues( (int) $objectId );
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