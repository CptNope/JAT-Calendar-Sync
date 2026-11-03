# Security and privacy

JAT Calendar Sync keeps visitor data on your own site, asks calendar services for the least access it needs, and refuses to offer times it can't check.

## Secrets at rest

`JATCS_Crypto` encrypts every stored secret with AES-256-GCM through OpenSSL.

| Item | Detail |
| --- | --- |
| Key | `sha256("jat-calendar-sync|" . JATCS_ENCRYPTION_KEY)`, or the WordPress `auth` salt when that constant isn't defined |
| Format | `jcs1:` + base64 of a 12-byte random IV, the 16-byte tag and the ciphertext |
| Older values | `aab1:` values written by Anderson Appointment Booking are read with the old key (`AAB_ENCRYPTION_KEY` or the `auth` salt) and re-encrypted as `jcs1:` |
| Without OpenSSL | Values are stored as `plain:` + base64 so the plugin keeps working; the settings screen warns about it |
| Encrypted | Google, Outlook and Stripe secrets in `jatcs_settings`; Google and Outlook refresh tokens; cached access tokens; the CalDAV password; iCal feed links; each booking's manage token |

Define `JATCS_ENCRYPTION_KEY` in `wp-config.php` to keep secrets readable after salts are rotated. Changing the key or the salts makes stored secrets unreadable: reconnect the calendars and re-enter the keys.

Secrets can also live only in `wp-config.php`; see [Settings-Reference](Settings-Reference).

## Access asked for

| Service | Scopes or access | Why |
| --- | --- | --- |
| Google | `calendar.events`, `calendar.freebusy`, `calendar.calendarlist.readonly` | Create booking events, read busy times, list calendars. If any scope is unticked on Google's consent screen, the connection is refused with `jatcs_scopes`. |
| Microsoft | `offline_access User.Read Calendars.Read`, or `Calendars.ReadWrite` instead of `Calendars.Read` when Outlook receives bookings | Busy times need only read access; write is asked for only when needed. Refreshes ask for no more than what was granted. |
| CalDAV | Your username and app-specific password | HTTPS only (plain HTTP only for `localhost`, `127.0.0.1`, `::1`); redirects are followed manually, at most 3, and only to HTTPS addresses; certificates are verified |
| iCal feeds | The secret feed link | Downloaded with `wp_safe_remote_get`, which refuses local and private addresses |
| Stripe | Secret key and webhook signing secret | Card details are entered on Stripe-hosted Checkout and never reach your site |

Calendars that only block times are read as busy/free: Google free/busy, Outlook start, end and show-as. Event titles and descriptions from them are not stored.

## Requests from visitors

| Concern | Handling |
| --- | --- |
| Spam | Hidden `website` field must be empty; the form must have been open at least 3 seconds |
| Floods | Per-IP limits (see [REST-API](REST-API#rate-limits)); counters keyed by an HMAC of the IP, not the IP itself |
| Visitor IP | `REMOTE_ADDR`; `CF-Connecting-IP`, `X-Real-IP` and `X-Forwarded-For` are trusted only when `REMOTE_ADDR` is private or loopback, and the forwarded address must be public. Override with [`jatcs_client_ip`](Hooks-and-Filters#jatcs_client_ip). |
| Manage links | 32-character random token (`wp_generate_password(32, false)`), stored as an HMAC-SHA256 using the `nonce` salt and compared with `hash_equals` |
| Cancelling | Needs a POST from the manage page, so email link scanners can't cancel |
| Double booking | Atomic slot lock, then a live check of every blocking calendar (see [Architecture](Architecture)) |
| Output | Escaped in PHP; the widget writes data with `textContent` |

## Admin actions

Every admin form handler calls `guard()`: `current_user_can( 'manage_options' )` and `check_admin_referer()` for that action's nonce. OAuth sign-ins carry a per-user `state` held in a transient for 15 minutes. The CSV export prefixes cells starting with `=`, `+`, `-` or `@` so spreadsheet apps don't run them as formulas.

## Payments

| Concern | Handling |
| --- | --- |
| Forged or replayed webhooks | `Stripe-Signature` HMAC-SHA256 checked with `hash_equals`, 300-second tolerance |
| Trusting the event | The session is re-read from Stripe before anything is confirmed; amount and currency must match the booking |
| Double charges and refunds | Idempotency keys: `jatcs-session-<id>-<hold_until>`, `jatcs-expire-<session>`, `jatcs-refund-<id>` |
| Late payment | Paid after the hold ended: refunded automatically and marked `expired` |

## XML from CalDAV servers

Responses are parsed with `DOMDocument::loadXML( $body, LIBXML_NONET )`. The external entity loader is replaced with one that loads nothing (and on PHP before 8, `libxml_disable_entity_loader( true )` is set). `LIBXML_NOENT` is never used, so entity expansion and external fetches are off.

## Personal data

| Tool | Behavior |
| --- | --- |
| Export (Tools → Export Personal Data) | Every booking for the email: time, name, email, phone, type, answers, notes, status |
| Erase (Tools → Erase Personal Data) | Deletes those booking records. Events already in your calendar are not changed. |
| Automatic deletion | "Delete booking records after N days" removes records whose end is older than N days, 100 per hourly run |
| Privacy policy text | Suggested text added to Settings → Privacy |

### What each service receives

| Service | Sent |
| --- | --- |
| Booking calendar (Google, Outlook or CalDAV) | Event with time, visitor name, email, phone, notes, answers, type and location |
| Blocking calendars | Only a date range to check; nothing about visitors |
| iCal feed hosts | A conditional GET for the feed |
| Stripe | Amount, currency, appointment name and time, visitor email, booking ID in metadata |
| Visitor | Confirmation, reminder and change emails sent through `wp_mail` |

Related: [Data-and-Storage](Data-and-Storage) · [REST-API](REST-API) · [Payments-with-Stripe](Payments-with-Stripe) · [Settings-Reference](Settings-Reference) · [Reliability-and-Site-Health](Reliability-and-Site-Health)
