<?php
/**
 * One additional attendee card. Rendered once as a JS template with
 * $index = '__i__' and cloned per extra ticket; the script fills in the
 * title, the seat's ticket type and price, and the "2 of 3" counter.
 *
 * Override: your-theme/blt-events/registration/attendee.php
 *
 * @var string|int $index  Attendee index placeholder.
 * @var array      $fields Field definitions (first name, last name, email, phone by default).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$prefix = 'attendees[' . $index . ']';
?>
<div class="blt-reg__card blt-attendee-card blt-attendee" data-blt-attendee="<?php echo esc_attr( $index ); ?>" role="group" aria-labelledby="blt-attendee-title-<?php echo esc_attr( $index ); ?>">
	<div class="blt-attendee-card__head">
		<h4 class="blt-attendee-card__title" id="blt-attendee-title-<?php echo esc_attr( $index ); ?>" data-blt-attendee-title></h4>
		<span class="blt-attendee-card__ticket" data-blt-seat-ticket></span>
		<span class="blt-attendee-card__price" data-blt-seat-price></span>
	</div>
	<div class="blt-fields-grid">
		<?php foreach ( $fields as $field ) : ?>
			<?php
			// Core attendee columns are posted flat (attendees[i][email]);
			// anything else lands in attendees[i][custom_fields][key].
			$core   = in_array( $field['key'], array( 'name', 'first_name', 'last_name', 'email', 'phone' ), true );
			$target = $core ? $prefix : $prefix . '[custom_fields]';
			echo BLT_Events_Fieldsets::render_field( $field, '', $target ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_field().
			?>
		<?php endforeach; ?>
	</div>
	<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[ticket_type]" data-blt-attendee-ticket />
	<div class="blt-attendee-card__foot">
		<button type="button" class="blt-attendee-card__remove" data-blt-remove-attendee>
			<?php echo BLT_Events_Templates::icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
			<?php esc_html_e( 'Remove attendee', 'blt-events' ); ?>
		</button>
		<span class="blt-attendee-card__count" data-blt-seat-count></span>
	</div>
</div>
