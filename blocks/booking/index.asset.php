<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- name expected by register_block_type().
/**
 * Script dependencies for the block editor script (hand-written; there is no build step).
 *
 * @package JATCalendarSync
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'jatcs-booking' ),
	'version'      => '1.3.0',
);
