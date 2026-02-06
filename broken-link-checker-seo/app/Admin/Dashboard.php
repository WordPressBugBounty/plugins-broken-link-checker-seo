<?php
namespace AIOSEO\BrokenLinkChecker\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class that holds our dashboard widget.
 *
 * @since 1.2.6
 */
class Dashboard {
	/**
	 * Class Constructor.
	 *
	 * @since 1.2.6
	 */
	public function __construct() {
		add_action( 'wp_dashboard_setup', [ $this, 'addDashboardWidgets' ] );
	}

	/**
	 * Registers our dashboard widgets.
	 *
	 * @since 1.2.6
	 *
	 * @return void
	 */
	public function addDashboardWidgets() {
		// Add the BLC Overview widget.
		if (
			$this->canShowWidget( 'blcOverview' ) &&
			apply_filters( 'aioseo_blc_show_overview', true ) &&
			( aioseoBrokenLinkChecker()->access->isAdmin() || aioseoBrokenLinkChecker()->access->hasCapability( 'aioseo_blc_broken_links_page' ) )
		) {
			wp_add_dashboard_widget(
				'aioseo-blc-overview',
				__( 'Broken Link Checker Overview', 'broken-link-checker-seo' ),
				[
					$this,
					'outputBlcOverview',
				]
			);
		}
	}

	/**
	 * Whether or not to show the widget.
	 *
	 * @since 1.2.6
	 *
	 * @param  string  $widget The widget to check if can show.
	 * @return boolean True if yes, false otherwise.
	 */
	protected function canShowWidget( $widget ) { // phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return true;
	}

	/**
	 * Output the BLC Overview widget.
	 *
	 * @since 1.2.6
	 *
	 * @return void
	 */
	public function outputBlcOverview() {
		$this->output( 'aioseo-blc-overview-app' );
	}

	/**
	 * Output the widget wrapper for the Vue App.
	 *
	 * @since 1.2.6
	 *
	 * @param  string $appId The App ID to print out.
	 * @return void
	 */
	private function output( $appId ) {
		// Enqueue the scripts for the widget.
		$this->enqueue();

		// Opening tag.
		echo '<div id="' . esc_attr( $appId ) . '">';

		// Loader element.
		require AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Views/parts/loader.php';

		// Closing tag.
		echo '</div>';
	}

	/**
	 * Enqueue the scripts and styles.
	 *
	 * @since 1.2.6
	 *
	 * @return void
	 */
	private function enqueue() {
		aioseoBrokenLinkChecker()->core->assets->load( 'src/vue/standalone/dashboard-widgets/main.js', [], aioseoBrokenLinkChecker()->helpers->getVueData( 'dashboard' ) );
	}
}