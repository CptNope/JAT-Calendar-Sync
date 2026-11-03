<?php
/**
 * CalDAV client (RFC 4791, RFC 6764 discovery) for JAT Calendar Sync.
 *
 * Moves raw iCalendar strings over HTTP and parses WebDAV XML. It never
 * parses event contents; that is the job of JATCS_ICal.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode properties (textContent, documentElement).

/**
 * Minimal, dependency-free CalDAV client.
 *
 * Errors are returned as WP_Error objects. Error codes:
 * jatcs_caldav_insecure, jatcs_caldav_invalid, jatcs_caldav_http,
 * jatcs_caldav_auth, jatcs_caldav_forbidden, jatcs_caldav_not_found,
 * jatcs_caldav_server (data: retryable), jatcs_caldav_not_caldav,
 * jatcs_caldav_xml, jatcs_caldav_exists (data: href),
 * jatcs_caldav_conflict.
 *
 * @package JATCalendarSync
 */
class JATCS_CalDAV {

	const NS_DAV    = 'DAV:';
	const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';
	const NS_CS     = 'http://calendarserver.org/ns/';
	const NS_ICAL   = 'http://apple.com/ns/ical/';

	const MAX_REDIRECTS = 3;
	const TIMEOUT       = 15;
	const USER_AGENT    = 'JAT-Calendar-Sync/1.0 (CalDAV)';

	/**
	 * Base URL entered by the site owner.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Lowercased host of the base URL.
	 *
	 * @var string
	 */
	private $base_host = '';

	/**
	 * Username.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * App-specific password. Never logged or included in errors.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * HTTP transport callable.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Error raised while validating the base URL, if any.
	 *
	 * @var WP_Error|null
	 */
	private $init_error = null;

	/**
	 * Cached calendar-home-set URL from discover().
	 *
	 * @var string|null
	 */
	private $home_url = null;

	/**
	 * Constructor.
	 *
	 * @param string        $base_url  Server or principal URL (https only, except localhost).
	 * @param string        $username  Account username.
	 * @param string        $password  App-specific password.
	 * @param callable|null $transport Optional transport: function( $method, $url, array $headers, $body )
	 *                                 returning array( 'code', 'headers', 'body' ) or array( 'error' => msg ).
	 */
	public function __construct( $base_url, $username, $password, $transport = null ) {
		$this->base_url = trim( (string) $base_url );
		$this->username = (string) $username;
		$this->password = (string) $password;

		if ( null !== $transport && is_callable( $transport ) ) {
			$this->transport = $transport;
		} else {
			$this->transport = function ( $method, $url, array $headers, $body ) {
				return self::wp_transport( $method, $url, $headers, $body );
			};
		}

		$check = $this->check_url( $this->base_url );
		if ( true !== $check ) {
			$this->init_error = $check;
			return;
		}
		$this->base_host = self::host_of( $this->base_url );
	}

	/**
	 * Provider presets for the settings screen.
	 *
	 * @return array Map of key => array( 'name', 'url', 'help' ).
	 */
	public static function presets() {
		return array(
			'icloud'      => array(
				'name' => 'iCloud',
				'url'  => 'https://caldav.icloud.com/',
				'help' => __( 'Use your Apple ID email and an app-specific password created at appleid.apple.com (Sign-In and Security > App-Specific Passwords).', 'jat-calendar-sync' ),
			),
			'fastmail'    => array(
				'name' => 'Fastmail',
				'url'  => 'https://caldav.fastmail.com/dav/',
				'help' => __( 'Use your Fastmail address and an app password with CalDAV access (Settings > Privacy & Security > App passwords).', 'jat-calendar-sync' ),
			),
			'nextcloud'   => array(
				'name' => 'Nextcloud',
				'url'  => 'https://YOUR-SERVER/remote.php/dav',
				'help' => __( 'Replace YOUR-SERVER with your Nextcloud address. Create an app password under Settings > Security.', 'jat-calendar-sync' ),
			),
			'zoho'        => array(
				'name' => 'Zoho Calendar',
				'url'  => 'https://calendar.zoho.com/caldav/',
				'help' => __( 'Use your Zoho email and an application-specific password from Zoho Accounts > Security.', 'jat-calendar-sync' ),
			),
			'yahoo'       => array(
				'name' => 'Yahoo Calendar',
				'url'  => 'https://caldav.calendar.yahoo.com/',
				'help' => __( 'Use your Yahoo email and an app password generated in Yahoo Account Security.', 'jat-calendar-sync' ),
			),
			'mailbox_org' => array(
				'name' => 'mailbox.org',
				'url'  => 'https://dav.mailbox.org/caldav/',
				'help' => __( 'Use your mailbox.org address and password (or an app password if two-factor authentication is on).', 'jat-calendar-sync' ),
			),
			'other'       => array(
				'name' => __( 'Other CalDAV server', 'jat-calendar-sync' ),
				'url'  => '',
				'help' => __( 'Enter your server URL (for example Radicale, Baikal, SOGo or Synology). The server root is enough if it supports /.well-known/caldav.', 'jat-calendar-sync' ),
			),
		);
	}

	/**
	 * Discover the calendar-home-set URL (RFC 6764 + RFC 4791 section 6.2.1).
	 *
	 * @return string|WP_Error Absolute calendar home URL.
	 */
	public function discover() {
		if ( $this->init_error ) {
			return $this->init_error;
		}
		if ( null !== $this->home_url ) {
			return $this->home_url;
		}

		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:prop><d:current-user-principal/><c:calendar-home-set/></d:prop>'
			. '</d:propfind>';

		$start = $this->base_url;
		$path  = (string) self::parse_url_part( $this->base_url, PHP_URL_PATH );
		$ms    = null;

		if ( '' === $path || '/' === $path ) {
			$wk  = self::origin_of( $this->base_url ) . '/.well-known/caldav';
			$res = $this->request( 'PROPFIND', $wk, array( 'Depth' => '0' ), $body );
			if ( self::is_error( $res ) ) {
				$err_data = $res->get_error_data();
				if ( 'jatcs_caldav_http' === $res->get_error_code() && ! empty( $err_data['retryable'] ) ) {
					// Network failure: the base URL would fail the same way.
					return $res;
				}
			} elseif ( 207 === $res['code'] ) {
				$parsed = $this->load_multistatus( $res['body'] );
				if ( ! self::is_error( $parsed ) ) {
					$ms    = $parsed;
					$start = $res['url'];
				}
			} elseif ( $res['url'] !== $wk ) {
				// Redirected but not answered (e.g. auth dropped across hosts): retry the target directly.
				$start = $res['url'];
			}
		}

		if ( null === $ms ) {
			$res = $this->request( 'PROPFIND', $start, array( 'Depth' => '0' ), $body );
			if ( self::is_error( $res ) ) {
				return $res;
			}
			if ( 207 !== $res['code'] ) {
				return $this->status_error( $res['code'], 'PROPFIND' );
			}
			$ms = $this->load_multistatus( $res['body'] );
			if ( self::is_error( $ms ) ) {
				return $ms;
			}
			$start = $res['url'];
		}

		$home      = $this->first_prop_href( $ms, 'c:calendar-home-set/d:href', $start );
		$principal = $this->first_prop_href( $ms, 'd:current-user-principal/d:href', $start );

		if ( '' === $home ) {
			if ( '' === $principal ) {
				return $this->error( 'jatcs_caldav_not_caldav', __( 'The server answered but did not report a CalDAV principal. Check the server URL.', 'jat-calendar-sync' ) );
			}
			$body = '<?xml version="1.0" encoding="utf-8"?>'
				. '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
				. '<d:prop><c:calendar-home-set/></d:prop>'
				. '</d:propfind>';
			$res  = $this->request( 'PROPFIND', $principal, array( 'Depth' => '0' ), $body );
			if ( self::is_error( $res ) ) {
				return $res;
			}
			if ( 207 !== $res['code'] ) {
				return $this->status_error( $res['code'], 'PROPFIND' );
			}
			$ms = $this->load_multistatus( $res['body'] );
			if ( self::is_error( $ms ) ) {
				return $ms;
			}
			$home = $this->first_prop_href( $ms, 'c:calendar-home-set/d:href', $res['url'] );
			if ( '' === $home ) {
				return $this->error( 'jatcs_caldav_not_caldav', __( 'The account has no calendar home. This server may not support CalDAV.', 'jat-calendar-sync' ) );
			}
		}

		$check = $this->check_url( $home );
		if ( true !== $check ) {
			return $check;
		}
		$this->home_url = self::trailing_slash( $home );
		return $this->home_url;
	}

	/**
	 * List calendars in a calendar home.
	 *
	 * @param string $home_url Calendar home URL from discover().
	 * @return array|WP_Error List of array( 'url', 'name', 'color', 'writable', 'events', 'ctag' ).
	 */
	public function calendars( $home_url ) {
		if ( $this->init_error ) {
			return $this->init_error;
		}
		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:ical="http://apple.com/ns/ical/">'
			. '<d:prop><d:resourcetype/><d:displayname/><d:current-user-privilege-set/>'
			. '<c:supported-calendar-component-set/><cs:getctag/><d:sync-token/><ical:calendar-color/></d:prop>'
			. '</d:propfind>';

		$res = $this->request( 'PROPFIND', (string) $home_url, array( 'Depth' => '1' ), $body );
		if ( self::is_error( $res ) ) {
			return $res;
		}
		if ( 207 !== $res['code'] ) {
			return $this->status_error( $res['code'], 'PROPFIND' );
		}
		$ms = $this->load_multistatus( $res['body'] );
		if ( self::is_error( $ms ) ) {
			return $ms;
		}

		$out = array();
		foreach ( $ms['xpath']->query( 'd:response', $ms['doc']->documentElement ) as $response ) {
			$href = $this->response_href( $ms['xpath'], $response, $res['url'] );
			if ( '' === $href ) {
				continue;
			}
			$rt = $this->find_prop( $ms['xpath'], $response, 'd:resourcetype' );
			if ( ! $rt ) {
				continue;
			}
			$x          = $ms['xpath'];
			$is_cal     = $x->query( 'c:calendar', $rt )->length > 0;
			$subscribed = $x->query( 'cs:subscribed', $rt )->length > 0;
			$special    = $x->query( 'c:schedule-inbox | c:schedule-outbox | cs:notification', $rt )->length > 0;
			if ( $special || ( ! $is_cal && ! $subscribed ) ) {
				continue;
			}

			$name_node = $this->find_prop( $x, $response, 'd:displayname' );
			$name      = $name_node ? trim( $name_node->textContent ) : '';
			if ( '' === $name ) {
				$segments = array_values( array_filter( explode( '/', (string) self::parse_url_part( $href, PHP_URL_PATH ) ), 'strlen' ) );
				$name     = $segments ? rawurldecode( end( $segments ) ) : $href;
			}

			$color      = '';
			$color_node = $this->find_prop( $x, $response, 'ical:calendar-color' );
			if ( $color_node && preg_match( '/^#[0-9a-f]{6}/i', trim( $color_node->textContent ), $m ) ) {
				$color = strtoupper( $m[0] );
			}

			$writable = true;
			$privs    = $this->find_prop( $x, $response, 'd:current-user-privilege-set' );
			if ( $privs ) {
				$writable = $x->query( './/d:privilege/d:write | .//d:privilege/d:write-content | .//d:privilege/d:all', $privs )->length > 0;
			}
			if ( $subscribed ) {
				$writable = false;
			}

			$events = true;
			$comps  = $this->find_prop( $x, $response, 'c:supported-calendar-component-set' );
			if ( $comps ) {
				$events = false;
				foreach ( $x->query( 'c:comp', $comps ) as $comp ) {
					if ( 'VEVENT' === strtoupper( $comp->getAttribute( 'name' ) ) ) {
						$events = true;
						break;
					}
				}
			}

			$ctag = '';
			foreach ( array( 'cs:getctag', 'd:sync-token' ) as $tag_prop ) {
				$tag_node = $this->find_prop( $x, $response, $tag_prop );
				if ( $tag_node && '' !== trim( $tag_node->textContent ) ) {
					$ctag = trim( $tag_node->textContent );
					break;
				}
			}

			$out[] = array(
				'url'      => $href,
				'name'     => $name,
				'color'    => $color,
				'writable' => $writable,
				'events'   => $events,
				'ctag'     => $ctag,
			);
		}
		return $out;
	}

	/**
	 * Fetch raw VEVENT resources overlapping a time range (no server-side expansion).
	 *
	 * @param string                       $calendar_url Calendar collection URL.
	 * @param int|string|DateTimeInterface $from         Range start (timestamp, date string or DateTime).
	 * @param int|string|DateTimeInterface $to           Range end (exclusive).
	 * @return array|WP_Error List of array( 'href', 'etag', 'data' ).
	 */
	public function events_in_range( $calendar_url, $from, $to ) {
		if ( $this->init_error ) {
			return $this->init_error;
		}
		$start = self::to_timestamp( $from );
		$end   = self::to_timestamp( $to );
		if ( null === $start || null === $end || $end <= $start ) {
			return $this->error( 'jatcs_caldav_invalid', __( 'Invalid time range.', 'jat-calendar-sync' ) );
		}

		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
			. '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
			. '<c:time-range start="' . gmdate( 'Ymd\THis\Z', $start ) . '" end="' . gmdate( 'Ymd\THis\Z', $end ) . '"/>'
			. '</c:comp-filter></c:comp-filter></c:filter>'
			. '</c:calendar-query>';

		$res = $this->request( 'REPORT', (string) $calendar_url, array( 'Depth' => '1' ), $body );
		if ( self::is_error( $res ) ) {
			return $res;
		}
		if ( 207 !== $res['code'] && 200 !== $res['code'] ) {
			return $this->status_error( $res['code'], 'REPORT' );
		}
		$ms = $this->load_multistatus( $res['body'] );
		if ( self::is_error( $ms ) ) {
			return $ms;
		}

		$out = array();
		$x   = $ms['xpath'];
		foreach ( $x->query( 'd:response', $ms['doc']->documentElement ) as $response ) {
			$status = $x->query( 'd:status', $response );
			if ( $status->length ) {
				$code = self::parse_status( $status->item( 0 )->textContent );
				if ( $code && ( $code < 200 || $code > 299 ) ) {
					continue;
				}
			}
			$data_node = $this->find_prop( $x, $response, 'c:calendar-data' );
			if ( ! $data_node ) {
				continue;
			}
			$data = $data_node->textContent;
			if ( '' === trim( $data ) ) {
				continue;
			}
			$etag_node = $this->find_prop( $x, $response, 'd:getetag' );
			$out[]     = array(
				'href' => $this->response_href( $x, $response, $res['url'] ),
				'etag' => $etag_node ? trim( $etag_node->textContent ) : '',
				'data' => $data,
			);
		}
		return $out;
	}

	/**
	 * Fetch a single event resource.
	 *
	 * @param string $href Absolute event URL.
	 * @return array|WP_Error array( 'etag', 'data' ).
	 */
	public function get_event( $href ) {
		if ( $this->init_error ) {
			return $this->init_error;
		}
		$res = $this->request( 'GET', (string) $href, array( 'Accept' => 'text/calendar' ), '' );
		if ( self::is_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] ) {
			return $this->status_error( $res['code'], 'GET' );
		}
		return array(
			'etag' => isset( $res['headers']['etag'] ) ? trim( $res['headers']['etag'] ) : '',
			'data' => (string) $res['body'],
		);
	}

	/**
	 * Replace an existing event at its stored address.
	 *
	 * With an ETag the write only succeeds if nobody changed the event since it was read;
	 * servers that don't send ETags get an unconditional write (never a create-only one).
	 *
	 * @param string $href Event URL.
	 * @param string $ics  iCalendar data.
	 * @param string $etag ETag from the last read ('' if the server sent none).
	 * @return array|WP_Error [ 'href', 'etag' ].
	 */
	public function update_event( $href, $ics, $etag = '' ) {
		if ( $this->init_error ) {
			return $this->init_error;
		}
		$headers = array( 'Content-Type' => 'text/calendar; charset=utf-8' );
		if ( '' !== (string) $etag ) {
			$headers['If-Match'] = (string) $etag;
		}
		$res = $this->request( 'PUT', (string) $href, $headers, (string) $ics );
		if ( self::is_error( $res ) ) {
			return $res;
		}
		if ( 412 === $res['code'] ) {
			return $this->error(
				'jatcs_caldav_conflict',
				__( 'The event was changed on the calendar since it was last read.', 'jat-calendar-sync' ),
				array(
					'href'   => $href,
					'status' => 412,
				)
			);
		}
		if ( $res['code'] < 200 || $res['code'] > 299 ) {
			return $this->status_error( $res['code'], 'PUT' );
		}
		return array(
			'href' => (string) $href,
			'etag' => isset( $res['headers']['etag'] ) ? trim( $res['headers']['etag'] ) : '',
		);
	}

	/**
	 * Create or update an event.
	 *
	 * Without $etag: create with If-None-Match: * (412 -> jatcs_caldav_exists, data['href']).
	 * With $etag: update with If-Match (412 -> jatcs_caldav_conflict).
	 *
	 * @param string      $calendar_url Calendar collection URL.
	 * @param string      $uid          Event UID (used to build the resource name).
	 * @param string      $ics          Raw iCalendar (VCALENDAR) string.
	 * @param string|null $etag         ETag of the version being replaced, or null to create.
	 * @return array|WP_Error array( 'href', 'etag' ); etag may be '' if the server withholds it.
	 */
	public function put_event( $calendar_url, $uid, $ics, $etag = null ) {
		if ( $this->init_error ) {
			return $this->init_error;
		}
		$name = self::resource_name( $uid );
		if ( '' === $name || '' === trim( (string) $ics ) ) {
			return $this->error( 'jatcs_caldav_invalid', __( 'An event UID and iCalendar data are required.', 'jat-calendar-sync' ) );
		}
		$href    = self::trailing_slash( (string) $calendar_url ) . $name;
		$headers = array( 'Content-Type' => 'text/calendar; charset=utf-8' );
		$create  = ( null === $etag || '' === $etag );
		if ( $create ) {
			$headers['If-None-Match'] = '*';
		} else {
			$headers['If-Match'] = (string) $etag;
		}

		$res = $this->request( 'PUT', $href, $headers, (string) $ics );
		if ( self::is_error( $res ) ) {
			return $res;
		}
		if ( 412 === $res['code'] ) {
			if ( $create ) {
				return $this->error(
					'jatcs_caldav_exists',
					__( 'An event with this UID already exists on the calendar.', 'jat-calendar-sync' ),
					array(
						'href'   => $href,
						'status' => 412,
					)
				);
			}
			return $this->error(
				'jatcs_caldav_conflict',
				__( 'The event was changed on the calendar since it was last read.', 'jat-calendar-sync' ),
				array(
					'href'   => $href,
					'status' => 412,
				)
			);
		}
		if ( $res['code'] < 200 || $res['code'] > 299 ) {
			return $this->status_error( $res['code'], 'PUT' );
		}

		$new_etag = isset( $res['headers']['etag'] ) ? trim( $res['headers']['etag'] ) : '';
		if ( '' === $new_etag ) {
			$got = $this->get_event( $href );
			if ( ! self::is_error( $got ) ) {
				$new_etag = $got['etag'];
			}
		}
		return array(
			'href' => $href,
			'etag' => $new_etag,
		);
	}

	/**
	 * Delete an event. A missing event (404/410) counts as success.
	 *
	 * @param string      $href Absolute event URL.
	 * @param string|null $etag Optional ETag for If-Match (412 -> jatcs_caldav_conflict).
	 * @return true|WP_Error
	 */
	public function delete_event( $href, $etag = null ) {
		if ( $this->init_error ) {
			return $this->init_error;
		}
		$headers = array();
		if ( null !== $etag && '' !== $etag ) {
			$headers['If-Match'] = (string) $etag;
		}
		$res = $this->request( 'DELETE', (string) $href, $headers, '' );
		if ( self::is_error( $res ) ) {
			return $res;
		}
		if ( ( $res['code'] >= 200 && $res['code'] <= 299 ) || 404 === $res['code'] || 410 === $res['code'] ) {
			return true;
		}
		if ( 412 === $res['code'] ) {
			return $this->error(
				'jatcs_caldav_conflict',
				__( 'The event was changed on the calendar since it was last read.', 'jat-calendar-sync' ),
				array(
					'href'   => $href,
					'status' => 412,
				)
			);
		}
		return $this->status_error( $res['code'], 'DELETE' );
	}

	/**
	 * Connection test: discovery plus calendar listing.
	 *
	 * @return array|WP_Error Calendars list (see calendars()) or an error with a human-friendly message.
	 */
	public function test() {
		$home = $this->discover();
		if ( self::is_error( $home ) ) {
			return $home;
		}
		return $this->calendars( $home );
	}

	/* ------------------------------------------------------------------ *
	 * HTTP
	 * ------------------------------------------------------------------ */

	/**
	 * Perform a request, following up to MAX_REDIRECTS redirects manually.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Absolute URL.
	 * @param array  $headers Extra request headers.
	 * @param string $body    Request body.
	 * @return array|WP_Error array( 'code', 'headers', 'body', 'url' ) where url is the final URL.
	 */
	private function request( $method, $url, array $headers, $body ) {
		$check = $this->check_url( $url );
		if ( true !== $check ) {
			return $check;
		}
		$send_auth = $this->is_trusted_host( self::host_of( $url ) );
		$redirects = 0;

		while ( true ) {
			$send = array( 'User-Agent' => self::USER_AGENT );
			if ( '' !== $body ) {
				if ( ! isset( $headers['Content-Type'] ) ) {
					$send['Content-Type'] = 'application/xml; charset=utf-8';
				}
			}
			$send = array_merge( $send, $headers );
			if ( $send_auth ) {
				$send['Authorization'] = 'Basic ' . base64_encode( $this->username . ':' . $this->password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}

			$raw = call_user_func( $this->transport, $method, $url, $send, $body );
			if ( ! is_array( $raw ) ) {
				return $this->error( 'jatcs_caldav_http', __( 'Could not connect to the calendar server.', 'jat-calendar-sync' ) );
			}
			if ( isset( $raw['error'] ) ) {
				return $this->error(
					'jatcs_caldav_http',
					__( 'Could not connect to the calendar server:', 'jat-calendar-sync' ) . ' ' . $this->scrub( (string) $raw['error'] ),
					array( 'retryable' => true )
				);
			}

			$code     = isset( $raw['code'] ) ? (int) $raw['code'] : 0;
			$res_head = array();
			if ( isset( $raw['headers'] ) && ( is_array( $raw['headers'] ) || $raw['headers'] instanceof Traversable ) ) {
				foreach ( $raw['headers'] as $key => $value ) {
					$res_head[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
				}
			}
			$res_body = isset( $raw['body'] ) ? (string) $raw['body'] : '';

			if ( in_array( $code, array( 301, 302, 307, 308 ), true ) && ! empty( $res_head['location'] ) ) {
				++$redirects;
				if ( $redirects > self::MAX_REDIRECTS ) {
					return $this->error( 'jatcs_caldav_http', __( 'The calendar server sent too many redirects.', 'jat-calendar-sync' ) );
				}
				$next  = self::resolve_url( $url, trim( $res_head['location'] ) );
				$check = $this->check_url( $next );
				if ( true !== $check ) {
					return $this->error( 'jatcs_caldav_insecure', __( 'The calendar server redirected to an insecure (non-HTTPS) address. The redirect was refused.', 'jat-calendar-sync' ) );
				}
				if ( self::host_of( $next ) !== self::host_of( $url ) ) {
					$send_auth = false;
				}
				$url = $next;
				continue;
			}

			return array(
				'code'    => $code,
				'headers' => $res_head,
				'body'    => $res_body,
				'url'     => $url,
			);
		}
	}

	/**
	 * Default transport using the WordPress HTTP API.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     URL.
	 * @param array  $headers Request headers.
	 * @param string $body    Request body.
	 * @return array array( 'code', 'headers', 'body' ) or array( 'error' ).
	 */
	private static function wp_transport( $method, $url, array $headers, $body ) {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return array( 'error' => 'WordPress HTTP API is not available.' );
		}
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => self::USER_AGENT,
		);
		if ( '' !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		$out_headers = array();
		$raw_headers = wp_remote_retrieve_headers( $response );
		if ( is_array( $raw_headers ) || $raw_headers instanceof Traversable ) {
			foreach ( $raw_headers as $key => $value ) {
				$out_headers[ strtolower( (string) $key ) ] = $value;
			}
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $out_headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Map an unexpected HTTP status to a WP_Error.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $context Method name for the message.
	 * @return WP_Error
	 */
	private function status_error( $code, $context ) {
		$data = array( 'status' => (int) $code );
		if ( 401 === $code ) {
			return $this->error( 'jatcs_caldav_auth', __( 'Username or app-specific password was rejected by the calendar server.', 'jat-calendar-sync' ), $data );
		}
		if ( 403 === $code ) {
			return $this->error( 'jatcs_caldav_forbidden', __( 'The calendar server refused access (403). Check that this account may use this calendar.', 'jat-calendar-sync' ), $data );
		}
		if ( 404 === $code || 410 === $code ) {
			return $this->error( 'jatcs_caldav_not_found', __( 'The calendar resource was not found.', 'jat-calendar-sync' ), $data );
		}
		if ( 405 === $code || 501 === $code || ( $code >= 200 && $code < 300 ) ) {
			return $this->error( 'jatcs_caldav_not_caldav', __( 'This URL does not appear to be a CalDAV server. Check the server URL.', 'jat-calendar-sync' ), $data );
		}
		if ( 429 === $code || $code >= 500 ) {
			$data['retryable'] = true;
			return $this->error( 'jatcs_caldav_server', __( 'The calendar server is temporarily unavailable. Please try again later.', 'jat-calendar-sync' ) . ' (HTTP ' . (int) $code . ')', $data );
		}
		return $this->error( 'jatcs_caldav_http', __( 'Unexpected response from the calendar server.', 'jat-calendar-sync' ) . ' (' . $context . ' HTTP ' . (int) $code . ')', $data );
	}

	/* ------------------------------------------------------------------ *
	 * XML
	 * ------------------------------------------------------------------ */

	/**
	 * Safely parse a DAV:multistatus document.
	 *
	 * DTDs are refused outright, external entity loading is disabled and
	 * network access is blocked (LIBXML_NONET). LIBXML_NOENT is never used.
	 *
	 * @param string $body Response body.
	 * @return array|WP_Error array( 'doc' => DOMDocument, 'xpath' => DOMXPath ).
	 */
	private function load_multistatus( $body ) {
		$body = (string) $body;
		if ( '' === trim( $body ) ) {
			return $this->error( 'jatcs_caldav_not_caldav', __( 'The calendar server returned an empty response. This URL may not be a CalDAV server.', 'jat-calendar-sync' ) );
		}

		$prev_errors = libxml_use_internal_errors( true );
		$prev_entity = null;
		if ( PHP_VERSION_ID < 80000 ) {
			$prev_entity = libxml_disable_entity_loader( true ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated, PHPCompatibility.FunctionUse.RemovedFunctions -- PHP < 8 only, where external entities are on by default.
		}
		$prev_loader = function_exists( 'libxml_get_external_entity_loader' ) ? libxml_get_external_entity_loader() : null;
		libxml_set_external_entity_loader(
			static function () {
				return null;
			}
		);

		$doc = new DOMDocument();
		$ok  = $doc->loadXML( $body, LIBXML_NONET );

		libxml_set_external_entity_loader( $prev_loader );
		if ( PHP_VERSION_ID < 80000 ) {
			libxml_disable_entity_loader( $prev_entity ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated, PHPCompatibility.FunctionUse.RemovedFunctions -- PHP < 8 only.
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev_errors );

		if ( ! $ok || ! $doc->documentElement ) {
			return $this->error( 'jatcs_caldav_not_caldav', __( 'The calendar server returned a response that is not valid XML. This URL may not be a CalDAV server.', 'jat-calendar-sync' ) );
		}
		if ( null !== $doc->doctype ) {
			return $this->error( 'jatcs_caldav_xml', __( 'The calendar server response contained a DTD, which is not allowed.', 'jat-calendar-sync' ) );
		}
		if ( 'multistatus' !== $doc->documentElement->localName || self::NS_DAV !== $doc->documentElement->namespaceURI ) {
			return $this->error( 'jatcs_caldav_not_caldav', __( 'This URL does not appear to be a CalDAV server. Check the server URL.', 'jat-calendar-sync' ) );
		}

		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'd', self::NS_DAV );
		$xpath->registerNamespace( 'c', self::NS_CALDAV );
		$xpath->registerNamespace( 'cs', self::NS_CS );
		$xpath->registerNamespace( 'ical', self::NS_ICAL );

		return array(
			'doc'   => $doc,
			'xpath' => $xpath,
		);
	}

	/**
	 * Find a property node inside the 2xx propstats of a response.
	 *
	 * @param DOMXPath $xpath    XPath with registered prefixes.
	 * @param DOMNode  $response d:response element.
	 * @param string   $expr     Relative XPath under d:prop (e.g. 'd:displayname').
	 * @return DOMNode|null
	 */
	private function find_prop( DOMXPath $xpath, DOMNode $response, $expr ) {
		foreach ( $xpath->query( 'd:propstat', $response ) as $propstat ) {
			$status = $xpath->query( 'd:status', $propstat );
			if ( $status->length ) {
				$code = self::parse_status( $status->item( 0 )->textContent );
				if ( $code && ( $code < 200 || $code > 299 ) ) {
					continue;
				}
			}
			$found = $xpath->query( 'd:prop/' . $expr, $propstat );
			if ( $found && $found->length ) {
				return $found->item( 0 );
			}
		}
		return null;
	}

	/**
	 * First href of a property across all responses, resolved to absolute.
	 *
	 * @param array  $ms   Parsed multistatus.
	 * @param string $expr Relative XPath under d:prop ending in d:href.
	 * @param string $base URL the request was made to.
	 * @return string Absolute URL or ''.
	 */
	private function first_prop_href( array $ms, $expr, $base ) {
		foreach ( $ms['xpath']->query( 'd:response', $ms['doc']->documentElement ) as $response ) {
			$node = $this->find_prop( $ms['xpath'], $response, $expr );
			if ( $node && '' !== trim( $node->textContent ) ) {
				return self::resolve_url( $base, trim( $node->textContent ) );
			}
		}
		return '';
	}

	/**
	 * Absolute href of a d:response.
	 *
	 * @param DOMXPath $xpath    XPath.
	 * @param DOMNode  $response d:response element.
	 * @param string   $base     Request URL.
	 * @return string
	 */
	private function response_href( DOMXPath $xpath, DOMNode $response, $base ) {
		$href = $xpath->query( 'd:href', $response );
		if ( ! $href->length || '' === trim( $href->item( 0 )->textContent ) ) {
			return '';
		}
		return self::resolve_url( $base, trim( $href->item( 0 )->textContent ) );
	}

	/**
	 * Parse an HTTP status line such as "HTTP/1.1 404 Not Found".
	 *
	 * @param string $line Status line.
	 * @return int Status code or 0.
	 */
	private static function parse_status( $line ) {
		if ( preg_match( '#\b([1-5]\d\d)\b#', (string) $line, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/* ------------------------------------------------------------------ *
	 * URL helpers and security
	 * ------------------------------------------------------------------ */

	/**
	 * Validate that a URL is absolute and HTTPS (HTTP allowed only for localhost).
	 *
	 * @param string $url URL.
	 * @return true|WP_Error
	 */
	private function check_url( $url ) {
		$parts = self::parse_url_part( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $this->error( 'jatcs_caldav_invalid', __( 'Please enter a full calendar server URL starting with https://.', 'jat-calendar-sync' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $this->error( 'jatcs_caldav_invalid', __( 'Do not put the username or password in the server URL.', 'jat-calendar-sync' ) );
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( trim( $parts['host'], '[]' ) );
		if ( 'https' === $scheme ) {
			return true;
		}
		if ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		return $this->error( 'jatcs_caldav_insecure', __( 'The calendar server URL must use https:// so your password is not sent unencrypted.', 'jat-calendar-sync' ) );
	}

	/**
	 * Whether credentials may be sent to a host: exact match or same site as the base host.
	 *
	 * @param string $host Lowercased host.
	 * @return bool
	 */
	private function is_trusted_host( $host ) {
		if ( '' === $host || '' === $this->base_host ) {
			return false;
		}
		if ( $host === $this->base_host ) {
			return true;
		}
		return self::same_site( $host, $this->base_host );
	}

	/**
	 * Simple same-site check: same registrable domain (last two labels, or three for co.uk-style suffixes).
	 *
	 * @param string $a Host A.
	 * @param string $b Host B.
	 * @return bool
	 */
	private static function same_site( $a, $b ) {
		if ( filter_var( $a, FILTER_VALIDATE_IP ) || filter_var( $b, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$site_a = self::registrable( $a );
		return '' !== $site_a && self::registrable( $b ) === $site_a;
	}

	/**
	 * Approximate registrable domain of a host.
	 *
	 * @param string $host Host name.
	 * @return string Registrable domain or '' for single-label hosts.
	 */
	private static function registrable( $host ) {
		$labels = explode( '.', rtrim( strtolower( $host ), '.' ) );
		$count  = count( $labels );
		if ( $count < 2 ) {
			return '';
		}
		$take = 2;
		$sld  = $labels[ $count - 2 ];
		if ( $count >= 3 && 2 === strlen( $labels[ $count - 1 ] ) && in_array( $sld, array( 'co', 'com', 'net', 'org', 'gov', 'ac', 'edu', 'ne', 'or', 'gv' ), true ) ) {
			$take = 3;
		}
		if ( $count < $take ) {
			return '';
		}
		return implode( '.', array_slice( $labels, -$take ) );
	}

	/**
	 * Lowercased host of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_of( $url ) {
		$host = self::parse_url_part( (string) $url, PHP_URL_HOST );
		return $host ? strtolower( trim( $host, '[]' ) ) : '';
	}

	/**
	 * Scheme, host and port of a URL.
	 *
	 * @param string $url URL.
	 * @return string e.g. https://example.com:8443
	 */
	private static function origin_of( $url ) {
		$p      = self::parse_url_part( (string) $url );
		$origin = strtolower( $p['scheme'] ) . '://' . $p['host'];
		if ( ! empty( $p['port'] ) ) {
			$origin .= ':' . (int) $p['port'];
		}
		return $origin;
	}

	/**
	 * Resolve a (possibly relative) reference against a base URL.
	 *
	 * @param string $base Absolute base URL.
	 * @param string $ref  Reference (absolute URL, //host/path, /path or relative path).
	 * @return string
	 */
	private static function resolve_url( $base, $ref ) {
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $ref ) ) {
			return $ref;
		}
		$p = self::parse_url_part( $base );
		if ( 0 === strpos( $ref, '//' ) ) {
			return strtolower( $p['scheme'] ) . ':' . $ref;
		}
		$origin = self::origin_of( $base );
		if ( 0 === strpos( $ref, '/' ) ) {
			return $origin . self::remove_dots( $ref );
		}
		$dir = isset( $p['path'] ) ? $p['path'] : '/';
		$dir = substr( $dir, 0, (int) strrpos( $dir, '/' ) + 1 );
		if ( '' === $dir ) {
			$dir = '/';
		}
		return $origin . self::remove_dots( $dir . $ref );
	}

	/**
	 * Remove "." and ".." path segments.
	 *
	 * @param string $path Path, possibly with a query string.
	 * @return string
	 */
	private static function remove_dots( $path ) {
		$query = '';
		$qpos  = strpos( $path, '?' );
		if ( false !== $qpos ) {
			$query = substr( $path, $qpos );
			$path  = substr( $path, 0, $qpos );
		}
		if ( false === strpos( $path, '.' ) ) {
			return $path . $query;
		}
		$out = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				if ( count( $out ) > 1 ) {
					array_pop( $out );
				}
				continue;
			}
			$out[] = $seg;
		}
		$result = implode( '/', $out );
		if ( preg_match( '#/\.\.?$#', $path ) ) {
			$result .= '/';
		}
		return ( '' === $result ? '/' : $result ) . $query;
	}

	/**
	 * Ensure a URL ends with a slash.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function trailing_slash( $url ) {
		return rtrim( $url, '/' ) . '/';
	}

	/**
	 * Build a safe resource name ("<uid>.ics") from an event UID.
	 *
	 * @param string $uid Event UID.
	 * @return string Resource name or '' when the UID is empty.
	 */
	private static function resource_name( $uid ) {
		$uid = trim( (string) $uid );
		if ( '' === $uid ) {
			return '';
		}
		if ( strlen( $uid ) > 200 ) {
			return sha1( $uid ) . '.ics';
		}
		if ( preg_match( '/^[A-Za-z0-9._@-]+$/', $uid ) && '.' !== $uid[0] ) {
			return $uid . '.ics';
		}
		return rawurlencode( $uid ) . '.ics';
	}

	/**
	 * Convert a time value to a UNIX timestamp.
	 *
	 * @param int|string|DateTimeInterface $value Time value.
	 * @return int|null
	 */
	private static function to_timestamp( $value ) {
		if ( $value instanceof DateTimeInterface ) {
			return $value->getTimestamp();
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return (int) $value;
		}
		if ( is_string( $value ) && '' !== $value ) {
			$ts = strtotime( $value );
			return false === $ts ? null : $ts;
		}
		return null;
	}

	/* ------------------------------------------------------------------ *
	 * Errors
	 * ------------------------------------------------------------------ */

	/**
	 * Remove credentials from a message.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function scrub( $message ) {
		if ( '' !== $this->password ) {
			$message = str_replace(
				array( $this->password, rawurlencode( $this->password ), base64_encode( $this->username . ':' . $this->password ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'********',
				$message
			);
		}
		return $message;
	}

	/**
	 * Build an error object.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message (never contains the password).
	 * @param array  $data    Extra data.
	 * @return WP_Error
	 * @throws RuntimeException When WP_Error is unavailable (outside WordPress).
	 */
	private function error( $code, $message, array $data = array() ) {
		$message = $this->scrub( $message );
		if ( class_exists( 'WP_Error' ) ) {
			return new WP_Error( $code, $message, $data );
		}
		throw new RuntimeException( $code . ': ' . $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Whether a value is an error returned by this class.
	 *
	 * @param mixed $thing Value.
	 * @return bool
	 */
	public static function is_error( $thing ) {
		return class_exists( 'WP_Error' ) && $thing instanceof WP_Error;
	}

	/**
	 * Parse a URL with wp_parse_url() when available, else parse_url().
	 *
	 * @param string $url       URL.
	 * @param int    $component Optional PHP_URL_* component, -1 for all parts.
	 * @return mixed
	 */
	private static function parse_url_part( $url, $component = -1 ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url, $component );
		}
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
