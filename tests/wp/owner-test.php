<?php
update_option( 'timezone_string', 'America/New_York' );
$s = JATCS_Settings::all(); $s['client_id'] = 'cid'; $s['client_secret'] = JATCS_Crypto::encrypt( 'cs' ); $s['min_notice'] = 1;
$s['hours'] = array_fill_keys( array( 'mon','tue','wed','thu','fri','sat','sun' ), '09:00-17:00' ); JATCS_Settings::save( $s );
$p = 0; $f = 0; $t = function ( $n, $c ) use ( &$p, &$f ) { $c ? $p++ : $f++; echo ( $c ? 'PASS ' : 'FAIL ' ) . "$n\n"; };
JATCS_Google::exchange_code( 'code-owner' );
$t( 'owner token in the original option', (bool) get_option( 'jatcs_google' ) && ! get_option( 'jatcs_google__' ) );
$t( 'owner ready', JATCS_Calendars::ready() && jatcs_host_ready( '' ) );
$type = JATCS_Types::first();
$day = ( new DateTimeImmutable( '+2 days', wp_timezone() ) )->format( 'Y-m-d' );
$at = ( new DateTimeImmutable( $day . ' 10:00', wp_timezone() ) )->getTimestamp();
$sl = JATCS_Availability::slots( $day, $day, true, $type );
$t( 'owner slots', in_array( $at, $sl[ $day ], true ) );
$b = JATCS_Bookings::create( $at, array( 'name' => 'A', 'email' => 'a@example.com' ), $type );
$t( 'owner booking', is_array( $b ) && 'confirmed' === $b['status'] && '' === JATCS_Hosts::of_booking( $b ) );
$t( 'owner event with owner token', in_array( 'insert owner cal=primary attendee=a@example.com', get_option( 'fake_google_log' ), true ) );
$t( 'plain shortcode renders', false !== strpos( do_shortcode( '[jat_booking]' ), 'data-jatcs-config' ) );
$t( 'no host column without people', ! isset( JATCS_Admin::columns( array() )['jatcs_host'] ) );
echo "$p passed, $f failed\n";
