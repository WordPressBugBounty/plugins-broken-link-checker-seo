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
 * Stands in for an `object_type` nothing registers any more.
 *
 * Rows survive the plugin that put them there. Rather than have every caller null-check, they get a
 * type that offers no actions and whose capability check can never pass, so the row stays visible in
 * the report and untouchable by the write paths. Its rows are deliberately not swept either — an
 * object we cannot describe is also one whose absence we cannot confirm.
 *
 * @since 1.3.1
 */
class UnknownObject extends ObjectType {
	/**
	 * The slug the row carries.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 *
	 * @param string $slug The slug the row carries.
	 */
	public function __construct( $slug = '' ) {
		$this->slug = (string) $slug;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return $this->slug;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'Unknown', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'Unknown', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: A capability no role has, so a write can never reach an object we cannot describe.
	 *
	 * @since 1.3.1
	 */
	public function capability() {
		return 'do_not_allow';
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
	public function supportedActions( $objectId = 0, $subtype = '' ) {
		return [ self::DISMISS ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function exists( $objectId, $subtype = '' ) {
		return false;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function locationLabel( $objectId, $subtype = '' ) {
		return '';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function editUrl( $objectId, $subtype = '' ) {
		return null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function isEnabled() {
		return false;
	}
}