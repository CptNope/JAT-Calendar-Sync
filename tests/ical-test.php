<?php
/**
 * CLI tests for JATCS_ICal. Run: php tests/ical-test.php
 *
 * @package JATCalendarSync
 */

define( 'ABSPATH', __DIR__ . '/' );
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

require_once dirname( __DIR__ ) . '/includes/class-jatcs-ical.php';

$GLOBALS['jatcs_pass']     = 0;
$GLOBALS['jatcs_fail']     = 0;
$GLOBALS['jatcs_warnings'] = array();

set_error_handler(
	function ( $errno, $errstr, $file, $line ) {
		$GLOBALS['jatcs_warnings'][] = "$errstr at " . basename( $file ) . ":$line";
		return true;
	}
);

/**
 * Record an assertion.
 *
 * @param string $name Test name.
 * @param bool   $ok   Result.
 * @param string $info Extra info on failure.
 */
function check( $name, $ok, $info = '' ) {
	if ( ! empty( $GLOBALS['jatcs_warnings'] ) ) {
		$info .= ' [PHP warnings: ' . implode( '; ', $GLOBALS['jatcs_warnings'] ) . ']';
		$GLOBALS['jatcs_warnings'] = array();
		$ok                        = false;
	}
	if ( $ok ) {
		++$GLOBALS['jatcs_pass'];
		echo "PASS  $name\n";
	} else {
		++$GLOBALS['jatcs_fail'];
		echo "FAIL  $name" . ( '' !== $info ? "  -- $info" : '' ) . "\n";
	}
}

/**
 * Equality assertion.
 *
 * @param string $name     Test name.
 * @param mixed  $actual   Actual.
 * @param mixed  $expected Expected.
 */
function eq( $name, $actual, $expected ) {
	check( $name, $actual === $expected, 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

/**
 * Unix time of a local wall-clock time.
 *
 * @param string $s  Date-time string.
 * @param string $tz Zone.
 * @return int
 */
function ts( $s, $tz = 'UTC' ) {
	$d = new DateTimeImmutable( $s, new DateTimeZone( $tz ) );
	return $d->getTimestamp();
}

/**
 * Local Y-m-d H:i of a unix time.
 *
 * @param int    $t  Unix time.
 * @param string $tz Zone.
 * @return string
 */
function loc( $t, $tz ) {
	$d = new DateTimeImmutable( '@' . $t );
	return $d->setTimezone( new DateTimeZone( $tz ) )->format( 'Y-m-d H:i' );
}

/**
 * Wrap VEVENT lines in a VCALENDAR.
 *
 * @param array $lines Lines.
 * @return string
 */
function cal( array $lines ) {
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n" . implode( "\r\n", $lines ) . "\r\nEND:VCALENDAR\r\n";
}

/**
 * Starts of occurrences formatted in a zone.
 *
 * @param array  $occ Occurrences.
 * @param string $tz  Zone.
 * @return array
 */
function starts( array $occ, $tz ) {
	$out = array();
	foreach ( $occ as $o ) {
		$out[] = loc( $o['start'], $tz );
	}
	return $out;
}

/**
 * Parse + expand helper.
 *
 * @param string $ics  ICS.
 * @param int    $from From.
 * @param int    $to   To.
 * @param string $tz   Default zone.
 * @return array
 */
function occ( $ics, $from, $to, $tz = 'UTC' ) {
	$z  = new DateTimeZone( $tz );
	$ev = JATCS_ICal::parse( $ics, $z );
	return false === $ev ? array() : JATCS_ICal::expand( $ev, $from, $to, $z );
}

$utc = new DateTimeZone( 'UTC' );
$ny  = new DateTimeZone( 'America/New_York' );
$fx  = __DIR__ . '/fixtures/';

// 1. Simple UTC event.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:simple-1', 'DTSTART:20261105T150000Z', 'DTEND:20261105T160000Z', 'SUMMARY:Simple', 'END:VEVENT' ) );
$ev  = JATCS_ICal::parse( $ics );
eq( 'simple: one event', count( $ev ), 1 );
eq( 'simple: start', $ev[0]['start'], ts( '2026-11-05 15:00' ) );
eq( 'simple: end', $ev[0]['end'], ts( '2026-11-05 16:00' ) );
eq( 'simple: uid/summary', $ev[0]['uid'] . '|' . $ev[0]['summary'], 'simple-1|Simple' );
eq( 'simple: busy', JATCS_ICal::busy( $ics, ts( '2026-11-01' ), ts( '2026-11-10' ), $utc ), array( array( ts( '2026-11-05 15:00' ), ts( '2026-11-05 16:00' ) ) ) );

// 2. America/New_York weekly 9:00 across DST end (2026-11-01).
$ics = cal( array( 'BEGIN:VEVENT', 'UID:dst-1', 'DTSTART;TZID=America/New_York:20261019T090000', 'DTEND;TZID=America/New_York:20261019T100000', 'RRULE:FREQ=WEEKLY;COUNT=6', 'SUMMARY:Standup', 'END:VEVENT' ) );
$o   = occ( $ics, ts( '2026-10-01' ), ts( '2026-12-31' ) );
eq( 'dst: 6 occurrences', count( $o ), 6 );
eq( 'dst: all at 09:00 local', array_unique( array_map( function ( $x ) { return substr( loc( $x['start'], 'America/New_York' ), 11 ); }, $o ) ), array( '09:00' ) );
eq( 'dst: Oct 26 = 13:00Z (EDT)', $o[1]['start'], ts( '2026-10-26 13:00' ) );
eq( 'dst: Nov 2 = 14:00Z (EST)', $o[2]['start'], ts( '2026-11-02 14:00' ) );
eq( 'dst: duration 1h after change', $o[2]['end'] - $o[2]['start'], 3600 );

// 3. Windows TZID names.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:win-1', 'DTSTART;TZID="Eastern Standard Time":20261105T100000', 'DTEND;TZID="Eastern Standard Time":20261105T110000', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:win-2', 'DTSTART;TZID=W. Europe Standard Time:20260701T100000', 'DURATION:PT1H', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:win-3', 'DTSTART;TZID=AUS Eastern Standard Time:20260115T090000', 'DURATION:PT1H', 'END:VEVENT' ) );
$ev  = JATCS_ICal::parse( $ics );
eq( 'windows: Eastern Standard Time', $ev[0]['start'], ts( '2026-11-05 15:00' ) );
eq( 'windows: W. Europe Standard Time (CEST)', $ev[1]['start'], ts( '2026-07-01 08:00' ) );
eq( 'windows: AUS Eastern Standard Time (AEDT)', $ev[2]['start'], ts( '2026-01-14 22:00' ) );

// 4. Outlook display-style and prefixed TZIDs.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:disp-1', 'DTSTART;TZID="(UTC-05:00) Eastern Time (US & Canada)":20260715T100000', 'DTEND;TZID="(UTC-05:00) Eastern Time (US & Canada)":20260715T110000', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:moz-1', 'DTSTART;TZID=/mozilla.org/20050126_1/America/Chicago:20260715T100000', 'DURATION:PT30M', 'END:VEVENT' ) );
$ev  = JATCS_ICal::parse( $ics );
eq( 'display TZID: summer EDT honored', $ev[0]['start'], ts( '2026-07-15 14:00' ) );
eq( 'mozilla prefixed TZID', $ev[1]['start'], ts( '2026-07-15 15:00' ) );

// 5. Unknown TZID resolved from in-file VTIMEZONE.
$vt  = array( 'BEGIN:VTIMEZONE', 'TZID:My Custom Eastern', 'BEGIN:STANDARD', 'DTSTART:16010101T020000', 'TZOFFSETFROM:-0400', 'TZOFFSETTO:-0500', 'RRULE:FREQ=YEARLY;BYDAY=1SU;BYMONTH=11', 'END:STANDARD', 'BEGIN:DAYLIGHT', 'DTSTART:16010101T020000', 'TZOFFSETFROM:-0500', 'TZOFFSETTO:-0400', 'RRULE:FREQ=YEARLY;BYDAY=2SU;BYMONTH=3', 'END:DAYLIGHT', 'END:VTIMEZONE' );
$ics = cal( array_merge( $vt, array( 'BEGIN:VEVENT', 'UID:vt-1', 'DTSTART;TZID=My Custom Eastern:20261026T090000', 'DTEND;TZID=My Custom Eastern:20261026T093000', 'RRULE:FREQ=WEEKLY;COUNT=3', 'END:VEVENT' ) ) );
$o   = occ( $ics, ts( '2026-10-01' ), ts( '2026-12-01' ) );
eq( 'vtimezone fallback: matches IANA across DST', array( $o[0]['start'], $o[1]['start'], $o[2]['start'] ), array( ts( '2026-10-26 09:00', 'America/New_York' ), ts( '2026-11-02 09:00', 'America/New_York' ), ts( '2026-11-09 09:00', 'America/New_York' ) ) );

// 6. Floating time uses the default zone.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:float-1', 'DTSTART:20261110T090000', 'DTEND:20261110T100000', 'END:VEVENT' ) );
$ev  = JATCS_ICal::parse( $ics, new DateTimeZone( 'America/Chicago' ) );
eq( 'floating: Chicago default', $ev[0]['start'], ts( '2026-11-10 15:00' ) );
$b   = JATCS_ICal::busy( $ics, ts( '2026-11-10' ), ts( '2026-11-11' ), $ny );
eq( 'floating: busy() with NY default', $b, array( array( ts( '2026-11-10 14:00' ), ts( '2026-11-10 15:00' ) ) ) );

// 7. All-day single and multi-day.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:ad-1', 'DTSTART;VALUE=DATE:20261110', 'SUMMARY:Day off', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:ad-2', 'DTSTART;VALUE=DATE:20261120', 'DTEND;VALUE=DATE:20261123', 'END:VEVENT' ) );
$b   = JATCS_ICal::busy( $ics, ts( '2026-11-01' ), ts( '2026-12-01' ), $ny );
eq( 'all-day: single day busy NY midnight-midnight', $b[0], array( ts( '2026-11-10 00:00', 'America/New_York' ), ts( '2026-11-11 00:00', 'America/New_York' ) ) );
eq( 'all-day: multi-day 3 days', $b[1], array( ts( '2026-11-20 00:00', 'America/New_York' ), ts( '2026-11-23 00:00', 'America/New_York' ) ) );
eq( 'all-day: include_all_day=false', JATCS_ICal::busy( $ics, ts( '2026-11-01' ), ts( '2026-12-01' ), $ny, array( 'include_all_day' => false ) ), array() );
$ev  = JATCS_ICal::parse( $ics, $ny );
check( 'all-day: flag set', true === $ev[0]['all_day'] && true === $ev[1]['all_day'] );

// 8. DURATION.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:d1', 'DTSTART:20261105T150000Z', 'DURATION:PT1H30M', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:d2', 'DTSTART;TZID=America/New_York:20261031T120000', 'DURATION:P1D', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:d3', 'DTSTART;VALUE=DATE:20261201', 'DURATION:P1W', 'END:VEVENT' ) );
$ev  = JATCS_ICal::parse( $ics, $ny );
eq( 'duration: PT1H30M', $ev[0]['end'] - $ev[0]['start'], 5400 );
eq( 'duration: P1D is nominal across DST (25h)', $ev[1]['end'] - $ev[1]['start'], 25 * 3600 );
eq( 'duration: P1W all-day', $ev[2]['end'] - $ev[2]['start'], 7 * 86400 );

// 9. Zero-length timed event is not busy.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:z1', 'DTSTART:20261105T150000Z', 'END:VEVENT' ) );
eq( 'no DTEND timed: no busy time', JATCS_ICal::busy( $ics, ts( '2026-11-01' ), ts( '2026-11-10' ), $utc ), array() );

// 10. Folding with multibyte characters split across folds, escapes, quoted params.
$summary = 'Café ☕ planning — naïve résumé review 日本語テキスト';
$raw     = 'SUMMARY:' . $summary;
$folded  = substr( $raw, 0, 13 ) . "\r\n " . substr( $raw, 13, 10 ) . "\r\n\t" . substr( $raw, 23 );
$ics     = cal( array( 'BEGIN:VEVENT', 'UID:fold-1', 'DTSTART;TZID="America/New_York";X-FOO="a;b:c":20261105T100000', 'DTEND;TZID=America/New_York:20261105T110000', $folded, 'ATTENDEE;CN="Doe; John: Jr":mailto:j@example.com', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:esc-1', 'DTSTART:20261106T100000Z', 'SUMMARY:Lunch\, then meeting\; notes\nline2 \\\\ done', 'END:VEVENT' ) );
$ev      = JATCS_ICal::parse( $ics );
eq( 'folding: multibyte summary rejoined', $ev[0]['summary'], $summary );
eq( 'quoted params with ; and : parsed', $ev[0]['start'], ts( '2026-11-05 15:00' ) );
eq( 'escaped text', $ev[1]['summary'], "Lunch, then meeting; notes\nline2 \\ done" );

// 11. DAILY COUNT.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:daily', 'DTSTART:20261102T090000Z', 'DTEND:20261102T093000Z', 'RRULE:FREQ=DAILY;COUNT=5', 'END:VEVENT' ) );
$o   = occ( $ics, ts( '2026-10-01' ), ts( '2027-01-01' ) );
eq( 'DAILY COUNT=5', count( $o ), 5 );
eq( 'DAILY COUNT=5 last', loc( $o[4]['start'], 'UTC' ), '2026-11-06 09:00' );

// 12. WEEKLY BYDAY=MO,WE,FR INTERVAL=2.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:wk', 'DTSTART:20261102T090000Z', 'DURATION:PT1H', 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE,FR;COUNT=9', 'END:VEVENT' ) );
$o   = occ( $ics, ts( '2026-10-01' ), ts( '2027-01-01' ) );
eq( 'WEEKLY MO,WE,FR INTERVAL=2', array_map( function ( $s ) { return substr( $s, 5, 5 ); }, starts( $o, 'UTC' ) ), array( '11-02', '11-04', '11-06', '11-16', '11-18', '11-20', '11-30', '12-02', '12-04' ) );

// 13. WKST matters for WEEKLY INTERVAL>1 (RFC 5545 examples).
$mk = function ( $wkst ) {
	return cal( array( 'BEGIN:VEVENT', 'UID:wkst', 'DTSTART;TZID=America/New_York:19970805T090000', 'RRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=' . $wkst, 'END:VEVENT' ) );
};
eq( 'WKST=MO', array_map( function ( $s ) { return substr( $s, 5, 5 ); }, starts( occ( $mk( 'MO' ), ts( '1997-08-01' ), ts( '1997-10-01' ) ), 'America/New_York' ) ), array( '08-05', '08-10', '08-19', '08-24' ) );
eq( 'WKST=SU', array_map( function ( $s ) { return substr( $s, 5, 5 ); }, starts( occ( $mk( 'SU' ), ts( '1997-08-01' ), ts( '1997-10-01' ) ), 'America/New_York' ) ), array( '08-05', '08-17', '08-19', '08-31' ) );

// 14. MONTHLY BYDAY=-1FR.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:lastfri', 'DTSTART:20261127T170000Z', 'DURATION:PT1H', 'RRULE:FREQ=MONTHLY;BYDAY=-1FR;COUNT=3', 'END:VEVENT' ) );
eq( 'MONTHLY BYDAY=-1FR', array_map( function ( $s ) { return substr( $s, 0, 10 ); }, starts( occ( $ics, ts( '2026-11-01' ), ts( '2027-12-01' ) ), 'UTC' ) ), array( '2026-11-27', '2026-12-25', '2027-01-29' ) );

// 15. MONTHLY BYMONTHDAY=31 skips short months.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:md31', 'DTSTART:20260131T120000Z', 'DURATION:PT1H', 'RRULE:FREQ=MONTHLY;BYMONTHDAY=31;COUNT=4', 'END:VEVENT' ) );
eq( 'MONTHLY BYMONTHDAY=31', array_map( function ( $s ) { return substr( $s, 0, 10 ); }, starts( occ( $ics, ts( '2026-01-01' ), ts( '2027-01-01' ) ), 'UTC' ) ), array( '2026-01-31', '2026-03-31', '2026-05-31', '2026-07-31' ) );
$ics = cal( array( 'BEGIN:VEVENT', 'UID:mdneg', 'DTSTART:20260130T120000Z', 'DURATION:PT1H', 'RRULE:FREQ=MONTHLY;BYMONTHDAY=-2;COUNT=3', 'END:VEVENT' ) );
eq( 'MONTHLY BYMONTHDAY=-2', array_map( function ( $s ) { return substr( $s, 0, 10 ); }, starts( occ( $ics, ts( '2026-01-01' ), ts( '2027-01-01' ) ), 'UTC' ) ), array( '2026-01-30', '2026-02-27', '2026-03-30' ) );

// 16. YEARLY Feb 29.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:leap', 'DTSTART;VALUE=DATE:20240229', 'RRULE:FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29;COUNT=3', 'END:VEVENT' ) );
eq( 'YEARLY BYMONTH=2 BYMONTHDAY=29', array_map( function ( $s ) { return substr( $s, 0, 10 ); }, starts( occ( $ics, ts( '2024-01-01' ), ts( '2040-01-01' ) ), 'UTC' ) ), array( '2024-02-29', '2028-02-29', '2032-02-29' ) );

// 17. BYSETPOS=-1 last weekday of month.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:setpos', 'DTSTART;TZID=America/New_York:20260130T160000', 'DURATION:PT1H', 'RRULE:FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;COUNT=4', 'END:VEVENT' ) );
eq( 'BYSETPOS=-1 last weekday', array_map( function ( $s ) { return substr( $s, 0, 10 ); }, starts( occ( $ics, ts( '2026-01-01' ), ts( '2027-01-01' ) ), 'America/New_York' ) ), array( '2026-01-30', '2026-02-27', '2026-03-31', '2026-04-30' ) );

// 18. BYWEEKNO and BYYEARDAY (RFC examples).
$ics = cal( array( 'BEGIN:VEVENT', 'UID:wn', 'DTSTART;TZID=America/New_York:19970512T090000', 'RRULE:FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO;COUNT=3', 'END:VEVENT' ) );
eq( 'YEARLY BYWEEKNO=20;BYDAY=MO', array_map( function ( $s ) { return substr( $s, 0, 10 ); }, starts( occ( $ics, ts( '1997-01-01' ), ts( '2000-01-01' ) ), 'America/New_York' ) ), array( '1997-05-12', '1998-05-11', '1999-05-17' ) );
$ics = cal( array( 'BEGIN:VEVENT', 'UID:yd', 'DTSTART;TZID=America/New_York:19970101T090000', 'RRULE:FREQ=YEARLY;INTERVAL=3;COUNT=4;BYYEARDAY=1,100,200', 'END:VEVENT' ) );
eq( 'YEARLY BYYEARDAY=1,100,200', array_map( function ( $s ) { return substr( $s, 0, 10 ); }, starts( occ( $ics, ts( '1997-01-01' ), ts( '2001-01-01' ) ), 'America/New_York' ) ), array( '1997-01-01', '1997-04-10', '1997-07-19', '2000-01-01' ) );

// 19. UNTIL inclusive; UNTIL as date.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:until', 'DTSTART:20261102T090000Z', 'DURATION:PT1H', 'RRULE:FREQ=DAILY;UNTIL=20261105T090000Z', 'END:VEVENT' ) );
eq( 'UNTIL inclusive', count( occ( $ics, ts( '2026-11-01' ), ts( '2026-12-01' ) ) ), 4 );
$ics = cal( array( 'BEGIN:VEVENT', 'UID:untild', 'DTSTART;VALUE=DATE:20261102', 'RRULE:FREQ=DAILY;UNTIL=20261104', 'END:VEVENT' ) );
eq( 'UNTIL date on all-day', count( occ( $ics, ts( '2026-11-01' ), ts( '2026-12-01' ), 'America/New_York' ) ), 3 );

// 20. EXDATE (multiple lines and comma list), RDATE (incl. PERIOD).
$ics = cal( array( 'BEGIN:VEVENT', 'UID:ex', 'DTSTART;TZID=America/New_York:20261102T090000', 'DTEND;TZID=America/New_York:20261102T100000', 'RRULE:FREQ=DAILY;COUNT=6', 'EXDATE;TZID=America/New_York:20261104T090000', 'EXDATE:20261105T140000Z,20261106T140000Z', 'RDATE;TZID=America/New_York:20261120T090000', 'RDATE;VALUE=PERIOD:20261121T150000Z/PT2H', 'END:VEVENT' ) );
$o   = occ( $ics, ts( '2026-11-01' ), ts( '2026-12-01' ) );
$s   = starts( $o, 'America/New_York' );
check( 'EXDATE TZID removes one', ! in_array( '2026-11-04 09:00', $s, true ) );
check( 'EXDATE UTC comma list removes two', ! in_array( '2026-11-05 09:00', $s, true ) && ! in_array( '2026-11-06 09:00', $s, true ) );
check( 'RDATE adds one', in_array( '2026-11-20 09:00', $s, true ) );
eq( 'RDATE PERIOD with own duration', $o[ count( $o ) - 1 ]['end'] - $o[ count( $o ) - 1 ]['start'], 7200 );
eq( 'EXDATE/RDATE total', count( $o ), 6 - 3 + 2 );

// 21. RECURRENCE-ID move and cancel; cancelled/transparent events.
$ics = cal(
	array(
		'BEGIN:VEVENT', 'UID:series', 'SEQUENCE:1', 'DTSTART;TZID=America/New_York:20261102T090000', 'DTEND;TZID=America/New_York:20261102T100000', 'RRULE:FREQ=WEEKLY;COUNT=4', 'SUMMARY:Series', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:series', 'RECURRENCE-ID;TZID=America/New_York:20261109T090000', 'DTSTART;TZID=America/New_York:20261110T140000', 'DTEND;TZID=America/New_York:20261110T150000', 'SUMMARY:Moved', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:series', 'RECURRENCE-ID:20261116T140000Z', 'DTSTART;TZID=America/New_York:20261116T090000', 'DTEND;TZID=America/New_York:20261116T100000', 'STATUS:CANCELLED', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:cx', 'DTSTART:20261103T150000Z', 'DTEND:20261103T160000Z', 'STATUS:CANCELLED', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:tr', 'DTSTART:20261103T170000Z', 'DTEND:20261103T180000Z', 'TRANSP:TRANSPARENT', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:tent', 'DTSTART:20261103T190000Z', 'DTEND:20261103T200000Z', 'STATUS:TENTATIVE', 'END:VEVENT',
	)
);
$o   = occ( $ics, ts( '2026-11-01' ), ts( '2026-12-01' ) );
$s   = array();
foreach ( $o as $x ) {
	if ( 'series' === $x['uid'] ) {
		$s[] = loc( $x['start'], 'America/New_York' );
	}
}
eq( 'RECURRENCE-ID moves one, CANCELLED override removes one', $s, array( '2026-11-02 09:00', '2026-11-10 14:00', '2026-11-23 09:00' ) );
$b = JATCS_ICal::busy( $ics, ts( '2026-11-03' ), ts( '2026-11-04' ), $utc );
eq( 'CANCELLED + TRANSPARENT ignored, TENTATIVE kept', $b, array( array( ts( '2026-11-03 19:00' ), ts( '2026-11-03 20:00' ) ) ) );
eq( 'include_tentative=false', JATCS_ICal::busy( $ics, ts( '2026-11-03' ), ts( '2026-11-04' ), $utc, array( 'include_tentative' => false ) ), array() );

// 22. Event starting before the range but overlapping (single and recurring overnight shift).
$ics = cal( array( 'BEGIN:VEVENT', 'UID:ov1', 'DTSTART:20261101T220000Z', 'DTEND:20261102T060000Z', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:ov2', 'DTSTART:20260101T220000Z', 'DTEND:20260102T070000Z', 'RRULE:FREQ=DAILY', 'END:VEVENT' ) );
$b   = JATCS_ICal::busy( $ics, ts( '2026-11-02 00:00' ), ts( '2026-11-02 12:00' ), $utc );
eq( 'overlap from before range included (clipped)', $b, array( array( ts( '2026-11-02 00:00' ), ts( '2026-11-02 07:00' ) ) ) );

// 23. HOURLY support.
$ics = cal( array( 'BEGIN:VEVENT', 'UID:hourly', 'DTSTART:20261102T080000Z', 'DURATION:PT15M', 'RRULE:FREQ=HOURLY;INTERVAL=2;BYHOUR=8,10,12;COUNT=5', 'END:VEVENT' ) );
eq( 'HOURLY INTERVAL=2 BYHOUR', array_map( function ( $s ) { return substr( $s, 5 ); }, starts( occ( $ics, ts( '2026-11-01' ), ts( '2026-11-10' ) ), 'UTC' ) ), array( '11-02 08:00', '11-02 10:00', '11-02 12:00', '11-03 08:00', '11-03 10:00' ) );

// 24. Malicious RRULEs complete quickly and are conservative.
$t0  = microtime( true );
$ics = cal( array( 'BEGIN:VEVENT', 'UID:evil', 'DTSTART:20261102T090000Z', 'DURATION:PT1S', 'RRULE:FREQ=SECONDLY;COUNT=99999999', 'END:VEVENT' ) );
$b   = JATCS_ICal::busy( $ics, ts( '2026-11-01' ), ts( '2026-11-30' ), $utc );
$t1  = microtime( true ) - $t0;
check( 'SECONDLY COUNT=99999999 < 1s', $t1 < 1.0, sprintf( '%.3fs', $t1 ) );
check( 'SECONDLY: conservative busy to window end', is_array( $b ) && 1 === count( $b ) && ts( '2026-11-02 09:00' ) === $b[0][0] && ts( '2026-11-30' ) === $b[0][1], var_export( $b, true ) );
$t0  = microtime( true );
$ics = cal( array( 'BEGIN:VEVENT', 'UID:evil2', 'DTSTART:19000101T000000Z', 'DURATION:PT1S', 'RRULE:FREQ=MINUTELY;COUNT=999999999', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:evil3', 'DTSTART:20261102T000000Z', 'RRULE:FREQ=DAILY;BYHOUR=0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23;BYMINUTE=0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,53,54,55,56,57,58,59;BYSECOND=0,10,20,30,40,50', 'DURATION:PT1S', 'END:VEVENT' ) );
$o   = occ( $ics, ts( '2026-11-01' ), ts( '2026-11-30' ) );
$t1  = microtime( true ) - $t0;
check( 'ancient MINUTELY + dense DAILY < 1s', $t1 < 1.0, sprintf( '%.3fs', $t1 ) );
check( 'capped occurrences flagged', count( $o ) <= 2 * JATCS_ICal::MAX_OCCURRENCES + 2 && ! empty( array_filter( $o, function ( $x ) { return ! empty( $x['capped'] ); } ) ) );

// 25. Garbage input.
mt_srand( 42 );
$junk = '';
for ( $i = 0; $i < 2000000; $i++ ) {
	$junk .= chr( mt_rand( 0, 255 ) );
}
$r = JATCS_ICal::parse( $junk );
check( 'random 2MB garbage returns false', false === $r );
check( 'last_error set', '' !== JATCS_ICal::last_error() );
eq( 'busy() on garbage returns false', JATCS_ICal::busy( 'hello world', 0, 100, $utc ), false );
$r = JATCS_ICal::parse( "BEGIN:VCALENDAR\n" . substr( $junk, 0, 300000 ) . "\nBEGIN:VEVENT\nDTSTART:2026BAD\nDTSTART;TZID=\"unterminated:x\nRRULE:FREQ=YEARLY;BYMONTH=99;BYDAY=99XX\nEND:VEVENT\nBEGIN:VEVENT\nDTSTART:20261105T150000Z\nRRULE:FREQ=BOGUS\nEXDATE:nonsense\nDURATION:PXYZ\nEND:VEVENT\nEND:VCALENDAR" );
check( 'garbage with markers parses without warnings', is_array( $r ) && 1 === count( $r ) && null === $r[0]['rrule'] );
eq( 'non-string input returns false', JATCS_ICal::parse( array() ), false );

// 26. BOM + concatenated VCALENDARs + nested VALARM/VTODO.
$ics = "\xEF\xBB\xBF" . cal( array( 'BEGIN:VEVENT', 'UID:c1', 'DTSTART:20261105T150000Z', 'DURATION:PT1H', 'BEGIN:VALARM', 'UID:alarm', 'SUMMARY:alarm text', 'TRIGGER:-PT5M', 'END:VALARM', 'SUMMARY:Real', 'END:VEVENT' ) ) . "\xEF\xBB\xBF" . cal( array( 'BEGIN:VTODO', 'UID:t', 'DTSTART:20261105T170000Z', 'END:VTODO', 'BEGIN:VEVENT', 'UID:c2', 'DTSTART:20261106T150000Z', 'DURATION:PT1H', 'END:VEVENT' ) );
$ev  = JATCS_ICal::parse( $ics );
eq( 'concatenated calendars, VTODO ignored', count( $ev ), 2 );
eq( 'VALARM properties ignored', $ev[0]['uid'] . '|' . $ev[0]['summary'], 'c1|Real' );

// 27. Sling fixture + last_event_end.
$sling = file_get_contents( $fx . 'sling.ics' );
$ev    = JATCS_ICal::parse( $sling, $ny );
eq( 'sling: 7 events parsed', count( $ev ), 7 );
check( 'sling: uids @getsling.com', 7 === count( array_filter( $ev, function ( $e ) { return '@getsling.com' === substr( $e['uid'], -13 ); } ) ) );
eq( 'sling: overnight shift end', $ev[2]['end'], ts( '2026-11-08 01:00', 'America/New_York' ) );
eq( 'sling: last_event_end = last shift end', JATCS_ICal::last_event_end( $sling, ts( '2026-11-02 00:00', 'America/New_York' ), $ny ), ts( '2026-11-14 23:30', 'America/New_York' ) );
eq( 'sling: last_event_end none after', JATCS_ICal::last_event_end( $sling, ts( '2026-12-01' ), $ny ), 0 );
eq( 'sling: busy has 6 shifts (cancelled ignored)', count( JATCS_ICal::busy( $sling, ts( '2026-11-01' ), ts( '2026-12-01' ), $utc ) ), 6 );

// 28. Outlook fixture.
$out = file_get_contents( $fx . 'outlook.ics' );
$o   = occ( $out, ts( '2026-10-01' ), ts( '2027-01-01' ) );
$sync = array_values( array_filter( $o, function ( $x ) { return 0 === strpos( $x['summary'], 'Agency' ); } ) );
$s    = starts( $sync, 'America/New_York' );
eq( 'outlook: 10 instances (11 - EXDATE)', count( $sync ), 10 );
check( 'outlook: exception moved', in_array( '2026-11-11 14:00', $s, true ) && ! in_array( '2026-11-10 10:00', $s, true ) );
check( 'outlook: EXDATE applied', ! in_array( '2026-11-24 10:00', $s, true ) );
check( 'outlook: UNTIL inclusive (Dec 15)', in_array( '2026-12-15 10:00', $s, true ) );
eq( 'outlook: Nov 3 10:00 EST = 15:00Z', $sync[4]['start'], ts( '2026-11-03 15:00' ) );
$b = JATCS_ICal::busy( $out, ts( '2026-11-04' ), ts( '2026-11-05' ), $utc );
eq( 'outlook: free lunch not busy', $b, array() );

// 29. iCloud fixture.
$icl = file_get_contents( $fx . 'icloud.ics' );
$ev  = JATCS_ICal::parse( $icl, $utc );
eq( 'icloud: 3 events (VTODO ignored)', count( $ev ), 3 );
eq( 'icloud: UID not overridden by VALARM', $ev[0]['uid'], '5B2A9C1E-3F4D-4E5A-9B8C-7D6E5F4A3B2C' );
$o = JATCS_ICal::expand( $ev, ts( '2026-11-01' ), ts( '2026-12-01' ), $utc );
$climb = array_values( array_filter( $o, function ( $x ) { return 'Climbing' === $x['summary']; } ) );
eq( 'icloud: 4 Wednesdays in Nov at 18:30 local', starts( $climb, 'America/New_York' ), array( '2026-11-04 18:30', '2026-11-11 18:30', '2026-11-18 18:30', '2026-11-25 18:30' ) );
$b = JATCS_ICal::busy( $icl, ts( '2026-11-26', 'America/New_York' ), ts( '2026-11-28', 'America/New_York' ), $utc );
eq( 'icloud: all-day uses X-WR-TIMEZONE day; birthday transparent', $b, array( array( ts( '2026-11-26', 'America/New_York' ), ts( '2026-11-27', 'America/New_York' ) ) ) );

// 30. build_event round trip.
$desc  = str_repeat( 'Ünïcødé ✓ 予約 ', 15 );
$desc  = mb_substr( $desc, 0, 200 );
$built = JATCS_ICal::build_event(
	array(
		'uid'             => 'booking-123@example.com',
		'summary'         => "Consult, Smith; Jane\nSecond line \\ end",
		'description'     => $desc,
		'location'        => 'Office, Suite 2',
		'start'           => ts( '2026-11-05 15:00' ),
		'end'             => ts( '2026-11-05 16:00' ),
		'timezone'        => 'America/New_York',
		'url'             => 'https://example.com/booking/123',
		'organizer_email' => 'info@example.com',
		'organizer_name'  => 'Example "Biz", LLC',
		'attendee_email'  => 'jane@example.com',
		'attendee_name'   => 'Jane Smith',
		'sequence'        => 2,
	)
);
$lines = explode( "\r\n", rtrim( $built, "\r\n" ) );
check( 'build: CRLF endings, no bare LF', substr( $built, -2 ) === "\r\n" && false === strpos( str_replace( "\r\n", '', $built ), "\n" ) );
check( 'build: all lines <= 75 octets', max( array_map( 'strlen', $lines ) ) <= 75 );
check( 'build: every physical line valid UTF-8', count( array_filter( $lines, function ( $l ) { return 1 === preg_match( '//u', $l ); } ) ) === count( $lines ) );
check( 'build: PRODID', false !== strpos( $built, "PRODID:-//JAT Calendar Sync//EN\r\n" ) );
check( 'build: description folded', count( $lines ) > 20 );
$rt = JATCS_ICal::parse( $built );
eq( 'build: round-trip start/end', array( $rt[0]['start'], $rt[0]['end'] ), array( ts( '2026-11-05 15:00' ), ts( '2026-11-05 16:00' ) ) );
eq( 'build: round-trip summary', $rt[0]['summary'], "Consult, Smith; Jane\nSecond line \\ end" );
eq( 'build: round-trip uid/sequence', $rt[0]['uid'] . '|' . $rt[0]['sequence'], 'booking-123@example.com|2' );
preg_match( '/DESCRIPTION:(.*?)\r\n(?! )/s', $built, $m );
$unfolded = str_replace( "\r\n ", '', $m[1] );
eq( 'build: description round-trip', $unfolded, $desc );
eq( 'build: invalid input returns empty string', JATCS_ICal::build_event( array( 'start' => 100, 'end' => 50 ) ), '' );

// Truncated data (a download cut off part-way) must be refused, not half-read.
$full = $built;
check( 'truncated: mid-event cut refused', false === JATCS_ICal::parse( substr( $full, 0, (int) ( strlen( $full ) * 0.6 ) ) ) );
check( 'truncated: missing END:VCALENDAR refused', false === JATCS_ICal::parse( str_replace( "END:VCALENDAR\r\n", '', $full ) ) );
check( 'truncated: busy() returns false', false === JATCS_ICal::busy( substr( $full, 0, 200 ), 0, 2000000000, new DateTimeZone( 'UTC' ) ) );
check( 'complete data still parses', is_array( JATCS_ICal::parse( $full ) ) );

// Summary.
if ( ! empty( $GLOBALS['jatcs_warnings'] ) ) {
	check( 'no stray PHP warnings', false );
}
printf( "\n%d passed, %d failed\n", $GLOBALS['jatcs_pass'], $GLOBALS['jatcs_fail'] );
exit( $GLOBALS['jatcs_fail'] > 0 ? 1 : 0 );
