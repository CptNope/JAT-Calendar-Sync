<?php
/**
 * Plugin bootstrap: hooks and activation.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together.
 */
final class JATCS_Plugin {

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Sites moving from Anderson Appointment Booking: on init, priority 0 (before the types below).
		JATCS_Legacy::init();
		// Runs on init (not now): it creates translated default names.
		add_action( 'init', array( 'JATCS_Types', 'maybe_migrate' ), 1 );
		JATCS_Bookings::init();
		JATCS_Rest::init();
		JATCS_Frontend::init();
		JATCS_Maintenance::init();
		if ( is_admin() ) {
			JATCS_Admin::init();
			JATCS_Admin_Types::init();
		}
	}

	/**
	 * Activation: seed defaults and register the post type so rewrite rules are correct.
	 */
	public static function activate() {
		// Take over from Anderson Appointment Booking first, so its settings aren't replaced by defaults.
		JATCS_Legacy::maybe_migrate();
		if ( false === get_option( JATCS_Settings::OPTION ) ) {
			add_option( JATCS_Settings::OPTION, JATCS_Settings::defaults(), '', false );
		}
		add_option( 'jatcs_installed', time(), '', false );
		JATCS_Types::maybe_migrate();
		JATCS_Bookings::register_post_type();
		JATCS_Maintenance::schedule();
	}
}
