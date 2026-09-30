<?php
namespace AIOSEO\BrokenLinkChecker\Standalone;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Handles highlighting broken links.
 *
 * @since 1.2.0
 */
class Highlighter {
	/**
	 * The admin-post action the toolbar's switch posts to.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const TOGGLE_ACTION = 'aioseo_blc_toggle_highlighter';

	/**
	 * The user meta recording that this reader has turned highlighting off for themselves.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const USER_META_KEY = 'aioseo_blc_highlighter_off';

	/**
	 * Class constructor.
	 *
	 * @since   1.2.0
	 * @version 1.3.1 Registers the toolbar switch's handler.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'init' ] );

		// Outside init(), which returns early in the admin — and admin-post.php is the admin.
		add_action( 'admin_post_' . self::TOGGLE_ACTION, [ $this, 'handleToggle' ] );
	}

	/**
	 * Initializes the class.
	 *
	 * @since   1.2.0
	 * @version 1.3.1 Adds the toolbar switch, and respects a reader who has turned highlighting off.
	 *
	 * @return void
	 */
	public function init() {
		// Our capability, not edit_posts. A contributor holds edit_posts, cannot open the report, and
		// holds nothing of ours - and was being handed the broken URLs on other people's posts.
		if (
			is_admin() ||
			! is_user_logged_in() ||
			! current_user_can( 'aioseo_blc_broken_links_page' )
		) {
			return;
		}

		if ( ! aioseoBrokenLinkChecker()->options->general->highlightBrokenLinks ) {
			return;
		}

		// Added whether or not this reader has it on, or there would be nothing to turn it back on with.
		add_action( 'admin_bar_menu', [ $this, 'addAdminBarToggle' ], 100 );
		add_action( 'wp_head', [ $this, 'printAdminBarStyles' ] );

		if ( $this->isOffForUser() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueScript' ] );
	}

	/**
	 * Whether the reader may be shown the broken links on the thing they are reading.
	 *
	 * The capability alone is not enough: it says the reader may open the report, not that they may see
	 * this post. An author holds it and can edit only their own content, so without the per-post test
	 * they were shown which links are broken on everybody's.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether highlighting applies to this request.
	 */
	public static function canHighlightCurrentRequest() {
		if ( is_admin() || ! is_user_logged_in() || ! current_user_can( 'aioseo_blc_broken_links_page' ) ) {
			return false;
		}

		if ( ! is_singular() ) {
			return false;
		}

		$postId = get_queried_object_id();

		// The same rule the report applies to a location: the reader must be able to edit the thing it
		// sits in. An editor and an administrator therefore keep everything they had.
		return $postId && current_user_can( 'edit_post', $postId );
	}

	/**
	 * Enqueues the script.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function enqueueScript() {
		if ( ! self::canHighlightCurrentRequest() ) {
			return;
		}

		$scriptHandle = 'src/vue/standalone/highlighter/main.js';
		aioseoBrokenLinkChecker()->core->assets->load( $scriptHandle, [], aioseoBrokenLinkChecker()->helpers->getVueData( 'highlighter' ) );
	}

	/**
	 * Adds the toolbar switch for the page being viewed.
	 *
	 * @since 1.3.1
	 *
	 * @param  \WP_Admin_Bar $wpAdminBar The admin bar.
	 * @return void
	 */
	public function addAdminBarToggle( $wpAdminBar ) {
		$count = $this->getBrokenCount();

		// A switch for marking nothing is a control with nothing to do, and the toolbar is not where a
		// site says all is well.
		if ( ! $count ) {
			return;
		}

		$off = $this->isOffForUser();

		$wpAdminBar->add_node(
			[
				'id'    => 'aioseo-blc-highlighter-toggle',
				'href'  => $this->toggleUrl( $off ),
				'meta'  => [
					'title' => $off
						? __( 'Mark these links on the page again', 'broken-link-checker-seo' )
						: __( 'Stop marking these links on the page', 'broken-link-checker-seo' )
				],
				'title' => sprintf(
					'<span class="aioseo-blc-bar%1$s">%2$s<span class="aioseo-blc-bar__label">%3$s</span><span class="aioseo-blc-bar__switch"></span></span>',
					$off ? ' aioseo-blc-bar--off' : '',
					$this->warningIcon(),
					esc_html(
						sprintf(
							// Translators: 1 - A number of broken links.
							_n( 'Highlight %1$s broken link', 'Highlight %1$s broken links', $count, 'broken-link-checker-seo' ),
							number_format_i18n( $count )
						)
					)
				)
			]
		);
	}

	/**
	 * Prints the toolbar switch's styles.
	 *
	 * NOTE: Printed rather than enqueued. The switch is a handful of rules and has to be there for a
	 * reader who has highlighting off, which is exactly when the highlighter's own assets are not loaded.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function printAdminBarStyles() {
		if ( ! is_admin_bar_showing() || ! $this->getBrokenCount() ) {
			return;
		}
		?>
		<style>
			/*
			 * Every rule is scoped to the node's id. This renders inside whatever theme the site runs, and
			 * a theme's own `svg { width: 100% }` reset beat a selector of ours — which stretched the mark
			 * to 159px and the pill with it.
			 */
			/*
			 * The bar's own item is a block, which leaves the pill sitting on the text baseline — two pixels
			 * from the top and seven from the bottom. Centred by the flex parent, the way the plugin's
			 * existing admin-bar warning does it, rather than by a margin the bar resets anyway.
			 */
			#wp-admin-bar-aioseo-blc-highlighter-toggle .ab-item {
				display: flex !important;
				align-items: center !important;
				padding: 0 6px !important;
			}
			#wp-admin-bar-aioseo-blc-highlighter-toggle .aioseo-blc-bar {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				width: auto;
				height: 24px;
				padding: 0 8px;
				border-radius: 4px;
				background-color: #FEF3C7;
				color: #92400E;
				font-size: 13px;
				font-weight: 600;
				letter-spacing: -.4px;
				line-height: 24px;
			}
			#wp-admin-bar-aioseo-blc-highlighter-toggle:hover .aioseo-blc-bar { background-color: #FDE68A; }
			#wp-admin-bar-aioseo-blc-highlighter-toggle .aioseo-blc-bar svg {
				display: block;
				flex: 0 0 auto;
				width: 15px;
				min-width: 15px;
				max-width: 15px;
				height: 15px;
				min-height: 15px;
				max-height: 15px;
				margin: 0;
				color: #B45309;
				vertical-align: middle;
			}
			#wp-admin-bar-aioseo-blc-highlighter-toggle .aioseo-blc-bar__label {
				color: #92400E;
				line-height: 24px;
			}
			#wp-admin-bar-aioseo-blc-highlighter-toggle .aioseo-blc-bar__switch {
				position: relative;
				display: inline-block;
				flex: 0 0 auto;
				width: 26px;
				min-width: 26px;
				height: 14px;
				min-height: 14px;
				margin: 0;
				padding: 0;
				border-radius: 999px;
				background-color: #B45309;
			}
			#wp-admin-bar-aioseo-blc-highlighter-toggle .aioseo-blc-bar__switch::after {
				content: "";
				position: absolute;
				top: 2px;
				left: 2px;
				width: 10px;
				height: 10px;
				border-radius: 50%;
				background-color: #FFFDF5;
				transform: translateX(12px);
			}
			#wp-admin-bar-aioseo-blc-highlighter-toggle .aioseo-blc-bar--off .aioseo-blc-bar__switch { background-color: #C9A227; }
			#wp-admin-bar-aioseo-blc-highlighter-toggle .aioseo-blc-bar--off .aioseo-blc-bar__switch::after { transform: none; }
		</style>
		<?php
	}

	/**
	 * Flips this reader's preference and returns them to the page they were on.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function handleToggle() {
		check_admin_referer( self::TOGGLE_ACTION );

		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'broken-link-checker-seo' ), '', [ 'response' => 403 ] );
		}

		$userId = get_current_user_id();
		if ( empty( $_GET['blc_off'] ) ) {
			delete_user_meta( $userId, self::USER_META_KEY );
		} else {
			update_user_meta( $userId, self::USER_META_KEY, '1' );
		}

		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : home_url() );
		exit;
	}

	/**
	 * Whether this reader has turned highlighting off for themselves.
	 *
	 * NOTE: Their own choice only. Whether the site highlights at all is the setting's to decide, and a
	 * reader who cannot change settings can still stop the marking on their own screen.
	 *
	 * @since 1.3.1
	 *
	 * @return bool Whether it is off for them.
	 */
	private function isOffForUser() {
		return (bool) get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
	}

	/**
	 * Returns the nonced URL that flips the preference.
	 *
	 * @since 1.3.1
	 *
	 * @param  bool   $isOff Whether it is currently off for this reader.
	 * @return string        The URL.
	 */
	private function toggleUrl( $isOff ) {
		$url = add_query_arg(
			[
				'action'  => self::TOGGLE_ACTION,
				'blc_off' => $isOff ? '' : '1'
			],
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::TOGGLE_ACTION );
	}

	/**
	 * Returns how many broken links the page being viewed holds.
	 *
	 * @since 1.3.1
	 *
	 * @return int The count.
	 */
	private function getBrokenCount() {
		// Also the toolbar switch's gate: it hides itself when this is zero, so one test covers both.
		if ( ! self::canHighlightCurrentRequest() ) {
			return 0;
		}

		$postId = get_queried_object_id();

		return $postId ? (int) Models\LinkStatus::getBrokenCountByPostId( $postId ) : 0;
	}

	/**
	 * Returns the warning mark the toolbar item carries.
	 *
	 * @since 1.3.1
	 *
	 * @return string The SVG.
	 */
	private function warningIcon() {
		return '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M10 2.5 18.5 17H1.5L10 2.5Zm0 5v5h1.5v-5H10Zm0 6.5v1.6h1.5V14H10Z"/></svg>';
	}
}