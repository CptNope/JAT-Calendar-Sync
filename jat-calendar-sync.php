<?php
/**
 * Plugin Name:       JAT Calendar Sync
 * Plugin URI:        https://github.com/CptNope/JAT-Calendar-Sync
 * Description:       Free appointment booking that syncs with Google Calendar, Outlook / Microsoft 365, iCloud and any CalDAV or iCal calendar, matches your theme and never double-books.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Jeremy Anderson
 * Author URI:        https://jeremyanderson.tech
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jat-calendar-sync
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

// Another copy is already loaded (e.g. a GitHub download in a differently named folder):
// stay out of the way instead of crashing on duplicate classes, and say so.
if ( defined( 'JATCS_VERSION' ) ) {
	$jatcs_duplicate = __FILE__;
	add_action(
		'admin_notices',
		function () use ( $jatcs_duplicate ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: 1: running version, 2: plugin folder of the extra copy */
					__( 'Two copies of JAT Calendar Sync are active. Version %1$s is running; the copy in “%2$s” is doing nothing. Deactivate the one you don’t want. To remove an old copy, delete its folder rather than using Delete here, which would also disconnect Google Calendar in older versions.', 'jat-calendar-sync' ),
					JATCS_VERSION,
					basename( dirname( $jatcs_duplicate ) )
				)
			) . '</p></div>';
		}
	);
	return;
}

define( 'JATCS_VERSION', '2.0.0' );
define( 'JATCS_FILE', __FILE__ );
define( 'JATCS_DIR', plugin_dir_path( __FILE__ ) );
define( 'JATCS_URL', plugin_dir_url( __FILE__ ) );

// wp-config.php settings written for the plugin's earlier name (AAB_*) keep working.
foreach ( array( 'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'MS_CLIENT_ID', 'MS_CLIENT_SECRET', 'STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET' ) as $jatcs_constant ) {
	if ( defined( 'AAB_' . $jatcs_constant ) && ! defined( 'JATCS_' . $jatcs_constant ) ) {
		define( 'JATCS_' . $jatcs_constant, constant( 'AAB_' . $jatcs_constant ) );
	}
}
unset( $jatcs_constant );

require_once JATCS_DIR . 'includes/class-jatcs-crypto.php';
require_once JATCS_DIR . 'includes/class-jatcs-settings.php';
require_once JATCS_DIR . 'includes/class-jatcs-types.php';
require_once JATCS_DIR . 'includes/class-jatcs-style.php';
require_once JATCS_DIR . 'includes/class-jatcs-google.php';
require_once JATCS_DIR . 'includes/class-jatcs-microsoft.php';
require_once JATCS_DIR . 'includes/class-jatcs-ical.php';
require_once JATCS_DIR . 'includes/class-jatcs-caldav.php';
require_once JATCS_DIR . 'includes/class-jatcs-caldav-store.php';
require_once JATCS_DIR . 'includes/class-jatcs-feeds.php';
require_once JATCS_DIR . 'includes/class-jatcs-calendars.php';
require_once JATCS_DIR . 'includes/class-jatcs-stripe.php';
require_once JATCS_DIR . 'includes/class-jatcs-payments.php';
require_once JATCS_DIR . 'includes/class-jatcs-availability.php';
require_once JATCS_DIR . 'includes/class-jatcs-bookings.php';
require_once JATCS_DIR . 'includes/class-jatcs-rest.php';
require_once JATCS_DIR . 'includes/class-jatcs-frontend.php';
require_once JATCS_DIR . 'includes/class-jatcs-maintenance.php';
require_once JATCS_DIR . 'includes/class-jatcs-admin.php';
require_once JATCS_DIR . 'includes/class-jatcs-admin-types.php';
require_once JATCS_DIR . 'includes/class-jatcs-legacy.php';
require_once JATCS_DIR . 'includes/class-jatcs-plugin.php';

add_action( 'plugins_loaded', array( 'JATCS_Plugin', 'init' ) );
register_activation_hook( __FILE__, array( 'JATCS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JATCS_Maintenance', 'unschedule' ) );
