<?php
namespace AIOSEO\BrokenLinkChecker\Objects;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links in a block theme's template parts — a header, a footer, a sidebar.
 *
 * {@see TemplateObject}, which this differs from only in which post type it reads.
 *
 * @since 1.3.1
 */
class TemplatePartObject extends TemplateObject {
	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.1
	 *
	 * @var bool
	 */
	protected $isPart = true;
}