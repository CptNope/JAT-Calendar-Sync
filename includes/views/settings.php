<?php
/**
 * Settings page markup.
 *
 * Variables come from JATCS_Admin::render().
 *
 * @package JATCalendarSync
 *
 * @var array            $s
 * @var bool             $connected
 * @var array            $account
 * @var array|false      $notice
 * @var array|WP_Error   $calendars
 * @var array            $day_names
 * @var bool             $id_locked
 * @var bool             $secret_locked
 */

defined( 'ABSPATH' ) || exit;

$jatcs_has_secret = $secret_locked || '' !== JATCS_Settings::client_secret();
?>
<div class="wrap jatcs-admin">
	<h1><?php esc_html_e( 'JAT Calendar Sync', 'jat-calendar-sync' ); ?></h1>

	<?php if ( is_array( $notice ) ) : ?>
		<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( '' === (string) get_option( 'timezone_string' ) ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'Your site timezone is a fixed UTC offset, which ignores daylight saving time, so booking hours would shift by an hour twice a year.', 'jat-calendar-sync' ); ?>
			<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php esc_html_e( 'Choose a city under Settings → General', 'jat-calendar-sync' ); ?></a>
		</p></div>
	<?php endif; ?>

	<?php if ( ! JATCS_Crypto::available() ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'The OpenSSL PHP extension is missing, so your calendar credentials are stored without encryption. Ask your host to enable OpenSSL.', 'jat-calendar-sync' ); ?></p></div>
	<?php endif; ?>

	<div class="jatcs-card">
		<h2><?php esc_html_e( 'Google Calendar', 'jat-calendar-sync' ); ?></h2>

		<?php if ( $connected ) : ?>
			<p class="jatcs-connection jatcs-connection--on">
				<?php
				if ( $account['email'] ) {
					/* translators: %s: Google account email */
					printf( esc_html__( 'Connected as %s.', 'jat-calendar-sync' ), '<strong>' . esc_html( $account['email'] ) . '</strong>' );
				} else {
					esc_html_e( 'Connected.', 'jat-calendar-sync' );
				}
				?>
				<?php esc_html_e( 'Visitors can book any open time in your hours below.', 'jat-calendar-sync' ); ?>
			</p>
			<?php
			$jatcs_last   = (int) get_option( JATCS_Maintenance::LAST_RUN );
			$jatcs_cal_tz = 'google' === JATCS_Calendars::booking_service() ? JATCS_Google::calendar_timezone( JATCS_Calendars::parse( (string) $s['booking_calendar'] )[1] ) : '';
			?>
			<p class="description">
				<?php
				echo $jatcs_last
					/* translators: %s: human time difference, e.g. "20 mins" */
					? esc_html( sprintf( __( 'Last checked your calendar for moved or deleted appointments %s ago.', 'jat-calendar-sync' ), human_time_diff( $jatcs_last ) ) )
					: esc_html__( 'The hourly check for moved or deleted appointments hasn’t run yet.', 'jat-calendar-sync' );
				?>
			</p>
			<?php if ( '' !== $jatcs_cal_tz && '' !== (string) get_option( 'timezone_string' ) && wp_timezone_string() !== $jatcs_cal_tz ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					printf(
						/* translators: 1: Google Calendar timezone, 2: site timezone */
						esc_html__( 'Your Google Calendar uses %1$s but this site uses %2$s. Booking hours below follow the site timezone; appointments still land at the right moment in Google Calendar.', 'jat-calendar-sync' ),
						'<strong>' . esc_html( $jatcs_cal_tz ) . '</strong>',
						'<strong>' . esc_html( wp_timezone_string() ) . '</strong>'
					);
					?>
				</p></div>
			<?php endif; ?>
			<div class="jatcs-inline-forms">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="jatcs_run_now">
					<?php wp_nonce_field( 'jatcs_run_now' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Check calendars now', 'jat-calendar-sync' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="jatcs_disconnect">
					<?php wp_nonce_field( 'jatcs_disconnect' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Disconnect Google Calendar', 'jat-calendar-sync' ); ?></button>
				</form>
			</div>
		<?php else : ?>
			<p class="jatcs-connection"><?php esc_html_e( 'Not connected. Connect Google Calendar here, or use Outlook, iCloud or another CalDAV service below.', 'jat-calendar-sync' ); ?></p>

			<details class="jatcs-steps" <?php echo JATCS_Google::has_credentials() ? '' : 'open'; ?>>
				<summary><?php esc_html_e( 'How to get a Google client ID and secret (about 5 minutes)', 'jat-calendar-sync' ); ?></summary>
				<ol>
					<li>
						<?php
						printf(
							/* translators: %s: link to Google Cloud Console */
							esc_html__( 'In %s, create a project (or pick one) and enable the Google Calendar API.', 'jat-calendar-sync' ),
							'<a href="https://console.cloud.google.com/apis/library/calendar-json.googleapis.com" target="_blank" rel="noopener">Google Cloud Console</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Open “Google Auth Platform” (OAuth consent screen), choose External, and fill in the app name and your email.', 'jat-calendar-sync' ); ?></li>
					<li><?php esc_html_e( 'Under Audience, click “Publish app” so it is In production. Apps left in Testing lose access after 7 days and booking stops. You don’t need Google’s verification to use it with your own account; you will see an “unverified app” screen once while connecting.', 'jat-calendar-sync' ); ?></li>
					<li>
						<?php esc_html_e( 'Under Clients, create an OAuth client of type “Web application” and add this exact Authorized redirect URI:', 'jat-calendar-sync' ); ?>
						<span class="jatcs-copy">
							<input type="text" readonly value="<?php echo esc_attr( JATCS_Google::redirect_uri() ); ?>" class="large-text code" id="jatcs-redirect-uri">
							<button type="button" class="button" data-jatcs-copy="jatcs-redirect-uri"><?php esc_html_e( 'Copy', 'jat-calendar-sync' ); ?></button>
						</span>
					</li>
					<li><?php esc_html_e( 'Paste the client ID and client secret below, save, then click Connect Google Calendar.', 'jat-calendar-sync' ); ?></li>
				</ol>
			</details>

			<?php if ( JATCS_Google::has_credentials() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="jatcs_connect">
					<?php wp_nonce_field( 'jatcs_connect' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect Google Calendar', 'jat-calendar-sync' ); ?></button>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jatcs-settings-form">
		<input type="hidden" name="action" value="jatcs_save_settings">
		<?php wp_nonce_field( 'jatcs_save_settings' ); ?>

		<div class="jatcs-card">
			<h2><?php esc_html_e( 'Google credentials', 'jat-calendar-sync' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="jatcs-client-id"><?php esc_html_e( 'Client ID', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<?php if ( $id_locked ) : ?>
							<code><?php esc_html_e( 'Set in wp-config.php', 'jat-calendar-sync' ); ?></code>
						<?php else : ?>
							<input type="text" id="jatcs-client-id" name="jatcs[client_id]" value="<?php echo esc_attr( $s['client_id'] ); ?>" class="large-text code" autocomplete="off">
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-client-secret"><?php esc_html_e( 'Client secret', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<?php if ( $secret_locked ) : ?>
							<code><?php esc_html_e( 'Set in wp-config.php', 'jat-calendar-sync' ); ?></code>
						<?php else : ?>
							<input type="password" id="jatcs-client-secret" name="jatcs[client_secret]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php echo $jatcs_has_secret ? esc_attr__( 'Saved. Leave blank to keep it.', 'jat-calendar-sync' ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'Stored encrypted. You can instead define JATCS_GOOGLE_CLIENT_ID and JATCS_GOOGLE_CLIENT_SECRET in wp-config.php.', 'jat-calendar-sync' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<?php if ( $connected ) : ?>
			<div class="jatcs-card">
				<h2><?php esc_html_e( 'Google calendars that block times', 'jat-calendar-sync' ); ?></h2>
				<?php if ( is_wp_error( $calendars ) ) : ?>
					<p class="jatcs-error">
						<?php
						/* translators: %s: error message */
						printf( esc_html__( 'Your calendars could not be loaded: %s', 'jat-calendar-sync' ), esc_html( $calendars->get_error_message() ) );
						?>
					</p>
				<?php else : ?>
					<input type="hidden" name="jatcs[calendars_present]" value="1">
					<fieldset class="jatcs-calendar-list">
						<legend class="screen-reader-text"><?php esc_html_e( 'Google calendars that block times', 'jat-calendar-sync' ); ?></legend>
						<?php foreach ( $calendars as $jatcs_cal ) : ?>
							<?php $jatcs_value = $jatcs_cal['primary'] ? 'primary' : $jatcs_cal['id']; ?>
							<label>
								<input type="checkbox" name="jatcs[conflict_calendars][]" value="<?php echo esc_attr( $jatcs_value ); ?>" <?php checked( in_array( $jatcs_value, (array) $s['conflict_calendars'], true ) || in_array( $jatcs_cal['id'], (array) $s['conflict_calendars'], true ) ); ?>>
								<?php echo esc_html( $jatcs_cal['summary'] ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'Busy events on checked calendars hide those times. Only free/busy is read; event details stay private.', 'jat-calendar-sync' ); ?>
						<a href="<?php echo esc_url( JATCS_Admin::page_url( array( 'refresh' => 1 ) ) ); ?>"><?php esc_html_e( 'Refresh list', 'jat-calendar-sync' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php require JATCS_DIR . 'includes/views/microsoft.php'; ?>
		<?php require JATCS_DIR . 'includes/views/caldav.php'; ?>
		<?php require JATCS_DIR . 'includes/views/feeds.php'; ?>

		<?php
		$jatcs_writable = JATCS_Calendars::choices( true );
		$jatcs_all      = JATCS_Calendars::choices();
		$jatcs_booking  = JATCS_Calendars::booking_ref();
		$jatcs_schedule = JATCS_Calendars::canonical( (string) $s['schedule_calendar'] );
		?>
		<div class="jatcs-card" id="jatcs-routing">
			<h2><?php esc_html_e( 'Where bookings go', 'jat-calendar-sync' ); ?></h2>
			<input type="hidden" name="jatcs[routing_present]" value="1">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="jatcs-booking-calendar"><?php esc_html_e( 'Add bookings to', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<?php if ( ! $jatcs_writable ) : ?>
							<p class="jatcs-error"><?php esc_html_e( 'Connect Google Calendar, Outlook, or a CalDAV account such as iCloud above, then choose the calendar new bookings are added to.', 'jat-calendar-sync' ); ?></p>
						<?php else : ?>
							<?php if ( ! JATCS_Calendars::ready() ) : ?>
								<p class="jatcs-error">
									<?php
									/* translators: %s: calendar name */
									echo esc_html( sprintf( __( 'Bookings are set to go to “%s”, which can’t be used right now (its service isn’t connected or can’t add events), so visitors can’t book. Choose another calendar and save, or reconnect.', 'jat-calendar-sync' ), JATCS_Calendars::label( JATCS_Calendars::booking_ref() ) ) );
									?>
								</p>
							<?php endif; ?>
							<select id="jatcs-booking-calendar" name="jatcs[booking_calendar]">
								<?php
								$jatcs_group = '';
								foreach ( $jatcs_writable as $jatcs_choice ) :
									if ( $jatcs_group !== $jatcs_choice['service_name'] ) :
										if ( '' !== $jatcs_group ) :
											echo '</optgroup>';
										endif;
										$jatcs_group = $jatcs_choice['service_name'];
										echo '<optgroup label="' . esc_attr( $jatcs_group ) . '">';
									endif;
									?>
									<option value="<?php echo esc_attr( $jatcs_choice['ref'] ); ?>" <?php selected( JATCS_Calendars::canonical( $jatcs_choice['ref'] ), $jatcs_booking ); ?>><?php echo esc_html( $jatcs_choice['name'] ); ?></option>
								<?php endforeach; ?>
								</optgroup>
							</select>
							<p class="description"><?php esc_html_e( 'New appointments are created here, and its busy times always block slots. Video calls: Google Meet in Google Calendar, Microsoft Teams in Outlook work and school accounts.', 'jat-calendar-sync' ); ?></p>
							<?php if ( 'ms' === JATCS_Calendars::booking_service() && JATCS_Microsoft::is_connected() && ! JATCS_Microsoft::can_write() ) : ?>
								<p class="jatcs-error"><?php esc_html_e( 'Outlook was connected with read-only access. Click “Allow adding bookings to Outlook” in the Outlook section to let bookings be added to it.', 'jat-calendar-sync' ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-schedule-calendar"><?php esc_html_e( 'Open booking only as far as', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<select id="jatcs-schedule-calendar" name="jatcs[schedule_calendar]">
							<option value=""><?php esc_html_e( 'My “Book up to” limit (no work schedule)', 'jat-calendar-sync' ); ?></option>
							<?php foreach ( $jatcs_all as $jatcs_choice ) : ?>
								<option value="<?php echo esc_attr( $jatcs_choice['ref'] ); ?>" <?php selected( JATCS_Calendars::canonical( $jatcs_choice['ref'] ), $jatcs_schedule ); ?>>
									<?php
									/* translators: 1: calendar name, 2: service name */
									echo esc_html( sprintf( __( 'my posted schedule on “%1$s” (%2$s)', 'jat-calendar-sync' ), $jatcs_choice['name'], $jatcs_choice['service_name'] ) );
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p><label><input type="checkbox" name="jatcs[schedule_week]" value="1" <?php checked( ! empty( $s['schedule_week'] ) ); ?>> <?php esc_html_e( 'and the rest of that week, through Sunday', 'jat-calendar-sync' ); ?></label></p>
						<p><label><?php esc_html_e( 'Keep free before and after each shift:', 'jat-calendar-sync' ); ?> <input type="number" min="0" max="480" name="jatcs[schedule_buffer]" value="<?php echo esc_attr( (int) $s['schedule_buffer'] ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'jat-calendar-sync' ); ?></label></p>
						<p class="description"><?php esc_html_e( 'For shift work (Sling, When I Work, Deputy, 7shifts…): booking opens only up to your last posted shift, so nobody books a time that may still become a shift. When the next schedule is posted, those days open automatically. If nothing is posted, nothing can be booked. Shifts on this calendar always block their own times, plus the free time you set around them. Tip: add your schedule app’s calendar link as an iCal feed above; it is checked every 10 minutes.', 'jat-calendar-sync' ); ?></p>
						<?php
						$jatcs_horizon = '' !== $jatcs_schedule ? JATCS_Availability::schedule_horizon() : null;
						if ( null !== $jatcs_horizon ) :
							?>
							<p class="description"><strong>
								<?php
								echo $jatcs_horizon < wp_date( 'Y-m-d' )
									? esc_html__( 'Right now: no upcoming schedule found, so booking is closed.', 'jat-calendar-sync' )
									/* translators: %s: date */
									: esc_html( sprintf( __( 'Right now: booking is open through %s.', 'jat-calendar-sync' ), wp_date( get_option( 'date_format' ), strtotime( $jatcs_horizon . ' 12:00' ) ) ) );
								?>
							</strong></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<div class="jatcs-card">
			<h2><?php esc_html_e( 'When people can book', 'jat-calendar-sync' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the appointment types screen */
					esc_html__( 'Appointment length, start times and booking questions are set per %s. Types use these hours unless you give them their own.', 'jat-calendar-sync' ),
					'<a href="' . esc_url( JATCS_Admin_Types::url() ) . '">' . esc_html__( 'appointment type', 'jat-calendar-sync' ) . '</a>'
				);
				?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: timezone name */
					esc_html__( 'Hours use 24-hour time in your site timezone (%s). Separate breaks with commas, e.g. 09:00-12:00, 13:00-17:00. Leave a day empty to close it.', 'jat-calendar-sync' ),
					'<strong>' . esc_html( wp_timezone_string() ) . '</strong>'
				);
				?>
			</p>
			<table class="form-table" role="presentation">
				<?php foreach ( $day_names as $jatcs_key => $jatcs_label ) : ?>
					<tr>
						<th scope="row"><label for="jatcs-hours-<?php echo esc_attr( $jatcs_key ); ?>"><?php echo esc_html( $jatcs_label ); ?></label></th>
						<td><input type="text" id="jatcs-hours-<?php echo esc_attr( $jatcs_key ); ?>" name="jatcs[hours][<?php echo esc_attr( $jatcs_key ); ?>]" value="<?php echo esc_attr( isset( $s['hours'][ $jatcs_key ] ) ? $s['hours'][ $jatcs_key ] : '' ); ?>" class="regular-text code" placeholder="<?php esc_attr_e( 'Closed', 'jat-calendar-sync' ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<table class="form-table" role="presentation">
				<?php
				$jatcs_numbers = array(
					'buffer_before'   => array( __( 'Free time before', 'jat-calendar-sync' ), __( 'minutes', 'jat-calendar-sync' ), __( 'Keeps this much time clear before each appointment.', 'jat-calendar-sync' ) ),
					'buffer_after'    => array( __( 'Free time after', 'jat-calendar-sync' ), __( 'minutes', 'jat-calendar-sync' ), '' ),
					'min_notice'      => array( __( 'Minimum notice', 'jat-calendar-sync' ), __( 'hours', 'jat-calendar-sync' ), __( 'No bookings sooner than this from now.', 'jat-calendar-sync' ) ),
					'max_days'        => array( __( 'Book up to', 'jat-calendar-sync' ), __( 'days ahead', 'jat-calendar-sync' ), '' ),
					'daily_limit'     => array( __( 'Most bookings per day', 'jat-calendar-sync' ), '', __( '0 means no limit.', 'jat-calendar-sync' ) ),
					'max_per_email'   => array( __( 'Upcoming bookings per person', 'jat-calendar-sync' ), '', __( 'How many future appointments one email address can hold at once. 0 means no limit.', 'jat-calendar-sync' ) ),
					'change_cutoff'   => array( __( 'Changes allowed until', 'jat-calendar-sync' ), __( 'hours before', 'jat-calendar-sync' ), __( 'Visitors can reschedule or cancel online until this long before the appointment. 0 means any time before it starts.', 'jat-calendar-sync' ) ),
					'max_reschedules' => array( __( 'Reschedules per booking', 'jat-calendar-sync' ), '', __( 'How many times a visitor can move one appointment online. 0 means no limit.', 'jat-calendar-sync' ) ),
				);
				foreach ( $jatcs_numbers as $jatcs_key => $jatcs_meta ) :
					?>
					<tr>
						<th scope="row"><label for="jatcs-<?php echo esc_attr( $jatcs_key ); ?>"><?php echo esc_html( $jatcs_meta[0] ); ?></label></th>
						<td>
							<input type="number" min="0" id="jatcs-<?php echo esc_attr( $jatcs_key ); ?>" name="jatcs[<?php echo esc_attr( $jatcs_key ); ?>]" value="<?php echo esc_attr( $s[ $jatcs_key ] ); ?>" class="small-text"> <?php echo esc_html( $jatcs_meta[1] ); ?>
							<?php if ( $jatcs_meta[2] ) : ?>
								<p class="description"><?php echo esc_html( $jatcs_meta[2] ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="jatcs-days-off"><?php esc_html_e( 'Days off', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<textarea id="jatcs-days-off" name="jatcs[days_off]" rows="4" class="regular-text code" placeholder="2026-11-26  # Thanksgiving&#10;2026-12-24 to 2027-01-02"><?php echo esc_textarea( $s['days_off'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One date or range per line (YYYY-MM-DD, or YYYY-MM-DD to YYYY-MM-DD). Nothing can be booked on these days.', 'jat-calendar-sync' ); ?></p>
						<p class="description"><?php esc_html_e( 'Tip: all-day events in Google Calendar (vacations, holidays) are marked “Free” by default, so they don’t block bookings. Set them to “Busy” in Google Calendar, or list the dates here.', 'jat-calendar-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="jatcs-card">
			<h2><?php esc_html_e( 'The appointment', 'jat-calendar-sync' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="jatcs-event-title"><?php esc_html_e( 'Calendar event title', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<input type="text" id="jatcs-event-title" name="jatcs[event_title]" value="<?php echo esc_attr( $s['event_title'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( '{name} is replaced with the visitor’s name, {type} with the appointment type, {site} with your site name.', 'jat-calendar-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-location"><?php esc_html_e( 'Location', 'jat-calendar-sync' ); ?></label></th>
					<td><input type="text" id="jatcs-location" name="jatcs[location]" value="<?php echo esc_attr( $s['location'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional: an address or phone number', 'jat-calendar-sync' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Video call', 'jat-calendar-sync' ); ?></th>
					<td><label><input type="checkbox" name="jatcs[add_meet]" value="1" <?php checked( $s['add_meet'] ); ?>> <?php esc_html_e( 'Add a Google Meet link to each appointment', 'jat-calendar-sync' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Calendar invite', 'jat-calendar-sync' ); ?></th>
					<td><label><input type="checkbox" name="jatcs[invite_guest]" value="1" <?php checked( $s['invite_guest'] ); ?>> <?php esc_html_e( 'Add the visitor as a guest so Google or Outlook sends them an invite and updates', 'jat-calendar-sync' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-phone-field"><?php esc_html_e( 'Phone number', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<select id="jatcs-phone-field" name="jatcs[phone_field]">
							<option value="off" <?php selected( $s['phone_field'], 'off' ); ?>><?php esc_html_e( 'Don’t ask', 'jat-calendar-sync' ); ?></option>
							<option value="optional" <?php selected( $s['phone_field'], 'optional' ); ?>><?php esc_html_e( 'Ask (optional)', 'jat-calendar-sync' ); ?></option>
							<option value="required" <?php selected( $s['phone_field'], 'required' ); ?>><?php esc_html_e( 'Ask (required)', 'jat-calendar-sync' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-notify-email"><?php esc_html_e( 'Notify me at', 'jat-calendar-sync' ); ?></label></th>
					<td><input type="email" id="jatcs-notify-email" name="jatcs[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-reminder"><?php esc_html_e( 'Reminder email', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<input type="number" min="0" max="168" id="jatcs-reminder" name="jatcs[reminder_hours]" value="<?php echo esc_attr( $s['reminder_hours'] ); ?>" class="small-text"> <?php esc_html_e( 'hours before', 'jat-calendar-sync' ); ?>
						<p class="description"><?php esc_html_e( '0 turns reminders off. Google and Outlook also remind invited guests.', 'jat-calendar-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-confirmation"><?php esc_html_e( 'Message after booking', 'jat-calendar-sync' ); ?></label></th>
					<td><textarea id="jatcs-confirmation" name="jatcs[confirmation_message]" rows="2" class="large-text"><?php echo esc_textarea( $s['confirmation_message'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-consent"><?php esc_html_e( 'Consent checkbox', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<textarea id="jatcs-consent" name="jatcs[consent_text]" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Optional, e.g. I agree to the privacy policy.', 'jat-calendar-sync' ); ?>"><?php echo esc_textarea( $s['consent_text'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'When filled in, visitors must tick a box with this text before booking.', 'jat-calendar-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="jatcs-card" id="jatcs-payments">
			<h2><?php esc_html_e( 'Payments (Stripe)', 'jat-calendar-sync' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Optional. Give an appointment type a price and visitors pay on Stripe’s secure checkout page before the booking is confirmed. Free types are unaffected.', 'jat-calendar-sync' ); ?></p>
			<?php if ( JATCS_Stripe::enabled() ) : ?>
				<p class="jatcs-connection jatcs-connection--on">
					<?php echo JATCS_Stripe::test_mode() ? esc_html__( 'Stripe connected in test mode: use Stripe’s test cards, no real money moves.', 'jat-calendar-sync' ) : esc_html__( 'Stripe connected (live).', 'jat-calendar-sync' ); ?>
				</p>
			<?php endif; ?>
			<details class="jatcs-steps" <?php echo JATCS_Stripe::enabled() ? '' : 'open'; ?>>
				<summary><?php esc_html_e( 'How to set up Stripe (about 3 minutes)', 'jat-calendar-sync' ); ?></summary>
				<ol>
					<li><?php esc_html_e( 'In the Stripe dashboard, open Developers → API keys and copy the Secret key (start with the test key, sk_test_…).', 'jat-calendar-sync' ); ?></li>
					<li>
						<?php esc_html_e( 'Open Developers → Webhooks, add an endpoint with this URL, and choose the events checkout.session.completed, checkout.session.expired, checkout.session.async_payment_succeeded and checkout.session.async_payment_failed:', 'jat-calendar-sync' ); ?>
						<span class="jatcs-copy">
							<input type="text" readonly value="<?php echo esc_attr( JATCS_Stripe::webhook_url() ); ?>" class="large-text code" id="jatcs-webhook-url">
							<button type="button" class="button" data-jatcs-copy="jatcs-webhook-url"><?php esc_html_e( 'Copy', 'jat-calendar-sync' ); ?></button>
						</span>
					</li>
					<li><?php esc_html_e( 'Copy the endpoint’s Signing secret (whsec_…) and paste both below. Without the webhook, bookings are still confirmed when visitors return from paying, or within the hour.', 'jat-calendar-sync' ); ?></li>
				</ol>
			</details>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="jatcs-stripe-secret"><?php esc_html_e( 'Secret key', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<?php if ( defined( 'JATCS_STRIPE_SECRET_KEY' ) ) : ?>
							<code><?php esc_html_e( 'Set in wp-config.php', 'jat-calendar-sync' ); ?></code>
						<?php else : ?>
							<input type="password" id="jatcs-stripe-secret" name="jatcs[stripe_secret]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php echo '' !== JATCS_Stripe::secret() ? esc_attr__( 'Saved. Leave blank to keep it.', 'jat-calendar-sync' ) : 'sk_test_…'; ?>">
							<?php if ( '' !== JATCS_Stripe::secret() ) : ?>
								<label class="jatcs-inline-check"><input type="checkbox" name="jatcs[stripe_clear]" value="1"> <?php esc_html_e( 'Remove saved keys', 'jat-calendar-sync' ); ?></label>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-stripe-whsec"><?php esc_html_e( 'Webhook signing secret', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<?php if ( defined( 'JATCS_STRIPE_WEBHOOK_SECRET' ) ) : ?>
							<code><?php esc_html_e( 'Set in wp-config.php', 'jat-calendar-sync' ); ?></code>
						<?php else : ?>
							<input type="password" id="jatcs-stripe-whsec" name="jatcs[stripe_webhook_secret]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php echo '' !== JATCS_Stripe::webhook_secret() ? esc_attr__( 'Saved. Leave blank to keep it.', 'jat-calendar-sync' ) : 'whsec_…'; ?>">
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Both are stored encrypted. You can instead define JATCS_STRIPE_SECRET_KEY and JATCS_STRIPE_WEBHOOK_SECRET in wp-config.php.', 'jat-calendar-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-currency"><?php esc_html_e( 'Currency', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<input type="text" id="jatcs-currency" name="jatcs[currency]" value="<?php echo esc_attr( strtoupper( JATCS_Payments::currency() ) ); ?>" class="small-text code" maxlength="3" pattern="[A-Za-z]{3}">
						<p class="description"><?php esc_html_e( 'Three-letter code such as USD, EUR, GBP. Changing it doesn’t convert prices you’ve already set.', 'jat-calendar-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-refund-cutoff"><?php esc_html_e( 'Refund visitor cancellations until', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<input type="number" min="0" max="720" id="jatcs-refund-cutoff" name="jatcs[refund_cutoff]" value="<?php echo esc_attr( $s['refund_cutoff'] ); ?>" class="small-text"> <?php esc_html_e( 'hours before', 'jat-calendar-sync' ); ?>
						<p class="description"><?php esc_html_e( 'Visitors who cancel earlier than this get a full refund automatically; later cancellations aren’t refunded. When you cancel (or delete the event in your calendar), the visitor is always refunded in full.', 'jat-calendar-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>


		<?php require JATCS_DIR . 'includes/views/appearance.php'; ?>

		<div class="jatcs-card">
			<h2><?php esc_html_e( 'Show the booking calendar', 'jat-calendar-sync' ); ?></h2>
			<p><?php esc_html_e( 'Add the “Appointment Booking” block to any page, or paste this shortcode:', 'jat-calendar-sync' ); ?></p>
			<p><code>[jat_booking]</code></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'When uninstalling', 'jat-calendar-sync' ); ?></th>
					<td><label><input type="checkbox" name="jatcs[delete_data]" value="1" <?php checked( $s['delete_data'] ); ?>> <?php esc_html_e( 'Delete all settings and booking records (events stay in your calendar)', 'jat-calendar-sync' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="jatcs-retention"><?php esc_html_e( 'Keep booking records', 'jat-calendar-sync' ); ?></label></th>
					<td>
						<input type="number" min="0" id="jatcs-retention" name="jatcs[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days after the appointment', 'jat-calendar-sync' ); ?>
						<p class="description"><?php esc_html_e( 'Older records (names, emails, notes) are deleted from this site automatically. 0 keeps them. Calendar events are not touched.', 'jat-calendar-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save settings', 'jat-calendar-sync' ) ); ?>
	</form>

	<?php // Forms can't nest: these are targeted by buttons inside the settings form via form="…". ?>
	<form id="jatcs-ms-connect" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="jatcs_ms_connect">
		<?php wp_nonce_field( 'jatcs_ms_connect', '_wpnonce', true, true ); ?>
	</form>
	<form id="jatcs-ms-disconnect" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="jatcs_ms_disconnect">
		<?php wp_nonce_field( 'jatcs_ms_disconnect', '_wpnonce', true, true ); ?>
	</form>
	<form id="jatcs-caldav-connect" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="jatcs_caldav_connect">
		<?php wp_nonce_field( 'jatcs_caldav_connect', '_wpnonce', true, true ); ?>
	</form>
	<form id="jatcs-caldav-disconnect" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="jatcs_caldav_disconnect">
		<?php wp_nonce_field( 'jatcs_caldav_disconnect', '_wpnonce', true, true ); ?>
	</form>
	<form id="jatcs-feed-add" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="jatcs_feed_add">
		<?php wp_nonce_field( 'jatcs_feed_add', '_wpnonce', true, true ); ?>
	</form>
	<form id="jatcs-feed-remove" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="jatcs_feed_remove">
		<?php wp_nonce_field( 'jatcs_feed_remove', '_wpnonce', true, true ); ?>
	</form>

	<?php $jatcs_log = get_option( JATCS_Google::LOG_OPTION, array() ); ?>
	<?php if ( is_array( $jatcs_log ) && $jatcs_log ) : ?>
		<div class="jatcs-card">
			<h2><?php esc_html_e( 'Recent problems talking to your calendars', 'jat-calendar-sync' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Occasional timeouts are normal and are retried automatically. Repeated errors usually mean the connection needs attention.', 'jat-calendar-sync' ); ?></p>
			<table class="widefat striped jatcs-log">
				<thead><tr><th><?php esc_html_e( 'When', 'jat-calendar-sync' ); ?></th><th><?php esc_html_e( 'Doing', 'jat-calendar-sync' ); ?></th><th><?php esc_html_e( 'Error', 'jat-calendar-sync' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( array_slice( $jatcs_log, 0, 10 ) as $jatcs_entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $jatcs_entry['time'] ) ); ?></td>
							<td><code><?php echo esc_html( $jatcs_entry['context'] ); ?></code></td>
							<td><?php echo esc_html( $jatcs_entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jatcs-log-clear">
				<input type="hidden" name="action" value="jatcs_clear_log">
				<?php wp_nonce_field( 'jatcs_clear_log' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Clear', 'jat-calendar-sync' ); ?></button>
			</form>
		</div>
	<?php endif; ?>
</div>
