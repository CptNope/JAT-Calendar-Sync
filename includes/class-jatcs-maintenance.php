<?php
/**
 * Background upkeep (hourly) and Site Health checks.
 *
 * Every hour, while the booking calendar (Google, Outlook or CalDAV) is connected:
 * - Sync: upcoming bookings whose event was deleted in the calendar are cancelled here,
 *   and ones moved to a new time are updated, with an email to the visitor either way.
 * - Reminders: visitors get a reminder email N hours before (setting; 0 turns it off).
 * - Retention: booking records older than N days are deleted (setting; 0 keeps them).
 *
 * WP-Cron runs on site traffic. On hosts that disable it (DISABLE_WP_CRON), make sure a real
 * cron job calls wp-cron.php; Site Health warns when the hourly task stops running.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hourly tasks and health checks.
 */
final class JATCS_Maintenance {

	const HOOK       = 'jatcs_hourly';
	const LAST_RUN   = 'jatcs_last_run';
	const BATCH_SIZE = 100;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'health_tests' ) );
	}

	/**
	 * Make sure the hourly event exists.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove the hourly event (deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Run all tasks.
	 *
	 * @return array{synced:int,cancelled:int,moved:int,reminded:int,deleted:int}
	 */
	public static function run() {
		$stats = array(
			'synced'    => 0,
			'cancelled' => 0,
			'moved'     => 0,
			'reminded'  => 0,
			'deleted'   => 0,
		);
		if ( JATCS_Calendars::ready() ) {
			$stats             = array_merge( $stats, self::sync() );
			$stats['reminded'] = self::reminders();
		}
		$payments          = JATCS_Payments::sweep();
		$stats['paid']     = $payments['confirmed'];
		$stats['released'] = $payments['expired'];
		$stats['deleted']  = self::retention();
		update_option( self::LAST_RUN, time(), false );
		return $stats;
	}

	/**
	 * Upcoming confirmed booking IDs, soonest first.
	 *
	 * @param int $until Only bookings starting before this time (0 = no limit).
	 * @return int[]
	 */
	private static function upcoming( $until = 0 ) {
		$meta = array(
			'relation' => 'AND',
			array(
				'key'   => '_jatcs_status',
				'value' => 'confirmed',
			),
			'start'    => array(
				'key'     => '_jatcs_start',
				'value'   => time(),
				'compare' => '>',
				'type'    => 'NUMERIC',
			),
		);
		if ( $until ) {
			$meta[] = array(
				'key'     => '_jatcs_start',
				'value'   => $until,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}
		return get_posts(
			array(
				'post_type'      => JATCS_Bookings::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => self::BATCH_SIZE,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'orderby'        => 'start',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Mirror changes made directly in the calendar (Google, Outlook or CalDAV).
	 *
	 * @return array{synced:int,cancelled:int,moved:int}
	 */
	public static function sync() {
		$out = array(
			'synced'    => 0,
			'cancelled' => 0,
			'moved'     => 0,
		);
		foreach ( self::upcoming() as $id ) {
			$b = JATCS_Bookings::get( $id );
			if ( ! $b || '' === $b['event_id'] ) {
				continue;
			}
			$event = JATCS_Calendars::event_state( $b['calendar_id'], $b['event_id'] );
			++$out['synced'];

			if ( is_wp_error( $event ) ) {
				continue; // Couldn't check: try again next hour.
			}

			if ( in_array( $event['status'], array( 'missing', 'cancelled' ), true ) ) {
				if ( ! is_wp_error( JATCS_Bookings::cancel( $id, 'calendar' ) ) ) {
					++$out['cancelled'];
				}
				continue;
			}

			if ( ! empty( $event['all_day'] ) ) {
				continue; // Turned into an all-day event; leave it alone.
			}
			$start = (int) $event['start'];
			$end   = (int) $event['end'];
			if ( $start && $end && $end > $start && ( $start !== $b['start'] || $end !== $b['end'] ) ) {
				update_post_meta( $id, '_jatcs_start', $start );
				update_post_meta( $id, '_jatcs_end', $end );
				update_post_meta( $id, '_jatcs_date', wp_date( 'Y-m-d', $start ) );
				delete_post_meta( $id, '_jatcs_reminded' );
				wp_update_post(
					array(
						'ID'         => $id,
						/* translators: 1: visitor name, 2: date and time */
						'post_title' => sprintf( __( '%1$s, %2$s', 'jat-calendar-sync' ), $b['name'], JATCS_Bookings::format_time( $start ) ),
					)
				);
				JATCS_Availability::bust_cache();
				if ( $start !== $b['start'] ) {
					JATCS_Bookings::email_rescheduled( JATCS_Bookings::get( $id ), $b['start'], 'calendar' );
				}
				++$out['moved'];
			}
		}
		return $out;
	}

	/**
	 * Send reminder emails.
	 *
	 * A booking only gets a reminder if it was made before its reminder window opened,
	 * so someone booking for this afternoon doesn't get a "reminder" minutes later.
	 *
	 * @return int Reminders sent.
	 */
	public static function reminders() {
		$hours = (int) JATCS_Settings::get( 'reminder_hours' );
		if ( $hours <= 0 ) {
			return 0;
		}
		$window = $hours * HOUR_IN_SECONDS;
		$sent   = 0;
		foreach ( self::upcoming( time() + $window ) as $id ) {
			if ( get_post_meta( $id, '_jatcs_reminded', true ) ) {
				continue;
			}
			update_post_meta( $id, '_jatcs_reminded', time() );
			$b       = JATCS_Bookings::get( $id );
			$created = (int) get_post_time( 'U', true, $id );
			if ( $b && $created && $created < $b['start'] - $window ) {
				JATCS_Bookings::email_reminder( $b );
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Delete old booking records.
	 *
	 * @return int Records deleted.
	 */
	public static function retention() {
		$days = (int) JATCS_Settings::get( 'retention_days' );
		if ( $days <= 0 ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => JATCS_Bookings::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => self::BATCH_SIZE,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_jatcs_end',
						'value'   => time() - $days * DAY_IN_SECONDS,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		return count( $ids );
	}

	/**
	 * Register Site Health tests.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function health_tests( $tests ) {
		$tests['direct']['jatcs_google']   = array(
			'label' => __( 'JAT Calendar Sync: calendar connection', 'jat-calendar-sync' ),
			'test'  => array( __CLASS__, 'test_google' ),
		);
		$tests['direct']['jatcs_outlook']  = array(
			'label' => __( 'JAT Calendar Sync: Outlook calendars', 'jat-calendar-sync' ),
			'test'  => array( __CLASS__, 'test_outlook' ),
		);
		$tests['direct']['jatcs_cron']     = array(
			'label' => __( 'JAT Calendar Sync: background tasks', 'jat-calendar-sync' ),
			'test'  => array( __CLASS__, 'test_cron' ),
		);
		$tests['direct']['jatcs_timezone'] = array(
			'label' => __( 'JAT Calendar Sync: timezone', 'jat-calendar-sync' ),
			'test'  => array( __CLASS__, 'test_timezone' ),
		);
		return $tests;
	}

	/**
	 * Build a Site Health result.
	 *
	 * @param string $test        Test ID.
	 * @param string $status      good|recommended|critical.
	 * @param string $label       Heading.
	 * @param string $description Plain-text explanation.
	 * @param string $action      Optional HTML action.
	 * @return array
	 */
	private static function result( $test, $status, $label, $description, $action = '' ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Appointments', 'jat-calendar-sync' ),
				'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $action,
			'test'        => $test,
		);
	}

	/**
	 * Link to the settings page.
	 *
	 * @return string
	 */
	private static function settings_link() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=jatcs-settings' ) ) . '">' . esc_html__( 'Open appointment settings', 'jat-calendar-sync' ) . '</a>';
	}

	/**
	 * Can visitors book? (Booking calendar connected and every blocking calendar readable.)
	 *
	 * @return array
	 */
	public static function test_google() {
		$service = JATCS_Calendars::booking_service();
		if ( 'google' === $service && get_option( JATCS_Google::ERROR_OPTION ) ) {
			return self::result( 'jatcs_google', 'critical', __( 'Online booking is offline: Google Calendar access expired or was revoked', 'jat-calendar-sync' ), __( 'Visitors can’t book until you reconnect Google Calendar. If this keeps happening every week, publish your Google OAuth app to In production.', 'jat-calendar-sync' ), self::settings_link() );
		}
		if ( ! JATCS_Calendars::ready() ) {
			$why = 'ms' === $service && JATCS_Microsoft::is_connected()
				? __( 'Your booking calendar is in Outlook, but Outlook was connected before write access was needed. Reconnect Outlook to let new bookings be added.', 'jat-calendar-sync' )
				: __( 'Connect a calendar (Google, Outlook, iCloud or another CalDAV service) and choose where bookings go to turn on online booking.', 'jat-calendar-sync' );
			return self::result( 'jatcs_google', 'recommended', __( 'No booking calendar is connected, so visitors can’t book', 'jat-calendar-sync' ), $why, self::settings_link() );
		}
		$busy = JATCS_Calendars::busy( time(), time() + DAY_IN_SECONDS );
		if ( is_wp_error( $busy ) ) {
			return self::result(
				'jatcs_google',
				'critical',
				__( 'Online booking is offline: a calendar can’t be read', 'jat-calendar-sync' ),
				/* translators: %s: error message */
				sprintf( __( 'No times are offered while a calendar that blocks times can’t be checked, so nobody books over an event. The problem: %s', 'jat-calendar-sync' ), $busy->get_error_message() ),
				self::settings_link()
			);
		}
		return self::result(
			'jatcs_google',
			'good',
			__( 'Your booking calendar is connected', 'jat-calendar-sync' ),
			/* translators: %s: calendar name */
			sprintf( __( 'New bookings go to “%s”, and every calendar that blocks times can be read.', 'jat-calendar-sync' ), JATCS_Calendars::label( JATCS_Calendars::booking_ref() ) )
		);
	}

	/**
	 * Outlook connection health (only reported when Outlook is in use).
	 *
	 * @return array
	 */
	public static function test_outlook() {
		if ( get_option( JATCS_Microsoft::ERROR_OPTION ) ) {
			return self::result( 'jatcs_outlook', 'critical', __( 'Online booking is offline: Outlook calendar access expired', 'jat-calendar-sync' ), __( 'Your Outlook calendars are set to block times but can’t be read, so no times are offered. Reconnect Outlook, or disconnect it if you no longer use it.', 'jat-calendar-sync' ), self::settings_link() );
		}
		if ( JATCS_Microsoft::active() ) {
			return self::result( 'jatcs_outlook', 'good', __( 'Outlook calendars are connected', 'jat-calendar-sync' ), __( 'Busy times in your selected Outlook calendars block bookings.', 'jat-calendar-sync' ) );
		}
		return self::result( 'jatcs_outlook', 'good', __( 'Outlook calendars are not in use', 'jat-calendar-sync' ), __( 'No Outlook calendars are checked for busy times.', 'jat-calendar-sync' ) );
	}

	/**
	 * Is the hourly task running?
	 *
	 * @return array
	 */
	public static function test_cron() {
		$last = (int) get_option( self::LAST_RUN );
		$late = JATCS_Calendars::ready() && ( ! $last || time() - $last > 3 * HOUR_IN_SECONDS );
		// A fresh install hasn't had its first run yet.
		$installed = (int) get_option( 'jatcs_installed', time() );
		if ( $late && time() - $installed > 3 * HOUR_IN_SECONDS ) {
			return self::result( 'jatcs_cron', 'recommended', __( 'Booking reminders and calendar sync aren’t running', 'jat-calendar-sync' ), __( 'The hourly task that sends reminders and notices events you move or delete in your calendar hasn’t run in over three hours. If WP-Cron is disabled on this host, add a server cron job that calls wp-cron.php every few minutes.', 'jat-calendar-sync' ) );
		}
		return self::result( 'jatcs_cron', 'good', __( 'Booking background tasks are running', 'jat-calendar-sync' ), __( 'Reminders and calendar sync run hourly.', 'jat-calendar-sync' ) );
	}

	/**
	 * Is the site timezone a city (needed for correct daylight-saving handling)?
	 *
	 * @return array
	 */
	public static function test_timezone() {
		if ( '' === (string) get_option( 'timezone_string' ) ) {
			return self::result(
				'jatcs_timezone',
				'recommended',
				__( 'Set your site timezone to a city for correct appointment times', 'jat-calendar-sync' ),
				__( 'Your site uses a fixed UTC offset, which doesn’t change for daylight saving time, so your booking hours would shift by an hour twice a year. Choose a city (for example New York) under Settings → General → Timezone.', 'jat-calendar-sync' ),
				'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Open general settings', 'jat-calendar-sync' ) . '</a>'
			);
		}
		return self::result( 'jatcs_timezone', 'good', __( 'Site timezone is set to a city', 'jat-calendar-sync' ), __( 'Booking hours follow daylight saving time correctly.', 'jat-calendar-sync' ) );
	}
}
