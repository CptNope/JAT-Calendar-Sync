<?php
/**
 * People who can be booked separately ("hosts").
 *
 * The site owner is the default host (''): their calendars and settings are the plugin's
 * usual ones, so a site that never adds a host works exactly as before. Each added host
 * connects their own Google account and chooses their own booking calendar and the
 * calendars that block their times. Appointment types belong to one host.
 *
 * While the plugin works for a host (showing their open times, booking, moving or cancelling
 * one of their appointments, or the hourly sync), that host is "current": the Google client
 * then uses the host's tokens, and the host's own settings override the site's.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hosts and the current-host context.
 */
final class JATCS_Hosts {

	const OPTION = 'jatcs_hosts';

	/**
	 * Settings a host has of their own (everything else is the site's).
	 */
	const OWN_SETTINGS = array( 'booking_calendar', 'conflict_calendars', 'notify_email' );

	/**
	 * Settings that don't apply to a host (other services are connected for the site owner only).
	 */
	const SITE_ONLY = array( 'ms_calendars', 'caldav_calendars', 'schedule_calendar' );

	/**
	 * The host the plugin is working for right now ('' = the site owner).
	 *
	 * @var string
	 */
	private static $current = '';

	/**
	 * Every added host, keyed by slug.
	 *
	 * @return array<string,array{name:string,notify_email:string,booking_calendar:string,conflict_calendars:string[]}>
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $slug => $h ) {
			if ( ! is_array( $h ) || '' === sanitize_key( (string) $slug ) ) {
				continue;
			}
			$out[ sanitize_key( (string) $slug ) ] = array(
				'name'               => isset( $h['name'] ) ? (string) $h['name'] : (string) $slug,
				'notify_email'       => isset( $h['notify_email'] ) ? (string) $h['notify_email'] : '',
				'booking_calendar'   => isset( $h['booking_calendar'] ) && '' !== (string) $h['booking_calendar'] ? (string) $h['booking_calendar'] : 'primary',
				'conflict_calendars' => isset( $h['conflict_calendars'] ) && is_array( $h['conflict_calendars'] ) ? array_values( array_map( 'strval', $h['conflict_calendars'] ) ) : array(),
			);
		}
		return $out;
	}

	/**
	 * One host, or null.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::all();
		$slug = sanitize_key( (string) $slug );
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Does this host exist? ('' always does: the site owner.)
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function exists( $slug ) {
		return '' === (string) $slug || null !== self::get( $slug );
	}

	/**
	 * Save one host.
	 *
	 * @param string $slug Slug.
	 * @param array  $host Host.
	 */
	public static function save( $slug, array $host ) {
		$all                          = get_option( self::OPTION, array() );
		$all                          = is_array( $all ) ? $all : array();
		$all[ sanitize_key( $slug ) ] = $host;
		update_option( self::OPTION, $all, false );
		JATCS_Settings::flush();
	}

	/**
	 * Remove a host (disconnects their Google account first).
	 *
	 * @param string $slug Slug.
	 */
	public static function delete( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return;
		}
		self::run(
			$slug,
			static function () {
				JATCS_Google::disconnect();
			}
		);
		$all = get_option( self::OPTION, array() );
		if ( is_array( $all ) && isset( $all[ $slug ] ) ) {
			unset( $all[ $slug ] );
			update_option( self::OPTION, $all, false );
		}
		JATCS_Settings::flush();
	}

	/**
	 * A free slug for a new host's name.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function unique_slug( $name ) {
		$base = sanitize_key( str_replace( ' ', '-', strtolower( remove_accents( (string) $name ) ) ) );
		$base = '' !== $base ? substr( $base, 0, 40 ) : 'host';
		$slug = $base;
		for ( $i = 2; self::get( $slug ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		return $slug;
	}

	/**
	 * The current host.
	 *
	 * @return string
	 */
	public static function current() {
		return self::$current;
	}

	/**
	 * Make a host current. Returns the previous one, for restore().
	 *
	 * Request handlers that only ever work for one host (a REST call for one type or one
	 * booking) can call this and never restore it.
	 *
	 * @param string $slug Host ('' = the site owner).
	 * @return string
	 */
	public static function enter( $slug ) {
		$previous      = self::$current;
		$slug          = sanitize_key( (string) $slug );
		self::$current = self::exists( $slug ) ? $slug : '';
		if ( $previous !== self::$current ) {
			JATCS_Settings::flush();
		}
		return $previous;
	}

	/**
	 * Go back to a host returned by enter().
	 *
	 * @param string $previous Host.
	 */
	public static function restore( $previous ) {
		self::enter( $previous );
	}

	/**
	 * Run a function for a host, then go back to whoever was current.
	 *
	 * @param string   $slug Host.
	 * @param callable $fn   Function.
	 * @return mixed What the function returns.
	 */
	public static function run( $slug, callable $fn ) {
		$previous = self::enter( $slug );
		try {
			return $fn();
		} finally {
			self::restore( $previous );
		}
	}

	/**
	 * Settings as the current host sees them: the site's, with the host's own on top.
	 *
	 * @param array $settings Site settings.
	 * @return array
	 */
	public static function overlay( array $settings ) {
		if ( '' === self::$current ) {
			return $settings;
		}
		$host = self::get( self::$current );
		if ( ! $host ) {
			return $settings;
		}
		foreach ( self::OWN_SETTINGS as $key ) {
			if ( 'notify_email' === $key && ! is_email( $host['notify_email'] ) ) {
				continue; // No address of their own: notices go to the site's.
			}
			$settings[ $key ] = $host[ $key ];
		}
		foreach ( self::SITE_ONLY as $key ) {
			$settings[ $key ] = is_array( $settings[ $key ] ) ? array() : '';
		}
		return $settings;
	}

	/**
	 * A suffix that keeps one host's stored data apart from another's ('' for the site owner,
	 * so the site owner's data stays where it always was).
	 *
	 * @param string|null $slug Host (null: the current one).
	 * @return string
	 */
	public static function suffix( $slug = null ) {
		$slug = null === $slug ? self::$current : sanitize_key( (string) $slug );
		return '' === $slug ? '' : '__' . $slug;
	}

	/**
	 * The host an appointment type belongs to.
	 *
	 * @param array|null $type Type.
	 * @return string
	 */
	public static function of_type( $type ) {
		$slug = is_array( $type ) && isset( $type['host'] ) ? sanitize_key( (string) $type['host'] ) : '';
		return self::exists( $slug ) ? $slug : '';
	}

	/**
	 * The host a booking belongs to.
	 *
	 * @param int|array $booking Booking ID or data.
	 * @return string
	 */
	public static function of_booking( $booking ) {
		$id   = is_array( $booking ) ? (int) $booking['id'] : (int) $booking;
		$slug = sanitize_key( (string) get_post_meta( $id, '_jatcs_host', true ) );
		return self::exists( $slug ) ? $slug : '';
	}

	/**
	 * Can visitors book this host right now? Their booking calendar is connected and
	 * at least one of their appointment types can be booked.
	 *
	 * @param string $slug Host ('' = the site owner).
	 * @return bool
	 */
	public static function ready( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( ! self::exists( $slug ) ) {
			return false;
		}
		return (bool) self::run(
			$slug,
			static function () {
				return JATCS_Calendars::ready() && (bool) JATCS_Types::public_list();
			}
		);
	}

	/**
	 * Is anyone (the site owner or a host) bookable? For the hourly tasks.
	 *
	 * @return bool
	 */
	public static function any_connected() {
		if ( JATCS_Calendars::ready() ) {
			return true;
		}
		foreach ( array_keys( self::all() ) as $slug ) {
			if ( self::run( $slug, array( 'JATCS_Calendars', 'ready' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Name for display ('' = the site owner).
	 *
	 * @param string $slug Host.
	 * @return string
	 */
	public static function name( $slug ) {
		if ( '' === (string) $slug ) {
			return __( 'Site owner', 'jat-calendar-sync' );
		}
		$host = self::get( $slug );
		return $host ? $host['name'] : (string) $slug;
	}
}

/**
 * Can a host be booked right now? For themes and other plugins that want to show a booking
 * calendar only once it is set up (for example on a team member's profile page).
 *
 * @param string $host Host slug ('' = the site owner).
 * @return bool
 */
function jatcs_host_ready( $host = '' ) {
	return JATCS_Hosts::ready( $host );
}
