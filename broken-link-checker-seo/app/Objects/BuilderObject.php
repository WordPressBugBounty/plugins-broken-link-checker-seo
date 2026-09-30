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
 * Links held in a page builder's own store rather than in post content.
 *
 * A builder of this kind keeps the layout as a structure in post meta and renders post content from
 * it, so nothing that reads post content sees the layout itself. The URLs sit in whatever a widget
 * calls them, nested as deep as the layout is.
 *
 * The walk keys off the shape rather than the widget's name: a URL is a string under a link-ish key,
 * or a `url` inside an object, and markup is anything holding an anchor. A third-party widget nobody
 * has heard of is read the same way as a first-party one.
 *
 * A subclass says where its layout lives and what to call it. {@see ElementorObject} for one whose
 * fields hang off a `settings` key, {@see SiteOriginObject} for one whose fields are the widget.
 *
 * @since 1.3.1
 */
abstract class BuilderObject extends ObjectType {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function primeCaches( $objectIds ) {
		$this->primePostCaches( $objectIds );
	}

	/**
	 * The keys whose string value is a URL rather than prose.
	 *
	 * Only a fast path for the bare-string case — a link written as an object is found by its shape.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const LINK_KEYS = [
		'url',
		'link',
		'href',
		'src',
		'video',
		'website_link',
		'button_link',
		'link_url',
		'linkUrl',
		'imageUrl',
		'videoUrl',
		'backgroundImage'
	];

	/**
	 * The keys whose value is the wording a reader would recognise the link by.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const TEXT_KEYS = [ 'text', 'title', 'title_text', 'heading', 'button_text', 'alt' ];

	/**
	 * The keys whose URL is the picture itself rather than somewhere to go.
	 *
	 * Rendered as an image so the report files it as one — the media filter and the row's icon read the
	 * flag the extractor sets from the tag, not from the field it came out of.
	 *
	 * @since 1.3.1
	 *
	 * @var string[]
	 */
	const IMAGE_KEYS = [ 'src', 'image', 'image_url', 'background_image', 'imageUrl', 'backgroundImage' ];




	/**
	 * Whether this post holds a layout of this builder's at all.
	 *
	 * @since 1.3.1
	 *
	 * @param  int  $objectId The post ID.
	 * @return bool           Whether it holds one.
	 */
	abstract public function hasLayout( $objectId );

	/**
	 * Reads the layout, collecting the links and the markup it holds.
	 *
	 * @since 1.3.1
	 *
	 * @param  int   $objectId  The post ID.
	 * @param  array $links     The links found, appended to.
	 * @param  array $fragments The markup found, appended to.
	 * @return void
	 */
	abstract protected function collect( $objectId, &$links, &$fragments );

	/**
	 * {@inheritdoc}
	 *
	 * The rows hang off a post, so the post rules — types, statuses, exclusions — apply to them.
	 *
	 * @since 1.3.1
	 */
	public function isPostBacked() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * A builder's pages are pages. Nobody would think to look for them under a list of extra places to
	 * scan, so there is nothing to switch off.
	 *
	 * @since 1.3.1
	 */
	public function settingKey() {
		return null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * The layout is turned into anchors before the extractor sees it, so it is read as rich text.
	 *
	 * @since 1.3.1
	 */
	public function isRichText( $objectId = 0, $subtype = '' ) {
		return true;
	}

	/**
	 * {@inheritdoc}
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
	 * Writing to the layout is what these cannot do; rechecking and dismissing read the report only.
	 *
	 * @since 1.3.1
	 */
	public function supportedActions( $objectId = 0, $subtype = '' ) {
		return [ self::RECHECK, self::DISMISS ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * Both writes are still shown, disabled, so the reader learns where to go instead of wondering why
	 * the report offers them nothing here.
	 *
	 * @since 1.3.1
	 */
	public function refusedActions( $objectId = 0, $subtype = '' ) {
		return [
			self::EDIT_URL => $this->urlRefusal( $objectId, $subtype )->get_error_message(),
			self::UNLINK   => $this->unlinkRefusalMessage( $objectId, $subtype )
		];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function urlRefusal( $objectId, $subtype = '' ) {
		return new \WP_Error(
			'blc_builder_url_not_editable',
			sprintf(
				// Translators: 1 - The name of a page builder, e.g. "Elementor".
				__( 'This link is part of a %1$s layout, which the report cannot rewrite. Open the page in %1$s to change it.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				$this->sourceLabel()
			),
			[ 'status' => 409 ]
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function unlinkRefusalMessage( $objectId = 0, $subtype = '' ) {
		return sprintf(
			// Translators: 1 - The name of a page builder, e.g. "Elementor".
			__( 'The report cannot take a link out of the %1$s layout. Open the page in %1$s to remove it.', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			$this->sourceLabel()
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function exists( $objectId, $subtype = '' ) {
		if ( ! is_a( get_post( (int) $objectId ), 'WP_Post' ) ) {
			return false;
		}

		// A builder copies its layout onto every revision it saves, and a revision is not a page anyone
		// reads, edits or would want the report to name.
		if ( wp_is_post_revision( (int) $objectId ) || wp_is_post_autosave( (int) $objectId ) ) {
			return false;
		}

		return $this->hasLayout( $objectId );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function locationLabel( $objectId, $subtype = '' ) {
		return $this->exists( $objectId ) ? aioseoBrokenLinkChecker()->helpers->getPostTitle( (int) $objectId ) : '';
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

		$editUrl = get_edit_post_link( (int) $objectId, '' );

		return $editUrl ? $editUrl : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function viewUrl( $objectId, $subtype = '' ) {
		$permalink = get_permalink( (int) $objectId );

		return $permalink ? $permalink : null;
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
	 * Returns the layout's links as anchors, so the one extractor the rest of the plugin uses reads this
	 * the way it reads post content. Each widget's own wording becomes the anchor text where it has one,
	 * which is what the report shows as the link's text.
	 *
	 * @since 1.3.1
	 */
	public function getContent( $objectId, $subtype = '' ) {
		if ( ! $this->hasLayout( $objectId ) ) {
			return '';
		}

		$links     = [];
		$fragments = [];
		$this->collect( $objectId, $links, $fragments );

		$html = '';
		foreach ( $links as $link ) {
			if ( ! empty( $link['isImage'] ) ) {
				$html .= sprintf( '<img src="%1$s" alt="%2$s" />', esc_url( $link['url'] ), esc_attr( $link['text'] ) );

				continue;
			}

			$html .= sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $link['url'] ),
				esc_html( '' === $link['text'] ? $link['url'] : $link['text'] )
			);
		}

		// A text widget's field is already markup, so it goes through as it stands and keeps the anchor
		// text and surrounding sentence the reader wrote. Deduplicated because a builder whose fields are
		// the widget itself has them read once as fields and again by the walk beneath.
		return $html . implode( '', array_unique( $fragments ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function saveContent( $objectId, $subtype, $content ) {
		return false;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Each post type has its own capability set, and authorship decides the rest.
	 *
	 * @since 1.3.1
	 */
	public function userScopeCondition() {
		$postTypes = get_post_types( [], 'objects' );
		$others    = [];
		$own       = [];

		foreach ( $postTypes as $postType ) {
			if ( current_user_can( $postType->cap->edit_others_posts ) ) {
				$others[] = $postType->name;

				continue;
			}

			if ( current_user_can( $postType->cap->edit_posts ) ) {
				$own[] = $postType->name;
			}
		}

		if ( count( $others ) === count( $postTypes ) ) {
			return '';
		}

		$conditions = [];
		if ( ! empty( $others ) ) {
			$conditions[] = 'p.post_type IN (' . $this->quoteList( $others ) . ')';
		}

		if ( ! empty( $own ) ) {
			$conditions[] = '( p.post_type IN (' . $this->quoteList( $own ) . ') AND p.post_author = ' . (int) get_current_user_id() . ' )';
		}

		return empty( $conditions ) ? '0' : implode( ' OR ', $conditions );
	}





	/**
	 * The fields belonging to one node, or null when the node holds none of its own.
	 *
	 * Null by default, for a family that has no tree to walk.
	 *
	 * @since 1.3.1
	 *
	 * @param  array      $node The node.
	 * @return array|null       The fields.
	 */
	protected function fieldsFor( $node ) {
		return null;
	}


	/**
	 * Walks the layout, collecting every link it holds.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $node      The node, or a list of them.
	 * @param  array $links     The links found so far, appended to.
	 * @param  array $fragments The markup fields found so far, appended to.
	 * @return void
	 */
	protected function collectLinks( $node, &$links, &$fragments ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		$fields = $this->fieldsFor( $node );
		if ( is_array( $fields ) ) {
			$this->collectFromFields( $fields, $links, $fragments );

			// The fields were read above in the widget's own context. A layout nested inside one is still
			// reached, because the recursion below walks whatever the fields themselves hold.
		}

		foreach ( $node as $key => $child ) {
			if ( is_array( $fields ) && $this->isFieldsKey( $key ) ) {
				continue;
			}

			if ( is_array( $child ) ) {
				$this->collectLinks( $child, $links, $fragments );
			}
		}
	}

	/**
	 * Whether walking this key again would read the same fields twice.
	 *
	 * @since 1.3.1
	 *
	 * @param  string|int $key The key.
	 * @return bool            Whether to skip it.
	 */
	protected function isFieldsKey( $key ) {
		return false;
	}

	/**
	 * Collects the links one widget's fields hold, with that widget's own wording as the anchor.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $fields    The widget's fields.
	 * @param  array $links     The links found so far, appended to.
	 * @param  array $fragments The markup fields found so far, appended to.
	 * @return void
	 */
	protected function collectFromFields( $fields, &$links, &$fragments ) {
		$text = '';
		foreach ( static::TEXT_KEYS as $key ) {
			if ( ! empty( $fields[ $key ] ) && is_string( $fields[ $key ] ) ) {
				$text = wp_strip_all_tags( $fields[ $key ] );

				break;
			}
		}

		foreach ( $fields as $key => $value ) {
			// A field that is markup — a text editor, an HTML block — holds ordinary anchors.
			if ( is_string( $value ) && false !== stripos( $value, '<a ' ) ) {
				$fragments[] = $value;

				continue;
			}

			// A link written as an object, which is what most builders do.
			if ( is_array( $value ) && isset( $value['url'] ) && is_string( $value['url'] ) ) {
				$this->addLink( $value['url'], $text, $links, $key );

				continue;
			}

			// Some widgets hold the URL as a bare string under a link-ish key.
			if ( is_string( $value ) && in_array( $key, static::LINK_KEYS, true ) ) {
				$this->addLink( $value, $text, $links, $key );

				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			// A group of fields rather than a list of field sets — which is how a block keeps one value per
			// breakpoint. Read as fields, or its own leaves are never a key and value to anything: a
			// string of markup sitting one level down was reached only as a list item and dropped, so the
			// anchors inside a text module went unseen.
			if ( ! $this->isList( $value ) ) {
				$this->collectFromFields( $value, $links, $fragments );

				continue;
			}

			// A repeater holds a list of its own field sets, each with its own wording.
			foreach ( $value as $item ) {
				if ( is_array( $item ) ) {
					$this->collectFromFields( $item, $links, $fragments );
				}
			}
		}
	}
	/**
	 * Whether an array is a list rather than a group of named fields.
	 *
	 * NOTE: Not array_is_list(), which needs PHP 8.1.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $value The array.
	 * @return bool         Whether its keys are 0..n.
	 */
	protected function isList( $value ) {
		if ( empty( $value ) ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Whether the same field can be read more than once, making a repeat a duplicate rather than a
	 * second occurrence.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether reading repeats.
	 */
	protected function readingRepeats() {
		return false;
	}

	/**
	 * Records one link, ignoring what is not a link to anywhere.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $url   The URL.
	 * @param  string $text  The anchor text.
	 * @param  array  $links The links found so far, appended to.
	 * @param  string $key   The field it came out of, which decides how it is rendered.
	 * @return void
	 */
	protected function addLink( $url, $text, &$links, $key = '' ) {
		$url = trim( (string) $url );
		if ( '' === $url || '#' === $url[0] ) {
			return;
		}

		// A builder writes an unset link as an empty object and a dynamic one as a shortcode-ish tag;
		// neither is a URL the checker could resolve.
		if ( 0 === strpos( $url, '[' ) ) {
			return;
		}

		// Only where the reading itself can repeat. Two tags carrying the same link are two occurrences
		// of it, exactly as two anchors in post content are, and collapsing them would undercount.
		if ( $this->readingRepeats() ) {
			foreach ( $links as $existing ) {
				if ( $existing['url'] === $url && $existing['text'] === (string) $text ) {
					return;
				}
			}
		}

		$links[] = [
			'url'     => $url,
			'text'    => (string) $text,
			'isImage' => in_array( (string) $key, static::IMAGE_KEYS, true )
		];
	}
}