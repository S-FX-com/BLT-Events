<?php
/**
 * "Virtual" card for online and hybrid events. The join link is shown only
 * to visitors allowed to see it (confirmed registrants by default).
 *
 * Override: your-theme/blt-events/single/virtual.php
 *
 * @var bool   $is_online
 * @var string $event_type
 * @var string $online_url
 * @var bool   $can_see_online_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $is_online ) {
	return;
}
?>
<div class="blt-event__card blt-event__virtual">
	<h3 class="blt-event__card-title"><?php echo 'hybrid' === $event_type ? esc_html__( 'Join online', 'blt-events' ) : esc_html__( 'Virtual', 'blt-events' ); ?></h3>
	<?php if ( $can_see_online_url && $online_url ) : ?>
		<p class="blt-event__virtual-text"><?php esc_html_e( 'You are registered. Join here:', 'blt-events' ); ?></p>
		<a class="blt-event__virtual-link" href="<?php echo esc_url( $online_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $online_url ); ?></a>
	<?php elseif ( 'hybrid' === $event_type ) : ?>
		<p class="blt-event__virtual-text"><?php esc_html_e( 'This event can also be attended online. The join link is sent to attendees and shown here once your registration is confirmed.', 'blt-events' ); ?></p>
	<?php else : ?>
		<p class="blt-event__virtual-text"><?php esc_html_e( 'This is a virtual event. The join link is sent to attendees and shown here once your registration is confirmed.', 'blt-events' ); ?></p>
	<?php endif; ?>
</div>
