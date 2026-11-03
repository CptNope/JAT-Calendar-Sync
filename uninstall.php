<?php
/**
 * Uninstall: always revoke Google access and forget calendar sign-ins; delete settings and
 * booking records only if the owner opted in.
 *
 * @package JATCalendarSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Another copy of this plugin (e.g. a GitHub download in a differently named folder)
// is still active and uses the same settings and Google grant: leave everything alone.
foreach ( (array) get_option( 'active_plugins', array() ) as $jatcs_active ) {
	if ( 'jat-calendar-sync.php' === basename( (string) $jatcs_active ) && WP_UNINSTALL_PLUGIN !== $jatcs_active ) {
		return;
	}
}

$jatcs_settings = get_option( 'jatcs_settings', array() );

// Revoke the Google grant so no token outlives the plugin.
require_once __DIR__ . '/includes/class-jatcs-crypto.php';
$jatcs_google = get_option( 'jatcs_google', array() );
if ( ! empty( $jatcs_google['refresh_token'] ) ) {
	$jatcs_token = JATCS_Crypto::decrypt( $jatcs_google['refresh_token'] );
	if ( '' !== $jatcs_token ) {
		wp_remote_post(
			'https://oauth2.googleapis.com/revoke',
			array(
				'timeout' => 10,
				'body'    => array( 'token' => $jatcs_token ),
			)
		);
	}
}
delete_option( 'jatcs_google' );
delete_option( 'jatcs_google_error' );
delete_option( 'jatcs_busy_generation' );
delete_option( 'jatcs_log' );
delete_option( 'jatcs_ms' );
delete_option( 'jatcs_ms_error' );
delete_transient( 'jatcs_ms_access' );
delete_transient( 'jatcs_ms_calendars' );
delete_option( 'jatcs_last_run' );
delete_option( 'jatcs_installed' );
wp_clear_scheduled_hook( 'jatcs_hourly' );
delete_transient( 'jatcs_access_token' );
delete_transient( 'jatcs_calendars' );
// CalDAV sign-in and iCal feed links are credentials too: always removed.
delete_option( 'jatcs_caldav' );
delete_transient( 'jatcs_caldav_calendars' );
foreach ( array_keys( (array) get_option( 'jatcs_feeds', array() ) ) as $jatcs_feed ) {
	delete_option( 'jatcs_feed_cache_' . $jatcs_feed );
}
delete_option( 'jatcs_feeds' );
delete_option( 'jatcs_feeds_version' );
delete_option( 'jatcs_legacy_google_redirect' );
delete_option( 'jatcs_legacy_ms_redirect' );
delete_option( 'jatcs_migrated_notice' );
// Slot locks and per-user sign-in state.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup of the plugin's own rows.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_jatcs_lock_' ) . '%', $wpdb->esc_like( '_transient_jatcs_' ) . '%', $wpdb->esc_like( '_transient_timeout_jatcs_' ) . '%', $wpdb->esc_like( 'jatcs_feed_cache_' ) . '%', $wpdb->esc_like( '_jatcs_' ) . '%' ) );

if ( empty( $jatcs_settings['delete_data'] ) ) {
	return;
}

delete_option( 'jatcs_settings' );
delete_option( 'jatcs_types' );
delete_option( 'jatcs_migrated' );
delete_option( 'jatcs_legacy_backup' );

$jatcs_ids = get_posts(
	array(
		'post_type'      => 'jatcs_booking',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $jatcs_ids as $jatcs_id ) {
	wp_delete_post( $jatcs_id, true );
}
