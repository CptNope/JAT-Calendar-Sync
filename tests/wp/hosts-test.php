<?php
update_option( 'timezone_string', 'America/New_York' );
delete_option( 'fake_google_log' ); delete_option( 'fake_mail_log' );
$s = JATCS_Settings::all();
$s['client_id'] = 'cid'; $s['client_secret'] = JATCS_Crypto::encrypt( 'csecret' ); $s['notify_email'] = 'owner@example.com'; $s['min_notice'] = 1; $s['buffer_after'] = 0;
JATCS_Settings::save( $s );
$pass = 0; $fail = 0;
$t = function ( $name, $cond ) use ( &$pass, &$fail ) { if ( $cond ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } };

JATCS_Hosts::save( 'brandon', array( 'name' => 'Brandon', 'notify_email' => 'brandon@example.com' ) );
JATCS_Hosts::save( 'kaitlyn', array( 'name' => 'Kaitlyn', 'notify_email' => 'kaitlyn@example.com' ) );
$all7 = array( 'mon' => '09:00-17:00', 'tue' => '09:00-17:00', 'wed' => '09:00-17:00', 'thu' => '09:00-17:00', 'fri' => '09:00-17:00', 'sat' => '09:00-17:00', 'sun' => '09:00-17:00' );
foreach ( array( 'brandon', 'kaitlyn' ) as $h ) {
	$r = JATCS_Types::clean( array( 'name' => 'Call with ' . ucfirst( $h ), 'duration' => 30, 'interval' => 30, 'active' => 1, 'custom_hours' => 1, 'hours' => $all7, 'host' => $h, 'meet' => 'on' ) );
	JATCS_Types::save( $r['type'] );
}
$tb = JATCS_Types::get( 'call-with-brandon' ); $tk = JATCS_Types::get( 'call-with-kaitlyn' );
$t( 'types saved with hosts', $tb && 'brandon' === $tb['host'] && 'kaitlyn' === $tk['host'] );
$t( 'owner widget lists only owner types', ! in_array( 'call-with-brandon', wp_list_pluck( JATCS_Types::public_list(), 'id' ), true ) );

$t( 'kaitlyn hidden before connecting', ! jatcs_host_ready( 'kaitlyn' ) );
$t( 'shortcode empty before connecting', '' === do_shortcode( '[jat_booking host="kaitlyn"]' ) );
$t( 'unknown host renders nothing', '' === do_shortcode( '[jat_booking host="nobody"]' ) );

JATCS_Hosts::run( 'kaitlyn', function () { return JATCS_Google::exchange_code( 'code-kaitlyn' ); } );
$t( 'kaitlyn ready after connecting', jatcs_host_ready( 'kaitlyn' ) );
$t( 'brandon still hidden', ! jatcs_host_ready( 'brandon' ) && '' === do_shortcode( '[jat_booking host="brandon"]' ) );
$t( 'owner still not connected', ! JATCS_Google::is_connected() && ! JATCS_Calendars::ready() );
$acct = JATCS_Hosts::run( 'kaitlyn', array( 'JATCS_Google', 'account' ) );
$t( 'kaitlyn account recorded', 'kaitlyn@gmail.com' === $acct['email'] );
$html = do_shortcode( '[jat_booking host="kaitlyn"]' );
$t( 'kaitlyn widget renders her type only', false !== strpos( $html, 'call-with-kaitlyn' ) && false === strpos( $html, 'call-with-brandon' ) );

JATCS_Hosts::run( 'brandon', function () { return JATCS_Google::exchange_code( 'code-brandon' ); } );
$t( 'brandon ready', jatcs_host_ready( 'brandon' ) );

$tz   = wp_timezone();
$day  = ( new DateTimeImmutable( '+2 days', $tz ) )->format( 'Y-m-d' );
$at   = function ( $hm ) use ( $day, $tz ) { return ( new DateTimeImmutable( $day . ' ' . $hm, $tz ) )->getTimestamp(); };
update_option( 'fake_busy_brandon', array( array( $at( '10:00' ), $at( '12:00' ) ) ) );
JATCS_Availability::bust_cache();
$sb = JATCS_Availability::slots( $day, $day, true, $tb );
$sk = JATCS_Availability::slots( $day, $day, true, $tk );
$t( 'brandon busy 10-12 blocks his times', ! in_array( $at( '10:00' ), $sb[ $day ], true ) && ! in_array( $at( '11:30' ), $sb[ $day ], true ) && in_array( $at( '12:00' ), $sb[ $day ], true ) );
$t( 'brandon busy does not block kaitlyn', in_array( $at( '10:00' ), $sk[ $day ], true ) );

$visitor = array( 'name' => 'Pat Buyer', 'email' => 'pat@example.com' );
$bk = JATCS_Bookings::create( $at( '13:00' ), $visitor, $tk );
$t( 'kaitlyn booking created', is_array( $bk ) && 'confirmed' === $bk['status'] );
$t( 'kaitlyn booking stored with host', 'kaitlyn' === JATCS_Hosts::of_booking( $bk ) );
$log = get_option( 'fake_google_log' );
$t( 'event inserted into kaitlyn calendar with her token', in_array( 'insert kaitlyn cal=primary attendee=pat@example.com', $log, true ) );
$t( 'meet link is kaitlyn\'s', 'https://meet.google.com/kaitlyn' === $bk['meet_link'] );
$mails = get_option( 'fake_mail_log' );
$t( 'new-booking notice went to kaitlyn', (bool) preg_grep( '/^kaitlyn@example.com \|/', $mails ) && ! preg_grep( '/^owner@example.com \|/', $mails ) );

$bb = JATCS_Bookings::create( $at( '13:00' ), array( 'name' => 'Sam Seller', 'email' => 'sam@example.com' ), $tb );
$t( 'brandon can be booked at the same time as kaitlyn', is_array( $bb ) && 'confirmed' === $bb['status'] );
JATCS_Availability::bust_cache();
$sk = JATCS_Availability::slots( $day, $day, true, $tk );
$sb = JATCS_Availability::slots( $day, $day, true, $tb );
$t( 'kaitlyn 13:00 now taken for her', ! in_array( $at( '13:00' ), $sk[ $day ], true ) );
$t( 'brandon 13:00 taken for him too, 13:30 open', ! in_array( $at( '13:00' ), $sb[ $day ], true ) && in_array( $at( '13:30' ), $sb[ $day ], true ) );
$t( 'context back to owner after all that', '' === JATCS_Hosts::current() && 'owner@example.com' === JATCS_Settings::get( 'notify_email' ) );

// REST, as a visitor would (owner not connected).
$req = new WP_REST_Request( 'GET', '/jatcs/v1/availability' );
$req->set_query_params( array( 'from' => $day, 'to' => $day, 'type' => 'call-with-kaitlyn' ) );
$res = rest_do_request( $req );
$d   = json_decode( wp_json_encode( $res->get_data() ), true );
$t( 'REST availability works for a host while the owner is offline', 200 === $res->get_status() && isset( $d['days'][ $day ] ) && in_array( $at( '10:00' ), (array) $d['days'][ $day ], true ) );
JATCS_Hosts::enter( '' );

// Cancel from Brandon's side: deleted with Brandon's token.
delete_option( 'fake_google_log' );
JATCS_Bookings::cancel( $bb['id'], 'admin' );
$log = get_option( 'fake_google_log', array() );
$t( 'cancel deletes from brandon calendar', in_array( 'delete brandon cal=primary', $log, true ) && 'cancelled' === JATCS_Bookings::get( $bb['id'] )['status'] );

// Hourly sync reads each booking with its host's account.
delete_option( 'fake_google_log' );
update_option( 'fake_event_status_kaitlyn', 'cancelled' );
$st = JATCS_Maintenance::run();
$log = get_option( 'fake_google_log', array() );
$t( 'hourly sync reads kaitlyn event with her token and mirrors the deletion', in_array( 'get kaitlyn cal=primary', $log, true ) && 'cancelled' === JATCS_Bookings::get( $bk['id'] )['status'] );

// Disconnect hides again.
JATCS_Hosts::run( 'kaitlyn', array( 'JATCS_Google', 'disconnect' ) );
$t( 'kaitlyn hidden after disconnect', ! jatcs_host_ready( 'kaitlyn' ) && '' === do_shortcode( '[jat_booking host="kaitlyn"]' ) );
$t( 'brandon unaffected by her disconnect', jatcs_host_ready( 'brandon' ) );
// Hiding the type hides the person.
$tb['active'] = 0; JATCS_Types::save( $tb );
$t( 'brandon hidden when his only type is hidden', ! jatcs_host_ready( 'brandon' ) );
echo "\n$pass passed, $fail failed\n";
