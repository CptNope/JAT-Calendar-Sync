<?php
/**
 * Settings card: CalDAV account (iCloud, Fastmail, Nextcloud, Zoho, Yahoo, mailbox.org, any CalDAV server).
 *
 * Included inside the settings form from settings.php; $s holds the settings.
 * Connect/disconnect submit separate forms via the HTML form attribute.
 *
 * @package JATCalendarSync
 *
 * @var array $s
 */

defined( 'ABSPATH' ) || exit;

$jatcs_dav_connected = JATCS_CalDAV_Store::is_connected();
$jatcs_dav_cals      = $jatcs_dav_connected ? JATCS_CalDAV_Store::calendars( isset( $_GET['refresh'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache refresh.
$jatcs_dav_presets   = JATCS_CalDAV::presets();
?>
<div class="jatcs-card" id="jatcs-caldav">
	<h2><?php esc_html_e( 'iCloud, Fastmail, Nextcloud and other CalDAV calendars', 'jat-calendar-sync' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Connect an Apple iCloud, Fastmail, Nextcloud, Zoho, Yahoo, mailbox.org or any CalDAV account. Its calendars can block times, and bookings can be added to one of them.', 'jat-calendar-sync' ); ?></p>

	<?php if ( $jatcs_dav_connected ) : ?>
		<p class="jatcs-connection jatcs-connection--on">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: service name, 2: user name */
					__( 'Connected to %1$s as %2$s.', 'jat-calendar-sync' ),
					'<strong>' . esc_html( JATCS_CalDAV_Store::service_name() ) . '</strong>',
					'<strong>' . esc_html( JATCS_CalDAV_Store::username() ) . '</strong>'
				),
				array( 'strong' => array() )
			);
			?>
		</p>
		<?php if ( is_wp_error( $jatcs_dav_cals ) ) : ?>
			<p class="jatcs-error">
				<?php
				/* translators: %s: error */
				printf( esc_html__( 'Your calendars could not be loaded: %s', 'jat-calendar-sync' ), esc_html( $jatcs_dav_cals->get_error_message() ) );
				?>
			</p>
		<?php else : ?>
			<input type="hidden" name="jatcs[caldav_present]" value="1">
			<fieldset class="jatcs-calendar-list">
				<legend class="jatcs-control__label"><?php esc_html_e( 'Block times that are busy in', 'jat-calendar-sync' ); ?></legend>
				<?php foreach ( $jatcs_dav_cals as $jatcs_cal ) : ?>
					<label>
						<input type="checkbox" name="jatcs[caldav_calendars][]" value="<?php echo esc_attr( $jatcs_cal['url'] ); ?>" <?php checked( in_array( $jatcs_cal['url'], (array) $s['caldav_calendars'], true ) ); ?>>
						<?php echo esc_html( $jatcs_cal['name'] . ( empty( $jatcs_cal['writable'] ) ? ' ' . __( '(read only)', 'jat-calendar-sync' ) : '' ) ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<p class="description"><?php esc_html_e( 'Events marked Free (transparent) and cancelled events don’t block times. Recurring events are included.', 'jat-calendar-sync' ); ?></p>
		<?php endif; ?>
		<p><button type="submit" form="jatcs-caldav-disconnect" class="button"><?php esc_html_e( 'Disconnect', 'jat-calendar-sync' ); ?></button></p>
	<?php else : ?>
		<details class="jatcs-steps">
			<summary><?php esc_html_e( 'How to connect (about 2 minutes)', 'jat-calendar-sync' ); ?></summary>
			<ul>
				<li>
					<?php
					printf(
						/* translators: %s: link to Apple Account */
						esc_html__( 'iCloud: sign in at %s, open Sign-In and Security → App-Specific Passwords, and create one. Use your Apple Account email as the user name.', 'jat-calendar-sync' ),
						'<a href="https://account.apple.com/" target="_blank" rel="noopener">account.apple.com</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Fastmail: Settings → Privacy & Security → Manage app passwords, with CalDAV access. Yahoo: Account security → Generate app password.', 'jat-calendar-sync' ); ?></li>
				<li><?php esc_html_e( 'Nextcloud: Personal settings → Security → Create new app password, and enter your server’s address.', 'jat-calendar-sync' ); ?></li>
				<li><?php esc_html_e( 'Your main account password usually won’t work: these services require an app-specific password. It is stored encrypted.', 'jat-calendar-sync' ); ?></li>
			</ul>
		</details>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="jatcs-dav-preset"><?php esc_html_e( 'Service', 'jat-calendar-sync' ); ?></label></th>
				<td>
					<select id="jatcs-dav-preset" name="jatcs_caldav[preset]" form="jatcs-caldav-connect">
						<?php foreach ( $jatcs_dav_presets as $jatcs_key => $jatcs_preset ) : ?>
							<option value="<?php echo esc_attr( $jatcs_key ); ?>" data-url="<?php echo esc_attr( $jatcs_preset['url'] ); ?>"><?php echo esc_html( $jatcs_preset['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="jatcs-dav-url"><?php esc_html_e( 'Server address', 'jat-calendar-sync' ); ?></label></th>
				<td>
					<input type="url" id="jatcs-dav-url" name="jatcs_caldav[url]" form="jatcs-caldav-connect" class="regular-text code" placeholder="https://caldav.icloud.com/">
					<p class="description"><?php esc_html_e( 'Filled in for the services above; enter your own for Nextcloud or another server.', 'jat-calendar-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="jatcs-dav-user"><?php esc_html_e( 'User name or email', 'jat-calendar-sync' ); ?></label></th>
				<td><input type="text" id="jatcs-dav-user" name="jatcs_caldav[username]" form="jatcs-caldav-connect" class="regular-text" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><label for="jatcs-dav-pass"><?php esc_html_e( 'App-specific password', 'jat-calendar-sync' ); ?></label></th>
				<td><input type="password" id="jatcs-dav-pass" name="jatcs_caldav[password]" form="jatcs-caldav-connect" class="regular-text" autocomplete="new-password"></td>
			</tr>
		</table>
		<p><button type="submit" form="jatcs-caldav-connect" class="button button-primary"><?php esc_html_e( 'Connect', 'jat-calendar-sync' ); ?></button></p>
	<?php endif; ?>
</div>
