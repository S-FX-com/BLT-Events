<?php
/**
 * Online attendance, inside the event card's facts list. The join link is
 * shown only to visitors allowed to see it (confirmed registrants by
 * default; see the blt_events_can_see_online_url filter).
 *
 * Override: your-theme/blt-events/single/virtual.php
 *
 * @var bool   $is_online
 * @var string $event_type          online | hybrid
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
<span class="blt-event__virtual">
	<strong class="blt-event__virtual-title"><?php echo 'hybrid' === $event_type ? esc_html__( 'Also online', 'blt-events' ) : esc_html__( 'Online event', 'blt-events' ); ?></strong>
	<?php if ( $can_see_online_url && $online_url ) : ?>
		<span class="blt-event__virtual-text">
			<?php esc_html_e( 'You are registered.', 'blt-events' ); ?>
			<a class="blt-event__virtual-link" href="<?php echo esc_url( $online_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Join online', 'blt-events' ); ?></a>
		</span>
	<?php else : ?>
		<span class="blt-event__virtual-text"><?php esc_html_e( 'The join link is sent to attendees and shown here once your registration is confirmed.', 'blt-events' ); ?></span>
	<?php endif; ?>
</span>
