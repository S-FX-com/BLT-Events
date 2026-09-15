<?php
/**
 * The event description (the post content).
 *
 * Override: your-theme/blt-events/single/description.php
 *
 * @var string $description Already-filtered post content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-event__description">
	<?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-filtered post content. ?>
</div>
