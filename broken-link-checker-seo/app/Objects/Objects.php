<?php
namespace AIOSEO\BrokenLinkChecker\Objects;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The kinds of object the scan reads links out of.
 *
 * @since 1.3.1
 */
class Objects {
	/**
	 * The registered types, keyed by slug.
	 *
	 * @since 1.3.1
	 *
	 * @var ObjectType[]|null
	 */
	private $types = null;

	/**
	 * Returns every registered type.
	 *
	 * @since 1.3.1
	 *
	 * @return ObjectType[] The types, keyed by slug.
	 */
	public function all() {
		if ( null !== $this->types ) {
			return $this->types;
		}

		$types = [
			'PostObject',
			'PostMetaObject',
			'TermObject',
			'UserObject',
			'MenuItemObject',
			'ReusableBlockObject',
			'NavigationObject',
			'TemplateObject',
			'TemplatePartObject'
		];

		// Offered only where it could find anything, so the report and its source filter don't carry a
		// builder the site doesn't run.
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$types[] = 'ElementorObject';
		}

		if ( defined( 'SITEORIGIN_PANELS_VERSION' ) ) {
			$types[] = 'SiteOriginObject';
		}

		if ( defined( 'WPB_VC_VERSION' ) ) {
			$types[] = 'WpBakeryObject';
		}

		// Divi is a theme first and a plugin second, so what counts is the builder being loaded at all —
		// by Divi, by Extra, or by the standalone Divi Builder.
		if ( defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_BUILDER_PLUGIN_VERSION' ) ) {
			$types[] = 'DiviObject';
		}

		// Avada is the theme and its builder is a plugin, and either one means a page may hold the
		// markup — content outlives a plugin someone switched off.
		if ( defined( 'AVADA_VERSION' ) || defined( 'FUSION_BUILDER_VERSION' ) ) {
			$types[] = 'AvadaObject';
		}

		// Either constant: the editions do not share one. Lite defines SEEDPROD_VERSION, Pro defines
		// SEEDPROD_PRO_VERSION only - so gating on the first alone left every paying customer's landing
		// pages attributed to post content.
		if ( defined( 'SEEDPROD_VERSION' ) || defined( 'SEEDPROD_PRO_VERSION' ) ) {
			$types[] = 'SeedProdObject';
		}

		$this->types = [];
		foreach ( $types as $class ) {
			$class = __NAMESPACE__ . '\\' . $class;

			// Named above and constructed here, so a type whose file is no longer on disk is skipped rather
			// than taking the request with it: a downgrade replaces our files partway through, and whatever
			// is still running autoloads the build that replaced them. class_exists() cannot answer this on
			// its own - every type extends another, so resolving a child that is present loads the parent
			// that is not, and the error comes from the child's own file.
			try {
				$type = new $class();
			} catch ( \Throwable $e ) {
				continue;
			}

			$this->types[ $type->type() ] = $type;
		}

		/**
		 * Filters the kinds of object the scan reads links out of.
		 *
		 * @since 1.3.1
		 *
		 * @param ObjectType[] $types The types, keyed by slug.
		 */
		$filtered = apply_filters( 'aioseo_blc_object_types', $this->types );
		if ( ! is_array( $filtered ) ) {
			return $this->types;
		}

		// A type that isn't one would break every caller, which all treat the registry as trustworthy.
		foreach ( $filtered as $slug => $type ) {
			if ( ! is_a( $type, 'AIOSEO\\BrokenLinkChecker\\Objects\\ObjectType' ) ) {
				unset( $filtered[ $slug ] );
			}
		}

		$this->types = $filtered;

		return $this->types;
	}

	/**
	 * Returns the type with the given slug, or a stand-in when nothing registers it.
	 *
	 * @since 1.3.1
	 *
	 * @param  string     $slug The slug.
	 * @return ObjectType       The type.
	 */
	public function get( $slug ) {
		$slug  = (string) $slug;
		$types = $this->all();

		if ( isset( $types[ $slug ] ) ) {
			return $types[ $slug ];
		}

		// The links table's column defaults to the post shape, so an empty slug is a post row.
		if ( '' === $slug && isset( $types['post'] ) ) {
			return $types['post'];
		}

		return new UnknownObject( $slug );
	}

	/**
	 * Returns the kind that owns the given post's content, where one does.
	 *
	 * @since 1.3.1
	 *
	 * @param  int              $postId The post ID.
	 * @return ObjectType|null          The owner, or null where post content is the post's own.
	 */
	public function postContentOwner( $postId ) {
		foreach ( $this->all() as $type ) {
			if ( $type->ownsPostContent( (int) $postId ) ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Returns the types whose source is switched on.
	 *
	 * @since 1.3.1
	 *
	 * @return ObjectType[] The types, keyed by slug.
	 */
	public function enabled() {
		return array_filter( $this->all(), function( $type ) {
			return $type->isEnabled();
		} );
	}

	/**
	 * Returns the slugs of the types whose source is switched off.
	 *
	 * @since 1.3.1
	 *
	 * @return string[] The slugs.
	 */
	public function disabledSlugs() {
		return array_values( array_diff( array_keys( $this->all() ), array_keys( $this->enabled() ) ) );
	}

	/**
	 * Whether the source for the given slug is switched on.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $slug The slug.
	 * @return bool         Whether it is enabled.
	 */
	public function isEnabled( $slug ) {
		$types = $this->all();

		return isset( $types[ (string) $slug ] ) && $types[ (string) $slug ]->isEnabled();
	}
}