<?php
/**
 * Appearance settings with a live preview rendered inside the site's theme.
 *
 * Included from settings.php; $s holds the current settings.
 *
 * @package JATCalendarSync
 *
 * @var array $s
 */

defined( 'ABSPATH' ) || exit;

$jatcs_tokens  = JATCS_Style::theme_tokens();
$jatcs_palette = $jatcs_tokens['palette'];
$jatcs_theme   = wp_get_theme();
$jatcs_radii   = array(
	'auto'    => null !== $jatcs_tokens['radius']
		/* translators: %s: CSS length such as 8px */
		? sprintf( __( 'Match my theme (%s)', 'jat-calendar-sync' ), $jatcs_tokens['radius'] )
		: __( 'Match my theme', 'jat-calendar-sync' ),
	'square'  => __( 'Square', 'jat-calendar-sync' ),
	'soft'    => __( 'Slightly rounded', 'jat-calendar-sync' ),
	'rounded' => __( 'Rounded', 'jat-calendar-sync' ),
	'round'   => __( 'Very rounded', 'jat-calendar-sync' ),
);
$jatcs_can_css = current_user_can( 'unfiltered_html' );
$jatcs_preview = add_query_arg( 'jatcs_preview', '1', home_url( '/' ) );
?>
<div class="jatcs-card jatcs-card--wide" id="jatcs-appearance">
	<h2><?php esc_html_e( 'Appearance', 'jat-calendar-sync' ); ?></h2>
	<p class="description">
		<?php
		if ( 'theme.json' === $jatcs_tokens['source'] ) {
			/* translators: %s: theme name */
			printf( esc_html__( 'The calendar uses your page’s fonts and text color everywhere. “Match my theme” also pulls colors, buttons, corners and heading font from %s.', 'jat-calendar-sync' ), '<strong>' . esc_html( $jatcs_theme->get( 'Name' ) ) . '</strong>' );
		} elseif ( 'palette' === $jatcs_tokens['source'] ) {
			/* translators: %s: theme name */
			printf( esc_html__( 'The calendar uses your page’s fonts and text color everywhere. %s is a classic theme, so “Match my theme” uses its color palette, or reads its button and link colors from the page, and leaves buttons and fields to its stylesheet.', 'jat-calendar-sync' ), '<strong>' . esc_html( $jatcs_theme->get( 'Name' ) ) . '</strong>' );
		} else {
			/* translators: %s: theme name */
			printf( esc_html__( 'The calendar uses your page’s fonts and text color everywhere. %s doesn’t publish a color palette, so “Match my theme” reads its button and link colors from the page and leaves buttons and fields to its stylesheet.', 'jat-calendar-sync' ), '<strong>' . esc_html( $jatcs_theme->get( 'Name' ) ) . '</strong>' );
		}
		?>
	</p>

	<div class="jatcs-appearance">
		<div class="jatcs-appearance__controls">
			<fieldset class="jatcs-control">
				<legend><?php esc_html_e( 'Accent color', 'jat-calendar-sync' ); ?></legend>
				<label class="jatcs-choice">
					<input type="radio" name="jatcs[style_accent]" value="theme" <?php checked( $s['style_accent'], 'theme' ); ?>>
					<?php esc_html_e( 'Match my theme', 'jat-calendar-sync' ); ?>
					<?php if ( null !== $jatcs_tokens['accent_hex'] ) : ?>
						<span class="jatcs-swatch" style="background:<?php echo esc_attr( $jatcs_tokens['accent_hex'] ); ?>" title="<?php echo esc_attr( $jatcs_tokens['accent_hex'] ); ?>"></span>
					<?php else : ?>
						<span class="jatcs-hint"><?php esc_html_e( '(read from your theme’s buttons and links on the page; the color below is the fallback)', 'jat-calendar-sync' ); ?></span>
					<?php endif; ?>
				</label>
				<label class="jatcs-choice">
					<input type="radio" name="jatcs[style_accent]" value="custom" <?php checked( $s['style_accent'], 'custom' ); ?>>
					<?php esc_html_e( 'Choose a color', 'jat-calendar-sync' ); ?>
				</label>
				<div class="jatcs-custom-accent">
					<input type="text" id="jatcs-accent" name="jatcs[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>" class="jatcs-color" data-default-color="#2e7d4f" data-jatcs-target="accent">
					<?php if ( $jatcs_palette ) : ?>
						<div class="jatcs-palette" role="group" aria-label="<?php esc_attr_e( 'Your theme’s colors', 'jat-calendar-sync' ); ?>">
							<?php foreach ( array_slice( $jatcs_palette, 0, 16, true ) as $jatcs_slug => $jatcs_hex ) : ?>
								<button type="button" class="jatcs-swatch jatcs-swatch--pick" style="background:<?php echo esc_attr( $jatcs_hex ); ?>" data-color="<?php echo esc_attr( $jatcs_hex ); ?>" data-target="jatcs-accent" title="<?php echo esc_attr( $jatcs_slug . ' ' . $jatcs_hex ); ?>"><span class="screen-reader-text"><?php echo esc_html( $jatcs_slug ); ?></span></button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</fieldset>

			<fieldset class="jatcs-control">
				<legend><?php esc_html_e( 'Booking button and form fields', 'jat-calendar-sync' ); ?></legend>
				<label class="jatcs-choice"><input type="radio" name="jatcs[style_buttons]" value="theme" <?php checked( $s['style_buttons'], 'theme' ); ?>> <?php esc_html_e( 'Use my theme’s buttons and fields', 'jat-calendar-sync' ); ?></label>
				<label class="jatcs-choice"><input type="radio" name="jatcs[style_buttons]" value="plugin" <?php checked( $s['style_buttons'], 'plugin' ); ?>> <?php esc_html_e( 'Use the calendar’s own style (accent color)', 'jat-calendar-sync' ); ?></label>
			</fieldset>

			<div class="jatcs-control">
				<label for="jatcs-style-radius" class="jatcs-control__label"><?php esc_html_e( 'Corners', 'jat-calendar-sync' ); ?></label>
				<select id="jatcs-style-radius" name="jatcs[style_radius]">
					<?php foreach ( $jatcs_radii as $jatcs_key => $jatcs_label ) : ?>
						<option value="<?php echo esc_attr( $jatcs_key ); ?>" <?php selected( $s['style_radius'], $jatcs_key ); ?>><?php echo esc_html( $jatcs_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="jatcs-control">
				<label for="jatcs-style-density" class="jatcs-control__label"><?php esc_html_e( 'Spacing', 'jat-calendar-sync' ); ?></label>
				<select id="jatcs-style-density" name="jatcs[style_density]">
					<option value="compact" <?php selected( $s['style_density'], 'compact' ); ?>><?php esc_html_e( 'Compact', 'jat-calendar-sync' ); ?></option>
					<option value="comfortable" <?php selected( $s['style_density'], 'comfortable' ); ?>><?php esc_html_e( 'Comfortable', 'jat-calendar-sync' ); ?></option>
					<option value="roomy" <?php selected( $s['style_density'], 'roomy' ); ?>><?php esc_html_e( 'Roomy', 'jat-calendar-sync' ); ?></option>
				</select>
			</div>

			<fieldset class="jatcs-control">
				<legend><?php esc_html_e( 'Calendar background', 'jat-calendar-sync' ); ?></legend>
				<label class="jatcs-choice"><input type="radio" name="jatcs[style_background]" value="none" <?php checked( $s['style_background'], 'none' ); ?>> <?php esc_html_e( 'None (shows the page behind it)', 'jat-calendar-sync' ); ?></label>
				<?php if ( null !== $jatcs_tokens['base'] ) : ?>
					<label class="jatcs-choice"><input type="radio" name="jatcs[style_background]" value="theme" <?php checked( $s['style_background'], 'theme' ); ?>> <?php esc_html_e( 'My theme’s page color', 'jat-calendar-sync' ); ?></label>
				<?php endif; ?>
				<label class="jatcs-choice"><input type="radio" name="jatcs[style_background]" value="custom" <?php checked( $s['style_background'], 'custom' ); ?>> <?php esc_html_e( 'Choose a color', 'jat-calendar-sync' ); ?></label>
				<div class="jatcs-custom-bg">
					<input type="text" id="jatcs-bg" name="jatcs[style_bg_color]" value="<?php echo esc_attr( $s['style_bg_color'] ); ?>" class="jatcs-color" data-default-color="#ffffff" data-jatcs-target="bg">
				</div>
			</fieldset>

			<fieldset class="jatcs-control">
				<legend><?php esc_html_e( 'Headings', 'jat-calendar-sync' ); ?></legend>
				<?php $jatcs_font_known = null !== $jatcs_tokens['heading_font'] || 'theme.json' !== $jatcs_tokens['source']; ?>
				<label class="jatcs-choice"><input type="radio" name="jatcs[style_headings]" value="theme" <?php checked( $s['style_headings'], 'theme' ); ?> <?php disabled( ! $jatcs_font_known ); ?>> <?php esc_html_e( 'My theme’s heading font', 'jat-calendar-sync' ); ?><?php echo ! $jatcs_font_known ? ' <span class="jatcs-hint">' . esc_html__( '(same as body text in this theme)', 'jat-calendar-sync' ) . '</span>' : ''; ?></label>
				<label class="jatcs-choice"><input type="radio" name="jatcs[style_headings]" value="inherit" <?php checked( 'inherit' === $s['style_headings'] || ! $jatcs_font_known ); ?>> <?php esc_html_e( 'Same font as the text around it', 'jat-calendar-sync' ); ?></label>
			</fieldset>

			<details class="jatcs-control jatcs-css" <?php echo '' !== trim( (string) $s['custom_css'] ) ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Custom CSS', 'jat-calendar-sync' ); ?></summary>
				<?php if ( $jatcs_can_css ) : ?>
					<textarea id="jatcs-custom-css" name="jatcs[custom_css]" rows="8" class="large-text code" spellcheck="false" placeholder=".jatcs {&#10;  --jatcs-accent: #0b5fff;&#10;  --jatcs-radius: 4px;&#10;}"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Loaded only on pages with the calendar. Useful variables:', 'jat-calendar-sync' ); ?>
						<code>--jatcs-accent</code> <code>--jatcs-on-accent</code> <code>--jatcs-radius</code> <code>--jatcs-space</code> <code>--jatcs-surface</code> <code>--jatcs-line</code> <code>--jatcs-muted</code> <code>--jatcs-heading-font</code> <code>--jatcs-max-width</code>.
						<?php esc_html_e( 'Parts:', 'jat-calendar-sync' ); ?>
						<code>.jatcs__day</code> <code>.jatcs__time</code> <code>.jatcs__submit</code> <code>.jatcs__input</code> <code>.jatcs__panel</code>.
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Only users allowed to post unfiltered HTML can edit custom CSS.', 'jat-calendar-sync' ); ?></p>
				<?php endif; ?>
			</details>
		</div>

		<div class="jatcs-appearance__preview">
			<div class="jatcs-preview-bar">
				<span><?php esc_html_e( 'Preview on your theme, with sample times', 'jat-calendar-sync' ); ?></span>
				<span class="jatcs-preview-sizes" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'jat-calendar-sync' ); ?>">
					<button type="button" class="button button-small is-pressed" data-jatcs-width="100%" aria-pressed="true"><?php esc_html_e( 'Wide', 'jat-calendar-sync' ); ?></button>
					<button type="button" class="button button-small" data-jatcs-width="390px" aria-pressed="false"><?php esc_html_e( 'Phone', 'jat-calendar-sync' ); ?></button>
				</span>
			</div>
			<div class="jatcs-preview-frame">
				<iframe id="jatcs-preview" src="<?php echo esc_url( $jatcs_preview ); ?>" title="<?php esc_attr_e( 'Booking calendar preview', 'jat-calendar-sync' ); ?>" loading="lazy"></iframe>
			</div>
		</div>
	</div>
</div>
