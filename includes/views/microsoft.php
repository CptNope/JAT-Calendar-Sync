<?php
/**
 * Settings card: Microsoft 365 / Outlook conflict calendars.
 *
 * Included inside the settings form from settings.php; $s holds the settings.
 * Connect/disconnect buttons submit separate forms via the HTML form attribute.
 *
 * @package JATCalendarSync
 *
 * @var array $s
 */

defined( 'ABSPATH' ) || exit;

$jatcs_ms_connected = JATCS_Microsoft::is_connected();
$jatcs_ms_cals      = $jatcs_ms_connected ? JATCS_Microsoft::calendars( isset( $_GET['refresh'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache refresh.
?>
<div class="jatcs-card" id="jatcs-outlook">
	<h2><?php esc_html_e( 'Outlook / Microsoft 365 calendars', 'jat-calendar-sync' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Connect Outlook (Microsoft 365, work, school or personal) to block times with its busy events, or to add bookings to an Outlook calendar with a Teams link (work and school accounts).', 'jat-calendar-sync' ); ?></p>

	<?php if ( $jatcs_ms_connected ) : ?>
		<p class="jatcs-connection jatcs-connection--on">
			<?php
			echo '' !== JATCS_Microsoft::account()
				/* translators: %s: Microsoft account */
				? wp_kses( sprintf( __( 'Connected as %s.', 'jat-calendar-sync' ), '<strong>' . esc_html( JATCS_Microsoft::account() ) . '</strong>' ), array( 'strong' => array() ) )
				: esc_html__( 'Connected.', 'jat-calendar-sync' );
			?>
		</p>
		<?php if ( is_wp_error( $jatcs_ms_cals ) ) : ?>
			<p class="jatcs-error">
				<?php
				/* translators: %s: error */
				printf( esc_html__( 'Your Outlook calendars could not be loaded: %s', 'jat-calendar-sync' ), esc_html( $jatcs_ms_cals->get_error_message() ) );
				?>
			</p>
		<?php else : ?>
			<input type="hidden" name="jatcs[ms_calendars_present]" value="1">
			<fieldset class="jatcs-calendar-list">
				<legend class="jatcs-control__label"><?php esc_html_e( 'Block times that are busy in', 'jat-calendar-sync' ); ?></legend>
				<?php foreach ( $jatcs_ms_cals as $jatcs_cal ) : ?>
					<label>
						<input type="checkbox" name="jatcs[ms_calendars][]" value="<?php echo esc_attr( $jatcs_cal['id'] ); ?>" <?php checked( in_array( $jatcs_cal['id'], (array) $s['ms_calendars'], true ) ); ?>>
						<?php echo esc_html( $jatcs_cal['name'] . ( $jatcs_cal['default'] ? ' ' . __( '(default)', 'jat-calendar-sync' ) : '' ) ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<p class="description"><?php esc_html_e( 'Events shown as Busy, Tentative or Out of office block times; Free and Working elsewhere don’t. Recurring meetings are included.', 'jat-calendar-sync' ); ?></p>
		<?php endif; ?>
		<p>
			<?php if ( ! JATCS_Microsoft::can_write() ) : ?>
				<button type="submit" form="jatcs-ms-connect" name="write" value="1" class="button"><?php esc_html_e( 'Allow adding bookings to Outlook', 'jat-calendar-sync' ); ?></button>
			<?php endif; ?>
			<button type="submit" form="jatcs-ms-disconnect" class="button"><?php esc_html_e( 'Disconnect Outlook', 'jat-calendar-sync' ); ?></button>
		</p>
	<?php else : ?>
		<details class="jatcs-steps">
			<summary><?php esc_html_e( 'How to connect Outlook (about 5 minutes)', 'jat-calendar-sync' ); ?></summary>
			<ol>
				<li>
					<?php
					printf(
						/* translators: %s: link to Microsoft Entra admin center */
						esc_html__( 'In %s, go to App registrations → New registration.', 'jat-calendar-sync' ),
						'<a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">Microsoft Entra</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Supported account types: “Accounts in any organizational directory and personal Microsoft accounts”. Redirect URI: platform Web, with this exact address:', 'jat-calendar-sync' ); ?>
					<span class="jatcs-copy">
						<input type="text" readonly value="<?php echo esc_attr( JATCS_Microsoft::redirect_uri() ); ?>" class="large-text code" id="jatcs-ms-redirect">
						<button type="button" class="button" data-jatcs-copy="jatcs-ms-redirect"><?php esc_html_e( 'Copy', 'jat-calendar-sync' ); ?></button>
					</span>
				</li>
				<li><?php esc_html_e( 'Under API permissions, add Microsoft Graph delegated permissions Calendars.ReadWrite, User.Read and offline_access.', 'jat-calendar-sync' ); ?></li>
				<li><?php esc_html_e( 'Under Certificates & secrets, create a client secret. Paste the Application (client) ID and the secret’s Value below, save, then click Connect Outlook. Secrets expire (24 months at most), so note the date.', 'jat-calendar-sync' ); ?></li>
			</ol>
		</details>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="jatcs-ms-client-id"><?php esc_html_e( 'Application (client) ID', 'jat-calendar-sync' ); ?></label></th>
				<td>
					<?php if ( defined( 'JATCS_MS_CLIENT_ID' ) ) : ?>
						<code><?php esc_html_e( 'Set in wp-config.php', 'jat-calendar-sync' ); ?></code>
					<?php else : ?>
						<input type="text" id="jatcs-ms-client-id" name="jatcs[ms_client_id]" value="<?php echo esc_attr( $s['ms_client_id'] ); ?>" class="regular-text code" autocomplete="off">
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="jatcs-ms-client-secret"><?php esc_html_e( 'Client secret', 'jat-calendar-sync' ); ?></label></th>
				<td>
					<?php if ( defined( 'JATCS_MS_CLIENT_SECRET' ) ) : ?>
						<code><?php esc_html_e( 'Set in wp-config.php', 'jat-calendar-sync' ); ?></code>
					<?php else : ?>
						<input type="password" id="jatcs-ms-client-secret" name="jatcs[ms_client_secret]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php echo '' !== JATCS_Microsoft::client_secret() ? esc_attr__( 'Saved. Leave blank to keep it.', 'jat-calendar-sync' ) : ''; ?>">
						<p class="description"><?php esc_html_e( 'Stored encrypted. You can instead define JATCS_MS_CLIENT_ID and JATCS_MS_CLIENT_SECRET in wp-config.php.', 'jat-calendar-sync' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php if ( JATCS_Microsoft::has_credentials() ) : ?>
			<p>
				<button type="submit" form="jatcs-ms-connect" class="button button-primary"><?php esc_html_e( 'Connect Outlook to block times', 'jat-calendar-sync' ); ?></button>
				<button type="submit" form="jatcs-ms-connect" name="write" value="1" class="button"><?php esc_html_e( 'Connect and allow adding bookings', 'jat-calendar-sync' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Adding bookings needs permission to create events (Calendars.ReadWrite). Some work accounts need an administrator to approve that.', 'jat-calendar-sync' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
