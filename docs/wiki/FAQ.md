# FAQ

| Question | Answer |
| --- | --- |
| **Is it really free?** | Yes. Every feature, including sync with every calendar service, payments, reminders, rescheduling and theme matching, is in the free plugin. There's no paid version. |
| **Do I need a Google account?** | No. Bookings can go to Google, Outlook / Microsoft 365, or a CalDAV calendar such as iCloud. |
| **Which calendar should bookings go to?** | The one you check every day. Any Google, Outlook or writable CalDAV calendar works; iCal feeds are read-only. |
| **Can visitors see my events?** | No. Only busy times are used, and visitors only see open start times. |
| **What if a calendar is down?** | No times are offered until it can be read, so nobody books over an event the plugin couldn't see. Feeds use their last copy for up to 6 hours first. |
| **Can it follow my Sling or When I Work schedule?** | Yes: add the schedule's calendar link as an iCal feed and choose it under **Open booking only as far as**. See [Shift-Work Mode](Shift-Work-Mode). |
| **Does it send Google Meet or Teams links?** | Yes: Meet when bookings go to Google, Teams for Outlook work and school accounts. CalDAV calendars have no built-in video. |
| **Can I charge for appointments?** | Yes, with Stripe Checkout, per appointment type. See [Payments with Stripe](Payments-with-Stripe). |
| **Can visitors reschedule?** | Yes, from the link in every email, within the cut-off and limit you set. |
| **What happens if I move the event in my calendar?** | Within the hour the booking moves too and the visitor is emailed. Deleting the event cancels the booking. |
| **Does it work with page caching or a CDN?** | Yes. Times are loaded live when the page opens. |
| **Does it work on multisite?** | Yes, per site. Each site connects its own calendars. |
| **Can I have several booking pages?** | Yes, and each can show one type or all types. |
| **Can two people be booked separately?** | Not yet: one site has one booking calendar. Several accounts per service is on the roadmap. |
| **Is my data sent to the plugin author?** | No. The plugin only talks to the services you connect, using your own credentials. |
| **Where are bookings stored?** | In your WordPress database (Appointments → Bookings) and as events in your calendar. |
| **How do I export bookings?** | Appointments → Bookings → **Export CSV**. |
| **Is it GDPR-friendly?** | It supports WordPress's personal-data export and erase tools, a retention setting, and suggested privacy policy text. You still decide your own legal basis. |
| **What languages?** | English, and it's fully translatable (text domain `jat-calendar-sync`). |
| **I used Anderson Appointment Booking.** | Install JAT Calendar Sync; everything moves over. See [Migrating](Migrating-from-Anderson-Appointment-Booking). |

Related: [Troubleshooting](Troubleshooting) · [Getting Started](Getting-Started)
