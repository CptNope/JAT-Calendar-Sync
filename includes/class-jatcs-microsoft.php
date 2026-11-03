<?php
/**
 * Microsoft 365 / Outlook.com calendars as extra conflict calendars (read-only).
 *
 * Busy times come from Microsoft Graph's calendarView, which expands recurring meetings
 * on Microsoft's side. Events marked Busy, Tentative or Out of office block slots;
 * Free and Working elsewhere don't. Only start, end and show-as are read, never titles.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Microsoft Graph.
 */
final class JATCS_Microsoft {

	const TOKEN_OPTION  = 'jatcs_ms';
	const ERROR_OPTION  = 'jatcs_ms_error';
	const ACCESS_KEY    = 'jatcs_ms_access';
	const CALENDARS_KEY = 'jatcs_ms_calendars';
	const AUTHORITY     = 'https://login.microsoftonline.com/common/oauth2/v2.0';
	const GRAPH         = 'https://graph.microsoft.com/v1.0';

	/**
	 * Delegated scopes asked for when connecting: calendars, stay signed in, know who connected.
	 * Write access only when Outlook should receive bookings: some work tenants don't let
	 * users grant it, and reading busy times doesn't need it.
	 *
	 * @param bool|null $write Ask for write access (null: what this sign-in asked for).
	 * @return string
	 */
	public static function scopes( $write = null ) {
		if ( null === $write ) {
			$write = (bool) get_transient( 'jatcs_ms_write_' . get_current_user_id() );
		}
		return 'offline_access User.Read ' . ( $write ? 'Calendars.ReadWrite' : 'Calendars.Read' );
	}

	/**
	 * Scopes this connection was granted. Refreshing must not ask for more than that, or Microsoft refuses.
	 * Connections made before write access existed have Calendars.Read.
	 *
	 * @return string
	 */
	private static function granted_scopes() {
		$data = get_option( self::TOKEN_OPTION, array() );
		return ! empty( $data['scopes'] ) ? (string) $data['scopes'] : 'offline_access User.Read Calendars.Read';
	}

	/**
	 * Can this connection create and change events (needed to use Outlook as the booking calendar)?
	 *
	 * @return bool
	 */
	public static function can_write() {
		return self::is_connected() && false !== stripos( self::granted_scopes(), 'Calendars.ReadWrite' );
	}

	/**
	 * Redirect URI to register in Microsoft Entra (Azure).
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=' . ( get_option( 'jatcs_legacy_ms_redirect' ) ? 'aab_ms_oauth' : 'jatcs_ms_oauth' ) );
	}

	/**
	 * Application (client) ID.
	 *
	 * @return string
	 */
	public static function client_id() {
		return defined( 'JATCS_MS_CLIENT_ID' ) ? (string) JATCS_MS_CLIENT_ID : (string) JATCS_Settings::get( 'ms_client_id' );
	}

	/**
	 * Client secret value.
	 *
	 * @return string
	 */
	public static function client_secret() {
		return defined( 'JATCS_MS_CLIENT_SECRET' ) ? (string) JATCS_MS_CLIENT_SECRET : JATCS_Crypto::decrypt( (string) JATCS_Settings::get( 'ms_client_secret' ) );
	}

	/**
	 * Are credentials saved?
	 *
	 * @return bool
	 */
	public static function has_credentials() {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	/**
	 * Is an account connected?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$data = get_option( self::TOKEN_OPTION, array() );
		return ! empty( $data['refresh_token'] ) && '' !== JATCS_Crypto::decrypt( $data['refresh_token'] );
	}

	/**
	 * Is it connected and set to check at least one calendar?
	 *
	 * @return bool
	 */
	public static function active() {
		return self::is_connected() && (bool) array_filter( (array) JATCS_Settings::get( 'ms_calendars' ) );
	}

	/**
	 * Connected account.
	 *
	 * @return string
	 */
	public static function account() {
		$data = get_option( self::TOKEN_OPTION, array() );
		return isset( $data['email'] ) ? (string) $data['email'] : '';
	}

	/**
	 * Sign-in URL.
	 *
	 * @param string $state Anti-CSRF state.
	 * @param bool   $write Ask for permission to add bookings.
	 * @return string
	 */
	public static function auth_url( $state, $write = false ) {
		set_transient( 'jatcs_ms_write_' . get_current_user_id(), $write ? 1 : 0, 15 * MINUTE_IN_SECONDS );
		return add_query_arg(
			rawurlencode_deep(
				array(
					'client_id'     => self::client_id(),
					'response_type' => 'code',
					'redirect_uri'  => self::redirect_uri(),
					'response_mode' => 'query',
					'scope'         => self::scopes( (bool) $write ),
					'state'         => $state,
					'prompt'        => 'select_account',
				)
			),
			self::AUTHORITY . '/authorize'
		);
	}

	/**
	 * POST to the token endpoint.
	 *
	 * @param array $params Grant params.
	 * @return array|WP_Error
	 */
	private static function token_request( array $params ) {
		$params['client_id']     = self::client_id();
		$params['client_secret'] = self::client_secret();
		$params['scope']         = 'refresh_token' === $params['grant_type'] ? self::granted_scopes() : self::scopes();
		$response                = wp_remote_post(
			self::AUTHORITY . '/token',
			array(
				'timeout' => 15,
				'body'    => $params,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! empty( $body['error'] ) || empty( $body['access_token'] ) ) {
			$error = is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : 'http_' . wp_remote_retrieve_response_code( $response );
			$desc  = is_array( $body ) && ! empty( $body['error_description'] ) ? strtok( (string) $body['error_description'], "\r\n" ) : $error;
			return new WP_Error( 'jatcs_ms_token', $desc, array( 'oauth_error' => $error ) );
		}
		return $body;
	}

	/**
	 * Store tokens from a token response. Microsoft rotates refresh tokens, so keep the newest.
	 *
	 * @param array $body Token response.
	 */
	private static function store_tokens( array $body ) {
		$data = get_option( self::TOKEN_OPTION, array() );
		$data = is_array( $data ) ? $data : array();
		if ( ! empty( $body['refresh_token'] ) ) {
			$data['refresh_token'] = JATCS_Crypto::encrypt( (string) $body['refresh_token'] );
		}
		update_option( self::TOKEN_OPTION, $data, false );
		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 120 ) : 3000;
		set_transient( self::ACCESS_KEY, JATCS_Crypto::encrypt( (string) $body['access_token'] ), $ttl );
	}

	/**
	 * Finish sign-in.
	 *
	 * @param string $code Authorization code.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$body = self::token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::redirect_uri(),
			)
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['refresh_token'] ) ) {
			return new WP_Error( 'jatcs_ms_no_refresh', __( 'Microsoft didn’t allow staying signed in. Make sure the app has the offline_access permission, then connect again.', 'jat-calendar-sync' ) );
		}
		update_option(
			self::TOKEN_OPTION,
			array(
				'connected_at' => time(),
				'scopes'       => isset( $body['scope'] ) ? self::normalize_scopes( (string) $body['scope'] ) : self::scopes(),
			),
			false
		);
		self::store_tokens( $body );
		delete_option( self::ERROR_OPTION );
		delete_transient( self::CALENDARS_KEY );

		$me = self::request( '/me?$select=mail,userPrincipalName' );
		if ( ! is_wp_error( $me ) ) {
			$data          = get_option( self::TOKEN_OPTION, array() );
			$data['email'] = sanitize_text_field( ! empty( $me['mail'] ) ? $me['mail'] : ( isset( $me['userPrincipalName'] ) ? $me['userPrincipalName'] : '' ) );
			update_option( self::TOKEN_OPTION, $data, false );
		}
		return true;
	}

	/**
	 * Microsoft returns granted scopes as URLs (https://graph.microsoft.com/Calendars.ReadWrite); keep the short names.
	 *
	 * @param string $scope Space-separated scopes.
	 * @return string
	 */
	private static function normalize_scopes( $scope ) {
		$out = array( 'offline_access' );
		foreach ( preg_split( '/\s+/', $scope ) as $one ) {
			$one = preg_replace( '#^https://graph\.microsoft\.com/#i', '', $one );
			if ( '' !== $one && 'offline_access' !== $one && 'openid' !== $one && 'profile' !== $one ) {
				$out[] = $one;
			}
		}
		return implode( ' ', array_unique( $out ) );
	}

	/**
	 * Forget the connection (Microsoft has no token revocation endpoint for this flow).
	 */
	public static function disconnect() {
		delete_option( self::TOKEN_OPTION );
		delete_option( self::ERROR_OPTION );
		delete_transient( self::ACCESS_KEY );
		delete_transient( self::CALENDARS_KEY );
	}

	/**
	 * A valid access token.
	 *
	 * @return string|WP_Error
	 */
	private static function access_token() {
		$cached = JATCS_Crypto::decrypt( (string) get_transient( self::ACCESS_KEY ) );
		if ( '' !== $cached ) {
			return $cached;
		}
		$data    = get_option( self::TOKEN_OPTION, array() );
		$refresh = ! empty( $data['refresh_token'] ) ? JATCS_Crypto::decrypt( $data['refresh_token'] ) : '';
		if ( '' === $refresh ) {
			return new WP_Error( 'jatcs_ms_not_connected', __( 'Outlook is not connected.', 'jat-calendar-sync' ) );
		}
		$body = self::token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			)
		);
		if ( is_wp_error( $body ) ) {
			$err = $body->get_error_data();
			if ( is_array( $err ) && isset( $err['oauth_error'] ) && in_array( $err['oauth_error'], array( 'invalid_grant', 'interaction_required' ), true ) ) {
				update_option(
					self::ERROR_OPTION,
					array(
						'message' => $body->get_error_message(),
						'time'    => time(),
					),
					false
				);
			}
			JATCS_Google::log( 'Outlook token', $body->get_error_message() );
			return $body;
		}
		self::store_tokens( $body );
		delete_option( self::ERROR_OPTION );
		return (string) $body['access_token'];
	}

	/**
	 * GET from Graph (absolute nextLink URLs are accepted too).
	 *
	 * @param string $path    Path under /v1.0, or a full graph.microsoft.com URL.
	 * @param bool   $retried Internal.
	 * @return array|WP_Error
	 */
	public static function request( $path, $retried = false ) {
		return self::send( 'GET', $path, null, $retried );
	}

	/**
	 * Call Graph.
	 *
	 * GET is retried once on timeouts, 429 and 5xx. Writes are retried only where repeating is
	 * harmless: creates carry a transactionId, so Outlook makes the event once.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path under /v1.0, or a full graph.microsoft.com URL.
	 * @param array|null $body    JSON body.
	 * @param bool       $retried Internal.
	 * @return array|true|WP_Error Decoded body, true for an empty success (204), or an error with the HTTP status.
	 */
	public static function send( $method, $path, $body = null, $retried = false ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url  = 0 === strpos( $path, 'https://graph.microsoft.com/' ) ? $path : self::GRAPH . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				// Ask for UTC times so no timezone mapping is needed.
				'Prefer'        => 'outlook.timezone="UTC"',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$tries = in_array( $method, array( 'GET', 'POST' ), true ) ? 2 : 1;
		for ( $attempt = 1; $attempt <= $tries; $attempt++ ) {
			$response = wp_remote_request( $url, $args );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( ( is_wp_error( $response ) || 429 === $code || $code >= 500 ) && $attempt < $tries ) {
				usleep( 500000 );
				continue;
			}
			break;
		}
		if ( 401 === $code && ! $retried ) {
			delete_transient( self::ACCESS_KEY );
			return self::send( $method, $path, $body, true );
		}
		if ( is_wp_error( $response ) ) {
			JATCS_Google::log( 'Outlook ' . $method, $response->get_error_message() );
			return $response;
		}
		$raw = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 200 && $code < 300 && '' === trim( $raw ) ) {
			return true;
		}
		$data = json_decode( $raw, true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
			if ( 404 !== $code ) {
				JATCS_Google::log( 'Outlook ' . $method, $code . ' ' . $message );
			}
			return new WP_Error( 'jatcs_ms_http', $message, array( 'status' => $code ) );
		}
		return $data;
	}

	/**
	 * Calendars in the connected account.
	 *
	 * @param bool $refresh Skip cache.
	 * @return array<int,array{id:string,name:string,default:bool}>|WP_Error
	 */
	public static function calendars( $refresh = false ) {
		$cached = get_transient( self::CALENDARS_KEY );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$out  = array();
		$next = '/me/calendars?$select=id,name,isDefaultCalendar,canEdit,allowedOnlineMeetingProviders,defaultOnlineMeetingProvider&$top=100';
		for ( $page = 0; $next && $page < 5; $page++ ) {
			$body = self::request( $next );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			foreach ( isset( $body['value'] ) ? $body['value'] : array() as $cal ) {
				$providers = isset( $cal['allowedOnlineMeetingProviders'] ) ? array_map( 'strtolower', (array) $cal['allowedOnlineMeetingProviders'] ) : array();
				$out[]     = array(
					'id'       => (string) $cal['id'],
					'name'     => (string) ( isset( $cal['name'] ) ? $cal['name'] : $cal['id'] ),
					'default'  => ! empty( $cal['isDefaultCalendar'] ),
					'writable' => ! isset( $cal['canEdit'] ) || ! empty( $cal['canEdit'] ),
					'meeting'  => in_array( 'teamsforbusiness', $providers, true ) ? 'teamsForBusiness' : ( in_array( 'skypeforconsumer', $providers, true ) ? 'skypeForConsumer' : '' ),
				);
			}
			$next = isset( $body['@odata.nextLink'] ) ? (string) $body['@odata.nextLink'] : '';
		}
		set_transient( self::CALENDARS_KEY, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Busy intervals from the selected Outlook calendars. Fails closed like Google.
	 *
	 * @param int $from Unix start.
	 * @param int $to   Unix end.
	 * @return array<int,array{0:int,1:int}>|WP_Error
	 */
	public static function busy( $from, $to ) {
		$map = self::busy_map( $from, $to, array_filter( (array) JATCS_Settings::get( 'ms_calendars' ) ) );
		if ( is_wp_error( $map ) ) {
			return $map;
		}
		return $map ? array_merge( ...array_values( $map ) ) : array();
	}

	/**
	 * Busy intervals per Outlook calendar.
	 *
	 * @param int      $from    Unix start.
	 * @param int      $to      Unix end.
	 * @param string[] $cal_ids Calendar IDs.
	 * @return array<string,array<int,array{0:int,1:int}>>|WP_Error
	 */
	public static function busy_map( $from, $to, array $cal_ids ) {
		$map = array();
		foreach ( array_unique( array_filter( array_map( 'strval', $cal_ids ) ) ) as $cal_id ) {
			$busy = array();
			$next = '/me/calendars/' . rawurlencode( $cal_id ) . '/calendarView?' . http_build_query(
				array(
					'startDateTime' => gmdate( 'Y-m-d\TH:i:s\Z', $from ),
					'endDateTime'   => gmdate( 'Y-m-d\TH:i:s\Z', $to ),
					'$select'       => 'start,end,showAs,isCancelled',
					'$top'          => 250,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			);
			for ( $page = 0; $next && $page < 25; $page++ ) {
				$body = self::request( $next );
				if ( is_wp_error( $body ) ) {
					/* translators: %s: error */
					return new WP_Error( 'jatcs_ms_busy', sprintf( __( 'Could not read Outlook availability: %s', 'jat-calendar-sync' ), $body->get_error_message() ) );
				}
				foreach ( isset( $body['value'] ) ? $body['value'] : array() as $ev ) {
					$show = isset( $ev['showAs'] ) ? strtolower( (string) $ev['showAs'] ) : 'busy';
					if ( ! empty( $ev['isCancelled'] ) || ! in_array( $show, array( 'busy', 'tentative', 'oof', 'unknown' ), true ) ) {
						continue;
					}
					$start = self::utc( isset( $ev['start']['dateTime'] ) ? $ev['start']['dateTime'] : '' );
					$end   = self::utc( isset( $ev['end']['dateTime'] ) ? $ev['end']['dateTime'] : '' );
					if ( $start && $end && $end > $start ) {
						$busy[] = array( $start, $end );
					}
				}
				$next = isset( $body['@odata.nextLink'] ) ? (string) $body['@odata.nextLink'] : '';
			}
			if ( '' !== $next ) {
				// More pages than we read: some busy times would be missing, so don't guess.
				return new WP_Error( 'jatcs_ms_busy', __( 'Your Outlook calendar has too many events in this period to read completely.', 'jat-calendar-sync' ) );
			}
			$map[ $cal_id ] = $busy;
		}
		return $map;
	}

	/**
	 * A calendar's details (from the cached list).
	 *
	 * @param string $cal_id Calendar ID.
	 * @return array|null
	 */
	public static function calendar( $cal_id ) {
		$cals = self::calendars();
		if ( is_wp_error( $cals ) ) {
			return null;
		}
		foreach ( $cals as $cal ) {
			if ( $cal['id'] === $cal_id ) {
				return $cal;
			}
		}
		return null;
	}

	/**
	 * Create a booking event.
	 *
	 * @param string $cal_id Calendar ID.
	 * @param array  $event  Neutral event (see JATCS_Calendars::create_event()).
	 * @return array|WP_Error {id, link, video}
	 */
	public static function insert_event( $cal_id, array $event ) {
		$body = array(
			// Outlook creates the event only once per transactionId, so a retry can't duplicate it.
			'transactionId' => (string) $event['uid'],
			'subject'       => (string) $event['summary'],
			'body'          => array(
				'contentType' => 'text',
				'content'     => (string) $event['description'],
			),
			'start'         => array(
				'dateTime' => gmdate( 'Y-m-d\TH:i:s', (int) $event['start'] ),
				'timeZone' => 'UTC',
			),
			'end'           => array(
				'dateTime' => gmdate( 'Y-m-d\TH:i:s', (int) $event['end'] ),
				'timeZone' => 'UTC',
			),
			'showAs'        => 'busy',
		);
		if ( '' !== (string) $event['location'] ) {
			$body['location'] = array( 'displayName' => (string) $event['location'] );
		}
		if ( ! empty( $event['attendee']['email'] ) ) {
			// Outlook emails the invitation itself.
			$body['attendees'] = array(
				array(
					'emailAddress' => array(
						'address' => (string) $event['attendee']['email'],
						'name'    => (string) $event['attendee']['name'],
					),
					'type'         => 'required',
				),
			);
		}
		if ( ! empty( $event['video'] ) ) {
			$cal      = self::calendar( $cal_id );
			$provider = $cal && '' !== $cal['meeting'] ? $cal['meeting'] : '';
			if ( '' !== $provider ) {
				$body['isOnlineMeeting']       = true;
				$body['onlineMeetingProvider'] = $provider;
			}
		}
		$created = self::send( 'POST', '/me/calendars/' . rawurlencode( $cal_id ) . '/events', $body );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( ! is_array( $created ) || empty( $created['id'] ) ) {
			return new WP_Error( 'jatcs_ms_http', 'Outlook did not return the new event.' );
		}
		return array(
			'id'    => (string) $created['id'],
			'link'  => isset( $created['webLink'] ) ? (string) $created['webLink'] : '',
			'video' => isset( $created['onlineMeeting']['joinUrl'] ) ? (string) $created['onlineMeeting']['joinUrl'] : '',
		);
	}

	/**
	 * Move an event.
	 *
	 * @param string $event_id Event ID.
	 * @param int    $start    Unix start.
	 * @param int    $end      Unix end.
	 * @return true|WP_Error
	 */
	public static function move_event( $event_id, $start, $end ) {
		$result = self::send(
			'PATCH',
			'/me/events/' . rawurlencode( $event_id ),
			array(
				'start' => array(
					'dateTime' => gmdate( 'Y-m-d\TH:i:s', (int) $start ),
					'timeZone' => 'UTC',
				),
				'end'   => array(
					'dateTime' => gmdate( 'Y-m-d\TH:i:s', (int) $end ),
					'timeZone' => 'UTC',
				),
			)
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Cancel an event. With guests, Outlook's "cancel" sends them the cancellation; otherwise it is deleted.
	 *
	 * @param string $event_id Event ID.
	 * @param bool   $notify   Tell the guest.
	 * @return true|WP_Error
	 */
	public static function delete_event( $event_id, $notify ) {
		$path   = '/me/events/' . rawurlencode( $event_id );
		$result = $notify ? self::send( 'POST', $path . '/cancel', array( 'comment' => '' ) ) : self::send( 'DELETE', $path );
		if ( is_wp_error( $result ) && $notify ) {
			// "cancel" only works for meetings the account organizes; fall back to deleting.
			$result = self::send( 'DELETE', $path );
		}
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) && in_array( (int) $data['status'], array( 404, 410 ), true ) ) {
				return true;
			}
			return $result;
		}
		return true;
	}

	/**
	 * Current state of an event, for following changes made in Outlook.
	 *
	 * @param string $event_id Event ID.
	 * @return array|WP_Error {status: confirmed|cancelled|missing, start, end, all_day}
	 */
	public static function event_state( $event_id ) {
		$event = self::send( 'GET', '/me/events/' . rawurlencode( $event_id ) . '?$select=start,end,isCancelled,isAllDay' );
		if ( is_wp_error( $event ) ) {
			$data = $event->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) && in_array( (int) $data['status'], array( 404, 410 ), true ) ) {
				return array( 'status' => 'missing' );
			}
			return $event;
		}
		return array(
			'status'  => ! empty( $event['isCancelled'] ) ? 'cancelled' : 'confirmed',
			'start'   => (int) self::utc( isset( $event['start']['dateTime'] ) ? $event['start']['dateTime'] : '' ),
			'end'     => (int) self::utc( isset( $event['end']['dateTime'] ) ? $event['end']['dateTime'] : '' ),
			'all_day' => ! empty( $event['isAllDay'] ),
		);
	}

	/**
	 * End of the last upcoming event on a calendar (how far a posted work schedule reaches).
	 *
	 * @param string $cal_id     Calendar ID.
	 * @param int    $days_ahead How far to look.
	 * @return int|WP_Error Unix time, 0 if nothing is scheduled.
	 */
	public static function last_event_end( $cal_id, $days_ahead ) {
		$map = self::busy_map( time(), time() + max( 1, (int) $days_ahead ) * DAY_IN_SECONDS, array( $cal_id ) );
		if ( is_wp_error( $map ) ) {
			return $map;
		}
		$latest = 0;
		foreach ( isset( $map[ $cal_id ] ) ? $map[ $cal_id ] : array() as $range ) {
			$latest = max( $latest, (int) $range[1] );
		}
		return $latest;
	}

	/**
	 * Parse Graph's "2026-10-27T13:00:00.0000000" (UTC, no zone suffix).
	 *
	 * @param string $value Date-time.
	 * @return int|false
	 */
	private static function utc( $value ) {
		if ( '' === (string) $value ) {
			return false;
		}
		$value = preg_replace( '/\.\d+$/', '', (string) $value );
		return strtotime( preg_match( '/(Z|[+-]\d{2}:\d{2})$/', $value ) ? $value : $value . 'Z' );
	}
}
