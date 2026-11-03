<?php
/**
 * Admin screens: settings, Google connection, bookings list.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin integration.
 */
final class JATCS_Admin {

	const SLUG = 'jatcs-settings';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'global_notice' ) );
		add_action( 'admin_post_jatcs_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_jatcs_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_jatcs_oauth', array( __CLASS__, 'oauth_callback' ) );
		add_action( 'admin_post_jatcs_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_jatcs_cancel_booking', array( __CLASS__, 'cancel_booking' ) );
		add_action( 'admin_post_jatcs_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_jatcs_ms_connect', array( __CLASS__, 'ms_connect' ) );
		add_action( 'admin_post_jatcs_ms_oauth', array( __CLASS__, 'ms_oauth' ) );
		add_action( 'admin_post_jatcs_ms_disconnect', array( __CLASS__, 'ms_disconnect' ) );
		add_action( 'admin_post_jatcs_caldav_connect', array( __CLASS__, 'caldav_connect' ) );
		add_action( 'admin_post_jatcs_caldav_disconnect', array( __CLASS__, 'caldav_disconnect' ) );
		add_action( 'admin_post_jatcs_feed_add', array( __CLASS__, 'feed_add' ) );
		add_action( 'admin_post_jatcs_feed_remove', array( __CLASS__, 'feed_remove' ) );
		add_action( 'admin_post_jatcs_clear_log', array( __CLASS__, 'clear_log' ) );
		add_action( 'admin_post_jatcs_run_now', array( __CLASS__, 'run_now' ) );
		add_action( 'manage_posts_extra_tablenav', array( __CLASS__, 'export_button' ) );
		add_filter( 'manage_' . JATCS_Bookings::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . JATCS_Bookings::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes_' . JATCS_Bookings::POST_TYPE, array( __CLASS__, 'meta_box' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( JATCS_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_text' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_menu_page(
			__( 'Appointments', 'jat-calendar-sync' ),
			__( 'Appointments', 'jat-calendar-sync' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-calendar-alt',
			26
		);
		add_submenu_page( self::SLUG, __( 'Appointment settings', 'jat-calendar-sync' ), __( 'Settings', 'jat-calendar-sync' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'jat-calendar-sync' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings page URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Admin CSS/JS on our screen only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( $screen && JATCS_Bookings::POST_TYPE === $screen->post_type ) {
			wp_enqueue_style( 'jatcs-admin', JATCS_URL . 'assets/css/admin.css', array(), JATCS_VERSION );
			return;
		}
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'jatcs-admin', JATCS_URL . 'assets/css/admin.css', array(), JATCS_VERSION );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'jatcs-admin', JATCS_URL . 'assets/js/admin.js', array( 'wp-color-picker' ), JATCS_VERSION, true );
		$tokens = JATCS_Style::theme_tokens();
		wp_localize_script(
			'jatcs-admin',
			'jatcsAdmin',
			array(
				'tokens'    => array(
					'accent'      => $tokens['accent'],
					'onAccent'    => $tokens['on_accent'],
					'base'        => $tokens['base'],
					'radius'      => $tokens['radius'],
					'headingFont' => $tokens['heading_font'],
					'json'        => 'theme.json' === $tokens['source'],
				),
				'radii'     => JATCS_Style::radii(),
				'densities' => JATCS_Style::densities(),
				'origin'    => untrailingslashit( home_url() ),
				'copied'    => __( 'Copied', 'jat-calendar-sync' ),
			)
		);
	}

	/**
	 * Warn everywhere in wp-admin if Google access broke.
	 */
	public static function global_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$cancelled = isset( $_GET['jatcs_cancelled'] ) ? sanitize_key( $_GET['jatcs_cancelled'] ) : '';
		if ( 'ok' === $cancelled ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Appointment cancelled and removed from your calendar.', 'jat-calendar-sync' ) . '</p></div>';
		} elseif ( 'error' === $cancelled ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The appointment could not be removed from your calendar, so it was not cancelled. Check the calendar connection and try again.', 'jat-calendar-sync' ) . '</p></div>';
		}

		if ( get_option( JATCS_Microsoft::ERROR_OPTION ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Appointment booking is offline.', 'jat-calendar-sync' ),
				esc_html__( 'Outlook calendar access expired, and availability can’t be checked without it.', 'jat-calendar-sync' ),
				esc_url( self::page_url() . '#jatcs-outlook' ),
				esc_html__( 'Reconnect Outlook', 'jat-calendar-sync' )
			);
		}
		$error = get_option( JATCS_Google::ERROR_OPTION );
		if ( ! $error ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Appointment booking is offline.', 'jat-calendar-sync' ),
			esc_html__( 'Google Calendar access was revoked or expired, so visitors cannot book.', 'jat-calendar-sync' ),
			esc_url( self::page_url() ),
			esc_html__( 'Reconnect Google Calendar', 'jat-calendar-sync' )
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function privacy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'JAT Calendar Sync', 'jat-calendar-sync' ),
			wp_kses_post( wpautop( __( 'When you book an appointment, we store the name, email address and notes you enter, together with the time you chose. This information is added to our calendar as an event (our calendar provider, such as Google, Microsoft or Apple, stores it for us) and is used to confirm, run and if needed cancel your appointment. You receive a confirmation email with a link to cancel.', 'jat-calendar-sync' ) ) )
		);
	}

	/**
	 * Redirect back to settings with a notice.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 */
	private static function back( $type, $message ) {
		set_transient(
			'jatcs_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Abort unless the current user may manage the plugin and the nonce matches.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'jat-calendar-sync' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Save settings.
	 */
	public static function save() {
		self::guard( 'jatcs_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$in  = isset( $_POST['jatcs'] ) && is_array( $_POST['jatcs'] ) ? wp_unslash( $_POST['jatcs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$cur = JATCS_Settings::all();
		$new = $cur;

		$int = static function ( $key, $min, $max ) use ( $in, $cur ) {
			if ( ! isset( $in[ $key ] ) ) {
				return $cur[ $key ];
			}
			return max( $min, min( $max, absint( $in[ $key ] ) ) );
		};

		if ( isset( $in['client_id'] ) ) {
			$new['client_id'] = sanitize_text_field( $in['client_id'] );
		}
		if ( ! empty( $in['client_secret'] ) ) {
			$new['client_secret'] = JATCS_Crypto::encrypt( sanitize_text_field( $in['client_secret'] ) );
		}
		$credentials_changed = $new['client_id'] !== $cur['client_id'] || ! empty( $in['client_secret'] );

		if ( isset( $in['booking_calendar'] ) ) {
			$new['booking_calendar'] = JATCS_Calendars::sanitize_ref( $in['booking_calendar'] );
		}
		if ( isset( $in['calendars_present'] ) ) {
			$new['conflict_calendars'] = isset( $in['conflict_calendars'] ) && is_array( $in['conflict_calendars'] )
				? array_values( array_map( 'sanitize_text_field', $in['conflict_calendars'] ) )
				: array();
		}
		if ( isset( $in['caldav_present'] ) ) {
			$new['caldav_calendars'] = isset( $in['caldav_calendars'] ) && is_array( $in['caldav_calendars'] )
				? array_values( array_filter( array_map( 'esc_url_raw', $in['caldav_calendars'] ) ) )
				: array();
		}
		if ( isset( $in['feeds_present'] ) ) {
			$blocking = isset( $in['feeds_block'] ) && is_array( $in['feeds_block'] ) ? array_map( 'sanitize_key', $in['feeds_block'] ) : array();
			foreach ( array_keys( JATCS_Feeds::all() ) as $feed_id ) {
				JATCS_Feeds::update( $feed_id, array( 'block' => in_array( $feed_id, $blocking, true ) ) );
			}
		}
		if ( isset( $in['routing_present'] ) ) {
			$new['schedule_calendar'] = isset( $in['schedule_calendar'] ) ? JATCS_Calendars::sanitize_ref( $in['schedule_calendar'] ) : '';
			$new['schedule_week']     = empty( $in['schedule_week'] ) ? 0 : 1;
			$new['schedule_buffer']   = isset( $in['schedule_buffer'] ) ? max( 0, min( 480, (int) $in['schedule_buffer'] ) ) : (int) $cur['schedule_buffer'];
		}

		$invalid_hours = array();
		foreach ( JATCS_Settings::weekdays() as $key ) {
			if ( ! isset( $in['hours'][ $key ] ) ) {
				continue;
			}
			$raw                  = sanitize_text_field( $in['hours'][ $key ] );
			$new['hours'][ $key ] = JATCS_Settings::normalize_hours( $raw );
			if ( '' !== trim( $raw ) && '' === $new['hours'][ $key ] ) {
				$invalid_hours[] = $key;
			}
		}

		$new['duration']        = $int( 'duration', 5, 480 );
		$new['interval']        = $int( 'interval', 5, 480 );
		$new['buffer_before']   = $int( 'buffer_before', 0, 240 );
		$new['buffer_after']    = $int( 'buffer_after', 0, 240 );
		$new['min_notice']      = $int( 'min_notice', 0, 720 );
		$new['max_days']        = $int( 'max_days', 1, 365 );
		$new['daily_limit']     = $int( 'daily_limit', 0, 100 );
		$new['max_per_email']   = $int( 'max_per_email', 0, 50 );
		$new['change_cutoff']   = $int( 'change_cutoff', 0, 720 );
		$new['max_reschedules'] = $int( 'max_reschedules', 0, 20 );
		$new['reminder_hours']  = $int( 'reminder_hours', 0, 168 );
		$new['retention_days']  = $int( 'retention_days', 0, 3650 );

		$invalid_days = array();
		if ( isset( $in['days_off'] ) ) {
			$new['days_off'] = sanitize_textarea_field( $in['days_off'] );
			$parsed          = JATCS_Settings::parse_days_off( $new['days_off'] );
			$invalid_days    = $parsed['invalid'];
		}
		if ( isset( $in['phone_field'] ) ) {
			$new['phone_field'] = in_array( $in['phone_field'], array( 'off', 'optional', 'required' ), true ) ? $in['phone_field'] : 'off';
		}

		foreach ( array( 'event_title', 'location' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$new[ $key ] = sanitize_text_field( $in[ $key ] );
			}
		}
		foreach ( array( 'confirmation_message', 'consent_text' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$new[ $key ] = sanitize_textarea_field( $in[ $key ] );
			}
		}
		if ( isset( $in['notify_email'] ) ) {
			$email               = sanitize_email( $in['notify_email'] );
			$new['notify_email'] = is_email( $email ) ? $email : get_option( 'admin_email' );
		}
		if ( isset( $in['accent'] ) ) {
			$color         = sanitize_hex_color( $in['accent'] );
			$new['accent'] = $color ? $color : $cur['accent'];
		}
		foreach ( array_keys( JATCS_Style::choices() ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$new[ $key ] = JATCS_Style::clean( $key, $in[ $key ] );
			}
		}
		if ( isset( $in['style_bg_color'] ) ) {
			$color                 = sanitize_hex_color( $in['style_bg_color'] );
			$new['style_bg_color'] = $color ? $color : $cur['style_bg_color'];
		}
		if ( ! empty( $in['stripe_clear'] ) ) {
			$new['stripe_secret']         = '';
			$new['stripe_webhook_secret'] = '';
		} else {
			foreach ( array( 'stripe_secret', 'stripe_webhook_secret' ) as $key ) {
				if ( ! empty( $in[ $key ] ) ) {
					$new[ $key ] = JATCS_Crypto::encrypt( trim( sanitize_text_field( $in[ $key ] ) ) );
				}
			}
		}
		if ( isset( $in['currency'] ) && preg_match( '/^[A-Za-z]{3}$/', trim( $in['currency'] ) ) ) {
			$new['currency'] = strtolower( trim( $in['currency'] ) );
		}
		$new['refund_cutoff'] = $int( 'refund_cutoff', 0, 720 );
		if ( isset( $in['ms_client_id'] ) ) {
			$new['ms_client_id'] = sanitize_text_field( $in['ms_client_id'] );
		}
		if ( ! empty( $in['ms_client_secret'] ) ) {
			$new['ms_client_secret'] = JATCS_Crypto::encrypt( sanitize_text_field( $in['ms_client_secret'] ) );
		}
		if ( isset( $in['ms_calendars_present'] ) ) {
			$new['ms_calendars'] = isset( $in['ms_calendars'] ) && is_array( $in['ms_calendars'] ) ? array_values( array_map( 'sanitize_text_field', $in['ms_calendars'] ) ) : array();
		}
		if ( isset( $in['custom_css'] ) && current_user_can( 'unfiltered_html' ) ) {
			$new['custom_css'] = trim( str_ireplace( '</style', '', wp_strip_all_tags( $in['custom_css'] ) ) );
		}
		foreach ( array( 'add_meet', 'invite_guest', 'delete_data' ) as $key ) {
			$new[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
		}
		// phpcs:enable

		JATCS_Settings::save( $new );
		JATCS_Availability::bust_cache();

		if ( $credentials_changed && JATCS_Google::is_connected() ) {
			JATCS_Google::disconnect();
			self::back( 'success', __( 'Settings saved. Your Google credentials changed, so connect Google Calendar again.', 'jat-calendar-sync' ) );
		}
		if ( $invalid_days ) {
			/* translators: %s: lines that could not be read */
			self::back( 'error', sprintf( __( 'Settings saved, but these days-off lines could not be read and are ignored: %s. Use YYYY-MM-DD or YYYY-MM-DD to YYYY-MM-DD.', 'jat-calendar-sync' ), implode( '; ', $invalid_days ) ) );
		}
		if ( $invalid_hours ) {
			self::back( 'error', __( 'Settings saved, but some hours could not be read and were cleared. Use 24-hour ranges like 09:00-12:00, 13:00-17:00.', 'jat-calendar-sync' ) );
		}
		self::back( 'success', __( 'Settings saved.', 'jat-calendar-sync' ) );
	}

	/**
	 * Start the OAuth flow.
	 */
	public static function connect() {
		self::guard( 'jatcs_connect' );
		if ( ! JATCS_Google::has_credentials() ) {
			self::back( 'error', __( 'Add your Google client ID and secret first.', 'jat-calendar-sync' ) );
		}
		$state = wp_generate_password( 32, false );
		set_transient( 'jatcs_oauth_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		wp_redirect( JATCS_Google::auth_url( $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external Google URL.
		exit;
	}

	/**
	 * OAuth redirect target.
	 */
	public static function oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'jat-calendar-sync' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth uses the state parameter instead of a nonce.
		$key      = 'jatcs_oauth_state_' . get_current_user_id();
		$expected = (string) get_transient( $key );
		delete_transient( $key );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::back( 'error', __( 'The connection request expired or did not match. Click Connect Google Calendar again.', 'jat-calendar-sync' ) );
		}
		if ( isset( $_GET['error'] ) ) {
			self::back( 'error', __( 'Google Calendar was not connected because access was not granted.', 'jat-calendar-sync' ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable
		if ( '' === $code ) {
			self::back( 'error', __( 'Google did not send an authorization code. Try connecting again.', 'jat-calendar-sync' ) );
		}
		$result = JATCS_Google::exchange_code( $code );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error from Google */
			self::back( 'error', sprintf( __( 'Google Calendar could not be connected: %s', 'jat-calendar-sync' ), $result->get_error_message() ) );
		}
		JATCS_Availability::bust_cache();
		self::back( 'success', __( 'Google Calendar connected. Choose your calendars below, then save.', 'jat-calendar-sync' ) );
	}

	/**
	 * Disconnect Google.
	 */
	public static function disconnect() {
		self::guard( 'jatcs_disconnect' );
		JATCS_Google::disconnect();
		$s                       = JATCS_Settings::all();
		$s['conflict_calendars'] = array();
		JATCS_Settings::save( $s );
		JATCS_Availability::bust_cache();
		$message = 'google' === JATCS_Calendars::booking_service()
			? __( 'Google Calendar disconnected. Bookings were going to Google, so visitors can’t book until you connect it again or choose another booking calendar under “Where bookings go”.', 'jat-calendar-sync' )
			: __( 'Google Calendar disconnected. Its calendars no longer block times.', 'jat-calendar-sync' );
		self::back( 'success', $message );
	}

	/**
	 * Cancel a booking from the list.
	 */
	public static function cancel_booking() {
		$id = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		self::guard( 'jatcs_cancel_booking_' . $id );
		$result                  = JATCS_Bookings::cancel( $id, 'admin' );
		$args                    = array( 'post_type' => JATCS_Bookings::POST_TYPE );
		$args['jatcs_cancelled'] = is_wp_error( $result ) ? 'error' : 'ok';
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * "Export CSV" button above the bookings list.
	 *
	 * @param string $which top|bottom.
	 */
	public static function export_button( $which ) {
		$screen = get_current_screen();
		if ( 'top' !== $which || ! $screen || JATCS_Bookings::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=jatcs_export' ), 'jatcs_export' );
		echo '<div class="alignleft actions"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export CSV', 'jat-calendar-sync' ) . '</a></div>';
	}

	/**
	 * Download all bookings as CSV.
	 */
	public static function export() {
		self::guard( 'jatcs_export' );
		$ids = get_posts(
			array(
				'post_type'      => JATCS_Bookings::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bookings-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		// Spreadsheet apps would run a cell starting with = + - @ as a formula; neutralize those.
		$cell = static function ( $value ) {
			$value = (string) $value;
			return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
		};
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		fputcsv( $out, array( 'Start', 'End', 'Timezone', 'Type', 'Name', 'Email', 'Phone', 'Answers', 'Notes', 'Status', 'Amount', 'Currency', 'Payment', 'Booked', 'Google Calendar event' ), ',', '"', '' );
		foreach ( $ids as $id ) {
			$b = JATCS_Bookings::get( $id );
			if ( ! $b ) {
				continue;
			}
			fputcsv(
				$out,
				array_map(
					$cell,
					array(
						wp_date( 'Y-m-d H:i', $b['start'] ),
						wp_date( 'Y-m-d H:i', $b['end'] ),
						wp_timezone_string(),
						$b['type_name'],
						$b['name'],
						$b['email'],
						$b['phone'],
						implode( "\n", JATCS_Bookings::answer_lines( $b['answers'] ) ),
						$b['notes'],
						$b['status'],
						$b['amount'] ? JATCS_Stripe::from_minor( $b['amount'], $b['currency'] ) : '',
						$b['amount'] ? strtoupper( $b['currency'] ) : '',
						$b['amount'] ? ( $b['refunded'] ? 'refunded' : ( $b['paid_at'] ? 'paid' : 'unpaid' ) ) : '',
						get_post_time( 'Y-m-d H:i', false, $id ),
						$b['event_link'],
					)
				),
				',',
				'"',
				''
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Start Microsoft sign-in.
	 */
	public static function ms_connect() {
		self::guard( 'jatcs_ms_connect' );
		if ( ! JATCS_Microsoft::has_credentials() ) {
			self::back( 'error', __( 'Add your Microsoft application (client) ID and client secret first.', 'jat-calendar-sync' ) );
		}
		$state = wp_generate_password( 32, false );
		set_transient( 'jatcs_ms_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		$write = ! empty( $_POST['write'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		wp_redirect( JATCS_Microsoft::auth_url( $state, $write ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Microsoft sign-in.
		exit;
	}

	/**
	 * Microsoft sign-in redirect target.
	 */
	public static function ms_oauth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'jat-calendar-sync' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth state parameter instead of a nonce.
		$key      = 'jatcs_ms_state_' . get_current_user_id();
		$expected = (string) get_transient( $key );
		delete_transient( $key );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::back( 'error', __( 'The Outlook connection request expired or did not match. Click Connect Outlook again.', 'jat-calendar-sync' ) );
		}
		if ( isset( $_GET['error'] ) ) {
			$desc = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
			/* translators: %s: error from Microsoft */
			self::back( 'error', sprintf( __( 'Outlook was not connected: %s', 'jat-calendar-sync' ), '' !== $desc ? strtok( $desc, "\r\n" ) : __( 'access was not granted.', 'jat-calendar-sync' ) ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable
		$result = '' !== $code ? JATCS_Microsoft::exchange_code( $code ) : new WP_Error( 'jatcs_ms_no_code', __( 'Microsoft did not send an authorization code.', 'jat-calendar-sync' ) );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error */
			self::back( 'error', sprintf( __( 'Outlook could not be connected: %s', 'jat-calendar-sync' ), $result->get_error_message() ) );
		}
		JATCS_Availability::bust_cache();
		self::back( 'success', __( 'Outlook connected. Choose which of its calendars should block times, then save.', 'jat-calendar-sync' ) );
	}

	/**
	 * Disconnect Microsoft.
	 */
	public static function ms_disconnect() {
		self::guard( 'jatcs_ms_disconnect' );
		JATCS_Microsoft::disconnect();
		$s                 = JATCS_Settings::all();
		$s['ms_calendars'] = array();
		JATCS_Settings::save( $s );
		JATCS_Availability::bust_cache();
		self::back( 'success', __( 'Outlook disconnected. Its calendars no longer block times.', 'jat-calendar-sync' ) );
	}

	/**
	 * Connect a CalDAV account (iCloud, Fastmail, Nextcloud, …).
	 */
	public static function caldav_connect() {
		self::guard( 'jatcs_caldav_connect' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$in = isset( $_POST['jatcs_caldav'] ) && is_array( $_POST['jatcs_caldav'] ) ? wp_unslash( $_POST['jatcs_caldav'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		// phpcs:enable
		$preset   = isset( $in['preset'] ) ? sanitize_key( $in['preset'] ) : 'other';
		$url      = isset( $in['url'] ) ? esc_url_raw( trim( (string) $in['url'] ) ) : '';
		$username = isset( $in['username'] ) ? sanitize_text_field( $in['username'] ) : '';
		// Passwords may contain any character; only surrounding whitespace is dropped.
		$password = isset( $in['password'] ) ? trim( (string) $in['password'] ) : '';
		if ( '' === $username || '' === $password ) {
			self::back( 'error', __( 'Enter the user name and the app-specific password for your calendar account.', 'jat-calendar-sync' ) );
		}
		$result = JATCS_CalDAV_Store::connect( $preset, $url, $username, $password );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error */
			self::back( 'error', sprintf( __( 'The calendar account could not be connected: %s', 'jat-calendar-sync' ), $result->get_error_message() ) );
		}
		/* translators: 1: service name, 2: number of calendars */
		self::back( 'success', sprintf( _n( '%1$s connected with %2$d calendar. Choose whether it blocks times or receives bookings, then save.', '%1$s connected with %2$d calendars. Choose which block times or receive bookings, then save.', count( $result ), 'jat-calendar-sync' ), JATCS_CalDAV_Store::service_name(), count( $result ) ) );
	}

	/**
	 * Disconnect the CalDAV account.
	 */
	public static function caldav_disconnect() {
		self::guard( 'jatcs_caldav_disconnect' );
		JATCS_CalDAV_Store::disconnect();
		$s                     = JATCS_Settings::all();
		$s['caldav_calendars'] = array();
		JATCS_Settings::save( $s );
		self::back( 'success', __( 'Calendar account disconnected. Its calendars no longer block times.', 'jat-calendar-sync' ) );
	}

	/**
	 * Subscribe to an iCal feed.
	 */
	public static function feed_add() {
		self::guard( 'jatcs_feed_add' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$in = isset( $_POST['jatcs_feed'] ) && is_array( $_POST['jatcs_feed'] ) ? wp_unslash( $_POST['jatcs_feed'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in JATCS_Feeds::add().
		// phpcs:enable
		$result = JATCS_Feeds::add(
			isset( $in['name'] ) ? sanitize_text_field( $in['name'] ) : '',
			isset( $in['url'] ) ? trim( (string) $in['url'] ) : '',
			true
		);
		if ( is_wp_error( $result ) ) {
			self::back( 'error', $result->get_error_message() );
		}
		/* translators: %s: feed name */
		self::back( 'success', sprintf( __( 'Calendar feed “%s” added. Its events now block times.', 'jat-calendar-sync' ), JATCS_Feeds::name( $result ) ) );
	}

	/**
	 * Remove an iCal feed.
	 */
	public static function feed_remove() {
		self::guard( 'jatcs_feed_remove' );
		$id = isset( $_POST['feed'] ) ? sanitize_key( wp_unslash( $_POST['feed'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$s  = JATCS_Settings::all();
		if ( 'ics:' . $id === (string) $s['schedule_calendar'] ) {
			$s['schedule_calendar'] = '';
			JATCS_Settings::save( $s );
		}
		JATCS_Feeds::remove( $id );
		self::back( 'success', __( 'Calendar feed removed.', 'jat-calendar-sync' ) );
	}

	/**
	 * Clear the problem log.
	 */
	public static function clear_log() {
		self::guard( 'jatcs_clear_log' );
		delete_option( JATCS_Google::LOG_OPTION );
		self::back( 'success', __( 'Problem log cleared.', 'jat-calendar-sync' ) );
	}

	/**
	 * Run the hourly tasks now.
	 */
	public static function run_now() {
		self::guard( 'jatcs_run_now' );
		$stats = JATCS_Maintenance::run();
		self::back(
			'success',
			sprintf(
				/* translators: 1: bookings checked, 2: cancelled, 3: moved, 4: reminders sent */
				__( 'Checked %1$d upcoming bookings against your calendar: %2$d cancelled there, %3$d moved. %4$d reminders sent.', 'jat-calendar-sync' ),
				$stats['synced'],
				$stats['cancelled'],
				$stats['moved'],
				$stats['reminded']
			)
		);
	}

	/**
	 * Bookings list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'           => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'title'        => __( 'Booking', 'jat-calendar-sync' ),
			'jatcs_when'   => __( 'When', 'jat-calendar-sync' ),
			'jatcs_type'   => __( 'Type', 'jat-calendar-sync' ),
			'jatcs_paid'   => __( 'Payment', 'jat-calendar-sync' ),
			'jatcs_email'  => __( 'Email', 'jat-calendar-sync' ),
			'jatcs_status' => __( 'Status', 'jat-calendar-sync' ),
			'jatcs_links'  => __( 'Links', 'jat-calendar-sync' ),
		);
	}

	/**
	 * Column output.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public static function column( $column, $post_id ) {
		$b = JATCS_Bookings::get( $post_id );
		if ( ! $b ) {
			return;
		}
		switch ( $column ) {
			case 'jatcs_type':
				echo esc_html( $b['type_name'] );
				break;
			case 'jatcs_paid':
				if ( ! $b['amount'] ) {
					echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Free', 'jat-calendar-sync' ) . '</span>';
				} elseif ( $b['refunded'] ) {
					/* translators: %s: amount */
					echo esc_html( sprintf( __( '%s refunded', 'jat-calendar-sync' ), JATCS_Stripe::format( $b['amount'], $b['currency'] ) ) );
				} elseif ( $b['refund_error'] ) {
					echo '<span class="jatcs-status--error">' . esc_html__( 'Refund failed: refund in Stripe', 'jat-calendar-sync' ) . '</span>';
				} elseif ( $b['paid_at'] ) {
					/* translators: %s: amount */
					echo esc_html( sprintf( __( '%s paid', 'jat-calendar-sync' ), JATCS_Stripe::format( $b['amount'], $b['currency'] ) ) );
				} else {
					echo esc_html__( 'Not paid', 'jat-calendar-sync' );
				}
				break;
			case 'jatcs_when':
				echo esc_html( JATCS_Bookings::format_time( $b['start'] ) );
				break;
			case 'jatcs_email':
				echo '<a href="mailto:' . esc_attr( $b['email'] ) . '">' . esc_html( $b['email'] ) . '</a>';
				break;
			case 'jatcs_status':
				$labels = array(
					'confirmed'        => __( 'Confirmed', 'jat-calendar-sync' ),
					'cancelled'        => __( 'Cancelled', 'jat-calendar-sync' ),
					'pending'          => __( 'Not confirmed', 'jat-calendar-sync' ),
					'awaiting_payment' => $b['paid_at'] ? __( 'Paid, adding to calendar', 'jat-calendar-sync' ) : __( 'Awaiting payment', 'jat-calendar-sync' ),
					'expired'          => __( 'Expired (not paid)', 'jat-calendar-sync' ),
				);
				if ( 'cancelled' === $b['status'] && 'calendar' === get_post_meta( $post_id, '_jatcs_cancelled_by', true ) ) {
					$labels['cancelled'] = __( 'Deleted in your calendar', 'jat-calendar-sync' );
				}
				$status = isset( $labels[ $b['status'] ] ) ? $b['status'] : 'pending';
				if ( 'confirmed' === $status && $b['end'] < time() ) {
					echo esc_html__( 'Past', 'jat-calendar-sync' );
				} else {
					echo '<span class="jatcs-status jatcs-status--' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
				}
				break;
			case 'jatcs_links':
				$links = array();
				if ( $b['event_link'] && 'cancelled' !== $b['status'] ) {
					$links[] = '<a href="' . esc_url( $b['event_link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Calendar event', 'jat-calendar-sync' ) . '</a>';
				}
				if ( $b['meet_link'] && 'cancelled' !== $b['status'] ) {
					$links[] = '<a href="' . esc_url( $b['meet_link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Video call', 'jat-calendar-sync' ) . '</a>';
				}
				echo wp_kses_post( implode( ' | ', $links ) );
				break;
		}
	}

	/**
	 * Replace row actions with View and Cancel.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( JATCS_Bookings::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'], $actions['view'] );
		$b = JATCS_Bookings::get( $post );
		if ( $b && 'confirmed' === $b['status'] && $b['end'] > time() ) {
			$url                     = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'jatcs_cancel_booking',
						'booking' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'jatcs_cancel_booking_' . $post->ID
			);
			$actions['jatcs_cancel'] = sprintf(
				'<a href="%1$s" class="jatcs-cancel-link" onclick="return confirm(%2$s);">%3$s</a>',
				esc_url( $url ),
				esc_attr( wp_json_encode( __( 'Cancel this appointment? It will be removed from your calendar and the visitor will be emailed.', 'jat-calendar-sync' ) ) ),
				esc_html__( 'Cancel appointment', 'jat-calendar-sync' )
			);
		}
		return $actions;
	}

	/**
	 * Read-only details box on the booking edit screen.
	 */
	public static function meta_box() {
		add_meta_box(
			'jatcs-details',
			__( 'Appointment details', 'jat-calendar-sync' ),
			static function ( $post ) {
				$b = JATCS_Bookings::get( $post );
				if ( ! $b ) {
					return;
				}
				echo '<table class="form-table"><tbody>';
				$rows = array(
					__( 'When', 'jat-calendar-sync' )    => JATCS_Bookings::format_time( $b['start'] ) . ' – ' . wp_date( get_option( 'time_format' ), $b['end'] ),
					__( 'Name', 'jat-calendar-sync' )    => $b['name'],
					__( 'Email', 'jat-calendar-sync' )   => $b['email'],
					__( 'Phone', 'jat-calendar-sync' )   => $b['phone'],
					__( 'Status', 'jat-calendar-sync' )  => $b['status'],
					__( 'Type', 'jat-calendar-sync' )    => $b['type_name'],
					__( 'Answers', 'jat-calendar-sync' ) => implode( "\n", JATCS_Bookings::answer_lines( $b['answers'] ) ),
					__( 'Notes', 'jat-calendar-sync' )   => $b['notes'],
				);
				foreach ( $rows as $label => $value ) {
					echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			},
			JATCS_Bookings::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = JATCS_Settings::all();
		$connected = JATCS_Google::is_connected();
		$account   = JATCS_Google::account();
		$notice    = get_transient( 'jatcs_notice_' . get_current_user_id() );
		delete_transient( 'jatcs_notice_' . get_current_user_id() );
		$calendars     = $connected ? JATCS_Google::calendars( isset( $_GET['refresh'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache refresh.
		$day_names     = array(
			'mon' => __( 'Monday', 'jat-calendar-sync' ),
			'tue' => __( 'Tuesday', 'jat-calendar-sync' ),
			'wed' => __( 'Wednesday', 'jat-calendar-sync' ),
			'thu' => __( 'Thursday', 'jat-calendar-sync' ),
			'fri' => __( 'Friday', 'jat-calendar-sync' ),
			'sat' => __( 'Saturday', 'jat-calendar-sync' ),
			'sun' => __( 'Sunday', 'jat-calendar-sync' ),
		);
		$id_locked     = defined( 'JATCS_GOOGLE_CLIENT_ID' );
		$secret_locked = defined( 'JATCS_GOOGLE_CLIENT_SECRET' );
		include JATCS_DIR . 'includes/views/settings.php';
	}
}
