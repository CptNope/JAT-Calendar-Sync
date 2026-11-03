<?php
/**
 * Moves a site over from the plugin's earlier name, Anderson Appointment Booking (prefix "aab").
 *
 * Runs once. Settings, appointment types, bookings, the Google and Outlook connections and
 * booking blocks/shortcodes carry over; the old plugin is deactivated and its stored data is
 * removed, so deleting the old plugin afterwards can't disconnect anything.
 * Links that were already sent (emails, Stripe webhooks, Google's redirect address) keep working.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migration from Anderson Appointment Booking and compatibility with its links.
 */
final class JATCS_Legacy {

	const DONE_OPTION   = 'jatcs_migrated';
	const NOTICE_OPTION = 'jatcs_migrated_notice';

	/**
	 * Options renamed one to one (old => new).
	 *
	 * @var string[]
	 */
	private static $options = array(
		'aab_settings'        => 'jatcs_settings',
		'aab_types'           => 'jatcs_types',
		'aab_google'          => 'jatcs_google',
		'aab_google_error'    => 'jatcs_google_error',
		'aab_log'             => 'jatcs_log',
		'aab_ms'              => 'jatcs_ms',
		'aab_ms_error'        => 'jatcs_ms_error',
		'aab_busy_generation' => 'jatcs_busy_generation',
		'aab_last_run'        => 'jatcs_last_run',
		'aab_installed'       => 'jatcs_installed',
	);

	/**
	 * Hooks for links and addresses created under the old name.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 0 );
		// Google and Microsoft send people back to the redirect address registered in their consoles.
		add_action( 'admin_post_aab_oauth', array( 'JATCS_Admin', 'oauth_callback' ) );
		add_action( 'admin_post_aab_ms_oauth', array( 'JATCS_Admin', 'ms_oauth' ) );
		add_shortcode( 'anderson_booking', array( 'JATCS_Frontend', 'shortcode' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Has an older install's data been found and not yet moved?
	 *
	 * @return bool
	 */
	public static function pending() {
		return ! get_option( self::DONE_OPTION ) && false !== get_option( 'aab_settings', false );
	}

	/**
	 * Move everything over, once.
	 *
	 * @return bool Whether a migration ran.
	 */
	public static function maybe_migrate() {
		if ( ! self::pending() ) {
			return false;
		}
		// One request at a time (atomic INSERT IGNORE lock), and check again once we hold it.
		if ( ! JATCS_Bookings::acquire_named( 'migrate', 10 * MINUTE_IN_SECONDS ) ) {
			return false;
		}
		try {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( self::DONE_OPTION, 'options' );
			wp_cache_delete( 'aab_settings', 'options' );
			if ( ! self::pending() ) {
				return false;
			}
			self::migrate();
		} finally {
			JATCS_Bookings::release_named( 'migrate' );
		}
		return true;
	}

	/**
	 * The move itself.
	 */
	private static function migrate() {
		global $wpdb;
		$report = array(
			'at'       => time(),
			'bookings' => 0,
			'google'   => false,
			'outlook'  => false,
			'pages'    => 0,
			'plugins'  => array(),
		);

		// Settings and the rest, moved (not copied): the old plugin's uninstall then finds nothing to revoke.
		// Values encrypted by the old plugin are re-encrypted with this plugin's key.
		$backup = array();
		foreach ( self::$options as $old => $new ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}
			if ( 'aab_settings' === $old && is_array( $value ) ) {
				$value = self::rename_settings( $value );
				foreach ( array( 'client_secret', 'stripe_secret', 'stripe_webhook_secret', 'ms_client_secret' ) as $secret ) {
					if ( isset( $value[ $secret ] ) ) {
						$value[ $secret ] = JATCS_Crypto::upgrade( $value[ $secret ] );
					}
				}
			}
			if ( in_array( $old, array( 'aab_google', 'aab_ms' ), true ) && is_array( $value ) && ! empty( $value['refresh_token'] ) ) {
				$report[ 'aab_google' === $old ? 'google' : 'outlook' ] = true;
				$value['refresh_token']                                 = JATCS_Crypto::upgrade( $value['refresh_token'] );
			}
			if ( in_array( $old, array( 'aab_settings', 'aab_types' ), true ) ) {
				$backup[ $old ] = $value;
			}
			update_option( $new, $value, in_array( $new, array( 'jatcs_settings', 'jatcs_types' ), true ) );
		}
		if ( isset( $backup['aab_settings'] ) ) {
			foreach ( array( 'client_secret', 'stripe_secret', 'stripe_webhook_secret', 'ms_client_secret' ) as $secret ) {
				unset( $backup['aab_settings'][ $secret ] );
			}
		}
		update_option( 'jatcs_legacy_backup', $backup, false );
		// The redirect addresses registered in Google Cloud / Microsoft Entra still point at the old action.
		if ( $report['google'] ) {
			update_option( 'jatcs_legacy_google_redirect', 1, false );
		}
		if ( $report['outlook'] ) {
			update_option( 'jatcs_legacy_ms_redirect', 1, false );
		}

		// Bookings, then their details (only booking meta: other plugins' "_aab_" keys are left alone).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time bulk rename.
		$ids                = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'aab_booking' ) ) );
		$report['bookings'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s", 'jatcs_booking', 'aab_booking' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = CONCAT( %s, SUBSTRING( meta_key, 6 ) ) WHERE meta_key LIKE %s AND post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = %s )", '_jatcs_', $wpdb->esc_like( '_aab_' ) . '%', 'jatcs_booking' ) );
		// Manage links in sent emails are kept encrypted per booking: re-encrypt them too.
		foreach ( $ids as $id ) {
			$enc = (string) get_post_meta( $id, '_jatcs_cancel_enc', true );
			if ( '' !== $enc ) {
				$upgraded = JATCS_Crypto::upgrade( $enc );
				if ( $upgraded !== $enc ) {
					update_post_meta( $id, '_jatcs_cancel_enc', $upgraded );
				}
			}
		}

		// Booking blocks and shortcodes in pages, posts, templates and patterns (the old names keep working either way).
		$report['pages'] = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE( REPLACE( post_content, %s, %s ), %s, %s ) WHERE post_content LIKE %s OR post_content LIKE %s",
				'wp:anderson-appointment-booking/booking',
				'wp:jat-calendar-sync/booking',
				'[anderson_booking',
				'[jat_booking',
				'%' . $wpdb->esc_like( 'anderson-appointment-booking/booking' ) . '%',
				'%' . $wpdb->esc_like( '[anderson_booking' ) . '%'
			)
		);
		// phpcs:enable
		// Cached copies of renamed posts would still say aab_booking: drop them.
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'posts' );
			wp_cache_flush_group( 'post_meta' );
		} else {
			wp_cache_flush();
		}

		// The old plugin: off (also when network-active), its schedule cleared, its leftovers gone.
		$report['plugins'] = self::deactivate_old_plugin();
		wp_clear_scheduled_hook( 'aab_hourly' );
		// Tokens first, settings last: if this stops part-way, the old plugin can't later revoke a token in use here.
		foreach ( array( 'aab_google', 'aab_ms', 'aab_google_error', 'aab_ms_error', 'aab_log', 'aab_busy_generation', 'aab_last_run', 'aab_installed', 'aab_types', 'aab_settings' ) as $old ) {
			delete_option( $old );
		}
		foreach ( array( 'aab_access_token', 'aab_calendars', 'aab_ms_access', 'aab_ms_calendars' ) as $transient ) {
			delete_transient( $transient );
		}

		update_option( self::DONE_OPTION, $report, false );
		update_option( self::NOTICE_OPTION, 1, false );
		JATCS_Settings::flush();
		if ( class_exists( 'JATCS_Maintenance' ) ) {
			JATCS_Maintenance::schedule();
		}
		/**
		 * Fires after a site moves over from Anderson Appointment Booking (purge page caches here).
		 *
		 * @param array $report What moved.
		 */
		do_action( 'jatcs_migrated', $report );
	}

	/**
	 * Carry settings over, renaming anything that referred to the old name.
	 *
	 * @param array $settings Old settings.
	 * @return array
	 */
	private static function rename_settings( array $settings ) {
		if ( ! empty( $settings['custom_css'] ) && is_string( $settings['custom_css'] ) ) {
			$settings['custom_css'] = str_replace( array( '--aab-', '.aab__', '.aab--', '.aab' ), array( '--jatcs-', '.jatcs__', '.jatcs--', '.jatcs' ), $settings['custom_css'] );
		}
		return $settings;
	}

	/**
	 * Deactivate any copy of the old plugin (it would otherwise keep running beside this one).
	 *
	 * @return string[] Plugin files deactivated.
	 */
	private static function deactivate_old_plugin() {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$old = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( 'anderson-appointment-booking.php' === basename( (string) $file ) ) {
				$old[] = (string) $file;
			}
		}
		if ( $old ) {
			deactivate_plugins( $old, true );
		}
		if ( is_multisite() ) {
			$network = array();
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $file ) {
				if ( 'anderson-appointment-booking.php' === basename( (string) $file ) ) {
					$network[] = (string) $file;
				}
			}
			if ( $network ) {
				deactivate_plugins( $network, true, true );
				$old = array_merge( $old, $network );
			}
		}
		return $old;
	}

	/**
	 * A query argument under its new name, or the old one (links in emails sent before the rename).
	 *
	 * @param string $name Argument name without prefix, e.g. 'manage'.
	 * @return string|null Raw value (caller sanitizes), or null.
	 */
	public static function query_arg( $name ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only lookup; callers sanitize and verify the token.
		foreach ( array( 'jatcs_', 'aab_' ) as $prefix ) {
			if ( isset( $_GET[ $prefix . $name ] ) && is_scalar( $_GET[ $prefix . $name ] ) ) {
				return wp_unslash( (string) $_GET[ $prefix . $name ] );
			}
		}
		// phpcs:enable
		return null;
	}

	/**
	 * One-time notice after moving.
	 */
	public static function notice() {
		if ( ! get_option( self::NOTICE_OPTION ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'plugins', 'dashboard', 'toplevel_page_jatcs-settings' ), true ) ) {
			return;
		}
		delete_option( self::NOTICE_OPTION );
		$report = (array) get_option( self::DONE_OPTION, array() );
		$parts  = array(
			/* translators: %d: number of bookings */
			sprintf( _n( '%d booking', '%d bookings', (int) ( $report['bookings'] ?? 0 ), 'jat-calendar-sync' ), (int) ( $report['bookings'] ?? 0 ) ),
		);
		if ( ! empty( $report['google'] ) ) {
			$parts[] = __( 'your Google Calendar connection', 'jat-calendar-sync' );
		}
		if ( ! empty( $report['outlook'] ) ) {
			$parts[] = __( 'your Outlook connection', 'jat-calendar-sync' );
		}
		echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'JAT Calendar Sync took over from Anderson Appointment Booking.', 'jat-calendar-sync' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: %s: list such as "3 bookings, your Google Calendar connection" */
				__( 'Your settings, appointment types, %s and booking pages carried over. The old plugin was deactivated and can now be deleted safely.', 'jat-calendar-sync' ),
				implode( ', ', $parts )
			)
		);
		echo '</p></div>';
	}
}
