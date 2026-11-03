# Settings Reference

Everything under **Appointments → Settings**, in screen order. The stored key is the field's name in the `jatcs_settings` option (useful for developers and migrations).

## Calendar connections

| Section | Setting | Default | Key | Notes |
| --- | --- | --- | --- | --- |
| Google Calendar | Connect / Disconnect | Not connected | — | See [Google Calendar](Google-Calendar) |
| Google credentials | Client ID | Empty | `client_id` | Or `JATCS_GOOGLE_CLIENT_ID` in wp-config.php |
| Google credentials | Client secret | Empty | `client_secret` | Encrypted; or `JATCS_GOOGLE_CLIENT_SECRET` |
| Google calendars that block times | Calendar checkboxes | None | `conflict_calendars` | Ignored while Google is disconnected |
| Outlook / Microsoft 365 | Application (client) ID | Empty | `ms_client_id` | Or `JATCS_MS_CLIENT_ID` |
| Outlook / Microsoft 365 | Client secret | Empty | `ms_client_secret` | Encrypted; or `JATCS_MS_CLIENT_SECRET` |
| Outlook / Microsoft 365 | Block times that are busy in | None | `ms_calendars` | See [Outlook and Microsoft 365](Outlook-and-Microsoft-365) |
| iCloud, Fastmail, Nextcloud and other CalDAV | Service, address, user, app password | Not connected | `jatcs_caldav` option | Password encrypted; see [iCloud and CalDAV](iCloud-and-CalDAV) |
| iCloud, Fastmail, Nextcloud and other CalDAV | Block times that are busy in | None | `caldav_calendars` | |
| iCal feeds | Feeds, Blocks times | None | `jatcs_feeds` option | Links encrypted; up to 20; see [iCal Feeds](iCal-Feeds) |

## Where bookings go

| Setting | Default | Key | Notes |
| --- | --- | --- | --- |
| Add bookings to | Google primary | `booking_calendar` | `primary`, a Google ID, `ms:ID` or `caldav:URL` |
| Open booking only as far as | Off | `schedule_calendar` | Any calendar or feed; see [Shift-Work Mode](Shift-Work-Mode) |
| …and the rest of that week, through Sunday | On | `schedule_week` | |
| Keep free before and after each shift | 60 minutes | `schedule_buffer` | 0–480 |

## When people can book

| Setting | Default | Key | Range |
| --- | --- | --- | --- |
| Monday … Sunday hours | Mon–Fri `09:00-17:00`, weekends closed | `hours` | 24-hour ranges, commas for breaks |
| Free time before | 0 min | `buffer_before` | 0–240 |
| Free time after | 15 min | `buffer_after` | 0–240 |
| Minimum notice | 12 hours | `min_notice` | 0–720 |
| Book up to | 30 days ahead | `max_days` | 1–365 |
| Most bookings per day | 0 (no limit) | `daily_limit` | 0–100 |
| Upcoming bookings per person | 2 | `max_per_email` | 0–50 (0 = no limit) |
| Changes allowed until | 0 hours before | `change_cutoff` | 0–720 (0 = any time before it starts) |
| Reschedules per booking | 0 (no limit) | `max_reschedules` | 0–20 |
| Days off | Empty | `days_off` | One date or range per line |

Appointment length, spacing and per-type hours live on each type: [Appointment Types and Questions](Appointment-Types-and-Questions).

## The appointment

| Setting | Default | Key | Notes |
| --- | --- | --- | --- |
| Calendar event title | `Appointment with {name}` | `event_title` | Placeholders `{name}`, `{type}`, `{site}` |
| Location | Empty | `location` | Default for types without their own |
| Video call | On | `add_meet` | Default for types set to "Default" |
| Calendar invite | On | `invite_guest` | Google and Outlook send the invite; CalDAV gets a calendar file instead |
| Phone number | Don't ask | `phone_field` | `off`, `optional`, `required` |
| Notify me at | Site admin email | `notify_email` | Owner emails |
| Reminder email | 24 hours before | `reminder_hours` | 0–168 (0 = off) |
| Message after booking | "You're booked. A confirmation is on its way to your inbox." | `confirmation_message` | |
| Consent checkbox | Empty (off) | `consent_text` | When filled in, visitors must tick it |

## Payments (Stripe)

| Setting | Default | Key | Notes |
| --- | --- | --- | --- |
| Secret key | Empty | `stripe_secret` | Encrypted; or `JATCS_STRIPE_SECRET_KEY` |
| Webhook signing secret | Empty | `stripe_webhook_secret` | Encrypted; or `JATCS_STRIPE_WEBHOOK_SECRET` |
| Currency | `usd` | `currency` | Three-letter code |
| Refund visitor cancellations until | 24 hours before | `refund_cutoff` | You cancelling always refunds |

See [Payments with Stripe](Payments-with-Stripe).

## Appearance

| Setting | Default | Key |
| --- | --- | --- |
| Accent color | Match my theme | `style_accent` (`theme`, `custom`) + `accent` |
| Booking button and form fields | Theme's | `style_buttons` (`theme`, `plugin`) |
| Corners | Match my theme | `style_radius` (`auto`, `square`, `soft`, `rounded`, `round`) |
| Spacing | Comfortable | `style_density` (`compact`, `comfortable`, `roomy`) |
| Calendar background | None | `style_background` (`none`, `theme`, `custom`) + `style_bg_color` |
| Headings | Theme's heading font | `style_headings` (`theme`, `inherit`) |
| Custom CSS | Empty | `custom_css` |

See [Styling and Theming](Styling-and-Theming).

## Housekeeping

| Setting | Default | Key | Notes |
| --- | --- | --- | --- |
| When uninstalling: delete all settings and booking records | Off | `delete_data` | Calendar connections, CalDAV sign-in and feed links are always removed on uninstall |
| Keep booking records | 0 (forever) | `retention_days` | Booking records (name, email, notes, answers) are deleted this many days after the appointment; calendar events are not touched |

## wp-config.php constants

| Constant | Replaces |
| --- | --- |
| `JATCS_GOOGLE_CLIENT_ID`, `JATCS_GOOGLE_CLIENT_SECRET` | Google credentials |
| `JATCS_MS_CLIENT_ID`, `JATCS_MS_CLIENT_SECRET` | Outlook credentials |
| `JATCS_STRIPE_SECRET_KEY`, `JATCS_STRIPE_WEBHOOK_SECRET` | Stripe keys |
| `JATCS_ENCRYPTION_KEY` | The key used to encrypt stored secrets (defaults to the site's auth salt) |

The old `AAB_*` names from Anderson Appointment Booking are still read. Changing `JATCS_ENCRYPTION_KEY` or the site's salts makes stored secrets unreadable, so you'd need to reconnect.

Related: [Getting Started](Getting-Started) · [Availability Rules](Availability-Rules) · [Data and Storage](Data-and-Storage)
