<?php
namespace AIOSEO\BrokenLinkChecker\Objects;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which of a post's custom fields hold links, and how to read and write one.
 *
 * A field is only ever reached because something declared it: an ACF field definition, a
 * `register_meta()` entry, or the `aioseo_blc_scannable_meta_keys` filter. Discovery never walks a
 * site's meta keys — `SELECT meta_key ... GROUP BY meta_key` is a filesort over the whole table.
 *
 * Known blind spots, all of them cases where nothing declares the field: meta written by a plugin
 * that neither uses ACF nor calls `register_meta()` (Meta Box, Toolset, a hand-rolled
 * `update_post_meta()`), links inside a serialised array, ACF clone fields, ACF options-page and
 * user- or term-level fields, `_`-prefixed protected meta, and a URL a template assembles at render
 * time out of several fields. The filter is the way past all of them.
 *
 * @since 1.3.1
 */
class CustomFields {
	/**
	 * The ACF field types whose value is prose a link can be anchored in.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const RICH_TEXT_TYPES = [ 'text', 'textarea', 'wysiwyg' ];

	/**
	 * The ACF field types whose value is the URL itself.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const URL_TYPES = [ 'url', 'link' ];

	/**
	 * The ACF field types that hold other fields, whose names prefix their children's meta keys.
	 *
	 * NOTE: `repeater` and `flexible_content` put a row index between the two, which is why the shape
	 * they expand to has to be matched with a LIKE rather than an exact key.
	 *
	 * @since 1.3.1
	 *
	 * @var array<string, bool>
	 */
	const CONTAINER_TYPES = [
		'repeater'         => true,
		'flexible_content' => true,
		'group'            => false
	];

	/**
	 * The type given to a key that was declared without one, whose shape the value decides.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const UNDECLARED_TYPE = 'undeclared';

	/**
	 * The most characters a meta key can have and still be stored whole.
	 *
	 * NOTE: The width of the `object_subtype` column. WordPress runs without STRICT_TRANS_TABLES, so a
	 * longer key would be truncated silently — and a truncated meta key still addresses a real, different
	 * field on the right post, which would pass the capability check and be written to.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const MAX_KEY_LENGTH = 191;

	/**
	 * How deep a field tree is walked before it is treated as a cycle.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const MAX_DEPTH = 10;

	/**
	 * Every ACF field definition in the database, keyed by post ID.
	 *
	 * @since 1.3.1
	 *
	 * @var array|null
	 */
	private $acfFields = null;

	/**
	 * The IDs of the ACF field definitions each parent holds.
	 *
	 * @since 1.3.1
	 *
	 * @var array
	 */
	private $acfChildren = [];

	/**
	 * The field trees already built, keyed by the ID of the definition they hang off.
	 *
	 * @since 1.3.1
	 *
	 * @var array
	 */
	private $nodes = [];

	/**
	 * What each post's fields resolved to, keyed by post ID.
	 *
	 * @since 1.3.1
	 *
	 * @var array
	 */
	private $definitions = [];

	/**
	 * The scannable string of every one of the given post's custom fields that holds a link.
	 *
	 * @since 1.3.1
	 *
	 * @param  int                   $postId The post ID.
	 * @return array<string, string>         The values, keyed by meta key.
	 */
	public function scannableValues( $postId ) {
		$definitions = $this->definitions( $postId );

		$values = [];
		foreach ( $this->readMeta( $postId, $definitions ) as $metaKey => $raw ) {
			$fieldType = $this->fieldType( $metaKey, $definitions );
			if ( '' === $fieldType ) {
				continue;
			}

			$value = $this->normalizeValue( $fieldType, $raw );
			if ( '' === $value ) {
				continue;
			}

			$values[ $metaKey ] = $value;
		}

		return $values;
	}

	/**
	 * Whether the given meta key is one of the post's declared, scannable fields.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $postId  The post ID.
	 * @param  string $metaKey The meta key.
	 * @return bool            Whether it is scannable.
	 */
	public function isScannable( $postId, $metaKey ) {
		return '' !== $this->fieldType( $metaKey, $this->definitions( $postId ) );
	}

	/**
	 * Whether the given field's links live inside prose, as opposed to the URL being the whole value.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $postId  The post ID.
	 * @param  string $metaKey The meta key.
	 * @return bool            Whether it is rich text.
	 */
	public function isRichText( $postId, $metaKey ) {
		$fieldType = $this->fieldType( $metaKey, $this->definitions( $postId ) );
		if ( in_array( $fieldType, self::URL_TYPES, true ) ) {
			return false;
		}

		if ( self::UNDECLARED_TYPE !== $fieldType ) {
			return true;
		}

		// Declared without a type, so the value is all there is to go on. Prose is the safer reading: it
		// rewrites the anchor it found and leaves the rest of the value alone.
		$raw = get_post_meta( (int) $postId, $metaKey, true );

		return ! is_string( $raw ) || false !== strpos( $raw, '<a ' ) || ! $this->isBareUrl( $raw );
	}

	/**
	 * The given field's value, in the shape the scan and the rewrite both read.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $postId  The post ID.
	 * @param  string $metaKey The meta key.
	 * @return string          The value.
	 */
	public function value( $postId, $metaKey ) {
		$fieldType = $this->fieldType( $metaKey, $this->definitions( $postId ) );
		if ( '' === $fieldType ) {
			return '';
		}

		return $this->normalizeValue( $fieldType, get_post_meta( (int) $postId, $metaKey, true ) );
	}

	/**
	 * Writes prose back to the given field.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $postId  The post ID.
	 * @param  string $metaKey The meta key.
	 * @param  string $content The content.
	 * @return bool            Whether it was written.
	 */
	public function writeContent( $postId, $metaKey, $content ) {
		if ( ! $this->isScannable( $postId, $metaKey ) ) {
			return false;
		}

		// update_post_meta() runs the value through wp_unslash(), so it has to arrive slashed.
		return false !== update_post_meta( (int) $postId, $metaKey, wp_slash( $content ) );
	}

	/**
	 * Writes a URL back to the given field, whichever of the two shapes it stores it in.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $postId  The post ID.
	 * @param  string $metaKey The meta key.
	 * @param  string $url     The URL.
	 * @return bool            Whether it was written.
	 */
	public function writeUrl( $postId, $metaKey, $url ) {
		if ( ! $this->isScannable( $postId, $metaKey ) ) {
			return false;
		}

		$raw = get_post_meta( (int) $postId, $metaKey, true );

		// An ACF link field stores a title and a target alongside the URL, so only the URL is replaced.
		if ( is_array( $raw ) && array_key_exists( 'url', $raw ) ) {
			$raw['url'] = $url;

			return false !== update_post_meta( (int) $postId, $metaKey, wp_slash( $raw ) );
		}

		return false !== update_post_meta( (int) $postId, $metaKey, wp_slash( $url ) );
	}

	/**
	 * The value a link is read out of, or an empty string when there is none to read.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $fieldType The field type.
	 * @param  mixed  $raw       The stored value.
	 * @return string            The value.
	 */
	private function normalizeValue( $fieldType, $raw ) {
		if ( 'link' === $fieldType ) {
			$raw = aioseoBrokenLinkChecker()->helpers->maybeUnserialize( $raw );
			$raw = is_array( $raw ) && isset( $raw['url'] ) ? $raw['url'] : $raw;
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		// A value that is still serialised is an array of its own, which is not something the report can
		// rewrite one field of.
		if ( is_serialized( $raw ) ) {
			return '';
		}

		// A cheap reject on a key that already passed the definitions, not a way of finding one. The
		// leading-slash case is only allowed where the URL is the whole value, so there is nothing to
		// mistake a fraction or a date for.
		if ( false !== strpos( $raw, 'http' ) || false !== strpos( $raw, '<a ' ) ) {
			return $raw;
		}

		return in_array( $fieldType, self::URL_TYPES, true ) && $this->isBareUrl( $raw ) ? $raw : '';
	}

	/**
	 * Whether the whole of the given value is a URL.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $value The value.
	 * @return bool          Whether it is a URL.
	 */
	private function isBareUrl( $value ) {
		$value = trim( $value );
		if ( '' === $value || preg_match( '/\s/', $value ) ) {
			return false;
		}

		if ( 0 === strpos( $value, '/' ) ) {
			return true;
		}

		$parsedUrl = wp_parse_url( $value );

		return ! empty( $parsedUrl['scheme'] ) && ! empty( $parsedUrl['host'] );
	}

	/**
	 * The type of the field the given meta key belongs to, or an empty string when nothing declares it.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $metaKey     The meta key.
	 * @param  array  $definitions The post's resolved definitions.
	 * @return string              The field type.
	 */
	private function fieldType( $metaKey, $definitions ) {
		if ( self::MAX_KEY_LENGTH < strlen( $metaKey ) ) {
			return '';
		}

		if ( isset( $definitions['keys'][ $metaKey ] ) ) {
			return $definitions['keys'][ $metaKey ];
		}

		return (string) $this->walkTree( $metaKey, $definitions['tree'] );
	}

	/**
	 * Resolves the remainder of a meta key against the fields a container holds.
	 *
	 * NOTE: The longest matching name is tried first. ACF's own key naming is ambiguous — a field called
	 * `hero_url` and a group `hero` holding a `url` produce the same key — so this only decides which of
	 * two readings is preferred, not which one ACF meant.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $metaKey The meta key, or what is left of one.
	 * @param  array  $nodes   The fields to resolve against.
	 * @param  int    $depth   How far in the walk already is.
	 * @return string          The field type, or an empty string.
	 */
	private function walkTree( $metaKey, $nodes, $depth = 0, $want = 'type' ) {
		if ( self::MAX_DEPTH <= $depth ) {
			return '';
		}

		foreach ( $nodes as $name => $node ) {
			if ( $metaKey === $name ) {
				return empty( $node['children'] ) && isset( $node[ $want ] ) ? $node[ $want ] : '';
			}

			if ( 0 !== strpos( $metaKey, $name . '_' ) ) {
				continue;
			}

			$remainder = substr( $metaKey, strlen( $name ) + 1 );

			if ( ! empty( $node['indexed'] ) ) {
				if ( ! preg_match( '/^[0-9]+_(.+)$/', $remainder, $matches ) ) {
					continue;
				}

				$remainder = $matches[1];
			}

			$resolved = $this->walkTree( $remainder, $node['children'], $depth + 1, $want );
			if ( '' !== $resolved ) {
				return $resolved;
			}
		}

		return '';
	}

	/**
	 * The exact keys and container prefixes the given post's fields come down to.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $postId The post ID.
	 * @return array{keys: array<string, string>, labels: array<string, string>, tree: array, prefixes: string[]} The definitions.
	 */
	private function definitions( $postId ) {
		$postId = (int) $postId;
		if ( isset( $this->definitions[ $postId ] ) ) {
			return $this->definitions[ $postId ];
		}

		// A long scan walks thousands of posts in one request, and each one's groups are only asked for
		// while it is being indexed.
		if ( 200 < count( $this->definitions ) ) {
			$this->definitions = [];
		}

		$definitions = [
			'keys'     => [],
			// Beside 'keys' rather than in it: that one holds the field's type, which is what
			// {@see self::fieldType()} reads. A leaf field has no children to walk for its label.
			'labels'   => [],
			'tree'     => [],
			'prefixes' => []
		];

		foreach ( $this->postFieldGroups( $postId ) as $fieldGroup ) {
			foreach ( $this->groupNodes( $fieldGroup ) as $name => $node ) {
				// A name the subtype column cannot hold whole is one no key under it can be addressed by
				// either, so neither the name nor its prefix is worth fetching rows for.
				if ( self::MAX_KEY_LENGTH < strlen( $name ) ) {
					continue;
				}

				if ( empty( $node['children'] ) ) {
					if ( $this->isScannableType( $node['type'] ) ) {
						$definitions['keys'][ $name ] = $node['type'];

						if ( ! empty( $node['label'] ) ) {
							$definitions['labels'][ $name ] = (string) $node['label'];
						}
					}

					continue;
				}

				// Without a scannable field somewhere underneath it, the prefix would fetch rows only to
				// have every one of them fail to resolve.
				if ( $this->holdsScannableField( $node ) ) {
					$definitions['tree'][ $name ] = $node;
					$definitions['prefixes'][]    = $name;
				}
			}
		}

		foreach ( $this->declaredKeys( $postId ) as $metaKey ) {
			if ( ! isset( $definitions['keys'][ $metaKey ] ) ) {
				$definitions['keys'][ $metaKey ] = self::UNDECLARED_TYPE;
			}
		}

		$this->definitions[ $postId ] = $definitions;

		return $definitions;
	}

	/**
	 * Reads the given post's meta rows that the definitions name, in one query.
	 *
	 * @since 1.3.1
	 *
	 * @param  int                  $postId      The post ID.
	 * @param  array                $definitions The post's resolved definitions.
	 * @return array<string, mixed>              The values, keyed by meta key.
	 */
	private function readMeta( $postId, $definitions ) {
		$db         = aioseoBrokenLinkChecker()->core->db;
		$conditions = [];

		if ( ! empty( $definitions['keys'] ) ) {
			$keys = array_keys( $definitions['keys'] );

			$conditions[] = $db->db->prepare(
				'pm.meta_key IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$keys
			);
		}

		foreach ( $definitions['prefixes'] as $prefix ) {
			$conditions[] = $db->db->prepare( 'pm.meta_key LIKE %s', $db->db->esc_like( $prefix . '_' ) . '%' );
		}

		if ( empty( $conditions ) ) {
			return [];
		}

		$rows = $db->start( 'postmeta as pm' )
			->select( 'pm.meta_key, pm.meta_value' )
			->where( 'pm.post_id', (int) $postId )
			->whereRaw( '( ' . implode( ' OR ', $conditions ) . ' )' )
			->run()
			->result();

		$values = [];
		foreach ( (array) $rows as $row ) {
			$values[ (string) $row->meta_key ] = $row->meta_value;
		}

		return $values;
	}

	/**
	 * The meta keys we declare ourselves, for plugins whose fields nothing else declares.
	 *
	 * NOTE: These are protected keys, so declaredKeys() would never reach them and register_meta() does
	 * not describe them. Named here rather than left to the filter because the plugins are ones we
	 * support: a link the editor fills in and the theme renders is a link, whatever it is stored under.
	 *
	 * @since 1.3.1
	 *
	 * @param  int      $postId The post ID.
	 * @return string[]         The meta keys.
	 */
	/**
	 * What to call one of the meta keys we declare ourselves, in the reader's terms.
	 *
	 * NOTE: Only the keys from {@see self::supportedPluginKeys()} are named here. Anything else is a key
	 * somebody chose - an ACF field name, or one added through the filter - and those read as themselves.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $metaKey The meta key.
	 * @return string          The name, or an empty string when we have none for it.
	 */
	/**
	 * Returns what the editor calls the field a meta key belongs to.
	 *
	 * NOTE: Needs the post, because which fields exist depends on it - the same key can belong to
	 * different field groups on different post types. Falls back to the names we know by hand, and then
	 * to nothing, which leaves the caller to decide whether the raw key is worth showing.
	 *
	 * @since 1.3.1
	 *
	 * @param  int    $postId  The post the field is on.
	 * @param  string $metaKey The meta key.
	 * @return string          The field's name, or an empty string.
	 */
	public function fieldLabel( $postId, $metaKey ) {
		$metaKey = (string) $metaKey;
		if ( '' === $metaKey || self::MAX_KEY_LENGTH < strlen( $metaKey ) ) {
			return '';
		}

		$definitions = $this->definitions( (int) $postId );

		// A plain field sits in 'keys' with nothing under it, so the tree walk never reaches it.
		if ( ! empty( $definitions['labels'][ $metaKey ] ) ) {
			return (string) $definitions['labels'][ $metaKey ];
		}

		$label = (string) $this->walkTree( $metaKey, $definitions['tree'], 0, 'label' );
		if ( '' !== $label ) {
			return $label;
		}

		// A key the groups we walked don't account for. Asking ACF directly costs a lookup, but only
		// where the alternative is showing the reader the meta key.
		if ( function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $metaKey );

			if ( ! empty( $field['label'] ) ) {
				return (string) $field['label'];
			}
		}

		return $this->keyLabel( $metaKey );
	}

	public function keyLabel( $metaKey ) {
		$labels = [
			// WooCommerce's own name for the field, so the report calls it what the product editor does.
			'_product_url' => __( 'Product URL', 'broken-link-checker-seo' )
		];

		return isset( $labels[ (string) $metaKey ] ) ? $labels[ (string) $metaKey ] : '';
	}

	private function supportedPluginKeys( $postId ) {
		$keys = [];

		// An External/Affiliate product's destination - the target of its "Buy product" button, and the
		// entire point of that product. Declared for every product rather than only the external ones, so
		// nothing has to resolve the product type: on any other type the field is empty and scans nothing.
		if (
			aioseoBrokenLinkChecker()->helpers->isWooCommerceActive() &&
			'product' === get_post_type( (int) $postId )
		) {
			$keys[] = '_product_url';
		}

		return $keys;
	}

	/**
	 * The meta keys something other than ACF declares as scannable strings.
	 *
	 * NOTE: Deliberately narrow — a `register_meta()` entry only qualifies when it is a single string
	 * exposed through the REST API, which is what a field an editor fills in looks like.
	 *
	 * @since 1.3.1
	 *
	 * @param  int      $postId The post ID.
	 * @return string[]         The meta keys.
	 */
	private function declaredKeys( $postId ) {
		$keys = [];
		foreach ( [ '', (string) get_post_type( (int) $postId ) ] as $objectSubtype ) {
			foreach ( (array) get_registered_meta_keys( 'post', $objectSubtype ) as $metaKey => $args ) {
				if ( empty( $args['show_in_rest'] ) || empty( $args['single'] ) ) {
					continue;
				}

				if ( ! isset( $args['type'] ) || 'string' !== $args['type'] ) {
					continue;
				}

				if ( is_protected_meta( $metaKey, 'post' ) ) {
					continue;
				}

				$keys[] = $metaKey;
			}
		}

		$keys = array_merge( $keys, $this->supportedPluginKeys( $postId ) );

		/**
		 * Filters the meta keys the custom-field source reads links out of.
		 *
		 * The way past every blind spot the definitions leave: a field a plugin writes without declaring
		 * it, one inside a serialised array, or a protected key that is edited all the same. A key whose
		 * type nothing declares has its shape read off its value — prose unless the whole of it is a URL.
		 *
		 * @since 1.3.1
		 *
		 * @param string[] $keys   The meta keys, from `register_meta()`.
		 * @param int      $postId The post the keys were resolved for.
		 */
		$filtered = apply_filters( 'aioseo_blc_scannable_meta_keys', $keys, (int) $postId );
		if ( ! is_array( $filtered ) ) {
			return $keys;
		}

		$sanitized = [];
		foreach ( $filtered as $metaKey ) {
			if ( ! is_string( $metaKey ) || '' === $metaKey || self::MAX_KEY_LENGTH < strlen( $metaKey ) ) {
				continue;
			}

			$sanitized[] = $metaKey;
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * The ACF field groups that apply to the given post.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $postId The post ID.
	 * @return array         The field groups.
	 */
	private function postFieldGroups( $postId ) {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return [];
		}

		$fieldGroups = acf_get_field_groups( [ 'post_id' => (int) $postId ] );

		return is_array( $fieldGroups ) ? $fieldGroups : [];
	}

	/**
	 * The fields the given ACF field group holds, as a tree.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $fieldGroup The field group.
	 * @return array             The fields, keyed by name.
	 */
	private function groupNodes( $fieldGroup ) {
		$groupId = isset( $fieldGroup['ID'] ) ? (int) $fieldGroup['ID'] : 0;
		if ( $groupId ) {
			$this->acfFields();

			if ( isset( $this->acfChildren[ $groupId ] ) ) {
				return $this->nodesFor( $groupId );
			}
		}

		// A group registered in PHP or held in an unsynced acf-json file has no definitions in the
		// database to read. ACF answers for one out of its own store without a query.
		if ( ! function_exists( 'acf_get_fields' ) ) {
			return [];
		}

		return $this->nodesFromFields( acf_get_fields( $fieldGroup ) );
	}

	/**
	 * Builds the tree of fields hanging off the given ACF definition.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $parentId The ID of the definition the fields hang off.
	 * @param  int   $depth    How far in the build already is.
	 * @return array           The fields, keyed by name.
	 */
	private function nodesFor( $parentId, $depth = 0 ) {
		if ( isset( $this->nodes[ $parentId ] ) ) {
			return $this->nodes[ $parentId ];
		}

		if ( self::MAX_DEPTH <= $depth || empty( $this->acfChildren[ $parentId ] ) ) {
			return [];
		}

		$nodes = [];
		foreach ( $this->acfChildren[ $parentId ] as $fieldId ) {
			$field = $this->acfFields[ $fieldId ];

			$nodes[ $field['name'] ] = [
				'type'     => $field['type'],
				// What the editor calls the field. Kept here as well as in nodesFromFields(): this is the
				// path a group created in the ACF admin takes, which is most of them.
				'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
				'indexed'  => ! empty( self::CONTAINER_TYPES[ $field['type'] ] ),
				'children' => isset( self::CONTAINER_TYPES[ $field['type'] ] ) ? $this->nodesFor( $fieldId, $depth + 1 ) : []
			];
		}

		$this->nodes[ $parentId ] = $this->sortByNameLength( $nodes );

		return $this->nodes[ $parentId ];
	}

	/**
	 * Builds the tree of fields out of what ACF's own reader returned.
	 *
	 * @since 1.3.1
	 *
	 * @param  mixed $fields The fields.
	 * @param  int   $depth  How far in the build already is.
	 * @return array         The fields, keyed by name.
	 */
	private function nodesFromFields( $fields, $depth = 0 ) {
		if ( ! is_array( $fields ) || self::MAX_DEPTH <= $depth ) {
			return [];
		}

		$nodes = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) || empty( $field['type'] ) ) {
				continue;
			}

			$children = [];
			if ( isset( self::CONTAINER_TYPES[ $field['type'] ] ) ) {
				$children = $this->nodesFromFields( isset( $field['sub_fields'] ) ? $field['sub_fields'] : [], $depth + 1 );

				// Flexible content hangs its fields off its layouts, and the layout name is not part of the
				// meta key, so they all sit at the same level as far as the key is concerned.
				foreach ( (array) ( isset( $field['layouts'] ) ? $field['layouts'] : [] ) as $layout ) {
					$children = array_merge(
						$children,
						$this->nodesFromFields( isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : [], $depth + 1 )
					);
				}
			}

			$nodes[ $field['name'] ] = [
				'type'     => (string) $field['type'],
				// What the editor calls the field. The report shows this rather than the meta key, which is
				// the field's plumbing and means nothing to whoever filled it in.
				'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
				'indexed'  => ! empty( self::CONTAINER_TYPES[ $field['type'] ] ),
				'children' => $children
			];
		}

		return $this->sortByNameLength( $nodes );
	}

	/**
	 * Reads every ACF field definition in the database, once per request.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	private function acfFields() {
		if ( null !== $this->acfFields ) {
			return;
		}

		$this->acfFields   = [];
		$this->acfChildren = [];

		$rows = aioseoBrokenLinkChecker()->core->db->start( 'posts as p' )
			// post_title is the field's label, post_excerpt its name. Both are needed: the report shows
			// the one and resolves the meta key against the other.
			->select( 'p.ID, p.post_parent, p.post_title, p.post_excerpt, p.post_content' )
			->where( 'p.post_type', 'acf-field' )
			->where( 'p.post_status', 'publish' )
			->orderBy( 'p.menu_order ASC, p.ID ASC' )
			->run()
			->result();

		foreach ( (array) $rows as $row ) {
			$name     = (string) $row->post_excerpt;
			$settings = aioseoBrokenLinkChecker()->helpers->maybeUnserialize( $row->post_content );
			$type     = is_array( $settings ) && ! empty( $settings['type'] ) ? (string) $settings['type'] : '';

			if ( '' === $name || '' === $type ) {
				continue;
			}

			$this->acfFields[ (int) $row->ID ] = [
				'name'  => $name,
				'type'  => $type,
				'label' => (string) $row->post_title
			];

			$this->acfChildren[ (int) $row->post_parent ][] = (int) $row->ID;
		}
	}

	/**
	 * Whether anything underneath the given field is one the scan can read.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $node  The field.
	 * @param  int   $depth How far in the walk already is.
	 * @return bool         Whether it holds one.
	 */
	private function holdsScannableField( $node, $depth = 0 ) {
		if ( self::MAX_DEPTH <= $depth ) {
			return false;
		}

		foreach ( $node['children'] as $child ) {
			if ( empty( $child['children'] ) ) {
				if ( $this->isScannableType( $child['type'] ) ) {
					return true;
				}

				continue;
			}

			if ( $this->holdsScannableField( $child, $depth + 1 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a field of the given type is one the scan can read links out of.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $fieldType The field type.
	 * @return bool              Whether it is scannable.
	 */
	private function isScannableType( $fieldType ) {
		return in_array( $fieldType, self::RICH_TEXT_TYPES, true ) || in_array( $fieldType, self::URL_TYPES, true );
	}

	/**
	 * Orders fields so that the longest name is matched against a meta key first.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $nodes The fields, keyed by name.
	 * @return array        The fields.
	 */
	private function sortByNameLength( $nodes ) {
		uksort( $nodes, function( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		return $nodes;
	}
}