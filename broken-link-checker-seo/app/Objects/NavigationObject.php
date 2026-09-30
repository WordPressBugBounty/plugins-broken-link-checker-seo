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
 * Links in a block theme's navigation.
 *
 * A block theme keeps its menus as `wp_navigation` posts, not as the `nav_menu_item` posts the menu
 * editor writes — so the navigation source has never seen them. On such a site every menu link is
 * missing from the report, and a menu link is on every page of the site.
 *
 * The URLs sit in the block's own attributes rather than in anchors, so they are turned into anchors
 * before the extractor reads them, with the block's label as the wording.
 *
 * @since 1.3.1
 */
class NavigationObject extends BlockContentObject {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function type() {
		return 'navigation';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function postType() {
		return 'wp_navigation';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function label() {
		return __( 'Navigation', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function sourceLabel() {
		return __( 'Block Navigation', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Shares the navigation menu switch. A block theme's navigation is the same thing to a reader as a
	 * classic menu, and which of the two a site uses is not a choice they made about link checking.
	 *
	 * @since 1.3.1
	 */
	public function settingKey() {
		return 'navMenus';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function tagLabel( $objectId = 0, $subtype = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return __( 'Menu', 'broken-link-checker-seo' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Menus are a theme concern, as they are for the classic menu editor.
	 *
	 * @since 1.3.1
	 */
	public function capability() {
		return 'edit_theme_options';
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

		return admin_url( 'site-editor.php?postType=wp_navigation&postId=' . (int) $objectId . '&canvas=edit' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * A navigation link holds its address in the block's attributes, where nothing that reads markup can
	 * see it, so those become anchors. Any real markup in the content is passed through untouched.
	 *
	 * @since 1.3.1
	 */
	public function getContent( $objectId, $subtype = '' ) {
		$content = parent::getContent( $objectId, $subtype );
		if ( '' === $content || false === strpos( $content, '<!-- wp:' ) ) {
			return $content;
		}

		$html = '';
		foreach ( $this->collectFromBlocks( parse_blocks( $content ) ) as $link ) {
			$html .= sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $link['url'] ),
				esc_html( '' === $link['label'] ? $link['url'] : $link['label'] )
			);
		}

		return $html . $content;
	}

	/**
	 * {@inheritdoc}
	 *
	 * The address is an attribute rather than an anchor, so the report cannot rewrite it in place.
	 *
	 * @since 1.3.1
	 */
	public function supportedActions( $objectId = 0, $subtype = '' ) {
		return [ self::RECHECK, self::DISMISS ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function refusedActions( $objectId = 0, $subtype = '' ) {
		$reason = __( 'This link is set on a navigation block, which the report cannot rewrite. Open the navigation in the editor to change it.', 'broken-link-checker-seo' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded

		return [
			self::EDIT_URL => $reason,
			self::UNLINK   => __( 'A navigation link has no link text to leave behind. Change its URL, or remove the item in the editor.', 'broken-link-checker-seo' ) // phpcs:ignore Generic.Files.LineLength.MaxExceeded
		];
	}

	/**
	 * Walks the block tree, collecting every address a navigation block holds.
	 *
	 * @since 1.3.1
	 *
	 * @param  array $blocks The parsed blocks.
	 * @return array         The links, each with a `url` and a `label`.
	 */
	private function collectFromBlocks( $blocks ) {
		$links = [];

		foreach ( (array) $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
			if ( ! empty( $attrs['url'] ) && is_string( $attrs['url'] ) ) {
				$links[] = [
					'url'   => trim( $attrs['url'] ),
					'label' => isset( $attrs['label'] ) && is_string( $attrs['label'] ) ? wp_strip_all_tags( $attrs['label'] ) : ''
				];
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$links = array_merge( $links, $this->collectFromBlocks( $block['innerBlocks'] ) );
			}
		}

		return $links;
	}
}