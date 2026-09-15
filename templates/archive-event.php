<?php
/**
 * Event archive for classic themes (/event/ and event category pages).
 *
 * Override: your-theme/blt-events/archive-event.php, or ship a regular
 * archive-event.php in your theme and WordPress will use that instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$blt_title       = is_tax( 'event_category' ) ? single_term_title( '', false ) : post_type_archive_title( '', false );
$blt_description = is_tax( 'event_category' ) ? term_description() : '';

/**
 * Filter the shortcode attributes used by the archive template.
 *
 * @param array $atts Attributes for [blt_events_calendar].
 */
$blt_atts = apply_filters( 'blt_events_archive_shortcode_atts', array(
	'view'     => 'list',
	'switcher' => 'yes',
	'limit'    => 12,
) );

$blt_shortcode = '[blt_events_calendar';
foreach ( $blt_atts as $blt_key => $blt_value ) {
	$blt_shortcode .= ' ' . sanitize_key( $blt_key ) . '="' . esc_attr( $blt_value ) . '"';
}
$blt_shortcode .= ']';
?>
<div class="blt-events-archive">
	<div class="blt-events-archive__inner">
		<header class="blt-events-archive__header">
			<h1 class="blt-events-archive__title"><?php echo esc_html( $blt_title ); ?></h1>
			<?php if ( $blt_description ) : ?>
				<div class="blt-events-archive__description"><?php echo wp_kses_post( $blt_description ); ?></div>
			<?php endif; ?>
		</header>

		<?php
		/**
		 * Fires before the archive listing.
		 */
		do_action( 'blt_events_archive_before' );

		echo do_shortcode( $blt_shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output is escaped in templates.

		/**
		 * Fires after the archive listing.
		 */
		do_action( 'blt_events_archive_after' );
		?>
	</div>
</div>
<?php
get_footer();
