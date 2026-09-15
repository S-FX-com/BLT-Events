<?php
/**
 * One additional attendee block. Rendered once as a JS template with
 * $index = '__i__' and cloned per extra ticket.
 *
 * Override: your-theme/blt-events/registration/attendee.php
 *
 * @var string|int $index  Attendee index placeholder.
 * @var array      $fields Field definitions (name, email, phone by default).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$prefix = 'attendees[' . $index . ']';
?>
<fieldset class="blt-attendee" data-blt-attendee="<?php echo esc_attr( $index ); ?>">
	<legend class="blt-attendee__title" data-blt-attendee-title></legend>
	<div class="blt-fields-grid">
		<?php foreach ( $fields as $field ) : ?>
			<?php
			// Core attendee columns are posted flat (attendees[i][name]);
			// anything else lands in attendees[i][custom_fields][key].
			$core   = in_array( $field['key'], array( 'name', 'email', 'phone' ), true );
			$target = $core ? $prefix : $prefix . '[custom_fields]';
			echo BLT_Events_Fieldsets::render_field( $field, '', $target ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_field().
			?>
		<?php endforeach; ?>
		<div class="blt-field-wrap blt-field-full">
			<label for="attendee_<?php echo esc_attr( $index ); ?>_ticket"><?php esc_html_e( 'Ticket', 'blt-events' ); ?></label>
			<select id="attendee_<?php echo esc_attr( $index ); ?>_ticket" name="<?php echo esc_attr( $prefix ); ?>[ticket_type]" data-blt-attendee-ticket></select>
		</div>
	</div>
</fieldset>
