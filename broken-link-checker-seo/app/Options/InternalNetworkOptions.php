<?php
namespace AIOSEO\BrokenLinkChecker\Options;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Traits;

/**
 * Holds the network's own copy of the internal options.
 *
 * Only the licence group is meaningful here. Everything else an install records about itself - what it
 * has scanned, which emails have gone out - stays per-site, because it describes that site.
 *
 * @since 1.3.1
 */
class InternalNetworkOptions extends InternalOptions {
	use Traits\NetworkOptions;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		parent::__construct( 'aioseo_blc_options_internal_network' );
	}
}