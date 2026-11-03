=== JAT Calendar Sync ===
Contributors: cptnope
Tags: appointment booking, booking calendar, google calendar, outlook calendar, scheduling
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free appointment booking that syncs with Google, Outlook, iCloud and any CalDAV or iCal calendar, matches your theme and never double-books.

== Description ==

JAT Calendar Sync puts a booking calendar on your site that knows when you are actually free. It checks every calendar you choose, offers only open times within your hours, and adds each booking to your calendar with a video link and an invitation for your visitor. Everything is free: no add-ons, no paid tiers, no account with us.

= Works with your calendars =

* **Google Calendar**: live free/busy from several calendars, bookings added with a Google Meet link and a guest invite.
* **Outlook / Microsoft 365** (work, school or personal): busy times from any of your calendars, or add bookings to Outlook with a Microsoft Teams link.
* **iCloud, Fastmail, Nextcloud, Zoho, Yahoo, mailbox.org and any CalDAV server**: read busy times and add bookings, with recurring events handled correctly.
* **Any iCal (ICS) feed**: Sling, When I Work, Deputy, 7shifts, iCloud shared calendars, Outlook published calendars, Proton, Teamup, Airbnb and more. Feeds are checked every 10 minutes.
* Mix them: for example, bookings go to Google Calendar while your Outlook work calendar and your Sling shifts block times.

= Built for people with a schedule =

* **Shift-work mode**: open booking only as far as your posted work schedule, keep free time around every shift, and open the next week automatically when it is posted.
* Appointment types with their own length, start-time spacing, hours, location, video setting and questions.
* Weekly hours with breaks, buffers, minimum notice, booking window, daily cap, days off and a per-person limit.
* Visitors reschedule or cancel from a link in every email, within the limits you set.
* Paid appointments with Stripe Checkout: the time is held while the visitor pays, confirmed only after payment and refunded automatically by your policy.
* Confirmation, reminder and change emails with a calendar file; owner notifications.
* Times shown in each visitor’s own time zone.

= Never double-books =

* Every time is re-checked live against your calendars at the moment of booking, under an atomic lock.
* If any calendar that blocks times can’t be read, no times are offered, so nobody books over an event the plugin couldn’t see.
* Changes you make in your calendar (moving or deleting an appointment) are mirrored on your site and the visitor is told.
* Site Health checks, an email if a calendar connection breaks, and a log of recent problems.

= Looks like your site =

* Matches your theme automatically, block or classic: fonts, colors, buttons, fields and corners.
* Appearance settings with a live preview inside your theme, per-block overrides, shortcode attributes and CSS variables.
* Fast: no libraries, loaded only on pages that show the calendar, and it works with page caching.
* Keyboard and screen-reader friendly.

= Private by design =

* Your bookings stay in your WordPress database and your own calendar. There is no third-party booking service in between.
* Calendar passwords, tokens and feed links are encrypted at rest.
* Retention setting, WordPress personal-data export and erase, CSV export.

Built by [Jeremy Anderson Tech](https://jeremyanderson.tech). Development happens on [GitHub](https://github.com/CptNope/WordPress-Calendar-Sync-Plugin).

== External services ==

This plugin connects only to the calendar and payment services you set up yourself, using your own accounts and credentials. Nothing is sent to the plugin’s author.

**Google Calendar** (when you connect Google). The plugin uses Google’s OAuth and Calendar APIs to read free/busy times for the calendars you choose, list your calendars, and create, move and delete appointment events.
* Sent when a visitor views the booking calendar: the time range shown and the IDs of the calendars to check (from your server to Google).
* Sent when a visitor books: the appointment time and the name, email address, phone number and answers the visitor entered, which become an event in your calendar (and, if enabled, a guest invitation emailed by Google).
* Terms: https://policies.google.com/terms — Privacy: https://policies.google.com/privacy

**Microsoft Graph / Outlook** (when you connect Outlook or Microsoft 365). Used to list your calendars, read the start, end and free/busy status of events in the calendars you choose, and, if Outlook is your booking calendar, create, move and cancel appointment events (with the visitor’s name, email and details, and a Teams meeting when enabled).
* Terms: https://www.microsoft.com/servicesagreement — Privacy: https://privacy.microsoft.com/privacystatement

**CalDAV servers** (when you connect iCloud, Fastmail, Nextcloud, Zoho, Yahoo, mailbox.org or another CalDAV server). The plugin signs in with the user name and app-specific password you enter, reads events in the date range being viewed, and, if a CalDAV calendar is your booking calendar, creates, updates and deletes appointment events containing the visitor’s details. Each provider’s own terms and privacy policy apply, for example Apple: https://www.apple.com/legal/internet-services/icloud/ and https://www.apple.com/legal/privacy/

**iCal feeds** (when you add a feed link). The plugin downloads the feed you added (at most every 10 minutes) from the address you entered. No visitor data is sent. The feed provider’s terms apply.

**Stripe** (only when you add Stripe keys and give an appointment type a price). Processes payments on Stripe’s hosted Checkout page. When a visitor books a paid appointment, your server sends Stripe the price, currency, appointment name and time, and the visitor’s email address; the visitor enters payment details directly on Stripe. Stripe sends payment notifications back to your site. Refunds are requested from Stripe when a paid booking is cancelled under your refund policy.
* Terms: https://stripe.com/legal/ssa — Privacy: https://stripe.com/privacy

== Installation ==

1. Install and activate the plugin, then open Appointments → Settings.
2. Connect at least one calendar: Google Calendar (follow the on-screen steps to create your own Google client), Outlook / Microsoft 365, or an iCloud / CalDAV account with an app-specific password. Add iCal feed links for any calendars that should only block times.
3. Under “Where bookings go”, choose the calendar new appointments are added to, and set your hours.
4. Add the Appointment Booking block or the `[jat_booking]` shortcode to a page.

Moving from Anderson Appointment Booking? Install and activate JAT Calendar Sync: your settings, appointment types, bookings, calendar connections and booking pages carry over automatically, and the old plugin is switched off.

== Frequently Asked Questions ==

= Is it really free? =

Yes. Every feature, including calendar sync for every service, payments, reminders, rescheduling and theme matching, is in the free plugin. There is no paid version.

= Which calendar should bookings go to? =

Any Google, Outlook / Microsoft 365 or CalDAV calendar you can write to. iCal feeds are read-only, so they can block times but can’t receive bookings.

= Can visitors see my events? =

No. Only busy times are used, and visitors only see open start times.

= What happens if a calendar can’t be reached? =

No times are offered until it can be checked again, so nobody books over an event the plugin couldn’t see. iCal feeds keep their last copy for up to 6 hours if the feed is briefly unreachable.

= I work shifts. Can booking follow my schedule? =

Yes. Add your schedule (for example Sling’s calendar link as an iCal feed) and choose it under “Open booking only as far as”. Booking opens only up to your last posted shift, optionally through the end of that week, with free time kept before and after each shift. When the next schedule is posted, those days open automatically.

= Why did Google booking stop after a week? =

Your Google OAuth app is in Testing mode. Publish it to In production in Google Cloud and connect again.

= iCloud says my password is wrong =

iCloud, Fastmail and Yahoo need an app-specific password, not your normal password. Create one in your account’s security settings and use that.

= Can I make it match my site? =

By default it does: it uses your theme’s fonts, button and field styles, colors and corner radius. Fine-tune it under Appointments → Settings → Appearance (with a live preview), per block in the editor, with shortcode attributes, or with CSS variables such as `--jatcs-accent` and `--jatcs-radius`.

= My vacation is in my calendar but people can still book =

All-day events are often marked “Free”, so they don’t block times. Set them to “Busy”, or add the dates under Appointments → Settings → Days off.

= My host disables WP-Cron. Does anything break? =

Booking works, but reminders and syncing with changes made in your calendar need the hourly task. Add a server cron job that requests wp-cron.php every few minutes. Tools → Site Health tells you if the task isn’t running.

= Can I charge for appointments? =

Yes. Add your Stripe keys under Appointments → Settings → Payments and give an appointment type a price. The time is held for 30 minutes while the visitor pays, and the booking is confirmed only once the payment succeeds.

= Does it work with page caching? =

Yes. Times are loaded when the page opens, so cached pages still show current availability.

== Screenshots ==

1. The booking calendar in a block theme, matching the site’s fonts and colors.
2. Picking an appointment type and a time.
3. Calendar connections: Google, Outlook, iCloud / CalDAV and iCal feeds.
4. Where bookings go, and shift-work mode.
5. Appearance settings with a live preview in your theme.

== Upgrade Notice ==

= 2.0.0 =
Anderson Appointment Booking is now JAT Calendar Sync, with Outlook, iCloud, CalDAV and iCal feed support. Settings, bookings and connections move over automatically.

== Changelog ==

= 2.0.0 =
* New name: JAT Calendar Sync (formerly Anderson Appointment Booking). Existing sites move over automatically: settings, appointment types, bookings, Google and Outlook connections, booking pages, emailed links and Stripe webhooks all keep working, and the old plugin is deactivated.
* New: iCloud, Fastmail, Nextcloud, Zoho, Yahoo, mailbox.org and any CalDAV calendar, for busy times and as the booking calendar.
* New: iCal (ICS) feeds as calendars that block times or set your work schedule, with full recurring-event support (time zones, exceptions, cancellations).
* New: Outlook / Microsoft 365 as the booking calendar, with Microsoft Teams links for work and school accounts.
* New: one “Where bookings go” setting across every connected service; the work-schedule calendar can be any of them.
* New: Site Health now checks that every calendar that blocks times can actually be read.
* License changed to GPLv2 or later.

= 1.5.0 =
* New: Open booking only as far as your posted work schedule (Sling, When I Work, Deputy or any calendar of shifts). Booking opens up to your last posted shift, optionally through the end of that week, and new weeks open automatically when the schedule reaches Google Calendar. If nothing is posted, or the schedule can't be read, nothing can be booked.
* New: Keep free time around shifts (default 60 minutes before and after each one). It counts as "at least": your usual free time before and after appointments doesn't stack on top.

= 1.4.2 =
* Fix: Installing a second copy of the plugin in a differently named folder (for example a GitHub download) no longer causes a fatal error on activation. The extra copy stays idle and a notice explains what to do.
* Fix: Deleting an extra copy no longer disconnects Google Calendar or removes settings while another copy is still active.

= 1.4.1 =
* Fix: The connected Google account and the calendar time-zone check no longer fail with "insufficient authentication scopes". They now use the calendar-list permission the plugin already asks for.

= 1.4.0 =
* New: Paid appointment types with Stripe Checkout. The time is held while the visitor pays, the booking is confirmed only after payment (by webhook, the visitor's return, or the hourly check), and nothing is ever confirmed on the browser's word alone.
* New: Automatic refunds: always when you cancel or delete the event in Google Calendar; for visitor cancellations before a cut-off you set. Failed refunds are flagged and emailed to you.
* New: Outlook / Microsoft 365 calendars as extra conflict calendars, with recurring meetings, via Microsoft Graph (read-only).
* New: Payment column and amounts in the bookings list and CSV; payment status on the visitor's manage page.
* Improved: Paid bookings whose Google event can't be created yet keep their slot and are retried hourly.

= 1.3.0 =
* New: Appointment types with their own length, start-time spacing, hours, location, video-call setting and questions. Visitors pick a type, then a time.
* New: Custom booking questions (short answer, long answer, dropdown, checkbox), included in the Google event, emails, booking record, CSV and privacy export.
* New: Visitors can reschedule from a page linked in every email; the Google event moves in place. Settings for a change cut-off and a reschedule limit.
* New: Shortcode type="…" and a block setting to show one appointment type.
* Fix: Appointments booked through the plugin now keep their free time before and after, so nothing can be booked right up against them.
* Upgrading creates one appointment type from your existing settings. Old cancel links keep working.

= 1.2.0 =
* New: Appointments deleted or moved in Google Calendar are cancelled or updated on the site, and the visitor is emailed.
* New: Reminder emails, a calendar file attached when no Google invite is sent, and the cancel link in every email.
* New: Days off, a cap on upcoming bookings per person, and an optional or required phone number.
* New: Site Health checks, an email to the owner if Google access breaks, a problem log, "Check Google Calendar now", CSV export, automatic record clean-up.
* New: Classic themes' heading font is detected; arrow-key navigation in the calendar.
* Fix: The slot lock is now atomic, so simultaneous requests can never double-book.
* Fix: Rate limiting uses the real visitor IP behind proxies and caches such as Varnish or Cloudflare.
* Improved: Google requests retry on timeouts and rate limits without creating duplicate events.

= 1.1.0 =
* New: "Match my theme" styling for block and classic themes (colors, buttons, fields, corners, heading font).
* New: Appearance settings with a live preview rendered in your theme.
* New: Per-block style controls and live preview in the editor; block color, typography and spacing support.
* New: Shortcode style attributes, custom CSS, CSS variables and styling filters.
* The calendar adapts to the width of its container; the cancel page uses your theme's styles.

= 1.0.0 =
* First release.
