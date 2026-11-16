<?php
/* Test only: a fake Google Calendar API. Each refresh token is one account; busy times and created events are per account. */
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'googleapis.com' ) && false === strpos( $url, 'accounts.google.com' ) ) {
		return $pre;
	}
	$log = get_option( 'fake_google_log', array() );
	$ok  = function ( $body, $code = 200 ) { return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array() ); };
	$auth = isset( $args['headers']['Authorization'] ) ? substr( $args['headers']['Authorization'], 7 ) : '';
	$acct = str_replace( 'at-', '', $auth );
	if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
		$b = $args['body'];
		if ( 'authorization_code' === $b['grant_type'] ) { // code "code-<acct>"
			$a = str_replace( 'code-', '', $b['code'] );
			return $ok( array( 'access_token' => 'at-' . $a, 'refresh_token' => 'rt-' . $a, 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy https://www.googleapis.com/auth/calendar.calendarlist.readonly' ) );
		}
		$a = str_replace( 'rt-', '', $b['refresh_token'] );
		return $ok( array( 'access_token' => 'at-' . $a, 'expires_in' => 3600 ) );
	}
	if ( false !== strpos( $url, '/users/me/calendarList/primary' ) ) {
		return $ok( array( 'id' => $acct . '@gmail.com', 'timeZone' => 'America/New_York' ) );
	}
	if ( false !== strpos( $url, '/users/me/calendarList' ) ) {
		return $ok( array( 'items' => array( array( 'id' => $acct . '@gmail.com', 'summary' => $acct, 'primary' => true, 'accessRole' => 'owner' ), array( 'id' => $acct . '-family@group', 'summary' => $acct . ' family', 'accessRole' => 'reader' ) ) ) );
	}
	if ( false !== strpos( $url, '/freeBusy' ) ) {
		$req  = json_decode( $args['body'], true );
		$busy = get_option( 'fake_busy_' . $acct, array() );
		$cals = array();
		foreach ( $req['items'] as $it ) {
			$cals[ $it['id'] ] = array( 'busy' => array_map( function ( $r ) { return array( 'start' => gmdate( 'c', $r[0] ), 'end' => gmdate( 'c', $r[1] ) ); }, $busy ) );
		}
		$log[] = 'freebusy ' . $acct . ' ' . implode( ',', array_keys( $cals ) );
		update_option( 'fake_google_log', $log );
		return $ok( array( 'calendars' => $cals ) );
	}
	if ( preg_match( '#/calendars/([^/]+)/events(/([^?]+))?#', $url, $m ) ) {
		$cal = rawurldecode( $m[1] );
		if ( 'POST' === $args['method'] ) {
			$ev    = json_decode( $args['body'], true );
			$log[] = 'insert ' . $acct . ' cal=' . $cal . ' attendee=' . ( isset( $ev['attendees'][0]['email'] ) ? $ev['attendees'][0]['email'] : '-' );
			update_option( 'fake_google_log', $log );
			return $ok( array_merge( $ev, array( 'htmlLink' => 'https://calendar.google.com/x', 'hangoutLink' => 'https://meet.google.com/' . $acct ) ) );
		}
		if ( 'DELETE' === $args['method'] ) {
			$log[] = 'delete ' . $acct . ' cal=' . $cal;
			update_option( 'fake_google_log', $log );
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 204, 'message' => 'No Content' ), 'cookies' => array() );
		}
		if ( 'GET' === $args['method'] && ! empty( $m[3] ) ) {
			$log[] = 'get ' . $acct . ' cal=' . $cal;
			update_option( 'fake_google_log', $log );
			$st = get_option( 'fake_event_status_' . $acct, 'confirmed' );
			return $ok( array( 'id' => rawurldecode( $m[3] ), 'status' => $st, 'start' => array( 'dateTime' => get_option( 'fake_event_start_' . $acct ) ), 'end' => array( 'dateTime' => get_option( 'fake_event_end_' . $acct ) ) ) );
		}
		if ( 'PATCH' === $args['method'] ) {
			$log[] = 'patch ' . $acct . ' cal=' . $cal;
			update_option( 'fake_google_log', $log );
			return $ok( array( 'id' => 'x' ) );
		}
	}
	if ( false !== strpos( $url, 'oauth2.googleapis.com/revoke' ) ) {
		return $ok( array() );
	}
	return $ok( array( 'error' => array( 'message' => 'unmocked ' . $url ) ), 500 );
}, 10, 3 );
add_filter( 'wp_mail', function ( $a ) { $l = get_option( 'fake_mail_log', array() ); $l[] = ( is_array( $a['to'] ) ? implode( ',', $a['to'] ) : $a['to'] ) . ' | ' . $a['subject']; update_option( 'fake_mail_log', $l ); return $a; } );
add_filter( 'pre_wp_mail', '__return_true' );
