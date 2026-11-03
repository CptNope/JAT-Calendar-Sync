<?php
/**
 * Settings card: iCal (ICS) feed subscriptions.
 *
 * Included inside the settings form from settings.php.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

$jatcs_feeds = JATCS_Feeds::all();
?>
<div class="jatcs-card" id="jatcs-feeds">
	<h2><?php esc_html_e( 'iCal feeds (Sling, shift apps, shared and published calendars)', 'jat-calendar-sync' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Paste any calendar subscription link (https:// or webcal://, usually ending in .ics): Sling, When I Work, Deputy, 7shifts, iCloud shared calendars, Outlook published calendars, Google’s secret address in iCal format, Proton, Teamup, Airbnb and more. Feeds are checked every 10 minutes; their events block times. Feed links are stored encrypted.', 'jat-calendar-sync' ); ?></p>

	<?php if ( $jatcs_feeds ) : ?>
		<input type="hidden" name="jatcs[feeds_present]" value="1">
		<table class="widefat striped jatcs-feeds-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Feed', 'jat-calendar-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Blocks times', 'jat-calendar-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last checked', 'jat-calendar-sync' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'jat-calendar-sync' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $jatcs_feeds as $jatcs_id => $jatcs_feed ) : ?>
					<?php $jatcs_fetched = JATCS_Feeds::fetched_at( $jatcs_id ); ?>
					<tr>
						<td><strong><?php echo esc_html( $jatcs_feed['name'] ); ?></strong><br><span class="description"><?php echo esc_html( JATCS_Feeds::display_url( $jatcs_id ) ); ?></span></td>
						<td><label><input type="checkbox" name="jatcs[feeds_block][]" value="<?php echo esc_attr( $jatcs_id ); ?>" <?php checked( ! empty( $jatcs_feed['block'] ) ); ?>> <span class="screen-reader-text"><?php echo esc_html( $jatcs_feed['name'] ); ?></span></label></td>
						<td>
							<?php
							echo $jatcs_fetched
								/* translators: %s: time difference */
								? esc_html( sprintf( __( '%s ago', 'jat-calendar-sync' ), human_time_diff( $jatcs_fetched ) ) )
								: esc_html__( 'Not yet', 'jat-calendar-sync' );
							?>
						</td>
						<td><button type="submit" form="jatcs-feed-remove" name="feed" value="<?php echo esc_attr( $jatcs_id ); ?>" class="button-link button-link-delete"><?php esc_html_e( 'Remove', 'jat-calendar-sync' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="jatcs-feed-url"><?php esc_html_e( 'Add a feed', 'jat-calendar-sync' ); ?></label></th>
			<td>
				<input type="url" id="jatcs-feed-url" name="jatcs_feed[url]" form="jatcs-feed-add" class="large-text code" placeholder="webcal://…/calendar.ics">
				<p><label for="jatcs-feed-name"><?php esc_html_e( 'Name', 'jat-calendar-sync' ); ?></label> <input type="text" id="jatcs-feed-name" name="jatcs_feed[name]" form="jatcs-feed-add" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Work shifts', 'jat-calendar-sync' ); ?>">
				<button type="submit" form="jatcs-feed-add" class="button"><?php esc_html_e( 'Add feed', 'jat-calendar-sync' ); ?></button></p>
			</td>
		</tr>
	</table>
</div>
