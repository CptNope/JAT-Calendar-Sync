<?php
/**
 * The connected CalDAV account (iCloud, Fastmail, Nextcloud, Zoho, Yahoo, mailbox.org or any
 * CalDAV server): stores its sign-in, lists its calendars, reads busy times and adds, moves
 * and removes booking events.
 *
 * The password (an app-specific password for iCloud, Fastmail and Yahoo) is encrypted at rest.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * CalDAV account and calendar operations.
 */
final class JATCS_CalDAV_Store {

	const OPTION        = 'jatcs_caldav';
	const CALENDARS_KEY = 'jatcs_caldav_calendars';

	/**
	 * Saved account.
	 *
	 * @return array
	 */
	private static function account() {
		$data = get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Is an account connected?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$a = self::account();
		return ! empty( $a['home'] ) && ! empty( $a['password'] );
	}

	/**
	 * The service's name ("iCloud", "Fastmail", or the server's host).
	 *
	 * @return string
	 */
	public static function service_name() {
		$a       = self::account();
		$presets = JATCS_CalDAV::presets();
		if ( ! empty( $a['preset'] ) && 'other' !== $a['preset'] && isset( $presets[ $a['preset'] ] ) ) {
			return (string) $presets[ $a['preset'] ]['name'];
		}
		$host = isset( $a['url'] ) ? (string) wp_parse_url( $a['url'], PHP_URL_HOST ) : '';
		return '' !== $host ? $host : 'CalDAV';
	}

	/**
	 * The signed-in user name.
	 *
	 * @return string
	 */
	public static function username() {
		$a = self::account();
		return isset( $a['username'] ) ? (string) $a['username'] : '';
	}

	/**
	 * A client for the saved account.
	 *
	 * @return JATCS_CalDAV|WP_Error
	 */
	private static function client() {
		$a = self::account();
		if ( empty( $a['url'] ) || empty( $a['password'] ) ) {
			return new WP_Error( 'jatcs_caldav_not_connected', __( 'No CalDAV calendar is connected.', 'jat-calendar-sync' ) );
		}
		return new JATCS_CalDAV( (string) $a['url'], (string) $a['username'], JATCS_Crypto::decrypt( (string) $a['password'] ) );
	}

	/**
	 * Sign in, find the calendars and save the account.
	 *
	 * @param string $preset   Preset key (icloud, fastmail, …, other).
	 * @param string $url      Server address.
	 * @param string $username User name or email.
	 * @param string $password App-specific password.
	 * @return array|WP_Error Calendars.
	 */
	public static function connect( $preset, $url, $username, $password ) {
		$presets = JATCS_CalDAV::presets();
		$preset  = isset( $presets[ $preset ] ) ? $preset : 'other';
		if ( '' === trim( (string) $url ) && '' !== $presets[ $preset ]['url'] ) {
			$url = $presets[ $preset ]['url'];
		}
		$url = esc_url_raw( trim( (string) $url ), array( 'https', 'http' ) );
		if ( '' === $url ) {
			return new WP_Error( 'jatcs_caldav_invalid', __( 'Enter your CalDAV server’s address.', 'jat-calendar-sync' ) );
		}
		$client = new JATCS_CalDAV( $url, (string) $username, (string) $password );
		$home   = $client->discover();
		if ( is_wp_error( $home ) ) {
			return $home;
		}
		$cals = $client->calendars( $home );
		if ( is_wp_error( $cals ) ) {
			return $cals;
		}
		update_option(
			self::OPTION,
			array(
				'preset'       => $preset,
				'url'          => $url,
				'username'     => sanitize_text_field( $username ),
				'password'     => JATCS_Crypto::encrypt( (string) $password ),
				'home'         => (string) $home,
				'connected_at' => time(),
			),
			false
		);
		set_transient( self::CALENDARS_KEY, self::clean_calendars( $cals ), 10 * MINUTE_IN_SECONDS );
		JATCS_Availability::bust_cache();
		return $cals;
	}

	/**
	 * Forget the account.
	 */
	public static function disconnect() {
		delete_option( self::OPTION );
		delete_transient( self::CALENDARS_KEY );
		JATCS_Availability::bust_cache();
	}

	/**
	 * Only calendars that hold events.
	 *
	 * @param array $cals Calendars from the client.
	 * @return array
	 */
	private static function clean_calendars( array $cals ) {
		return array_values(
			array_filter(
				$cals,
				static function ( $c ) {
					return ! isset( $c['events'] ) || ! empty( $c['events'] );
				}
			)
		);
	}

	/**
	 * Calendars in the account.
	 *
	 * @param bool $refresh Skip cache.
	 * @return array|WP_Error
	 */
	public static function calendars( $refresh = false ) {
		$cached = get_transient( self::CALENDARS_KEY );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$a    = self::account();
		$cals = $client->calendars( (string) $a['home'] );
		if ( is_wp_error( $cals ) ) {
			return $cals;
		}
		$cals = self::clean_calendars( $cals );
		set_transient( self::CALENDARS_KEY, $cals, 10 * MINUTE_IN_SECONDS );
		return $cals;
	}

	/**
	 * Busy times per calendar. Fails if any can't be read.
	 *
	 * @param int      $from Unix start.
	 * @param int      $to   Unix end.
	 * @param string[] $urls Calendar URLs.
	 * @return array<string,array<int,array{0:int,1:int}>>|WP_Error
	 */
	public static function busy_map( $from, $to, array $urls ) {
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$map = array();
		foreach ( $urls as $url ) {
			$events = $client->events_in_range( $url, $from, $to );
			if ( is_wp_error( $events ) ) {
				return $events;
			}
			$ranges = array();
			foreach ( $events as $event ) {
				$busy = JATCS_ICal::busy( (string) $event['data'], $from, $to, wp_timezone() );
				if ( false === $busy ) {
					return new WP_Error( 'jatcs_caldav_parse', __( 'An event in your CalDAV calendar couldn’t be read.', 'jat-calendar-sync' ) );
				}
				foreach ( $busy as $r ) {
					$ranges[] = $r;
				}
			}
			$map[ $url ] = $ranges;
		}
		return $map;
	}

	/**
	 * End of the last upcoming event in a calendar.
	 *
	 * @param string $url        Calendar URL.
	 * @param int    $days_ahead How far to look.
	 * @return int|WP_Error
	 */
	public static function last_event_end( $url, $days_ahead ) {
		$map = self::busy_map( time(), time() + max( 1, (int) $days_ahead ) * DAY_IN_SECONDS, array( $url ) );
		if ( is_wp_error( $map ) ) {
			return $map;
		}
		$latest = 0;
		foreach ( $map[ $url ] as $r ) {
			$latest = max( $latest, (int) $r[1] );
		}
		return $latest;
	}

	/**
	 * Add a booking event. Safe to repeat: the event's file name comes from its UID.
	 *
	 * @param string $url   Calendar URL.
	 * @param array  $event Neutral event (see JATCS_Calendars::create_event()).
	 * @return array|WP_Error {id, link, video}
	 */
	public static function create_event( $url, array $event ) {
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$ics = JATCS_ICal::build_event(
			array(
				'uid'         => (string) $event['uid'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'summary'     => (string) $event['summary'],
				'description' => (string) $event['description'],
				'location'    => (string) $event['location'],
				'start'       => (int) $event['start'],
				'end'         => (int) $event['end'],
				'timezone'    => JATCS_Calendars::timezone(),
				'url'         => isset( $event['url'] ) ? (string) $event['url'] : '',
				'sequence'    => 0,
				'status'      => 'CONFIRMED',
			)
		);
		$put = $client->put_event( $url, (string) $event['uid'], $ics );
		if ( is_wp_error( $put ) ) {
			if ( 'jatcs_caldav_exists' === $put->get_error_code() ) {
				$data = $put->get_error_data();
				return array(
					'id'    => is_array( $data ) && ! empty( $data['href'] ) ? (string) $data['href'] : '',
					'link'  => '',
					'video' => '',
				);
			}
			return $put;
		}
		return array(
			'id'    => (string) $put['href'],
			'link'  => '',
			'video' => '',
		);
	}

	/**
	 * Move a booking event: rewrite its start and end, keep everything else.
	 *
	 * @param string $url   Calendar URL.
	 * @param string $href  Event URL.
	 * @param int    $start Unix start.
	 * @param int    $end   Unix end.
	 * @return true|WP_Error
	 */
	public static function move_event( $url, $href, $start, $end ) {
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$current = $client->get_event( $href );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$ics = self::retime( (string) $current['data'], (int) $start, (int) $end );
		if ( '' === $ics ) {
			return new WP_Error( 'jatcs_caldav_parse', __( 'The event in your CalDAV calendar couldn’t be updated.', 'jat-calendar-sync' ) );
		}
		$put = $client->update_event( $href, $ics, (string) $current['etag'] );
		return is_wp_error( $put ) ? $put : true;
	}

	/**
	 * Change an event's times in iCalendar text (the first VEVENT, i.e. ours: not recurring).
	 *
	 * @param string $ics   iCalendar text.
	 * @param int    $start Unix start.
	 * @param int    $end   Unix end.
	 * @return string '' if the text has no event.
	 */
	public static function retime( $ics, $start, $end ) {
		$ics   = str_replace( array( "\r\n", "\r" ), "\n", (string) $ics );
		$ics   = preg_replace( "/\n[ \t]/", '', $ics ); // Unfold.
		$lines = explode( "\n", $ics );
		$out   = array();
		$depth = 0;     // Nesting inside our VEVENT (VALARM etc.): their DURATION/TRIGGER stay untouched.
		$in    = false;
		$seen  = false;
		$stamp = gmdate( 'Ymd\THis\Z' );
		foreach ( $lines as $line ) {
			$upper = strtoupper( trim( $line ) );
			if ( ! $in && ! $seen && 'BEGIN:VEVENT' === $upper ) {
				$in    = true;
				$seen  = true;
				$out[] = 'BEGIN:VEVENT';
				continue;
			}
			if ( $in && 0 === $depth && 'END:VEVENT' === $upper ) {
				$in    = false;
				$out[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', $start );
				$out[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', $end );
				$out[] = 'DTSTAMP:' . $stamp;
				$out[] = 'END:VEVENT';
				continue;
			}
			if ( $in && 0 === strpos( $upper, 'BEGIN:' ) ) {
				++$depth;
			} elseif ( $in && 0 === strpos( $upper, 'END:' ) && $depth > 0 ) {
				--$depth;
			} elseif ( $in && 0 === $depth ) {
				$name = strtoupper( (string) strtok( $line, ':;' ) );
				if ( in_array( $name, array( 'DTSTART', 'DTEND', 'DURATION', 'DTSTAMP' ), true ) ) {
					continue;
				}
				if ( 'SEQUENCE' === $name ) {
					$out[] = 'SEQUENCE:' . ( (int) substr( $line, strrpos( $line, ':' ) + 1 ) + 1 );
					continue;
				}
			}
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $seen ? implode( "\r\n", $out ) . "\r\n" : '';
	}

	/**
	 * Remove a booking event (already gone counts as done).
	 *
	 * @param string $href Event URL.
	 * @return true|WP_Error
	 */
	public static function delete_event( $href ) {
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->delete_event( $href );
	}

	/**
	 * What happened to a booking event (moved, cancelled, deleted)?
	 *
	 * @param string $href Event URL.
	 * @return array|WP_Error {status, start, end, all_day}
	 */
	public static function event_state( $href ) {
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$event = $client->get_event( $href );
		if ( is_wp_error( $event ) ) {
			return 'jatcs_caldav_not_found' === $event->get_error_code() ? array( 'status' => 'missing' ) : $event;
		}
		$parsed = JATCS_ICal::parse( (string) $event['data'], wp_timezone() );
		if ( ! $parsed ) {
			return new WP_Error( 'jatcs_caldav_parse', 'Unreadable event.' );
		}
		$first = $parsed[0];
		return array(
			'status'  => 'CANCELLED' === $first['status'] ? 'cancelled' : 'confirmed',
			'start'   => (int) $first['start'],
			'end'     => (int) $first['end'],
			'all_day' => ! empty( $first['all_day'] ),
		);
	}
}
