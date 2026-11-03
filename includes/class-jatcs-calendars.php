<?php
/**
 * One way to talk to every calendar service.
 *
 * A calendar is named by a reference: "google:ID" (or a bare Google ID, as stored before 2.0),
 * "ms:ID" for Outlook / Microsoft 365, "caldav:URL" for iCloud, Fastmail, Nextcloud and other
 * CalDAV servers, and "ics:ID" for an iCal feed (read only). Bookings are created in the
 * booking calendar; busy times come from every calendar chosen to block times. Anything that
 * can't be read makes the check fail, so nobody books over an event the plugin couldn't see.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calendar services behind a single interface.
 */
final class JATCS_Calendars {

	/**
	 * Split a reference into service and ID.
	 *
	 * @param string $ref Reference.
	 * @return array{0:string,1:string} Service ('google', 'ms', 'caldav', 'ics') and ID.
	 */
	public static function parse( $ref ) {
		$ref = (string) $ref;
		if ( preg_match( '/^(google|ms|caldav|ics):(.+)$/s', $ref, $m ) ) {
			return array( $m[1], $m[2] );
		}
		return array( 'google', $ref );
	}

	/**
	 * The canonical form of a reference ("primary" → "google:primary").
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	public static function canonical( $ref ) {
		if ( '' === (string) $ref ) {
			return '';
		}
		list( $service, $id ) = self::parse( $ref );
		return $service . ':' . $id;
	}

	/**
	 * Clean a submitted calendar reference. CalDAV addresses keep their %-encoding.
	 *
	 * @param string $ref Raw reference.
	 * @return string
	 */
	public static function sanitize_ref( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return '';
		}
		list( $service, $id ) = self::parse( $ref );
		if ( 'caldav' === $service ) {
			$url = esc_url_raw( $id, array( 'https', 'http' ) );
			return '' !== $url ? 'caldav:' . $url : '';
		}
		$id = sanitize_text_field( $id );
		return 'google' === $service && 0 !== strpos( $ref, 'google:' ) ? $id : $service . ':' . $id;
	}

	/**
	 * Where new bookings go.
	 *
	 * @return string Canonical reference.
	 */
	public static function booking_ref() {
		$ref = (string) JATCS_Settings::get( 'booking_calendar' );
		return self::canonical( '' !== $ref ? $ref : 'primary' );
	}

	/**
	 * The service bookings go to.
	 *
	 * @return string
	 */
	public static function booking_service() {
		list( $service ) = self::parse( self::booking_ref() );
		return $service;
	}

	/**
	 * Is a service connected?
	 *
	 * @param string $service Service.
	 * @return bool
	 */
	public static function connected( $service ) {
		switch ( $service ) {
			case 'google':
				return JATCS_Google::is_connected();
			case 'ms':
				return JATCS_Microsoft::is_connected();
			case 'caldav':
				return JATCS_CalDAV_Store::is_connected();
			case 'ics':
				return (bool) JATCS_Feeds::all();
		}
		return false;
	}

	/**
	 * Can visitors book right now? (The booking calendar's service is connected and can write.)
	 *
	 * @return bool
	 */
	public static function ready() {
		switch ( self::booking_service() ) {
			case 'google':
				return JATCS_Google::is_connected();
			case 'ms':
				return JATCS_Microsoft::can_write();
			case 'caldav':
				return JATCS_CalDAV_Store::is_connected();
		}
		return false;
	}

	/**
	 * Is any calendar service connected (for setup prompts)?
	 *
	 * @return bool
	 */
	public static function any_connected() {
		return JATCS_Google::is_connected() || JATCS_Microsoft::is_connected() || JATCS_CalDAV_Store::is_connected();
	}

	/**
	 * Every calendar the site can see, for choosing the booking and schedule calendars.
	 *
	 * @param bool $writable_only Only calendars bookings can be added to.
	 * @return array<int,array{ref:string,name:string,service:string,service_name:string,writable:bool}>
	 */
	public static function choices( $writable_only = false ) {
		$out = array();
		if ( JATCS_Google::is_connected() ) {
			$cals = JATCS_Google::calendars();
			if ( ! is_wp_error( $cals ) ) {
				foreach ( $cals as $cal ) {
					$out[] = array(
						'ref'          => $cal['primary'] ? 'primary' : (string) $cal['id'],
						'name'         => (string) $cal['summary'] . ( $cal['primary'] ? ' ' . __( '(primary)', 'jat-calendar-sync' ) : '' ),
						'service'      => 'google',
						'service_name' => __( 'Google Calendar', 'jat-calendar-sync' ),
						'writable'     => ! empty( $cal['writable'] ),
					);
				}
			}
		}
		if ( JATCS_Microsoft::is_connected() ) {
			$cals = JATCS_Microsoft::calendars();
			if ( ! is_wp_error( $cals ) ) {
				foreach ( $cals as $cal ) {
					$out[] = array(
						'ref'          => 'ms:' . $cal['id'],
						'name'         => (string) $cal['name'] . ( $cal['default'] ? ' ' . __( '(default)', 'jat-calendar-sync' ) : '' ),
						'service'      => 'ms',
						'service_name' => __( 'Outlook / Microsoft 365', 'jat-calendar-sync' ),
						'writable'     => ! empty( $cal['writable'] ) && JATCS_Microsoft::can_write(),
					);
				}
			}
		}
		if ( JATCS_CalDAV_Store::is_connected() ) {
			$cals = JATCS_CalDAV_Store::calendars();
			if ( ! is_wp_error( $cals ) ) {
				foreach ( $cals as $cal ) {
					$out[] = array(
						'ref'          => 'caldav:' . $cal['url'],
						'name'         => (string) $cal['name'],
						'service'      => 'caldav',
						'service_name' => JATCS_CalDAV_Store::service_name(),
						'writable'     => ! empty( $cal['writable'] ),
					);
				}
			}
		}
		foreach ( JATCS_Feeds::all() as $id => $feed ) {
			$out[] = array(
				'ref'          => 'ics:' . $id,
				'name'         => (string) $feed['name'],
				'service'      => 'ics',
				'service_name' => __( 'iCal feed', 'jat-calendar-sync' ),
				'writable'     => false,
			);
		}
		if ( $writable_only ) {
			$out = array_values(
				array_filter(
					$out,
					static function ( $c ) {
						return $c['writable'];
					}
				)
			);
		}
		return $out;
	}

	/**
	 * A calendar's name, for messages.
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	public static function label( $ref ) {
		$canonical = self::canonical( $ref );
		foreach ( self::choices() as $choice ) {
			if ( self::canonical( $choice['ref'] ) === $canonical ) {
				return $choice['name'];
			}
		}
		list( , $id ) = self::parse( $ref );
		return $id;
	}

	/**
	 * Calendars whose busy times block bookings, grouped by service.
	 *
	 * @return array<string,string[]> service => IDs.
	 */
	public static function blocking() {
		$groups = array(
			'google' => array(),
			'ms'     => array(),
			'caldav' => array(),
			'ics'    => array(),
		);
		// Ticked calendars of a service that is no longer connected are ignored (they can't be
		// unticked while it's disconnected). The booking and schedule calendars below are not:
		// if their service breaks, no times are offered, rather than booking over hidden events.
		if ( JATCS_Google::is_connected() ) {
			foreach ( (array) JATCS_Settings::get( 'conflict_calendars' ) as $ref ) {
				list( $service, $id ) = self::parse( (string) $ref );
				$groups[ $service ][] = $id;
			}
		}
		if ( JATCS_Microsoft::is_connected() ) {
			foreach ( (array) JATCS_Settings::get( 'ms_calendars' ) as $id ) {
				$groups['ms'][] = (string) $id;
			}
		}
		if ( JATCS_CalDAV_Store::is_connected() ) {
			foreach ( (array) JATCS_Settings::get( 'caldav_calendars' ) as $url ) {
				$groups['caldav'][] = (string) $url;
			}
		}
		foreach ( JATCS_Feeds::all() as $id => $feed ) {
			if ( ! empty( $feed['block'] ) ) {
				$groups['ics'][] = (string) $id;
			}
		}
		// The booking calendar and the work-schedule calendar always block their own times.
		foreach ( array( self::booking_ref(), (string) JATCS_Settings::get( 'schedule_calendar' ) ) as $ref ) {
			if ( '' !== $ref ) {
				list( $service, $id ) = self::parse( $ref );
				$groups[ $service ][] = $id;
			}
		}
		foreach ( $groups as $service => $ids ) {
			$groups[ $service ] = array_values( array_unique( array_filter( $ids, 'strlen' ) ) );
		}
		return $groups;
	}

	/**
	 * A fingerprint of everything that decides busy times (for cache keys).
	 *
	 * @return string
	 */
	public static function fingerprint() {
		return md5( wp_json_encode( array( self::blocking(), JATCS_Feeds::version() ) ) );
	}

	/**
	 * Busy times across every blocking calendar.
	 *
	 * @param int        $from Unix start.
	 * @param int        $to   Unix end.
	 * @param array|null $skip {calendar, start, end}: a booking's own event, ignored when moving it.
	 * @param array      $pad  Canonical ref => [seconds before, seconds after] (clear time around work shifts).
	 * @return array<int,array{0:int,1:int}>|WP_Error
	 */
	public static function busy( $from, $to, $skip = null, array $pad = array() ) {
		$groups = self::blocking();
		$maps   = array();
		if ( $groups['google'] ) {
			$maps['google'] = JATCS_Google::is_connected() ? JATCS_Google::busy_map( $from, $to, $groups['google'] ) : new WP_Error( 'jatcs_not_connected', __( 'Google Calendar is not connected.', 'jat-calendar-sync' ) );
		}
		if ( $groups['ms'] ) {
			$maps['ms'] = JATCS_Microsoft::is_connected() ? JATCS_Microsoft::busy_map( $from, $to, $groups['ms'] ) : new WP_Error( 'jatcs_ms_not_connected', __( 'Outlook is not connected.', 'jat-calendar-sync' ) );
		}
		if ( $groups['caldav'] ) {
			$maps['caldav'] = JATCS_CalDAV_Store::busy_map( $from, $to, $groups['caldav'] );
		}
		if ( $groups['ics'] ) {
			$maps['ics'] = JATCS_Feeds::busy_map( $from, $to, $groups['ics'] );
		}

		$skip_ref = $skip ? self::canonical( $skip['calendar'] ) : '';
		$busy     = array();
		foreach ( $maps as $service => $map ) {
			if ( is_wp_error( $map ) ) {
				return $map;
			}
			foreach ( $map as $id => $ranges ) {
				$ref = $service . ':' . $id;
				if ( $skip && $ref === $skip_ref ) {
					$ranges = JATCS_Availability::subtract( $ranges, (int) $skip['start'], (int) $skip['end'] );
				}
				if ( isset( $pad[ $ref ] ) ) {
					foreach ( $ranges as $i => $r ) {
						$ranges[ $i ] = array( $r[0] - (int) $pad[ $ref ][0], $r[1] + (int) $pad[ $ref ][1] );
					}
				}
				foreach ( $ranges as $r ) {
					$busy[] = $r;
				}
			}
		}
		return $busy;
	}

	/**
	 * End of the last upcoming event on a calendar (how far a posted work schedule reaches).
	 *
	 * @param string $ref        Reference.
	 * @param int    $days_ahead How far to look.
	 * @return int|WP_Error Unix time, 0 if nothing is scheduled.
	 */
	public static function last_event_end( $ref, $days_ahead ) {
		list( $service, $id ) = self::parse( $ref );
		switch ( $service ) {
			case 'google':
				return JATCS_Google::last_event_end( $id, $days_ahead );
			case 'ms':
				return JATCS_Microsoft::last_event_end( $id, $days_ahead );
			case 'caldav':
				return JATCS_CalDAV_Store::last_event_end( $id, $days_ahead );
			case 'ics':
				return JATCS_Feeds::last_event_end( $id, $days_ahead );
		}
		return new WP_Error( 'jatcs_bad_calendar', __( 'Unknown calendar.', 'jat-calendar-sync' ) );
	}

	/**
	 * Add a booking to a calendar.
	 *
	 * @param string $ref   Reference.
	 * @param array  $event {uid, booking_id, summary, description, location, start, end, timezone,
	 *                      attendee: {email, name}|null, video: bool, url}.
	 * @return array|WP_Error {id, link, video}
	 */
	public static function create_event( $ref, array $event ) {
		list( $service, $id ) = self::parse( $ref );
		switch ( $service ) {
			case 'google':
				return self::google_create( $id, $event );
			case 'ms':
				return JATCS_Microsoft::insert_event( $id, $event );
			case 'caldav':
				return JATCS_CalDAV_Store::create_event( $id, $event );
		}
		return new WP_Error( 'jatcs_bad_calendar', __( 'Bookings can’t be added to this calendar.', 'jat-calendar-sync' ) );
	}

	/**
	 * Move a booking's event.
	 *
	 * @param string $ref      Reference.
	 * @param string $event_id Event ID.
	 * @param int    $start    Unix start.
	 * @param int    $end      Unix end.
	 * @param bool   $notify   Tell the guest.
	 * @return true|WP_Error
	 */
	public static function move_event( $ref, $event_id, $start, $end, $notify ) {
		list( $service, $id ) = self::parse( $ref );
		switch ( $service ) {
			case 'google':
				$tz     = self::timezone();
				$result = JATCS_Google::patch_event(
					$id,
					$event_id,
					array(
						'start' => array(
							'dateTime' => wp_date( 'c', $start ),
							'timeZone' => $tz,
						),
						'end'   => array(
							'dateTime' => wp_date( 'c', $end ),
							'timeZone' => $tz,
						),
					),
					$notify
				);
				return is_wp_error( $result ) ? $result : true;
			case 'ms':
				return JATCS_Microsoft::move_event( $event_id, $start, $end );
			case 'caldav':
				return JATCS_CalDAV_Store::move_event( $id, $event_id, $start, $end );
		}
		return new WP_Error( 'jatcs_bad_calendar', __( 'Unknown calendar.', 'jat-calendar-sync' ) );
	}

	/**
	 * Remove a booking's event.
	 *
	 * @param string $ref      Reference.
	 * @param string $event_id Event ID.
	 * @param bool   $notify   Tell the guest.
	 * @return true|WP_Error
	 */
	public static function delete_event( $ref, $event_id, $notify ) {
		list( $service, $id ) = self::parse( $ref );
		switch ( $service ) {
			case 'google':
				$result = JATCS_Google::delete_event( $id, $event_id, $notify );
				return is_wp_error( $result ) ? $result : true;
			case 'ms':
				return JATCS_Microsoft::delete_event( $event_id, $notify );
			case 'caldav':
				return JATCS_CalDAV_Store::delete_event( $event_id );
		}
		return new WP_Error( 'jatcs_bad_calendar', __( 'Unknown calendar.', 'jat-calendar-sync' ) );
	}

	/**
	 * What happened to a booking's event in the calendar (moved, deleted)?
	 *
	 * @param string $ref      Reference.
	 * @param string $event_id Event ID.
	 * @return array|WP_Error {status: confirmed|cancelled|missing, start, end, all_day}
	 */
	public static function event_state( $ref, $event_id ) {
		list( $service, $id ) = self::parse( $ref );
		switch ( $service ) {
			case 'google':
				return JATCS_Google::is_connected() ? JATCS_Google::event_state( $id, $event_id ) : new WP_Error( 'jatcs_not_connected', 'Google Calendar is not connected.' );
			case 'ms':
				return JATCS_Microsoft::is_connected() ? JATCS_Microsoft::event_state( $event_id ) : new WP_Error( 'jatcs_ms_not_connected', 'Outlook is not connected.' );
			case 'caldav':
				return JATCS_CalDAV_Store::is_connected() ? JATCS_CalDAV_Store::event_state( $event_id ) : new WP_Error( 'jatcs_caldav_not_connected', 'CalDAV is not connected.' );
		}
		return new WP_Error( 'jatcs_bad_calendar', 'Unknown calendar.' );
	}

	/**
	 * The site's IANA timezone, or UTC when the site uses a fixed offset.
	 *
	 * @return string
	 */
	public static function timezone() {
		$tz = wp_timezone_string();
		return preg_match( '#^[A-Za-z_]+/[A-Za-z_/\-+0-9]+$#', $tz ) || 'UTC' === $tz ? $tz : 'UTC';
	}

	/**
	 * Create the event in Google Calendar (with a Meet link when asked).
	 *
	 * @param string $calendar Calendar ID.
	 * @param array  $e        Neutral event.
	 * @return array|WP_Error
	 */
	private static function google_create( $calendar, array $e ) {
		$tz    = self::timezone();
		$event = array(
			'id'                 => (string) $e['uid'],
			'summary'            => (string) $e['summary'],
			'description'        => (string) $e['description'],
			'start'              => array(
				'dateTime' => wp_date( 'c', (int) $e['start'] ),
				'timeZone' => $tz,
			),
			'end'                => array(
				'dateTime' => wp_date( 'c', (int) $e['end'] ),
				'timeZone' => $tz,
			),
			'reminders'          => array( 'useDefault' => true ),
			'extendedProperties' => array( 'private' => array( 'jatcs_booking_id' => (string) $e['booking_id'] ) ),
		);
		if ( '' !== (string) $e['location'] ) {
			$event['location'] = (string) $e['location'];
		}
		$invite = ! empty( $e['attendee']['email'] );
		if ( $invite ) {
			$event['attendees'] = array(
				array(
					'email'       => (string) $e['attendee']['email'],
					'displayName' => (string) $e['attendee']['name'],
				),
			);
		}
		if ( ! empty( $e['video'] ) ) {
			$event['conferenceData'] = array(
				'createRequest' => array(
					'requestId'             => 'jatcs-' . (int) $e['booking_id'],
					'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ),
				),
			);
		}
		$created = JATCS_Google::insert_event( $calendar, $event, $invite );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$video = '';
		if ( ! empty( $created['hangoutLink'] ) ) {
			$video = (string) $created['hangoutLink'];
		} elseif ( ! empty( $created['conferenceData']['entryPoints'] ) ) {
			foreach ( $created['conferenceData']['entryPoints'] as $entry ) {
				if ( isset( $entry['entryPointType'] ) && 'video' === $entry['entryPointType'] ) {
					$video = (string) $entry['uri'];
					break;
				}
			}
		}
		return array(
			'id'    => (string) $created['id'],
			'link'  => isset( $created['htmlLink'] ) ? (string) $created['htmlLink'] : '',
			'video' => $video,
		);
	}
}
