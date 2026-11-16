<?php
/**
 * Appointments → People: add the people visitors can book separately, connect each
 * person's Google Calendar, and choose where their bookings go and which of their
 * calendars block times.
 *
 * Each person connects with their own Google account through a private link that the
 * site owner sends them (it works without a WordPress login, once, for 7 days), or the
 * owner connects it here while signed in to that person's Google account.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * People screen.
 */
final class JATCS_Admin_Hosts {

	const SLUG       = 'jatcs-people';
	const INVITE_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_jatcs_host_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_jatcs_host_delete', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_jatcs_host_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_jatcs_host_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_jatcs_host_invite_new', array( __CLASS__, 'new_invite' ) );
		// The connect link a person opens: works signed in or not.
		add_action( 'admin_post_jatcs_host_invite', array( __CLASS__, 'open_invite' ) );
		add_action( 'admin_post_nopriv_jatcs_host_invite', array( __CLASS__, 'open_invite' ) );
		// Google sends them back to the plugin's usual redirect URI; JATCS_Admin::oauth_callback()
		// hands host sign-ins to host_callback(). Signed-out visitors need the nopriv hook too.
		add_action( 'admin_post_nopriv_jatcs_oauth', array( __CLASS__, 'nopriv_callback' ) );
		add_action( 'admin_post_nopriv_aab_oauth', array( __CLASS__, 'nopriv_callback' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page(
			JATCS_Admin::SLUG,
			__( 'People', 'jat-calendar-sync' ),
			__( 'People', 'jat-calendar-sync' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			2
		);
	}

	/**
	 * Page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Styles on this screen.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::SLUG ) ) {
			wp_enqueue_style( 'jatcs-admin', JATCS_URL . 'assets/css/admin.css', array(), JATCS_VERSION );
			// Just the Copy button (the settings screen's script expects its own fields).
			wp_register_script( 'jatcs-people', false, array(), JATCS_VERSION, true );
			wp_enqueue_script( 'jatcs-people' );
			wp_add_inline_script(
				'jatcs-people',
				'document.addEventListener("click",function(e){var b=e.target.closest("[data-jatcs-copy]");if(!b){return;}var f=document.getElementById(b.getAttribute("data-jatcs-copy"));if(!f){return;}var l=b.textContent,d=function(){b.textContent=' . wp_json_encode( __( 'Copied', 'jat-calendar-sync' ) ) . ';setTimeout(function(){b.textContent=l;},1500);};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(f.value).then(d);}else{f.select();document.execCommand("copy");d();}});'
			);
		}
	}

	/**
	 * Permission and nonce.
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
	 * Redirect back with a notice.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 * @param array  $args    Query args.
	 */
	private static function back( $type, $message, array $args = array() ) {
		set_transient(
			'jatcs_people_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * The host a request names (must exist).
	 *
	 * @return string
	 */
	private static function requested_host() {
		// phpcs:ignore WordPress.Security.NonceVerification -- callers check the nonce first.
		$slug = isset( $_REQUEST['host'] ) ? sanitize_key( wp_unslash( $_REQUEST['host'] ) ) : '';
		if ( '' === $slug || ! JATCS_Hosts::get( $slug ) ) {
			self::back( 'error', __( 'That person no longer exists.', 'jat-calendar-sync' ) );
		}
		return $slug;
	}

	/**
	 * Add or update a person.
	 */
	public static function save() {
		self::guard( 'jatcs_host_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$in   = isset( $_POST['host'] ) && is_array( $_POST['host'] ) ? wp_unslash( $_POST['host'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- per field below.
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		// phpcs:enable
		$name = sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' );
		if ( '' === $name ) {
			self::back( 'error', __( 'Enter the person’s name.', 'jat-calendar-sync' ), array( 'edit' => '' !== $slug ? $slug : 'new' ) );
		}
		$is_new = '' === $slug;
		$host   = $is_new ? array() : (array) JATCS_Hosts::get( $slug );
		if ( ! $is_new && ! $host ) {
			self::back( 'error', __( 'That person no longer exists.', 'jat-calendar-sync' ) );
		}
		if ( $is_new ) {
			$wanted = isset( $in['slug'] ) ? sanitize_key( $in['slug'] ) : '';
			$slug   = '' !== $wanted && ! JATCS_Hosts::get( $wanted ) ? $wanted : JATCS_Hosts::unique_slug( $name );
		}
		$email                = sanitize_email( isset( $in['notify_email'] ) ? $in['notify_email'] : '' );
		$host['name']         = $name;
		$host['notify_email'] = is_email( $email ) ? $email : '';

		// Calendars can only be chosen once their Google account is connected.
		$connected = JATCS_Hosts::run( $slug, array( 'JATCS_Google', 'is_connected' ) );
		if ( ! $is_new && $connected ) {
			$host['booking_calendar']   = JATCS_Calendars::sanitize_ref( isset( $in['booking_calendar'] ) ? $in['booking_calendar'] : 'primary' );
			$host['booking_calendar']   = '' !== $host['booking_calendar'] && 0 !== strpos( $host['booking_calendar'], 'ms:' ) && 0 !== strpos( $host['booking_calendar'], 'caldav:' ) && 0 !== strpos( $host['booking_calendar'], 'ics:' ) ? $host['booking_calendar'] : 'primary';
			$host['conflict_calendars'] = array();
			foreach ( isset( $in['conflict_calendars'] ) ? (array) $in['conflict_calendars'] : array() as $ref ) {
				$ref = JATCS_Calendars::sanitize_ref( $ref );
				if ( '' !== $ref && false === strpos( $ref, ':' ) ) {
					$host['conflict_calendars'][] = $ref; // Google calendar IDs only.
				}
			}
		}
		JATCS_Hosts::save( $slug, $host );
		JATCS_Availability::bust_cache();
		self::back(
			'success',
			$is_new
				/* translators: %s: name */
				? sprintf( __( '%s added. Next, send them their connect link (or connect their Google Calendar yourself).', 'jat-calendar-sync' ), $name )
				/* translators: %s: name */
				: sprintf( __( '%s saved.', 'jat-calendar-sync' ), $name ),
			array( 'edit' => $slug )
		);
	}

	/**
	 * Remove a person.
	 */
	public static function delete() {
		$slug = isset( $_GET['host'] ) ? sanitize_key( wp_unslash( $_GET['host'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		self::guard( 'jatcs_host_delete_' . $slug );
		$host = JATCS_Hosts::get( $slug );
		if ( ! $host ) {
			self::back( 'error', __( 'That person no longer exists.', 'jat-calendar-sync' ) );
		}
		foreach ( JATCS_Types::all() as $t ) {
			if ( JATCS_Hosts::of_type( $t ) === $slug ) {
				/* translators: %s: name */
				self::back( 'error', sprintf( __( 'Move or delete %s’s appointment types first (Appointments → Appointment types).', 'jat-calendar-sync' ), $host['name'] ) );
			}
		}
		self::forget_invite( $slug );
		JATCS_Hosts::delete( $slug );
		JATCS_Availability::bust_cache();
		/* translators: %s: name */
		self::back( 'success', sprintf( __( '%s removed and their Google Calendar disconnected.', 'jat-calendar-sync' ), $host['name'] ) );
	}

	/**
	 * Start Google sign-in for a person (from this screen, signed in as an admin).
	 */
	public static function connect() {
		self::guard( 'jatcs_host_connect' );
		$slug = self::requested_host();
		self::to_google( $slug );
	}

	/**
	 * Disconnect a person's Google Calendar.
	 */
	public static function disconnect() {
		self::guard( 'jatcs_host_disconnect' );
		$slug = self::requested_host();
		JATCS_Hosts::run( $slug, array( 'JATCS_Google', 'disconnect' ) );
		JATCS_Availability::bust_cache();
		self::back( 'success', __( 'Google Calendar disconnected. Their booking calendar is hidden until it is connected again.', 'jat-calendar-sync' ), array( 'edit' => $slug ) );
	}

	/**
	 * Make a fresh connect link for a person (the old one stops working).
	 */
	public static function new_invite() {
		self::guard( 'jatcs_host_invite_new' );
		$slug  = self::requested_host();
		$token = wp_generate_password( 40, false );
		self::forget_invite( $slug );
		set_transient( 'jatcs_invite_' . hash( 'sha256', $token ), $slug, self::INVITE_TTL );
		update_option( 'jatcs_invite_of_' . $slug, hash( 'sha256', $token ), false );
		// Shown once, right after it's made; only its hash is stored.
		set_transient( 'jatcs_invite_link_' . get_current_user_id(), add_query_arg( array( 'action' => 'jatcs_host_invite', 't' => $token ), admin_url( 'admin-post.php' ) ), 300 );
		self::back( 'success', __( 'New connect link made. Send it to them privately; it works once, for 7 days.', 'jat-calendar-sync' ), array( 'edit' => $slug ) );
	}

	/**
	 * Forget a person's connect link.
	 *
	 * @param string $slug Host.
	 */
	private static function forget_invite( $slug ) {
		$hash = (string) get_option( 'jatcs_invite_of_' . $slug, '' );
		if ( '' !== $hash ) {
			delete_transient( 'jatcs_invite_' . $hash );
		}
		delete_option( 'jatcs_invite_of_' . $slug );
	}

	/**
	 * A person opens their connect link: on to Google.
	 */
	public static function open_invite() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential.
		$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
		$slug  = '' !== $token ? (string) get_transient( 'jatcs_invite_' . hash( 'sha256', $token ) ) : '';
		if ( '' === $slug || ! JATCS_Hosts::get( $slug ) || ! JATCS_Google::has_credentials() ) {
			self::message(
				__( 'This link has expired', 'jat-calendar-sync' ),
				__( 'This connect link has already been used or has expired. Ask for a new one.', 'jat-calendar-sync' )
			);
		}
		self::to_google( $slug, hash( 'sha256', $token ) );
	}

	/**
	 * Send the browser to Google's consent screen for a person.
	 *
	 * @param string $slug   Host.
	 * @param string $invite Hash of the connect link used ('' when an admin connects here).
	 */
	private static function to_google( $slug, $invite = '' ) {
		if ( ! JATCS_Google::has_credentials() ) {
			self::back( 'error', __( 'Add the Google client ID and secret under Appointments → Settings first. Everyone uses the same Google app.', 'jat-calendar-sync' ), array( 'edit' => $slug ) );
		}
		$state = wp_generate_password( 32, false );
		set_transient(
			'jatcs_host_state_' . hash( 'sha256', $state ),
			array(
				'host'   => $slug,
				'invite' => $invite,
				'user'   => get_current_user_id(),
			),
			15 * MINUTE_IN_SECONDS
		);
		$url = JATCS_Google::auth_url( $state );
		// Let them pick which Google account, not silently use one already signed in.
		$url = add_query_arg( 'prompt', rawurlencode( 'select_account consent' ), remove_query_arg( 'prompt', $url ) );
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google's consent screen.
		exit;
	}

	/**
	 * Google's redirect for a signed-out person (their connect link).
	 */
	public static function nopriv_callback() {
		if ( ! self::host_callback() ) {
			self::message( __( 'This link has expired', 'jat-calendar-sync' ), __( 'The sign-in request expired or did not match. Open your connect link again.', 'jat-calendar-sync' ) );
		}
	}

	/**
	 * Handle Google's redirect when it was a person's sign-in. Returns false when the
	 * state isn't a person's (then the site owner's usual flow handles it).
	 *
	 * @return bool
	 */
	public static function host_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth state instead of a nonce.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( '' === $state ) {
			return false;
		}
		$key  = 'jatcs_host_state_' . hash( 'sha256', $state );
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['host'] ) ) {
			return false;
		}
		delete_transient( $key );
		$slug   = sanitize_key( $data['host'] );
		$host   = JATCS_Hosts::get( $slug );
		$admin  = '' === (string) $data['invite'];
		$finish = static function ( $ok, $message ) use ( $admin, $slug ) {
			if ( $admin && current_user_can( 'manage_options' ) ) {
				self::back( $ok ? 'success' : 'error', $message, array( 'edit' => $slug ) );
			}
			self::message( $ok ? __( 'Calendar connected', 'jat-calendar-sync' ) : __( 'Not connected', 'jat-calendar-sync' ), $message );
		};
		if ( ! $host ) {
			$finish( false, __( 'That person no longer exists.', 'jat-calendar-sync' ) );
		}
		// A link-based sign-in must still be a live link (it may have been replaced meanwhile).
		if ( ! $admin && $slug !== (string) get_transient( 'jatcs_invite_' . $data['invite'] ) ) {
			$finish( false, __( 'This connect link has already been used or has expired. Ask for a new one.', 'jat-calendar-sync' ) );
		}
		// An admin's sign-in must come back to the same admin.
		if ( $admin && (int) $data['user'] !== get_current_user_id() ) {
			$finish( false, __( 'The connection request did not match. Try again.', 'jat-calendar-sync' ) );
		}
		if ( isset( $_GET['error'] ) ) {
			$finish( false, __( 'Google Calendar was not connected because access was not granted.', 'jat-calendar-sync' ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable
		if ( '' === $code ) {
			$finish( false, __( 'Google did not send an authorization code. Try again.', 'jat-calendar-sync' ) );
		}
		$result = JATCS_Hosts::run(
			$slug,
			static function () use ( $code ) {
				return JATCS_Google::exchange_code( $code );
			}
		);
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error from Google */
			$finish( false, sprintf( __( 'Google Calendar could not be connected: %s', 'jat-calendar-sync' ), $result->get_error_message() ) );
		}
		if ( ! $admin ) {
			self::forget_invite( $slug ); // Used once.
		}
		JATCS_Availability::bust_cache();
		$account = JATCS_Hosts::run( $slug, array( 'JATCS_Google', 'account' ) );
		$finish(
			true,
			$admin
				/* translators: %s: Google account */
				? sprintf( __( 'Connected %s. Check where bookings go and which calendars block times, then save.', 'jat-calendar-sync' ), $account['email'] )
				/* translators: 1: Google account, 2: site name */
				: sprintf( __( 'Your Google Calendar (%1$s) is connected to %2$s. Visitors will see your open times and new bookings will appear in your calendar. You can close this page.', 'jat-calendar-sync' ), $account['email'], wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )
		);
		return true;
	}

	/**
	 * A plain message page for people without an admin login.
	 *
	 * @param string $title   Title.
	 * @param string $message Message.
	 */
	private static function message( $title, $message ) {
		wp_die(
			'<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p>',
			esc_html( $title ),
			array(
				'response'  => 200,
				'back_link' => false,
			)
		);
	}

	/**
	 * The screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( 'jatcs_people_notice_' . get_current_user_id() );
		delete_transient( 'jatcs_people_notice_' . get_current_user_id() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		echo '<div class="wrap jatcs-admin">';
		if ( is_array( $notice ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $notice['type'] ? 'error' : 'success', esc_html( $notice['message'] ) );
		}
		if ( '' !== $edit ) {
			self::render_form( $edit );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	/**
	 * Status of one person, for the list and the form.
	 *
	 * @param string $slug Host.
	 * @return array{connected:bool,email:string,ready:bool,types:int,error:bool}
	 */
	private static function status( $slug ) {
		$types = 0;
		foreach ( JATCS_Types::all() as $t ) {
			if ( JATCS_Hosts::of_type( $t ) === $slug && ! empty( $t['active'] ) ) {
				++$types;
			}
		}
		return JATCS_Hosts::run(
			$slug,
			static function () use ( $slug, $types ) {
				$account = JATCS_Google::account();
				return array(
					'connected' => JATCS_Google::is_connected(),
					'email'     => $account['email'],
					'ready'     => JATCS_Hosts::ready( $slug ),
					'types'     => $types,
					'error'     => (bool) get_option( JATCS_Google::ERROR_OPTION . JATCS_Hosts::suffix() ),
				);
			}
		);
	}

	/**
	 * The list.
	 */
	private static function render_list() {
		$hosts = JATCS_Hosts::all();
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'People', 'jat-calendar-sync' ); ?></h1>
		<a href="<?php echo esc_url( self::url( array( 'edit' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add person', 'jat-calendar-sync' ); ?></a>
		<hr class="wp-header-end">
		<p class="description"><?php esc_html_e( 'Add the people visitors can book separately, each with their own Google Calendar. Give each person an appointment type, then place their calendar with [jat_booking host="their-slug"]. A person’s calendar shows nothing until their Google Calendar is connected and they have a visible appointment type.', 'jat-calendar-sync' ); ?></p>
		<?php if ( ! JATCS_Google::has_credentials() ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				printf(
					/* translators: %s: link to settings */
					esc_html__( 'Everyone signs in through the site’s Google app. Add its client ID and secret under %s first.', 'jat-calendar-sync' ),
					'<a href="' . esc_url( JATCS_Admin::page_url() ) . '">' . esc_html__( 'Appointments → Settings', 'jat-calendar-sync' ) . '</a>'
				);
				?>
			</p></div>
		<?php endif; ?>
		<?php if ( ! $hosts ) : ?>
			<p><?php esc_html_e( 'No people yet. Bookings go to the site owner’s calendar (Settings).', 'jat-calendar-sync' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Name', 'jat-calendar-sync' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Google Calendar', 'jat-calendar-sync' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Visible types', 'jat-calendar-sync' ); ?></th>
				<th scope="col"><?php esc_html_e( 'On the site', 'jat-calendar-sync' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Shortcode', 'jat-calendar-sync' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $hosts as $slug => $h ) :
				$st = self::status( $slug );
				?>
				<tr>
					<td><strong><a href="<?php echo esc_url( self::url( array( 'edit' => $slug ) ) ); ?>"><?php echo esc_html( $h['name'] ); ?></a></strong></td>
					<td>
						<?php
						if ( $st['error'] ) {
							esc_html_e( 'Access expired: reconnect', 'jat-calendar-sync' );
						} elseif ( $st['connected'] ) {
							echo esc_html( $st['email'] );
						} else {
							esc_html_e( 'Not connected', 'jat-calendar-sync' );
						}
						?>
					</td>
					<td><?php echo esc_html( number_format_i18n( $st['types'] ) ); ?></td>
					<td><?php echo $st['ready'] ? esc_html__( 'Showing', 'jat-calendar-sync' ) : esc_html__( 'Hidden until set up', 'jat-calendar-sync' ); ?></td>
					<td><code class="jatcs-code">[jat_booking host="<?php echo esc_html( $slug ); ?>"]</code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Add/edit form.
	 *
	 * @param string $edit Slug or 'new'.
	 */
	private static function render_form( $edit ) {
		$is_new = 'new' === $edit;
		$host   = $is_new ? array(
			'name'               => '',
			'notify_email'       => '',
			'booking_calendar'   => 'primary',
			'conflict_calendars' => array(),
		) : JATCS_Hosts::get( $edit );
		if ( ! $host ) {
			echo '<p>' . esc_html__( 'That person no longer exists.', 'jat-calendar-sync' ) . '</p>';
			return;
		}
		$st = $is_new ? null : self::status( $edit );
		?>
		<h1><?php echo $is_new ? esc_html__( 'Add person', 'jat-calendar-sync' ) : esc_html( $host['name'] ); ?></h1>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← <?php esc_html_e( 'All people', 'jat-calendar-sync' ); ?></a></p>

		<?php if ( ! $is_new ) : ?>
			<div class="jatcs-card">
				<h2><?php esc_html_e( 'Google Calendar', 'jat-calendar-sync' ); ?></h2>
				<?php if ( $st['connected'] ) : ?>
					<p class="jatcs-connection jatcs-connection--on">
						<?php
						/* translators: %s: Google account */
						echo esc_html( sprintf( __( 'Connected as %s', 'jat-calendar-sync' ), $st['email'] ) );
						?>
					</p>
					<?php if ( $st['error'] ) : ?>
						<div class="notice notice-error inline"><p><?php esc_html_e( 'Google stopped accepting this connection, so their calendar is hidden. Send them a new connect link.', 'jat-calendar-sync' ); ?></p></div>
					<?php endif; ?>
				<?php else : ?>
					<p class="jatcs-connection"><?php esc_html_e( 'Not connected yet. Their booking calendar stays hidden on the site until it is.', 'jat-calendar-sync' ); ?></p>
				<?php endif; ?>

				<?php $link = get_transient( 'jatcs_invite_link_' . get_current_user_id() ); ?>
				<?php if ( $link ) : ?>
					<?php delete_transient( 'jatcs_invite_link_' . get_current_user_id() ); ?>
					<p><strong><?php esc_html_e( 'Their connect link', 'jat-calendar-sync' ); ?></strong> <?php esc_html_e( '(shown once; works once, for 7 days):', 'jat-calendar-sync' ); ?></p>
					<span class="jatcs-copy">
						<input type="text" readonly value="<?php echo esc_attr( $link ); ?>" class="large-text code" id="jatcs-invite-link">
						<button type="button" class="button" data-jatcs-copy="jatcs-invite-link"><?php esc_html_e( 'Copy', 'jat-calendar-sync' ); ?></button>
					</span>
					<p class="description"><?php esc_html_e( 'They open it, pick their Google account and allow access. No WordPress login needed.', 'jat-calendar-sync' ); ?></p>
				<?php endif; ?>

				<div class="jatcs-inline-forms">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="jatcs_host_invite_new">
						<input type="hidden" name="host" value="<?php echo esc_attr( $edit ); ?>">
						<?php wp_nonce_field( 'jatcs_host_invite_new' ); ?>
						<button type="submit" class="button button-primary"><?php echo $st['connected'] ? esc_html__( 'Make a new connect link', 'jat-calendar-sync' ) : esc_html__( 'Make their connect link', 'jat-calendar-sync' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="jatcs_host_connect">
						<input type="hidden" name="host" value="<?php echo esc_attr( $edit ); ?>">
						<?php wp_nonce_field( 'jatcs_host_connect' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Connect here instead', 'jat-calendar-sync' ); ?></button>
					</form>
					<?php if ( $st['connected'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="jatcs_host_disconnect">
							<input type="hidden" name="host" value="<?php echo esc_attr( $edit ); ?>">
							<?php wp_nonce_field( 'jatcs_host_disconnect' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'jat-calendar-sync' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
				<p class="description"><?php esc_html_e( '“Connect here instead” signs in with the Google account you choose in this browser, so pick theirs.', 'jat-calendar-sync' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="jatcs_host_save">
			<input type="hidden" name="slug" value="<?php echo esc_attr( $is_new ? '' : $edit ); ?>">
			<?php wp_nonce_field( 'jatcs_host_save' ); ?>
			<div class="jatcs-card">
				<h2><?php esc_html_e( 'Details', 'jat-calendar-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="jatcs-host-name"><?php esc_html_e( 'Name', 'jat-calendar-sync' ); ?></label></th>
						<td><input type="text" id="jatcs-host-name" name="host[name]" value="<?php echo esc_attr( $host['name'] ); ?>" class="regular-text" required></td>
					</tr>
					<?php if ( $is_new ) : ?>
					<tr>
						<th scope="row"><label for="jatcs-host-slug"><?php esc_html_e( 'Slug', 'jat-calendar-sync' ); ?></label></th>
						<td><input type="text" id="jatcs-host-slug" name="host[slug]" value="" class="regular-text code" placeholder="<?php esc_attr_e( 'Optional: made from the name', 'jat-calendar-sync' ); ?>">
						<p class="description"><?php esc_html_e( 'Used in the shortcode: [jat_booking host="slug"]. Can’t be changed later.', 'jat-calendar-sync' ); ?></p></td>
					</tr>
					<?php else : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shortcode', 'jat-calendar-sync' ); ?></th>
						<td><code class="jatcs-code">[jat_booking host="<?php echo esc_html( $edit ); ?>"]</code></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="jatcs-host-email"><?php esc_html_e( 'Send their booking notices to', 'jat-calendar-sync' ); ?></label></th>
						<td><input type="email" id="jatcs-host-email" name="host[notify_email]" value="<?php echo esc_attr( $host['notify_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( JATCS_Bookings::notify_email() ); ?>">
						<p class="description"><?php esc_html_e( 'New bookings, changes and cancellations. Visitors’ replies go here too. Empty: the site’s address.', 'jat-calendar-sync' ); ?></p></td>
					</tr>
				</table>
			</div>

			<?php if ( ! $is_new && $st['connected'] ) : ?>
				<?php
				$choices = JATCS_Hosts::run(
					$edit,
					static function () {
						return JATCS_Calendars::choices();
					}
				);
				?>
				<div class="jatcs-card">
					<h2><?php esc_html_e( 'Calendars', 'jat-calendar-sync' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="jatcs-host-booking"><?php esc_html_e( 'Bookings go to', 'jat-calendar-sync' ); ?></label></th>
							<td><select id="jatcs-host-booking" name="host[booking_calendar]">
								<?php foreach ( $choices as $c ) : ?>
									<?php if ( $c['writable'] ) : ?>
										<option value="<?php echo esc_attr( $c['ref'] ); ?>" <?php selected( JATCS_Calendars::canonical( $c['ref'] ), JATCS_Calendars::canonical( $host['booking_calendar'] ) ); ?>><?php echo esc_html( $c['name'] ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'This calendar always blocks its own times.', 'jat-calendar-sync' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Also block times from', 'jat-calendar-sync' ); ?></th>
							<td><fieldset>
								<?php foreach ( $choices as $c ) : ?>
									<label style="display:block"><input type="checkbox" name="host[conflict_calendars][]" value="<?php echo esc_attr( $c['ref'] ); ?>" <?php checked( in_array( $c['ref'], $host['conflict_calendars'], true ) ); ?>> <?php echo esc_html( $c['name'] ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Anything busy on these calendars can’t be booked.', 'jat-calendar-sync' ); ?></p></td>
						</tr>
					</table>
				</div>
			<?php endif; ?>
			<?php submit_button( $is_new ? __( 'Add person', 'jat-calendar-sync' ) : __( 'Save', 'jat-calendar-sync' ) ); ?>
		</form>

		<?php if ( ! $is_new ) : ?>
			<p><a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'jatcs_host_delete', 'host' => $edit ), admin_url( 'admin-post.php' ) ), 'jatcs_host_delete_' . $edit ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Remove this person and disconnect their Google Calendar? Their past bookings are kept.', 'jat-calendar-sync' ) ) ); ?>);"><?php esc_html_e( 'Remove person', 'jat-calendar-sync' ); ?></a></p>
		<?php endif; ?>
		<?php
	}
}
