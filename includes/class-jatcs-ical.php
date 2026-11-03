<?php
/**
 * iCalendar (RFC 5545) parser, recurrence expander and writer.
 *
 * Used to compute BUSY time ranges from external calendar feeds (Sling,
 * iCloud, Outlook / Office 365, Proton, Fastmail, Teamup, Airbnb, Google
 * secret iCal URLs and CalDAV calendar-data) and to build single-event
 * VCALENDAR objects for writing to CalDAV servers.
 *
 * Design notes:
 * - All date-times are kept internally as "local naive" integers (seconds
 *   since 1970-01-01 00:00 of the wall clock, as if it were UTC) plus a zone
 *   resolver. Recurrence rules are expanded on the wall clock and converted
 *   to real UTC instants at the very end, so a 9:00 meeting stays at 9:00
 *   across DST changes.
 * - A zone resolver is either a DateTimeZone, or an array compiled from a
 *   VTIMEZONE block (used only when the TZID cannot be mapped to IANA), or
 *   null for floating times / all-day dates (resolved with the calendar's
 *   X-WR-TIMEZONE if valid, otherwise the caller's default zone).
 * - When something cannot be computed exactly (safety caps hit, invalid
 *   rule parts) the code errs on the side of reporting MORE busy time.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static iCalendar helper.
 */
final class JATCS_ICal {

	/**
	 * Maximum occurrences emitted per event per expand() call.
	 */
	const MAX_OCCURRENCES = 5000;

	/**
	 * Maximum recurrence periods iterated per rule per call.
	 */
	const MAX_PERIODS = 100000;

	/**
	 * Maximum candidate instants evaluated per rule per call.
	 */
	const MAX_CANDIDATES = 500000;

	/**
	 * Maximum accepted input size in bytes.
	 */
	const MAX_BYTES = 52428800;

	/**
	 * Seconds per day.
	 */
	const DAY = 86400;

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Lower-cased IANA identifier => canonical identifier.
	 *
	 * @var array|null
	 */
	private static $iana = null;

	/**
	 * DateTimeZone cache keyed by identifier.
	 *
	 * @var array
	 */
	private static $zones = array();

	/**
	 * VTIMEZONE transition cache keyed by "id|year".
	 *
	 * @var array
	 */
	private static $vtz_cache = array();

	/**
	 * Frequency name => rank (lower = longer period).
	 *
	 * @var array
	 */
	private static $freqs = array(
		'YEARLY'   => 0,
		'MONTHLY'  => 1,
		'WEEKLY'   => 2,
		'DAILY'    => 3,
		'HOURLY'   => 4,
		'MINUTELY' => 5,
		'SECONDLY' => 6,
	);

	/**
	 * Weekday code => index (Monday = 0).
	 *
	 * @var array
	 */
	private static $wdays = array(
		'MO' => 0,
		'TU' => 1,
		'WE' => 2,
		'TH' => 3,
		'FR' => 4,
		'SA' => 5,
		'SU' => 6,
	);

	/**
	 * Windows (Outlook / Exchange) zone names => IANA (CLDR windowsZones, territory 001).
	 *
	 * @var array
	 */
	private static $windows = array(
		'Dateline Standard Time'          => 'Etc/GMT+12',
		'UTC-11'                          => 'Etc/GMT+11',
		'Aleutian Standard Time'          => 'America/Adak',
		'Hawaiian Standard Time'          => 'Pacific/Honolulu',
		'Marquesas Standard Time'         => 'Pacific/Marquesas',
		'Alaskan Standard Time'           => 'America/Anchorage',
		'UTC-09'                          => 'Etc/GMT+9',
		'Pacific Standard Time (Mexico)'  => 'America/Tijuana',
		'UTC-08'                          => 'Etc/GMT+8',
		'Pacific Standard Time'           => 'America/Los_Angeles',
		'US Mountain Standard Time'       => 'America/Phoenix',
		'Mountain Standard Time (Mexico)' => 'America/Mazatlan',
		'Mountain Standard Time'          => 'America/Denver',
		'Yukon Standard Time'             => 'America/Whitehorse',
		'Central America Standard Time'   => 'America/Guatemala',
		'Central Standard Time'           => 'America/Chicago',
		'Easter Island Standard Time'     => 'Pacific/Easter',
		'Central Standard Time (Mexico)'  => 'America/Mexico_City',
		'Mexico Standard Time'            => 'America/Mexico_City',
		'Mexico Standard Time 2'          => 'America/Chihuahua',
		'Canada Central Standard Time'    => 'America/Regina',
		'SA Pacific Standard Time'        => 'America/Bogota',
		'Eastern Standard Time (Mexico)'  => 'America/Cancun',
		'Eastern Standard Time'           => 'America/New_York',
		'Haiti Standard Time'             => 'America/Port-au-Prince',
		'Cuba Standard Time'              => 'America/Havana',
		'US Eastern Standard Time'        => 'America/Indiana/Indianapolis',
		'Turks And Caicos Standard Time'  => 'America/Grand_Turk',
		'Paraguay Standard Time'          => 'America/Asuncion',
		'Atlantic Standard Time'          => 'America/Halifax',
		'Venezuela Standard Time'         => 'America/Caracas',
		'Central Brazilian Standard Time' => 'America/Cuiaba',
		'SA Western Standard Time'        => 'America/La_Paz',
		'Pacific SA Standard Time'        => 'America/Santiago',
		'Newfoundland Standard Time'      => 'America/St_Johns',
		'Tocantins Standard Time'         => 'America/Araguaina',
		'E. South America Standard Time'  => 'America/Sao_Paulo',
		'SA Eastern Standard Time'        => 'America/Cayenne',
		'Argentina Standard Time'         => 'America/Argentina/Buenos_Aires',
		'Greenland Standard Time'         => 'America/Godthab',
		'Montevideo Standard Time'        => 'America/Montevideo',
		'Magallanes Standard Time'        => 'America/Punta_Arenas',
		'Saint Pierre Standard Time'      => 'America/Miquelon',
		'Bahia Standard Time'             => 'America/Bahia',
		'UTC-02'                          => 'Etc/GMT+2',
		'Mid-Atlantic Standard Time'      => 'Etc/GMT+2',
		'Azores Standard Time'            => 'Atlantic/Azores',
		'Cape Verde Standard Time'        => 'Atlantic/Cape_Verde',
		'UTC'                             => 'UTC',
		'Coordinated Universal Time'      => 'UTC',
		'GMT Standard Time'               => 'Europe/London',
		'Greenwich Standard Time'         => 'Atlantic/Reykjavik',
		'Sao Tome Standard Time'          => 'Africa/Sao_Tome',
		'Morocco Standard Time'           => 'Africa/Casablanca',
		'W. Europe Standard Time'         => 'Europe/Berlin',
		'Central Europe Standard Time'    => 'Europe/Budapest',
		'Romance Standard Time'           => 'Europe/Paris',
		'Central European Standard Time'  => 'Europe/Warsaw',
		'W. Central Africa Standard Time' => 'Africa/Lagos',
		'Jordan Standard Time'            => 'Asia/Amman',
		'GTB Standard Time'               => 'Europe/Bucharest',
		'Middle East Standard Time'       => 'Asia/Beirut',
		'Egypt Standard Time'             => 'Africa/Cairo',
		'E. Europe Standard Time'         => 'Europe/Chisinau',
		'Syria Standard Time'             => 'Asia/Damascus',
		'West Bank Standard Time'         => 'Asia/Hebron',
		'South Africa Standard Time'      => 'Africa/Johannesburg',
		'FLE Standard Time'               => 'Europe/Kiev',
		'Israel Standard Time'            => 'Asia/Jerusalem',
		'South Sudan Standard Time'       => 'Africa/Juba',
		'Kaliningrad Standard Time'       => 'Europe/Kaliningrad',
		'Sudan Standard Time'             => 'Africa/Khartoum',
		'Libya Standard Time'             => 'Africa/Tripoli',
		'Namibia Standard Time'           => 'Africa/Windhoek',
		'Arabic Standard Time'            => 'Asia/Baghdad',
		'Turkey Standard Time'            => 'Europe/Istanbul',
		'Arab Standard Time'              => 'Asia/Riyadh',
		'Belarus Standard Time'           => 'Europe/Minsk',
		'Russian Standard Time'           => 'Europe/Moscow',
		'E. Africa Standard Time'         => 'Africa/Nairobi',
		'Volgograd Standard Time'         => 'Europe/Volgograd',
		'Iran Standard Time'              => 'Asia/Tehran',
		'Arabian Standard Time'           => 'Asia/Dubai',
		'Astrakhan Standard Time'         => 'Europe/Astrakhan',
		'Azerbaijan Standard Time'        => 'Asia/Baku',
		'Russia Time Zone 3'              => 'Europe/Samara',
		'Mauritius Standard Time'         => 'Indian/Mauritius',
		'Saratov Standard Time'           => 'Europe/Saratov',
		'Georgian Standard Time'          => 'Asia/Tbilisi',
		'Caucasus Standard Time'          => 'Asia/Yerevan',
		'Armenian Standard Time'          => 'Asia/Yerevan',
		'Afghanistan Standard Time'       => 'Asia/Kabul',
		'West Asia Standard Time'         => 'Asia/Tashkent',
		'Ekaterinburg Standard Time'      => 'Asia/Yekaterinburg',
		'Pakistan Standard Time'          => 'Asia/Karachi',
		'Qyzylorda Standard Time'         => 'Asia/Qyzylorda',
		'India Standard Time'             => 'Asia/Kolkata',
		'Sri Lanka Standard Time'         => 'Asia/Colombo',
		'Nepal Standard Time'             => 'Asia/Kathmandu',
		'Central Asia Standard Time'      => 'Asia/Almaty',
		'Bangladesh Standard Time'        => 'Asia/Dhaka',
		'Omsk Standard Time'              => 'Asia/Omsk',
		'Myanmar Standard Time'           => 'Asia/Yangon',
		'SE Asia Standard Time'           => 'Asia/Bangkok',
		'Altai Standard Time'             => 'Asia/Barnaul',
		'W. Mongolia Standard Time'       => 'Asia/Hovd',
		'North Asia Standard Time'        => 'Asia/Krasnoyarsk',
		'N. Central Asia Standard Time'   => 'Asia/Novosibirsk',
		'Tomsk Standard Time'             => 'Asia/Tomsk',
		'China Standard Time'             => 'Asia/Shanghai',
		'North Asia East Standard Time'   => 'Asia/Irkutsk',
		'Singapore Standard Time'         => 'Asia/Singapore',
		'W. Australia Standard Time'      => 'Australia/Perth',
		'Taipei Standard Time'            => 'Asia/Taipei',
		'Ulaanbaatar Standard Time'       => 'Asia/Ulaanbaatar',
		'Aus Central W. Standard Time'    => 'Australia/Eucla',
		'Transbaikal Standard Time'       => 'Asia/Chita',
		'Tokyo Standard Time'             => 'Asia/Tokyo',
		'North Korea Standard Time'       => 'Asia/Pyongyang',
		'Korea Standard Time'             => 'Asia/Seoul',
		'Yakutsk Standard Time'           => 'Asia/Yakutsk',
		'Cen. Australia Standard Time'    => 'Australia/Adelaide',
		'AUS Central Standard Time'       => 'Australia/Darwin',
		'E. Australia Standard Time'      => 'Australia/Brisbane',
		'AUS Eastern Standard Time'       => 'Australia/Sydney',
		'West Pacific Standard Time'      => 'Pacific/Port_Moresby',
		'Tasmania Standard Time'          => 'Australia/Hobart',
		'Vladivostok Standard Time'       => 'Asia/Vladivostok',
		'Lord Howe Standard Time'         => 'Australia/Lord_Howe',
		'Bougainville Standard Time'      => 'Pacific/Bougainville',
		'Russia Time Zone 10'             => 'Asia/Srednekolymsk',
		'Magadan Standard Time'           => 'Asia/Magadan',
		'Norfolk Standard Time'           => 'Pacific/Norfolk',
		'Sakhalin Standard Time'          => 'Asia/Sakhalin',
		'Central Pacific Standard Time'   => 'Pacific/Guadalcanal',
		'Russia Time Zone 11'             => 'Asia/Kamchatka',
		'Kamchatka Standard Time'         => 'Asia/Kamchatka',
		'New Zealand Standard Time'       => 'Pacific/Auckland',
		'UTC+12'                          => 'Etc/GMT-12',
		'Fiji Standard Time'              => 'Pacific/Fiji',
		'Chatham Islands Standard Time'   => 'Pacific/Chatham',
		'UTC+13'                          => 'Etc/GMT-13',
		'Tonga Standard Time'             => 'Pacific/Tongatapu',
		'Samoa Standard Time'             => 'Pacific/Apia',
		'Line Islands Standard Time'      => 'Pacific/Kiritimati',
	);

	/**
	 * Outlook display names (text after "(UTC+hh:mm) ") => IANA.
	 *
	 * @var array
	 */
	private static $display = array(
		'international date line west'                     => 'Etc/GMT+12',
		'hawaii'                                           => 'Pacific/Honolulu',
		'alaska'                                           => 'America/Anchorage',
		'pacific time (us & canada)'                       => 'America/Los_Angeles',
		'pacific time (us and canada)'                     => 'America/Los_Angeles',
		'baja california'                                  => 'America/Tijuana',
		'tijuana, baja california'                         => 'America/Tijuana',
		'arizona'                                          => 'America/Phoenix',
		'mountain time (us & canada)'                      => 'America/Denver',
		'mountain time (us and canada)'                    => 'America/Denver',
		'chihuahua, la paz, mazatlan'                      => 'America/Mazatlan',
		'central time (us & canada)'                       => 'America/Chicago',
		'central time (us and canada)'                     => 'America/Chicago',
		'central america'                                  => 'America/Guatemala',
		'guadalajara, mexico city, monterrey'              => 'America/Mexico_City',
		'saskatchewan'                                     => 'America/Regina',
		'eastern time (us & canada)'                       => 'America/New_York',
		'eastern time (us and canada)'                     => 'America/New_York',
		'indiana (east)'                                   => 'America/Indiana/Indianapolis',
		'bogota, lima, quito, rio branco'                  => 'America/Bogota',
		'bogota, lima, quito'                              => 'America/Bogota',
		'atlantic time (canada)'                           => 'America/Halifax',
		'caracas'                                          => 'America/Caracas',
		'georgetown, la paz, manaus, san juan'             => 'America/La_Paz',
		'santiago'                                         => 'America/Santiago',
		'newfoundland'                                     => 'America/St_Johns',
		'brasilia'                                         => 'America/Sao_Paulo',
		'buenos aires'                                     => 'America/Argentina/Buenos_Aires',
		'city of buenos aires'                             => 'America/Argentina/Buenos_Aires',
		'greenland'                                        => 'America/Godthab',
		'montevideo'                                       => 'America/Montevideo',
		'azores'                                           => 'Atlantic/Azores',
		'cabo verde is.'                                   => 'Atlantic/Cape_Verde',
		'cape verde is.'                                   => 'Atlantic/Cape_Verde',
		'coordinated universal time'                       => 'UTC',
		'dublin, edinburgh, lisbon, london'                => 'Europe/London',
		'monrovia, reykjavik'                              => 'Atlantic/Reykjavik',
		'casablanca'                                       => 'Africa/Casablanca',
		'amsterdam, berlin, bern, rome, stockholm, vienna' => 'Europe/Berlin',
		'belgrade, bratislava, budapest, ljubljana, prague' => 'Europe/Budapest',
		'brussels, copenhagen, madrid, paris'              => 'Europe/Paris',
		'sarajevo, skopje, warsaw, zagreb'                 => 'Europe/Warsaw',
		'west central africa'                              => 'Africa/Lagos',
		'amman'                                            => 'Asia/Amman',
		'athens, bucharest'                                => 'Europe/Bucharest',
		'athens, bucharest, istanbul'                      => 'Europe/Bucharest',
		'beirut'                                           => 'Asia/Beirut',
		'cairo'                                            => 'Africa/Cairo',
		'harare, pretoria'                                 => 'Africa/Johannesburg',
		'helsinki, kyiv, riga, sofia, tallinn, vilnius'    => 'Europe/Helsinki',
		'helsinki, kiev, riga, sofia, tallinn, vilnius'    => 'Europe/Helsinki',
		'jerusalem'                                        => 'Asia/Jerusalem',
		'istanbul'                                         => 'Europe/Istanbul',
		'baghdad'                                          => 'Asia/Baghdad',
		'kuwait, riyadh'                                   => 'Asia/Riyadh',
		'moscow, st. petersburg'                           => 'Europe/Moscow',
		'moscow, st. petersburg, volgograd'                => 'Europe/Moscow',
		'nairobi'                                          => 'Africa/Nairobi',
		'tehran'                                           => 'Asia/Tehran',
		'abu dhabi, muscat'                                => 'Asia/Dubai',
		'baku'                                             => 'Asia/Baku',
		'tbilisi'                                          => 'Asia/Tbilisi',
		'yerevan'                                          => 'Asia/Yerevan',
		'kabul'                                            => 'Asia/Kabul',
		'islamabad, karachi'                               => 'Asia/Karachi',
		'tashkent'                                         => 'Asia/Tashkent',
		'ekaterinburg'                                     => 'Asia/Yekaterinburg',
		'chennai, kolkata, mumbai, new delhi'              => 'Asia/Kolkata',
		'sri jayawardenepura'                              => 'Asia/Colombo',
		'kathmandu'                                        => 'Asia/Kathmandu',
		'astana'                                           => 'Asia/Almaty',
		'dhaka'                                            => 'Asia/Dhaka',
		'yangon (rangoon)'                                 => 'Asia/Yangon',
		'bangkok, hanoi, jakarta'                          => 'Asia/Bangkok',
		'novosibirsk'                                      => 'Asia/Novosibirsk',
		'krasnoyarsk'                                      => 'Asia/Krasnoyarsk',
		'beijing, chongqing, hong kong, urumqi'            => 'Asia/Shanghai',
		'irkutsk'                                          => 'Asia/Irkutsk',
		'kuala lumpur, singapore'                          => 'Asia/Singapore',
		'perth'                                            => 'Australia/Perth',
		'taipei'                                           => 'Asia/Taipei',
		'ulaanbaatar'                                      => 'Asia/Ulaanbaatar',
		'osaka, sapporo, tokyo'                            => 'Asia/Tokyo',
		'seoul'                                            => 'Asia/Seoul',
		'yakutsk'                                          => 'Asia/Yakutsk',
		'adelaide'                                         => 'Australia/Adelaide',
		'darwin'                                           => 'Australia/Darwin',
		'brisbane'                                         => 'Australia/Brisbane',
		'canberra, melbourne, sydney'                      => 'Australia/Sydney',
		'guam, port moresby'                               => 'Pacific/Port_Moresby',
		'hobart'                                           => 'Australia/Hobart',
		'vladivostok'                                      => 'Asia/Vladivostok',
		'magadan'                                          => 'Asia/Magadan',
		'solomon is., new caledonia'                       => 'Pacific/Guadalcanal',
		'auckland, wellington'                             => 'Pacific/Auckland',
		'fiji'                                             => 'Pacific/Fiji',
		"nuku'alofa"                                       => 'Pacific/Tongatapu',
		'samoa'                                            => 'Pacific/Apia',
	);

	/*
	 * ---------------------------------------------------------------------
	 * Public API
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Return the message of the last failure (empty string if none).
	 *
	 * @return string
	 */
	public static function last_error() {
		return self::$last_error;
	}

	/**
	 * Parse iCalendar data into VEVENT arrays.
	 *
	 * Keys starting with an underscore are internal (needed by expand()) and
	 * should be treated as opaque.
	 *
	 * @param string            $ics        Raw iCalendar text.
	 * @param DateTimeZone|null $default_tz Zone for floating times / all-day dates (default UTC).
	 * @return array|false List of events, or false when the input is not iCalendar data.
	 */
	public static function parse( $ics, ?DateTimeZone $default_tz = null ) {
		self::$last_error = '';
		if ( null === $default_tz ) {
			$default_tz = self::zone( 'UTC' );
		}
		if ( ! is_string( $ics ) ) {
			return self::fail( self::t( 'Calendar data must be a string.' ) );
		}
		if ( strlen( $ics ) > self::MAX_BYTES ) {
			return self::fail( self::t( 'Calendar data is too large.' ) );
		}
		$ics = self::normalize_input( $ics );
		if ( '' === trim( $ics ) ) {
			return self::fail( self::t( 'Calendar data is empty.' ) );
		}
		if ( false === stripos( $ics, 'BEGIN:VCALENDAR' ) && false === stripos( $ics, 'BEGIN:VEVENT' ) ) {
			return self::fail( self::t( 'The data is not an iCalendar feed (no BEGIN:VCALENDAR found).' ) );
		}
		// A cut-off download must never pass as a complete calendar: events after the cut would be missing.
		$begin_ev = preg_match_all( '/^BEGIN:VEVENT\s*$/mi', $ics );
		$end_ev   = preg_match_all( '/^END:VEVENT\s*$/mi', $ics );
		$begin_ca = preg_match_all( '/^BEGIN:VCALENDAR\s*$/mi', $ics );
		$end_ca   = preg_match_all( '/^END:VCALENDAR\s*$/mi', $ics );
		if ( $begin_ev !== $end_ev || $begin_ca !== $end_ca ) {
			return self::fail( self::t( 'The calendar data is incomplete (it may have been cut off).' ) );
		}

		$cals = self::scan( explode( "\n", $ics ) );
		if ( empty( $cals ) ) {
			return self::fail( self::t( 'No calendar components could be parsed.' ) );
		}

		$events = array();
		foreach ( $cals as $cal ) {
			$vtz = array();
			foreach ( $cal['timezones'] as $raw_tz ) {
				$compiled = self::compile_vtimezone( $raw_tz['tzid'], $raw_tz['obs'] );
				if ( null !== $compiled && '' !== $raw_tz['tzid'] ) {
					$vtz[ strtolower( trim( $raw_tz['tzid'], " \t\"'" ) ) ] = $compiled;
				}
			}
			$ftz = null;
			if ( '' !== $cal['xwr_tz'] ) {
				$ftz = self::resolve_tzid( $cal['xwr_tz'], $vtz );
			}
			$ctx = array(
				'vtz'     => $vtz,
				'ftz'     => $ftz,
				'default' => $default_tz,
			);
			foreach ( $cal['events'] as $props ) {
				$event = self::make_event( $props, $ctx, count( $events ) );
				if ( null !== $event ) {
					$events[] = $event;
				}
			}
		}
		return $events;
	}

	/**
	 * Expand parsed events into concrete occurrences overlapping [from, to).
	 *
	 * Cancelled occurrences (STATUS:CANCELLED masters or overrides) are not
	 * returned. When a safety cap is hit, a synthetic occurrence with
	 * 'capped' => true covers the remainder of the window (conservative).
	 *
	 * @param array        $events     Events from parse().
	 * @param int          $from       Window start (unix, inclusive).
	 * @param int          $to         Window end (unix, exclusive).
	 * @param DateTimeZone $default_tz Zone for floating times / all-day dates.
	 * @return array List of occurrences sorted by start.
	 */
	public static function expand( array $events, $from, $to, DateTimeZone $default_tz ) {
		$from = (int) $from;
		$to   = (int) $to;
		if ( $to <= $from ) {
			return array();
		}

		$masters   = array();
		$overrides = array();
		$out       = array();

		foreach ( $events as $ev ) {
			if ( ! is_array( $ev ) ) {
				continue;
			}
			if ( ! isset( $ev['_s'] ) ) {
				// Foreign / hand-built event: single occurrence.
				if ( isset( $ev['start'], $ev['end'] ) && self::overlaps( (int) $ev['start'], (int) $ev['end'], $from, $to ) ) {
					$ev = array_merge( self::blank_event(), $ev );
					if ( 'CANCELLED' !== $ev['status'] ) {
						$out[] = self::occurrence( $ev, (int) $ev['start'], max( (int) $ev['start'], (int) $ev['end'] ) );
					}
				}
				continue;
			}
			if ( null !== $ev['_rid'] ) {
				$overrides[] = $ev;
			} else {
				$masters[] = $ev;
			}
		}

		// Index overrides by UID and original start; keep the highest SEQUENCE.
		$ov_by_uid = array();
		foreach ( $overrides as $ov ) {
			$ftz  = self::floating_zone( $ov, $default_tz );
			$zone = null !== $ov['_tz'] ? $ov['_tz'] : $ftz;
			$rid  = self::to_utc( $ov['_rid'], $zone );
			$uid  = (string) $ov['uid'];
			if ( isset( $ov_by_uid[ $uid ][ $rid ] ) && $ov_by_uid[ $uid ][ $rid ]['ev']['sequence'] > $ov['sequence'] ) {
				continue;
			}
			$ov_by_uid[ $uid ][ $rid ] = array(
				'ev'      => $ov,
				'rid'     => $rid,
				'rid_day' => $ov['_rid']['date'] ? self::day_of( $ov['_rid']['l'] ) : null,
			);
		}

		foreach ( $masters as $ev ) {
			$uid = (string) $ev['uid'];
			$ovs = isset( $ov_by_uid[ $uid ] ) ? $ov_by_uid[ $uid ] : array();
			foreach ( self::expand_master( $ev, $from, $to, $default_tz, $ovs ) as $occ ) {
				$out[] = $occ;
			}
		}

		foreach ( $ov_by_uid as $list ) {
			foreach ( $list as $item ) {
				$ev = $item['ev'];
				if ( 'CANCELLED' === $ev['status'] ) {
					continue;
				}
				$ftz  = self::floating_zone( $ev, $default_tz );
				$zone = null !== $ev['_tz'] ? $ev['_tz'] : $ftz;
				$s    = self::to_utc( $ev['_s'], $zone );
				$e    = self::occ_end( $ev, $ev['_s']['l'], $s, $zone );
				if ( self::overlaps( $s, $e, $from, $to ) ) {
					$out[] = self::occurrence( $ev, $s, $e );
				}
			}
		}

		// De-duplicate identical occurrences (same feed concatenated twice, etc.).
		$seen   = array();
		$unique = array();
		foreach ( $out as $occ ) {
			$key = $occ['uid'] . '|' . $occ['start'] . '|' . $occ['end'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $occ;
		}
		usort(
			$unique,
			function ( $a, $b ) {
				if ( $a['start'] === $b['start'] ) {
					return $a['end'] - $b['end'];
				}
				return ( $a['start'] < $b['start'] ) ? -1 : 1;
			}
		);
		return $unique;
	}

	/**
	 * Compute busy ranges from iCalendar data.
	 *
	 * Options: include_all_day (bool, default true), include_tentative
	 * (bool, default true), merge (bool, default true: sort and merge
	 * overlapping/adjacent ranges). Ranges are clipped to [from, to).
	 *
	 * @param string       $ics        Raw iCalendar text.
	 * @param int          $from       Window start (unix).
	 * @param int          $to         Window end (unix).
	 * @param DateTimeZone $default_tz Zone for floating times / all-day dates.
	 * @param array        $options    Options.
	 * @return array|false List of array( start, end ) pairs, or false on parse failure.
	 */
	public static function busy( $ics, $from, $to, DateTimeZone $default_tz, array $options = array() ) {
		$options = array_merge(
			array(
				'include_all_day'   => true,
				'include_tentative' => true,
				'merge'             => true,
			),
			$options
		);
		$events  = self::parse( $ics, $default_tz );
		if ( false === $events ) {
			return false;
		}
		$from   = (int) $from;
		$to     = (int) $to;
		$ranges = array();
		foreach ( self::expand( $events, $from, $to, $default_tz ) as $occ ) {
			if ( $occ['transparent'] || 'CANCELLED' === $occ['status'] ) {
				continue;
			}
			if ( ! $options['include_tentative'] && 'TENTATIVE' === $occ['status'] ) {
				continue;
			}
			if ( ! $options['include_all_day'] && $occ['all_day'] ) {
				continue;
			}
			$s = max( $occ['start'], $from );
			$e = min( $occ['end'], $to );
			if ( $e > $s ) {
				$ranges[] = array( $s, $e );
			}
		}
		return $options['merge'] ? self::merge_ranges( $ranges ) : $ranges;
	}

	/**
	 * End of the last (non-cancelled) occurrence ending after $after,
	 * looking at occurrences overlapping [after, after + horizon).
	 *
	 * @param string       $ics          Raw iCalendar text.
	 * @param int          $after        Unix time.
	 * @param DateTimeZone $default_tz   Zone for floating times / all-day dates.
	 * @param int          $horizon_days Look-ahead in days.
	 * @return int|false Unix end, 0 if none, false on parse failure.
	 */
	public static function last_event_end( $ics, $after, DateTimeZone $default_tz, $horizon_days = 120 ) {
		$events = self::parse( $ics, $default_tz );
		if ( false === $events ) {
			return false;
		}
		$after   = (int) $after;
		$horizon = max( 1, min( 3660, (int) $horizon_days ) );
		$last    = 0;
		foreach ( self::expand( $events, $after, $after + $horizon * self::DAY, $default_tz ) as $occ ) {
			if ( 'CANCELLED' === $occ['status'] ) {
				continue;
			}
			if ( $occ['end'] > $after && $occ['end'] > $last ) {
				$last = $occ['end'];
			}
		}
		return $last;
	}

	/**
	 * Build a complete VCALENDAR containing one VEVENT (times written in UTC).
	 *
	 * Keys: uid, summary, description, location, start, end (unix), timezone
	 * (accepted, times are always written as UTC "Z" values), url,
	 * organizer_email, organizer_name, attendee_email, attendee_name,
	 * sequence, status, created, dtstamp.
	 *
	 * @param array $e Event data.
	 * @return string iCalendar text with CRLF line endings, or '' on invalid input (see last_error()).
	 */
	public static function build_event( array $e ) {
		self::$last_error = '';
		$start            = isset( $e['start'] ) && is_numeric( $e['start'] ) ? (int) $e['start'] : 0;
		$end              = isset( $e['end'] ) && is_numeric( $e['end'] ) ? (int) $e['end'] : 0;
		if ( $start <= 0 || $end <= $start ) {
			self::fail( self::t( 'Event start and end must be unix times with end after start.' ) );
			return '';
		}
		$now = isset( $e['dtstamp'] ) && is_numeric( $e['dtstamp'] ) ? (int) $e['dtstamp'] : time();
		$uid = isset( $e['uid'] ) ? self::clean_line( (string) $e['uid'] ) : '';
		if ( '' === $uid ) {
			$uid = self::random_hex( 16 ) . '@jat-calendar-sync';
		}
		$created  = isset( $e['created'] ) && is_numeric( $e['created'] ) ? (int) $e['created'] : $now;
		$status   = isset( $e['status'] ) ? strtoupper( trim( (string) $e['status'] ) ) : '';
		$status   = in_array( $status, array( 'CONFIRMED', 'TENTATIVE', 'CANCELLED' ), true ) ? $status : 'CONFIRMED';
		$sequence = isset( $e['sequence'] ) ? max( 0, (int) $e['sequence'] ) : 0;

		$lines   = array();
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//JAT Calendar Sync//EN';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . self::escape_text( $uid );
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', $now );
		$lines[] = 'CREATED:' . gmdate( 'Ymd\THis\Z', $created );
		$lines[] = 'LAST-MODIFIED:' . gmdate( 'Ymd\THis\Z', $now );
		$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', $start );
		$lines[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', $end );
		$lines[] = 'SUMMARY:' . self::escape_text( isset( $e['summary'] ) ? (string) $e['summary'] : '' );
		foreach ( array(
			'description' => 'DESCRIPTION',
			'location'    => 'LOCATION',
		) as $key => $prop ) {
			if ( isset( $e[ $key ] ) && '' !== trim( (string) $e[ $key ] ) ) {
				$lines[] = $prop . ':' . self::escape_text( (string) $e[ $key ] );
			}
		}
		if ( isset( $e['url'] ) ) {
			$url = preg_replace( '/[\x00-\x20\x7F]+/', '', (string) $e['url'] );
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
				$lines[] = 'URL:' . $url;
			}
		}
		$org = isset( $e['organizer_email'] ) ? self::clean_email( $e['organizer_email'] ) : '';
		if ( '' !== $org ) {
			$cn      = isset( $e['organizer_name'] ) ? self::param_value( $e['organizer_name'] ) : '';
			$lines[] = 'ORGANIZER' . ( '' !== $cn ? ';CN=' . $cn : '' ) . ':mailto:' . $org;
		}
		$att = isset( $e['attendee_email'] ) ? self::clean_email( $e['attendee_email'] ) : '';
		if ( '' !== $att ) {
			$cn      = isset( $e['attendee_name'] ) ? self::param_value( $e['attendee_name'] ) : '';
			$lines[] = 'ATTENDEE' . ( '' !== $cn ? ';CN=' . $cn : '' ) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:' . $att;
		}
		$lines[] = 'SEQUENCE:' . $sequence;
		$lines[] = 'STATUS:' . $status;
		$lines[] = 'TRANSP:OPAQUE';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		$out = '';
		foreach ( $lines as $line ) {
			$out .= self::fold( $line ) . "\r\n";
		}
		return $out;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Lexing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Strip BOMs/NULs, convert UTF-16, normalise newlines and unfold lines.
	 *
	 * @param string $s Raw input.
	 * @return string
	 */
	private static function normalize_input( $s ) {
		$head = substr( $s, 0, 2 );
		if ( ( "\xFF\xFE" === $head || "\xFE\xFF" === $head ) && function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $s, 'UTF-8', 'UTF-16' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_string( $converted ) ) {
				$s = $converted;
			}
		}
		$s = str_replace( array( "\xEF\xBB\xBF", "\0" ), '', $s );
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		return str_replace( array( "\n ", "\n\t" ), '', $s );
	}

	/**
	 * Split one content line into name, parameters and value.
	 *
	 * @param string $line Unfolded content line.
	 * @return array|null array( NAME, params, value ) or null if not a content line.
	 */
	private static function parse_line( $line ) {
		$len = strlen( $line );
		$i   = strcspn( $line, ';:' );
		if ( $i >= $len || $i > 200 || 0 === $i ) {
			return null;
		}
		$name = strtoupper( trim( substr( $line, 0, $i ) ) );
		if ( ! preg_match( '/^[A-Z0-9-]+(?:\.[A-Z0-9-]+)?$/', $name ) ) {
			return null;
		}
		$dot = strpos( $name, '.' );
		if ( false !== $dot ) {
			$name = substr( $name, $dot + 1 );
		}
		$params = array();
		while ( $i < $len && ';' === $line[ $i ] ) {
			++$i;
			$eq    = strcspn( $line, '=;:', $i );
			$pname = strtoupper( trim( substr( $line, $i, $eq ) ) );
			$i    += $eq;
			$pval  = '';
			if ( $i < $len && '=' === $line[ $i ] ) {
				++$i;
				$start = $i;
				$in_q  = false;
				while ( $i < $len ) {
					$c = $line[ $i ];
					if ( '"' === $c ) {
						$in_q = ! $in_q;
					} elseif ( ! $in_q && ( ';' === $c || ':' === $c ) ) {
						break;
					}
					++$i;
				}
				$pval = trim( str_replace( '"', '', substr( $line, $start, $i - $start ) ) );
			}
			if ( '' !== $pname && ! isset( $params[ $pname ] ) ) {
				$params[ $pname ] = $pval;
			}
		}
		if ( $i >= $len || ':' !== $line[ $i ] ) {
			return null;
		}
		return array( $name, $params, (string) substr( $line, $i + 1 ) );
	}

	/**
	 * Walk content lines and collect calendars, VEVENT property lists and VTIMEZONEs.
	 *
	 * @param array $lines Unfolded lines.
	 * @return array List of calendars.
	 */
	private static function scan( array $lines ) {
		$cals   = array();
		$ci     = -1;
		$stack  = array();
		$event  = null;
		$ev_dep = 0;
		$tz     = null;
		$tz_dep = 0;
		$obs    = null;
		$ob_dep = 0;

		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				continue;
			}
			$p = self::parse_line( $line );
			if ( null === $p ) {
				continue;
			}
			$name = $p[0];

			if ( 'BEGIN' === $name ) {
				$comp = strtoupper( trim( $p[2] ) );
				if ( '' === $comp ) {
					continue;
				}
				if ( 'VCALENDAR' === $comp ) {
					if ( $ci >= 0 ) {
						if ( null !== $event ) {
							$cals[ $ci ]['events'][] = $event;
						}
						if ( null !== $tz ) {
							$cals[ $ci ]['timezones'][] = $tz;
						}
					}
					$cals[] = array(
						'events'    => array(),
						'timezones' => array(),
						'xwr_tz'    => '',
					);
					$ci     = count( $cals ) - 1;
					$stack  = array( 'VCALENDAR' );
					$event  = null;
					$tz     = null;
					$obs    = null;
					continue;
				}
				if ( $ci < 0 ) {
					$cals[] = array(
						'events'    => array(),
						'timezones' => array(),
						'xwr_tz'    => '',
					);
					$ci     = 0;
				}
				$parent  = empty( $stack ) ? '' : $stack[ count( $stack ) - 1 ];
				$stack[] = $comp;
				$depth   = count( $stack );
				$top_ok  = ( '' === $parent || 'VCALENDAR' === $parent );
				if ( 'VEVENT' === $comp && null === $event && null === $tz && $top_ok ) {
					$event  = array();
					$ev_dep = $depth;
				} elseif ( 'VTIMEZONE' === $comp && null === $event && null === $tz && $top_ok ) {
					$tz     = array(
						'tzid' => '',
						'obs'  => array(),
					);
					$tz_dep = $depth;
				} elseif ( ( 'STANDARD' === $comp || 'DAYLIGHT' === $comp ) && null !== $tz && 'VTIMEZONE' === $parent && null === $obs ) {
					$obs    = array(
						'type'  => $comp,
						'props' => array(),
					);
					$ob_dep = $depth;
				}
				continue;
			}

			if ( 'END' === $name ) {
				$comp = strtoupper( trim( $p[2] ) );
				$idx  = false;
				for ( $k = count( $stack ) - 1; $k >= 0; $k-- ) {
					if ( $stack[ $k ] === $comp ) {
						$idx = $k;
						break;
					}
				}
				if ( false === $idx ) {
					continue;
				}
				$stack = array_slice( $stack, 0, $idx );
				$depth = count( $stack );
				if ( null !== $obs && $depth < $ob_dep ) {
					$tz['obs'][] = $obs;
					$obs         = null;
				}
				if ( null !== $tz && $depth < $tz_dep ) {
					$cals[ $ci ]['timezones'][] = $tz;
					$tz                         = null;
				}
				if ( null !== $event && $depth < $ev_dep ) {
					$cals[ $ci ]['events'][] = $event;
					$event                   = null;
				}
				continue;
			}

			$depth = count( $stack );
			if ( null !== $event ) {
				if ( $depth === $ev_dep ) {
					$event[] = $p;
				}
				continue;
			}
			if ( null !== $obs ) {
				if ( $depth === $ob_dep ) {
					$obs['props'][] = $p;
				}
				continue;
			}
			if ( null !== $tz ) {
				if ( $depth === $tz_dep && 'TZID' === $name ) {
					$tz['tzid'] = trim( $p[2] );
				}
				continue;
			}
			if ( $ci >= 0 && 1 === $depth && 'X-WR-TIMEZONE' === $name ) {
				$cals[ $ci ]['xwr_tz'] = trim( $p[2] );
			}
		}

		// Truncated input: keep what we have (an unterminated VEVENT still counts as busy).
		if ( $ci >= 0 ) {
			if ( null !== $obs && null !== $tz ) {
				$tz['obs'][] = $obs;
			}
			if ( null !== $tz ) {
				$cals[ $ci ]['timezones'][] = $tz;
			}
			if ( null !== $event ) {
				$cals[ $ci ]['events'][] = $event;
			}
		}
		return $cals;
	}

	/**
	 * Build an event array from collected VEVENT properties.
	 *
	 * @param array $props Property list (name, params, value).
	 * @param array $ctx   Calendar context (vtz, ftz, default).
	 * @param int   $index Event index (for synthetic UIDs).
	 * @return array|null Event, or null when DTSTART is missing/invalid.
	 */
	private static function make_event( array $props, array $ctx, $index ) {
		$first = array();
		$multi = array(
			'RRULE'  => array(),
			'RDATE'  => array(),
			'EXDATE' => array(),
		);
		foreach ( $props as $p ) {
			if ( isset( $multi[ $p[0] ] ) ) {
				$multi[ $p[0] ][] = $p;
			} elseif ( ! isset( $first[ $p[0] ] ) ) {
				$first[ $p[0] ] = $p;
			}
		}
		if ( ! isset( $first['DTSTART'] ) ) {
			return null;
		}
		$s = self::dt_prop( $first['DTSTART'][2], $first['DTSTART'][1], $ctx );
		if ( null === $s ) {
			return null;
		}

		$all_day = $s['date'];
		$tz      = null;
		if ( $s['utc'] ) {
			$tz = self::zone( 'UTC' );
		} elseif ( ! $all_day ) {
			$tz = $s['tz'];
		}
		$ftz   = null !== $ctx['ftz'] ? $ctx['ftz'] : $ctx['default'];
		$zone  = null !== $tz ? $tz : $ftz;
		$start = self::to_utc( $s, $zone );

		$wall  = 0;
		$exact = 0;
		$e     = isset( $first['DTEND'] ) ? self::dt_prop( $first['DTEND'][2], $first['DTEND'][1], $ctx ) : null;
		$dur   = isset( $first['DURATION'] ) ? self::parse_duration( $first['DURATION'][2] ) : null;
		if ( null !== $e ) {
			if ( $all_day ) {
				$days = self::day_of( $e['l'] ) - self::day_of( $s['l'] );
				$wall = $days > 0 ? $days * self::DAY : self::DAY;
			} elseif ( self::tz_key( $s ) === self::tz_key( $e ) ) {
				$wall = max( 0, $e['l'] - $s['l'] );
			} else {
				$exact = max( 0, self::to_utc( $e, $zone ) - $start );
			}
		} elseif ( null !== $dur ) {
			$wall  = $dur[0];
			$exact = $dur[1];
			if ( $all_day && 0 === $wall + $exact ) {
				$wall = self::DAY;
			}
		} elseif ( $all_day ) {
			$wall = self::DAY;
		}

		$rrules = array();
		foreach ( $multi['RRULE'] as $p ) {
			$rr = self::parse_rrule( $p[2] );
			if ( null !== $rr ) {
				$rrules[] = $rr;
			}
		}

		$rdates = array();
		foreach ( $multi['RDATE'] as $p ) {
			foreach ( explode( ',', $p[2] ) as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				$item = array(
					'v'   => null,
					'end' => null,
					'dur' => null,
				);
				if ( false !== strpos( $part, '/' ) ) {
					list( $a, $b ) = explode( '/', $part, 2 );
					$item['v']     = self::dt_prop( $a, $p[1], $ctx );
					$b             = trim( $b );
					if ( preg_match( '/^[+-]?P/i', $b ) ) {
						$item['dur'] = self::parse_duration( $b );
					} else {
						$item['end'] = self::dt_prop( $b, $p[1], $ctx );
					}
				} else {
					$item['v'] = self::dt_prop( $part, $p[1], $ctx );
				}
				if ( null !== $item['v'] ) {
					$rdates[] = $item;
				}
			}
		}

		$exdates = array();
		foreach ( $multi['EXDATE'] as $p ) {
			foreach ( explode( ',', $p[2] ) as $part ) {
				$v = self::dt_prop( $part, $p[1], $ctx );
				if ( null !== $v ) {
					$exdates[] = $v;
				}
			}
		}

		$rid = isset( $first['RECURRENCE-ID'] ) ? self::dt_prop( $first['RECURRENCE-ID'][2], $first['RECURRENCE-ID'][1], $ctx ) : null;

		$status = isset( $first['STATUS'] ) ? strtoupper( trim( $first['STATUS'][2] ) ) : '';
		if ( ! in_array( $status, array( 'CONFIRMED', 'TENTATIVE', 'CANCELLED' ), true ) ) {
			$status = '';
		}
		$transparent = isset( $first['TRANSP'] ) && 'TRANSPARENT' === strtoupper( trim( $first['TRANSP'][2] ) );
		if ( isset( $first['X-MICROSOFT-CDO-BUSYSTATUS'] ) && 'FREE' === strtoupper( trim( $first['X-MICROSOFT-CDO-BUSYSTATUS'][2] ) ) ) {
			$transparent = true;
		}

		$summary = isset( $first['SUMMARY'] ) ? self::unescape_text( $first['SUMMARY'][2] ) : '';
		$uid     = isset( $first['UID'] ) ? trim( self::unescape_text( $first['UID'][2] ) ) : '';
		if ( '' === $uid ) {
			$uid = 'jatcs-' . md5( $first['DTSTART'][2] . '|' . $summary . '|' . $index );
		}

		$ev        = array(
			'uid'           => $uid,
			'recurrence_id' => null !== $rid ? self::to_utc( $rid, $zone ) : null,
			'start'         => $start,
			'end'           => $start,
			'all_day'       => $all_day,
			'tzid'          => isset( $first['DTSTART'][1]['TZID'] ) && ! $s['utc'] && ! $all_day ? $first['DTSTART'][1]['TZID'] : '',
			'rrule'         => empty( $rrules ) ? null : $rrules[0],
			'rdate'         => array(),
			'exdate'        => array(),
			'status'        => $status,
			'transparent'   => $transparent,
			'summary'       => $summary,
			'sequence'      => isset( $first['SEQUENCE'] ) ? (int) trim( $first['SEQUENCE'][2] ) : 0,
			'_s'            => $s,
			'_tz'           => $tz,
			'_ftz'          => $ctx['ftz'],
			'_wall'         => $wall,
			'_exact'        => $exact,
			'_rrules'       => $rrules,
			'_rdates'       => $rdates,
			'_exdates'      => $exdates,
			'_rid'          => $rid,
		);
		$ev['end'] = self::occ_end( $ev, $s['l'], $start, $zone );
		foreach ( $rdates as $rd ) {
			$ev['rdate'][] = self::to_utc( $rd['v'], $zone );
		}
		foreach ( $exdates as $x ) {
			$ev['exdate'][] = self::to_utc( $x, $zone );
		}
		return $ev;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Value parsing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Parse a DATE or DATE-TIME value.
	 *
	 * @param string $value  Value text.
	 * @param array  $params Property parameters.
	 * @return array|null array( l, date, utc, tz ) or null.
	 */
	private static function parse_dt_value( $value, array $params ) {
		$v = strtoupper( trim( (string) $value ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $v ) ) {
			$v = str_replace( array( '-', ':' ), '', $v );
		}
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})?(?:\.\d+)?(Z)?)?$/', $v, $m ) ) {
			return null;
		}
		$y  = (int) $m[1];
		$mo = (int) $m[2];
		$d  = (int) $m[3];
		if ( $y < 1 || ! checkdate( $mo, $d, $y ) ) {
			return null;
		}
		$is_date = ! isset( $m[4] ) || '' === $m[4];
		if ( isset( $params['VALUE'] ) && 'DATE' === strtoupper( $params['VALUE'] ) ) {
			$is_date = true;
		}
		$sod = 0;
		$utc = false;
		if ( ! $is_date ) {
			$h  = (int) $m[4];
			$i  = (int) $m[5];
			$sc = ( isset( $m[6] ) && '' !== $m[6] ) ? (int) $m[6] : 0;
			if ( $h > 23 || $i > 59 || $sc > 60 ) {
				return null;
			}
			$sod = $h * 3600 + $i * 60 + min( 59, $sc );
			$utc = isset( $m[7] ) && 'Z' === $m[7];
		}
		return array(
			'l'    => self::days_from_civil( $y, $mo, $d ) * self::DAY + $sod,
			'date' => $is_date,
			'utc'  => $utc,
			'tz'   => null,
		);
	}

	/**
	 * Parse a date property value and attach its TZID resolver.
	 *
	 * @param string $value  Value text.
	 * @param array  $params Parameters.
	 * @param array  $ctx    Calendar context.
	 * @return array|null
	 */
	private static function dt_prop( $value, array $params, array $ctx ) {
		$v = self::parse_dt_value( $value, $params );
		if ( null === $v ) {
			return null;
		}
		if ( $v['utc'] ) {
			$v['tz'] = self::zone( 'UTC' );
		} elseif ( ! $v['date'] && isset( $params['TZID'] ) && '' !== $params['TZID'] ) {
			$v['tz'] = self::resolve_tzid( $params['TZID'], $ctx['vtz'] );
		}
		return $v;
	}

	/**
	 * Parse an ISO 8601 / RFC 5545 duration.
	 *
	 * @param string $value Duration such as P1D, PT1H30M, P1W.
	 * @return array|null array( wall_seconds (days/weeks), exact_seconds ) or null; negative => zero.
	 */
	private static function parse_duration( $value ) {
		$v = strtoupper( trim( (string) $value ) );
		if ( ! preg_match( '/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $v, $m ) || 'P' === ltrim( $v, '+-' ) ) {
			return null;
		}
		if ( isset( $m[1] ) && '-' === $m[1] ) {
			return array( 0, 0 );
		}
		$w = isset( $m[2] ) ? (int) $m[2] : 0;
		$d = isset( $m[3] ) ? (int) $m[3] : 0;
		$h = isset( $m[4] ) ? (int) $m[4] : 0;
		$i = isset( $m[5] ) ? (int) $m[5] : 0;
		$s = isset( $m[6] ) ? (int) $m[6] : 0;
		return array( ( $w * 7 + $d ) * self::DAY, $h * 3600 + $i * 60 + $s );
	}

	/**
	 * Parse an RRULE value into its parts.
	 *
	 * @param string $value RRULE value.
	 * @return array|null Parts keyed by upper-case name (BY* as string lists), or null without a valid FREQ.
	 */
	private static function parse_rrule( $value ) {
		$out = array();
		foreach ( explode( ';', (string) $value ) as $pair ) {
			if ( false === strpos( $pair, '=' ) ) {
				continue;
			}
			list( $k, $v ) = explode( '=', $pair, 2 );
			$k             = strtoupper( trim( $k ) );
			$v             = strtoupper( trim( $v ) );
			if ( '' === $k ) {
				continue;
			}
			if ( 'INTERVAL' === $k || 'COUNT' === $k ) {
				$out[ $k ] = (int) $v;
			} elseif ( 'FREQ' === $k || 'UNTIL' === $k || 'WKST' === $k ) {
				$out[ $k ] = $v;
			} else {
				$out[ $k ] = array_values( array_filter( array_map( 'trim', explode( ',', $v ) ), 'strlen' ) );
			}
		}
		if ( ! isset( $out['FREQ'] ) || ! isset( self::$freqs[ $out['FREQ'] ] ) ) {
			return null;
		}
		return $out;
	}

	/**
	 * Parse a UTC offset such as -0500 or +053000.
	 *
	 * @param string $value Offset text.
	 * @return int|null Seconds east of UTC.
	 */
	private static function parse_offset( $value ) {
		if ( ! preg_match( '/^([+-])(\d{2}):?(\d{2})(?::?(\d{2}))?$/', trim( (string) $value ), $m ) ) {
			return null;
		}
		$secs = (int) $m[2] * 3600 + (int) $m[3] * 60 + ( isset( $m[4] ) ? (int) $m[4] : 0 );
		return '-' === $m[1] ? -$secs : $secs;
	}

	/**
	 * Unescape an RFC 5545 TEXT value.
	 *
	 * @param string $s Escaped text.
	 * @return string
	 */
	private static function unescape_text( $s ) {
		return (string) preg_replace_callback(
			'/\\\\(.)/s',
			function ( $m ) {
				return ( 'n' === $m[1] || 'N' === $m[1] ) ? "\n" : $m[1];
			},
			(string) $s
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Time zones
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Get a cached DateTimeZone, or null if the identifier is invalid.
	 *
	 * @param string $name Zone identifier or offset.
	 * @return DateTimeZone|null
	 */
	private static function zone( $name ) {
		if ( ! array_key_exists( $name, self::$zones ) ) {
			try {
				self::$zones[ $name ] = new DateTimeZone( $name );
			} catch ( Exception $e ) {
				self::$zones[ $name ] = null;
			}
		}
		return self::$zones[ $name ];
	}

	/**
	 * Map a TZID to an IANA identifier when possible.
	 *
	 * @param string $tzid TZID parameter value (unquoted).
	 * @return string|null
	 */
	private static function tzid_to_iana( $tzid ) {
		if ( null === self::$iana ) {
			self::$iana = array();
			foreach ( DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ) as $id ) {
				self::$iana[ strtolower( $id ) ] = $id;
			}
			foreach ( self::$windows as $win => $id ) {
				self::$windows[ strtolower( $win ) ] = $id;
			}
		}
		$lower = strtolower( $tzid );
		if ( isset( self::$iana[ $lower ] ) ) {
			return self::$iana[ $lower ];
		}
		if ( isset( self::$windows[ $lower ] ) ) {
			return self::$windows[ $lower ];
		}
		$label = $lower;
		if ( preg_match( '/^\(\s*(?:utc|gmt)[^)]*\)\s*(.*)$/', $lower, $m ) ) {
			$label = trim( $m[1] );
		}
		if ( isset( self::$display[ $label ] ) ) {
			return self::$display[ $label ];
		}
		if ( false !== strpos( $tzid, '/' ) ) {
			$parts = array_values( array_filter( explode( '/', $lower ), 'strlen' ) );
			for ( $n = min( 3, count( $parts ) ); $n >= 1; $n-- ) {
				$cand = implode( '/', array_slice( $parts, -$n ) );
				if ( isset( self::$iana[ $cand ] ) ) {
					return self::$iana[ $cand ];
				}
				if ( isset( self::$windows[ $cand ] ) ) {
					return self::$windows[ $cand ];
				}
			}
		}
		return null;
	}

	/**
	 * Resolve a TZID to a zone resolver.
	 *
	 * Order: IANA, Windows name, Outlook display name, prefixed path,
	 * in-file VTIMEZONE, numeric offset; null means "floating" (default zone).
	 *
	 * @param string $tzid TZID.
	 * @param array  $vtz  Compiled VTIMEZONEs keyed by lower-case TZID.
	 * @return DateTimeZone|array|null
	 */
	private static function resolve_tzid( $tzid, array $vtz ) {
		$tzid = trim( (string) $tzid, " \t\"'" );
		if ( '' === $tzid ) {
			return null;
		}
		$iana = self::tzid_to_iana( $tzid );
		if ( null !== $iana ) {
			$zone = self::zone( $iana );
			if ( null !== $zone ) {
				return $zone;
			}
		}
		$lower = strtolower( $tzid );
		if ( isset( $vtz[ $lower ] ) ) {
			return $vtz[ $lower ];
		}
		// Fixed offsets: "(UTC-05:00) Something", "GMT+0530", "+05:00". Ignores DST.
		if ( preg_match( '/^\(?\s*(?:utc|gmt)?\s*([+-])(\d{1,2})(?:[:.]?(\d{2}))?\s*\)?/i', $tzid, $m ) ) {
			$zone = self::zone( sprintf( '%s%02d:%02d', $m[1], (int) $m[2], isset( $m[3] ) ? (int) $m[3] : 0 ) );
			if ( null !== $zone ) {
				return $zone;
			}
		}
		if ( preg_match( '/^\(\s*(?:utc|gmt)\s*\)/i', $tzid ) ) {
			return self::zone( 'UTC' );
		}
		return null;
	}

	/**
	 * Compile a VTIMEZONE into a resolver array.
	 *
	 * @param string $tzid         TZID.
	 * @param array  $observances  Raw STANDARD/DAYLIGHT blocks.
	 * @return array|null
	 */
	private static function compile_vtimezone( $tzid, array $observances ) {
		$obs = array();
		foreach ( $observances as $o ) {
			$start = null;
			$from  = null;
			$to    = null;
			$rule  = null;
			$rd    = array();
			foreach ( $o['props'] as $p ) {
				if ( 'DTSTART' === $p[0] ) {
					$start = self::parse_dt_value( $p[2], array() );
				} elseif ( 'TZOFFSETFROM' === $p[0] ) {
					$from = self::parse_offset( $p[2] );
				} elseif ( 'TZOFFSETTO' === $p[0] ) {
					$to = self::parse_offset( $p[2] );
				} elseif ( 'RRULE' === $p[0] ) {
					$rule = self::parse_rrule( $p[2] );
				} elseif ( 'RDATE' === $p[0] ) {
					foreach ( explode( ',', $p[2] ) as $part ) {
						$v = self::parse_dt_value( $part, array() );
						if ( null !== $v ) {
							$rd[] = $v['l'];
						}
					}
				}
			}
			if ( null === $to ) {
				continue;
			}
			if ( null === $from ) {
				$from = $to;
			}
			$l     = null !== $start ? $start['l'] : self::days_from_civil( 1601, 1, 1 ) * self::DAY;
			$cr    = null;
			$civil = self::civil_from_days( self::day_of( $l ) );
			if ( null !== $rule && 'YEARLY' === $rule['FREQ'] ) {
				$cr = array(
					'month' => $civil[1],
					'mday'  => $civil[2],
					'byday' => null,
					'bymd'  => array(),
					'until' => null,
				);
				if ( ! empty( $rule['BYMONTH'] ) && (int) $rule['BYMONTH'][0] >= 1 && (int) $rule['BYMONTH'][0] <= 12 ) {
					$cr['month'] = (int) $rule['BYMONTH'][0];
				}
				if ( ! empty( $rule['BYDAY'] ) && preg_match( '/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $rule['BYDAY'][0], $m ) ) {
					$cr['byday'] = array( ( isset( $m[1] ) && '' !== $m[1] ) ? (int) $m[1] : 0, self::$wdays[ $m[2] ] );
				}
				if ( ! empty( $rule['BYMONTHDAY'] ) ) {
					$cr['bymd'] = array_map( 'intval', $rule['BYMONTHDAY'] );
					sort( $cr['bymd'] );
				}
				if ( isset( $rule['UNTIL'] ) ) {
					$u = self::parse_dt_value( $rule['UNTIL'], array() );
					if ( null !== $u ) {
						$cr['until'] = $u['utc'] ? $u['l'] : $u['l'] - $from;
					}
				}
			}
			$obs[] = array(
				'from' => $from,
				'to'   => $to,
				'l'    => $l,
				'rr'   => $cr,
				'rd'   => $rd,
			);
		}
		if ( empty( $obs ) ) {
			return null;
		}
		$offsets = array();
		foreach ( $obs as $o ) {
			$offsets[ $o['from'] ] = true;
			$offsets[ $o['to'] ]   = true;
		}
		return array(
			'vtz'     => true,
			'id'      => md5( $tzid . '|' . serialize( $obs ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			'tzid'    => $tzid,
			'obs'     => $obs,
			'offsets' => array_keys( $offsets ),
		);
	}

	/**
	 * Transitions (utc, offset_to) of a compiled VTIMEZONE that follow a yearly rule in one year.
	 *
	 * @param array $vtz Compiled VTIMEZONE.
	 * @param int   $y   Year.
	 * @return array List of array( utc, offset_to ).
	 */
	private static function vtz_transitions( array $vtz, $y ) {
		$key = $vtz['id'] . '|' . $y;
		if ( isset( self::$vtz_cache[ $key ] ) ) {
			return self::$vtz_cache[ $key ];
		}
		if ( count( self::$vtz_cache ) > 4000 ) {
			self::$vtz_cache = array();
		}
		$list = array();
		foreach ( $vtz['obs'] as $o ) {
			$r = $o['rr'];
			if ( null === $r ) {
				continue;
			}
			$sy = self::civil_from_days( self::day_of( $o['l'] ) );
			if ( $y < $sy[0] ) {
				continue;
			}
			$m     = $r['month'];
			$dim   = self::days_in_month( $y, $m );
			$first = self::days_from_civil( $y, $m, 1 );
			$last  = $first + $dim - 1;
			$day   = null;
			if ( null !== $r['byday'] ) {
				list( $n, $wd ) = $r['byday'];
				if ( ! empty( $r['bymd'] ) ) {
					foreach ( $r['bymd'] as $md ) {
						if ( $md >= 1 && $md <= $dim && self::weekday( $first + $md - 1 ) === $wd ) {
							$day = $first + $md - 1;
							break;
						}
					}
				} elseif ( $n >= 0 ) {
					$z   = $first + ( ( $wd - self::weekday( $first ) + 7 ) % 7 ) + ( max( 1, $n ) - 1 ) * 7;
					$day = $z <= $last ? $z : null;
				} else {
					$z   = $last - ( ( self::weekday( $last ) - $wd + 7 ) % 7 ) + ( $n + 1 ) * 7;
					$day = $z >= $first ? $z : null;
				}
			} elseif ( ! empty( $r['bymd'] ) ) {
				$md  = $r['bymd'][0];
				$md  = $md < 0 ? $dim + $md + 1 : $md;
				$day = ( $md >= 1 && $md <= $dim ) ? $first + $md - 1 : null;
			} else {
				$day = $r['mday'] <= $dim ? $first + $r['mday'] - 1 : null;
			}
			if ( null === $day ) {
				continue;
			}
			$l = $day * self::DAY + ( $o['l'] - self::day_of( $o['l'] ) * self::DAY );
			if ( $l < $o['l'] ) {
				continue;
			}
			$u = $l - $o['from'];
			if ( null !== $r['until'] && $u > $r['until'] ) {
				continue;
			}
			$list[] = array( $u, $o['to'] );
		}
		self::$vtz_cache[ $key ] = $list;
		return $list;
	}

	/**
	 * UTC offset of a compiled VTIMEZONE at a UTC instant.
	 *
	 * @param array $vtz Compiled VTIMEZONE.
	 * @param int   $t   Unix time.
	 * @return int Offset seconds.
	 */
	private static function vtz_offset_at( array $vtz, $t ) {
		$best = null;
		foreach ( $vtz['obs'] as $o ) {
			$fixed = array();
			if ( null === $o['rr'] ) {
				$fixed[] = $o['l'] - $o['from'];
			}
			foreach ( $o['rd'] as $l ) {
				$fixed[] = $l - $o['from'];
			}
			foreach ( $fixed as $u ) {
				if ( $u <= $t && ( null === $best || $u > $best[0] ) ) {
					$best = array( $u, $o['to'] );
				}
			}
		}
		$c = self::civil_from_days( self::day_of( $t ) );
		for ( $yy = $c[0] + 1; $yy >= $c[0] - 60; $yy-- ) {
			$found = false;
			foreach ( self::vtz_transitions( $vtz, $yy ) as $tr ) {
				if ( $tr[0] <= $t ) {
					$found = true;
					if ( null === $best || $tr[0] > $best[0] ) {
						$best = $tr;
					}
				}
			}
			if ( $found ) {
				break;
			}
		}
		if ( null !== $best ) {
			return $best[1];
		}
		$earliest = $vtz['obs'][0];
		foreach ( $vtz['obs'] as $o ) {
			if ( $o['l'] < $earliest['l'] ) {
				$earliest = $o;
			}
		}
		return $earliest['from'];
	}

	/**
	 * Convert a local wall-clock value to a UTC instant.
	 *
	 * Non-existent times (DST gap) use the offset before the gap; ambiguous
	 * times use the first (earlier) instant, as RFC 5545 requires.
	 *
	 * @param int                     $l    Local naive seconds.
	 * @param DateTimeZone|array|null $zone Resolver (null = UTC).
	 * @return int
	 */
	private static function local_to_utc( $l, $zone ) {
		$l = (int) $l;
		if ( $zone instanceof DateTimeZone ) {
			if ( 'UTC' === $zone->getName() ) {
				return $l;
			}
			try {
				$dt = new DateTimeImmutable( gmdate( 'Y-m-d H:i:s', $l ), $zone );
				return $dt->getTimestamp();
			} catch ( Exception $e ) {
				return $l;
			}
		}
		if ( is_array( $zone ) && isset( $zone['vtz'] ) ) {
			$matches = array();
			foreach ( $zone['offsets'] as $off ) {
				if ( self::vtz_offset_at( $zone, $l - $off ) === $off ) {
					$matches[] = $off;
				}
			}
			if ( ! empty( $matches ) ) {
				return $l - max( $matches );
			}
			return $l - min( $zone['offsets'] );
		}
		return $l;
	}

	/**
	 * Convert a UTC instant to local naive seconds.
	 *
	 * @param int                     $t    Unix time.
	 * @param DateTimeZone|array|null $zone Resolver (null = UTC).
	 * @return int
	 */
	private static function utc_to_local( $t, $zone ) {
		$t = (int) $t;
		if ( $zone instanceof DateTimeZone ) {
			try {
				return $t + $zone->getOffset( new DateTimeImmutable( '@' . $t ) );
			} catch ( Exception $e ) {
				return $t;
			}
		}
		if ( is_array( $zone ) && isset( $zone['vtz'] ) ) {
			return $t + self::vtz_offset_at( $zone, $t );
		}
		return $t;
	}

	/**
	 * Resolve a parsed date value to a UTC instant.
	 *
	 * @param array                   $v        Parsed value.
	 * @param DateTimeZone|array|null $fallback Zone for floating values and dates.
	 * @return int
	 */
	private static function to_utc( array $v, $fallback ) {
		if ( $v['utc'] ) {
			return $v['l'];
		}
		$zone = ( ! $v['date'] && null !== $v['tz'] ) ? $v['tz'] : $fallback;
		return self::local_to_utc( $v['l'], $zone );
	}

	/**
	 * Identity of a value's zone (for DTSTART/DTEND comparison).
	 *
	 * @param array $v Parsed value.
	 * @return string
	 */
	private static function tz_key( array $v ) {
		if ( $v['utc'] ) {
			return 'Z';
		}
		if ( $v['date'] ) {
			return 'D';
		}
		if ( $v['tz'] instanceof DateTimeZone ) {
			return 'T:' . $v['tz']->getName();
		}
		if ( is_array( $v['tz'] ) ) {
			return 'V:' . $v['tz']['id'];
		}
		return 'F';
	}

	/**
	 * Zone used for an event's floating times and all-day dates.
	 *
	 * @param array        $ev      Event.
	 * @param DateTimeZone $default Default zone.
	 * @return DateTimeZone|array
	 */
	private static function floating_zone( array $ev, DateTimeZone $default ) {
		return ( isset( $ev['_ftz'] ) && null !== $ev['_ftz'] ) ? $ev['_ftz'] : $default;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Expansion
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Expand one master (non-override) event.
	 *
	 * @param array        $ev         Event.
	 * @param int          $from       Window start.
	 * @param int          $to         Window end.
	 * @param DateTimeZone $default_tz Default zone.
	 * @param array        $ovs        Overrides for this UID keyed by original start.
	 * @return array Occurrences.
	 */
	private static function expand_master( array $ev, $from, $to, DateTimeZone $default_tz, array $ovs ) {
		$ftz  = self::floating_zone( $ev, $default_tz );
		$zone = null !== $ev['_tz'] ? $ev['_tz'] : $ftz;
		$l0   = $ev['_s']['l'];
		$u0   = self::to_utc( $ev['_s'], $zone );

		$cands        = array();
		$cands[ $u0 ] = array( $l0, $u0, self::occ_end( $ev, $l0, $u0, $zone ) );
		$block        = null;

		foreach ( $ev['_rrules'] as $rr ) {
			$res = self::rrule_occurrences( $ev, $rr, $zone, $u0, $from, $to );
			foreach ( $res['occ'] as $o ) {
				$cands[ $o[1] ] = $o;
			}
			if ( null !== $res['block'] && ( null === $block || $res['block'][0] < $block[0] ) ) {
				$block = $res['block'];
			}
		}

		$sod0 = $l0 - self::day_of( $l0 ) * self::DAY;
		foreach ( $ev['_rdates'] as $rd ) {
			$v = $rd['v'];
			if ( $v['date'] && ! $ev['all_day'] ) {
				$l = self::day_of( $v['l'] ) * self::DAY + $sod0;
				$u = self::local_to_utc( $l, $zone );
			} else {
				$u = self::to_utc( $v, $zone );
				$l = $ev['all_day'] ? $v['l'] : self::utc_to_local( $u, $zone );
			}
			if ( null !== $rd['end'] ) {
				$e = max( $u, self::to_utc( $rd['end'], $zone ) );
			} elseif ( null !== $rd['dur'] ) {
				$e = ( $rd['dur'][0] > 0 ? self::local_to_utc( $l + $rd['dur'][0], $zone ) : $u ) + $rd['dur'][1];
			} else {
				$e = self::occ_end( $ev, $l, $u, $zone );
			}
			$cands[ $u ] = array( $l, $u, $e );
		}

		$ex_i = array();
		$ex_d = array();
		foreach ( $ev['_exdates'] as $x ) {
			if ( $x['date'] && ! $ev['all_day'] ) {
				$ex_d[ self::day_of( $x['l'] ) ] = true;
			} else {
				$ex_i[ self::to_utc( $x, $zone ) ] = true;
			}
		}
		$ov_i = array();
		$ov_d = array();
		foreach ( $ovs as $o ) {
			if ( null !== $o['rid_day'] && ! $ev['all_day'] ) {
				$ov_d[ $o['rid_day'] ] = true;
			} else {
				$ov_i[ $o['rid'] ] = true;
			}
		}

		$out = array();
		if ( 'CANCELLED' === $ev['status'] ) {
			return $out;
		}
		foreach ( $cands as $u => $c ) {
			if ( isset( $ex_i[ $u ] ) || isset( $ov_i[ $u ] ) ) {
				continue;
			}
			$day = self::day_of( $c[0] );
			if ( isset( $ex_d[ $day ] ) || isset( $ov_d[ $day ] ) ) {
				continue;
			}
			if ( self::overlaps( $c[1], $c[2], $from, $to ) ) {
				$out[] = self::occurrence( $ev, $c[1], $c[2] );
			}
		}
		if ( null !== $block ) {
			$occ           = self::occurrence( $ev, $block[0], $block[1] );
			$occ['capped'] = true;
			$out[]         = $occ;
		}
		return $out;
	}

	/**
	 * Compile RRULE parts into an expansion context.
	 *
	 * Invalid BY* values are dropped (a part with no valid values is ignored,
	 * which yields more occurrences, i.e. more busy time).
	 *
	 * @param array $rr Parsed RRULE parts.
	 * @param int   $l0 DTSTART local naive seconds.
	 * @return array
	 */
	private static function compile_rule( array $rr, $l0 ) {
		$c             = array();
		$c['freq']     = self::$freqs[ $rr['FREQ'] ];
		$c['interval'] = isset( $rr['INTERVAL'] ) && $rr['INTERVAL'] > 0 ? (int) $rr['INTERVAL'] : 1;
		$c['count']    = isset( $rr['COUNT'] ) && $rr['COUNT'] > 0 ? (int) $rr['COUNT'] : 0;
		$c['until']    = isset( $rr['UNTIL'] ) ? self::parse_dt_value( $rr['UNTIL'], array() ) : null;
		$c['wkst']     = isset( $rr['WKST'], self::$wdays[ $rr['WKST'] ] ) ? self::$wdays[ $rr['WKST'] ] : 0;

		$c['bymonth']  = self::int_set( $rr, 'BYMONTH', 1, 12, false );
		$md            = self::int_set( $rr, 'BYMONTHDAY', 1, 31, true );
		$yd            = self::int_set( $rr, 'BYYEARDAY', 1, 366, true );
		$wn            = self::int_set( $rr, 'BYWEEKNO', 1, 53, true );
		$c['byhour']   = self::int_set( $rr, 'BYHOUR', 0, 23, false );
		$c['byminute'] = self::int_set( $rr, 'BYMINUTE', 0, 59, false );
		$bysecond      = self::int_set( $rr, 'BYSECOND', 0, 60, false );
		$c['bysecond'] = array();
		foreach ( $bysecond as $s => $unused ) {
			$c['bysecond'][ min( 59, $s ) ] = true;
		}
		ksort( $c['bysecond'] );
		$c['bysetpos'] = array_keys( self::int_set( $rr, 'BYSETPOS', 1, 366, true ) );

		$c['md_pos'] = array();
		$c['md_neg'] = array();
		foreach ( $md as $v => $unused ) {
			if ( $v > 0 ) {
				$c['md_pos'][ $v ] = true;
			} else {
				$c['md_neg'][ $v ] = true;
			}
		}
		$c['yd_pos'] = array();
		$c['yd_neg'] = array();
		foreach ( $yd as $v => $unused ) {
			if ( $v > 0 ) {
				$c['yd_pos'][ $v ] = true;
			} else {
				$c['yd_neg'][ $v ] = true;
			}
		}
		$c['wn_pos'] = array();
		$c['wn_neg'] = array();
		foreach ( $wn as $v => $unused ) {
			if ( $v > 0 ) {
				$c['wn_pos'][ $v ] = true;
			} else {
				$c['wn_neg'][ $v ] = true;
			}
		}

		$c['wd_plain'] = array();
		$c['wd_nth']   = array();
		if ( isset( $rr['BYDAY'] ) && is_array( $rr['BYDAY'] ) ) {
			foreach ( $rr['BYDAY'] as $item ) {
				if ( ! preg_match( '/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $item, $m ) ) {
					continue;
				}
				$n  = ( isset( $m[1] ) && '' !== $m[1] ) ? (int) $m[1] : 0;
				$wd = self::$wdays[ $m[2] ];
				if ( 0 === $n || $c['freq'] > 1 || abs( $n ) > 53 ) {
					$c['wd_plain'][ $wd ] = true;
				} else {
					$c['wd_nth'][] = array( $wd, $n );
				}
			}
		}

		$d0 = self::day_of( $l0 );
		$cv = self::civil_from_days( $d0 );
		if ( empty( $c['md_pos'] ) && empty( $c['md_neg'] ) && empty( $yd ) && empty( $wn ) && empty( $c['wd_plain'] ) && empty( $c['wd_nth'] ) ) {
			if ( 0 === $c['freq'] ) {
				if ( empty( $c['bymonth'] ) ) {
					$c['bymonth'] = array( $cv[1] => true );
				}
				$c['md_pos'] = array( $cv[2] => true );
			} elseif ( 1 === $c['freq'] ) {
				$c['md_pos'] = array( $cv[2] => true );
			} elseif ( 2 === $c['freq'] ) {
				$c['wd_plain'] = array( self::weekday( $d0 ) => true );
			}
		}
		$c['has_wd'] = ! empty( $c['wd_plain'] ) || ! empty( $c['wd_nth'] );

		$sod = $l0 - $d0 * self::DAY;
		$h0  = intdiv( $sod, 3600 );
		$i0  = intdiv( $sod % 3600, 60 );
		$s0  = $sod % 60;
		$hrs = ! empty( $c['byhour'] ) ? array_keys( $c['byhour'] ) : array( $h0 );
		$mns = ! empty( $c['byminute'] ) ? array_keys( $c['byminute'] ) : array( $i0 );
		$scs = ! empty( $c['bysecond'] ) ? array_keys( $c['bysecond'] ) : array( $s0 );

		$c['times'] = array();
		if ( $c['freq'] <= 3 ) {
			foreach ( $hrs as $h ) {
				foreach ( $mns as $i ) {
					foreach ( $scs as $s ) {
						$c['times'][] = $h * 3600 + $i * 60 + $s;
					}
				}
			}
		} elseif ( 4 === $c['freq'] ) {
			foreach ( $mns as $i ) {
				foreach ( $scs as $s ) {
					$c['times'][] = $i * 60 + $s;
				}
			}
		} elseif ( 5 === $c['freq'] ) {
			$c['times'] = $scs;
		} else {
			$c['times'] = array( 0 );
		}
		sort( $c['times'] );
		$c['times'] = array_values( array_unique( $c['times'] ) );
		return $c;
	}

	/**
	 * Collect a BY* integer list as a set.
	 *
	 * @param array $rr        RRULE parts.
	 * @param string $key      Part name.
	 * @param int    $min      Minimum absolute value.
	 * @param int    $max      Maximum absolute value.
	 * @param bool   $allow_neg Whether negative values are allowed.
	 * @return array Set (value => true), sorted.
	 */
	private static function int_set( array $rr, $key, $min, $max, $allow_neg ) {
		$out = array();
		if ( ! isset( $rr[ $key ] ) || ! is_array( $rr[ $key ] ) ) {
			return $out;
		}
		foreach ( $rr[ $key ] as $v ) {
			if ( ! preg_match( '/^[+-]?\d{1,4}$/', (string) $v ) ) {
				continue;
			}
			$n = (int) $v;
			if ( $n < 0 && ! $allow_neg ) {
				continue;
			}
			$a = abs( $n );
			if ( $a < $min || $a > $max || ( 0 === $n && $min > 0 ) ) {
				continue;
			}
			$out[ $n ] = true;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Generate RRULE occurrences overlapping the window.
	 *
	 * DTSTART itself is added by the caller and counts as the first
	 * occurrence towards COUNT. If a safety cap is hit, 'block' is a
	 * conservative busy range covering the rest of the window.
	 *
	 * @param array                   $ev   Event.
	 * @param array                   $rr   RRULE parts.
	 * @param DateTimeZone|array|null $zone Expansion zone.
	 * @param int                     $u0   DTSTART as unix time.
	 * @param int                     $from Window start.
	 * @param int                     $to   Window end.
	 * @return array array( 'occ' => list of array( l, start, end ), 'block' => array|null ).
	 */
	private static function rrule_occurrences( array $ev, array $rr, $zone, $u0, $from, $to ) {
		$l0  = $ev['_s']['l'];
		$c   = self::compile_rule( $rr, $l0 );
		$occ = array();
		$dur = $ev['_wall'] + $ev['_exact'];
		$lf  = self::utc_to_local( $from, $zone ) - $dur - 2 * self::DAY;
		$lt  = self::utc_to_local( $to, $zone ) + 2 * self::DAY;

		// UNTIL handling.
		$u_type    = '';
		$u_val     = 0;
		$until_end = null;
		if ( null !== $c['until'] ) {
			$uv = $c['until'];
			if ( $uv['utc'] && $ev['all_day'] ) {
				$u_type = 'date';
				$u_val  = max( self::day_of( $uv['l'] ), self::day_of( self::utc_to_local( $uv['l'], $zone ) ) );
			} elseif ( $uv['utc'] ) {
				$u_type = 'utc';
				$u_val  = $uv['l'];
			} elseif ( $uv['date'] ) {
				$u_type = 'date';
				$u_val  = self::day_of( $uv['l'] );
			} else {
				$u_type = 'local';
				$u_val  = $uv['l'];
			}
			if ( 'utc' === $u_type ) {
				$until_end = $u_val;
				$u_bound   = $u_val + 2 * self::DAY;
			} elseif ( 'date' === $u_type ) {
				$until_end = self::local_to_utc( ( $u_val + 1 ) * self::DAY, $zone );
				$u_bound   = ( $u_val + 1 ) * self::DAY;
			} else {
				$until_end = self::local_to_utc( $u_val, $zone );
				$u_bound   = $u_val;
			}
		}

		$d0       = self::day_of( $l0 );
		$cv0      = self::civil_from_days( $d0 );
		$freq     = $c['freq'];
		$interval = $c['interval'];
		$units    = array(
			4 => 3600,
			5 => 60,
			6 => 1,
		);
		$max_day  = self::days_from_civil( 9999, 12, 31 );

		switch ( $freq ) {
			case 0:
				$p = $cv0[0];
				break;
			case 1:
				$p = $cv0[0] * 12 + $cv0[1] - 1;
				break;
			case 2:
				$p = $d0 - ( ( self::weekday( $d0 ) - $c['wkst'] + 7 ) % 7 );
				break;
			case 3:
				$p = $d0;
				break;
			default:
				$p = (int) ( floor( $l0 / $units[ $freq ] ) * $units[ $freq ] );
		}

		// Fast-forward towards the window when COUNT does not require counting from DTSTART.
		if ( 0 === $c['count'] && $lf > $l0 ) {
			$lfd = self::day_of( $lf );
			$lfc = self::civil_from_days( $lfd );
			switch ( $freq ) {
				case 0:
					$k = intdiv( $lfc[0] - $p, $interval ) - 1;
					if ( $k > 0 ) {
						$p += $k * $interval;
					}
					break;
				case 1:
					$k = intdiv( ( $lfc[0] * 12 + $lfc[1] - 1 ) - $p, $interval ) - 1;
					if ( $k > 0 ) {
						$p += $k * $interval;
					}
					break;
				case 2:
					$k = intdiv( $lfd - $p, 7 * $interval ) - 1;
					if ( $k > 0 ) {
						$p += $k * 7 * $interval;
					}
					break;
				case 3:
					$k = intdiv( $lfd - $p, $interval ) - 1;
					if ( $k > 0 ) {
						$p += $k * $interval;
					}
					break;
				default:
					$step = $units[ $freq ] * $interval;
					$k    = intdiv( $lf - $p, $step ) - 1;
					if ( $k > 0 ) {
						$p += $k * $step;
					}
			}
		}

		$seen    = 1;
		$periods = 0;
		$work    = 0;
		$capped  = false;
		$done    = false;
		$last    = null;

		while ( ! $done ) {
			if ( ++$periods > self::MAX_PERIODS ) {
				$capped = true;
				break;
			}
			// Start of this period on the local wall clock.
			if ( 0 === $freq ) {
				if ( $p > 9999 ) {
					break;
				}
				$pstart = self::days_from_civil( $p, 1, 1 ) * self::DAY;
			} elseif ( 1 === $freq ) {
				if ( intdiv( $p, 12 ) > 9999 ) {
					break;
				}
				$pstart = self::days_from_civil( intdiv( $p, 12 ), $p % 12 + 1, 1 ) * self::DAY;
			} elseif ( $freq <= 3 ) {
				if ( $p > $max_day ) {
					break;
				}
				$pstart = $p * self::DAY;
			} else {
				if ( $p > $max_day * self::DAY ) {
					break;
				}
				$pstart = $p;
			}
			if ( $pstart > $lt || ( '' !== $u_type && $pstart > $u_bound ) ) {
				break;
			}

			$list  = self::period_candidates( $c, $p );
			$work += count( $list ) + 1;
			if ( $work > self::MAX_CANDIDATES ) {
				$capped = true;
				break;
			}
			if ( ! empty( $c['bysetpos'] ) && ! empty( $list ) ) {
				$list = self::apply_setpos( $list, $c['bysetpos'] );
			}

			foreach ( $list as $l ) {
				if ( $l <= $l0 ) {
					continue;
				}
				if ( 'local' === $u_type && $l > $u_val ) {
					$done = true;
					break;
				}
				if ( 'date' === $u_type && self::day_of( $l ) > $u_val ) {
					$done = true;
					break;
				}
				$u = null;
				if ( 'utc' === $u_type && $l >= $u_val - 2 * self::DAY ) {
					$u = self::local_to_utc( $l, $zone );
					if ( $u > $u_val ) {
						$done = true;
						break;
					}
				}
				if ( $c['count'] > 0 && ++$seen > $c['count'] ) {
					$done = true;
					break;
				}
				if ( $l > $lt ) {
					$done = true;
					break;
				}
				if ( $l < $lf ) {
					continue;
				}
				if ( null === $u ) {
					$u = self::local_to_utc( $l, $zone );
				}
				$e = self::occ_end( $ev, $l, $u, $zone );
				if ( self::overlaps( $u, $e, $from, $to ) ) {
					$occ[] = array( $l, $u, $e );
					$last  = $u;
					if ( count( $occ ) >= self::MAX_OCCURRENCES ) {
						$capped = true;
						$done   = true;
						break;
					}
				}
			}

			// Next period.
			if ( $freq <= 1 || 3 === $freq ) {
				$p += $interval;
			} elseif ( 2 === $freq ) {
				$p += 7 * $interval;
			} else {
				$p += $units[ $freq ] * $interval;
			}
		}

		$block = null;
		if ( $capped ) {
			$bs = null !== $last ? $last : max( $from, $u0 );
			$be = $to;
			if ( null !== $until_end ) {
				$be = min( $be, $until_end + $dur + 3600 );
			}
			$bs = max( $bs, $from );
			if ( $be > $bs ) {
				$block = array( $bs, $be );
			}
		}
		return array(
			'occ'   => $occ,
			'block' => $block,
		);
	}

	/**
	 * Candidate local instants (sorted) for one recurrence period.
	 *
	 * @param array $c Compiled rule.
	 * @param int   $p Period key (year, month index, day number or local seconds).
	 * @return array
	 */
	private static function period_candidates( array $c, $p ) {
		$freq = $c['freq'];
		$out  = array();

		if ( $freq >= 4 ) {
			$day = self::day_of( $p );
			$sod = $p - $day * self::DAY;
			if ( ! empty( $c['byhour'] ) && ! isset( $c['byhour'][ intdiv( $sod, 3600 ) ] ) ) {
				return $out;
			}
			if ( $freq >= 5 && ! empty( $c['byminute'] ) && ! isset( $c['byminute'][ intdiv( $sod % 3600, 60 ) ] ) ) {
				return $out;
			}
			if ( 6 === $freq && ! empty( $c['bysecond'] ) && ! isset( $c['bysecond'][ $sod % 60 ] ) ) {
				return $out;
			}
			if ( ! self::day_ok( $day, $c, array() ) ) {
				return $out;
			}
			foreach ( $c['times'] as $off ) {
				$out[] = $p + $off;
			}
			return $out;
		}

		$ranges = array();
		$nmask  = array();
		if ( 0 === $freq ) {
			if ( ! empty( $c['bymonth'] ) ) {
				foreach ( $c['bymonth'] as $m => $unused ) {
					$a        = self::days_from_civil( $p, $m, 1 );
					$ranges[] = array( $a, $a + self::days_in_month( $p, $m ) - 1 );
				}
			} else {
				$ranges[] = array( self::days_from_civil( $p, 1, 1 ), self::days_from_civil( $p + 1, 1, 1 ) - 1 );
			}
		} elseif ( 1 === $freq ) {
			$y        = intdiv( $p, 12 );
			$m        = $p % 12 + 1;
			$a        = self::days_from_civil( $y, $m, 1 );
			$ranges[] = array( $a, $a + self::days_in_month( $y, $m ) - 1 );
		} elseif ( 2 === $freq ) {
			$ranges[] = array( $p, $p + 6 );
		} else {
			$ranges[] = array( $p, $p );
		}

		if ( ! empty( $c['wd_nth'] ) && $freq <= 1 ) {
			foreach ( $ranges as $r ) {
				foreach ( $c['wd_nth'] as $nth ) {
					list( $wd, $n ) = $nth;
					if ( $n > 0 ) {
						$z = $r[0] + ( ( $wd - self::weekday( $r[0] ) + 7 ) % 7 ) + ( $n - 1 ) * 7;
						if ( $z <= $r[1] ) {
							$nmask[ $z ] = true;
						}
					} else {
						$z = $r[1] - ( ( self::weekday( $r[1] ) - $wd + 7 ) % 7 ) + ( $n + 1 ) * 7;
						if ( $z >= $r[0] ) {
							$nmask[ $z ] = true;
						}
					}
				}
			}
		}

		foreach ( $ranges as $r ) {
			for ( $z = $r[0]; $z <= $r[1]; $z++ ) {
				if ( ! self::day_ok( $z, $c, $nmask ) ) {
					continue;
				}
				$base = $z * self::DAY;
				foreach ( $c['times'] as $t ) {
					$out[] = $base + $t;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether a day passes the rule's day-level BY* filters.
	 *
	 * @param int   $z     Day number.
	 * @param array $c     Compiled rule.
	 * @param array $nmask Days matched by ordinal BYDAY entries.
	 * @return bool
	 */
	private static function day_ok( $z, array $c, array $nmask ) {
		$need_civil = ! empty( $c['bymonth'] ) || ! empty( $c['md_pos'] ) || ! empty( $c['md_neg'] ) || ! empty( $c['yd_pos'] ) || ! empty( $c['yd_neg'] );
		$cv         = $need_civil ? self::civil_from_days( $z ) : null;
		if ( ! empty( $c['bymonth'] ) && ! isset( $c['bymonth'][ $cv[1] ] ) ) {
			return false;
		}
		if ( ( ! empty( $c['wn_pos'] ) || ! empty( $c['wn_neg'] ) ) && ! self::weekno_ok( $z, $c ) ) {
			return false;
		}
		if ( $c['has_wd'] && ! isset( $c['wd_plain'][ self::weekday( $z ) ] ) && ! isset( $nmask[ $z ] ) ) {
			return false;
		}
		if ( ! empty( $c['md_pos'] ) || ! empty( $c['md_neg'] ) ) {
			$dim = self::days_in_month( $cv[0], $cv[1] );
			if ( ! isset( $c['md_pos'][ $cv[2] ] ) && ! isset( $c['md_neg'][ $cv[2] - $dim - 1 ] ) ) {
				return false;
			}
		}
		if ( ! empty( $c['yd_pos'] ) || ! empty( $c['yd_neg'] ) ) {
			$yd   = $z - self::days_from_civil( $cv[0], 1, 1 ) + 1;
			$ylen = self::is_leap( $cv[0] ) ? 366 : 365;
			if ( ! isset( $c['yd_pos'][ $yd ] ) && ! isset( $c['yd_neg'][ $yd - $ylen - 1 ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * BYWEEKNO check (ISO-style week numbering relative to WKST).
	 *
	 * @param int   $z Day number.
	 * @param array $c Compiled rule.
	 * @return bool
	 */
	private static function weekno_ok( $z, array $c ) {
		$y  = self::civil_from_days( $z );
		$y  = $y[0];
		$w1 = self::week1_start( $y, $c['wkst'] );
		if ( $z < $w1 ) {
			--$y;
			$w1 = self::week1_start( $y, $c['wkst'] );
		} else {
			$next = self::week1_start( $y + 1, $c['wkst'] );
			if ( $z >= $next ) {
				++$y;
				$w1 = $next;
			}
		}
		$weekno = intdiv( $z - $w1, 7 ) + 1;
		$total  = intdiv( self::week1_start( $y + 1, $c['wkst'] ) - $w1, 7 );
		return isset( $c['wn_pos'][ $weekno ] ) || isset( $c['wn_neg'][ $weekno - $total - 1 ] );
	}

	/**
	 * First day of week 1 (the WKST-aligned week containing January 4).
	 *
	 * @param int $y    Year.
	 * @param int $wkst Week start (0 = Monday).
	 * @return int Day number.
	 */
	private static function week1_start( $y, $wkst ) {
		$jan4 = self::days_from_civil( $y, 1, 4 );
		return $jan4 - ( ( self::weekday( $jan4 ) - $wkst + 7 ) % 7 );
	}

	/**
	 * Apply BYSETPOS to a sorted candidate list.
	 *
	 * @param array $list    Sorted candidates.
	 * @param array $setpos  Positions (1-based, negative from the end).
	 * @return array
	 */
	private static function apply_setpos( array $list, array $setpos ) {
		$n   = count( $list );
		$out = array();
		foreach ( $setpos as $pos ) {
			$idx = $pos > 0 ? $pos - 1 : $n + $pos;
			if ( $idx >= 0 && $idx < $n ) {
				$out[ $list[ $idx ] ] = true;
			}
		}
		$out = array_keys( $out );
		sort( $out );
		return $out;
	}

	/**
	 * End instant of an occurrence.
	 *
	 * @param array                   $ev   Event.
	 * @param int                     $l    Occurrence start, local naive.
	 * @param int                     $u    Occurrence start, unix.
	 * @param DateTimeZone|array|null $zone Expansion zone.
	 * @return int
	 */
	private static function occ_end( array $ev, $l, $u, $zone ) {
		$e = $u;
		if ( $ev['_wall'] > 0 ) {
			$e = self::local_to_utc( $l + $ev['_wall'], $zone );
		}
		return max( $u, $e + $ev['_exact'] );
	}

	/**
	 * Build an occurrence array.
	 *
	 * @param array $ev    Event.
	 * @param int   $start Unix start.
	 * @param int   $end   Unix end.
	 * @return array
	 */
	private static function occurrence( array $ev, $start, $end ) {
		return array(
			'start'       => (int) $start,
			'end'         => (int) $end,
			'uid'         => (string) $ev['uid'],
			'all_day'     => (bool) $ev['all_day'],
			'transparent' => (bool) $ev['transparent'],
			'status'      => (string) $ev['status'],
			'summary'     => (string) $ev['summary'],
		);
	}

	/**
	 * Defaults for hand-built events passed to expand().
	 *
	 * @return array
	 */
	private static function blank_event() {
		return array(
			'uid'         => '',
			'all_day'     => false,
			'transparent' => false,
			'status'      => '',
			'summary'     => '',
		);
	}

	/**
	 * Whether [s, e) overlaps [from, to); zero-length items count if s is inside.
	 *
	 * @param int $s    Start.
	 * @param int $e    End.
	 * @param int $from Window start.
	 * @param int $to   Window end.
	 * @return bool
	 */
	private static function overlaps( $s, $e, $from, $to ) {
		if ( $e > $s ) {
			return $e > $from && $s < $to;
		}
		return $s >= $from && $s < $to;
	}

	/**
	 * Sort and merge overlapping or adjacent ranges.
	 *
	 * @param array $ranges List of array( start, end ).
	 * @return array
	 */
	private static function merge_ranges( array $ranges ) {
		if ( empty( $ranges ) ) {
			return array();
		}
		usort(
			$ranges,
			function ( $a, $b ) {
				return $a[0] === $b[0] ? $a[1] - $b[1] : ( $a[0] < $b[0] ? -1 : 1 );
			}
		);
		$out = array( $ranges[0] );
		$n   = count( $ranges );
		for ( $i = 1; $i < $n; $i++ ) {
			$last = count( $out ) - 1;
			if ( $ranges[ $i ][0] <= $out[ $last ][1] ) {
				$out[ $last ][1] = max( $out[ $last ][1], $ranges[ $i ][1] );
			} else {
				$out[] = $ranges[ $i ];
			}
		}
		return $out;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Calendar arithmetic (proleptic Gregorian, day 0 = 1970-01-01)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Day number of a civil date.
	 *
	 * @param int $y Year.
	 * @param int $m Month.
	 * @param int $d Day.
	 * @return int
	 */
	private static function days_from_civil( $y, $m, $d ) {
		$y  -= ( $m <= 2 ) ? 1 : 0;
		$era = intdiv( $y >= 0 ? $y : $y - 399, 400 );
		$yoe = $y - $era * 400;
		$doy = intdiv( 153 * ( $m + ( $m > 2 ? -3 : 9 ) ) + 2, 5 ) + $d - 1;
		$doe = $yoe * 365 + intdiv( $yoe, 4 ) - intdiv( $yoe, 100 ) + $doy;
		return $era * 146097 + $doe - 719468;
	}

	/**
	 * Civil date of a day number.
	 *
	 * @param int $z Day number.
	 * @return array array( year, month, day ).
	 */
	private static function civil_from_days( $z ) {
		$z  += 719468;
		$era = intdiv( $z >= 0 ? $z : $z - 146096, 146097 );
		$doe = $z - $era * 146097;
		$yoe = intdiv( $doe - intdiv( $doe, 1460 ) + intdiv( $doe, 36524 ) - intdiv( $doe, 146096 ), 365 );
		$y   = $yoe + $era * 400;
		$doy = $doe - ( 365 * $yoe + intdiv( $yoe, 4 ) - intdiv( $yoe, 100 ) );
		$mp  = intdiv( 5 * $doy + 2, 153 );
		$d   = $doy - intdiv( 153 * $mp + 2, 5 ) + 1;
		$m   = $mp + ( $mp < 10 ? 3 : -9 );
		return array( $y + ( $m <= 2 ? 1 : 0 ), $m, $d );
	}

	/**
	 * Day number containing a local naive second.
	 *
	 * @param int $l Seconds.
	 * @return int
	 */
	private static function day_of( $l ) {
		return (int) floor( $l / self::DAY );
	}

	/**
	 * Weekday of a day number (0 = Monday).
	 *
	 * @param int $z Day number.
	 * @return int
	 */
	private static function weekday( $z ) {
		return ( ( $z % 7 ) + 10 ) % 7;
	}

	/**
	 * Leap year test.
	 *
	 * @param int $y Year.
	 * @return bool
	 */
	private static function is_leap( $y ) {
		return ( 0 === $y % 4 && 0 !== $y % 100 ) || 0 === $y % 400;
	}

	/**
	 * Days in a month.
	 *
	 * @param int $y Year.
	 * @param int $m Month.
	 * @return int
	 */
	private static function days_in_month( $y, $m ) {
		if ( 2 === $m ) {
			return self::is_leap( $y ) ? 29 : 28;
		}
		return in_array( $m, array( 4, 6, 9, 11 ), true ) ? 30 : 31;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Writing helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Escape a TEXT value (backslash, semicolon, comma, newline).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function escape_text( $s ) {
		$s = self::valid_utf8( $s );
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $s );
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), $s );
	}

	/**
	 * Make a string safe as a quoted parameter value.
	 *
	 * @param string $s Text.
	 * @return string Quoted value or ''.
	 */
	private static function param_value( $s ) {
		$s = trim( (string) preg_replace( '/[\x00-\x1F\x7F"]/', '', self::valid_utf8( (string) $s ) ) );
		return '' === $s ? '' : '"' . $s . '"';
	}

	/**
	 * Validate an e-mail address for mailto: use.
	 *
	 * @param mixed $email Address.
	 * @return string Address or ''.
	 */
	private static function clean_email( $email ) {
		$email = trim( (string) $email );
		return ( '' !== $email && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ) ? $email : '';
	}

	/**
	 * Strip control characters from a single-line value.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function clean_line( $s ) {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', self::valid_utf8( $s ) ) );
	}

	/**
	 * Replace invalid UTF-8 sequences.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function valid_utf8( $s ) {
		$s = (string) $s;
		if ( '' === $s || preg_match( '//u', $s ) ) {
			return $s;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $s, 'UTF-8', 'UTF-8' );
		}
		return (string) preg_replace( '/[\x80-\xFF]/', '?', $s );
	}

	/**
	 * Fold a content line at 75 octets without splitting UTF-8 characters.
	 *
	 * @param string $line Line without CRLF.
	 * @return string Folded line (CRLF + space separated).
	 */
	private static function fold( $line ) {
		$len = strlen( $line );
		if ( $len <= 75 ) {
			return $line;
		}
		$out   = '';
		$pos   = 0;
		$first = true;
		while ( $pos < $len ) {
			$max   = $first ? 75 : 74;
			$chunk = min( $max, $len - $pos );
			if ( $pos + $chunk < $len ) {
				$back = $chunk;
				while ( $back > 0 && 0x80 === ( ord( $line[ $pos + $back ] ) & 0xC0 ) ) {
					--$back;
				}
				if ( $back > 0 ) {
					$chunk = $back;
				}
			}
			$out  .= ( $first ? '' : "\r\n " ) . substr( $line, $pos, $chunk );
			$pos  += $chunk;
			$first = false;
		}
		return $out;
	}

	/**
	 * Random hex string.
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string
	 */
	private static function random_hex( $bytes ) {
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( Exception $e ) {
			return md5( uniqid( '', true ) . mt_rand() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
		}
	}

	/**
	 * Record an error and return false.
	 *
	 * @param string $message Message.
	 * @return false
	 */
	private static function fail( $message ) {
		self::$last_error = (string) $message;
		return false;
	}

	/**
	 * Translate a message when WordPress is loaded.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	private static function t( $text ) {
		return function_exists( '__' ) ? __( $text, 'jat-calendar-sync' ) : $text; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
