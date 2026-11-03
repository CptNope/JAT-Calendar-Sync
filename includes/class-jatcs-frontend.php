<?php
/**
 * Front end: shortcode, block and the visitor cancel page.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public-facing output.
 */
final class JATCS_Frontend {

	/**
	 * Counter for unique widget IDs.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'jat_booking', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'template_redirect', array( __CLASS__, 'manage_page' ) );
		add_action( 'template_redirect', array( __CLASS__, 'preview_page' ) );
	}

	/**
	 * Style keys that a block or shortcode may override, mapped to their setting.
	 *
	 * @return array<string,string> attribute => setting key
	 */
	public static function style_attributes() {
		return array(
			'buttons'  => 'style_buttons',
			'radius'   => 'style_radius',
			'density'  => 'style_density',
			'headings' => 'style_headings',
		);
	}

	/**
	 * Register scripts and styles (enqueued only where the widget appears).
	 */
	public static function register_assets() {
		wp_register_style( 'jatcs-booking', JATCS_URL . 'assets/css/booking.css', array(), JATCS_VERSION );
		wp_register_script( 'jatcs-booking', JATCS_URL . 'assets/js/booking.js', array(), JATCS_VERSION, true );

		$css = JATCS_Style::custom_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'jatcs-booking', $css );
		}
	}

	/**
	 * Register the block.
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$block = register_block_type(
			JATCS_DIR . 'blocks/booking',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
		// The block's name before the rename: still renders, hidden from the inserter.
		if ( $block && ! WP_Block_Type_Registry::get_instance()->is_registered( 'anderson-appointment-booking/booking' ) ) {
			register_block_type(
				'anderson-appointment-booking/booking',
				array(
					'attributes'      => $block->attributes,
					'render_callback' => array( __CLASS__, 'render_block' ),
					'supports'        => array( 'inserter' => false ),
				)
			);
		}
		if ( $block && ! empty( $block->editor_script_handles ) ) {
			add_action(
				'enqueue_block_editor_assets',
				static function () use ( $block ) {
					wp_add_inline_script(
						$block->editor_script_handles[0],
						'window.jatcsEditor = ' . wp_json_encode( self::editor_data() ) . ';',
						'before'
					);
				}
			);
		}
	}

	/**
	 * Data the block editor needs to draw a live, styled preview.
	 *
	 * @return array
	 */
	public static function editor_data() {
		$resolved = JATCS_Style::resolve();
		$tokens   = JATCS_Style::theme_tokens();
		return array(
			'config'  => self::config( array( 'heading' => '' ), true ),
			'types'   => array_map(
				static function ( $t ) {
					return array(
						'id'   => $t['id'],
						'name' => $t['name'],
					);
				},
				JATCS_Types::all()
			),
			'vars'    => $resolved['vars'],
			'classes' => $resolved['classes'],
			'radii'   => JATCS_Style::radii(),
			'spaces'  => JATCS_Style::densities(),
			'theme'   => array(
				'radius'      => $tokens['radius'],
				'headingFont' => $tokens['heading_font'],
			),
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		$atts = array(
			'heading' => isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '',
			'type'    => isset( $attributes['type'] ) ? (string) $attributes['type'] : '',
		);
		foreach ( array_keys( self::style_attributes() ) as $key ) {
			$atts[ $key ] = isset( $attributes[ $key ] ) ? (string) $attributes[ $key ] : '';
		}
		$atts['accent']     = isset( $attributes['accent'] ) ? (string) $attributes['accent'] : '';
		$atts['background'] = isset( $attributes['surface'] ) ? (string) $attributes['surface'] : '';

		$html = self::render( $atts );
		return function_exists( 'get_block_wrapper_attributes' )
			? '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>'
			: $html;
	}

	/**
	 * Shortcode callback.
	 *
	 * Style attributes are optional and override Appointments → Settings → Appearance:
	 * [jat_booking heading="Book a call" accent="#0b5fff" buttons="plugin" radius="round" density="compact" background="#ffffff" headings="inherit"]
	 * Show one appointment type only with type="its-id" (IDs are listed under Appointments → Appointment types).
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'heading'    => '',
				'type'       => '',
				'accent'     => '',
				'background' => '',
				'buttons'    => '',
				'radius'     => '',
				'density'    => '',
				'headings'   => '',
			),
			is_array( $atts ) ? $atts : array(),
			'jat_booking'
		);
		return self::render( $atts );
	}

	/**
	 * Script configuration for one widget.
	 *
	 * @param array $atts Attributes.
	 * @param bool  $demo Use sample times instead of live data (previews).
	 * @return array
	 */
	private static function config( array $atts, $demo = false ) {
		$types = JATCS_Types::public_list();
		$only  = isset( $atts['type'] ) ? sanitize_key( (string) $atts['type'] ) : '';
		if ( '' !== $only ) {
			$match = array_values(
				array_filter(
					$types,
					static function ( $t ) use ( $only ) {
						return $t['id'] === $only;
					}
				)
			);
			// An unknown or hidden type falls back to showing all types rather than an empty widget.
			$types = $match ? $match : $types;
		}
		$first = $types ? $types[0] : null;
		return array(
			'rest'      => esc_url_raw( rest_url( JATCS_Rest::NS . '/' ) ),
			'locale'    => str_replace( '_', '-', determine_locale() ),
			'weekStart' => (int) get_option( 'start_of_week', 1 ),
			'consent'   => (string) JATCS_Settings::get( 'consent_text' ),
			'phone'     => (string) JATCS_Settings::get( 'phone_field' ),
			'heading'   => isset( $atts['heading'] ) ? (string) $atts['heading'] : '',
			'types'     => $types,
			'duration'  => $first ? $first['duration'] : (int) JATCS_Settings::get( 'duration' ),
			'message'   => (string) JATCS_Settings::get( 'confirmation_message' ),
			'demo'      => (bool) $demo,
			'i18n'      => self::strings(),
		);
	}

	/**
	 * Widget markup. The script builds the calendar inside it; without JavaScript a short message shows.
	 *
	 * @param array $atts Attributes: heading, plus optional style overrides (accent, background, buttons, radius, density, headings).
	 * @param bool  $demo  Render with sample times (used by the settings preview).
	 * @param array $extra Extra script configuration (e.g. rescheduling).
	 * @return string
	 */
	public static function render( array $atts, $demo = false, array $extra = array() ) {
		++self::$instance;

		/**
		 * Whether to load the plugin's stylesheet. Return false to style the widget entirely from your theme.
		 *
		 * @param bool $load Load it.
		 */
		if ( apply_filters( 'jatcs_enqueue_styles', true ) ) {
			wp_enqueue_style( 'jatcs-booking' );
		}
		wp_enqueue_script( 'jatcs-booking' );

		$overrides = array();
		foreach ( self::style_attributes() as $attr => $setting ) {
			if ( ! empty( $atts[ $attr ] ) ) {
				$overrides[ $setting ] = $atts[ $attr ];
			}
		}
		if ( ! empty( $atts['accent'] ) ) {
			$overrides['accent'] = $atts['accent'];
		}
		if ( ! empty( $atts['background'] ) ) {
			if ( in_array( $atts['background'], array( 'none', 'theme' ), true ) ) {
				$overrides['style_background'] = $atts['background'];
			} else {
				$overrides['background_color'] = $atts['background'];
			}
		}

		$style = JATCS_Style::resolve( $overrides );

		return sprintf(
			'<div class="%1$s" id="jatcs-%2$d" style="%3$s" data-jatcs-config="%4$s"><noscript>%5$s</noscript></div>',
			esc_attr( implode( ' ', array_merge( array( 'jatcs' ), $style['classes'] ) ) ),
			self::$instance,
			esc_attr( JATCS_Style::inline( $style['vars'] ) ),
			esc_attr( wp_json_encode( array_merge( self::config( $atts, $demo ), $extra ) ) ),
			esc_html__( 'Turn on JavaScript to book an appointment.', 'jat-calendar-sync' )
		);
	}

	/**
	 * Appearance preview: the widget with sample times, rendered inside the site's own theme
	 * (its fonts, colors and buttons). Loaded in an iframe on the settings screen; admins only.
	 */
	public static function preview_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, capability-checked preview.
		if ( ! isset( $_GET['jatcs_preview'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this preview.', 'jat-calendar-sync' ), 403 );
		}
		nocache_headers();
		show_admin_bar( false );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		$widget = self::render( array( 'heading' => '' ), true );
		$bodyc  = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ? 'jatcs-preview wp-site-blocks' : 'jatcs-preview';
		status_header( 200 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php wp_head(); ?>
<style>
	html { margin-top: 0 !important; }
	body.jatcs-preview-body { margin: 0; }
	.jatcs-preview { padding: 8px 24px 32px; }
	.jatcs-preview .jatcs { margin-top: 0; }
</style>
</head>
<body <?php body_class( 'jatcs-preview-body' ); ?>>
<div class="<?php echo esc_attr( $bodyc ); ?>">
<main class="entry-content is-layout-constrained">
		<?php echo $widget; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in render(). ?>
</main>
</div>
<script>
/* Live updates from the settings screen (same origin only). */
window.addEventListener( 'message', function ( e ) {
	if ( e.origin !== window.location.origin || ! e.data || e.data.type !== 'jatcs-style' ) { return; }
	var el = document.querySelector( '.jatcs' );
	if ( ! el ) { return; }
	el.style.cssText = e.data.style;
	el.className = 'jatcs jatcs--ready ' + e.data.classes.join( ' ' );
	var submit = el.querySelector( '.jatcs__submit' );
	if ( submit ) { submit.classList.toggle( 'wp-element-button', e.data.classes.indexOf( 'jatcs--buttons-theme' ) !== -1 && e.data.classes.indexOf( 'jatcs--wp-buttons' ) !== -1 ); }
	if ( window.JATCSBooking ) { window.JATCSBooking.probe( el ); }
	if ( typeof e.data.css !== 'string' ) { return; }
	var css = document.getElementById( 'jatcs-preview-custom-css' );
	if ( ! css ) { css = document.createElement( 'style' ); css.id = 'jatcs-preview-custom-css'; document.head.appendChild( css ); }
	var inline = document.getElementById( 'jatcs-booking-inline-css' );
	if ( inline ) { inline.disabled = true; }
	css.textContent = e.data.css || '';
} );
</script>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Translatable UI strings for the script.
	 *
	 * @return array<string,string>
	 */
	private static function strings() {
		return array(
			'pickDay'       => __( 'Pick a day', 'jat-calendar-sync' ),
			'pickTime'      => __( 'Pick a time', 'jat-calendar-sync' ),
			'prevMonth'     => __( 'Previous month', 'jat-calendar-sync' ),
			'nextMonth'     => __( 'Next month', 'jat-calendar-sync' ),
			'loading'       => __( 'Loading open times…', 'jat-calendar-sync' ),
			'noDay'         => __( 'Choose a highlighted day to see open times.', 'jat-calendar-sync' ),
			'noTimes'       => __( 'No open times this month. Try the next month.', 'jat-calendar-sync' ),
			'dayFull'       => __( 'No open times left on this day.', 'jat-calendar-sync' ),
			/* translators: %d: number of open times */
			'openCount'     => __( '%d open times', 'jat-calendar-sync' ),
			'openOne'       => __( '1 open time', 'jat-calendar-sync' ),
			/* translators: %s: timezone name */
			'timezone'      => __( 'Times are shown in your timezone, %s.', 'jat-calendar-sync' ),
			/* translators: %d: appointment length in minutes */
			'minutes'       => __( '%d min', 'jat-calendar-sync' ),
			'changeTime'    => __( 'Change time', 'jat-calendar-sync' ),
			'name'          => __( 'Your name', 'jat-calendar-sync' ),
			'email'         => __( 'Email', 'jat-calendar-sync' ),
			'notes'         => __( 'Anything I should know? (optional)', 'jat-calendar-sync' ),
			'needName'      => __( 'Enter your name.', 'jat-calendar-sync' ),
			'needEmail'     => __( 'Enter a valid email address.', 'jat-calendar-sync' ),
			'needConsent'   => __( 'Check the box to continue.', 'jat-calendar-sync' ),
			'phoneLabel'    => __( 'Phone', 'jat-calendar-sync' ),
			'phoneOpt'      => __( 'Phone (optional)', 'jat-calendar-sync' ),
			'needPhone'     => __( 'Enter your phone number.', 'jat-calendar-sync' ),
			/* translators: %s: appointment start time */
			'book'          => __( 'Book %s', 'jat-calendar-sync' ),
			'booking'       => __( 'Booking…', 'jat-calendar-sync' ),
			'booked'        => __( 'Booked', 'jat-calendar-sync' ),
			'joinCall'      => __( 'Video call link', 'jat-calendar-sync' ),
			'bookAnother'   => __( 'Book another time', 'jat-calendar-sync' ),
			'error'         => __( 'Something went wrong. Check your connection and try again.', 'jat-calendar-sync' ),
			'retry'         => __( 'Try again', 'jat-calendar-sync' ),
			'website'       => __( 'Leave this field empty', 'jat-calendar-sync' ),
			'pickType'      => __( 'What would you like to book?', 'jat-calendar-sync' ),
			'allTypes'      => __( 'All appointment types', 'jat-calendar-sync' ),
			'video'         => __( 'Video call', 'jat-calendar-sync' ),
			'needAnswer'    => __( 'Answer this question.', 'jat-calendar-sync' ),
			'needChoice'    => __( 'Choose one of the options.', 'jat-calendar-sync' ),
			'optional'      => __( '(optional)', 'jat-calendar-sync' ),
			'choose'        => __( 'Choose…', 'jat-calendar-sync' ),
			'currentTime'   => __( 'Your current time', 'jat-calendar-sync' ),
			/* translators: %s: new appointment time */
			'moveTo'        => __( 'Move to %s', 'jat-calendar-sync' ),
			'moving'        => __( 'Moving…', 'jat-calendar-sync' ),
			'moved'         => __( 'Moved', 'jat-calendar-sync' ),
			'movedText'     => __( 'Your appointment has a new time. A confirmation is on its way to your inbox.', 'jat-calendar-sync' ),
			'backToBooking' => __( 'Back to your appointment', 'jat-calendar-sync' ),
			/* translators: %s: price */
			'payAndBook'    => __( 'Pay %s and book', 'jat-calendar-sync' ),
			/* translators: %s: price */
			'payNote'       => __( 'You’ll pay %s on Stripe’s secure checkout. Your time is held for 30 minutes while you pay.', 'jat-calendar-sync' ),
			'redirecting'   => __( 'Taking you to secure payment…', 'jat-calendar-sync' ),
		);
	}

	/**
	 * Visitor manage page: ?jatcs_manage=ID&jatcs_token=TOKEN (older emails used ?jatcs_cancel=, or aab_ before the rename).
	 *
	 * Shows the appointment with Reschedule and Cancel. Cancelling is a POST behind a
	 * confirmation button, so link scanners in mail apps can't cancel by visiting the link.
	 * The page loads the theme's head so it looks like the rest of the site.
	 */
	public static function manage_page() {
		// phpcs:disable WordPress.Security.NonceVerification -- the emailed token authenticates the visitor.
		// Links sent before the rename use aab_ instead of jatcs_; both are read.
		$cancel_raw = JATCS_Legacy::query_arg( 'cancel' );
		$id_raw     = null !== JATCS_Legacy::query_arg( 'manage' ) ? JATCS_Legacy::query_arg( 'manage' ) : $cancel_raw;
		$token_raw  = JATCS_Legacy::query_arg( 'token' );
		if ( null === $id_raw || null === $token_raw ) {
			return;
		}
		$id     = absint( $id_raw );
		$token  = sanitize_text_field( $token_raw );
		$do_raw = JATCS_Legacy::query_arg( 'do' );
		$view   = null !== $do_raw ? sanitize_key( $do_raw ) : ( null !== $cancel_raw ? 'cancel' : '' );
		$posted = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['jatcs_confirm_cancel'] );
		// phpcs:enable
		$booking = JATCS_Bookings::get( $id );
		$valid   = $booking && JATCS_Bookings::verify_token( $id, $token );

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$base   = add_query_arg(
			array(
				'jatcs_manage' => $id,
				'jatcs_token'  => rawurlencode( $token ),
			),
			home_url( '/' )
		);
		$themed = 'theme' === JATCS_Settings::get( 'style_buttons' ) && 'theme.json' === JATCS_Style::theme_tokens()['source'] ? ' wp-element-button' : '';
		$title  = __( 'Your appointment', 'jat-calendar-sync' );
		$body   = '';
		$wide   = false;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag; payment is re-checked with Stripe.
		$returned = null !== JATCS_Legacy::query_arg( 'paid' );
		if ( $valid && 'awaiting_payment' === $booking['status'] && ( $returned || 'abandon' === $view ) ) {
			// Ask Stripe directly; never trust the return URL alone.
			$checked = JATCS_Payments::check( $id );
			$booking = JATCS_Bookings::get( $id );
			if ( 'abandon' === $view && 'awaiting_payment' === $booking['status'] && ! $booking['paid_at'] ) {
				JATCS_Payments::release( $id, 'cancelled' );
				$booking = JATCS_Bookings::get( $id );
			}
		}
		$page = (string) get_post_meta( $id, '_jatcs_page', true );
		$page = '' !== $page ? $page : home_url( '/' );

		if ( $valid && 'awaiting_payment' === $booking['status'] ) {
			if ( $booking['paid_at'] ) {
				$title = __( 'Payment received', 'jat-calendar-sync' );
				$body  = self::summary_html( $booking ) . '<p>' . esc_html__( 'Thanks, your payment went through. We’re adding the appointment to the calendar and will email your confirmation shortly.', 'jat-calendar-sync' ) . '</p>';
			} elseif ( $returned ) {
				$title = __( 'Payment processing', 'jat-calendar-sync' );
				$body  = self::summary_html( $booking ) . '<p>' . esc_html__( 'Your payment is still being processed. You’ll get a confirmation email as soon as it clears; there’s nothing else you need to do.', 'jat-calendar-sync' ) . '</p>';
			} elseif ( $booking['hold_until'] > time() && '' !== $booking['checkout'] ) {
				$title = __( 'Waiting for payment', 'jat-calendar-sync' );
				$body  = self::summary_html( $booking )
					/* translators: %s: time the hold ends */
					. '<p>' . esc_html( sprintf( __( 'This time is held for you until %s. Complete payment to confirm it.', 'jat-calendar-sync' ), wp_date( get_option( 'time_format' ), $booking['hold_until'] ) ) ) . '</p>'
					. '<div class="jatcs-manage__actions"><a class="jatcs-cancel__button jatcs-manage__primary' . $themed . '" href="' . esc_url( $booking['checkout'] ) . '">'
					/* translators: %s: price */
					. esc_html( sprintf( __( 'Pay %s', 'jat-calendar-sync' ), JATCS_Stripe::format( $booking['amount'], $booking['currency'] ) ) ) . '</a>'
					. '<a class="jatcs-manage__secondary" href="' . esc_url( add_query_arg( 'jatcs_do', 'abandon', $base ) ) . '">' . esc_html__( 'Release this time', 'jat-calendar-sync' ) . '</a></div>';
			} else {
				$title = __( 'Reservation expired', 'jat-calendar-sync' );
				$body  = '<p>' . esc_html__( 'The time wasn’t paid for in time, so it was released. Nothing was charged.', 'jat-calendar-sync' ) . '</p><p><a class="jatcs-cancel__home" href="' . esc_url( $page ) . '">' . esc_html__( 'Pick a time again', 'jat-calendar-sync' ) . '</a></p>';
			}
		} elseif ( $valid && in_array( $booking['status'], array( 'expired', 'cancelled' ), true ) && ! $booking['paid_at'] && ( 'abandon' === $view || 'expired' === $booking['status'] ) ) {
			$title = 'abandon' === $view ? __( 'Payment cancelled', 'jat-calendar-sync' ) : __( 'Reservation expired', 'jat-calendar-sync' );
			$body  = '<p>' . esc_html__( 'Nothing was charged and the time has been released.', 'jat-calendar-sync' ) . '</p><p><a class="jatcs-cancel__home" href="' . esc_url( $page ) . '">' . esc_html__( 'Pick a time again', 'jat-calendar-sync' ) . '</a></p>';
		} elseif ( $booking && 'cancelled' === $booking['status'] && $valid ) {
			$body = '<p>' . esc_html__( 'This appointment is cancelled.', 'jat-calendar-sync' ) . '</p>';
		} elseif ( ! $valid ) {
			// Cancelled bookings drop their token, so an old link lands here too.
			$body = '<p>' . esc_html__( 'This link is no longer valid. If you cancelled, nothing else is needed. Otherwise reply to your confirmation email and we’ll sort it out.', 'jat-calendar-sync' ) . '</p>';
		} else {
			if ( $returned ) {
				$title = __( 'You’re booked', 'jat-calendar-sync' );
			}
			$can = JATCS_Bookings::can_change( $booking );
			if ( is_wp_error( $can ) ) {
				$body = self::summary_html( $booking ) . '<p>' . esc_html( $can->get_error_message() ) . '</p>';
			} elseif ( $posted ) {
				$result = JATCS_Bookings::cancel( $id, 'visitor' );
				if ( is_wp_error( $result ) ) {
					$body = '<p>' . esc_html__( 'The appointment could not be cancelled right now. Try again in a few minutes, or reply to your confirmation email.', 'jat-calendar-sync' ) . '</p>';
				} else {
					$title = __( 'Appointment cancelled', 'jat-calendar-sync' );
					/* translators: %s: date and time */
					$body = '<p>' . esc_html( sprintf( __( 'Your appointment on %s is cancelled. You’ll get an email confirming it.', 'jat-calendar-sync' ), JATCS_Bookings::format_time( $booking['start'] ) ) ) . '</p>'
						. '<p><a class="jatcs-cancel__home" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the site', 'jat-calendar-sync' ) . '</a></p>';
				}
			} elseif ( 'cancel' === $view ) {
				$title  = __( 'Cancel appointment', 'jat-calendar-sync' );
				$refund = '';
				if ( $booking['paid_at'] && ! $booking['refunded'] ) {
					$refund = JATCS_Payments::visitor_gets_refund( $booking )
						/* translators: %s: amount */
						? '<p>' . esc_html( sprintf( __( 'Your payment of %s will be refunded.', 'jat-calendar-sync' ), JATCS_Stripe::format( $booking['amount'], $booking['currency'] ) ) ) . '</p>'
						/* translators: %d: hours */
						: '<p>' . esc_html( sprintf( _n( 'Cancellations within %d hour of the appointment aren’t refunded.', 'Cancellations within %d hours of the appointment aren’t refunded.', (int) JATCS_Settings::get( 'refund_cutoff' ), 'jat-calendar-sync' ), (int) JATCS_Settings::get( 'refund_cutoff' ) ) ) . '</p>';
				}
				$body = self::summary_html( $booking ) . $refund
					. '<form method="post" action="' . esc_url( add_query_arg( 'jatcs_do', 'cancel', $base ) ) . '"><input type="hidden" name="jatcs_confirm_cancel" value="1">'
					. '<button type="submit" class="jatcs-cancel__button' . $themed . '">' . esc_html__( 'Yes, cancel it', 'jat-calendar-sync' ) . '</button></form>'
					. '<p class="jatcs-cancel__keep"><a href="' . esc_url( $base ) . '">' . esc_html__( 'Keep it', 'jat-calendar-sync' ) . '</a></p>';
			} elseif ( 'reschedule' === $view ) {
				$title = __( 'Pick a new time', 'jat-calendar-sync' );
				$wide  = true;
				$type  = JATCS_Bookings::type_of( $booking );
				$body  = self::summary_html( $booking, true )
					. self::render(
						array( 'heading' => '' ),
						false,
						array(
							'types'      => array( JATCS_Types::public_type( $type ) ),
							'duration'   => (int) $type['duration'],
							'reschedule' => array(
								'id'     => $id,
								'token'  => $token,
								'start'  => $booking['start'],
								'manage' => $base,
							),
						)
					)
					. '<p class="jatcs-cancel__keep"><a href="' . esc_url( $base ) . '">' . esc_html__( 'Keep the current time', 'jat-calendar-sync' ) . '</a></p>';
			} else {
				$limit = (int) JATCS_Settings::get( 'max_reschedules' );
				$body  = self::summary_html( $booking );
				if ( $returned ) {
					$body .= '<p>' . esc_html__( 'Payment received. A confirmation is on its way to your inbox.', 'jat-calendar-sync' ) . '</p>';
				}
				$body .= '<div class="jatcs-manage__actions">';
				if ( ! $limit || $booking['reschedules'] < $limit ) {
					$body .= '<a class="jatcs-cancel__button jatcs-manage__primary' . $themed . '" href="' . esc_url( add_query_arg( 'jatcs_do', 'reschedule', $base ) ) . '">' . esc_html__( 'Reschedule', 'jat-calendar-sync' ) . '</a>';
				}
				$body .= '<a class="jatcs-manage__secondary" href="' . esc_url( add_query_arg( 'jatcs_do', 'cancel', $base ) ) . '">' . esc_html__( 'Cancel appointment', 'jat-calendar-sync' ) . '</a></div>';
			}
		}

		$style = JATCS_Style::resolve();
		wp_enqueue_style( 'jatcs-booking' );
		show_admin_bar( false );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		status_header( 200 );
		$classes = array_merge( array( 'jatcs', 'jatcs-cancel', 'jatcs-manage' ), $wide ? array( 'jatcs-manage--wide' ) : array(), $style['classes'] );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php wp_head(); // Loads the theme's fonts, colors and button styles so the page looks like the site. ?>
<style>html { margin-top: 0 !important; }</style>
</head>
<body <?php body_class( 'jatcs-cancel-page' ); ?>>
<main class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="<?php echo esc_attr( JATCS_Style::inline( $style['vars'] ) ); ?>">
<p class="jatcs-cancel__site"><?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></p>
<h1><?php echo esc_html( $title ); ?></h1>
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic part of $body is escaped where it is built above. ?>
</main>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * The appointment summary block on the manage page.
	 *
	 * @param array $booking Booking.
	 * @param bool  $compact One line ("Currently: …").
	 * @return string
	 */
	private static function summary_html( array $booking, $compact = false ) {
		$when = JATCS_Bookings::format_time( $booking['start'] ) . ' – ' . wp_date( get_option( 'time_format' ), $booking['end'] );
		if ( $compact ) {
			/* translators: %s: current date and time */
			return '<p class="jatcs-manage__current">' . esc_html( sprintf( __( 'Currently: %s', 'jat-calendar-sync' ), $when ) ) . '</p>';
		}
		$rows = array();
		if ( '' !== $booking['type_name'] ) {
			$rows[ __( 'What', 'jat-calendar-sync' ) ] = $booking['type_name'];
		}
		$rows[ __( 'When', 'jat-calendar-sync' ) ] = $when . ' (' . wp_timezone_string() . ')';
		if ( '' !== $booking['location'] ) {
			$rows[ __( 'Where', 'jat-calendar-sync' ) ] = $booking['location'];
		}
		if ( $booking['amount'] ) {
			$price                                      = JATCS_Stripe::format( $booking['amount'], $booking['currency'] );
			$rows[ __( 'Price', 'jat-calendar-sync' ) ] = $booking['refunded']
				/* translators: %s: amount */
				? sprintf( __( '%s (refunded)', 'jat-calendar-sync' ), $price )
				: ( $booking['paid_at']
					/* translators: %s: amount */
					? sprintf( __( '%s (paid)', 'jat-calendar-sync' ), $price )
					: $price );
		}
		$html = '<dl class="jatcs-manage__details">';
		foreach ( $rows as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		if ( '' !== $booking['meet_link'] && 'confirmed' === $booking['status'] ) {
			$html .= '<dt>' . esc_html__( 'Video call', 'jat-calendar-sync' ) . '</dt><dd><a href="' . esc_url( $booking['meet_link'] ) . '" rel="noopener">' . esc_html( $booking['meet_link'] ) . '</a></dd>';
		}
		return $html . '</dl>';
	}
}
