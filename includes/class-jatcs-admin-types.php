<?php
/**
 * Appointments → Appointment types: list, add, edit, reorder, delete.
 *
 * @package JATCalendarSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen for appointment types.
 */
final class JATCS_Admin_Types {

	const SLUG = 'jatcs-types';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_jatcs_save_type', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_jatcs_delete_type', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_jatcs_move_type', array( __CLASS__, 'move' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page(
			JATCS_Admin::SLUG,
			__( 'Appointment types', 'jat-calendar-sync' ),
			__( 'Appointment types', 'jat-calendar-sync' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			1
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
	 * CSS/JS on this screen.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'jatcs-admin', JATCS_URL . 'assets/css/admin.css', array(), JATCS_VERSION );
		wp_enqueue_script( 'jatcs-admin-types', JATCS_URL . 'assets/js/admin-types.js', array(), JATCS_VERSION, true );
		wp_localize_script(
			'jatcs-admin-types',
			'jatcsTypes',
			array(
				'kinds' => JATCS_Types::question_kinds(),
				'i18n'  => array(
					'question' => __( 'Question', 'jat-calendar-sync' ),
					'kind'     => __( 'Answer type', 'jat-calendar-sync' ),
					'options'  => __( 'Choices (one per line)', 'jat-calendar-sync' ),
					'required' => __( 'Required', 'jat-calendar-sync' ),
					'remove'   => __( 'Remove', 'jat-calendar-sync' ),
					'up'       => __( 'Move up', 'jat-calendar-sync' ),
					'down'     => __( 'Move down', 'jat-calendar-sync' ),
					'copied'   => __( 'Copied', 'jat-calendar-sync' ),
				),
			)
		);
	}

	/**
	 * Permission + nonce check.
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
	 * Redirect with a notice.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 * @param array  $args    Extra query args.
	 */
	private static function back( $type, $message, array $args = array() ) {
		set_transient(
			'jatcs_types_notice_' . get_current_user_id(),
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
	 * Save a type.
	 */
	public static function save() {
		self::guard( 'jatcs_save_type' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in JATCS_Types::clean().
		$in       = isset( $_POST['type'] ) && is_array( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : array();
		$existing = isset( $_POST['existing'] ) ? sanitize_key( wp_unslash( $_POST['existing'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' !== $existing && ! JATCS_Types::get( $existing ) ) {
			$existing = '';
		}

		$result = JATCS_Types::clean( $in, $existing );
		if ( $result['errors'] ) {
			// Keep what they typed so nothing is lost.
			set_transient( 'jatcs_type_draft_' . get_current_user_id(), $result['type'], 300 );
			self::back( 'error', implode( ' ', $result['errors'] ), array( 'edit' => '' !== $existing ? $existing : 'new' ) );
		}

		$type = $result['type'];
		if ( ! $type['active'] ) {
			$others = array_filter(
				JATCS_Types::active(),
				static function ( $t ) use ( $type ) {
					return $t['id'] !== $type['id'];
				}
			);
			if ( ! $others ) {
				$type['active'] = 1;
				JATCS_Types::save( $type );
				self::back( 'error', __( 'Saved, but kept visible: at least one appointment type must be bookable.', 'jat-calendar-sync' ), array( 'edit' => $type['id'] ) );
			}
		}
		JATCS_Types::save( $type );
		JATCS_Availability::bust_cache();
		/* translators: %s: appointment type name */
		self::back( 'success', sprintf( __( '“%s” saved.', 'jat-calendar-sync' ), $type['name'] ) );
	}

	/**
	 * Delete a type.
	 */
	public static function delete() {
		$id = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		self::guard( 'jatcs_delete_type_' . $id );
		$type = JATCS_Types::get( $id );
		if ( ! $type ) {
			self::back( 'error', __( 'That appointment type no longer exists.', 'jat-calendar-sync' ) );
		}
		if ( count( JATCS_Types::all() ) < 2 ) {
			self::back( 'error', __( 'You need at least one appointment type. Edit this one instead.', 'jat-calendar-sync' ) );
		}
		JATCS_Types::delete( $id );
		if ( ! JATCS_Types::active() ) {
			$all              = JATCS_Types::all();
			$all[0]['active'] = 1;
			JATCS_Types::save_all( $all );
		}
		/* translators: %s: appointment type name */
		self::back( 'success', sprintf( __( '“%s” deleted. Existing bookings of this type are kept and can still be rescheduled.', 'jat-calendar-sync' ), $type['name'] ) );
	}

	/**
	 * Reorder.
	 */
	public static function move() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked in guard().
		$id  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$dir = isset( $_GET['dir'] ) && 'up' === $_GET['dir'] ? -1 : 1;
		// phpcs:enable
		self::guard( 'jatcs_move_type_' . $id );
		JATCS_Types::move( $id, $dir );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Render list or edit form.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( 'jatcs_types_notice_' . get_current_user_id() );
		delete_transient( 'jatcs_types_notice_' . get_current_user_id() );
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
	 * Hours summary for the list.
	 *
	 * @param array $t Type.
	 * @return string
	 */
	private static function hours_summary( array $t ) {
		return empty( $t['custom_hours'] ) ? __( 'Your usual hours', 'jat-calendar-sync' ) : __( 'Own hours', 'jat-calendar-sync' );
	}

	/**
	 * The list of types.
	 */
	private static function render_list() {
		$types = JATCS_Types::all();
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Appointment types', 'jat-calendar-sync' ); ?></h1>
		<a href="<?php echo esc_url( self::url( array( 'edit' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add appointment type', 'jat-calendar-sync' ); ?></a>
		<hr class="wp-header-end">
		<?php
		$paid = array_filter(
			$types,
			static function ( $t ) {
				return ! empty( $t['active'] ) && JATCS_Payments::is_paid( $t );
			}
		);
		if ( $paid && ! JATCS_Payments::ready() ) :
			?>
			<div class="notice notice-warning inline"><p>
				<?php
				printf(
					/* translators: %s: link to payment settings */
					esc_html__( 'Some types have a price but Stripe isn’t set up, so visitors can’t see them yet. Set up %s.', 'jat-calendar-sync' ),
					'<a href="' . esc_url( JATCS_Admin::page_url() . '#jatcs-payments' ) . '">' . esc_html__( 'Stripe payments', 'jat-calendar-sync' ) . '</a>'
				);
				?>
			</p></div>
		<?php endif; ?>
		<p class="description jatcs-types-intro"><?php esc_html_e( 'Visitors pick one of these first, then a time. Each type can have its own length, hours and questions; buffers, notice, days off and the booking window are shared and set under Settings.', 'jat-calendar-sync' ); ?></p>

		<table class="widefat striped jatcs-types-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'jat-calendar-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Length', 'jat-calendar-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price', 'jat-calendar-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Hours', 'jat-calendar-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Questions', 'jat-calendar-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Shortcode for this type only', 'jat-calendar-sync' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'jat-calendar-sync' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $types as $i => $t ) : ?>
				<?php
				$edit_url   = self::url( array( 'edit' => $t['id'] ) );
				$delete_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'jatcs_delete_type',
							'type'   => $t['id'],
						),
						admin_url( 'admin-post.php' )
					),
					'jatcs_delete_type_' . $t['id']
				);
				$move       = static function ( $dir ) use ( $t ) {
					return wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'jatcs_move_type',
								'type'   => $t['id'],
								'dir'    => $dir,
							),
							admin_url( 'admin-post.php' )
						),
						'jatcs_move_type_' . $t['id']
					);
				};
				$code       = '[jat_booking type="' . $t['id'] . '"]';
	?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $t['name'] ); ?></a></strong>
						<?php if ( empty( $t['active'] ) ) : ?>
							<span class="jatcs-pill"><?php esc_html_e( 'Hidden', 'jat-calendar-sync' ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $t['description'] ) : ?>
							<br><span class="description"><?php echo esc_html( wp_trim_words( $t['description'], 16 ) ); ?></span>
						<?php endif; ?>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'jat-calendar-sync' ); ?></a></span>
							<?php if ( count( $types ) > 1 ) : ?>
								| <span class="trash"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this appointment type? Existing bookings are kept.', 'jat-calendar-sync' ) ) ); ?>);"><?php esc_html_e( 'Delete', 'jat-calendar-sync' ); ?></a></span>
							<?php endif; ?>
						</div>
					</td>
					<td>
						<?php
						/* translators: %d: minutes */
						echo esc_html( sprintf( __( '%d min', 'jat-calendar-sync' ), $t['duration'] ) );
						?>
					</td>
					<td><?php echo esc_html( $t['price'] ? JATCS_Stripe::format( $t['price'], JATCS_Payments::currency() ) : __( 'Free', 'jat-calendar-sync' ) ); ?></td>
					<td><?php echo esc_html( self::hours_summary( $t ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( count( $t['questions'] ) ) ); ?></td>
					<td><code class="jatcs-code"><?php echo esc_html( $code ); ?></code></td>
					<td class="jatcs-order">
						<?php if ( $i > 0 ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $move( 'up' ) ); ?>" aria-label="<?php esc_attr_e( 'Move up', 'jat-calendar-sync' ); ?>">↑</a>
						<?php endif; ?>
						<?php if ( $i < count( $types ) - 1 ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $move( 'down' ) ); ?>" aria-label="<?php esc_attr_e( 'Move down', 'jat-calendar-sync' ); ?>">↓</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'The order here is the order visitors see. [jat_booking] without a type shows all visible types.', 'jat-calendar-sync' ); ?></p>
		<?php
	}

	/**
	 * Add/edit form.
	 *
	 * @param string $edit Type ID or 'new'.
	 */
	private static function render_form( $edit ) {
		$is_new = 'new' === $edit;
		$type   = $is_new ? JATCS_Types::defaults() : JATCS_Types::get( $edit );
		if ( ! $type ) {
			echo '<p>' . esc_html__( 'That appointment type no longer exists.', 'jat-calendar-sync' ) . '</p>';
			return;
		}
		$draft = get_transient( 'jatcs_type_draft_' . get_current_user_id() );
		if ( is_array( $draft ) ) {
			delete_transient( 'jatcs_type_draft_' . get_current_user_id() );
			$type = array_merge( $type, $draft );
		}
		if ( $is_new ) {
			$type['hours'] = (array) JATCS_Settings::get( 'hours' );
		}
		$days    = array(
			'mon' => __( 'Monday', 'jat-calendar-sync' ),
			'tue' => __( 'Tuesday', 'jat-calendar-sync' ),
			'wed' => __( 'Wednesday', 'jat-calendar-sync' ),
			'thu' => __( 'Thursday', 'jat-calendar-sync' ),
			'fri' => __( 'Friday', 'jat-calendar-sync' ),
			'sat' => __( 'Saturday', 'jat-calendar-sync' ),
			'sun' => __( 'Sunday', 'jat-calendar-sync' ),
		);
		$global  = (array) JATCS_Settings::get( 'hours' );
		$meet_on = (bool) JATCS_Settings::get( 'add_meet' );
		$loc     = trim( (string) JATCS_Settings::get( 'location' ) );
		?>
		<h1><?php echo $is_new ? esc_html__( 'Add appointment type', 'jat-calendar-sync' ) : esc_html( $type['name'] ); ?></h1>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← <?php esc_html_e( 'All appointment types', 'jat-calendar-sync' ); ?></a></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jatcs-type-form">
			<input type="hidden" name="action" value="jatcs_save_type">
			<input type="hidden" name="existing" value="<?php echo esc_attr( $is_new ? '' : $type['id'] ); ?>">
			<?php wp_nonce_field( 'jatcs_save_type' ); ?>

			<div class="jatcs-card">
				<h2><?php esc_html_e( 'Basics', 'jat-calendar-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="jatcs-type-name"><?php esc_html_e( 'Name', 'jat-calendar-sync' ); ?></label></th>
						<td>
							<input type="text" id="jatcs-type-name" name="type[name]" value="<?php echo esc_attr( $type['name'] ); ?>" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. 15-minute intro call', 'jat-calendar-sync' ); ?>">
							<?php if ( ! $is_new ) : ?>
								<p class="description">
									<?php esc_html_e( 'Shortcode for this type only:', 'jat-calendar-sync' ); ?>
									<code>[jat_booking type="<?php echo esc_html( $type['id'] ); ?>"]</code>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jatcs-type-desc"><?php esc_html_e( 'Description', 'jat-calendar-sync' ); ?></label></th>
						<td><textarea id="jatcs-type-desc" name="type[description]" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Optional: shown to visitors under the name.', 'jat-calendar-sync' ); ?>"><?php echo esc_textarea( $type['description'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="jatcs-type-duration"><?php esc_html_e( 'Length', 'jat-calendar-sync' ); ?></label></th>
						<td><input type="number" min="5" max="480" id="jatcs-type-duration" name="type[duration]" value="<?php echo esc_attr( $type['duration'] ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'jat-calendar-sync' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="jatcs-type-interval"><?php esc_html_e( 'Start times every', 'jat-calendar-sync' ); ?></label></th>
						<td>
							<input type="number" min="5" max="480" id="jatcs-type-interval" name="type[interval]" value="<?php echo esc_attr( $type['interval'] ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'jat-calendar-sync' ); ?>
							<p class="description"><?php esc_html_e( 'E.g. 30 offers 9:00, 9:30, 10:00…', 'jat-calendar-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jatcs-type-price"><?php esc_html_e( 'Price', 'jat-calendar-sync' ); ?></label></th>
						<td>
							<input type="text" inputmode="decimal" id="jatcs-type-price" name="type[price]" value="<?php echo esc_attr( $type['price'] ? JATCS_Stripe::from_minor( $type['price'], JATCS_Payments::currency() ) : '' ); ?>" class="small-text" placeholder="0"> <?php echo esc_html( strtoupper( JATCS_Payments::currency() ) ); ?>
							<p class="description">
								<?php
								if ( JATCS_Stripe::enabled() ) {
									esc_html_e( 'Leave empty or 0 for free. Paid appointments are confirmed after payment on Stripe.', 'jat-calendar-sync' );
								} else {
									printf(
										/* translators: %s: link to payment settings */
										esc_html__( 'Leave empty for free. To charge for this type, first set up %s.', 'jat-calendar-sync' ),
										'<a href="' . esc_url( JATCS_Admin::page_url() . '#jatcs-payments' ) . '">' . esc_html__( 'Stripe payments', 'jat-calendar-sync' ) . '</a>'
									);
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Visible', 'jat-calendar-sync' ); ?></th>
						<td><label><input type="checkbox" name="type[active]" value="1" <?php checked( $type['active'] ); ?>> <?php esc_html_e( 'Visitors can book this type', 'jat-calendar-sync' ); ?></label></td>
					</tr>
				</table>
			</div>

			<div class="jatcs-card">
				<h2><?php esc_html_e( 'When', 'jat-calendar-sync' ); ?></h2>
				<fieldset class="jatcs-control">
					<legend class="screen-reader-text"><?php esc_html_e( 'Hours', 'jat-calendar-sync' ); ?></legend>
					<label class="jatcs-choice"><input type="radio" name="type[custom_hours]" value="0" <?php checked( empty( $type['custom_hours'] ) ); ?>> <?php esc_html_e( 'Use my usual hours (from Settings)', 'jat-calendar-sync' ); ?></label>
					<label class="jatcs-choice"><input type="radio" name="type[custom_hours]" value="1" <?php checked( ! empty( $type['custom_hours'] ) ); ?>> <?php esc_html_e( 'Use different hours for this type', 'jat-calendar-sync' ); ?></label>
				</fieldset>
				<table class="form-table jatcs-type-hours" role="presentation">
					<?php foreach ( $days as $key => $label ) : ?>
						<tr>
							<th scope="row"><label for="jatcs-type-hours-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input type="text" id="jatcs-type-hours-<?php echo esc_attr( $key ); ?>" name="type[hours][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $type['hours'][ $key ] ) ? $type['hours'][ $key ] : '' ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( '' !== ( isset( $global[ $key ] ) ? $global[ $key ] : '' ) ? $global[ $key ] : __( 'Closed', 'jat-calendar-sync' ) ); ?>">
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<p class="description"><?php esc_html_e( '24-hour ranges, breaks separated by commas, e.g. 09:00-12:00, 13:00-17:00. Leave a day empty to close it for this type. Your other calendar commitments still block times as usual.', 'jat-calendar-sync' ); ?></p>
			</div>

			<div class="jatcs-card">
				<h2><?php esc_html_e( 'Where', 'jat-calendar-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="jatcs-type-location"><?php esc_html_e( 'Location', 'jat-calendar-sync' ); ?></label></th>
						<td>
							<input type="text" id="jatcs-type-location" name="type[location]" value="<?php echo esc_attr( $type['location'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( '' !== $loc ? $loc : __( 'Optional: an address or phone number', 'jat-calendar-sync' ) ); ?>">
							<?php if ( '' !== $loc ) : ?>
								<p class="description"><?php esc_html_e( 'Leave empty to use the location from Settings.', 'jat-calendar-sync' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jatcs-type-meet"><?php esc_html_e( 'Video call', 'jat-calendar-sync' ); ?></label></th>
						<td>
							<select id="jatcs-type-meet" name="type[meet]">
								<option value="default" <?php selected( $type['meet'], 'default' ); ?>>
									<?php echo esc_html( $meet_on ? __( 'Default (add a Google Meet link)', 'jat-calendar-sync' ) : __( 'Default (no video link)', 'jat-calendar-sync' ) ); ?>
								</option>
								<option value="on" <?php selected( $type['meet'], 'on' ); ?>><?php esc_html_e( 'Add a Google Meet link', 'jat-calendar-sync' ); ?></option>
								<option value="off" <?php selected( $type['meet'], 'off' ); ?>><?php esc_html_e( 'No video link (in person or phone)', 'jat-calendar-sync' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<div class="jatcs-card">
				<h2><?php esc_html_e( 'Questions', 'jat-calendar-sync' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Asked after name and email. Answers go into the calendar event, your notification email and the booking record.', 'jat-calendar-sync' ); ?></p>
				<div class="jatcs-questions" id="jatcs-questions" data-next="<?php echo esc_attr( count( $type['questions'] ) ); ?>">
					<?php foreach ( $type['questions'] as $i => $q ) : ?>
						<?php self::question_row( $i, $q ); ?>
					<?php endforeach; ?>
				</div>
				<template id="jatcs-question-template"><?php self::question_row( '__i__', array() ); ?></template>
				<p><button type="button" class="button" id="jatcs-add-question"><?php esc_html_e( 'Add question', 'jat-calendar-sync' ); ?></button></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Notes box', 'jat-calendar-sync' ); ?></th>
						<td><label><input type="checkbox" name="type[notes]" value="1" <?php checked( $type['notes'] ); ?>> <?php esc_html_e( 'Also show “Anything I should know?” (optional)', 'jat-calendar-sync' ); ?></label></td>
					</tr>
				</table>
			</div>

			<?php submit_button( $is_new ? __( 'Add appointment type', 'jat-calendar-sync' ) : __( 'Save appointment type', 'jat-calendar-sync' ) ); ?>
		</form>
		<?php
	}

	/**
	 * One question row (also used as the JS template with index "__i__").
	 *
	 * @param int|string $i Index.
	 * @param array      $q Question.
	 */
	private static function question_row( $i, array $q ) {
		$q    = wp_parse_args(
			$q,
			array(
				'id'       => '',
				'label'    => '',
				'kind'     => 'text',
				'required' => 0,
				'options'  => array(),
			)
		);
		$name = 'type[questions][' . $i . ']';
		$base = 'jatcs-q-' . $i;
		?>
		<fieldset class="jatcs-question" data-kind="<?php echo esc_attr( $q['kind'] ); ?>">
			<legend class="screen-reader-text"><?php esc_html_e( 'Question', 'jat-calendar-sync' ); ?></legend>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $q['id'] ); ?>">
			<div class="jatcs-question__main">
				<label for="<?php echo esc_attr( $base ); ?>-label"><?php esc_html_e( 'Question', 'jat-calendar-sync' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $base ); ?>-label" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $q['label'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. What would you like to talk about?', 'jat-calendar-sync' ); ?>">
			</div>
			<div>
				<label for="<?php echo esc_attr( $base ); ?>-kind"><?php esc_html_e( 'Answer type', 'jat-calendar-sync' ); ?></label>
				<select id="<?php echo esc_attr( $base ); ?>-kind" name="<?php echo esc_attr( $name ); ?>[kind]" class="jatcs-question__kind">
					<?php foreach ( JATCS_Types::question_kinds() as $kind => $label ) : ?>
						<option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $q['kind'], $kind ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="jatcs-question__options">
				<label for="<?php echo esc_attr( $base ); ?>-options"><?php esc_html_e( 'Choices (one per line)', 'jat-calendar-sync' ); ?></label>
				<textarea id="<?php echo esc_attr( $base ); ?>-options" name="<?php echo esc_attr( $name ); ?>[options]" rows="3"><?php echo esc_textarea( implode( "\n", (array) $q['options'] ) ); ?></textarea>
			</div>
			<div class="jatcs-question__actions">
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="1" <?php checked( $q['required'] ); ?>> <?php esc_html_e( 'Required', 'jat-calendar-sync' ); ?></label>
				<button type="button" class="button-link jatcs-question__up"><?php esc_html_e( 'Move up', 'jat-calendar-sync' ); ?></button>
				<button type="button" class="button-link button-link-delete jatcs-question__remove"><?php esc_html_e( 'Remove', 'jat-calendar-sync' ); ?></button>
			</div>
		</fieldset>
		<?php
	}
}
