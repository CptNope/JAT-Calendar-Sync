<?php
/**
 * iCal (ICS) feed calendars: Sling and other shift apps, iCloud shared calendars, Outlook's
 * published calendars, Google's secret address, Proton, Fastmail, Teamup, Airbnb and anything
 * else that offers a calendar subscription link. Read only: their events block times.
 *
 * Feed addresses are secrets (anyone with one can read the calendar), so they are stored
 * encrypted and never shown in full again.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscribed iCal feeds.
 */
final class JATCS_Feeds {

	const OPTION       = 'jatcs_feeds';
	const VERSION      = 'jatcs_feeds_version';
	const CACHE_PREFIX = 'jatcs_feed_cache_';
	const FRESH_FOR    = 600;   // Fetch again after 10 minutes.
	const STALE_FOR    = 21600; // If the feed can't be reached, use the last copy for up to 6 hours.
	const MAX_BYTES    = 5242880;
	const MAX_FEEDS    = 20;
	const RETRY_AFTER  = 300;   // After a failed fetch, wait 5 minutes before trying again.

	/**
	 * All feeds (addresses stay encrypted).
	 *
	 * @return array<string,array{name:string,url:string,block:bool,added:int}>
	 */
	public static function all() {
		$feeds = get_option( self::OPTION, array() );
		return is_array( $feeds ) ? $feeds : array();
	}

	/**
	 * Changes whenever feeds are added, removed or refreshed (for cache keys).
	 *
	 * @return int
	 */
	public static function version() {
		return (int) get_option( self::VERSION, 0 );
	}

	/**
	 * Bump the version.
	 */
	private static function bump() {
		update_option( self::VERSION, self::version() + 1, false );
		JATCS_Availability::bust_cache();
	}

	/**
	 * Normalize and check a feed address.
	 *
	 * @param string $url Address as pasted (webcal:// is accepted).
	 * @return string|WP_Error
	 */
	public static function clean_url( $url ) {
		$url = trim( (string) $url );
		$url = preg_replace( '#^webcals?://#i', 'https://', $url );
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		// Only the form is checked here; the download itself refuses local and private addresses (wp_safe_remote_get).
		$host = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
		if ( '' === $host || false === strpos( $host, '.' ) ) {
			return new WP_Error( 'jatcs_feed_url', __( 'That doesn’t look like a calendar feed address. It usually starts with https:// or webcal://.', 'jat-calendar-sync' ) );
		}
		return $url;
	}

	/**
	 * A short, non-secret version of an address for display (host and the end of the path).
	 *
	 * @param string $id Feed ID.
	 * @return string
	 */
	public static function display_url( $id ) {
		$feeds = self::all();
		if ( ! isset( $feeds[ $id ] ) ) {
			return '';
		}
		$url  = JATCS_Crypto::decrypt( $feeds[ $id ]['url'] );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return '' !== $host ? $host . '/…' : '';
	}

	/**
	 * Add a feed after checking it can be read.
	 *
	 * @param string $name  Name shown in settings.
	 * @param string $url   Address.
	 * @param bool   $block Its events block times.
	 * @return string|WP_Error New feed ID.
	 */
	public static function add( $name, $url, $block = true ) {
		$feeds = self::all();
		if ( count( $feeds ) >= self::MAX_FEEDS ) {
			return new WP_Error( 'jatcs_feed_limit', __( 'You can add up to 20 calendar feeds.', 'jat-calendar-sync' ) );
		}
		$url = self::clean_url( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$body = self::download( $url, array() );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( false === JATCS_ICal::parse( $body['body'] ) ) {
			return new WP_Error( 'jatcs_feed_parse', __( 'That address answered, but not with a calendar. Check you copied the calendar’s subscription (iCal / ICS) link.', 'jat-calendar-sync' ) );
		}
		$id           = 'f' . strtolower( wp_generate_password( 8, false ) );
		$feeds[ $id ] = array(
			'name'  => '' !== trim( (string) $name ) ? sanitize_text_field( $name ) : (string) wp_parse_url( $url, PHP_URL_HOST ),
			'url'   => JATCS_Crypto::encrypt( $url ),
			'block' => (bool) $block,
			'added' => time(),
		);
		update_option( self::OPTION, $feeds, false );
		self::store_cache( $id, $body );
		self::bump();
		return $id;
	}

	/**
	 * Change a feed's name or whether it blocks times.
	 *
	 * @param string $id    Feed ID.
	 * @param array  $props {name?, block?}.
	 */
	public static function update( $id, array $props ) {
		$feeds = self::all();
		if ( ! isset( $feeds[ $id ] ) ) {
			return;
		}
		if ( isset( $props['name'] ) && '' !== trim( (string) $props['name'] ) ) {
			$feeds[ $id ]['name'] = sanitize_text_field( $props['name'] );
		}
		if ( isset( $props['block'] ) ) {
			$feeds[ $id ]['block'] = (bool) $props['block'];
		}
		update_option( self::OPTION, $feeds, false );
		self::bump();
	}

	/**
	 * Remove a feed.
	 *
	 * @param string $id Feed ID.
	 */
	public static function remove( $id ) {
		$feeds = self::all();
		unset( $feeds[ $id ] );
		update_option( self::OPTION, $feeds, false );
		delete_option( self::CACHE_PREFIX . $id );
		self::bump();
	}

	/**
	 * Fetch the address (only public internet addresses; local network addresses are refused).
	 *
	 * @param string $url     Address.
	 * @param array  $headers Extra request headers (conditional GET).
	 * @return array|WP_Error {body, etag, modified, not_modified}
	 */
	private static function download( $url, array $headers ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => $headers + array( 'Accept' => 'text/calendar, */*;q=0.5' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new WP_Error( 'jatcs_feed_http', sprintf( __( 'The calendar feed couldn’t be reached: %s', 'jat-calendar-sync' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code ) {
			return array( 'not_modified' => true );
		}
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'jatcs_feed_http', sprintf( __( 'The calendar feed answered with an error (HTTP %d). The link may have expired or been reset.', 'jat-calendar-sync' ), $code ) );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) >= self::MAX_BYTES ) {
			// The download stopped at the size limit, so the rest of the calendar is missing.
			return new WP_Error( 'jatcs_feed_too_big', __( 'The calendar feed is larger than 5 MB, so it can’t be read completely. Use a feed with fewer past events (for example a calendar subscription instead of a full export).', 'jat-calendar-sync' ) );
		}
		return array(
			'body'         => $body,
			'etag'         => (string) wp_remote_retrieve_header( $response, 'etag' ),
			'modified'     => (string) wp_remote_retrieve_header( $response, 'last-modified' ),
			'not_modified' => false,
		);
	}

	/**
	 * Keep the last good copy.
	 *
	 * @param string $id   Feed ID.
	 * @param array  $body Download result.
	 */
	private static function store_cache( $id, array $body ) {
		update_option(
			self::CACHE_PREFIX . $id,
			array(
				'body'     => $body['body'],
				'etag'     => $body['etag'],
				'modified' => $body['modified'],
				'fetched'  => time(),
			),
			false
		);
	}

	/**
	 * A feed's calendar data, fresh enough to rely on.
	 *
	 * @param string $id    Feed ID.
	 * @param bool   $force Fetch even if the copy is fresh.
	 * @return string|WP_Error
	 */
	public static function data( $id, $force = false ) {
		$feeds = self::all();
		if ( ! isset( $feeds[ $id ] ) ) {
			return new WP_Error( 'jatcs_feed_missing', __( 'That calendar feed was removed.', 'jat-calendar-sync' ) );
		}
		$cache = get_option( self::CACHE_PREFIX . $id, array() );
		$age   = isset( $cache['fetched'] ) ? time() - (int) $cache['fetched'] : PHP_INT_MAX;
		if ( ! $force && $age < self::FRESH_FOR && isset( $cache['body'] ) ) {
			return (string) $cache['body'];
		}
		// Recently failed: don't make every visitor wait for an unreachable server.
		if ( ! $force && ! empty( $cache['failed_at'] ) && time() - (int) $cache['failed_at'] < self::RETRY_AFTER ) {
			if ( isset( $cache['body'] ) && $age < self::STALE_FOR ) {
				return (string) $cache['body'];
			}
			return new WP_Error( 'jatcs_feed_http', ! empty( $cache['error'] ) ? (string) $cache['error'] : __( 'The calendar feed couldn’t be read.', 'jat-calendar-sync' ) );
		}
		$headers = array();
		if ( ! empty( $cache['etag'] ) ) {
			$headers['If-None-Match'] = $cache['etag'];
		}
		if ( ! empty( $cache['modified'] ) ) {
			$headers['If-Modified-Since'] = $cache['modified'];
		}
		$got = self::download( JATCS_Crypto::decrypt( $feeds[ $id ]['url'] ), isset( $cache['body'] ) ? $headers : array() );
		if ( ! is_wp_error( $got ) && ! empty( $got['not_modified'] ) && isset( $cache['body'] ) ) {
			$cache['fetched'] = time();
			update_option( self::CACHE_PREFIX . $id, $cache, false );
			return (string) $cache['body'];
		}
		if ( ! is_wp_error( $got ) && empty( $got['not_modified'] ) ) {
			if ( ! isset( $cache['body'] ) || $cache['body'] !== $got['body'] ) {
				update_option( self::VERSION, self::version() + 1, false );
			}
			self::store_cache( $id, $got );
			return $got['body'];
		}
		if ( is_wp_error( $got ) ) {
			JATCS_Google::log( 'iCal feed ' . $feeds[ $id ]['name'], $got->get_error_message() );
			$cache              = is_array( $cache ) ? $cache : array();
			$cache['failed_at'] = time();
			$cache['error']     = $got->get_error_message();
			update_option( self::CACHE_PREFIX . $id, $cache, false );
		}
		if ( isset( $cache['body'] ) && $age < self::STALE_FOR ) {
			return (string) $cache['body'];
		}
		return is_wp_error( $got ) ? $got : new WP_Error( 'jatcs_feed_http', __( 'The calendar feed couldn’t be read.', 'jat-calendar-sync' ) );
	}

	/**
	 * Busy times per feed. Fails if any feed can't be read, so nobody books over a hidden event.
	 *
	 * @param int      $from Unix start.
	 * @param int      $to   Unix end.
	 * @param string[] $ids  Feed IDs.
	 * @return array<string,array<int,array{0:int,1:int}>>|WP_Error
	 */
	public static function busy_map( $from, $to, array $ids ) {
		$map = array();
		foreach ( $ids as $id ) {
			$data = self::data( $id );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$ranges = JATCS_ICal::busy( $data, $from, $to, wp_timezone() );
			if ( false === $ranges ) {
				/* translators: %s: feed name */
				return new WP_Error( 'jatcs_feed_parse', sprintf( __( 'The calendar feed “%s” couldn’t be read.', 'jat-calendar-sync' ), self::name( $id ) ) );
			}
			$map[ $id ] = $ranges;
		}
		return $map;
	}

	/**
	 * End of the last upcoming event in a feed.
	 *
	 * @param string $id         Feed ID.
	 * @param int    $days_ahead How far to look.
	 * @return int|WP_Error
	 */
	public static function last_event_end( $id, $days_ahead ) {
		$data = self::data( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		// Busy events only (like the other services): cancelled and "Free" events aren't shifts.
		$busy = JATCS_ICal::busy( $data, time(), time() + max( 1, (int) $days_ahead ) * DAY_IN_SECONDS, wp_timezone(), array( 'merge' => false ) );
		if ( false === $busy ) {
			return new WP_Error( 'jatcs_feed_parse', __( 'The calendar feed couldn’t be read.', 'jat-calendar-sync' ) );
		}
		$latest = 0;
		foreach ( $busy as $range ) {
			$latest = max( $latest, (int) $range[1] );
		}
		return $latest;
	}

	/**
	 * A feed's name.
	 *
	 * @param string $id Feed ID.
	 * @return string
	 */
	public static function name( $id ) {
		$feeds = self::all();
		return isset( $feeds[ $id ] ) ? (string) $feeds[ $id ]['name'] : (string) $id;
	}

	/**
	 * When a feed was last fetched (0 if never).
	 *
	 * @param string $id Feed ID.
	 * @return int
	 */
	public static function fetched_at( $id ) {
		$cache = get_option( self::CACHE_PREFIX . $id, array() );
		return isset( $cache['fetched'] ) ? (int) $cache['fetched'] : 0;
	}
}
