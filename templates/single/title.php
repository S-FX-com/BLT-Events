<?php
/**
 * Event title (only when the plugin is set to print it; most themes already do).
 *
 * Override: your-theme/blt-events/single/title.php
 *
 * @var bool   $show_title
 * @var string $title
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $show_title ) {
	return;
}
?>
<h1 class="blt-event__title"><?php echo esc_html( $title ); ?></h1>
