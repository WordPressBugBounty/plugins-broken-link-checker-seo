<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations\Definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Main\Migrations\Migration;

/**
 * Switches off an uninstall setting the user was never shown as active.
 *
 * The Advanced card used to gate its own body on advanced.enable, the uninstall checkbox included. A
 * user who enabled Advanced, ticked uninstall, then switched Advanced back off left uninstall stored
 * as true and deliberately inert. Nothing gates it now, so that stored true would go live and take
 * every table and option with it on delete.
 *
 * @since 1.3.1
 */
class ReconcileUninstallWithAdvancedToggle implements Migration {
	/**
	 * The name this migration is logged under.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	const NAME = 'reconcile_uninstall_with_advanced_toggle';

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function name() {
		return self::NAME;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function version() {
		return '1.3.1';
	}

	/**
	 * {@inheritdoc}
	 *
	 * NOTE: Registered before anything else that saves this row. advanced.enable is no longer declared,
	 * so the first save drops it and there is nothing left to reconcile against.
	 *
	 * @since 1.3.1
	 */
	public function up() {
		$advanced = $this->storedAdvanced();

		// A row that never carried the toggle has nothing to reconcile: what was stored is what the user
		// was shown.
		if ( ! array_key_exists( 'enable', $advanced ) || $this->valueOf( $advanced['enable'] ) ) {
			return;
		}

		aioseoBrokenLinkChecker()->options->advanced->uninstall = false;
		aioseoBrokenLinkChecker()->options->save( true );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 */
	public function verify() {
		$advanced = $this->storedAdvanced();

		if ( ! array_key_exists( 'enable', $advanced ) || $this->valueOf( $advanced['enable'] ) ) {
			return true;
		}

		return ! $this->valueOf( isset( $advanced['uninstall'] ) ? $advanced['uninstall'] : false );
	}

	/**
	 * Returns the advanced group as the row holds it.
	 *
	 * @since 1.3.1
	 *
	 * @return array The stored group.
	 */
	private function storedAdvanced() {
		$stored  = get_option( 'aioseo_blc_options' );
		$decoded = is_string( $stored ) ? json_decode( $stored, true ) : null;

		return isset( $decoded['advanced'] ) && is_array( $decoded['advanced'] ) ? $decoded['advanced'] : [];
	}

	/**
	 * Returns a stored setting's value, whichever of the two shapes the row holds it in.
	 *
	 * @since 1.3.1
	 *
	 * @param  mixed $node The stored node.
	 * @return bool        Its value.
	 */
	private function valueOf( $node ) {
		return (bool) ( is_array( $node ) && array_key_exists( 'value', $node ) ? $node['value'] : $node );
	}
}