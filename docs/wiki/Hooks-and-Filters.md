# Hooks and filters

JAT Calendar Sync fires four actions and four filters. Put examples in a small custom plugin or your theme's `functions.php`.

| Hook | Type | Fires |
| --- | --- | --- |
| [`jatcs_booking_confirmed`](#jatcs_booking_confirmed) | action | A booking is confirmed and its event added to the booking calendar |
| [`jatcs_booking_rescheduled`](#jatcs_booking_rescheduled) | action | A visitor moved their booking from the manage page |
| [`jatcs_booking_cancelled`](#jatcs_booking_cancelled) | action | A confirmed (or paid) booking is cancelled |
| [`jatcs_migrated`](#jatcs_migrated) | action | A site has just moved over from Anderson Appointment Booking |
| [`jatcs_client_ip`](#jatcs_client_ip) | filter | The visitor IP used for rate limiting |
| [`jatcs_enqueue_styles`](#jatcs_enqueue_styles) | filter | Whether the widget's stylesheet loads |
| [`jatcs_theme_tokens`](#jatcs_theme_tokens) | filter | Design tokens read from the active theme |
| [`jatcs_style_vars`](#jatcs_style_vars) | filter | CSS variables applied to one widget |

## jatcs_booking_confirmed

```php
do_action( 'jatcs_booking_confirmed', array $booking );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$booking` | array | The booking, as returned by `JATCS_Bookings::get()` (see [the booking array](#the-booking-array)) |

Fires once per booking, inside `JATCS_Bookings::finalize()`, after the calendar event exists, the status is `confirmed` and the confirmation emails have been sent. For paid types this is after Stripe reports the payment. It does not fire again if finalize is called a second time (for example the webhook and the visitor's return arriving together).

```php
add_action( 'jatcs_booking_confirmed', function ( $booking ) {
	wp_remote_post( 'https://hooks.example.com/new-booking', array(
		'body' => array(
			'name'  => $booking['name'],
			'email' => $booking['email'],
			'start' => gmdate( 'c', $booking['start'] ),
			'type'  => $booking['type_name'],
		),
	) );
} );
```

## jatcs_booking_rescheduled

```php
do_action( 'jatcs_booking_rescheduled', array $booking, int $old_start );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$booking` | array | The booking after the move |
| `$old_start` | int | The previous Unix start time |

Fires when a visitor moves their booking through `POST /bookings/{id}/reschedule`. Moves you make in the calendar itself are picked up by the hourly sync, which updates the booking and emails the visitor but does not fire this action.

```php
add_action( 'jatcs_booking_rescheduled', function ( $booking, $old_start ) {
	error_log( sprintf( 'Booking %d moved from %s to %s', $booking['id'], gmdate( 'c', $old_start ), gmdate( 'c', $booking['start'] ) ) );
}, 10, 2 );
```

## jatcs_booking_cancelled

```php
do_action( 'jatcs_booking_cancelled', array $booking, string $by );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$booking` | array | The booking, with `status` set to `cancelled` |
| `$by` | string | `visitor` (manage page), `admin` (bookings list or trashing an upcoming booking) or `calendar` (the event was deleted or cancelled in the calendar) |

Fires after the calendar event is removed (unless `$by` is `calendar`), the record is marked cancelled, any refund is requested and the emails are sent. Unpaid holds that are released do not fire it.

```php
add_action( 'jatcs_booking_cancelled', function ( $booking, $by ) {
	if ( 'visitor' === $by ) {
		wp_mail( get_option( 'admin_email' ), 'Cancellation', $booking['name'] . ' cancelled.' );
	}
}, 10, 2 );
```

## jatcs_migrated

```php
do_action( 'jatcs_migrated', array $report );
```

| Key | Type | Description |
| --- | --- | --- |
| `at` | int | Unix time of the move |
| `bookings` | int | Booking posts renamed |
| `google` | bool | A Google connection was carried over |
| `outlook` | bool | An Outlook connection was carried over |
| `pages` | int | Posts whose block or shortcode was renamed |
| `plugins` | string[] | Old plugin files deactivated |

Fires once, at the end of the migration from Anderson Appointment Booking. Use it to purge page caches so cached pages pick up the new block markup.

```php
add_action( 'jatcs_migrated', function ( $report ) {
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
	do_action( 'litespeed_purge_all' ); // Example: LiteSpeed Cache.
} );
```

## jatcs_client_ip

```php
apply_filters( 'jatcs_client_ip', string $ip, string $remote );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$ip` | string | Detected visitor IP (from a forwarding header when `REMOTE_ADDR` is private) |
| `$remote` | string | The raw `REMOTE_ADDR` |

Used for the per-IP rate limits on the REST API. Override it when your proxy uses a different header.

```php
add_filter( 'jatcs_client_ip', function ( $ip, $remote ) {
	return ! empty( $_SERVER['HTTP_TRUE_CLIENT_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_TRUE_CLIENT_IP'] ) ) : $ip;
}, 10, 2 );
```

## jatcs_enqueue_styles

```php
apply_filters( 'jatcs_enqueue_styles', bool $load );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$load` | bool | Load `assets/css/booking.css` (default `true`) |

Checked every time a widget renders. Return `false` to style the widget entirely from your theme.

```php
add_filter( 'jatcs_enqueue_styles', '__return_false' );
```

## jatcs_theme_tokens

```php
apply_filters( 'jatcs_theme_tokens', array $tokens );
```

| Key | Description |
| --- | --- |
| `source` | `theme.json` for block themes, otherwise `none` (classic themes are probed in the browser) |
| `accent`, `accent_hex` | Button color as a CSS value and as hex |
| `on_accent`, `on_accent_hex` | Text color on the button |
| `base`, `base_hex` | Page background color |
| `radius` | Button corner radius |
| `heading_font` | Heading font family |
| `palette` | Theme palette, `slug => hex` |

Read once per request from `theme.json` ("Match my theme"). Change a token when the detected value isn't what you want.

```php
add_filter( 'jatcs_theme_tokens', function ( $tokens ) {
	$tokens['accent']     = 'var(--wp--preset--color--vivid-green-cyan)';
	$tokens['accent_hex'] = '#00d084';
	return $tokens;
} );
```

## jatcs_style_vars

```php
apply_filters( 'jatcs_style_vars', array $vars, array $overrides );
```

| Parameter | Type | Description |
| --- | --- | --- |
| `$vars` | array | CSS custom properties for this widget, e.g. `--jatcs-accent`, `--jatcs-radius`, `--jatcs-surface`, `--jatcs-heading-font` |
| `$overrides` | array | Per-block or shortcode overrides for this widget |

The result is written to the widget's inline `style` attribute.

```php
add_filter( 'jatcs_style_vars', function ( $vars, $overrides ) {
	$vars['--jatcs-radius'] = '0px';
	return $vars;
}, 10, 2 );
```

## The booking array

`JATCS_Bookings::get( $id )` returns `null` for anything that isn't a booking, otherwise:

| Key | Type | Description |
| --- | --- | --- |
| `id` | int | Post ID |
| `start`, `end` | int | Unix times |
| `date` | string | Start date, `Y-m-d`, site time zone |
| `name`, `email`, `notes`, `phone` | string | What the visitor entered |
| `status` | string | `pending`, `awaiting_payment`, `confirmed`, `cancelled` or `expired` |
| `event_id` | string | Event ID in the booking calendar (a CalDAV event URL for CalDAV) |
| `calendar_id` | string | Calendar reference the event is in, e.g. `primary`, `ms:AAMk…`, `caldav:https://…` |
| `meet_link` | string | Google Meet or Teams join link |
| `event_link` | string | Link to the event in Google Calendar or Outlook on the web |
| `type`, `type_name` | string | Appointment type ID and its name at booking time |
| `answers` | array | List of `{label, value}` |
| `location` | string | Location at booking time |
| `reschedules` | int | Times the visitor moved it |
| `amount` | int | Price in minor units (cents); 0 for free types |
| `currency` | string | Lowercase ISO code |
| `paid_at` | int | Unix time payment was confirmed, 0 if not paid |
| `payment` | string | Stripe PaymentIntent ID |
| `refunded` | int | Unix time of the refund, 0 if none |
| `refund_error` | string | Last refund error, if a refund failed |
| `hold_until` | int | Unix time an unpaid hold ends |
| `session` | string | Stripe Checkout Session ID |
| `checkout` | string | Stripe Checkout URL |

Related: [REST-API](REST-API) · [Data-and-Storage](Data-and-Storage) · [Styling-and-Theming](Styling-and-Theming) · [Architecture](Architecture) · [Emails-and-Reminders](Emails-and-Reminders)
