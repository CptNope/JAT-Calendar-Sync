<?php
/**
 * CLI tests for JATCS_CalDAV (no WordPress, no PHPUnit).
 *
 * Usage: php tests/caldav-test.php
 *
 * @package JATCalendarSync
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['jatcs_failures'] = 0;
$GLOBALS['jatcs_passes']   = 0;

set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) {
		$GLOBALS['jatcs_failures']++;
		echo "FAIL: PHP notice/warning [$errno] $errstr at $errfile:$errline\n";
		return true;
	}
);

/**
 * Minimal WP_Error stub.
 */
class WP_Error {
	/** @var string */
	private $code;
	/** @var string */
	private $message;
	/** @var mixed */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param mixed  $data    Data.
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	/** @return string */
	public function get_error_code() {
		return $this->code;
	}

	/** @return string */
	public function get_error_message() {
		return $this->message;
	}

	/** @return mixed */
	public function get_error_data() {
		return $this->data;
	}
}

/**
 * Translation stub.
 *
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {
	return $text;
}

require dirname( __DIR__ ) . '/includes/class-jatcs-caldav.php';

/**
 * Assert helper.
 *
 * @param bool   $cond  Condition.
 * @param string $label Label.
 * @return void
 */
function check( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['jatcs_passes']++;
		echo "PASS: $label\n";
	} else {
		$GLOBALS['jatcs_failures']++;
		echo "FAIL: $label\n";
	}
}

/**
 * Error code of a value, or '' when not an error.
 *
 * @param mixed $v Value.
 * @return string
 */
function code_of( $v ) {
	return $v instanceof WP_Error ? $v->get_error_code() : '';
}

/**
 * Fake HTTP server: routes "METHOD url" to responses and records requests.
 */
class Fake_Server {
	/** @var array */
	public $routes = array();
	/** @var array */
	public $log = array();

	/**
	 * Add a route.
	 *
	 * @param string         $method Method.
	 * @param string         $url    URL.
	 * @param array|callable $resp   Response array or callable( $headers, $body ).
	 * @return $this
	 */
	public function on( $method, $url, $resp ) {
		$this->routes[ $method . ' ' . $url ] = $resp;
		return $this;
	}

	/**
	 * Transport entry point.
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @return array
	 */
	public function __invoke( $method, $url, array $headers, $body ) {
		$this->log[] = compact( 'method', 'url', 'headers', 'body' );
		$key         = $method . ' ' . $url;
		if ( ! isset( $this->routes[ $key ] ) ) {
			return array( 'code' => 404, 'headers' => array(), 'body' => '' );
		}
		$r = $this->routes[ $key ];
		if ( is_callable( $r ) ) {
			$r = $r( $headers, $body );
		}
		return $r + array( 'headers' => array(), 'body' => '' );
	}

	/**
	 * Find logged requests by URL.
	 *
	 * @param string $method Method.
	 * @param string $url    URL.
	 * @return array|null Last matching request.
	 */
	public function req( $method, $url ) {
		$found = null;
		foreach ( $this->log as $entry ) {
			if ( $entry['method'] === $method && $entry['url'] === $url ) {
				$found = $entry;
			}
		}
		return $found;
	}
}

/**
 * Build a 207 response.
 *
 * @param string $xml Body.
 * @return array
 */
function ms( $xml ) {
	return array(
		'code'    => 207,
		'headers' => array( 'content-type' => 'application/xml; charset=utf-8' ),
		'body'    => $xml,
	);
}

/**
 * Response that requires correct Basic auth.
 *
 * @param array  $resp Response when authorized.
 * @param string $user User.
 * @param string $pass Pass.
 * @return callable
 */
function authed( $resp, $user, $pass ) {
	return function ( $headers ) use ( $resp, $user, $pass ) {
		$want = 'Basic ' . base64_encode( $user . ':' . $pass );
		if ( ! isset( $headers['Authorization'] ) || $headers['Authorization'] !== $want ) {
			return array( 'code' => 401, 'headers' => array( 'www-authenticate' => 'Basic realm="x"' ), 'body' => '' );
		}
		return $resp;
	};
}

/* ====================================================================
 * 1. iCloud-like server
 * ==================================================================== */
$icloud = new Fake_Server();
$u      = 'me@icloud.com';
$pw     = 'abcd-efgh-ijkl-mnop';
$icloud->on( 'PROPFIND', 'https://caldav.icloud.com/.well-known/caldav', array( 'code' => 301, 'headers' => array( 'location' => '/' ) ) );
$icloud->on(
	'PROPFIND',
	'https://caldav.icloud.com/',
	authed(
		ms( '<?xml version="1.0" encoding="UTF-8"?><multistatus xmlns="DAV:"><response><href>/</href><propstat><prop><current-user-principal><href>/123456789/principal/</href></current-user-principal></prop><status>HTTP/1.1 200 OK</status></propstat><propstat><prop><calendar-home-set xmlns="urn:ietf:params:xml:ns:caldav"/></prop><status>HTTP/1.1 404 Not Found</status></propstat></response></multistatus>' ),
		$u,
		$pw
	)
);
$icloud->on(
	'PROPFIND',
	'https://caldav.icloud.com/123456789/principal/',
	authed(
		ms( '<?xml version="1.0" encoding="UTF-8"?><multistatus xmlns="DAV:"><response><href>/123456789/principal/</href><propstat><prop><calendar-home-set xmlns="urn:ietf:params:xml:ns:caldav"><href xmlns="DAV:">https://p42-caldav.icloud.com:443/123456789/calendars/</href></calendar-home-set></prop><status>HTTP/1.1 200 OK</status></propstat></response></multistatus>' ),
		$u,
		$pw
	)
);
$icloud_home_xml = '<?xml version="1.0" encoding="UTF-8"?>
<multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:CS="http://calendarserver.org/ns/" xmlns:A="http://apple.com/ns/ical/">
 <response><href>/123456789/calendars/</href><propstat><prop><resourcetype><collection/></resourcetype></prop><status>HTTP/1.1 200 OK</status></propstat></response>
 <response><href>/123456789/calendars/home/</href><propstat><prop>
   <resourcetype><collection/><C:calendar/></resourcetype><displayname>Home</displayname>
   <A:calendar-color>#FF2968FF</A:calendar-color><CS:getctag>HwoQEgwAAA</CS:getctag>
   <current-user-privilege-set><privilege><read/></privilege><privilege><write/></privilege></current-user-privilege-set>
   <C:supported-calendar-component-set><C:comp name="VEVENT"/></C:supported-calendar-component-set>
 </prop><status>HTTP/1.1 200 OK</status></propstat></response>
 <response><href>/123456789/calendars/reminders/</href><propstat><prop>
   <resourcetype><collection/><C:calendar/></resourcetype><displayname>Reminders</displayname>
   <current-user-privilege-set><privilege><all/></privilege></current-user-privilege-set>
   <C:supported-calendar-component-set><C:comp name="VTODO"/></C:supported-calendar-component-set>
 </prop><status>HTTP/1.1 200 OK</status></propstat></response>
 <response><href>/123456789/calendars/holidays/</href><propstat><prop>
   <resourcetype><collection/><CS:subscribed/></resourcetype><displayname>US Holidays</displayname>
 </prop><status>HTTP/1.1 200 OK</status></propstat></response>
 <response><href>/123456789/calendars/inbox/</href><propstat><prop>
   <resourcetype><collection/><C:schedule-inbox/></resourcetype><displayname>Inbox</displayname>
 </prop><status>HTTP/1.1 200 OK</status></propstat></response>
</multistatus>';
$icloud->on( 'PROPFIND', 'https://p42-caldav.icloud.com:443/123456789/calendars/', authed( ms( $icloud_home_xml ), $u, $pw ) );

$c    = new JATCS_CalDAV( 'https://caldav.icloud.com/', $u, $pw, $icloud );
$home = $c->discover();
check( 'https://p42-caldav.icloud.com:443/123456789/calendars/' === $home, 'iCloud: discover follows well-known redirect and finds home on p42 host' );
$wk = $icloud->req( 'PROPFIND', 'https://caldav.icloud.com/.well-known/caldav' );
check( $wk && '0' === $wk['headers']['Depth'], 'iCloud: well-known PROPFIND uses Depth: 0' );
$cals = $c->calendars( $home );
check( is_array( $cals ) && 3 === count( $cals ), 'iCloud: 3 calendars (home, reminders, subscribed), inbox excluded' );
$p42 = $icloud->req( 'PROPFIND', 'https://p42-caldav.icloud.com:443/123456789/calendars/' );
check( $p42 && isset( $p42['headers']['Authorization'] ), 'iCloud: auth sent to same-site p42-caldav.icloud.com' );
check( $p42 && '1' === $p42['headers']['Depth'], 'iCloud: calendars PROPFIND uses Depth: 1' );
$by = array();
foreach ( (array) $cals as $cal ) {
	$by[ $cal['name'] ] = $cal;
}
check( isset( $by['Home'] ) && 'https://p42-caldav.icloud.com:443/123456789/calendars/home/' === $by['Home']['url'], 'iCloud: calendar URL resolved to absolute on home host' );
check( isset( $by['Home'] ) && '#FF2968' === $by['Home']['color'] && 'HwoQEgwAAA' === $by['Home']['ctag'], 'iCloud: color (alpha stripped) and ctag' );
check( isset( $by['Home'] ) && true === $by['Home']['writable'] && true === $by['Home']['events'], 'iCloud: Home writable + events' );
check( isset( $by['Reminders'] ) && true === $by['Reminders']['writable'] && false === $by['Reminders']['events'], 'iCloud: VTODO-only calendar has events=false, all privilege => writable' );
check( isset( $by['US Holidays'] ) && false === $by['US Holidays']['writable'] && true === $by['US Holidays']['events'], 'iCloud: subscribed calendar read-only' );
check( ! isset( $by['Inbox'] ), 'iCloud: schedule inbox excluded' );
$t = $c->test();
check( is_array( $t ) && 3 === count( $t ), 'iCloud: test() returns calendar list' );

/* ====================================================================
 * 2. Nextcloud-like server (d:, cal:, oc:, cs: prefixes)
 * ==================================================================== */
$nc = new Fake_Server();
$nc->on(
	'PROPFIND',
	'https://cloud.example.com/remote.php/dav',
	ms( '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"><d:response><d:href>/remote.php/dav/</d:href><d:propstat><d:prop><d:current-user-principal><d:href>/remote.php/dav/principals/users/jeremy/</d:href></d:current-user-principal></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat><d:propstat><d:prop><cal:calendar-home-set/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat></d:response></d:multistatus>' )
);
$nc->on(
	'PROPFIND',
	'https://cloud.example.com/remote.php/dav/principals/users/jeremy/',
	ms( '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:response><d:href>/remote.php/dav/principals/users/jeremy/</d:href><d:propstat><d:prop><cal:calendar-home-set><d:href>/remote.php/dav/calendars/jeremy/</d:href></cal:calendar-home-set></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>' )
);
$nc->on(
	'PROPFIND',
	'https://cloud.example.com/remote.php/dav/calendars/jeremy/',
	ms(
		'<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns" xmlns:x1="http://apple.com/ns/ical/">
 <d:response><d:href>/remote.php/dav/calendars/jeremy/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>
 <d:response><d:href>/remote.php/dav/calendars/jeremy/personal/</d:href>
  <d:propstat><d:prop><d:resourcetype><d:collection/><cal:calendar/></d:resourcetype><d:displayname>Personal</d:displayname><x1:calendar-color>#0082c9</x1:calendar-color>
   <d:current-user-privilege-set><d:privilege><d:write/></d:privilege><d:privilege><d:read/></d:privilege></d:current-user-privilege-set>
   <cal:supported-calendar-component-set><cal:comp name="VEVENT"/><cal:comp name="VTODO"/></cal:supported-calendar-component-set>
   <cs:getctag>http://sabre.io/ns/sync/42</cs:getctag><d:sync-token>http://sabre.io/ns/sync/42</d:sync-token></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
 </d:response>
 <d:response><d:href>/remote.php/dav/calendars/jeremy/contact_birthdays/</d:href>
  <d:propstat><d:prop><d:resourcetype><d:collection/><cal:calendar/></d:resourcetype><d:displayname>Contact birthdays</d:displayname>
   <d:current-user-privilege-set><d:privilege><d:read/></d:privilege><d:privilege><cal:read-free-busy/></d:privilege></d:current-user-privilege-set></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  <d:propstat><d:prop><cs:getctag/><oc:owner-principal/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>
 </d:response>
 <d:response><d:href>/remote.php/dav/calendars/jeremy/trashbin/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/><nc:trash-bin/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>
 <d:response><d:href>/remote.php/dav/calendars/jeremy/no%20name/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/><cal:calendar/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>
</d:multistatus>'
	)
);
$c    = new JATCS_CalDAV( 'https://cloud.example.com/remote.php/dav', 'jeremy', 'nc-app-pass', $nc );
$home = $c->discover();
check( 'https://cloud.example.com/remote.php/dav/calendars/jeremy/' === $home, 'Nextcloud: discover via principal with d:/cal: prefixes, no well-known for non-root path' );
check( null === $nc->req( 'PROPFIND', 'https://cloud.example.com/.well-known/caldav' ), 'Nextcloud: well-known skipped when base has a path' );
$cals = $c->calendars( $home );
$by   = array();
foreach ( (array) $cals as $cal ) {
	$by[ $cal['name'] ] = $cal;
}
check( is_array( $cals ) && 3 === count( $cals ) && ! isset( $by['trashbin'] ), 'Nextcloud: 3 calendars, trash-bin excluded' );
check( isset( $by['Personal'] ) && $by['Personal']['writable'] && '#0082C9' === $by['Personal']['color'] && 'http://sabre.io/ns/sync/42' === $by['Personal']['ctag'], 'Nextcloud: Personal writable/color/ctag' );
check( isset( $by['Contact birthdays'] ) && false === $by['Contact birthdays']['writable'] && '' === $by['Contact birthdays']['ctag'], 'Nextcloud: read-only birthdays, 404 propstat ignored' );
check( isset( $by['no name'] ) && true === $by['no name']['writable'] && true === $by['no name']['events'], 'Nextcloud: missing displayname uses decoded path segment; absent privileges/components default true' );

/* ====================================================================
 * 3. Fastmail-like server (default namespace xmlns="DAV:")
 * ==================================================================== */
$fm = new Fake_Server();
$fm->on( 'PROPFIND', 'https://caldav.fastmail.com/dav/', ms( '<?xml version="1.0" encoding="utf-8"?><multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><response><href>/dav/</href><propstat><prop><current-user-principal><href>/dav/principals/user/me%40fastmail.com/</href></current-user-principal><C:calendar-home-set><href>/dav/calendars/user/me%40fastmail.com/</href></C:calendar-home-set></prop><status>HTTP/1.1 200 OK</status></propstat></response></multistatus>' ) );
$fm->on( 'PROPFIND', 'https://caldav.fastmail.com/dav/calendars/user/me%40fastmail.com/', ms( '<?xml version="1.0" encoding="utf-8"?><multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><response><href>/dav/calendars/user/me%40fastmail.com/Default/</href><propstat><prop><resourcetype><collection/><C:calendar/></resourcetype><displayname>Calendar</displayname><sync-token>https://fastmail.com/sync/abc</sync-token></prop><status>HTTP/1.1 200 OK</status></propstat></response></multistatus>' ) );
$c    = new JATCS_CalDAV( 'https://caldav.fastmail.com/dav/', 'me@fastmail.com', 'fm-pass', $fm );
$home = $c->discover();
check( 'https://caldav.fastmail.com/dav/calendars/user/me%40fastmail.com/' === $home, 'Fastmail: home-set returned in same PROPFIND is used directly' );
check( 1 === count( $fm->log ), 'Fastmail: discovery needed only one request' );
$cals = $c->calendars( $home );
check( is_array( $cals ) && 1 === count( $cals ) && 'https://fastmail.com/sync/abc' === $cals[0]['ctag'] && 'https://caldav.fastmail.com/dav/calendars/user/me%40fastmail.com/Default/' === $cals[0]['url'], 'Fastmail: default namespace parsed, sync-token used as ctag' );

/* ====================================================================
 * 4. events_in_range
 * ==================================================================== */
$cal_url = 'https://cloud.example.com/remote.php/dav/calendars/jeremy/personal/';
$ev      = new Fake_Server();
$ev->on(
	'REPORT',
	$cal_url,
	ms(
		'<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
 <d:response><d:href>/remote.php/dav/calendars/jeremy/personal/a.ics</d:href><d:propstat><d:prop><d:getetag>"etag-a"</d:getetag><cal:calendar-data><![CDATA[BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:a
SUMMARY:Dinner & <drinks>
END:VEVENT
END:VCALENDAR
]]></cal:calendar-data></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>
 <d:response><d:href>b.ics</d:href><d:propstat><d:prop><d:getetag>"etag-b"</d:getetag><cal:calendar-data>BEGIN:VCALENDAR&#13;
BEGIN:VEVENT&#13;
UID:b&#13;
SUMMARY:Tom &amp; Jerry &lt;3&#13;
END:VEVENT&#13;
END:VCALENDAR&#13;
</cal:calendar-data></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>
 <d:response><d:href>/remote.php/dav/calendars/jeremy/personal/gone.ics</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>
</d:multistatus>'
	)
);
$c   = new JATCS_CalDAV( 'https://cloud.example.com/remote.php/dav', 'jeremy', 'nc-app-pass', $ev );
$res = $c->events_in_range( $cal_url, gmmktime( 0, 0, 0, 11, 1, 2026 ), new DateTime( '2026-11-08 00:00:00', new DateTimeZone( 'UTC' ) ) );
check( is_array( $res ) && 2 === count( $res ), 'events: 2 events returned, 404 response skipped' );
check( isset( $res[0] ) && 'https://cloud.example.com/remote.php/dav/calendars/jeremy/personal/a.ics' === $res[0]['href'] && '"etag-a"' === $res[0]['etag'], 'events: absolute href and quoted etag preserved' );
check( isset( $res[0] ) && false !== strpos( $res[0]['data'], 'SUMMARY:Dinner & <drinks>' ), 'events: CDATA calendar-data returned raw' );
check( isset( $res[1] ) && false !== strpos( $res[1]['data'], "SUMMARY:Tom & Jerry <3\r\n" ) && 'https://cloud.example.com/remote.php/dav/calendars/jeremy/personal/b.ics' === $res[1]['href'], 'events: entity-escaped calendar-data decoded (incl. CR), relative href resolved' );
$rq = $ev->req( 'REPORT', $cal_url );
check( $rq && '1' === $rq['headers']['Depth'], 'events: REPORT uses Depth: 1' );
check( $rq && false !== strpos( $rq['body'], '<c:time-range start="20261101T000000Z" end="20261108T000000Z"/>' ), 'events: time-range in UTC YYYYMMDDTHHMMSSZ' );
check( $rq && false !== strpos( $rq['body'], '<c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">' ) && false === strpos( $rq['body'], 'expand' ), 'events: VCALENDAR>VEVENT filter, no <c:expand>' );
check( $rq && false !== strpos( $rq['body'], '<d:getetag/><c:calendar-data/>' ) && 0 === strpos( $rq['headers']['Content-Type'], 'application/xml' ), 'events: requests getetag + calendar-data with XML content type' );
check( 'jatcs_caldav_invalid' === code_of( $c->events_in_range( $cal_url, 2000, 1000 ) ), 'events: inverted range rejected' );

/* ====================================================================
 * 5. put / get / delete
 * ==================================================================== */
$cal = 'https://cloud.example.com/remote.php/dav/calendars/jeremy/personal';
$ics = "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";
$pd  = new Fake_Server();
$pd->on( 'PUT', $cal . '/appt-1@example.com.ics', array( 'code' => 201, 'headers' => array( 'etag' => '"new1"' ) ) );
$pd->on( 'PUT', $cal . '/dup.ics', array( 'code' => 412 ) );
$pd->on(
	'PUT',
	$cal . '/upd.ics',
	function ( $headers ) {
		return isset( $headers['If-Match'] ) && '"v1"' === $headers['If-Match'] ? array( 'code' => 204, 'headers' => array( 'etag' => '"v2"' ) ) : array( 'code' => 412 );
	}
);
$pd->on( 'PUT', $cal . '/noetag.ics', array( 'code' => 201 ) );
$pd->on( 'GET', $cal . '/noetag.ics', array( 'code' => 200, 'headers' => array( 'etag' => '"fetched"' ), 'body' => $ics ) );
$pd->on( 'PUT', $cal . '/a%20b%2Fc.ics', array( 'code' => 201, 'headers' => array( 'etag' => '"enc"' ) ) );
$pd->on( 'DELETE', $cal . '/del.ics', array( 'code' => 204 ) );
$pd->on( 'GET', $cal . '/ok.ics', array( 'code' => 200, 'headers' => array( 'etag' => '"ok"' ), 'body' => $ics ) );
$pd->on( 'PUT', $cal . '/boom.ics', array( 'code' => 503 ) );
$c = new JATCS_CalDAV( 'https://cloud.example.com/remote.php/dav', 'jeremy', 'nc-app-pass', $pd );

$r = $c->put_event( $cal, 'appt-1@example.com', $ics );
check( is_array( $r ) && $cal . '/appt-1@example.com.ics' === $r['href'] && '"new1"' === $r['etag'], 'put: create 201 returns href (trailing slash added) + ETag' );
$rq = $pd->req( 'PUT', $cal . '/appt-1@example.com.ics' );
check( $rq && '*' === $rq['headers']['If-None-Match'] && ! isset( $rq['headers']['If-Match'] ) && 'text/calendar; charset=utf-8' === $rq['headers']['Content-Type'] && $ics === $rq['body'], 'put: create sends If-None-Match: *, text/calendar, raw body' );
$r = $c->put_event( $cal, 'dup', $ics );
$d = $r instanceof WP_Error ? $r->get_error_data() : array();
check( 'jatcs_caldav_exists' === code_of( $r ) && isset( $d['href'] ) && $cal . '/dup.ics' === $d['href'], 'put: create 412 => jatcs_caldav_exists with href' );
$r = $c->put_event( $cal, 'upd', $ics, '"v1"' );
check( is_array( $r ) && '"v2"' === $r['etag'], 'put: update with If-Match succeeds' );
$rq = $pd->req( 'PUT', $cal . '/upd.ics' );
check( $rq && ! isset( $rq['headers']['If-None-Match'] ), 'put: update does not send If-None-Match' );
check( 'jatcs_caldav_conflict' === code_of( $c->put_event( $cal, 'upd', $ics, '"stale"' ) ), 'put: update 412 => jatcs_caldav_conflict' );
$r = $c->put_event( $cal, 'noetag', $ics );
check( is_array( $r ) && '"fetched"' === $r['etag'], 'put: missing ETag header triggers follow-up GET' );
$r = $c->put_event( $cal, 'a b/c', $ics );
check( is_array( $r ) && $cal . '/a%20b%2Fc.ics' === $r['href'], 'put: unsafe UID characters are percent-encoded (no path traversal)' );
$r = $c->put_event( $cal, 'boom', $ics );
$d = $r instanceof WP_Error ? $r->get_error_data() : array();
check( 'jatcs_caldav_server' === code_of( $r ) && ! empty( $d['retryable'] ), 'put: 503 => jatcs_caldav_server, retryable' );
check( 'jatcs_caldav_invalid' === code_of( $c->put_event( $cal, '', $ics ) ), 'put: empty UID rejected' );

check( true === $c->delete_event( $cal . '/del.ics', '"x"' ), 'delete: 204 => true' );
$rq = $pd->req( 'DELETE', $cal . '/del.ics' );
check( $rq && '"x"' === $rq['headers']['If-Match'], 'delete: sends If-Match when etag given' );
check( true === $c->delete_event( $cal . '/missing.ics' ), 'delete: 404 => true (idempotent)' );
check( 'jatcs_caldav_not_found' === code_of( $c->get_event( $cal . '/missing.ics' ) ), 'get: 404 => jatcs_caldav_not_found' );
$g = $c->get_event( $cal . '/ok.ics' );
check( is_array( $g ) && '"ok"' === $g['etag'] && $ics === $g['data'], 'get: returns etag + raw data' );

/* ====================================================================
 * 6. Auth, security, errors
 * ==================================================================== */
$bad = new Fake_Server();
$bad->on( 'PROPFIND', 'https://caldav.icloud.com/', array( 'code' => 401 ) );
$bad->on( 'PROPFIND', 'https://caldav.icloud.com/.well-known/caldav', array( 'code' => 401 ) );
$c = new JATCS_CalDAV( 'https://caldav.icloud.com/', 'me', 'wrong-pass', $bad );
$r = $c->test();
check( 'jatcs_caldav_auth' === code_of( $r ) && false !== strpos( $r->get_error_message(), 'app-specific password was rejected' ), 'auth: 401 => jatcs_caldav_auth with friendly message' );
check( false === strpos( serialize( $r ), 'wrong-pass' ), 'auth: password not present in error' );

$net = function () {
	return array( 'error' => 'cURL error 7: Failed to connect for me:s3cret-pw' );
};
$c   = new JATCS_CalDAV( 'https://dav.example.org/caldav/', 'me', 's3cret-pw', $net );
$r   = $c->calendars( 'https://dav.example.org/caldav/me/' );
check( 'jatcs_caldav_http' === code_of( $r ) && false === strpos( $r->get_error_message(), 's3cret-pw' ), 'network error => jatcs_caldav_http, password scrubbed' );

$called = false;
$spy    = function () use ( &$called ) {
	$called = true;
	return array( 'code' => 207, 'headers' => array(), 'body' => '' );
};
$c      = new JATCS_CalDAV( 'http://caldav.example.com/', 'me', 'pw', $spy );
check( 'jatcs_caldav_insecure' === code_of( $c->discover() ) && false === $called, 'security: http:// base URL refused without any request' );
$c = new JATCS_CalDAV( 'http://localhost:5232/', 'me', 'pw', $spy );
$c->calendars( 'http://localhost:5232/me/' );
check( true === $called, 'security: http://localhost allowed for testing' );

$rd = new Fake_Server();
$rd->on( 'PROPFIND', 'https://cal.example.com/home/', array( 'code' => 302, 'headers' => array( 'location' => 'http://cal.example.com/home/' ) ) );
$rd->on( 'PROPFIND', 'https://cal.example.com/other/', array( 'code' => 307, 'headers' => array( 'location' => 'https://evil.example.net/steal/' ) ) );
$rd->on( 'PROPFIND', 'https://evil.example.net/steal/', array( 'code' => 401 ) );
$rd->on( 'PROPFIND', 'https://cal.example.com/loop/', array( 'code' => 301, 'headers' => array( 'location' => '/loop/' ) ) );
$c = new JATCS_CalDAV( 'https://cal.example.com/', 'me', 'pw', $rd );
check( 'jatcs_caldav_insecure' === code_of( $c->calendars( 'https://cal.example.com/home/' ) ) && null === $rd->req( 'PROPFIND', 'http://cal.example.com/home/' ), 'security: redirect to http refused' );
$c->calendars( 'https://cal.example.com/other/' );
$first = $rd->req( 'PROPFIND', 'https://cal.example.com/other/' );
$evil  = $rd->req( 'PROPFIND', 'https://evil.example.net/steal/' );
check( $first && isset( $first['headers']['Authorization'] ) && $evil && ! isset( $evil['headers']['Authorization'] ), 'security: auth not re-sent after redirect to a different site' );
$rd->log = array();
check( 'jatcs_caldav_http' === code_of( $c->calendars( 'https://cal.example.com/loop/' ) ) && 4 === count( $rd->log ), 'security: redirect loop stops after 3 redirects' );
$rd->log = array();
$c->calendars( 'https://evil.example.net/steal/' );
check( 1 === count( $rd->log ) && ! isset( $rd->log[0]['headers']['Authorization'] ), 'security: auth never sent directly to an unrelated host' );

$xxe_body = '<?xml version="1.0"?><!DOCTYPE d:multistatus [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:response><d:href>/cal/x/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/><cal:calendar/></d:resourcetype><d:displayname>&xxe;</d:displayname></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
$xx       = new Fake_Server();
$xx->on( 'PROPFIND', 'https://cal.example.com/cal/', ms( $xxe_body ) );
$xx->on( 'REPORT', 'https://cal.example.com/cal/x/', ms( str_replace( '<d:displayname>&xxe;</d:displayname>', '<d:getetag>"1"</d:getetag><cal:calendar-data>&xxe;</cal:calendar-data>', $xxe_body ) ) );
$c  = new JATCS_CalDAV( 'https://cal.example.com/', 'me', 'pw', $xx );
$r1 = $c->calendars( 'https://cal.example.com/cal/' );
$r2 = $c->events_in_range( 'https://cal.example.com/cal/x/', '2026-11-01', '2026-11-02' );
check( false === strpos( var_export( $r1, true ) . var_export( $r2, true ), 'root:' ), 'security: XXE payload does not read /etc/passwd' );
check( 'jatcs_caldav_xml' === code_of( $r1 ) && 'jatcs_caldav_xml' === code_of( $r2 ), 'security: documents with a DTD are refused' );

$html = new Fake_Server();
$html->on( 'PROPFIND', 'https://www.example.com/.well-known/caldav', array( 'code' => 404, 'body' => 'nope' ) );
$html->on( 'PROPFIND', 'https://www.example.com/', array( 'code' => 200, 'body' => '<html><body>Hi</body></html>' ) );
$c = new JATCS_CalDAV( 'https://www.example.com/', 'me', 'pw', $html );
check( 'jatcs_caldav_not_caldav' === code_of( $c->test() ), 'errors: plain website => jatcs_caldav_not_caldav' );

$p = JATCS_CalDAV::presets();
check( isset( $p['icloud'], $p['fastmail'], $p['nextcloud'], $p['zoho'], $p['yahoo'], $p['mailbox_org'], $p['other'] ) && 'https://caldav.icloud.com/' === $p['icloud']['url'], 'presets: all providers present' );

$total = $GLOBALS['jatcs_passes'] + $GLOBALS['jatcs_failures'];
echo "\n{$GLOBALS['jatcs_passes']}/{$total} passed, {$GLOBALS['jatcs_failures']} failed\n";
exit( $GLOBALS['jatcs_failures'] > 0 ? 1 : 0 );
